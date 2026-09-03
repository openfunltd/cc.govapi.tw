<?php

class CCAPI_Type_SittingAgenda extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議程';
    }

    /**
     * ES Document ID：{代碼}（例：kin-9cbbb468fe）
     * 路徑：/sitting_agenda/{代碼}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'kin-9cbbb468fe',
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
                'description' => '所屬場次代碼（部分議程沒有對應到明確的場次，可能是空的）',
                'type' => 'string',
            ],
            '會期代碼' => [
                'es_field' => '會期代碼',
                'description' => '所屬會期代碼（從場次代碼查詢既有場次資料取得）',
                'type' => 'string',
            ],
            '議程類型' => [
                'es_field' => '議程類型',
                'description' => '議程類型（例: 部門質詢分組、委員會分組審查、市政總質詢等）',
                'type' => 'string',
            ],
            '委員會或名稱' => [
                'es_field' => '委員會或名稱',
                'description' => '所屬委員會或分組名稱（大會層級的議程通常是空的）',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['委員會或名稱', '質詢對象機關'];
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
        return 'sitting_agendas';
    }
}
