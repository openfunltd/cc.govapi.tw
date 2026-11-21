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
 *   IMPORT_REGISTRATION_BULLETIN_JSONL   選舉公報萃取結果（逗號分隔，選填），每筆有「登記識別碼」對到名冊；
 *                                        沒設時讀 IMPORT_CANDIDATE_JSONL 同目錄的 bulletin{,-village,-township}-115.jsonl
 *   IMPORT_REGISTRATION_MANIFESTO_JSONL  政見圖辨識結果（選填，每筆有「登記識別碼」）；公報的政見沒有文字時才用。
 *                                        沒設時讀同目錄的 bulletin-manifesto-paddle-115*.jsonl（逗號分隔可指定多個）
 *   IMPORT_REGISTRATION_FIELDS_JSONL     學經歷、相片補充檔（選填，每筆有「登記識別碼」）；公報紀錄都沒有那個欄位時才用。
 *                                        沒設時讀同目錄的 bulletin-fields-115*.jsonl
 *   IMPORT_REGISTRATION_QR_JSONL         政見圖 QR code 掃描結果（選填，每筆有「登記識別碼」「內容」），連結接在政見最後面。
 *                                        沒設時讀同目錄的 bulletin-qr-115*.jsonl
 *
 * 登記階段還沒有候選人代碼、號次、政見：
 *   代碼：REG-{年}:{識別碼}（識別碼是「選舉類型:選區:姓名」）
 *   選舉代碼：比照中選會的格式先用 ELC-{類別}-{年}（例：ELC-T1-115），選舉名稱比照歷屆（例：115年直轄市議員選舉）
 *   登記階段：true；登記日期；號次、得票數、當選都沒有
 *   人物代碼：同縣市過去參選過、姓名相同而且只有一個人的，連到那個人（尋求連任的議員、縣市長點進去
 *             看得到歷屆紀錄）；同縣市沒有就找全國性的（不分區立委、總統副總統）；對不到或同名多人的不連
 * 選區別比照歷屆同類選舉的寫法：議員「第01選舉區」（縣市在行政區代碼）、代表「竹北市第01選舉區」、
 * 村里長「中山區下埤里」、首長是縣市或鄉鎮市區名稱。
 * 選舉公報出來之後：對到公報的補上號次、相片、學經歷、政見與原始公報連結；公報已經涵蓋的選舉
 * （同選舉類型同選區至少一位對到）登記階段改成 false；縣市長、議員名冊沒有號次又不在公報裡的（未抽籤、已退出）不匯入。
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

