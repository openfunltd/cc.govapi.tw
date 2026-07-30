<?php
/**
 * 匯入議案資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-bill.php            # 匯入資料（upsert）
 *   php scripts/import-bill.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議案.jsonl（每行一筆 JSON，欄位：代碼/縣市/類別/案號/提案單位/提案人/
 * 連署人/案由/說明/辦法/審查意見/議決/來源檔案/來源頁碼）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 衍生欄位：
 *   議會代碼：從「代碼」欄位第一段解析（例：yun-44d4ef6b-民甲200 → yun）
 *   屆：從「來源檔案」檔名解析「第N屆」（來源目前沒有屆/會期/場次的正式關聯
 *       欄位，只能從檔名反推，且無法精確對應到會期或場次——一份議事錄常常
 *       橫跨一個定期會加多個臨時會，解析不到時這個欄位就沒有值）
 * Doc ID：代碼本身（來源已保證跨議會唯一）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'     => ['type' => 'keyword'],
        '縣市'     => ['type' => 'keyword'],
        '類別'     => ['type' => 'keyword'],
        '案號'     => ['type' => 'keyword'],
        '提案單位' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '提案人'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '連署人'   => ['type' => 'text'],
        '案由'     => ['type' => 'text'],
        '說明'     => ['type' => 'text'],
        '辦法'     => ['type' => 'text'],
        '審查意見' => ['type' => 'text'],
        '議決'     => ['type' => 'text'],
        '來源檔案' => ['type' => 'keyword'],
        '來源頁碼' => ['type' => 'keyword'],
        // 衍生欄位
        '議會代碼' => ['type' => 'keyword'],
        '屆'       => ['type' => 'integer'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '類別', '案號', '提案單位', '提案人', '連署人',
    '案由', '說明', '辦法', '審查意見', '議決', '來源檔案', '來源頁碼',
];

if ($reset) {
    try {
        Elastic::dropIndex('bill');
        error_log("Dropped index: bill");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('bill', $index_mapping);
    error_log("Created index: bill");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

$jsonl_path = getenv('IMPORT_BILL_JSONL') ?: (__DIR__ . '/../議案.jsonl');
if (!file_exists($jsonl_path)) {
    error_log("ERROR: 找不到議案.jsonl：{$jsonl_path}");
    exit(1);
}

$fh = fopen($jsonl_path, 'r');
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$count = 0;
$skip = 0;
$headers_checked = false;
// 「代碼」來源上不保證跨紀錄唯一：實測新北市有 36 組「同一份文件裡，
// 同一類別+同一案號卻是完全不同的議案」（例：同一份法規類議事錄裡，
// 案號「一」出現 3 次，各自是不同的自治條例草案）。若直接拿「代碼」當
// ES doc ID 會被 upsert 覆蓋掉，靜默遺失資料，這裡改成偵測到重複時
// 對 doc ID 加上序號後綴，確保每筆來源記錄都保留下來
$seen_codes = [];

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

    if (!$headers_checked) {
        $headers_checked = true;
        $unknown = array_diff(array_keys($record), $known_source_keys);
        if ($unknown) {
            error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
            error_log("請先在 import-bill.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
            fclose($fh);
            exit(1);
        }
    }

    $code = $record['代碼'] ?? '';
    if ($code === '') {
        $skip++;
        continue;
    }

    // 從「代碼」第一段解析議會代碼（例：yun-44d4ef6b-民甲200 → yun）
    $cc_code = explode('-', $code)[0];

    // 從「來源檔案」檔名解析屆次，解析不到就不寫入這個欄位
    $term = null;
    if (preg_match('/第?(\d+)屆/u', $record['來源檔案'] ?? '', $m)) {
        $term = (int)$m[1];
    }

    $doc = ['議會代碼' => $cc_code];
    if ($term !== null) {
        $doc['屆'] = $term;
    }

    foreach ($record as $key => $val) {
        if ($val === '' || $val === null) continue;
        $doc[$key] = $val;
    }

    $seen_codes[$code] = ($seen_codes[$code] ?? 0) + 1;
    $doc_id = $seen_codes[$code] > 1 ? "{$code}-dup{$seen_codes[$code]}" : $code;

    Elastic::dbBulkInsert('bill', $doc_id, $doc);
    $count++;
    if ($count % 500 === 0) {
        error_log("Imported {$count} bills...");
    }
}
fclose($fh);

Elastic::dbBulkCommit('bill');
error_log("Done. Imported {$count} bills, skipped {$skip}.");
