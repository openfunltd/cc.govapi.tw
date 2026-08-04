<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4">找不到議員資料</div>
<?php return; endif;

// 記錄已依屆次由新到舊排序，第一筆當作「目前/最近」資訊來源
$latest = $records[0];
?>

<div class="row g-4">
  <div class="col-md-3">
    <?php if ($latest->{'照片'} ?? null): ?>
    <img src="<?= htmlspecialchars($latest->{'照片'}) ?>" alt="" class="img-fluid rounded shadow-sm" style="width:100%; aspect-ratio:3/4; object-fit:cover;">
    <?php else: ?>
    <div class="d-flex align-items-center justify-content-center bg-secondary bg-opacity-10 rounded" style="aspect-ratio:3/4; font-size:3rem;">🧑</div>
    <?php endif; ?>
  </div>
  <div class="col-md-9">
    <p class="text-body-secondary mb-2">
      <?= htmlspecialchars($latest->{'議會代碼'} ?? '') ?>
      第 <?= htmlspecialchars($latest->{'屆次'} ?? '') ?> 屆
      <?php if (($latest->{'職稱'} ?? '議員') !== '議員'): ?>
      <span class="badge bg-warning text-dark"><?= htmlspecialchars($latest->{'職稱'}) ?></span>
      <?php endif; ?>
      <?= info_election_status_badge($latest) ?>
      ・<?= htmlspecialchars($latest->{'黨籍'} ?? '—') ?>
      ・<?= htmlspecialchars(info_district_label($latest) ?: '—') ?>
    </p>
    <p class="small text-body-secondary mb-3">共任職 <?= count($records) ?> 屆</p>

    <?php if ($latest->{'簡歷'} ?? null): ?>
    <h2 class="h6 fw-semibold">簡歷</h2>
    <p class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($latest->{'簡歷'}) ?></p>
    <?php endif; ?>

    <?php if ($latest->{'學歷'} ?? null): ?>
    <h2 class="h6 fw-semibold">學歷</h2>
    <p class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($latest->{'學歷'}) ?></p>
    <?php endif; ?>

    <?php
    $contacts = array_filter([
        '電話' => $latest->{'聯絡電話'} ?? null,
        '信箱' => $latest->{'電子信箱'} ?? null,
        '通訊處' => $latest->{'辦公地址'} ?? null,
    ], fn($v) => $v && $v !== '無');
    ?>
    <?php if ($contacts): ?>
    <h2 class="h6 fw-semibold">聯絡資訊<span class="text-body-secondary fw-normal small">（最新一屆）</span></h2>
    <ul class="small list-unstyled mb-0">
      <?php foreach ($contacts as $label => $value): ?>
      <li><?= $label ?>：<?= htmlspecialchars($value) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<h2 class="h5 fw-semibold mb-3 mt-4">歷屆紀錄</h2>
<div class="table-responsive">
  <table class="table table-sm">
    <thead class="table-light">
      <tr>
        <th>屆次</th>
        <th>議會</th>
        <th>黨籍</th>
        <th>職稱</th>
        <th>選區／區域</th>
        <th>當選狀態</th>
        <th>得票數</th>
        <th>得票率</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($records as $r): ?>
      <tr>
        <td>
          <a href="/info/<?= (int)$r->{'屆次'} ?>/councilors">第 <?= htmlspecialchars($r->{'屆次'} ?? '') ?> 屆</a>
        </td>
        <td><?= htmlspecialchars($r->{'議會代碼'} ?? '') ?></td>
        <td><?= htmlspecialchars($r->{'黨籍'} ?? '—') ?></td>
        <td><?= htmlspecialchars($r->{'職稱'} ?? '') ?></td>
        <td><?= htmlspecialchars(info_district_label($r) ?: '—') ?></td>
        <td><?= info_election_status_badge($r) ?: '—' ?></td>
        <td><?= ($r->{'得票數'} ?? null) !== null ? number_format($r->{'得票數'}) : '—' ?></td>
        <td><?= ($r->{'得票率'} ?? null) !== null ? htmlspecialchars($r->{'得票率'}) . '%' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
