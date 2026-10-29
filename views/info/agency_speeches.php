<?php
$a = $this->agency;
$term_no = (int) $this->term_no;
$sp = $this->agency_speeches;
$children = $this->agency_children ?? [];
$base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'}) . '/speeches';
$speech_url = function ($override) use ($sp, $base, $children) {
    $q = array_merge(['children' => $sp['children'] ? '1' : '0', 'page' => 1], $override);
    if (!$children) unset($q['children']);
    if ((int) $q['page'] === 1) unset($q['page']);
    return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''));
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
  <span class="text-body-secondary"><?= htmlspecialchars(CCAPI_Council::termLabel($a->{'議會代碼'} ?? '', $term_no)) ?>・共 <?= number_format($sp['total']) ?> 筆發言（依日期新到舊）</span>
  <?php if ($children): ?>
  <a class="btn btn-sm btn-outline-secondary ms-auto<?= $sp['children'] ? ' active' : '' ?>" href="<?= $speech_url(['children' => $sp['children'] ? '0' : '1']) ?>"><?= $sp['children'] ? '☑' : '☐' ?> 含所屬機關</a>
  <?php endif; ?>
</div>
<?php if ($sp['speakers']): ?>
<p class="small mb-3"><span class="text-body-secondary">發言官員：</span>
  <?= implode('、', array_map(fn($b) => htmlspecialchars($b->{'姓名'}) . '<span class="text-body-secondary">（' . (int) $b->count . '）</span>', $sp['speakers'])) ?>
</p>
<?php endif; ?>

<?php if (!$sp['groups']): ?>
<div class="alert alert-light border">
  <?= $sp['no_coverage'] ? '本議會的會議逐字稿尚未收錄，不代表這個機關沒有在議會發言。' : '這一屆的會議逐字稿裡沒有找到這個機關的發言。' ?>
</div>
<?php else: ?>
<?php
// 「議員好。」「是。」這類問候、簡短應答不列出，只列有內容的發言；整場都是這類應答的會議也不列
$is_brief = fn($t) => mb_strlen(preg_replace('/[\s\p{P}]/u', '', $t->{'發言內容'} ?? '')) <= 4
    || preg_match('/^(各位)?(議員|主席|召集人|大家)(您)?(好|早|早安|午安)[\s\p{P}]*$/u', $t->{'發言內容'} ?? '');
?>
<?php foreach ($sp['groups'] as $g): ?>
<?php $main = array_values(array_filter($g->items, fn($t) => !$is_brief($t))); ?>
<?php if (!$main) continue; ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 small">
    <a href="/info/<?= $term_no ?>/meet/<?= urlencode($g->{'會議代碼'}) ?>" class="fw-semibold"><?= htmlspecialchars($g->{'日期'}) ?>・<?= htmlspecialchars($g->{'會議主旨'} ?? '會議') ?></a>
    <span class="text-body-secondary ms-1"><?= htmlspecialchars($g->{'會期名稱'}) ?></span>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($main as $t): ?>
    <a href="/info/<?= $term_no ?>/meet/<?= urlencode($t->{'會議代碼'}) ?>#speech-<?= urlencode($t->{'代碼'}) ?>" class="list-group-item list-group-item-action">
      <div class="small text-body-secondary mb-1">
        <?= htmlspecialchars(trim(($t->{'職稱'} ?? '') . ' ' . ($t->{'姓名'} ?? ''))) ?>
        <?php if ($sp['children'] && ($t->{'對應單位全名'} ?? null)): ?>・<?= htmlspecialchars($t->{'對應單位全名'}) ?><?php endif; ?>
      </div>
      <div class="small"><?= htmlspecialchars(mb_substr($t->{'發言內容'} ?? '', 0, 120)) ?><?= mb_strlen($t->{'發言內容'} ?? '') > 120 ? '…' : '' ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if ($sp['total_page'] > 1): ?>
<div class="d-flex align-items-center gap-2 small">
  <a class="btn btn-sm btn-outline-secondary<?= $sp['page'] <= 1 ? ' disabled' : '' ?>" href="<?= $speech_url(['page' => max(1, $sp['page'] - 1)]) ?>">上一頁</a>
  <span>第 <?= number_format($sp['page']) ?> / <?= number_format($sp['total_page']) ?> 頁</span>
  <a class="btn btn-sm btn-outline-secondary<?= $sp['page'] >= $sp['total_page'] ? ' disabled' : '' ?>" href="<?= $speech_url(['page' => $sp['page'] + 1]) ?>">下一頁</a>
</div>
<?php endif; ?>
<?php endif; ?>
