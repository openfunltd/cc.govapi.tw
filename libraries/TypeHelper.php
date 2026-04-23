<?php

class TypeHelper
{
    public static function getTypeConfig()
    {
        return [
            'councilor' => [
                'name' => '議員',
                'icon' => 'fas fa-fw fa-user-tie',
                'cols' => [
                    'all' => [
                        '議會代碼',
                        '屆次',
                        '姓名',
                        '職稱',
                        '黨籍',
                        '區域',
                        '性別',
                    ],
                    'single' => [
                        '屆次',
                        '姓名',
                        '職稱',
                        '黨籍',
                        '性別',
                    ],
                ],
                'default_aggs' => [
                    'all' => [
                        '議會代碼',
                        '職稱',
                        '黨籍',
                    ],
                    'single' => [
                        '職稱',
                        '黨籍',
                        '性別',
                    ],
                ],
                'item_features' => [
                    'data' => '議員資料',
                ],
            ],
            'term' => [
                'name' => '屆',
                'icon' => 'fas fa-fw fa-calendar-alt',
                'cols' => [
                    '議會代碼',
                    '屆次',
                    '投票日',
                    '就職日',
                    '任期屆滿日',
                    '現任',
                ],
                'default_aggs' => [
                    '議會代碼',
                    '現任',
                ],
                'item_features' => [
                    'data' => '屆期資料',
                ],
            ],
            'committee' => [
                'name' => '委員會',
                'icon' => 'fas fa-fw fa-users',
                'cols' => [
                    'all' => [
                        '議會代碼',
                        '名稱',
                        '類別',
                    ],
                    'single' => [
                        '名稱',
                        '類別',
                    ],
                ],
                'default_aggs' => [
                    'all' => [
                        '議會代碼',
                        '類別',
                    ],
                    'single' => [
                        '類別',
                    ],
                ],
                'item_features' => [
                    'data' => '委員會資料',
                ],
            ],
            'council' => [
                'name' => '議會',
                'icon' => 'fas fa-fw fa-landmark',
                'cols' => [
                    '代碼',
                    '議會名稱',
                    '議會類別',
                    '現存',
                    '最新屆期代碼',
                ],
                'default_aggs' => [
                    '議會類別',
                    '現存',
                ],
                'item_features' => [
                    'data' => '議會資料',
                ],
            ],
            'session' => [
                'name' => '會期',
                'icon' => 'fas fa-fw fa-calendar-week',
                'cols' => [
                    'all' => [
                        '議會代碼',
                        '屆',
                        '會期名稱',
                        '會期類別',
                        '次',
                        '開始日期',
                        '結束日期',
                    ],
                    'single' => [
                        '屆',
                        '會期名稱',
                        '會期類別',
                        '次',
                        '開始日期',
                        '結束日期',
                    ],
                ],
                'default_aggs' => [
                    'all' => [
                        '議會代碼',
                        '會期類別',
                        '屆',
                    ],
                    'single' => [
                        '會期類別',
                        '屆',
                    ],
                ],
                'item_features' => [
                    'data' => '會期資料',
                ],
            ],
        ];
    }

    public static function getColumns($type)
    {
        $config = self::getTypeConfig();
        $cols = $config[$type]['cols'] ?? [];
        // 支援依全國/單一議會回傳不同欄位
        if (isset($cols['all'])) {
            $is_all = (CouncilHelper::getCurrentCode() === 'all');
            return $is_all ? $cols['all'] : $cols['single'];
        }
        return $cols;
    }

    public static function getDataColumn($type)
    {
        $type = str_replace('_', '', $type);
        return $type . 's';
    }

    public static function getDataByID($type, $id)
    {
        return CCAPI::apiQuery('/' . $type . '/' . rawurlencode($id), "抓取 {$type} 的 {$id} 資料");
    }

    public static function getData($data, $type)
    {
        return $data->{self::getDataColumn($type)} ?? [];
    }

    public static function getApiUrl($type)
    {
        $host = getenv('CCAPI_HOST') ?: 'all.cc.govapi.tw';
        return 'https://' . $host . '/' . $type . 's';
    }

    public static function getCurrentFilter()
    {
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        $terms = explode('&', $query_string);
        $filter = [];
        foreach ($terms as $term) {
            if (strpos($term, '=') === false) {
                continue;
            }
            list($k, $v) = array_map('urldecode', explode('=', $term, 2));
            if ($k === 'filter') {
                $filter[] = explode(':', $v, 2);
            }
        }
        return $filter;
    }

    public static function getCurrentAgg($type)
    {
        $config = self::getTypeConfig();
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        $terms = explode('&', $query_string);
        $agg = [];
        foreach ($terms as $term) {
            if (strpos($term, '=') === false) {
                continue;
            }
            list($k, $v) = array_map('urldecode', explode('=', $term, 2));
            if ($k === 'agg') {
                $agg[] = $v;
            }
        }
        if ($agg) {
            return $agg;
        }
        $default = $config[$type]['default_aggs'] ?? [];
        // 支援依全國/單一議會回傳不同預設 agg
        if (isset($default['all'])) {
            $is_all = (CouncilHelper::getCurrentCode() === 'all');
            return $is_all ? $default['all'] : $default['single'];
        }
        return $default;
    }

    public static function getRecordList($data, $prefix = '')
    {
        if (is_scalar($data)) {
            return [[
                'key' => rtrim($prefix, '.'),
                'value' => $data,
            ]];
        }
        if (is_array($data)) {
            $ret = [];
            foreach ($data as $idx => $item) {
                $ret = array_merge(
                    $ret,
                    self::getRecordList($item, rtrim($prefix, '.') . "[{$idx}].")
                );
            }
            return $ret;
        }
        $ret = [];
        foreach ($data as $k => $v) {
            $ret = array_merge(
                $ret,
                self::getRecordList($v, "{$prefix}{$k}.")
            );
        }
        return $ret;
    }

    public static function getItemFeatures($type)
    {
        $config = self::getTypeConfig();
        $features = $config[$type]['item_features'] ?? [];
        $features['rawdata'] = '原始資料';
        return $features;
    }

    public static function getCollectionFeatures($type)
    {
        $config = self::getTypeConfig();
        $features = $config[$type]['collection_features'] ?? [];
        $features['table'] = '列表';
        return $features;
    }
}
