<?php if ($this->has_villages ?? false): ?>
<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link py-1<?= $this->agency_section === 'agencies' ? ' active' : '' ?>" href="/info/<?= (int) $this->term_no ?>/agencies">縣市政府機關</a></li>
  <li class="nav-item"><a class="nav-link py-1<?= $this->agency_section === 'villages' ? ' active' : '' ?>" href="/info/<?= (int) $this->term_no ?>/agencies/villages">村里長</a></li>
</ul>
<?php endif; ?>
<?php if (($this->agency_section ?? '') === 'villages'): ?>
<?= $this->partial('info/villages', $this) ?>
<?php return; endif; ?>
<?php
$tree = $this->agency_tree ?? null;

$head_text = fn($rec) => $rec ? htmlspecialchars(($rec->{'職稱'} ?? '') . ' ' . ($rec->{'姓名'} ?? '')) : '';

// 一個機關的卡片：名稱、本屆最新首長（逐字稿彙整）、底下的所屬機關
$agency_card_html = function ($a) use ($head_text) {
    $html = '<div class="card h-100 shadow-sm"><div class="card-body p-2">';
    $html .= '<div class="fw-semibold">' . htmlspecialchars($a->{'名稱'} ?? '') . '</div>';
    if ($a->_head) {
        $html .= '<div class="small">' . $head_text($a->_head) . '</div>';
    }
    if ($a->_children) {
        $html .= '<details class="small mt-1"><summary class="text-body-secondary">所屬機關 ' . count($a->_children) . ' 個</summary><ul class="mb-0 ps-3">';
        foreach ($a->_children as $c) {
            $html .= '<li>' . htmlspecialchars($c->{'名稱'} ?? '')
                . ($c->_head ? ' <span class="text-body-secondary">（' . $head_text($c->_head) . '）</span>' : '') . '</li>';
        }
        $html .= '</ul></details>';
    }
    $html .= '</div></div>';
    return $html;
};
?>
<?php if (!$tree): ?>
<div class="alert alert-light border">目前尚無這個議會監督的行政機關資料</div>
<?php else: ?>
<div class="alert alert-light border small mb-4">
  議會監督的縣市政府機關，順序依縣市政府網站上的組織架構。縣市長是中選會的選舉當選人；各機關首長是從本屆
  會議逐字稿裡最後一次以首長職稱在議會答詢的紀錄取得，不是官方人事資料，可能是代理首長。
</div>

<?php if ($gov = $tree['縣市政府']): ?>
<div class="card shadow-sm mb-4 border-primary">
  <div class="card-body py-2">
    <div class="fw-semibold fs-5"><?= htmlspecialchars($gov->{'名稱'}) ?></div>
    <?php if ($gov->_mayor): ?>
    <div><?= htmlspecialchars($gov->_mayor->{'職稱'}) ?>
      <?php if ($gov->_mayor->_person_code ?? null): ?>
      <a href="/info/candidate/<?= urlencode($gov->_mayor->_person_code) ?>"><?= htmlspecialchars($gov->_mayor->{'姓名'}) ?></a>
      <?php else: ?>
      <?= htmlspecialchars($gov->_mayor->{'姓名'}) ?>
      <?php endif; ?>
      <span class="small text-body-secondary">（<?= (int) $gov->_mayor->{'年份'} ?>年當選<?= ($gov->_mayor->{'政黨'} ?? null) ? '・' . htmlspecialchars($gov->_mayor->{'政黨'}) : '' ?>）</span>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php foreach (['一級內部單位' => '縣市政府的內部單位', '一級外部單位' => '縣市政府所屬的一級機關'] as $category => $desc): ?>
<?php if (!empty($tree[$category])): ?>
<h2 class="h6 fw-semibold mb-1"><?= htmlspecialchars($category) ?> <span class="fw-normal text-body-secondary small">（<?= htmlspecialchars($desc) ?>，<?= count($tree[$category]) ?> 個）</span></h2>
<div class="row g-2 mb-4">
  <?php foreach ($tree[$category] as $a): ?>
  <div class="col-12 col-md-6 col-lg-4"><?= $agency_card_html($a) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if (!empty($tree['其他單位'])): ?>
<h2 class="h6 fw-semibold mb-2">其他單位</h2>
<?php foreach ($tree['其他單位'] as $kind => $list): ?>
<details class="mb-2">
  <summary><?= htmlspecialchars($kind) ?>（<?= count($list) ?> 個）</summary>
  <div class="row g-2 mt-1 mb-2">
    <?php foreach ($list as $a): ?>
    <div class="col-12 col-md-6 col-lg-4"><?= $agency_card_html($a) ?></div>
    <?php endforeach; ?>
  </div>
</details>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
