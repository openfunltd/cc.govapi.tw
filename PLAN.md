# cc.govapi.tw 實作計劃

## 目前進度（2026-03-31）

### 已完成

#### Phase 1 — 骨架 ✅
- 複製共用基礎檔案：`mini-engine.php`, `libraries/Elastic.php`, `libraries/OpenFunAPIHelper.php`, `libraries/MiniEngineHelper.php`, `.htaccess`
- `init.inc.php`（載入 `/srv/config/cc.govapi.tw.inc.php`）
- `config.sample.inc.php`（含 `ELASTIC_*` 與 `CCAPI_DOMAIN_POSTFIX` 環境變數）
- `libraries/CCAPI/Council.php`（35 個議會代碼：21 現行 + 14 已廢止；subdomain 解析邏輯）
- `libraries/CCAPI/Helper.php`（`LYAPI_Helper` → `CCAPI_Helper`）
- `libraries/CCAPI/Type.php`（`LYAPI_Type` → `CCAPI_Type`；修正 `getFieldMap()` 預設回傳 `[]` 而非 `new StdClass`）

#### Phase 2 — 核心路由與 cc_code 注入 ✅
- `index.php`（subdomain 解析 → `$_SERVER['CCAPI_COUNCIL_CODE']`；未知 subdomain → 404）
- `libraries/CCAPI/SearchAction.php`（`getCollections` 注入 `cc_code` filter；`getItem` 驗證跨議會存取）
- `controllers/ApiController.php`（帶 `cc_code` 呼叫 SearchAction）

#### Phase 3 — 第一個資料型別：Councilor ✅
- `libraries/CCAPI/Type/Councilor.php`（15 個欄位；composite ID `cc_code/term/name`；filter 支援議會代碼、屆、姓名、性別、黨籍、選區名稱）

#### Phase 4 — IndexController 與 view ✅
- `controllers/IndexController.php`（`indexAction` + `unknownCouncilAction` 回傳 404 JSON）
- `controllers/ErrorController.php`
- `views/index/index.php`（簡介頁 + 議會列表 + API 使用範例）

#### Phase 5 — 議會（Council）型別與匯入腳本 ✅
- `scripts/import-council.php`（讀取 `議會.csv`，含 UTF-8 BOM 處理，寫入 `ccv1_council` index；支援 `--reset` 重建）
- `libraries/CCAPI/Type/Council.php`（10 個欄位含 `is_active`；`/councils` endpoint）
  - `all.cc.govapi.tw/councils` → 全部議會
  - `tpe.cc.govapi.tw/councils` → 只回傳 tpe 議會

#### Phase 6 — Swagger ✅
- `controllers/SwaggerController.php`（自動掃描 `CCAPI/Type/*.php` 產生 OpenAPI 3.0 YAML）
- `views/swagger/ui.php`（Swagger UI HTML）
- `public/swagger-ui/`（靜態資源）
- 路由：`GET /swagger` → UI；`GET /swagger.yaml` → YAML

### 已知 Bug 修正紀錄
- `getFieldMap()` 誤用 `(object)[...]`（stdClass），應為 `[...]`（array）→ 造成 `array_key_exists` 錯誤，已修正 Council、Councilor、Type 基底
- `scripts/import-council.php` CSV 第一欄 header 因 UTF-8 BOM（`\xEF\xBB\xBF`）導致 `Undefined array key "代碼"`，已修正

---

## 目標

建立地方議會開放 API（cc.govapi.tw），讓地方議會資料透明易存取。

- `{city-code}.cc.govapi.tw` — 存取特定縣市議會資料
- `all.cc.govapi.tw` — 不分縣市跨議會查詢

參考現有專案：`ly.govapi.tw-v2/`（立法院 API），沿用其 PHP MiniEngine 框架與 Elasticsearch 查詢架構。

---

## 架構決策

### Elasticsearch 多租戶設計：共用 index + `cc_code` 欄位

- 所有議會資料放同一個 index（例如 `ccv1_councilor`）（這個 index 會在其他專案建立，這邊不需要建立）
- 每筆文件有 `cc_code` 欄位（例如 `"tpe"`）
- `tpe.cc.govapi.tw/councilors` → 自動加入 ES filter: `cc_code=tpe`
- `all.cc.govapi.tw/councilors` → 無自動 filter，可查詢全國資料
- 優點：跨議會統計查詢自然支援；schema 一致；無 index 爆炸問題

### 命名空間

- 現有：`LYAPI_Helper`, `LYAPI_Type`, `LYAPI_SearchAction`
- 新專案：`CCAPI_Helper`, `CCAPI_Type`, `CCAPI_SearchAction`

