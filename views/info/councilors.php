<?php if (empty($this->councilors)): ?>
<div class="alert alert-light border mb-4">目前尚無本屆議員資料</div>
<?php else: ?>
<?php foreach ($this->councilors as $group): ?>
<h2 class="h6 fw-semibold text-body-secondary mb-2"><?= htmlspecialchars($group['label']) ?></h2>
<div class="row g-3 mb-4">
  <?php foreach ($group['councilors'] as $c): ?>
  <div class="col-6 col-md-3 col-lg-2">
    <a href="/info/councilor/<?= urlencode($c->{'人物代碼'} ?? '') ?>" class="text-decoration-none text-reset">
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
          <?= info_election_status_badge($c) ?>
          <?= info_departure_status_badge($c) ?>
        </div>
        <div class="text-body-secondary" style="font-size: 0.75rem;">
          <?= htmlspecialchars($c->{'黨籍'} ?? '—') ?><br>
          <?= htmlspecialchars(info_district_label($c) ?: '—') ?>
          <?php if ($c->{'得票率'} ?? null): ?>
          <br><span class="text-primary">得票：<?= htmlspecialchars($c->{'得票率'}) ?>%</span>
          <?php elseif ($c->{'得票數'} ?? null): ?>
          <!-- 得票率需要同選區候選人完整得票數才能算出百分比，目前只有111年這次
               選舉有這份資料（見InfoController::attachVoteShare()的說明），較舊
               屆次沒有百分比可算，退而求其次顯示議員record自己帶的得票數（來源
               回溯年份廣得多），總比完全不顯示任何得票資訊好 -->
          <br><span class="text-primary">得票：<?= number_format($c->{'得票數'}) ?> 票</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
