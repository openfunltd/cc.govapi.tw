# cc.govapi.tw 實作計劃

## 目前進度（2026-07-30）

### 已完成

#### Phase 1 — 骨架 ✅
- 複製共用基礎檔案：`mini-engine.php`, `libraries/Elastic.php`, `libraries/OpenFunAPIHelper.php`, `libraries/MiniEngineHelper.php`, `.htaccess`
- `init.inc.php`（載入 `/srv/config/cc.govapi.tw.inc.php`）
- `config.sample.inc.php`（含 `ELASTIC_*` 與 `CCAPI_DOMAIN_POSTFIX` 環境變數）
- `libraries/CCAPI/Council.php`（議會代碼清單；subdomain 解析邏輯）
- `libraries/CCAPI/Helper.php`（`LYAPI_Helper` → `CCAPI_Helper`）
- `libraries/CCAPI/Type.php`（`LYAPI_Type` → `CCAPI_Type`；修正 `getFieldMap()` 預設回傳 `[]` 而非 `new StdClass`）

#### Phase 2 — 核心路由與 cc_code 注入 ✅
- `index.php`（subdomain 解析 → `$_SERVER['CCAPI_COUNCIL_CODE']`；未知 subdomain → 404）
- `libraries/CCAPI/SearchAction.php`（`getCollections` 注入 cc_code filter；`getItem` 驗證跨議會存取）
- `controllers/ApiController.php`（帶 cc_code 呼叫 SearchAction）

#### Phase 3 — 第一個資料型別：Councilor ✅
- `libraries/CCAPI/Type/Councilor.php`：composite ID `cc_code/term/name`；filter 支援議會代碼、屆次、屆代碼、姓名、職稱、性別、黨籍、區域、身分別

#### Phase 4 — IndexController 與 view ✅
- `controllers/IndexController.php`（`indexAction` 轉址 `/about`；`unknownCouncilAction` 回傳 404 JSON）
- `controllers/ErrorController.php`

#### Phase 5 — 議會（Council）型別與匯入腳本 ✅
- `scripts/import-council.php`（讀取 `議會.csv`，含 UTF-8 BOM 處理，寫入 `ccv1_council` index；支援 `--reset` 重建）
- `libraries/CCAPI/Type/Council.php`（`代碼`、`議會名稱`、`議會類別`、`現存`、`ISO碼` 等欄位；`/councils` endpoint）

#### Phase 6 — Swagger ✅
- `controllers/SwaggerController.php`（自動掃描 `CCAPI/Type/*.php` 產生 OpenAPI 3.0 YAML）
- `views/swagger/ui.php` + `public/swagger-ui/`（Swagger UI 靜態資源）
- 路由：`GET /swagger` → UI；`GET /swagger.yaml` → YAML

#### Phase 7 — 首頁改善（初版）✅
- 議會列表改從 ES `ccv1_council` 動態載入
- 裸網域 `cc.govapi.tw` 301 轉址至 `all.cc.govapi.tw`

#### Phase 8 — 屆期（Term）型別與匯入腳本 ✅
- `屆.csv`（各議會屆期來源資料）
- `scripts/import-term.php`（讀取 `屆.csv`，寫入 `ccv1_term` index；支援 `--reset`；完成後自動更新各議會 `council.latest_term`）
- `libraries/CCAPI/Type/Term.php`：composite ID `cc_code/term`；filter 支援議會代碼、屆次、現任；欄位含投票日（後續新增）

#### Phase 9 — 資料瀏覽器初版（DataCC）✅（後於 Phase 14 併入 `/viewer`）
從 `dataly-v2` 架構移植，針對 cc.govapi.tw API 呈現資料。初期以獨立子網域 `datacc.openfun.app` 運作，DataTables server-side 分頁／搜尋／篩選／agg。

#### Phase 10 — ES 欄位名稱重構：消除雙重翻譯 ✅（commit `5daf613`）
- 過去每個 Type 都要維護「ES 欄位名稱 ↔ API 輸出欄位名稱」的對照表（`getFieldMap()`），造成資料在 CSV/JSONL 來源、ES index、API 輸出之間要做兩次改名，容易出錯又難維護。
- 改為**全面採用來源資料原始欄位名稱**（例如 CSV 的「姓名」「黨籍」）直接寫入 ES、直接輸出，`getFieldMap()` 統一回傳 `[]`（不做欄位改名）。
- 各 Type 新增 `getCCCodeField()`，用來指定該型別在 ES 中「議會代碼」欄位的實際名稱（大部分是 `議會代碼`，`council` 型別例外是 `代碼`），取代寫死在 `SearchAction` 裡的 `cc_code`。
- 影響：`CCAPI_SearchAction::getCollections/getItem` 改用 `CCAPI_Type::run($type, 'getCCCodeField')` 動態取得欄位名稱做 filter / 跨議會驗證。

#### Phase 11 — 會期（Session）型別 ✅（commit `de54c05`）
- `會期.csv` 來源（欄位：代碼、議會名稱、會期名稱、屆、會期類別、次、開始日期、結束日期）
- `scripts/import-session.php`：Doc ID 為代碼本身（例：`nan-18-r1`），衍生欄位「議會代碼」（從代碼/屆推導）
- `libraries/CCAPI/Type/Session.php`：filter 支援議會代碼、屆、會期類別、次；查詢欄位為會期名稱／議會名稱；預設排序「開始日期」

#### Phase 12 — 委員會（Committee）型別 ✅（commit `0c4aa6d`）
- `data.csv` 來源（欄位：代碼、議會代碼、名稱、別稱、類別、職掌、生效日期、廢止日期）
- `scripts/import-committee.php`：Doc ID 為代碼（例：`tpe-c1`）；委員會**不綁屆**，以生效/廢止日期記錄存續期間
- `libraries/CCAPI/Type/Committee.php`：filter 支援議會代碼、類別（常設/特種）；查詢欄位為名稱／別稱／職掌

