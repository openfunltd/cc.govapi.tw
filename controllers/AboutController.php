<?php

class AboutController extends MiniEngine_Controller
{
    public function indexAction()
    {
        $this->view->cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->council_name = CouncilHelper::getName($this->view->cc_code);
    }
}
