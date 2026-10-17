<?php
/**
 * 從中選會候選人名冊補匯入「沒有選舉公報」的議員候選人到 candidate index
 *
 * 用法：
 *   php scripts/import-roster-councilor-candidate.php
 *
 * 議員候選人原本由 import-candidate.php 從選舉公報匯入（學經歷、政見、相片），公報只回溯到
 * 民國 98／99 年，更早的選舉（直轄市議員 83～95 年、縣市議員 87～94 年）以及議員缺額補選
 * 只有名冊與得票。這支把名冊裡、candidate index 還沒有的議員候選人補進來，讓候選人頁的歷年
 * 參選紀錄可以回溯到更早；**已經從公報匯入的候選人代碼一律不動**（不覆蓋公報的欄位）。
 *
 * 來源：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv（ELC-T1／ELC-T2 與補選 BEL-T1／BEL-T2）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv
 *
 * 補選：選舉代碼 BEL-T{1,2}-{任期年}:{投票日}:{縣市代碼}-{選區}，「選舉名稱」「行政區代碼」兩欄對調，
 * 年份用任期年份，另有「補選」「投票日」欄位；選區別換成同一任正式選舉的寫法（名冊裡補選只寫「第7選舉區」）
 */

include(__DIR__ . '/../init.inc.php');

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

// 縣市 → 議會代碼（依選舉年份處理縣市改制），同 import-village-candidate.php
$council_of = function ($county, $year) {
    $county = str_replace('台', '臺', $county);
    $abolished = ['臺北縣' => 'tpq', '臺中縣' => 'txq', '臺南縣' => 'tnq', '高雄縣' => 'khq', '桃園縣' => 'tao-1952'];
    if (isset($abolished[$county])) {
        return $abolished[$county];
    }
    if ($year < 99) {
        $before_2010 = ['臺中市' => 'txg-1950', '臺南市' => 'tnn-1950', '高雄市' => 'khh-1981'];
        if (isset($before_2010[$county])) {
            return $before_2010[$county];
        }
    }
    return CountyCodeHelper::getCode($county);
};
$code_re = '/^(?:ELC-(T1|T2)-(\d+)|BEL-(T1|T2)-(\d+):(\d{8}):([\d-]+))$/';
$is_ours = fn($code) => (bool) preg_match('/^(ELC|BEL)-T[12]-/', $code);

$prefix = getenv('ELASTIC_PREFIX');

// 已經在 index 裡的議員候選人代碼（從公報匯入的），不要動
$existing = [];
foreach (['ELC-T1-', 'ELC-T2-', 'BEL-T1-', 'BEL-T2-'] as $p) {
    $r = Elastic::dbQuery("/{$prefix}candidate/_search", 'POST', json_encode([
        'size' => 10000, '_source' => false, 'query' => ['prefix' => ['候選人代碼' => $p]],
    ]));
    foreach ($r->hits->hits ?? [] as $h) {
        $existing[$h->_id] = true;
    }
}

// 候選人代碼 → 人物代碼
$person_code = [];
$path = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$fh = ($path && file_exists($path)) ? fopen($path, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_PERSON_SOURCE_JSONL）：{$path}");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '-T1-') === false && strpos($line, '-T2-') === false) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if ($is_ours($rec->person_id ?? '')) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 得票數
$votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === count($headers) && $is_ours($row[0]) && strpos($row[2], $row[0] . ':') === 0) {
        $votes[$row[2]] = intval($row[3]);
    }
}
fclose($fh);

// 名冊（先全部讀進來：補選的選區別要用同一任正式選舉的寫法）
$rows = [];
$district_names = [];  // [類別][年][選區代碼（例：10004-7）] => 正式選舉的選區別
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !$is_ours($row[0])) {
        continue;
    }
    $r = array_combine($headers, $row);
    if (preg_match('/^ELC-(T1|T2)-(\d+)$/', $r['選舉代碼'], $m) && $r['選區別'] !== '') {
        $parts = explode(':', $r['候選人代碼']);
        $district_names[$m[1]][(int) $m[2]][$parts[count($parts) - 2]] = $r['選區別'];
    }
    $rows[] = $r;
}
fclose($fh);

$candidates = [];
$skipped = 0;
foreach ($rows as $r) {
    if (!preg_match($code_re, $r['選舉代碼'], $m)) {
        continue;
    }
    $by_election = ($m[3] ?? '') !== '';
    $kind = $by_election ? $m[3] : $m[1];
    $year = (int) ($by_election ? $m[4] : $m[2]);
    $code = $r['候選人代碼'];
    $parts = explode(':', $code);
    $district_key = $parts[count($parts) - 2];
    if ($by_election) {
        [$r['選舉名稱'], $r['行政區代碼']] = [$r['行政區代碼'], $r['選舉名稱']];
        $r['選區別'] = $district_names[$kind][$year][$district_key] ?? ($r['縣市'] . $r['選區別']);
    }
    $county = str_replace('台', '臺', $r['縣市']);
    $cc_code = $council_of($county, $year);
    if (!$cc_code) {
        $skipped++;
        continue;
    }
    $candidates[$code] = [
        '代碼'         => $code,
        '候選人代碼'   => $code,
        '選舉代碼'     => $r['選舉代碼'],
        '選舉名稱'     => $r['選舉名稱'],
        '選舉類型'     => $kind === 'T1' ? '直轄市議員' : '縣市議員',
        '年份'         => $year,
        '議會代碼'     => $cc_code,
        '縣市'         => $county,
        '行政區代碼'   => explode('-', $district_key)[0] ?: null,
        '選區別'       => $r['選區別'] ?: $county,
        '姓名'         => $r['姓名'],
        '號次'         => $r['號次'],
        '學歷'         => $r['學歷'] ?: null,
        '其他欄位'     => array_filter([
            '性別'       => $r['性別'],
            '推薦之政黨' => $r['政黨'],
            '出生日期'   => $r['出生日期'],
            '出生地'     => $r['出生地'],
            '現任'       => $r['現任'] === 'Y' ? '是' : '',
        ], fn($v) => $v !== ''),
        '得票數'       => $votes[$code] ?? null,
        '人物代碼'     => $person_code[$code] ?? null,
        '當選'         => in_array($r['當選註記'], ['*', '!'], true),
        '當選註記'     => $r['當選註記'] ?: null,
        '_race'        => $r['選舉代碼'] . '|' . $district_key,
    ] + ($by_election ? ['補選' => true, '投票日' => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $m[5])] : []);
}

// 得票率、排名（同一場選舉同一選區；用名冊裡的全部候選人算，包含已經從公報匯入的）
$races = [];
foreach ($candidates as $code => $c) {
    if ($c['得票數'] !== null) {
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

$added = [];
foreach ($candidates as $code => $c) {
    if (isset($existing[$code])) {
        continue;
    }
    unset($c['_race']);
    $added[$c['選舉代碼']] = ($added[$c['選舉代碼']] ?? 0) + 1;
    Elastic::dbBulkInsert('candidate', $code, $c);
}
Elastic::dbBulkCommit('candidate');
ksort($added);
error_log(sprintf('Done. 名冊 %d 位議員候選人，已有 %d 位（公報匯入，不動），新增 %d 位（%s）；%d skipped.',
    count($candidates), count(array_intersect_key($candidates, $existing)), array_sum($added),
    implode('、', array_map(fn($k, $v) => "{$k} {$v}", array_keys($added), $added)), $skipped));
