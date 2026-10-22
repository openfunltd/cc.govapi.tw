<?php
/**
 * 匯入監察院公職人員財產申報（declaration）到 Elasticsearch：一份申報一筆，含總金額與各類財產的筆數、金額小計
 * （各筆財產明細另外處理）
 *
 * 用法：
 *   php scripts/import-declaration.php              # 匯入資料（upsert）
 *   php scripts/import-declaration.php --reset      # 先刪除 index 再重建並匯入
 *
 * 來源：
 *   IMPORT_DECLARATION_JSON_DIR   每份申報一個 JSON（{申報人}/{申報日}-{機關}-{職稱}-{來源編號}.json），監察院廉政專刊
 *                                 刊登的申報資料轉成的結構化資料
 *   IMPORT_DECLARATION_LIST_CSV   申報清單（id, 出版日, 姓名, 服務機關, 職稱, 申報日, 種類）
 *   IMPORT_DECLARATION_PERSON_CSV 申報人屬性（person-id, relation, value…；value「參選人代碼:ELC-…」是申報人參選時的候選人代碼）
 *   IMPORT_DECLARATION_ISSUE_HTML 廉政專刊列表頁（逗號分隔，選填）：連結 title「廉政專刊第N期」對到官方下載網址
 *
 * 對應方式（都只接受確定的對應，對不到就留空）：
 *   人物：申報人屬性的候選人代碼 → candidate 的人物代碼、議會代碼
 *   機關：服務機關名稱跟 agency 的全名完全相同，或跟立法院中央機關的名稱／舊名稱完全相同 → 機關的代碼、議會代碼
 *   議會代碼：候選人與機關對到的議會（可能好幾個），用來讓各議會子網域查得到
 *
 * Doc ID：來源編號（例：廉政專刊第136期-254，期別＋頁碼）
 */

include(__DIR__ . '/../init.inc.php');

$reset = in_array('--reset', $argv ?? []);
$prefix = getenv('ELASTIC_PREFIX');

$amount_props = ['properties' => [
    '現金' => ['type' => 'double'], '存款' => ['type' => 'double'], '有價證券' => ['type' => 'double'],
    '債權' => ['type' => 'double'], '債務' => ['type' => 'double'], '事業投資' => ['type' => 'double'],
]];
$index_mapping = [
    'properties' => [
        '代碼'         => ['type' => 'keyword'],
        '議會代碼'     => ['type' => 'keyword'],
        '姓名'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        '人物代碼'     => ['type' => 'keyword'],
        '候選人代碼'   => ['type' => 'keyword'],
        '申報日'       => ['type' => 'date'],
        '出版日'       => ['type' => 'date'],
        '種類'         => ['type' => 'keyword'],
        '申報類別'     => ['type' => 'keyword'],
        '原始申報類別' => ['type' => 'keyword'],
        '服務機關紀錄' => ['properties' => [
            '服務機關' => ['type' => 'keyword'],
            '職稱'     => ['type' => 'keyword'],
            '機關代碼' => ['type' => 'keyword'],
        ]],
        '機關代碼'     => ['type' => 'keyword'],
        '配偶子女'     => ['type' => 'object', 'enabled' => false],
        '總金額'       => $amount_props,
        '原始總金額'   => ['type' => 'object', 'enabled' => false],
        '各類筆數'     => ['type' => 'object'],
        '各類金額'     => ['type' => 'object'],
        '變動期間'     => ['type' => 'keyword'],
        '受託人'       => ['type' => 'keyword'],
        '備註'         => ['type' => 'text'],
        '解析錯誤'     => ['type' => 'boolean'],
        '來源'         => ['properties' => [
            '刊物'     => ['type' => 'keyword'],
            '頁碼'     => ['type' => 'integer'],
            '官方網址' => ['type' => 'keyword', 'index' => false],
        ]],
    ],
];

if ($reset) {
    try {
        Elastic::dropIndex('declaration');
    } catch (Exception $e) {
        error_log("Drop index skipped (may not exist): " . $e->getMessage());
    }
}
try {
    Elastic::createIndex('declaration', $index_mapping);
} catch (Exception $e) {
    error_log("Index exists or created: " . $e->getMessage());
}

$json_dir = getenv('IMPORT_DECLARATION_JSON_DIR');
if (!$json_dir || !is_dir($json_dir)) {
    error_log("ERROR: 找不到申報資料目錄（IMPORT_DECLARATION_JSON_DIR）：{$json_dir}");
    exit(1);
}

// 出版日
$published = [];
if (($path = getenv('IMPORT_DECLARATION_LIST_CSV')) && ($fh = fopen($path, 'r'))) {
    $headers = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === count($headers)) {
            $r = array_combine($headers, $row);
            $published[$r['id']] = $r['出版日'];
        }
    }
    fclose($fh);
}

