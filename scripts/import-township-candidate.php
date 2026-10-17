<?php
/**
 * 匯入鄉鎮市長、鄉鎮市民代表，以及直轄市山地原住民區長、區民代表選舉候選人到 candidate index
 *
 * 用法：
 *   php scripts/import-township-candidate.php
 *
 * 寫進既有的 candidate index（不建新 index、不支援 --reset，要重建請用
 * import-candidate.php --reset 後再跑這支）
 *
 * 來源：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv（跟立委、縣市長共用同一份）
 *   IMPORT_CANDIDATE_TOWNSHIP_JSONL      選舉公報萃取結果（依候選人代碼併入學經歷、政見、相片；選填，
 *                                        可以用逗號列多個檔案，同一位候選人以有政見的那筆為準）
 *
 * 選舉代碼與參選職位：
 *   ELC-D2-{年}[-{鄉鎮市代碼}]   鄉鎮市長（後綴是重行選舉）
 *   ELC-R2-{年}[-{鄉鎮市代碼}-{選區}]  鄉鎮市民代表；但 103、107 年的直轄市山地原住民區民代表
 *                                名冊裡也用 ELC-R2（選舉名稱是「直轄市區民代表」），歸到山地原住民區民代表
 *   ELC-D1-{年}                  直轄市山地原住民區長
 *   ELC-R1-{年}                  直轄市山地原住民區民代表（111 年起）
 *   BEL-{D2,R2,D1,R1}-{年}:{投票日}:{行政區代碼[-選區]}  以上各種的補選（年份是任期年份），另有「補選」「投票日」欄位；
 *                                代表補選的選區別換成同一任正式選舉的寫法（名冊裡補選只寫「第4選舉區」）
 *
 * 鄉鎮市區／鄉鎮市區代碼（行政區代碼前 8 碼）：行政體系「鄉鎮市／村里長」頁依這兩個欄位分組
 * 選區別：鄉鎮市長、區長是鄉鎮市區名稱；代表是名冊的選區名稱（例：茂林區第01選舉區）
 * 重行選舉在名冊裡「選舉名稱」跟「行政區代碼」兩欄對調（同村里長），鄉鎮市代碼改從選舉代碼取
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
$bulletin_image_url = function ($path) {
    if (!$path) return null;
    $path = preg_replace('#^files/#', '', $path);
    return 'https://lydata.ronny-s3.click/bulletin/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};
$code_re = '/^(?:ELC-(D2|R2|D1|R1)-(\d+)(-[\d-]+)?|BEL-(D2|R2|D1|R1)-(\d+):(\d{8}):([\d-]+))$/';
$is_ours = fn($code) => (bool) preg_match('/^(ELC|BEL)-(D2|R2|D1|R1)-/', $code);

$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}candidate/_mapping", 'PUT', json_encode(['properties' => [
    '參選職位'     => ['type' => 'keyword'],
    '鄉鎮市區'     => ['type' => 'keyword'],
    '鄉鎮市區代碼' => ['type' => 'keyword'],
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
    if (!preg_match('/(ELC|BEL)-(D2|R2|D1|R1)-/', $line)) {
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

// 候選人（先讀進來：代表補選的選區別要用同一任正式選舉的寫法，得先看過正式選舉的選區）
$rows = [];
$district_names = [];  // [年][選區代碼（例：10008060-4）] => 正式選舉的選區別（例：名間鄉第04選區）
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !$is_ours($row[0])) {
        continue;
    }
    $r = array_combine($headers, $row);
    if (preg_match('/^ELC-(R1|R2)-(\d+)$/', $r['選舉代碼'], $m2) && $r['選區別'] !== '') {
        $parts = explode(':', $r['候選人代碼']);
        $district_names[(int) $m2[2]][$parts[count($parts) - 2]] = $r['選區別'];
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
    $by_election = ($m[4] ?? '') !== '';
    $kind = $by_election ? $m[4] : $m[1];
    $year = (int) ($by_election ? $m[5] : $m[2]);
    $rerun = !$by_election && ($m[3] ?? '') !== '';
    if ($rerun || $by_election) {
        [$r['選舉名稱'], $r['行政區代碼']] = [$r['行政區代碼'], $r['選舉名稱']];
    }
    if ($by_election) {
        $town_code = substr($m[7], 0, 8);
    } else {
        $town_code = $rerun ? substr(ltrim($m[3], '-'), 0, 8) : substr(str_replace('-', '', $r['行政區代碼']), 0, 8);
    }
    if (!preg_match('/^\d{8}$/', $town_code)) {
        $skipped++;
        continue;
    }
    $position = [
        'D2' => '鄉鎮市長',
        'R2' => mb_strpos($r['選舉名稱'], '直轄市') !== false ? '山地原住民區民代表' : '鄉鎮市民代表',
        'D1' => '山地原住民區長',
        'R1' => '山地原住民區民代表',
    ][$kind];
    $county = str_replace('台', '臺', $r['縣市']);
    $cc_code = $council_of($county, $year);
    if (!$cc_code) {
        $skipped++;
        continue;
    }
    $town = $r['鄉鎮市區'];
    $is_rep = in_array($kind, ['R1', 'R2'], true);
    $code = $r['候選人代碼'];
    // 代表的選區：候選人代碼倒數第二段（例：64000360-1），同一場選舉同一選區比得票
    $code_parts = explode(':', $code);
    $district_key = $code_parts[count($code_parts) - 2] ?? $town_code;
    $district = $r['選區別'] ?: $town;
    if ($by_election && $is_rep) {
        $district = $district_names[$year][$district_key] ?? ($town . $district);
    }
    $candidates[$code] = [
        '代碼'         => $code,
        '候選人代碼'   => $code,
        '選舉代碼'     => $r['選舉代碼'],
        '選舉名稱'     => $r['選舉名稱'],
        '參選職位'     => $position,
        '年份'         => $year,
        '議會代碼'     => $cc_code,
        '縣市'         => $county,
        '行政區代碼'   => $town_code,
        '鄉鎮市區'     => $town,
        '鄉鎮市區代碼' => $town_code,
        '選區別'       => $is_rep ? $district : $town,
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
    ] + ($by_election ? ['補選' => true, '投票日' => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $m[6])] : []);
}

// 選舉公報（依候選人代碼併入；同代碼多筆時優先取有政見文字的）
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision', 'paddleocr'], true);
$bulletins = [];
foreach (array_filter(array_map('trim', explode(',', (string) getenv('IMPORT_CANDIDATE_TOWNSHIP_JSONL')))) as $bulletin_path) {
    if (!file_exists($bulletin_path)) {
        error_log("WARNING: 找不到選舉公報（IMPORT_CANDIDATE_TOWNSHIP_JSONL）：{$bulletin_path}");
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

// 同一場選舉同一選區內計算得票率、排名
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

$by_position = [];
foreach ($candidates as $code => $c) {
    unset($c['_race']);
    $by_position[$c['參選職位']] = ($by_position[$c['參選職位']] ?? 0) + 1;
    Elastic::dbBulkInsert('candidate', $code, $c);
}
Elastic::dbBulkCommit('candidate');
error_log(sprintf('Done. Imported %d township candidates (%s; %d with bulletin; %d skipped).',
    count($candidates), implode('、', array_map(fn($k, $v) => "{$k} {$v}", array_keys($by_position), $by_position)),
    count($bulletins), $skipped));
