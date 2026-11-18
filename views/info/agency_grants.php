<?php
$a = $this->agency;
$term_no = (int) $this->term_no;
$g = $this->agency_grants;
$base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'}) . '/grants';
$grants_url = function ($override) use ($g, $base) {
    $q = array_filter(array_merge(['year' => $g['year'], 'page' => $g['page']], $override), fn($v) => $v && $v !== 1);
    return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''));
};
$money = fn($v) => $v === null ? '' : number_format($v) . ' 元';
$pages = $g['total'] ? (int) ceil($g['total'] / $g['per_page']) : 0;
?>
<?php if (!$g['ok']): ?>
<div class="alert alert-light border">獎補助資料讀取失敗，請稍後再試</div>
<?php elseif (!$g['years']): ?>
<p class="small text-body-secondary">查無獎補助資料</p>
<?php else: ?>
<div class="table-responsive mb-3">
  <table class="table table-sm small mb-0" style="max-width: 32rem">
    <thead><tr><th>年度</th><th class="text-end">筆數</th><th class="text-end">累計撥付金額</th></tr></thead>
    <tbody>
      <?php foreach ($g['years'] as $y => $v): ?>
      <tr<?= $y === $g['year'] ? ' class="table-active"' : '' ?>>
        <td><a href="<?= $grants_url(['year' => $y, 'page' => 1]) ?>"><?= $y ?></a></td>
        <td class="text-end"><?= number_format($v['count']) ?></td>
        <td class="text-end"><?= $money($v['sum']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h3 class="h6 fw-semibold"><?= (int) $g['year'] ?> 年度補助明細<span class="small text-body-secondary fw-normal ms-2"><?= number_format($g['total']) ?> 筆</span></h3>
<div class="table-responsive">
  <table class="table table-sm small align-middle">
    <thead><tr><th>補助對象</th><th>補助事項</th><?php if (!$g['units']): ?><th>主辦機關</th><?php endif; ?><th class="text-end text-nowrap">累計撥付金額</th><th>來源</th></tr></thead>
    <tbody>
      <?php foreach ($g['records'] as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r->{'補助對象'} ?? '') ?></td>
        <td><?= htmlspecialchars($r->{'補助事項'} ?? '') ?></td>
        <?php if (!$g['units']): ?><td class="text-body-secondary"><?= htmlspecialchars($r->{'主辦機關'} ?? '') ?></td><?php endif; ?>
        <td class="text-end text-nowrap"><?= $money($r->{'累計撥付金額'} ?? null) ?></td>
        <td class="text-nowrap"><?php if ($r->{'來源檔案網址'} ?? null): ?><a href="<?= htmlspecialchars($r->{'來源檔案網址'}) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars(trim(($r->{'來源工作表'} ?? '') . ' 第 ' . ((int) ($r->{'來源列號'} ?? 0) + 1) . ' 列')) ?>"><?= htmlspecialchars(strtoupper($r->{'來源檔案格式'} ?? '檔案')) ?></a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($pages > 1): ?>
<nav><ul class="pagination pagination-sm">
  <?php if ($g['page'] > 1): ?><li class="page-item"><a class="page-link" href="<?= $grants_url(['page' => $g['page'] - 1]) ?>">上一頁</a></li><?php endif; ?>
  <li class="page-item disabled"><span class="page-link"><?= $g['page'] ?> / <?= $pages ?></span></li>
  <?php if ($g['page'] < $pages): ?><li class="page-item"><a class="page-link" href="<?= $grants_url(['page' => $g['page'] + 1]) ?>">下一頁</a></li><?php endif; ?>
</ul></nav>
<?php endif; ?>
<?php endif; ?>
