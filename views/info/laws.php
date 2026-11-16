<?php
$base = $this->law_base;
$dataset = InfoController::LAW_DATASETS[$this->law_dataset];
$central = $this->law_dataset === 'central';
// 中央法規的函釋類沒有修正日期，改顯示公發布或發文日期
$law_date = fn($law) => $law->LawModifiedDate ?? ($law->LawPublishedDate ?? ($law->LawIssuedDate ?? ''));
// 關鍵字標示：先跳脫再把關鍵字包 <mark>
$mark = function ($text, $keyword) {
    $html = htmlspecialchars($text);
    if ($keyword === '') return $html;
    return preg_replace('/' . preg_quote(htmlspecialchars($keyword), '/') . '/iu', '<mark>$0</mark>', $html);
};

// 條文來源是固定寬度斷行，句子中間的換行接回去；句末標點後、或下一行是款目編號的換行保留
$unwrap = fn($text) => preg_replace('/(?<![。：:；;！？!?」）)])\n(?![一二三四五六七八九十]+[、　 ]|[（(][一二三四五六七八九十\d]+[）)]|\d+[\.、 ]|第[一二三四五六七八九十百\d]+[條點])/u', '', $text);

if ($this->law ?? null):
    // 單一法規
    $law = $this->law;
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $current_id = $law->LawHistoryId[0] ?? $law->LawId;
    $law_url = fn($id) => htmlspecialchars($base . '/' . rawurlencode($id) . ($keyword !== '' ? '?' . http_build_query(['q' => $keyword]) : ''));
?>
<p class="small mb-2"><a href="<?= htmlspecialchars($base) ?>">← 法規列表</a></p>
<h2 class="h5 fw-semibold mb-2"><?= htmlspecialchars($law->LawName) ?></h2>
<div class="d-flex flex-wrap gap-1 mb-2">
  <?php if ($law->LawLevel ?? null): ?><span class="badge bg-secondary"><?= htmlspecialchars($law->LawLevel) ?></span><?php endif; ?>
  <?php if ($law->LawAbandoned ?? false): ?>
  <span class="badge bg-danger">已廢止<?= ($law->LawAbandonedDate ?? null) ? ' ' . htmlspecialchars($law->LawAbandonedDate) : '' ?></span>
  <?php endif; ?>
  <?php if (!($law->LawIsCurrent ?? true)): ?>
  <span class="badge bg-warning text-dark">歷史版本</span>
  <?php endif; ?>
</div>
<?php if (!($law->LawIsCurrent ?? true)): ?>
<div class="alert alert-warning small py-2">這是<?= $law_date($law) !== '' ? ' ' . htmlspecialchars($law_date($law)) . ' 的' : '歷史' ?>版本，<a href="<?= $law_url($current_id) ?>">查看現行版本</a></div>
<?php endif; ?>
<table class="table table-sm small mb-3" style="max-width: 40rem">
  <tbody>
    <?php if ($central): ?>
    <?php if ($law->LawCategory ?? []): ?>
    <tr><th class="text-nowrap" style="width: 7rem">類別</th><td><?= htmlspecialchars($law->LawCategory[0]) ?></td></tr>
    <?php endif; ?>
    <?php foreach (['LawIssuer' => '發布機關', 'LawPublishedDate' => '公發布日期', 'LawIssuedDate' => '發文日期', 'LawModifiedDate' => '修正日期', 'LawEffectiveDate' => '生效日期', 'LawSignedDate' => '簽署日期', 'LawDateNote' => '日期說明'] as $field => $label): ?>
    <?php if ($law->$field ?? null): ?>
    <tr><th class="text-nowrap" style="width: 7rem"><?= $label ?></th><td><?= htmlspecialchars($law->$field) ?></td></tr>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php else: ?>
    <?php if (count($law->LawCategory ?? []) > 1): ?>
    <tr><th class="text-nowrap" style="width: 7rem">類別</th><td><?= htmlspecialchars(implode(' > ', $law->LawCategory)) ?></td></tr>
    <?php endif; ?>
    <tr><th class="text-nowrap" style="width: 7rem">公發布日期</th><td><?= htmlspecialchars($law->LawEffectiveDate ?? '') ?></td></tr>
    <tr><th class="text-nowrap">修正日期</th><td><?= htmlspecialchars($law->LawModifiedDate ?? '') ?></td></tr>
    <?php endif; ?>
    <tr><th class="text-nowrap">原始網頁</th><td><a href="<?= htmlspecialchars($law->LawURL ?? '') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(parse_url($law->LawURL ?? '', PHP_URL_HOST) ?: '') ?></a></td></tr>
    <?php if ($this->law_versions): ?>
    <tr><th class="text-nowrap">版本</th><td>
      <select class="form-select form-select-sm d-inline-block w-auto" onchange="location.href = this.value">
        <?php foreach ($this->law_versions as $v): ?>
        <option value="<?= $law_url($v->id) ?>"<?= $v->id === $law->LawId ? ' selected' : '' ?>><?= htmlspecialchars($v->date ?: '日期不詳') ?><?= $v->current ? '（現行）' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($law->LawSummary ?? null): ?>