// 選舉公報：登記識別碼 => 公報紀錄（同一人出現在多份公報時，留有可用政見的那筆）
$bulletin_paths = getenv('IMPORT_REGISTRATION_BULLETIN_JSONL')
    ? array_map('trim', explode(',', getenv('IMPORT_REGISTRATION_BULLETIN_JSONL')))
    : array_map(fn($k) => dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/bulletin{$k}-{$year}.jsonl", ['', '-village', '-township']);
// 議員選區範圍（選舉公報上印的鄉鎮市區；公報沒印的縣市用上一屆開票資料的組成）：「縣市|選區號碼」=> 範圍
$district_area = [];
$area_path = dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/councilor-district-area-{$year}.csv";
if (file_exists($area_path) && ($fh = fopen($area_path, 'r'))) {
    $headers = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $a = array_combine($headers, $row);
        $district_area["{$a['縣市']}|" . (int) $a['選區']] = $a['範圍'] ?: null;
    }
    fclose($fh);
}
$has_manifesto = fn($r) => in_array($r['政見來源'] ?? '', ['text', 'manifesto-image-vision', 'cell-image-vision', 'page-image-vision', 'paddleocr'], true);
$bulletins = [];
$bulletin_all = [];   // 同一人常出現在好幾份公報（合刊、重複印），選中的那筆缺的學經歷、相片從其他筆補
$covered_races = [];  // 公報已經涵蓋的選舉：「選舉類型:選區」
foreach ($bulletin_paths as $bulletin_path) {
    if (!file_exists($bulletin_path)) {
        continue;
    }
    $bfh = fopen($bulletin_path, 'r');
    while (($line = fgets($bfh)) !== false) {
        $b = json_decode($line, true);
        $id = $b['登記識別碼'] ?? null;
        if (!$id) {
            continue;
        }
        if (!isset($bulletins[$id]) || (!$has_manifesto($bulletins[$id]) && $has_manifesto($b))) {
            $bulletins[$id] = $b;
        }
        $bulletin_all[$id][] = $b;
        $covered_races[substr($id, 0, strrpos($id, ':'))] = true;
    }
    fclose($bfh);
}
$bulletin_image_url = function ($path) {
    $path = preg_replace('#^files/#', '', $path);
    return 'https://lydata.ronny-s3.click/bulletin/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};
// 政見圖辨識結果：登記識別碼 => 政見文字
$manifesto_ocr = [];
$manifesto_paths = getenv('IMPORT_REGISTRATION_MANIFESTO_JSONL')
    ? array_map('trim', explode(',', getenv('IMPORT_REGISTRATION_MANIFESTO_JSONL')))
    : glob(dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/bulletin-manifesto-paddle-{$year}*.jsonl");
foreach (array_merge(...array_map(fn($p) => file_exists($p) ? file($p) : [], $manifesto_paths ?: [])) as $line) {
    $m = json_decode($line, true);
    if (isset($m['登記識別碼']) && trim((string) ($m['政見'] ?? '')) !== '') {
        $manifesto_ocr[$m['登記識別碼']] = $m['政見'];
    }
}
// 學經歷、相片補充檔（選填，每筆有「登記識別碼」與要補的欄位）：公報萃取漏掉、另外裁圖辨識或看圖照抄的結果，
// 公報紀錄都沒有那個欄位時才用。沒設時讀同目錄的 bulletin-fields-115*.jsonl
$field_supplement = [];
$field_paths = getenv('IMPORT_REGISTRATION_FIELDS_JSONL')
    ? array_map('trim', explode(',', getenv('IMPORT_REGISTRATION_FIELDS_JSONL')))
    : glob(dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/bulletin-fields-{$year}*.jsonl");
foreach (array_merge(...array_map(fn($p) => file_exists($p) ? file($p) : [], $field_paths ?: [])) as $line) {
    $m = json_decode($line, true);
    foreach (['學歷', '經歷', '相片路徑'] as $key) {
        if (isset($m['登記識別碼']) && trim((string) ($m[$key] ?? '')) !== '' && !isset($field_supplement[$m['登記識別碼']][$key])) {
            $field_supplement[$m['登記識別碼']][$key] = ['value' => $m[$key], 'source' => $m["{$key}來源"] ?? null];
        }
    }
}
// 學經歷、政見更正檔（選填）：公報萃取欄位錯位（學歷混進經歷與政見、經歷變成投票須知、政見拿到隔壁候選人的），
// 依卡片格子重新取字的結果。有出現的欄位直接取代公報紀錄的值，空字串代表那格確實是空的（相片路徑空字串用在
// 資格不符、公報不予刊登卻被配到別人卡片的人）。沒設時讀 bulletin-fix-115*.jsonl
$field_fix = [];
$fix_paths = getenv('IMPORT_REGISTRATION_FIX_JSONL')
    ? array_map('trim', explode(',', getenv('IMPORT_REGISTRATION_FIX_JSONL')))
    : glob(dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/bulletin-fix-{$year}*.jsonl");
foreach (array_merge(...array_map(fn($p) => file_exists($p) ? file($p) : [], $fix_paths ?: [])) as $line) {
    $m = json_decode($line, true);
    foreach (['學歷', '經歷', '政見', '相片路徑'] as $key) {
        if (isset($m['登記識別碼']) && array_key_exists($key, $m)) {
            $field_fix[$m['登記識別碼']][$key] = ['value' => trim((string) $m[$key]), 'source' => $m["{$key}來源"] ?? null];
        }
    }
}
// 政見圖裡掃到的 QR code（選填，每筆有「登記識別碼」與「內容」）：候選人放的粉專、完整政見連結，接在政見最後面。
// 沒設時讀同目錄的 bulletin-qr-115*.jsonl
$manifesto_links = [];
$qr_paths = getenv('IMPORT_REGISTRATION_QR_JSONL')
    ? array_map('trim', explode(',', getenv('IMPORT_REGISTRATION_QR_JSONL')))
    : glob(dirname((string) getenv('IMPORT_CANDIDATE_JSONL')) . "/bulletin-qr-{$year}*.jsonl");
foreach (array_merge(...array_map(fn($p) => file_exists($p) ? file($p) : [], $qr_paths ?: [])) as $line) {
    $m = json_decode($line, true);
    $url = trim((string) ($m['內容'] ?? ''));
    if (!isset($m['登記識別碼']) || !preg_match('#^(https?://)?[\w.-]+\.[a-z]{2,}(/\S*)?$#i', $url)) {
        continue;
    }
    $url = preg_match('#^https?://#i', $url) ? $url : "https://{$url}";
    $manifesto_links[$m['登記識別碼']][$url] = true;
}
$BULLETIN_FIELDS = ['來源', '來源PDF', '來源頁碼', '學歷', '學歷來源', '經歷', '經歷來源', '政見', '政見來源', '相片路徑', '政見圖路徑', '欄位圖片', '其他欄位'];

$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}candidate/_mapping", 'PUT', json_encode(['properties' => [
    '登記階段' => ['type' => 'boolean'],
    '登記日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
]]));

