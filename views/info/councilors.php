<?php if (empty($this->councilors)): ?>
<div class="alert alert-light border mb-4"><?= htmlspecialchars(CouncilHelper::t('目前尚無本屆議員資料')) ?></div>
<?php else: ?>
<?php foreach ($this->councilors as $group): ?>
<h2 class="h6 fw-semibold text-body-secondary mb-2"><?= htmlspecialchars($group['label']) ?></h2>
<?php // 不分區依政黨再分一層（見 InfoController::groupCouncilorsByDistrict()），其他分組只有一層 ?>
<?php foreach ($group['subgroups'] ?? [['label' => null, 'note' => null, 'councilors' => $group['councilors']]] as $sub): ?>
<?php $is_party_list = $sub['label'] !== null; ?>
<?php if ($is_party_list): ?>
<h3 class="small fw-semibold mb-2 ms-2">
  <?= htmlspecialchars($sub['label']) ?>
  <?php if ($sub['note']): ?><span class="fw-normal text-body-secondary ms-1"><?= htmlspecialchars($sub['note']) ?></span><?php endif; ?>
</h3>
<?php if ($manifesto = ($sub['party']->{'政見'} ?? null)): ?>
<details class="small mb-2 ms-3">
  <summary class="text-body-secondary">政黨政見<?= ($sub['party']->{'政見來源'} ?? '') === 'manifesto-image-vision' ? '（公報為圖片，文字由辨識取得）' : '' ?></summary>
  <div class="mt-1" style="white-space: pre-wrap;"><?= htmlspecialchars($manifesto) ?></div>
  <?php if ($sub['party']->{'政見圖路徑'} ?? null): ?><a href="<?= htmlspecialchars($sub['party']->{'政見圖路徑'}) ?>" target="_blank" rel="noopener">查看公報原圖 →</a><?php endif; ?>
</details>
<?php endif; ?>
<?php endif; ?>
<div class="row g-3 mb-4<?= $is_party_list ? ' ms-1' : '' ?>">
  <?php foreach ($sub['councilors'] as $c): ?>
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
          <?php if (!in_array($c->{'職稱'} ?? '議員', ['議員', CouncilHelper::t('議員')], true)): ?>
          <span class="badge bg-warning text-dark"><?= htmlspecialchars($c->{'職稱'}) ?></span>
          <?php endif; ?>
          <?= info_election_status_badge($c) ?>
          <?= info_departure_status_badge($c) ?>
        </div>
        <div class="text-body-secondary" style="font-size: 0.75rem;">
          <?= htmlspecialchars($c->{'黨籍'} ?? '—') ?><br>
          <?php if ($is_party_list && ($c->{'號次'} ?? null)): ?>
          名單第 <?= (int)$c->{'號次'} ?> 名
          <?php else: ?>
          <?= htmlspecialchars(info_district_label($c) ?: '—') ?>
          <?php endif; ?>
          <?php if ($c->{'得票率'} ?? null): ?>
          <br><span class="text-primary">得票：<?= htmlspecialchars($c->{'得票率'}) ?>%</span>
          <?php elseif ($c->{'得票數'} ?? null): ?>
          <!-- 得票率需要同選區候選人完整得票數才能算出百分比，目前只有111年這次
               選舉有這份資料（見InfoController::attachVoteShare()的說明），較舊
               屆次沒有百分比可算，退而求其次顯示議員record自己帶的得票數（來源
               回溯年份廣得多），總比完全不顯示任何得票資訊好 -->
          <br><span class="text-primary">得票：<?= number_format($c->{'得票數'}) ?> 票</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php if (!empty($sub['waiting'])): ?>
<details class="mb-4 ms-3" style="margin-top:-0.75rem;">
  <summary class="small text-body-secondary">待遞補（<?= count($sub['waiting']) ?> 人）</summary>
  <div class="small mt-1">
    <?php foreach ($sub['waiting'] as $i => $cand): ?>
    <?= $i ? '、' : '' ?><?php if ($cand->{'人物代碼'} ?? null): ?><a href="/info/candidate/<?= urlencode($cand->{'人物代碼'}) ?>"><?php endif; ?><?= (int)($cand->{'號次'} ?? 0) ?>. <?= htmlspecialchars($cand->{'姓名'} ?? '') ?><?php if ($cand->{'人物代碼'} ?? null): ?></a><?php endif; ?><?php if ($g = $cand->{'其他欄位'}->{'性別'} ?? null): ?><span class="text-body-secondary">（<?= htmlspecialchars($g) ?>）</span><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="small text-body-secondary mt-1">依名單排序；遞補須維持女性不少於半數，女性委員出缺時通常由名單上下一位女性遞補，不一定照順序。</div>
</details>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
