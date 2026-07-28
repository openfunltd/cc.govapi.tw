<?php

class CCAPI_Type_Session extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '會期';
    }

    /**
     * ES Document ID：{代碼}（例：nan-18-r1）
     * 路徑：/session/{代碼}（代碼含連字符，以 rawurlencode 傳入）
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'nan-18-r1',
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
                'description' => '議會代碼（例: nan）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（例: 18）',
                'type' => 'integer',
            ],
            '會期類別' => [
                'es_field' => '會期類別',
                'description' => '定期會 / 臨時會 / 成立大會',
                'type' => 'string',
            ],
            '次' => [
                'es_field' => '次',
                'description' => '會期次數（例: 1）',
                'type' => 'integer',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['會期名稱', '議會名稱'];
    }

    public static function sortFields()
    {
        return ['開始日期<'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'sessions';
    }

    /**
     * 會期代碼格式為 {議會代碼}-{屆次}-{類別縮寫}{次別}（例：tpe-14-r8、nwt-4-e2、nwt-4-i），
     * 這是彙整腳本統一產生的命名規則（已核對全部 21 個有會期資料的議會、1965 筆代碼皆符合，
     * r=定期會、e=臨時會、i=成立大會 對應完全一致，非各縣市各自的格式，可放心解析）。
     * 用來在會期紀錄還沒建檔、但場次已公告時，組出跟正式「會期名稱」同樣格式的友善名稱。
     */
    public static function getFriendlyName($session_code)
    {
        if (!preg_match('/^[a-z]+-(\d+)-([a-z]+)(\d*)$/', $session_code, $m)) {
            return '本次會期';
        }
        [, $term_no, $type_code, $no] = $m;
        $type = ['r' => '定期會', 'e' => '臨時會', 'i' => '成立大會'][$type_code] ?? '會期';
        return $no !== '' ? "第{$term_no}屆第{$no}次{$type}" : "第{$term_no}屆{$type}";
    }
}
