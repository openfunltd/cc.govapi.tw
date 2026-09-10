<?php
$meet = $this->meet_meta ?? null;
$notes = $this->meet_notes ?? [];
$tr = $this->meet_transcript_result ?? null;
$session_code = $meet->{'會期代碼'} ?? null;

function meet_page_url($page) {
    return '?page=' . (int)$page;
}

// 發言者姓名（議員且比對到人物代碼時連到議員個人頁；機關發言優先顯示對應單位全名），
// 比照 agenda.php 的 speech_speaker_html()／speech_avatar_html()，用 meet_ 前綴
// 避免跟 agenda.php 的同名函式衝突（雖然一次 request 只會載入其中一個 partial）
function meet_speech_speaker_html($s) {
    $name = htmlspecialchars($s->{'姓名'} ?? $s->{'原始標記'} ?? '');
    $title = $s->{'職稱'} ?? null ? '（' . htmlspecialchars($s->{'職稱'}) . '）' : '';
    if (($s->{'對應代碼類型'} ?? null) === '議員') {
        $person_code = $s->{'_人物代碼'} ?? null;
        if ($person_code) {
            return '<a href="/info/councilor/' . urlencode($person_code) . '">' . $name . '</a>' . $title;
        }
    } elseif ($s->{'對應單位全名'} ?? null) {
        return htmlspecialchars($s->{'對應單位全名'}) . $title;
    }
    return $name . $title;
}

function meet_speech_avatar_html($s) {
    $name = $s->{'姓名'} ?? $s->{'原始標記'} ?? '';
    if (($s->{'對應代碼類型'} ?? null) === '議員') {
        $photo = $s->{'_照片'} ?? null;
        if ($photo) {
            return '<img class="speech-avatar" src="' . htmlspecialchars($photo) . '" alt="">';
        }
        return '<div class="speech-avatar">' . htmlspecialchars(mb_substr($name, 0, 1)) . '</div>';
    }
    return '<div class="speech-avatar">🏛</div>';
}
?>

<?php if ($session_code): ?>
<nav aria-label="breadcrumb" class="mb-3">
  <a href="/info/<?= $this->term_no ?>/sessions/<?= urlencode($session_code) ?>" class="text-decoration-none small">&larr; 返回會期</a>
</nav>
<?php endif; ?>

<?php if (!$meet): ?>
<div class="alert alert-light border">找不到會議資料</div>
<?php else: ?>

<h1 class="h5 fw-semibold mb-1">
  <?= htmlspecialchars($meet->{'日期'} ?? '') ?>
  <?php if ($meet->{'委員會或主旨'} ?? null): ?>
  <span class="badge bg-secondary"><?= htmlspecialchars($meet->{'委員會或主旨'}) ?></span>
  <?php endif; ?>
</h1>
<?php if (($meet->{'時間資訊'} ?? null) || ($meet->{'地點'} ?? null)): ?>
<p class="text-body-secondary small mb-1">
  <?= htmlspecialchars($meet->{'時間資訊'} ?? '') ?><?= ($meet->{'地點'} ?? null) ? '・' . htmlspecialchars($meet->{'地點'}) : '' ?>
</p>
<?php endif; ?>
<?php if ($meet->{'來源網址'} ?? null): ?>
<p class="text-body-secondary small mb-3">
  原始檔案：
  <a href="<?= htmlspecialchars($meet->{'來源網址'}) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($meet->{'來源檔案'} ?? '原始檔案') ?> →</a>
</p>
<?php endif; ?>

<?php
  $has_notes = !empty($notes);
  $has_transcript = !empty($meet->{'有逐字稿'});
  // 預設打開資料比較完整的那一頁籤：逐字稿優先於速記，都沒有就停在會議資訊
  $default_pane = $has_transcript ? 'transcript' : ($has_notes ? 'notes' : 'info');
?>

