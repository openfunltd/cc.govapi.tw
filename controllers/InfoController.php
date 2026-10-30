<?php

class InfoController extends MiniEngine_Controller
{
    // 首長級職稱（逐字稿答詢官員的職稱，用來從首長紀錄挑出機關首長）
    const HEAD_TITLE_PATTERN = '/^(代理)?(局長|處長|主任委員|主委|執行秘書|總經理|董事長|館長|院長|所長|主任|大隊長|總隊長|隊長|團長|區長|校長|執行長|秘書長|總幹事|場長|廠長)$/u';

    protected $term_data = null;

    // 選舉指南預設顯示的選舉年份（民國）。有新的登記名冊匯入時改這裡
    const NEXT_ELECTION_YEAR = 115;

    protected $tabs = [
        'councilors'  => '議員名單',
        'agencies'    => '行政體系',
        'sessions'    => '會期',
        'timeline'    => '時間軸',
        'committees'  => '委員會',
        'bills'       => '議案',
    ];

    public function indexAction($term_no = null, $tab = null, $sub_id = null, $sub_id2 = null)
    {
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        $this->view->cc_code = $cc_code;
        $this->view->council_name = CouncilHelper::getName($cc_code);
        // 預設 og 內容，各分支視情況覆蓋成更具體的版本（給社群分享／預覽卡片用）
        $this->setOg('地方議會開放資料 — cc.govapi.tw', '台灣各縣市議會的議員、會期、議案、逐字稿等開放資料。');

        // 逐字稿搜尋：全國跟單一議會子網域都適用，不屬於屆次路由，前端 JS 直接呼叫
        // /transcripts API 做 crossfilter，這裡只需要準備頁面骨架
        if ($term_no === 'search') {
            $this->view->is_search = true;
            return;
        }

        // 議員個人頁：用「人物代碼」串連同一人跨屆的所有記錄，不屬於屆次路由
        // /info/councilor/{人物代碼}（基本資料，預設最新一屆）或
        // /info/councilor/{人物代碼}/{屆次}（基本資料，指定屆次，例：/14）或
        // /info/councilor/{人物代碼}/speeches（發言記錄）或
        // /info/councilor/{人物代碼}/bills（提案記錄）或
        // /info/councilor/{人物代碼}/elections（選舉紀錄）
        if ($term_no === 'councilor') {
            $person_code = $tab;
            // sub_id是純數字時當作「切換屆次」用，不是分頁名稱（見使用者回饋：
            // 某屆是遞補/卸任，但那屆剛好不是這個人最新一屆，個人頁預設只顯示
            // 最新一屆資訊，完全看不到那次遞補的詳細資訊）
            $selected_term_no = ctype_digit((string) $sub_id) ? (int) $sub_id : null;
            $profile_tab = ($selected_term_no === null && in_array($sub_id, ['speeches', 'bills', 'elections'], true)) ? $sub_id : 'profile';
            $this->view->is_councilor_profile = true;
            $this->view->profile_tab = $profile_tab;

            $records = $this->loadCouncilorProfile($person_code);
            $this->view->councilor_records = $records;

            // $records 已依選舉日期新到舊排序；URL帶屆次就找該屆，查無該屆
            // （屆次打錯或查無資料）就退回預設的最新一屆，不是整頁噴錯
            $selected = $records[0] ?? null;
            if ($selected_term_no !== null) {
                foreach ($records as $r) {
                    if ((int) ($r->{'屆次'} ?? -1) === $selected_term_no) {
                        $selected = $r;
                        break;
                    }
                }
            }
            $this->view->councilor_selected = $selected;

            if ($selected) {
                $council_name = CouncilHelper::getName($selected->{'議會代碼'} ?? '') ?: ($selected->{'議會代碼'} ?? '');
                $district = $selected->{'選區別'} ?? '';
                if ($district === '' || $district === '區域') {
                    $district = $selected->{'區域'} ?? '';
                }
                $this->setOg(
                    ($selected->{'姓名'} ?? '') . ' — ' . $council_name . CCAPI_Council::termLabel($selected->{'議會代碼'} ?? '', $selected->{'屆次'} ?? '') . CCAPI_Council::localize('議員', $selected->{'議會代碼'} ?? ''),
                    trim(($selected->{'黨籍'} ?? '') . '・' . $district . '，共任職 ' . count($records) . ' 屆', '・')
                );
            }

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
            $groups = $this->view->candidate_groups ?? [];
            if ($groups) {
                $latest = $groups[0]->{'candidate'};
                $wins = 0;
                foreach ($groups as $g) {
                    if ($g->{'candidate'}->{'當選'} ?? false) $wins++;
                }
                $this->setOg(
                    ($latest->{'姓名'} ?? '') . ' — 候選人歷年參選紀錄',
                    '共參選 ' . count($groups) . ' 次，當選 ' . $wins . ' 次。' . ($latest->{'縣市'} ?? '')
                );
            }
            return;
        }

        // 選舉指南：/info/election/{年}，即將舉行（或剛辦完）的那次選舉的候選人。不屬於屆次路由：
        // 選前還沒有新的一屆，候選人資料（含登記階段的參選人）放在 candidate index，用年份查
        // /info/election/{年}/town/{鄉鎮市區}：鄉鎮市長、代表、各村里的候選人
        // /info/election/{年}/race/{類別}:{選區別}：單一選舉（例：V0:板橋區華東里）全部候選人的學經歷、政見，像選舉公報
        if ($term_no === 'election' && !CCAPI_Council::isAll($cc_code)) {
            $this->view->is_election_guide = true;
            $year = (int) ($tab ?: self::NEXT_ELECTION_YEAR);
            if ($sub_id === 'race' && $sub_id2) {
                $this->loadElectionRace($year, $sub_id2);
                $this->setOg(
                    $this->view->council_name . '・' . $year . ' 年' . $this->view->race_title . '候選人',
                    $this->view->race_title . '候選人的學歷、經歷與政見。'
                );
                return;
            }
            if ($sub_id === 'town' && $sub_id2) {
                $this->loadElectionTown($year, $sub_id2);
                $this->setOg(
                    $this->view->council_name . $sub_id2 . '・' . $year . ' 年選舉候選人',
                    $sub_id2 . ' ' . $year . ' 年鄉鎮市長、代表與村里長候選人。'
                );
                return;
            }
            $this->loadElectionGuide($year);
            $this->setOg(
                $this->view->council_name . '・' . $this->view->election_year . ' 年選舉候選人',
                $this->view->council_name . '轄區 ' . $this->view->election_year . ' 年地方選舉的縣市長、' . CouncilHelper::t('議員') . '候選人與歷年參選紀錄。'
            );
            return;
        }

        if (CCAPI_Council::isAll($cc_code)) {
            // 全國頁沒有「屆」的概念（各議會屆次互不相干），維持原本的現況卡片牆
            $result = CCAPI::apiQuery('/overviews?limit=50', '全國議會現況資料');
            $this->view->overviews = $result->overviews ?? [];
            return;
        }

        if (!$term_no || $term_no === 'latest') {
            // 沒帶屆次，或帶 latest 佔位字（議會切換器改用這個，避免帶著別議會的屆次號
            // 跳過去，跳到不存在或對不上的屆） → 查最新一屆，導向該屆對應頁面
            $overview = CCAPI::apiQuery('/overview/' . rawurlencode($cc_code), '本議會現況資料（決定最新屆）');
            $latest_term = $overview->data->{'屆次'} ?? null;
            if ($latest_term) {
                $target = '/info/' . $latest_term . '/' . ($tab ?: 'councilors');
                if ($sub_id) {
                    $target .= '/' . $sub_id;
                }
                header('Location: ' . $target, true, 302);
                exit;
            }
            $this->view->term_no = null;
            return;
        }

        $term_no = (int)$term_no;
        // 'bill'／'agenda'／'sitting'／'meet' 是從各自列表頁連結進去的詳情子頁面，
        // 不放進主要 tab 導覽列。'agenda'／'sitting' 是舊版場次/議程詳情頁的代碼，
        // 保留給既有連結相容用，但已經改成單純redirect到新的統一會議詳情頁
        // 'meet'（見indexAction switch裡的處理），不再各自維護一整份頁面邏輯
        $valid_tabs = array_merge(array_keys($this->tabs), ['bill', 'agenda', 'sitting', 'meet']);
        $tab = ($tab && in_array($tab, $valid_tabs)) ? $tab : 'councilors';

        $this->view->term_no = $term_no;
        $this->view->tab = $tab;
        $this->view->tabs = $this->tabs = array_map([CouncilHelper::class, 't'], $this->tabs);
        $this->view->all_terms = $this->loadAllTerms($cc_code);
        $this->view->header = $this->loadHeader($cc_code, $term_no);
        $this->setOg(
            $this->view->council_name . CCAPI_Council::termLabel($cc_code, $term_no) . '・' . ($this->tabs[$tab] ?? $tab),
            $this->view->council_name . CCAPI_Council::termLabel($cc_code, $term_no) . '的' . CouncilHelper::t('議員') . '、會期、議案等開放資料。'
        );

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
            case 'agencies':
                // 行政體系底下分「縣市政府機關」與「村里長」（/info/{屆}/agencies/villages[/{鄉鎮市區}]），
                // 村里長只有縣市議會才有（立法院等其他議會種類沒有對應的村里）
                $this->view->has_villages = CCAPI_Council::getKind($cc_code) === '縣市議會';
                // 縣（有鄉鎮市長選舉）的分頁叫「鄉鎮市／村里長」，直轄市、省轄市（區長官派）維持「村里長」
                $this->view->has_townships = $this->view->has_villages && (CCAPI::apiQuery(
                    '/candidates?limit=0&' . urlencode('參選職位') . '=' . urlencode('鄉鎮市長'),
                    '本議會有沒有鄉鎮市長選舉資料'
                )->total ?? 0) > 0;
                $this->view->agency_section = ($sub_id === 'villages' && $this->view->has_villages) ? 'villages'
                    : ($sub_id ? 'detail' : 'agencies');
                if ($this->view->agency_section === 'villages') {
                    $this->loadVillagesTab($cc_code, $term_no, $sub_id2);
                } elseif ($this->view->agency_section === 'detail') {
                    $this->view->agency_page = in_array($sub_id2, ['tenders', 'speeches', 'news', 'budget'], true) ? $sub_id2 : 'info';
                    $this->loadAgencyDetail($term_no, urldecode($sub_id), $this->view->agency_page);
                    if ($a = $this->view->agency ?? null) {
                        $full = ($a->{'全名'} ?? '') ?: $a->{'名稱'};
                        $this->setOg(
                            $full . '・行政體系',
                            $this->view->council_name . '監督的行政機關：' . $full . '的首長、所屬機關與標案'
                        );
                    }
                } else {
                    $this->view->agency_tree = $this->loadAgencyTree($cc_code, $term_no);
                    if (in_array($cc_code, ['ly', 'tpg'], true)) {
                        $this->loadPresidents($cc_code);
                    }
                }
                break;
            case 'committees':
                $this->view->committee_groups = $this->loadCommittees($cc_code);
                $this->loadCommitteeMembers($term_no);
                break;
            case 'bill':
                $bill = $this->loadBillDetail($sub_id);
                $this->resolveBillPeople($bill);
                $this->view->bill_detail = $bill;
                if ($bill) {
                    $this->setOg(
                        ($bill->{'案由'} ?? '議案詳情'),
                        $this->view->council_name . CCAPI_Council::termLabel($cc_code, $bill->{'屆'} ?? $term_no) . '・' . ($bill->{'案號'} ?? '')
                    );
                }
                break;
            case 'sitting':
                // 舊版場次詳情頁已經整個retire，改成單純redirect到新的統一會議
                // 頁面（見PLAN.md議程功能retire的說明），這裡直接處理完就return，
                // 不會繼續往下執行view render
                $this->redirectSittingToMeet($term_no, $sub_id);
                break;
            case 'agenda':
                // 舊版議程詳情頁的代碼相容：9個「agenda代碼=meet代碼」的縣市
                // （高雄/雲林/臺南/臺東/新竹縣/新竹市/連江/桃園/屏東）舊連結會
                // 正確導頁；其餘6個委員會拆分縣市的舊agenda連結因為代碼對不起來
                // 會導向不存在的meet、顯示「找不到會議資料」，這批連結數量少
                // （多半是議程功能開發期間產生的測試連結），可接受
                if ($sub_id) {
                    header('Location: /info/' . $term_no . '/meet/' . urlencode($sub_id), true, 302);
                } else {
                    header('Location: /info/' . $term_no . '/sessions', true, 302);
                }
                exit;
            case 'meet':
                $this->loadMeetTab($sub_id);
                if ($this->view->meet_meta ?? null) {
                    $m = $this->view->meet_meta;
                    $this->setOg(
                        ($m->{'日期'} ?? '') . '・' . ($m->{'委員會或主旨'} ?? '會議詳情'),
                        $this->view->council_name . CCAPI_Council::termLabel($cc_code, $term_no) . '・' . ($m->{'場次代碼'} ?? '')
                    );
                }
                break;
        }
    }

    /**
     * 給社群分享／預覽卡片（og:title／og:description）用，views/info/index.php
     * 的共用 <head> 會輸出這兩個值。內容都是純文字，view 那邊再做 htmlspecialchars
     */
    protected function setOg($title, $description)
    {
        $this->view->og_title = $title;
        $this->view->og_description = $description;
    }

    protected function loadAllTerms($cc_code)
    {
        $r = CCAPI::apiQuery('/terms?limit=100&sort=' . urlencode('屆次>'), '本議會所有屆期');
        return $r->terms ?? [];
    }

    protected function loadHeader($cc_code, $term_no)
    {
        $term = CCAPI::apiQuery('/term/' . rawurlencode($cc_code) . '/' . $term_no, '本屆屆期資料');
        $this->term_data = $term->data ?? null;
        $speaker = CCAPI::apiQuery(
            '/councilors?limit=1&' . urlencode('屆次') . '=' . $term_no . '&' . urlencode('職稱') . '=' . urlencode(CouncilHelper::t('議長')),
            CouncilHelper::t('本屆議長')
        );
        $deputy = CCAPI::apiQuery(
            '/councilors?limit=1&' . urlencode('屆次') . '=' . $term_no . '&' . urlencode('職稱') . '=' . urlencode(CouncilHelper::t('副議長')),
            CouncilHelper::t('本屆副議長')
        );
        $count = CCAPI::apiQuery('/councilors?limit=0&' . urlencode('屆次') . '=' . $term_no, CouncilHelper::t('本屆議員人數'));

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
        $r = CCAPI::apiQuery('/councilors?limit=300&' . urlencode('屆次') . '=' . $term_no, CouncilHelper::t('本屆議員名單'));
        $councilors = $r->councilors ?? [];
        $this->attachVoteShare($councilors);
        $groups = $this->groupCouncilorsByDistrict($councilors);
        $this->attachPartyListWaiting($groups, $councilors);
        return $groups;
    }

    /**
     * 現任屆次的不分區各政黨分組附上「待遞補」名單：同一份政黨名單裡還沒就任過的
     * 候選人（依名單排序），放在該政黨分組的 waiting。只有現任屆次才有意義，歷屆不附。
     */
    protected function attachPartyListWaiting(&$groups, $councilors)
    {
        if (!($this->term_data->{'現任'} ?? false)) {
            return;
        }
        $election_code = null;
        $served = [];
        foreach ($councilors as $c) {
            $code = $c->{'參選代碼'} ?? '';
            if (strpos($code, ':party-') !== false) {
                $served[$code] = true;
                $election_code = $election_code ?: ($c->{'選舉代碼'} ?? null);
            }
        }
        if (!$election_code) {
            return;
        }
        $r = CCAPI::apiQuery(
            '/candidates?limit=500&' . urlencode('選舉代碼') . '=' . urlencode($election_code)
                . '&' . urlencode('選區別') . '=' . urlencode('全國不分區'),
            '不分區政黨名單（待遞補）'
        );
        $waiting_by_party = [];
        foreach (($r->candidates ?? []) as $cand) {
            if (!isset($served[$cand->{'候選人代碼'} ?? ''])) {
                $waiting_by_party[$cand->{'其他欄位'}->{'推薦之政黨'} ?? ''][] = $cand;
            }
        }
        foreach ($groups as &$group) {
            if (empty($group['subgroups'])) {
                continue;
            }
            foreach ($group['subgroups'] as &$sub) {
                $list = $waiting_by_party[$sub['label']] ?? [];
                usort($list, fn($a, $b) => intval($a->{'號次'} ?? 0) <=> intval($b->{'號次'} ?? 0));
                $sub['waiting'] = $list;
            }
            unset($sub);
        }
        unset($group);
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
                // candidate的得票數/得票率/得票排名只在111年這次選舉才有資料（來源
                // 得票數.jsonl目前只回溯到111年，見PLAN.md這次調查記錄），較舊屆次
                // 這三個欄位在candidate record上會是null——這裡不能無條件覆蓋，
                // 否則議員record自己原本就有的得票數（來自vote.csv，回溯年份廣得多，
                // 見mixed-tw.gov-議會-議員資料/crawl.php的enrich_councilor_from_cec()）
                // 會被這個null蓋掉，列表頁反而從「有筆數、沒有率」退化成「完全沒有」
                if (isset($cand->{'得票數'})) {
                    $c->{'得票數'} = $cand->{'得票數'};
                }
                if (isset($cand->{'得票率'})) {
                    $c->{'得票率'} = $cand->{'得票率'};
                }
                if (isset($cand->{'得票排名'})) {
                    $c->{'得票排名'} = $cand->{'得票排名'};
                }
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
            if ($district_no !== null && $district_no !== '') {
                if ($district_name === '' || $district_name === '區域') {
                    $district_name = "第{$district_no}選舉區";
                }
                $key = (int)$district_no;
            } else {
                // 沒有選舉區號（例：立法委員的選區跨縣市，不是單一議會內的編號）→
                // 區域選出的依縣市分組，原住民／不分區依選區名稱分組
                $district_name = ($c->{'區域'} ?? '') ?: (($district_name !== '' && $district_name !== '區域') ? $district_name : '選區不詳');
                $key = 'name:' . $district_name;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['label' => $district_name, 'councilors' => []];
            }
            $groups[$key]['councilors'][] = $c;
        }
        // 有編號的選區依編號排；沒編號的依縣市（議會清單順序）、原住民、不分區排
        $county_order = array_flip(array_map(
            fn($n) => preg_replace('/議會$/u', '', $n),
            array_values(CouncilHelper::getAll())
        ));
        // 已改制的縣排在改制後的直轄市後面（立法委員早期屆次的選區）
        $merged_into = ['臺北縣' => '新北市', '桃園縣' => '桃園市', '臺中縣' => '臺中市', '臺南縣' => '臺南市', '高雄縣' => '高雄市'];
        $name_rank = function ($label) use ($county_order, $merged_into) {
            $label = str_replace('台', '臺', $label);
            if (preg_match('/不分區|僑/u', $label)) return [3, 0];
            if (preg_match('/原住民|山胞/u', $label)) return [2, 0];
            if (isset($merged_into[$label])) return [1, ($county_order[$merged_into[$label]] ?? PHP_INT_MAX) + 0.5];
            return [1, $county_order[$label] ?? PHP_INT_MAX];
        };
        uksort($groups, function ($a, $b) use ($groups, $name_rank) {
            if (is_int($a) && is_int($b)) return $a <=> $b;
            if (is_int($a)) return -1;
            if (is_int($b)) return 1;
            return [$name_rank($groups[$a]['label']), $a] <=> [$name_rank($groups[$b]['label']), $b];
        });

        foreach ($groups as &$group) {
            usort($group['councilors'], function ($a, $b) {
                // 沒有選舉區號的分組（立法委員依縣市分組）先依選區排，同選區再比得票率
                if (($a->{'選舉區號'} ?? '') === '' && ($b->{'選舉區號'} ?? '') === '') {
                    $cmp = strnatcmp($a->{'選區別'} ?? '', $b->{'選區別'} ?? '');
                    if ($cmp !== 0) return $cmp;
                }
                $va = $a->{'得票率'} ?? null;
                $vb = $b->{'得票率'} ?? null;
                if ($va === null && $vb === null) return strnatcmp($a->{'選區別'} ?? '', $b->{'選區別'} ?? '');
                if ($va === null) return 1;
                if ($vb === null) return -1;
                return $vb <=> $va;
            });
        }
        unset($group);

        // 不分區（政黨名單）再依參選政黨分組：有政黨票的屆次依得票數多到少排、標上
        // 得票數與得票率，沒有的（立法院第3～6屆依區域得票分配）依人數多到少排；
        // 組內依名單排序（號次）
        $party_votes = [];
        foreach (($this->term_data->{'政黨票'} ?? []) as $p) {
            $party_votes[$p->{'政黨'}] = $p;
        }
        foreach ($groups as &$group) {
            if (!preg_match('/不分區|僑/u', $group['label'])) {
                continue;
            }
            $by_party = [];
            foreach ($group['councilors'] as $c) {
                $by_party[($c->{'參選政黨'} ?? null) ?: (($c->{'黨籍'} ?? null) ?: '無黨籍')][] = $c;
            }
            uksort($by_party, function ($a, $b) use ($party_votes, $by_party) {
                $va = $party_votes[$a]->{'得票數'} ?? -1;
                $vb = $party_votes[$b]->{'得票數'} ?? -1;
                return [$vb, count($by_party[$b])] <=> [$va, count($by_party[$a])];
            });
            $group['subgroups'] = [];
            foreach ($by_party as $party => $members) {
                usort($members, fn($a, $b) => [($a->{'號次'} ?? null) === null, $a->{'號次'} ?? 0] <=> [($b->{'號次'} ?? null) === null, $b->{'號次'} ?? 0]);
                $pv = $party_votes[$party] ?? null;
                $group['subgroups'][] = [
                    'label'      => $party,
                    'note'       => $pv ? ('得票 ' . number_format($pv->{'得票數'}) . ' 票（' . $pv->{'得票率'} . '%）') : null,
                    'party'      => $pv,   // 政黨票資料，含公報上的政黨政見（政見／政見來源／政見圖路徑）
                    'councilors' => $members,
                ];
            }
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
    /**
     * 委員會成員：立法委員資料逐會期記錄所屬委員會（「委員會」欄位，格式
     * 「第11屆第1會期：內政委員會」），取本屆最近一個有資料的會期，依委員會名稱
     * 分組。地方議會沒有這個欄位，結果是空的，畫面照舊只列委員會清單。
     */
    protected function loadCommitteeMembers($term_no)
    {
        $this->view->committee_members = [];
        $this->view->committee_members_period = null;
        $r = CCAPI::apiQuery(
            '/councilors?limit=300&' . urlencode('屆次') . '=' . $term_no,
            '本屆委員會成員'
        );
        $by_period = [];
        foreach (($r->councilors ?? []) as $c) {
            foreach ((array) ($c->{'委員會'} ?? []) as $entry) {
                if (preg_match('/^第(\d+)屆第(\d+)會期：(.+)$/u', $entry, $m) && (int) $m[1] === (int) $term_no) {
                    $by_period[(int) $m[2]][$m[3]][] = $c;
                }
            }
        }
        if (!$by_period) {
            return;
        }
        $period = max(array_keys($by_period));
        $this->view->committee_members_period = $period;
        $this->view->committee_members = $by_period[$period];
    }

    /**
     * 行政體系 tab：議會監督的行政機關（agency），依縣市政府網站上的順序分成一級內部單位／
     * 一級外部單位（底下掛二級機關），學校、戶政所、區公所等其他單位另外分組；每個機關附上
     * 本屆在議會答詢的官員（從會議逐字稿「對應代碼」彙整，機關的代碼去掉議會代碼前綴就是
     * 逐字稿用的那個代碼，縣市政府的內部單位也是同一套）
     */
    protected function loadAgencyTree($cc_code, $term_no)
    {
        $r = CCAPI::apiQuery('/agencies?limit=1000&sort=' . urlencode('排序<'), '本議會監督的行政機關');
        $agencies = $r->agencies ?? [];
        if (!$agencies) {
            return null;
        }

        $children = [];
        foreach ($agencies as $a) {
            $a->_head = $this->pickAgencyHead($a, $term_no);
            if ($a->{'上層代碼'} ?? null) {
                $children[$a->{'上層代碼'}][] = $a;
            }
        }

        // 立法院：中央機關（院 → 二級機關 → 國營事業），import-agency.php 依人事行政總處機關代碼匯入
        if (array_filter($agencies, fn($a) => ($a->{'分類'} ?? '') === '院')) {
            $tree = ['院' => [], '國營事業' => []];
            foreach ($agencies as $a) {
                $a->_children = array_values(array_filter($children[$a->{'代碼'}] ?? [], fn($c) => ($c->{'分類'} ?? '') === '二級機關'));
                $a->_soe = array_values(array_filter($children[$a->{'代碼'}] ?? [], fn($c) => ($c->{'分類'} ?? '') === '國營事業'));
                if (($a->{'分類'} ?? '') === '院') {
                    $tree['院'][] = $a;
                } elseif ($a->_soe) {
                    $tree['國營事業'][] = $a;
                }
            }
            return $tree;
        }

        $tree = ['縣市政府' => null, '一級內部單位' => [], '一級外部單位' => [], '其他單位' => []];
        foreach ($agencies as $a) {
            $category = $a->{'分類'} ?? '其他單位';
            $a->_children = array_values(array_filter($children[$a->{'代碼'}] ?? [], fn($c) => ($c->{'分類'} ?? '') !== '其他單位'));
            if ($category === '縣市政府') {
                $a->_mayor = $this->pickMayor($a);
                // 縣市長姓名連到候選人歷年參選頁（學經歷、政見），用當選人的候選人代碼查人物代碼
                if ($a->_mayor) {
                    $this->attachPersonCodes([$a->_mayor], '縣市長的候選人資料');
                }
                $tree['縣市政府'] = $a;
            } elseif ($category === '其他單位') {
                // 其他單位依性質分組，收合顯示
                $name = $a->{'名稱'};
                $kind = preg_match('/(學校|國民小學|國民中學|國民中小學|高級中學|高中|國小|國中|幼兒園|托兒所|大學)/u', $name) ? '學校'
                    : (preg_match('/戶政/u', $name) ? '戶政事務所'
                    : (preg_match('/地政/u', $name) ? '地政事務所'
                    : (preg_match('/區公所/u', $name) ? '區公所'
                    : (preg_match('/(鄉|鎮|市)公所|代表會/u', $name) ? '鄉鎮市公所／代表會'
                    : (preg_match('/衛生所/u', $name) ? '衛生所' : '其他')))));
                $tree['其他單位'][$kind][] = $a;
            } elseif (!($a->{'上層代碼'} ?? null) || $category !== '二級機關') {
                $tree[$category === '二級機關' ? '一級外部單位' : $category][] = $a;
            }
        }
        return $tree;
    }

    /**
     * 縣市長：中選會歷屆當選人（縣市政府的「歷任首長」），取這一屆議會選舉同年（或之前最近一次）選出的
     */
    protected function pickMayor($a)
    {
        $vote_date = $this->term_data->{'投票日'} ?? ($this->term_data->{'就職日'} ?? null);
        $roc_year = $vote_date ? ((int) substr($vote_date, 0, 4) - 1911) : null;
        $mayor = null;
        foreach (($a->{'歷任首長'} ?? []) as $m) {
            if ($roc_year && $m->{'年份'} <= $roc_year && (!$mayor || $m->{'年份'} > $mayor->{'年份'})) {
                $mayor = $m;
            }
        }
        return $mayor;
    }

    /**
     * 中選會當選人（有候選人代碼的）一次查出人物代碼，寫進 _person_code，姓名才能連到候選人歷年參選頁
     */
    protected function attachPersonCodes($list, $reason)
    {
        $codes = array_values(array_unique(array_filter(array_map(fn($m) => $m->{'候選人代碼'} ?? null, $list))));
        if (!$codes) {
            return;
        }
        $query = implode('&', array_map(fn($c) => urlencode('候選人代碼') . '=' . urlencode($c), $codes));
        $r = CCAPI::apiQuery('/candidates?limit=' . count($codes) . '&' . $query, $reason);
        $person = [];
        foreach (($r->candidates ?? []) as $c) {
            $person[$c->{'候選人代碼'}] = $c->{'人物代碼'} ?? null;
        }
        foreach ($list as $m) {
            $m->_person_code = $person[$m->{'候選人代碼'} ?? ''] ?? null;
        }
    }

    /**
     * 機關本屆最後一次答詢的首長級職稱當作（最新）首長；首長紀錄由 generate-agency-heads.php
     * 從會議逐字稿彙整
     */
    protected function pickAgencyHead($a, $term_no)
    {
        $head = null;
        foreach (($a->{'首長紀錄'} ?? []) as $rec) {
            if ((int) $rec->{'屆'} === (int) $term_no && preg_match(self::HEAD_TITLE_PATTERN, $rec->{'職稱'} ?? '')
                && (!$head || $rec->{'最後'} > $head->{'最後'})) {
                $head = $rec;
            }
        }
        return $head;
    }

    /**
     * 人事行政總處機關代碼換成政府電子採購網的機關代碼（pcc-api 的 unit_id）：第一碼照抄，
     * 之後每兩碼一層轉成整數，去掉結尾的 0，例：379110500A → 3.79.11.5
     */
    public static function pccUnitId($org_code)
    {
        if (!preg_match('/^([0-9A-Z])(\d{2})(\d{2})(\d{2})(\d{2})[A-Z]$/', (string) $org_code, $m)) {
            return null;
        }
        $parts = array_merge([$m[1]], array_map('intval', array_slice($m, 2)));
        while (count($parts) > 1 && end($parts) === 0) {
            array_pop($parts);
        }
        return implode('.', $parts);
    }

    /**
     * 行政體系 → 單一機關頁：/info/{屆}/agencies/{機關代碼}，顯示首長、上層與所屬機關，
     * 標案列表由前端直接呼叫 pcc-api（依 unit_id 查詢）。縣市政府的內部單位（代碼
     * {縣市政府代碼}-U……）在採購網沒有自己的機關代碼，標案掛在縣市政府底下，只能靠
     * 標案的「單位名稱」區分
     */
    protected function loadAgencyDetail($term_no, $code, $page = 'info')
    {
        $r = CCAPI::apiQuery('/agency/' . rawurlencode($code), '行政機關資料');
        $agency = ($r->error ?? true) ? null : $r->data;
        $this->view->agency = $agency;
        if (!$agency) {
            return;
        }
        $agency->_head = $this->pickAgencyHead($agency, $term_no);

        $is_internal = !($agency->{'機關代碼'} ?? null) && preg_match('/^(.+)-U\w+$/', $agency->{'代碼'}, $m);
        $parent_code = $agency->{'上層代碼'} ?? ($is_internal ? $m[1] : null);
        $parent = null;
        if ($parent_code) {
            $r = CCAPI::apiQuery('/agency/' . rawurlencode($parent_code), '上層機關資料');
            $parent = ($r->error ?? true) ? null : $r->data;
        } elseif ($this->isTopLevelAgency($agency)) {
            // 縣市政府直屬的一級單位沒有上層代碼，上層就是縣市政府
            $r = CCAPI::apiQuery('/agencies?limit=1&' . urlencode('分類') . '=' . urlencode('縣市政府'), '縣市政府');
            $parent = $r->agencies[0] ?? null;
        }
        $this->view->agency_parent = $parent;

        $r = CCAPI::apiQuery(
            '/agencies?limit=1000&sort=' . urlencode('排序<') . '&' . urlencode('上層代碼') . '=' . urlencode($agency->{'代碼'}),
            '所屬機關'
        );
        $children = $r->agencies ?? [];
        foreach ($children as $c) {
            $c->_head = $this->pickAgencyHead($c, $term_no);
        }
        $this->view->agency_children = $children;

        // 標案查詢條件：有機關代碼的直接換算；內部單位用縣市政府的 unit_id，前端再依單位名稱標示
        $this->view->pcc_unit_id = self::pccUnitId($agency->{'機關代碼'} ?? null);
        $this->view->pcc_dept_name = null;
        if (!$this->view->pcc_unit_id && $is_internal && $parent) {
            $this->view->pcc_unit_id = self::pccUnitId($parent->{'機關代碼'} ?? null);
            $this->view->pcc_dept_name = $agency->{'名稱'};
        }
        if ($page === 'speeches') {
            $this->loadAgencySpeeches($agency, $children, $term_no);
        } elseif ($page === 'news') {
            $this->loadAgencyNews($agency, $children, $is_internal ? $parent : null);
        } elseif ($page === 'budget') {
            $this->loadAgencyBudget($agency, $is_internal ? $parent : null);
        } elseif ($page === 'info') {
            $this->loadAgencyInfo($agency, $parent, $term_no);
        } elseif ($this->view->pcc_unit_id) {
            $this->loadAgencyTenders($this->view->pcc_unit_id, $this->view->pcc_dept_name, (bool) $children && !$this->view->pcc_dept_name);
        }
    }

    /**
     * 縣市政府直屬的一級單位（沒有上層代碼、不是縣市政府本身或學校戶所等其他單位）
     */
    public function isTopLevelAgency($a)
    {
        return !($a->{'上層代碼'} ?? null) && in_array($a->{'分類'} ?? '', ['一級內部單位', '一級外部單位', '二級機關'], true);
    }

    /**
     * 單一機關頁 → 基本資料：上層機關一路到最上層（麵包屑）、同層機關、首長與歷任首長。
     * 縣市政府的首長是中選會歷屆縣市長；其他機關是各屆在議會答詢過的首長級官員（首長紀錄）
     */
    protected function loadAgencyInfo($agency, $parent, $term_no)
    {
        $ancestors = [];
        for ($p = $parent; $p && count($ancestors) < 6; ) {
            array_unshift($ancestors, $p);
            $code = $p->{'上層代碼'} ?? null;
            $r = $code ? CCAPI::apiQuery('/agency/' . rawurlencode($code), '上層機關資料') : null;
            $p = ($r && !($r->error ?? true)) ? $r->data : null;
        }
        $this->view->agency_ancestors = $ancestors;

        // 縣市政府底下的一級單位沒有上層代碼，所屬／同層機關要另外查（跟行政體系總覽的分法一致）
        $top_level = null;
        if (($agency->{'分類'} ?? '') === '縣市政府' || ($parent && ($parent->{'分類'} ?? '') === '縣市政府' && !($agency->{'上層代碼'} ?? null))) {
            $r = CCAPI::apiQuery('/agencies?limit=1000&sort=' . urlencode('排序<'), '本議會監督的行政機關');
            $top_level = array_values(array_filter($r->agencies ?? [], [$this, 'isTopLevelAgency']));
            foreach ($top_level as $t) {
                $t->_head = $this->pickAgencyHead($t, $term_no);
            }
        }
        if (($agency->{'分類'} ?? '') === '縣市政府') {
            $this->view->agency_children = $top_level;
        }

        $siblings = [];
        if ($top_level !== null && $parent) {
            $siblings = array_values(array_filter($top_level, fn($s) => $s->{'代碼'} !== $agency->{'代碼'}));
        } elseif ($parent) {
            $r = CCAPI::apiQuery(
                '/agencies?limit=1000&sort=' . urlencode('排序<') . '&' . urlencode('上層代碼') . '=' . urlencode($parent->{'代碼'}),
                '同層機關'
            );
            $siblings = array_values(array_filter($r->agencies ?? [], fn($s) => $s->{'代碼'} !== $agency->{'代碼'}));
            foreach ($siblings as $s) {
                $s->_head = $this->pickAgencyHead($s, $term_no);
            }
        }
        $this->view->agency_siblings = $siblings;

        if (($agency->{'分類'} ?? '') === '縣市政府') {
            $agency->_mayor = $this->pickMayor($agency);
            $history = $agency->{'歷任首長'} ?? [];
            usort($history, fn($x, $y) => $y->{'年份'} <=> $x->{'年份'});
            $this->attachPersonCodes($history, '歷任縣市長的候選人資料');
            $this->view->agency_mayors = $history;
            return;
        }
        $heads = array_values(array_filter($agency->{'首長紀錄'} ?? [], fn($rec) => preg_match(self::HEAD_TITLE_PATTERN, $rec->{'職稱'} ?? '')));
        usort($heads, fn($x, $y) => [$y->{'屆'}, $y->{'最後'}] <=> [$x->{'屆'}, $x->{'最後'}]);
        $this->view->agency_heads = $heads;
    }

    /**
     * 單一機關頁 → 議會發言記錄：本屆會議逐字稿裡這個機關官員的發言（逐字稿的「對應代碼」
     * 是機關代碼去掉議會代碼前綴，縣市政府內部單位也是同一套），每頁 50 筆（問候、簡短應答在頁面上收起來）、依日期新到舊，
     * 同一場會議的發言併在一起。?children=1 連同所屬機關（預設有所屬機關就含）
     */
    protected function loadAgencySpeeches($agency, $children, $term_no)
    {
        $cc_prefix = ($agency->{'議會代碼'} ?? '') . '-';
        $strip = fn($c) => strpos($c, $cc_prefix) === 0 ? substr($c, strlen($cc_prefix)) : $c;
        $with_children = $children && (isset($_GET['children']) ? $_GET['children'] === '1' : true);
        $codes = [$strip($agency->{'代碼'})];
        if ($with_children) {
            foreach ($children as $c) {
                $codes[] = $strip($c->{'代碼'});
            }
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $qs = urlencode('屆') . '=' . (int) $term_no . '&' . urlencode('對應代碼類型') . '=' . urlencode('機關')
            . implode('', array_map(fn($c) => '&' . urlencode('對應代碼') . '=' . urlencode($c), $codes));
        $r = CCAPI::apiQuery(
            '/meet_transcripts?limit=50&page=' . $page . '&' . $qs
                . '&sort=' . urlencode('日期>') . '&sort=' . urlencode('順序<')
                . '&agg=' . urlencode('姓名')
                . '&output_fields=' . urlencode('代碼') . '&output_fields=' . urlencode('會議代碼')
                . '&output_fields=' . urlencode('會期代碼') . '&output_fields=' . urlencode('日期')
                . '&output_fields=' . urlencode('姓名') . '&output_fields=' . urlencode('職稱')
                . '&output_fields=' . urlencode('對應單位全名') . '&output_fields=' . urlencode('發言內容'),
            '這個機關官員在議會的發言（逐字稿「對應代碼」精確比對，依日期新到舊）'
        );
        $items = $r->meet_transcripts ?? [];

        // 補上會議的委員會或主旨：每個會期查一次該會期全部會議，不逐筆查
        $meets_by_code = [];
        foreach (array_unique(array_map(fn($t) => $t->{'會期代碼'} ?? '', $items)) as $session_code) {
            if ($session_code === '') continue;
            foreach ($this->loadMeetsForSession($session_code) as $meets_of_sitting) {
                foreach ($meets_of_sitting as $m) {
                    $meets_by_code[$m->{'代碼'}] = $m;
                }
            }
        }
        // 同一場會議的連續發言併成一組（已依日期新到舊、會議內依順序排列）
        $groups = [];
        foreach ($items as $t) {
            $meet_code = $t->{'會議代碼'} ?? '';
            $last = end($groups);
            if (!$last || $last->{'會議代碼'} !== $meet_code) {
                $groups[] = (object) [
                    '會議代碼' => $meet_code,
                    '日期' => $t->{'日期'} ?? '',
                    '會期名稱' => CCAPI_Type_Session::getFriendlyName($t->{'會期代碼'} ?? ''),
                    '會議主旨' => $meets_by_code[$meet_code]->{'委員會或主旨'} ?? null,
                    'items' => [],
                ];
            }
            $groups[count($groups) - 1]->items[] = $t;
        }

        $speakers = [];
        foreach (($r->aggs ?? []) as $agg) {
            if (($agg->agg ?? '') === '姓名') {
                $speakers = array_slice($agg->buckets ?? [], 0, 10);
            }
        }

        // 查無發言時分清楚是「這個議會逐字稿還沒收錄」還是真的沒有發言
        $no_coverage = false;
        if (!$items && $page === 1) {
            $comp = CCAPI::apiQuery('/completeness/' . rawurlencode($agency->{'議會代碼'} ?? ''), '議會逐字稿收錄狀態（判斷查無發言記錄的真正原因）');
            $transcript = $comp->data->types->transcript ?? null;
            $no_coverage = $transcript && ($transcript->total ?? 0) === 0;
        }

        $this->view->agency_speeches = [
            'groups' => $groups,
            'total' => (int) ($r->total ?? 0),
            'total_page' => (int) ($r->total_page ?? 0),
            'page' => $page,
            'children' => $with_children,
            'speakers' => $speakers,
            'no_coverage' => $no_coverage,
        ];
    }

    /**
     * 單一機關頁 → 新聞公告：後端呼叫 elastic-govnews（全政府新聞公告），org_id 就是機關代碼
     * 去掉議會代碼前綴（縣市政府內部單位的代碼 {縣市政府代碼}-U…… 也是同一套）。內部單位
     * 沒有自己來源的，另外查縣市政府公告裡「發布單位」以單位名稱開頭的。?children=1 連同
     * 所屬機關（預設有所屬機關就含），?q= 在這個範圍內搜尋，每頁 20 筆、依時間新到舊
     */
    protected function loadAgencyNews($agency, $children, $internal_parent)
    {
        $cc_prefix = ($agency->{'議會代碼'} ?? '') . '-';
        $strip = fn($c) => strpos($c, $cc_prefix) === 0 ? substr($c, strlen($cc_prefix)) : $c;
        $quote = fn($v) => '"' . addcslashes($v, '"\\') . '"';
        $with_children = $children && (isset($_GET['children']) ? $_GET['children'] === '1' : true);
        $org_ids = [$strip($agency->{'代碼'})];
        if ($with_children) {
            foreach ($children as $c) {
                $org_ids[] = $strip($c->{'代碼'});
            }
        }
        $clauses = array_map(fn($id) => 'org_id.keyword:' . $quote($id), $org_ids);
        if ($internal_parent) {
            // query_string 語法的特殊字元要跳脫（單位名稱可能有括號、空白）
            $unit_prefix = addcslashes($agency->{'名稱'}, '+-=&|><!(){}[]^"~*?:\\/ ');
            $clauses[] = '(org_id.keyword:' . $quote($strip($internal_parent->{'代碼'})) . ' AND unit.keyword:' . $unit_prefix . '*)';
        }
        // ?q= 關鍵字：空白分開的每個詞都要出現在標題或內文（中文用片語比對，跟 govnews 建議的 match_phrase 一樣）
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $query = '(' . implode(' OR ', $clauses) . ')';
        foreach (array_filter(preg_split('/\s+/u', $keyword)) as $term) {
            $query .= ' AND (title:' . $quote($term) . ' OR content_text:' . $quote($term) . ')';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $r = CCAPI::externalQuery(
            'https://elastic-govnews.api.openfun.dev/gov-news_announcements/_search?' . http_build_query([
                'q' => $query,
                'sort' => 'time:desc',
                'size' => 20,
                'from' => ($page - 1) * 20,
                'track_total_hits' => 'true',
                '_source' => 'title,org_name,unit,category,time,source_url',
            ]),
            '這個機關的新聞稿與公告（全政府公告搜尋 API）',
            'https://data.openfun.tw/datasets/tw.openfun~api~elastic-govnews/skill.md'
        );
        $total = (int) ($r->hits->total->value ?? 0);
        $this->view->agency_news = [
            'ok' => isset($r->hits),
            'items' => array_map(fn($h) => $h->_source, $r->hits->hits ?? []),
            'total' => $total,
            'total_page' => (int) ceil(min($total, 10000) / 20),
            'page' => $page,
            'children' => $with_children,
            'keyword' => $keyword,
        ];
    }

    /**
     * 單一機關頁的預算：後端呼叫 budget.openfun.app 的 /agency/{縣市}/{機關代碼}（歷年總額＋指定年度
     * 的工作計劃）。縣市政府內部單位沒有自己的單位預算，改查縣市政府、只留「承辦單位」是這個單位的
     * 工作計劃；承辦單位只有部分年度有，沒指定年度時往前找最近有承辦單位的年度。?year=&type=歲出|歲入
     */
    protected function loadAgencyBudget($agency, $internal_parent)
    {
        // 立法院監督的中央機關在 budget API 的縣市是「中央」
        $county = ($agency->{'議會代碼'} ?? '') === 'ly' ? '中央' : array_search($agency->{'議會代碼'} ?? '', CountyCodeHelper::getMap(), true);
        $org_code = $internal_parent ? ($internal_parent->{'機關代碼'} ?? null) : ($agency->{'機關代碼'} ?? null);
        $type = ($_GET['type'] ?? '') === '歲入' ? '歲入' : '歲出';
        $year = (int) ($_GET['year'] ?? 0);
        $this->view->agency_budget = ['ok' => true, 'type' => $type, 'year' => null, 'years' => [], 'history' => [], 'plans' => [], 'unit' => $internal_parent ? $agency->{'名稱'} : null];
        if (!$county || !$org_code) {
            return;
        }
        $fetch = fn($y) => CCAPI::externalQuery(
            'https://budget.openfun.app/api/agency/' . rawurlencode($county) . '/' . rawurlencode($org_code) . '?' . http_build_query(array_filter(['year' => $y, 'type' => $type])),
            $internal_parent ? '縣市政府的工作計劃，篩出這個單位承辦的（政府預算查詢平台 API）' : '這個機關的歷年預算與工作計劃（政府預算查詢平台 API）',
            'https://budget.openfun.app/skill.md'
        );
        $r = $fetch($year ?: null);
        if (!isset($r->歷年)) {
            $this->view->agency_budget['ok'] = false;
            return;
        }
        $years = array_map(fn($h) => (int) $h->年, $r->歷年);
        $mine = fn($r) => array_values(array_filter($r->工作計劃 ?? [], fn($p) => in_array($agency->{'名稱'}, (array) ($p->承辦單位 ?? []), true)));
        $plans = $internal_parent ? $mine($r) : ($r->工作計劃 ?? []);
        if ($internal_parent && !$year && !$plans) {
            foreach (array_slice(array_filter($years, fn($y) => $y < (int) $r->year), 0, 3) as $y) {
                $older = $fetch($y);
                if ($plans = $older ? $mine($older) : []) {
                    $r->year = $older->year;
                    break;
                }
            }
        }
        $this->view->agency_budget = array_merge($this->view->agency_budget, [
            'year' => (int) $r->year,
            'years' => $years,
            'history' => $internal_parent ? [] : $r->歷年,
            'plans' => $plans,
        ]);
    }

    // 標案列表的公告類型篩選（pcc-api 的 type 是完全比對）
    const TENDER_TYPES = ['' => '全部公告', '公開招標公告' => '公開招標', '決標公告' => '決標'];

    /**
     * 單一機關頁的標案：後端呼叫 pcc-api 的 listbyunit（公告列表，每頁 20 筆）與 unitstat
     * （各年度件數、決標金額、得標廠商排行）。篩選條件放在網址 ?type=&children=&page=；
     * children 沒帶時，有所屬機關的預設含所屬機關。縣市政府內部單位用 dept_name（標案的
     * 「機關資料:單位名稱」前綴）在縣市政府的標案裡篩出來
     */
    protected function loadAgencyTenders($unit_id, $dept_name, $default_children)
    {
        $type = array_key_exists($_GET['type'] ?? '', self::TENDER_TYPES) ? ($_GET['type'] ?? '') : '';
        $with_children = isset($_GET['children']) ? $_GET['children'] === '1' : $default_children;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $scope = ['unit_id' => $unit_id];
        if ($with_children) {
            $scope['include_children'] = 1;
        }
        if ($dept_name) {
            $scope['dept_name'] = $dept_name;
        }
        $list = CCAPI::externalQuery(
            'https://pcc-api.openfun.app/api/listbyunit?' . http_build_query($scope + ['limit' => 20, 'page' => $page] + ($type !== '' ? ['type' => $type] : [])),
            '這個機關的標案公告（政府採購公告 API）',
            'https://pcc-api.openfun.app/skill.md'
        );
        $stat = CCAPI::externalQuery(
            'https://pcc-api.openfun.app/api/unitstat?' . http_build_query($scope + ['top' => 10]),
            '這個機關的標案統計：各年度件數、決標金額、得標廠商排行（政府採購公告 API）',
            'https://pcc-api.openfun.app/skill.md'
        );
        $this->view->tenders = [
            'ok' => $list !== null && isset($list->records),
            'records' => $list->records ?? [],
            'total' => (int) ($list->total ?? 0),
            'total_page' => (int) ($list->total_page ?? 0),
            'page' => $page,
            'type' => $type,
            'children' => $with_children,
            'years' => array_reverse($stat->years ?? []),
            'winners' => $stat->winners ?? [],
        ];
    }

    /**
     * 行政體系 → 村里長：/info/{屆}/agencies/villages 列出鄉鎮市區（附地圖），
     * /info/{屆}/agencies/villages/{鄉鎮市區} 列出該區每個村里的村里長（中選會名冊的當選人，
     * 姓名連到候選人頁看得票、政見）。村里長跟縣市議員同一年選出
     * （民國99年那次縣市議員是98年底選、村里長99年選），取跟本屆投票日同年或隔年的那次
     * 村里長選舉；同一任期內的重行選舉（年份相同）一起列在該村里底下
     */
    protected function loadVillagesTab($cc_code, $term_no, $town)
    {
        $this->view->village_year = null;
        $this->view->village_geo = null;  // 地圖用的邊界檔（scripts/export-geo.php 匯出，沒有就不顯示地圖）
        $this->view->village_town = $town = ($town !== null && $town !== '') ? urldecode($town) : null;
        $vote_date = $this->term_data->{'投票日'} ?? ($this->term_data->{'就職日'} ?? null);
        $years_r = CCAPI::apiQuery(
            '/candidates?limit=0&' . urlencode('參選職位') . '=' . urlencode('村里長') . '&agg=' . urlencode('年份'),
            '本議會有收錄的村里長選舉年份'
        );
        $years = array_map(fn($b) => (int) $b->{'年份'}, $years_r->aggs[0]->buckets ?? []);
        sort($years);
        $this->view->village_years = $years;
        if (!$vote_date) {
            return;
        }
        $roc_year = (int) substr($vote_date, 0, 4) - 1911;
        foreach ([$roc_year, $roc_year + 1] as $y) {
            if (in_array($y, $years, true)) {
                $this->view->village_year = $year = $y;
                break;
            }
        }
        if (!$this->view->village_year) {
            return;
        }
        $base_qs = urlencode('參選職位') . '=' . urlencode('村里長') . '&' . urlencode('年份') . '=' . $year;
        $this->loadTownshipOfficials($roc_year, $town);

        if ($town === null) {
            // 每個鄉鎮市區的村里數：依候選人的行政區代碼（村里層級）去重複計算
            $r = CCAPI::apiQuery(
                '/candidates?limit=10000&' . $base_qs
                    . '&output_fields=' . urlencode('鄉鎮市區代碼') . '&output_fields=' . urlencode('鄉鎮市區')
                    . '&output_fields=' . urlencode('行政區代碼'),
                '本屆村里長候選人的鄉鎮市區與村里'
            );
            $towns = [];
            foreach (($r->candidates ?? []) as $c) {
                $code = $c->{'鄉鎮市區代碼'} ?? '';
                $towns[$code] ??= ['代碼' => $code, '名稱' => $c->{'鄉鎮市區'} ?? '', '村里' => []];
                $towns[$code]['村里'][$c->{'行政區代碼'} ?? ''] = true;
            }
            ksort($towns);
            $county_code = substr((string) array_key_first($towns), 0, 5);
            if ($county_code && file_exists(__DIR__ . "/../static/geo/town/{$county_code}.json")) {
                $this->view->village_geo = "/static/geo/town/{$county_code}.json";
            }
            $this->view->village_towns = array_values(array_map(fn($t) => [
                '代碼' => $t['代碼'], '名稱' => $t['名稱'], '村里數' => count($t['村里']),
                '首長' => $this->view->township_chiefs[$t['代碼']] ?? [],
                '代表數' => $this->view->township_rep_counts[$t['代碼']] ?? null,
            ], $towns));
            return;
        }

        $fields = ['姓名', '人物代碼', '鄉鎮市區代碼', '行政區代碼', '村里', '當選', '選舉代碼', '補選'];
        $r = CCAPI::apiQuery(
            '/candidates?limit=3000&' . $base_qs . '&' . urlencode('鄉鎮市區') . '=' . urlencode($town)
                . '&sort=' . urlencode('行政區代碼<') . '&sort=' . urlencode('選舉代碼<')
                . implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), $fields)),
            '該鄉鎮市區的村里長候選人'
        );
        // 依村里分組，只留當選人（同一任期的重行選舉當選人也算）
        $villages = [];
        foreach (($r->candidates ?? []) as $c) {
            $area = $c->{'行政區代碼'} ?? '';
            $villages[$area] ??= ['村里' => $c->{'村里'} ?? '', '行政區代碼' => $area, '村里長' => []];
            if ($c->{'當選'} ?? false) {
                $villages[$area]['村里長'][] = $c;
            }
            $this->view->village_town_code = $c->{'鄉鎮市區代碼'} ?? null;
        }
        ksort($villages);
        $town_code = $this->view->village_town_code ?? null;
        if ($town_code && file_exists(__DIR__ . "/../static/geo/village/{$town_code}.json")) {
            $this->view->village_geo = "/static/geo/village/{$town_code}.json";
        }
        $this->view->village_list = array_values($villages);

        // 即將舉行的選舉（選舉指南同一年）在這個鄉鎮市區的參選人：鄉鎮市長、代表、村里長
        $upcoming = [];
        $r = CCAPI::apiQuery('/candidates?limit=1000&' . urlencode('年份') . '=' . self::NEXT_ELECTION_YEAR
            . '&' . urlencode('鄉鎮市區') . '=' . urlencode($town)
            . implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), ['姓名', '人物代碼', '參選職位', '選區別', '村里', '號次', '其他欄位', '登記階段'])),
            self::NEXT_ELECTION_YEAR . ' 年選舉在該鄉鎮市區的參選人');
        foreach (($r->candidates ?? []) as $c) {
            $upcoming[$c->{'參選職位'} ?? ''][$c->{'選區別'} ?? ''][] = $c;
        }
        foreach ($upcoming as &$groups) {
            ksort($groups);
        }
        unset($groups);
        $this->view->upcoming_year = self::NEXT_ELECTION_YEAR;
        $this->view->upcoming_candidates = $upcoming;
    }

    /**
     * 選舉指南：這個議會轄區內某次選舉的候選人（登記階段的參選人也算，見
     * scripts/import-registration-candidate.php）。補選不算（選舉代碼不是 ELC- 開頭）。
     * election_mayors = 縣市長候選人；election_districts = [選區別 => 議員候選人]；
     * election_towns = [鄉鎮市區 => [參選職位 => 人數]]（鄉鎮市長、代表、村里長，細節在各鄉鎮市區頁）
     */
    protected function loadElectionGuide($year)
    {
        $this->view->election_year = $year;
        $fields = ['姓名', '人物代碼', '參選職位', '選舉類型', '選區別', '號次', '其他欄位', '登記階段', '登記日期', '得票數', '得票率', '當選', '選舉代碼', '鄉鎮市區', '鄉鎮市區代碼'];
        $fields_qs = implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), $fields));
        $r = CCAPI::apiQuery('/candidates?limit=10000&' . urlencode('年份') . '=' . $year . $fields_qs, $year . ' 年選舉的候選人');
        $mayors = $districts = $towns = $town_codes = [];
        foreach (($r->candidates ?? []) as $c) {
            if (!str_starts_with($c->{'選舉代碼'} ?? '', 'ELC-')) {
                continue;
            }
            $position = $c->{'參選職位'} ?? null;
            if (in_array($position, ['直轄市長', '縣市長'], true)) {
                $mayors[] = $c;
            } elseif (in_array($c->{'選舉類型'} ?? '', ['直轄市議員', '縣市議員'], true)) {
                $districts[$c->{'選區別'} ?? ''][] = $c;
            } elseif ($position && ($c->{'鄉鎮市區'} ?? null)) {
                $towns[$c->{'鄉鎮市區'}][$position] = ($towns[$c->{'鄉鎮市區'}][$position] ?? 0) + 1;
                $town_codes[$c->{'鄉鎮市區'}] = $c->{'鄉鎮市區代碼'} ?? '';
            }
        }
        ksort($districts);
        uksort($towns, fn($a, $b) => [$town_codes[$a], $a] <=> [$town_codes[$b], $b]);
        usort($mayors, [$this, 'compareCandidateNumber']);
        foreach ($districts as &$list) {
            usort($list, [$this, 'compareCandidateNumber']);
        }
        unset($list);

        $this->view->election_mayors = $mayors;
        $this->view->election_districts = $districts;
        $this->view->election_towns = $towns;
        $this->view->election_incumbent = $this->loadElectionIncumbents($year);
        $this->view->election_registration_only = (bool) array_filter(array_merge($mayors, ...array_values($districts) ?: [[]]),
            fn($c) => $c->{'登記階段'} ?? false);
        $this->view->latest_term_no = CCAPI::apiQuery('/overview/' . rawurlencode($this->view->cc_code), '本議會現況資料')->data->{'屆次'} ?? null;
    }

    /**
     * 選舉指南的鄉鎮市區頁：鄉鎮市長（原住民區長）、代表（依選區）、各村里的候選人
     */
    protected function loadElectionTown($year, $town)
    {
        $this->view->election_year = $year;
        $this->view->election_town = $town;
        $fields = ['姓名', '人物代碼', '參選職位', '選區別', '村里', '行政區代碼', '號次', '其他欄位', '登記階段', '得票數', '得票率', '當選', '選舉代碼'];
        $fields_qs = implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), $fields));
        $r = CCAPI::apiQuery('/candidates?limit=5000&' . urlencode('年份') . '=' . $year . '&' . urlencode('鄉鎮市區') . '=' . urlencode($town) . $fields_qs,
            $town . ' ' . $year . ' 年選舉的候選人');
        $chiefs = $reps = $villages = $village_codes = [];
        foreach (($r->candidates ?? []) as $c) {
            if (!str_starts_with($c->{'選舉代碼'} ?? '', 'ELC-')) {
                continue;
            }
            $position = $c->{'參選職位'} ?? '';
            if (in_array($position, ['鄉鎮市長', '山地原住民區長'], true)) {
                $chiefs[] = $c;
            } elseif (in_array($position, ['鄉鎮市民代表', '山地原住民區民代表'], true)) {
                $reps[$c->{'選區別'} ?? ''][] = $c;
            } elseif ($position === '村里長') {
                $villages[$c->{'村里'} ?? ''][] = $c;
                $village_codes[$c->{'村里'} ?? ''] = $c->{'行政區代碼'} ?? '';
            }
        }
        usort($chiefs, [$this, 'compareCandidateNumber']);
        ksort($reps, SORT_NATURAL);
        foreach ($reps as &$list) {
            usort($list, [$this, 'compareCandidateNumber']);
        }
        unset($list);
        uksort($villages, fn($a, $b) => [$village_codes[$a], $a] <=> [$village_codes[$b], $b]);
        foreach ($villages as &$list) {
            usort($list, [$this, 'compareCandidateNumber']);
        }
        unset($list);
        $this->view->town_chiefs = $chiefs;
        $this->view->town_reps = $reps;
        $this->view->town_villages = $villages;
        $this->view->election_incumbent = $this->loadElectionIncumbents($year);
        $this->view->election_registration_only = (bool) array_filter($r->candidates ?? [], fn($c) => $c->{'登記階段'} ?? false);
        $this->view->latest_term_no = CCAPI::apiQuery('/overview/' . rawurlencode($this->view->cc_code), '本議會現況資料')->data->{'屆次'} ?? null;
    }

    /**
     * 選舉指南的單一選舉頁（像選舉公報）：$key 是「{選舉代碼的類別}:{選區別}」，例：C1:新北市、T1:第01選舉區、
     * V0:板橋區華東里、D2:竹北市、R2:竹北市第01選舉區。列出這一場全部候選人的相片、學歷、經歷、政見
     */
    protected function loadElectionRace($year, $key)
    {
        $this->view->election_year = $year;
        [$kind, $district] = array_pad(explode(':', $key, 2), 2, '');
        $candidates = [];
        if (preg_match('/^[A-Z]\d$/', $kind) && $district !== '') {
            $r = CCAPI::apiQuery('/candidates?limit=500&' . urlencode('選舉代碼') . '=' . urlencode("ELC-{$kind}-{$year}")
                . '&' . urlencode('選區別') . '=' . urlencode($district), $district . ' ' . $year . ' 年選舉的候選人');
            $candidates = $r->candidates ?? [];
        }
        usort($candidates, [$this, 'compareCandidateNumber']);
        $first = $candidates[0] ?? null;
        // 標題：新北市長、新北市議員 第01選舉區、竹北市長、竹北市第01選舉區 代表、板橋區華東里里長
        $county = $first->{'縣市'} ?? '';
        $title = match ($kind) {
            'C1', 'C2', 'D1', 'D2' => $district . '長',
            'T1', 'T2' => ($county ?: $this->view->council_name) . CouncilHelper::t('議員') . ' ' . $district,
            'R1' => $district . ' 區民代表',
            'R2' => $district . ' 代表',
            'V0' => $district . (str_ends_with($district, '村') ? '村長' : '里長'),
            default => $district,
        };
        $this->view->race_candidates = $candidates;
        $this->view->race_district = $district;
        $this->view->race_town = $first->{'鄉鎮市區'} ?? null;
        $this->view->race_title = $title;
        $this->view->election_incumbent = $this->loadElectionIncumbents($year);
        $this->view->election_registration_only = (bool) array_filter($candidates, fn($c) => $c->{'登記階段'} ?? false);
        $this->view->latest_term_no = CCAPI::apiQuery('/overview/' . rawurlencode($this->view->cc_code), '本議會現況資料')->data->{'屆次'} ?? null;
    }

    /**
     * 號次排序（登記階段還沒有號次，依登記日期）
     */
    protected function compareCandidateNumber($a, $b)
    {
        return [(int) ($a->{'號次'} ?? 999), $a->{'登記日期'} ?? ''] <=> [(int) ($b->{'號次'} ?? 999), $b->{'登記日期'} ?? ''];
    }

    /**
     * 現任：[人物代碼 => 職稱]。上一次（4 年前）地方選舉的當選人（縣市長、議員、鄉鎮市長、代表、村里長），
     * 以及最近一次立委選舉的當選人（含不分區；立法院的資料掛在 ly，要跨議會查）
     */
    protected function loadElectionIncumbents($year)
    {
        $incumbent = [];
        $prev = CCAPI::apiQuery('/candidates?limit=10000&' . urlencode('年份') . '=' . ($year - 4) . '&' . urlencode('當選') . '=true'
            . '&output_fields=' . urlencode('人物代碼') . '&output_fields=' . urlencode('參選職位') . '&output_fields=' . urlencode('選舉類型')
            . '&output_fields=' . urlencode('選舉代碼'),
            '上一次選舉的當選人（標示現任）');
        foreach (($prev->candidates ?? []) as $c) {
            $title = $c->{'選舉類型'} ?? $c->{'參選職位'} ?? null;
            if (($c->{'人物代碼'} ?? null) && $title && str_starts_with($c->{'選舉代碼'} ?? '', 'ELC-')) {
                $incumbent[$c->{'人物代碼'}] = '現任' . ([
                    '直轄市長' => '市長', '直轄市議員' => '議員', '縣市議員' => '議員', '鄉鎮市民代表' => '代表',
                    '山地原住民區長' => '區長', '山地原住民區民代表' => '區民代表',
                ][$title] ?? $title);
            }
        }
        $ly = CCAPI::apiQuery('/candidates?limit=500&' . urlencode('議會代碼') . '=ly&' . urlencode('當選') . '=true&sort=' . urlencode('年份>')
            . '&output_fields=' . urlencode('人物代碼') . '&output_fields=' . urlencode('年份') . '&output_fields=' . urlencode('選舉類型'),
            '最近一次立委選舉的當選人（標示現任立委）', 'all');
        $ly_year = null;
        foreach (($ly->candidates ?? []) as $c) {
            if (($c->{'選舉類型'} ?? '') !== '立法委員') {
                continue;
            }
            $ly_year ??= $c->{'年份'};
            if ($c->{'年份'} === $ly_year && ($c->{'人物代碼'} ?? null)) {
                $incumbent[$c->{'人物代碼'}] ??= '現任立委';
            }
        }
        return $incumbent;
    }

    /**
     * 立法院行政體系最上面的總統、副總統（scripts/import-president-candidate.php，議會代碼掛 ly）。
     * 取本屆立委投票日隔年以前最近的一次總統選舉（第 3 屆立委 84 年底選出，對應 85 年選出的總統；
     * 97 年起立委、總統同年選）。president_ticket = [總統, 副總統]，president_history = 歷屆當選組。
     * 臺灣省議會（tpg）同一套顯示省長（只有 83 年一次民選）
     */
    protected function loadPresidents($cc_code = 'ly')
    {
        $this->view->president_ticket = null;
        $this->view->president_history = [];
        $titles = $cc_code === 'tpg' ? ['省長'] : ['總統', '副總統'];
        $this->view->president_titles = $titles;
        $vote_date = $this->term_data->{'投票日'} ?? ($this->term_data->{'就職日'} ?? null);
        $fields = ['姓名', '人物代碼', '參選職位', '年份', '選舉名稱', '得票數', '得票率', '其他欄位', '號次', '當選'];
        $r = CCAPI::apiQuery(
            '/candidates?limit=100&' . implode('&', array_map(fn($t) => urlencode('參選職位') . '=' . urlencode($t), $titles))
                . '&' . urlencode('當選') . '=true' . implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), $fields)),
            '歷屆' . implode('、', $titles) . '當選人'
        );
        $by_year = [];
        foreach (($r->candidates ?? []) as $c) {
            $by_year[(int) $c->{'年份'}][(int) array_search($c->{'參選職位'}, $titles, true)] = $c;
        }
        krsort($by_year);
        $this->view->president_history = $by_year;
        if ($vote_date) {
            $roc_year = (int) substr($vote_date, 0, 4) - 1911;
            foreach ($by_year as $year => $ticket) {
                if ($year <= $roc_year + 1) {
                    $this->view->president_ticket = $ticket;
                    break;
                }
            }
        }
    }

    /**
     * 鄉鎮市長、鄉鎮市民代表（縣），以及直轄市山地原住民區長、區民代表（scripts/import-township-candidate.php）。
     * 鄉鎮市長、代表通常跟縣市議員同一天選，但民國 98／99 年那次代表跟村里長一起在 99 年選，
     * 所以首長、代表各自取跟本屆投票日同年或隔年的那次選舉。
     *
     * 鄉鎮市區列表：township_chiefs[鄉鎮市區代碼] = 當選首長、township_rep_counts[鄉鎮市區代碼] = 當選代表人數
     * 單一鄉鎮市區：township_chief_race = 首長那場選舉的全部候選人（依得票排序）、
     *               township_reps = [選區別 => 當選代表]
     */
    protected function loadTownshipOfficials($roc_year, $town)
    {
        // view 的屬性是 magic property，不能直接 $this->view->x[] = …（間接修改會靜靜失敗），先用區域變數
        $chiefs = $rep_counts = $chief_race = $reps = [];
        $this->view->township_chief_year = null;
        $this->view->township_rep_year = null;
        $positions = [
            'chief' => ['鄉鎮市長', '山地原住民區長'],
            'rep'   => ['鄉鎮市民代表', '山地原住民區民代表'],
        ];
        $pos_qs = fn($list) => implode('&', array_map(fn($p) => urlencode('參選職位') . '=' . urlencode($p), $list));
        $year_of = [];
        foreach ($positions as $kind => $list) {
            $r = CCAPI::apiQuery('/candidates?limit=0&' . $pos_qs($list) . '&agg=' . urlencode('年份'), '本議會有收錄的鄉鎮市選舉年份');
            $years = array_map(fn($b) => (int) $b->{'年份'}, $r->aggs[0]->buckets ?? []);
            foreach ([$roc_year, $roc_year + 1] as $y) {
                if (in_array($y, $years, true)) {
                    $year_of[$kind] = $y;
                    break;
                }
            }
        }
        $this->view->township_chief_year = $year_of['chief'] ?? null;
        $this->view->township_rep_year = $year_of['rep'] ?? null;
        $fields = ['姓名', '人物代碼', '鄉鎮市區代碼', '鄉鎮市區', '參選職位', '選區別', '當選', '得票數', '得票率', '號次', '其他欄位', '選舉代碼', '補選', '投票日'];
        $fields_qs = implode('', array_map(fn($f) => '&output_fields=' . urlencode($f), $fields));
        $town_qs = $town !== null ? '&' . urlencode('鄉鎮市區') . '=' . urlencode($town) : '';

        if (isset($year_of['chief'])) {
            $r = CCAPI::apiQuery(
                '/candidates?limit=3000&' . $pos_qs($positions['chief']) . '&' . urlencode('年份') . '=' . $year_of['chief']
                    . $town_qs . '&' . urlencode('當選') . '=true' . $fields_qs,
                $town === null ? '本屆鄉鎮市長（區長）當選人' : '該鄉鎮市區的鄉鎮市長（區長）當選人'
            );
            foreach (($r->candidates ?? []) as $c) {
                if ($town === null) {
                    if ($c->{'當選'} ?? false) {
                        $chiefs[$c->{'鄉鎮市區代碼'} ?? ''][] = $c;
                    }
                } elseif ($c->{'當選'} ?? false) {
                    $chief_race[] = $c;
                }
            }
            usort($chief_race, fn($a, $b) => [$a->{'選舉代碼'} ?? '', -($a->{'得票數'} ?? 0)] <=> [$b->{'選舉代碼'} ?? '', -($b->{'得票數'} ?? 0)]);
        }
        if (isset($year_of['rep'])) {
            $r = CCAPI::apiQuery(
                '/candidates?limit=5000&' . $pos_qs($positions['rep']) . '&' . urlencode('年份') . '=' . $year_of['rep']
                    . $town_qs . '&' . urlencode('當選') . '=true' . $fields_qs,
                $town === null ? '本屆鄉鎮市民代表（區民代表）當選人' : '該鄉鎮市區的鄉鎮市民代表（區民代表）當選人'
            );
            foreach (($r->candidates ?? []) as $c) {
                if (!($c->{'當選'} ?? false)) {
                    continue;
                }
                if ($town === null) {
                    $code = $c->{'鄉鎮市區代碼'} ?? '';
                    // 席次數：補選是遞補出缺的席次，不另外算
                    $rep_counts[$code] = ($rep_counts[$code] ?? 0) + (($c->{'補選'} ?? false) ? 0 : 1);
                } else {
                    $reps[$c->{'選區別'} ?? ''][] = $c;
                }
            }
            ksort($reps);
        }
        $this->view->township_chiefs = $chiefs;
        $this->view->township_rep_counts = $rep_counts;
        $this->view->township_chief_race = $chief_race;
        $this->view->township_reps = $reps;
    }

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
            $this->view->meets_by_sitting = $this->loadMeetsForSession($session_code);
            return;
        }

        // 沒指定 → 找本屆目前進行中或最近一次的會期
        [$meta, $status, $sittings] = $this->findCurrentSessionInTerm($cc_code, $term_no);
        $this->view->session_meta = $meta;
        $this->view->session_status = $status;
        $this->view->session_sittings = $sittings;
        $this->view->meets_by_sitting = $meta ? $this->loadMeetsForSession($meta->{'代碼'}) : [];
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
        // 沒有場次資料可判斷時，改看會期自己的起訖日（還沒有結束日期＝還在進行中）
        $latest_end = $latest->{'結束日期'} ?? null;
        $ongoing = ($latest->{'開始日期'} ?? '9999') <= $today_str && (!$latest_end || $latest_end >= $today_str);
        return [$latest, $ongoing ? 'ongoing' : 'ended', $sittings];
    }

    protected function loadSittingsForSession($session_code, $size = 500)
    {
        $r = CCAPI::apiQuery(
            '/sittings?limit=' . (int)$size . '&' . urlencode('會期代碼') . '=' . urlencode($session_code) . '&sort=' . urlencode('日期<'),
            '該會期場次'
        );
        return $r->sittings ?? [];
    }

    /**
     * 用一次聚合查詢拿到「本屆哪些會期代碼有逐字稿」，避免對每個會期各別查一次
     * （N+1）。改吃meet（有逐字稿=true時才算），取代舊版查/transcripts的做法
     * （舊transcript pipeline已經整個retire）。
     */
    protected function loadSessionsWithTranscript($term_no)
    {
        $r = CCAPI::apiQuery(
            '/meets?limit=0&' . urlencode('屆') . '=' . $term_no . '&' . urlencode('有逐字稿') . '=true&agg=' . urlencode('會期代碼'),
            '本屆哪些會期有逐字稿'
        );
        $codes = [];
        foreach (($r->aggs[0]->buckets ?? []) as $b) {
            $codes[$b->{'會期代碼'}] = true;
        }
        return $codes;
    }

    /**
     * 用一次查詢拿到本會期全部meet（含分組審查等平行會議），依「場次代碼」分組，
     * 取代舊的 loadSittingsWithTranscript()／loadSittingsWithAgendaCount()——
     * 一個場次代碼現在可能對應多筆meet，sessions.php 依這個分組結果決定每個
     * 場次要渲染幾列（見 views/info/sessions.php）。查不到meet的場次代碼（key
     * 不存在）維持原樣顯示一行、沒有速記/逐字稿勾勾，不擋畫面。
     */
    protected function loadMeetsForSession($session_code)
    {
        $r = CCAPI::apiQuery(
            '/meets?limit=1000&' . urlencode('會期代碼') . '=' . urlencode($session_code),
            '本會期所有會議（含分組審查等平行會議）'
        );
        $by_sitting = [];
        foreach (($r->meets ?? []) as $m) {
            $code = $m->{'場次代碼'} ?? null;
            if ($code) {
                $by_sitting[$code][] = $m;
            }
        }
        return $by_sitting;
    }

    /**
     * 舊版場次詳情頁（/info/{屆}/sitting/{場次代碼}）相容用：整個retire掉舊的
     * 逐字稿/議程並存頁面，改成單純redirect——查這個場次代碼對應到幾筆meet，
     * 剛好1筆就直接導去該meet的新詳情頁；0筆或多筆（分組審查，不知道該導去
     * 哪一筆）就退回導去所屬會期頁面，讓使用者自己從那邊挑
     */
    protected function redirectSittingToMeet($term_no, $sitting_code)
    {
        if (!$sitting_code) {
            header('Location: /info/' . $term_no . '/sessions', true, 302);
            exit;
        }
        $sitting_code = urldecode($sitting_code);

        $meets_r = CCAPI::apiQuery(
            '/meets?limit=10&' . urlencode('場次代碼') . '=' . urlencode($sitting_code),
            '場次對應的會議（舊連結轉址用）'
        );
        $meets = $meets_r->meets ?? [];
        if (count($meets) === 1) {
            header('Location: /info/' . $term_no . '/meet/' . urlencode($meets[0]->{'代碼'}), true, 302);
            exit;
        }

        $sitting_r = CCAPI::apiQuery('/sitting/' . rawurlencode($sitting_code), '場次資料（舊連結轉址用）');
        $session_code = $sitting_r->data->{'會期代碼'} ?? null;
        if ($session_code) {
            header('Location: /info/' . $term_no . '/sessions/' . urlencode($session_code), true, 302);
        } else {
            header('Location: /info/' . $term_no . '/sessions', true, 302);
        }
        exit;
    }

    /**
     * 統一的「會議」詳情頁：取代舊的 sitting／agenda 頁面，對應到 sitting 底下
     * 一場實際會議（可能有多個平行meet，例如分組審查）。三段式查詢：會議資訊
     * （/meet）、速記摘要（/meet_notes）、分頁逐字稿（/meet_transcripts）。
     * /info/{屆}/meet/{meet代碼}
     */
    protected function loadMeetTab($meet_code)
    {
        if (!$meet_code) {
            $this->view->meet_meta = null;
            $this->view->meet_notes = [];
            $this->view->meet_transcript_result = null;
            return;
        }
        $meet_code = urldecode($meet_code);

        $meet = CCAPI::apiQuery('/meet/' . rawurlencode($meet_code), '會議資料');
        $this->view->meet_meta = ($meet->error ?? true) ? null : $meet->data;

        // 同一場次的其他 meet（立法院一次會議跨好幾天，每天一筆）
        $this->view->meet_siblings = [];
        if ($sitting_code = $this->view->meet_meta->{'場次代碼'} ?? null) {
            $siblings = CCAPI::apiQuery(
                '/meets?limit=20&' . urlencode('場次代碼') . '=' . urlencode($sitting_code) . '&sort=' . urlencode('日期<'),
                '同一場次的其他會議'
            );
            $this->view->meet_siblings = $siblings->meets ?? [];
        }

        $notes = CCAPI::apiQuery(
            '/meet_notes?limit=20&' . urlencode('會議代碼') . '=' . urlencode($meet_code),
            '本會議速記摘要'
        );
        $this->view->meet_notes = $notes->meet_notes ?? [];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $transcript_result = $this->loadMeetTranscripts($meet_code, $page);
        $this->resolveMeetTranscriptPeople($transcript_result->meet_transcripts ?? []);
        $this->view->meet_transcript_result = $transcript_result;
    }

    /**
     * 會議的逐字稿發言，用伺服器端分頁載入（單一會議可能有數百筆發言，例如
     * 臺北市委員會分組審查案例，不能一次撈完）
     */
    protected function loadMeetTranscripts($meet_code, $page, $limit = 500)
    {
        $r = CCAPI::apiQuery(
            '/meet_transcripts?' . urlencode('會議代碼') . '=' . urlencode($meet_code)
                . '&limit=' . (int)$limit . '&page=' . (int)$page,
            '會議逐字稿'
        );
        return (object)[
            'meet_transcripts' => $r->meet_transcripts ?? [],
            'total'      => $r->total ?? 0,
            'total_page' => $r->total_page ?? 0,
            'page'       => $page,
            'limit'      => $limit,
        ];
    }

    /**
     * 會議逐字稿裡「對應代碼類型」是議員的，批次查一次 councilor 換「人物代碼」
     * （連到議員個人頁）跟「照片」（頭像），一次查詢批次處理，不逐筆查避免 N+1。
     */
    protected function resolveMeetTranscriptPeople($transcripts)
    {
        $codes = [];
        foreach ($transcripts as $s) {
            if (($s->{'對應代碼類型'} ?? null) === '議員' && ($s->{'對應代碼'} ?? null)) {
                $codes[$s->{'對應代碼'}] = true;
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
            '會議逐字稿對應議員資料'
        );

        $by_code = [];
        foreach (($r->councilors ?? []) as $c) {
            $by_code[$c->{'代碼'}] = ['person_code' => $c->{'人物代碼'} ?? null, 'photo' => $c->{'照片'} ?? null];
        }

        foreach ($transcripts as $s) {
            $info = $by_code[$s->{'對應代碼'} ?? ''] ?? null;
            $s->{'_人物代碼'} = $info['person_code'] ?? null;
            $s->{'_照片'} = $info['photo'] ?? null;
        }
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
     * 用「人物代碼」查出同一人跨屆的所有議員記錄，依「選舉日期」由新到舊排序
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
        $records = $this->attachTermInfo($records);
        $this->attachVoteShare($records);

        $cand_r = CCAPI::apiQuery(
            '/candidates?limit=1&' . urlencode('人物代碼') . '=' . urlencode($person_code),
            '議員是否有候選人歷次參選頁'
        );
        $this->view->councilor_has_candidate = ($cand_r->total ?? 0) > 0;

        // 同一個人物代碼（中選會依姓名＋生日串連，議員、立委共用同一套）在其他議會的
        // 記錄：用 all 查，all 本身就排除隱藏議會，所以地方議會頁面不會連到隱藏議會
        $other_council_records = [];
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        if (!CCAPI_Council::isAll($cc_code)) {
            $other_r = CCAPI::apiQuery(
                '/councilors?limit=50&' . urlencode('人物代碼') . '=' . urlencode($person_code) . '&sort=' . urlencode('屆次>'),
                '同一人在其他議會的記錄',
                'all'
            );
            foreach (($other_r->councilors ?? []) as $o) {
                $o_cc = $o->{'議會代碼'} ?? '';
                if ($o_cc !== '' && $o_cc !== $cc_code) {
                    $other_council_records[$o_cc][] = (int) ($o->{'屆次'} ?? 0);
                }
            }
        }
        // view 是 magic property，不能直接對它的陣列元素賦值，組好再整個指定
        $this->view->other_council_records = $other_council_records;

        return $records;
    }

    /**
     * 附加每筆議員記錄的屆期資訊（投票日/就職日/任期屆滿日/議會名稱），並依「選舉
     * 日期」（投票日，缺值時退回就職日）由新到舊重新排序。
     *
     * 不能只依「屆次」數字排序：同一人跨兩個議會代碼時（例：桃園縣議會 tao-1952
     * 屆次可以編到 17，改制直轄市後的桃園市議會 tao 屆次又從 1 重新起算），屆次
     * 數字本身在跨議會時不具備「誰比較新」的意義——tao-1952 屆次 17（2010~2014）
     * 數字比 tao 屆次 3（約 2018~2022）大，但實際上 tao 屆次 3 才是比較新的任期。
     * 已知案例：徐景文（人物代碼 ELC-T2-91:10003-7:26）同時有 tao-1952 17/16/15 屆
     * 跟 tao 3/2 屆的記錄，純依屆次數字排序會把 tao 的兩屆錯誤排到最後面。
     */
    protected function attachTermInfo($records)
    {
        if (!$records) {
            return $records;
        }

        $council_codes = [];
        foreach ($records as $r) {
            if ($r->{'議會代碼'} ?? null) {
                $council_codes[$r->{'議會代碼'}] = true;
            }
        }
        if (!$council_codes) {
            return $records;
        }

        $term_by_key = [];
        foreach (array_keys($council_codes) as $cc_code) {
            $term_r = CCAPI::apiQuery(
                '/terms?limit=100&' . urlencode('議會代碼') . '=' . urlencode($cc_code),
                '議員所屬議會的屆期資料（排序/顯示任期年份用）'
            );
            foreach (($term_r->terms ?? []) as $term) {
                $term_by_key[$cc_code . ':' . $term->{'屆次'}] = $term;
            }
        }

        $council_name_by_code = [];
        $qs = '';
        foreach (array_keys($council_codes) as $code) {
            $qs .= '&' . urlencode('代碼') . '=' . urlencode($code);
        }
        $council_r = CCAPI::apiQuery('/councils?limit=' . count($council_codes) . $qs, '議會名稱對照');
        foreach (($council_r->councils ?? []) as $council) {
            $council_name_by_code[$council->{'代碼'}] = $council->{'議會名稱'};
        }

        foreach ($records as $r) {
            $key = ($r->{'議會代碼'} ?? '') . ':' . ($r->{'屆次'} ?? '');
            $term = $term_by_key[$key] ?? null;
            $vote_date = $term->{'投票日'} ?? null;
            $start_date = $term->{'就職日'} ?? null;
            $end_date = $term->{'任期屆滿日'} ?? null;

            $r->{'_排序日期'} = $vote_date ?: ($start_date ?: '');

            $start_year = substr($start_date ?: ($vote_date ?: ''), 0, 4);
            $end_year = $end_date ? substr($end_date, 0, 4) : null;
            $r->{'_任期年份'} = $start_year !== '' ? ($start_year . '-' . ($end_year ?: '至今')) : null;

            $council_name = $council_name_by_code[$r->{'議會代碼'} ?? ''] ?? null;
            if (CCAPI_Council::getKind($r->{'議會代碼'} ?? '') !== '縣市議會') {
                $r->{'_議員頭銜'} = CCAPI_Council::localize('議員', $r->{'議會代碼'});
            } else {
                $r->{'_議員頭銜'} = $council_name ? preg_replace('/議會$/u', '議員', $council_name) : null;
            }
        }

        usort($records, function ($a, $b) {
            $ka = $a->{'_排序日期'} ?? '';
            $kb = $b->{'_排序日期'} ?? '';
            if ($ka !== $kb) {
                return strcmp($kb, $ka);
            }
            return ($b->{'屆次'} ?? 0) <=> ($a->{'屆次'} ?? 0);
        });

        return $records;
    }

    /**
     * 發言記錄 tab：預設抓最新一屆（$records 已依屆次新到舊排序），可用 ?term= 指定
     * 要看哪一屆。改用meet_transcripts的「對應代碼」精確比對（議員自己的
     * candidate/councilor代碼），取代舊版對/transcripts做「姓+職稱+名」關鍵字
     * 猜測比對的做法——舊做法是因為舊transcript資料沒有逐句結構化發言者代碼
     * 才需要的heuristic，meet_transcripts本身已經有結構化的「對應代碼」，
     * 不需要再猜。
     */
    protected function loadCouncilorSpeeches($records, $requested_term = null)
    {
        $this->view->speech_term = null;
        $this->view->speech_total = 0;
        $this->view->speech_groups = [];
        $this->view->speech_no_coverage = false;
        $this->view->speech_no_coverage_note = null;

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
        $councilor_code = $record->{'代碼'};
        $this->view->speech_term = $term_no;

        $r = CCAPI::apiQuery(
            '/meet_transcripts?limit=20&' . urlencode('屆') . '=' . $term_no
                . '&' . urlencode('對應代碼') . '=' . urlencode($councilor_code)
                . '&' . urlencode('對應代碼類型') . '=' . urlencode('議員')
                . '&sort=' . urlencode('日期>')
                . '&output_fields=' . urlencode('代碼')
                . '&output_fields=' . urlencode('會議代碼')
                . '&output_fields=' . urlencode('會期代碼')
                . '&output_fields=' . urlencode('日期')
                . '&output_fields=' . urlencode('發言內容'),
            '該議員發言記錄（用對應代碼精確比對，依日期新到舊）'
        );
        $this->view->speech_total = $r->total ?? 0;

        // 依會期分組，發言已經依日期新到舊排序，分組後第一次出現的會期自然就是最新的
        $groups = [];
        foreach (($r->meet_transcripts ?? []) as $t) {
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

        // 補上每筆發言所屬會議的委員會或主旨（分組審查時有意義），每個會期只查一次
        // 該會期全部meet（loadMeetsForSession已有），不逐筆查，避免 N+1
        foreach ($groups as $group) {
            $meets_by_code = [];
            foreach ($this->loadMeetsForSession($group->{'會期代碼'}) as $meets_of_sitting) {
                foreach ($meets_of_sitting as $m) {
                    $meets_by_code[$m->{'代碼'}] = $m;
                }
            }
            foreach ($group->items as $item) {
                $meet = $meets_by_code[$item->{'會議代碼'} ?? ''] ?? null;
                $item->{'會議主旨'} = $meet->{'委員會或主旨'} ?? null;
            }
        }

        $this->view->speech_groups = array_values($groups);

        // 查無發言記錄時，要分清楚「這個議會的逐字稿整個都還沒收錄」（PM回饋案例：
        // 基隆/宜蘭/苗栗/彰化/嘉義縣/嘉義市/澎湖目前完全沒有逐字稿block，使用者
        // 看到「這屆沒有找到發言記錄」會誤以為是這位議員真的四年都沒發言）跟
        // 「這個議會有收錄逐字稿、但剛好這位議員/這屆查無資料」兩種情況，用
        // /completeness/{議會代碼} 的 transcript.status 判斷，只在查無記錄時才
        // 多查一次，不影響有資料的正常情況
        if (empty($groups)) {
            $council_code = $record->{'議會代碼'} ?? '';
            if ($council_code !== '') {
                try {
                    $comp = CCAPI::apiQuery(
                        '/completeness/' . rawurlencode($council_code),
                        '議會逐字稿收錄狀態（判斷查無發言記錄的真正原因）'
                    );
                    $transcript = $comp->data->types->transcript ?? null;
                    if ($transcript && ($transcript->total ?? 0) === 0) {
                        $this->view->speech_no_coverage = true;
                        $this->view->speech_no_coverage_note = $transcript->upstream_note ?? null;
                    }
                } catch (Exception $e) {
                    // 查完整度失敗不影響主要功能，維持原本「查無發言記錄」的訊息即可
                }
            }
        }
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
                    . '&' . urlencode('選區別') . '=' . urlencode($candidate->{'選區別'} ?? '')
                    // 總統副總統一組兩筆（正、副手得票相同），比較表一組只列總統候選人那一筆
                    . (in_array($candidate->{'參選職位'} ?? '', ['總統', '副總統'], true) ? '&' . urlencode('參選職位') . '=' . urlencode('總統') : '');
                $race_r = CCAPI::apiQuery(
                    '/candidates?limit=100&sort=' . urlencode('得票排名<') . $race_qs,
                    '同選區候選人得票比較'
                );
                $race_candidates = $race_r->candidates ?? [];
                // 沒有個人得票的（立法委員不分區政黨名單）改依號次（名單排序）排
                if ($race_candidates && !array_filter($race_candidates, fn($rc) => isset($rc->{'得票排名'}))) {
                    usort($race_candidates, fn($a, $b) => intval($a->{'號次'} ?? 0) <=> intval($b->{'號次'} ?? 0));
                }
                $this->enrichRaceCandidates($race_candidates);
            }

            // 不分區候選人個人沒有政見，政見是政黨層級的，從該屆 term 的政黨票取
            $party_info = null;
            if ($candidate && ($candidate->{'選區別'} ?? '') === '全國不分區') {
                $term_doc = CCAPI::apiQuery('/term/' . rawurlencode($term_record->{'議會代碼'} ?? '') . '/' . (int) $term_record->{'屆次'}, '該屆政黨票與政黨政見');
                $party_name = $candidate->{'其他欄位'}->{'推薦之政黨'} ?? null;
                foreach (($term_doc->data->{'政黨票'} ?? []) as $pv) {
                    if (($pv->{'政黨'} ?? null) === $party_name) {
                        $party_info = $pv;
                    }
                }
            }

            $groups[] = (object)[
                'party'     => $party_info,
                '屆次'      => $term_record->{'屆次'},
                '議員頭銜'  => $term_record->{'_議員頭銜'} ?? null,
                '任期年份'  => $term_record->{'_任期年份'} ?? null,
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

        // 財產申報（監察院公報）：依申報資料記載的候選人代碼對應到人物代碼
        $this->view->declarations = CCAPI::apiQuery(
            '/declarations?limit=300&' . urlencode('人物代碼') . '=' . urlencode($person_code),
            '該候選人的財產申報'
        )->declarations ?? [];

        $r = CCAPI::apiQuery(
            '/candidates?limit=50&' . urlencode('人物代碼') . '=' . urlencode($person_code) . '&sort=' . urlencode('年份>'),
            '該候選人歷年參選紀錄'
        );

        // 單一議會的頁面只列本議會範圍的參選紀錄；同一人在其他縣市或其他層級（立委等）
        // 也有參選的話，提示並連到 all 的完整頁面
        $this->view->candidate_all_url = null;
        $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
        if (!CCAPI_Council::isAll($cc_code)) {
            $all_r = CCAPI::apiQuery(
                '/candidates?limit=0&' . urlencode('人物代碼') . '=' . urlencode($person_code),
                '該候選人在所有議會的參選紀錄數',
                'all'
            );
            if (($all_r->total ?? 0) > ($r->total ?? 0)) {
                $this->view->candidate_all_url = 'https://all' . (getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw')
                    . '/info/candidate/' . rawurlencode($person_code);
            }
        }

        $groups = [];
        foreach (($r->candidates ?? []) as $cand) {
            $race_candidates = [];
            if ($cand->{'選舉代碼'} ?? null) {
                $race_qs = '&' . urlencode('選舉代碼') . '=' . urlencode($cand->{'選舉代碼'})
                    . '&' . urlencode('行政區代碼') . '=' . urlencode($cand->{'行政區代碼'} ?? '')
                    . '&' . urlencode('選區別') . '=' . urlencode($cand->{'選區別'} ?? '')
                    // 總統副總統一組兩筆（正、副手得票相同），比較表一組只列總統候選人那一筆
                    . (in_array($cand->{'參選職位'} ?? '', ['總統', '副總統'], true) ? '&' . urlencode('參選職位') . '=' . urlencode('總統') : '');
                $race_r = CCAPI::apiQuery(
                    '/candidates?limit=100&sort=' . urlencode('得票排名<') . $race_qs,
                    '同選區候選人得票比較'
                );
                $race_candidates = $race_r->candidates ?? [];
                // 沒有個人得票的（立法委員不分區政黨名單）改依號次（名單排序）排
                if ($race_candidates && !array_filter($race_candidates, fn($rc) => isset($rc->{'得票排名'}))) {
                    usort($race_candidates, fn($a, $b) => intval($a->{'號次'} ?? 0) <=> intval($b->{'號次'} ?? 0));
                }
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
     * 記錄來判斷，維持用candidate自己的欄位當判斷依據比較單純，不用多查一次API。
     * （舊版這裡原本還提到一個理由：查councilor存不存在會被議員中途離職資料消失
     * 誤判成沒當選，實測案例是李彥秀111年台北市議員選舉最高票當選、任內離職，
     * 當時councilor資料完全沒有這筆記錄——這個根本問題已經在2026-10-07修好了
     * （見PLAN-20261005-reporter.md B1項目：mixed-tw.gov-議會-議員資料/crawl.php
     * 新增了反查CEC當選名單的fallback，現在查得到這筆記錄、而且現況會正確
     * 顯示「已卸任」），但這裡還是維持原本的寫法，單純因為沒有理由為了省略
     * 一個已經不是bug的workaround而多繞一次API）。
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
