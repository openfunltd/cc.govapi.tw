<!DOCTYPE html>
<html lang="zh-tw">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <?php
    $og_title = $this->og_title ?? '議會資訊 — 地方議會開放 API';
    $og_description = $this->og_description ?? '台灣各縣市議會的議員、會期、議案、逐字稿等開放資料。';
    $og_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'all.cc.govapi.tw') . ($_SERVER['REQUEST_URI'] ?? '/');
  ?>
  <title><?= htmlspecialchars($og_title) ?></title>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="cc.govapi.tw">
  <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
  <meta property="og:url" content="<?= htmlspecialchars($og_url) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="description" content="<?= htmlspecialchars($og_description) ?>">
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

<?php
// 卡片上方已經顯示「第 X 屆」，會期名稱開頭的「第X屆」是重複資訊，這裡顯示時拿掉
function info_strip_term_prefix($name) {
    return preg_replace('/^第\d+屆/u', '', $name ?? '');
}

// 選區顯示：優先用「選區別」（例：基隆市第3選舉區），較舊資料這欄位可能是空的
// 或只是「區域」這個佔位字串（資料品質問題），這種情況就退回用「區域」（縣市名）
function info_district_label($record) {
    $district = $record->{'選區別'} ?? '';
    if ($district === '' || $district === '區域') {
        return $record->{'區域'} ?? '';
    }
    return $district;
}

// 非直接當選（遞補、補選當選）時顯示提示 badge，一般當選或資料缺漏（較舊資料）不顯示
function info_election_status_badge($record) {
    $status = $record->{'當選狀態'} ?? '';
    if ($status === '' || $status === '當選') {
        return '';
    }
    return '<span class="badge bg-info text-dark">' . htmlspecialchars($status) . '</span>';
}

// 候選人學歷/經歷/政見是否為可用文字：text=公報文字層；cell-image-vision=AI 視覺
// 模型辨識裁切後的欄位圖片得出的文字（不是文字層，但仍可當文字用）；text-garbled
// （文字層是亂碼）跟缺值（圖片或空白）都不算可用
function info_candidate_text_ok($source) {
    return in_array($source, ['text', 'cell-image-vision'], true);
}

// 候選人學歷/經歷/政見欄位的共用渲染：可用文字時顯示文字；來源是 cell-image-vision
// 且有對應的欄位裁切圖時，多顯示一個「查看原圖」切換鈕（跟 .ocr-toggle-btn 的
// click delegation 配對，見本檔案下面的 <script>），方便使用者核對辨識文字跟
// 原圖是否一致。$fallback_image_field 只有「政見」會傳（政見圖路徑），沒有可用
// 文字時退回顯示整欄政見圖片，或顯示「無資料」；學歷/經歷沒有這個 fallback，
// 沒有可用文字時整段（含標題）都不顯示
function info_candidate_field_html($tag, $label, $c, $field, $fallback_image_field = null) {
    $source = $c->{$field . '來源'} ?? null;
    $text = $c->{$field} ?? null;
    $ocr_image = $c->{'欄位圖片'}->{$field} ?? null;
    $has_toggle = ($source === 'cell-image-vision') && $ocr_image;

    if (info_candidate_text_ok($source) && $text) {
        $html = "<div class=\"ocr-field\"><{$tag} class=\"h6 fw-semibold mb-1\">" . htmlspecialchars($label);
        if ($has_toggle) {
            $html .= ' <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ocr-toggle-btn">查看原圖</button>';
        }
        $html .= "</{$tag}>";
        $html .= '<p class="small ocr-text" style="white-space: pre-wrap;">' . htmlspecialchars($text) . '</p>';
        if ($has_toggle) {
            $html .= '<img class="ocr-image d-none border rounded" style="max-width: 100%; max-height: 240px;" src="' . htmlspecialchars($ocr_image) . '" alt="' . htmlspecialchars($label) . '原圖">';
        }
        return $html . '</div>';
    }

    if (!$fallback_image_field) {
        return '';
    }

    $html = "<{$tag} class=\"h6 fw-semibold mb-1\">" . htmlspecialchars($label) . "</{$tag}>";
    if ($c->{$fallback_image_field} ?? null) {
        $html .= '<img src="' . htmlspecialchars($c->{$fallback_image_field}) . '" alt="' . htmlspecialchars($label) . '" class="img-fluid border rounded">';
    } else {
        $html .= '<p class="small text-body-secondary">（無' . htmlspecialchars($label) . '資料）</p>';
    }
    return $html;
}
?>
<script>
// 候選人學歷/經歷/政見「查看原圖」切換鈕共用邏輯（見 info_candidate_field_html()）：
// 用 event delegation 掛在 document 上，不管按鈕是從哪個 partial（candidate.php／
// councilor_elections.php）渲染出來的都能生效，不用每個 partial 各自重複寫一份
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.ocr-toggle-btn');
  if (!btn) return;
  var wrap = btn.closest('.ocr-field');
  var text = wrap.querySelector('.ocr-text');
  var img = wrap.querySelector('.ocr-image');
  var showingImage = !img.classList.contains('d-none');
  text.classList.toggle('d-none');
  img.classList.toggle('d-none');
  btn.textContent = showingImage ? '查看原圖' : '查看文字';
});
</script>
<main>
  <div class="container" style="max-width: 1100px;">

    <?php if (!empty($this->is_search)): ?>
    <?= $this->partial('info/search', $this) ?>
    <?php elseif (!empty($this->is_councilor_profile)): ?>
    <?= $this->partial('info/councilor', $this) ?>
    <?php elseif (!empty($this->is_candidate_profile)): ?>
    <?= $this->partial('info/candidate', $this) ?>
    <?php else: ?>

    <form action="/info/search" method="get" class="pt-3 pb-1">
      <input type="hidden" name="tab" value="transcript">
      <div class="input-group">
        <span class="input-group-text">🔍</span>
        <input type="text" name="q" class="form-control" placeholder="搜尋逐字稿關鍵字…">
        <button class="btn btn-outline-primary" type="submit">搜尋逐字稿</button>
      </div>
    </form>

    <?php if ($this->cc_code === 'all'): ?>

    <div class="pt-4 pb-3">
      <h1 class="h3 fw-semibold mb-1">🏛 全國議會資訊</h1>
      <p class="text-body-secondary mb-4">各議會目前的屆期、議長、議員人數與最近會期概況，點進議會名稱看更多資料。</p>

      <?php
        $overviews_by_code = [];
        foreach (($this->overviews ?? []) as $o) {
            $overviews_by_code[$o->{'代碼'}] = $o;
        }
      ?>

      <?php foreach (CouncilHelper::getRegions() as $region_name => $region_codes): ?>
      <h2 class="h5 fw-semibold mb-3"><?= htmlspecialchars($region_name) ?></h2>
      <div class="row g-3 mb-4">
        <?php foreach ($region_codes as $code): ?>
        <?php
          $o = $overviews_by_code[$code] ?? null;
          if (!$o) continue;
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
                <?php if ($status === 'ongoing' || $status === 'ended'): ?>
                  最新會期：<?= htmlspecialchars(info_strip_term_prefix($session->{'會期名稱'} ?? '')) ?>
                  <?php if ($session->{'開始日期'} ?? null): ?>
                  <span class="text-body-secondary">
                    （<?= htmlspecialchars($session->{'開始日期'}) ?> ~ <?= $status === 'ongoing' ? '進行中' : htmlspecialchars($session->{'結束日期'} ?? '') ?>）
                  </span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-body-secondary">目前無會期資料</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <?= $this->partial('info/detail', $this) ?>
    <?php endif; ?>

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
