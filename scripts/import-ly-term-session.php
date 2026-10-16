<?php
/**
 * 匯入立法院（議會代碼 ly）的議會、屆期、會期資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-ly-term-session.php
 *
 * 寫進既有的 council／term／session index（不建新 index、不支援 --reset，要重建請用
 * import-council.php／import-term.php／import-session.php 的 --reset 後再跑這支）
 *
 * 來源：
 *   IMPORT_LY_TERM_CSV    屆.csv（欄位：屆, 就職日, 任期屆滿日）
 *   IMPORT_LY_SESSION_CSV 會期.csv（欄位：屆, 會期, 臨時會次, 名稱, 開始日期, 結束日期, 來源網址）
 *
 * council：只有立法院一筆，固定內容直接寫在這裡
 * term：代碼 ly-{屆}，還沒就職的屆次不匯入；匯入後更新 council 的最新屆期代碼
 * session：立法院的臨時會都掛在某個會期底下，會期代碼也帶上會期：
 *   ly-{屆}-r{會期}          第N屆第M會期（會期類別=定期會，次=M）
 *   ly-{屆}-r{會期}e{次}     第N屆第M會期第K次臨時會（會期類別=臨時會，次=K）
 *   ly-{屆}-e{次}            第N屆第K次臨時會（只有第1屆有，沒有掛在會期底下）
 */

include(__DIR__ . '/../init.inc.php');

$cc_code = 'ly';
$cc_name = '立法院';
$today = date('Y-m-d');

$read_csv = function ($path) {
    if (!$path || !file_exists($path)) {
        error_log("ERROR: 找不到來源檔案：{$path}");
        exit(1);
    }
    $fh = fopen($path, 'r');
    $headers = fgetcsv($fh);
    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($headers)) {
            continue;
        }
        $rows[] = array_combine($headers, $row);
    }
    fclose($fh);
    return $rows;
};

// council
Elastic::dbBulkInsert('council', $cc_code, [
    '代碼'     => $cc_code,
    '議會名稱' => $cc_name,
    '議會種類' => '立法院',
    '生效日期' => '1948-05-08',
    '維基條目' => 'https://zh.wikipedia.org/wiki/立法院',
    '現存'     => true,
]);
Elastic::dbBulkCommit('council');

// term
$latest_term_id = null;
$count = 0;
foreach ($read_csv(getenv('IMPORT_LY_TERM_CSV')) as $record) {
    if ($record['就職日'] > $today) {
        continue;
    }
    $term = intval($record['屆']);
    $doc_id = "{$cc_code}-{$term}";
    Elastic::dbBulkInsert('term', $doc_id, [
        '代碼'       => $doc_id,
        '議會代碼'   => $cc_code,
        '屆次'       => $term,
        '就職日'     => $record['就職日'],
        '任期屆滿日' => $record['任期屆滿日'],
        '現任'       => ($record['就職日'] <= $today && $today <= $record['任期屆滿日']),
    ]);
    $latest_term_id = $doc_id;
    $count++;
}
Elastic::dbBulkCommit('term');
error_log("Done. Imported {$count} terms.");

if ($latest_term_id) {
    Elastic::dbQuery(
        '/{prefix}council/_update/' . rawurlencode($cc_code),
        'POST',
        json_encode(['doc' => ['最新屆期代碼' => $latest_term_id]])
    );
}

// session
$count = 0;
foreach ($read_csv(getenv('IMPORT_LY_SESSION_CSV')) as $record) {
    $term = intval($record['屆']);
    $period = $record['會期'];
    $extra = $record['臨時會次'];
    if ($extra === '') {
        $code = "r{$period}";
        $type = '定期會';
        $no = intval($period);
    } else {
        $code = ($period === '' ? '' : "r{$period}") . "e{$extra}";
        $type = '臨時會';
        $no = intval($extra);
    }
    $doc_id = "{$cc_code}-{$term}-{$code}";
    Elastic::dbBulkInsert('session', $doc_id, [
        '代碼'     => $doc_id,
        '議會名稱' => $cc_name,
        '會期名稱' => $record['名稱'],
        '屆'       => $term,
        '會期類別' => $type,
        '次'       => $no,
        '開始日期' => $record['開始日期'] ?: null,
        '結束日期' => $record['結束日期'] ?: null,
        '來源網址' => $record['來源網址'],
        '議會代碼' => $cc_code,
    ]);
    $count++;
}
Elastic::dbBulkCommit('session');
error_log("Done. Imported {$count} sessions.");
