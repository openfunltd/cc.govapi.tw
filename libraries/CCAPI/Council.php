<?php

class CCAPI_Council
{
    /**
     * 所有議會代碼（含已廢止），key 為代碼，value 為議會名稱
     */
    protected static $councils = [
        // 國會
        'ly' => '立法院',
        'na' => '國民大會',
        // 省議會
        'tpg' => '臺灣省議會',
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
        'kee' => '基隆市議會',
        'mia' => '苗栗縣議會',
        'cha' => '彰化縣議會',
        'nan' => '南投縣議會',
        'yun' => '雲林縣議會',
        'cyi' => '嘉義市議會',
        'cyq' => '嘉義縣議會',
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
     * 隱藏議會：子網域可以正常使用（只有知道網址的人看得到），但不出現在任何
     * 議會清單，all 跨議會查詢也會排除它的資料。用於新資料來源正式公開前的測試。
     */
    protected static $hidden_councils = ['ly', 'na', 'tpg'];

    /**
     * 不受隱藏議會限制的型別：all 查詢照常包含隱藏議會的資料。候選人先開放，讓跨選舉的
     * 參選紀錄完整（例：同一人的立委選舉與縣市長選舉出現在同一個候選人頁）；財產申報跟著候選人頁一起開放
     */
    protected static $hidden_exempt_types = ['candidate', 'declaration'];

    /**
     * 議會種類（國民大會／立法院／縣市議會／鄉鎮市區代表會／省議會），沒列出的一律是
     * 縣市議會。跟 council index 的「議會種類」欄位一致（見 scripts/import-council.php）
     */
    protected static $council_kinds = [
        'ly' => '立法院',
        'na' => '國民大會',
        'tpg' => '省議會',
    ];

    /**
     * 各議會種類跟縣市議會不同的用詞，key 是縣市議會的用詞。同時用在畫面文字跟
     * councilor「職稱」欄位的查詢值（立法院的職稱資料是 立法委員／院長／副院長）
     */
    protected static $kind_terms = [
        '立法院' => [
            '副議長' => '副院長',
            '議長' => '院長',
            '議員' => '立法委員',
            '議會官網公告頁' => '資料來源',
        ],
        '國民大會' => [
            '議員' => '國民大會代表',
            '議會官網公告頁' => '資料來源',
        ],
        '省議會' => [
            '議員' => '省議員',
            '議會官網公告頁' => '資料來源',
        ],
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
     * 取得隱藏議會代碼清單
     */
    public static function getHiddenCodes()
    {
        return self::$hidden_councils;
    }

    public static function isHidden($cc_code)
    {
        return in_array($cc_code, self::$hidden_councils, true);
    }

    /**
     * all 查詢這個型別時要排除的議會代碼（隱藏議會，除非型別在例外清單）
     */
    public static function getHiddenCodesForType($type)
    {
        return in_array($type, self::$hidden_exempt_types, true) ? [] : self::$hidden_councils;
    }

    public static function getKind($cc_code)
    {
        return self::$council_kinds[$cc_code] ?? '縣市議會';
    }

    /**
     * 把縣市議會用詞（議長、議員…）換成該議會種類的用詞，例：立法院「議員名單」→「立法委員名單」
     */
    public static function localize($text, $cc_code)
    {
        $map = self::$kind_terms[self::getKind($cc_code)] ?? [];
        return $map ? strtr($text, $map) : $text;
    }

    /**
     * 屆期的顯示名稱：不是延續編號的特殊屆期用自己的名稱（94 年任務型國民大會是為了複決修憲案臨時選出、
     * 政黨比例代表制，中選會稱「94年任務型國民大會代表選舉」，叫「第4屆」容易誤會跟前三屆一脈相承），
     * 其他一律「第 N 屆」。$spaced 為 true 時數字前後留空白（「第 3 屆」，跟原本頁面寫法一致）
     */
    protected static $term_labels = [
        'na' => [4 => '94年任務型'],
    ];

    public static function termLabel($cc_code, $term_no, $spaced = false)
    {
        $label = self::$term_labels[$cc_code][(int) $term_no] ?? null;
        if ($label !== null) {
            return $label;
        }
        return $spaced ? "第 {$term_no} 屆" : "第{$term_no}屆";
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
