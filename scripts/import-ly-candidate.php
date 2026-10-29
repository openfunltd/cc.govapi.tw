<?php
/**
 * 匯入立法委員選舉候選人（議會代碼 ly）到 candidate index，並把各屆不分區政黨票
 * 寫進 term 文件
 *
 * 用法：
 *   php scripts/import-ly-candidate.php
 *
 * 寫進既有的 candidate／term index（不建新 index、不支援 --reset，要重建請用
 * import-candidate.php --reset 後再跑這支）
 *
 * 來源（中選會選舉資料庫，立法委員選舉從第3屆開始有資料，第7屆起才有不分區名單）：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  候選人名冊 cand.csv（選舉代碼 ELC-L0-{屆}／補選 BEL-L0-{屆}:...）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl（候選人代碼→人物代碼）
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv（選舉代碼, 選區代碼, 候選人代碼, 票數）
 *   IMPORT_LY_PARTY_VOTES_CSV            不分區政黨得票數（選舉代碼, 政黨, 得票數）
 *   IMPORT_CANDIDATE_JSONL               選舉公報萃取結果 bulletin.jsonl（跟議員共用同一份，
 *                                        這裡只取「選舉類型＝立法委員」且對到候選人代碼的）
 *   IMPORT_LY_PARTY_MANIFESTO_JSONL      不分區公報的政黨政見（一行一個政黨：選舉代碼, 政黨,
 *                                        政黨號次, 政見, 政見來源, 政見圖路徑, 來源PDF, 來源頁碼）
 *
 * candidate 欄位對照（沒有選舉公報的學經歷/政見，只有名冊資料）：
 *   選區別      名冊的選區別；空白時（原住民、單一選區縣市）用得票資料的選區代碼或縣市；
 *               不分區一律「全國不分區」
 *   行政區代碼  名冊的行政區代碼；不分區沒有行政區，改放政黨代碼（例：party-350），讓
 *               「同選區比較」（選舉代碼＋行政區代碼＋選區別）變成同一份政黨名單
 *   得票率／得票排名  同一場選舉同一選區裡計算；不分區候選人沒有個人得票
 *   其他欄位    性別、推薦之政黨（跟選舉公報的欄位名稱一致，同選區比較表直接沿用）
 *
 * 選舉公報：依候選人代碼併入學歷（取代名冊只有學歷等級的那欄）、經歷、政見、相片、公報
 * 來源頁碼等欄位，欄位名稱與格式跟 import-candidate.php 匯入的議員候選人一致（圖片路徑
 * 同樣轉成公開網址）。同一個代碼有多筆時（原住民候選人會重複刊在各縣市的公報裡），
 * 優先取有政見文字的那筆。不分區候選人個人沒有政見（公報上的政見是政黨層級的）
 *
 * term 新增「政黨票」：[{政黨, 得票數, 得票率, 號次, 政見, 政見來源, 政見圖路徑, 來源PDF, 來源頁碼}]，
 * 依得票數多到少，得票率是佔全部政黨票的比例；政見等欄位來自不分區公報（政黨層級的政見，
 * 不屬於任何一位候選人）
 */

include(__DIR__ . '/../init.inc.php');

$cc_code = 'ly';

$open_csv = function ($env) {
    $path = getenv($env);
    if (!$path || !file_exists($path)) {
        error_log("ERROR: 找不到來源檔案（{$env}）：{$path}");
        exit(1);
    }
    $fh = fopen($path, 'r');
    $headers = fgetcsv($fh);
    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
    return [$fh, $headers];
};
// 跟 import-candidate.php 的 bulletin_image_url() 同一套轉換規則，各 import 腳本
// 獨立可執行、不互相 include，所以這裡複製一份
$bulletin_image_url = function ($path) {
    if (!$path) return null;
    $path = preg_replace('#^files/#', '', $path);
    return 'https://lydata.ronny-s3.click/bulletin/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};
$term_of = function ($election_code) {
    return preg_match('/^(?:ELC|BEL)-L0-(\d+)/', $election_code, $m) ? intval($m[1]) : null;
};

$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}term/_mapping", 'PUT', json_encode(['properties' => [
    '政黨票' => ['properties' => [
        '政黨'       => ['type' => 'keyword'],
        '得票數'     => ['type' => 'integer'],
        '得票率'     => ['type' => 'float'],
        '號次'       => ['type' => 'integer'],
        '政見'       => ['type' => 'text'],
        '政見來源'   => ['type' => 'keyword'],
        '政見圖路徑' => ['type' => 'keyword', 'index' => false],
        '來源PDF'    => ['type' => 'keyword', 'index' => false],
        '來源頁碼'   => ['type' => 'integer'],
    ]],
]]));

