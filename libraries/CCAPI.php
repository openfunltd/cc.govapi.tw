<?php

class CCAPI
{
    protected static $log = [];

    public static function hasLog()
    {
        return count(self::$log) > 0;
    }

    public static function getLogs()
    {
        return self::$log;
    }

    public static function apiQuery($url, $reason)
    {
        $host = getenv('CCAPI_HOST') ?: ($_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all') . (getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw');
        $full_url = 'https://' . $host . $url;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $full_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($curl);
        $res_json = json_decode($res);
        curl_close($curl);

        self::$log[] = [$full_url, $reason];

        return $res_json;
    }
}
