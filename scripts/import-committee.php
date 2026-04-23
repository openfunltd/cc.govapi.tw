<?php
/**
 * 匯入委員會資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-committee.php            # 匯入資料（upsert）
 *   php scripts/import-committee.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：data.csv（欄位：代碼, 議會代碼, 名稱, 別稱, 類別, 職掌, 生效日期, 廢止日期）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * Doc ID：{代碼}（例：tpe-c1）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        '代碼'     => ['type' => 'keyword'],
        '議會代碼' => ['type' => 'keyword'],
        '名稱'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '別稱'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '類別'     => ['type' => 'keyword'],
        '職掌'     => ['type' => 'text'],
        '生效日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '廢止日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    ],
];

$known_source_keys = ['代碼', '議會代碼', '名稱', '別稱', '類別', '職掌', '生效日期', '廢止日期'];

if ($reset) {
    try {
        Elastic::dropIndex('committee');
        error_log("Dropped index: committee");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('committee', $index_mapping);
    error_log("Created index: committee");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_COMMITTEE_CSV');
if (!$csv_path) {
    $csv_path = __DIR__ . '/../data.csv';
}
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到委員會 CSV：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-committee.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

$count = 0;
$errors = 0;

while (($row = fgetcsv($fh)) !== false) {
    $data = array_combine($headers, $row);

    // 空白日期轉 null
    foreach (['生效日期', '廢止日期'] as $f) {
        if (isset($data[$f]) && trim($data[$f]) === '') {
            $data[$f] = null;
        }
    }

    // 空白文字欄位轉 null
    foreach (['別稱', '職掌'] as $f) {
        if (isset($data[$f]) && trim($data[$f]) === '') {
            $data[$f] = null;
        }
    }

    $doc_id = $data['代碼'];

    Elastic::dbBulkInsert('committee', $doc_id, $data);
    $count++;
}

fclose($fh);
Elastic::dbBulkCommit('committee');
error_log("Done. Imported: {$count}, Errors: {$errors}");
