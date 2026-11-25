<?php
/**
 * 把逐字稿、會議紀錄摘要、議案切段算 embedding，寫入向量 Elasticsearch（設定見 libraries/VectorIndex.php）
 *
 * 用法：
 *   php scripts/build-vectors.php meet_transcript   # 型別：meet_transcript、meet_note、bill，可以給多個；不給就三個都跑
 *   php scripts/build-vectors.php bill --cc=tpe     # 只跑一個議會（不會刪除其他議會的向量）
 *   php scripts/build-vectors.php bill --limit=100  # 只處理前 N 個來源單位（測試用，不刪除）
 *   php scripts/build-vectors.php --dry-run         # 只切段統計段數，不算向量、不寫入
 *   php scripts/build-vectors.php --allow-mass-delete  # 允許一次刪掉超過 5% 的來源單位
 *   php scripts/build-vectors.php --rewrite      # 內容沒變的也重寫 ES（向量從本地備份讀，不重算），改了段落欄位時用
 *
 * 來源是本專案自己的 index（不是原始 CSV），所以段落帶的代碼一定查得回來：
 *   meet_transcript：同一場會議（會議代碼）的發言依順序接起來，每句前面加發言人標記，再切成約 600 字的
 *     視窗（步長 500、重疊 100）。單句逐字稿很短（中位數二十幾字），一句一段的向量效果很差。
 *     來源單位是一場會議，API 路徑指向 /api/meet/{會議代碼}；每段另外記涵蓋的逐字稿代碼與起訖順序
 *   meet_note：一份會議紀錄摘要切段，來源單位是摘要代碼
 *   bill：案由、說明、辦法、審查意見、議決接起來切段，來源單位是議案代碼
 * 每段前面加「議會、屆、會議／議案」的脈絡，沒提到主題的段落也帶著這段會議或議案的語意。
 *
 * 內容雜湊沒變的來源單位不重算（本地備份見 VectorIndex::store()）；整份跑完時，來源已經沒有的單位
 * 從向量 index 與本地備份刪除。中斷後重跑會從頭讀來源，但已經算過的直接跳過。
 */

include(__DIR__ . '/../init.inc.php');

$types = [];
$opts = ['cc' => null, 'limit' => null, 'dry-run' => false, 'allow-mass-delete' => false, 'rewrite' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        if (!array_key_exists($m[1], $opts)) {
            die("未知的參數：{$arg}\n");
        }
        $opts[$m[1]] = $m[2] ?? true;
    } elseif (in_array($arg, ['meet_transcript', 'meet_note', 'bill'], true)) {
        $types[] = $arg;
    } else {
        die("未知的型別：{$arg}\n");
    }
}
$types = $types ?: ['meet_note', 'bill', 'meet_transcript'];
$partial = $opts['cc'] !== null || $opts['limit'] !== null;

foreach (['VEC_ELASTIC_URL', 'VEC_ELASTIC_PREFIX', 'VEC_EMBED_URLS'] as $key) {
    if (!getenv($key) && !$opts['dry-run']) {
        die("config.inc.php 沒有設定 {$key}\n");
    }
}

// Elastic::dbQuery() 經過的反向代理偶爾回 503，重試幾次
function source_query($url, $body)
{
    for ($try = 1; ; $try++) {
        try {
            return Elastic::dbQuery($url, 'GET', json_encode($body, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            if ($try >= 5) {
                throw $e;
            }
            error_log("讀取來源失敗，{$try} 次：" . substr($e->getMessage(), 0, 200));
            sleep(10 * $try);
        }
    }
}

// 用 scroll 依 $sort 依序讀完整個來源 index；search_after 遇到排序欄位相同（例如重複的代碼）剛好跨頁會漏讀
// 每筆加上 _id：議案的代碼可能重複，匯入時第二筆以後的 _id 會加 -dup 後綴，單位要用 _id 才不會撞在一起
function source_scan($type, $fields, $sort, $cc)
{
    $body = [
        'size' => 5000,
        '_source' => $fields,
        'sort' => array_map(function ($f) {
            return [$f => 'asc'];
        }, $sort),
        'query' => $cc ? ['term' => ['議會代碼' => $cc]] : ['match_all' => new stdClass],
    ];
    $r = source_query("/{prefix}{$type}/_search?scroll=10m", $body);
    try {
        while ($r->hits->hits) {
            foreach ($r->hits->hits as $h) {
                $h->_source->_id = $h->_id;
                yield $h->_source;
            }
            $r = source_query('/_search/scroll', ['scroll' => '10m', 'scroll_id' => $r->_scroll_id]);
        }
    } finally {
        try {
            Elastic::dbQuery('/_search/scroll', 'DELETE', json_encode(['scroll_id' => $r->_scroll_id]));
        } catch (Exception $e) {
        }
    }
}

// 議員代碼 => 人物代碼（逐字稿的「對應代碼」、議案的「議員代碼」都是議員代碼，換成跨屆共用的人物代碼才能依人統計）
function load_person_codes()
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (source_scan('councilor', ['代碼', '人物代碼'], ['代碼'], null) as $c) {
            if (($c->人物代碼 ?? '') !== '') {
                $map[$c->代碼] = $c->人物代碼;
            }
        }
    }
    return $map;
}

