<?php
/**
 * 匯入會議逐字稿（meet_transcript）資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-meet-transcript.php            # 匯入資料（upsert）
 *   php scripts/import-meet-transcript.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：meet_transcripts.csv（單一檔案，約523萬筆，欄位：代碼, 縣市, 場次代碼,
 * 會議代碼, 日期, 順序, 原始標記, 姓名, 職稱, 身分類別, 對應代碼, 對應代碼類型,
 * 對應單位全名, 發言內容, 來源頁碼, 印刷頁碼）
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆／會期代碼：跟import-speech.php同樣做法，查既有sitting index取得（同一個
 *     場次代碼在檔案內會重複很多次，用行程內快取避免重複查ES）
 * Doc ID：代碼本身（例：tpe-8320eaa898-0）
 *
 * 效能設計：這個來源跟舊speech不同，是單一大檔案而非依縣市+年月拆檔，所以沒有
 * 沿用speech的.state.json檔案級跳過機制（單一檔案任何一行變動都會讓整份mtime
 * 改變，檔案級跳過在這裡起不了作用）。第一版採用最單純的做法：每次執行都整份
 * 重讀重寫，靠ES的upsert語意保證重覆匯入是安全的，只是比較慢（約523萬筆）。
 * 之後如果排程頻率造成負擔，再考慮換成逐筆比對雜湊或其他增量策略。
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        '代碼'         => ['type' => 'keyword'],
        '縣市'         => ['type' => 'keyword'],
        '場次代碼'     => ['type' => 'keyword'],
        '會議代碼'     => ['type' => 'keyword'],
        '日期'         => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '順序'         => ['type' => 'integer'],
        '原始標記'     => ['type' => 'keyword'],
        '姓名'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '職稱'         => ['type' => 'keyword'],
        '身分類別'     => ['type' => 'keyword'],
        '對應代碼'     => ['type' => 'keyword'],
        '對應代碼類型' => ['type' => 'keyword'],
        '對應單位全名' => ['type' => 'keyword'],
        '發言內容'     => ['type' => 'text'],
        // 來源頁碼／印刷頁碼實測有 "786-787" 這種範圍格式，比照speech的教訓用
        // keyword不要用integer（否則ES 400拒絕整份文件）
        '來源頁碼'     => ['type' => 'keyword'],
        '印刷頁碼'     => ['type' => 'keyword'],
        // 衍生欄位
        '議會代碼'     => ['type' => 'keyword'],
        '屆'           => ['type' => 'integer'],
        '會期代碼'     => ['type' => 'keyword'],
        // 從「日期」取前4碼，給搜尋頁「逐字稿」分頁的年份篩選用（取代舊
        // transcript type的「年」欄位）
        '年'           => ['type' => 'integer'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '會議代碼', '日期', '順序', '原始標記', '姓名', '職稱',
    '身分類別', '對應代碼', '對應代碼類型', '對應單位全名', '發言內容', '來源頁碼', '印刷頁碼',
];

if ($reset) {
    try {
        Elastic::dropIndex('meet_transcript');
        error_log("Dropped index: meet_transcript");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('meet_transcript', $index_mapping);
    error_log("Created index: meet_transcript");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}meet_transcript/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_MEET_TRANSCRIPT_CSV') ?: (__DIR__ . '/../meet_transcripts.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到meet_transcripts.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-meet-transcript.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

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

$count = 0;
$errors = 0;
$malformed = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers)) {
        $malformed++;
        continue;
    }
    $data = array_combine($headers, $row);

    foreach ($data as $k => $v) {
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            $data[$k] = mb_scrub($v, 'UTF-8');
        }
    }

    $doc = $data;

    if (isset($doc['日期']) && trim($doc['日期']) === '') {
        $doc['日期'] = null;
    }
    foreach (['場次代碼', '會議代碼', '職稱', '對應代碼', '對應代碼類型', '對應單位全名', '來源頁碼', '印刷頁碼'] as $f) {
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
    $doc['年'] = $doc['日期'] ? (int)substr($doc['日期'], 0, 4) : null;

    $doc_id = $data['代碼'];

    try {
        Elastic::dbBulkInsert('meet_transcript', $doc_id, $doc);
        $count++;
        if ($count % 10000 === 0) {
            error_log("Imported {$count} meet_transcripts...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('meet_transcript');
error_log("Done. Imported: {$count}, Errors: {$errors}, 欄位數異常跳過: {$malformed}");
