<?php
$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4">找不到議員資料</div>
<?php return; endif;

// 記錄已依選舉日期由新到舊排序；$selected是controller依URL路徑最後一段
// （/info/councilor/{人物代碼}/{屆次}）選出的那一屆，沒帶屆次或查無該屆
// 時退回第一筆（最新一屆）——見使用者回饋：某屆是遞補/卸任，但那屆剛好
// 不是最新一屆，個人頁要能切換查看才看得到那次異動的詳細資訊
$selected = $this->councilor_selected ?? $records[0];
$person_code = $records[0]->{'人物代碼'} ?? '';
// 多屆之間議會代碼可能不同（如桃園縣議會併入桃園市議會後屆次重新起算），
// 屆次數字單獨顯示會混淆，這種情況下切換鈕要連議會代碼一起標示
$spans_multiple_councils = count(array_unique(array_map(fn($r) => $r->{'議會代碼'} ?? '', $records))) > 1;
?>

<?php if (count($records) > 1): ?>
<div class="mb-3">
  <span class="text-body-secondary small me-2">查看屆期：</span>
  <?php foreach ($records as $r): ?>
  <a href="/info/councilor/<?= urlencode($person_code) ?>/<?= (int) ($r->{'屆次'} ?? 0) ?>"
     class="badge text-decoration-none me-1 <?= $r === $selected ? 'bg-primary' : 'bg-light text-dark border' ?>">
    <?php if ($spans_multiple_councils): ?><?= htmlspecialchars($r->{'議會代碼'} ?? '') ?><?php endif; ?>
    第<?= htmlspecialchars($r->{'屆次'} ?? '') ?>屆
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-md-3">
    <?php if ($selected->{'照片'} ?? null): ?>
    <img src="<?= htmlspecialchars($selected->{'照片'}) ?>" alt="" class="img-fluid rounded shadow-sm" style="width:100%; aspect-ratio:3/4; object-fit:cover;">
    <?php else: ?>
    <div class="d-flex align-items-center justify-content-center bg-secondary bg-opacity-10 rounded" style="aspect-ratio:3/4; font-size:3rem;">🧑</div>
    <?php endif; ?>
  </div>
  <div class="col-md-9">
    <p class="text-body-secondary mb-2">
      <?= htmlspecialchars($selected->{'議會代碼'} ?? '') ?>
      第 <?= htmlspecialchars($selected->{'屆次'} ?? '') ?> 屆
      <?php if (($selected->{'職稱'} ?? '議員') !== '議員'): ?>
      <span class="badge bg-warning text-dark"><?= htmlspecialchars($selected->{'職稱'}) ?></span>
      <?php endif; ?>
      <?= info_election_status_badge($selected) ?>
      <?= info_departure_status_badge($selected) ?>
      ・<?= htmlspecialchars($selected->{'黨籍'} ?? '—') ?>
      ・<?= htmlspecialchars(info_district_label($selected) ?: '—') ?>
    </p>
    <p class="small text-body-secondary mb-3">共任職 <?= count($records) ?> 屆</p>

    <?= info_departure_detail_panels($selected) ?>

    <?php if ($selected->{'簡歷'} ?? null): ?>
    <h2 class="h6 fw-semibold">簡歷</h2>
    <p class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($selected->{'簡歷'}) ?></p>
    <?php endif; ?>

    <?php if ($selected->{'學歷'} ?? null): ?>
    <h2 class="h6 fw-semibold">學歷</h2>
    <p class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($selected->{'學歷'}) ?></p>
    <?php endif; ?>

    <?php
    $contacts = array_filter([
        '電話' => $selected->{'聯絡電話'} ?? null,
        '信箱' => $selected->{'電子信箱'} ?? null,
        '通訊處' => $selected->{'辦公地址'} ?? null,
    ], fn($v) => $v && $v !== '無');
    ?>
    <?php if ($contacts): ?>
    <h2 class="h6 fw-semibold">聯絡資訊<span class="text-body-secondary fw-normal small">（這一屆）</span></h2>
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
        <th>任期</th>
        <th>黨籍</th>
        <th>職稱</th>
        <th>選區／區域</th>
        <th>當選狀態</th>
        <th>異動</th>
        <th>得票數</th>
        <th>得票率</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($records as $r): ?>
      <tr<?= $r === $selected ? ' class="table-active"' : '' ?>>
        <td>
          <a href="/info/councilor/<?= urlencode($person_code) ?>/<?= (int) ($r->{'屆次'} ?? 0) ?>">第 <?= htmlspecialchars($r->{'屆次'} ?? '') ?> 屆</a>
          <a href="/info/<?= (int) ($r->{'屆次'} ?? 0) ?>/councilors" class="text-body-secondary small ms-1" title="查看該屆議員名單">（名單）</a>
        </td>
        <td><?= htmlspecialchars($r->{'議會代碼'} ?? '') ?></td>
        <td><?= htmlspecialchars($r->{'_任期年份'} ?? '—') ?></td>
        <td><?= htmlspecialchars($r->{'黨籍'} ?? '—') ?></td>
        <td><?= htmlspecialchars($r->{'職稱'} ?? '') ?></td>
        <td><?= htmlspecialchars(info_district_label($r) ?: '—') ?></td>
        <td><?= info_election_status_badge($r) ?: '—' ?></td>
        <td><?= info_departure_status_badge($r) ?: '—' ?></td>
        <td><?= ($r->{'得票數'} ?? null) !== null ? number_format($r->{'得票數'}) : '—' ?></td>
        <td><?= ($r->{'得票率'} ?? null) !== null ? htmlspecialchars($r->{'得票率'}) . '%' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