// 申報編號 → 候選人代碼
$cand_codes = [];
if (($path = getenv('IMPORT_DECLARATION_PERSON_CSV')) && ($fh = fopen($path, 'r'))) {
    fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (($row[1] ?? '') === 'attr' && str_starts_with($row[2] ?? '', '參選人代碼:')) {
            $cand_codes[$row[0]][] = substr($row[2], strlen('參選人代碼:'));
        }
    }
    fclose($fh);
}

// 廉政專刊期別 → 官方下載網址
$issue_url = [];
foreach (array_filter(array_map('trim', explode(',', (string) getenv('IMPORT_DECLARATION_ISSUE_HTML')))) as $path) {
    if (!file_exists($path)) {
        continue;
    }
    preg_match_all('#href="([^"]+)"[^>]*title="[^"]*(廉政專刊第\d+期)[^"]*#u', file_get_contents($path), $m);
    foreach ($m[1] as $i => $url) {
        $issue_url[$m[2][$i]] ??= html_entity_decode($url);
    }
}
$issue_list_url = 'https://sunshine.cy.gov.tw/News.aspx?n=17&sms=8861';

// 候選人代碼 → 人物代碼、議會代碼
$candidate_info = [];
$all_codes = array_values(array_unique(array_merge(...array_values($cand_codes ?: [[]]))));
foreach (array_chunk($all_codes, 1000) as $chunk) {
    $r = Elastic::dbQuery("/{$prefix}candidate/_search", 'POST', json_encode([
        'size' => 2000, '_source' => ['候選人代碼', '人物代碼', '議會代碼'], 'query' => ['terms' => ['候選人代碼' => $chunk]],
    ]));
    foreach ($r->hits->hits ?? [] as $h) {
        $candidate_info[$h->_source->候選人代碼] = $h->_source;
    }
}

// 機關名稱 → [代碼, 議會代碼]：各議會機關用全名，立法院中央機關另外用名稱、舊名稱；名稱重複（對到兩個以上）的不用
$agency_by_name = [];
$add_agency = function ($name, $a) use (&$agency_by_name) {
    if (!$name) {
        return;
    }
    $agency_by_name[$name][$a->代碼] = $a;
};
$r = Elastic::dbQuery("/{$prefix}agency/_search", 'POST', json_encode(['size' => 10000, '_source' => ['代碼', '議會代碼', '名稱', '全名', '舊名稱']]));
foreach ($r->hits->hits ?? [] as $h) {
    $a = $h->_source;
    $add_agency($a->全名 ?? null, $a);
    if ($a->議會代碼 === 'ly') {
        $add_agency($a->名稱 ?? null, $a);
        $add_agency($a->舊名稱 ?? null, $a);
    }
}
$find_agency = function ($name) use ($agency_by_name) {
    $hits = $agency_by_name[$name] ?? $agency_by_name[str_replace('台', '臺', $name)] ?? [];
    return count($hits) === 1 ? reset($hits) : null;
};

// 金額文字 → 數字（「1,234,567」「(超過五年)」）；不是數字的回傳 null
$num = function ($v) {
    $v = str_replace([',', '，', ' '], '', (string) $v);
    return preg_match('/^-?\d+(\.\d+)?/', $v, $m) ? (float) $m[0] : null;
};

// 財產類別：來源的類別名稱有時黏到前一段（「珠寶、古董、字畫…:保險」「存款:基金受益憑證」），取最後一段判斷
$category_of = function ($key) {
    $parts = explode(':', $key);
    $last = end($parts) === '變動情形' && count($parts) > 1 ? $parts[count($parts) - 2] : end($parts);
    foreach ([
        '保險' => '保險', '基金受益憑證' => '基金受益憑證', '債券' => '債券', '其他有價證券' => '其他有價證券',
        '股票' => '股票', '國內上市' => '國內上市', '土地' => '土地', '建物' => '建物', '存款' => '存款', '現金' => '現金',
        '汽車' => '汽車', '船舶' => '船舶', '航空器' => '航空器', '債權' => '債權', '債務' => '債務', '事業投資' => '事業投資',
        '珠寶' => '珠寶古董字畫', '其他財產' => '珠寶古董字畫',
    ] as $needle => $category) {
        if (str_contains($last, $needle)) {
            return $category;
        }
    }
    return null;
};
// 各類加總用的金額欄位
$amount_field = [
    '存款' => ['新臺幣總額'], '現金' => ['新臺幣總額'], '股票' => ['新臺幣總額', '總額'], '債券' => ['新臺幣總額'],
    '基金受益憑證' => ['新臺幣總額'], '其他有價證券' => ['新臺幣總額', '總額'], '國內上市' => ['總額'],
    '債權' => ['餘額', '金額'], '債務' => ['餘額'], '事業投資' => ['投資金額'], '珠寶古董字畫' => ['價額'],
];

