<?php
/**
 * 匯入立法委員（議會代碼 ly）資料到 councilor index
 *
 * 用法：
 *   php scripts/import-ly-councilor.php
 *
 * 寫進既有的 councilor index（不建新 index、不支援 --reset，要重建請用
 * import-councilor.php --reset 後再跑這支）
 *
 * 來源：
 *   IMPORT_LY_COUNCILOR_JSONL 歷屆委員 JSONL（立法院開放資料「歷屆委員資料」整理後，
 *                             已對好跨屆的 bioId）
 *   IMPORT_LY_TERM_CSV        屆.csv（判斷是否為任期中途就任的遞補/補選）
 *   IMPORT_CANDIDATE_ELECTED_SOURCE_CSV  中選會候選人名冊 cand.csv（對應參選代碼）
 *   IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 中選會人物代碼對照 person.jsonl
 *
 * 欄位對照：
 *   參選代碼  用「屆＋姓名（只比對漢字）」對應中選會立法委員選舉候選人（第3屆起才有資料，
 *             第3～6屆不分區／僑選沒有個別候選人，對不到）；同名多筆時依序用「不分區對
 *             政黨名單、區域對選區」「有當選註記」「正式選舉優先於補選」篩選。對到的同時
 *             帶入參選政黨、號次（不分區是名單排序）、選舉代碼
 *   人物代碼  跟地方議員同一套的中選會人物代碼：同一個 bioId（立法院自己的跨屆委員編號）
 *             任何一屆對得到候選人，各屆都用那個人物代碼；都對不到才用 ly-bio-{bioId}，
 *             連 bioId 都還沒有的少數新就任委員用 ly-{屆}-{姓名}
 *   職稱      一律「立法委員」（來源沒有院長／副院長資訊）
 *   身分別    區域／平地原住民／山地原住民／全國不分區／僑居國外國民（從選區名稱判斷）
 *   區域      區域立委的縣市（從選區名稱取出），其他身分別留空
 *   當選狀態  到職日晚於該屆就職日的：不分區是「遞補」，其餘是「補選當選」
 *   現況      leaveFlag=是 →「已卸任」（卸任日、卸任原因照來源）
 * 立法委員才有的欄位：英文姓名、黨團、委員會（每個會期一筆，如「第11屆第1會期：內政委員會」）、
 * 到職日
 */

include(__DIR__ . '/../init.inc.php');

$cc_code = 'ly';

$extra_mapping = [
    'properties' => [
        '英文姓名' => ['type' => 'keyword'],
        '黨團'     => ['type' => 'keyword'],
        '委員會'   => ['type' => 'keyword'],
        '到職日'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '號次'     => ['type' => 'integer'],
    ],
];
$prefix = getenv('ELASTIC_PREFIX');
Elastic::dbQuery("/{$prefix}councilor/_mapping", 'PUT', json_encode($extra_mapping));

// 各屆就職日
$term_start = [];
$term_csv = getenv('IMPORT_LY_TERM_CSV');
if (!$term_csv || !file_exists($term_csv)) {
    error_log("ERROR: 找不到屆.csv：{$term_csv}");
    exit(1);
}
$fh = fopen($term_csv, 'r');
fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    $term_start[intval($row[0])] = $row[1];
}
fclose($fh);

// 中選會立法委員選舉候選人，依「屆 → 姓名漢字」分組
$name_key = fn($name) => preg_replace('/[^\p{Han}]/u', '', str_replace(['台', '峯', '啓', '崐'], ['臺', '峰', '啟', '崑'], $name));
$cec_candidates = [];
$cand_csv = getenv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
$fh = ($cand_csv && file_exists($cand_csv)) ? fopen($cand_csv, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_ELECTED_SOURCE_CSV）：{$cand_csv}");
    exit(1);
}
$headers = fgetcsv($fh);
$headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers) || !preg_match('/^(?:ELC|BEL)-L0-(\d+)/', $row[0], $m)) {
        continue;
    }
    $r = array_combine($headers, $row);
    $cec_candidates[intval($m[1])][$name_key($r['姓名'])][] = $r;
}
fclose($fh);

