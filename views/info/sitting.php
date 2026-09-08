<?php
$sitting = $this->sitting_meta ?? null;
$agendas = $this->sitting_agendas ?? [];
$transcript = $this->transcript ?? null;
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
<?php if ($sitting->{'議程說明'} ?? null): ?>
<p class="text-body-secondary small mb-1" style="white-space: pre-wrap;"><?= htmlspecialchars($sitting->{'議程說明'}) ?></p>
<?php endif; ?>
<?php if ($sitting->{'來源網址'} ?? null): ?>
<p class="text-body-secondary small mb-3">
  原始會議紀錄：
  <a href="<?= htmlspecialchars($sitting->{'來源網址'}) ?>" target="_blank" rel="noopener">議會官網公告頁 →</a>
</p>
<?php endif; ?>

<?php
  $has_agendas = !empty($agendas);
  $has_transcript = $transcript && !empty($transcript->{'分段'});
  // 預設打開資料比較完整的那一頁籤：議程（新版逐句發言）優先於逐字稿（舊版
  // 整篇文字），兩者都沒有時還是顯示議程頁籤（讓使用者看到「本場次尚無議程
  // 資料」的提示，而不是完全空白）
  $default_pane = $has_agendas ? 'agendas' : ($has_transcript ? 'transcript' : 'agendas');
?>

<ul class="nav nav-pills mb-3" id="sitting-outer-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $default_pane === 'agendas' ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#sitting-pane-agendas" type="button" role="tab">
      🗂 議程<?= $has_agendas ? ' (' . count($agendas) . ')' : '' ?>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $default_pane === 'transcript' ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#sitting-pane-transcript" type="button" role="tab">
      📄 逐字稿<?= $has_transcript ? (($transcript->{'有逐字稿'} ?? false) ? '' : '（速記）') : '' ?>
    </button>
  </li>
</ul>

<div class="tab-content">

  <div class="tab-pane fade <?= $default_pane === 'agendas' ? 'show active' : '' ?>" id="sitting-pane-agendas" role="tabpanel">
    <p class="text-body-secondary small mb-3">
      本場次的議程清單（依實際內容拆解出的子段落，含逐句發言，比舊版逐字稿更完整）
    </p>
    <?php if (!$has_agendas): ?>
    <div class="alert alert-light border">本場次尚無議程資料</div>
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
  </div>

  <div class="tab-pane fade <?= $default_pane === 'transcript' ? 'show active' : '' ?>" id="sitting-pane-transcript" role="tabpanel">
    <p class="text-body-secondary small mb-3">
      舊版整場次逐字稿全文（跟上面「議程」是兩條獨立的資料來源，涵蓋範圍不完全重疊）。
      每個分段旁邊標示是不是真的有逐字對話，還是只有速記/摘要式紀錄。
    </p>
    <?php if (!$has_transcript): ?>
    <div class="alert alert-light border">本場次尚無逐字稿資料</div>
    <?php else: ?>

    <ul class="nav nav-pills mb-3 flex-wrap" id="transcript-section-tabs" role="tablist">
      <?php foreach ($transcript->{'分段'} as $i => $sec): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $i === 0 ? 'active' : '' ?>" data-bs-toggle="pill"
                data-bs-target="#transcript-pane-<?= $i ?>" type="button" role="tab">
          <?= htmlspecialchars($sec->{'標籤'} ?? "分段 " . ($i + 1)) ?>
          <?php if ($sec->{'是否逐字'} ?? false): ?>
          <span class="badge bg-success ms-1">逐字</span>
          <?php else: ?>
          <span class="badge bg-secondary ms-1">速記</span>
          <?php endif; ?>
        </button>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="tab-content">
      <?php foreach ($transcript->{'分段'} as $i => $sec): ?>
      <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="transcript-pane-<?= $i ?>" role="tabpanel">
        <?php if (!is_null($sec->{'字數'} ?? null)): ?>
        <div class="small text-body-secondary mb-2">
          <?= number_format($sec->{'字數'}) ?> 字
          <?php if (!($sec->{'是否逐字'} ?? false)): ?>
          ・<span class="text-body-secondary">這段只判斷為速記/摘要，不一定有完整逐字對話，對細節有興趣可以找原始會議紀錄或影音記錄</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <pre class="border rounded p-3 bg-light" style="white-space: pre-wrap; max-height: 600px; overflow-y: auto; font-family: inherit;"><?= htmlspecialchars($sec->{'內容'} ?? '') ?></pre>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>
  </div>

</div>

<?php endif; ?>
