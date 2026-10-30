<?php
$a = $this->agency;
$term_no = (int) $this->term_no;
$b = $this->agency_budget;
$base = '/info/' . $term_no . '/agencies/' . rawurlencode($a->{'代碼'}) . '/budget';
$budget_url = function ($override) use ($b, $base) {
    $q = array_merge(['type' => $b['type'], 'year' => $b['year']], $override);
    if ($q['type'] === '歲出') unset($q['type']);
    if (!$q['year']) unset($q['year']);
    return htmlspecialchars($base . ($q ? '?' . http_build_query($q) : ''));
};
$money = fn($v) => $v === null ? '' : number_format($v) . ' 元';
$is_expense = $b['type'] === '歲出';
?>
<?php if (!$b['ok']): ?>
<div class="alert alert-light border">預算資料讀取失敗，請稍後再試</div>
<?php elseif (!$b['years']): ?>
<p class="small text-body-secondary">查無預算資料</p>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small">
  <div class="btn-group btn-group-sm" role="group">
    <?php foreach (['歲出', '歲入'] as $type): ?>
    <a class="btn btn-outline-secondary<?= $b['type'] === $type ? ' active' : '' ?>" href="<?= $budget_url(['type' => $type, 'year' => null]) ?>"><?= $type ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($b['history']): ?>
<div class="table-responsive mb-3">
  <table class="table table-sm small mb-0" style="max-width: 48rem">
    <thead><tr><th>年度</th><th class="text-end">預算案</th><th class="text-end">法定預算</th><th class="text-end">決算</th></tr></thead>
    <tbody>
      <?php foreach ($b['history'] as $h): ?>
      <?php $v = $h->{$b['type']} ?? null; ?>
      <tr<?= (int) $h->年 === $b['year'] ? ' class="table-active"' : '' ?>>
        <td><a href="<?= $budget_url(['year' => (int) $h->年]) ?>"><?= (int) $h->年 ?></a></td>
        <td class="text-end"><?= $money($v->預算案 ?? null) ?></td>
        <td class="text-end"><?= $money($v->法定預算 ?? null) ?></td>
        <td class="text-end"><?= $money($v->決算 ?? null) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="btn-group btn-group-sm flex-wrap mb-3" role="group">
  <?php foreach ($b['years'] as $y): ?>
  <a class="btn btn-outline-secondary<?= $y === $b['year'] ? ' active' : '' ?>" href="<?= $budget_url(['year' => $y]) ?>"><?= $y ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h3 class="h6 fw-semibold"><?= (int) $b['year'] ?> 年度<?= $is_expense ? '工作計劃' : '歲入細目' ?></h3>
<?php if (!$b['plans']): ?>
<p class="small text-body-secondary">查無<?= $b['unit'] ? '這個單位承辦的' : '' ?>項目</p>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm small align-middle">
    <thead><tr>
      <?php if ($is_expense): ?><th>業務計劃</th><th>工作計劃</th><?php else: ?><th>來源</th><th>子目</th><th>細目</th><?php endif; ?>
      <th>承辦單位</th><th class="text-end text-nowrap">預算數</th><th class="text-end text-nowrap">與去年差異</th>
    </tr></thead>
    <tbody>
      <?php $sum = 0; foreach ($b['plans'] as $p): ?>
      <?php $amount = $p->法定預算 ?? $p->預算案 ?? null; $sum += (int) $amount; ?>
      <tr>
        <?php if ($is_expense): ?>
        <td><?= htmlspecialchars($p->業務計劃 ?? '') ?></td>
        <td><?= htmlspecialchars($p->工作計劃 ?? '') ?></td>
        <?php else: ?>
        <td><?= htmlspecialchars($p->來源 ?? '') ?></td>
        <td><?= htmlspecialchars($p->子目 ?? '') ?></td>
        <td><?= htmlspecialchars($p->細目 ?? '') ?></td>
        <?php endif; ?>
        <td class="text-body-secondary"><?= htmlspecialchars(implode('、', (array) ($p->承辦單位 ?? []))) ?></td>
        <td class="text-end text-nowrap"><?= $money($amount) ?></td>
        <td class="text-end text-nowrap"><?= ($p->與去年差異 ?? null) === null ? '' : ($p->與去年差異 > 0 ? '+' : '') . number_format($p->與去年差異) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($b['unit']): ?>
    <tfoot><tr><th colspan="<?= $is_expense ? 3 : 4 ?>">合計</th><th class="text-end text-nowrap"><?= $money($sum) ?></th><th></th></tr></tfoot>
    <?php endif; ?>
  </table>
</div>
<?php endif; ?>
<p class="small text-body-secondary mt-2">資料來源：<a href="https://budget.openfun.app/" target="_blank" rel="noopener">政府預算查詢平台</a></p>
<?php endif; ?>
