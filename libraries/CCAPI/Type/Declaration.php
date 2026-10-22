<?php

class CCAPI_Type_Declaration extends CCAPI_Type
{
    public static function getTypeSubject()
    {
        return '財產申報';
    }

    public static function getIdFieldsInfo()
    {
        return [
            '代碼' => [
                'path_name' => 'code',
                'type' => 'string',
                'example' => '廉政專刊第136期-254',
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
                'description' => '申報人參選或任職的機關所屬議會代碼（例: tpe）；一份申報可能屬於好幾個議會',
                'type' => 'string',
            ],
            '人物代碼' => [
                'es_field' => '人物代碼',
                'description' => '申報人的人物代碼（跟候選人資料同一套，依申報資料記載的參選人候選人代碼對應）',
                'type' => 'string',
            ],
            '姓名' => [
                'es_field' => '姓名.keyword',
                'description' => '申報人姓名',
                'type' => 'string',
            ],
            '種類' => [
                'es_field' => '種類',
                'description' => '申報種類',
                'type' => 'string',
                'enum' => ['財產申報', '信託財產申報', '變動財產申報', '信託財產管理或處分指示通知'],
            ],
            '申報類別' => [
                'es_field' => '申報類別',
                'description' => '申報類別（例: 定期申報、就(到)職申報、卸(離)職申報、新增信託財產申報、代理申報）',
                'type' => 'string',
            ],
            '機關代碼' => [
                'es_field' => '機關代碼',
                'description' => '申報時服務機關對應到的行政機關代碼（例: ly-A13000000G）',
                'type' => 'string',
            ],
        ];
    }

    public static function queryFields()
    {
        return ['姓名', '服務機關紀錄.服務機關', '服務機關紀錄.職稱'];
    }

    public static function sortFields()
    {
        return ['申報日>'];
    }

    public static function defaultLimit()
    {
        return 100;
    }

    public static function getReturnKey()
    {
        return 'declarations';
    }
}