// 會議代碼 => 會議基本資料（逐字稿與會議紀錄的段首脈絡用）
function load_meets($cc)
{
    $meets = [];
    foreach (source_scan('meet', ['代碼', '委員會或主旨', '屆'], ['代碼'], $cc) as $m) {
        $meets[$m->代碼] = $m;
    }
    return $meets;
}

function meet_header($council_name, $term, $meet, $date, $suffix = '')
{
    $parts = [$council_name . '議會'];
    if ($term) {
        $parts[] = "第{$term}屆";
    }
    $parts[] = trim((string) ($meet->委員會或主旨 ?? '')) ?: '會議';
    if ($date) {
        $parts[] = $date;
    }
    return implode(' ', $parts) . $suffix;
}

function text_value($v)
{
    if (is_array($v)) {
        $v = implode('、', array_filter($v, 'is_scalar'));
    }
    return trim(preg_replace('/[ \t\x{3000}]+/u', ' ', (string) $v));
}

// 各型別的來源單位產生器：每個單位是 ['id', 'header', 'body'（雜湊用）, 'meta', 'chunks' => [['text', 'meta']]]
function units_meet_transcript($cc)
{
    $meets = load_meets($cc);
    $persons = load_person_codes();
    $fields = ['代碼', '會議代碼', '順序', '原始標記', '姓名', '職稱', '發言內容', '縣市', '議會代碼', '屆', '日期', '場次代碼', '對應代碼', '對應代碼類型'];
    $cur = null;
    $rows = [];
    $flush = function () use (&$cur, &$rows, $meets, $persons) {
        $first = $rows[0];
        $text = '';
        $spans = []; // 每句在接起來的文字裡的 [起點, 終點, 列]
        foreach ($rows as $row) {
            $label = trim((string) ($row->原始標記 ?? '')) ?: trim(($row->姓名 ?? '') . ($row->職稱 ?? ''));
            $line = text_value($row->發言內容 ?? '');
            if ($line === '') {
                continue;
            }
            $line = ($label !== '' ? "{$label}：" : '') . $line . "\n";
            $start = mb_strlen($text);
            $text .= $line;
            $spans[] = [$start, $start + mb_strlen($line), $row];
        }
        if ($text === '') {
            return null;
        }
        $header = meet_header($first->縣市, $first->屆 ?? null, $meets[$cur] ?? null, $first->日期 ?? null, ' 逐字稿');
        $chunks = [];
        $si = 0;
        foreach (VectorIndex::windows($text) as list($start, $part)) {
            $end = $start + mb_strlen($part);
            while ($spans[$si][1] <= $start) {
                $si++;
            }
            $codes = $speakers = $orders = $councilors = $councilor_codes = $person_codes = [];
            for ($k = $si; $k < count($spans) && $spans[$k][0] < $end; $k++) {
                $row = $spans[$k][2];
                $codes[] = $row->代碼;
                $orders[] = $row->順序;
                if (($row->姓名 ?? '') !== '') {
                    $speakers[$row->姓名] = true;
                }
                // 對應到議員的發言（主席是議長、副議長時也算）
                if (($row->對應代碼類型 ?? '') === '議員' && ($row->對應代碼 ?? '') !== '') {
                    $councilors[$row->姓名] = true;
                    $councilor_codes[$row->對應代碼] = true;
                    if (isset($persons[$row->對應代碼])) {
                        $person_codes[$persons[$row->對應代碼]] = true;
                    }
                }
            }
            $chunks[] = ['text' => rtrim($part), 'meta' => [
                '逐字稿代碼' => $codes,
                '發言人' => array_keys($speakers),
                '發言議員' => array_keys($councilors),
                '議員代碼' => array_keys($councilor_codes),
                '人物代碼' => array_keys($person_codes),
                '起始順序' => min($orders),
                '結束順序' => max($orders),
            ]];
        }
        return [
            'id' => $cur,
            'header' => $header,
            'body' => $text,
            'meta' => [
                '類型' => 'meet_transcript', '議會代碼' => $first->議會代碼, '縣市' => $first->縣市, '屆' => $first->屆 ?? null,
                '日期' => $first->日期 ?? null, '會議代碼' => $cur, '場次代碼' => $first->場次代碼 ?? null,
                'API路徑' => '/api/meet/' . rawurlencode($cur),
            ],
            'chunks' => $chunks,
        ];
    };
    foreach (source_scan('meet_transcript', $fields, ['會議代碼', '順序', '代碼'], $cc) as $row) {
        if ($row->會議代碼 !== $cur) {
            if ($rows && ($unit = $flush())) {
                yield $unit;
            }
            $cur = $row->會議代碼;
            $rows = [];
        }
        $rows[] = $row;
    }
    if ($rows && ($unit = $flush())) {
        yield $unit;
    }
}

