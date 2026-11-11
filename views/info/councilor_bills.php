<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4"><?= htmlspecialchars(CouncilHelper::t('找不到議員資料')) ?></div>
<?php return; endif;

$total = $this->bill_total ?? 0;
$groups = $this->bill_groups ?? [];
$terms = $this->bill_terms ?? [];
$term = $this->bill_term ?? '';
$all_total = $this->bill_all_total ?? $total;
$page = $this->bill_page ?? 1;
$total_page = $this->bill_total_page ?? 0;
$person_code = $records[0]->{'人物代碼'} ?? '';
$bill_url = function ($term_no, $page_no) use ($person_code) {
    $q = [];
    if ($term_no !== '') $q['term'] = (int)$term_no;
    if ($page_no > 1) $q['page'] = $page_no;
    return '/info/councilor/' . urlencode($person_code) . '/bills' . ($q ? '?' . http_build_query($q) : '');
};
?>

<div class="alert alert-light border small">
  「提案人」欄位直接來自議案資料的原始記錄（不是關鍵字猜測），但目前議案只涵蓋
  少數議會、且沒有精確的會期/場次關聯，「屆」是從來源檔名解析出來的推測值。
</div>

<?php if (!$total): ?>
<div class="alert alert-light border"><?= htmlspecialchars(CouncilHelper::t('找不到這位議員的提案記錄（目前議案資料只涵蓋少數議會）')) ?></div>
<?php else: ?>

<p class="small text-body-secondary">共找到 <?= number_format($all_total) ?> 筆議案（含聯合提案），來自 <?= count($terms) ?> 個屆次（依屆次新到舊排列）</p>

<?php if (count($terms) > 1): ?>
<div class="d-flex flex-wrap gap-1 mb-3">
  <a href="<?= htmlspecialchars($bill_url('', 1)) ?>" class="badge <?= $term === '' ? 'bg-primary' : 'bg-light text-dark border' ?>">全部 <?= number_format($all_total) ?></a>
  <?php foreach ($terms as $t => $count): ?>
  <a href="<?= htmlspecialchars($bill_url((string)$t, 1)) ?>" class="badge <?= $term === (string)$t ? 'bg-primary' : 'bg-light text-dark border' ?>">第 <?= (int)$t ?> 屆 <?= number_format($count) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <strong class="small"><?= $group->{'屆'} ? '第 ' . htmlspecialchars($group->{'屆'}) . ' 屆' : '屆次不詳' ?></strong>
    <span class="text-body-secondary small"><?= number_format($terms[(string)$group->{'屆'}] ?? count($group->items)) ?> 筆</span>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($group->items as $b): ?>
    <?php $href = '/info/' . (int)($group->{'屆'} ?? 0) . '/bill/' . urlencode($b->{'代碼'}); ?>
    <a href="<?= htmlspecialchars($href) ?>" class="list-group-item list-group-item-action">
      <div class="small fw-semibold">
        <?= htmlspecialchars($b->{'案號'} ?? '') ?>
        <?php if ($b->{'類別'} ?? null): ?>
        <span class="badge bg-secondary"><?= htmlspecialchars($b->{'類別'}) ?></span>
        <?php endif; ?>
      </div>
      <div class="small text-body-secondary"><?= htmlspecialchars($b->{'案由'} ?? '') ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if ($total_page > 1): ?>
<div class="d-flex align-items-center gap-2 small">
  <a class="btn btn-sm btn-outline-secondary<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars($bill_url($term, max(1, $page - 1))) ?>">上一頁</a>
  <span>第 <?= number_format($page) ?> / <?= number_format($total_page) ?> 頁</span>
  <a class="btn btn-sm btn-outline-secondary<?= $page >= $total_page ? ' disabled' : '' ?>" href="<?= htmlspecialchars($bill_url($term, $page + 1)) ?>">下一頁</a>
</div>
<?php endif; ?>

<?php endif; ?>
