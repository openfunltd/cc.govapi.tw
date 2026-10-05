# cc.govapi.tw

台灣地方議會（直轄市議會、縣市議會）開放資料 API。

## 這是什麼／不是什麼

- **是**台灣「地方制度法」規範的地方議會——直轄市議會、縣（市）議會——的開放資料，
  涵蓋議會沿革、屆期、議員、會期、場次、委員會、議案、逐字稿等資料。
- **不是**立法院（國會）：立法委員、國會的資料在姊妹專案
  [`ly.govapi.tw`](https://github.com/openfunltd/ly.govapi.tw)，資料不重疊、欄位
  設計相似但不是同一份資料。
- **不是**鄉鎮市民代表會：地方制度法底下更基層的「鄉（鎮、市）民代表會」目前不收錄，
  只到縣市層級。

更完整的背景知識（容易誤解的欄位定義、已知限制）見線上的
[`/knowledge.md`](https://all.cc.govapi.tw/knowledge.md)。

## 資料涵蓋範圍

```
議會（council） → 屆（term） → 議員（councilor）
                            └→ 會期（session） → 場次（sitting） → 會議（meet） → 會議摘要（meet_note）／逐字稿（meet_transcript）
議會（council） → 委員會（committee）  ← 不綁屆，跨屆存續
```

另外還有候選人（candidate，含落選者）、議案（bill）、完整度統計
（completeness）、議會現況（overview）等資源，完整清單與欄位說明見線上
Swagger 文件。

| 資源 | 說明 |
|---|---|
| `council` | 議會（含已廢止的歷史議會，如縣市合併前的舊議會） |
| `term` | 屆期（投票日、就職日、任期屆滿日） |
| `councilor` | 議員（每人每屆各一筆記錄） |
| `candidate` | 候選人（縣市議員／直轄市議員選舉所有登記參選人，含落選者） |
| `session` | 會期 |
| `sitting` | 場次 |
| `meet` | 會議 |
| `meet_note` | 會議摘要（速記錄） |
| `meet_transcript` | 會議逐字稿 |
| `committee` | 委員會 |
| `bill` | 議案 |
| `completeness` | 各議會資料完整度統計 |
| `overview` | 議會現況總覽 |

## API 使用方式

- **子網域＝議會代碼**：`{議會代碼}.cc.govapi.tw`，例如 `khh.cc.govapi.tw` 只查高雄
  市議會的資料；查全部議會用 `all.cc.govapi.tw`（不帶子網域會自動轉址到這裡）。
- **純資料 API 一律掛 `/api/` 前綴**，資源路徑是複數形（見上表），例如：

  ```bash
  # 查高雄市議會第 4 屆的議員
  curl 'https://khh.cc.govapi.tw/api/councilors?屆次=4'

  # 查全部議會裡黨籍為「無黨籍」的議員
  curl 'https://all.cc.govapi.tw/api/councilors?黨籍=無黨籍'
  ```

- 人類可讀頁面在 `/info/*`（會期/議員/議案等頁面）與 `/viewer/*`，不需要 `/api/`
  前綴。

### 線上文件

- [`/swagger`](https://all.cc.govapi.tw/swagger)：互動式 API 文件（UI）
- [`/swagger.yaml`](https://all.cc.govapi.tw/swagger.yaml)：OpenAPI 規格
- [`/skill.md`](https://all.cc.govapi.tw/skill.md)：給 AI Agent 讀的 API 呼叫方式
- [`/knowledge.md`](https://all.cc.govapi.tw/knowledge.md)：給 AI Agent 讀的資料定義背景知識

## 本地開發

1. 複製 `config.sample.inc.php` 為 `config.inc.php`，填入 Elasticsearch 連線資訊
   （`ELASTIC_URL`／`ELASTIC_USER`／`ELASTIC_PASSWORD`／`ELASTIC_PREFIX`）。
2. 準備各資源的來源檔案（`議會.csv`、`屆.csv` 已內附於本 repo；`議員.jsonl` 等其餘
   來源檔案依 `config.inc.php` 裡 `IMPORT_*` 環境變數指到實際路徑，或放在專案根目錄
   同名檔案）。
3. 執行對應的 `scripts/import-*.php` 匯入 Elasticsearch（加 `--reset` 會先清空重建
   整個 index）：

   ```bash
   php scripts/import-council.php --reset
   php scripts/import-term.php --reset
   php scripts/import-councilor.php --reset
   # ……其餘資源同樣模式
   ```

更多實作細節與架構決策紀錄見 `PLAN.md`。

## 授權

- **程式碼**：採 [BSD License](LICENSE)。
- **資料**：`屆.csv`、`議會.csv` 等本 repo 內收錄的資料檔案，以及透過 API 提供的
  內容，採「CC-BY 歐噴資料庫」授權。