### 專案目錄

`/home/srwang/work/cc.govapi.tw/`（之後會移除參考完的 `ly.govapi.tw-v2/`）

---

## 目錄結構

```
cc.govapi.tw-v1/
├── index.php                          # Entry point，含 subdomain 解析
├── init.inc.php                       # 初始化（同 ly 版）
├── config.sample.inc.php              # 設定範例
├── mini-engine.php                    # 從 ly.govapi.tw-v2 複製
├── .htaccess                          # URL rewriting
├── controllers/
│   ├── ApiController.php              # 帶入 cc_code 的 API 控制器
│   ├── IndexController.php            # 首頁 / 未知 subdomain 錯誤
│   └── ErrorController.php
├── libraries/
│   ├── Elastic.php                    # 從 ly.govapi.tw-v2 複製（無修改）
│   ├── OpenFunAPIHelper.php           # 從 ly.govapi.tw-v2 複製
│   ├── MiniEngineHelper.php           # 從 ly.govapi.tw-v2 複製
│   └── CCAPI/
│       ├── Council.php                # 議會代碼清單 + subdomain 解析
│       ├── Helper.php                 # CCAPI_Helper（改名自 LYAPI_Helper）
│       ├── Type.php                   # CCAPI_Type 基底類別
│       ├── SearchAction.php           # CCAPI_SearchAction（含 cc_code 注入）
│       └── Type/
│           └── Councilor.php          # 第一個資料型別：議員
├── views/
│   ├── common/
│   │   ├── header.php
│   │   └── footer.php
│   └── index/
│       └── index.php
└── static/
```

---

## 實作步驟

### Phase 1 — 建立骨架（5 個任務）

**Task 1.1** 複製共用基礎檔案（無需修改）
- `mini-engine.php`
- `libraries/Elastic.php`
- `libraries/OpenFunAPIHelper.php`
- `libraries/MiniEngineHelper.php`
- `.htaccess`

**Task 1.2** 建立 `init.inc.php` 與 `config.sample.inc.php`

```php
// init.inc.php - 唯一差異：外部 config 路徑
include("/srv/config/cc.govapi.tw.inc.php");

// config.sample.inc.php 新增環境變數
ELASTIC_PASSWORD=              // ES 密碼（必填）
ELASTIC_URL=                 // ES URL（必填）
ELASTIC_USER=             // ES 使用者（必填）
ELASTIC_PREFIX=ccv1_          // index: ccv1_councilor, ccv1_meet ...
CCAPI_DOMAIN_POSTFIX=.cc.govapi.tw  // 子網域後綴（開發可能會用 {code}-ccapi.custom.domain ，不只是用 dot）
```

**Task 1.3** 建立 `libraries/CCAPI/Council.php`

核心邏輯：
```php
CCAPI_Council::getCouncilCode('tpe.cc.govapi.tw') → 'tpe'
CCAPI_Council::getCouncilCode('all.cc.govapi.tw') → 'all'
CCAPI_Council::getCouncilCode('unknown.cc.govapi.tw') → null (→ 404)
```

包含完整的 22 個現行議會 + 14 個已廢止議會代碼（含 `tao-1952` 等帶連字號代碼）。

**Task 1.4** 建立 `libraries/CCAPI/Helper.php`

從 `LYAPI_Helper` 改名，`LYAPI_` → `CCAPI_`，`Type/` 路徑指向 `CCAPI/Type/`。

**Task 1.5** 建立 `libraries/CCAPI/Type.php`

從 `LYAPI_Type` 複製，替換：
- `LYAPI_Type::run(` → `CCAPI_Type::run(`
- `LYAPI_Helper::ucfirst(` → `CCAPI_Helper::ucfirst(`
- `LYAPI_Type_` prefix 在 `getReturnKey()` 中改為 `CCAPI_Type_`

---

### Phase 2 — 核心路由與 cc_code 注入（3 個任務）

**Task 2.1** 建立 `index.php`

```php
MiniEngine::dispatch(function($uri) {
    $cc_code = CCAPI_Council::getCouncilCode($_SERVER['HTTP_HOST'] ?? '');
    if (is_null($cc_code)) {
        return ['index', 'unknown_council'];
    }
    $_SERVER['CCAPI_COUNCIL_CODE'] = $cc_code;

    $param = CCAPI_Helper::getApiType($uri);
    if ($param) return $param;
    return null;
});
```

**Task 2.2** 建立 `libraries/CCAPI/SearchAction.php`

