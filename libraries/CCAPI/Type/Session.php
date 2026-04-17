<?php

class CCAPI_Type_Session extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '會期';
    }

    /**
     * ES Document ID：{代碼}（例：nan-18-r1）
     * 路徑：/session/{代碼}（代碼含連字符，以 rawurlencode 傳入）
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'nan-18-r1',
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
                'description' => '議會代碼（例: nan）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（例: 18）',
                'type' => 'integer',
            ],
            '會期類別' => [
                'es_field' => '會期類別',
                'description' => '定期會 / 臨時會 / 成立大會',
                'type' => 'string',
            ],
            '次' => [
                'es_field' => '次',
                'description' => '會期次數（例: 1）',
                'type' => 'integer',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['會期名稱', '議會名稱'];
    }

    public static function sortFields()
    {
        return ['開始日期<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'sessions';
    }
}
