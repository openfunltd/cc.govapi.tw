<?php

class CCAPI_Type_Committee extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '委員會';
    }

    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'tpe-c1',
            ],
        ];
    }

    public static function getCCCodeField()
    {
        return '議會代碼';
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
            '類別' => [
                'es_field' => '類別',
                'description' => '常設 / 特種',
                'type' => 'string',
                'enum' => ['常設', '特種'],
            ],
        ];
    }

    public static function queryFields()
    {
        return ['名稱', '別稱', '職掌'];
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
        return 'committees';
    }
}
