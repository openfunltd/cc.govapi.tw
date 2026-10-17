<?php
/**
 * 匯入即將舉行的選舉「已登記參選人」名單到 candidate index（選舉指南用）
 *
 * 用法：
 *   php scripts/import-registration-candidate.php
 *
 * 來源：
 *   IMPORT_REGISTRATION_CANDIDATE_JSONL  中選會公告的候選人登記名冊（115 年九合一選舉，每行
 *                                        {識別碼, 選舉類型, 選區, 姓名, 推薦政黨, 登記日期, 備註}）
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會歷屆候選人名冊 cand.csv（用來對應縣市／鄉鎮市區／村里代碼，
 *                                        以及跟過去的參選紀錄連人物代碼）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *
 * 登記階段還沒有候選人代碼、號次、政見：
 *   代碼：REG-{年}:{識別碼}（識別碼是「選舉類型:選區:姓名」）
 *   選舉代碼：比照中選會的格式先用 ELC-{類別}-{年}（例：ELC-T1-115），選舉名稱比照歷屆（例：115年直轄市議員選舉）
 *   登記階段：true；登記日期；號次、得票數、當選都沒有
 *   人物代碼：同縣市過去參選過、姓名相同而且只有一個人的，連到那個人（尋求連任的議員、縣市長點進去
 *             看得到歷屆紀錄）；同縣市沒有就找全國性的（不分區立委、總統副總統）；對不到或同名多人的不連
 * 選區別比照歷屆同類選舉的寫法：議員「第01選舉區」（縣市在行政區代碼）、代表「竹北市第01選舉區」、
 * 村里長「中山區下埤里」、首長是縣市或鄉鎮市區名稱。
 * 中選會公布正式候選人名冊（有候選人代碼與號次）之後，改由各選舉的匯入程式匯入正式資料，
 * 登記階段的紀錄要另外清掉（REG- 開頭的代碼）。
 */

include(__DIR__ . '/../init.inc.php');

$path = getenv('IMPORT_REGISTRATION_CANDIDATE_JSONL');
if (!$path || !file_exists($path)) {
    error_log("ERROR: 找不到來源檔案（IMPORT_REGISTRATION_CANDIDATE_JSONL）：{$path}");
    exit(1);
}

$council_of = function ($county) {
    return CountyCodeHelper::getCode(str_replace('台', '臺', $county));
};

// 選舉類型 → [類別碼, 選舉名稱後綴, 參選職位或議員的選舉類型, 是不是議員]
$TYPES = [
    '直轄市長'                 => ['C1', '直轄市長選舉', '直轄市長', false],
    '縣市長'                   => ['C2', '縣(市)長選舉', '縣市長', false],
    '直轄市議員'               => ['T1', '直轄市議員選舉', '直轄市議員', true],
    '縣市議員'                 => ['T2', '縣(市)議員選舉', '縣市議員', true],
    '鄉鎮市長'                 => ['D2', '鄉(鎮、市)長選舉', '鄉鎮市長', false],
    '鄉鎮市民代表'             => ['R2', '鄉(鎮、市)民代表選舉', '鄉鎮市民代表', false],
    '直轄市山地原住民區長'     => ['D1', '直轄市山地原住民區長選舉', '山地原住民區長', false],
    '直轄市山地原住民區民代表' => ['R1', '直轄市山地原住民區民代表選舉', '山地原住民區民代表', false],
    '村里長'                   => ['V0', '村(里)長選舉', '村里長', false],
];

// 從歷屆名冊取：縣市代碼、鄉鎮市區代碼、村里代碼；姓名＋縣市 → 候選人代碼（連人物代碼用）
$county_code = [];   // 縣市 => 5 碼
$town_code = [];     // 縣市 => [鄉鎮市區 => 8 碼]
$village_code = [];  // 縣市/鄉鎮市區 => [村里 => 行政區代碼]
$past = [];          // 縣市 => [姓名 => [候選人代碼, ...]]
$fh = fopen(getenv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV'), 'r');
$headers = fgetcsv($fh);
$headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers)) {
        continue;
    }
    $r = array_combine($headers, $row);
    $county = str_replace('台', '臺', $r['縣市']);
    if (preg_match('/^ELC-(T1|T2|C1|C2)-\d+$/', $r['選舉代碼']) && preg_match('/^\d{5}$/', $r['行政區代碼'])) {
        $county_code[$county] = $r['行政區代碼'];
    }
    if (preg_match('/^ELC-V0-\d+$/', $r['選舉代碼']) && $r['鄉鎮市區'] !== '' && $r['村里別'] !== '') {
        $code = str_replace('-', '', $r['行政區代碼']);
        $town_code[$county][$r['鄉鎮市區']] = substr($code, 0, 8);
        $village_code["{$county}/{$r['鄉鎮市區']}"][$r['村里別']] = $r['行政區代碼'];
    }
    $past[$county][$r['姓名']][] = $r['候選人代碼'];
}
fclose($fh);

