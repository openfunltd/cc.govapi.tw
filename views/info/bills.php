<?php
$cc_code = $this->cc_code;
$term_no = $this->term_no;
?>

<div class="alert alert-light border small mb-3">
  議案是當天大會/委員會實際在審的個別提案內容，跟「會期」「場次」不是同一顆粒度。
  目前只有部分議會有這份資料，涵蓋範圍持續擴充中；也還沒辦法連到精確的會期或場次，
  只能知道大概是哪一屆。
</div>

<div class="input-group mb-3">
  <span class="input-group-text">🔍</span>
  <input type="text" id="bill-q" class="form-control" placeholder="搜尋案由、審查意見、議決…">
  <button class="btn btn-primary" id="bill-search-btn" type="button">搜尋</button>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header py-2"><strong class="small">依類別篩選</strong></div>
      <div class="card-body" id="bill-facet-category" style="max-height:260px; overflow-y:auto;">
        <span class="text-muted small">載入中…</span>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header py-2"><strong class="small">依提案人篩選</strong></div>
      <div class="card-body" id="bill-facet-proposer" style="max-height:260px; overflow-y:auto;">
        <span class="text-muted small">載入中…</span>
      </div>
    </div>
  </div>
</div>

<div id="bill-summary" class="small text-body-secondary mb-2"></div>
<div id="bill-results"></div>
<div id="bill-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>

<script>
(function () {
  var apiBase = <?= json_encode(TypeHelper::getApiUrl('bill')) ?>;
  var ccCode = <?= json_encode($cc_code) ?>;
  var termNo = <?= json_encode($term_no) ?>;
  var pageSize = 10;

  var state = { q: '', category: null, proposer: null, page: 1 };

  function fp(name) { return encodeURIComponent(name); }

  function baseParts() {
    return [fp('議會代碼') + '=' + encodeURIComponent(ccCode), fp('屆') + '=' + termNo];
  }

  function buildQuery(overrides) {
    var s = Object.assign({}, state, overrides || {});
    var parts = baseParts();
    if (s.q) parts.push('q=' + encodeURIComponent(s.q));
    if (s.category) parts.push(fp('類別') + '=' + encodeURIComponent(s.category));
    if (s.proposer) parts.push(fp('提案人') + '=' + encodeURIComponent(s.proposer));
    return parts;
  }

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

  function renderFacet(containerId, stateKey, esField, buckets) {
    var el = document.getElementById(containerId);
    el.innerHTML = '';
    if (!buckets || !buckets.length) {
      el.innerHTML = '<span class="text-muted small">無資料</span>';
      return;
    }
    var allBtn = document.createElement('span');
    allBtn.className = 'badge me-1 mb-1 ' + (state[stateKey] == null ? 'bg-primary' : 'bg-light text-dark border');
    allBtn.style.cursor = 'pointer';
    allBtn.textContent = '全部';
    allBtn.addEventListener('click', function () { state[stateKey] = null; state.page = 1; run(); });
    el.appendChild(allBtn);

    buckets.forEach(function (b) {
      var value = b[esField];
      var active = (state[stateKey] != null && state[stateKey] === value);
      var badge = document.createElement('span');
      badge.className = 'badge me-1 mb-1 ' + (active ? 'bg-primary' : 'bg-light text-dark border');
      badge.style.cursor = 'pointer';
      badge.textContent = value + '（' + b.count + '）';
      badge.addEventListener('click', function () { state[stateKey] = value; state.page = 1; run(); });
      el.appendChild(badge);
    });
  }

  function fieldBlock(label, value) {
    if (!value) return '';
    return '<div class="small mt-1"><span class="text-body-secondary">' + escapeHtml(label) + '：</span>' + value + '</div>';
  }

  function renderResults(data) {
    var summary = document.getElementById('bill-summary');
    var results = document.getElementById('bill-results');
    var pagination = document.getElementById('bill-pagination');

    summary.textContent = '共找到 ' + data.total + ' 筆議案';
    var list = data.bills || [];
    results.innerHTML = '';
    if (!list.length) {
      results.innerHTML = '<div class="alert alert-light border">找不到符合的議案</div>';
      pagination.innerHTML = '';
      return;
    }

    list.forEach(function (b) {
      var card = document.createElement('div');
      card.className = 'card shadow-sm mb-2';
      var body = document.createElement('div');
      body.className = 'card-body py-2';

      var meta = document.createElement('div');
      meta.className = 'small fw-semibold';
      meta.textContent = (b['案號'] || '') + (b['提案單位'] ? '・' + b['提案單位'] : '') + (b['提案人'] ? '・' + b['提案人'] : '');
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

      card.appendChild(body);
      results.appendChild(card);
    });

    var totalPages = Math.max(1, Math.ceil(data.total / pageSize));
    pagination.innerHTML = '';
    if (totalPages > 1) {
      var prev = document.createElement('button');
      prev.className = 'btn btn-sm btn-outline-secondary';
      prev.textContent = '← 上一頁';
      prev.disabled = state.page <= 1;
      prev.addEventListener('click', function () { state.page--; run(); });
      pagination.appendChild(prev);

      var info = document.createElement('span');
      info.className = 'small';
      info.textContent = state.page + ' / ' + totalPages;
      pagination.appendChild(info);

      var next = document.createElement('button');
      next.className = 'btn btn-sm btn-outline-secondary';
      next.textContent = '下一頁 →';
      next.disabled = state.page >= totalPages;
      next.addEventListener('click', function () { state.page++; run(); });
      pagination.appendChild(next);
    }
  }

  function run() {
    var resultParts = buildQuery().concat(['limit=' + pageSize, 'page=' + state.page]);
    fetchJson(apiBase + '?' + resultParts.join('&')).then(renderResults);

    // 類別／提案人 facet：排除自己這個篩選條件，才能做 crossfilter
    var categoryParts = buildQuery({ category: null }).concat(['limit=0', 'agg=' + fp('類別')]);
    fetchJson(apiBase + '?' + categoryParts.join('&')).then(function (d) {
      var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
      renderFacet('bill-facet-category', 'category', '類別', buckets);
    });

    var proposerParts = buildQuery({ proposer: null }).concat(['limit=0', 'agg=' + fp('提案人')]);
    fetchJson(apiBase + '?' + proposerParts.join('&')).then(function (d) {
      var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
      renderFacet('bill-facet-proposer', 'proposer', '提案人', buckets);
    });
  }

  document.getElementById('bill-search-btn').addEventListener('click', function () {
    state.q = document.getElementById('bill-q').value.trim();
    state.page = 1;
    run();
  });
  document.getElementById('bill-q').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('bill-search-btn').click();
  });

  run();
})();
</script>
