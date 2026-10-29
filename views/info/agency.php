<?php
$a = $this->agency ?? null;
$term_no = (int) $this->term_no;
$agency_url = fn($x) => '/info/' . $term_no . '/agencies/' . rawurlencode($x->{'代碼'});
$head_text = fn($rec) => $rec ? htmlspecialchars(($rec->{'職稱'} ?? '') . ' ' . ($rec->{'姓名'} ?? '')) : '';
?>
<?php if (!$a): ?>
<div class="alert alert-light border">找不到這個機關的資料。<a href="/info/<?= $term_no ?>/agencies">回行政體系</a></div>
<?php return; endif; ?>
<?php
$parent = $this->agency_parent ?? null;
$children = $this->agency_children ?? [];
$unit_id = $this->pcc_unit_id ?? null;
$dept = $this->pcc_dept_name ?? null;
?>
<nav class="small mb-2">
  <a href="/info/<?= $term_no ?>/agencies">行政體系</a>
  <?php if ($parent): ?> › <a href="<?= $agency_url($parent) ?>"><?= htmlspecialchars($parent->{'名稱'}) ?></a><?php endif; ?>
  › <?= htmlspecialchars($a->{'名稱'}) ?>
</nav>

<h2 class="h4 fw-semibold mb-1"><?= htmlspecialchars($a->{'名稱'}) ?></h2>
<div class="small text-body-secondary mb-3">
  <?= htmlspecialchars(($a->{'全名'} ?? '') ?: '') ?>
  <?php if ($a->{'分類'} ?? null): ?><span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($a->{'分類'}) ?></span><?php endif; ?>
  <?php if ($a->{'機關代碼'} ?? null): ?><span class="ms-1">機關代碼 <?= htmlspecialchars($a->{'機關代碼'}) ?></span><?php endif; ?>
</div>

<?php if ($a->_head): ?>
<p class="mb-3">首長：<?= $head_text($a->_head) ?>
  <span class="small text-body-secondary">（最後答詢 <?= htmlspecialchars($a->_head->{'最後'} ?? '') ?>）</span></p>
<?php endif; ?>

<?php if ($children): ?>
<details class="mb-4">
  <summary>所屬機關 <?= count($children) ?> 個</summary>
  <ul class="mb-0 ps-3 mt-1">
    <?php foreach ($children as $c): ?>
    <li><a href="<?= $agency_url($c) ?>"><?= htmlspecialchars($c->{'名稱'}) ?></a><?= $c->_head ? ' <span class="small text-body-secondary">（' . $head_text($c->_head) . '）</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<?php $base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'}); ?>
<ul class="nav nav-pills mt-4 mb-3">
  <li class="nav-item"><a class="nav-link py-1<?= ($this->agency_page ?? '') === 'overview' ? ' active' : '' ?>" href="<?= htmlspecialchars($base) ?>">標案</a></li>
  <li class="nav-item"><a class="nav-link py-1<?= ($this->agency_page ?? '') === 'news' ? ' active' : '' ?>" href="<?= htmlspecialchars($base . '/news') ?>">新聞公告</a></li>
  <li class="nav-item"><a class="nav-link py-1<?= ($this->agency_page ?? '') === 'speeches' ? ' active' : '' ?>" href="<?= htmlspecialchars($base . '/speeches') ?>">議會發言記錄</a></li>
</ul>

<?php if (($this->agency_page ?? '') === 'speeches'): ?>
<?= $this->partial('info/agency_speeches', $this) ?>
<?php return; endif; ?>
<?php if (($this->agency_page ?? '') === 'news'): ?>
<?= $this->partial('info/agency_news', $this) ?>
<?php return; endif; ?>

