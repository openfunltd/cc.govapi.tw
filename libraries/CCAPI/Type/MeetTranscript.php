<?php

class CCAPI_Type_MeetTranscript extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '會議逐字稿';
    }

    /**
     * meet 底下的逐句發言，與 meet 多對一（透過「會議代碼」）。取代舊的
     * speech（透過議程代碼連 sitting_agenda 那條pipeline）。
     * ES Document ID：{代碼}（例：tpe-8320eaa898-0）
     * 路徑：/meet_transcript/{代碼}
     */
    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => 'tpe-8320eaa898-0',
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
                'description' => '屆次（從場次代碼查詢既有場次資料取得，場次代碼缺值時這個欄位也會缺值）',
                'type' => 'integer',
            ],
            '場次代碼' => [
                'es_field' => '場次代碼',
                'description' => '所屬場次代碼',
                'type' => 'string',
            ],
            '會議代碼' => [
                'es_field' => '會議代碼',
                'description' => '所屬會議代碼，對應 meet 的「代碼」',
                'type' => 'string',
            ],
            '對應代碼' => [
                'es_field' => '對應代碼',
                'description' => '發言者對應到的代碼；身分類別為「議員」時對應 councilor 的「代碼」欄位',
                'type' => 'string',
            ],
            '對應代碼類型' => [
                'es_field' => '對應代碼類型',
                'description' => '「對應代碼」的類型（例: 議員、機關）',
                'type' => 'string',
            ],
            '身分類別' => [
                'es_field' => '身分類別',
                'description' => '發言者身分（例: 主席、議員、政府機關首長）',
                'type' => 'string',
            ],
            '日期' => [
                'es_field' => '日期',
                'description' => '發言當天日期',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['發言內容'];
    }

    public static function sortFields()
    {
        return ['順序<'];
    }

    /**
     * 單一場會議可能有數百筆逐句發言，list 預設筆數故意偏小
     */
    public static function defaultLimit()
    {
        return 20;
    }

    public static function getReturnKey()
    {
        return 'meet_transcripts';
    }
}