#### Phase 13 — 資料完整度（Completeness）系統 ✅（commit `99e22bd`, `dbafc84`）
- `scripts/generate-completeness.php`：離線計算各議會在「屆」「議員」「會期」三種型別的資料完整度，寫入 `ccv1_completeness` index
  - 屆：最新一屆「任期屆滿日」未過今天 → ok
  - 議員：該屆 councilor count > 0 → ok；並統計「有資料屆數 / 總屆數」
  - 會期：最後一筆會期結束日 ≥（現任用今天／歷史用任期屆滿日）− 90 天 → ok
- `libraries/CCAPI/Type/Completeness.php`：`/completeness/{cc_code}` 單一議會、`/completenesses` 全部議會列表
- `views/collection/completeness.php` + `completeness_detail.php`：兩層資料瀏覽器頁面（全部議會總覽表 → 點入單一議會屆期細圖），依現存/已廢止分表顯示

#### Phase 14 — DataCC 併入 `/viewer`、共用 Navbar、`/about` 說明頁、首頁重構 ✅（commits `184756e`, `a8ee93a`, `9646717`, `22ea91a`, `f3d0376`）
- 原本獨立子網域的資料瀏覽器（DataCC）**併入本專案的 `/viewer` 路徑**，不再需要獨立部署/子網域（`datacc.openfun.app/` 已加入 `.gitignore` 排除）
- `index.php` dispatch 新增 `/viewer/*` 路由邏輯：解析 path 對應到 `viewer`／`collection` controller
- 新增 `controllers/ViewerController.php`（dashboard 首頁，依 `TypeHelper::getTypeConfig()` 列出各型別卡片）
- 新增 `controllers/CollectionController.php`（`listAction` 列表頁 + tab、`itemAction` 單筆頁 + tab、`completenessAction` 完整度頁）
- 新增 `libraries/TypeHelper.php`（各型別在瀏覽器上顯示用的欄位設定、預設 agg、item/collection features，依「全國/單一議會」動態切換欄位）
- 新增 `libraries/CouncilHelper.php`（議會代碼中文名稱對照、目前議會代碼、切換議會 URL 產生，取代原本寫死在各 view 的邏輯）
- 新增 `libraries/CCAPI.php`（`CCAPI::apiQuery()`：viewer 端呼叫自己 API 的共用 helper，並記錄查詢 log）
- 新增共用 navbar（`views/nav/top.php`）、套用到 viewer / swagger / about 頁面，並將 viewer 頁面 CSS 框架升級為 Bootstrap 5（原本 sb-admin-2 / Bootstrap 4 風格）
- 新增 `controllers/AboutController.php` + `views/about/index.php`：完整說明頁（如何使用本站、地方議會背景知識、資料實體架構圖、各實體欄位/API 說明、資料完整度指標說明）
- 首頁（`IndexController::indexAction`）改為直接 302 轉址到 `/about`，不再維護獨立的首頁 view
- 修正 about 頁曾連結到私有研究用 repo 的問題（`f3d0376`，已移除）

#### Phase 15 — 場次（Sitting）型別 ✅（commit `8169cd0`）
- `場次.csv` 來源（會期底下逐日排程，通常一天上午/下午各一筆）
- `scripts/import-sitting.php`：Doc ID 為代碼本身，衍生欄位「議會代碼」「屆」（從代碼推導）
- `libraries/CCAPI/Type/Sitting.php`：filter 支援議會代碼、屆、會期代碼、日期、時段、場次類別、委員會名稱
- `scripts/generate-completeness.php` 延伸出場次維度的完整度計算

#### Phase 16 — 議會資訊頁 `/info` 初版 ✅（commits `076bb36`, `00a7b2f`）
- 新增 `libraries/CCAPI/Type/Overview.php` + `scripts/generate-council-overview.php`：預先計算好的快取型別（議會基本資料、目前屆、議長/副議長姓名、議員人數、目前或最近一次會期＋場次片段），避免 `/info` 全國卡片牆做 N+1 即時查詢
  - 「目前或最近會期」優先從場次資料判斷（會期紀錄常常落後於實際公告的排程），找不到才退回查會期索引
  - 會期紀錄還沒建檔時，從會期代碼解析出友善名稱顯示（`CCAPI_Type_Session::getFriendlyName()`，已核對此命名規則橫跨全部議會一致）
- 新增 `controllers/InfoController.php`：`all` 子網域顯示全國議會卡片牆，各議會子網域顯示屆次切換 + 分頁 tab（議員名單／會期／時間軸），沒帶屆次時導向最新一屆
- `/info/{屆}/sessions`：沒帶會期代碼顯示目前進行中或最近一次會期＋場次列表；帶會期代碼顯示該會期完整場次記錄
- `/info/{屆}/timeline`：會期層級時間軸（不含場次顆粒度）

#### Phase 17 — 逐字稿（Transcript）型別與全文搜尋 ✅（commits `63a7cc6`, `caa9557`, `0d4f3a5`, `5496772`, `4ac54df`, `08a9850`）
- `libraries/CCAPI/Type/Transcript.php`：與場次（sitting）一對一，ES Document ID 沿用場次代碼；查詢欄位為「內容」，搭配 `q=` 全文搜尋 + ES highlight 回傳命中片段
- `scripts/import-transcript.php`：讀取逐字稿索引 CSV，依（來源分類, 委員會）分組成獨立的「分段」陣列欄位（標籤/內容/字數），同一場次若有大會會議紀錄、各委員會審查會議事錄等不同來源可各自分開顯示；來源檔案為 `.txt`／`.html` 兩種格式；議會代碼/屆/會期代碼優先查詢既有 sitting index
  - 目前資料涵蓋 13 個議會、約 7,200 個場次（全部場次約 2 成），其餘議會多為結構性缺口（逐字稿另外公布在別處、上游場次資料對不上、或圖片尚待 OCR），屬於實驗性質的資料補充
