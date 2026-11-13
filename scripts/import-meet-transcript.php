<?php
/**
 * 匯入會議逐字稿（meet_transcript）資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-meet-transcript.php            # 匯入資料（只送新增／改過的，來源沒有的刪掉，見 ImportDiff）
 *   php scripts/import-meet-transcript.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：meet_transcripts.csv（單一檔案，約523萬筆，欄位：代碼, 縣市, 場次代碼,
 * 會議代碼, 日期, 順序, 原始標記, 姓名, 職稱, 身分類別, 對應代碼, 對應代碼類型,
 * 對應單位全名, 發言內容, 來源頁碼, 印刷頁碼, 來源網址, 來源檔案, 屆）
 * 來源網址／來源檔案：絕大多數縣市一個meet只對應一份實體檔案、這兩欄沒有值，
 *   畫面端退回用meet自己的同名欄位即可；只有臺北市這種一個meet拆成好幾份
 *   實體PDF（檔名「-1」「-2」……）的情形才會提供，且會跟meet層級的值不同
 *   ——「來源頁碼」是各自檔案內部重新起算的頁碼，用meet的第一份檔案會連到
 *   錯的檔案或對的檔案錯的頁
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆：來源CSV本身的「屆」欄位有值就直接採用（parser從檔名/場次標題等
 *     解析出來的，不依賴場次代碼能不能查到），沒有值（空字串，絕大多數
 *     縣市目前都是這樣）才退回原本的做法：查既有sitting index取得（
 *     SittingContext 開頭一次讀進整個 sitting index）。這是PM
 *     測試回饋：臺北市2009~2010年「委員會分組審查」子格式track.csv比對
 *     大量失敗（場次代碼為空）導致約14.7萬筆（8%）查無屆別，但屆別其實
 *     從檔名就解析得出來，只是之前沒有管道傳下去，2026-10修正。
 *   會期代碼：維持原本做法，查既有sitting index取得（屆別有獨立管道後，
 *     會期代碼目前還是只能透過場次代碼查，場次代碼空白的record會期代碼
 *     依然是null，這不在這次修正範圍內）
 * Doc ID：代碼本身（例：tpe-8320eaa898-0）
 *
 * 效能設計：這個來源是單一大檔案（約568萬筆），每次都整份重讀（約1分鐘），但用
 * ImportDiff 逐筆比對上次寫進 ES 的內容，只送新增／改過的文件，來源已經沒有的
 * 代碼從 ES 刪掉。第一次執行（或 --reset）沒有比對狀態，會整份寫入（約60-80分鐘）。
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        '代碼'         => ['type' => 'keyword'],
        '縣市'         => ['type' => 'keyword'],
        '場次代碼'     => ['type' => 'keyword'],
        '會議代碼'     => ['type' => 'keyword'],
        '日期'         => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '順序'         => ['type' => 'integer'],
        '原始標記'     => ['type' => 'keyword'],
        '姓名'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '職稱'         => ['type' => 'keyword'],
        '身分類別'     => ['type' => 'keyword'],
        '對應代碼'     => ['type' => 'keyword'],
        '對應代碼類型' => ['type' => 'keyword'],
        '對應單位全名' => ['type' => 'keyword'],
        '發言內容'     => ['type' => 'text'],
        // 來源頁碼／印刷頁碼實測有 "786-787" 這種範圍格式，比照speech的教訓用
        // keyword不要用integer（否則ES 400拒絕整份文件）
        '來源頁碼'     => ['type' => 'keyword'],
        '印刷頁碼'     => ['type' => 'keyword'],
        // 絕大多數縣市一個meet只對應一份實體檔案，這兩欄跟meet自己的
        // 「來源檔案」「來源網址」會是同一個值；只有臺北市這種一個meet
        // 拆成好幾份實體PDF的情形才會不同，沒有值（絕大多數縣市）時畫面
        // 退回用meet自己的欄位，型別比照import-meet.php同名欄位
        '來源檔案'     => ['type' => 'keyword'],
        '來源網址'     => ['type' => 'keyword', 'index' => false],
        // 衍生欄位
        '議會代碼'     => ['type' => 'keyword'],
        '屆'           => ['type' => 'integer'],
        '會期代碼'     => ['type' => 'keyword'],
        // 從「日期」取前4碼，給搜尋頁「逐字稿」分頁的年份篩選用（取代舊
        // transcript type的「年」欄位）
        '年'           => ['type' => 'integer'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '會議代碼', '日期', '順序', '原始標記', '姓名', '職稱',
    '身分類別', '對應代碼', '對應代碼類型', '對應單位全名', '發言內容', '來源頁碼', '印刷頁碼',
    '來源網址', '來源檔案', '屆',
];

if ($reset) {
    try {
        Elastic::dropIndex('meet_transcript');
        error_log("Dropped index: meet_transcript");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('meet_transcript', $index_mapping);
    error_log("Created index: meet_transcript");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}meet_transcript/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_MEET_TRANSCRIPT_CSV') ?: (__DIR__ . '/../meet_transcripts.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到meet_transcripts.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-meet-transcript.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}


$county_to_cc_code = CountyCodeHelper::getMap();

$diff = new ImportDiff('meet_transcript', $reset);
$count = 0;
$errors = 0;
$malformed = 0;

while (true) {
    $pos = ftell($fh);
    if (($row = fgetcsv($fh)) === false) {
        break;
    }
    if (count($row) !== count($headers)) {
        // 發言內容以反斜線結尾時，預設跳脫字元會把結尾的 \" 當成跳脫的引號，連下一列一起吃掉；
        // 退回這一列開頭，不用跳脫字元重讀
        fseek($fh, $pos);
        $row = fgetcsv($fh, 0, ',', '"', '');
    }
    if (count($row) !== count($headers)) {
        $malformed++;
        continue;
    }
    $data = array_combine($headers, $row);

    foreach ($data as $k => $v) {
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            $data[$k] = mb_scrub($v, 'UTF-8');
        }
    }

    $doc = $data;

    if (isset($doc['日期']) && trim($doc['日期']) === '') {
        $doc['日期'] = null;
    }
    foreach (['場次代碼', '會議代碼', '姓名', '職稱', '對應代碼', '對應代碼類型', '對應單位全名', '來源頁碼', '印刷頁碼', '來源網址', '來源檔案', '屆'] as $f) {
        if (isset($doc[$f]) && trim($doc[$f]) === '') {
            $doc[$f] = null;
        }
    }
    if (isset($doc['順序']) && $doc['順序'] !== '') {
        $doc['順序'] = (int)$doc['順序'];
    }

    $doc['議會代碼'] = $county_to_cc_code[$data['縣市']] ?? null;

    $context = SittingContext::get($doc['場次代碼']);
    // 來源CSV本身的「屆」有值（目前只有臺北市會提供）就直接採用，不依賴
    // 場次代碼能不能查到sitting——否則退回原本查sitting index的做法
    if ($doc['屆'] === null) {
        $doc['屆'] = $context['屆'];
    } else {
        $doc['屆'] = (int)$doc['屆'];
    }
    $doc['會期代碼'] = $context['會期代碼'];
    $doc['年'] = $doc['日期'] ? (int)substr($doc['日期'], 0, 4) : null;

    $doc_id = $data['代碼'];
    if ($diff->unchanged($doc_id, $doc)) {
        continue;
    }

    try {
        Elastic::dbBulkInsert('meet_transcript', $doc_id, $doc, true);
        $count++;
        if ($count % 10000 === 0) {
            error_log("Imported {$count} meet_transcripts...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('meet_transcript');
$diff->finish();
error_log("Done. Imported: {$count}, Errors: {$errors}, 欄位數異常跳過: {$malformed}");
