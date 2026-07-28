<?php

class CCAPI_Type_Overview extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議會現況';
    }

    /**
     * ES Document ID：{代碼}（例：tpe）
     * 路徑：/overview/{代碼}
     */
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

    /**
     * 這個型別的議會代碼欄位本身就是 '代碼'（跟 council 型別一樣）
     */
    public static function getCCCodeField()
    {
        return '代碼';
    }

    public static function getFieldMap()
    {
        return [];
    }

    public static function getFilterFieldsInfo(): array
    {
        return [
            '議會類別' => [
                'es_field' => '議會類別',
                'description' => '議會類別',
                'type' => 'string',
                'enum' => ['直轄市議會', '縣（市）議會'],
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
        return 'overviews';
    }
}
