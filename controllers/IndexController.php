<?php

class IndexController extends MiniEngine_Controller
{
    public function indexAction()
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->cc_code = $cc_code;
        $this->view->council_name = $cc_code === 'all'
            ? '全國'
            : (CCAPI_Council::getName($cc_code) ?? $cc_code);

        $domain_postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
        $this->view->domain_postfix = $domain_postfix;

        try {
            $result = CCAPI_SearchAction::getCollections('council', '現存=true', 'all');
            $this->view->councils = $result->councils ?? [];
            $this->view->councils_error = null;
        } catch (Exception $e) {
            $this->view->councils = [];
            $this->view->councils_error = $e->getMessage();
        }
    }

    public function unknownCouncilAction()
    {
        header('HTTP/1.1 404 Not Found', true, 404);
        $ret = [
            'error' => true,
            'message' => '未知的議會子網域，請確認網址是否正確',
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($ret, JSON_UNESCAPED_UNICODE);
        return $this->noview();
    }
}
