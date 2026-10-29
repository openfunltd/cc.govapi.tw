<?php
$a = $this->agency;
$term_no = (int) $this->term_no;
$n = $this->agency_news;
$children = $this->agency_children ?? [];
$base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'}) . '/news';
$news_url = function ($override) use ($n, $base, $children) {
    $q = array_merge(['q' => $n['keyword'], 'children' => $n['children'] ? '1' : '0', 'page' => 1], $override);
    if ($q['q'] === '') unset($q['q']);
    if (!$children) unset($q['children']);
    if ((int) $q['page'] === 1) unset($q['page']);
    return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''));
};
?>
<form method="get" action="<?= htmlspecialchars($base) ?>" class="input-group input-group-sm mb-2" style="max-width: 32rem">
  <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($n['keyword']) ?>" placeholder="搜尋<?= htmlspecialchars($a->{'名稱'}) ?>的新聞公告">
  <?php if ($children): ?><input type="hidden" name="children" value="<?= $n['children'] ? '1' : '0' ?>"><?php endif; ?>
  <button class="btn btn-outline-secondary" type="submit">搜尋</button>
  <?php if ($n['keyword'] !== ''): ?><a class="btn btn-outline-secondary" href="<?= $news_url(['q' => '']) ?>">清除</a><?php endif; ?>
</form>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
  <span class="text-body-secondary"><?= $n['ok'] ? '共 ' . number_format($n['total']) . ' 則' : '' ?></span>
  <?php if ($children): ?>
  <a class="btn btn-sm btn-outline-secondary ms-auto<?= $n['children'] ? ' active' : '' ?>" href="<?= $news_url(['children' => $n['children'] ? '0' : '1']) ?>"><?= $n['children'] ? '☑' : '☐' ?> 含所屬機關</a>
  <?php endif; ?>
</div>
<?php if (!$n['ok']): ?>
<div class="alert alert-light border">新聞公告讀取失敗，請稍後再試</div>
<?php elseif (!$n['items']): ?>
<div class="alert alert-light border">查無新聞公告</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm small align-middle">
    <thead><tr><th class="text-nowrap">日期</th><th>標題</th><th class="text-nowrap">分類</th><th class="text-nowrap">發布單位</th></tr></thead>
    <tbody>
      <?php foreach ($n['items'] as $item): ?>
      <tr>
        <td class="text-nowrap"><?= htmlspecialchars(substr($item->time ?? '', 0, 10)) ?></td>
        <td><a href="<?= htmlspecialchars($item->source_url ?? '') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($item->title ?? '') ?></a></td>
        <td class="text-nowrap"><?= htmlspecialchars($item->category ?? '') ?></td>
        <td><?= htmlspecialchars(($item->unit ?? null) ?: ($item->org_name ?? '')) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($n['total_page'] > 1): ?>
<div class="d-flex align-items-center gap-2 small">
  <a class="btn btn-sm btn-outline-secondary<?= $n['page'] <= 1 ? ' disabled' : '' ?>" href="<?= $news_url(['page' => max(1, $n['page'] - 1)]) ?>">上一頁</a>
  <span>第 <?= number_format($n['page']) ?> / <?= number_format($n['total_page']) ?> 頁</span>
  <a class="btn btn-sm btn-outline-secondary<?= $n['page'] >= $n['total_page'] ? ' disabled' : '' ?>" href="<?= $news_url(['page' => $n['page'] + 1]) ?>">下一頁</a>
</div>
<?php endif; ?>
<?php endif; ?>
<p class="small text-body-secondary mt-2">資料來源：<a href="https://data.openfun.tw/datasets/tw.openfun~api~elastic-govnews" target="_blank" rel="noopener">全政府公告搜尋 API</a></p>
