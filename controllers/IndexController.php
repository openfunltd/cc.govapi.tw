<?php

class IndexController extends MiniEngine_Controller
{
    public function indexAction()
    {
        header('Location: /about', true, 302);
        exit;
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
