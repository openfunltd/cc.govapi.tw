<?php

class CCAPI_Type_Councilor extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議員';
    }

    /**
     * ES Document ID：{議會代碼}-{屆次}-{姓名}（例：tpe-14-王大明）
     * 路徑：/{cc_code}/{term}/{name}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '議會代碼' => [
                'path_name' => 'cc_code',
                'type' => 'string',
                'example' => 'tpe',
            ],
            '屆次' => [
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
            '屆次' => [
                'es_field' => '屆次',
                'description' => '屆期（例: 13）',
                'type' => 'integer',
            ],
            '屆代碼' => [
                'es_field' => '屆代碼',
                'description' => '屆代碼（例: tpe-14）',
                'type' => 'string',
            ],
            '姓名' => [
                'es_field' => '姓名.keyword',
                'description' => '議員姓名',
                'type' => 'string',
            ],
            '職稱' => [
                'es_field' => '職稱',
                'description' => '職稱（例: 議長、副議長、議員）',
                'type' => 'string',
            ],
            '性別' => [
                'es_field' => '性別',
                'description' => '性別',
                'type' => 'string',
                'enum' => ['男', '女'],
            ],
            '黨籍' => [
                'es_field' => '黨籍.keyword',
                'description' => '政黨名稱',
                'type' => 'string',
            ],
            '區域' => [
                'es_field' => '區域.keyword',
                'description' => '選區／區域名稱',
                'type' => 'string',
            ],
            '身分別' => [
                'es_field' => '身分別',
                'description' => '身分別',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['姓名', '黨籍', '區域', '簡歷'];
    }

    public static function sortFields()
    {
        return ['屆次>', '姓名<'];
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
