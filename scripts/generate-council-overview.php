<?php
/**
 * 計算各議會現況快照（給議會資訊頁 /info 用），寫入 ES overview index
 *
 * 用法：
 *   php scripts/generate-council-overview.php            # 計算並寫入
 *   php scripts/generate-council-overview.php --reset    # 先刪除 index 再重建
 *
 * 「目前或最近會期」判斷邏輯（不依賴會期的結束日期，因為延長會期時該欄位不一定同步更新）：
 *   1. 取該議會「開始日期 <= 今天」裡最新的一個會期
 *   2. 若該會期底下有「日期 >= 今天」的場次 → 狀態 ongoing，場次片段取最近幾筆（含今天/未來）
 *   3. 否則 → 狀態 ended，場次片段取該會期最後幾筆（已結束）
 *   4. 若議會完全沒有會期資料 → 會期為 null
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);
$today = new DateTimeImmutable('today');
$today_str = $today->format('Y-m-d');
$sitting_snapshot_size = 5;

// ── ES index 設定 ────────────────────────────────────────────────────────────

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '議會名稱'   => ['type' => 'keyword'],
        '議會類別'   => ['type' => 'keyword'],
        '現存'       => ['type' => 'boolean'],
        '屆次'       => ['type' => 'integer'],
        '就職日'     => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '任期屆滿日' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '議長姓名'   => ['type' => 'keyword'],
        '副議長姓名' => ['type' => 'keyword'],
        '議員人數'   => ['type' => 'integer'],
        '會期'       => ['type' => 'object', 'dynamic' => true],
        '場次'       => ['type' => 'nested', 'dynamic' => true],
        'updated_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    ],
];

if ($reset) {
    try { Elastic::dropIndex('overview'); error_log("Dropped index: overview"); }
    catch (Exception $e) { error_log("Drop skipped: " . $e->getMessage()); }
}

try { Elastic::createIndex('overview', $index_mapping); error_log("Created index: overview"); }
catch (Exception $e) { error_log("Index exists: " . $e->getMessage()); }

// ── 1. 取得現存議會 ──────────────────────────────────────────────────────────

$councils_result = Elastic::dbQuery('/{prefix}council/_search', 'GET',
    json_encode(['size' => 100, 'query' => ['term' => ['現存' => true]]]));

$councils = [];
foreach ($councils_result->hits->hits as $h) {
    $s = $h->_source;
    $councils[$s->{'代碼'}] = [
        '代碼'     => $s->{'代碼'},
        '議會名稱' => $s->{'議會名稱'},
        '議會類別' => $s->{'議會類別'} ?? '',
    ];
}
error_log("Loaded " . count($councils) . " 現存議會");

// ── 2. 各議會屆期，取最新一屆 ─────────────────────────────────────────────────

$terms_result = Elastic::dbQuery('/{prefix}term/_search', 'GET',
    json_encode(['size' => 1000, 'query' => ['match_all' => (object)[]],
        'sort' => [['屆次' => 'desc']]]));

$latest_term_by_council = [];   // cc_code => {屆次, 就職日, 任期屆滿日}
foreach ($terms_result->hits->hits as $h) {
    $s = $h->_source;
    $cc = $s->{'議會代碼'};
    if (array_key_exists($cc, $latest_term_by_council)) {
        continue;   // 已排序 desc，第一筆就是最新屆
    }
    $latest_term_by_council[$cc] = [
        '屆次'       => (int)($s->{'屆次'} ?? 0),
        '就職日'     => $s->{'就職日'} ?? null,
        '任期屆滿日' => $s->{'任期屆滿日'} ?? null,
    ];
}
error_log("Loaded latest term for " . count($latest_term_by_council) . " councils");

// ── 3. 每個議會：議長/副議長姓名、議員人數、目前或最近會期＋場次片段 ──────────

function find_councilor_name($cc, $term_no, $title)
{
    $q = [
        'size' => 1,
        'query' => ['bool' => ['must' => [
            ['term' => ['議會代碼' => $cc]],
            ['term' => ['屆次' => $term_no]],
            ['term' => ['職稱' => $title]],
        ]]],
    ];
    $r = Elastic::dbQuery('/{prefix}councilor/_search', 'POST', json_encode($q));
    return $r->hits->hits[0]->_source->{'姓名'} ?? null;
}

function count_councilors($cc, $term_no)
{
    $q = [
        'query' => ['bool' => ['must' => [
            ['term' => ['議會代碼' => $cc]],
            ['term' => ['屆次' => $term_no]],
        ]]],
    ];
    $r = Elastic::dbQuery('/{prefix}councilor/_count', 'POST', json_encode($q));
    return (int)($r->count ?? 0);
}

/**
 * 找出「目前進行中或最近一次」的會期代碼。
 *
 * 優先直接從場次資料找「有未來日期場次」的會期代碼 —— 場次資料常常比會期資料
 * 更新得快（例如會期還沒建檔，但下一場次的詳細日程已經公告，見場次資料的
 * 「前瞻期」特性），不能只靠會期的開始日期判斷。
 * 找不到才退回用會期索引「開始日期 <= 今天」裡最新的一筆（視為已結束）。
 */
