<?php
$is_all = ($this->cc_code === 'all');
$postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$council_names = CouncilHelper::getAll();
$initial_q = $_GET['q'] ?? '';

// 四個獨立搜尋分頁的設定：key 同時是 DOM id 前綴跟 JS 端 renderer 的對照 key。
// facets 最多兩個，第一個「議會代碼」只在全國版（all）才顯示，單一議會子網域本來就
// 已經鎖定單一議會，不需要再篩一次。
$search_tabs = [
    [
        'key' => 'name', 'label' => '議員姓名',
        'placeholder' => '搜尋議員姓名…',
        'note' => '只搜尋「查得到議員記錄」的人（當選過至少一次）；落選紀錄請用「政見」分頁搜尋。',
        'summary_unit' => '位議員',
        'no_results' => '找不到符合的議員',
        'facets' => [
            ['field' => '議會代碼', 'label' => '依議會篩選', 'only_all' => true],
            ['field' => '黨籍', 'label' => '依黨籍篩選'],
        ],
    ],
    [
        'key' => 'transcript', 'label' => '逐字稿',
        'placeholder' => '搜尋逐字稿內容…',
        'note' => null,
        'summary_unit' => '筆逐字稿',
        'no_results' => '找不到符合的逐字稿',
        'facets' => [
            ['field' => '議會代碼', 'label' => '依議會篩選', 'only_all' => true],
            ['field' => '年', 'label' => '依年份篩選', 'sort' => 'year_desc'],
        ],
    ],
    [
        'key' => 'platform', 'label' => '政見',
        'placeholder' => '搜尋候選人政見…',
        'note' => '只有「政見來源」是可用文字的候選人資料才搜得到，圖片政見不在搜尋範圍內；包含落選的候選人。',
        'summary_unit' => '筆政見',
        'no_results' => '找不到符合的政見',
        'facets' => [
            ['field' => '議會代碼', 'label' => '依議會篩選', 'only_all' => true],
            ['field' => '當選', 'label' => '依是否當選篩選', 'boolean' => true],
        ],
    ],
    [
        'key' => 'bill', 'label' => '議案',
        'placeholder' => '搜尋案由、審查意見、議決…',
        'note' => '議案目前只涵蓋少數議會，是持續擴充中的實驗性補充資料。',
        'summary_unit' => '筆議案',
        'no_results' => '找不到符合的議案',
        'facets' => [
            ['field' => '議會代碼', 'label' => '依議會篩選', 'only_all' => true],
            ['field' => '類別', 'label' => '依類別篩選'],
        ],
    ],
];

// 分享網址時要能還原搜尋狀態：從網址的 tab/q 決定預設開哪個分頁、分頁裡預先帶入
// 什麼關鍵字。沒有 tab 參數但有 q 時，沿用舊網址的假設（以前只有逐字稿能被分享）
// 落在「逐字稿」分頁，維持舊分享連結（例如 /info/search?q=xxx）行為不變。
$valid_tab_keys = array_column($search_tabs, 'key');
$initial_tab = $_GET['tab'] ?? '';
if (!in_array($initial_tab, $valid_tab_keys, true)) {
    $initial_tab = ($initial_q !== '') ? 'transcript' : $search_tabs[0]['key'];
}
?>

