<?php
/**
 * 匯入屆期資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-term.php            # 匯入資料（upsert）
 *   php scripts/import-term.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源欄位：代碼, 議會代碼, 屆次, 投票日, 就職日, 任期屆滿日, 現任, 備註
 * ES 欄位名稱與來源一致（現任 Y/'' 轉為 boolean）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '議會代碼'   => ['type' => 'keyword'],
        '屆次'       => ['type' => 'integer'],
        '投票日'     => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '就職日'     => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '任期屆滿日' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '現任'       => ['type' => 'boolean'],
        '備註'       => ['type' => 'text'],
    ],
];

$known_source_columns = ['代碼', '議會代碼', '屆次', '投票日', '就職日', '任期屆滿日', '現任', '備註'];

if ($reset) {
    try {
        Elastic::dropIndex('term');
        error_log("Dropped index: term");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
    try {
        Elastic::createIndex('term', $index_mapping);
        error_log("Created index: term");
    } catch (Exception $e) {
        error_log("Create index failed: " . $e->getMessage());
        exit(1);
    }
}

$csv_path = getenv('IMPORT_TERM_CSV') ?: (__DIR__ . '/../屆.csv');
$fh = fopen($csv_path, 'r');
if (!$fh) {
    error_log("Cannot open {$csv_path}");
    exit(1);
}

// 跳過 UTF-8 BOM
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$headers = fgetcsv($fh);

// 檢查未知欄位
$unknown = array_diff($headers, $known_source_columns);
if ($unknown) {
    error_log("ERROR: 來源檔案有未定義的欄位，請先在 index_mapping 補上對應設定再匯入：" . implode(', ', $unknown));
    fclose($fh);
    exit(1);
}

$date_fields = ['投票日', '就職日', '任期屆滿日'];
$count = 0;
$latest_term_map = []; // cc_code => ['屆次' => int, 'doc_id' => string]

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < count($headers)) {
        continue;
    }
    $record = array_combine($headers, $row);

    $doc_id   = $record['代碼'];
    $cc_code  = $record['議會代碼'];
    $term_int = intval($record['屆次']);

    $doc = [
        '代碼'     => $doc_id,
        '議會代碼' => $cc_code,
        '屆次'     => $term_int,
        '現任'     => ($record['現任'] === 'Y'),
    ];

    foreach ($date_fields as $f) {
        if ($record[$f] !== '') {
            $doc[$f] = $record[$f];
        }
    }
    if ($record['備註'] !== '') {
        $doc['備註'] = $record['備註'];
    }

    Elastic::dbBulkInsert('term', $doc_id, $doc);
    $count++;

    if (!isset($latest_term_map[$cc_code]) || $term_int > $latest_term_map[$cc_code]['屆次']) {
        $latest_term_map[$cc_code] = ['屆次' => $term_int, 'doc_id' => $doc_id];
    }
}
fclose($fh);

Elastic::dbBulkCommit('term');
error_log("Done. Imported {$count} terms.");

// 更新各議會 council 文件，寫入最新屆期代碼
$updated = 0;
foreach ($latest_term_map as $cc_code => $info) {
    try {
        Elastic::dbQuery(
            '/{prefix}council/_update/' . rawurlencode($cc_code),
            'POST',
            json_encode(['doc' => ['最新屆期代碼' => $info['doc_id']]])
        );
        $updated++;
    } catch (Exception $e) {
        error_log("Update council [{$cc_code}] failed: " . $e->getMessage());
    }
}
error_log("Done. Updated 最新屆期代碼 for {$updated} councils.");
