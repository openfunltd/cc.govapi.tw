<?php
include(__DIR__ . '/init.inc.php');

// 轉址：cc.govapi.tw（無子網域）→ all.cc.govapi.tw
$_host = $_SERVER['HTTP_HOST'] ?? '';
$_postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$_bare_domain = ltrim($_postfix, '.');
if ($_host === $_bare_domain) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: https://all' . $_postfix . ($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

MiniEngine::dispatch(function($uri) {
    $cc_code = CCAPI_Council::getCouncilCode($_SERVER['HTTP_HOST'] ?? '');
    if (is_null($cc_code)) {
        return ['index', 'unknown_council'];
    }
    $_SERVER['CCAPI_COUNCIL_CODE'] = $cc_code;

    // /viewer/* routing → viewer/collection controllers
    if ($uri === '/viewer' || strpos($uri, '/viewer/') === 0) {
        $viewer_uri = substr($uri, 7) ?: '/';
        $parts = array_filter(explode('/', ltrim($viewer_uri, '/')), fn($s) => $s !== '');
        $parts = array_values($parts);
        $controller = $parts[0] ?? 'viewer';
        $action = $parts[1] ?? 'index';
        $params = array_map('urldecode', array_slice($parts, 2));
        return [$controller ?: 'viewer', $action ?: 'index', $params];
    }
    // /info、/info/{屆}、/info/{屆}/{tab}、/info/{屆}/{tab}/{sub_id}
    if ($uri === '/info' || strpos($uri, '/info/') === 0) {
        $info_uri = substr($uri, 5) ?: '/';
        $parts = array_values(array_filter(explode('/', ltrim($info_uri, '/')), fn($s) => $s !== ''));
        $params = array_map('urldecode', $parts);
        return ['info', 'index', $params];
    }

    if ($uri === '/swagger') {
        return ['swagger', 'ui'];
    }

    if ($uri === '/swagger.yaml') {
        return ['swagger', 'index'];
    }

    if ($uri === '/skill.md') {
        return ['swagger', 'skill'];
    }

    if ($uri === '/knowledge.md') {
        return ['about', 'knowledge'];
    }

    if ($uri === '/robots.txt') {
        return ['about', 'robots'];
    }

    // 純資料 API 一律要有 /api/ 前綴（例：/api/councilors、/api/councilor/tpe-14-王大明），
    // 跟 /info、/viewer 等人類可讀頁面用路徑就能明確區分，方便 robots.txt／Anubis 這類
    // 規則直接用 /api/ 一條就涵蓋全部，不用每個型別各自列一條。專案還沒正式對外公開，
    // 沒有相容性負擔，直接只留新路徑，不保留 /councilors 這種舊式無前綴路徑
    if ($uri === '/api' || strpos($uri, '/api/') === 0) {
        $api_uri = substr($uri, 4) ?: '/';
        $param = CCAPI_Helper::getApiType($api_uri);
        if ($param) {
            return $param;
        }
    }

    return null;
});
