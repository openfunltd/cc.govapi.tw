<?php if (empty($this->councilors)): ?>
<div class="alert alert-light border mb-4">目前尚無本屆議員資料</div>
<?php else: ?>
<div class="row g-3 mb-4">
  <?php foreach ($this->councilors as $c): ?>
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
        </div>
        <div class="text-body-secondary" style="font-size: 0.75rem;">
          <?= htmlspecialchars($c->{'黨籍'} ?? '—') ?><br>
          <?= htmlspecialchars($c->{'區域'} ?? '—') ?>
        </div>
      </div>
    </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
