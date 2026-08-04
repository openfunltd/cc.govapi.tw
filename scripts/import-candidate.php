<?php
/**
 * 匯入候選人（選舉公報）資料到 Elasticsearch
 *
 * 用法：
 *   php scripts/import-candidate.php            # 匯入資料（upsert）
 *   php scripts/import-candidate.php --reset    # 先刪除 index 再重建並匯入
 *
 * 來源：bulletin.jsonl（每行一位候選人，欄位：來源PDF/來源頁碼/選舉類型/縣市/
 * 選舉名稱/候選人代碼/選舉代碼/行政區代碼/選區別/code_match/姓名/號次/候選人別/
 * 學歷/經歷/政見/政見來源/其他欄位/note/extract_method/相片路徑/政見圖路徑）
 * 所有來源欄位直接沿用原始名稱匯入 ES
 *
 * 重要：來源涵蓋總統/立委/縣市長/直轄市長/縣市議員/直轄市議員六種選舉，這裡只匯入
 * 跟「地方議會」有關的縣市議員／直轄市議員候選人，其餘（國家層級、行政首長）不在
 * ccapi 範疇，直接跳過不匯入。
 *
 * 判斷是不是議員選舉：優先用「選舉名稱」（來自候選人名單，準確），因為「選舉類型」
 * 是從檔名判斷、公報常把同一天投票的幾種選舉合刊在一份 PDF，檔名判斷會不準；
 * 「選舉名稱」是 null（沒比對到候選人名單）時才退回用「選舉類型」。
 *
 * 去重：上面這個合刊公報問題也會造成「同一位候選人被兩份不同來源 PDF 各自抽出一次」
 * 的真重複（例：某年臺中市長公報其實混刊了議員候選人，同一人在「直轄市長」跟
 * 「直轄市議員」兩份公報裡都有一筆，選舉名稱一樣、內容一樣，只有選舉類型/來源PDF
 * 不同）。依「候選人代碼」去重，同一代碼只保留一筆（優先保留「選舉類型」正確標示為
 * 議員選舉的那筆）。
 *
 * 衍生欄位：
 *   代碼：doc ID。候選人代碼存在時直接沿用；查無代碼（來源約 2% 沒有）時用
 *         {來源PDF sha1 前8碼}-{來源頁碼}-{號次}-{姓名} 組出替代 ID
 *   議會代碼：從「縣市」欄位（原始縣市名稱）對照出議會代碼；桃園市 2014 年改制前
 *             的舊「桃園縣」對應到已廢止的 tao-1952，其餘 21 個直接對應現行議會；
 *             對照不到的縣市名稱不寫入這個欄位（目前資料裡沒有遇到過，但保留防呆）
 *   年份：從「選舉名稱」開頭解析出民國年整數（例：「111年縣(市)議員選舉」→111），
 *         選舉名稱是 null 時這個欄位也不會有值
 *
 * 圖片網址：來源「相片路徑」「政見圖路徑」是相對於 block_root 的路徑
 * （files/image/...），改寫成公開網址 https://lydata.ronny-s3.click/bulletin/image/...
 * （每段路徑用 rawurlencode，中文檔名才能組成合法網址）
 *
 * 得票數（衍生自另一個來源，見 得票數.jsonl）：
 * 原始來源 tw.gov.cec~txn~candidates-votes.jsonl 是中選會逐投開票所的得票明細
 * （全國 577 萬列，含全國/縣市/鄉鎮/村里/投開票所五種顆粒度），對縣市議員/直轄市
 * 議員這種選區完全在單一縣市裡的選舉來說，「縣市層級」那一列的得票數就是候選人在
 * 自己選區的完整得票總數，不需要再往下鑽到鄉鎮/村里/投開票所。
 * 這裡讀的 得票數.jsonl 已經是預先篩選過的子集（行政區層級=county 且候選人代碼
 * 前綴是 ELC-T1/ELC-T2），只有 13,205 列，篩選指令見本檔案的 git commit 說明，
 * 不在 crawl 流程裡重跑（原始檔案 1.7GB，不適合每次匯入都整份掃過）。
 * 依「選舉代碼＋選區代碼」把同一場選舉的候選人分組，算出每位候選人在自己選區裡的
 * 得票排名／得票率（得票數 ÷ 該選區全部候選人得票數總和）。
 *
 * 人物代碼（衍生自另一個來源，見 人物代碼.jsonl）：
 * 原始來源 mixed-tw.gov.cec.data-選舉資料庫/files/person.jsonl（39MB，51,158 個
 * 人物分組）把同一個人歷次參選（不限選舉類型，總統/立委/縣市長/議員都算）的所有
 * 候選人代碼歸在同一組，組的 id 是該人「第一次參選」的候選人代碼——這跟 councilor
 * 的「人物代碼」是同一套推導邏輯（已實測驗證：林世宗 4 屆議員的參選代碼全部對應到
 * 同一個 group id，且這個 group id 跟他 councilor 記錄的人物代碼一模一樣），所以
 * 直接沿用當作候選人的「人物代碼」，讓查得到 councilor 的當選人跟查不到 councilor
 * 的落選人可以用同一套代碼互相對應／串連歷次參選記錄。
 * 這裡讀的 人物代碼.jsonl 已經是預先篩選過的子集（只留我們候選人清單裡實際出現過
 * 的代碼，4,136 筆），不在 crawl 流程裡重跑（原始檔案 39MB，不適合每次匯入都整份
 * 掃過且要解析巢狀結構）。查無人物代碼的（來源沒有候選人代碼的那 109 筆本來就無法
 * 查表）就不寫入這個欄位。
 *
 * 當選（衍生自另一個來源，見 當選註記.jsonl）：
 * 原本用「這個候選人代碼是否存在於 councilor 資料」判斷有沒有當選，但實測發現
 * councilor 來源（moi 地方公職人員資訊專區）如果議員中途辭職，資料就會直接消失
 * （實測案例：李彥秀 111 年台北市議員選舉最高票當選，但 councilor 資料完全沒有
 * 這筆記錄——她可能任內離職，議員名冊就不會保留她的紀錄），會把「當選但後來離職」
 * 誤判成「沒有當選」。改成直接採用中選會 `mixed-tw.gov.cec.data-選舉資料庫/
 * files/cand.csv` 的「當選註記」欄位（`*`／`!` 都代表當選，空字串代表落選，`-`
 * 是極少數未查明的特殊狀態、當作未當選處理），這是選舉當下的正式結果，不會因為
 * 事後的人事異動而改變。
 * 這裡讀的 當選註記.jsonl 同樣是預先篩選過的子集（只留 ELC-T1/ELC-T2 候選人，
 * 13,206 筆）。
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);

// 縣市名稱 → ccapi 議會代碼（見上方註解，桃園縣是唯一的歷史特例）
$county_to_cc_code = [
    '臺北市' => 'tpe', '新北市' => 'nwt', '臺中市' => 'txg', '臺南市' => 'tnn',
    '高雄市' => 'khh', '桃園市' => 'tao', '宜蘭縣' => 'ila', '新竹縣' => 'hsq',
    '新竹市' => 'hsz', '基隆市' => 'kee', '苗栗縣' => 'mia', '彰化縣' => 'cha',
    '南投縣' => 'nan', '雲林縣' => 'yun', '嘉義縣' => 'cyi', '嘉義市' => 'cyq',
    '屏東縣' => 'pif', '臺東縣' => 'ttt', '花蓮縣' => 'hua', '澎湖縣' => 'pen',
    '金門縣' => 'kin', '連江縣' => 'lie',
    '桃園縣' => 'tao-1952',
];

$index_mapping = [
    'properties' => [
        // 來源欄位（原始名稱）
        '來源PDF'   => ['type' => 'keyword'],
        '來源頁碼'   => ['type' => 'integer'],
        '選舉類型'   => ['type' => 'keyword'],
        '縣市'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '選舉名稱'   => ['type' => 'keyword'],
        '候選人代碼' => ['type' => 'keyword'],
        '選舉代碼'   => ['type' => 'keyword'],
        '行政區代碼' => ['type' => 'keyword'],
        '選區別'     => ['type' => 'keyword'],
        'code_match' => ['type' => 'keyword'],
        '姓名'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '號次'       => ['type' => 'keyword'],
        '候選人別'   => ['type' => 'keyword'],
        '學歷'       => ['type' => 'text'],
        '經歷'       => ['type' => 'text'],
        '政見'       => ['type' => 'text'],
        '政見來源'   => ['type' => 'keyword'],
        '其他欄位'   => ['type' => 'object', 'dynamic' => true],
        'note'       => ['type' => 'text'],
        'extract_method' => ['type' => 'keyword'],
        '相片路徑'   => ['type' => 'keyword', 'index' => false],
        '政見圖路徑' => ['type' => 'keyword', 'index' => false],
        // 衍生欄位
        '代碼'       => ['type' => 'keyword'],
        '議會代碼'   => ['type' => 'keyword'],
        '年份'       => ['type' => 'integer'],
        '得票數'     => ['type' => 'integer'],
        '得票排名'   => ['type' => 'integer'],
        '得票率'     => ['type' => 'float'],
        '人物代碼'   => ['type' => 'keyword'],
        '當選'       => ['type' => 'boolean'],
        '當選註記'   => ['type' => 'keyword'],
    ],
];

$known_source_keys = [
    '來源PDF', '來源頁碼', '選舉類型', '縣市', '選舉名稱', '候選人代碼', '選舉代碼',
    '行政區代碼', '選區別', 'code_match', '姓名', '號次', '候選人別', '學歷', '經歷',
    '政見', '政見來源', '其他欄位', 'note', 'extract_method', '相片路徑', '政見圖路徑',
];

if ($reset) {
    try {
        Elastic::dropIndex('candidate');
        error_log("Dropped index: candidate");
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}

try {
    Elastic::createIndex('candidate', $index_mapping);
    error_log("Created index: candidate");
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

$jsonl_path = getenv('IMPORT_CANDIDATE_JSONL') ?: (__DIR__ . '/../bulletin.jsonl');
if (!file_exists($jsonl_path)) {
    error_log("ERROR: 找不到 bulletin.jsonl：{$jsonl_path}");
    exit(1);
}

function bulletin_image_url($path)
{
    if (!$path) return null;
    $path = preg_replace('#^files/image/#', '', $path);
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    return 'https://lydata.ronny-s3.click/bulletin/image/' . $encoded;
}

// ── 讀取得票數子集，算出每位候選人的得票數／同選區排名／得票率 ──────────────

$votes_by_code = [];    // 候選人代碼 => 得票數
$votes_rank = [];       // 候選人代碼 => ['得票排名' => n, '得票率' => pct]

$votes_path = getenv('IMPORT_CANDIDATE_VOTES_JSONL') ?: (__DIR__ . '/../得票數.jsonl');
if (file_exists($votes_path)) {
    $race_groups = [];  // "{選舉代碼}:{選區代碼}" => [[候選人代碼, 得票數], ...]
    $vfh = fopen($votes_path, 'r');
    while (($vline = fgets($vfh)) !== false) {
        $vline = trim($vline);
        if ($vline === '') continue;
        $vrecord = json_decode($vline, true);
        if (!$vrecord) continue;
        $vcode = $vrecord['候選人代碼'] ?? '';
        $vvotes = $vrecord['得票數'] ?? null;
        if ($vcode === '' || $vvotes === null) continue;
        $votes_by_code[$vcode] = (int)$vvotes;
        $race_key = ($vrecord['選舉代碼'] ?? '') . ':' . ($vrecord['選區.代碼'] ?? '');
        $race_groups[$race_key][] = [$vcode, (int)$vvotes];
    }
    fclose($vfh);

    foreach ($race_groups as $candidates) {
        usort($candidates, function ($a, $b) { return $b[1] <=> $a[1]; });
        $total = array_sum(array_column($candidates, 1));
        foreach ($candidates as $rank => [$vcode, $vvotes]) {
            $votes_rank[$vcode] = [
                '得票排名' => $rank + 1,
                '得票率'   => $total > 0 ? round($vvotes / $total * 100, 1) : null,
            ];
        }
    }
    error_log("Loaded votes for " . count($votes_by_code) . " candidates, " . count($race_groups) . " races");
} else {
    error_log("得票數.jsonl not found at {$votes_path}, skipping vote data (candidate import continues without it)");
}

// ── 讀取人物代碼對照表：候選人代碼 → 人物代碼（同一人跨屆/跨次參選的共用代碼）──

$person_by_code = [];
$person_path = getenv('IMPORT_CANDIDATE_PERSON_JSONL') ?: (__DIR__ . '/../人物代碼.jsonl');
if (file_exists($person_path)) {
    $pfh = fopen($person_path, 'r');
    while (($pline = fgets($pfh)) !== false) {
        $pline = trim($pline);
        if ($pline === '') continue;
        $precord = json_decode($pline, true);
        if (!$precord) continue;
        $pcode = $precord['候選人代碼'] ?? '';
        $pgroup = $precord['人物代碼'] ?? '';
        if ($pcode !== '' && $pgroup !== '') {
            $person_by_code[$pcode] = $pgroup;
        }
    }
    fclose($pfh);
    error_log("Loaded person mapping for " . count($person_by_code) . " candidates");
} else {
    error_log("人物代碼.jsonl not found at {$person_path}, skipping person mapping (candidate import continues without it)");
}

// ── 讀取中選會當選註記：候選人代碼 → 是否當選（見上方註解，不用 councilor 資料
// 有沒有這筆記錄來判斷，議員中途離職會讓 councilor 記錄消失、誤判成沒當選）──

$elected_by_code = [];
$elected_path = getenv('IMPORT_CANDIDATE_ELECTED_JSONL') ?: (__DIR__ . '/../當選註記.jsonl');
if (file_exists($elected_path)) {
    $efh = fopen($elected_path, 'r');
    while (($eline = fgets($efh)) !== false) {
        $eline = trim($eline);
        if ($eline === '') continue;
        $erecord = json_decode($eline, true);
        if (!$erecord) continue;
        $ecode = $erecord['候選人代碼'] ?? '';
        if ($ecode === '') continue;
        $elected_by_code[$ecode] = $erecord['當選註記'] ?? '';
    }
    fclose($efh);
    error_log("Loaded elected marker for " . count($elected_by_code) . " candidates");
} else {
    error_log("當選註記.jsonl not found at {$elected_path}, skipping elected marker (candidate import continues without it)");
}

$fh = fopen($jsonl_path, 'r');
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($fh);
}

// 第一輪：讀進所有議員選舉候選人，依「候選人代碼」分組準備去重
// （沒有候選人代碼的直接收進 $no_code_records，不用分組）
$grouped = [];      // 候選人代碼 => [record, ...]
$no_code_records = [];
$skip_not_council = 0;
$headers_checked = false;

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $record = json_decode($line, true);
    if (!$record) {
        error_log("Invalid JSON: {$line}");
        continue;
    }

    if (!$headers_checked) {
        $headers_checked = true;
        $unknown = array_diff(array_keys($record), $known_source_keys);
        if ($unknown) {
            error_log("ERROR: 來源檔案出現未知欄位：" . implode(', ', $unknown));
            error_log("請先在 import-candidate.php 的 index_mapping 和 \$known_source_keys 補上對應設定。");
            fclose($fh);
            exit(1);
        }
    }

    // 判斷是否為縣市議員／直轄市議員選舉：優先用「選舉名稱」（來自候選人名單，準確），
    // 選舉名稱是 null 時才退回用「選舉類型」（來自檔名，合刊公報時可能不準）
    $election_name = $record['選舉名稱'] ?? '';
    if ($election_name !== '') {
        $is_council_race = (mb_strpos($election_name, '議員選舉') !== false);
    } else {
        $is_council_race = in_array($record['選舉類型'] ?? '', ['直轄市議員', '縣市議員'], true);
    }
    if (!$is_council_race) {
        $skip_not_council++;
        continue;
    }

    $code = $record['候選人代碼'] ?? '';
    if ($code !== '') {
        $grouped[$code][] = $record;
    } else {
        $no_code_records[] = $record;
    }
}
fclose($fh);

error_log("Skipped (not council-related): {$skip_not_council}");
error_log("Grouped by 候選人代碼: " . count($grouped) . " groups, no-code records: " . count($no_code_records));

$count = 0;
$dedup_dropped = 0;
$no_council_code_field = 0;

function import_candidate_doc($record, $doc_id, $county_to_cc_code, $votes_by_code, $votes_rank, $person_by_code, $elected_by_code)
{
    $doc = ['代碼' => $doc_id];

    $election_name = $record['選舉名稱'] ?? '';
    if ($election_name && preg_match('/^(\d+)年/u', $election_name, $m)) {
        $doc['年份'] = (int)$m[1];
    }

    $county = $record['縣市'] ?? '';
    if ($county !== '' && isset($county_to_cc_code[$county])) {
        $doc['議會代碼'] = $county_to_cc_code[$county];
    }

    foreach ($record as $key => $val) {
        if ($val === '' || $val === null) continue;
        if ($key === '相片路徑' || $key === '政見圖路徑') {
            $doc[$key] = bulletin_image_url($val);
            continue;
        }
        $doc[$key] = $val;
    }

    $candidate_code = $record['候選人代碼'] ?? '';
    if ($candidate_code !== '' && isset($votes_by_code[$candidate_code])) {
        $doc['得票數'] = $votes_by_code[$candidate_code];
        if (isset($votes_rank[$candidate_code])) {
            $doc['得票排名'] = $votes_rank[$candidate_code]['得票排名'];
            if ($votes_rank[$candidate_code]['得票率'] !== null) {
                $doc['得票率'] = $votes_rank[$candidate_code]['得票率'];
            }
        }
        if (isset($person_by_code[$candidate_code])) {
            $doc['人物代碼'] = $person_by_code[$candidate_code];
        }
        if (isset($elected_by_code[$candidate_code])) {
            $marker = $elected_by_code[$candidate_code];
            $doc['當選'] = in_array($marker, ['*', '!'], true);
            if ($marker !== '') {
                $doc['當選註記'] = $marker;
            }
        }
    }

    return $doc;
}

// 有候選人代碼的：每組去重，優先保留「選舉類型」正確標示為議員選舉的那筆
foreach ($grouped as $code => $records) {
    $chosen = $records[0];
    if (count($records) > 1) {
        foreach ($records as $r) {
            if (in_array($r['選舉類型'] ?? '', ['直轄市議員', '縣市議員'], true)) {
                $chosen = $r;
                break;
            }
        }
        $dedup_dropped += count($records) - 1;
    }

    $doc = import_candidate_doc($chosen, $code, $county_to_cc_code, $votes_by_code, $votes_rank, $person_by_code, $elected_by_code);
    if (!isset($doc['議會代碼'])) {
        $no_council_code_field++;
    }
    Elastic::dbBulkInsert('candidate', $code, $doc);
    $count++;
    if ($count % 500 === 0) {
        error_log("Imported {$count} candidates...");
    }
}

// 沒有候選人代碼的：用「來源PDF sha1 前8碼-來源頁碼-號次-姓名」組合成替代 ID，
// 仍可能撞號（合刊公報造成同一頁被抓兩次），撞到就加序號後綴，不要靜默覆蓋掉
$seen_synthetic_ids = [];
foreach ($no_code_records as $record) {
    $pdf_hash = substr(sha1($record['來源PDF'] ?? ''), 0, 8);
    $base_id = "nc-{$pdf_hash}-" . ($record['來源頁碼'] ?? '0') . '-' . ($record['號次'] ?? '0') . '-' . ($record['姓名'] ?? '');
    $seen_synthetic_ids[$base_id] = ($seen_synthetic_ids[$base_id] ?? 0) + 1;
    $doc_id = $seen_synthetic_ids[$base_id] > 1 ? "{$base_id}-dup{$seen_synthetic_ids[$base_id]}" : $base_id;

    $doc = import_candidate_doc($record, $doc_id, $county_to_cc_code, $votes_by_code, $votes_rank, $person_by_code, $elected_by_code);
    if (!isset($doc['議會代碼'])) {
        $no_council_code_field++;
    }
    Elastic::dbBulkInsert('candidate', $doc_id, $doc);
    $count++;
    if ($count % 500 === 0) {
        error_log("Imported {$count} candidates...");
    }
}

Elastic::dbBulkCommit('candidate');
error_log("Done. Imported {$count} candidates, deduped {$dedup_dropped} duplicate records, {$no_council_code_field} without 議會代碼.");