- fix：`q=` 全文搜尋中文查詢加上片語比對（每個詞加雙引號、詞間 AND），避免單字被拆散誤判命中（`caa9557`，`CCAPI_SearchAction` 共用邏輯，所有型別受惠）
- fix：ES highlight 命中超長欄位內容（最長一筆 327 萬字）會讓整個查詢 500，`CCAPI::apiQuery()` 又把非 JSON 錯誤靜默當作沒資料，畫面上看起來像「查無資料」但其實是查詢炸掉。修法是 highlight 設定加上 `max_analyzed_offset`（`0d4f3a5`，共用邏輯）
- `/info/search`：全國跟單一議會子網域都適用的逐字稿搜尋頁，前端 JS 呼叫 `/transcripts` API，支援依議會/年份 crossfilter
- `/info/{屆}/sessions`：場次列表逐列顯示逐字稿連結（只有真的有逐字稿才顯示），`/info/{屆}/transcript/{場次代碼}` 對應單一場次（不是整個會期，避免一次載入破千萬字），同一場次的「分段」各自渲染成一個 tab
- `/info` 頁面底部加上「本頁使用 API」清單（沿用既有 `CCAPI::getLogs()` 機制）

#### Phase 18 — 資料完整度總覽改版：議員/會期/場次/逐字稿四區分色 ✅（commits `8d00f64`, `d3a12ae`）
- `scripts/generate-completeness.php` 新增逐字稿完整度維度
- `views/collection/completeness.php` 改成四個區塊（議員/會期/場次/逐字稿），每區依 status 分成完整/部分缺漏/缺三欄；拿掉「屆」這個維度；現存/已廢止議會不再拆兩張表，改成單一列表 + JS 開關切換是否顯示已廢止議會
- 完整桶顯示「共 N 屆」，部分缺漏桶顯示「有資料屆數/總屆數」，避免小議會與大議會的「完整」被誤認為同一回事

#### Phase 19 — `/skill.md`、`/knowledge.md`：給 AI Agent 讀的文件 ✅（commit `9883ce5`）
- `/skill.md`：從 `CCAPI_Type/*.php` 自動掃描產生 Markdown 版 API 使用說明（跟 `swagger.yaml` 同一份 type 定義，不會脫節）
- `/knowledge.md`：固定文字的背景知識，重點是避免 AI Agent 用自己既有知識庫裡「議會」「議員」等詞的一般定義（容易跟國會、其他國家地方議會搞混）去理解這個 API
- `skill.md` 開頭連結指向 `knowledge.md`，讓 Agent 先讀背景知識再看 API 怎麼呼叫

#### Phase 20 — 議員個人頁 `/info/councilor/{人物代碼}` ✅（commits `7b04aff`, `b2a0ff6`）
- `CCAPI_Type_Councilor` 新增「人物代碼」filter 欄位，可查出同一人跨屆的所有記錄
- 個人頁：照片/姓名/最新一屆職稱黨籍選區摘要 + 簡歷/學歷/聯絡資訊（取最新一屆）+ 歷屆完整紀錄表格
- 「發言記錄」tab（關鍵字比對版本，非精確逐句）：依「姓＋職稱＋名」組出逐字稿說話者標記猜測字串（例：侯議員漢廷），已知複姓與原住民族名/羅馬拼音兩種例外會 fallback 成直接比對全名；依會期分組、依日期新到舊排序，每個場次附場次名稱；明確標示這是過渡性的粗略版本，等逐字稿清整成逐句之後才會有更準確的做法

#### Phase 21 — Bug 修正與 `/info` 顯示改善 ✅（commits `a384725`, `127639f`, `09dfaae`, `8acba89`）
- fix：viewer 列表頁與 swagger 全國查詢網址寫死 `all.cc.govapi.tw`，不吃 `CCAPI_DOMAIN_POSTFIX`（開發/自訂網域環境完全抓不到資料）
- fix：`import-session.php` 日期欄位加上 trim，避免來源偶爾多出的空白造成 ES date 型別解析失敗、匯入靜默失敗
- `/info` 全國卡片牆：會期名稱開頭重複的「第X屆」拿掉；「進行中」跟「已結束」合併成統一的「最新會期：{名稱}（開始日期 ~ 結束日期或進行中）」格式
- fix：`CCAPI_Council`／`CouncilHelper` 議會清單都漏了基隆市（`kee`），導致 `kee.cc.govapi.tw` 在應用層直接 404

#### Phase 22 — `/info` 全國議會清單依地理分區分組 ✅（commit `ca5b7f0`）
- 新增 `CouncilHelper::getRegions()`：跟隨 `budget.openfun.app` 首頁的分區方式（北部/中部/南部/東部/外島），方便使用者依地區尋找議會

#### Phase 23 — 議員資料新增選區、當選狀態欄位 ✅（commits `d7714f7`, `0bff005`, `7615a88`）
- 來源新增「選舉區號」「選區別」（原名「選區名稱」，後改名）「當選狀態」（當選／遞補／補選當選）三個欄位，`選區別` 含原住民保障名額
- API 開放對應的 filter 欄位；`/info` 議員列表與個人頁改用選區別取代單純的區域名稱，非直接當選時顯示遞補／補選當選 badge
- `/info/{屆}/councilors` 議員名單依「選舉區號」由小到大分組排序（原住民保障名額的選舉區號固定編在一般選區之後，數字排序自然排最後）
- fix：`import-councilor.php` 出生日期驗證改成嚴格檢查月份 01-12、日期 01-31——較舊回溯資料常見「年份已知、月日不明」用 00 佔位（例：`1939-00-00`），ES date 型別不接受
- 來源同時新增一個跟既有「身分別」內容完全重複的「身份別」（打錯字造成的重複欄位），ccapi 端先忽略不匯入

#### Phase 24 — `/info/{屆}/committees` 新增委員會 tab ✅（commit `77b9c8b`）
- 委員會不綁屆，是議會層級的常設編制；依「類別」（常設／特種）分組列出，並標示已廢止的委員會
- 目前沒有「委員會成員」資料（查不到哪位議員屬於哪個委員會），這次先做委員會清單本身，成員關聯之後有資料再補

