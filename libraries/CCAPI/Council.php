<?php

class CCAPI_Council
{
    /**
     * 所有議會代碼（含已廢止），key 為代碼，value 為議會名稱
     */
    protected static $councils = [
        // 現行議會
        'tpe' => '臺北市議會',
        'nwt' => '新北市議會',
        'txg' => '臺中市議會',
        'tnn' => '臺南市議會',
        'khh' => '高雄市議會',
        'tao' => '桃園市議會',
        'ila' => '宜蘭縣議會',
        'hsq' => '新竹縣議會',
        'hsz' => '新竹市議會',
        'mia' => '苗栗縣議會',
        'cha' => '彰化縣議會',
        'nan' => '南投縣議會',
        'yun' => '雲林縣議會',
        'cyi' => '嘉義縣議會',
        'cyq' => '嘉義市議會',
        'pif' => '屏東縣議會',
        'ttt' => '臺東縣議會',
        'hua' => '花蓮縣議會',
        'pen' => '澎湖縣議會',
        'kin' => '金門縣議會',
        'lie' => '連江縣議會',
        // 已廢止議會
        'tao-1952'  => '桃園縣議會',
        'tpq'       => '臺北縣議會',
        'khq'       => '高雄縣議會',
        'txq'       => '臺中縣議會',
        'tnq'       => '臺南縣議會',
        'tpe-1950'  => '臺北（省轄）市議會',
        'txg-1950'  => '臺中（省轄）市議會',
        'tnn-1950'  => '臺南（省轄）市議會',
        'khh-1951'  => '高雄市議會（省轄市）',
        'khh-1981'  => '高雄市議會（直轄市）',
        'tpe-1967'  => '臺北（直轄）市臨時市議會',
        'khh-1979'  => '高雄市臨時市議會',
        'kin-1992'  => '金門縣臨時縣議會',
        'lie-1992'  => '連江縣臨時縣議會',
    ];

    /**
     * 從 HTTP_HOST 解析出議會代碼。
     *
     * 以 CCAPI_DOMAIN_POSTFIX 環境變數為後綴，去除後取得代碼。
     * 回傳 'all' 代表全國查詢，null 代表未知子網域（應回傳 404）。
     */
    public static function getCouncilCode($host)
    {
        $postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
        if (substr($host, -strlen($postfix)) !== $postfix) {
            return null;
        }
        $code = substr($host, 0, -strlen($postfix));
        if ($code === 'all') {
            return 'all';
        }
        if (array_key_exists($code, self::$councils)) {
            return $code;
        }
        return null;
    }

    /**
     * 判斷是否為全國查詢
     */
    public static function isAll($cc_code)
    {
        return $cc_code === 'all';
    }

    /**
     * 取得所有議會代碼清單
     */
    public static function getAll()
    {
        return self::$councils;
    }

    /**
     * 取得議會名稱
     */
    public static function getName($cc_code)
    {
        return self::$councils[$cc_code] ?? null;
    }
}
