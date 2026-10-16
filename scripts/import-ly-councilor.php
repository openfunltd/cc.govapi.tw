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
 *
 * 欄位對照：
 *   人物代碼  ly-bio-{bioId}（bioId 是立法院自己的跨屆委員編號；少數新就任委員還沒有
 *             bioId，改用 ly-{屆}-{姓名}，之後上游補上 bioId 會變成新代碼）
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

$jsonl_path = getenv('IMPORT_LY_COUNCILOR_JSONL');
if (!$jsonl_path || !file_exists($jsonl_path)) {
    error_log("ERROR: 找不到來源檔案：{$jsonl_path}");
    exit(1);
}

$count = 0;
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

    $doc_id = "{$cc_code}-{$term}-{$name}";
    $doc = [
        '代碼'     => $doc_id,
        '人物代碼' => $d->bioId ? "ly-bio-{$d->bioId}" : "ly-{$term}-{$name}",
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
    Elastic::dbBulkInsert('councilor', $doc_id, $doc);
    $count++;
}
fclose($fh);
Elastic::dbBulkCommit('councilor');
error_log("Done. Imported {$count} legislators.");
