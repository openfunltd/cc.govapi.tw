<?php
/**
 * 匯入場次資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-sitting.php            # 匯入資料（upsert）
 *   php scripts/import-sitting.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：場次.csv（欄位：代碼, 會期代碼, 日期, 星期, 時段, 會次, 場次類別, 委員會名稱, 議程說明, 停會原因, 開始時間, 結束時間）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 衍生欄位：議會代碼、屆（從會期代碼推導，格式同會期的代碼：{議會代碼}-{屆}-...）
 * Doc ID：{代碼}（例：tpe-14-r7-20220408-am）
 *
 * 目前資料來源涵蓋 19 個議會（缺基隆市、彰化縣、連江縣，尚無場次層級資料）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'       => ['type' => 'keyword'],
        '會期代碼'   => ['type' => 'keyword'],
        '日期'       => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '星期'       => ['type' => 'keyword'],
        '時段'       => ['type' => 'keyword'],
        '會次'       => ['type' => 'integer'],
        '場次類別'   => ['type' => 'keyword'],
        '委員會名稱' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '議程說明'   => ['type' => 'text'],
        '停會原因'   => ['type' => 'text'],
        '開始時間'   => ['type' => 'keyword'],
        '結束時間'   => ['type' => 'keyword'],
        // 衍生欄位
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
    ],
];

// 已知的來源欄位（不含衍生欄位 議會代碼、屆）
$known_source_keys = [
    '代碼', '會期代碼', '日期', '星期', '時段', '會次',
    '場次類別', '委員會名稱', '議程說明', '停會原因', '開始時間', '結束時間',
];

if ($reset) {
    try {
        Elastic::dropIndex('sitting');
        error_log("Dropped index: sitting");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

// 建立 index（如不存在）
try {
    Elastic::createIndex('sitting', $index_mapping);
    error_log("Created index: sitting");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

// 讀取來源檔案路徑
$csv_path = getenv('IMPORT_SITTING_CSV');
if (!$csv_path) {
    $csv_path = __DIR__ . '/../場次.csv';
}
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到場次.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

// 檢查未知欄位
$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-sitting.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

$count = 0;
$errors = 0;

while (($row = fgetcsv($fh)) !== false) {
    $data = array_combine($headers, $row);

    // 推導議會代碼、屆：會期代碼格式為 {議會代碼}-{屆}-{會期類別縮寫}{次}，取前兩段
    $terms = explode('-', $data['會期代碼']);
    $data['議會代碼'] = $terms[0] ?? '';
    $data['屆'] = isset($terms[1]) ? (int)$terms[1] : null;

    // 空白日期轉 null
    if (isset($data['日期']) && trim($data['日期']) === '') {
        $data['日期'] = null;
    }

    // 會次為空白時轉 null（而非 0），避免誤導「第 0 次」
    if (isset($data['會次']) && trim($data['會次']) !== '') {
        $data['會次'] = (int)$data['會次'];
    } else {
        $data['會次'] = null;
    }

    // 其餘空白文字欄位轉 null
    foreach (['時段', '委員會名稱', '停會原因', '開始時間', '結束時間'] as $f) {
        if (isset($data[$f]) && trim($data[$f]) === '') {
            $data[$f] = null;
        }
    }

    $doc_id = $data['代碼'];

    try {
        Elastic::dbBulkInsert('sitting', $doc_id, $data);
        $count++;
        if ($count % 1000 === 0) {
            error_log("Imported {$count} sittings...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('sitting');
error_log("Done. Imported: {$count}, Errors: {$errors}");
