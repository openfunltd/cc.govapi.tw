<?php

class ApiController extends MiniEngine_Controller
{
    public function collectionsAction($type)
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'];
        try {
            $ret = CCAPI_SearchAction::getCollections($type, $_SERVER['QUERY_STRING'], $cc_code);
        } catch (Exception $e) {
            $uniqid = MiniEngineHelper::uniqid(8);
            if (strpos($e->getMessage(), 'Result window is too large')) {
                header('HTTP/1.1 413 Payload Too Large', true, 413);
                $message = "錯誤，請縮小查詢範圍或調整分頁參數後重試";
            } else {
                header('HTTP/1.1 500 Internal Server Error', true, 500);
                $message = "錯誤，錯誤代碼為 " . $uniqid;
                error_log("[$uniqid] " . $e->getMessage());
            }
            $ret = [
                'error' => true,
                'message' => $message,
            ];
        }
        return $this->cors_json($ret);
    }

    public function itemAction($type, $id, $sub)
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'];
        $ret = CCAPI_SearchAction::getItem($type, $id, $sub, $_SERVER['QUERY_STRING'], $cc_code);
        return $this->cors_json($ret);
    }
}
