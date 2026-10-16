<?php
/**
 * 匯入立法院（議會代碼 ly）的場次（sitting）與會議（meet）
 *
 * 用法：
 *   php scripts/import-ly-meet.php
 *
 * 寫進既有的 sitting／meet index（不建新 index、不支援 --reset）
 *
 * 來源：
 *   IMPORT_LY_MEET_DIR       ly.govapi.tw 整合後的會議資料目錄（每場一個 {會議ID}.json，
 *                            例：院會-11-1-10.json、委員會-11-1-15-10.json）
 *   IMPORT_LY_COMMITTEE_CSV  委員會代號 → 名稱
 *
 * 立法院的切法跟地方議會不同：
 *   sitting＝官方編號的「一次會議」（例：第11屆第1會期第10次會議、某委員會第10次全體
 *     委員會議），可以跨好幾天（院會大多兩天）。代碼 ly-{會議ID}，「日期」是第一天，
 *     「日期清單」列出全部日期；議事錄的出席委員放在這一層
 *   meet＝這次會議的其中一天，代碼 {場次代碼}-{yyyymmdd}；當天的時間、地點、出席委員、
 *     會議內容，以及公報裡當天各議程的發言者與頁碼（「公報議程」）放在這一層
 * 會期代碼：一般是 ly-{屆}-r{會期}；臨時會的會議ID帶有臨時會次
 *   （臨時會院會-{屆}-{會期}-{臨時會次}-{會次}），會期代碼是 ly-{屆}-r{會期}e{臨時會次}
 * 委員會或主旨：會議名稱（考察的名稱只有「考察活動」，改用標題）
 * 公報只有發言者清單與頁碼，沒有逐字稿全文，所以「有速記」「有逐字稿」都是 false
 */

include(__DIR__ . '/../init.inc.php');

$cc_code = 'ly';
$prefix = getenv('ELASTIC_PREFIX');

Elastic::dbQuery("/{$prefix}sitting/_mapping", 'PUT', json_encode(['properties' => [
    '日期清單'   => ['type' => 'date', 'format' => 'yyyy-MM-dd'],
    '會議名稱'   => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
    '委員會代碼' => ['type' => 'keyword'],
    '出席委員'   => ['type' => 'keyword'],
]]));
Elastic::dbQuery("/{$prefix}meet/_mapping", 'PUT', json_encode(['properties' => [
    '會議內容' => ['type' => 'text'],
    '出席委員' => ['type' => 'keyword'],
    '公報議程' => ['type' => 'object', 'enabled' => false],
]]));

// 委員會代號 → 名稱
$committee_names = [];
$csv_path = getenv('IMPORT_LY_COMMITTEE_CSV');
if ($csv_path && ($fh = fopen($csv_path, 'r'))) {
    fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (($row[0] ?? '') !== '' && ($row[1] ?? '') !== '') {
            $committee_names[intval($row[0])] = $row[1];
        }
    }
    fclose($fh);
}

$dir = getenv('IMPORT_LY_MEET_DIR');
if (!$dir || !is_dir($dir)) {
    error_log("ERROR: 找不到來源目錄（IMPORT_LY_MEET_DIR）：{$dir}");
    exit(1);
}

$weekdays = ['日', '一', '二', '三', '四', '五', '六'];
$hhmm = fn($datetime) => preg_match('/T(\d\d:\d\d)/', (string) $datetime, $m) ? $m[1] : null;
$names = function ($value) {
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), 'strlen'));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
};

