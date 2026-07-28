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
 * 組裝規則：同一個代碼的多筆，依（委員會, 順序）排序後依序串接
 * 衍生欄位：議會代碼、屆、會期代碼 —— 優先查詢既有 sitting index（該筆場次匯入時已算好，
 *           避免重複實作字串解析），查不到才退回用代碼字串自行解析
 * Doc ID：{代碼}（跟場次代碼一致，一對一）
 *
 * 目前資料來源涵蓋 13 個議會、約 7,200 個場次（占全部場次約 2 成），其餘議會多為
 * 結構性缺口（逐字稿另外公布在別處、上游場次資料本身對不上、或圖片尚待 OCR），
 * 並非匯入疏漏。
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
        '內容'       => ['type' => 'text'],
        '來源分類'   => ['type' => 'keyword'],
        '檔案數'     => ['type' => 'integer'],
        '字數'       => ['type' => 'integer'],
        'updated_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    ],
];

if ($reset) {
    try { Elastic::dropIndex('transcript'); error_log("Dropped index: transcript"); }
    catch (Exception $e) { error_log("Drop skipped: " . $e->getMessage()); }
}

try { Elastic::createIndex('transcript', $index_mapping); error_log("Created index: transcript"); }
catch (Exception $e) { error_log("Index exists: " . $e->getMessage()); }

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
 * 查詢既有 sitting index 取得 議會代碼/屆/會期代碼（該筆場次匯入時已算好）；
 * 查不到（理論上不應發生）才退回自行解析代碼字串
 */
function derive_sitting_context($code)
{
    try {
        $r = Elastic::dbQuery('/{prefix}sitting/_doc/' . rawurlencode($code), 'GET');
        if ($r->found ?? false) {
            $s = $r->_source;
            return [
                '議會代碼' => $s->{'議會代碼'} ?? null,
                '屆'       => $s->{'屆'} ?? null,
                '會期代碼' => $s->{'會期代碼'} ?? null,
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
    ];
}

// ── 3. 逐代碼組裝內容並寫入 ──────────────────────────────────────────────────

$count = 0;
$skipped_files = 0;
$processed = 0;

foreach ($groups as $code => $rows) {
    usort($rows, function ($a, $b) {
        $c = strcmp($a['委員會'], $b['委員會']);
        if ($c !== 0) return $c;
        return ((int)($a['順序'] ?: 0)) <=> ((int)($b['順序'] ?: 0));
    });

    $sections = [];
    $source_types = [];
    $file_count = 0;

    foreach ($rows as $row) {
        $text = read_doc_file($base_dir, $row['檔案路徑']);
        if ($text === null || $text === '') {
            $skipped_files++;
            continue;
        }
        $label = trim(($row['來源分類'] ?: '') . ($row['委員會'] ? "・{$row['委員會']}" : ''));
        $sections[] = $label ? "【{$label}】\n{$text}" : $text;
        if ($row['來源分類']) {
            $source_types[$row['來源分類']] = true;
        }
        $file_count++;
    }

    if (!$file_count) {
        continue;   // 這個代碼所有檔案都讀不到，跳過
    }

    $content = implode("\n\n----\n\n", $sections);
    $context = derive_sitting_context($code);

    $doc = [
        '代碼'       => $code,
        '議會代碼'   => $context['議會代碼'],
        '屆'         => $context['屆'],
        '會期代碼'   => $context['會期代碼'],
        '內容'       => $content,
        '來源分類'   => array_keys($source_types),
        '檔案數'     => $file_count,
        '字數'       => mb_strlen($content),
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
error_log("Done. Imported: {$count}, 讀取失敗檔案數: {$skipped_files}");
