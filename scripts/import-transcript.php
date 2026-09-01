<?php
/**
 * 匯入逐字稿資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-transcript.php            # 匯入資料（upsert）
 *   php scripts/import-transcript.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：
 *   - 逐字稿索引.csv（欄位：代碼, 委員會, 順序, 檔案路徑, 來源分類）
 *     代碼＝場次（sitting）代碼，一個代碼可能對應多筆（多檔案／多委員會平行審查需組裝）
 *   - 逐字稿索引.csv 的「檔案路徑」是相對路徑，實際文字檔放在 IMPORT_TRANSCRIPT_BASE_DIR 底下
 *     檔案格式為 .txt（純文字）或 .html（原始網頁，需去標籤取文字）
 *
 * 組裝規則：同一個代碼的多筆，先依（來源分類, 委員會）分組（同一場次可能同時有大會
 *           會議紀錄、各委員會審查會議事錄等不同來源，分開存放才能在前端各自分頁顯示），
 *           組內再依順序排序後串接（同一份文件常拆成多頁/多檔）
 * 衍生欄位：議會代碼、屆、會期代碼 —— 優先查詢既有 sitting index（該筆場次匯入時已算好，
 *           避免重複實作字串解析），查不到才退回用代碼字串自行解析
 * Doc ID：{代碼}（跟場次代碼一致，一對一）
 *
 * 目前資料來源涵蓋 13 個議會、約 7,200 個場次（占全部場次約 2 成），其餘議會多為
 * 結構性缺口（逐字稿另外公布在別處、上游場次資料本身對不上、或圖片尚待 OCR），
 * 並非匯入疏漏。
 *
 * 加速機制：每個代碼組裝前先算「來源簽章」（該代碼底下每筆索引列 + 對應檔案的
 * mtime/size 組出來的 md5），跟 ES 裡既有的來源簽章比對，沒變就整組跳過（連檔案內容
 * 都不用讀），只有真的有變的場次才重新組裝、重新寫入。--reset 時 ES 是空的，等於
 * 全部視為有變化，會重新處理每一筆。
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);
$today_str = (new DateTimeImmutable('today'))->format('Y-m-d');
$commit_every = 200;

// ── ES index 設定 ────────────────────────────────────────────────────────────

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
        '會期代碼'   => ['type' => 'keyword'],
        '日期'       => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '年'         => ['type' => 'integer'],
        '內容'       => ['type' => 'text'],
        '來源分類'   => ['type' => 'keyword'],
        '檔案數'     => ['type' => 'integer'],
        '字數'       => ['type' => 'integer'],
        // 分段內容（依來源分類/委員會分組），供前端分 tab 顯示
        '分段'       => ['type' => 'nested', 'dynamic' => true],
        '來源簽章'   => ['type' => 'keyword', 'index' => false],
        'updated_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    ],
];

if ($reset) {
    try { Elastic::dropIndex('transcript'); error_log("Dropped index: transcript"); }
    catch (Exception $e) { error_log("Drop skipped: " . $e->getMessage()); }
}

try { Elastic::createIndex('transcript', $index_mapping); error_log("Created index: transcript"); }
catch (Exception $e) { error_log("Index exists: " . $e->getMessage()); }

// index 已存在時（非 --reset）也嘗試更新 mapping，讓新增的來源欄位（例：來源簽章）
// 套用正確型別（ES 允許為既有 index 補新欄位定義，不影響既有欄位，重複執行也安全）
try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}transcript/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

// ── 讀取來源路徑 ─────────────────────────────────────────────────────────────

$csv_path = getenv('IMPORT_TRANSCRIPT_CSV') ?: (__DIR__ . '/../逐字稿索引.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到逐字稿索引.csv：{$csv_path}");
    exit(1);
}

$base_dir = getenv('IMPORT_TRANSCRIPT_BASE_DIR') ?: (__DIR__ . '/../逐字稿');
$base_dir = rtrim($base_dir, '/');
if (!is_dir($base_dir)) {
    error_log("ERROR: 找不到逐字稿檔案目錄：{$base_dir}");
    exit(1);
}

// ── 1. 讀取索引，依代碼分組 ──────────────────────────────────────────────────

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);
$known_source_keys = ['代碼', '委員會', '順序', '檔案路徑', '來源分類'];
$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    exit(1);
}

$groups = [];   // 代碼 => [row, row, ...]
while (($row = fgetcsv($fh)) !== false) {
    $data = array_combine($headers, $row);
    $groups[$data['代碼']][] = $data;
}
fclose($fh);
error_log("讀取逐字稿索引：" . count($groups) . " 個場次代碼");

// ── 2. HTML 去標籤取文字（來源檔案有 .txt 也有 .html 兩種格式）──────────────

function html_to_text($html)
{
    $text = preg_replace('#<script.*?</script>#is', '', $html);
    $text = preg_replace('#<style.*?</style>#is', '', $text);
    $text = preg_replace('#<[^>]+>#', ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n\s*\n+/u', "\n", $text);
    return trim($text);
}

function read_doc_file($base_dir, $relative_path)
{
    $full_path = $base_dir . '/' . $relative_path;
    if (!file_exists($full_path)) {
        return null;
    }
    $raw = file_get_contents($full_path);
    if ($raw === false) {
        return null;
    }
    $ext = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
    return ($ext === 'html' || $ext === 'htm') ? html_to_text($raw) : trim($raw);
}

/**
 * 查詢既有 sitting index 取得 議會代碼/屆/會期代碼/日期（該筆場次匯入時已算好）；
 * 查不到（理論上不應發生）才退回自行解析代碼字串（日期部分只能放棄，因為代碼裡的
 * 日期段是場次自己組的格式，沒有 sitting 資料時無法可靠反推）
 */