<h3 class="h6 fw-semibold">要旨</h3>
<p class="small mb-3" style="white-space: pre-wrap"><?= htmlspecialchars($law->LawSummary) ?></p>
<?php endif; ?>

<?php if ($law->LawNotes ?? []): ?>
<ul class="small text-body-secondary mb-3">
  <?php foreach ($law->LawNotes as $n): ?>
  <li><?= htmlspecialchars($n) ?></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($law->LawAttachments ?? []): ?>
<h3 class="h6 fw-semibold">附件</h3>
<ul class="small mb-3">
  <?php foreach ($law->LawAttachments as $f): ?>
  <li><a href="<?= htmlspecialchars($f->FileURL ?? '') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($f->FileName ?? '') ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<h3 class="h6 fw-semibold">條文</h3>
<?php if (!($law->LawArticles ?? [])): ?>
<p class="small text-body-secondary">無條文</p>
<?php else: ?>
<div class="mb-3">
  <?php foreach ($law->LawArticles as $a): ?>
  <div class="d-flex gap-3 border-bottom py-2 small">
    <?php if (($a->{'條號'} ?? '') !== ''): ?>
    <div class="fw-semibold text-nowrap" style="min-width: 5rem"><?= htmlspecialchars($a->{'條號'}) ?></div>
    <?php endif; ?>
    <div style="white-space: pre-wrap"><?= $mark($unwrap($a->{'內容'} ?? ''), $keyword) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($law->LawHistories ?? []): ?>
<h3 class="h6 fw-semibold">沿革</h3>
<ol class="small mb-3">
  <?php foreach (array_reverse($law->LawHistories) as $h): ?>
  <li><?= htmlspecialchars($h) ?></li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php elseif ($this->law_id ?? null): ?>
<p class="small mb-2"><a href="<?= htmlspecialchars($base) ?>">← 法規列表</a></p>
<div class="alert alert-light border">找不到這部法規</div>

<?php else:
    // 法規列表
    $l = $this->laws;
    $list_url = function ($override) use ($l, $base) {
        $q = array_merge(['q' => $l['keyword'], 'scope' => $l['scope'], 'level' => $l['level'], 'status' => $l['status'], 'page' => 1], $override);
        if ($q['q'] === '' || $q['scope'] === 'all') unset($q['scope']);
        if ($q['q'] === '') unset($q['q']);
        if ($q['level'] === '') unset($q['level']);
        if ($q['status'] === array_key_first($l['statuses'])) unset($q['status']);
        if ((int) $q['page'] === 1) unset($q['page']);
        return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''));
    };
    // 搜條文時，列出第一條含關鍵字的條文片段
    $snippet = function ($law) use ($l) {
        if ($l['keyword'] === '' || $l['scope'] === 'name') return '';
        foreach ($law->LawArticles ?? [] as $a) {
            $text = preg_replace('/\s+/u', '', $a->{'內容'} ?? '');
            $pos = mb_stripos($text, $l['keyword']);
            if ($pos === false) continue;
            $start = max(0, $pos - 30);
            return ($start > 0 ? '…' : '') . mb_substr($text, $start, 90) . (mb_strlen($text) > $start + 90 ? '…' : '');
        }
        return '';
    };
