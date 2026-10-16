<?php
$groups = $this->committee_groups ?? [];
$members = $this->committee_members ?? [];
$period = $this->committee_members_period ?? null;
?>

<?php if (empty($groups)): ?>
<div class="alert alert-light border">目前尚無委員會資料</div>
<?php else: ?>
<div class="alert alert-light border small mb-4">
  委員會不綁屆，是議會層級的常設編制，不會每屆重新產生。
  <?php if ($members): ?>
  成員是第 <?= (int)$this->term_no ?> 屆第 <?= (int)$period ?> 會期的名單（立法委員每個會期重新登記委員會）。
  <?php else: ?>
  目前資料只有委員會本身的清單，還沒有「哪位<?= htmlspecialchars(CouncilHelper::t('議員')) ?>屬於哪個委員會」的成員資料。
  <?php endif; ?>
</div>
<?php foreach ($groups as $type => $committees): ?>
<h2 class="h6 fw-semibold text-body-secondary mb-2"><?= htmlspecialchars($type) ?></h2>
<div class="row g-3 mb-4">
  <?php foreach ($committees as $c): ?>
  <div class="col-md-6">
    <div class="card h-100 shadow-sm">
      <div class="card-body">
        <h3 class="h6 fw-semibold mb-1">
          <?= htmlspecialchars($c->{'名稱'} ?? '') ?>
          <?php if ($c->_is_abolished): ?>
          <span class="badge bg-secondary">已廢止</span>
          <?php endif; ?>
        </h3>
        <?php if ($c->{'別稱'} ?? null): ?>
        <p class="text-body-secondary small mb-2">別稱：<?= htmlspecialchars($c->{'別稱'}) ?></p>
        <?php endif; ?>
        <?php $c_members = $members[$c->{'名稱'} ?? ''] ?? []; ?>
        <?php if ($c_members): ?>
        <p class="small mb-2">
          <span class="text-body-secondary">成員（<?= count($c_members) ?> 人）：</span>
          <?php foreach ($c_members as $i => $m): ?><?= $i ? '、' : '' ?><a href="/info/councilor/<?= urlencode($m->{'人物代碼'} ?? '') ?>" title="<?= htmlspecialchars($m->{'黨籍'} ?? '') ?>"><?= htmlspecialchars($m->{'姓名'} ?? '') ?></a><?php endforeach; ?>
        </p>
        <?php endif; ?>
        <?php if ($c->{'職掌'} ?? null): ?>
        <?php if ($members): ?>
        <details class="small"><summary class="text-body-secondary">職掌</summary><p class="mb-0 mt-1" style="white-space: pre-wrap;"><?= htmlspecialchars($c->{'職掌'}) ?></p></details>
        <?php else: ?>
        <p class="small mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($c->{'職掌'}) ?></p>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
