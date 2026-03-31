<?php

class CCAPI_Type_Councilor extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議員';
    }

    /**
     * ES Document ID 慣例：{cc_code}-{term}-{name}
     * Composite ID 路徑：/{cc_code}/{term}/{name}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '議會代碼' => [
                'path_name' => 'cc_code',
                'type' => 'string',
                'example' => 'tpe',
            ],
            '屆' => [
                'path_name' => 'term',
                'type' => 'integer',
                'example' => 13,
            ],
            '姓名' => [
                'path_name' => 'name',
                'type' => 'string',
                'example' => '王大明',
            ],
        ];
    }

    public static function getFieldMap()
    {
        return (object)[
            'cc_code'       => '議會代碼',
            'term'          => '屆',
            'name'          => '姓名',
            'gender'        => '性別',
            'party'         => '黨籍',
            'constituency'  => '選區名稱',
            'onboard_date'  => '就任日',
            'leave_date'    => '離職日',
            'leave_reason'  => '離職原因',
            'pic_url'       => '照片位址',
            'bio'           => '簡歷',
            'tel'           => '電話',
            'addr'          => '通訊處',
            'email'         => '電子信箱',
            'website'       => '個人網站',
        ];
    }

    public static function getFilterFieldsInfo(): array
    {
        return [
            '議會代碼' => [
                'es_field' => 'cc_code',
                'description' => '議會代碼（例: tpe）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => 'term',
                'description' => '屆期（例: 13）',
                'type' => 'integer',
            ],
            '姓名' => [
                'es_field' => 'name.keyword',
                'description' => '議員姓名',
                'type' => 'string',
            ],
            '性別' => [
                'es_field' => 'gender',
                'description' => '性別',
                'type' => 'string',
                'enum' => ['男', '女'],
            ],
            '黨籍' => [
                'es_field' => 'party.keyword',
                'description' => '政黨名稱',
                'type' => 'string',
            ],
            '選區名稱' => [
                'es_field' => 'constituency.keyword',
                'description' => '選區名稱',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['姓名', '黨籍', '選區名稱', '簡歷'];
    }

    public static function sortFields()
    {
        return ['屆>', '姓名<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'councilors';
    }
}
