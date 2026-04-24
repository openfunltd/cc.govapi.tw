<!DOCTYPE html>
<html lang="zh-tw">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <title>CCAPI 說明 — 地方議會開放 API</title>
  <style>
    body { font-family: 'Noto Sans TC', sans-serif; }
    .entity-card { border-left: 4px solid; }
    .entity-card.council  { border-color: #0d6efd; }
    .entity-card.term     { border-color: #198754; }
    .entity-card.session  { border-color: #0dcaf0; }
    .entity-card.councilor { border-color: #ffc107; }
    .entity-card.committee { border-color: #6f42c1; }
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
      <p>CCAPI 提供三種主要介面：</p>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="card h-100 border-primary">
            <div class="card-body">
              <h6 class="card-title">🏛 首頁（現在這裡）</h6>
              <p class="card-text small text-body-secondary">各議會的 API 入口，切換縣市後顯示對應的 API 端點列表。</p>
              <a href="/" class="btn btn-sm btn-outline-primary">前往首頁</a>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 border-success">
            <div class="card-body">
              <h6 class="card-title">📊 資料瀏覽器</h6>
              <p class="card-text small text-body-secondary">視覺化瀏覽議員、屆期、委員會等資料，支援篩選與搜尋，不需要寫程式。</p>
              <a href="/viewer" class="btn btn-sm btn-outline-success">前往瀏覽器</a>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 border-secondary">
            <div class="card-body">
              <h6 class="card-title">📄 API 文件（Swagger）</h6>
              <p class="card-text small text-body-secondary">完整的 API 規格說明，適合開發者整合資料到自己的應用程式。</p>
              <a href="/swagger" class="btn btn-sm btn-outline-secondary">前往 API 文件</a>
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
          <tr><td colspan="2" class="text-body-secondary small">其餘依此類推，代碼對照請見<a href="/">首頁</a>的議會清單</td></tr>
        </tbody>
      </table>
      <p class="small text-body-secondary">資料瀏覽器也支援相同的子網域邏輯：<code>tpe.cc.govapi.tw/viewer</code> 會自動鎖定臺北市議會。</p>
    </section>

    <!-- ── 2. 什麼是地方議會 ── -->
    <section id="background" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">2. 什麼是地方議會</h2>
      <p>台灣的地方民意機關依<strong>地方制度法</strong>成立，分為三個層級：</p>
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
      <div class="alert alert-info small">ℹ️ 目前 CCAPI 只收錄<strong>直轄市議會</strong>與<strong>縣（市）議會</strong>共 36 個（含 14 個歷史廢止議會），暫不含鄉鎮市民代表會。</div>

      <h5 class="mt-4">法定會議類型</h5>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>類型</th><th>頻率</th><th>法定上限</th></tr>
        </thead>
        <tbody>
          <tr><td>定期會</td><td>每半年一次（每年2次）</td><td>直轄市 ≤ 70 天；縣市 ≤ 30～40 天</td></tr>
          <tr><td>臨時會</td><td>有需要時召開</td><td>直轄市每次 ≤ 10 天，每年 ≤ 8 次</td></tr>
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
 └── 屆（term）
      ├── 議員（councilor）
      └── 會期（session）

議會（council）
 └── 委員會（committee）     ← 不綁屆，跨屆存續</pre>
      </div>
      <p class="small text-body-secondary">未來計劃新增：場次（sitting）、開會日、會議紀錄等，請參考<a href="https://github.com/openfunltd/open-forest-research" target="_blank">研究文件</a>。</p>

      <h5 class="mt-4">識別碼設計原則</h5>
      <p>每個實體都有一個自管的代碼（slug），格式如下：</p>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>實體</th><th>格式</th><th>範例</th></tr>
        </thead>
        <tbody>
          <tr><td>議會</td><td><code>{slug}</code></td><td><code>nwt</code></td></tr>
          <tr><td>屆</td><td><code>{slug}-{屆次}</code></td><td><code>nwt-4</code></td></tr>
          <tr><td>會期</td><td><code>{屆代碼}-{類別縮寫}{次別}</code></td><td><code>nwt-4-r1</code>（定期會第1次）<br><code>nwt-4-e2</code>（臨時會第2次）<br><code>nwt-4-i</code>（成立大會）</td></tr>
          <tr><td>委員會</td><td><code>{議會代碼}-c{流水號}</code></td><td><code>tpe-c1</code></td></tr>
        </tbody>
      </table>
      <p class="small text-body-secondary">採用自管 slug 而非內政部代碼或 ISO 碼，原因是外部代碼在歷史上有重複使用問題（例如省轄高雄市與直轄高雄市內政部代碼同為 64000），無法唯一識別歷史實體。</p>
    </section>

    <!-- ── 4. 各實體說明 ── -->
    <section id="entity-detail" class="section-anchor mb-5">
      <h2 class="h4 fw-semibold mb-3">4. 各實體說明</h2>

      <!-- 議會 -->
      <div class="card entity-card council mb-3">
        <div class="card-body">
          <h5 class="card-title">🏛 議會（council）</h5>
          <p class="card-text small">全台 36 個議會（22 個現存 + 14 個歷史廢止），每個議會有唯一代碼，同時作為 API 子網域。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>代碼</code>：自管 slug，例 <code>nwt</code></li>
                <li><code>議會名稱</code>、<code>議會類別</code></li>
                <li><code>生效日期</code>、<code>廢止日期</code>（null = 現存）</li>
                <li><code>wikidata-id</code>、<code>ISO碼</code>（僅供參考）</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /councils</code> — 議會列表</li>
                <li><code>GET /council/tpe</code> — 單一議會</li>
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
          <p class="card-text small">每次議員改選後產生一屆。屆次從各議會設立時的第1屆開始計算，各議會獨立計算。</p>
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
                <li><code>GET /terms</code> — 屆清單</li>
                <li><code>GET /term/nwt-4</code> — 單一屆</li>
              </ul>
              <a href="/viewer/collection/list/term" class="btn btn-sm btn-outline-success mt-2">瀏覽器查看 →</a>
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
                <li><code>GET /sessions</code> — 會期清單</li>
                <li><code>GET /session/nwt-4-r1</code> — 單一會期</li>
              </ul>
              <a href="/viewer/collection/list/session" class="btn btn-sm btn-outline-info mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 議員 -->
      <div class="card entity-card councilor mb-3">
        <div class="card-body">
          <h5 class="card-title">👤 議員（councilor）</h5>
          <p class="card-text small">每人每屆各建一筆記錄。同一人當選多屆時，會在不同屆次各有一筆，未來將透過「人物代碼」跨屆連結。</p>
          <div class="row g-2">
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">主要欄位</p>
              <ul class="small mb-0">
                <li><code>姓名</code>、<code>性別</code>、<code>黨籍</code></li>
                <li><code>職稱</code>：議員 / 副議長 / 議長</li>
                <li><code>選區名稱</code>、<code>屆</code>（所屬屆代碼）</li>
                <li><code>照片位址</code>、<code>電子信箱</code> 等聯絡資訊</li>
              </ul>
            </div>
            <div class="col-md-6">
              <p class="small mb-1 fw-semibold">API 路徑</p>
              <ul class="small mb-0">
                <li><code>GET /councilors</code> — 議員清單</li>
                <li><code>GET /councilors?黨籍=民主進步黨</code></li>
                <li><code>GET /councilors?agg=黨籍</code> — 統計</li>
              </ul>
              <a href="/viewer/collection/list/councilor" class="btn btn-sm btn-outline-warning mt-2">瀏覽器查看 →</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 委員會 -->
      <div class="card entity-card committee mb-3">
        <div class="card-body">
          <h5 class="card-title">👥 委員會（committee）</h5>
          <p class="card-text small">各議會設置的常設或特種委員會。委員會<strong>不綁屆</strong>，以生效日期與廢止日期記錄存續期間。各縣市委員會數量與名稱差異很大（大縣市有多個固定委員會，小縣市如連江縣直接全體審查）。</p>
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
                <li><code>GET /committees</code> — 委員會清單</li>
                <li><code>GET /committee/tpe-c1</code> — 單一委員會</li>
              </ul>
              <a href="/viewer/collection/list/committee" class="btn btn-sm btn-outline-secondary mt-2" style="border-color:#6f42c1;color:#6f42c1">瀏覽器查看 →</a>
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
      <p>完整度指標的評估方式：</p>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light">
          <tr><th>資料類型</th><th>OK 條件</th></tr>
        </thead>
        <tbody>
          <tr><td>屆</td><td>最新一屆的任期屆滿日未過（表示資料是最新的）</td></tr>
          <tr><td>議員</td><td>有議員資料的屆數 / 總屆數 = 100%</td></tr>
          <tr><td>會期</td><td>最後一筆會期結束日距今不超過 90 天</td></tr>
        </tbody>
      </table>
      <a href="/viewer/collection/completeness" class="btn btn-outline-secondary btn-sm">查看完整度總覽 →</a>
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
