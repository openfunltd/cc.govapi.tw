<?php
/**
 * 匯入國民大會（na）、臺灣省議會（tpg）：議會、屆期、代表／議員名單與候選人
 *
 * 用法：
 *   php scripts/import-na-tpg.php
 *
 * 這兩個機關都已經停止運作，沒有議事資料可以抓，這裡收的是：
 *   - 議會、屆期：人工整理的參考資料（下方 $COUNCILS／$TERMS），依維基百科條目的沿革
 *     （https://zh.wikipedia.org/wiki/臺灣省議會 、https://zh.wikipedia.org/zh-tw/國民大會），
 *     就職日、任期屆滿日照條目的屆次表；投票日條目沒寫的依當年選舉日期補上
 *   - 候選人（candidate）：中選會候選人名冊有的選舉——國大代表 85 年（第 3 屆）、94 年任務型國大
 *     （政黨名單比例代表，名冊上是政黨）、83 年臺灣省議員（第 10 屆）、83 年臺灣省省長
 *   - 代表／議員名單（councilor）：上面各次選舉的區域當選人（國大第 3 屆另有 100 席全國不分區、
 *     僑居國外代表由政黨分配，名冊沒有，任務型國大也是政黨名單，所以不產生個人名單）
 *
 * 來源：IMPORT_CANDIDATE_ELECTED_SOURCE_CSV（cand.csv）、IMPORT_CANDIDATE_PERSON_SOURCE_JSONL、
 *       IMPORT_LY_CANDIDATE_VOTE_CSV
 */

include(__DIR__ . '/../init.inc.php');

$COUNCILS = [
    'na' => [
        '議會名稱' => '國民大會', '議會種類' => '國民大會', '議會類別' => '國民大會',
        '生效日期' => '1948-03-29', '廢止日期' => '2005-06-10',
        '維基條目' => 'https://zh.wikipedia.org/zh-tw/國民大會',
    ],
    'tpg' => [
        '議會名稱' => '臺灣省議會', '議會種類' => '省議會', '議會類別' => '省議會',
        '生效日期' => '1959-06-24', '廢止日期' => '1998-12-20',
        '維基條目' => 'https://zh.wikipedia.org/wiki/臺灣省議會',
    ],
];
// [議會代碼, 屆次, 投票日, 就職日, 任期屆滿日, 備註]
$TERMS = [
    ['na', 1, '1947-11-21', '1948-03-29', '1991-12-31', '第一屆國民大會，原定任期 6 年，後延任至 1991 年底'],
    ['na', 2, '1991-12-21', '1992-01-01', '1996-05-19', ''],
    ['na', 3, '1996-03-23', '1996-05-20', '2000-05-19', '第三屆起設議長、副議長'],
    ['na', 4, '2005-05-14', '2005-05-20', '2005-06-07', '任務型國民大會（政黨比例代表，複決憲法修正案後停止運作）'],
    ['tpg', 1, '1957-04-21', '1959-06-24', '1960-06-02', '由臺灣省臨時省議會第三屆改制'],
    ['tpg', 2, '1960-04-24', '1960-06-02', '1963-06-02', ''],
    ['tpg', 3, '1963-04-28', '1963-06-02', '1968-06-02', ''],
    ['tpg', 4, '1968-04-21', '1968-06-02', '1973-02-01', ''],
    ['tpg', 5, '1972-12-23', '1973-02-01', '1977-12-20', ''],
    ['tpg', 6, '1977-11-19', '1977-12-20', '1981-12-20', ''],
    ['tpg', 7, '1981-11-14', '1981-12-20', '1985-12-20', ''],
    ['tpg', 8, '1985-11-16', '1985-12-20', '1989-12-20', ''],
    ['tpg', 9, '1989-12-02', '1989-12-20', '1994-12-20', ''],
    ['tpg', 10, '1994-12-03', '1994-12-20', '1998-12-20', '最後一屆，1998 年 12 月 21 日改制為臺灣省諮議會'],
];
// 選舉代碼 => [議會代碼, 屆次, 選舉類型（候選人）, 參選職位, 職稱（名單）]
$ELECTIONS = [
    'ELC-N0-03' => ['na', 3, '國大代表', null, '國民大會代表'],
    'ELC-N0-94' => ['na', 4, '國大代表', null, null],
    'ELC-T9-83' => ['tpg', 10, '省議員', null, '省議員'],
    'ELC-P9-83' => ['tpg', 10, '省長', '省長', null],
];

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
$ours = fn($code) => isset($ELECTIONS[explode(':', $code)[0]]);

