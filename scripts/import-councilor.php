<?php
/**
 * 匯入議員資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-councilor.php            # 匯入資料（upsert）
 *   php scripts/import-councilor.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議員.jsonl（每行一筆 JSON）
 * Doc ID 格式：{cc_code}-{term}-{name}（例：tpe-14-王大明）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        'cc_code'       => ['type' => 'keyword'],
        'term'          => ['type' => 'integer'],
        'term_code'     => ['type' => 'keyword'],
        'name'          => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'title'         => ['type' => 'keyword'],
        'gender'        => ['type' => 'keyword'],
        'party'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'constituency'  => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'onboard_date'  => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        'leave_date'    => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        'leave_reason'  => ['type' => 'keyword'],
        'education'     => ['type' => 'text'],
        'pic_url'       => ['type' => 'keyword', 'index' => false],
        'bio'           => ['type' => 'text'],
        'tel'           => ['type' => 'keyword', 'index' => false],
        'addr'          => ['type' => 'keyword', 'index' => false],
        'email'         => ['type' => 'keyword', 'index' => false],
        'website'       => ['type' => 'keyword', 'index' => false],
    ],
];

if ($reset) {
    try {
        Elastic::dropIndex('councilor');
        error_log("Dropped index: councilor");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
    try {
        Elastic::createIndex('councilor', $index_mapping);
        error_log("Created index: councilor");
    } catch (Exception $e) {
        error_log("Create index failed: " . $e->getMessage());
        exit(1);
    }
}

$jsonl_path = __DIR__ . '/../議員.jsonl';
$fh = fopen($jsonl_path, 'r');
if (!$fh) {
    error_log("Cannot open {$jsonl_path}");
    exit(1);
}

// 跳過 UTF-8 BOM（EF BB BF）
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$count = 0;
$skip  = 0;
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $record = json_decode($line, true);
    if (!$record) {
        error_log("Invalid JSON: {$line}");
        $skip++;
        continue;
    }

    $cc_code   = $record['議會代碼'] ?? '';
    $term_code = $record['屆代碼']   ?? '';
    $name      = $record['姓名']     ?? '';

    if ($cc_code === '' || $term_code === '' || $name === '') {
        $skip++;
        continue;
    }

    // 從 屆代碼 取 term 整數（最後一個 '-' 後的數字）
    $term = intval(substr($term_code, strrpos($term_code, '-') + 1));

    $doc_id = "{$cc_code}-{$term}-{$name}";

    $doc = [
        'cc_code'   => $cc_code,
        'term'      => $term,
        'term_code' => $term_code,
        'name'      => $name,
    ];

    // 選填欄位（空字串不寫入）
    $optional = [
        'title'    => '職稱',
        'party'    => '黨籍',
        'constituency' => '區域',
        'education' => '學歷',
        'bio'      => '簡歷',
        'pic_url'  => '照片',
        'tel'      => '聯絡電話',
        'addr'     => '辦公地址',
        'email'    => '電子信箱',
    ];
    foreach ($optional as $es_field => $json_key) {
        $v = $record[$json_key] ?? '';
        if ($v !== '') {
            $doc[$es_field] = $v;
        }
    }

    Elastic::dbBulkInsert('councilor', $doc_id, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('councilor');
error_log("Done. Imported {$count} councilors, skipped {$skip}.");