$sitting_count = 0;
$meet_count = 0;
$skipped = 0;
foreach (glob("{$dir}/*.json") as $file) {
    $d = json_decode(file_get_contents($file));
    $meet_id = $d->meet_id ?? null;
    $term = intval($d->term ?? 0);
    $dates = array_filter((array) ($d->dates ?? []));
    if (!$dates) {
        // 少數會議沒有 dates，改從公報發言紀錄／發言紀錄帶的日期補
        foreach ((array) ($d->公報發言紀錄 ?? []) as $g) {
            $dates = array_merge($dates, (array) ($g->meetingDate ?? []));
        }
        foreach ((array) ($d->發言紀錄 ?? []) as $sp) {
            $dates[] = $sp->smeetingDate ?? null;
        }
    }
    if (!$dates && ($d->議事錄->時間 ?? null)) {
        // 再不行就解析議事錄的時間文字（例：「中華民國113年4月19日（星期五）…4月23日…」，
        // 年份通常只寫在第一個日期）
        $roc_year = null;
        preg_match_all('/(?:(\d+)年)?(\d+)月(\d+)日/u', $d->議事錄->時間, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $roc_year = ($m[1] ?? '') !== '' ? intval($m[1]) : $roc_year;
            if ($roc_year) {
                $dates[] = sprintf('%04d-%02d-%02d', $roc_year + 1911, $m[2], $m[3]);
            }
        }
    }
    $dates = array_values(array_unique(array_filter($dates, fn($v) => preg_match('/^\d{4}-\d\d-\d\d$/', (string) $v))));
    sort($dates);
    if (!$meet_id || !$term || !$dates) {
        $skipped++;
        continue;
    }

    if (preg_match('/^臨時會[^-]+-(\d+)-(\d+)-(\d+)-/u', $meet_id, $m)) {
        $session_code = "{$cc_code}-{$m[1]}-r{$m[2]}e{$m[3]}";
    } elseif ($d->sessionPeriod ?? null) {
        $session_code = "{$cc_code}-{$term}-r" . intval($d->sessionPeriod);
    } else {
        $session_code = null;
    }

    $committee_codes = array_map('intval', (array) ($d->committees ?? []));
    $committee_label = implode('、', array_filter(array_map(fn($c) => $committee_names[$c] ?? null, $committee_codes)));
    $type = $d->meet_type ?? '';
    $name = trim($d->name ?? '') ?: trim($d->title ?? '');
    $subject = ($type === '考察' && trim($d->title ?? '')) ? trim($d->title) : $name;

    $meet_data_by_date = [];
    foreach ((array) ($d->meet_data ?? []) as $md) {
        if ($md->date ?? null) {
            $meet_data_by_date[$md->date] = $md;
        }
    }
    $first = $meet_data_by_date[$dates[0]] ?? null;

    $sitting_code = "{$cc_code}-{$meet_id}";
    Elastic::dbBulkInsert('sitting', $sitting_code, [
        '代碼'       => $sitting_code,
        '會期代碼'   => $session_code,
        '日期'       => $dates[0],
        '日期清單'   => $dates,
        '星期'       => $weekdays[date('w', strtotime($dates[0]))],
        '會次'       => ($d->sessionTimes ?? null) ? intval($d->sessionTimes) : null,
        '場次類別'   => $type,
        '委員會名稱' => $committee_label ?: null,
        '委員會代碼' => array_map(fn($c) => "{$cc_code}-{$c}", $committee_codes),
        '會議名稱'   => $name ?: null,
        '議程說明'   => $subject ?: null,
        '開始時間'   => $first ? $hhmm($first->startTime ?? null) : null,
        '結束時間'   => $first ? $hhmm($first->endTime ?? null) : null,
        '出席委員'   => $names($d->議事錄->出席委員 ?? []),
        '議會代碼'   => $cc_code,
        '屆'         => $term,
    ]);
    $sitting_count++;

    foreach ($dates as $date) {
        $md = $meet_data_by_date[$date] ?? null;
        $ppg_url = null;
        if ($md && ($md->meetingNo ?? null) && strlen($md->meetingNo) < 15) {
            $ppg_url = sprintf('https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%d/%s',
                $md->meetingNo, intval(substr($date, 0, 4)) - 1911, str_replace('-', '/', substr($date, 5)));
        }
        $agenda = [];
        foreach ((array) ($d->公報發言紀錄 ?? []) as $g) {
            $g_dates = (array) ($g->meetingDate ?? []);
            if ($g_dates ? !in_array($date, $g_dates, true) : count($dates) > 1) {
                continue;
            }
            $agenda[] = [
                '順序'         => count($agenda) + 1,
                '議程'         => trim(preg_replace('/\s*\n\s*/u', '', $g->content ?? '')),
                '發言者'       => array_values((array) ($g->speakers ?? [])),
                '公報編號'     => $g->gazette_id ?? null,
                '公報頁碼'     => ($g->page_start ?? null) ? ($g->page_start . (($g->page_end ?? null) && $g->page_end != $g->page_start ? '-' . $g->page_end : '')) : null,
                '公報網址'     => $g->pdf_url ?? null,
                '議事日程網址' => $g->agendarec_url ?? null,
            ];
        }

        $meet_code = $sitting_code . '-' . str_replace('-', '', $date);
        Elastic::dbBulkInsert('meet', $meet_code, [
            '代碼'         => $meet_code,
            '場次代碼'     => $sitting_code,
            '委員會或主旨' => $subject ?: null,
            '日期'         => $date,
            '時間資訊'     => $md->meetingDateDesc ?? null,
            '地點'         => $md->meetingRoom ?? null,
            '開始時間'     => $md ? $hhmm($md->startTime ?? null) : null,
            '結束時間'     => $md ? $hhmm($md->endTime ?? null) : null,
            '來源網址'     => $ppg_url,
            '有速記'       => false,
            '有逐字稿'     => false,
            '會議內容'     => trim($md->meetingContent ?? '') ?: null,
            '出席委員'     => $md ? $names($md->attendLegislator ?? []) : [],
            '公報議程'     => $agenda,
            '議會代碼'     => $cc_code,
            '屆'           => $term,
            '會期代碼'     => $session_code,
        ]);
        $meet_count++;
    }
}
Elastic::dbBulkCommit('sitting');
Elastic::dbBulkCommit('meet');
error_log("Done. Imported {$sitting_count} sittings, {$meet_count} meets (skipped {$skipped}).");
