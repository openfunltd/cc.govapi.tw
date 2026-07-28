<?php
$session = $this->session_meta ?? null;
$status = $this->session_status ?? null;
$sittings = $this->session_sittings ?? [];
?>

<div class="row g-3">
  <div class="col-md-8">
    <?php if (!$session): ?>
    <div class="alert alert-light border">本屆尚無會期資料</div>
    <?php else: ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <div>
          <?php if ($status): ?><span class="status-dot <?= $status ?>"></span><?php endif; ?>
          <strong><?= htmlspecialchars($session->{'會期名稱'} ?? '') ?></strong>
          <?php if ($session->{'開始日期'} ?? null): ?>
          <span class="text-body-secondary small ms-1"><?= htmlspecialchars($session->{'開始日期'}) ?> ~ <?= htmlspecialchars($session->{'結束日期'} ?? '進行中') ?></span>
          <?php endif; ?>
        </div>
        <div>
          <?php if ($status === 'ongoing'): ?>
          <span class="badge bg-success">進行中</span>
          <?php elseif ($status === 'ended'): ?>
          <span class="badge bg-secondary">已結束</span>
          <?php endif; ?>
        </div>
      </div>
      <?php if (empty($sittings)): ?>
      <div class="card-body text-body-secondary small">本會期尚無場次資料</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>日期</th>
              <th>星期</th>
              <th>時段</th>
              <th>類別</th>
              <th>內容</th>
              <th>逐字稿</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sittings as $s): ?>
            <tr>
              <td class="text-nowrap"><?= htmlspecialchars($s->{'日期'} ?? '') ?></td>
              <td><?= htmlspecialchars($s->{'星期'} ?? '') ?></td>
              <td><?= htmlspecialchars($s->{'時段'} ?? '全天') ?></td>
              <td><?= htmlspecialchars($s->{'場次類別'} ?? '') ?></td>
              <td class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($s->{'委員會名稱'} ?? $s->{'議程說明'} ?? '') ?></td>
              <td class="text-center">
                <?php if (!empty($this->sittings_with_transcript[$s->{'代碼'}])): ?>
                <a href="/info/<?= $this->term_no ?>/transcript/<?= urlencode($s->{'代碼'}) ?>" title="查看逐字稿">📄</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-header py-2"><strong class="small">本屆其他會期</strong></div>
      <?php if (empty($this->all_sessions)): ?>
      <div class="card-body text-body-secondary small">尚無會期資料</div>
      <?php else: ?>
      <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
        <?php foreach ($this->all_sessions as $s): ?>
        <?php $is_current = $session && ($s->{'代碼'} === ($session->{'代碼'} ?? null)); ?>
        <a href="/info/<?= $this->term_no ?>/sessions/<?= urlencode($s->{'代碼'}) ?>"
           class="list-group-item list-group-item-action small <?= $is_current ? 'active' : '' ?>">
          <?= htmlspecialchars($s->{'會期名稱'} ?? $s->{'代碼'}) ?>
          <?php if (!empty($this->sessions_with_transcript[$s->{'代碼'}])): ?>
          <span title="有逐字稿">📄</span>
          <?php endif; ?>
          <div class="<?= $is_current ? '' : 'text-body-secondary' ?>" style="font-size:0.75rem;">
            <?= htmlspecialchars($s->{'開始日期'} ?? '') ?> ~ <?= htmlspecialchars($s->{'結束日期'} ?? '') ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
