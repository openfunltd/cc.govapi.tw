<!DOCTYPE html>
<html lang="zh-tw">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <title>CCAPI 說明 — 地方議會開放 API</title>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="cc.govapi.tw">
  <meta property="og:title" content="CCAPI 說明 — 地方議會開放 API">
  <meta property="og:description" content="台灣各縣市議會的議員、會期、議案、逐字稿、行政機關、候選人等開放資料 API，免費、開源、跨議會查詢。">
  <meta property="og:url" content="https://all.cc.govapi.tw/about">
  <meta name="twitter:card" content="summary">
  <meta name="description" content="台灣各縣市議會的議員、會期、議案、逐字稿、行政機關、候選人等開放資料 API，免費、開源、跨議會查詢。">
  <style>
    body { font-family: 'Noto Sans TC', sans-serif; }
    .entity-card { border-left: 4px solid; }
    .entity-card.council  { border-color: #0d6efd; }
    .entity-card.term     { border-color: #198754; }
    .entity-card.session  { border-color: #0dcaf0; }
    .entity-card.councilor { border-color: #ffc107; }
    .entity-card.committee { border-color: #6f42c1; }
    .entity-card.sitting { border-color: #fd7e14; }
    .entity-card.meet { border-color: #dc3545; }
    .entity-card.bill { border-color: #6c757d; }
    .entity-card.agency { border-color: #20c997; }
    .entity-card.candidate { border-color: #198754; }
    .entity-card.declaration { border-color: #212529; }
    .relation-diagram { font-family: monospace; font-size: 0.9rem; background: #f8f9fa; border-radius: 8px; padding: 1.5rem; }
    .section-anchor { scroll-margin-top: 72px; }
    code { color: #d63384; }
  </style>
</head>
<body class="bg-light">
<?php
  $active = 'about';
  $cc_code = $this->cc_code;
  $council_name = $this->council_name;
  include(__DIR__ . '/../nav/top.php');
?>

<main>
  <div class="container" style="max-width: 900px;">

    <!-- TOC -->
    <div class="pt-4 pb-2 border-bottom mb-4">
      <h1 class="h3 fw-semibold mb-1">📖 CCAPI 說明</h1>
      <p class="text-body-secondary mb-3">了解這個網站、資料結構，以及如何使用 API。</p>
      <nav class="d-flex flex-wrap gap-2">
        <a href="#how-to-use" class="btn btn-outline-secondary btn-sm">如何使用本站</a>
        <a href="#background"  class="btn btn-outline-secondary btn-sm">什麼是地方議會</a>
        <a href="#entities"    class="btn btn-outline-secondary btn-sm">資料實體架構</a>
        <a href="#entity-detail" class="btn btn-outline-secondary btn-sm">各實體說明</a>
        <a href="#completeness" class="btn btn-outline-secondary btn-sm">資料完整度</a>
      </nav>
    </div>

    <!-- ── 1. 如何使用本站 ── -->
    <section id="how-to-use" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">1. 如何使用本站</h2>
      <p>CCAPI 提供四種主要介面：</p>
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="card h-100 border-primary">
            <div class="card-body">
              <h6 class="card-title">🏛 議會資訊</h6>
              <p class="card-text small text-body-secondary">依議會、屆次整理的網頁：議員名單、法規、行政體系、會期、時間軸、委員會、議案，以及會議逐字稿、議員個人頁（發言、提案、選舉紀錄）、候選人與選舉公報。</p>
              <a href="/info" class="btn btn-sm btn-outline-primary">前往議會資訊</a>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100 border-success">
            <div class="card-body">
              <h6 class="card-title">📊 資料瀏覽器</h6>
              <p class="card-text small text-body-secondary">以表格逐筆瀏覽每一種資料，支援篩選與統計，不需要寫程式。</p>
              <a href="/viewer" class="btn btn-sm btn-outline-success">前往瀏覽器</a>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100 border-secondary">
            <div class="card-body">
              <h6 class="card-title">📄 API 文件（Swagger）</h6>
              <p class="card-text small text-body-secondary">完整的 API 規格，所有資料都在 <code>/api/</code> 底下，適合開發者整合到自己的應用程式。</p>
              <a href="/swagger" class="btn btn-sm btn-outline-secondary">前往 API 文件</a>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100 border-dark">
            <div class="card-body">
              <h6 class="card-title">🤖 給 AI Agent</h6>
              <p class="card-text small text-body-secondary"><code>skill.md</code> 說明 API 怎麼呼叫，<code>knowledge.md</code> 說明資料定義與容易誤解的地方，可直接交給 AI Agent 讀取。</p>
              <a href="/skill.md" class="btn btn-sm btn-outline-dark">skill.md</a>
              <a href="/knowledge.md" class="btn btn-sm btn-outline-dark">knowledge.md</a>
            </div>
          </div>
        </div>
      </div>

      <h5 class="mt-4">子網域與議會範圍</h5>
      <p>CCAPI 以<strong>子網域</strong>決定資料範圍：</p>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>URL</th><th>說明</th></tr>
        </thead>
        <tbody>
          <tr><td><code>all.cc.govapi.tw</code></td><td>跨議會查詢（全國版），可搜尋全部 36 個議會資料</td></tr>
          <tr><td><code>tpe.cc.govapi.tw</code></td><td>只回傳臺北市議會資料</td></tr>
          <tr><td><code>nwt.cc.govapi.tw</code></td><td>只回傳新北市議會資料</td></tr>
          <tr><td colspan="2" class="text-body-secondary small">其餘依此類推，可從上方選單切換議會</td></tr>
        </tbody>
      </table>
      <p class="small text-body-secondary">網頁與 API 都依子網域鎖定範圍：<code>tpe.cc.govapi.tw/info</code>、<code>tpe.cc.govapi.tw/api/councilors</code> 只會看到臺北市議會。一個人完整的參選紀錄請用 <code>all</code> 子網域查。</p>
    </section>

    <!-- ── 2. 什麼是地方議會 ── -->
    <section id="background" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">2. 什麼是地方議會</h2>
      <p>台灣的地方民意機關依<strong><a href="https://law.moj.gov.tw/LawClass/LawAll.aspx?pcode=A0040003" target="_blank" rel="noopener">地方制度法</a></strong>成立，分為三個層級：</p>
      <table class="table table-sm table-bordered bg-white mb-3">
        <thead class="table-light">
          <tr><th>層級</th><th>機關名稱</th><th>成員稱謂</th><th>主持人</th></tr>
        </thead>
        <tbody>
          <tr><td>直轄市</td><td>直轄市議會</td><td>議員</td><td>議長 / 副議長</td></tr>
          <tr><td>縣（市）</td><td>縣（市）議會</td><td>議員</td><td>議長 / 副議長</td></tr>
          <tr class="table-light"><td>鄉（鎮、市）</td><td>鄉（鎮、市）民代表會</td><td>代表</td><td>主席 / 副主席</td></tr>
        </tbody>
      </table>
      <div class="alert alert-info small">ℹ️ 目前 CCAPI 只收錄<strong>直轄市議會</strong>與<strong>縣（市）議會</strong>共 36 個（含 14 個歷史廢止議會），不含鄉鎮市民代表會的議事資料；鄉鎮市長、鄉鎮市民代表、村里長的<strong>選舉</strong>資料則收錄在候選人裡。</div>

      <h5 class="mt-4">法定會議類型</h5>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>類型</th><th>頻率</th><th>法定上限</th></tr>
        </thead>
        <tbody>
          <tr><td>定期會</td><td>每半年一次（每年2次）</td><td>直轄市 ≤ 70 天；縣市 ≤ 30～40 天</td></tr>
          <tr><td>臨時會</td><td>有需要時召開</td><td>直轄市每次 ≤ 10 天，每年 ≤ 8 次；縣市每次 ≤ 5 天，每年 ≤ 6 次</td></tr>
          <tr><td>成立大會</td><td>每屆 1 次</td><td>無規定</td></tr>
        </tbody>
      </table>
    </section>

    <!-- ── 3. 資料實體架構 ── -->
    <section id="entities" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">3. 資料實體架構</h2>
      <p>CCAPI 的資料分為以下幾個互相關聯的實體：</p>
      <div class="relation-diagram mb-3">
<pre class="mb-0">議會（council）
 ├── 屆（term）
 │    ├── 議員（councilor）
 │    ├── 會期（session）
 │    │     └── 場次（sitting）
 │    │           └── 會議（meet）
 │    │                 ├── 會議摘要（meet_note）
 │    │                 └── 逐字稿（meet_transcript）
 │    └── 議案（bill）           ← 只對應到屆
 ├── 委員會（committee）         ← 不綁屆，跨屆存續
 └── 行政機關（agency）          ← 議會監督的縣市政府機關

候選人（candidate） ── 人物代碼 ── 議員（councilor）
 └── 財產申報（declaration）     ← 以人物代碼對應</pre>
      </div>

      <h5 class="mt-4">識別碼設計原則</h5>
      <p>議會、屆、會期、場次、委員會使用自管的代碼（slug），格式如下：</p>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>實體</th><th>格式</th><th>範例</th></tr>
        </thead>
        <tbody>
          <tr><td>議會</td><td><code>{slug}</code></td><td><code>nwt</code></td></tr>
          <tr><td>屆</td><td><code>{slug}-{屆次}</code></td><td><code>nwt-4</code></td></tr>
          <tr><td>會期</td><td><code>{屆代碼}-{類別縮寫}{次別}</code></td><td><code>nwt-4-r1</code>（定期會第1次）<br><code>nwt-4-e2</code>（臨時會第2次）<br><code>nwt-4-i</code>（成立大會）</td></tr>
          <tr><td>場次</td><td><code>{會期代碼}-{日期}{-am|-pm}</code></td><td><code>tpe-14-r7-20220408-am</code>（上午）<br><code>tpe-14-r7-20220408</code>（全天一筆）</td></tr>
          <tr><td>委員會</td><td><code>{議會代碼}-c{流水號}</code></td><td><code>tpe-c1</code></td></tr>
          <tr><td>行政機關</td><td><code>{議會代碼}-{機關代碼}</code></td><td><code>tpe-379000000A</code></td></tr>
        </tbody>
      </table>
      <p class="small text-body-secondary">採用自管 slug 而非內政部代碼或 ISO 碼，原因是外部代碼在歷史上有重複使用問題（例如省轄高雄市與直轄高雄市內政部代碼同為 64000），無法唯一識別歷史實體。會議、逐字稿、議案的代碼由來源內容產生，只用來識別單筆資料。</p>
    </section>

    <!-- ── 4. 各實體說明 ── -->
    <section id="entity-detail" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">4. 各實體說明</h2>

      <!-- 議會 -->
      <div class="card entity-card council mb-3">
        <div class="card-body">
          <h5 class="card-title">🏛 議會（council）</h5>
          <p class="card-text small">全台 36 個議會（22 個現存 + 14 個歷史廢止），每個議會有唯一代碼，同時作為子網域。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：自管 slug，例 <code>nwt</code></li>
                <li><code>議會名稱</code>、<code>議會類別</code></li>
                <li><code>生效日期</code>、<code>廢止日期</code>（null = 現存）</li>
                <li><code>內政部行政區代碼</code>、<code>ISO碼</code>（僅供參考）</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/councils</code> — 議會列表</li>
                <li><code>GET /api/council/tpe</code> — 單一議會</li>
              </ul>
              <a href="/viewer/collection/list/council" class="btn btn-sm btn-outline-primary mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 屆 -->
      <div class="card entity-card term mb-3">
        <div class="card-body">
          <h5 class="card-title">📅 屆（term）</h5>
          <p class="card-text small">每次議員改選後產生一屆。屆次從各議會設立時的第1屆開始計算，各議會獨立計算，不同議會的同一屆次對應的年份不同。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：例 <code>nwt-4</code></li>
                <li><code>議會代碼</code>、<code>屆次</code></li>
                <li><code>投票日</code>、<code>就職日</code>、<code>任期屆滿日</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/terms</code> — 屆清單</li>
                <li><code>GET /api/term/nwt-4</code> — 單一屆</li>
              </ul>
              <a href="/viewer/collection/list/term" class="btn btn-sm btn-outline-success mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 議員 -->
      <div class="card entity-card councilor mb-3">
        <div class="card-body">
          <h5 class="card-title">👤 議員（councilor）</h5>
          <p class="card-text small">每人每屆各一筆記錄，同一人連任多屆會有多筆，以<code>人物代碼</code>串連（議員個人頁 <code>/info/councilor/{人物代碼}</code>）。議長、副議長是議員記錄裡的<code>職稱</code>，不是另外的資料。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>姓名</code>、<code>性別</code>、<code>人物代碼</code></li>
                <li><code>黨籍</code>（目前黨籍）、<code>參選政黨</code>（當選該屆時的政黨）</li>
                <li><code>職稱</code>：議員 / 副議長 / 議長</li>
                <li><code>當選狀態</code>：當選 / 遞補 / 補選當選</li>
                <li><code>選區別</code>、<code>屆代碼</code>、聯絡資訊</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/councilors</code> — 議員清單</li>
                <li><code>GET /api/councilors?黨籍=民主進步黨</code></li>
                <li><code>GET /api/councilors?agg=黨籍</code> — 統計</li>
              </ul>
              <a href="/viewer/collection/list/councilor" class="btn btn-sm btn-outline-warning mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 會期 -->
      <div class="card entity-card session mb-3">
        <div class="card-body">
          <h5 class="card-title">🗓 會期（session）</h5>
          <p class="card-text small">每屆有多個會期（定期會、臨時會、成立大會）。各縣市對會期的稱呼略有不同，但都依地方制度法規定召開。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：例 <code>nwt-4-r1</code></li>
                <li><code>會期類別</code>：定期會 / 臨時會 / 成立大會</li>
                <li><code>次</code>：第幾次定期會或臨時會</li>
                <li><code>開始日期</code>、<code>結束日期</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/sessions</code> — 會期清單</li>
                <li><code>GET /api/session/nwt-4-r1</code> — 單一會期</li>
              </ul>
              <a href="/viewer/collection/list/session" class="btn btn-sm btn-outline-info mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 場次 -->
      <div class="card entity-card sitting mb-3">
        <div class="card-body">
          <h5 class="card-title">🗓️ 場次（sitting）</h5>
          <p class="card-text small">每個會期裡逐日的排程，通常一天拆成上午/下午兩筆（少數全天一筆），可用來排出「議會下週在做什麼」的行事曆。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>日期</code>、<code>星期</code>、<code>時段</code></li>
                <li><code>場次類別</code>：大會 / 委員會審查 / 分組審查 / 全體審查 / 停會</li>
                <li><code>議程說明</code>、<code>委員會名稱</code>、<code>停會原因</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/sittings?會期代碼=nwt-4-r1</code> — 單一會期的場次</li>
                <li><code>GET /api/sitting/tpe-14-r7-20220408-am</code> — 單一場次</li>
              </ul>
              <a href="/viewer/collection/list/sitting" class="btn btn-sm mt-2" style="border-color:#fd7e14;color:#fd7e14;border-width:1px;border-style:solid;background:transparent">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 會議 -->
      <div class="card entity-card meet mb-3">
        <div class="card-body">
          <h5 class="card-title">🎙 會議（meet）／會議摘要（meet_note）／逐字稿（meet_transcript）</h5>
          <p class="card-text small">場次底下實際開的會議，分組審查時一個場次會有好幾場。會議內容分兩種：<strong>會議摘要</strong>是速記式的簡短文字，<strong>逐字稿</strong>是逐句的發言紀錄。同一場會議可能兩種都有、只有一種，或都還沒有（<code>有速記</code>、<code>有逐字稿</code>欄位會標示）。網頁版在 <code>/info/{屆}/meet/{代碼}</code>，跨議會的逐字稿搜尋在 <a href="/info/search">/info/search</a>。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li>會議：<code>場次代碼</code>、<code>委員會或主旨</code>、<code>日期</code>、<code>地點</code></li>
                <li>逐字稿：<code>會議代碼</code>、<code>順序</code>、<code>姓名</code>、<code>職稱</code>、<code>身分類別</code>、<code>發言內容</code></li>
                <li>逐字稿 <code>對應代碼</code>：發言人對應的議員人物代碼或行政機關代碼</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/meets?場次代碼=…</code> — 場次的會議</li>
                <li><code>GET /api/meet_notes?會議代碼=…</code></li>
                <li><code>GET /api/meet_transcripts?會議代碼=…</code></li>
              </ul>
              <a href="/viewer/collection/list/meet" class="btn btn-sm btn-outline-danger mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 議案 -->
      <div class="card entity-card bill mb-3">
        <div class="card-body">
          <h5 class="card-title">📝 議案（bill）</h5>
          <p class="card-text small">大會與委員會審議的個別提案。來源的議事錄常常一份橫跨好幾個會期，所以議案只對應到<strong>屆</strong>，沒有會期、場次代碼。政府提案填<code>提案單位</code>，議員提案填<code>提案人</code>。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>議會代碼</code>、<code>屆</code>、<code>類別</code>、<code>案號</code></li>
                <li><code>提案單位</code>、<code>提案人</code>、<code>連署人</code></li>
                <li><code>案由</code>、<code>說明</code>、<code>辦法</code>、<code>審查意見</code>、<code>議決</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/bills?議會代碼=tpe</code> — 議案清單</li>
                <li><code>GET /api/bills?agg=議會代碼</code> — 各議會議案數</li>
              </ul>
              <a href="/viewer/collection/list/bill" class="btn btn-sm btn-outline-secondary mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 委員會 -->
      <div class="card entity-card committee mb-3">
        <div class="card-body">
          <h5 class="card-title">👥 委員會（committee）</h5>
          <p class="card-text small">各議會設置的常設或特種委員會。委員會<strong>不綁屆</strong>，以生效日期與廢止日期記錄存續期間。各縣市委員會數量與名稱差異很大（大縣市有多個固定委員會，小縣市如連江縣直接全體審查）。目前沒有委員會成員資料。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：例 <code>tpe-c1</code></li>
                <li><code>名稱</code>、<code>別稱</code>、<code>類別</code>（常設/特種）</li>
                <li><code>職掌</code>：負責審查的業務範圍</li>
                <li><code>生效日期</code>、<code>廢止日期</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/committees</code> — 委員會清單</li>
                <li><code>GET /api/committee/tpe-c1</code> — 單一委員會</li>
              </ul>
              <a href="/viewer/collection/list/committee" class="btn btn-sm btn-outline-secondary mt-2" style="border-color:#6f42c1;color:#6f42c1">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 行政機關 -->
      <div class="card entity-card agency mb-3">
        <div class="card-body">
          <h5 class="card-title">🏢 行政機關（agency）</h5>
          <p class="card-text small">議會監督的縣市政府機關，每個議會一棵樹：縣市政府底下分成一級內部單位、一級外部單位、二級機關與其他單位（學校、戶政所、區公所等），依縣市政府組織架構的順序排列。網頁上的「行政體系」分頁另外整理了歷任首長與在議會答詢過的官員。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：例 <code>tpe-379000000A</code></li>
                <li><code>名稱</code>、<code>全名</code>、<code>機關代碼</code></li>
                <li><code>分類</code>、<code>上層代碼</code>、<code>排序</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/agencies?議會代碼=tpe</code> — 機關清單</li>
                <li><code>GET /api/agency/tpe-379000000A</code> — 單一機關</li>
              </ul>
              <a href="/viewer/collection/list/agency" class="btn btn-sm btn-outline-info mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 候選人 -->
      <div class="card entity-card candidate mb-3">
        <div class="card-body">
          <h5 class="card-title">🗳 候選人（candidate）</h5>
          <p class="card-text small">各次選舉的<strong>所有</strong>登記參選人（含落選者），不只議員，也包含縣市長、鄉鎮市長、鄉鎮市民代表、村里長、立法委員、總統副總統等選舉，附得票數、當選註記，以及選舉公報上的學歷、經歷、政見與相片。同一人歷次參選以<code>人物代碼</code>串連（<code>/info/candidate/{人物代碼}</code>），即將舉行的選舉另有選舉指南頁 <code>/info/election/{年}</code>。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>選舉名稱</code>、<code>年份</code>、<code>參選職位</code>、<code>選區別</code></li>
                <li><code>姓名</code>、<code>人物代碼</code>、<code>號次</code></li>
                <li><code>其他欄位</code>：性別、推薦之政黨、出生日期、出生地</li>
                <li><code>得票數</code>、<code>得票率</code>、<code>當選</code></li>
                <li><code>學歷</code>、<code>經歷</code>、<code>政見</code>（附來源類型）</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/candidates?年份=111&amp;議會代碼=tpe</code></li>
                <li><code>GET /api/candidates?人物代碼=…</code> — 某人的參選紀錄</li>
              </ul>
              <a href="/viewer/collection/list/candidate" class="btn btn-sm btn-outline-success mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 財產申報 -->
      <div class="card entity-card declaration mb-3">
        <div class="card-body">
          <h5 class="card-title">💰 財產申報（declaration）</h5>
          <p class="card-text small">監察院廉政專刊刊登的財產申報資料，依申報資料記載的候選人代碼對應到人物代碼，顯示在候選人頁面上。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>姓名</code>、<code>人物代碼</code>、<code>候選人代碼</code></li>
                <li><code>申報日</code>、<code>出版日</code>、<code>種類</code>、<code>申報類別</code></li>
                <li><code>服務機關紀錄</code></li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /api/declarations?人物代碼=…</code></li>
              </ul>
              <a href="/viewer/collection/list/declaration" class="btn btn-sm btn-outline-dark mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── 5. 資料完整度 ── -->
    <section id="completeness" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">5. 資料完整度</h2>
      <p>議會資料的收錄是一個持續進行中的工作。部分議會（尤其是已廢止或小型議會）的歷史資料可能需要人工整理紙本資料，因此各議會的資料完整程度不一。</p>
      <div class="alert alert-warning small">
        ⚠️ <strong>「缺」不代表資料不存在</strong>，只代表目前尚未收錄到資料庫中。資料持續補充中。
      </div>
      <p>完整度依每一屆是否有資料計算，分成完整、部分缺漏、缺三種狀態：</p>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>資料類型</th><th>完整條件</th></tr>
        </thead>
        <tbody>
          <tr><td>屆</td><td>最新一屆的任期屆滿日未過（表示資料有追上現任）</td></tr>
          <tr><td>議員</td><td>每一屆都有議員資料</td></tr>
          <tr><td>會期</td><td>每一屆都有會期，且最後一筆會期結束日距今（歷史屆次：距任期屆滿日）不超過 90 天</td></tr>
          <tr><td>場次</td><td>每一屆都有場次資料</td></tr>
          <tr><td>逐字稿</td><td>每一屆都有逐字稿</td></tr>
          <tr><td>議案</td><td>每一屆都有議案</td></tr>
        </tbody>
      </table>
      <a href="/viewer/collection/completeness" class="btn btn-outline-secondary btn-sm">查看完整度總覽 →</a>
    </section>

    <!-- ── 法源依據 ── -->
    <section id="legal-basis" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">法源依據</h2>
      <p class="small text-body-secondary mb-2">CCAPI 收錄的直轄市議會、縣（市）議會是依下列法規設立、運作：</p>
      <ul class="small mb-0">
        <li><a href="https://law.moj.gov.tw/LawClass/LawAll.aspx?pcode=A0040003" target="_blank" rel="noopener">地方制度法</a>（全國法規資料庫）——地方自治團體、地方立法機關（議會）的組織、職權與運作的基本法律</li>
      </ul>
    </section>

    <!-- CTA -->
    <div class="text-center py-5 border-top">
      <h4 class="fw-semibold mb-3">開始使用</h4>
      <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="/viewer" class="btn btn-success">📊 資料瀏覽器</a>
        <a href="/swagger" class="btn btn-secondary">📄 API 文件</a>
        <a href="https://github.com/openfunltd/cc.govapi.tw" target="_blank" class="btn btn-outline-dark">⭐ GitHub</a>
      </div>
    </div>

    <footer class="py-4 border-top text-center text-body-secondary">
      <p class="small">&copy; <?= date('Y') ?> CCAPI — 由 <a href="https://openfun.tw" target="_blank">歐噴有限公司</a> 開發</p>
    </footer>

  </div>
</main>
</body>
</html>
