<?php
/**
 * 匯入逐句發言資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-speech.php            # 匯入資料（upsert）
 *   php scripts/import-speech.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：IMPORT_SPEECH_CSV_DIR 目錄下的 逐字稿-{議會代碼}-{年月}.csv（依議會＋年月拆檔，
 * 目前約 869 個檔案、共 511 萬筆），欄位：代碼, 縣市, 場次代碼, 日期, 時段, 順序,
 * 原始標記, 姓名, 職稱, 身分類別, 對應代碼, 對應代碼類型, 對應單位全名, 發言內容,
 * 來源檔案, 來源頁碼, 印刷頁碼, 來源網址, 議程代碼
 * 所有來源欄位直接沿用原始名稱匯入 ES；「對應代碼」在身分類別＝議員時對應 councilor 的
 * 「代碼」欄位（實測精準比對成功）
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆／會期代碼：「場次代碼」有值時查既有 sitting index 取得（同一個場次代碼在同一個
 *     檔案內會重複出現很多次，用行程內快取避免對同一個代碼重複查 ES）
 * Doc ID：代碼本身（來源已保證唯一）
 *
 * 效能設計（511 萬筆是目前最大 index 的近百倍，不能每天全量重讀重寫）：
 *   本地存一份 .speech-import-state.json（gitignored，比照 auto-refresh.php 的
 *   .auto-refresh-state.json 慣例），記錄每個檔案的 mtime+size；沒有變化的檔案整個
 *   跳過（不開檔、不讀取、不送 ES），只處理真的新增/變動的檔案，讓之後的排程匯入
 *   只需要處理增量檔案
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'       => ['type' => 'keyword'],
        '縣市'       => ['type' => 'keyword'],
        '場次代碼'   => ['type' => 'keyword'],
        '日期'       => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '時段'       => ['type' => 'keyword'],
        '順序'       => ['type' => 'integer'],
        '原始標記'   => ['type' => 'keyword'],
        '姓名'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '職稱'       => ['type' => 'keyword'],
        '身分類別'   => ['type' => 'keyword'],
        '對應代碼'   => ['type' => 'keyword'],
        '對應代碼類型' => ['type' => 'keyword'],
        '對應單位全名' => ['type' => 'keyword'],
        '發言內容'   => ['type' => 'text'],
        '來源檔案'   => ['type' => 'keyword'],
        // 來源頁碼／印刷頁碼實測有 "786-787" 這種範圍格式，不是單一整數，比照候選人
        // 「來源頁碼」欄位的教訓，用 keyword 不要用 integer（否則 ES 400 拒絕整份文件）
        '來源頁碼'   => ['type' => 'keyword'],
        '印刷頁碼'   => ['type' => 'keyword'],
        '來源網址'   => ['type' => 'keyword', 'index' => false],
        '議程代碼'   => ['type' => 'keyword'],
        // 衍生欄位
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
        '會期代碼'   => ['type' => 'keyword'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '日期', '時段', '順序', '原始標記', '姓名', '職稱',
    '身分類別', '對應代碼', '對應代碼類型', '對應單位全名', '發言內容', '來源檔案',
    '來源頁碼', '印刷頁碼', '來源網址', '議程代碼',
];

if ($reset) {
    try { Elastic::dropIndex('speech'); error_log("Dropped index: speech"); }
    catch (Exception $e) { error_log("Drop skipped: " . $e->getMessage()); }
}

try { Elastic::createIndex('speech', $index_mapping); error_log("Created index: speech"); }
catch (Exception $e) { error_log("Index exists: " . $e->getMessage()); }

$dir = getenv('IMPORT_SPEECH_CSV_DIR') ?: (__DIR__ . '/..');
$dir = rtrim($dir, '/');
$files = glob($dir . '/逐字稿-*.csv');
if (!$files) {
    error_log("ERROR: 在 {$dir} 找不到任何 逐字稿-*.csv 檔案");
    exit(1);
}
sort($files);
error_log('找到 ' . count($files) . ' 個來源檔案');

// ── 檔案層級跳過機制：沒有變化的檔案整個略過 ──────────────────────────────────

$state_path = __DIR__ . '/../.speech-import-state.json';
$state = file_exists($state_path) ? json_decode(file_get_contents($state_path), true) : [];
if (!is_array($state)) {
    $state = [];
}

function file_signature($path)
{
    $stat = stat($path);
    return "{$stat['mtime']}:{$stat['size']}";
}

function save_state($state_path, $state)
{
    file_put_contents($state_path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── 場次代碼 → 屆/會期代碼 查詢快取（同一個場次代碼在同一個檔案內會重複很多次）───

$sitting_context_cache = [];

function derive_sitting_context($code, &$cache)
{
    if (!$code) {
        return ['屆' => null, '會期代碼' => null];
    }
    if (array_key_exists($code, $cache)) {
        return $cache[$code];
    }
    $context = ['屆' => null, '會期代碼' => null];
    try {
        $r = Elastic::dbQuery('/{prefix}sitting/_doc/' . rawurlencode($code), 'GET');
        if ($r->found ?? false) {
            $s = $r->_source;
            $context = [
                '屆'       => $s->{'屆'} ?? null,
                '會期代碼' => $s->{'會期代碼'} ?? null,
            ];
        }
    } catch (Exception $e) {
        // 查不到就留空
    }
    $cache[$code] = $context;
    return $context;
}

$county_to_cc_code = CountyCodeHelper::getMap();

$total_count = 0;
$total_errors = 0;
$total_malformed = 0;
$skipped_files = 0;
$processed_files = 0;

foreach ($files as $path) {
    $fname = basename($path);
    $sig = file_signature($path);
    if (!$reset && ($state[$fname] ?? null) === $sig) {
        $skipped_files++;
        continue;
    }

    $fh = fopen($path, 'r');
    $headers = fgetcsv($fh);
    $unknown = array_diff($headers, $known_source_keys);
    if ($unknown) {
        error_log("ERROR: {$fname} 出現未知欄位：" . implode(', ', $unknown));
        error_log("請先在 import-speech.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
        fclose($fh);
        exit(1);
    }

    $file_count = 0;
    $file_errors = 0;
    $file_malformed = 0;

    while (($row = fgetcsv($fh)) !== false) {
        // 少數列的欄位數跟表頭對不上（實測來源偶爾有未跳脫的逗號跑進「發言內容」，
        // 撐開多出來的欄位），跳過這幾筆而不是整支腳本中斷，避免因為一筆壞資料
        // 漏掉整個檔案其餘幾千筆正常資料
        if (count($row) !== count($headers)) {
            $file_malformed++;
            continue;
        }
        $data = array_combine($headers, $row);

        // 實測桃園市有部分欄位（例：姓名）被來源截斷到一個多位元組 UTF-8 字元中間，
        // 產生不合法的位元組序列，json_encode() 遇到這種值會整筆失敗、被
        // Elastic::dbBulkInsert() 靜默丟棄（518 筆案例）。用 mb_scrub() 把不合法的
        // 位元組換成替代字元，讓整筆資料還能匯入，只有壞掉的那個欄位變成帶
        // 替代字元的殘缺文字（好過整筆資料完全消失查不到）
        foreach ($data as $k => $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $data[$k] = mb_scrub($v, 'UTF-8');
            }
        }

        $doc = $data;

        // 空白日期轉 null
        if (isset($doc['日期']) && trim($doc['日期']) === '') {
            $doc['日期'] = null;
        }
        // 空白文字欄位轉 null
        foreach (['時段', '對應代碼', '對應代碼類型', '對應單位全名', '來源頁碼', '印刷頁碼', '議程代碼', '場次代碼'] as $f) {
            if (isset($doc[$f]) && trim($doc[$f]) === '') {
                $doc[$f] = null;
            }
        }
        if (isset($doc['順序']) && $doc['順序'] !== '') {
            $doc['順序'] = (int)$doc['順序'];
        }

        $doc['議會代碼'] = $county_to_cc_code[$data['縣市']] ?? null;

        $context = derive_sitting_context($doc['場次代碼'], $sitting_context_cache);
        $doc['屆'] = $context['屆'];
        $doc['會期代碼'] = $context['會期代碼'];

        $doc_id = $data['代碼'];

        try {
            Elastic::dbBulkInsert('speech', $doc_id, $doc);
            $file_count++;
        } catch (Exception $e) {
            error_log("ERROR on {$doc_id}: " . $e->getMessage());
            $file_errors++;
        }
    }
    fclose($fh);
    Elastic::dbBulkCommit('speech');

    $total_count += $file_count;
    $total_errors += $file_errors;
    $total_malformed += $file_malformed;
    $processed_files++;
    $state[$fname] = $sig;
    // 每個檔案處理完就存檔，中途被中斷也不用整批重來
    save_state($state_path, $state);

    $malformed_note = $file_malformed ? "，欄位數異常跳過 {$file_malformed} 筆" : '';
    error_log("[{$fname}] {$file_count} 筆（累計 {$processed_files} 個檔案、{$total_count} 筆）{$malformed_note}");
}

error_log("Done. 處理檔案: {$processed_files}, 略過未變動檔案: {$skipped_files}, 匯入: {$total_count}, Errors: {$total_errors}, 欄位數異常跳過: {$total_malformed}");
