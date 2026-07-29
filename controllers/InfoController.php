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

        // 逐字稿搜尋：全國跟單一議會子網域都適用，不屬於屆次路由，前端 JS 直接呼叫
        // /transcripts API 做 crossfilter，這裡只需要準備頁面骨架
        if ($term_no === 'search') {
            $this->view->is_search = true;
            return;
        }

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
        // 'transcript' 是從 sessions tab 連結進去的子頁面，不放進主要 tab 導覽列
        $valid_tabs = array_merge(array_keys($this->tabs), ['transcript']);
        $tab = ($tab && in_array($tab, $valid_tabs)) ? $tab : 'councilors';

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
            case 'transcript':
                $this->loadTranscriptTab($cc_code, $term_no, $sub_id);
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
        $this->view->sessions_with_transcript = $this->loadSessionsWithTranscript($term_no);

        if ($session_code) {
            $session_code = urldecode($session_code);
            $session = CCAPI::apiQuery('/session/' . rawurlencode($session_code), '指定會期資料');
            $this->view->session_meta = $session->data ?? (object)['代碼' => $session_code, '會期名稱' => CCAPI_Type_Session::getFriendlyName($session_code)];
            $this->view->session_status = null;   // 指定歷史會期，不需要進行中/已結束標記
            $this->view->session_sittings = $this->loadSittingsForSession($session_code);
            $this->view->sittings_with_transcript = $this->loadSittingsWithTranscript($session_code);
            return;
        }

        // 沒指定 → 找本屆目前進行中或最近一次的會期
        [$meta, $status, $sittings] = $this->findCurrentSessionInTerm($cc_code, $term_no);
        $this->view->session_meta = $meta;
        $this->view->session_status = $status;
        $this->view->session_sittings = $sittings;
        $this->view->sittings_with_transcript = $meta ? $this->loadSittingsWithTranscript($meta->{'代碼'}) : [];
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

    /**
     * 用一次聚合查詢拿到「本屆哪些會期代碼有逐字稿」，避免對每個會期各別查一次（N+1）
     */
    protected function loadSessionsWithTranscript($term_no)
    {
        $r = CCAPI::apiQuery(
            '/transcripts?limit=0&' . urlencode('屆') . '=' . $term_no . '&agg=' . urlencode('會期代碼'),
            '本屆哪些會期有逐字稿'
        );
        $codes = [];
        foreach (($r->aggs[0]->buckets ?? []) as $b) {
            $codes[$b->{'會期代碼'}] = true;
        }
        return $codes;
    }

    /**
     * 用一次聚合查詢拿到「本會期哪些場次代碼有逐字稿」，避免對每個場次各別查一次（N+1）
     */
    protected function loadSittingsWithTranscript($session_code)
    {
        $r = CCAPI::apiQuery(
            '/transcripts?limit=200&' . urlencode('會期代碼') . '=' . urlencode($session_code)
                . '&output_fields=' . urlencode('代碼'),
            '本會期哪些場次有逐字稿'
        );
        $codes = [];
        foreach (($r->transcripts ?? []) as $t) {
            $codes[$t->{'代碼'}] = true;
        }
        return $codes;
    }

    /**
     * 逐字稿 tab：對應單一場次（sitting），不是整個會期——單一場次的逐字稿全文
     * 大小是安全的（實測平均 20 萬字、單筆 47 萬 bytes），可以一次整份撈回來，
     * 不需要像「整個會期全部場次」那樣分批載入（那樣做曾經把伺服器打爆過）。
     * 同一場次若有多種來源（大會會議紀錄、各委員會審查會議事錄等），用匯入時
     * 已經分好的「分段」陣列各自顯示一個 tab。
     */
    protected function loadTranscriptTab($cc_code, $term_no, $sitting_code)
    {
        if (!$sitting_code) {
            $this->view->sitting_meta = null;
            $this->view->transcript = null;
            return;
        }
        $sitting_code = urldecode($sitting_code);

        $sitting = CCAPI::apiQuery('/sitting/' . rawurlencode($sitting_code), '場次資料');
        $this->view->sitting_meta = $sitting->data ?? (object)['代碼' => $sitting_code];

        $transcript = CCAPI::apiQuery('/transcript/' . rawurlencode($sitting_code), '場次逐字稿');
        $this->view->transcript = ($transcript->error ?? true) ? null : $transcript->data;
    }
}
