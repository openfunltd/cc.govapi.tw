<?php

class InfoController extends MiniEngine_Controller
{
    protected $tabs = [
        'councilors'  => '議員名單',
        'sessions'    => '會期',
        'timeline'    => '時間軸',
        'committees'  => '委員會',
        'bills'       => '議案',
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
        // /info/councilor/{人物代碼}（基本資料，預設）或 /info/councilor/{人物代碼}/speeches
        // （發言記錄）或 /info/councilor/{人物代碼}/bills（提案記錄）或
        // /info/councilor/{人物代碼}/elections（選舉紀錄）
        if ($term_no === 'councilor') {
            $person_code = $tab;
            $profile_tab = in_array($sub_id, ['speeches', 'bills', 'elections'], true) ? $sub_id : 'profile';
            $this->view->is_councilor_profile = true;
            $this->view->profile_tab = $profile_tab;

            $records = $this->loadCouncilorProfile($person_code);
            $this->view->councilor_records = $records;

            if ($profile_tab === 'speeches') {
                $this->loadCouncilorSpeeches($records, $_GET['term'] ?? null);
            } elseif ($profile_tab === 'bills') {
                $this->loadCouncilorBills($records);
            } elseif ($profile_tab === 'elections') {
                $this->loadCouncilorElections($records);
            }
            return;
        }

        // 候選人歷年參選頁：用「人物代碼」串連同一人歷次參選紀錄，包含落選的次數
        // （councilor 只收錄當選過的人，落選者在那邊完全查不到，這裡是唯一能看到
        // 落選紀錄的地方）。/info/candidate/{人物代碼}，不屬於屆次路由。
        if ($term_no === 'candidate') {
            $person_code = $tab;
            $this->view->is_candidate_profile = true;
            $this->loadCandidateProfile($person_code);
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
        // 'transcript'／'bill' 是從各自列表頁連結進去的單筆詳情子頁面，不放進主要 tab 導覽列
        $valid_tabs = array_merge(array_keys($this->tabs), ['transcript', 'bill']);
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
            case 'committees':
                $this->view->committee_groups = $this->loadCommittees($cc_code);
                break;
            case 'transcript':
                $this->loadTranscriptTab($cc_code, $term_no, $sub_id);
                break;
            case 'bill':
                $bill = $this->loadBillDetail($sub_id);
                $this->resolveBillPeople($bill);
                $this->view->bill_detail = $bill;
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
        $councilors = $r->councilors ?? [];
        $this->attachVoteShare($councilors);
        return $this->groupCouncilorsByDistrict($councilors);
    }

    /**
     * 用議員的「參選代碼」比對 candidate 的「候選人代碼」（同一組代碼體系），
     * 把該次選舉的得票數／得票率／得票排名附到議員物件上（沒有比對到候選人
     * 資料時就不會有這幾個屬性，例如候選人資料只回溯到民國98年，較舊的屆次
     * 查不到）。一次查詢批次處理整批議員，不逐筆查，避免 N+1。
     */
    protected function attachVoteShare($councilors)
    {
        $codes = [];
        foreach ($councilors as $c) {
            if ($c->{'參選代碼'} ?? null) {
                $codes[$c->{'參選代碼'}] = true;
            }
        }
        if (!$codes) {
            return;
        }

        $qs = '';
        foreach (array_keys($codes) as $code) {
            $qs .= '&' . urlencode('候選人代碼') . '=' . urlencode($code);
        }
        $r = CCAPI::apiQuery(
            '/candidates?limit=' . count($codes) . $qs,
            '議員名單對應候選人得票資料'
        );

        $by_code = [];
        foreach (($r->candidates ?? []) as $cand) {
            $by_code[$cand->{'候選人代碼'}] = $cand;
        }

        foreach ($councilors as $c) {
            $cand = $by_code[$c->{'參選代碼'} ?? ''] ?? null;
            if ($cand) {
                $c->{'得票數'} = $cand->{'得票數'} ?? null;
                $c->{'得票率'} = $cand->{'得票率'} ?? null;
                $c->{'得票排名'} = $cand->{'得票排名'} ?? null;
            }
        }
    }

    /**
     * 依選區分組，組內依得票率由高到低排序（沒有得票資料的排最後，保留原本
     * API 排序的相對順序），讓使用者能一眼看出每一區誰的得票最強；組間依
     * 選舉區號由小到大排序（原住民保障名額的選舉區號固定編在一般選區之後，
     * 數字排序自然會放在最後）。
     * 部分較舊資料的「選區別」是「區域」佔位字串，不是真正選區名稱，這裡改用
     * 「第N選舉區」代替，比直接顯示縣市名更有意義。
     * 完全沒有選舉區號的記錄（來源資料缺漏）獨立分到最後一組。
     */
    protected function groupCouncilorsByDistrict($councilors)
    {
        $groups = [];
        foreach ($councilors as $c) {
            $district_no = $c->{'選舉區號'} ?? null;
            $district_name = $c->{'選區別'} ?? '';
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

        foreach ($groups as &$group) {
            usort($group['councilors'], function ($a, $b) {
                $va = $a->{'得票率'} ?? null;
                $vb = $b->{'得票率'} ?? null;
                if ($va === null && $vb === null) return 0;
                if ($va === null) return 1;
                if ($vb === null) return -1;
                return $vb <=> $va;
            });
        }
        unset($group);

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

    /**
     * 委員會不綁屆，是議會層級的常設編制，這裡回傳依「類別」（常設／特種）分組的
     * 委員會清單。目前資料沒有「委員會成員」，所以只列出委員會本身，不列出議員。
     */
    protected function loadCommittees($cc_code)
    {
        $r = CCAPI::apiQuery(
            '/committees?limit=100&' . urlencode('議會代碼') . '=' . urlencode($cc_code),
            '本議會委員會清單'
        );
        $committees = $r->committees ?? [];

        $today = date('Y-m-d');
        $groups = [];
        foreach ($committees as $c) {
            $abolished = $c->{'廢止日期'} ?? null;
            $c->_is_abolished = ($abolished !== null && $abolished !== '' && $abolished < $today);
            $type = $c->{'類別'} ?? '常設';
            $groups[$type][] = $c;
        }
        uksort($groups, function ($a, $b) {
            if ($a === $b) return 0;
            if ($a === '常設') return -1;
            if ($b === '常設') return 1;
            return strcmp($a, $b);
        });
        return $groups;
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
     * 議案單筆詳情頁：對應單一議案代碼，顯示完整欄位（案由/說明/辦法/審查意見/議決全文）
     */
    protected function loadBillDetail($bill_code)
    {
        if (!$bill_code) {
            return null;
        }
        $bill_code = urldecode($bill_code);
        $r = CCAPI::apiQuery('/bill/' . rawurlencode($bill_code), '議案詳情');
        return ($r->error ?? true) ? null : $r->data;
    }

    /**
     * 議案的「提案人結構」「連署人結構」裡的「議員代碼」對應到 councilor 的
     * 「代碼」欄位（候選人登記代碼），不是「人物代碼」（跨屆連結用的代碼），
     * 要連到議員個人頁 /info/councilor/{人物代碼} 得先查一次 councilor 換算。
     * 一次查詢批次處理該筆議案裡出現過的所有代碼（OR 查詢），不逐筆查避免 N+1。
     * 查不到對應議員資料的（理論上不應該發生，但沒有全面驗證過）就不加連結，
     * 前端顯示原始姓名文字。
     */
    protected function resolveBillPeople($bill)
    {
        if (!$bill) {
            return;
        }
        $codes = [];
        foreach (['提案人結構', '連署人結構'] as $field) {
            foreach ($bill->{$field} ?? [] as $p) {
                if ($p->{'議員代碼'} ?? null) {
                    $codes[$p->{'議員代碼'}] = true;
                }
            }
        }
        if (!$codes) {
            return;
        }

        $qs = '';
        foreach (array_keys($codes) as $code) {
            $qs .= '&' . urlencode('代碼') . '=' . urlencode($code);
        }
        $r = CCAPI::apiQuery(
            '/councilors?limit=' . count($codes) . $qs,
            '議案提案人／連署人對應議員資料'
        );

        $person_code_by_code = [];
        foreach (($r->councilors ?? []) as $c) {
            $person_code_by_code[$c->{'代碼'}] = $c->{'人物代碼'} ?? null;
        }

        foreach (['提案人結構', '連署人結構'] as $field) {
            foreach ($bill->{$field} ?? [] as $p) {
                $p->{'人物代碼'} = $person_code_by_code[$p->{'議員代碼'} ?? ''] ?? null;
            }
        }
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
        $records = $r->councilors ?? [];
        $this->attachVoteShare($records);
        return $records;
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

    /**
     * 提案記錄 tab：議案的「提案人」欄位直接就是議員姓名（不像逐字稿說話者標記
     * 需要猜測姓+職稱+名的格式），用「議會代碼＋姓名」直接查詢即可，比發言記錄
     * 準確很多。依「屆」（議案來源本身沒有會期/場次可以分組，屆是從來源檔名
     * 解析出來的推測值）由新到舊分組。
     */
    protected function loadCouncilorBills($records)
    {
        $this->view->bill_total = 0;
        $this->view->bill_groups = [];
        if (!$records) {
            return;
        }

        $cc_code = $records[0]->{'議會代碼'};
        $name = $records[0]->{'姓名'};

        $r = CCAPI::apiQuery(
            '/bills?limit=200&' . urlencode('議會代碼') . '=' . urlencode($cc_code)
                . '&' . urlencode('提案人') . '=' . urlencode($name),
            '該議員提案記錄'
        );
        $this->view->bill_total = $r->total ?? 0;

        $groups = [];
        foreach (($r->bills ?? []) as $b) {
            $term_no = $b->{'屆'} ?? null;
            $key = $term_no ?? 0;
            if (!isset($groups[$key])) {
                $groups[$key] = (object)['屆' => $term_no, 'items' => []];
            }
            $groups[$key]->items[] = $b;
        }
        krsort($groups, SORT_NUMERIC);
        $this->view->bill_groups = array_values($groups);
    }

    /**
     * 選舉紀錄 tab：每一屆的「參選代碼」對應到 candidate 的完整候選人公報資料
     * （學歷/經歷/政見/相片，候選人資料只回溯到民國98年，較舊屆次可能查不到）
     * 加上同選區其他候選人的得票比較表（用候選人自己的選舉代碼＋行政區代碼＋
     * 選區別去查，這三個欄位一起才能精確定位同一場選舉，見 knowledge.md 說明）。
     * 依屆次新到舊排列（$records 已經是這個順序）。
     */
    protected function loadCouncilorElections($records)
    {
        $this->view->election_groups = [];
        if (!$records) {
            return;
        }

        $codes = [];
        foreach ($records as $r) {
            if ($r->{'參選代碼'} ?? null) {
                $codes[$r->{'參選代碼'}] = true;
            }
        }
        if (!$codes) {
            return;
        }

        $qs = '';
        foreach (array_keys($codes) as $code) {
            $qs .= '&' . urlencode('候選人代碼') . '=' . urlencode($code);
        }
        $r = CCAPI::apiQuery('/candidates?limit=' . count($codes) . $qs, '該議員各屆候選人公報資料');

        $candidate_by_code = [];
        foreach (($r->candidates ?? []) as $cand) {
            $candidate_by_code[$cand->{'候選人代碼'}] = $cand;
        }

        $groups = [];
        foreach ($records as $term_record) {
            $participation_code = $term_record->{'參選代碼'} ?? null;
            $candidate = $participation_code ? ($candidate_by_code[$participation_code] ?? null) : null;

            $race_candidates = [];
            if ($candidate && ($candidate->{'選舉代碼'} ?? null)) {
                $race_qs = '&' . urlencode('選舉代碼') . '=' . urlencode($candidate->{'選舉代碼'})
                    . '&' . urlencode('行政區代碼') . '=' . urlencode($candidate->{'行政區代碼'} ?? '')
                    . '&' . urlencode('選區別') . '=' . urlencode($candidate->{'選區別'} ?? '');
                $race_r = CCAPI::apiQuery(
                    '/candidates?limit=100&sort=' . urlencode('得票排名<') . $race_qs,
                    '同選區候選人得票比較'
                );
                $race_candidates = $race_r->candidates ?? [];
                $this->enrichRaceCandidates($race_candidates);
            }

            $groups[] = (object)[
                '屆次'      => $term_record->{'屆次'},
                'candidate' => $candidate,
                'race'      => $race_candidates,
            ];
        }

        $this->view->election_groups = $groups;
    }

    /**
     * 候選人歷年參選頁：用「人物代碼」查出同一人歷次參選紀錄（candidate 的
     * 人物代碼是從 mixed-tw.gov.cec.data-選舉資料庫/person.jsonl 衍生，見
     * import-candidate.php 說明，涵蓋落選的人；候選人資料只回溯到民國98年，
     * 更早期的參選紀錄查不到）。每筆參選都附上同選區得票比較表。
     */
    protected function loadCandidateProfile($person_code)
    {
        $this->view->candidate_groups = [];
        $this->view->candidate_is_councilor = false;
        $this->view->candidate_person_code = null;
        if (!$person_code) {
            return;
        }
        $person_code = urldecode($person_code);
        $this->view->candidate_person_code = $person_code;

        $r = CCAPI::apiQuery(
            '/candidates?limit=50&' . urlencode('人物代碼') . '=' . urlencode($person_code) . '&sort=' . urlencode('年份>'),
            '該候選人歷年參選紀錄'
        );

        $groups = [];
        foreach (($r->candidates ?? []) as $cand) {
            $race_candidates = [];
            if ($cand->{'選舉代碼'} ?? null) {
                $race_qs = '&' . urlencode('選舉代碼') . '=' . urlencode($cand->{'選舉代碼'})
                    . '&' . urlencode('行政區代碼') . '=' . urlencode($cand->{'行政區代碼'} ?? '')
                    . '&' . urlencode('選區別') . '=' . urlencode($cand->{'選區別'} ?? '');
                $race_r = CCAPI::apiQuery(
                    '/candidates?limit=100&sort=' . urlencode('得票排名<') . $race_qs,
                    '同選區候選人得票比較'
                );
                $race_candidates = $race_r->candidates ?? [];
                $this->enrichRaceCandidates($race_candidates);
            }
            $groups[] = (object)[
                'candidate' => $cand,
                'race'      => $race_candidates,
            ];
        }
        $this->view->candidate_groups = $groups;

        // 是否曾經當選過議員（不限哪一屆），有的話在頁面上附連結過去
        $councilor_r = CCAPI::apiQuery(
            '/councilors?limit=1&' . urlencode('人物代碼') . '=' . urlencode($person_code),
            '候選人是否曾任議員'
        );
        $this->view->candidate_is_councilor = ($councilor_r->total ?? 0) > 0;
    }

    /**
     * 幫「同選區得票比較」表格補上性別、黨籍（來源在候選人的「其他欄位」裡，攤平成
     * 頂層屬性方便 view 直接讀）。「當選」欄位已經是 candidate 資料本身的欄位
     * （來自中選會 cand.csv 的當選註記），不需要另外查 councilor 是否有這筆
     * 記錄來判斷——查 councilor 存不存在會被議員中途離職資料消失誤判成沒當選
     * （實測案例：李彥秀 111 年台北市議員選舉最高票當選，但因任內離職，councilor
     * 資料完全沒有這筆記錄，見 import-candidate.php 的說明）。
     */
    protected function enrichRaceCandidates($race_candidates)
    {
        if (!$race_candidates) {
            return;
        }

        foreach ($race_candidates as $rc) {
            $extra = $rc->{'其他欄位'} ?? null;
            $gender = $extra->{'性別'} ?? null;
            // 少數（卡片式版面）沒有獨立的「性別」欄位，是跟出生年月日/出生地
            // 合併成一整段文字塞在「個人資料」欄位裡，要另外解析
            if (!$gender && ($extra->{'個人資料'} ?? null) && preg_match('/性別[:：]\s*([男女])/u', $extra->{'個人資料'}, $m)) {
                $gender = $m[1];
            }
            $rc->{'性別'} = $gender;
            $rc->{'黨籍'} = $extra->{'推薦之政黨'} ?? null;
        }
    }
}
