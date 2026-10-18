<?php
$year = (int) ($this->election_year ?? 0);
$candidates = $this->race_candidates ?? [];
$incumbent = $this->election_incumbent ?? [];
$town = $this->race_town ?? null;
$has_bulletin = (bool) array_filter($candidates, fn($c) => ($c->{'學歷'} ?? null) || ($c->{'政見'} ?? null) || ($c->{'政見圖路徑'} ?? null));
?>
<div class="pt-3 pb-2">
  <nav aria-label="breadcrumb" class="mb-1">
    <ol class="breadcrumb mb-0 small">
      <li class="breadcrumb-item"><a href="/info<?= ($this->latest_term_no ?? null) ? '/' . (int) $this->latest_term_no : '' ?>"><?= htmlspecialchars($this->council_name ?? '') ?></a></li>
      <li class="breadcrumb-item"><a href="/info/election/<?= $year ?>"><?= $year ?> 年選舉</a></li>
      <?php if ($town): ?>
      <li class="breadcrumb-item"><a href="/info/election/<?= $year ?>/town/<?= rawurlencode($town) ?>"><?= htmlspecialchars($town) ?></a></li>
      <?php endif; ?>
      <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($this->race_district ?? '') ?></li>
    </ol>
  </nav>
  <h1 class="h3 fw-semibold mb-1">🗳 <?= htmlspecialchars($this->race_title ?? '') ?>候選人</h1>
  <p class="text-body-secondary mb-2"><?= htmlspecialchars($candidates[0]->{'選舉名稱'} ?? ($year . ' 年選舉')) ?>，共 <?= count($candidates) ?> 位候選人。</p>
  <?php if ($this->election_registration_only ?? false): ?>
  <div class="alert alert-light border small">
    目前是<strong>登記階段</strong>的名單（中選會公告的候選人登記名冊），還沒有號次、學經歷與政見；名單在資格審定後可能變動。
    中選會公布選舉公報後，這裡會補上相片、學經歷與政見。點選姓名可以看候選人過去的參選紀錄與當時的政見。
  </div>
  <?php elseif ($candidates && !$has_bulletin): ?>
  <div class="alert alert-light border small">這場選舉還沒有整理好的選舉公報內容（學經歷、政見）。</div>
  <?php endif; ?>
</div>

<?php if (!$candidates): ?>
<div class="alert alert-light border">找不到這場選舉的候選人資料</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($candidates as $c): ?>
  <div class="col-12<?= $has_bulletin ? ' col-xl-6' : ' col-md-6 col-lg-4' ?>">
    <div class="card h-100 shadow-sm">
      <div class="card-body">
        <div class="d-flex gap-3 mb-2">
          <?php if ($c->{'相片路徑'} ?? null): ?>
          <img src="<?= htmlspecialchars($c->{'相片路徑'}) ?>" alt="<?= htmlspecialchars($c->{'姓名'} ?? '') ?>" loading="lazy" style="width:96px; aspect-ratio:3/4; object-fit:cover;" class="rounded shadow-sm flex-shrink-0">
          <?php endif; ?>
          <div>
            <?php if ($c->{'號次'} ?? null): ?><div class="small text-body-secondary"><?= (int) $c->{'號次'} ?> 號</div><?php endif; ?>
            <div class="fs-4 fw-semibold"><?= election_person($c) ?><?= election_badge($c, $incumbent) ?></div>
            <div class="small text-body-secondary">
              <?= htmlspecialchars(implode('・', array_filter([
                  $c->{'其他欄位'}->{'推薦之政黨'} ?? null,
                  $c->{'其他欄位'}->{'性別'} ?? null,
                  $c->{'其他欄位'}->{'出生年月日'} ?? $c->{'其他欄位'}->{'出生日期'} ?? null,
              ]))) ?>
            </div>
            <?php if (($c->{'得票數'} ?? null) !== null): ?>
            <div class="small">得票 <?= number_format($c->{'得票數'}) ?><?php if (($c->{'得票率'} ?? null) !== null): ?>（<?= htmlspecialchars($c->{'得票率'}) ?>%）<?php endif; ?></div>
            <?php endif; ?>
            <?php if ($c->{'登記日期'} ?? null): ?>
            <div class="small text-body-secondary"><?= htmlspecialchars($c->{'登記日期'}) ?> 登記</div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($has_bulletin): ?>
        <?= info_candidate_source_links($c) ?>
        <?= info_candidate_field_html('h3', '學歷', $c, '學歷') ?>
        <?= info_candidate_field_html('h3', '經歷', $c, '經歷') ?>
        <?= info_candidate_field_html('h3', '政見', $c, '政見', '政見圖路徑') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