$count = 0;
$linked = 0;
$with_bulletin = 0;
$withdrawn = 0;
$skipped = [];
$seen = [];
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
    $bulletin = $bulletins[$r['識別碼']] ?? null;
    $covered = isset($covered_races[substr($r['識別碼'], 0, strrpos($r['識別碼'], ':'))]);
    // 只套用在縣市長、議員：地方選舉（鄉鎮市長、代表、村里長）不少縣市的名冊還沒有號次，沒號次不代表退出
    if ($covered && !$bulletin && ($r['號次'] ?? null) === null && !in_array($kind, ['D1', 'D2', 'R1', 'R2', 'V0'], true)) {
        $withdrawn++;
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
        '號次'       => $r['號次'] ?? (isset($bulletin['號次']) && ctype_digit((string) $bulletin['號次']) ? (int) $bulletin['號次'] : null),
        '登記階段'   => !$covered,
        '登記日期'   => $r['登記日期'] ?? null,
        'note'       => ($r['備註'] ?? '') !== '' ? $r['備註'] : null,
    ];
    if ($bulletin) {
        // 欄位錯位時學經歷、政見會裝到別的欄位的內容，那種不採用（見 BulletinFieldHelper）
        [$bulletin] = BulletinFieldHelper::sanitize($bulletin);
        foreach ($BULLETIN_FIELDS as $key) {
            $val = $bulletin[$key] ?? null;
            if ($val === '' || $val === null || $val === []) {
                continue;
            }
            if ($key === '相片路徑' || $key === '政見圖路徑') {
                $val = $bulletin_image_url($val);
            } elseif ($key === '欄位圖片') {
                $val = array_map($bulletin_image_url, $val);
            } elseif ($key === '其他欄位') {
                // 推薦之政黨以登記名冊為準（公報的政黨欄常混進具結聲明等文字）
                unset($val['推薦之政黨']);
                $val = array_merge($val, $doc['其他欄位']);
            }
            $doc[$key] = $val;
        }
        foreach ($field_fix[$r['識別碼']] ?? [] as $key => $fix) {
            unset($doc[$key], $doc["{$key}來源"]);
            if ($fix['value'] !== '') {
                $doc[$key] = $key === '相片路徑' ? $bulletin_image_url($fix['value']) : $fix['value'];
                if ($fix['source']) {
                    $doc["{$key}來源"] = $fix['source'];
                }
            }
        }
        foreach (['學歷', '經歷', '相片路徑'] as $key) {
            // 更正檔確認過的欄位（含確認空白）不再從其他紀錄、補充檔補
            if (isset($doc[$key]) || isset($field_fix[$r['識別碼']][$key])) {
                continue;
            }
            foreach ($bulletin_all[$r['識別碼']] ?? [] as $other) {
                [$other] = BulletinFieldHelper::sanitize($other);
                $val = $other[$key] ?? null;
                if (is_string($val) && trim($val) !== '') {
                    $doc[$key] = $key === '相片路徑' ? $bulletin_image_url($val) : $val;
                    if ($key !== '相片路徑' && isset($other["{$key}來源"])) {
                        $doc["{$key}來源"] = $other["{$key}來源"];
                    }
                    break;
                }
            }
            if (!isset($doc[$key]) && isset($field_supplement[$r['識別碼']][$key])) {
                $fill = $field_supplement[$r['識別碼']][$key];
                $doc[$key] = $key === '相片路徑' ? $bulletin_image_url($fill['value']) : $fill['value'];
                if ($key !== '相片路徑' && $fill['source']) {
                    $doc["{$key}來源"] = $fill['source'];
                }
            }
        }
        if (!$has_manifesto($doc) && isset($manifesto_ocr[$r['識別碼']]) && !isset($field_fix[$r['識別碼']]['政見'])) {
            $doc['政見'] = $manifesto_ocr[$r['識別碼']];
            $doc['政見來源'] = 'paddleocr';
        }
        if (isset($manifesto_links[$r['識別碼']])) {
            $doc['政見'] = trim(($doc['政見'] ?? '') . "\n\n" . implode("\n", array_keys($manifesto_links[$r['識別碼']])));
        }
        $with_bulletin++;
    }
    if ($is_councilor) {
        $doc['選舉類型'] = $label;
        $doc['行政區代碼'] = $county_code[$county] ?? null;
        $doc['選區別'] = $district_no($rest) ?? $r['選區'];
        $doc['選區範圍'] = preg_match('/第(\d+)選舉區/u', $doc['選區別'], $m) ? ($district_area["{$county}|" . (int) $m[1]] ?? null) : null;
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
    // 整筆取代（index）而不是 update：update 會跟舊文件合併，連「其他欄位」這種巢狀物件裡拿掉的鍵也會留著
    // （公報重新解析後拿掉的亂碼出生地、混進投票須知的政見）。REG- 開頭的紀錄只有這支會寫
    Elastic::dbBulkInsert('candidate', $doc['代碼'], array_filter($doc, fn($v) => $v !== null && $v !== []), true);
    $seen[$doc['代碼']] = true;
    $count++;
}
fclose($fh);
Elastic::dbBulkCommit('candidate');