// 候選人代碼 → 人物代碼
$person_code = [];
$person_jsonl = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$fh = ($person_jsonl && file_exists($person_jsonl)) ? fopen($person_jsonl, 'r') : null;
if (!$fh) {
    error_log("ERROR: 找不到來源檔案（IMPORT_CANDIDATE_PERSON_SOURCE_JSONL）：{$person_jsonl}");
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

$find_candidate = function ($term, $name, $identity) use ($cec_candidates, $name_key) {
    $list = $cec_candidates[$term][$name_key($name)] ?? [];
    $is_party_list = in_array($identity, ['全國不分區', '僑居國外國民'], true);
    $filters = [
        fn($c) => (strpos($c['候選人代碼'], ':party-') !== false) === $is_party_list,
        fn($c) => in_array($c['當選註記'], ['*', '!'], true),
        fn($c) => strpos($c['選舉代碼'], 'ELC-') === 0,
    ];
    foreach ($filters as $filter) {
        if (count($list) <= 1) {
            break;
        }
        $filtered = array_values(array_filter($list, $filter));
        if ($filtered) {
            $list = $filtered;
        }
    }
    if (!$list) {
        return null;
    }
    // 篩完還有多筆的是名冊裡的重複列（同一人同一場補選出現兩次），取第一筆
    return ($list[0] && ((strpos($list[0]['候選人代碼'], ':party-') !== false) === $is_party_list)) ? $list[0] : null;
};

$jsonl_path = getenv('IMPORT_LY_COUNCILOR_JSONL');
if (!$jsonl_path || !file_exists($jsonl_path)) {
    error_log("ERROR: 找不到來源檔案：{$jsonl_path}");
    exit(1);
}

$docs = [];
$cec_person_by_bio = [];
$fh = fopen($jsonl_path, 'r');
while (($line = fgets($fh)) !== false) {
    $d = json_decode($line);
    if (!$d || !($d->name ?? '')) {
        continue;
    }
    $term = intval($d->term);
    $name = $d->name;
    $area = $d->areaName ?? '';

    if (preg_match('/平地/u', $area)) {
        $identity = '平地原住民';
    } elseif (preg_match('/山地/u', $area)) {
        $identity = '山地原住民';
    } elseif (preg_match('/不分區/u', $area)) {
        $identity = '全國不分區';
    } elseif (preg_match('/僑/u', $area)) {
        $identity = '僑居國外國民';
    } else {
        $identity = '區域';
    }
    $region = ($identity === '區域' && preg_match('/^(\S+?[市縣])/u', $area, $m)) ? $m[1] : '';

    $onboard = $d->onboardDate ?: null;
    $status = '當選';
    if ($onboard && isset($term_start[$term]) && $onboard > $term_start[$term]) {
        $status = ($identity === '全國不分區' || $identity === '僑居國外國民') ? '遞補' : '補選當選';
    }

    $cand = $find_candidate($term, $name, $identity);
    $cec_person = $cand ? ($person_code[$cand['候選人代碼']] ?? null) : null;
    if ($cec_person && $d->bioId && !isset($cec_person_by_bio[$d->bioId])) {
        $cec_person_by_bio[$d->bioId] = $cec_person;
    }

    $doc_id = "{$cc_code}-{$term}-{$name}";
    $doc = [
        '代碼'     => $doc_id,
        '人物代碼' => $cec_person ?: ($d->bioId ? "ly-bio-{$d->bioId}" : "ly-{$term}-{$name}"),
        '參選代碼' => $cand['候選人代碼'] ?? null,
        '選舉代碼' => $cand['選舉代碼'] ?? null,
        '參選政黨' => ($cand['政黨'] ?? '') ?: null,
        '號次'     => $cand ? intval($cand['號次']) : null,
        '姓名'     => $name,
        '英文姓名' => $d->ename ?: null,
        '性別'     => $d->sex ?: null,
        '黨籍'     => $d->party ?: null,
        '黨團'     => $d->partyGroup ?: null,
        '單位'     => '立法院',
        '職稱'     => '立法委員',
        '身分別'   => $identity,
        '區域'     => $region ?: null,
        '選區別'   => $area ?: null,
        '當選狀態' => $status,
        '學歷'     => implode("\n", $d->degree ?? []) ?: null,
        '簡歷'     => implode("\n", $d->experience ?? []) ?: null,
        '委員會'   => $d->committee ?? [],
        '到職日'   => $onboard,
        '照片'     => $d->picUrl ? preg_replace('#^https?://www\.ly\.gov\.tw/+#', 'https://www.ly.gov.tw/', $d->picUrl) : null,
        '議會代碼' => $cc_code,
        '屆代碼'   => "{$cc_code}-{$term}",
        '屆次'     => $term,
        '現況'     => ($d->leaveFlag ?? '') === '是' ? '已卸任' : '在任',
        '卸任日'   => $d->leaveDate ?: null,
        '卸任原因' => ($d->leaveReason ?? '') ?: null,
    ];
    $docs[] = [$doc, $d->bioId, (bool)$cec_person];
}
fclose($fh);

// 同一個 bioId 有任何一屆對到中選會人物代碼，就讓各屆都用那個代碼（個人頁靠人物代碼串連各屆）
$count = 0;
$matched = 0;
foreach ($docs as [$doc, $bio_id, $has_cec]) {
    if (!$has_cec && $bio_id && isset($cec_person_by_bio[$bio_id])) {
        $doc['人物代碼'] = $cec_person_by_bio[$bio_id];
    }
    if ($doc['參選代碼']) {
        $matched++;
    }
    Elastic::dbBulkInsert('councilor', $doc['代碼'], $doc);
    $count++;
}
Elastic::dbBulkCommit('councilor');
error_log("Done. Imported {$count} legislators ({$matched} matched to CEC candidates).");