// 申報類別統一寫法
$normalize_category = function ($v) {
    $v = trim(preg_replace('/^★+/u', '', (string) $v));
    $v = str_replace(['（', '）'], ['(', ')'], $v);
    $map = ['就到職申報' => '就(到)職申報', '卸離職申報' => '卸(離)職申報'];
    return $map[$v] ?? ($v === '' ? null : $v);
};

$count = 0;
$stats = ['人物' => 0, '機關' => 0, '解析錯誤' => 0];
foreach (scandir($json_dir) as $person_dir) {
    if ($person_dir[0] === '.' || !is_dir("{$json_dir}/{$person_dir}")) {
        continue;
    }
    foreach (scandir("{$json_dir}/{$person_dir}") as $file) {
        if (!str_ends_with($file, '.json')) {
            continue;
        }
        $d = json_decode(file_get_contents("{$json_dir}/{$person_dir}/{$file}"), true);
        if (!$d) {
            continue;
        }
        if (!preg_match('/(廉政專刊第\d+期)-(\d+)\.json$/u', $file, $m)) {
            continue;
        }
        [$id, $issue, $page] = [$m[1] . '-' . $m[2], $m[1], (int) $m[2]];

        $councils = [];
        $person_code = null;
        $codes = array_values(array_unique($cand_codes[$id] ?? []));
        foreach ($codes as $c) {
            if ($info = $candidate_info[$c] ?? null) {
                $person_code ??= $info->人物代碼 ?? null;
                if ($info->議會代碼 ?? null) {
                    $councils[] = $info->議會代碼;
                }
            }
        }
        $orgs = [];
        $agency_codes = [];
        foreach ($d['服務機關紀錄'] ?? [] as $o) {
            $a = $find_agency($o['服務機關'] ?? '');
            $orgs[] = ['服務機關' => $o['服務機關'] ?? null, '職稱' => $o['職稱'] ?? null, '機關代碼' => $a->代碼 ?? null];
            if ($a) {
                $agency_codes[] = $a->代碼;
                $councils[] = $a->議會代碼;
            }
        }

        $counts = [];
        $sums = [];
        foreach ($d as $key => $items) {
            if (!is_array($items) || !$items || !isset($items[0]) || !is_array($items[0])
                || in_array($key, ['服務機關紀錄', '配偶子女'], true) || !($category = $category_of($key))) {
                continue;
            }
            $counts[$category] = ($counts[$category] ?? 0) + count($items);
            foreach ($items as $item) {
                foreach ($amount_field[$category] ?? [] as $f) {
                    if (($n = $num($item[$f] ?? null)) !== null) {
                        $sums[$category] = ($sums[$category] ?? 0) + $n;
                        break;
                    }
                }
            }
        }
        $totals = [];
        foreach (['現金', '存款', '有價證券', '債權', '債務', '事業投資'] as $k) {
            $totals[$k] = $num($d['總金額'][$k] ?? null);
        }

        $doc = [
            '代碼'         => $id,
            '議會代碼'     => array_values(array_unique($councils)),
            '姓名'         => $d['申報人姓名'] ?? $person_dir,
            '人物代碼'     => $person_code,
            '候選人代碼'   => $codes,
            '申報日'       => $d['申報日'] ?? null,
            '出版日'       => $published[$id] ?? null,
            '種類'         => $d['種類'] ?? null,
            '申報類別'     => $normalize_category($d['申報類別'] ?? ''),
            '原始申報類別' => $d['申報類別'] ?? null,
            '服務機關紀錄' => $orgs,
            '機關代碼'     => array_values(array_unique($agency_codes)),
            '配偶子女'     => $d['配偶子女'] ?? [],
            '總金額'       => $totals,
            '原始總金額'   => $d['總金額'] ?? null,
            '各類筆數'     => $counts ?: null,
            '各類金額'     => $sums ?: null,
            '變動期間'     => $d['變動期間'] ?? null,
            '受託人'       => is_string($d['受託人'] ?? null) ? $d['受託人'] : null,
            '備註'         => $d['備註'] ?? null,
            '解析錯誤'     => !empty($d['errors']),
            '來源'         => ['刊物' => $issue, '頁碼' => $page, '官方網址' => ($issue_url[$issue] ?? $issue_list_url) . ($issue_url[$issue] ?? null ? "#page={$page}" : '')],
        ];
        Elastic::dbBulkInsert('declaration', $id, $doc);
        $count++;
        $stats['人物'] += $person_code ? 1 : 0;
        $stats['機關'] += $agency_codes ? 1 : 0;
        $stats['解析錯誤'] += $doc['解析錯誤'] ? 1 : 0;
    }
}
Elastic::dbBulkCommit('declaration');
error_log(sprintf('Done. %d declarations（對到人物 %d、對到機關 %d、來源標記解析錯誤 %d）', $count, $stats['人物'], $stats['機關'], $stats['解析錯誤']));
