<?php

class CouncilHelper
{
    protected static $councils = [
        'all'  => '全國（跨議會查詢）',
        'tpe'  => '臺北市議會',
        'nwt'  => '新北市議會',
        'txg'  => '臺中市議會',
        'tnn'  => '臺南市議會',
        'khh'  => '高雄市議會',
        'tao'  => '桃園市議會',
        'ila'  => '宜蘭縣議會',
        'hsq'  => '新竹縣議會',
        'hsz'  => '新竹市議會',
        'mia'  => '苗栗縣議會',
        'cha'  => '彰化縣議會',
        'nan'  => '南投縣議會',
        'yun'  => '雲林縣議會',
        'cyi'  => '嘉義縣議會',
        'cyq'  => '嘉義市議會',
        'pif'  => '屏東縣議會',
        'ttt'  => '臺東縣議會',
        'hua'  => '花蓮縣議會',
        'pen'  => '澎湖縣議會',
        'kin'  => '金門縣議會',
        'lie'  => '連江縣議會',
    ];

    public static function getAll()
    {
        return self::$councils;
    }

    /**
     * 取得目前的議會代碼（從 ccapi dispatch 設定的 CCAPI_COUNCIL_CODE 讀取）
     */
    public static function getCurrentCode()
    {
        return $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
    }

    /**
     * 取得切換到某個議會的 URL（保留目前 viewer path）
     */
    public static function getSwitchUrl($code)
    {
        $postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
        $path = strtok($_SERVER['REQUEST_URI'] ?? '/viewer', '?');
        return 'https://' . $code . $postfix . $path;
    }

    public static function getName($code)
    {
        return self::$councils[$code] ?? $code;
    }
}