<?php if (!$unit_id): ?>
<p class="small text-body-secondary">查無標案資料</p>
<?php else: ?>
<?php
$t = $this->tenders;
// 篩選、換頁都是一般連結（標案由後端呼叫 pcc-api 取得），只改動指定的參數
$tender_url = function ($override) use ($t, $base, $dept) {
    $q = array_merge(['type' => $t['type'], 'children' => $t['children'] ? '1' : '0', 'page' => 1], $override);
    if ($q['type'] === '') unset($q['type']);
    if ($dept) unset($q['children']);
    if ((int) $q['page'] === 1) unset($q['page']);
    return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : '') . '#tenders');
};
?>
<div id="tenders">
<?php if ($t['years']): ?>
<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <table class="table table-sm small mb-0">
      <thead><tr><th>年度</th><th class="text-end">標案數</th><th class="text-end">決標件數</th><th class="text-end">決標金額</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($t['years'], 0, 10) as $y): ?>
        <tr><td><?= (int) $y->year ?></td><td class="text-end"><?= number_format($y->tenders) ?></td><td class="text-end"><?= number_format($y->awards) ?></td><td class="text-end"><?= number_format($y->award_amount) ?> 元</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($t['winners']): ?>
  <div class="col-12 col-lg-6">
    <table class="table table-sm small mb-0">
      <thead><tr><th>得標廠商</th><th class="text-end">決標件數</th><th class="text-end">決標金額</th></tr></thead>
      <tbody>
        <?php foreach ($t['winners'] as $w): ?>
        <tr><td><a href="https://openfunltd.github.io/pcc-viewer/searchbycompany.html?query=<?= rawurlencode($w->company_name ?? '') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($w->company_name ?? '') ?></a></td><td class="text-end"><?= number_format($w->awards) ?></td><td class="text-end"><?= number_format($w->amount) ?> 元</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
    <div class="btn-group btn-group-sm" role="group">
      <?php foreach (InfoController::TENDER_TYPES as $type => $label): ?>
      <a class="btn btn-outline-secondary<?= $t['type'] === $type ? ' active' : '' ?>" href="<?= $tender_url(['type' => $type]) ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$dept): ?>
    <a class="btn btn-sm btn-outline-secondary<?= $t['children'] ? ' active' : '' ?>" href="<?= $tender_url(['children' => $t['children'] ? '0' : '1']) ?>"><?= $t['children'] ? '☑' : '☐' ?> 含所屬機關</a>
    <?php endif; ?>
    <span class="text-body-secondary ms-auto"><?= $t['ok'] ? '共 ' . number_format($t['total']) . ' 筆公告' : '' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm small align-middle">
      <thead><tr><th class="text-nowrap">日期</th><th>標案名稱</th><th class="text-nowrap">公告類型</th><th class="text-nowrap">機關／單位</th><th class="text-end text-nowrap">金額</th></tr></thead>
      <tbody>
        <?php if (!$t['ok']): ?>
        <tr><td colspan="5" class="text-danger">標案資料讀取失敗，請稍後再試</td></tr>
        <?php elseif (!$t['records']): ?>
        <tr><td colspan="5" class="text-body-secondary">查無標案公告</td></tr>
        <?php endif; ?>
        <?php foreach ($t['records'] as $r): ?>
        <?php
          $amount = $r->award_amount ?? $r->budget_amount ?? null;
          $date = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string) ($r->date ?? ''));
        ?>
        <tr>
          <td class="text-nowrap"><?= htmlspecialchars($date) ?></td>
          <td><a href="https://openfunltd.github.io/pcc-viewer/tender.html?<?= htmlspecialchars(http_build_query(['unit_id' => $r->unit_id ?? '', 'job_number' => $r->job_number ?? ''])) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r->brief->title ?? '') ?></a></td>
          <td class="text-nowrap"><?= htmlspecialchars($r->brief->type ?? '') ?></td>
          <td><?php if ($t['children']): ?><?= htmlspecialchars($r->unit_name ?? '') ?><?= ($r->dept_name ?? '') !== '' ? '<br>' : '' ?><?php endif; ?><span class="text-body-secondary"><?= htmlspecialchars($r->dept_name ?? '') ?></span></td>
          <td class="text-end text-nowrap"><?= $amount !== null ? number_format($amount) . ' 元' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($t['total_page'] > 1): ?>
  <div class="d-flex align-items-center gap-2 small">
    <a class="btn btn-sm btn-outline-secondary<?= $t['page'] <= 1 ? ' disabled' : '' ?>" href="<?= $tender_url(['page' => max(1, $t['page'] - 1)]) ?>">上一頁</a>
    <span>第 <?= number_format($t['page']) ?> / <?= number_format($t['total_page']) ?> 頁</span>
    <a class="btn btn-sm btn-outline-secondary<?= $t['page'] >= $t['total_page'] ? ' disabled' : '' ?>" href="<?= $tender_url(['page' => $t['page'] + 1]) ?>">下一頁</a>
  </div>
  <?php endif; ?>
  <p class="small text-body-secondary mt-2">資料來源：<a href="https://pcc-api.openfun.app/" target="_blank" rel="noopener">政府採購公告 API</a></p>
</div>
<?php endif; ?>
