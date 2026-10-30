<?php
$a = $this->agency;
$term_no = (int) $this->term_no;
$agency_url = fn($x) => '/info/' . $term_no . '/agencies/' . rawurlencode($x->{'代碼'});
$parent = $this->agency_parent ?? null;
$children = $this->agency_children ?? [];
$siblings = $this->agency_siblings ?? [];
$mayors = $this->agency_mayors ?? null;
$heads = $this->agency_heads ?? [];
$person = function ($m) {
    $name = htmlspecialchars($m->{'姓名'} ?? '');
    return ($m->_person_code ?? null) ? '<a href="/info/candidate/' . rawurlencode($m->_person_code) . '">' . $name . '</a>' : $name;
};
$head_text = fn($rec) => $rec ? htmlspecialchars(($rec->{'職稱'} ?? '') . ' ' . ($rec->{'姓名'} ?? '')) : '';
$agency_list = function ($list) use ($agency_url, $head_text) {
    $html = '<ul class="list-unstyled row row-cols-1 row-cols-md-2 row-cols-xl-3 g-1 small mb-0">';
    foreach ($list as $c) {
        $html .= '<li class="col"><a href="' . htmlspecialchars($agency_url($c)) . '">' . htmlspecialchars($c->{'名稱'}) . '</a>'
            . (($c->_head ?? null) ? ' <span class="text-body-secondary">' . $head_text($c->_head) . '</span>' : '') . '</li>';
    }
    return $html . '</ul>';
};
?>
<table class="table table-sm small mb-4" style="max-width: 40rem">
  <tbody>
    <?php if ($a->{'機關代碼'} ?? null): ?><tr><th class="text-nowrap" style="width: 7rem">機關代碼</th><td><?= htmlspecialchars($a->{'機關代碼'}) ?></td></tr><?php endif; ?>
    <?php if ($a->{'舊名稱'} ?? null): ?><tr><th class="text-nowrap">舊名稱</th><td><?= htmlspecialchars($a->{'舊名稱'}) ?></td></tr><?php endif; ?>
    <?php if ($parent): ?><tr><th class="text-nowrap">上層機關</th><td><a href="<?= htmlspecialchars($agency_url($parent)) ?>"><?= htmlspecialchars(($parent->{'全名'} ?? '') ?: $parent->{'名稱'}) ?></a></td></tr><?php endif; ?>
    <?php if ($mayors !== null): ?>
    <tr><th class="text-nowrap">首長</th><td><?= $a->_mayor ? htmlspecialchars($a->_mayor->{'職稱'} ?? '') . ' ' . $person($a->_mayor) : '' ?></td></tr>
    <?php elseif ($a->_head): ?>
    <tr><th class="text-nowrap">首長</th><td><?= $head_text($a->_head) ?></td></tr>
    <?php endif; ?>
    <?php if ($a->{'網址'} ?? null): ?><tr><th class="text-nowrap">網站</th><td><a href="<?= htmlspecialchars($a->{'網址'}) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($a->{'網址'}) ?></a></td></tr><?php endif; ?>
  </tbody>
</table>

<?php if ($mayors): ?>
<h3 class="h6 fw-semibold">歷任首長</h3>
<div class="table-responsive mb-4">
  <table class="table table-sm small mb-0" style="max-width: 40rem">
    <thead><tr><th>選舉年度</th><th>職稱</th><th>姓名</th><th>政黨</th></tr></thead>
    <tbody>
      <?php foreach ($mayors as $m): ?>
      <tr<?= $m === $a->_mayor ? ' class="table-active"' : '' ?>><td><?= (int) $m->{'年份'} ?></td><td><?= htmlspecialchars($m->{'職稱'} ?? '') ?></td><td><?= $person($m) ?></td><td class="text-body-secondary"><?= htmlspecialchars($m->{'政黨'} ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($heads): ?>
<h3 class="h6 fw-semibold">歷任首長</h3>
<div class="table-responsive mb-4">
  <table class="table table-sm small mb-0" style="max-width: 40rem">
    <thead><tr><th>屆</th><th>職稱</th><th>姓名</th><th>議會答詢期間</th><th class="text-end">發言數</th></tr></thead>
    <tbody>
      <?php foreach ($heads as $h): ?>
      <tr<?= $h === $a->_head ? ' class="table-active"' : '' ?>>
        <td><?= (int) $h->{'屆'} ?></td>
        <td><?= htmlspecialchars($h->{'職稱'} ?? '') ?></td>
        <td><?= htmlspecialchars($h->{'姓名'} ?? '') ?></td>
        <td class="text-nowrap"><?= htmlspecialchars(($h->{'首次'} ?? '') . ' ~ ' . ($h->{'最後'} ?? '')) ?></td>
        <td class="text-end"><?= number_format($h->{'次數'} ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($children): ?>
<h3 class="h6 fw-semibold">所屬機關 <span class="text-body-secondary fw-normal"><?= count($children) ?></span></h3>
<div class="mb-4"><?= $agency_list($children) ?></div>
<?php endif; ?>

<?php if ($siblings): ?>
<details class="mb-4">
  <summary class="h6 fw-semibold">同層機關 <span class="text-body-secondary fw-normal"><?= count($siblings) ?></span></summary>
  <div class="mt-2"><?= $agency_list($siblings) ?></div>
</details>
<?php endif; ?>
