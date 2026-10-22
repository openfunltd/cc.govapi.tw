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
    這裡收錄的是本議會範圍內「縣市議員／直轄市議員」、「縣市長／直轄市長」、「鄉鎮市長／山地原住民區長」、「鄉鎮市民代表／山地原住民區民代表」、「村（里）長」與「總統、副總統」選舉的參選紀錄，
    包含落選的次數；選舉公報（學經歷、政見）只回溯到民國98年（2009年），更早期的參選紀錄只有
    得票資料。政見如果來源是圖片或文字層是亂碼，會直接顯示原始圖片。
    <?php if ($this->candidate_all_url ?? null): ?>
    本參選人有在其他縣市或層級有參選記錄，可以點選<a href="<?= htmlspecialchars($this->candidate_all_url) ?>">這邊</a>看完整記錄。
    <?php endif; ?>
  </div>

  <?php foreach ($groups as $group): ?>
  <?php $c = $group->{'candidate'}; ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
      <strong class="small">
        <?= htmlspecialchars($c->{'年份'} ?? '') ?> 年・<?= htmlspecialchars($c->{'縣市'} ?? '') ?>
        <?php if (($c->{'議會代碼'} ?? null) && !($c->{'參選職位'} ?? null)): // 縣市長選舉前面的縣市名就夠了，不標議會 ?>
        （<?= htmlspecialchars(CCAPI_Council::getName($c->{'議會代碼'}) ?? $c->{'議會代碼'}) ?>）
        <?php endif; ?>
        <?php if ($c->{'選舉名稱'} ?? null): // 同一年可能有正式選舉與補選，標出完整選舉名稱才分得出來 ?>
        <span class="fw-normal text-body-secondary">・<?= htmlspecialchars($c->{'選舉名稱'}) ?></span>
        <?php endif; ?>
      </strong>
      <?php if ($c->{'當選'} ?? false): ?>
      <span class="badge bg-success">當選</span>
      <?php elseif ($c->{'登記階段'} ?? false): ?>
      <span class="badge bg-info text-dark">登記參選</span>
      <?php elseif (($c->{'得票數'} ?? null) === null && (int) ($c->{'年份'} ?? 0) >= InfoController::NEXT_ELECTION_YEAR): ?>
      <span class="badge bg-info text-dark">尚未投票</span>
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

          <?= info_candidate_source_links($c) ?>

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
            <?php // 登記階段的參選人沒有候選人代碼（都是 null），要用 doc 的「代碼」比，不然同一場每個人都會變成「本人」
            $is_self = ($rc->{'代碼'} ?? $rc->{'候選人代碼'} ?? null) === ($c->{'代碼'} ?? $c->{'候選人代碼'} ?? null); ?>
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

<?php $declarations = $this->declarations ?? []; ?>
<?php if ($declarations): ?>
<?php
$money = fn($v) => ($v === null || $v === '') ? '—' : number_format((float) $v);
$sum_of = fn($d, $k) => $d->{'總金額'}->{$k} ?? null;
?>
<h2 class="h5 mt-4 mb-2">財產申報 <span class="small text-body-secondary fw-normal">（監察院廉政專刊，<?= count($declarations) ?> 份）</span></h2>
<p class="small text-body-secondary mb-2">
  依申報資料記載的參選人代碼對應到本人。金額是申報表上的總金額（新臺幣元）；土地、建物、汽車是申報筆數。
  點「原始」看廉政專刊刊登的申報原文。
</p>
<div class="table-responsive">
  <table class="table table-sm table-hover small align-middle">
    <thead>
      <tr>
        <th>申報日</th>
        <th>種類</th>
        <th>服務機關／職稱</th>
        <th class="text-end">存款</th>
        <th class="text-end">有價證券</th>
        <th class="text-end">現金</th>
        <th class="text-end">債務</th>
        <th class="text-end">土地</th>
        <th class="text-end">建物</th>
        <th class="text-end">汽車</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($declarations as $d): ?>
      <?php $n = $d->{'各類筆數'} ?? new stdClass; ?>
      <tr>
        <td class="text-nowrap"><?= htmlspecialchars($d->{'申報日'} ?? '') ?></td>
        <td><?= htmlspecialchars($d->{'種類'} ?? '') ?><?php if ($d->{'申報類別'} ?? null): ?><div class="text-body-secondary"><?= htmlspecialchars($d->{'申報類別'}) ?></div><?php endif; ?></td>
        <td><?= implode('<br>', array_map(fn($o) => htmlspecialchars(trim(($o->{'服務機關'} ?? '') . ' ' . ($o->{'職稱'} ?? ''))), $d->{'服務機關紀錄'} ?? [])) ?></td>
        <td class="text-end"><?= $money($sum_of($d, '存款')) ?></td>
        <td class="text-end"><?= $money($sum_of($d, '有價證券')) ?></td>
        <td class="text-end"><?= $money($sum_of($d, '現金')) ?></td>
        <td class="text-end"><?= $money($sum_of($d, '債務')) ?></td>
        <td class="text-end"><?= (int) ($n->{'土地'} ?? 0) ?: '—' ?></td>
        <td class="text-end"><?= (int) ($n->{'建物'} ?? 0) ?: '—' ?></td>
        <td class="text-end"><?= (int) ($n->{'汽車'} ?? 0) ?: '—' ?></td>
        <td class="text-nowrap"><a href="<?= htmlspecialchars($d->{'來源'}->{'官方網址'} ?? '#') ?>" target="_blank" rel="noopener">原始</a>
          <span class="text-body-secondary"><?= htmlspecialchars(($d->{'來源'}->{'刊物'} ?? '') . ' p.' . ($d->{'來源'}->{'頁碼'} ?? '')) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
