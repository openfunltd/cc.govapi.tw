<?php
$sitting = $this->sitting_meta ?? null;
$agendas = $this->sitting_agendas ?? [];
$session_code = $sitting->{'會期代碼'} ?? null;
?>

<?php if ($session_code): ?>
<nav aria-label="breadcrumb" class="mb-3">
  <a href="/info/<?= $this->term_no ?>/sessions/<?= urlencode($session_code) ?>" class="text-decoration-none small">&larr; 返回會期</a>
</nav>
<?php endif; ?>

<?php if (!$sitting): ?>
<div class="alert alert-light border">找不到場次資料</div>
<?php else: ?>

<h2 class="h5 fw-semibold mb-1">
  <?= htmlspecialchars($sitting->{'日期'} ?? '') ?>
  <?= htmlspecialchars($sitting->{'星期'} ?? '') ?>
  <?= htmlspecialchars($sitting->{'時段'} ?? '全天') ?>
  <span class="badge bg-secondary"><?= htmlspecialchars($sitting->{'場次類別'} ?? '') ?></span>
</h2>
<p class="text-body-secondary small mb-3">
  本場次的議程清單（依實際內容拆解出的子段落，跟舊版逐字稿是不同的資料來源）
</p>

<?php if (!$agendas): ?>
<div class="alert alert-light border mt-3">本場次尚無議程資料</div>
<?php else: ?>

<div class="list-group">
  <?php foreach ($agendas as $a): ?>
  <a href="/info/<?= $this->term_no ?>/agenda/<?= urlencode($a->{'代碼'}) ?>" class="list-group-item list-group-item-action">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <span class="fw-semibold"><?= htmlspecialchars($a->{'議程類型'} ?? '') ?></span>
        <?php if ($a->{'委員會或名稱'} ?? null): ?>
        <span class="text-body-secondary small">・<?= htmlspecialchars($a->{'委員會或名稱'}) ?></span>
        <?php endif; ?>
        <?php if ($a->{'質詢對象機關'} ?? null): ?>
        <div class="small text-body-secondary">質詢對象：<?= htmlspecialchars($a->{'質詢對象機關'}) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($a->{'時間資訊'} ?? null): ?>
      <span class="small text-body-secondary text-nowrap ms-2"><?= htmlspecialchars($a->{'時間資訊'}) ?></span>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>
