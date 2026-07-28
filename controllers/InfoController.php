<?php

class InfoController extends MiniEngine_Controller
{
    protected $tabs = [
        'councilors' => '議員名單',
        'sessions'   => '會期',
        'timeline'   => '時間軸',
    ];

    public function indexAction($term_no = null, $tab = null, $sub_id = null)
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->cc_code = $cc_code;
        $this->view->council_name = CouncilHelper::getName($cc_code);

        if (CCAPI_Council::isAll($cc_code)) {
            // 全國頁沒有「屆」的概念（各議會屆次互不相干），維持原本的現況卡片牆
            $result = CCAPI::apiQuery('/overviews?limit=50', '全國議會現況資料');
            $this->view->overviews = $result->overviews ?? [];
            return;
        }

        if (!$term_no) {
            // 沒帶屆次 → 查最新一屆，導向該屆的議員名單頁
            $overview = CCAPI::apiQuery('/overview/' . rawurlencode($cc_code), '本議會現況資料（決定最新屆）');
            $latest_term = $overview->data->{'屆次'} ?? null;
            if ($latest_term) {
                header('Location: /info/' . $latest_term . '/councilors', true, 302);
                exit;
            }
            $this->view->term_no = null;
            return;
        }

        $term_no = (int)$term_no;
        $tab = ($tab && array_key_exists($tab, $this->tabs)) ? $tab : 'councilors';

        $this->view->term_no = $term_no;
        $this->view->tab = $tab;
        $this->view->tabs = $this->tabs;
        $this->view->all_terms = $this->loadAllTerms($cc_code);
        $this->view->header = $this->loadHeader($cc_code, $term_no);

        switch ($tab) {
            case 'councilors':
                $this->view->councilors = $this->loadCouncilors($cc_code, $term_no);
                break;
            case 'sessions':
                $this->loadSessionsTab($cc_code, $term_no, $sub_id);
                break;
            case 'timeline':
                $this->view->timeline_sessions = $this->loadTermSessions($cc_code, $term_no);
                break;
        }
    }

    protected function loadAllTerms($cc_code)
    {
        $r = CCAPI::apiQuery('/terms?limit=100&sort=' . urlencode('屆次>'), '本議會所有屆期');
        return $r->terms ?? [];
    }

    protected function loadHeader($cc_code, $term_no)
    {
        $term = CCAPI::apiQuery('/term/' . rawurlencode($cc_code) . '/' . $term_no, '本屆屆期資料');
        $speaker = CCAPI::apiQuery(
            '/councilors?limit=1&' . urlencode('屆次') . '=' . $term_no . '&' . urlencode('職稱') . '=' . urlencode('議長'),
            '本屆議長'
        );
        $deputy = CCAPI::apiQuery(
            '/councilors?limit=1&' . urlencode('屆次') . '=' . $term_no . '&' . urlencode('職稱') . '=' . urlencode('副議長'),
            '本屆副議長'
        );
        $count = CCAPI::apiQuery('/councilors?limit=0&' . urlencode('屆次') . '=' . $term_no, '本屆議員人數');

        return (object)[
            '屆次'       => $term_no,
            '就職日'     => $term->data->{'就職日'} ?? null,
            '任期屆滿日' => $term->data->{'任期屆滿日'} ?? null,
            '議長姓名'   => $speaker->councilors[0]->{'姓名'} ?? null,
            '副議長姓名' => $deputy->councilors[0]->{'姓名'} ?? null,
            '議員人數'   => $count->total ?? null,
        ];
    }

    protected function loadCouncilors($cc_code, $term_no)
    {
        $r = CCAPI::apiQuery('/councilors?limit=100&' . urlencode('屆次') . '=' . $term_no, '本屆議員名單');
        return $r->councilors ?? [];
    }

    protected function loadTermSessions($cc_code, $term_no)
    {
        $r = CCAPI::apiQuery(
            '/sessions?limit=200&' . urlencode('屆') . '=' . $term_no . '&sort=' . urlencode('開始日期<'),
            '本屆所有會期'
        );
        return $r->sessions ?? [];
    }

    protected function loadSessionsTab($cc_code, $term_no, $session_code)
    {
        $this->view->all_sessions = $this->loadTermSessions($cc_code, $term_no);

        if ($session_code) {
            $session_code = urldecode($session_code);
            $session = CCAPI::apiQuery('/session/' . rawurlencode($session_code), '指定會期資料');
            $this->view->session_meta = $session->data ?? (object)['代碼' => $session_code, '會期名稱' => CCAPI_Type_Session::getFriendlyName($session_code)];
            $this->view->session_status = null;   // 指定歷史會期，不需要進行中/已結束標記
            $this->view->session_sittings = $this->loadSittingsForSession($session_code);
            return;
        }

        // 沒指定 → 找本屆目前進行中或最近一次的會期
        [$meta, $status, $sittings] = $this->findCurrentSessionInTerm($cc_code, $term_no);
        $this->view->session_meta = $meta;
        $this->view->session_status = $status;
        $this->view->session_sittings = $sittings;
    }

    protected function findCurrentSessionInTerm($cc_code, $term_no)
    {
        $today_str = date('Y-m-d');

        $upcoming_r = CCAPI::apiQuery(
            '/sittings?limit=5&' . urlencode('屆') . '=' . $term_no
                . '&' . urlencode('日期') . ':' . $today_str . ','
                . '&sort=' . urlencode('日期<'),
            '本屆是否有進行中場次'
        );
        $upcoming = $upcoming_r->sittings ?? [];
        if ($upcoming) {
            $session_code = $upcoming[0]->{'會期代碼'};
            $session = CCAPI::apiQuery('/session/' . rawurlencode($session_code), '進行中會期資料');
            $meta = $session->data ?? (object)['代碼' => $session_code, '會期名稱' => CCAPI_Type_Session::getFriendlyName($session_code)];
            return [$meta, 'ongoing', $upcoming];
        }

        $latest_r = CCAPI::apiQuery(
            '/sessions?limit=1&' . urlencode('屆') . '=' . $term_no . '&sort=' . urlencode('開始日期>'),
            '本屆最近一次會期'
        );
        $latest = $latest_r->sessions[0] ?? null;
        if (!$latest) {
            return [null, 'none', []];
        }
        $sittings = $this->loadSittingsForSession($latest->{'代碼'});
        return [$latest, 'ended', $sittings];
    }

    protected function loadSittingsForSession($session_code, $size = 200)
    {
        $r = CCAPI::apiQuery(
            '/sittings?limit=' . (int)$size . '&' . urlencode('會期代碼') . '=' . urlencode($session_code) . '&sort=' . urlencode('日期<'),
            '該會期場次'
        );
        return $r->sittings ?? [];
    }
}
