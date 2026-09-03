<?php
$a = $this->agenda_detail ?? null;

// 「參與議員結構」有人物代碼時連到議員個人頁，沒有就顯示純文字姓名（比照 bill.php
// 的 bill_person_links()）
function agenda_person_links($structured) {
    if (empty($structured)) {
        return null;
    }
    $parts = [];
    foreach ($structured as $p) {
        $name = htmlspecialchars($p->{'姓名'} ?? '');
        $person_code = $p->{'人物代碼'} ?? null;
        $parts[] = $person_code
            ? '<a href="/info/councilor/' . urlencode($person_code) . '">' . $name . '</a>'
            : $name;
    }
    return implode('、', $parts);
}
?>

<?php if ($a && ($a->{'場次代碼'} ?? null)): ?>
<nav aria-label="breadcrumb" class="mb-3">
  <a href="/info/<?= $this->term_no ?>/agendas/<?= urlencode($a->{'場次代碼'}) ?>" class="text-decoration-none small">&larr; 返回本場次議程清單</a>
</nav>
<?php endif; ?>

<?php if (!$a): ?>
<div class="alert alert-light border">找不到議程資料</div>
<?php else: ?>

<h1 class="h4 fw-semibold mb-1">
  <?= htmlspecialchars($a->{'議程類型'} ?? '') ?>
  <?php if ($a->{'委員會或名稱'} ?? null): ?>
  <span class="badge bg-secondary"><?= htmlspecialchars($a->{'委員會或名稱'}) ?></span>
  <?php endif; ?>
</h1>
<p class="text-body-secondary small mb-3">
  <?= htmlspecialchars($this->council_name ?? '') ?>
  <?php if ($a->{'時間資訊'} ?? null): ?>
  ・<?= htmlspecialchars($a->{'時間資訊'}) ?>
  <?php endif; ?>
  <?php if ($a->{'質詢對象機關'} ?? null): ?>
  ・質詢對象：<?= htmlspecialchars($a->{'質詢對象機關'}) ?>
  <?php endif; ?>
  <?php $people_html = agenda_person_links($a->{'參與議員結構'} ?? []); ?>
  <?php if ($people_html): ?>
  ・參與議員：<?= $people_html ?>
  <?php endif; ?>
</p>

<div id="agenda-sections" class="mb-3"></div>

<style>
/* 逐句發言：比照 SayIt 平台「發言者頭像＋內容」的呈現方式，額外把「民代」（議員）
   放左邊、「非民代」（機關／官員，頭像之後再補）放右邊，方便一眼區分發言者身分 */
