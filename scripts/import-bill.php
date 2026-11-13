<?php
/**
 * 匯入議案資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-bill.php            # 匯入資料（只送新增／改過的，來源沒有的刪掉，見 ImportDiff）
 *   php scripts/import-bill.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：議案.jsonl（每行一筆 JSON，欄位：代碼/縣市/類別/案號/提案單位/提案人/
 * 連署人/案由/說明/辦法/審查意見/議決/來源檔案/來源頁碼/來源網址/備註/會議代碼/
 * 提案人結構/連署人結構）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 * 「來源網址」是議事錄原始 PDF/Word 在該議會官網的直連下載網址（「官方來源」），
 * 跟「來源檔案」「來源頁碼」（檔案內來源）合起來才是完整的溯源資訊；實測部分議會
 * 網站有 Cloudflare 擋自動化請求（curl 回 403），網址本身格式正常，用瀏覽器應該
 * 沒問題，只是沒辦法用程式自動驗證每一筆都能下載
 * 「備註」欄位各縣市意義不同（不是同一個概念）：屏東縣是議案類型（三讀／一般／
 * 臨時動議案）、連江縣是提案主體（縣府／議員／人民陳情案）、臺北市是發言議員
 * 名單、彰化縣是所屬會期、新北市是狀態註記，沿用既有「同名欄位不同縣市不同
 * 語意」的慣例，不強行統一，照原樣存放
 * 「會議代碼」是對應到 session index 的真正會期代碼（目前只有部分議會有值，
 * 尚未涵蓋全部）；「提案人結構」「連署人結構」是 [{姓名, 議員代碼}, ...] 陣列，
 * 「議員代碼」對應到 councilor 的「代碼」欄位（不是「人物代碼」，兩者格式不同，
 * 要連到議員個人頁需要先查一次 councilor 換成「人物代碼」）
 * 衍生欄位：
 *   議會代碼：從「代碼」欄位第一段解析（例：yun-44d4ef6b-民甲200 → yun）
 *   屆：從「來源檔案」檔名解析「第N屆」（來源目前沒有屆/會期/場次的正式關聯
 *       欄位，只能從檔名反推，且無法精確對應到會期或場次——一份議事錄常常
 *       橫跨一個定期會加多個臨時會，解析不到時這個欄位就沒有值）
 * Doc ID：代碼本身（來源已保證跨議會唯一）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '代碼'     => ['type' => 'keyword'],
        '縣市'     => ['type' => 'keyword'],
        '類別'     => ['type' => 'keyword'],
        '案號'     => ['type' => 'keyword'],
        '提案單位' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        // ignore_above：少數 OCR 錯置的議案把整段會議紀錄塞進提案人（超過 keyword 長度上限，整筆匯入失敗）
        '提案人'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 2000]]],
        '提案人姓名' => ['type' => 'keyword', 'ignore_above' => 2000], // 衍生：「提案人」拆成一人一個值，給篩選／統計用
        '連署人'   => ['type' => 'text'],
        '案由'     => ['type' => 'text'],
        '說明'     => ['type' => 'text'],
        '辦法'     => ['type' => 'text'],
        '審查意見' => ['type' => 'text'],
        '議決'     => ['type' => 'text'],
        '來源檔案' => ['type' => 'keyword'],
        '來源頁碼' => ['type' => 'keyword'],
        '來源網址' => ['type' => 'keyword', 'index' => false],
        '備註'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '會議代碼' => ['type' => 'keyword'],
        '提案人結構' => ['type' => 'nested', 'dynamic' => true],
        '連署人結構' => ['type' => 'nested', 'dynamic' => true],
        // 衍生欄位
        '議會代碼' => ['type' => 'keyword'],
        '屆'       => ['type' => 'integer'],
    ],
];

$known_source_keys = [
    '代碼', '縣市', '類別', '案號', '提案單位', '提案人', '連署人',
    '案由', '說明', '辦法', '審查意見', '議決', '來源檔案', '來源頁碼', '來源網址', '備註',
    '屆', '會議代碼', '提案人結構', '連署人結構',
];

if ($reset) {
    try {
        Elastic::dropIndex('bill');
        error_log("Dropped index: bill");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('bill', $index_mapping);
    error_log("Created index: bill");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
    // index 已存在時也更新 mapping，讓新增的衍生欄位套用正確型別
    $prefix = getenv('ELASTIC_PREFIX');
    Elastic::dbQuery("/{$prefix}bill/_mapping", 'PUT', json_encode($index_mapping));
}

$jsonl_path = getenv('IMPORT_BILL_JSONL') ?: (__DIR__ . '/../議案.jsonl');
if (!file_exists($jsonl_path)) {
    error_log("ERROR: 找不到議案.jsonl：{$jsonl_path}");
    exit(1);
}

$fh = fopen($jsonl_path, 'r');
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

/**
 * 「提案人」是多人串成的一個字串，各議會分隔方式不同（逗號、頓號、分號、
 * 換行、兩個以上半形空白、「及」）。單一空白只在每一段都是 2~4 個漢字時才拆
 * （「江勝雄 何欣純」要拆，「縣長 張花冠」「李 中」「Ingay Tali穎艾達利」不拆）。
 * 每個名字再去掉頭銜（縣長／議長／副議長／議員，含「黃議員大祐」這種夾在姓名
 * 中間的寫法）和括號註記（「林武忠(106.2.16已解職)」）。
 */
