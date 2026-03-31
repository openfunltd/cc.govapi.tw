<?php
/**
 * 匯入議會資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-council.php            # 匯入資料（upsert）
 *   php scripts/import-council.php --reset    # 先刪除 index 再重建並匯入
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        'cc_code'      => ['type' => 'keyword'],
        'name'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'type'         => ['type' => 'keyword'],
        'moi_code'     => ['type' => 'keyword'],
        'iso_code'     => ['type' => 'keyword'],
        'start_date'   => ['type' => 'date', 'format' => 'yyyy-MM-dd||yyyy'],
        'end_date'     => ['type' => 'date', 'format' => 'yyyy-MM-dd||yyyy'],
        'wikipedia_url'=> ['type' => 'keyword', 'index' => false],
        'wikidata_id'  => ['type' => 'keyword'],
        'is_active'    => ['type' => 'boolean'],
    ],
];

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

$csv_path = __DIR__ . '/../議會.csv';
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

// 讀取 header 行
$headers = fgetcsv($fh);

$count = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < count($headers)) {
        continue;
    }
    $record = array_combine($headers, $row);

    $cc_code = $record['代碼'];
    $doc = [
        'cc_code'       => $cc_code,
        'name'          => $record['議會名稱'],
        'type'          => $record['議會類別'],
        'moi_code'      => $record['內政部行政區代碼'],
        'iso_code'      => $record['ISO碼'],
        'is_active'     => ($record['廢止日期'] === ''),
    ];
    if ($record['生效日期'] !== '') {
        $doc['start_date'] = $record['生效日期'];
    }
    if ($record['廢止日期'] !== '') {
        $doc['end_date'] = $record['廢止日期'];
    }
    if ($record['維基條目'] !== '') {
        $doc['wikipedia_url'] = $record['維基條目'];
    }
    if ($record['wikidata-id'] !== '') {
        $doc['wikidata_id'] = $record['wikidata-id'];
    }

    Elastic::dbBulkInsert('council', $cc_code, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('council');
error_log("Done. Imported {$count} councils.");
