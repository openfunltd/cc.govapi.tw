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

    if ($uri === '/swagger') {
        return ['swagger', 'ui'];
    }

    if ($uri === '/swagger.yaml') {
        return ['swagger', 'index'];
    }

    $param = CCAPI_Helper::getApiType($uri);
    if ($param) {
        return $param;
    }

    return null;
});