#### Phase 25 — 議案（Bill）型別 ✅
- `議案.jsonl` 來源（欄位：代碼/縣市/類別/案號/提案單位/提案人/連署人/案由/說明/辦法/審查意見/議決/來源檔案/來源頁碼），跟「會期」「場次」不同顆粒度——議案是當天大會/委員會實際在審的個別提案內容
- `scripts/import-bill.php`：Doc ID 為代碼本身，衍生欄位「議會代碼」（從代碼第一段解析）、「屆」（從來源檔名解析「第N屆」，解析不到就不寫入這個欄位）
  - fix：來源「代碼」不保證跨紀錄唯一（實測新北市有 36 組「同一份文件裡、同一類別+同一案號卻是完全不同議案」的案例），直接拿代碼當 ES doc ID 會被 upsert 覆蓋掉、靜默遺失資料；改成偵測到重複時對 doc ID 加上序號後綴（`{代碼}-dup2`），確保每筆來源記錄都保留下來
- `libraries/CCAPI/Type/Bill.php`：filter 支援議會代碼、屆、類別、提案單位、提案人；查詢欄位為案由/說明/辦法/審查意見/議決/提案單位/提案人
- `libraries/TypeHelper.php`、`views/collection/bill_data.php`：瀏覽器列表/詳情頁
- **目前只涵蓋 4 個議會**（雲林縣、新北市、花蓮縣、臺南市），是持續擴充中的實驗性補充資料；**沒有會期代碼／場次代碼可以連結**，「屆」只是從檔名解析出來的推測值（來源議事錄檔案常橫跨一個定期會加多個臨時會，無法精確對應到單一會期或場次）

#### Phase 26 — 候選人（Candidate）型別 ✅
- `bulletin.jsonl` 來源（選舉公報清整出的候選人學歷/經歷/政見/相片），只匯入縣市議員／直轄市議員候選人（立委/總統/縣市長/直轄市長不在 ccapi 範疇）；**不是「議員」**，包含落選候選人，跟現有 `councilor`（當選、實際擔任過議員的人）是不同實體，語意上刻意分開
- `scripts/import-candidate.php`：
  - 判斷是不是議員選舉優先用「選舉名稱」（來自候選人名單，準確），「選舉類型」（來自檔名）在合刊公報時可能不準，只在選舉名稱缺值時當退回依據
  - fix：合刊公報造成同一位候選人被兩份不同來源 PDF 各自抽出一次的真重複（173 組），依候選人代碼去重，優先保留「選舉類型」正確標示為議員選舉的那筆
  - 衍生欄位：代碼（doc ID，候選人代碼缺值時用來源PDF/頁碼/號次/姓名組合成替代 ID）、議會代碼（從縣市名稱對照，桃園市 2014 年改制前的舊「桃園縣」對應到已廢止的 `tao-1952`）、年份（從選舉名稱解析民國年）
  - 圖片網址：相片路徑/政見圖路徑改寫成 `https://lydata.ronny-s3.click/bulletin/image/...` 公開網址
  - 得票數/得票排名/得票率：另外 join 中選會逐投開票所得票明細（`tw.gov.cec~txn~candidates-votes.jsonl`，原始 1.7GB/577萬列，先篩選縣市層級+縣市議員/直轄市議員候選人縮小到 13,205 列存成 `得票數.jsonl`），依「選舉代碼＋選區代碼」分組算出同選區排名與得票率
- `libraries/CCAPI/Type/Candidate.php`：filter 支援議會代碼、年份、縣市、姓名、候選人代碼、code_match、政見來源、得票排名；查詢欄位為姓名/學歷/經歷/政見
- `libraries/TypeHelper.php`、`views/collection/candidate_data.php`：瀏覽器列表/詳情頁
- **資料品質注意事項**（已寫進 `knowledge.md`）：政見有沒有可用文字要看「政見來源」欄位（`text-garbled` 代表文字層是亂碼），不能只看「政見」是否有值；候選人代碼只回溯到民國 98 年（2009），更早期公報是純掃描圖沒有文字層，不在這批資料裡

#### Phase 27 — 議員頁面整合候選人得票資料、新增候選人歷年參選頁 ✅
- 用議員的「參選代碼」比對 candidate 的「候選人代碼」（同一組代碼體系），把該次選舉的得票數/得票率/得票排名附到議員物件上（`InfoController::attachVoteShare()`，批次查詢避免 N+1）
  - `/info/{屆}/councilors`：每個選區內依得票率由高到低排序，卡片顯示「得票：XX%」
  - `/info/councilor/{人物代碼}` 歷屆紀錄表格加上得票數／得票率欄位
  - 新增「選舉紀錄」tab：每屆顯示候選人公報內容（學歷/經歷/政見，政見是圖片或亂碼時直接嵌圖）與同選區得票比較表（依 candidate 的選舉代碼＋行政區代碼＋選區別精確定位同一場選舉），比較表加上性別／黨籍／是否當選三欄，候選人姓名連結到 `/info/candidate/{人物代碼}`
- CCAPI_Type_Councilor 新增「參選代碼」filter 欄位；CCAPI_Type_Candidate 新增「人物代碼」「當選」filter 欄位
- **候選人的「人物代碼」衍生自 `mixed-tw.gov.cec.data-選舉資料庫/person.jsonl`**（同一人歷次參選不限選舉類型歸在同一組，組 id 是該人第一次參選的候選人代碼，已實測跟 councilor 的人物代碼推導邏輯完全一致、100% 比對成功、零衝突），讓落選者也有一個可跨屆連結的代碼——`councilor` 完全不收錄落選者，這是唯一能串連歷次參選（含落選）的方式
- **fix：「是否當選」改用中選會 cand.csv 的當選註記，不要用「candidate 代碼是否存在於 councilor 資料」判斷**——已實測發現 councilor 來源（moi 地方公職人員資訊專區）若議員任期中辭職，資料會直接消失整筆記錄，把「當選但後來離職」誤判成「沒有當選」（案例：李彥秀 111 年台北市議員選舉最高票當選，`councilor` 完全沒有這筆記錄）；改用當選當下的正式結果就不受這個問題影響
- 新增 `/info/candidate/{人物代碼}` 頁面：顯示某人歷年參選紀錄（含落選次數），每次參選都有候選人公報內容＋同選區得票比較表，若曾經當選過議員會附上連到 `/info/councilor/{人物代碼}` 的連結
- 三個新的篩選/對照表子集（皆為原始檔案篩選後的小檔案，不在 crawl 流程重跑）：`得票數.jsonl`（1.7GB→13,205列）、`人物代碼.jsonl`（39MB→4,136列）、`當選註記.jsonl`（13,206列）

