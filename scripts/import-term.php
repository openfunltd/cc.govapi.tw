<?php
/**
 * 匯入屆期資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-term.php            # 匯入資料（upsert）
 *   php scripts/import-term.php --reset    # 先刪除 index 再重建並匯入
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        'cc_code'    => ['type' => 'keyword'],
        'term'       => ['type' => 'integer'],
        'start_date' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        'end_date'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        'is_current' => ['type' => 'boolean'],
        'note'       => ['type' => 'text'],
    ],
];

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

$csv_path = __DIR__ . '/../屆.csv';

$fh = fopen($csv_path, 'r');
if (!$fh) {
    error_log("Cannot open {$csv_path}");
    exit(1);
}

// 跳過 UTF-8 BOM（EF BB BF）
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$headers = fgetcsv($fh);

$count = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < count($headers)) {
        continue;
    }
    $record = array_combine($headers, $row);

    $doc_id = $record['代碼'];
    $doc = [
        'cc_code'    => $record['議會代碼'],
        'term'       => intval($record['屆次']),
        'is_current' => ($record['現任'] === 'Y'),
    ];
    if ($record['就職日'] !== '') {
        $doc['start_date'] = $record['就職日'];
    }
    if ($record['任期屆滿日'] !== '') {
        $doc['end_date'] = $record['任期屆滿日'];
    }
    if ($record['備註'] !== '') {
        $doc['note'] = $record['備註'];
    }

    Elastic::dbBulkInsert('term', $doc_id, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('term');
error_log("Done. Imported {$count} terms.");
