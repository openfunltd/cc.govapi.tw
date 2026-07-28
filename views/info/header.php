<?php $h = $this->header ?? null; ?>

<div class="pt-4 pb-2">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1 class="h3 fw-semibold mb-0">🏛 <?= htmlspecialchars($this->council_name) ?></h1>
    <?php if (!empty($this->all_terms)): ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
        第 <?= (int)$this->term_no ?> 屆
      </button>
      <ul class="dropdown-menu dropdown-menu-end" style="max-height: 320px; overflow-y: auto;">
        <?php foreach ($this->all_terms as $t): ?>
        <li>
          <a class="dropdown-item <?= (int)$t->{'屆次'} === (int)$this->term_no ? 'active' : '' ?>"
             href="/info/<?= (int)$t->{'屆次'} ?>/<?= htmlspecialchars($this->tab) ?>">
            第 <?= (int)$t->{'屆次'} ?> 屆
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($h): ?>
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">目前屆次</div>
        <div class="fs-5 fw-semibold">第 <?= (int)$h->{'屆次'} ?> 屆</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">議長</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars($h->{'議長姓名'} ?? '—') ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">副議長</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars($h->{'副議長姓名'} ?? '—') ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-body-secondary small">議員人數</div>
        <div class="fs-5 fw-semibold"><?= is_null($h->{'議員人數'} ?? null) ? '—' : (int)$h->{'議員人數'} . ' 位' ?></div>
      </div>
    </div>
    <?php if ($h->{'就職日'} ?? null): ?>
    <div class="text-body-secondary small mt-3">
      本屆就職日：<?= htmlspecialchars($h->{'就職日'}) ?>
      <?php if ($h->{'任期屆滿日'} ?? null): ?>
        ・任期屆滿日：<?= htmlspecialchars($h->{'任期屆滿日'}) ?>
      <?php else: ?>
        ・任期進行中
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
