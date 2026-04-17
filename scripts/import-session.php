<?php
/**
 * 匯入會期資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-session.php            # 匯入資料（upsert）
 *   php scripts/import-session.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：會期.csv（欄位：代碼, 議會名稱, 會期名稱, 屆, 會期類別, 次, 開始日期, 結束日期）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 衍生欄位：議會代碼（從代碼和屆推導）
 * Doc ID：{代碼}（例：nan-18-r1）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'       => ['type' => 'keyword'],
        '議會名稱'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '會期名稱'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '屆'         => ['type' => 'integer'],
        '會期類別'   => ['type' => 'keyword'],
        '次'         => ['type' => 'integer'],
        '開始日期'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '結束日期'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        // 衍生欄位
        '議會代碼'   => ['type' => 'keyword'],
    ],
];

// 已知的來源欄位（不含衍生欄位 議會代碼）
$known_source_keys = ['代碼', '議會名稱', '會期名稱', '屆', '會期類別', '次', '開始日期', '結束日期'];

if ($reset) {
    try {
        Elastic::dropIndex('session');
        error_log("Dropped index: session");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

// 建立 index（如不存在）
try {
    Elastic::createIndex('session', $index_mapping);
    error_log("Created index: session");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

// 讀取來源檔案路徑
$csv_path = getenv('IMPORT_SESSION_CSV');
if (!$csv_path) {
    $csv_path = __DIR__ . '/../會期.csv';
}
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到會期.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

// 檢查未知欄位
$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-session.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

$count = 0;
$errors = 0;

while (($row = fgetcsv($fh)) !== false) {
    $data = array_combine($headers, $row);

    // 推導議會代碼：從代碼中找出 `-{屆}-` 的位置，前面的部分即議會代碼
    $term = $data['屆'];
    $pos = strpos($data['代碼'], "-{$term}-");
    if ($pos === false) {
        // fallback：取第一個 `-` 前的部分
        $cc_code = explode('-', $data['代碼'])[0];
    } else {
        $cc_code = substr($data['代碼'], 0, $pos);
    }
    $data['議會代碼'] = $cc_code;

    // 型別轉換
    $data['屆'] = (int)$data['屆'];
    $data['次'] = (int)$data['次'];

    // 空白日期轉 null（ES 不接受空字串 date）
    foreach (['開始日期', '結束日期'] as $f) {
        if (isset($data[$f]) && trim($data[$f]) === '') {
            $data[$f] = null;
        }
    }

    $doc_id = $data['代碼'];

    try {
        Elastic::dbBulkInsert('session', $doc_id, $data);
        $count++;
        if ($count % 100 === 0) {
            error_log("Imported {$count} sessions...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('session');
error_log("Done. Imported: {$count}, Errors: {$errors}");
