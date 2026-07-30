<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4">找不到議員資料</div>
<?php return; endif;

$total = $this->bill_total ?? 0;
$groups = $this->bill_groups ?? [];
?>

<div class="alert alert-light border small">
  「提案人」欄位直接來自議案資料的原始記錄（不是關鍵字猜測），但目前議案只涵蓋
  少數議會、且沒有精確的會期/場次關聯，「屆」是從來源檔名解析出來的推測值。
</div>

<?php if (!$total): ?>
<div class="alert alert-light border">找不到這位議員的提案記錄（目前議案資料只涵蓋少數議會）</div>
<?php else: ?>

<p class="small text-body-secondary">共找到 <?= $total ?> 筆議案，來自 <?= count($groups) ?> 個屆次（依屆次新到舊排列）</p>

<?php foreach ($groups as $group): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <strong class="small"><?= $group->{'屆'} ? '第 ' . htmlspecialchars($group->{'屆'}) . ' 屆' : '屆次不詳' ?></strong>
    <span class="text-body-secondary small"><?= count($group->items) ?> 筆</span>
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

<?php endif; ?>
