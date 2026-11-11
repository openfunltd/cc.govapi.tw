<?php

class CCAPI_Type_Bill extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '議案';
    }

    /**
     * ES Document ID：{代碼}（例：yun-44d4ef6b-民甲200）
     * 路徑：/bill/{代碼}（代碼含連字符，以 rawurlencode 傳入）
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'yun-44d4ef6b-民甲200',
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
                'description' => '議會代碼（例: yun）',
                'type' => 'string',
            ],
            '屆' => [
                'es_field' => '屆',
                'description' => '屆次（從來源檔名解析，例: 20；較舊資料或無法解析時可能沒有這個欄位）',
                'type' => 'integer',
            ],
            '類別' => [
                'es_field' => '類別',
                'description' => '議案類別／委員會分類（各議會用詞不同，例: 民政、教育；不是所有議會都有這個欄位）',
                'type' => 'string',
            ],
            '提案單位' => [
                'es_field' => '提案單位.keyword',
                'description' => '提案的政府機關／單位（議員提案時通常是空的）',
                'type' => 'string',
            ],
            '提案人' => [
                'es_field' => '提案人姓名',
                'description' => '提案議員姓名，多人聯合提案時任一位符合即可（政府提案時通常是空的）',
                'type' => 'string',
            ],
        ];
    }

    /**
     * 提案人姓名的比對寫法：原住民族議員姓名有漢名也有羅馬拼音時只留漢名（「蔡依靜 Lamen．Panay」
     * 「Ingay Tali穎艾達利」「石慶龍Rungquan．Lhkatafatu」→ 蔡依靜／穎艾達利／石慶龍），各份文件、
     * 甚至議員名冊不同屆的寫法都不一樣，統一後同一個人才不會分成好幾個名字
     */
    public static function proposerKey($name)
    {
        $name = trim((string) $name);
        if (preg_match('/\p{Han}/u', $name) && preg_match('/[A-Za-z]/u', $name)
            && preg_match_all('/\p{Han}(?:[\p{Han}．‧·・]*\p{Han})?/u', $name, $m)) {
            usort($m[0], fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            return $m[0][0];
        }
        return $name;
    }

    public static function queryFields()
    {
        return ['案由', '說明', '辦法', '審查意見', '議決', '提案單位', '提案人'];
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
        return 'bills';
    }
}
