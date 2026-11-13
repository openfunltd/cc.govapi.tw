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
 * 逐字稿的姓名／職稱偶爾拆錯，職稱的一部分跑進姓名（「主任 陳秘書良輝」其實是「主任秘書 陳良輝」、
 * 「局長 蕭分正松」其實是「分局長 蕭正松」、「局長 陳兼代雄文」其實是「代理局長 陳雄文」、
 * 「主任 人事陳淑芳」其實是「人事主任 陳淑芳」），$fix_name 把這些常見情形修正回來。
 * 姓名中間的空白（「曾効 忠」）拿掉；只有姓的（「鍾局長」→ 姓名「鍾」）併到同屆、同職稱、
 * 同姓且任期涵蓋那次答詢的人（多人符合取答詢次數最多的），找不到就不列。
 *
 * 跟 generate-completeness.php／generate-council-overview.php 一樣是下游快取，逐字稿或
 * agency 重新匯入後要重跑（auto-refresh.php 會自動處理）。
 */

include(__DIR__ . '/../init.inc.php');

$fix_name = function ($name, $title) {
    $name = str_replace('祕書', '秘書', preg_replace('/\s+/u', '', $name));
    if (preg_match('/^(會計|人事|主計|政風)室$/u', $name)) {
        return ['', $title];
    }
    if ($title === '區長' && preg_match('/^區(\p{Han}{2,3})$/u', $name, $m)) {
        return [$m[1], $title];
    }
    // 前面黏到單位名稱：「人事陳淑芳 主任」「計室范玉梅 主任」是人事／會計主任；「會俞委員振華」的「會」是研考會的會
    if (preg_match('/^(人事室|會計室|主計室|政風室|人事|會計|主計|計室|政風)(\p{Han}{2,3})$/u', $name, $m)) {
        return [$m[2], strtr($m[1], ['計室' => '會計', '會計室' => '會計', '人事室' => '人事', '主計室' => '主計', '政風室' => '政風']) . $title];
    }
    if (preg_match('/^(\p{Han})(會計|人事|政風)(\p{Han}{1,2})$/u', $name, $m)) {
        return [$m[1] . $m[3], $m[2] . $title];
    }
    $name = preg_replace('/^會(?=\p{Han}委員)/u', '', $name);
    if (preg_match('/^代理(\p{Han}{2,3})$/u', $name, $m)) {
        return [$m[1], '代理' . $title];
    }
    // 「主任 徐世勲委員」→ 主任委員 徐世勲
    if (preg_match('/^(\p{Han}{2,3})(委員|秘書)$/u', $name, $m) && $title === '主任') {
        return [$m[1], $title . $m[2]];
    }
    // 「張張弘岳」姓重複
    if (preg_match('/^(\p{Han})\1(\p{Han}{2})$/u', $name, $m)) {
        return [$m[1] . $m[2], $title];
    }
    // 職稱拆進姓名中間：「蕭分正松 局長」→ 分局長、「王大健州 隊長」→ 大隊長、「陳副幸雄 主任委員」→ 副主任委員、「陳前雪慧 處長」→ 前處長、
    // 「陳兼代雄文」「李代麗珠」→ 代理、「陳代委員永仁 主任」→ 代理主任委員、「張兼處長哲揚 秘書長」→ 秘書長
    if (preg_match('/^(\p{Han})(副|前)?(分|大)?(兼代|兼|代理|代)?(委員|委|秘書|處長|局長)?(\p{Han}{1,2})$/u', $name, $m)
        && ($m[2] . $m[3] . $m[4] . $m[5]) !== ''
        && ($m[3] !== '分' || $title === '局長') && ($m[3] !== '大' || $title === '隊長')) {
        [, $surname, $deputy, $unit, $acting, $word, $given] = $m;
        if ($unit === '' && $acting === '' && $word === '' && $deputy === '') {
            return [$name, $title];
        }
        $t = $unit . $title;
        if ($word === '委員' || $word === '委' || $word === '秘書') {
            $t .= $word === '委' ? '委員' : $word;
        }
        if ($deputy) {
            $t = $deputy . $t;
        } elseif (in_array($acting, ['兼代', '代理', '代'], true)) {
            $t = '代理' . $t;
        }
        return [$surname . $given, $t];
    }
    return [$name, $title];
};

// $a 的字依序出現在 $b 裡
$is_subsequence = function ($a, $b) {
    $pattern = '/' . implode('.*', array_map(fn($c) => preg_quote($c, '/'), mb_str_split($a))) . '/u';
    return (bool) preg_match($pattern, $b);
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
                if ($name === '') {
                    continue;
                }
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
        // 姓名多字或少字的併到同屆答詢次數較多的人：
        // - 多出來的字（「康宗虎說明」「陳炳煌長」「白付理智榮」）：對方姓名是它的子字串，或同姓且是它的子序列，最多多兩字
        // - 少字的（「鍾」「陳盛」）：同姓、同職稱、是對方姓名的子序列且只少一字（只有姓的不限），任期涵蓋這次答詢
        // 只有姓又找不到人的不列
        uasort($list, fn($x, $y) => $x['次數'] <=> $y['次數']);
        foreach ($list as $key => $rec) {
            $name = $rec['姓名'];
            $len = mb_strlen($name);
            $match = null;
            foreach ($list as $other_key => $other) {
                $other_name = $other['姓名'];
                $other_len = mb_strlen($other_name);
                if ($other_key === $key || !isset($list[$key]) || $other['屆'] !== $rec['屆'] || $other['次數'] <= $rec['次數']
                    || ($match && $other['次數'] <= $list[$match]['次數'])) {
                    continue;
                }
                $same_surname = mb_substr($other_name, 0, 1) === mb_substr($name, 0, 1);
                $longer = $other_len >= 3 && $len > $other_len && $len - $other_len <= 2
                    && (mb_strpos($name, $other_name) !== false || ($same_surname && $is_subsequence($other_name, $name)));
                $shorter = $same_surname && $other['職稱'] === $rec['職稱'] && $other_len > $len && ($len === 1 || $other_len - $len === 1)
                    && $is_subsequence($name, $other_name) && $other['首次'] <= $rec['首次'] && $other['最後'] >= $rec['最後'];
                if ($longer || $shorter) {
                    $match = $other_key;
                }
            }
            if ($match) {
                $list[$match]['次數'] += $rec['次數'];
                $list[$match]['首次'] = min($list[$match]['首次'], $rec['首次']);
                $list[$match]['最後'] = max($list[$match]['最後'], $rec['最後']);
            }
            if ($match || $len === 1) {
                unset($list[$key]);
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