// 候選人代碼 → 人物代碼
$person_code = [];
$path = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$fh = fopen($path, 'r');
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_PERSON_SOURCE_JSONL）：{$path}");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '-L0-') === false) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if (preg_match('/^(ELC|BEL)-L0-/', $rec->person_id ?? '')) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 候選人得票數、選區代碼
$votes = [];
$race_code = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !$term_of($row[0]) || strpos($row[2], $row[0] . ':') !== 0) {
        continue;
    }
    $votes[$row[2]] = intval($row[3]);
    $race_code[$row[2]] = $row[1];
}
fclose($fh);

// 候選人
$candidates = [];
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !$term_of($row[0])) {
        continue;
    }
    $r = array_combine($headers, $row);
    $code = $r['候選人代碼'];
    $is_party_list = (strpos($code, ':party-') !== false);
    if ($is_party_list) {
        $district = '全國不分區';
        $area_code = explode(':', $code)[1];
    } else {
        $rc = $race_code[$code] ?? '';
        $district = $r['選區別'] ?: (($rc !== '' && !preg_match('/^\d/', $rc)) ? $rc : $r['縣市']);
        $area_code = $r['行政區代碼'];
    }
    // 第9、10屆補選在名冊裡的選舉名稱是行政區代碼（「63000」），組一個看得懂的名稱
    if (!preg_match('/(\d+年|補選)/u', $r['選舉名稱']) && preg_match('/^BEL-L0-(\d+):/', $r['選舉代碼'], $bm)) {
        $r['選舉名稱'] = sprintf('第%d屆立法委員%s%s補選', $bm[1], $r['縣市'], $r['選區別']);
        $candidates_name_fixed = ($candidates_name_fixed ?? 0) + 1;
    }
    if (preg_match('/^(\d+)年/u', $r['選舉名稱'], $m)) {
        $year = intval($m[1]);
    } else {
        $year = preg_match('/^BEL-L0-\d+:(\d{4})/', $r['選舉代碼'], $m) ? intval($m[1]) - 1911 : null;
    }
    $candidates[$code] = [
        '代碼'       => $code,
        '候選人代碼' => $code,
        '選舉代碼'   => $r['選舉代碼'],
        '選舉名稱'   => $r['選舉名稱'],
        '選舉類型'   => '立法委員',
        '年份'       => $year,
        '議會代碼'   => $cc_code,
        '縣市'       => $r['縣市'] ?: null,
        '行政區代碼' => $area_code ?: null,
        '選區別'     => $district,
        '姓名'       => $r['姓名'],
        '號次'       => $r['號次'],
        '學歷'       => $r['學歷'] ?: null,
        '其他欄位'   => array_filter([
            '性別'       => $r['性別'],
            '推薦之政黨' => $r['政黨'],
            '出生日期'   => $r['出生日期'],
            '出生地'     => $r['出生地'],
        ], fn($v) => $v !== ''),
        '得票數'     => $votes[$code] ?? null,
        '人物代碼'   => $person_code[$code] ?? null,
        '當選'       => in_array($r['當選註記'], ['*', '!'], true), // ! 是婦女保障名額當選
        '當選註記'   => $r['當選註記'] ?: null,
    ];
}
fclose($fh);