// 來源修正姓名或選區時識別碼會跟著變（例：原住民族姓名去掉中間空格），舊識別碼的紀錄會留成重複的人，
// 匯入後刪掉來源已經沒有的登記紀錄；來源讀不到資料時不刪，避免整批清空
$removed = [];
if ($count > 0) {
    $index = '/' . getenv('ELASTIC_PREFIX') . 'candidate';
    $r = Elastic::dbQuery("{$index}/_search?scroll=2m", 'POST', json_encode([
        'size' => 5000, '_source' => false, 'query' => ['prefix' => ['代碼' => "REG-{$year}:"]],
    ]));
    while ($r->hits->hits ?? []) {
        foreach ($r->hits->hits as $hit) {
            if (!isset($seen[$hit->_id])) {
                $removed[] = $hit->_id;
            }
        }
        $r = Elastic::dbQuery('/_search/scroll', 'POST', json_encode(['scroll' => '2m', 'scroll_id' => $r->_scroll_id]));
    }
    foreach (array_chunk($removed, 1000) as $ids) {
        Elastic::dbQuery("{$index}/_delete_by_query?conflicts=proceed", 'POST', json_encode(['query' => ['ids' => ['values' => $ids]]]));
    }
}
error_log(sprintf('Done. 匯入 %d 位登記參選人（%d 位連到過去的參選紀錄、%d 位有選舉公報），%d 位對不到縣市略過%s，%d 位不在公報裡也沒有號次略過；刪除 %d 筆來源已經沒有的登記紀錄。',
    $count, $linked, $with_bulletin, count($skipped), $skipped ? '：' . implode('、', array_slice($skipped, 0, 5)) : '', $withdrawn, count($removed)));
