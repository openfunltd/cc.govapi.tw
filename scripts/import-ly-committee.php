<?php
/**
 * 匯入立法院（議會代碼 ly）委員會資料到 committee index
 *
 * 用法：
 *   php scripts/import-ly-committee.php
 *
 * 寫進既有的 committee index（不建新 index、不支援 --reset，要重建請用
 * import-committee.php --reset 後再跑這支）
 *
 * 來源：IMPORT_LY_COMMITTEE_CSV 立法院開放資料「委員會」（欄位：comtCd, comtName, comtDesp, comtType）
 *
 * 欄位對照：
 *   代碼  ly-{comtCd}（例：ly-15）
 *   名稱／職掌  comtName／comtDesp（職掌去掉 HTML 空白實體與多餘空白）
 *   類別  comtType：1 常設、2 特種、3 國會改革前舊委員會（照來源的分類，來源沒有廢止日期）
 * 委員會成員不在這裡：立法委員資料本身逐會期記錄所屬委員會（councilor 的「委員會」欄位）
 */

include(__DIR__ . '/../init.inc.php');

$cc_code = 'ly';
$type_names = [1 => '常設', 2 => '特種', 3 => '國會改革前舊委員會'];

$csv_path = getenv('IMPORT_LY_COMMITTEE_CSV');
if (!$csv_path || !file_exists($csv_path)) {
    error_log("ERROR: 找不到來源檔案（IMPORT_LY_COMMITTEE_CSV）：{$csv_path}");
    exit(1);
}
$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);
$headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

$count = 0;
while (($row = fgetcsv($fh)) !== false) {
    $row = array_slice($row, 0, count($headers));
    if (count($row) !== count($headers)) {
        continue;
    }
    $r = array_combine($headers, $row);
    if (!($r['comtCd'] ?? '') || !($r['comtName'] ?? '')) {
        continue;
    }
    $desc = html_entity_decode($r['comtDesp'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $desc = trim(preg_replace('/[ \x{00A0}\x{3000}]+/u', ' ', $desc));

    $doc_id = "{$cc_code}-{$r['comtCd']}";
    Elastic::dbBulkInsert('committee', $doc_id, [
        '代碼'     => $doc_id,
        '議會代碼' => $cc_code,
        '名稱'     => $r['comtName'],
        '類別'     => $type_names[intval($r['comtType'])] ?? null,
        '職掌'     => $desc ?: null,
    ]);
    $count++;
}
fclose($fh);
Elastic::dbBulkCommit('committee');
error_log("Done. Imported {$count} committees.");