#### Phase 28 — 候選人得票資料整合、候選人歷年參選頁 ✅（2026-08-04，commit `0bd5ce2`、`b142913`）
議員頁面串連候選人得票數/得票率；新增 `/info/candidate/{人物代碼}` 落選者歷年參選頁；「是否當選」改用中選會當選註記判斷，不用 councilor 資料是否存在推論（議員中途離職會讓 councilor 記錄消失，之前會誤判成沒當選）。

#### Phase 29 — 搜尋分頁拆四種資料、匯入來源改指向原始檔案 ✅（2026-08-04，commit `884e562`）
`/info/search` 拆成議員姓名／逐字稿／政見／議案四個獨立分頁；`config.inc.php` 的 `IMPORT_*` 改指向 `/srv/open-forest` 原始檔案，不再複製進專案（複製進來的快照會過期而不自知）；新增 `scripts/auto-refresh.php` 每日自動偵測重新匯入。

#### Phase 30 — 候選人資料整合 OCR、議員歷屆紀錄改依選舉日期排序 ✅（2026-08-04，commit `a31c500`）
候選人來源整合 AI 視覺辨識（`cell-image-vision`）批次資料，修正可用文字判斷邏輯／選舉代碼反解／圖片網址；議員個人頁歷屆紀錄改依實際投票日排序（原本用屆次數字排序，同一人跨議會代碼時會排錯，如桃園縣→桃園市改制的案例）。

#### Phase 31 — 整合 OpenFunAPIHelper（nginx gateway token 驗證／用量記錄）✅（2026-08-06，commit `f2d806a`、`fc49d8b`）
換掉原本沒被使用、舊版 PDO 設計的 `OpenFunAPIHelper`，改用跟姊妹專案一致的新版（nginx gateway 驗證 token、寫 JSONL 用量 log）；修正 `record_count` 一直是 0 的問題。

#### Phase 32 — API 路由全面改 `/api/` 前綴、新增 robots.txt、補上 OG 分享資訊 ✅（2026-08-06，commit `4504d66`、`a83c2a7`）
準備上 Anubis 擋爬蟲：所有 API 型別路由統一掛 `/api/` 前綴、不留舊路徑；新增 `/robots.txt`；`/info/*` 主要頁面補上 OG/SEO meta tags。附帶發現並確認 `ENV=production` 是既有框架機制，可避免路由錯誤時洩漏伺服器檔案路徑。

#### Phase 33 — `/skill.md` 改版符合跨服務標準 ✅（2026-08-06，commit `d0b6a42`）
依循歐噴內部的 API skill.md 跨服務標準文件重寫，補上「開始之前」「Device Authorization Grant」等必填段落，讓 data.openfun.tw portal 可以直接 proxy。

#### Phase 34 — CCAPI_TOKEN：內部 API 呼叫繞過 rate limit ✅（2026-08-06，commit `fdeaa20`）
正式站上了 rate limit 後，`/info` 頁面內部大量自呼叫被當成匿名流量擋掉（已知案例：台南市議會議員名單一度顯示空白）；`CCAPI::apiQuery()` 新增 `CCAPI_TOKEN` 環境變數繞過限制，用 `Authorization: Bearer` header 帶（跟姊妹專案 lawtrace 目前用的方式一致，原本是 `?token=` query string，已改成 header），不會出現在網址／log 裡。

#### Phase 35 — 候選人／議案頁面加上原始來源連結 ✅（2026-08-26）
候選人歷年參選頁、議員選舉紀錄分頁的候選人卡片新增「資料來源（原始公報）」連結（用新的 `來源` 陣列，多頁時列出多個連結，PDF 網址加 `#page=N` 直接跳頁）；議案詳情頁的「來源檔案」文字改成連到 `來源網址` 的連結。共用邏輯放在 `views/info/index.php` 的 `info_candidate_source_links()`。

### 已知 Bug 修正紀錄
- `getFieldMap()` 誤用 `(object)[...]`（stdClass），應為 `[...]`（array）→ 造成 `array_key_exists` 錯誤，已修正 Council、Councilor、Type 基底
- `scripts/import-council.php` CSV 第一欄 header 因 UTF-8 BOM（`\xEF\xBB\xBF`）導致 `Undefined array key "代碼"`，已修正
- MiniEngine view 變數存取方式：`$this->xxx`（非裸變數 `$xxx`）
- `CCAPI_Helper::getApiType()` 對不規則複數型別名稱（如 `completeness`／`completenesses`，非單純字尾加 `s`）誤判 singular/plural，改為優先嘗試直接匹配檔名，再嘗試去 `es`，最後才去掉單一 `s`（`f3fb6b6`）
- 首頁議會連結網址少了代碼前綴（`ef2df32`，已修正）

---

## 目標

建立地方議會開放 API（cc.govapi.tw），讓地方議會資料透明易存取。

- `{city-code}.cc.govapi.tw` — 存取特定縣市議會資料
- `all.cc.govapi.tw` — 不分縣市跨議會查詢

參考現有專案：`ly.govapi.tw-v2/`（立法院 API），沿用其 PHP MiniEngine 框架與 Elasticsearch 查詢架構。

---

## 架構決策

### Elasticsearch 多租戶設計：共用 index + 議會代碼欄位

- 所有議會資料放同一個 index（例如 `ccv1_councilor`）
- 每筆文件有一個代表議會代碼的欄位（大多數型別叫 `議會代碼`，`council` 型別本身叫 `代碼`，由各 Type 的 `getCCCodeField()` 指定）
- `tpe.cc.govapi.tw/api/councilors` → 自動加入 ES filter: `議會代碼=tpe`
- `all.cc.govapi.tw/api/councilors` → 無自動 filter，可查詢全國資料
- 優點：跨議會統計查詢自然支援；schema 一致；無 index 爆炸問題

