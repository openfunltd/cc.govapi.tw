<?php
$year = (int) ($this->election_year ?? 0);
$town = $this->election_town ?? '';
$chiefs = $this->town_chiefs ?? [];
$reps = $this->town_reps ?? [];
$villages = $this->town_villages ?? [];
$incumbent = $this->election_incumbent ?? [];
$names = function ($list) use ($incumbent) {
    return implode('、', array_map(fn($c) => (($c->{'號次'} ?? null) ? '<span class="text-body-secondary">' . (int) $c->{'號次'} . '.</span>' : '')
        . election_person($c) . election_badge($c, $incumbent), $list));
};
?>
<div class="pt-3 pb-2">
  <nav aria-label="breadcrumb" class="mb-1">
    <ol class="breadcrumb mb-0 small">
      <li class="breadcrumb-item"><a href="/info<?= ($this->latest_term_no ?? null) ? '/' . (int) $this->latest_term_no : '' ?>"><?= htmlspecialchars($this->council_name ?? '') ?></a></li>
      <li class="breadcrumb-item"><a href="/info/election/<?= $year ?>"><?= $year ?> 年選舉</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($town) ?></li>
    </ol>
  </nav>
  <h1 class="h3 fw-semibold mb-1">🗳 <?= htmlspecialchars($town) ?> <?= $year ?> 年選舉候選人</h1>
  <p class="text-body-secondary mb-2">點選選區或村里看全部候選人的學經歷與政見，點選姓名看歷年參選紀錄。</p>
  <?php if ($this->election_registration_only ?? false): ?>
  <div class="alert alert-light border small">目前是<strong>登記階段</strong>的名單，還沒有號次、學經歷與政見；名單在資格審定後可能變動。</div>
  <?php endif; ?>
</div>

<?php if (!$chiefs && !$reps && !$villages): ?>
<div class="alert alert-light border">目前沒有 <?= htmlspecialchars($town) ?> <?= $year ?> 年選舉的候選人資料</div>
<?php endif; ?>

<?php if ($chiefs): ?>
<h2 class="h5 fw-semibold mt-3 mb-2"><?= htmlspecialchars($chiefs[0]->{'參選職位'} === '山地原住民區長' ? '區長' : '鄉鎮市長') ?>候選人<span class="small text-body-secondary fw-normal ms-2"><?= count($chiefs) ?> 位</span>
  <a class="small fw-normal ms-2" href="<?= election_race_url($year, $chiefs[0]) ?>">學經歷・政見 →</a></h2>
<div class="row g-2 mb-4">
  <?php foreach ($chiefs as $c): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card h-100 shadow-sm"><div class="card-body p-2">
      <?php if ($c->{'號次'} ?? null): ?><div class="small text-body-secondary"><?= (int) $c->{'號次'} ?> 號</div><?php endif; ?>
      <div class="fs-5 fw-semibold"><?= election_person($c) ?><?= election_badge($c, $incumbent) ?></div>
      <div class="small text-body-secondary"><?= election_party($c) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($reps): ?>
<h2 class="h5 fw-semibold mt-3 mb-2">代表候選人<span class="small text-body-secondary fw-normal ms-2"><?= array_sum(array_map('count', $reps)) ?> 位，<?= count($reps) ?> 個選區</span></h2>
<div class="row g-2 mb-4">
  <?php foreach ($reps as $district => $list): ?>
  <div class="col-12 col-md-6">
    <div class="border rounded p-2 h-100">
      <div class="fw-semibold mb-1"><a href="<?= election_race_url($year, $list[0]) ?>"><?= htmlspecialchars($district) ?></a><span class="small text-body-secondary fw-normal ms-1"><?= count($list) ?> 位</span></div>
      <div class="small"><?= $names($list) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($villages): ?>
<h2 class="h5 fw-semibold mt-3 mb-2">村里長候選人<span class="small text-body-secondary fw-normal ms-2"><?= array_sum(array_map('count', $villages)) ?> 位，<?= count($villages) ?> 個村里</span></h2>
<div class="table-responsive mb-4">
  <table class="table table-sm align-middle">
    <tbody>
      <?php foreach ($villages as $village => $list): ?>
      <tr>
        <th class="text-nowrap fw-semibold" style="width: 7em;"><a href="<?= election_race_url($year, $list[0]) ?>"><?= htmlspecialchars($village) ?></a></th>
        <td class="small"><?= $names($list) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