function units_meet_note($cc)
{
    $meets = load_meets($cc);
    $fields = ['代碼', '會議代碼', '摘要內容', '縣市', '議會代碼', '屆', '日期', '場次代碼'];
    foreach (source_scan('meet_note', $fields, ['代碼'], $cc) as $row) {
        $text = trim((string) ($row->摘要內容 ?? ''));
        if ($text === '') {
            continue;
        }
        $header = meet_header($row->縣市, $row->屆 ?? null, $meets[$row->會議代碼] ?? null, $row->日期 ?? null, ' 會議紀錄');
        yield [
            'id' => $row->_id,
            'header' => $header,
            'body' => $text,
            'meta' => [
                '類型' => 'meet_note', '議會代碼' => $row->議會代碼, '縣市' => $row->縣市, '屆' => $row->屆 ?? null,
                '日期' => $row->日期 ?? null, '會議代碼' => $row->會議代碼, '場次代碼' => $row->場次代碼 ?? null,
                'API路徑' => '/api/meet_note/' . rawurlencode($row->_id),
            ],
            'chunks' => array_map(function ($w) {
                return ['text' => $w[1]];
            }, VectorIndex::windows($text)),
        ];
    }
}

function units_bill($cc)
{
    $sections = ['案由', '說明', '辦法', '審查意見', '議決'];
    $persons = load_person_codes();
    $fields = array_merge(['代碼', '案號', '類別', '提案人姓名', '提案單位', '提案人結構', '連署人結構', '縣市', '議會代碼', '屆', '會議代碼'], $sections);
    foreach (source_scan('bill', $fields, ['代碼'], $cc) as $row) {
        $text = '';
        foreach ($sections as $s) {
            $v = text_value($row->$s ?? '');
            if ($v !== '') {
                $text .= "{$s}：{$v}\n";
            }
        }
        if ($text === '') {
            continue;
        }
        $header = $row->縣市 . '議會';
        if ($row->屆 ?? null) {
            $header .= " 第{$row->屆}屆";
        }
        $header .= ' 議案';
        if (($row->案號 ?? '') !== '') {
            $header .= " {$row->案號}";
        }
        if (($v = text_value($row->類別 ?? '')) !== '') {
            $header .= "（{$v}）";
        }
        // 提案人、連署人：有對應到議員的才有議員代碼
        $people = ['提案人' => [], '連署人' => []];
        $councilor_codes = $person_codes = [];
        foreach (['提案人' => '提案人結構', '連署人' => '連署人結構'] as $role => $field) {
            foreach ((array) ($row->$field ?? []) as $p) {
                if (($p->姓名 ?? '') !== '') {
                    $people[$role][$p->姓名] = true;
                }
                if (($p->議員代碼 ?? '') !== '') {
                    $councilor_codes[$p->議員代碼] = true;
                    if (isset($persons[$p->議員代碼])) {
                        $person_codes[$persons[$p->議員代碼]] = true;
                    }
                }
            }
        }
        if (!$people['提案人']) {
            foreach ((array) ($row->提案人姓名 ?? []) as $name) {
                $people['提案人'][$name] = true;
            }
        }
        $by = text_value($row->提案人姓名 ?? '') ?: text_value($row->提案單位 ?? '');
        if ($by !== '') {
            $header .= " 提案：{$by}";
        }
        yield [
            'id' => $row->_id,
            'header' => $header,
            'body' => $text,
            'meta' => [
                '類型' => 'bill', '議會代碼' => $row->議會代碼, '縣市' => $row->縣市, '屆' => $row->屆 ?? null,
                '會議代碼' => $row->會議代碼 ?? null,
                '提案人' => array_keys($people['提案人']), '連署人' => array_keys($people['連署人']),
                '議員代碼' => array_keys($councilor_codes), '人物代碼' => array_keys($person_codes),
                'API路徑' => '/api/bill/' . rawurlencode($row->_id),
            ],
            'chunks' => array_map(function ($w) {
                return ['text' => rtrim($w[1])];
            }, VectorIndex::windows($text)),
        ];
    }
}