.speech-row { display: flex; margin-bottom: 0.75rem; gap: 0.5rem; }
.speech-row.role-other { justify-content: flex-end; }
.speech-avatar {
  width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
  object-fit: cover; background-color: #adb5bd;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1rem; font-weight: 600;
}
.speech-bubble { max-width: 75%; background: #f1f3f5; border-radius: 0.75rem; padding: 0.5rem 0.75rem; }
.role-other .speech-bubble { background: #e7f1ff; }
</style>

<div id="speech-summary" class="small text-body-secondary mb-2"></div>
<div id="speech-list"></div>
<div id="speech-pagination" class="d-flex justify-content-center align-items-center gap-2 my-3"></div>

<?php if ($a->{'來源網址'} ?? null): ?>
<p class="text-body-secondary small">
  來源檔案：
  <a href="<?= htmlspecialchars($a->{'來源網址'}) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($a->{'來源檔案'} ?? '原始檔案') ?></a>
</p>
<?php endif; ?>

<script>
(function () {
  var agendaCode = <?= json_encode($a->{'代碼'}) ?>;
  var sections = <?= json_encode($a->{'小節清單'} ?? []) ?>;
  var pageSize = 30;
  var state = { page: 1 };
  var personCache = {};   // 對應代碼 => {人物代碼, 照片}（查過沒有對到就存 null）

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s == null ? '' : s;
    return div.innerHTML;
  }

  function fetchJson(url) {
    return fetch(url).then(function (r) { return r.json(); });
  }

  function goToPage(page) {
    state.page = page;
    run();
    window.scrollTo({ top: document.getElementById('speech-list').offsetTop - 80, behavior: 'smooth' });
  }

  function renderSections() {
    var el = document.getElementById('agenda-sections');
    if (!sections || !sections.length) {
      el.innerHTML = '';
      return;
    }
    var html = '<div class="small text-body-secondary mb-1">本議程小節：</div>';
    sections.forEach(function (s) {
      var page = Math.floor((s['起始順序'] || 0) / pageSize) + 1;
      html += '<span class="badge bg-light text-dark border me-1 mb-1" style="cursor:pointer" data-page="' + page + '">'
        + escapeHtml(s['名稱']) + '</span> ';
    });
    el.innerHTML = html;
    el.querySelectorAll('[data-page]').forEach(function (badge) {
      badge.addEventListener('click', function () { goToPage(parseInt(badge.dataset.page, 10)); });
    });
  }

  // 對應代碼類型=議員 的發言者批次查一次 councilor 換人物代碼，才能連到議員個人頁
  // （比照 resolveBillPeople() 的做法，一次查詢批次處理，不逐筆查避免 N+1）
  function resolvePeople(list) {
    var codes = [];
    list.forEach(function (s) {
      if (s['對應代碼類型'] === '議員' && s['對應代碼'] && !(s['對應代碼'] in personCache)) {
        codes.push(s['對應代碼']);
      }
    });
    if (!codes.length) {
      return Promise.resolve();
    }
    var qs = codes.map(function (c) { return encodeURIComponent('代碼') + '=' + encodeURIComponent(c); }).join('&');
    return fetchJson('/api/councilors?limit=' + codes.length + '&' + qs).then(function (d) {
      (d.councilors || []).forEach(function (c) {
        personCache[c['代碼']] = { personCode: c['人物代碼'] || null, photo: c['照片'] || null };
      });
      codes.forEach(function (c) {
        if (!(c in personCache)) personCache[c] = { personCode: null, photo: null };
      });
    });
  }

  function speakerHtml(s) {
    var name = escapeHtml(s['姓名'] || s['原始標記'] || '');
    var title = s['職稱'] ? '（' + escapeHtml(s['職稱']) + '）' : '';
    if (s['對應代碼類型'] === '議員') {
      var info = personCache[s['對應代碼']];
      if (info && info.personCode) {
        return '<a href="/info/councilor/' + encodeURIComponent(info.personCode) + '">' + name + '</a>' + title;
      }
    } else if (s['對應單位全名']) {
      return escapeHtml(s['對應單位全名']) + title;
    }
    return name + title;
  }

  // 民代（議員）：有照片就用照片，沒有就用姓名首字當佔位頭像；非民代（機關／官員）
  // 目前一律用機關佔位圖示，之後補上真實頭像時只要在這裡換掉即可
  function avatarHtml(s) {
    var name = s['姓名'] || s['原始標記'] || '';
    if (s['對應代碼類型'] === '議員') {
      var info = personCache[s['對應代碼']];
      if (info && info.photo) {
        return '<img class="speech-avatar" src="' + escapeHtml(info.photo) + '" alt="">';
      }
      return '<div class="speech-avatar">' + escapeHtml(name.substr(0, 1)) + '</div>';
    }
    return '<div class="speech-avatar">🏛</div>';
  }

  function renderResults(data) {
    var summary = document.getElementById('speech-summary');
    var results = document.getElementById('speech-list');
    var pagination = document.getElementById('speech-pagination');

    summary.textContent = '共 ' + data.total + ' 筆發言';
    var list = data.speeches || [];
    if (!list.length) {
      results.innerHTML = '<div class="alert alert-light border">本議程尚無逐句發言資料</div>';
      pagination.innerHTML = '';
      return;
    }

    resolvePeople(list).then(function () {
      var html = '';
      list.forEach(function (s) {
        var isRep = s['對應代碼類型'] === '議員';   // 民代放左邊、非民代放右邊
        var sourceLink = (s['來源網址'] && s['來源頁碼'])
          ? ' <a class="small" href="' + escapeHtml(s['來源網址']) + '#page=' + encodeURIComponent(s['來源頁碼']) + '" target="_blank" rel="noopener">（第' + escapeHtml(s['來源頁碼']) + '頁）</a>'
          : '';
        var bubble = '<div class="speech-bubble">'
          + '<div class="small fw-semibold">' + speakerHtml(s) + sourceLink + '</div>'
          + '<div class="small" style="white-space: pre-wrap;">' + escapeHtml(s['發言內容']) + '</div>'
          + '</div>';
        var avatar = avatarHtml(s);
        html += '<div class="speech-row ' + (isRep ? 'role-rep' : 'role-other') + '">'
          + (isRep ? (avatar + bubble) : (bubble + avatar))
          + '</div>';
      });
      results.innerHTML = html;

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
    });
  }

  function run() {
    var qs = encodeURIComponent('議程代碼') + '=' + encodeURIComponent(agendaCode)
      + '&limit=' + pageSize + '&page=' + state.page;
    fetchJson('/api/speeches?' + qs).then(renderResults);
  }

  renderSections();
  run();
})();
</script>

<?php endif; ?>
