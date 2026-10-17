<?php
/**
 * 匯入縣市長／直轄市長選舉候選人到 candidate index
 *
 * 用法：
 *   php scripts/import-mayor-candidate.php
 *
 * 寫進既有的 candidate index（不建新 index、不支援 --reset，要重建請用
 * import-candidate.php --reset 後再跑這支）
 *
 * 來源：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv（選舉名稱「XX年直轄市市長選舉」
 *                                        「XX年縣(市)長選舉」）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv（跟立委共用同一份）
 *   IMPORT_CANDIDATE_JSONL               選舉公報萃取結果 bulletin.jsonl（依候選人代碼併入學經歷、政見、相片）
 *
 * 議會代碼：候選人所在縣市「監督這個縣市政府」的議會。縣市改制前的選舉對到已廢止的議會
 * （例：民國99年以前的臺中市是省轄市，對到 txg-1950；臺北縣對到 tpq）
 * 參選職位：直轄市長／縣市長（新增欄位，用來篩選縣市長選舉；不用公報的「選舉類型」，那是
 * 從檔名判斷的，合刊公報會不準）
 * 選區別：縣市名稱（整個縣市一個選區）；得票率／得票排名在同一場選舉同一縣市裡計算
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
$mayor_election = fn($name) => preg_match('/^(\d+)年(直轄市市長|直轄市長|縣\(市\)長)選舉/u', $name, $m) ? [(int) $m[1], $m[2]] : null;
// 縣市長補選：選舉代碼 BEL-{類別}-{任期年}:{投票日}:{縣市代碼 5 碼}，名冊的「選舉名稱」「行政區代碼」兩欄對調
//（實測 109 年高雄市長補選代碼是 BEL-D1-107:20200815:64000，類別碼誤用了原住民區長的 D1，所以只看縣市代碼與名稱）
$mayor_by_election = function ($r) {
    if (!preg_match('/^BEL-[A-Z0-9]+-(\d+):(\d{8}):(\d{5})$/', $r['選舉代碼'], $m)) {
        return null;
    }
    $name = $r['行政區代碼'];
    if (!preg_match('/(市長|縣長)補選/u', $name)) {
        return null;
    }
    $direct = in_array($m[3], ['63000', '64000', '65000', '66000', '67000', '68000'], true);
    return [(int) $m[1], $direct ? '直轄市長' : '縣(市)長', $name, $m[3], preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $m[2])];
};
$mayor_code = fn($code) => (bool) preg_match('/^(ELC-C[12]-|BEL-[A-Z0-9]+-\d+:\d{8}:\d{5}:)/', $code);
// 單一縣市另外投票的那一場（實測 111 年嘉義市長因候選人過世延期，代碼 ELC-C2-111-10020），
// 名冊格式同重行選舉：「選舉名稱」「行政區代碼」兩欄對調
$mayor_separate = fn($r) => preg_match('/^ELC-(C[12])-(\d+)-(\d{5})$/', $r['選舉代碼'], $m)
    ? [(int) $m[2], $m[1] === 'C1' ? '直轄市長' : '縣(市)長', $r['行政區代碼'], $m[3]] : null;

// 縣市 → 議會代碼（依選舉年份處理縣市改制）
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
$bulletin_image_url = function ($path) {
    if (!$path) return null;
    $path = preg_replace('#^files/#', '', $path);
    return 'https://lydata.ronny-s3.click/bulletin/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};

$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}candidate/_mapping", 'PUT', json_encode(['properties' => [
    '參選職位' => ['type' => 'keyword'],
]]));

// 候選人代碼 → 人物代碼
$person_code = [];
$path = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$fh = ($path && file_exists($path)) ? fopen($path, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_PERSON_SOURCE_JSONL）：{$path}");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '-C1-') === false && strpos($line, '-C2-') === false && strpos($line, '"BEL-') === false) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if ($mayor_code($rec->person_id ?? '')) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 得票數
$votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === count($headers) && $mayor_code($row[2]) && strpos($row[2], $row[0] . ':') === 0) {
        $votes[$row[2]] = intval($row[3]);
    }
}
fclose($fh);

// 候選人
$candidates = [];
$skipped = 0;
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers)) {
        continue;
    }
    $r = array_combine($headers, $row);
    $by_election = $mayor_by_election($r);
    if ($by_election) {
        [$year, $kind, $r['選舉名稱'], $r['行政區代碼']] = $by_election;
    } elseif ($separate = $mayor_separate($r)) {
        [$year, $kind, $r['選舉名稱'], $r['行政區代碼']] = $separate;
    } elseif ($e = $mayor_election($r['選舉名稱'])) {
        [$year, $kind] = $e;
    } else {
        continue;
    }
    $county = str_replace('台', '臺', $r['縣市']);
    $cc_code = $council_of($county, $year);
    if (!$cc_code) {
        $skipped++;
        continue;
    }
    $code = $r['候選人代碼'];
    $candidates[$code] = [
        '代碼'       => $code,
        '候選人代碼' => $code,
        '選舉代碼'   => $r['選舉代碼'],
        '選舉名稱'   => $r['選舉名稱'],
        '參選職位'   => $kind === '縣(市)長' ? '縣市長' : '直轄市長',
        '年份'       => $year,
        '議會代碼'   => $cc_code,
        '縣市'       => $county,
        '行政區代碼' => $r['行政區代碼'] ?: null,
        '選區別'     => $county,
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
    ] + ($by_election ? ['補選' => true, '投票日' => $by_election[4]] : []);
}
fclose($fh);

// 選舉公報（依候選人代碼併入；同代碼多筆時優先取有政見文字的）
$bulletin_path = getenv('IMPORT_CANDIDATE_JSONL');
$fh = ($bulletin_path && file_exists($bulletin_path)) ? fopen($bulletin_path, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_JSONL）：{$bulletin_path}");
    exit(1);
}
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision'], true);
$bulletins = [];
while (($line = fgets($fh)) !== false) {
    if (strpos($line, '"ELC-C') === false) {
        continue;
    }
    $r = json_decode($line, true);
    $code = $r['候選人代碼'] ?? '';
    if (!isset($candidates[$code])) {
        continue;
    }
    if (!isset($bulletins[$code]) || (!$has_manifesto($bulletins[$code]) && $has_manifesto($r))) {
        $bulletins[$code] = $r;
    }
}
fclose($fh);
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
        }
        $candidates[$code][$key] = $val;
    }
}

// 同一場選舉同一縣市內計算得票率、排名
$races = [];
foreach ($candidates as $code => $c) {
    if ($c['得票數'] !== null) {
        $races[$c['選舉代碼'] . '|' . $c['縣市']][] = $code;
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
error_log(sprintf('Done. Imported %d mayor candidates (%d with bulletin, %d with manifesto; %d skipped: unknown county).',
    count($candidates), count($bulletins), count(array_filter($bulletins, $has_manifesto)), $skipped));
