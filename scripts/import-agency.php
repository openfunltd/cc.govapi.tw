<?php
/**
 * 匯入各縣市議會監督的行政機關（agency）到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-agency.php              # 匯入資料（upsert）
 *   php scripts/import-agency.php --reset      # 先刪除 index 再重建並匯入
 *   php scripts/import-agency.php --dry-run    # 只印出各縣市各分類的筆數與對不到的單位，不寫入
 *
 * 來源：
 *   IMPORT_AGENCY_ORG_DIR    各縣市政府組織架構（每縣市一個 {county_id}.json：county_name,
 *                            source_url, departments[{name, code, parent, sub_units, url}]），
 *                            departments 的順序就是縣市政府網站上的呈現順序
 *   IMPORT_AGENCY_UNITS_JSONL 政府機關代碼階層（一行一個機關或單位：id, original_name,
 *                            full_name, sub_name, level, parent_id, dgpa_code, dgpa_status, source…）
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV 中選會候選人名冊 cand.csv（縣市長／直轄市長當選人）
 *
 * 整合方式：
 *   - 收哪些單位、排序：完全照組織架構（縣市政府網站的順序本身就代表該縣市認定的位階）
 *   - 機關代碼：把組織架構的單位名稱對到機關代碼階層裡該縣市政府底下的單位（先比完整
 *     名稱，再比「字依序出現」的縮寫，例：研考會→研究發展考核委員會，只接受唯一命中）
 *   - 分類：
 *       一級內部單位：縣市政府的內部單位（機關代碼階層裡掛在縣市政府底下、來源是單位代碼的，
 *                     例：民政處、主計處）
 *       一級外部單位：縣市政府直屬的獨立機關（掛在縣市政府底下、有自己機關代碼的，例：警察局）
 *       二級機關：一級外部單位底下的獨立機關（例：工務局新建工程處），上層代碼指向一級單位
 *       其他單位：學校、幼兒園、戶政／地政事務所、區公所、鄉鎮市公所與代表會、衛生所等
 *       縣市政府：最上層的縣市政府本身（一個議會一筆），「歷任首長」是中選會歷屆縣市長／
 *                 直轄市長選舉的當選人（年份是選舉的民國年，對應同年選出的那一屆議會）
 *     對不到機關代碼的，參考組織架構上的分組名稱（「府內單位」「一級機關」等）或名稱字尾判斷
 *   - 組織架構裡的職位（縣長、秘書長、參議…）不是機關，不匯入；分組名稱（「直屬單位」
 *     「學校」「財稅主計」等）只當分類參考，不成為節點
 *
 * Doc ID：{議會代碼}-{機關代碼}，對不到機關代碼的用 {議會代碼}-org-{名稱雜湊}
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);
$dry_run = in_array('--dry-run', $argv ?? []);

$index_mapping = [
    'properties' => [
        '代碼'       => ['type' => 'keyword'],
        '議會代碼'   => ['type' => 'keyword'],
        '名稱'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '全名'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '機關代碼'   => ['type' => 'keyword'],
        '分類'       => ['type' => 'keyword'],
        '上層代碼'   => ['type' => 'keyword'],
        '排序'       => ['type' => 'integer'],
        '組織圖分組' => ['type' => 'keyword'],
        '內部單位'   => ['type' => 'keyword'],
        '網址'       => ['type' => 'keyword', 'index' => false],
        '比對方式'   => ['type' => 'keyword'],
        '來源網址'   => ['type' => 'keyword', 'index' => false],
        '歷任首長'   => ['properties' => [
            '年份'       => ['type' => 'integer'],
            '姓名'       => ['type' => 'keyword'],
            '職稱'       => ['type' => 'keyword'],
            '政黨'       => ['type' => 'keyword'],
            '候選人代碼' => ['type' => 'keyword'],
        ]],
        // 由 generate-agency-heads.php 寫入：各屆在議會答詢的官員（會議逐字稿彙整）
        '首長紀錄'   => ['type' => 'object', 'enabled' => false],
    ],
];

$county_to_cc = [
    'taipei' => 'tpe', 'new_taipei' => 'nwt', 'taichung' => 'txg', 'tainan' => 'tnn', 'kaohsiung' => 'khh',
    'taoyuan' => 'tao', 'yilan' => 'ila', 'hsinchu_county' => 'hsq', 'hsinchu_city' => 'hsz', 'keelung' => 'kee',
    'miaoli' => 'mia', 'changhua' => 'cha', 'nantou' => 'nan', 'yunlin' => 'yun', 'chiayi_city' => 'cyi',
    'chiayi_county' => 'cyq', 'pingtung' => 'pif', 'taitung' => 'ttt', 'hualien' => 'hua', 'penghu' => 'pen',
    'kinmen' => 'kin', 'lienchiang' => 'lie',
];

// 職位（不是機關）
$position_pattern = '/^(縣長|市長|副縣長|副市長|秘書長|副秘書長|主任秘書|參議|秘書|顧問|技監)$/u';
// 其他單位（收合在獨立一區）
$other_pattern = '/(學校|國民小學|國民中學|國民中小學|高級中學|高中|國小|國中|幼兒園|托兒所|戶政|地政事務所|區公所|鄉公所|鎮公所|市公所|鄉鎮市公所|代表會|衛生所|空中大學|圖書館分館)/u';
// 列在縣市政府網站上、但其實是中央機關的（實測澎湖縣：財政部南區國稅局、澎湖縣選舉委員會、
// 國家風景區管理處、榮民服務處），不受縣市議會監督，對不到縣市政府底下的機關代碼時排除
$central_pattern = '/(財政部|國稅局|國家風景區|選舉委員會|榮民服務處|中央|行政院|內政部|經濟部|交通部)/u';
// 真正的機關／單位名稱字尾；分組名稱（「直屬單位」「財稅主計」）通常不是這些字尾
$unit_suffix_pattern = '/(局|處|室|所|會|中心|館|院|隊|場|園|校|廠|署|站|公司|大學|醫院)$/u';
$group_pattern = '/(單位|機關|事業|附屬|所屬|直屬|府內|其他|其它)/u';

if ($reset && !$dry_run) {
    try {
        Elastic::dropIndex('agency');
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}
if (!$dry_run) {
    try {
        Elastic::createIndex('agency', $index_mapping);
    } catch (Exception $e) {
        error_log("Index exists or created: " . $e->getMessage());
    }
}

// 機關代碼階層
$units_path = getenv('IMPORT_AGENCY_UNITS_JSONL');
if (!$units_path || !file_exists($units_path)) {
    error_log("ERROR: 找不到來源檔案（IMPORT_AGENCY_UNITS_JSONL）：{$units_path}");
    exit(1);
}
$units = [];
$children = [];
foreach (file($units_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $u = json_decode($line, true);
    if (!$u) {
        continue;
    }
    $units[$u['id']] = $u;
    $children[$u['parent_id']][] = $u['id'];
}

// 歷屆縣市長／直轄市長當選人：縣市名稱 => [{年份, 姓名, 職稱, 政黨, 候選人代碼}]
$mayors = [];
$cand_csv = getenv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
if ($cand_csv && ($fh = fopen($cand_csv, 'r'))) {
    $headers = fgetcsv($fh);
    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($headers)) {
            continue;
        }
        $r = array_combine($headers, $row);
        if (!in_array($r['當選註記'], ['*', '!'], true) || !preg_match('/^(\d+)年(直轄市市長|直轄市長|縣\(市\)長)選舉/u', $r['選舉名稱'], $m)) {
            continue;
        }
        $county_name = str_replace('台', '臺', $r['縣市']);
        $mayors[$county_name][] = [
            '年份'       => (int) $m[1],
            '姓名'       => $r['姓名'],
            '職稱'       => preg_match('/縣$/u', $county_name) ? '縣長' : '市長',
            '政黨'       => $r['政黨'] ?: null,
            '候選人代碼' => $r['候選人代碼'],
        ];
    }
    fclose($fh);
} else {
    error_log("找不到 IMPORT_CANDIDATE_ELECTED_SOURCE_CSV（{$cand_csv}），縣市政府不附歷任首長");
}

$org_dir = getenv('IMPORT_AGENCY_ORG_DIR');
if (!$org_dir || !is_dir($org_dir)) {
    error_log("ERROR: 找不到來源目錄（IMPORT_AGENCY_ORG_DIR）：{$org_dir}");
    exit(1);
}

$subseq = function ($needle, $hay) {
    $pos = 0;
    foreach (preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $p = mb_strpos($hay, $ch, $pos);
        if ($p === false) {
            return false;
        }
        $pos = $p + 1;
    }
    return true;
};

$total = 0;
foreach (glob("{$org_dir}/*.json") as $file) {
    $org = json_decode(file_get_contents($file), true);
    $cc_code = $county_to_cc[$org['county_id'] ?? ''] ?? null;
    if (!$cc_code || empty($org['departments'])) {
        continue;
    }
    $county = $org['county_name'];
    $norm = fn($s) => str_replace(['臺', '（', '）', ' ', '　'], ['台', '(', ')', '', ''],
        preg_replace('/^' . preg_quote(str_replace('臺', '台', $county), '/') . '(政府)?/u', '', str_replace('臺', '台', trim($s))));

    // 縣市政府在機關代碼階層裡的根節點（現行的那一個）
    $root = null;
    foreach ($units as $id => $u) {
        if (($u['full_name'] ?? $u['original_name']) === $county . '政府' && ($u['dgpa_status']['status'] ?? '') === '現行') {
            $root = $id;
            break;
        }
    }
    if (!$root) {
        error_log("{$county}：機關代碼階層找不到縣市政府，略過");
        continue;
    }
    // 候選單位：縣市政府底下兩層以內，第二層只收獨立機關（不收各機關內部的科室）
    $candidates = [];   // 正規化名稱 => [unit id, 深度]
    $stack = [[$root, 0]];
    while ($stack) {
        [$id, $depth] = array_pop($stack);
        if ($depth > 0 && ($depth === 1 || $units[$id]['source'] !== 'dgpa+unitId')) {
            $candidates[$norm($units[$id]['sub_name'] ?: $units[$id]['original_name'])] = [$id, $depth];
        }
        if ($depth < 2) {
            foreach ($children[$id] ?? [] as $k) {
                $stack[] = [$k, $depth + 1];
            }
        }
    }

    $names_in_org = array_flip(array_map(fn($d) => $d['name'], $org['departments']));
    $docs = [];
    // 最上層：縣市政府本身
    $county_mayors = $mayors[str_replace('台', '臺', $county)] ?? [];
    usort($county_mayors, fn($a, $b) => $b['年份'] <=> $a['年份']);
    $docs["{$cc_code}-{$root}"] = [
        '代碼'       => "{$cc_code}-{$root}",
        '議會代碼'   => $cc_code,
        '名稱'       => $county . '政府',
        '全名'       => $county . '政府',
        '機關代碼'   => $units[$root]['dgpa_code'] ?: $root,
        '分類'       => '縣市政府',
        '上層代碼'   => null,
        '排序'       => -1,
        '組織圖分組' => null,
        '內部單位'   => [],
        '網址'       => null,
        '比對方式'   => 'exact',
        '來源網址'   => $org['source_url'] ?? null,
        '歷任首長'   => $county_mayors,
    ];
    $stats = ['一級內部單位' => 0, '一級外部單位' => 0, '二級機關' => 0, '其他單位' => 0, '未對到' => [], '略過' => 0];
    foreach ($org['departments'] as $order => $dep) {
        $name = trim($dep['name']);
        if (preg_match($position_pattern, $name)) {
            $stats['略過']++;
            continue;
        }
        // 只當分組用的名稱：本身被別人當成上層、看起來不像機關名稱
        $is_parent = false;
        foreach ($org['departments'] as $other) {
            if (($other['parent'] ?? null) === $name) {
                $is_parent = true;
                break;
            }
        }
        $key = $norm($name);
        $match = $candidates[$key] ?? null;
        $how = $match ? 'exact' : null;
        if (!$match) {
            $k2 = preg_replace('/\s*\(\d+\)$/u', '', $key);
            $hits = array_filter(array_keys($candidates), fn($c) => mb_strlen($k2) >= 2 && $subseq($k2, $c));
            if (count($hits) === 1) {
                $match = $candidates[reset($hits)];
                $how = 'fuzzy';
            }
        }
        if (!$match && preg_match($central_pattern, $name)) {
            $stats['略過']++;
            continue;
        }
        if (!$match && $is_parent && (preg_match($group_pattern, $name) || !preg_match($unit_suffix_pattern, $name))) {
            $stats['略過']++;   // 分組名稱
            continue;
        }

        $group = $dep['parent'] ?? null;
        if (preg_match($other_pattern, $name)) {
            $category = '其他單位';
        } elseif ($match) {
            [$uid, $depth] = $match;
            if ($depth === 1) {
                $category = $units[$uid]['source'] === 'dgpa+unitId' ? '一級內部單位' : '一級外部單位';
            } else {
                $category = '二級機關';
            }
        } elseif ($group && preg_match('/(府內|直屬|內部)/u', $group)) {
            $category = '一級內部單位';
        } elseif ($group && preg_match('/(一級機關|所屬一級)/u', $group)) {
            $category = '一級外部單位';
        } elseif (preg_match('/局$/u', $name)) {
            $category = '一級外部單位';
        } elseif (preg_match('/(處|室|委員會)$/u', $name) && (!$group || !isset($names_in_org[$group]))) {
            $category = '一級內部單位';
        } else {
            $category = '其他單位';
        }

        $uid = $match[0] ?? null;
        $dgpa_code = $uid ? ($units[$uid]['dgpa_code'] ?: null) : null;
        $doc_id = $uid ? "{$cc_code}-{$uid}" : "{$cc_code}-org-" . substr(md5($name), 0, 10);
        $parent_doc = null;
        if ($uid && ($units[$uid]['parent_id'] ?? null) && $units[$uid]['parent_id'] !== $root) {
            $parent_doc = "{$cc_code}-{$units[$uid]['parent_id']}";
        }
        $docs[$doc_id] = [
            '代碼'       => $doc_id,
            '議會代碼'   => $cc_code,
            '名稱'       => $name,
            '全名'       => $uid ? ($units[$uid]['full_name'] ?? $units[$uid]['original_name']) : null,
            '機關代碼'   => $dgpa_code,
            '分類'       => $category,
            '上層代碼'   => $parent_doc,
            '排序'       => $order,
            '組織圖分組' => $group,
            '內部單位'   => array_values(array_filter((array) ($dep['sub_units'] ?? []))),
            '網址'       => $dep['url'] ?? null,
            '比對方式'   => $how,
            '來源網址'   => $org['source_url'] ?? null,
        ];
        if ($category !== '其他單位' && !$match) {
            $stats['未對到'][] = $name;
        }
        $stats[$category]++;
    }
    // 上層不在這份清單裡的（例如組織圖沒列出的中間層），就當作沒有上層
    foreach ($docs as &$doc) {
        if ($doc['上層代碼'] && !isset($docs[$doc['上層代碼']])) {
            $doc['上層代碼'] = null;
        }
    }
    unset($doc);

    printf("%-4s %-4s 內部 %3d／外部 %3d／二級 %3d／其他 %3d，略過 %2d，未對到機關代碼 %d%s\n",
        $cc_code, $county, $stats['一級內部單位'], $stats['一級外部單位'], $stats['二級機關'], $stats['其他單位'],
        $stats['略過'], count($stats['未對到']), $stats['未對到'] ? '：' . implode('、', array_slice($stats['未對到'], 0, 12)) : '');
    if (!$dry_run) {
        foreach ($docs as $doc_id => $doc) {
            Elastic::dbBulkInsert('agency', $doc_id, $doc);
        }
    }
    $total += count($docs);
}
if (!$dry_run) {
    Elastic::dbBulkCommit('agency');
}
error_log("Done. {$total} agencies" . ($dry_run ? '（--dry-run，未寫入）' : ''));
