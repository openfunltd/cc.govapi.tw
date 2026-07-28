<?php

class InfoController extends MiniEngine_Controller
{
    public function indexAction()
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->cc_code = $cc_code;
        $this->view->council_name = CouncilHelper::getName($cc_code);

        if (CCAPI_Council::isAll($cc_code)) {
            $result = CCAPI::apiQuery('/overviews?limit=50', '全國議會現況資料');
            $this->view->overviews = $result->overviews ?? [];
            return;
        }

        $result = CCAPI::apiQuery('/overview/' . rawurlencode($cc_code), '本議會現況資料');
        $this->view->overview = $result->data ?? null;

        $term_no = $this->view->overview->{'屆次'} ?? null;
        if ($term_no) {
            $councilors_result = CCAPI::apiQuery(
                '/councilors?limit=100&' . urlencode('屆次') . '=' . $term_no,
                '本屆議員名單'
            );
            $this->view->councilors = $councilors_result->councilors ?? [];
        } else {
            $this->view->councilors = [];
        }
    }
}