function derive_sitting_context($code)
{
    try {
        $r = Elastic::dbQuery('/{prefix}sitting/_doc/' . rawurlencode($code), 'GET');
        if ($r->found ?? false) {
            $s = $r->_source;
            $date = $s->{'日期'} ?? null;
            return [
                '議會代碼' => $s->{'議會代碼'} ?? null,
                '屆'       => $s->{'屆'} ?? null,
                '會期代碼' => $s->{'會期代碼'} ?? null,
                '日期'     => $date,
                '年'       => $date ? (int)substr($date, 0, 4) : null,
            ];
        }
    } catch (Exception $e) {
        // 找不到就退回字串解析
    }
    $parts = explode('-', $code);
    return [
        '議會代碼' => $parts[0] ?? null,
        '屆'       => isset($parts[1]) ? (int)$parts[1] : null,
        '會期代碼' => (isset($parts[0]) && isset($parts[1]) && isset($parts[2])) ? "{$parts[0]}-{$parts[1]}-{$parts[2]}" : null,
        '日期'     => null,
        '年'       => null,
    ];
}

// ── 3. 讀取既有的來源簽章（判斷哪些代碼可以整組跳過不重新處理）─────────────────

$existing_sig = [];
try {
    $prefix = getenv('ELASTIC_PREFIX');
    $ret = Elastic::dbQuery("/{$prefix}transcript/_search", 'POST', json_encode([
        'size' => 10000,
        '_source' => ['代碼', '來源簽章'],
        'query' => (object)['match_all' => (object)[]],
    ]));
    foreach ($ret->hits->hits as $hit) {
        if (isset($hit->_source->{'來源簽章'})) {
            $existing_sig[$hit->_source->{'代碼'}] = $hit->_source->{'來源簽章'};
        }
    }
    error_log("讀取既有來源簽章：" . count($existing_sig) . " 筆");
} catch (Exception $e) {
    error_log("讀取既有來源簽章失敗（視為全部重新處理）：" . $e->getMessage());
}

// ── 4. 逐代碼組裝內容並寫入 ──────────────────────────────────────────────────

$count = 0;
$skipped_files = 0;
$skipped_unchanged = 0;
$processed = 0;

foreach ($groups as $code => $rows) {
    // 來源簽章：每筆索引列本身的內容 + 對應檔案的 mtime/size，兩者都沒變才跳過，
    // 涵蓋「檔案內容改了」跟「索引列改分類/順序但檔案沒動」兩種情況
    $sig_parts = [];
    foreach ($rows as $row) {
        $full_path = $base_dir . '/' . $row['檔案路徑'];
        $file_stat = file_exists($full_path) ? (filemtime($full_path) . ':' . filesize($full_path)) : 'missing';
        $sig_parts[] = json_encode($row) . ':' . $file_stat;
    }
    sort($sig_parts);
    $signature = md5(implode('|', $sig_parts));

    if (($existing_sig[$code] ?? null) === $signature) {
        $skipped_unchanged++;
        continue;
    }

    // 先依（來源分類, 委員會）分組，組內再依順序排序
    $by_group = [];   // "{來源分類}|{委員會}" => [row, ...]
    foreach ($rows as $row) {
        $group_key = ($row['來源分類'] ?: '') . '|' . ($row['委員會'] ?: '');
        $by_group[$group_key][] = $row;
    }

    $sections = [];
    $source_types = [];
    $file_count = 0;
    $content_parts = [];

    foreach ($by_group as $group_rows) {
        usort($group_rows, fn($a, $b) => ((int)($a['順序'] ?: 0)) <=> ((int)($b['順序'] ?: 0)));

        $texts = [];
        foreach ($group_rows as $row) {
            $text = read_doc_file($base_dir, $row['檔案路徑']);
            if ($text === null || $text === '') {
                $skipped_files++;
                continue;
            }
            $texts[] = $text;
            $file_count++;
        }
        if (!$texts) {
            continue;   // 這組所有檔案都讀不到，跳過這個分組
        }

        $section_content = implode("\n\n", $texts);
        $source_type = $group_rows[0]['來源分類'] ?: '';
        $committee = $group_rows[0]['委員會'] ?: '';
        $label = trim($source_type . ($committee ? "・{$committee}" : '')) ?: '逐字稿';

        $sections[] = [
            '標籤' => $label,
            '內容' => $section_content,
            '字數' => mb_strlen($section_content),
        ];
        if ($source_type) {
            $source_types[$source_type] = true;
        }
        $content_parts[] = $section_content;
    }

    if (!$sections) {
        continue;   // 這個代碼所有分組都讀不到檔案，跳過
    }

    $content = implode("\n\n----\n\n", $content_parts);
    $context = derive_sitting_context($code);

    $doc = [
        '代碼'       => $code,
        '議會代碼'   => $context['議會代碼'],
        '屆'         => $context['屆'],
        '會期代碼'   => $context['會期代碼'],
        '日期'       => $context['日期'],
        '年'         => $context['年'],
        '內容'       => $content,
        '來源分類'   => array_keys($source_types),
        '檔案數'     => $file_count,
        '字數'       => mb_strlen($content),
        '分段'       => $sections,
        '來源簽章'   => $signature,
        'updated_at' => $today_str,
    ];

    Elastic::dbBulkInsert('transcript', $code, $doc);
    $count++;
    $processed++;

    if ($processed % $commit_every === 0) {
        Elastic::dbBulkCommit('transcript');
        error_log("Imported {$processed} / " . count($groups) . " transcripts...");
    }
}

Elastic::dbBulkCommit('transcript');
error_log("Done. Imported: {$count}, 來源未變跳過: {$skipped_unchanged}, 讀取失敗檔案數: {$skipped_files}");
