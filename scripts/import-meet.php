<?php
/**
 * 匯入會議（meet）資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-meet.php            # 匯入資料（upsert）
 *   php scripts/import-meet.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：meet.csv（欄位：代碼, 縣市, 場次代碼, 委員會或主旨, 日期, 時間資訊, 地點,
 * 來源檔案, 來源網址, 有速記, 有逐字稿, 小節清單）
 * 所有來源欄位直接沿用原始名稱匯入 ES；「小節清單」是 CSV 儲存格內的 JSON 陣列字串，
 * 先 decode 再存成 nested 陣列（跟 sitting_agenda 的「小節清單」同一種設計，這個欄位
 * 就是從那邊搬過來的，只是「起始順序」現在對應 meet_transcript 的「順序」而不是舊的
 * speech）：
 *   小節清單：[{名稱, 提案人, 議案代碼, 起始順序}, ...]，會議底下更細的段落標記（目前
 *     只有臺北市委員會分組審查類型有實作偵測，不是每個meet都有），「起始順序」對應
 *     meet_transcript 的「順序」欄位，消費端用「順序 BETWEEN 這個小節的起始順序 AND
 *     下一個小節的起始順序-1」查詢
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆／會期代碼：「場次代碼」有值時查既有 sitting index 取得（沿用
 *     import-transcript.php 的 derive_sitting_context() 做法）；場次代碼是空值時
 *     這兩個欄位就留空，不擋匯入
 * Doc ID：代碼本身（來源已保證唯一）
 *
 * 這個 type 是 sitting_agenda／speech／舊 transcript pipeline 整個 retire 之後的
 * 取代方案（見 pure-tw.gov-議會-議事日程/PLAN.md 步驟32~43 的設計討論），一個 meet
 * 底下再拆 meet_note（速記摘要）／meet_transcript（逐字稿），視內容夠不夠格分類。
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$county_to_cc_code = CountyCodeHelper::getMap();

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'       => ['type' => 'keyword'],
        '縣市'       => ['type' => 'keyword'],
        '場次代碼'   => ['type' => 'keyword'],
        '委員會或主旨' => ['type' => 'keyword'],
        // 「日期」跟sitting_agenda的「時間資訊」同樣的教訓：這個欄位理論上都是
        // yyyy-MM-dd，但保留keyword不用date型別，避免少數邊界格式讓ES拒絕整份文件
        '日期'       => ['type' => 'keyword'],
        '時間資訊'   => ['type' => 'keyword'],
        '地點'       => ['type' => 'keyword'],
        '來源檔案'   => ['type' => 'keyword'],
        '來源網址'   => ['type' => 'keyword', 'index' => false],
        '有速記'     => ['type' => 'boolean'],
        '有逐字稿'   => ['type' => 'boolean'],
        '小節清單'   => ['type' => 'nested', 'dynamic' => true],
        // 衍生欄位
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
        '會期代碼'   => ['type' => 'keyword'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '委員會或主旨', '日期', '時間資訊', '地點',
    '來源檔案', '來源網址', '有速記', '有逐字稿', '小節清單',
];

if ($reset) {
    try {
        Elastic::dropIndex('meet');
        error_log("Dropped index: meet");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('meet', $index_mapping);
    error_log("Created index: meet");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}meet/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_MEET_CSV') ?: (__DIR__ . '/../meet.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到meet.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-meet.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
    exit(1);
}

/**
 * 查詢既有 sitting index 取得 屆/會期代碼（跟 import-transcript.php 的
 * derive_sitting_context() 同一種做法，查不到就整個留空）
 */
function derive_sitting_context($code)
{
    if (!$code) {
        return ['屆' => null, '會期代碼' => null];
    }
    try {
        $r = Elastic::dbQuery('/{prefix}sitting/_doc/' . rawurlencode($code), 'GET');
        if ($r->found ?? false) {
            $s = $r->_source;
            return [
                '屆'       => $s->{'屆'} ?? null,
                '會期代碼' => $s->{'會期代碼'} ?? null,
            ];
        }
    } catch (Exception $e) {
        // 找不到就留空
    }
    return ['屆' => null, '會期代碼' => null];
}

$count = 0;
$errors = 0;
$no_cc_code = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($headers)) {
        error_log("跳過欄位數異常列: " . implode(',', $row));
        continue;
    }
    $data = array_combine($headers, $row);

    $doc = $data;

    $sections = json_decode($data['小節清單'] ?? '', true);
    $doc['小節清單'] = is_array($sections) ? $sections : [];

    $doc['有速記'] = ($data['有速記'] ?? '0') === '1';
    $doc['有逐字稿'] = ($data['有逐字稿'] ?? '0') === '1';

    if (isset($doc['日期']) && trim($doc['日期']) === '') {
        $doc['日期'] = null;
    }
    if (isset($doc['時間資訊']) && trim($doc['時間資訊']) === '') {
        $doc['時間資訊'] = null;
    }
    foreach (['場次代碼', '委員會或主旨', '地點'] as $f) {
        if (isset($doc[$f]) && trim($doc[$f]) === '') {
            $doc[$f] = null;
        }
    }

    $doc['議會代碼'] = $county_to_cc_code[$data['縣市']] ?? null;
    if (!$doc['議會代碼']) {
        $no_cc_code++;
    }

    $context = derive_sitting_context($doc['場次代碼']);
    $doc['屆'] = $context['屆'];
    $doc['會期代碼'] = $context['會期代碼'];

    $doc_id = $data['代碼'];

    try {
        Elastic::dbBulkInsert('meet', $doc_id, $doc);
        $count++;
        if ($count % 1000 === 0) {
            error_log("Imported {$count} meets...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('meet');
error_log("Done. Imported: {$count}, Errors: {$errors}, 無法對照議會代碼: {$no_cc_code}");
