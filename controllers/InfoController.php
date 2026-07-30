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

        // 議員個人頁：用「人物代碼」串連同一人跨屆的所有記錄，不屬於屆次路由
        // /info/councilor/{人物代碼}（基本資料，預設）或 /info/councilor/{人物代碼}/speeches（發言記錄）
        if ($term_no === 'councilor') {
            $person_code = $tab;
            $profile_tab = ($sub_id === 'speeches') ? 'speeches' : 'profile';
            $this->view->is_councilor_profile = true;
            $this->view->profile_tab = $profile_tab;

            $records = $this->loadCouncilorProfile($person_code);
            $this->view->councilor_records = $records;

            if ($profile_tab === 'speeches') {
                $this->loadCouncilorSpeeches($records, $_GET['term'] ?? null);
            }
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
        return $this->groupCouncilorsByDistrict($r->councilors ?? []);
    }

    /**
     * 依選區分組，組內依姓名排序（沿用 API 預設排序），組間依選舉區號由小到大排序
     * （原住民保障名額的選舉區號固定編在一般選區之後，數字排序自然會放在最後）。
     * 較舊屆次（tpe-11~13）來源資料的「選區名稱」大多是「區域」佔位字串，不是真正
     * 選區名稱，這裡改用「第N選舉區」代替，比直接顯示縣市名更有意義。
     * 完全沒有選舉區號的記錄（來源資料缺漏）獨立分到最後一組。
     */
    protected function groupCouncilorsByDistrict($councilors)
    {
        $groups = [];
        foreach ($councilors as $c) {
            $district_no = $c->{'選舉區號'} ?? null;
            $district_name = $c->{'選區名稱'} ?? '';
            if ($district_name === '' || $district_name === '區域') {
                $district_name = $district_no !== null && $district_no !== '' ? "第{$district_no}選舉區" : '選區不詳';
            }
            $key = ($district_no !== null && $district_no !== '') ? (int)$district_no : PHP_INT_MAX;
            if (!isset($groups[$key])) {
                $groups[$key] = ['label' => $district_name, 'councilors' => []];
            }
            $groups[$key]['councilors'][] = $c;
        }
        ksort($groups, SORT_NUMERIC);
        return array_values($groups);
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

    /**
     * 用「人物代碼」查出同一人跨屆的所有議員記錄，依屆次由新到舊排序
     * （人物代碼已核對過全部 3,464 筆議員記錄都有值，可放心當作跨屆連結的 key）
     */
    protected function loadCouncilorProfile($person_code)
    {
        if (!$person_code) {
            return [];
        }
        $person_code = urldecode($person_code);
        $r = CCAPI::apiQuery(
            '/councilors?limit=50&' . urlencode('人物代碼') . '=' . urlencode($person_code) . '&sort=' . urlencode('屆次>'),
            '該議員所有屆期記錄'
        );
        return $r->councilors ?? [];
    }

    /**
     * 已知複姓（涵蓋目前議員資料裡出現過的 歐陽/上官，其餘為常見複姓，預先納入避免
     * 未來新當選議員剛好是複姓卻被切錯）
     */
    protected static $compoundSurnames = [
        '歐陽', '上官', '司馬', '諸葛', '東方', '皇甫', '尉遲', '公孫', '令狐', '太史',
        '端木', '獨孤', '軒轅', '長孫', '宇文', '慕容', '夏侯', '萬俟', '司徒', '司空',
        '拓跋', '赫連', '澹台', '公羊', '濮陽',
    ];

    /**
     * 逐字稿裡的說話者標記格式是「姓+職稱+名」（例：侯議員漢廷），不是「職稱+全名」。
     * 這是關鍵字比對的 heuristic，不是精確的逐句發言記錄——之後逐字稿清整成一句一句後
     * 會有更準確的做法。
     *
     * 兩種已知例外，會 fallback 成直接比對全名（不插入職稱）：
     *   1. 複姓：單純「取第一個字當姓」會切錯（例：「歐陽龍」切成「歐」+「陽龍」）
     *   2. 原住民族名／羅馬拼音名（例：「夷將．拔路兒Icyang • Parod」）：不符合
     *      漢名「姓+名」的慣例，套用規則會產生垃圾查詢字串
     */
    protected function buildSpeakerPattern($name, $title)
    {
        $title = $title ?: '議員';

        if (preg_match('/[a-zA-Z．·‧•]/u', $name)) {
            return $name;
        }

        $surname_len = 1;
        foreach (self::$compoundSurnames as $cs) {
            if (mb_substr($name, 0, mb_strlen($cs)) === $cs) {
                $surname_len = mb_strlen($cs);
                break;
            }
        }
        $surname = mb_substr($name, 0, $surname_len);
        $given = mb_substr($name, $surname_len);
        if ($given === '') {
            return $name;
        }
        return $surname . $title . $given;
    }

    /**
     * 發言記錄 tab：預設抓最新一屆（$records 已依屆次新到舊排序），可用 ?term= 指定
     * 要看哪一屆（不同屆職稱可能不同，例如某屆是議員、某屆是議長，說話者標記也會不同）
     */
    protected function loadCouncilorSpeeches($records, $requested_term = null)
    {
        $this->view->speech_term = null;
        $this->view->speech_pattern = null;
        $this->view->speech_total = 0;
        $this->view->speech_results = [];

        if (!$records) {
            return;
        }

        $record = $records[0];
        if ($requested_term) {
            foreach ($records as $r) {
                if ((string)$r->{'屆次'} === (string)$requested_term) {
                    $record = $r;
                    break;
                }
            }
        }

        $term_no = $record->{'屆次'};
        $pattern = $this->buildSpeakerPattern($record->{'姓名'}, $record->{'職稱'});
        $this->view->speech_term = $term_no;
        $this->view->speech_pattern = $pattern;

        $r = CCAPI::apiQuery(
            '/transcripts?limit=20&' . urlencode('屆') . '=' . $term_no . '&q=' . urlencode($pattern)
                . '&sort=' . urlencode('日期>')
                . '&output_fields=' . urlencode('代碼')
                . '&output_fields=' . urlencode('會期代碼')
                . '&output_fields=' . urlencode('日期'),
            '該議員發言記錄（關鍵字比對，非精確逐句，依日期新到舊）'
        );
        $this->view->speech_total = $r->total ?? 0;

        // 依會期分組，場次已經依日期新到舊排序，分組後第一次出現的會期自然就是最新的
        $groups = [];
        foreach (($r->transcripts ?? []) as $t) {
            $session_code = $t->{'會期代碼'} ?? '';
            if (!isset($groups[$session_code])) {
                $groups[$session_code] = (object)[
                    '會期代碼' => $session_code,
                    '會期名稱' => CCAPI_Type_Session::getFriendlyName($session_code),
                    'items' => [],
                ];
            }
            $groups[$session_code]->items[] = $t;
        }

        // 補上每個場次的名稱（時段＋場次類別，委員會審查/分組審查時附上委員會名稱）；
        // 每個會期只查一次該會期全部場次（loadSittingsForSession 已有），不逐筆查，避免 N+1
        foreach ($groups as $group) {
            $sittings_by_code = [];
            foreach ($this->loadSittingsForSession($group->{'會期代碼'}) as $s) {
                $sittings_by_code[$s->{'代碼'}] = $s;
            }
            foreach ($group->items as $item) {
                $sitting = $sittings_by_code[$item->{'代碼'}] ?? null;
                $item->{'場次名稱'} = $sitting ? $this->buildSittingLabel($sitting) : null;
            }
        }

        $this->view->speech_groups = array_values($groups);
    }

    protected function buildSittingLabel($sitting)
    {
        $label = trim(implode(' ', array_filter([
            $sitting->{'時段'} ?? null,
            $sitting->{'場次類別'} ?? null,
        ])));
        if ($sitting->{'委員會名稱'} ?? null) {
            $label .= ($label ? '・' : '') . $sitting->{'委員會名稱'};
        }
        return $label ?: null;
    }
}
