<?php
/**
 * 匯入議會資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-council.php            # 匯入資料（upsert）
 *   php scripts/import-council.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源欄位：代碼, 議會名稱, 議會類別, 內政部行政區代碼, ISO碼, 生效日期, 廢止日期, 維基條目, wikidata-id
 * ES 欄位名稱與來源一致（wikidata-id 轉為 wikidata_id）
 * 衍生欄位：現存（由廢止日期推算）、最新屆期代碼（由 import-term 寫入）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

// wikidata-id 在 CSV 標頭中有連字符，importer 轉換為 wikidata_id
$index_mapping = [
    'properties' => [
        '代碼'              => ['type' => 'keyword'],
        '議會名稱'          => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '議會類別'          => ['type' => 'keyword'],
        '內政部行政區代碼'  => ['type' => 'keyword'],
        'ISO碼'             => ['type' => 'keyword'],
        '生效日期'          => ['type' => 'date', 'format' => 'yyyy-MM-dd||yyyy'],
        '廢止日期'          => ['type' => 'date', 'format' => 'yyyy-MM-dd||yyyy'],
        '維基條目'          => ['type' => 'keyword', 'index' => false],
        'wikidata_id'       => ['type' => 'keyword'],
        '現存'              => ['type' => 'boolean'],
        '最新屆期代碼'      => ['type' => 'keyword'],
    ],
];

// 來源欄位（含 wikidata-id 連字符原名）
$known_source_columns = ['代碼', '議會名稱', '議會類別', '內政部行政區代碼', 'ISO碼', '生效日期', '廢止日期', '維基條目', 'wikidata-id'];

if ($reset) {
    try {
        Elastic::dropIndex('council');
        error_log("Dropped index: council");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
    try {
        Elastic::createIndex('council', $index_mapping);
        error_log("Created index: council");
    } catch (Exception $e) {
        error_log("Create index failed: " . $e->getMessage());
        exit(1);
    }
}

$csv_path = getenv('IMPORT_COUNCIL_CSV') ?: (__DIR__ . '/../議會.csv');
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

$count = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < count($headers)) {
        continue;
    }
    $record = array_combine($headers, $row);

    $doc_id = $record['代碼'];
    $doc = ['代碼' => $doc_id];

    foreach ($record as $col => $val) {
        if ($col === '代碼') continue;
        if ($val === '') continue;
        if ($col === 'wikidata-id') {
            $doc['wikidata_id'] = $val;
        } else {
            $doc[$col] = $val;
        }
    }

    $doc['現存'] = ($record['廢止日期'] === '');

    Elastic::dbBulkInsert('council', $doc_id, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('council');
error_log("Done. Imported {$count} councils.");