$person_code = [];
$fh = fopen(getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL'), 'r');
while (($line = fgets($fh)) !== false) {
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if (isset($rec->person_id)) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 「新竹縣竹北市第1選舉區」→ [縣市, 鄉鎮市區, 其餘]；縣市、鄉鎮市區取最長的前綴
$split = function ($text) use ($town_code) {
    $text = str_replace('台', '臺', $text);
    $county = null;
    foreach (array_keys($town_code) as $c) {
        if (strpos($text, $c) === 0 && (!$county || mb_strlen($c) > mb_strlen($county))) {
            $county = $c;
        }
    }
    if (!$county) {
        return [null, null, $text];
    }
    $rest = mb_substr($text, mb_strlen($county));
    $town = null;
    foreach (array_keys($town_code[$county]) as $t) {
        if (strpos($rest, $t) === 0 && (!$town || mb_strlen($t) > mb_strlen($town))) {
            $town = $t;
        }
    }
    return [$county, $town, $town ? mb_substr($rest, mb_strlen($town)) : $rest];
};
$district_no = fn($text) => preg_match('/第(\d+)選舉區/u', $text, $m) ? sprintf('第%02d選舉區', (int) $m[1]) : null;

$year = 115;
$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}candidate/_mapping", 'PUT', json_encode(['properties' => [
    '登記階段' => ['type' => 'boolean'],
    '登記日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
]]));

$count = 0;
$linked = 0;
$skipped = [];
$fh = fopen($path, 'r');
while (($line = fgets($fh)) !== false) {
    $r = json_decode($line, true);
    if (!isset($r['識別碼'], $TYPES[$r['選舉類型'] ?? ''])) {
        continue;
    }
    [$kind, $suffix, $label, $is_councilor] = $TYPES[$r['選舉類型']];
    [$county, $town, $rest] = $split($r['選區']);
    $cc_code = $county ? $council_of($county) : null;
    if (!$cc_code) {
        $skipped[] = $r['識別碼'];
        continue;
    }
    $doc = [
        '代碼'       => "REG-{$year}:{$r['識別碼']}",
        '選舉代碼'   => "ELC-{$kind}-{$year}",
        '選舉名稱'   => "{$year}年{$suffix}",
        '年份'       => $year,
        '議會代碼'   => $cc_code,
        '縣市'       => $county,
        '姓名'       => $r['姓名'],
        '其他欄位'   => array_filter(['推薦之政黨' => $r['推薦政黨'] ?? ''], fn($v) => $v !== ''),
        '登記階段'   => true,
        '登記日期'   => $r['登記日期'] ?? null,
        'note'       => ($r['備註'] ?? '') !== '' ? $r['備註'] : null,
    ];
    if ($is_councilor) {
        $doc['選舉類型'] = $label;
        $doc['行政區代碼'] = $county_code[$county] ?? null;
        $doc['選區別'] = $district_no($rest) ?? $r['選區'];
    } else {
        $doc['參選職位'] = $label;
        if (in_array($kind, ['C1', 'C2'], true)) {
            $doc['行政區代碼'] = $county_code[$county] ?? null;
            $doc['選區別'] = $county;
        } else {
            $doc['鄉鎮市區'] = $town;
            $doc['鄉鎮市區代碼'] = $town ? ($town_code[$county][$town] ?? null) : null;
            if ($kind === 'V0') {
                $doc['村里'] = $rest;
                $doc['行政區代碼'] = $village_code["{$county}/{$town}"][$rest] ?? null;
                $doc['選區別'] = $town . $rest;
            } elseif (in_array($kind, ['R1', 'R2'], true)) {
                $doc['行政區代碼'] = $doc['鄉鎮市區代碼'];
                $doc['選區別'] = $town . ($district_no($rest) ?? $rest);
            } else {
                $doc['行政區代碼'] = $doc['鄉鎮市區代碼'];
                $doc['選區別'] = $town;
            }
        }
    }
    // 人物代碼：同縣市過去參選過、同名而且是同一個人；同縣市對不到時，再找全國性的參選紀錄
    //（不分區立委的縣市是空字串、總統副總統是「全國」，例：沈伯洋上一次是不分區立委）
    $persons = array_values(array_unique(array_filter(array_map(fn($c) => $person_code[$c] ?? null, $past[$county][$r['姓名']] ?? []))));
    if (!$persons) {
        $persons = array_values(array_unique(array_filter(array_map(fn($c) => $person_code[$c] ?? null,
            array_merge($past[''][$r['姓名']] ?? [], $past['全國'][$r['姓名']] ?? [])))));
    }
    if (count($persons) === 1) {
        $doc['人物代碼'] = $persons[0];
        $linked++;
    }
    Elastic::dbBulkInsert('candidate', $doc['代碼'], array_filter($doc, fn($v) => $v !== null && $v !== []));
    $count++;
}
fclose($fh);
Elastic::dbBulkCommit('candidate');
error_log(sprintf('Done. 匯入 %d 位登記參選人（%d 位連到過去的參選紀錄），%d 位對不到縣市略過%s。',
    $count, $linked, count($skipped), $skipped ? '：' . implode('、', array_slice($skipped, 0, 5)) : ''));
