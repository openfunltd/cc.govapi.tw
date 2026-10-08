<?php
/**
 * 匯入議員資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-councilor.php            # 匯入資料（upsert）
 *   php scripts/import-councilor.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議員.jsonl（每行一筆 JSON）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 衍生欄位：屆次（整數，從屆代碼解析，供排序用）
 * Doc ID：{議會代碼}-{屆次}-{姓名}
 *
 * 「黨籍」跟「參選政黨」是兩種不同口徑（見knowledge.md議員章節的說明）：
 * 「黨籍」是內政部名冊抓取當下的目前黨籍，「參選政黨」是當選該屆時登記
 * 參選當下的政黨，任期中轉黨的人這兩個欄位會不一致，這是正常現象不是bug
 */

include(__DIR__ . '/../init.inc.php');

// 跟import-candidate.php的bulletin_image_url()是同一套轉換規則（見該檔案
// 的說明），這裡複製一份而不是抽成共用函式庫，維持跟專案現有慣例一致
// （每支import腳本自己獨立可執行，不互相include）
function bulletin_image_url($path)
{
    if (!$path) return null;
    $path = preg_replace('#^files/#', '', $path);
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    return 'https://lydata.ronny-s3.click/bulletin/' . $encoded;
}

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'     => ['type' => 'keyword'],
        '人物代碼' => ['type' => 'keyword'],
        '參選代碼' => ['type' => 'keyword'],
        '選舉代碼' => ['type' => 'keyword'],
        '年份'     => ['type' => 'keyword'],
        '姓名'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '區域'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '單位'     => ['type' => 'keyword'],
        '職稱'     => ['type' => 'keyword'],
        '黨籍'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '選舉屆次' => ['type' => 'text'],
        '學歷'     => ['type' => 'text'],
        '簡歷'     => ['type' => 'text'],
        '辦公地址' => ['type' => 'keyword', 'index' => false],
        '聯絡電話' => ['type' => 'keyword', 'index' => false],
        '電子信箱' => ['type' => 'keyword', 'index' => false],
        '身分別'   => ['type' => 'keyword'],
        '照片'     => ['type' => 'keyword', 'index' => false],
        '議會代碼' => ['type' => 'keyword'],
        '屆代碼'   => ['type' => 'keyword'],
        '性別'     => ['type' => 'keyword'],
        '出生日期' => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '出生地'   => ['type' => 'keyword'],
        '參選政黨' => ['type' => 'keyword'],
        '參選學歷' => ['type' => 'keyword'],
        '選舉區號' => ['type' => 'keyword'],
        '選區別'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '當選狀態' => ['type' => 'keyword'],
        // 2026-10-08新增：「歷屆議員」補充來源/卸任fallback原本schema很精簡
        // 查不到這個欄位，現在open-forest-scripts那邊補上候選人代碼反查CEC
        // 選舉資料庫/選舉公報後，這幾筆record也會帶這個欄位（舊的MOI主來源
        // record本來就沒有這欄，繼續維持沒有不影響既有資料）
        '得票數'   => ['type' => 'integer'],
        // 卸任追蹤機制（PLAN-20261005-reporter.md B1項目）：open-forest-scripts
        // 的 mixed-tw.gov-議會-議員資料/crawl.php 反查 CEC 當選名單跟官網「歷屆
        // 議員」備註欄位得出，查無異動記錄的人這幾欄一律是空字串（因為上游
        // 用空字串/預設「在任」表示沒有已知卸任記錄，不是故意不給值）
        '現況'       => ['type' => 'keyword'],
        '卸任日'     => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '卸任原因'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '繼任人姓名' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        // 中選會罷免補選公告原文連結，個人頁/列表頁的卸任/遞補資訊區塊附上這個
        // 連結增加公信力，不是只有信心度/查證方式這種內部文字說明
        '卸任來源網址'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        // 反過來：當選狀態是遞補/補選當選時，被我取代的原任者是誰/何時卸任/
        // 為什麼卸任，給councilors列表頁的「遞補/補選當選」badge加hover提示用
        '原任者姓名'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '原任者卸任日'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
        '原任者卸任原因' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '原任者卸任來源網址' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        // 以下兩個欄位（補充來源/歷屆議員record才有）過去一直透過ES動態mapping
        // 隱性生效，沒有正式宣告——第一筆record（通常來自MOI主來源）沒有這兩個
        // 欄位，匯入腳本的「第一筆檢查未知欄位」機制因此沒抓到，見CLAUDE.md
        // 已知的「只檢查第一筆」陷阱。這裡順手補上正式宣告，不是新增欄位
        '來源網址' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '來源備註' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        // 衍生欄位
        '屆次'     => ['type' => 'integer'],
    ],
];

