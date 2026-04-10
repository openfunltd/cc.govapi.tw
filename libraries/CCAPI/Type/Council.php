<?php

class CCAPI_Type_Council extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議會';
    }

    /**
     * council 的 ES 議會代碼欄位名稱為 '代碼'（來自議會.csv 主鍵欄）
     */
    public static function getCCCodeField()
    {
        return '代碼';
    }

    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'cc_code',
                'type' => 'string',
                'example' => 'tpe',
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
            '代碼' => [
                'es_field' => '代碼',
                'description' => '議會代碼（例: tpe）',
                'type' => 'string',
            ],
            '議會類別' => [
                'es_field' => '議會類別',
                'description' => '議會類別',
                'type' => 'string',
                'enum' => ['直轄市議會', '縣（市）議會'],
            ],
            '現存' => [
                'es_field' => '現存',
                'description' => '是否為現存議會（true/false）',
                'type' => 'boolean',
            ],
            'ISO碼' => [
                'es_field' => 'ISO碼',
                'description' => 'ISO 3166-2 代碼（例: TW-TPE）',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['議會名稱'];
    }

    public static function sortFields()
    {
        return ['生效日期<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'councils';
    }
}