function find_current_or_latest_session_code($cc, $today_str)
{
    $upcoming_q = [
        'size' => 0,
        'query' => ['bool' => ['must' => [
            ['term' => ['議會代碼' => $cc]],
            ['range' => ['日期' => ['gte' => $today_str]]],
        ]]],
        'aggs' => ['by_session' => [
            'terms' => ['field' => '會期代碼', 'size' => 5],
            'aggs' => ['earliest' => ['min' => ['field' => '日期']]],
        ]],
    ];
    $r = Elastic::dbQuery('/{prefix}sitting/_search', 'POST', json_encode($upcoming_q));
    $buckets = $r->aggregations->by_session->buckets ?? [];
    if ($buckets) {
        usort($buckets, fn($a, $b) => strcmp($a->earliest->value_as_string, $b->earliest->value_as_string));
        return ['code' => $buckets[0]->key, 'status' => 'ongoing'];
    }

    $ended_q = [
        'size' => 1,
        'query' => ['bool' => ['must' => [
            ['term' => ['議會代碼' => $cc]],
            ['range' => ['開始日期' => ['lte' => $today_str]]],
        ]]],
        'sort' => [['開始日期' => 'desc']],
    ];
    $r2 = Elastic::dbQuery('/{prefix}session/_search', 'GET', json_encode($ended_q));
    $code = $r2->hits->hits[0]->_source->{'代碼'} ?? null;
    return $code ? ['code' => $code, 'status' => 'ended'] : null;
}

/**
 * 取得會期本身的中繼資料（名稱/類別/次/起訖日期）。
 * 可能找不到（例如場次資料已公告，但會期紀錄還沒建檔），此時僅回傳代碼。
 */
function find_session_meta($session_code)
{
    $q = ['size' => 1, 'query' => ['term' => ['代碼' => $session_code]]];
    $r = Elastic::dbQuery('/{prefix}session/_search', 'GET', json_encode($q));
    return $r->hits->hits[0]->_source ?? null;
}

// 友善會期名稱解析（會期紀錄還沒建檔時使用）見 CCAPI_Type_Session::getFriendlyName()

function find_sittings($session_code, $today_str, $mode, $size)
{
    if ($mode === 'upcoming') {
        $q = [
            'size' => $size,
            'query' => ['bool' => ['must' => [
                ['term' => ['會期代碼' => $session_code]],
                ['range' => ['日期' => ['gte' => $today_str]]],
            ]]],
            'sort' => [['日期' => 'asc']],
        ];
    } else {
        $q = [
            'size' => $size,
            'query' => ['term' => ['會期代碼' => $session_code]],
            'sort' => [['日期' => 'desc']],
        ];
    }
    $r = Elastic::dbQuery('/{prefix}sitting/_search', 'GET', json_encode($q));
    $rows = array_map(fn($h) => $h->_source, $r->hits->hits);
    if ($mode === 'recent') {
        $rows = array_reverse($rows);   // 由舊到新排列，方便顯示
    }
    return array_map(function ($s) {
        return [
            '日期'     => $s->{'日期'} ?? null,
            '星期'     => $s->{'星期'} ?? null,
            '時段'     => $s->{'時段'} ?? null,
            '場次類別' => $s->{'場次類別'} ?? null,
            '委員會名稱' => $s->{'委員會名稱'} ?? null,
            '議程說明' => $s->{'議程說明'} ?? null,
            '開始時間' => $s->{'開始時間'} ?? null,
            '結束時間' => $s->{'結束時間'} ?? null,
        ];
    }, $rows);
}

$written = 0;

foreach ($councils as $cc => $council) {
    $term = $latest_term_by_council[$cc] ?? null;

    $term_no = $term['屆次'] ?? null;
    $speaker = null;
    $deputy_speaker = null;
    $councilor_count = null;

    if ($term_no) {
        $speaker = find_councilor_name($cc, $term_no, '議長');
        $deputy_speaker = find_councilor_name($cc, $term_no, '副議長');
        $councilor_count = count_councilors($cc, $term_no);
    }

    $session_doc = null;
    $sittings = [];

    $current = find_current_or_latest_session_code($cc, $today_str);
    if ($current) {
        $session_code = $current['code'];
        $status = $current['status'];
        $meta = find_session_meta($session_code);

        $sittings = ($status === 'ongoing')
            ? find_sittings($session_code, $today_str, 'upcoming', $sitting_snapshot_size)
            : find_sittings($session_code, $today_str, 'recent', $sitting_snapshot_size);

        // 場次資料可能比會期建檔更早公告，此時 $meta 會是 null；
        // 友善顯示用名稱從會期代碼解析出屆次/類別/次別組成，跟正式會期名稱同樣格式
        $session_doc = [
            '代碼'     => $session_code,
            '會期名稱' => $meta->{'會期名稱'} ?? CCAPI_Type_Session::getFriendlyName($session_code),
            '會期類別' => $meta->{'會期類別'} ?? null,
            '次'       => $meta->{'次'} ?? null,
            '開始日期' => $meta->{'開始日期'} ?? null,
            '結束日期' => $meta->{'結束日期'} ?? null,
            '狀態'     => $status,
        ];
    }

    $doc = [
        '代碼'       => $cc,
        '議會名稱'   => $council['議會名稱'],
        '議會類別'   => $council['議會類別'],
        '現存'       => true,
        '屆次'       => $term_no,
        '就職日'     => $term['就職日'] ?? null,
        '任期屆滿日' => $term['任期屆滿日'] ?? null,
        '議長姓名'   => $speaker,
        '副議長姓名' => $deputy_speaker,
        '議員人數'   => $councilor_count,
        '會期'       => $session_doc,
        '場次'       => $sittings,
        'updated_at' => $today_str,
    ];

    Elastic::dbBulkInsert('overview', $cc, $doc);
    $written++;
    error_log("Processed {$cc}" . ($term_no ? " (第{$term_no}屆)" : " (無屆期資料)"));
}

Elastic::dbBulkCommit('overview');
error_log("Done. Written: {$written} council overview docs");
