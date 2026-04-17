<?php
/**
 * 計算各議會資料完整度，並寫入 ES completeness index
 *
 * 用法：
 *   php scripts/generate-completeness.php            # 計算並寫入
 *   php scripts/generate-completeness.php --reset    # 先刪除 index 再重建
 *
 * 完整度判斷邏輯：
 *   議員：該屆 councilor count > 0 → ok
 *   屆  ：最新屆的任期屆滿日 >= 今天 → ok（資料有追上現任）
 *   會期：最後一筆會期結束日 >= (現任:今天 / 歷史:任期屆滿日) - 90天 → ok
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);
$today = new DateTimeImmutable('today');
$session_gap_days = 90;

// ── ES index 設定 ────────────────────────────────────────────────────────────

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '議會名稱'   => ['type' => 'keyword'],
        '議會類別'   => ['type' => 'keyword'],
        '現存'       => ['type' => 'boolean'],
        'types'      => ['type' => 'object', 'dynamic' => true],
        'terms'      => ['type' => 'nested', 'dynamic' => true],
        'updated_at' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    ],
];

if ($reset) {
    try { Elastic::dropIndex('completeness'); error_log("Dropped index: completeness"); }
    catch (Exception $e) { error_log("Drop skipped: " . $e->getMessage()); }
}

try { Elastic::createIndex('completeness', $index_mapping); error_log("Created index: completeness"); }
catch (Exception $e) { error_log("Index exists: " . $e->getMessage()); }

// ── 1. 取得所有議會 ─────────────────────────────────────────────────────────

$councils_result = Elastic::dbQuery('/{prefix}council/_search', 'GET',
    json_encode(['size' => 100, 'query' => ['match_all' => (object)[]]]));

$councils = [];
foreach ($councils_result->hits->hits as $h) {
    $s = $h->_source;
    $councils[$s->{'代碼'}] = [
        '代碼'     => $s->{'代碼'},
        '議會名稱' => $s->{'議會名稱'},
        '議會類別' => $s->{'議會類別'} ?? '',
        '現存'     => $s->{'現存'} ?? false,
    ];
}
error_log("Loaded " . count($councils) . " councils");

// ── 2. 取得所有屆期（含就職日/任期屆滿日），依議會分組 ─────────────────────

$terms_result = Elastic::dbQuery('/{prefix}term/_search', 'GET',
    json_encode(['size' => 1000, 'query' => ['match_all' => (object)[]],
        'sort' => [['屆次' => 'desc']]]));

$terms_by_council = [];   // [cc_code => [{屆次, 就職日, 任期屆滿日}, ...]]
foreach ($terms_result->hits->hits as $h) {
    $s = $h->_source;
    $cc = $s->{'議會代碼'};
    $terms_by_council[$cc][] = [
        '屆次'       => (int)($s->{'屆次'} ?? 0),
        '就職日'     => $s->{'就職日'} ?? null,
        '任期屆滿日' => $s->{'任期屆滿日'} ?? null,
    ];
}
error_log("Loaded terms for " . count($terms_by_council) . " councils");

// ── 3. 議員計數：terms agg by 議會代碼 → 屆次 ──────────────────────────────

$councilor_agg_query = [
    'size' => 0,
    'aggs' => [
        'by_council' => [
            'terms' => ['field' => '議會代碼', 'size' => 100],
            'aggs' => [
                'by_term' => [
                    'terms' => ['field' => '屆次', 'size' => 50],
                ],
            ],
        ],
    ],
];
$councilor_agg = Elastic::dbQuery('/{prefix}councilor/_search', 'POST',
    json_encode($councilor_agg_query));

// [cc_code][屆次] = count
$councilor_counts = [];
foreach ($councilor_agg->aggregations->by_council->buckets as $cb) {
    $cc = $cb->key;
    foreach ($cb->by_term->buckets as $tb) {
        $councilor_counts[$cc][(int)$tb->key] = (int)$tb->doc_count;
    }
}
error_log("Loaded councilor counts for " . count($councilor_counts) . " councils");

// ── 4. 會期計數：terms agg by 議會代碼 → 屆 + max(結束日期) ─────────────────

$session_agg_query = [
    'size' => 0,
    'aggs' => [
        'by_council' => [
            'terms' => ['field' => '議會代碼', 'size' => 100],
            'aggs' => [
                'by_term' => [
                    'terms' => ['field' => '屆', 'size' => 50],
                    'aggs' => [
                        'latest_end' => ['max' => ['field' => '結束日期']],
                    ],
                ],
            ],
        ],
    ],
];
$session_agg = Elastic::dbQuery('/{prefix}session/_search', 'POST',
    json_encode($session_agg_query));

// [cc_code][屆次] = {count, latest_end_date}
$session_data = [];
foreach ($session_agg->aggregations->by_council->buckets as $cb) {
    $cc = $cb->key;
    foreach ($cb->by_term->buckets as $tb) {
        $latest = $tb->latest_end->value_as_string ?? null;
        $session_data[$cc][(int)$tb->key] = [
            'count'      => (int)$tb->doc_count,
            'latest_end' => $latest ? substr($latest, 0, 10) : null,
        ];
    }
}
error_log("Loaded session data for " . count($session_data) . " councils");

// ── 5. 計算完整度並寫入 ES ──────────────────────────────────────────────────

function calc_status($count, $type, $term_info, $is_current, $gap_days = 90)
{
    if ($count === 0) return 'missing';

    if ($type === 'councilor') {
        return 'ok';
    }

    if ($type === 'session') {
        // 最後會期結束日是否夠接近任期屆滿日（或今天）
        $latest_end = $term_info['latest_end'] ?? null;
        if (!$latest_end) return 'missing';

        $today = new DateTimeImmutable('today');
        $end_dt = new DateTimeImmutable($latest_end);

        $ref_date = $is_current
            ? $today
            : ($term_info['任期屆滿日'] ? new DateTimeImmutable($term_info['任期屆滿日']) : $today);

        $diff = $ref_date->diff($end_dt)->days;
        $behind = $end_dt < $ref_date;

        return ($behind && $diff > $gap_days) ? 'incomplete' : 'ok';
    }

    return 'ok';
}

$today_str = $today->format('Y-m-d');
$written = 0;

foreach ($councils as $cc => $council) {
    $terms = $terms_by_council[$cc] ?? [];

    // 屆期完整度：最新屆的任期屆滿日 >= 今天？
    $latest_term = $terms[0] ?? null;  // 已按 屆次 desc 排序
    if (!$latest_term) {
        $term_type_status = 'missing';
    } elseif (!$latest_term['任期屆滿日'] || $latest_term['任期屆滿日'] >= $today_str) {
        // 任期屆滿日為 null = 現任進行中；或日期尚未到期 → ok
        $term_type_status = 'ok';
    } else {
        $term_type_status = 'incomplete';
    }

    // 每屆的議員/會期計數
    $term_docs = [];
    $councilor_total = 0;
    $session_total = 0;
    $councilor_terms_with_data = 0;
    $session_terms_with_data = 0;

    foreach ($terms as $t) {
        $term_no = $t['屆次'];
        $is_current = $latest_term && $term_no === $latest_term['屆次'];

        $c_count = $councilor_counts[$cc][$term_no] ?? 0;
        $s_info  = $session_data[$cc][$term_no] ?? ['count' => 0, 'latest_end' => null];
        $s_count = $s_info['count'];

        $councilor_total += $c_count;
        $session_total   += $s_count;
        if ($c_count > 0) $councilor_terms_with_data++;
        if ($s_count > 0) $session_terms_with_data++;

        $session_term_info = array_merge($t, ['latest_end' => $s_info['latest_end']]);

        $term_docs[] = [
            '屆次'              => $term_no,
            '就職日'            => $t['就職日'],
            '任期屆滿日'        => $t['任期屆滿日'],
            'councilor_count'   => $c_count,
            'councilor_status'  => calc_status($c_count, 'councilor', $t, $is_current, $session_gap_days),
            'session_count'     => $s_count,
            'session_latest_end'=> $s_info['latest_end'],
            'session_status'    => calc_status($s_count, 'session', $session_term_info, $is_current, $session_gap_days),
        ];
    }

    $total_terms = count($terms);

    // 整體 councilor/session 狀態：依有資料屆數佔比
    $councilor_type_status = ($total_terms === 0 || $councilor_terms_with_data === 0)
        ? 'missing'
        : ($councilor_terms_with_data === $total_terms ? 'ok' : 'incomplete');
    $session_type_status = ($total_terms === 0 || $session_terms_with_data === 0)
        ? 'missing'
        : ($session_terms_with_data === $total_terms ? 'ok' : 'incomplete');

    $doc = [
        '代碼'       => $cc,
        '議會名稱'   => $council['議會名稱'],
        '議會類別'   => $council['議會類別'],
        '現存'       => (bool)$council['現存'],
        'types' => [
            'term'      => ['total' => $total_terms, 'status' => $term_type_status],
            'councilor' => [
                'total'           => $councilor_total,
                'terms_with_data' => $councilor_terms_with_data,
                'total_terms'     => $total_terms,
                'status'          => $councilor_type_status,
            ],
            'session' => [
                'total'           => $session_total,
                'terms_with_data' => $session_terms_with_data,
                'total_terms'     => $total_terms,
                'status'          => $session_type_status,
            ],
        ],
        'terms'      => $term_docs,
        'updated_at' => $today_str,
    ];

    Elastic::dbBulkInsert('completeness', $cc, $doc);
    $written++;
}

Elastic::dbBulkCommit('completeness');
error_log("Done. Written: {$written} council completeness docs");
