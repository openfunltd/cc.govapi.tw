# cc.govapi.tw 實作計劃

## 目前進度（2026-07-28）

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
- `tpe.cc.govapi.tw/councilors` → 自動加入 ES filter: `議會代碼=tpe`
- `all.cc.govapi.tw/councilors` → 無自動 filter，可查詢全國資料
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
│   ├── AboutController.php            # /about 說明頁
│   ├── ViewerController.php           # /viewer 資料瀏覽器首頁（dashboard）
│   ├── CollectionController.php       # /viewer/collection/* 列表／單筆／完整度頁
│   ├── SwaggerController.php          # /swagger、/swagger.yaml
│   └── ErrorController.php
├── libraries/
│   ├── Elastic.php                    # 從 ly.govapi.tw-v2 複製（無修改）
│   ├── OpenFunAPIHelper.php
│   ├── MiniEngineHelper.php
│   ├── CCAPI.php                      # CCAPI::apiQuery()，viewer 呼叫自己 API 用
│   ├── CouncilHelper.php              # 議會代碼/名稱對照、目前議會、切換議會 URL（viewer 用）
│   ├── TypeHelper.php                 # 各型別在 viewer 顯示用的欄位/agg/tab 設定
│   └── CCAPI/
│       ├── Council.php                # 議會代碼清單 + subdomain 解析
│       ├── Helper.php                 # CCAPI_Helper（型別名稱 ↔ 檔名/URL 對應，含不規則複數處理）
│       ├── Type.php                   # CCAPI_Type 基底類別（filterFields、buildData、agg 等共用邏輯）
│       ├── SearchAction.php           # CCAPI_SearchAction（getCollections/getItem，含 cc_code 注入與驗證）
│       └── Type/
│           ├── Council.php            # 議會
│           ├── Term.php               # 屆
│           ├── Councilor.php          # 議員
│           ├── Session.php            # 會期
│           ├── Committee.php          # 委員會
│           └── Completeness.php       # 資料完整度（唯讀彙整型別，非來源資料）
├── scripts/
│   ├── import-council.php             # 議會.csv → ccv1_council
│   ├── import-term.php                # 屆.csv → ccv1_term（並更新 council.latest_term）
│   ├── import-councilor.php           # 議員.jsonl → ccv1_councilor
│   ├── import-session.php             # 會期.csv → ccv1_session
│   ├── import-committee.php           # data.csv → ccv1_committee
│   └── generate-completeness.php      # 彙整計算 → ccv1_completeness
├── views/
│   ├── common/, layout/app.php        # 共用版型
│   ├── nav/top.php                    # 共用 navbar（首頁/viewer/swagger/about 共用，含議會切換下拉選單）
│   ├── about/index.php                # /about 說明頁
│   ├── swagger/ui.php                 # Swagger UI
│   ├── viewer/index.php               # /viewer dashboard
│   └── collection/                    # 列表/單筆/完整度頁（table, item, rawdata, *_data.php 各型別詳情, completeness*.php）
├── public/swagger-ui/                 # Swagger UI 靜態資源
├── static/                            # sb-admin-2 CSS/JS（viewer 舊版殘留，逐步淘汰中）
├── 議會.csv, 屆.csv                    # 進版控管的來源資料
└── （git-ignored：議員.jsonl, 會期.csv, data.csv, config.inc.php, datacc.openfun.app/）
```

---

## Elasticsearch Index 一覽

| Index | 型別檔案 | 來源 | Doc ID |
|---|---|---|---|
| `ccv1_council` | `Type/Council.php` | `議會.csv` | `代碼`（例 `tpe`） |
| `ccv1_term` | `Type/Term.php` | `屆.csv` | `{議會代碼}-{屆次}`（例 `tpe-13`） |
| `ccv1_councilor` | `Type/Councilor.php` | `議員.jsonl` | `{議會代碼}-{屆次}-{姓名}` |
| `ccv1_session` | `Type/Session.php` | `會期.csv` | 代碼本身（例 `nan-18-r1`） |
| `ccv1_committee` | `Type/Committee.php` | `data.csv` | 代碼本身（例 `tpe-c1`） |
| `ccv1_completeness` | `Type/Completeness.php` | 由 `generate-completeness.php` 彙整其他 index 算出 | 議會代碼（例 `tpe`） |

（index 前綴 `ccv1_` 由 `ELASTIC_PREFIX` 環境變數決定）

---

## API 使用範例

```
# 台北市議會第13屆議員名單
GET tpe.cc.govapi.tw/councilors?屆次=13

# 全國民主進步黨議員
GET all.cc.govapi.tw/councilors?黨籍=民主進步黨

# 各黨派議員數量統計
GET all.cc.govapi.tw/councilors?agg=黨籍

# 特定議員資料（doc _id，需 rawurlencode）
GET tpe.cc.govapi.tw/councilor/tpe-13-王大明

# 台北市議會會期列表
GET tpe.cc.govapi.tw/sessions

# 全國委員會，依類別統計
GET all.cc.govapi.tw/committees?agg=類別

# 全部議會資料完整度總覽
GET all.cc.govapi.tw/completenesses

# 資料瀏覽器（免寫程式）
https://tpe.cc.govapi.tw/viewer
https://all.cc.govapi.tw/viewer/collection/completeness

# API 文件
https://all.cc.govapi.tw/swagger
```

---

## 未來規劃（依 `/about` 頁面與程式碼註解線索整理，尚未排入 Phase）

- **人物代碼跨屆連結**：`議員` 目前每屆各存一筆記錄，`人物代碼` 欄位已存在於來源資料但尚未用於 API 層面串連同一人的跨屆資料
- **場次（sitting）／開會日／會議紀錄**：`/about` 頁面已預告的資料實體，尚未開始設計/實作
- **鄉鎮市民代表會**：目前僅收錄直轄市議會與縣（市）議會共 36 個，鄉鎮市民代表會尚未納入範圍
- **`static/` 舊版 sb-admin-2 資源淘汰**：viewer 已改用 Bootstrap 5，舊版 CSS/JS 是否仍有頁面依賴需盤點後移除
- **`ly.govapi.tw-v2/` 參考專案移除**：待確認不再需要參考後移除該目錄