function clean_proposer_name($name)
{
    $name = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $name);
    $name = preg_replace('/\s*[(（〈][^)）〉]*[)）〉]\s*/u', '', $name);
    $name = preg_replace('/^提案議員[:：]\s*/u', '', $name);
    $name = preg_replace('/^(?:縣長|市長|副議長|議長)[\s\x{3000}]*(?=\S{2,})/u', '', $name);
    $name = preg_replace('/^(\p{Han})(?:副議長|議長|議員)(\p{Han}{1,3})$/u', '$1$2', $name);
    $name = preg_replace('/^(.{2,}?)[\s\x{3000}]*(?:副議長|議長|議員|縣長|市長)$/u', '$1', $name);
    // 原住民族名字羅馬拼音和漢名之間有沒有空白，各份文件不一致
    return preg_replace('/([A-Za-z])[\s\x{3000}]+(\p{Han})/u', '$1$2', $name);
}

function split_proposers($s)
{
    $names = [];
    foreach (preg_split('/[,，、;；\r\n\t]+| {2,}|[\s\x{3000}]+及[\s\x{3000}]+/u', $s) as $part) {
        $part = clean_proposer_name($part);
        $pieces = preg_split('/[ \x{3000}]+/u', $part);
        if (count($pieces) > 1 && count(array_filter($pieces, fn($p) => preg_match('/^\p{Han}{2,4}$/u', $p))) === count($pieces)) {
            $pieces = array_map('clean_proposer_name', $pieces);
        } else {
            $pieces = [$part];
        }
        foreach ($pieces as $name) {
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    return $names;
}

/**
 * 議員代碼 → 議員資料上的姓名。提案人有對到議員代碼時用名冊上的寫法（來源文件常有錯字、
 * 黏字切開時也只有名冊寫法），再經過 CCAPI_Type_Bill::proposerKey() 統一
 */
function councilor_name_by_code($code)
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $path = getenv('IMPORT_COUNCILOR_JSONL') ?: (__DIR__ . '/../議員.jsonl');
        if ($fp = @fopen($path, 'r')) {
            while (($line = fgets($fp)) !== false) {
                $d = json_decode($line);
                if (($d->代碼 ?? '') !== '' && ($d->姓名 ?? '') !== '') {
                    $names[$d->代碼] = $d->姓名;
                }
            }
            fclose($fp);
        }
    }
    return $names[$code] ?? null;
}

/**
 * 來源已經拆好的「提案人結構」優先（有用該屆議員名冊切開黏在一起的姓名、去掉頭銜），
 * 沒有結構化資料的才自己拆「提案人」字串
 */
