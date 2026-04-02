<?php

class CCAPI_Type_Term extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '屆';
    }

    /**
     * ES Document ID：{cc_code}-{term}（例：tpe-13）
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
        return [
            'cc_code'    => '議會代碼',
            'term'       => '屆次',
            'start_date' => '就職日',
            'end_date'   => '任期屆滿日',
            'is_current' => '現任',
            'note'       => '備註',
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
            '屆次' => [
                'es_field' => 'term',
                'description' => '屆次（例: 13）',
                'type' => 'integer',
            ],
            '現任' => [
                'es_field' => 'is_current',
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
