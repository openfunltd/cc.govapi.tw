<?php
$groups = $this->candidate_groups ?? [];
if (!$groups): ?>
<div class="alert alert-warning mt-4">找不到這個人的參選紀錄</div>
<?php return; endif;

$latest = $groups[0]->{'candidate'};
$total_runs = count($groups);
$total_wins = 0;
foreach ($groups as $g) {
    if ($g->{'candidate'}->{'當選'} ?? false) $total_wins++;
}
?>

<div class="pt-4 pb-3">
  <h1 class="h3 fw-semibold mb-1"><?= htmlspecialchars($latest->{'姓名'} ?? '') ?></h1>
  <p class="text-body-secondary mb-2">
    共參選 <?= $total_runs ?> 次，當選 <?= $total_wins ?> 次
    <?php if ($this->candidate_is_councilor ?? false): ?>
    ・<a href="/info/councilor/<?= urlencode($this->candidate_person_code) ?>">查看議員個人頁 →</a>
    <?php endif; ?>
  </p>

  <div class="alert alert-light border small">
    這裡收錄的是「縣市議員／直轄市議員」選舉的參選紀錄，包含落選的次數；候選人資料只回溯到
    民國98年（2009年），更早期的參選紀錄查不到。政見如果來源是圖片或文字層是亂碼，會直接
    顯示原始圖片。
  </div>

  <?php foreach ($groups as $group): ?>
  <?php $c = $group->{'candidate'}; ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
      <strong class="small">
        <?= htmlspecialchars($c->{'年份'} ?? '') ?> 年・<?= htmlspecialchars($c->{'縣市'} ?? '') ?>
        <?php if ($c->{'議會代碼'} ?? null): ?>
        （<?= htmlspecialchars($c->{'議會代碼'}) ?>）
        <?php endif; ?>
      </strong>
      <?php if ($c->{'當選'} ?? false): ?>
      <span class="badge bg-success">當選</span>
      <?php else: ?>
      <span class="badge bg-secondary">落選</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-3">
        <?php if ($c->{'相片路徑'} ?? null): ?>
        <div class="col-auto">
          <img src="<?= htmlspecialchars($c->{'相片路徑'}) ?>" alt="" style="width:120px; aspect-ratio:3/4; object-fit:cover;" class="rounded shadow-sm">
        </div>
        <?php endif; ?>
        <div class="col">
          <p class="small text-body-secondary mb-2">
            <?= htmlspecialchars($c->{'選舉名稱'} ?? '') ?>
            <?php if ($c->{'選區別'} ?? null): ?>
            ・<?= htmlspecialchars($c->{'選區別'}) ?>
            <?php endif; ?>
            <?php if ($c->{'號次'} ?? null): ?>
            ・號次 <?= htmlspecialchars($c->{'號次'}) ?>
            <?php endif; ?>
            <?php if (($c->{'得票數'} ?? null) !== null): ?>
            ・得票數 <?= number_format($c->{'得票數'}) ?>
            <?php endif; ?>
            <?php if (($c->{'得票率'} ?? null) !== null): ?>
            （<?= htmlspecialchars($c->{'得票率'}) ?>%<?php if ($c->{'得票排名'} ?? null): ?>，第 <?= htmlspecialchars($c->{'得票排名'}) ?> 高票<?php endif; ?>）
            <?php endif; ?>
          </p>

          <?= info_candidate_field_html('h2', '學歷', $c, '學歷') ?>
          <?= info_candidate_field_html('h2', '經歷', $c, '經歷') ?>
          <?= info_candidate_field_html('h2', '政見', $c, '政見', '政見圖路徑') ?>
        </div>
      </div>

      <?php if (!empty($group->{'race'})): ?>
      <h2 class="h6 fw-semibold mb-2">同選區得票比較</h2>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light">
            <tr>
              <th>排名</th>
              <th>號次</th>
              <th>姓名</th>
              <th>性別</th>
              <th>黨籍</th>
              <th>得票數</th>
              <th>得票率</th>
              <th>當選</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group->{'race'} as $rc): ?>
            <?php $is_self = ($rc->{'候選人代碼'} ?? null) === ($c->{'候選人代碼'} ?? null); ?>
            <tr class="<?= $is_self ? 'table-primary' : '' ?>">
              <td><?= htmlspecialchars($rc->{'得票排名'} ?? '—') ?></td>
              <td><?= htmlspecialchars($rc->{'號次'} ?? '—') ?></td>
              <td>
                <?php if (!$is_self && ($rc->{'人物代碼'} ?? null)): ?>
                <a href="/info/candidate/<?= urlencode($rc->{'人物代碼'}) ?>"><?= htmlspecialchars($rc->{'姓名'} ?? '') ?></a>
                <?php else: ?>
                <?= htmlspecialchars($rc->{'姓名'} ?? '') ?>
                <?php endif; ?>
                <?= $is_self ? '（本人）' : '' ?>
              </td>
              <td><?= htmlspecialchars($rc->{'性別'} ?? '—') ?></td>
              <td><?= htmlspecialchars($rc->{'黨籍'} ?? '—') ?></td>
              <td><?= ($rc->{'得票數'} ?? null) !== null ? number_format($rc->{'得票數'}) : '—' ?></td>
              <td><?= ($rc->{'得票率'} ?? null) !== null ? htmlspecialchars($rc->{'得票率'}) . '%' : '—' ?></td>
              <td><?= ($rc->{'當選'} ?? false) ? '✅' : '' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
