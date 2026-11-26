<?php
$b = $this->bill_detail ?? null;

// 「提案人結構」「連署人結構」有值時優先用（可能連到議員個人頁），目前只有
// 部分議會有這個結構化欄位，沒有時退回顯示純文字姓名（不加連結）
function bill_person_links($structured, $plain) {
    if (!empty($structured)) {
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
    return $plain ? htmlspecialchars($plain) : null;
}
?>

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
  <?php $proposer_html = bill_person_links($b->{'提案人結構'} ?? [], $b->{'提案人'} ?? null); ?>
  <?php if ($proposer_html): ?>
  ・提案人：<?= $proposer_html ?>
  <?php endif; ?>
  <?php $cosigner_html = bill_person_links($b->{'連署人結構'} ?? [], $b->{'連署人'} ?? null); ?>
  <?php if ($cosigner_html): ?>
  ・連署人：<?= $cosigner_html ?>
  <?php endif; ?>
  <?php if ($b->{'備註'} ?? null): ?>
  ・備註：<?= htmlspecialchars($b->{'備註'}) ?>
  <?php endif; ?>
  <?php if ($b->{'會議代碼'} ?? null): ?>
  ・<a href="/info/<?= (int)($b->{'屆'} ?? 0) ?>/sessions/<?= urlencode($b->{'會議代碼'}) ?>">查看提案會議 &rarr;</a>
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

<?php $progress = $b->{'處理進度'} ?? []; ?>
<?php if ($progress): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2"><strong class="small">處理進度</strong></div>
  <ul class="list-group list-group-flush small">
    <?php foreach ($progress as $p): ?>
    <li class="list-group-item py-2">
      <span class="text-body-secondary me-2"><?= htmlspecialchars($p->{'日期'} ?? '') ?></span>
      <strong><?= htmlspecialchars($p->{'階段'} ?? '') ?></strong>
      <?php if ($p->{'會次'} ?? null): ?>
      <span class="text-body-secondary">（<?= htmlspecialchars($p->{'會次'}) ?>）</span>
      <?php endif; ?>
      <?php if ($p->{'結果'} ?? null): ?>
      <div style="white-space: pre-wrap;"><?= htmlspecialchars($p->{'結果'}) ?></div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php $replies = $b->{'機關回覆'} ?? []; ?>
<?php if ($replies): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2">
    <strong class="small">機關回覆</strong>
    <?php if ($b->{'辦理狀態原文'} ?? null): ?>
    <span class="badge <?= ($b->{'辦理狀態'} ?? '') === '已結案' ? 'bg-success' : 'bg-warning text-dark' ?> ms-1"><?= htmlspecialchars($b->{'辦理狀態原文'}) ?></span>
    <?php endif; ?>
  </div>
  <ul class="list-group list-group-flush small">
    <?php foreach ($replies as $r): ?>
    <li class="list-group-item py-2">
      <div class="text-body-secondary">
        <?= htmlspecialchars(implode('・', array_filter([$r->{'日期'} ?? '', $r->{'機關'} ?? '', $r->{'文號'} ?? '', $r->{'狀態'} ?? '']))) ?>
      </div>
      <?php if ($r->{'內容'} ?? null): ?>
      <div style="white-space: pre-wrap;"><?= htmlspecialchars($r->{'內容'}) ?></div>
      <?php endif; ?>
      <?php foreach ($r->{'附件'} ?? [] as $f): ?>
      <div><a href="<?= htmlspecialchars($f->{'網址'} ?? '') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(($f->{'名稱'} ?? '') ?: '附件') ?></a></div>
      <?php endforeach; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<p class="text-body-secondary small">
  來源檔案：
  <?php $source_url = $b->{'來源網址'} ?? null; ?>
  <?php $source_page = $b->{'來源頁碼'} ?? null; ?>
  <?php
  // 臺北市議會議事資訊系統的議案頁只接受 Referer 是 *.tcc.gov.tw 的連線（2026-11 起），從這裡點過去會 403；
  // 改給議案進階查詢頁（不檢查 Referer）和系統號，讓使用者自己到議會網站查證
  $tcc_sysn = null;
  if ($source_url && str_starts_with($source_url, 'https://ifddoc2.tcc.gov.tw/TCCMIS_Front/DetailPage.aspx')
      && preg_match('/[?&]SYSN=(\d+)/', $source_url, $m)) {
      $tcc_sysn = sprintf('%05d', $m[1]);
  }
  ?>
  <?php if ($tcc_sysn): ?>
  請至<a href="https://ifddoc2.tcc.gov.tw/TCCMIS_Front/Adv_SearchInput.aspx" target="_blank" rel="noopener">臺北市議會議案進階查詢</a>以系統號 <?= htmlspecialchars($tcc_sysn) ?> 查詢
  <?php elseif ($source_url): ?>
  <a href="<?= htmlspecialchars($source_url . ($source_page ? '#page=' . urlencode($source_page) : '')) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($b->{'來源檔案'} ?? '原始檔案') ?></a>
  <?php else: ?>
  <?= htmlspecialchars($b->{'來源檔案'} ?? '—') ?>
  <?php endif; ?>
  <?php if ($source_page): ?>
  （第 <?= htmlspecialchars($source_page) ?> 頁）
  <?php endif; ?>
</p>

<?php endif; ?>