function proposer_names($doc)
{
    $names = [];
    foreach (($doc['提案人結構'] ?? []) as $p) {
        $name = CCAPI_Type_Bill::proposerKey(councilor_name_by_code($p['議員代碼'] ?? '') ?? clean_proposer_name((string) ($p['姓名'] ?? '')));
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    if (!$names && is_string($doc['提案人'] ?? null)) {
        $names = array_values(array_unique(array_map(['CCAPI_Type_Bill', 'proposerKey'], split_proposers($doc['提案人']))));
    }
    return $names;
}

/**
 * 把「一」～「九十九」這種中文數字轉成整數，解析不出來回傳 null。
 * 屆次來源檔名有些縣市（連江縣、屏東縣）用中文數字寫（例：第十九屆、第六屆），
 * 不是阿拉伯數字，只比對 \d 會抓不到。
 */
function chinese_num_to_int($s)
{
    $digits = ['〇' => 0, '零' => 0, '一' => 1, '二' => 2, '兩' => 2, '三' => 3, '四' => 4,
               '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
    if (isset($digits[$s])) return $digits[$s];
    if ($s === '十') return 10;
    if (preg_match('/^十([一二兩三四五六七八九])$/u', $s, $m)) return 10 + $digits[$m[1]];
    if (preg_match('/^([一二兩三四五六七八九])十$/u', $s, $m)) return $digits[$m[1]] * 10;
    if (preg_match('/^([一二兩三四五六七八九])十([一二兩三四五六七八九])$/u', $s, $m)) {
        return $digits[$m[1]] * 10 + $digits[$m[2]];
    }
    return null;
}

/**
 * 從檔名解析「第N屆」，先試阿拉伯數字（例：20屆第13.14次臨時會議事錄），
 * 抓不到再試中文數字（例：屏東縣議會第十九屆第二次定期會、連江縣議會第六屆
 * 第八次定期大會），都抓不到就回傳 null（不寫入屆欄位）。
 */
function extract_term_from_filename($filename)
{
    if (preg_match('/第?(\d+)屆/u', $filename, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/第([〇零一二兩三四五六七八九十]+)屆/u', $filename, $m)) {
        return chinese_num_to_int($m[1]);
    }
    return null;
}

$count = 0;
$skip = 0;
$headers_checked = false;
// 「代碼」來源上不保證跨紀錄唯一：實測新北市有 36 組「同一份文件裡，
// 同一類別+同一案號卻是完全不同的議案」（例：同一份法規類議事錄裡，
// 案號「一」出現 3 次，各自是不同的自治條例草案）。若直接拿「代碼」當
// ES doc ID 會被 upsert 覆蓋掉，靜默遺失資料，這裡改成偵測到重複時
// 對 doc ID 加上序號後綴，確保每筆來源記錄都保留下來
$seen_codes = [];
$diff = new ImportDiff('bill', $reset);

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

    if (!$headers_checked) {
        $headers_checked = true;
        $unknown = array_diff(array_keys($record), $known_source_keys);
        if ($unknown) {
            error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
            error_log("請先在 import-bill.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
            fclose($fh);
            exit(1);
        }
    }

    $code = $record['代碼'] ?? '';
    if ($code === '') {
        $skip++;
        continue;
    }

    // 從「代碼」第一段解析議會代碼（例：yun-44d4ef6b-民甲200 → yun）
    $cc_code = explode('-', $code)[0];

    // 來源「代碼」欄位偶爾用跟 ccapi 既有議會代碼不一致的縣市代碼（實測屏東縣
    // 全部一致用 pin、金門縣全部一致用 kmt，都不是零星錯誤），修正成 ccapi
    // 慣用的代碼，才能正確連到對應的 {代碼}.cc.govapi.tw
    $cc_code_fixes = ['pin' => 'pif', 'kmt' => 'kin'];
    $cc_code = $cc_code_fixes[$cc_code] ?? $cc_code;

    // 2026-10-05起優先使用來源直接提供的「屆」欄位（mixed-tw.gov-議會-議案的
    // compute_bill_structured_extras() 解析「大會/會期」文字時就已經算出來，不用
    // 再靠檔名猜）；來源沒有這個值（舊資料、或這份文件本身就沒有掛會期上下文）
    // 才退而求其次從「來源檔案」檔名解析，解析不到就不寫入這個欄位
    $term = ($record['屆'] ?? '') !== '' ? (int)$record['屆'] : extract_term_from_filename($record['來源檔案'] ?? '');

    $doc = ['議會代碼' => $cc_code];
    if ($term !== null) {
        $doc['屆'] = $term;
    }

    foreach ($record as $key => $val) {
        if ($key === '屆') continue; // 已經在上面處理過（優先用來源值，備援用檔名猜測），避免原始字串值蓋掉正確的整數值
        if ($val === '' || $val === null) continue;
        $doc[$key] = $val;
    }
    if ($names = proposer_names($doc)) {
        $doc['提案人姓名'] = $names;
    }

    $seen_codes[$code] = ($seen_codes[$code] ?? 0) + 1;
    $doc_id = $seen_codes[$code] > 1 ? "{$code}-dup{$seen_codes[$code]}" : $code;

    $count++;
    if ($diff->unchanged($doc_id, $doc)) {
        continue;
    }
    // 整份取代：來源清空的欄位（例如誤放在提案人、後來移到提案單位的機關名稱）不會留在 ES
    Elastic::dbBulkInsert('bill', $doc_id, $doc, true);
    if ($count % 500 === 0) {
        error_log("Imported {$count} bills...");
    }
}
fclose($fh);

Elastic::dbBulkCommit('bill');
$diff->finish();
error_log("Done. Imported {$count} bills, skipped {$skip}.");
