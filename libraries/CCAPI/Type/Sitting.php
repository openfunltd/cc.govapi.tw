<?php

class CCAPI_Type_Sitting extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '場次';
    }

    /**
     * ES Document ID：{代碼}（例：tpe-14-r7-20220408-am）
     * 路徑：/sitting/{代碼}（代碼含連字符，以 rawurlencode 傳入）
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'tpe-14-r7-20220408-am',
            ],
        ];
    }

    public static function getFieldMap()
    {
        return [];
    }

    public static function getFilterFieldsInfo(): array
    {
        return [
            '議會代碼' => [
                'es_field' => '議會代碼',
                'description' => '議會代碼（例: tpe）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（例: 14）',
                'type' => 'integer',
            ],
            '會期代碼' => [
                'es_field' => '會期代碼',
                'description' => '所屬會期代碼（例: tpe-14-r7）',
                'type' => 'string',
            ],
            '日期' => [
                'es_field' => '日期',
                'description' => '場次日期（例: 2022-04-08）',
                'type' => 'string',
            ],
            '時段' => [
                'es_field' => '時段',
                'description' => '上午 / 下午 / 無（全天一筆）',
                'type' => 'string',
                'enum' => ['上午', '下午'],
            ],
            '場次類別' => [
                'es_field' => '場次類別',
                'description' => '大會 / 委員會審查 / 分組審查 / 全體審查 / 停會',
                'type' => 'string',
                'enum' => ['大會', '委員會審查', '分組審查', '全體審查', '停會'],
            ],
            '委員會名稱' => [
                'es_field' => '委員會名稱.keyword',
                'description' => '審查委員會名稱，非大會時使用（例: 第一審查委員會）',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['議程說明'];
    }

    public static function sortFields()
    {
        return ['日期<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'sittings';
    }
}