// 「身份別」（份）是來源資料新增「選區別」欄位時順手打錯字造成的重複欄位，
// 內容跟既有的「身分別」（分）完全一樣（比對全部 6,514 筆資料零差異），
// 不匯入，只是列進已知欄位清單讓匯入腳本不會因為看到未預期欄位而中止
$ignored_source_keys = ['身份別'];

// 已知的來源欄位（不含衍生欄位 屆次）
$known_source_keys = array_merge(
    array_diff(array_keys($index_mapping['properties']), ['屆次']),
    $ignored_source_keys
);

if ($reset) {
    try {
        Elastic::dropIndex('councilor');
        error_log("Dropped index: councilor");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
    try {
        Elastic::createIndex('councilor', $index_mapping);
        error_log("Created index: councilor");
    } catch (Exception $e) {
        error_log("Create index failed: " . $e->getMessage());
        exit(1);
    }
}

// index 已存在時（非 --reset）也嘗試更新 mapping，讓新增的來源欄位套用正確型別
// （ES 允許為既有 index 補新欄位定義，不影響既有欄位，重複執行也安全）
try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}councilor/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$jsonl_path = getenv('IMPORT_COUNCILOR_JSONL') ?: (__DIR__ . '/../議員.jsonl');
$fh = fopen($jsonl_path, 'r');
if (!$fh) {
    error_log("Cannot open {$jsonl_path}");
    exit(1);
}

// 跳過 UTF-8 BOM
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$count = 0;
$skip  = 0;
$headers_checked = false;

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $record = json_decode($line, true);
    if (!$record) {
        error_log("Invalid JSON: {$line}");
        $skip++;
        continue;
    }

    // 第一筆資料時檢查是否有未知欄位
    if (!$headers_checked) {
        $headers_checked = true;
        $unknown = array_diff(array_keys($record), $known_source_keys);
        if ($unknown) {
            error_log("ERROR: 來源檔案有未定義的欄位，請先在 index_mapping 補上對應設定再匯入：" . implode(', ', $unknown));
            fclose($fh);
            exit(1);
        }
    }

    $cc_code   = $record['議會代碼'] ?? '';
    $term_code = $record['屆代碼']   ?? '';
    $name      = $record['姓名']     ?? '';

    if ($cc_code === '' || $term_code === '' || $name === '') {
        $skip++;
        continue;
    }

    // 從屆代碼取 term 整數（最後一個 '-' 後的數字）
    $term_int = intval(substr($term_code, strrpos($term_code, '-') + 1));

    $doc_id = "{$cc_code}-{$term_int}-{$name}";
    $doc = ['屆次' => $term_int];

    foreach ($record as $key => $val) {
        if (in_array($key, $ignored_source_keys, true)) continue;
        if ($val === '' || $val === null) continue;
        if ($key === '出生日期') {
            // 較舊的回溯資料常見「年份已知、月日不明」用 00 佔位（例：1939-00-00），
            // ES date 型別不接受，一律當作未知日期跳過（保留年份已知這件事沒有意義，直接略過整欄）
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $val)) {
                $doc[$key] = $val;
            }
            continue;
        }
        if ($key === '照片' && strpos($val, 'files/') === 0) {
            // 來源是選舉公報的相片（block本機相對路徑，例：files/image/...），
            // 跟MOI來源直接給完整https網址不同格式，轉成跟候選人照片同一套
            // 公開網址（import-candidate.php的bulletin_image_url()）
            $val = bulletin_image_url($val);
        }
        $doc[$key] = $val;
    }

    Elastic::dbBulkInsert('councilor', $doc_id, $doc);
    $count++;
}
fclose($fh);

Elastic::dbBulkCommit('councilor');
error_log("Done. Imported {$count} councilors, skipped {$skip}.");