?>
<form method="get" action="<?= htmlspecialchars($base) ?>" class="mb-2" style="max-width: 40rem">
  <div class="input-group input-group-sm">
    <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($l['keyword']) ?>" placeholder="搜尋<?= htmlspecialchars($l['label']) ?>法規">
    <select name="scope" class="form-select" style="max-width: 9rem">
      <option value="all">名稱與條文</option>
      <option value="name"<?= $l['scope'] === 'name' ? ' selected' : '' ?>>只搜名稱</option>
    </select>
    <?php if ($l['level'] !== ''): ?><input type="hidden" name="level" value="<?= htmlspecialchars($l['level']) ?>"><?php endif; ?>
    <?php if ($l['status'] !== array_key_first($l['statuses'])): ?><input type="hidden" name="status" value="<?= htmlspecialchars($l['status']) ?>"><?php endif; ?>
    <button class="btn btn-outline-secondary" type="submit">搜尋</button>
    <?php if ($l['keyword'] !== ''): ?><a class="btn btn-outline-secondary" href="<?= $list_url(['q' => '']) ?>">清除</a><?php endif; ?>
  </div>
</form>
<div class="d-flex flex-wrap align-items-center gap-1 mb-2 small">
  <a class="btn btn-sm btn-outline-secondary<?= $l['level'] === '' ? ' active' : '' ?>" href="<?= $list_url(['level' => '']) ?>">全部位階</a>
  <?php foreach ($l['levels'] as [$name, $count]): ?>
  <a class="btn btn-sm btn-outline-secondary<?= $l['level'] === $name ? ' active' : '' ?>" href="<?= $list_url(['level' => $name]) ?>"><?= htmlspecialchars($name) ?> <span class="text-body-secondary"><?= number_format($count) ?></span></a>
  <?php endforeach; ?>
  <div class="btn-group btn-group-sm ms-auto">
    <?php foreach ($l['statuses'] as $key => $label): ?>
    <a class="btn btn-outline-secondary<?= $l['status'] === $key ? ' active' : '' ?>" href="<?= $list_url(['status' => $key, 'level' => '']) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php if (!$l['ok']): ?>
<div class="alert alert-light border">法規讀取失敗，請稍後再試</div>
<?php elseif (!$l['items']): ?>
<div class="alert alert-light border">查無法規</div>
<?php else: ?>
<p class="small text-body-secondary mb-1">共 <?= number_format($l['total']) ?> 部</p>
<div class="table-responsive">
  <table class="table table-sm small align-middle">
    <thead><tr><th>法規名稱</th><th class="text-nowrap">位階</th><th class="text-nowrap">修正日期</th></tr></thead>
    <tbody>
      <?php foreach ($l['items'] as $law): ?>
      <tr>
        <td>
          <a href="<?= htmlspecialchars($base . '/' . rawurlencode($law->LawId) . ($l['keyword'] !== '' ? '?' . http_build_query(['q' => $l['keyword']]) : '')) ?>"><?= $mark($law->LawName, $l['keyword']) ?></a>
          <?php if ($law->LawAbandoned ?? false): ?><span class="badge bg-danger">已廢止</span><?php endif; ?>
          <?php if ($s = $snippet($law)): ?><div class="text-body-secondary"><?= $mark($s, $l['keyword']) ?></div><?php endif; ?>
        </td>
        <td class="text-nowrap"><?= htmlspecialchars($law->LawLevel ?? '') ?></td>
        <td class="text-nowrap"><?= htmlspecialchars($law_date($law)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($l['total_page'] > 1): ?>
<div class="d-flex align-items-center gap-2 small">
  <a class="btn btn-sm btn-outline-secondary<?= $l['page'] <= 1 ? ' disabled' : '' ?>" href="<?= $list_url(['page' => max(1, $l['page'] - 1)]) ?>">上一頁</a>
  <span>第 <?= number_format($l['page']) ?> / <?= number_format($l['total_page']) ?> 頁</span>
  <a class="btn btn-sm btn-outline-secondary<?= $l['page'] >= $l['total_page'] ? ' disabled' : '' ?>" href="<?= $list_url(['page' => $l['page'] + 1]) ?>">下一頁</a>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<p class="small text-body-secondary mt-2">資料來源：<a href="<?= htmlspecialchars($dataset['page']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($dataset['name']) ?></a></p>
