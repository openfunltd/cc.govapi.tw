<?php
// 選舉指南共用：候選人姓名連結、政黨、現任／當選標籤、單一選舉頁連結
if (!function_exists('election_person')) {
    function election_person($c) {
        $name = htmlspecialchars($c->{'姓名'} ?? '');
        return ($c->{'人物代碼'} ?? null) ? '<a href="/info/candidate/' . rawurlencode($c->{'人物代碼'}) . '">' . $name . '</a>' : $name;
    }
    function election_party($c) {
        return htmlspecialchars($c->{'其他欄位'}->{'推薦之政黨'} ?? '');
    }
    function election_badge($c, $incumbent) {
        $html = '';
        if (isset($incumbent[$c->{'人物代碼'} ?? ''])) {
            $html .= ' <span class="badge text-bg-warning">' . htmlspecialchars($incumbent[$c->{'人物代碼'}]) . '</span>';
        }
        if ($c->{'當選'} ?? false) {
            $html .= ' <span class="badge text-bg-success">當選</span>';
        }
        return $html;
    }
    // 單一選舉頁網址：選舉代碼的類別（ELC-T1-115 → T1）＋選區別
    function election_race_url($year, $c) {
        $kind = explode('-', $c->{'選舉代碼'} ?? '')[1] ?? '';
        return '/info/election/' . (int) $year . '/race/' . rawurlencode($kind . ':' . ($c->{'選區別'} ?? ''));
    }
}
if (isset($this->race_candidates)) {
    echo $this->partial('info/election_race', $this);
    return;
}
if (isset($this->election_town)) {
    echo $this->partial('info/election_town', $this);
    return;
}
$year = (int) ($this->election_year ?? 0);
$mayors = $this->election_mayors ?? [];
$districts = $this->election_districts ?? [];
$incumbent = $this->election_incumbent ?? [];
$towns = $this->election_towns ?? [];
$person = 'election_person';
$party = 'election_party';
$badge = fn($c) => election_badge($c, $incumbent);
$total = count($mayors) + array_sum(array_map('count', $districts)) + count($towns);
?>
<div class="pt-3 pb-2">
  <nav aria-label="breadcrumb" class="mb-1">
    <ol class="breadcrumb mb-0 small">
      <li class="breadcrumb-item"><a href="/info<?= ($this->latest_term_no ?? null) ? '/' . (int) $this->latest_term_no : '' ?>"><?= htmlspecialchars($this->council_name ?? '') ?></a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= $year ?> 年選舉</li>
    </ol>
  </nav>
  <h1 class="h3 fw-semibold mb-1">🗳 <?= $year ?> 年地方選舉候選人</h1>
  <p class="text-body-secondary mb-2"><?= htmlspecialchars($this->council_name ?? '') ?>轄區的縣市長、<?= htmlspecialchars(CouncilHelper::t('議員')) ?><?= $towns ? '、鄉鎮市長、代表與村里長' : '' ?>候選人。點選選區看全部候選人的學經歷與政見，點選姓名看歷年參選紀錄。</p>
  <?php if ($this->election_registration_only ?? false): ?>
  <div class="alert alert-light border small">
    目前是<strong>登記階段</strong>的名單（中選會公告的候選人登記名冊），還沒有號次、學經歷與政見；名單在資格審定後可能變動。
    中選會公布正式候選人名冊與選舉公報後，這裡會補上號次、學經歷與政見。標示「現任」的是上一次（<?= $year - 4 ?> 年）選舉的當選人，「現任立委」是最近一次立委選舉的當選人。
  </div>
  <?php endif; ?>
</div>

<?php if (!$total): ?>
<div class="alert alert-light border">目前沒有 <?= $year ?> 年選舉的候選人資料</div>
<?php else: ?>

<?php if ($mayors): ?>
<h2 class="h5 fw-semibold mt-3 mb-2"><?= htmlspecialchars($mayors[0]->{'參選職位'} ?? '縣市長') ?>候選人<span class="small text-body-secondary fw-normal ms-2"><?= count($mayors) ?> 位</span>
  <a class="small fw-normal ms-2" href="<?= election_race_url($year, $mayors[0]) ?>">學經歷・政見 →</a></h2>
<div class="row g-2 mb-4">
  <?php foreach ($mayors as $c): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card h-100 shadow-sm"><div class="card-body p-2">
      <?php if ($c->{'號次'} ?? null): ?><div class="small text-body-secondary"><?= (int) $c->{'號次'} ?> 號</div><?php endif; ?>
      <div class="fs-5 fw-semibold"><?= $person($c) ?><?= $badge($c) ?></div>
      <div class="small text-body-secondary"><?= $party($c) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($districts): ?>
<h2 class="h5 fw-semibold mt-3 mb-2"><?= htmlspecialchars(CouncilHelper::t('議員')) ?>候選人<span class="small text-body-secondary fw-normal ms-2"><?= array_sum(array_map('count', $districts)) ?> 位，<?= count($districts) ?> 個選區</span></h2>
<div class="row g-2 mb-4">
  <?php foreach ($districts as $district => $list): ?>
  <div class="col-12 col-md-6">
    <div class="border rounded p-2 h-100">
      <div class="fw-semibold mb-1"><a href="<?= election_race_url($year, $list[0]) ?>"><?= htmlspecialchars($district) ?></a><span class="small text-body-secondary fw-normal ms-1"><?= count($list) ?> 位</span></div>
      <ul class="list-unstyled mb-0 small">
        <?php foreach ($list as $c): ?>
        <li><?php if ($c->{'號次'} ?? null): ?><span class="text-body-secondary"><?= (int) $c->{'號次'} ?>.</span> <?php endif; ?><?= $person($c) ?><?= $badge($c) ?> <span class="text-body-secondary"><?= $party($c) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($towns): ?>
<h2 class="h5 fw-semibold mt-3 mb-2">鄉鎮市長、代表與村里長<span class="small text-body-secondary fw-normal ms-2">依鄉鎮市區，<?= count($towns) ?> 個</span></h2>
<div class="row g-2 mb-4">
  <?php foreach ($towns as $town => $counts): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <a class="d-block border rounded p-2 h-100 text-decoration-none" href="/info/election/<?= $year ?>/town/<?= rawurlencode($town) ?>">
      <div class="fw-semibold"><?= htmlspecialchars($town) ?></div>
      <div class="small text-body-secondary"><?= htmlspecialchars(implode('、', array_map(fn($k, $v) => str_replace(['山地原住民', '鄉鎮市民'], ['', ''], $k) . " {$v}", array_keys($counts), $counts))) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>
