<?php
/**
 * 匯入村里長選舉候選人到 candidate index
 *
 * 用法：
 *   php scripts/import-village-candidate.php
 *
 * 寫進既有的 candidate index（不建新 index、不支援 --reset，要重建請用
 * import-candidate.php --reset 後再跑這支）
 *
 * 來源：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv（選舉代碼 ELC-V0-{年}，以及重行選舉
 *                                        ELC-V0-{年}-{村里行政區代碼}、補選 BEL-V0-{年}:{投票日}:{村里代碼}）
 *   IMPORT_CANDIDATE_MANIFESTO_SUPPLEMENT_JSONL 政見圖 OCR 補充檔（選填，逗號分隔）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv（跟立委、縣市長共用同一份）
 *   IMPORT_CANDIDATE_VILLAGE_JSONL       村里長選舉公報萃取結果（依候選人代碼併入學經歷、政見、相片；
 *                                        選填，沒有的話只匯入名冊）。可以用逗號列多個檔案（例：各年份的
 *                                        文字層解析結果、掃描公報的辨識結果），同一位候選人以有政見的那筆為準
 *
 * 名單以中選會名冊為準，沒有公報（或公報還沒解析）的村里長一樣會列出來，只是沒有政見。
 * 參選職位：村里長
 * 鄉鎮市區／鄉鎮市區代碼（行政區代碼前 8 碼）／村里：村里長名單頁依這三個欄位分組
 * 選區別：鄉鎮市區＋村里（例：中山區下埤里）；行政區代碼是村里層級，同一場選舉一個村里一個選區
 * 年份：重行選舉、補選的年份沿用代碼裡的任期年份（例：ELC-V0-107-… 是 107 年那次選舉之後的重行選舉，
 * BEL-V0-111:20240817:… 是 111 年選出的那一任中途出缺、113 年 8 月 17 日的補選）；補選另有「補選」「投票日」欄位
 * 重行選舉在名冊裡「選舉名稱」跟「行政區代碼」兩欄對調（選舉名稱欄是行政區代碼，行政區代碼欄才是
 * 「臺北市第12屆大安區光武里里長重行選舉」這樣的名稱），部分還缺鄉鎮市區／村里別，用同一個行政區
 * 代碼在正式選舉裡的鄉鎮市區／村里補上
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

// 縣市 → 議會代碼（依選舉年份處理縣市改制），同 import-mayor-candidate.php
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
    '參選職位'     => ['type' => 'keyword'],
    '鄉鎮市區'     => ['type' => 'keyword'],
    '鄉鎮市區代碼' => ['type' => 'keyword'],
    '村里'         => ['type' => 'keyword'],
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
    if (strpos($line, '-V0-') === false) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if (preg_match('/^(ELC|BEL)-V0-/', $rec->person_id ?? '')) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 得票數
$votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === count($headers) && preg_match('/^(ELC|BEL)-V0-/', $row[0]) && strpos($row[2], $row[0] . ':') === 0) {
        $votes[$row[2]] = intval($row[3]);
    }
}
fclose($fh);

// 候選人
$rows = [];
$area_names = [];  // 行政區代碼 → [鄉鎮市區, 村里]
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !preg_match('/^(ELC|BEL)-V0-/', $row[0])) {
        continue;
    }
    $r = array_combine($headers, $row);
    if (preg_match('/^\d{8}-\d{3}$/', $r['選舉名稱'])) {
        [$r['選舉名稱'], $r['行政區代碼']] = [$r['行政區代碼'], $r['選舉名稱']];
    }
    if ($r['鄉鎮市區'] !== '' && $r['村里別'] !== '') {
        $area_names[$r['行政區代碼']] = [$r['鄉鎮市區'], $r['村里別']];
    }
    $rows[] = $r;
}
fclose($fh);

$candidates = [];
$skipped = 0;
foreach ($rows as $r) {
    if (!preg_match('/^(?:ELC-V0-(\d+)(-\d+)?|BEL-V0-(\d+):(\d{8}):\d+)$/', $r['選舉代碼'], $m)) {
        continue;
    }
    $by_election = ($m[3] ?? '') !== '';
    $year = (int) ($by_election ? $m[3] : $m[1]);
    if ($r['鄉鎮市區'] === '' || $r['村里別'] === '') {
        [$r['鄉鎮市區'], $r['村里別']] = $area_names[$r['行政區代碼']] ?? ['', ''];
    }
    $county = str_replace('台', '臺', $r['縣市']);
    $cc_code = $council_of($county, $year);
    if (!$cc_code) {
        $skipped++;
        continue;
    }
    $area_code = str_replace('-', '', $r['行政區代碼']);
    $town = $r['鄉鎮市區'];
    $village = $r['村里別'];
    $code = $r['候選人代碼'];
    $candidates[$code] = [
        '代碼'         => $code,
        '候選人代碼'   => $code,
        '選舉代碼'     => $r['選舉代碼'],
        '選舉名稱'     => $r['選舉名稱'],
        '參選職位'     => '村里長',
        '年份'         => $year,
        '議會代碼'     => $cc_code,
        '縣市'         => $county,
        '行政區代碼'   => $r['行政區代碼'] ?: null,
        '鄉鎮市區'     => $town,
        '鄉鎮市區代碼' => substr($area_code, 0, 8) ?: null,
        '村里'         => $village,
        '選區別'       => $town . $village,
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
    ] + ($by_election ? ['補選' => true, '投票日' => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $m[4])] : []);
}

// 選舉公報（依候選人代碼併入；同代碼多筆時優先取有政見文字的）
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision', 'paddleocr'], true);
$bulletins = [];
foreach (array_filter(array_map('trim', explode(',', (string) getenv('IMPORT_CANDIDATE_VILLAGE_JSONL')))) as $bulletin_path) {
    if (!file_exists($bulletin_path)) {
        error_log("WARNING: 找不到村里長選舉公報（IMPORT_CANDIDATE_VILLAGE_JSONL）：{$bulletin_path}");
        continue;
    }
    $fh = fopen($bulletin_path, 'r');
    while (($line = fgets($fh)) !== false) {
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
}
if (!$bulletins) {
    error_log('WARNING: 沒有讀到村里長選舉公報（IMPORT_CANDIDATE_VILLAGE_JSONL），只匯入名冊');
}
$skip_keys = ['代碼', '候選人代碼', '選舉代碼', '選舉名稱', '選舉類型', '縣市', '行政區代碼', '選區別', '姓名', '號次'];
foreach ($bulletins as $code => $r) {
    // 欄位錯位時學經歷、政見會裝到別的欄位的內容（政黨、出生地、公報標題），那種不採用（見 BulletinFieldHelper）
    [$r] = BulletinFieldHelper::sanitize($r);
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

// 政見補充檔（選填，IMPORT_CANDIDATE_MANIFESTO_SUPPLEMENT_JSONL，逗號分隔）：政見是圖片的候選人另外用 OCR 辨識
// 政見圖的結果，只在公報本身的政見不是可用文字時才用
foreach (array_filter(array_map('trim', explode(',', (string) getenv('IMPORT_CANDIDATE_MANIFESTO_SUPPLEMENT_JSONL')))) as $path) {
    if (!file_exists($path)) {
        continue;
    }
    foreach (file($path) as $line) {
        $d = json_decode($line, true);
        $code = $d['候選人代碼'] ?? '';
        if (isset($candidates[$code]) && trim((string) ($d['政見'] ?? '')) !== ''
            && (!$has_manifesto($candidates[$code]) || trim((string) ($candidates[$code]['政見'] ?? '')) === '')) {
            $candidates[$code]['政見'] = $d['政見'];
            $candidates[$code]['政見來源'] = $d['政見來源'] ?? 'paddleocr';
        }
    }
}

// 同一場選舉同一村里內計算得票率、排名
$races = [];
foreach ($candidates as $code => $c) {
    if ($c['得票數'] !== null) {
        $races[$c['選舉代碼'] . '|' . $c['行政區代碼']][] = $code;
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
error_log(sprintf('Done. Imported %d village chief candidates (%d with bulletin, %d with manifesto; %d skipped: unknown county).',
    count($candidates), count($bulletins), count(array_filter($bulletins, $has_manifesto)), $skipped));
