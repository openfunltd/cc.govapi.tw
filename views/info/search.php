<?php
$is_all = ($this->cc_code === 'all');
$postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$council_names = CouncilHelper::getAll();
$initial_q = $_GET['q'] ?? '';
?>

<div class="pt-4 pb-3">
  <h1 class="h3 fw-semibold mb-1">
    🔍 逐字稿搜尋<?= $is_all ? '' : '（' . htmlspecialchars($this->council_name) . '）' ?>
  </h1>
  <p class="text-body-secondary mb-3">
    <?= $is_all ? '搜尋全國議會逐字稿內容，看哪些議會提到過這個關鍵字' : '在本議會的逐字稿內容中搜尋' ?>，
    可依<?= $is_all ? '議會、' : '' ?>年份進一步篩選。
  </p>

  <div class="input-group mb-3">
    <span class="input-group-text">🔍</span>
    <input type="text" id="search-q" class="form-control" placeholder="輸入關鍵字…" value="<?= htmlspecialchars($initial_q) ?>">
    <button class="btn btn-primary" id="search-btn" type="button">搜尋</button>
  </div>

  <div class="row g-3 mb-3">
    <?php if ($is_all): ?>
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong class="small">依議會篩選</strong></div>
        <div class="card-body" id="facet-council" style="max-height:260px; overflow-y:auto;">
          <span class="text-muted small">請先輸入關鍵字搜尋</span>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="col-md-<?= $is_all ? 6 : 12 ?>">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong class="small">依年份篩選</strong></div>
        <div class="card-body" id="facet-year" style="max-height:260px; overflow-y:auto;">
          <span class="text-muted small">請先輸入關鍵字搜尋</span>
        </div>
      </div>
    </div>
  </div>

  <div id="search-summary" class="small text-body-secondary mb-2"></div>
  <div id="search-results"></div>
  <div id="search-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>
</div>

<script>
(function () {
  var isAll = <?= json_encode($is_all) ?>;
  var postfix = <?= json_encode($postfix) ?>;
  var councilNames = <?= json_encode($council_names, JSON_UNESCAPED_UNICODE) ?>;
  var apiBase = <?= json_encode(TypeHelper::getApiUrl('transcript')) ?>;
  var pageSize = 10;

  var state = { q: <?= json_encode($initial_q) ?>, council: null, year: null, page: 1 };

  function fp(name) { return encodeURIComponent(name); }

  function buildQuery(overrides) {
    var s = Object.assign({}, state, overrides || {});
    var parts = [];
    if (s.q) parts.push('q=' + encodeURIComponent(s.q));
    if (isAll && s.council) parts.push(fp('議會代碼') + '=' + encodeURIComponent(s.council));
    if (s.year) parts.push(fp('年') + '=' + s.year);
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

  // ES highlight 片段含 <em>...</em>，其餘文字要跳脫避免把逐字稿內容當 HTML 注入
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

  function renderResults(data) {
    var summary = document.getElementById('search-summary');
    var results = document.getElementById('search-results');
    var pagination = document.getElementById('search-pagination');

    summary.textContent = '共找到 ' + data.total + ' 筆逐字稿';
    var list = data.transcripts || [];
    results.innerHTML = '';
    if (!list.length) {
      results.innerHTML = '<div class="alert alert-light border">找不到符合的逐字稿</div>';
      pagination.innerHTML = '';
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

    var totalPages = Math.max(1, Math.ceil(data.total / pageSize));
    pagination.innerHTML = '';
    if (totalPages > 1) {
      var prev = document.createElement('button');
      prev.className = 'btn btn-sm btn-outline-secondary';
      prev.textContent = '← 上一頁';
      prev.disabled = state.page <= 1;
      prev.addEventListener('click', function () { state.page--; runSearch(); });
      pagination.appendChild(prev);

      var info = document.createElement('span');
      info.className = 'small';
      info.textContent = state.page + ' / ' + totalPages;
      pagination.appendChild(info);

      var next = document.createElement('button');
      next.className = 'btn btn-sm btn-outline-secondary';
      next.textContent = '下一頁 →';
      next.disabled = state.page >= totalPages;
      next.addEventListener('click', function () { state.page++; runSearch(); });
      pagination.appendChild(next);
    }
  }

  function runSearch() {
    if (!state.q) {
      document.getElementById('search-summary').textContent = '';
      document.getElementById('search-results').innerHTML = '';
      document.getElementById('search-pagination').innerHTML = '';
      return;
    }

    var resultParts = buildQuery().concat([
      'limit=' + pageSize, 'page=' + state.page,
      'output_fields=' + fp('代碼'), 'output_fields=' + fp('議會代碼'),
      'output_fields=' + fp('屆'), 'output_fields=' + fp('年'),
    ]);
    fetchJson(apiBase + '?' + resultParts.join('&')).then(renderResults);

    // 議會 facet：排除自己這個篩選條件，才能做 crossfilter（顯示「如果選這個議會會剩幾筆」）
    if (isAll) {
      var councilParts = buildQuery({ council: null }).concat(['limit=0', 'agg=' + fp('議會代碼')]);
      fetchJson(apiBase + '?' + councilParts.join('&')).then(function (d) {
        var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
        renderFacet('facet-council', buckets, state.council, '議會代碼', function (v) {
          state.council = v; state.page = 1; runSearch();
        }, function (v) { return councilNames[v] || v; });
      });
    }

    // 年份 facet
    var yearParts = buildQuery({ year: null }).concat(['limit=0', 'agg=' + fp('年')]);
    fetchJson(apiBase + '?' + yearParts.join('&')).then(function (d) {
      var buckets = ((d.aggs && d.aggs[0] && d.aggs[0].buckets) || []).slice().sort(function (a, b) {
        return b['年'] - a['年'];
      });
      renderFacet('facet-year', buckets, state.year, '年', function (v) {
        state.year = v; state.page = 1; runSearch();
      }, function (v) { return v + ' 年'; });
    });
  }

  document.getElementById('search-btn').addEventListener('click', function () {
    state.q = document.getElementById('search-q').value.trim();
    state.council = null; state.year = null; state.page = 1;
    runSearch();
  });
  document.getElementById('search-q').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('search-btn').click();
  });

  if (state.q) runSearch();
})();
</script>
