<?php
$sitting = $this->sitting_meta ?? null;
$transcript = $this->transcript ?? null;
$session_code = $transcript->{'會期代碼'} ?? $sitting->{'會期代碼'} ?? null;
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
<?php if ($sitting->{'議程說明'} ?? null): ?>
<p class="text-body-secondary small mb-1" style="white-space: pre-wrap;"><?= htmlspecialchars($sitting->{'議程說明'}) ?></p>
<?php endif; ?>
<?php if ($sitting->{'來源網址'} ?? null): ?>
<p class="text-body-secondary small">
  原始會議紀錄：
  <a href="<?= htmlspecialchars($sitting->{'來源網址'}) ?>" target="_blank" rel="noopener">議會官網公告頁 →</a>
</p>
<?php endif; ?>

<?php if (!$transcript || empty($transcript->{'分段'})): ?>
<div class="alert alert-light border mt-3">本場次尚無逐字稿資料</div>
<?php else: ?>

<ul class="nav nav-pills mb-3 flex-wrap" id="transcript-section-tabs" role="tablist">
  <?php foreach ($transcript->{'分段'} as $i => $sec): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $i === 0 ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#transcript-pane-<?= $i ?>" type="button" role="tab">
      <?= htmlspecialchars($sec->{'標籤'} ?? "分段 " . ($i + 1)) ?>
    </button>
  </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content">
  <?php foreach ($transcript->{'分段'} as $i => $sec): ?>
  <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="transcript-pane-<?= $i ?>" role="tabpanel">
    <?php if (!is_null($sec->{'字數'} ?? null)): ?>
    <div class="small text-body-secondary mb-2"><?= number_format($sec->{'字數'}) ?> 字</div>
    <?php endif; ?>
    <pre class="border rounded p-3 bg-light" style="white-space: pre-wrap; max-height: 600px; overflow-y: auto; font-family: inherit;"><?= htmlspecialchars($sec->{'內容'} ?? '') ?></pre>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>
