<?php
include(__DIR__ . '/init.inc.php');

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
