<?php

class ApiController extends MiniEngine_Controller
{
    // cors_json() 本身也會設 Access-Control-Allow-Origin/Methods，這裡提前在
    // init() 補上 Allow-Headers：改用 Authorization: Bearer header 帶 CCAPI_TOKEN
    // 之後，跨網域用瀏覽器帶 Authorization header 呼叫 /api/* 會先送 CORS
    // preflight（OPTIONS），沒有這行瀏覽器會擋掉，不讓 Authorization 過
    public function init()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization');
    }

    public function collectionsAction($type)
    {
        OpenFunAPIHelper::checkUsage([
            'service' => 'ccapi',
            'class' => "{$type}_collection",
        ]);
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
        OpenFunAPIHelper::apiDone([
            'size' => strlen(json_encode($ret, JSON_UNESCAPED_UNICODE)),
            'count' => $this->countCollectionRecords($type, $ret),
        ]);
        return $this->cors_json($ret);
    }

    public function itemAction($type, $id, $sub)
    {
        OpenFunAPIHelper::checkUsage([
            'service' => 'ccapi',
            'class' => "{$type}_item",
        ]);
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'];
        $ret = CCAPI_SearchAction::getItem($type, $id, $sub, $_SERVER['QUERY_STRING'], $cc_code);
        OpenFunAPIHelper::apiDone([
            'size' => strlen(json_encode($ret, JSON_UNESCAPED_UNICODE)),
            'count' => $this->countItemRecords($ret),
        ]);
        return $this->cors_json($ret);
    }

    // collectionsAction 錯誤時 $ret 是純陣列（['error'=>true,...]），成功時是
    // StdClass、實際資料放在 getReturnKey() 那個屬性（例如 councilors）
    protected function countCollectionRecords($type, $ret)
    {
        if (!is_object($ret)) {
            return 0;
        }
        $return_key = CCAPI_Type::run($type, 'getReturnKey');
        return count($ret->{$return_key} ?? []);
    }

    // itemAction 的回應形狀不只一種：單筆詳情（->data）、關聯是子集合時
    // （relation type 是 collection，回應形狀等同 getCollections()）、或
    // relation type 是 _function 時的任意自訂形狀——沒有單一固定的 key 名稱，
    // 找第一個陣列型別的屬性當作實際筆數，找不到就當作 1 筆（單一邏輯單位）
    protected function countItemRecords($ret)
    {
        if (!is_object($ret) || ($ret->error ?? false)) {
            return 0;
        }
        if (isset($ret->data)) {
            return 1;
        }
        foreach (get_object_vars($ret) as $value) {
            if (is_array($value)) {
                return count($value);
            }
        }
        return 1;
    }
}