### 欄位命名：直接採用來源原始名稱，不做雙重翻譯

- 早期（Phase 1-9）沿用 ly.govapi.tw-v2 的 `getFieldMap()` 機制，需維護 ES 欄位 ↔ API 輸出欄位對照表
- Phase 10 重構後，`getFieldMap()` 一律回傳 `[]`：CSV/JSONL 來源欄位名稱（中文）直接寫入 ES，直接作為 API 輸出欄位，減少一層轉換與維護成本
- 各 Type 用 `getIdFieldsInfo()` 定義 composite ID 的組成欄位與對外 path 參數名稱（英文，因 URL path 慣例不支援中文參數名）

### 命名空間

- 現有：`LYAPI_Helper`, `LYAPI_Type`, `LYAPI_SearchAction`
- 新專案：`CCAPI_Helper`, `CCAPI_Type`, `CCAPI_SearchAction`

### 資料瀏覽器（viewer）與 API 共用一個部署

- 早期曾以獨立子網域（`datacc.openfun.app`）部署資料瀏覽器，Phase 14 後併入本專案 `/viewer` 路徑，透過 `CCAPI::apiQuery()` 呼叫自己的 API 取得資料，不直接查 ES
- `TypeHelper` 負責瀏覽器顯示邏輯（欄位、agg、tab），`CouncilHelper` 負責議會代碼/名稱與切換邏輯，兩者皆與 API 層（`CCAPI_*`）分離

### 專案目錄

`/home/srwang/work/cc.govapi.tw/`（`ly.govapi.tw-v2/` 僅供參考，已加入 `.gitignore`，之後會移除）

---

## 目錄結構（現況）

```
cc.govapi.tw/
├── index.php                          # Entry point：subdomain 解析、裸網域轉址、/viewer 與 /swagger 路由
├── init.inc.php                       # 初始化（載入 /srv/config/cc.govapi.tw.inc.php）
├── config.sample.inc.php              # 設定範例（ELASTIC_*、CCAPI_DOMAIN_POSTFIX、IMPORT_*_CSV 路徑）
├── config.inc.php                     # 實際設定（git-ignored）
├── mini-engine.php                    # MiniEngine 框架（從 ly.govapi.tw-v2 複製）
├── .htaccess                          # URL rewriting
├── controllers/
│   ├── ApiController.php              # 帶入議會代碼的 API 控制器（collections / item）
│   ├── IndexController.php            # 首頁（302 → /about）/ 未知 subdomain 404
│   ├── AboutController.php            # /about 說明頁、/knowledge.md
│   ├── InfoController.php             # /info 議會資訊頁（全國卡片牆／單一議會屆次分頁 tab／議員個人頁／逐字稿搜尋）
│   ├── ViewerController.php           # /viewer 資料瀏覽器首頁（dashboard）
│   ├── CollectionController.php       # /viewer/collection/* 列表／單筆／完整度頁
│   ├── SwaggerController.php          # /swagger、/swagger.yaml、/skill.md
│   └── ErrorController.php
├── libraries/
│   ├── Elastic.php                    # 從 ly.govapi.tw-v2 複製（無修改）
│   ├── OpenFunAPIHelper.php
│   ├── MiniEngineHelper.php
│   ├── CCAPI.php                      # CCAPI::apiQuery()，viewer/info 呼叫自己 API 用，含 getLogs()
│   ├── CouncilHelper.php              # 議會代碼/名稱對照、地理分區分組（getRegions()）、目前議會、切換議會 URL（viewer 用）
│   ├── TypeHelper.php                 # 各型別在 viewer 顯示用的欄位/agg/tab 設定
│   └── CCAPI/
│       ├── Council.php                # 議會代碼清單 + subdomain 解析
│       ├── Helper.php                 # CCAPI_Helper（型別名稱 ↔ 檔名/URL 對應，含不規則複數處理）
│       ├── Type.php                   # CCAPI_Type 基底類別（filterFields、buildData、agg 等共用邏輯）
│       ├── SearchAction.php           # CCAPI_SearchAction（getCollections/getItem，含 cc_code 注入與驗證、中文片語搜尋、highlight 設定）
│       └── Type/
│           ├── Council.php            # 議會
│           ├── Term.php               # 屆
│           ├── Councilor.php          # 議員
│           ├── Session.php            # 會期（含 getFriendlyName()）
│           ├── Sitting.php            # 場次
│           ├── Transcript.php         # 逐字稿
│           ├── Committee.php          # 委員會
│           ├── Bill.php               # 議案
│           ├── Candidate.php          # 候選人（選舉公報，落選者也收錄，不是議員）
│           ├── Overview.php           # 議會現況快取（唯讀彙整型別，供 /info 用）
│           └── Completeness.php       # 資料完整度（唯讀彙整型別，非來源資料）
├── scripts/
│   ├── import-council.php             # 議會.csv → ccv1_council
│   ├── import-term.php                # 屆.csv → ccv1_term（並更新 council.latest_term）
│   ├── import-councilor.php           # 議員.jsonl → ccv1_councilor
│   ├── import-session.php             # 會期.csv → ccv1_session
│   ├── import-sitting.php             # 場次.csv → ccv1_sitting
│   ├── import-transcript.php          # 逐字稿索引 CSV + 逐字稿檔案 → ccv1_transcript
│   ├── import-committee.php           # data.csv → ccv1_committee
│   ├── import-bill.php                # 議案.jsonl → ccv1_bill
│   ├── import-candidate.php           # bulletin.jsonl + 得票數.jsonl + 人物代碼.jsonl
│   │                                   # + 當選註記.jsonl → ccv1_candidate
│   ├── prepare-candidate-lookups.php  # 從 person.jsonl/cand.csv/候選人得票數原始來源
│   │                                   # 重新產生上面三個對照表子集
│   ├── generate-completeness.php      # 彙整計算 → ccv1_completeness（議員/會期/場次/逐字稿四維度）
│   ├── generate-council-overview.php  # 彙整計算 → ccv1_overview（供 /info 全國卡片牆用，需在資料重新匯入後手動重跑）
│   └── auto-refresh.php               # 排程用：偵測各型別來源檔案有沒有變化，有才重新匯入
│                                       # + 重跑上面兩個彙整快取（見 .auto-refresh-state.json）
├── views/
│   ├── common/, layout/app.php        # 共用版型
│   ├── nav/top.php                    # 共用 navbar（首頁/viewer/swagger/about/info 共用，含議會切換下拉選單）
│   ├── about/index.php                # /about 說明頁
│   ├── swagger/ui.php                 # Swagger UI
│   ├── viewer/index.php               # /viewer dashboard
│   ├── collection/                    # 列表/單筆/完整度頁（table, item, rawdata, *_data.php 各型別詳情, completeness*.php）
│   └── info/                          # /info 議會資訊頁：index（骨架）、detail（tab 容器）、header、
│                                       # councilors、sessions、timeline、committees、transcript、search、
│                                       # councilor（個人頁 tab 容器）、councilor_profile、
│                                       # councilor_speeches、councilor_bills、councilor_elections、
│                                       # candidate（候選人歷年參選頁）
├── public/swagger-ui/                 # Swagger UI 靜態資源
├── static/                            # sb-admin-2 CSS/JS（viewer 舊版殘留，逐步淘汰中）
├── 議會.csv, 屆.csv                    # 進版控管的來源資料
└── （git-ignored：議員.jsonl, 會期.csv, data.csv, 場次.csv, 逐字稿索引.csv, 逐字稿/,
    議案.jsonl, bulletin.jsonl, 得票數.jsonl, 人物代碼.jsonl, 當選註記.jsonl,
    縣市界.geojson, config.inc.php, datacc.openfun.app/）
```