// 議會、屆期
$latest = [];
foreach ($TERMS as [$cc, $no, $vote, $start, $end, $note]) {
    $id = "{$cc}-{$no}";
    Elastic::dbBulkInsert('term', $id, array_filter([
        '代碼' => $id, '議會代碼' => $cc, '屆次' => $no, '現任' => false,
        '投票日' => $vote, '就職日' => $start, '任期屆滿日' => $end, '備註' => $note ?: null,
    ], fn($v) => $v !== null));
    $latest[$cc] = max($latest[$cc] ?? 0, $no);
}
Elastic::dbBulkCommit('term');
foreach ($COUNCILS as $cc => $c) {
    Elastic::dbBulkInsert('council', $cc, $c + ['代碼' => $cc, '現存' => false, '最新屆期代碼' => "{$cc}-{$latest[$cc]}"]);
}
Elastic::dbBulkCommit('council');

// 人物代碼、得票數
$person_code = [];
$path = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$fh = ($path && file_exists($path)) ? fopen($path, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_PERSON_SOURCE_JSONL）：{$path}");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    if (!preg_match('/ELC-(N0|T9|P9)-/', $line)) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if ($ours($rec->person_id ?? '')) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);
$votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === count($headers) && $ours($row[0]) && strpos($row[2], $row[0] . ':') === 0) {
        $votes[$row[2]] = intval($row[3]);
    }
}
fclose($fh);

// 候選人
$candidates = [];
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !isset($ELECTIONS[$row[0]])) {
        continue;
    }
    $r = array_combine($headers, $row);
    [$cc, $term, $type, $position] = $ELECTIONS[$r['選舉代碼']];
    $code = $r['候選人代碼'];
    $parts = explode(':', $code);
    $candidates[$code] = array_filter([
        '代碼'       => $code,
        '候選人代碼' => $code,
        '選舉代碼'   => $r['選舉代碼'],
        '選舉名稱'   => $r['選舉名稱'],
        '選舉類型'   => $type,
        '參選職位'   => $position,
        '年份'       => preg_match('/^(\d+)年/u', $r['選舉名稱'], $m) ? (int) $m[1] : null,
        '議會代碼'   => $cc,
        '縣市'       => str_replace('台', '臺', $r['縣市']) ?: null,
        '行政區代碼' => $r['行政區代碼'] ?: null,
        '選區別'     => $r['選區別'] ?: (str_replace('台', '臺', $r['縣市']) ?: '全國'),
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
        '當選'       => in_array($r['當選註記'], ['*', '!'], true),
        '當選註記'   => $r['當選註記'] ?: null,
        '_race'      => $r['選舉代碼'] . '|' . (count($parts) > 2 ? $parts[1] : ''),
        '_term'      => $term,
    ], fn($v) => $v !== null);
}
fclose($fh);

$races = [];
foreach ($candidates as $code => $c) {
    if (isset($c['得票數'])) {
        $races[$c['_race']][] = $code;
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

// 代表／議員名單：區域當選人
$councilors = 0;
foreach ($candidates as $code => $c) {
    [$cc, $term, , , $title] = $ELECTIONS[$c['選舉代碼']];
    $race = $c['_race'];
    unset($c['_race'], $c['_term']);
    Elastic::dbBulkInsert('candidate', $code, $c);
    if (!$title || !$c['當選']) {
        continue;
    }
    $doc_id = "{$cc}-{$term}-{$c['姓名']}";
    Elastic::dbBulkInsert('councilor', $doc_id, array_filter([
        '代碼'     => $doc_id,
        '人物代碼' => $c['人物代碼'] ?? $code,
        '參選代碼' => $code,
        '選舉代碼' => $c['選舉代碼'],
        '參選政黨' => $c['其他欄位']['推薦之政黨'] ?? null,
        '號次'     => (int) $c['號次'],
        '姓名'     => $c['姓名'],
        '性別'     => $c['其他欄位']['性別'] ?? null,
        '黨籍'     => $c['其他欄位']['推薦之政黨'] ?? null,
        '單位'     => $COUNCILS[$cc]['議會名稱'],
        '職稱'     => $title,
        '區域'     => $c['縣市'] ?? null,
        '選區別'   => $c['選區別'] ?? null,
        '學歷'     => $c['學歷'] ?? null,
        '議會代碼' => $cc,
        '屆代碼'   => "{$cc}-{$term}",
        '屆次'     => $term,
        // 「現況」是這一屆任期內有沒有中途卸任（其他議會任期屆滿的也是「在任」、不顯示 badge），
        // 不是這個機關現在還在不在；這兩個機關的名單都是任期屆滿，沒有中途異動資料
        '現況'     => '在任',
    ], fn($v) => $v !== null));
    $councilors++;
}
Elastic::dbBulkCommit('candidate');
Elastic::dbBulkCommit('councilor');
error_log(sprintf('Done. %d 個議會、%d 個屆期、%d 位候選人、%d 位代表／議員。',
    count($COUNCILS), count($TERMS), count($candidates), $councilors));
