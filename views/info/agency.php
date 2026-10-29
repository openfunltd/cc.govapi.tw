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
  <span class="small text-body-secondary">（本屆最後一次在議會答詢：<?= htmlspecialchars($a->_head->{'最後'} ?? '') ?>，從會議逐字稿彙整，不是官方人事資料）</span></p>
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

<h3 class="h5 fw-semibold mt-4 mb-2">標案</h3>
<?php if (!$unit_id): ?>
<p class="small text-body-secondary">這個機關沒有可以對應到政府電子採購網的機關代碼，暫時無法列出標案。</p>
<?php else: ?>
<?php if ($dept): ?>
<div class="alert alert-light border small">
  <?= htmlspecialchars($a->{'名稱'}) ?>是<?= htmlspecialchars($parent->{'名稱'} ?? '') ?>的內部單位，在政府電子採購網沒有自己的機關代碼，標案都以<?= htmlspecialchars($parent->{'名稱'} ?? '') ?>名義公告。
  下面列出<?= htmlspecialchars($parent->{'名稱'} ?? '') ?>的全部標案，承辦單位是<?= htmlspecialchars($a->{'名稱'}) ?>的標案會特別標示。
</div>
<?php endif; ?>
<?php
$t = $this->tenders;
$base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'});
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
          $det = $r->detail ?? new stdClass;
          $dept_name = $det->{'機關資料:單位名稱'} ?? '';
          $amount = ($det->{'決標資料:總決標金額'} ?? null) ?: ($det->{'採購資料:預算金額'} ?? '');
          $date = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string) ($r->date ?? ''));
          $mine = $dept && $dept_name !== '' && strpos($dept_name, $dept) === 0;
        ?>
        <tr<?= $mine ? ' class="table-warning"' : '' ?>>
          <td class="text-nowrap"><?= htmlspecialchars($date) ?></td>
          <td><a href="<?= htmlspecialchars('https://pcc-api.openfun.app' . ($r->url ?? '')) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r->brief->title ?? '') ?></a></td>
          <td class="text-nowrap"><?= htmlspecialchars($r->brief->type ?? '') ?></td>
          <td><?php if ($t['children']): ?><?= htmlspecialchars($r->unit_name ?? '') ?><?= $dept_name !== '' ? '<br>' : '' ?><?php endif; ?><span class="text-body-secondary"><?= htmlspecialchars($dept_name) ?></span></td>
          <td class="text-end text-nowrap"><?= htmlspecialchars($amount) ?></td>
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
  <p class="small text-body-secondary mt-2">
    資料來源：政府電子採購網，經歐噴<a href="https://pcc-api.openfun.app/" target="_blank" rel="noopener">政府採購公告 API</a>整理；點標案名稱看公告全文。
    金額為決標公告的總決標金額，其他公告為預算金額。
  </p>
</div>
<?php endif; ?>
