<?php
/**
 * 匯入議程資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-sitting-agenda.php            # 匯入資料（upsert）
 *   php scripts/import-sitting-agenda.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議程.csv（欄位：代碼, 縣市, 場次代碼, 議程類型, 委員會或名稱, 質詢對象機關,
 * 參與議員結構, 時間資訊, 來源檔案, 來源網址, 小節清單）
 * 所有來源欄位直接沿用原始名稱匯入 ES；「參與議員結構」「小節清單」都是 CSV 儲存格內的
 * JSON 陣列字串，先 decode 再存成 nested 陣列（跟 bill 的「提案人結構」是同一種設計，
 * 比照辦理）：
 *   參與議員結構：[{姓名, 議員代碼}, ...]，「議員代碼」對應到 councilor 的「代碼」欄位
 *   小節清單：[{名稱, 提案人, 議案代碼, 起始順序}, ...]，議程底下更細的段落標記（不是
 *     每個議程都有），「起始順序」對應 speech 的「順序」欄位，消費端要抓某個小節的
 *     發言時用「順序 BETWEEN 這個小節的起始順序 AND 下一個小節的起始順序-1」查詢，
 *     不是靠獨立的 section_code；「議案代碼」能連的話對應到 bill 的「代碼」欄位
 * 衍生欄位：
 *   議會代碼：從「縣市」欄位對照（CountyCodeHelper::getMap()）
 *   屆／會期代碼：「場次代碼」有值時查既有 sitting index 取得（沿用
 *     import-transcript.php 的 derive_sitting_context() 做法）；場次代碼是空值時
 *     （實測約 11.6% 的議程沒有場次代碼）這兩個欄位就留空，不擋匯入
 * Doc ID：代碼本身（來源已保證唯一）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

// 縣市名稱 → ccapi 議會代碼（見 CountyCodeHelper 說明，桃園縣是唯一的歷史特例）
$county_to_cc_code = CountyCodeHelper::getMap();

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'       => ['type' => 'keyword'],
        '縣市'       => ['type' => 'keyword'],
        '場次代碼'   => ['type' => 'keyword'],
        '議程類型'   => ['type' => 'keyword'],
        '委員會或名稱' => ['type' => 'keyword'],
        '質詢對象機關' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '參與議員結構' => ['type' => 'nested', 'dynamic' => true],
        '小節清單'   => ['type' => 'nested', 'dynamic' => true],
        // 實測「時間資訊」不保證是日期——多數議程類型是日期（yyyy-MM-dd），但「部門質詢
        // 分組」「市政總質詢」等類型是「N位/N分鐘」的參與人數/時長摘要（例："3位/54分鐘"），
        // 用 date 型別會讓 ES 拒絕整份文件寫入（bulk API 裡單筆失敗不會拋例外，容易silent
        // 漏資料），改用 keyword
        '時間資訊'   => ['type' => 'keyword'],
        '來源檔案'   => ['type' => 'keyword'],
        '來源網址'   => ['type' => 'keyword', 'index' => false],
        // 衍生欄位
        '議會代碼'   => ['type' => 'keyword'],
        '屆'         => ['type' => 'integer'],
        '會期代碼'   => ['type' => 'keyword'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '場次代碼', '議程類型', '委員會或名稱', '質詢對象機關',
    '參與議員結構', '時間資訊', '來源檔案', '來源網址', '小節清單',
];

if ($reset) {
    try {
        Elastic::dropIndex('sitting_agenda');
        error_log("Dropped index: sitting_agenda");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('sitting_agenda', $index_mapping);
    error_log("Created index: sitting_agenda");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

// index 已存在時（非 --reset）也嘗試更新 mapping，讓新增的來源欄位（例：小節清單）
// 套用正確型別（ES 允許為既有 index 補新欄位定義，不影響既有欄位，重複執行也安全）
try {
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}sitting_agenda/_mapping", 'PUT', json_encode($index_mapping));
} catch (Exception $e) {
    error_log("Mapping update skipped: " . $e->getMessage());
}

$csv_path = getenv('IMPORT_SITTING_AGENDA_CSV') ?: (__DIR__ . '/../議程.csv');
if (!file_exists($csv_path)) {
    error_log("ERROR: 找不到議程.csv：{$csv_path}");
    exit(1);
}

$fh = fopen($csv_path, 'r');
$headers = fgetcsv($fh);

$unknown = array_diff($headers, $known_source_keys);
if ($unknown) {
    error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
    error_log("請先在 import-sitting-agenda.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
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
    $data = array_combine($headers, $row);

    $doc = $data;

    // 參與議員結構／小節清單：CSV 儲存格內的 JSON 陣列字串，decode 成陣列
    $participants = json_decode($data['參與議員結構'] ?? '', true);
    $doc['參與議員結構'] = is_array($participants) ? $participants : [];
    $sections = json_decode($data['小節清單'] ?? '', true);
    $doc['小節清單'] = is_array($sections) ? $sections : [];

    // 空白時間資訊轉 null（ES date 型別不接受空字串）
    if (isset($doc['時間資訊']) && trim($doc['時間資訊']) === '') {
        $doc['時間資訊'] = null;
    }

    // 空白文字欄位轉 null
    foreach (['場次代碼', '委員會或名稱', '質詢對象機關'] as $f) {
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
        Elastic::dbBulkInsert('sitting_agenda', $doc_id, $doc);
        $count++;
        if ($count % 1000 === 0) {
            error_log("Imported {$count} agendas...");
        }
    } catch (Exception $e) {
        error_log("ERROR on {$doc_id}: " . $e->getMessage());
        $errors++;
    }
}

fclose($fh);
Elastic::dbBulkCommit('sitting_agenda');
error_log("Done. Imported: {$count}, Errors: {$errors}, 無法對照議會代碼: {$no_cc_code}");