從 `LYAPI_SearchAction` 複製，加入 cc_code 注入：

```php
public static function getCollections($type, $query_string, $cc_code = null)
{
    // ... 原本邏輯 ...

    // Council filter injection
    if ($cc_code && !CCAPI_Council::isAll($cc_code)) {
        $cmd->query->bool->must[] = (object)[
            'term' => (object)['cc_code' => $cc_code],
        ];
    }

    // ... 其餘相同 ...
}

public static function getItem($type, $ids, $sub, $query_string, $cc_code = null)
{
    // ... 取得文件後，驗證 cc_code 避免跨議會洩漏 ...
    if ($cc_code && !CCAPI_Council::isAll($cc_code)) {
        if (($obj->_source->cc_code ?? null) !== $cc_code) {
            // 回傳 404，不揭露跨議會資料存在
        }
    }
}
```

**Task 2.3** 建立 `controllers/ApiController.php`

```php
public function collectionsAction($type)
{
    $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'];
    $ret = CCAPI_SearchAction::getCollections($type, $_SERVER['QUERY_STRING'], $cc_code);
    return $this->cors_json($ret);
}

public function itemAction($type, $id, $sub)
{
    $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'];
    $ret = CCAPI_SearchAction::getItem($type, $id, $sub, $_SERVER['QUERY_STRING'], $cc_code);
    return $this->cors_json($ret);
}
```

---

### Phase 3 — 第一個資料型別：Councilor（1 個任務）

**Task 3.1** 建立 `libraries/CCAPI/Type/Councilor.php`

欄位設計：

| API 欄位名 | ES 欄位名 | 說明 |
|-----------|----------|------|
| 議會代碼 | cc_code | 議會代碼（可 filter） |
| 屆 | term | 屆期（integer） |
| 姓名 | name | 議員姓名 |
| 性別 | gender | 男/女 |
| 黨籍 | party | 政黨名稱 |
| 選區名稱 | constituency | 選區 |
| 就任日 | onboard_date | 就任日期 |
| 離職日 | leave_date | 離職日期 |
| 離職原因 | leave_reason | 辭職/去職等 |
| 照片位址 | pic_url | 照片 URL |
| 簡歷 | bio | 個人簡歷 |
| 電話 | tel | 聯絡電話 |
| 通訊處 | addr | 通訊地址 |
| 電子信箱 | email | Email |
| 個人網站 | website | 個人網站 URL |

- Composite ID: `{議會代碼}/{屆}/{姓名}`（例：`tpe.cc.govapi.tw/councilor/tpe/13/王大明`）
- `all.cc.govapi.tw` 存取時同樣需帶完整路徑
- ES Document ID 慣例：`{cc_code}-{term}-{name}`

---

### Phase 4 — IndexController 與基本 view

**Task 4.1** `controllers/IndexController.php` + `views/index/index.php`
- `indexAction()` → 顯示 API 簡介
- `unknownCouncilAction()` → 返回 HTTP 404 + JSON 錯誤訊息

---

## Elasticsearch Index 設計（參考）

```json
// Index name: ccv1_councilor
{
  "mappings": {
    "properties": {
      "cc_code":  { "type": "keyword" },
      "term":          { "type": "integer" },
      "name":          { "type": "text", "fields": { "keyword": { "type": "keyword" } } },
      "gender":        { "type": "keyword" },
      "party":         { "type": "text", "fields": { "keyword": { "type": "keyword" } } },
      "constituency":  { "type": "text", "fields": { "keyword": { "type": "keyword" } } },
      "onboard_date":  { "type": "date", "format": "yyyy-MM-dd" },
      "leave_date":    { "type": "date", "format": "yyyy-MM-dd" },
      "leave_reason":  { "type": "keyword" },
      "pic_url":       { "type": "keyword", "index": false },
      "bio":           { "type": "text" },
      "tel":           { "type": "keyword", "index": false },
      "addr":          { "type": "keyword", "index": false },
      "email":         { "type": "keyword", "index": false },
      "website":       { "type": "keyword", "index": false }
    }
  }
}
```

---

## API 使用範例（完成後）

```
# 台北市議會第13屆議員名單
GET tpe.cc.govapi.tw/councilors?屆=13

# 全國民主進步黨議員
GET all.cc.govapi.tw/councilors?黨籍=民主進步黨

# 特定議員資料
GET tpe.cc.govapi.tw/councilor/tpe:13:王大明 (後面固定為 doc 的 _id)

# 各黨派議員數量統計
GET all.cc.govapi.tw/councilors?agg=黨籍
```
