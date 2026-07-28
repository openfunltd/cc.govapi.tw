<?php

class CCAPI_Type_Transcript extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '逐字稿';
    }

    /**
     * 與場次（sitting）一對一，ES Document ID 沿用場次代碼（例：tpe-14-r7-20220408-am）
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'tpe-14-r7-20220408-am',
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
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（例: 14）',
                'type' => 'integer',
            ],
            '會期代碼' => [
                'es_field' => '會期代碼',
                'description' => '所屬會期代碼（例: tpe-14-r7）',
                'type' => 'string',
            ],
            '來源分類' => [
                'es_field' => '來源分類',
                'description' => '逐字稿內容來源（例: 議事錄、速記錄、大會會議紀錄），一筆可能包含多種來源',
                'type' => 'string',
            ],
        ];
    }

    /**
     * 全文搜尋以「內容」欄位為主，命中時搭配 ES highlight 回傳前後文片段，
     * 避免每次搜尋都要把整份逐字稿（可能數十 KB 以上）塞進列表回應。
     */
    public static function queryFields()
    {
        return ['內容'];
    }

    public static function sortFields()
    {
        return [];
    }

    /**
     * 單筆內容可能很大，list 預設筆數故意偏小
     */
    public static function defaultLimit()
    {
        return 10;
    }

    public static function getReturnKey()
    {
        return 'transcripts';
    }
}
