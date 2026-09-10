<?php

class CCAPI_Type_Meet extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '會議';
    }

    /**
     * 場次（sitting）底下的實際會議，同一個場次同一天可能有好幾筆平行的meet
     * （例如分組審查）。ES Document ID：{代碼}（例：nan-abc1234567）
     * 路徑：/meet/{代碼}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'nan-abc1234567',
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
                'description' => '屆次（從場次代碼查詢既有場次資料取得，場次代碼缺值時這個欄位也會缺值）',
                'type' => 'integer',
            ],
            '場次代碼' => [
                'es_field' => '場次代碼',
                'description' => '所屬場次代碼（同一個場次代碼可能對應多筆meet，例如分組審查）',
                'type' => 'string',
            ],
            '會期代碼' => [
                'es_field' => '會期代碼',
                'description' => '所屬會期代碼（從場次代碼查詢既有場次資料取得）',
                'type' => 'string',
            ],
            '委員會或主旨' => [
                'es_field' => '委員會或主旨',
                'description' => '所屬委員會或主旨名稱（沒有分組的縣市通常是空的）',
                'type' => 'string',
            ],
            '有速記' => [
                'es_field' => '有速記',
                'description' => '這筆會議是否至少有一筆meet_notes摘要',
                'type' => 'boolean',
            ],
            '有逐字稿' => [
                'es_field' => '有逐字稿',
                'description' => '這筆會議是否至少有一筆meet_transcripts逐字稿',
                'type' => 'boolean',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['委員會或主旨'];
    }

    public static function sortFields()
    {
        return [];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'meets';
    }
}