// 算好向量的一批單位：開始在背景寫 ES，回傳交給 finish_batch() 的待辦
function start_batch($type, $units)
{
    $body = VectorIndex::bulkBody($units);
    return ['units' => $units, 'body' => $body,
        'ch' => $body === null ? null : VectorIndex::start(VectorIndex::esHandle('POST', '/' . VectorIndex::indexName($type) . '/_bulk', $body, true))];
}

// 等 ES 寫完（失敗重試 4 次）才存本地備份：中途失敗時備份沒記錄，下次會重算重寫，
// 不會出現「本地有、ES 沒有」
function finish_batch($type, $db, $pending)
{
    if ($pending === null) {
        return;
    }
    if ($pending['ch'] !== null) {
        list($response, $code) = VectorIndex::wait($pending['ch']);
        $ok = VectorIndex::bulkOk($code, json_decode((string) $response, true) ?? $response);
        for ($try = 0; !$ok && $try < 4; $try++) {
            sleep(10);
            list($code, $r) = VectorIndex::es('POST', '/' . VectorIndex::indexName($type) . '/_bulk', $pending['body'], true);
            $ok = VectorIndex::bulkOk($code, $r);
        }
        if (!$ok) {
            throw new Exception('寫入 ' . VectorIndex::indexName($type) . ' 失敗');
        }
    }
    VectorIndex::storeSave($db, array_filter($pending['units'], function ($u) {
        return empty($u['reused']);
    }));
}

