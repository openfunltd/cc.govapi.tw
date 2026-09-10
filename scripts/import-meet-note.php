<?php
/**
 * 匯入會議速記摘要（meet_note）資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-meet-note.php            # 匯入資料（upsert）
 *   php scripts/import-meet-note.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：meet_notes.csv（欄位：代碼, 縣市, 場次代碼, 會議代碼, 日期, 摘要內容,
 * 來源檔案, 來源網址）
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆／會期代碼：跟import-meet.php同樣做法，查既有sitting index取得
 * Doc ID：代碼本身（例：pif-330fa3198c-note）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$county_to_cc_code = CountyCodeHelper::getMap();

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '縣市'       => ['type' => 'keyword'],
        '場次代碼'   => ['type' => 'keyword'],
        '會議代碼'   => ['type' => 'keyword'],
        '日期'       => ['type' => 'keyword'],
        '摘要內容'   => ['type' => 'text'],
        '來源檔案'   => ['type' => 'keyword'],
        '來源網址'   => ['type' => 'keyword', 'index' => false],
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
        '會期代碼'   => ['type' => 'keyword'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '會議代碼', '日期', '摘要內容', '來源檔案', '來源網址',
];

if ($reset) {
    try {
        Elastic::dropIndex('meet_note');
        error_log("Dropped index: meet_note");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('meet_note', $index_mapping);
    error_log("Created index: meet_note");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}meet_note/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_MEET_NOTE_CSV') ?: (__DIR__ . '/../meet_notes.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到meet_notes.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-meet-note.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

function derive_sitting_context($code)
{
    if (!$code) {
        return ['屆' => null, '會期代碼' => null];
    }
    try {
        $r = Elastic::dbQuery('/{prefix}sitting/_doc/' . rawurlencode($code), 'GET');
        if ($r->found ?? false) {
            $s = $r->_source;
            return [
                '屆'       => $s->{'屆'} ?? null,
                '會期代碼' => $s->{'會期代碼'} ?? null,
            ];
        }
    } catch (Exception $e) {
        // 找不到就留空
    }
    return ['屆' => null, '會期代碼' => null];
}

$count = 0;
$errors = 0;
$no_cc_code = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers)) {
        error_log("跳過欄位數異常列: " . implode(',', $row));
        continue;
    }
    $data = array_combine($headers, $row);

    $doc = $data;

    foreach (['場次代碼', '會議代碼', '日期'] as $f) {
        if (isset($doc[$f]) && trim($doc[$f]) === '') {
            $doc[$f] = null;
        }
    }

    $doc['議會代碼'] = $county_to_cc_code[$data['縣市']] ?? null;
    if (!$doc['議會代碼']) {
        $no_cc_code++;
    }

    $context = derive_sitting_context($doc['場次代碼']);
    $doc['屆'] = $context['屆'];
    $doc['會期代碼'] = $context['會期代碼'];

    $doc_id = $data['代碼'];

    try {
        Elastic::dbBulkInsert('meet_note', $doc_id, $doc);
        $count++;
        if ($count % 1000 === 0) {
            error_log("Imported {$count} meet_notes...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('meet_note');
error_log("Done. Imported: {$count}, Errors: {$errors}, 無法對照議會代碼: {$no_cc_code}");
