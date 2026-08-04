<?php
/**
 * 每天排程用：自動偵測各原始資料來源（config.inc.php 設定的 IMPORT_* 路徑）有沒有
 * 更新，有更新的型別才重新匯入，全部型別跑完後如果有任何型別更新過，才重跑
 * generate-completeness.php／generate-council-overview.php 這兩個下游快取。
 *
 * 用法：
 *   php scripts/auto-refresh.php              # 一般執行（建議排程每天跑一次）
 *   php scripts/auto-refresh.php --force      # 不管有沒有偵測到變化，全部型別都重新匯入
 *   php scripts/auto-refresh.php --reset-all  # 全部型別都加 --reset（先砍 index 再重建），
 *                                              # 用於來源資料有刪除/更正、需要清掉舊資料時；
 *                                              # 平常自動排程不需要，一般 upsert 就夠了
 *
 * 偵測方式：每個型別的來源檔案（可能不只一個）記錄 mtime+size 組成的簽章，存在
 * .auto-refresh-state.json（gitignored），跟上次記錄的值比對，不同就代表來源有變化。
 * 只讀檔案屬性、不讀內容——候選人得票數原始來源有 1.7GB，不能每天整份重讀比對內容。
 *
 * candidate 型別比較特殊：除了 bulletin.jsonl，還要多看 person.jsonl／cand.csv／
 * 候選人得票數這三個上游原始來源，任一個有變化都要先重跑 prepare-candidate-lookups.php
 * 重新產生三個對照檔（人物代碼.jsonl 是用「目前候選人清單」反查出來的，來源清單一變就
 * 會出現查無人物代碼的缺口，必須重新產生、不能只重跑 import-candidate.php——已知案例見
 * PLAN.md：新北市 107/111 年公報補齊後，還要重新產生人物代碼.jsonl 候選人姓名超連結
 * 才會出現），所以這裡一律先重跑 prepare 腳本再匯入。
 *
 * 匯入預設用 upsert（不加 --reset），這也是每支 import-*.php 自己文件寫的一般用法；
 * --reset 只用來處理「來源刪除了某筆資料、upsert 不會主動刪除 ES 裡對應舊資料」這種
 * 情況，平常的每日排程不需要。
 *
 * 建議排程（cron，每天凌晨跑一次，log 自己重導向到檔案）：
 *   0 5 * * * cd /path/to/cc.govapi.tw && php scripts/auto-refresh.php >> logs/auto-refresh.log 2>&1
 */

include(__DIR__ . '/../init.inc.php');

$force = in_array('--force', $argv ?? []);
$reset_all = in_array('--reset-all', $argv ?? []);

$state_path = __DIR__ . '/../.auto-refresh-state.json';
$state = file_exists($state_path) ? json_decode(file_get_contents($state_path), true) : [];
if (!is_array($state)) {
    $state = [];
}

function watched_signature($paths)
{
    $parts = [];
    foreach ($paths as $p) {
        if ($p && file_exists($p)) {
            $stat = stat($p);
            $parts[] = "{$p}:{$stat['mtime']}:{$stat['size']}";
        } else {
            $parts[] = "{$p}:missing";
        }
    }
    return md5(implode('|', $parts));
}