---

## Elasticsearch Index 一覽

| Index | 型別檔案 | 來源 | Doc ID |
|---|---|---|---|
| `ccv1_council` | `Type/Council.php` | `議會.csv` | `代碼`（例 `tpe`） |
| `ccv1_term` | `Type/Term.php` | `屆.csv` | `{議會代碼}-{屆次}`（例 `tpe-13`） |
| `ccv1_councilor` | `Type/Councilor.php` | `議員.jsonl` | `{議會代碼}-{屆次}-{姓名}` |
| `ccv1_session` | `Type/Session.php` | `會期.csv` | 代碼本身（例 `nan-18-r1`） |
| `ccv1_sitting` | `Type/Sitting.php` | `場次.csv` | 代碼本身 |
| `ccv1_transcript` | `Type/Transcript.php` | 逐字稿索引 CSV + 逐字稿檔案 | 與對應場次同一個代碼（1:1） |
| `ccv1_committee` | `Type/Committee.php` | `data.csv` | 代碼本身（例 `tpe-c1`） |
| `ccv1_bill` | `Type/Bill.php` | `議案.jsonl` | 代碼本身，重複時加 `-dup{N}` 後綴 |
| `ccv1_candidate` | `Type/Candidate.php` | `bulletin.jsonl` + `得票數.jsonl` + `人物代碼.jsonl` + `當選註記.jsonl` | 候選人代碼；缺值時用來源PDF/頁碼/號次/姓名組合替代 ID |
| `ccv1_overview` | `Type/Overview.php` | 由 `generate-council-overview.php` 彙整其他 index 算出 | 議會代碼（例 `tpe`） |
| `ccv1_completeness` | `Type/Completeness.php` | 由 `generate-completeness.php` 彙整其他 index 算出 | 議會代碼（例 `tpe`） |

（index 前綴 `ccv1_` 由 `ELASTIC_PREFIX` 環境變數決定）

**⚠️ `ccv1_overview`、`ccv1_completeness` 是彙整快取，不會自動跟著來源資料更新**：任何一種來源資料（議員/會期/場次/委員會/逐字稿）重新匯入後，都要手動重跑 `generate-completeness.php` 跟 `generate-council-overview.php`，否則 `/info` 與 `/viewer/collection/completeness` 會顯示過期資料。

---

## API 使用範例

```
# 台北市議會第13屆議員名單
GET tpe.cc.govapi.tw/api/councilors?屆次=13

# 全國民主進步黨議員
GET all.cc.govapi.tw/api/councilors?黨籍=民主進步黨

# 各黨派議員數量統計
GET all.cc.govapi.tw/api/councilors?agg=黨籍

# 特定議員資料（doc _id，需 rawurlencode）
GET tpe.cc.govapi.tw/api/councilor/tpe-13-王大明

# 台北市議會會期列表
GET tpe.cc.govapi.tw/api/sessions

# 全國委員會，依類別統計
GET all.cc.govapi.tw/api/committees?agg=類別

# 全部議會資料完整度總覽
GET all.cc.govapi.tw/api/completenesses

# 資料瀏覽器（免寫程式）
https://tpe.cc.govapi.tw/viewer
https://all.cc.govapi.tw/viewer/collection/completeness

# 議會資訊頁（給不寫程式的一般讀者看）
https://all.cc.govapi.tw/info
https://tpe.cc.govapi.tw/info/14/councilors
https://tpe.cc.govapi.tw/info/search?q=預算

# API 文件
https://all.cc.govapi.tw/swagger
https://all.cc.govapi.tw/skill.md
https://all.cc.govapi.tw/knowledge.md
```

---

## 未來規劃

### 長期規劃（規模較大，先列著，之後再挑來做）

- **整合 ly.govapi.tw → `ly.cc.govapi.tw`**：把立法院 API 併進同一個網域體系下的子網域，讓中央（立法院）與地方（各縣市議會）資料可以一起被搜尋。目前兩者是完全分開的專案、資料不互通，這是大工程，涉及兩邊資料模型對照與搜尋層整合
- **行政體系 API**：新增中央與地方各部會/局處等行政組織結構資訊，並收錄各機關首長名單。目前 ccapi 只有「議會」（立法/監督端），完全沒有「行政」（執行端）資料，兩者是台灣地方自治的兩個對應面向
- **鄉鎮市民代表會整合**：目前僅收錄直轄市議會與縣（市）議會共 36 個，地方制度法底下更基層的鄉（鎮、市）民代表會尚未納入範圍（見 `knowledge.md` 的層級說明）

