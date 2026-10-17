<?php
/**
 * 匯入總統、副總統選舉候選人到 candidate index（議會代碼掛在立法院 ly 底下）
 *
 * 用法：
 *   php scripts/import-president-candidate.php
 *
 * 寫進既有的 candidate index（不建新 index、不支援 --reset）
 *
 * 來源：
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv（選舉代碼 ELC-P0-{任次}；副總統候選人的
 *                                        候選人代碼是「{總統候選人代碼}:副手」）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 人物代碼對照 person.jsonl
 *   IMPORT_LY_CANDIDATE_VOTE_CSV         候選人得票數 vote.csv（一組一個得票數，副總統沿用同組的）
 *   IMPORT_CANDIDATE_JSONL               選舉公報萃取結果 bulletin.jsonl（依候選人代碼併入學經歷、政見、相片）
 *
 * 參選職位：總統／副總統；搭檔：同組另一位的候選人代碼；年份：選舉名稱裡的民國年（例：113）
 * 議會代碼：ly（總統副總統不屬於任何地方議會，立法院「行政體系」頁最上面顯示總統、副總統）
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
$bulletin_image_url = function ($path) {
    if (!$path) return null;
    $path = preg_replace('#^files/#', '', $path);
    return 'https://lydata.ronny-s3.click/bulletin/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};

$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}candidate/_mapping", 'PUT', json_encode(['properties' => [
    '參選職位' => ['type' => 'keyword'],
    '搭檔'     => ['type' => 'keyword'],
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
    if (strpos($line, 'ELC-P0-') === false) {
        continue;
    }
    $p = json_decode($line);
    foreach ($p->records ?? [] as $rec) {
        if (strpos($rec->person_id ?? '', 'ELC-P0-') === 0) {
            $person_code[$rec->person_id] = $p->id;
        }
    }
}
fclose($fh);

// 得票數（一組一筆，鍵是總統候選人的代碼）
$votes = [];
[$fh, $headers] = $open_csv('IMPORT_LY_CANDIDATE_VOTE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === count($headers) && strpos($row[0], 'ELC-P0-') === 0 && strpos($row[2], $row[0] . ':') === 0) {
        $votes[$row[2]] = intval($row[3]);
    }
}
fclose($fh);

$candidates = [];
[$fh, $headers] = $open_csv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || strpos($row[0], 'ELC-P0-') !== 0) {
        continue;
    }
    $r = array_combine($headers, $row);
    if (!preg_match('/^(\d+)年/u', $r['選舉名稱'], $m)) {
        continue;
    }
    $code = $r['候選人代碼'];
    $is_vice = str_ends_with($code, ':副手');
    $ticket = $is_vice ? substr($code, 0, -strlen(':副手')) : $code;
    $candidates[$code] = [
        '代碼'       => $code,
        '候選人代碼' => $code,
        '選舉代碼'   => $r['選舉代碼'],
        '選舉名稱'   => $r['選舉名稱'],
        '參選職位'   => $is_vice ? '副總統' : '總統',
        '搭檔'       => $is_vice ? $ticket : $ticket . ':副手',
        '年份'       => (int) $m[1],
        '議會代碼'   => 'ly',
        '縣市'       => '全國',
        '選區別'     => '全國',
        '姓名'       => $r['姓名'],
        '號次'       => $r['號次'],
        '學歷'       => $r['學歷'] ?: null,
        '其他欄位'   => array_filter([
            '性別'       => $r['性別'],
            '推薦之政黨' => $r['政黨'],
            '出生日期'   => $r['出生日期'],
            '出生地'     => $r['出生地'],
        ], fn($v) => $v !== ''),
        '得票數'     => $votes[$ticket] ?? null,
        '人物代碼'   => $person_code[$code] ?? null,
        '當選'       => in_array($r['當選註記'], ['*', '!'], true),
        '當選註記'   => $r['當選註記'] ?: null,
    ];
}
fclose($fh);

// 選舉公報（依候選人代碼併入）
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision', 'paddleocr'], true);
$bulletins = [];
$bulletin_path = getenv('IMPORT_CANDIDATE_JSONL');
if ($bulletin_path && file_exists($bulletin_path)) {
    $fh = fopen($bulletin_path, 'r');
    while (($line = fgets($fh)) !== false) {
        if (strpos($line, 'ELC-P0-') === false) {
            continue;
        }
        $r = json_decode($line, true);
        $code = $r['候選人代碼'] ?? '';
        if (isset($candidates[$code]) && (!isset($bulletins[$code]) || (!$has_manifesto($bulletins[$code]) && $has_manifesto($r)))) {
            $bulletins[$code] = $r;
        }
    }
    fclose($fh);
} else {
    error_log("WARNING: 找不到選舉公報（IMPORT_CANDIDATE_JSONL），只匯入名冊");
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

// 得票率、排名（每次選舉一組一筆，副總統沿用同組的）
$by_election = [];
foreach ($candidates as $code => $c) {
    if ($c['參選職位'] === '總統' && $c['得票數'] !== null) {
        $by_election[$c['選舉代碼']][] = $code;
    }
}
foreach ($by_election as $codes) {
    $total = array_sum(array_map(fn($code) => $candidates[$code]['得票數'], $codes));
    usort($codes, fn($a, $b) => $candidates[$b]['得票數'] <=> $candidates[$a]['得票數']);
    foreach ($codes as $i => $code) {
        foreach ([$code, $code . ':副手'] as $c) {
            if (isset($candidates[$c])) {
                $candidates[$c]['得票排名'] = $i + 1;
                $candidates[$c]['得票率'] = $total ? round($candidates[$code]['得票數'] / $total * 100, 2) : null;
            }
        }
    }
}

foreach ($candidates as $code => $c) {
    Elastic::dbBulkInsert('candidate', $code, $c);
}
Elastic::dbBulkCommit('candidate');
error_log(sprintf('Done. Imported %d presidential candidates (%d with bulletin, %d with manifesto).',
    count($candidates), count($bulletins), count(array_filter($bulletins, $has_manifesto))));
