<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4"><?= htmlspecialchars(CouncilHelper::t('找不到議員資料')) ?></div>
<?php return; endif;

$groups = $this->election_groups ?? [];
?>

<div class="alert alert-light border small">
  <?php if (CCAPI_Council::getKind($records[0]->{'議會代碼'} ?? '') === '立法院'): ?>
  立法委員選舉資料來自中選會選舉資料庫，從第3屆（民國84年）開始，第7屆起才有不分區政黨名單；
  第3～6屆不分區、僑選委員沒有個別候選人資料。選舉公報的學經歷／政見目前只有第8屆（民國101年）起
  的區域與原住民立委，更早的公報是掃描檔還沒辨識；不分區的政見是政黨層級的，不在個人資料裡。
  <?php else: ?>
  選舉公報資料只回溯到民國98年（2009年），較舊的屆次可能查不到公報內容；
  政見如果來源是圖片或文字層是亂碼，會直接顯示原始圖片。
  <?php endif; ?>
</div>

<?php if (!$groups): ?>
<div class="alert alert-light border"><?= htmlspecialchars(CouncilHelper::t('找不到這位議員的選舉紀錄')) ?></div>
<?php else: ?>

<?php foreach ($groups as $group): ?>
<?php $c = $group->{'candidate'} ?? null; ?>
<div class="card shadow-sm mb-4">
  <div class="card-header py-2">
    <strong class="small">
      第 <?= htmlspecialchars($group->{'屆次'} ?? '') ?> 屆<?= htmlspecialchars($group->{'議員頭銜'} ?? '') ?>
      <?php if ($group->{'任期年份'} ?? null): ?>
      (<?= htmlspecialchars($group->{'任期年份'}) ?>)
      <?php endif; ?>
    </strong>
  </div>
  <div class="card-body">
    <?php if (!$c): ?>
    <div class="text-body-secondary small">找不到這屆的候選人公報資料</div>
    <?php else: ?>

    <div class="row g-3 mb-3">
      <?php if ($c->{'相片路徑'} ?? null): ?>
      <div class="col-auto">
        <img src="<?= htmlspecialchars($c->{'相片路徑'}) ?>" alt="" style="width:120px; aspect-ratio:3/4; object-fit:cover;" class="rounded shadow-sm">
      </div>
      <?php endif; ?>
      <div class="col">
        <p class="small text-body-secondary mb-2">
          <?= htmlspecialchars($c->{'選舉名稱'} ?? '') ?>
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

        <?= info_candidate_field_html('h3', '學歷', $c, '學歷') ?>
        <?= info_candidate_field_html('h3', '經歷', $c, '經歷') ?>
        <?php $party = $group->{'party'} ?? null; ?>
        <?php if (!$party || ($c->{'政見'} ?? null)): // 不分區候選人個人沒有政見，只顯示下面的政黨政見 ?>
        <?= info_candidate_field_html('h3', '政見', $c, '政見', '政見圖路徑') ?>
        <?php endif; ?>
        <?php if ($party): ?>
        <?php if ($party->{'政見'} ?? null): ?>
        <h3 class="h6 fw-semibold mt-3"><?= htmlspecialchars($party->{'政黨'} ?? '') ?> 政黨政見<span class="small fw-normal text-body-secondary ms-1">（不分區名單共用）</span></h3>
        <div class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($party->{'政見'}) ?></div>
        <?php if ($party->{'政見圖路徑'} ?? null): ?><a class="small" href="<?= htmlspecialchars($party->{'政見圖路徑'}) ?>" target="_blank" rel="noopener">查看公報原圖 →</a><?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($group->{'race'})): ?>
    <h3 class="h6 fw-semibold mb-2"><?= ($c->{'選區別'} ?? '') === '全國不分區' ? '同政黨不分區名單' : '同選區得票比較' ?></h3>
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
              <?php if ($rc->{'人物代碼'} ?? null): ?>
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

    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
