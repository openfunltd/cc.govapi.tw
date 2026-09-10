<?php
$session = $this->session_meta ?? null;
$status = $this->session_status ?? null;
$sittings = $this->session_sittings ?? [];
$meets_by_sitting = $this->meets_by_sitting ?? [];
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
          <?php if ($session->{'來源網址'} ?? null): ?>
          <a class="small ms-1" href="<?= htmlspecialchars($session->{'來源網址'}) ?>" target="_blank" rel="noopener">議會官網公告頁 →</a>
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
              <th class="text-center">速記</th>
              <th class="text-center">逐字稿</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sittings as $s): ?>
            <?php
              $code = $s->{'代碼'};
              $meets = $meets_by_sitting[$code] ?? [];
              // 沒有meet資料的場次（例如尚未涵蓋新pipeline的縣市）還是照樣列出一行，
              // 只是沒有速記/逐字稿勾勾、也沒有連結可點；有分組審查時一個場次代碼
              // 對應多筆meet，每筆各自渲染一列（同一天的日期/星期/時段會重複出現）
              $rows = $meets ?: [null];
            ?>
            <?php foreach ($rows as $meet): ?>
            <?php
              $content = ($meet->{'委員會或主旨'} ?? null) ?: ($s->{'委員會名稱'} ?? $s->{'議程說明'} ?? '');
              $meet_url = $meet ? ('/info/' . $this->term_no . '/meet/' . urlencode($meet->{'代碼'})) : null;
            ?>
            <tr>
              <td class="text-nowrap"><?= htmlspecialchars($s->{'日期'} ?? '') ?></td>
              <td><?= htmlspecialchars($s->{'星期'} ?? '') ?></td>
              <td>
                <?= htmlspecialchars($s->{'時段'} ?? '全天') ?>
                <?php if ($meet && (($meet->{'開始時間'} ?? null) || ($meet->{'結束時間'} ?? null))): ?>
                <div class="small text-body-secondary text-nowrap"><?= htmlspecialchars($meet->{'開始時間'} ?? '') ?>～<?= htmlspecialchars($meet->{'結束時間'} ?? '') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($s->{'場次類別'} ?? '') ?></td>
              <td class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($content) ?></td>
              <td class="text-center">
                <?php if ($meet && ($meet->{'有速記'} ?? false)): ?>
                <a href="<?= htmlspecialchars($meet_url) ?>" title="有速記/摘要式紀錄">✓</a>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($meet && ($meet->{'有逐字稿'} ?? false)): ?>
                <a href="<?= htmlspecialchars($meet_url) ?>" title="有逐字對話紀錄">✓</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
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
