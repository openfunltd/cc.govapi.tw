<?php

class CCAPI_Type_Agency extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '行政機關';
    }

    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'tpe-379020000A',
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
                'description' => '監督這個機關的議會代碼（例: tpe）',
                'type' => 'string',
            ],
            '分類' => [
                'es_field' => '分類',
                'description' => '縣市政府／一級內部單位（縣市政府的內部單位）／一級外部單位（縣市政府直屬的獨立機關）／二級機關／其他單位（學校、戶政所、區公所等）；立法院另有院（總統府、國家安全會議與各院）、國營事業',
                'type' => 'string',
                'enum' => ['縣市政府', '一級內部單位', '一級外部單位', '二級機關', '其他單位', '院', '國營事業'],
            ],
            '機關代碼' => [
                'es_field' => '機關代碼',
                'description' => '行政院人事行政總處機關代碼（例: 379020000A），跟會議逐字稿的「對應代碼」同一套',
                'type' => 'string',
            ],
            '上層代碼' => [
                'es_field' => '上層代碼',
                'description' => '上層機關的代碼（二級機關才有）',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['名稱', '全名'];
    }

    public static function sortFields()
    {
        return ['排序<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'agencies';
    }
}
