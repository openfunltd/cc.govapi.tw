<?php

/**
 * 把各型別的 Elasticsearch 資料匯出成 JSONL，供打包成開放資料集（HuggingFace）
 *
 * 用法：php scripts/export-dataset.php {輸出目錄} [型別,型別...]
 *
 * - 內容跟 API 回傳一致（各型別 getFieldMap() 皆為空，API 直接輸出 _source）
 * - 隱藏議會（CCAPI_Council::getHiddenCodesForType()）不匯出，跟 all 查詢的規則相同
 * - 資料量大的型別依議會代碼分檔：{輸出目錄}/{型別}/{議會代碼}.jsonl，其餘為 {型別}/data.jsonl
 * - 同時輸出 {型別}/fields.json：每個欄位的有值筆數、JSON 型態，以及 API 文件裡的欄位說明，
 *   給後續產生資料集說明文件用
 */

include(__DIR__ . '/../init.inc.php');

$types = [
    'council' => false,
    'term' => false,
    'councilor' => false,
    'session' => false,
    'sitting' => false,
    'committee' => false,
    'bill' => true,
    'meet' => false,
    'meet_note' => false,
    'meet_transcript' => true,
    'candidate' => false,
    'agency' => false,
];

$out_dir = $argv[1] ?? null;
if (!$out_dir) {
    fwrite(STDERR, "用法：php scripts/export-dataset.php {輸出目錄} [型別,型別...]\n");
    exit(1);
}
if (isset($argv[2])) {
    $types = array_intersect_key($types, array_flip(explode(',', $argv[2])));
}

function json_type($v)
{
    if (is_null($v)) return 'null';
    if (is_bool($v)) return 'boolean';
    if (is_int($v)) return 'integer';
    if (is_float($v)) return 'number';
    if (is_string($v)) return 'string';
    if (is_array($v)) return 'array';
    return 'object';
}

foreach ($types as $type => $split_by_council) {
    $class = 'CCAPI_Type_' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
    $cc_field = $class::getCCCodeField();
    $hidden = CCAPI_Council::getHiddenCodesForType($type);
    $type_dir = "{$out_dir}/{$type}";
    if (!is_dir($type_dir)) {
        mkdir($type_dir, 0755, true);
    }
    foreach (glob("{$type_dir}/*.jsonl") as $f) {
        unlink($f);
    }

    $query = $hidden ? ['bool' => ['must_not' => [['terms' => [$cc_field => $hidden]]]]] : ['match_all' => new StdClass];
    $res = Elastic::dbQuery("/{prefix}{$type}/_search?scroll=5m", 'POST', json_encode([
        'size' => 5000,
        'query' => $query,
        'sort' => ['_doc'],
    ]));

    $fps = [];
    $fields = [];
    $total = 0;
    while ($res->hits->hits) {
        foreach ($res->hits->hits as $hit) {
            $doc = $hit->_source;
            $file = $split_by_council ? ($doc->{$cc_field} ?? '_unknown') : 'data';
            if (!isset($fps[$file])) {
                $fps[$file] = fopen("{$type_dir}/{$file}.jsonl", 'w');
            }
            fwrite($fps[$file], json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            foreach ($doc as $k => $v) {
                $fields[$k]['count'] = ($fields[$k]['count'] ?? 0) + (is_null($v) ? 0 : 1);
                $fields[$k]['types'][json_type($v)] = true;
            }
            $total++;
        }
        $res = Elastic::dbQuery('/_search/scroll', 'POST', json_encode(['scroll' => '5m', 'scroll_id' => $res->_scroll_id]));
    }
    foreach ($fps as $fp) {
        fclose($fp);
    }

    $descriptions = [];
    foreach ($class::getFilterFieldsInfo() as $name => $info) {
        $descriptions[$info['es_field'] ?? $name] = $info['description'] ?? '';
    }
    foreach ($fields as $k => $info) {
        $fields[$k]['types'] = array_keys($info['types']);
        $fields[$k]['description'] = $descriptions[$k] ?? '';
    }
    file_put_contents("{$type_dir}/fields.json", json_encode([
        'type' => $type,
        'subject' => $class::getTypeSubject(),
        'endpoint' => $class::getReturnKey(),
        'total' => $total,
        'files' => array_keys($fps),
        'fields' => $fields,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "{$type}: {$total} 筆、" . count($fps) . " 個檔案\n";
}
