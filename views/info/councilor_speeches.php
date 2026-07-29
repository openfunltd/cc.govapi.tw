<?php
// ES highlight 片段含 <em>...</em>，其餘文字要跳脫，避免把逐字稿內容當 HTML 注入
function safe_highlight($raw) {
    $parts = preg_split('/(<em>|<\/em>)/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    return implode('', array_map(function ($part) {
        return ($part === '<em>' || $part === '</em>') ? $part : htmlspecialchars($part);
    }, $parts));
}

$records = $this->councilor_records ?? [];
if (!$records): ?>
<div class="alert alert-warning mt-4">找不到議員資料</div>
<?php return; endif;

$person_code = $records[0]->{'人物代碼'} ?? '';
$term = $this->speech_term ?? null;
$pattern = $this->speech_pattern ?? '';
$total = $this->speech_total ?? 0;
$groups = $this->speech_groups ?? [];
?>

<div class="alert alert-warning small">
  ⚠️ 這是關鍵字比對（依「姓＋職稱＋名」猜測逐字稿裡的說話者標記），<strong>不是精確的逐句發言記錄</strong>，
  可能有漏抓或誤判。之後逐字稿清整成一句一句的格式後，會有更準確的做法。
</div>

<?php if (count($records) > 1): ?>
<div class="mb-3">
  <span class="small text-body-secondary me-2">選擇屆次：</span>
  <?php foreach ($records as $r): ?>
  <a href="/info/councilor/<?= urlencode($person_code) ?>/speeches?term=<?= (int)$r->{'屆次'} ?>"
     class="badge <?= ((int)$r->{'屆次'} === (int)$term) ? 'bg-primary' : 'bg-light text-dark border' ?> me-1">
    第 <?= (int)$r->{'屆次'} ?> 屆
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$term): ?>
<div class="alert alert-light border">找不到可查詢的屆期資料</div>
<?php else: ?>

<p class="small text-body-secondary">
  第 <?= (int)$term ?> 屆・比對關鍵字：<code><?= htmlspecialchars($pattern) ?></code>・共找到 <?= $total ?> 筆場次紀錄，來自 <?= count($groups) ?> 個會期（依日期新到舊排列）
</p>

<?php if (empty($groups)): ?>
<div class="alert alert-light border">這屆沒有比對到符合的逐字稿片段</div>
<?php else: ?>
<?php foreach ($groups as $group): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center justify-content-between">
    <strong class="small"><?= htmlspecialchars($group->{'會期名稱'}) ?></strong>
    <span class="text-body-secondary small"><?= count($group->items) ?> 個場次有提到</span>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($group->items as $t): ?>
    <?php
      $highlight = $t->{'內容:highlight'} ?? [];
      $href = '/info/' . (int)$term . '/transcript/' . urlencode($t->{'代碼'});
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="list-group-item list-group-item-action">
      <div class="small text-body-secondary mb-1">
        <?= htmlspecialchars($t->{'日期'} ?? '') ?>
        <?php if ($t->{'場次名稱'} ?? null): ?>
        ・<?= htmlspecialchars($t->{'場次名稱'}) ?>
        <?php endif; ?>
      </div>
      <?php foreach (array_slice($highlight, 0, 2) as $h): ?>
      <div class="small"><?= safe_highlight($h) ?></div>
      <?php endforeach; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