foreach ($types as $type) {
    $t0 = microtime(true);
    error_log("== {$type}" . ($opts['dry-run'] ? '（只統計）' : ''));
    $db = VectorIndex::store($type);
    $bulk_mode = false;
    if (!$opts['dry-run']) {
        $is_new = VectorIndex::ensureIndex($type);
        $bulk_mode = $is_new || $opts['rewrite'] || VectorIndex::isBulkMode($type);
        if ($is_new || $opts['rewrite']) {
            VectorIndex::setBulkMode($type, true);
        }
    }

    $stats = ['units' => 0, 'skipped' => 0, 'embedded_units' => 0, 'chunks' => 0];
    $seen = [];
    $buffer = [];
    $buffer_chunks = 0;
    $pending = null;
    $flush = function ($force) use ($type, $db, &$buffer, &$buffer_chunks, &$pending, &$stats, $opts, $t0) {
        if (!$buffer || (!$force && $buffer_chunks < VectorIndex::EMBED_BATCH * 32)) {
            return;
        }
        $known = VectorIndex::storeLookup($db, array_column($buffer, 'id'));
        $todo = [];
        $reuse = [];
        foreach ($buffer as $u) {
            $u['hash'] = VectorIndex::hash([$type, $u['header'], $u['body']]);
            if (isset($known[$u['id']]) && $known[$u['id']][0] === $u['hash']) {
                $stats['skipped']++;
                if ($opts['rewrite'] && !$opts['dry-run']) {
                    $u['old_chunks'] = $known[$u['id']][1];
                    unset($u['body']);
                    $reuse[$u['id']] = $u;
                }
                continue;
            }
            $u['old_chunks'] = $known[$u['id']][1] ?? 0;
            unset($u['body']);
            $todo[] = $u;
        }
        $buffer = [];
        $buffer_chunks = 0;
        $flat = [];
        foreach ($todo as $ui => $u) {
            foreach ($u['chunks'] as $c) {
                $flat[] = [$ui, $u['header'] . "\n" . $c['text']];
            }
        }
        $stats['embedded_units'] += count($todo);
        $stats['chunks'] += count($flat);
        if ($opts['dry-run'] || (!$flat && !$reuse)) {
            return;
        }
        $vecs = $flat ? VectorIndex::embed(array_column($flat, 1)) : [];
        foreach ($flat as $k => list($ui)) {
            $todo[$ui]['vecs'][] = $vecs[$k];
        }
        // --rewrite：內容沒變的用本地備份的向量重寫 ES，不用再存回備份
        foreach (VectorIndex::storeVectors($db, array_keys($reuse)) as $id => $unit_vecs) {
            $reuse[$id]['vecs'] = $unit_vecs;
            $reuse[$id]['reused'] = true;
            $todo[] = $reuse[$id];
        }
        finish_batch($type, $db, $pending);
        $pending = start_batch($type, $todo);
        $sec = microtime(true) - $t0;
        error_log(sprintf('%s 已讀 %d 單位，沒變 %d，算了 %d 單位 %d 段（%.1f 段/秒）',
            $type, $stats['units'], $stats['skipped'], $stats['embedded_units'], $stats['chunks'], $stats['chunks'] / max(1, $sec)));
    };

    foreach (('units_' . $type)($opts['cc']) as $unit) {
        $stats['units']++;
        $seen[$unit['id']] = true;
        $buffer[] = $unit;
        $buffer_chunks += count($unit['chunks']);
        $flush(false);
        if ($opts['limit'] !== null && $stats['units'] >= (int) $opts['limit']) {
            break;
        }
    }
    $flush(true);
    if (!$opts['dry-run']) {
        finish_batch($type, $db, $pending);
    }

    // 整份跑完才刪除來源已經沒有的單位
    if (!$partial && !$opts['dry-run']) {
        $gone = array_diff_key(VectorIndex::storeAll($db), $seen);
        if ($gone && count($gone) > 100 && count($gone) > ($stats['units'] + count($gone)) * 0.05 && !$opts['allow-mass-delete']) {
            error_log("{$type} 有 " . count($gone) . ' 個來源單位要刪除，超過 5%，沒有刪；確認來源沒問題後加 --allow-mass-delete');
        } elseif ($gone) {
            $lines = [];
            foreach ($gone as $id => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $lines[] = json_encode(['delete' => ['_id' => "{$id}#{$i}"]], JSON_UNESCAPED_UNICODE);
                }
            }
            foreach (array_chunk($lines, 5000) as $part) {
                list($code, $r) = VectorIndex::es('POST', '/' . VectorIndex::indexName($type) . '/_bulk', implode("\n", $part) . "\n", true);
                if (!VectorIndex::bulkOk($code, $r)) {
                    throw new Exception('刪除舊向量失敗');
                }
            }
            VectorIndex::storeDelete($db, array_keys($gone));
            error_log("{$type} 刪除來源已經沒有的 " . count($gone) . ' 個單位');
        }
    }
    if ($bulk_mode && !$partial) {
        VectorIndex::setBulkMode($type, false);
    }
    error_log(sprintf('%s 完成：讀 %d 單位，沒變 %d，算了 %d 單位 %d 段，%.0f 秒',
        $type, $stats['units'], $stats['skipped'], $stats['embedded_units'], $stats['chunks'], microtime(true) - $t0));
}
