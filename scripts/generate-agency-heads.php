<?php
/**
 * 彙整各行政機關在議會答詢的官員，寫進 agency 的「首長紀錄」
 *
 * 用法：
 *   php scripts/generate-agency-heads.php
 *
 * 資料來源是 meet_transcript：發言者「對應代碼類型＝機關」時，「對應代碼」就是機關代碼
 * （縣市政府內部單位是 {縣市政府代碼}-U… 那一套），跟 agency 的「代碼」去掉議會代碼前綴
 * 相同。依機關 → 屆 → 姓名彙整出職稱、答詢次數、第一次與最後一次答詢日期：
 *   首長紀錄：[{屆, 姓名, 職稱, 次數, 首次, 最後}, ...]（依屆、最後答詢日期由新到舊）
 * 這不是官方人事資料，是「誰以什麼職稱在議會答詢過」的紀錄；頁面上取每屆最後答詢的
 * 首長級職稱當作該屆（最新）首長。
 *
 * 逐字稿的姓名／職稱偶爾拆錯（「主任 陳秘書良輝」其實是「主任秘書 陳良輝」、
 * 「執行秘書 丁代理秋霞」其實是「代理執行秘書 丁秋霞」），這裡把這兩種常見情形修正回來。
 *
 * 跟 generate-completeness.php／generate-council-overview.php 一樣是下游快取，逐字稿或
 * agency 重新匯入後要重跑（auto-refresh.php 會自動處理）。
 */

include(__DIR__ . '/../init.inc.php');

$fix_name = function ($name, $title) {
    if (preg_match('/^(\p{Han})(秘書|委員|代理)(\p{Han}{1,3})$/u', $name, $m)) {
        return [$m[1] . $m[3], $m[2] === '代理' ? '代理' . $title : $title . $m[2]];
    }
    return [$name, $title];
};

$councils = Elastic::dbQuery('/{prefix}agency/_search', 'GET', json_encode([
    'size' => 0,
    'aggs' => ['cc' => ['terms' => ['field' => '議會代碼', 'size' => 100]]],
]));
$updated = 0;
foreach ($councils->aggregations->cc->buckets as $cc_bucket) {
    $cc_code = $cc_bucket->key;
    $r = Elastic::dbQuery('/{prefix}meet_transcript/_search', 'GET', json_encode([
        'size' => 0,
        'query' => ['bool' => ['must' => [
            ['term' => ['議會代碼' => $cc_code]],
            ['term' => ['對應代碼類型' => '機關']],
        ]]],
        'aggs' => ['unit' => [
            'terms' => ['field' => '對應代碼', 'size' => 3000],
            'aggs' => ['term' => [
                'terms' => ['field' => '屆', 'size' => 50],
                'aggs' => ['person' => [
                    'terms' => ['field' => '姓名.keyword', 'size' => 30],
                    'aggs' => [
                        'title' => ['terms' => ['field' => '職稱', 'size' => 1]],
                        'first' => ['min' => ['field' => '日期']],
                        'last'  => ['max' => ['field' => '日期']],
                    ],
                ]],
            ]],
        ]],
    ]));
    $records = [];
    foreach ($r->aggregations->unit->buckets as $unit) {
        $list = [];
        foreach ($unit->term->buckets as $term) {
            foreach ($term->person->buckets as $p) {
                [$name, $title] = $fix_name($p->key, $p->title->buckets[0]->key ?? '');
                $key = $term->key . '|' . $name . '|' . $title;
                if (isset($list[$key])) {
                    $list[$key]['次數'] += $p->doc_count;
                    $list[$key]['首次'] = min($list[$key]['首次'], $p->first->value_as_string);
                    $list[$key]['最後'] = max($list[$key]['最後'], $p->last->value_as_string);
                    continue;
                }
                $list[$key] = [
                    '屆'   => (int) $term->key,
                    '姓名' => $name,
                    '職稱' => $title,
                    '次數' => $p->doc_count,
                    '首次' => $p->first->value_as_string,
                    '最後' => $p->last->value_as_string,
                ];
            }
        }
        $list = array_values($list);
        usort($list, fn($a, $b) => [$b['屆'], $b['最後']] <=> [$a['屆'], $a['最後']]);
        $records[$unit->key] = $list;
    }

    // 只更新這個議會實際存在的 agency
    $agencies = Elastic::dbQuery('/{prefix}agency/_search', 'GET', json_encode([
        'size' => 3000,
        '_source' => ['代碼'],
        'query' => ['term' => ['議會代碼' => $cc_code]],
    ]));
    $prefix_len = strlen($cc_code) + 1;
    foreach ($agencies->hits->hits as $hit) {
        $unit_code = substr($hit->_id, $prefix_len);
        Elastic::dbBulkInsert('agency', $hit->_id, ['首長紀錄' => $records[$unit_code] ?? []]);
        $updated += isset($records[$unit_code]) ? 1 : 0;
    }
    Elastic::dbBulkCommit('agency');
}
error_log("Done. {$updated} agencies have head records.");
