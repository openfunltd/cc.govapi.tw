<?php
$is_all = ($this->cc_code === 'all');
$postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$council_names = CouncilHelper::getAll();
$initial_q = $_GET['q'] ?? '';
?>

<div class="pt-4 pb-3">
  <h1 class="h3 fw-semibold mb-1">
    🔍 搜尋<?= $is_all ? '' : '（' . htmlspecialchars($this->council_name) . '）' ?>
  </h1>
  <p class="text-body-secondary mb-3">
    分別搜尋逐字稿內容跟議案內容（案由/審查意見/議決），兩者是不同性質的資料，放在不同分頁。
  </p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="search-tab-transcript" data-bs-toggle="tab" data-bs-target="#search-pane-transcript" type="button" role="tab">逐字稿</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="search-tab-bill" data-bs-toggle="tab" data-bs-target="#search-pane-bill" type="button" role="tab">議案</button>
    </li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="search-pane-transcript" role="tabpanel">
      <div class="input-group mb-3">
        <span class="input-group-text">🔍</span>
        <input type="text" id="t-q" class="form-control" placeholder="搜尋逐字稿內容…" value="<?= htmlspecialchars($initial_q) ?>">
        <button class="btn btn-primary" id="t-search-btn" type="button">搜尋</button>
      </div>

      <div class="row g-3 mb-3">
        <?php if ($is_all): ?>
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header py-2"><strong class="small">依議會篩選</strong></div>
            <div class="card-body" id="t-facet-council" style="max-height:260px; overflow-y:auto;">
              <span class="text-muted small">請先輸入關鍵字搜尋</span>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $is_all ? 6 : 12 ?>">
          <div class="card shadow-sm">
            <div class="card-header py-2"><strong class="small">依年份篩選</strong></div>
            <div class="card-body" id="t-facet-year" style="max-height:260px; overflow-y:auto;">
              <span class="text-muted small">請先輸入關鍵字搜尋</span>
            </div>
          </div>
        </div>
      </div>

      <div id="t-summary" class="small text-body-secondary mb-2"></div>
      <div id="t-results"></div>
      <div id="t-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>
    </div>

    <div class="tab-pane fade" id="search-pane-bill" role="tabpanel">
      <div class="alert alert-light border small">
        議案目前只涵蓋少數議會，是持續擴充中的實驗性補充資料。
      </div>
      <div class="input-group mb-3">
        <span class="input-group-text">🔍</span>
        <input type="text" id="b-q" class="form-control" placeholder="搜尋案由、審查意見、議決…">
        <button class="btn btn-primary" id="b-search-btn" type="button">搜尋</button>
      </div>

      <div class="row g-3 mb-3">
        <?php if ($is_all): ?>
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header py-2"><strong class="small">依議會篩選</strong></div>
            <div class="card-body" id="b-facet-council" style="max-height:260px; overflow-y:auto;">
              <span class="text-muted small">請先輸入關鍵字搜尋</span>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $is_all ? 6 : 12 ?>">
          <div class="card shadow-sm">
            <div class="card-header py-2"><strong class="small">依類別篩選</strong></div>
            <div class="card-body" id="b-facet-category" style="max-height:260px; overflow-y:auto;">
              <span class="text-muted small">請先輸入關鍵字搜尋</span>
            </div>
          </div>
        </div>
      </div>

      <div id="b-summary" class="small text-body-secondary mb-2"></div>
      <div id="b-results"></div>
      <div id="b-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>
    </div>
  </div>
</div>

