<?php
$a = $this->agenda_detail ?? null;
$sr = $this->speech_result ?? null;

// 「參與議員結構」有人物代碼時連到議員個人頁，沒有就顯示純文字姓名（比照 bill.php
// 的 bill_person_links()）
function agenda_person_links($structured) {
    if (empty($structured)) {
        return null;
    }
    $parts = [];
    foreach ($structured as $p) {
        $name = htmlspecialchars($p->{'姓名'} ?? '');
        $person_code = $p->{'人物代碼'} ?? null;
        $parts[] = $person_code
            ? '<a href="/info/councilor/' . urlencode($person_code) . '">' . $name . '</a>'
            : $name;
    }
    return implode('、', $parts);
}

// 發言者姓名（議員且比對到人物代碼時連到議員個人頁；機關發言優先顯示對應單位全名）
function speech_speaker_html($s) {
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

// 民代（議員）：有照片就用照片，沒有就用姓名首字當佔位頭像；非民代（機關／官員）
// 目前一律用機關佔位圖示，之後補上真實頭像時只要在這裡換掉即可
function speech_avatar_html($s) {
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

function agenda_page_url($page) {
    return '?page=' . (int)$page;
}
?>

<?php if ($a && ($a->{'場次代碼'} ?? null)): ?>
<nav aria-label="breadcrumb" class="mb-3">
  <a href="/info/<?= $this->term_no ?>/agendas/<?= urlencode($a->{'場次代碼'}) ?>" class="text-decoration-none small">&larr; 返回本場次議程清單</a>
</nav>
<?php endif; ?>

<?php if (!$a): ?>
<div class="alert alert-light border">找不到議程資料</div>
<?php else: ?>

<h1 class="h4 fw-semibold mb-1">
  <?= htmlspecialchars($a->{'議程類型'} ?? '') ?>
  <?php if ($a->{'委員會或名稱'} ?? null): ?>
  <span class="badge bg-secondary"><?= htmlspecialchars($a->{'委員會或名稱'}) ?></span>
  <?php endif; ?>
</h1>
<p class="text-body-secondary small mb-3">
  <?= htmlspecialchars($this->council_name ?? '') ?>
  <?php if ($a->{'時間資訊'} ?? null): ?>
  ・<?= htmlspecialchars($a->{'時間資訊'}) ?>
  <?php endif; ?>
  <?php if ($a->{'質詢對象機關'} ?? null): ?>
  ・質詢對象：<?= htmlspecialchars($a->{'質詢對象機關'}) ?>
  <?php endif; ?>
  <?php $people_html = agenda_person_links($a->{'參與議員結構'} ?? []); ?>
  <?php if ($people_html): ?>
  ・參與議員：<?= $people_html ?>
  <?php endif; ?>
</p>

<?php if (!empty($a->{'小節清單'})): ?>
<div class="mb-3">
  <div class="small text-body-secondary mb-1">本議程小節：</div>
  <?php foreach ($a->{'小節清單'} as $sec): ?>
  <?php $sec_page = intdiv((int)($sec->{'起始順序'} ?? 0), (int)($sr->limit ?? 500)) + 1; ?>
  <a href="<?= htmlspecialchars(agenda_page_url($sec_page)) ?>" class="badge bg-light text-dark border me-1 mb-1 text-decoration-none">
    <?= htmlspecialchars($sec->{'名稱'} ?? '') ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
/* 逐句發言：比照 SayIt 平台「發言者頭像＋內容」的呈現方式，額外把「民代」（議員）
   放左邊、「非民代」（機關／官員，頭像之後再補）放右邊，方便一眼區分發言者身分 */
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

<div class="small text-body-secondary mb-2">共 <?= (int)($sr->total ?? 0) ?> 筆發言</div>

<?php if (empty($sr->speeches)): ?>
<div class="alert alert-light border">本議程尚無逐句發言資料</div>
<?php else: ?>

<div>
  <?php foreach ($sr->speeches as $s): ?>
  <?php $is_rep = ($s->{'對應代碼類型'} ?? null) === '議員'; /* 民代放左邊、非民代放右邊 */ ?>
  <?php
    // 「來源頁碼」是 PDF 檔案實際的頁碼位置（#page= 導覽要用這個，已用真實 PDF 驗證過：
    // 檔案內來源頁碼跟 pdftotext 撈出來的頁面內容吻合），可能是「1112-1113」這種範圍格式，
    // #page= 只能吃單一整數，取第一個數字；「印刷頁碼」是印在頁面上、人看的頁碼，兩者
    // 常常對不上（合訂本前面常有目錄等沒編頁的內容，兩邊會有偏移量），顯示給人看時優先用
    // 印刷頁碼，沒有才退回顯示來源頁碼；不是每個議會都有印刷頁碼（例：雲林縣目前沒有）
    $source_link = '';
    if (($s->{'來源網址'} ?? null) && ($s->{'來源頁碼'} ?? null)) {
        $nav_page = preg_replace('/[^0-9].*$/', '', (string)$s->{'來源頁碼'});
        $display_page = $s->{'印刷頁碼'} ?? $s->{'來源頁碼'};
        $source_link = ' <a class="small" href="' . htmlspecialchars($s->{'來源網址'} . '#page=' . urlencode($nav_page)) . '" target="_blank" rel="noopener">（第' . htmlspecialchars($display_page) . '頁）</a>';
    }
    // 「代碼」是這則發言在 speech index 的 doc id（來源已保證唯一），拿來當本頁錨點，
    // 方便分享時直接連到 ?page=N#speech-{代碼} 跳到這一句
    $speech_code = $s->{'代碼'} ?? '';
    $permalink = $speech_code
        ? ' <a class="small text-decoration-none" href="' . htmlspecialchars(agenda_page_url($sr->page ?? 1) . '#speech-' . $speech_code) . '" title="複製連結分享這則發言">#</a>'
        : '';
    $bubble = '<div class="speech-bubble">'
        . '<div class="small fw-semibold">' . speech_speaker_html($s) . $source_link . $permalink . '</div>'
        . '<div class="small" style="white-space: pre-wrap;">' . htmlspecialchars($s->{'發言內容'} ?? '') . '</div>'
        . '</div>';
    $avatar = speech_avatar_html($s);
  ?>
  <div class="speech-row <?= $is_rep ? 'role-rep' : 'role-other' ?>"<?= $speech_code ? ' id="speech-' . htmlspecialchars($speech_code) . '"' : '' ?>>
    <?= $is_rep ? ($avatar . $bubble) : ($bubble . $avatar) ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if (($sr->total_page ?? 0) > 1): ?>
<div class="d-flex justify-content-center align-items-center gap-2 my-3">
  <?php if ($sr->page > 1): ?>
  <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(agenda_page_url($sr->page - 1)) ?>">&larr; 上一頁</a>
  <?php else: ?>
  <button class="btn btn-sm btn-outline-secondary" disabled>&larr; 上一頁</button>
  <?php endif; ?>
  <span class="small"><?= (int)$sr->page ?> / <?= (int)$sr->total_page ?></span>
  <?php if ($sr->page < $sr->total_page): ?>
  <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(agenda_page_url($sr->page + 1)) ?>">下一頁 &rarr;</a>
  <?php else: ?>
  <button class="btn btn-sm btn-outline-secondary" disabled>下一頁 &rarr;</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($a->{'來源網址'} ?? null): ?>
<p class="text-body-secondary small">
  來源檔案：
  <a href="<?= htmlspecialchars($a->{'來源網址'}) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($a->{'來源檔案'} ?? '原始檔案') ?></a>
</p>
<?php endif; ?>

<?php endif; ?>
