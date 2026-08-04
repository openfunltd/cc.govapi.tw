<?php
/**
 * 從三個大型原始來源重新產生 import-candidate.php 用的預篩選對照檔（得票數.jsonl／
 * 人物代碼.jsonl／當選註記.jsonl）。這三個原始檔案都太大，不適合每次匯入都整份掃過
 * （得票數來源 1.7GB／人物代碼來源 39MB），所以另外篩選出小很多的子集放在專案目錄。
 *
 * 用法：
 *   php scripts/prepare-candidate-lookups.php
 *
 * 三個對照檔的「會不會過期」性質不同：
 * - 得票數.jsonl／當選註記.jsonl 是用獨立規則篩選（行政區層級=county 且候選人代碼
 *   前綴 ELC-T[12]-；選舉代碼前綴 ELC-T1/ELC-T2），只要規則沒變就不會過期。
 * - 人物代碼.jsonl 是「查 bulletin.jsonl 目前有哪些候選人代碼，逐一去 person.jsonl
 *   查」，bulletin.jsonl 只要新增候選人（例如新增某年份/縣市的公報），這份對照表就會
 *   出現「有候選人、但查不到人物代碼」的缺口，必須重新產生（不能只重跑
 *   import-candidate.php），見 PLAN.md 已知案例（新北市 107/111 年公報补上後才發現）。
 *
 * 所以這隻腳本每次都把三個對照檔全部重新產生一次，不做增量判斷，避免上述人物代碼
 * 過期的問題再次發生。
 */

include(__DIR__ . '/../init.inc.php');

$bulletin_path = getenv('IMPORT_CANDIDATE_JSONL') ?: (__DIR__ . '/../bulletin.jsonl');
if (!file_exists($bulletin_path)) {
    error_log("ERROR: 找不到 bulletin.jsonl：{$bulletin_path}");
    exit(1);
}

// ── 蒐集 bulletin.jsonl 目前所有候選人代碼（不篩選選舉類型，寧可對照表多收一點）──

$codes = [];
$bfh = fopen($bulletin_path, 'r');
while (($line = fgets($bfh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $record = json_decode($line, true);
    if (!$record) continue;
    $code = $record['候選人代碼'] ?? '';
    if ($code !== '') {
        $codes[$code] = true;
    }
}
fclose($bfh);
error_log("bulletin.jsonl 候選人代碼數：" . count($codes));

// ── 人物代碼.jsonl：候選人代碼 → person.jsonl 的 group id ──────────────────

$person_source = getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL');
$person_out = getenv('IMPORT_CANDIDATE_PERSON_JSONL') ?: (__DIR__ . '/../人物代碼.jsonl');
if ($person_source && file_exists($person_source)) {
    $matched = 0;
    $out = fopen($person_out, 'w');
    $pfh = fopen($person_source, 'r');
    while (($line = fgets($pfh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $obj = json_decode($line, true);
        if (!$obj) continue;
        $gid = $obj['id'] ?? null;
        if (!$gid) continue;
        foreach (($obj['records'] ?? []) as $rec) {
            $pid = $rec['person_id'] ?? null;
            if ($pid !== null && isset($codes[$pid])) {
                fwrite($out, json_encode(['候選人代碼' => $pid, '人物代碼' => $gid], JSON_UNESCAPED_UNICODE) . "\n");
                $matched++;
            }
        }
    }
    fclose($pfh);
    fclose($out);
    error_log("人物代碼.jsonl 重新產生：{$matched} / " . count($codes) . " 筆候選人代碼比對到");
} else {
    error_log("IMPORT_CANDIDATE_PERSON_SOURCE_JSONL 未設定或檔案不存在，略過人物代碼.jsonl 重新產生");
}

// ── 當選註記.jsonl：候選人代碼 → 中選會 cand.csv 當選註記（ELC-T1/ELC-T2）──────

$elected_source = getenv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV');
$elected_out = getenv('IMPORT_CANDIDATE_ELECTED_JSONL') ?: (__DIR__ . '/../當選註記.jsonl');
if ($elected_source && file_exists($elected_source)) {
    $count = 0;
    $out = fopen($elected_out, 'w');
    $cfh = fopen($elected_source, 'r');
    $headers = fgetcsv($cfh);
    $col = array_flip($headers);
    while (($row = fgetcsv($cfh)) !== false) {
        $election_code = $row[$col['選舉代碼']] ?? '';
        if (strpos($election_code, 'ELC-T1') !== 0 && strpos($election_code, 'ELC-T2') !== 0) {
            continue;
        }
        $cand_code = $row[$col['候選人代碼']] ?? '';
        if ($cand_code === '') continue;
        fwrite($out, json_encode([
            '候選人代碼' => $cand_code,
            '當選註記'   => $row[$col['當選註記']] ?? '',
        ], JSON_UNESCAPED_UNICODE) . "\n");
        $count++;
    }
    fclose($cfh);
    fclose($out);
    error_log("當選註記.jsonl 重新產生：{$count} 筆");
} else {
    error_log("IMPORT_CANDIDATE_ELECTED_SOURCE_CSV 未設定或檔案不存在，略過當選註記.jsonl 重新產生");
}

// ── 得票數.jsonl：候選人在自己選區的縣市層級得票數（ELC-T1/ELC-T2，county 層級）──

$votes_source = getenv('IMPORT_CANDIDATE_VOTES_SOURCE_JSONL');
$votes_out = getenv('IMPORT_CANDIDATE_VOTES_JSONL') ?: (__DIR__ . '/../得票數.jsonl');
if ($votes_source && file_exists($votes_source)) {
    $count = 0;
    $out = fopen($votes_out, 'w');
    $vfh = fopen($votes_source, 'r');
    while (($line = fgets($vfh)) !== false) {
        // 先用字串比對快速跳過不相關的列，避免每一列都 json_decode（來源檔案 577萬列）
        if (strpos($line, '"行政區層級":"county"') === false) continue;
        if (strpos($line, '"候選人代碼":"ELC-T1-') === false && strpos($line, '"候選人代碼":"ELC-T2-') === false) continue;
        $record = json_decode($line, true);
        if (!$record) continue;
        if (($record['行政區層級'] ?? '') !== 'county') continue;
        $cand_code = $record['候選人代碼'] ?? '';
        if (strpos($cand_code, 'ELC-T1-') !== 0 && strpos($cand_code, 'ELC-T2-') !== 0) continue;
        fwrite($out, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n");
        $count++;
    }
    fclose($vfh);
    fclose($out);
    error_log("得票數.jsonl 重新產生：{$count} 筆");
} else {
    error_log("IMPORT_CANDIDATE_VOTES_SOURCE_JSONL 未設定或檔案不存在，略過得票數.jsonl 重新產生");
}

error_log("Done.");
