<?php
/**
 * 匯入議員資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-councilor.php            # 匯入資料（upsert）
 *   php scripts/import-councilor.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議員.jsonl（每行一筆 JSON）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 衍生欄位：屆次（整數，從屆代碼解析，供排序用）
 * Doc ID：{議會代碼}-{屆次}-{姓名}
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'     => ['type' => 'keyword'],
        '人物代碼' => ['type' => 'keyword'],
        '參選代碼' => ['type' => 'keyword'],
        '選舉代碼' => ['type' => 'keyword'],
        '年份'     => ['type' => 'keyword'],
        '姓名'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '區域'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '單位'     => ['type' => 'keyword'],
        '職稱'     => ['type' => 'keyword'],
        '黨籍'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '選舉屆次' => ['type' => 'text'],
        '學歷'     => ['type' => 'text'],
        '簡歷'     => ['type' => 'text'],
        '辦公地址' => ['type' => 'keyword', 'index' => false],
        '聯絡電話' => ['type' => 'keyword', 'index' => false],
        '電子信箱' => ['type' => 'keyword', 'index' => false],
        '身分別'   => ['type' => 'keyword'],
        '照片'     => ['type' => 'keyword', 'index' => false],
        '議會代碼' => ['type' => 'keyword'],
        '屆代碼'   => ['type' => 'keyword'],
        '性別'     => ['type' => 'keyword'],
        '出生日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '出生地'   => ['type' => 'keyword'],
        '參選政黨' => ['type' => 'keyword'],
        '參選學歷' => ['type' => 'keyword'],
        // 衍生欄位
        '屆次'     => ['type' => 'integer'],
    ],
];

// 已知的來源欄位（不含衍生欄位 屆次）
$known_source_keys = array_diff(array_keys($index_mapping['properties']), ['屆次']);

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

$jsonl_path = getenv('IMPORT_COUNCILOR_JSONL') ?: (__DIR__ . '/../議員.jsonl');
$fh = fopen($jsonl_path, 'r');
if (!$fh) {
    error_log("Cannot open {$jsonl_path}");
    exit(1);
}

// 跳過 UTF-8 BOM
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$count = 0;
$skip  = 0;
$headers_checked = false;

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

    // 第一筆資料時檢查是否有未知欄位
    if (!$headers_checked) {
        $headers_checked = true;
        $unknown = array_diff(array_keys($record), $known_source_keys);
        if ($unknown) {
            error_log("ERROR: 來源檔案有未定義的欄位，請先在 index_mapping 補上對應設定再匯入：" . implode(', ', $unknown));
            fclose($fh);
            exit(1);
        }
    }

    $cc_code   = $record['議會代碼'] ?? '';
    $term_code = $record['屆代碼']   ?? '';
    $name      = $record['姓名']     ?? '';

    if ($cc_code === '' || $term_code === '' || $name === '') {
        $skip++;
        continue;
    }

    // 從屆代碼取 term 整數（最後一個 '-' 後的數字）
    $term_int = intval(substr($term_code, strrpos($term_code, '-') + 1));

    $doc_id = "{$cc_code}-{$term_int}-{$name}";
    $doc = ['屆次' => $term_int];

    foreach ($record as $key => $val) {
        if ($val === '' || $val === null) continue;
        if ($key === '出生日期') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $doc[$key] = $val;
            }
            continue;
        }
        $doc[$key] = $val;
    }

    Elastic::dbBulkInsert('councilor', $doc_id, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('councilor');
error_log("Done. Imported {$count} councilors, skipped {$skip}.");