// 選舉公報
$bulletin_path = getenv('IMPORT_CANDIDATE_JSONL');
$fh = ($bulletin_path && file_exists($bulletin_path)) ? fopen($bulletin_path, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_JSONL）：{$bulletin_path}");
    exit(1);
}
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision'], true);
$bulletins = [];
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '立法委員') === false) {
        continue;
    }
    $r = json_decode($line, true);
    $code = $r['候選人代碼'] ?? '';
    if (($r['選舉類型'] ?? '') !== '立法委員' || !isset($candidates[$code])) {
        continue;
    }
    if (!isset($bulletins[$code]) || (!$has_manifesto($bulletins[$code]) && $has_manifesto($r))) {
        $bulletins[$code] = $r;
    }
}
fclose($fh);
// 名冊已經有、或是由這支腳本自己決定的欄位不從公報覆蓋
$skip_keys = ['代碼', '候選人代碼', '選舉代碼', '選舉名稱', '選舉類型', '縣市', '行政區代碼', '選區別', '姓名', '號次'];
foreach ($bulletins as $code => $r) {
    foreach ($r as $key => $val) {
        if (in_array($key, $skip_keys, true) || $val === '' || $val === null || $val === []) {
            continue;
        }
        if ($key === '相片路徑' || $key === '政見圖路徑') {
            $val = $bulletin_image_url($val);
        } elseif ($key === '欄位圖片' && is_array($val)) {
            $val = array_map($bulletin_image_url, $val);
        } elseif ($key === '其他欄位' && is_array($val)) {
            $val = array_merge($val, $candidates[$code]['其他欄位']);
            $val['推薦之政黨'] = BulletinFieldHelper::normalizeParty($val['推薦之政黨'] ?? null);
            $val = array_filter($val, fn($v) => $v !== null);
        }
        $candidates[$code][$key] = $val;
    }
}
error_log('Merged bulletin data for ' . count($bulletins) . ' candidates ('
    . count(array_filter($bulletins, $has_manifesto)) . ' with manifesto).');

// 同一場選舉同一選區（候選人代碼去掉最後的號次）內計算得票率、排名
$races = [];
foreach ($candidates as $code => $c) {
    if ($c['得票數'] !== null) {
        $races[substr($code, 0, strrpos($code, ':'))][] = $code;
    }
}
foreach ($races as $codes) {
    $total = array_sum(array_map(fn($code) => $candidates[$code]['得票數'], $codes));
    usort($codes, fn($a, $b) => $candidates[$b]['得票數'] <=> $candidates[$a]['得票數']);
    foreach ($codes as $i => $code) {
        $candidates[$code]['得票排名'] = $i + 1;
        $candidates[$code]['得票率'] = $total ? round($candidates[$code]['得票數'] / $total * 100, 2) : null;
    }
}

foreach ($candidates as $code => $c) {
    Elastic::dbBulkInsert('candidate', $code, $c);
}
Elastic::dbBulkCommit('candidate');
error_log('Done. Imported ' . count($candidates) . ' legislator candidates.');

// 不分區政黨票 → term
$party_votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_PARTY_VOTES_CSV');
while (($row = fgetcsv($fh)) !== false) {
    $term = $term_of($row[0]);
    if (count($row) !== count($headers) || !$term) {
        continue;
    }
    $party_votes[$term][] = ['政黨' => $row[1], '得票數' => intval($row[2])];
}
fclose($fh);

// 政黨政見（不分區公報）：屆 → 政黨 → 政見資料
$party_manifestos = [];
$manifesto_path = getenv('IMPORT_LY_PARTY_MANIFESTO_JSONL');
if ($manifesto_path && file_exists($manifesto_path)) {
    foreach (file($manifesto_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $m = json_decode($line, true);
        $term = $term_of($m['選舉代碼'] ?? '');
        if (!$term || !($m['政黨'] ?? null)) {
            continue;
        }
        $party_manifestos[$term][$m['政黨']] = array_filter([
            '號次'       => $m['政黨號次'] ?? null,
            '政見'       => $m['政見'] ?? null,
            '政見來源'   => $m['政見來源'] ?? null,
            '政見圖路徑' => $bulletin_image_url($m['政見圖路徑'] ?? null),
            '來源PDF'    => $m['來源PDF'] ?? null,
            '來源頁碼'   => $m['來源頁碼'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
    }
} else {
    error_log("找不到 IMPORT_LY_PARTY_MANIFESTO_JSONL（{$manifesto_path}），政黨票不附政見");
}

foreach ($party_votes as $term => $list) {
    $total = array_sum(array_column($list, '得票數'));
    usort($list, fn($a, $b) => $b['得票數'] <=> $a['得票數']);
    foreach ($list as &$p) {
        $p['得票率'] = round($p['得票數'] / $total * 100, 2);
        $p += $party_manifestos[$term][$p['政黨']] ?? [];
    }
    unset($p);
    Elastic::dbQuery(
        '/{prefix}term/_update/' . rawurlencode("{$cc_code}-{$term}"),
        'POST',
        json_encode(['doc' => ['政黨票' => $list]])
    );
}
error_log('Done. Updated 政黨票 for ' . count($party_votes) . ' terms.');