<ul class="nav nav-pills mb-3" id="meet-outer-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $default_pane === 'info' ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#meet-pane-info" type="button" role="tab">
      ℹ️ 會議資訊
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $default_pane === 'notes' ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#meet-pane-notes" type="button" role="tab">
      📝 速記錄<?= $has_notes ? ' (' . count($notes) . ')' : '' ?>
    </button>
  </li>
  <?php if ($has_transcript): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $default_pane === 'transcript' ? 'active' : '' ?>" data-bs-toggle="pill"
            data-bs-target="#meet-pane-transcript" type="button" role="tab">
      📄 逐字稿<?= ($tr->total ?? 0) ? ' (' . (int)$tr->total . ')' : '' ?>
    </button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content">

  <div class="tab-pane fade <?= $default_pane === 'info' ? 'show active' : '' ?>" id="meet-pane-info" role="tabpanel">
    <table class="table table-sm w-auto">
      <tbody>
        <tr><th class="text-body-secondary fw-normal">場次代碼</th><td><?= htmlspecialchars($meet->{'場次代碼'} ?? '') ?></td></tr>
        <tr><th class="text-body-secondary fw-normal">日期</th><td><?= htmlspecialchars($meet->{'日期'} ?? '') ?></td></tr>
        <?php if ($meet->{'時間資訊'} ?? null): ?>
        <tr><th class="text-body-secondary fw-normal">時間資訊</th><td><?= htmlspecialchars($meet->{'時間資訊'}) ?></td></tr>
        <?php endif; ?>
        <?php if ($meet->{'地點'} ?? null): ?>
        <tr><th class="text-body-secondary fw-normal">地點</th><td><?= htmlspecialchars($meet->{'地點'}) ?></td></tr>
        <?php endif; ?>
        <?php if ($meet->{'委員會或主旨'} ?? null): ?>
        <tr><th class="text-body-secondary fw-normal">委員會或主旨</th><td><?= htmlspecialchars($meet->{'委員會或主旨'}) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if (!$has_notes && !$has_transcript): ?>
    <div class="alert alert-light border">本場會議尚無速記或逐字稿資料</div>
    <?php endif; ?>
  </div>

  <div class="tab-pane fade <?= $default_pane === 'notes' ? 'show active' : '' ?>" id="meet-pane-notes" role="tabpanel">
    <?php if (!$has_notes): ?>
    <div class="alert alert-light border">本場會議尚無速記摘要資料</div>
    <?php else: ?>
    <?php foreach ($notes as $note): ?>
    <pre class="border rounded p-3 bg-light mb-3" style="white-space: pre-wrap; font-family: inherit;"><?= htmlspecialchars($note->{'摘要內容'} ?? '') ?></pre>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($has_transcript): ?>
  <div class="tab-pane fade <?= $default_pane === 'transcript' ? 'show active' : '' ?>" id="meet-pane-transcript" role="tabpanel">

    <?php if (!empty($meet->{'小節清單'})): ?>
    <div class="mb-3">
      <div class="small text-body-secondary mb-1">本會議小節：</div>
      <?php foreach ($meet->{'小節清單'} as $sec): ?>
      <?php $sec_page = intdiv((int)($sec->{'起始順序'} ?? 0), (int)($tr->limit ?? 500)) + 1; ?>
      <a href="<?= htmlspecialchars(meet_page_url($sec_page)) ?>" class="badge bg-light text-dark border me-1 mb-1 text-decoration-none">
        <?= htmlspecialchars($sec->{'名稱'} ?? '') ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <style>
    /* 逐句發言：跟 agenda.php 同一套呈現方式（民代放左邊、非民代放右邊） */
    .speech-row { display: flex; margin-bottom: 0.75rem; gap: 0.5rem; }
    .speech-row.role-other { justify-content: flex-end; }
    .speech-avatar {
      width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
      object-fit: cover; background-color: #adb5bd;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1rem; font-weight: 600;
    }
    .speech-bubble { max-width: 75%; background: #f1f3f5; border-radius: 0.75rem; padding: 0.5rem 0.75rem; }
    .role-other .speech-bubble { background: #e7f1ff; }
    .speech-row:target .speech-bubble { outline: 2px solid #fd7e14; }
    </style>

    <div class="small text-body-secondary mb-2">共 <?= (int)($tr->total ?? 0) ?> 筆發言</div>

    <?php if (empty($tr->meet_transcripts)): ?>
    <div class="alert alert-light border">本會議尚無逐字稿發言資料</div>
    <?php else: ?>

    <div>
      <?php foreach ($tr->meet_transcripts as $s): ?>
      <?php $is_rep = ($s->{'對應代碼類型'} ?? null) === '議員'; /* 民代放左邊、非民代放右邊 */ ?>
      <?php
        // 「來源頁碼」可能是「1112-1113」這種範圍格式，#page= 只能吃單一整數，取第一個
        // 數字；「印刷頁碼」是印在頁面上、人看的頁碼，顯示時優先用印刷頁碼，比照
        // agenda.php 的做法
        $source_link = '';
        if (($meet->{'來源網址'} ?? null) && ($s->{'來源頁碼'} ?? null)) {
            $nav_page = preg_replace('/[^0-9].*$/', '', (string)$s->{'來源頁碼'});
            $display_page = $s->{'印刷頁碼'} ?? $s->{'來源頁碼'};
            $source_link = ' <a class="small" href="' . htmlspecialchars($meet->{'來源網址'} . '#page=' . urlencode($nav_page)) . '" target="_blank" rel="noopener">（第' . htmlspecialchars($display_page) . '頁）</a>';
        }
        // 「代碼」是這則發言在 meet_transcript index 的 doc id（來源已保證唯一），
        // 拿來當本頁錨點，方便分享時直接連到 ?page=N#speech-{代碼} 跳到這一句
        $speech_code = $s->{'代碼'} ?? '';
        $permalink = $speech_code
            ? ' <a class="small text-decoration-none" href="' . htmlspecialchars(meet_page_url($tr->page ?? 1) . '#speech-' . $speech_code) . '" title="複製連結分享這則發言">#</a>'
            : '';
        $bubble = '<div class="speech-bubble">'
            . '<div class="small fw-semibold">' . meet_speech_speaker_html($s) . $source_link . $permalink . '</div>'
            . '<div class="small" style="white-space: pre-wrap;">' . htmlspecialchars($s->{'發言內容'} ?? '') . '</div>'
            . '</div>';
        $avatar = meet_speech_avatar_html($s);
      ?>
      <div class="speech-row <?= $is_rep ? 'role-rep' : 'role-other' ?>"<?= $speech_code ? ' id="speech-' . htmlspecialchars($speech_code) . '"' : '' ?>>
        <?= $is_rep ? ($avatar . $bubble) : ($bubble . $avatar) ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (($tr->total_page ?? 0) > 1): ?>
    <div class="d-flex justify-content-center align-items-center gap-2 my-3">
      <?php if ($tr->page > 1): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(meet_page_url($tr->page - 1)) ?>">&larr; 上一頁</a>
      <?php else: ?>
      <button class="btn btn-sm btn-outline-secondary" disabled>&larr; 上一頁</button>
      <?php endif; ?>
      <span class="small"><?= (int)$tr->page ?> / <?= (int)$tr->total_page ?></span>
      <?php if ($tr->page < $tr->total_page): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(meet_page_url($tr->page + 1)) ?>">下一頁 &rarr;</a>
      <?php else: ?>
      <button class="btn btn-sm btn-outline-secondary" disabled>下一頁 &rarr;</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php endif; ?>
