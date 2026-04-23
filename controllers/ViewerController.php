<?php

class ViewerController extends MiniEngine_Controller
{
    public function indexAction()
    {
        $this->view->type = 'dashboard';
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->council_name = CouncilHelper::getName($cc_code);
        $this->view->cc_code = $cc_code;
    }
}