<script>
(function () {
  var isAll = <?= json_encode($is_all) ?>;
  var postfix = <?= json_encode($postfix) ?>;
  var councilNames = <?= json_encode($council_names, JSON_UNESCAPED_UNICODE) ?>;
  var pageSize = 10;

  function fp(name) { return encodeURIComponent(name); }

  function fetchJson(url) {
    return fetch(url).then(function (r) { return r.json(); });
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  // ES highlight 片段含 <em>...</em>，其餘文字要跳脫避免把內容當 HTML 注入
  function safeHighlight(raw) {
    return raw.split(/(<em>|<\/em>)/).map(function (part) {
      if (part === '<em>' || part === '</em>') return part;
      return escapeHtml(part);
    }).join('');
  }

  function renderFacet(containerId, buckets, activeValue, keyField, onPick, formatLabel) {
    var el = document.getElementById(containerId);
    el.innerHTML = '';
    if (!buckets || !buckets.length) {
      el.innerHTML = '<span class="text-muted small">無資料</span>';
      return;
    }
    var allBtn = document.createElement('span');
    allBtn.className = 'badge me-1 mb-1 ' + (activeValue == null ? 'bg-primary' : 'bg-light text-dark border');
    allBtn.style.cursor = 'pointer';
    allBtn.textContent = '全部';
    allBtn.addEventListener('click', function () { onPick(null); });
    el.appendChild(allBtn);

    buckets.forEach(function (b) {
      var value = b[keyField];
      var active = (activeValue != null && String(activeValue) === String(value));
      var badge = document.createElement('span');
      badge.className = 'badge me-1 mb-1 ' + (active ? 'bg-primary' : 'bg-light text-dark border');
      badge.style.cursor = 'pointer';
      badge.textContent = formatLabel(value) + '（' + b.count + '）';
      badge.addEventListener('click', function () { onPick(value); });
      el.appendChild(badge);
    });
  }

  function renderPagination(containerId, state, total, onChange) {
    var pagination = document.getElementById(containerId);
    var totalPages = Math.max(1, Math.ceil(total / pageSize));
    pagination.innerHTML = '';
    if (totalPages <= 1) return;

    var prev = document.createElement('button');
    prev.className = 'btn btn-sm btn-outline-secondary';
    prev.textContent = '← 上一頁';
    prev.disabled = state.page <= 1;
    prev.addEventListener('click', function () { state.page--; onChange(); });
    pagination.appendChild(prev);

    var info = document.createElement('span');
    info.className = 'small';
    info.textContent = state.page + ' / ' + totalPages;
    pagination.appendChild(info);

    var next = document.createElement('button');
    next.className = 'btn btn-sm btn-outline-secondary';
    next.textContent = '下一頁 →';
    next.disabled = state.page >= totalPages;
    next.addEventListener('click', function () { state.page++; onChange(); });
    pagination.appendChild(next);
  }

  // ── 逐字稿搜尋 ──────────────────────────────────────────────────────────
  (function () {
    var apiBase = <?= json_encode(TypeHelper::getApiUrl('transcript')) ?>;
    var state = { q: <?= json_encode($initial_q) ?>, council: null, year: null, page: 1 };

    function buildQuery(overrides) {
      var s = Object.assign({}, state, overrides || {});
      var parts = [];
      if (s.q) parts.push('q=' + encodeURIComponent(s.q));
      if (isAll && s.council) parts.push(fp('議會代碼') + '=' + encodeURIComponent(s.council));
      if (s.year) parts.push(fp('年') + '=' + s.year);
      return parts;
    }

    function renderResults(data) {
      var summary = document.getElementById('t-summary');
      var results = document.getElementById('t-results');

      summary.textContent = '共找到 ' + data.total + ' 筆逐字稿';
      var list = data.transcripts || [];
      results.innerHTML = '';
      if (!list.length) {
        results.innerHTML = '<div class="alert alert-light border">找不到符合的逐字稿</div>';
        document.getElementById('t-pagination').innerHTML = '';
        return;
      }

      list.forEach(function (t) {
        var highlightArr = t['內容:highlight'];
        var snippetHtml = (highlightArr && highlightArr.length)
          ? highlightArr.map(safeHighlight).join(' … ')
          : '<span class="text-muted">（無摘要）</span>';
        var councilName = councilNames[t['議會代碼']] || t['議會代碼'];
        var href = (isAll ? ('https://' + t['議會代碼'] + postfix) : '')
          + '/info/' + t['屆'] + '/transcript/' + encodeURIComponent(t['代碼']);

        var card = document.createElement('div');
        card.className = 'card shadow-sm mb-2';
        var body = document.createElement('div');
        body.className = 'card-body py-2';

        var meta = document.createElement('div');
        meta.className = 'small text-body-secondary';
        meta.textContent = councilName + ' ・ 第' + t['屆'] + '屆' + (t['年'] ? ' ・ ' + t['年'] + ' 年' : '');
        body.appendChild(meta);

        var snippet = document.createElement('div');
        snippet.className = 'small mt-1';
        snippet.innerHTML = snippetHtml;
        body.appendChild(snippet);

        var link = document.createElement('a');
        link.href = href;
        link.target = '_blank';
        link.className = 'small';
        link.textContent = '查看完整逐字稿 →';
        body.appendChild(link);

        card.appendChild(body);
        results.appendChild(card);
      });

      renderPagination('t-pagination', state, data.total, run);
    }

    function run() {
      if (!state.q) {
        document.getElementById('t-summary').textContent = '';
        document.getElementById('t-results').innerHTML = '';
        document.getElementById('t-pagination').innerHTML = '';
        return;
      }

      var resultParts = buildQuery().concat([
        'limit=' + pageSize, 'page=' + state.page,
        'output_fields=' + fp('代碼'), 'output_fields=' + fp('議會代碼'),
        'output_fields=' + fp('屆'), 'output_fields=' + fp('年'),
      ]);
      fetchJson(apiBase + '?' + resultParts.join('&')).then(renderResults);

      if (isAll) {
        var councilParts = buildQuery({ council: null }).concat(['limit=0', 'agg=' + fp('議會代碼')]);
        fetchJson(apiBase + '?' + councilParts.join('&')).then(function (d) {
          var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
          renderFacet('t-facet-council', buckets, state.council, '議會代碼', function (v) {
            state.council = v; state.page = 1; run();
          }, function (v) { return councilNames[v] || v; });
        });
      }

      var yearParts = buildQuery({ year: null }).concat(['limit=0', 'agg=' + fp('年')]);
      fetchJson(apiBase + '?' + yearParts.join('&')).then(function (d) {
        var buckets = ((d.aggs && d.aggs[0] && d.aggs[0].buckets) || []).slice().sort(function (a, b) {
          return b['年'] - a['年'];
        });
        renderFacet('t-facet-year', buckets, state.year, '年', function (v) {
          state.year = v; state.page = 1; run();
        }, function (v) { return v + ' 年'; });
      });
    }

    document.getElementById('t-search-btn').addEventListener('click', function () {
      state.q = document.getElementById('t-q').value.trim();
      state.council = null; state.year = null; state.page = 1;
      run();
    });
    document.getElementById('t-q').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') document.getElementById('t-search-btn').click();
    });

    if (state.q) run();
  })();

  // ── 議案搜尋 ────────────────────────────────────────────────────────────
  (function () {
    var apiBase = <?= json_encode(TypeHelper::getApiUrl('bill')) ?>;
    var state = { q: '', council: null, category: null, page: 1 };

    function buildQuery(overrides) {
      var s = Object.assign({}, state, overrides || {});
      var parts = [];
      if (s.q) parts.push('q=' + encodeURIComponent(s.q));
      if (isAll && s.council) parts.push(fp('議會代碼') + '=' + encodeURIComponent(s.council));
      if (s.category) parts.push(fp('類別') + '=' + encodeURIComponent(s.category));
      return parts;
    }

    function fieldBlock(label, value) {
      if (!value) return '';
      return '<div class="small mt-1"><span class="text-body-secondary">' + escapeHtml(label) + '：</span>' + value + '</div>';
    }

    function renderResults(data) {
      var summary = document.getElementById('b-summary');
      var results = document.getElementById('b-results');

      summary.textContent = '共找到 ' + data.total + ' 筆議案';
      var list = data.bills || [];
      results.innerHTML = '';
      if (!list.length) {
        results.innerHTML = '<div class="alert alert-light border">找不到符合的議案</div>';
        document.getElementById('b-pagination').innerHTML = '';
        return;
      }

      list.forEach(function (b) {
        var councilName = councilNames[b['議會代碼']] || b['議會代碼'];
        var href = (isAll ? ('https://' + b['議會代碼'] + postfix) : '')
          + '/info/' + (b['屆'] || 0) + '/bill/' + encodeURIComponent(b['代碼']);

        var card = document.createElement('div');
        card.className = 'card shadow-sm mb-2';
        var body = document.createElement('div');
        body.className = 'card-body py-2';

        var meta = document.createElement('div');
        meta.className = 'small text-body-secondary';
        meta.textContent = councilName + (b['屆'] ? ' ・ 第' + b['屆'] + '屆' : '') + (b['案號'] ? ' ・ ' + b['案號'] : '');
        if (b['類別']) {
          var badge = document.createElement('span');
          badge.className = 'badge bg-secondary ms-1';
          badge.textContent = b['類別'];
          meta.appendChild(badge);
        }
        body.appendChild(meta);

        var caseHtml = (b['案由:highlight'] && b['案由:highlight'].length)
          ? b['案由:highlight'].map(safeHighlight).join(' … ')
          : escapeHtml(b['案由'] || '');
        var html = '<div class="small mt-1">' + caseHtml + '</div>';
        html += fieldBlock('審查意見', escapeHtml(b['審查意見'] || ''));
        html += fieldBlock('議決', escapeHtml(b['議決'] || ''));
        body.insertAdjacentHTML('beforeend', html);

        var link = document.createElement('a');
        link.href = href;
        link.target = '_blank';
        link.className = 'small';
        link.textContent = '查看完整議案 →';
        body.appendChild(link);

        card.appendChild(body);
        results.appendChild(card);
      });

      renderPagination('b-pagination', state, data.total, run);
    }

    function run() {
      if (!state.q) {
        document.getElementById('b-summary').textContent = '';
        document.getElementById('b-results').innerHTML = '';
        document.getElementById('b-pagination').innerHTML = '';
        return;
      }

      var resultParts = buildQuery().concat(['limit=' + pageSize, 'page=' + state.page]);
      fetchJson(apiBase + '?' + resultParts.join('&')).then(renderResults);

      if (isAll) {
        var councilParts = buildQuery({ council: null }).concat(['limit=0', 'agg=' + fp('議會代碼')]);
        fetchJson(apiBase + '?' + councilParts.join('&')).then(function (d) {
          var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
          renderFacet('b-facet-council', buckets, state.council, '議會代碼', function (v) {
            state.council = v; state.page = 1; run();
          }, function (v) { return councilNames[v] || v; });
        });
      }

      var categoryParts = buildQuery({ category: null }).concat(['limit=0', 'agg=' + fp('類別')]);
      fetchJson(apiBase + '?' + categoryParts.join('&')).then(function (d) {
        var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
        renderFacet('b-facet-category', buckets, state.category, '類別', function (v) {
          state.category = v; state.page = 1; run();
        }, function (v) { return v; });
      });
    }

    document.getElementById('b-search-btn').addEventListener('click', function () {
      state.q = document.getElementById('b-q').value.trim();
      state.council = null; state.category = null; state.page = 1;
      run();
    });
    document.getElementById('b-q').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') document.getElementById('b-search-btn').click();
    });
  })();
})();
</script>
