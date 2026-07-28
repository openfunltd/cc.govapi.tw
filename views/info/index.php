<!DOCTYPE html>
<html lang="zh-tw">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <title><?= $this->cc_code === 'all' ? '議會資訊 — 地方議會開放 API' : htmlspecialchars($this->council_name) . ' 議會資訊' ?></title>
  <style>
    body { font-family: 'Noto Sans TC', sans-serif; }
    .status-dot { display: inline-block; width: 0.6em; height: 0.6em; border-radius: 50%; margin-right: 0.3em; }
    .status-dot.ongoing { background: #198754; }
    .status-dot.ended { background: #adb5bd; }
    .status-dot.none { background: #dee2e6; }
    .councilor-card img { width: 100%; aspect-ratio: 3/4; object-fit: cover; background: #e9ecef; }
  </style>
</head>
<body class="bg-light">
<?php
  $active = 'info';
  $cc_code = $this->cc_code;
  $council_name = $this->council_name;
  include(__DIR__ . '/../nav/top.php');
?>

<main>
  <div class="container" style="max-width: 1100px;">

    <?php if ($this->cc_code === 'all'): ?>

    <div class="pt-4 pb-3">
      <h1 class="h3 fw-semibold mb-1">🏛 全國議會資訊</h1>
      <p class="text-body-secondary mb-4">各議會目前的屆期、議長、議員人數與最近會期概況，點進議會名稱看更多資料。</p>

      <div class="row g-3">
        <?php foreach (($this->overviews ?? []) as $o): ?>
        <?php
          $postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
          $council_url = 'https://' . $o->{'代碼'} . $postfix . '/info';
          $session = $o->{'會期'} ?? null;
          $status = $session->{'狀態'} ?? 'none';
        ?>
        <div class="col-md-6 col-lg-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <h5 class="card-title mb-1">
                <a href="<?= htmlspecialchars($council_url) ?>" class="text-decoration-none"><?= htmlspecialchars($o->{'議會名稱'}) ?></a>
              </h5>
              <p class="text-body-secondary small mb-2">
                <?= $o->{'屆次'} ? '第 ' . (int)$o->{'屆次'} . ' 屆' : '無屆期資料' ?>
              </p>
              <ul class="list-unstyled small mb-3">
                <li>👤 議長：<?= htmlspecialchars($o->{'議長姓名'} ?? '—') ?></li>
                <li>🪑 議員人數：<?= is_null($o->{'議員人數'} ?? null) ? '—' : (int)$o->{'議員人數'} . ' 位' ?></li>
              </ul>
              <div class="small">
                <span class="status-dot <?= $status ?>"></span>
                <?php if ($status === 'ongoing'): ?>
                  進行中：<?= htmlspecialchars($session->{'會期名稱'} ?? '') ?>
                <?php elseif ($status === 'ended'): ?>
                  最近一次會期已結束：<?= htmlspecialchars($session->{'會期名稱'} ?? '') ?>
                  <span class="text-body-secondary">（<?= htmlspecialchars($session->{'結束日期'} ?? '') ?>）</span>
                <?php else: ?>
                  <span class="text-body-secondary">目前無會期資料</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php else: ?>
    <?= $this->partial('info/detail', $this) ?>
    <?php endif; ?>

    <?php if (CCAPI::hasLog()): ?>
    <div class="card shadow-sm my-4">
      <div class="card-body">
        <h2 class="h6 fw-semibold mb-2">本頁使用 API</h2>
        <ul class="small mb-0 ps-3">
          <?php foreach (CCAPI::getLogs() as $log): ?>
          <li><a href="<?= htmlspecialchars($log[0]) ?>" target="_blank"><?= htmlspecialchars($log[1]) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>
</body>
</html>
