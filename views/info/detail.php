<?php if (is_null($this->term_no ?? null)): ?>
<div class="alert alert-warning mt-4">目前尚無屆期資料</div>
<?php return; endif; ?>

<?= $this->partial('info/header', $this) ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($this->tabs as $key => $label): ?>
  <li class="nav-item">
    <a class="nav-link <?= $this->tab === $key ? 'active' : '' ?>" href="/info/<?= $this->term_no ?>/<?= $key ?>">
      <?= htmlspecialchars($label) ?>
    </a>
  </li>
  <?php endforeach; ?>
  <?php if (CCAPI_Council::getKind($this->cc_code ?? '') === '縣市議會'): ?>
  <li class="nav-item ms-auto">
    <a class="nav-link text-danger" href="/info/election/<?= InfoController::NEXT_ELECTION_YEAR ?>">🗳 <?= InfoController::NEXT_ELECTION_YEAR ?> 年選舉</a>
  </li>
  <?php endif; ?>
</ul>

<?= $this->partial('info/' . $this->tab, $this) ?>