### 補齊原始來源（官方來源／檔案內來源），為線上預覽比對做準備

目標：ccapi 每一筆資料都能回溯到「官方來源」（使用者可以回原網站探索/下載的網址）跟
「檔案內來源」（原始 PDF/Word 裡的第幾頁），並且之後要做「線上預覽該頁內容，比對剖析
是否正確」的功能。以下是 2026-08 盤點的現況跟缺口，一項一項在上游資料處理流程處理，
處理完的項目改成 ✅：

- [ ] **前提／最關鍵：保留並公開原始 PDF/Word 檔案本身**——目前不管是本機來源資料夾還是
  CDN（`lydata.ronny-s3.click`），都只留了「抽取後的文字/圖片」，原始 PDF/Word 檔案完全
  沒有被保留或上傳。沒有這個，就算頁碼欄位再精準，也沒有東西可以拿來做線上預覽比對。
  建議比照現在候選人公報圖片的模式（`bulletin_cell_image_url()`，上傳到
  `lydata.ronny-s3.click`），把原始 PDF/Word 也留下來、放到可公開存取的地方
- [ ] **逐字稿（Transcript）**：頁碼目前藏在 `track-doc.csv` 的「檔案路徑」欄位檔名字串裡
  （例：`議事錄_上冊目錄封面-6定_p213-p217.txt` 的 `p213-p217`），不同議會/年份的命名慣例
  可能不一致，用字串規則猜容易漏判。請上游直接加「起始頁」「結束頁」結構化欄位，不要靠
  檔名反解；另外目前完全沒有官方來源網址欄位
- [ ] **會期（Session）／場次（Sitting）／委員會（Committee）**：這三個型別的來源
  `data.csv`／`track.csv`／`data.csv` 目前**完全沒有任何來源追溯欄位**（無官方網址、無
  檔案/頁碼）。第一步要先跟維護資料的人確認這三種資料實際上是怎麼取得的（議會官網公告
  頁？議事日程系統？PDF 議程表？），才能決定該補「官方來源網址」還是「檔案內來源（頁
  碼）」，還是兩者都要
- [ ] **議員（Councilor）**：來源 `議員.jsonl`（moi 地方公職人員資訊專區）目前只有照片是
  直連網址（`照片` 欄位），沒有議員本人的 moi 詳情頁網址。確認 moi 系統有沒有查詢單筆
  議員資料的網址規則（例如用 `代碼` 欄位當查詢參數組出網址），如果有可以直接當官方來源，
  不需要額外處理
- [x] **候選人（Candidate）／議案（Bill）**（2026-08-26）：上游都補上官方來源網址了。
  候選人來源新增 `來源` 陣列欄位（取代單頁的 `來源PDF`／`來源頁碼`，一位候選人資料橫跨
  多頁時會有多筆），每筆是 `{檔名, 官方網址, 檔案頁碼, 印刷頁碼, 印刷總頁數}`，`官方網址`
  是中選會公報伺服器（`bulletin.cec.gov.tw`）直連 PDF，實測可直接下載；議案新增
  `來源網址`（各議會官網直連下載連結），實測部分議會網站有 Cloudflare 擋自動化請求
  （curl 403），網址格式正常，只是沒辦法用程式自動驗證每一筆能不能下載。已更新
  `import-candidate.php`／`import-bill.php` 吃進這兩個新欄位並重新匯入
  - **附帶修正一個會擋住寫入的 bug**：候選人的「來源頁碼」欄位型別跟著這次改版從純數字
    變成字串（多頁時用「；」分隔，如 `"1；1"`），但 ES mapping 還是 `integer`，實測會讓
    ES 直接 400 拒絕整份文件更新（不是只有那個欄位失敗），已改成 `keyword`
  - **⚠️ 已知暫時性資料缺口**：候選人得票數原始來源（`tw.gov.cec~txn~candidates-votes.jsonl`）
    同時從 1.7GB／577萬列大幅縮小成 167MB／55萬列，且目前**只涵蓋 111 年（2022）選舉**，
    83～107 年的歷史選舉得票數/得票率/得票排名都不在裡面了。套用型別修正需要 `--reset`
    整個重建 index，會連帶讓歷史年份的得票資料暫時消失（已跟使用者確認過，選擇接受這個
    暫時代價換取修好寫入失敗的 bug）。**等上游把歷年得票數據補回去之後，只要重新跑一次
    `prepare-candidate-lookups.php` + `import-candidate.php`（不需要 `--reset`，upsert
    就會補回來）即可恢復**
- [ ] **議會（Council）／屆（Term）**：這兩個是人工整理的參考資料，不是從特定文件抽取
  出來的，不需要「檔案內來源」，但可以考慮補一個「資料本身的依據」（例如地方制度法、議會
  組織自治條例、或官方公告）當官方來源，比較像是引註而不是文件頁碼。議會目前已經有維基
  條目／wikidata-id，可以當作參考來源，但不是政府官方來源

### 較小的待辦（依 `/about` 頁面與程式碼註解線索整理，尚未排入 Phase）

- **委員會成員資料**：目前只有委員會本身的清單（`/info/{屆}/committees`），完全沒有「哪位議員屬於哪個委員會」的關聯資料，等這份資料收集到之後可以把委員會 tab 升級成依委員會分組議員
- **開會日詳細議程樹／會議紀錄 PDF 連結**：`/about` 頁面已預告、場次（sitting）與逐字稿（transcript）已完成，議程樹狀結構跟原始會議紀錄檔案連結尚未實作
- **地圖顯示評估**：已下載全國 22 縣市界 GeoJSON（`縣市界.geojson`，git-ignored），但 `/info` 是否要做地圖視覺化尚未決定、也還沒有任何地圖 UI
- **`static/` 舊版 sb-admin-2 資源淘汰**：viewer 已改用 Bootstrap 5，`views/layout/app.php` 仍在引用舊版 `static/`，需盤點還有沒有頁面依賴後移除
- **`ly.govapi.tw-v2/` 參考專案移除**：待確認不再需要參考後移除該目錄
