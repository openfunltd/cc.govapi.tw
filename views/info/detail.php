<?php
$o = $this->overview ?? null;
if (!$o): ?>
<div class="alert alert-warning mt-4">找不到本議會資料</div>
<?php return; endif;

$session = $o->{'會期'} ?? null;
$status = $session->{'狀態'} ?? 'none';
$sittings = $o->{'場次'} ?? [];
?>

<div class="pt-4 pb-2">
  <h1 class="h3 fw-semibold mb-1">🏛 <?= htmlspecialchars($o->{'議會名稱'}) ?></h1>
  <p class="text-body-secondary mb-4"><?= htmlspecialchars($o->{'議會類別'} ?? '') ?></p>
</div>

<!-- Region 1：議會基本資料 -->
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">目前屆次</div>
        <div class="fs-5 fw-semibold"><?= $o->{'屆次'} ? '第 ' . (int)$o->{'屆次'} . ' 屆' : '—' ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">議長</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars($o->{'議長姓名'} ?? '—') ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">副議長</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars($o->{'副議長姓名'} ?? '—') ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">議員人數</div>
        <div class="fs-5 fw-semibold"><?= is_null($o->{'議員人數'} ?? null) ? '—' : (int)$o->{'議員人數'} . ' 位' ?></div>
      </div>
    </div>
    <?php if ($o->{'就職日'} ?? null): ?>
    <div class="text-body-secondary small mt-3">
      本屆就職日：<?= htmlspecialchars($o->{'就職日'}) ?>
      <?php if ($o->{'任期屆滿日'} ?? null): ?>
        ・任期屆滿日：<?= htmlspecialchars($o->{'任期屆滿日'}) ?>
      <?php else: ?>
        ・任期進行中
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Region 2：議員名單 -->
<h2 class="h5 fw-semibold mb-3">👥 議員名單</h2>
<?php if (empty($this->councilors)): ?>
<div class="alert alert-light border mb-4">目前尚無本屆議員資料</div>
<?php else: ?>
<div class="row g-3 mb-4">
  <?php foreach ($this->councilors as $c): ?>
  <div class="col-6 col-md-3 col-lg-2">
    <div class="card councilor-card h-100 shadow-sm">
      <?php if ($c->{'照片'} ?? null): ?>
      <img src="<?= htmlspecialchars($c->{'照片'}) ?>" alt="" loading="lazy">
      <?php else: ?>
      <div class="d-flex align-items-center justify-content-center bg-secondary bg-opacity-10" style="aspect-ratio:3/4;">🧑</div>
      <?php endif; ?>
      <div class="card-body p-2">
        <div class="fw-semibold small">
          <?= htmlspecialchars($c->{'姓名'} ?? '') ?>
          <?php if (($c->{'職稱'} ?? '議員') !== '議員'): ?>
          <span class="badge bg-warning text-dark"><?= htmlspecialchars($c->{'職稱'}) ?></span>
          <?php endif; ?>
        </div>
        <div class="text-body-secondary" style="font-size: 0.75rem;">
          <?= htmlspecialchars($c->{'黨籍'} ?? '—') ?><br>
          <?= htmlspecialchars($c->{'區域'} ?? '—') ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Region 3：行事曆／最近場次 -->
<h2 class="h5 fw-semibold mb-3">🗓 <?= $status === 'ongoing' ? '目前會期' : '最近會期' ?></h2>
<?php if (!$session): ?>
<div class="alert alert-light border mb-4">目前尚無會期資料</div>
<?php else: ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-white d-flex align-items-center justify-content-between">
    <div>
      <span class="status-dot <?= $status ?>"></span>
      <strong><?= htmlspecialchars($session->{'會期名稱'} ?? '') ?></strong>
      <?php if ($session->{'開始日期'} ?? null): ?>
      <span class="text-body-secondary small ms-1"><?= htmlspecialchars($session->{'開始日期'}) ?> ~ <?= htmlspecialchars($session->{'結束日期'} ?? '進行中') ?></span>
      <?php endif; ?>
    </div>
    <span class="badge <?= $status === 'ongoing' ? 'bg-success' : 'bg-secondary' ?>">
      <?= $status === 'ongoing' ? '進行中' : '已結束' ?>
    </span>
  </div>
  <?php if (empty($sittings)): ?>
  <div class="card-body text-body-secondary small">本會期尚無場次資料</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th>日期</th>
          <th>星期</th>
          <th>時段</th>
          <th>類別</th>
          <th>內容</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sittings as $s): ?>
        <tr>
          <td class="text-nowrap"><?= htmlspecialchars($s->{'日期'} ?? '') ?></td>
          <td><?= htmlspecialchars($s->{'星期'} ?? '') ?></td>
          <td><?= htmlspecialchars($s->{'時段'} ?? '全天') ?></td>
          <td><?= htmlspecialchars($s->{'場次類別'} ?? '') ?></td>
          <td class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($s->{'委員會名稱'} ?? $s->{'議程說明'} ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
