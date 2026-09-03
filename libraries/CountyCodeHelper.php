<?php

/**
 * 縣市名稱 → ccapi 議會代碼對照表，供各匯入腳本共用（候選人/議程/發言等來源用「縣市」
 * 中文名稱標示所屬議會，不是議會代碼本身，需要這份對照表換算）
 *
 * 桃園縣是唯一的歷史特例：2014 年桃園縣改制為桃園市，改制前的舊「桃園縣」資料要對應到
 * 已廢止的 tao-1952，不能跟現在的 tao（桃園市）混在一起
 *
 * 這份對照表原本在多個 import 腳本裡各自複製一份，曾經因為其中一份手誤把嘉義縣/市寫反
 * 而沒被發現（其餘來源改用真正的議會代碼後才對照出來），改成共用單一檔案，之後要修正
 * 只需要改一個地方
 */
class CountyCodeHelper
{
    protected static $map = [
        '臺北市' => 'tpe', '新北市' => 'nwt', '臺中市' => 'txg', '臺南市' => 'tnn',
        '高雄市' => 'khh', '桃園市' => 'tao', '宜蘭縣' => 'ila', '新竹縣' => 'hsq',
        '新竹市' => 'hsz', '基隆市' => 'kee', '苗栗縣' => 'mia', '彰化縣' => 'cha',
        '南投縣' => 'nan', '雲林縣' => 'yun', '嘉義縣' => 'cyq', '嘉義市' => 'cyi',
        '屏東縣' => 'pif', '臺東縣' => 'ttt', '花蓮縣' => 'hua', '澎湖縣' => 'pen',
        '金門縣' => 'kin', '連江縣' => 'lie',
        '桃園縣' => 'tao-1952',
    ];

    public static function getCode($county)
    {
        return self::$map[$county] ?? null;
    }

    public static function getMap()
    {
        return self::$map;
    }
}