<div class="pt-4 pb-3">
  <h1 class="h3 fw-semibold mb-1">
    🔍 搜尋<?= $is_all ? '' : '（' . htmlspecialchars($this->council_name) . '）' ?>
  </h1>
  <p class="text-body-secondary mb-3">
    議員姓名、逐字稿、政見、議案是四種不同性質的資料，分開搜尋、分開篩選。
  </p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach ($search_tabs as $t): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $t['key'] === $initial_tab ? 'active' : '' ?>" id="search-tab-<?= $t['key'] ?>" data-bs-toggle="tab" data-bs-target="#search-pane-<?= $t['key'] ?>" type="button" role="tab"><?= htmlspecialchars($t['label']) ?></button>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="tab-content">
    <?php foreach ($search_tabs as $t): $key = $t['key']; ?>
    <div class="tab-pane fade <?= $key === $initial_tab ? 'show active' : '' ?>" id="search-pane-<?= $key ?>" role="tabpanel">
      <?php if ($t['note']): ?>
      <div class="alert alert-light border small"><?= htmlspecialchars($t['note']) ?></div>
      <?php endif; ?>

      <div class="input-group mb-3">
        <span class="input-group-text">🔍</span>
        <input type="text" id="<?= $key ?>-q" class="form-control" placeholder="<?= htmlspecialchars($t['placeholder']) ?>" value="<?= $key === $initial_tab ? htmlspecialchars($initial_q) : '' ?>">
        <button class="btn btn-primary" id="<?= $key ?>-search-btn" type="button">搜尋</button>
      </div>

      <?php
        $visible_facets = array_values(array_filter($t['facets'], function ($f) use ($is_all) {
            return empty($f['only_all']) || $is_all;
        }));
      ?>
      <?php if ($visible_facets): ?>
      <div class="row g-3 mb-3">
        <?php foreach ($visible_facets as $i => $f): ?>
        <div class="col-md-<?= count($visible_facets) > 1 ? 6 : 12 ?>">
          <div class="card shadow-sm">
            <div class="card-header py-2"><strong class="small"><?= htmlspecialchars($f['label']) ?></strong></div>
            <div class="card-body" id="<?= $key ?>-facet-<?= $i ?>" style="max-height:260px; overflow-y:auto;">
              <span class="text-muted small">請先輸入關鍵字搜尋</span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div id="<?= $key ?>-summary" class="small text-body-secondary mb-2"></div>
      <div id="<?= $key ?>-results"></div>
      <div id="<?= $key ?>-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  var isAll = <?= json_encode($is_all) ?>;
  var postfix = <?= json_encode($postfix) ?>;
  var councilNames = <?= json_encode($council_names, JSON_UNESCAPED_UNICODE) ?>;
  var tabsConfig = <?= json_encode(array_map(function ($t) use ($is_all) {
      return [
          'key' => $t['key'],
          'summary_unit' => $t['summary_unit'],
          'no_results' => $t['no_results'],
          'facets' => array_values(array_filter($t['facets'], function ($f) use ($is_all) {
              return empty($f['only_all']) || $is_all;
          })),
      ];
  }, $search_tabs), JSON_UNESCAPED_UNICODE) ?>;
  var pageSize = 10;
  var initialTab = <?= json_encode($initial_tab) ?>;
  var initialQ = <?= json_encode($initial_q) ?>;

  // 搜尋時把 tab/q 帶到網址上（history.replaceState，不留一堆分頁紀錄），
  // 這樣分享網址可以還原搜尋結果；只在真的執行搜尋時更新，切分頁本身不動網址
  function syncUrl(tabKey, q) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabKey);
    if (q) {
      url.searchParams.set('q', q);
    } else {
      url.searchParams.delete('q');
    }
    history.replaceState(null, '', url.toString());
  }

  function fp(name) { return encodeURIComponent(name); }
  function fetchJson(url) { return fetch(url).then(function (r) { return r.json(); }); }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  // ES highlight 片段含 <em>...</em>，其餘文字要跳脫避免把內容當 HTML 注入
  function safeHighlight(raw) {
    return raw.split(/(<em>|<\/em>)/).map(function (part) {
      if (part === '<em>' || part === '</em>') return part;
      return escapeHtml(part);
    }).join('');
  }

  function highlightOr(item, field, fallback) {
    var arr = item[field + ':highlight'];
    if (arr && arr.length) return arr.map(safeHighlight).join(' … ');
    return fallback != null ? escapeHtml(fallback) : '<span class="text-muted">（無摘要）</span>';
  }

  function councilLabel(code) { return councilNames[code] || code; }

  function facetFormatLabel(facetField, value) {
    if (facetField === '議會代碼') return councilLabel(value);
    if (facetField === '年') return value + ' 年';
    if (facetField === '當選') return (value === 'true' || value === true) ? '✅ 當選' : '未當選';
    return value;
  }

  function renderFacet(containerId, buckets, activeValue, keyField, onPick, formatLabel) {
    var el = document.getElementById(containerId);
    if (!el) return;
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

  // ── 共用搜尋分頁 factory：狀態、查詢組裝、facet/分頁渲染都共用，
  //    只有「打哪個 API／限定哪些欄位搜尋／怎麼畫一張結果卡片」是各分頁自己的邏輯 ──
  function createSearchTab(cfg) {
    var state = { q: cfg.initialQ || '', page: 1 };
    cfg.facets.forEach(function (f) { state[f.field] = null; });

    function buildQueryParts(overrides) {
      var s = Object.assign({}, state, overrides || {});
      var parts = [];
      if (s.q) {
        parts.push('q=' + encodeURIComponent(s.q));
        if (cfg.queryFields) {
          cfg.queryFields.forEach(function (f) { parts.push('query_fields=' + fp(f)); });
        }
      }
      cfg.facets.forEach(function (f) {
        var v = s[f.field];
        if (v != null) parts.push(fp(f.field) + '=' + encodeURIComponent(v));
      });
      return parts;
    }

    function renderResults(data) {
      var summary = document.getElementById(cfg.key + '-summary');
      var results = document.getElementById(cfg.key + '-results');
      summary.textContent = '共找到 ' + data.total + ' ' + cfg.summaryUnit;
      var list = data[cfg.resultsKey] || [];
      results.innerHTML = '';
      if (!list.length) {
        results.innerHTML = '<div class="alert alert-light border">' + escapeHtml(cfg.noResults) + '</div>';
        document.getElementById(cfg.key + '-pagination').innerHTML = '';
        return;
      }
      list.forEach(function (item) {
        var card = document.createElement('div');
        card.className = 'card shadow-sm mb-2';
        var body = document.createElement('div');
        body.className = 'card-body py-2';
        body.innerHTML = cfg.renderCard(item);
        card.appendChild(body);
        results.appendChild(card);
      });
      renderPagination(cfg.key + '-pagination', state, data.total, run);
    }

    function run() {
      if (!state.q) {
        document.getElementById(cfg.key + '-summary').textContent = '';
        document.getElementById(cfg.key + '-results').innerHTML = '';
        document.getElementById(cfg.key + '-pagination').innerHTML = '';
        return;
      }

      var resultParts = buildQueryParts().concat(['limit=' + pageSize, 'page=' + state.page]);
      fetchJson(cfg.apiBase + '?' + resultParts.join('&')).then(renderResults);

      cfg.facets.forEach(function (f, i) {
        var overrides = {}; overrides[f.field] = null;
        var facetParts = buildQueryParts(overrides).concat(['limit=0', 'agg=' + fp(f.field)]);
        fetchJson(cfg.apiBase + '?' + facetParts.join('&')).then(function (d) {
          var buckets = (d.aggs && d.aggs[0] && d.aggs[0].buckets) || [];
          if (f.sort === 'year_desc') {
            buckets = buckets.slice().sort(function (a, b) { return b[f.field] - a[f.field]; });
          }
          renderFacet(cfg.key + '-facet-' + i, buckets, state[f.field], f.field, function (v) {
            state[f.field] = v; state.page = 1; run();
          }, function (v) { return facetFormatLabel(f.field, v); });
        });
      });
    }

    document.getElementById(cfg.key + '-search-btn').addEventListener('click', function () {
      state.q = document.getElementById(cfg.key + '-q').value.trim();
      cfg.facets.forEach(function (f) { state[f.field] = null; });
      state.page = 1;
      syncUrl(cfg.key, state.q);
      run();
    });
    document.getElementById(cfg.key + '-q').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') document.getElementById(cfg.key + '-search-btn').click();
    });

    if (state.q) run();
  }

  // ── 各分頁自己的卡片渲染 + API 設定 ──────────────────────────────────────

  function renderNameCard(c) {
    var councilName = councilLabel(c['議會代碼']);
    var href = (isAll ? ('https://' + c['議會代碼'] + postfix) : '') + '/info/councilor/' + encodeURIComponent(c['人物代碼'] || '');
    var html = '<div class="d-flex justify-content-between">';
    html += '<div>';
    if (c['人物代碼']) {
      html += '<a href="' + href + '" target="_blank" class="fw-semibold text-decoration-none">' + escapeHtml(c['姓名']) + '</a>';
    } else {
      html += '<span class="fw-semibold">' + escapeHtml(c['姓名']) + '</span>';
    }
    html += '<div class="small text-body-secondary">' + councilName + ' ・ 第' + c['屆次'] + '屆' + (c['黨籍'] ? ' ・ ' + escapeHtml(c['黨籍']) : '') + '</div>';
    html += '</div>';
    if (c['職稱'] && c['職稱'] !== '議員') {
      html += '<span class="badge bg-info text-dark align-self-start">' + escapeHtml(c['職稱']) + '</span>';
    }
    html += '</div>';
    return html;
  }

  function renderTranscriptCard(t) {
    var councilName = councilLabel(t['議會代碼']);
    var href = (isAll ? ('https://' + t['議會代碼'] + postfix) : '') + '/info/' + t['屆'] + '/transcript/' + encodeURIComponent(t['代碼']);
    var html = '<div class="small text-body-secondary">' + councilName + ' ・ 第' + t['屆'] + '屆' + (t['年'] ? ' ・ ' + t['年'] + ' 年' : '') + '</div>';
    html += '<div class="small mt-1">' + highlightOr(t, '內容', null) + '</div>';
    html += '<a href="' + href + '" target="_blank" class="small">查看完整逐字稿 →</a>';
    return html;
  }

  function renderPlatformCard(c) {
    var councilName = councilLabel(c['議會代碼']);
    var candHref = (isAll ? ('https://' + c['議會代碼'] + postfix) : '') + '/info/candidate/' + encodeURIComponent(c['人物代碼'] || '');
    var html = '<div class="d-flex justify-content-between align-items-start">';
    html += '<div class="small text-body-secondary">' + councilName + (c['年份'] ? ' ・ ' + c['年份'] + ' 年' : '') + ' ・ ';
    if (c['人物代碼']) {
      html += '<a href="' + candHref + '" target="_blank">' + escapeHtml(c['姓名']) + '</a>';
    } else {
      html += escapeHtml(c['姓名']);
    }
    html += '</div>';
    html += c['當選'] ? '<span class="badge bg-success">當選</span>' : '<span class="badge bg-secondary">落選</span>';
    html += '</div>';
    html += '<div class="small mt-1">' + highlightOr(c, '政見', c['政見']) + '</div>';
    return html;
  }

  function fieldBlock(label, value) {
    if (!value) return '';
    return '<div class="small mt-1"><span class="text-body-secondary">' + escapeHtml(label) + '：</span>' + value + '</div>';
  }

  function renderBillCard(b) {
    var councilName = councilLabel(b['議會代碼']);
    var href = (isAll ? ('https://' + b['議會代碼'] + postfix) : '') + '/info/' + (b['屆'] || 0) + '/bill/' + encodeURIComponent(b['代碼']);
    var html = '<div class="small text-body-secondary">' + councilName + (b['屆'] ? ' ・ 第' + b['屆'] + '屆' : '') + (b['案號'] ? ' ・ ' + escapeHtml(b['案號']) : '');
    if (b['類別']) html += '<span class="badge bg-secondary ms-1">' + escapeHtml(b['類別']) + '</span>';
    html += '</div>';
    html += '<div class="small mt-1">' + highlightOr(b, '案由', b['案由']) + '</div>';
    html += fieldBlock('審查意見', escapeHtml(b['審查意見'] || ''));
    html += fieldBlock('議決', escapeHtml(b['議決'] || ''));
    html += '<a href="' + href + '" target="_blank" class="small">查看完整議案 →</a>';
    return html;
  }

  var cardRenderers = {
    name: renderNameCard,
    transcript: renderTranscriptCard,
    platform: renderPlatformCard,
    bill: renderBillCard,
  };
  var resultsKeys = { name: 'councilors', transcript: 'transcripts', platform: 'candidates', bill: 'bills' };
  var queryFieldsByTab = { name: ['姓名'], platform: ['政見'] };

  tabsConfig.forEach(function (t) {
    createSearchTab({
      key: t.key,
      apiBase: <?= json_encode(array_combine(array_column($search_tabs, 'key'), array_map(function ($t) {
          $type_map = ['name' => 'councilor', 'transcript' => 'transcript', 'platform' => 'candidate', 'bill' => 'bill'];
          return TypeHelper::getApiUrl($type_map[$t['key']]);
      }, $search_tabs))) ?>[t.key],
      resultsKey: resultsKeys[t.key],
      summaryUnit: t.summary_unit,
      noResults: t.no_results,
      facets: t.facets,
      queryFields: queryFieldsByTab[t.key],
      renderCard: cardRenderers[t.key],
      initialQ: t.key === initialTab ? initialQ : '',
    });
  });
})();
</script>
