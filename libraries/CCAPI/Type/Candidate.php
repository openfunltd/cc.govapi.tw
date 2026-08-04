<?php

class CCAPI_Type_Candidate extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '候選人';
    }

    /**
     * ES Document ID：{代碼}（衍生欄位，見 import-candidate.php 說明）
     * 路徑：/candidate/{代碼}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'ELC-T1-99:63000-1:1',
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
                'description' => '議會代碼（例: tpe），從縣市名稱解析；查不到對應議會時沒有這個欄位',
                'type' => 'string',
            ],
            '年份' => [
                'es_field' => '年份',
                'description' => '選舉年份（民國年，例: 111）',
                'type' => 'integer',
            ],
            '縣市' => [
                'es_field' => '縣市.keyword',
                'description' => '候選人所屬縣市（來源原始名稱，較舊資料可能是改制前的舊縣名，例: 桃園縣）',
                'type' => 'string',
            ],
            '姓名' => [
                'es_field' => '姓名.keyword',
                'description' => '候選人姓名（少數是依名單補正過的，原文見 note）',
                'type' => 'string',
            ],
            '候選人代碼' => [
                'es_field' => '候選人代碼',
                'description' => '對應中選會選舉資料庫的候選人代碼，可跟 councilor 的「參選代碼」／「人物代碼」比對是否當選；查無此代碼代表沒有比對到候選人名單（仍有學經歷/政見等內容，只是不能 join）',
                'type' => 'string',
            ],
            '選舉代碼' => [
                'es_field' => '選舉代碼',
                'description' => '選舉代碼（例: ELC-T1-111）。要定位同一場選舉的所有候選人，需搭配「行政區代碼」「選區別」一起查（單一縣市可能有多個選舉區）',
                'type' => 'string',
            ],
            '行政區代碼' => [
                'es_field' => '行政區代碼',
                'description' => '候選人所屬縣市的行政區代碼（例: 63000）',
                'type' => 'string',
            ],
            '選區別' => [
                'es_field' => '選區別',
                'description' => '選舉區名稱（例: 第01選舉區），同一個縣市通常有多個同名選舉區跨不同選舉代碼，定位選區請用「選舉代碼＋行政區代碼＋選區別」三個欄位一起查',
                'type' => 'string',
            ],
            'code_match' => [
                'es_field' => 'code_match',
                'description' => '候選人代碼比對方式，代表可信度：exact／name_only／subsequence／cjk（皆為高或中高可信度），沒有這個欄位代表沒比對到',
                'type' => 'string',
                'enum' => ['exact', 'name_only', 'subsequence', 'cjk'],
            ],
            '政見來源' => [
                'es_field' => '政見來源',
                'description' => '「政見」欄位內容是否可用：text=可用；text-garbled=文字層抽出來是亂碼、不可當文字用；沒有這個欄位代表政見是圖片或空白（見「政見圖路徑」）',
                'type' => 'string',
                'enum' => ['text', 'text-garbled'],
            ],
            '得票排名' => [
                'es_field' => '得票排名',
                'description' => '候選人在自己選區的得票排名（1 為最高票）；只有縣市議員/直轄市議員的得票資料涵蓋這個欄位，其餘沒有這個欄位',
                'type' => 'integer',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['姓名', '學歷', '經歷', '政見'];
    }

    public static function sortFields()
    {
        return ['年份>'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'candidates';
    }
}
