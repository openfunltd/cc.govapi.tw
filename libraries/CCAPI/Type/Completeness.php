<?php

class CCAPI_Type_Completeness extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '完整度';
    }

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
            '現存' => [
                'es_field' => '現存',
                'description' => '是否為現存議會（true/false）',
                'type' => 'boolean',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['議會名稱'];
    }

    public static function sortFields()
    {
        return [];
    }

    public static function defaultLimit()
    {
        return 50;
    }

    public static function getReturnKey()
    {
        return 'completenesses';
    }
}
