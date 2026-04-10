<?php

class CCAPI_Type_Term extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '屆';
    }

    /**
     * ES Document ID：{議會代碼}-{屆次}（例：tpe-13）
     * 路徑：/term/{cc_code}/{term}
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
                'description' => '屆次（例: 13）',
                'type' => 'integer',
            ],
            '現任' => [
                'es_field' => '現任',
                'description' => '是否為現任屆期（true/false）',
                'type' => 'boolean',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['備註'];
    }

    public static function sortFields()
    {
        return ['屆次<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'terms';
    }
}
