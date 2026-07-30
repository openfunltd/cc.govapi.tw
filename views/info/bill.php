<?php $b = $this->bill_detail ?? null; ?>

<nav aria-label="breadcrumb" class="mb-3">
  <a href="/info/<?= $this->term_no ?>/bills" class="text-decoration-none small">&larr; 返回議案列表</a>
</nav>

<?php if (!$b): ?>
<div class="alert alert-light border">找不到議案資料</div>
<?php else: ?>

<h1 class="h4 fw-semibold mb-1">
  <?= htmlspecialchars($b->{'案號'} ?? '') ?>
  <?php if ($b->{'類別'} ?? null): ?>
  <span class="badge bg-secondary"><?= htmlspecialchars($b->{'類別'}) ?></span>
  <?php endif; ?>
</h1>
<p class="text-body-secondary small mb-4">
  <?= htmlspecialchars($this->council_name ?? '') ?>
  <?php if ($b->{'屆'} ?? null): ?>
  ・第 <?= htmlspecialchars($b->{'屆'}) ?> 屆
  <?php endif; ?>
  <?php if ($b->{'提案單位'} ?? null): ?>
  ・提案單位：<?= htmlspecialchars($b->{'提案單位'}) ?>
  <?php endif; ?>
  <?php if ($b->{'提案人'} ?? null): ?>
  ・提案人：<?= htmlspecialchars($b->{'提案人'}) ?>
  <?php endif; ?>
  <?php if ($b->{'連署人'} ?? null): ?>
  ・連署人：<?= htmlspecialchars($b->{'連署人'}) ?>
  <?php endif; ?>
  <?php if ($b->{'備註'} ?? null): ?>
  ・備註：<?= htmlspecialchars($b->{'備註'}) ?>
  <?php endif; ?>
</p>

<?php
$sections = [
    '案由'     => $b->{'案由'} ?? null,
    '說明'     => $b->{'說明'} ?? null,
    '辦法'     => $b->{'辦法'} ?? null,
    '審查意見' => $b->{'審查意見'} ?? null,
    '議決'     => $b->{'議決'} ?? null,
];
?>
<?php foreach ($sections as $label => $content): ?>
<?php if (!$content) continue; ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2"><strong class="small"><?= htmlspecialchars($label) ?></strong></div>
  <div class="card-body py-2">
    <p class="mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($content) ?></p>
  </div>
</div>
<?php endforeach; ?>

<p class="text-body-secondary small">
  來源檔案：<?= htmlspecialchars($b->{'來源檔案'} ?? '—') ?>
  <?php if ($b->{'來源頁碼'} ?? null): ?>
  （第 <?= htmlspecialchars($b->{'來源頁碼'}) ?> 頁）
  <?php endif; ?>
</p>

<?php endif; ?>
