<?php

class CCAPI_Type_Council extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議會';
    }

    public static function getIdFieldsInfo()
    {
        return [
            '議會代碼' => [
                'path_name' => 'cc_code',
                'type' => 'string',
                'example' => 'tpe',
            ],
        ];
    }

    public static function getFieldMap()
    {
        return [
            'cc_code'       => '議會代碼',
            'name'          => '議會名稱',
            'type'          => '議會類別',
            'moi_code'      => '內政部行政區代碼',
            'iso_code'      => 'ISO碼',
            'start_date'    => '生效日期',
            'end_date'      => '廢止日期',
            'wikipedia_url' => '維基條目',
            'wikidata_id'   => 'Wikidata',
            'is_active'     => '現存',
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
            '議會類別' => [
                'es_field' => 'type',
                'description' => '議會類別',
                'type' => 'string',
                'enum' => ['直轄市議會', '縣（市）議會'],
            ],
            '現存' => [
                'es_field' => 'is_active',
                'description' => '是否為現存議會（true/false）',
                'type' => 'boolean',
            ],
            'ISO碼' => [
                'es_field' => 'iso_code',
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
