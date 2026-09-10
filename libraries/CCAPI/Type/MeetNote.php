<?php

class CCAPI_Type_MeetNote extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '會議摘要';
    }

    /**
     * meet 底下的摘要式速記錄，與 meet 多對一（透過「會議代碼」）。
     * ES Document ID：{代碼}（例：pif-330fa3198c-note）
     * 路徑：/meet_note/{代碼}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'pif-330fa3198c-note',
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
                'description' => '議會代碼（例: pif）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（從場次代碼查詢既有場次資料取得，場次代碼缺值時這個欄位也會缺值）',
                'type' => 'integer',
            ],
            '場次代碼' => [
                'es_field' => '場次代碼',
                'description' => '所屬場次代碼',
                'type' => 'string',
            ],
            '會議代碼' => [
                'es_field' => '會議代碼',
                'description' => '所屬會議代碼，對應 meet 的「代碼」',
                'type' => 'string',
            ],
            '日期' => [
                'es_field' => '日期',
                'description' => '會議當天日期',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['摘要內容'];
    }

    public static function sortFields()
    {
        return [];
    }

    public static function defaultLimit()
    {
        return 20;
    }

    public static function getReturnKey()
    {
        return 'meet_notes';
    }
}
