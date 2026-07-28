<?php
$sessions = $this->timeline_sessions ?? [];
$h = $this->header ?? null;

function info_timeline_pct($date, $start_ts, $span)
{
    if (!$date || !$start_ts || !$span) return null;
    $ts = strtotime($date);
    if (!$ts) return null;
    return max(0, min(100, ($ts - $start_ts) / $span * 100));
}
?>

<?php if (empty($sessions)): ?>
<div class="alert alert-light border">本屆尚無會期資料</div>
<?php else: ?>
<?php
// 時間軸範圍：就職日 ~ 任期屆滿日（現任則用最後一筆會期結束日或今天）
$start = $h->{'就職日'} ?? $sessions[0]->{'開始日期'} ?? null;
$last_session_end = end($sessions)->{'結束日期'} ?? null;
$end = $h->{'任期屆滿日'} ?? $last_session_end ?? date('Y-m-d');
$start_ts = $start ? strtotime($start) : null;
$end_ts = $end ? strtotime($end) : null;
$span = ($start_ts && $end_ts && $end_ts > $start_ts) ? ($end_ts - $start_ts) : null;

$type_color = ['定期會' => '#0d6efd', '臨時會' => '#fd7e14', '成立大會' => '#198754'];
?>
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <?php if ($span): ?>
    <div class="position-relative mb-2" style="height: 36px; background: #f1f3f5; border-radius: 4px;">
      <?php foreach ($sessions as $s): ?>
      <?php
        $left = info_timeline_pct($s->{'開始日期'} ?? null, $start_ts, $span);
        $right = info_timeline_pct($s->{'結束日期'} ?? ($s->{'開始日期'} ?? null), $start_ts, $span);
        if (is_null($left) || is_null($right)) continue;
        $width = max($right - $left, 0.6);
        $color = $type_color[$s->{'會期類別'} ?? ''] ?? '#6c757d';
      ?>
      <a href="/info/<?= $this->term_no ?>/sessions/<?= urlencode($s->{'代碼'}) ?>"
         class="position-absolute top-0 h-100"
         title="<?= htmlspecialchars($s->{'會期名稱'} ?? '') ?>"
         style="left: <?= $left ?>%; width: <?= $width ?>%; background: <?= $color ?>; border-radius: 3px; display:block;"></a>
      <?php endforeach; ?>
    </div>
    <div class="small text-body-secondary mb-3 d-flex flex-wrap gap-3">
      <?php foreach ($type_color as $type => $color): ?>
      <span><span class="d-inline-block" style="width:10px;height:10px;background:<?= $color ?>;border-radius:2px;"></span> <?= $type ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>會期</th>
            <th>類別</th>
            <th>次</th>
            <th>開始日期</th>
            <th>結束日期</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s): ?>
          <tr>
            <td><a href="/info/<?= $this->term_no ?>/sessions/<?= urlencode($s->{'代碼'}) ?>"><?= htmlspecialchars($s->{'會期名稱'} ?? $s->{'代碼'}) ?></a></td>
            <td><?= htmlspecialchars($s->{'會期類別'} ?? '') ?></td>
            <td><?= htmlspecialchars($s->{'次'} ?? '') ?></td>
            <td><?= htmlspecialchars($s->{'開始日期'} ?? '') ?></td>
            <td><?= htmlspecialchars($s->{'結束日期'} ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
