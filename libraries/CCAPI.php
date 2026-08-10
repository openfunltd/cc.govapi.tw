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
        // 純資料 API 一律掛在 /api/ 前綴下（見 index.php 路由說明），$url 只帶
        // 型別路徑本身（例：/councilors?...），這裡統一補上前綴
        $full_url = 'https://' . $host . '/api' . $url;

        // /info、/viewer 等頁面渲染時會對自己的 API 發出大量內部請求，量一大會被
        // nginx gateway 對匿名流量的 rate limit 擋掉（已知案例：2026-08 台南市議會
        // 議員名單一度全部顯示空白）。CCAPI_TOKEN 帶這個內部專用 token 繞過限制，
        // 用 Authorization: Bearer header 帶（跟姊妹專案 lawtrace 目前用的方式一致
        // ——原本是 ?token= query string，已改成 header），不會出現在網址裡，
        // 「本頁使用 API」清單（CCAPI::getLogs()）不會洩漏 token
        $curl = curl_init();
        if (getenv('CCAPI_TOKEN')) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . getenv('CCAPI_TOKEN'),
            ]);
        }
        curl_setopt($curl, CURLOPT_URL, $full_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($curl);
        $res_json = json_decode($res);
        curl_close($curl);

        self::$log[] = [$full_url, $reason];

        return $res_json;
    }
}
