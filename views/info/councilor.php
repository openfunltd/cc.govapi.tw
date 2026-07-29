<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4">找不到議員資料</div>
<?php return; endif;

$latest = $records[0];
$person_code = $latest->{'人物代碼'} ?? '';
$tab = $this->profile_tab ?? 'profile';
?>

<div class="pt-4 pb-3">
  <h1 class="h3 fw-semibold mb-3"><?= htmlspecialchars($latest->{'姓名'} ?? '') ?></h1>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'profile' ? 'active' : '' ?>" href="/info/councilor/<?= urlencode($person_code) ?>">基本資料</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'speeches' ? 'active' : '' ?>" href="/info/councilor/<?= urlencode($person_code) ?>/speeches">發言記錄</a>
    </li>
  </ul>

  <?= $this->partial('info/councilor_' . $tab, $this) ?>
</div>