function save_state($state_path, $state)
{
    file_put_contents($state_path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function run_cmd($cmd)
{
    echo "  \$ {$cmd}\n";
    $output = [];
    $exit_code = 0;
    exec($cmd . ' 2>&1', $output, $exit_code);
    foreach ($output as $line) {
        echo "    {$line}\n";
    }
    return $exit_code === 0;
}

$php_bin = PHP_BINARY;
$dir = __DIR__;

// 各型別的來源路徑一律先讀 IMPORT_* 環境變數，跟對應 import-*.php 的預設路徑邏輯一致
$jobs = [
    'council' => [
        'watch'  => [getenv('IMPORT_COUNCIL_CSV') ?: ($dir . '/../議會.csv')],
        'import' => ["{$php_bin} {$dir}/import-council.php"],
    ],
    'term' => [
        'watch'  => [getenv('IMPORT_TERM_CSV') ?: ($dir . '/../屆.csv')],
        'import' => ["{$php_bin} {$dir}/import-term.php"],
    ],
    'councilor' => [
        'watch'  => [getenv('IMPORT_COUNCILOR_JSONL') ?: ($dir . '/../議員.jsonl')],
        'import' => ["{$php_bin} {$dir}/import-councilor.php"],
    ],
    'session' => [
        'watch'  => [getenv('IMPORT_SESSION_CSV') ?: ($dir . '/../會期.csv')],
        'import' => ["{$php_bin} {$dir}/import-session.php"],
    ],
    'sitting' => [
        'watch'  => [getenv('IMPORT_SITTING_CSV') ?: ($dir . '/../場次.csv')],
        'import' => ["{$php_bin} {$dir}/import-sitting.php"],
    ],
    'committee' => [
        'watch'  => [getenv('IMPORT_COMMITTEE_CSV') ?: ($dir . '/../data.csv')],
        'import' => ["{$php_bin} {$dir}/import-committee.php"],
    ],
    'transcript' => [
        'watch'  => [
            getenv('IMPORT_TRANSCRIPT_CSV') ?: ($dir . '/../逐字稿索引.csv'),
        ],
        'import' => ["{$php_bin} {$dir}/import-transcript.php"],
    ],
    'bill' => [
        'watch'  => [getenv('IMPORT_BILL_JSONL') ?: ($dir . '/../議案.jsonl')],
        'import' => ["{$php_bin} {$dir}/import-bill.php"],
    ],
    'candidate' => [
        'watch' => [
            getenv('IMPORT_CANDIDATE_JSONL') ?: ($dir . '/../bulletin.jsonl'),
            getenv('IMPORT_CANDIDATE_PERSON_SOURCE_JSONL'),
            getenv('IMPORT_CANDIDATE_ELECTED_SOURCE_CSV'),
            getenv('IMPORT_CANDIDATE_VOTES_SOURCE_JSONL'),
        ],
        'prepare' => ["{$php_bin} {$dir}/prepare-candidate-lookups.php"],
        'import'  => ["{$php_bin} {$dir}/import-candidate.php"],
    ],
];

echo '=== auto-refresh ' . date('Y-m-d H:i:s') . " ===\n";

$any_ran = false;
$any_failed = false;

foreach ($jobs as $name => $job) {
    $watch = array_values(array_filter($job['watch']));
    if (!$watch) {
        echo "[{$name}] 沒有設定任何來源路徑，略過\n";
        continue;
    }

    $sig = watched_signature($watch);
    $changed = $force || $reset_all || (($state[$name] ?? null) !== $sig);
    if (!$changed) {
        echo "[{$name}] 來源沒有變化，略過\n";
        continue;
    }

    echo "[{$name}] 偵測到來源變化，重新匯入...\n";
    $ok = true;
    foreach (($job['prepare'] ?? []) as $cmd) {
        $ok = $ok && run_cmd($cmd);
    }
    foreach ($job['import'] as $cmd) {
        if ($reset_all) {
            $cmd .= ' --reset';
        }
        $ok = $ok && run_cmd($cmd);
    }

    if ($ok) {
        $state[$name] = $sig;
        // 每個型別跑完就馬上存檔，不要等全部型別跑完才寫——這樣即使中途被中斷，
        // 已經成功的型別下次也不用重跑一次
        save_state($state_path, $state);
        $any_ran = true;
        echo "[{$name}] 完成\n";
    } else {
        $any_failed = true;
        echo "[{$name}] 失敗，保留舊的比對狀態，下次執行會重試\n";
    }
}

if ($any_ran) {
    echo "=== 有型別更新，重建下游快取 ===\n";
    run_cmd("{$php_bin} {$dir}/generate-completeness.php");
    run_cmd("{$php_bin} {$dir}/generate-council-overview.php");
} else {
    echo "沒有任何型別的來源資料有變化，不需要重建下游快取\n";
}

echo '=== auto-refresh 完成，' . ($any_failed ? '有型別失敗' : '全部成功') . " ===\n";
exit($any_failed ? 1 : 0);
