<?php
$c = $this->council;
if (!$c): ?>
<div class="alert alert-warning">找不到議會資料</div>
<?php return; endif;

$types = $c->types;

function detail_cell($count, $status) {
    if ($status === 'ok') {
        $color = 'text-success'; $icon = '✅';
    } elseif ($status === 'incomplete') {
        $color = 'text-warning'; $icon = '⚠️';
    } else {
        $color = 'text-danger'; $icon = '❌';
    }
    return '<span class="' . $color . ' font-weight-bold">' . $count . ' ' . $icon . '</span>';
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= viewer_url('/collection/completeness') ?>">資料完整度</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($c->{'議會名稱'}) ?></li>
    </ol>
</nav>

<div class="row mb-4">
    <?php
    $summary = [
        '屆'   => ['icon' => 'fas fa-calendar-alt', 'type' => 'term'],
        '議員' => ['icon' => 'fas fa-user-tie',      'type' => 'councilor'],
        '會期' => ['icon' => 'fas fa-calendar-week', 'type' => 'session'],
    ];
    foreach ($summary as $label => $info):
        $td = $types->{$info['type']};
        $color = ($td->status === 'ok') ? 'success' : (($td->status === 'incomplete') ? 'warning' : 'danger');
    ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-<?= $color ?> shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-<?= $color ?> text-uppercase mb-1"><?= $label ?></div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $td->total ?> 筆</div>
                    </div>
                    <div class="col-auto">
                        <i class="<?= $info['icon'] ?> fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= htmlspecialchars($c->{'議會名稱'}) ?> 各屆完整度
            <small class="text-muted ml-2"><?= htmlspecialchars($c->{'代碼'}) ?></small>
            <?php if (!($c->{'現存'} ?? true)): ?>
                <span class="badge badge-secondary ml-1">已廢止</span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th class="text-center">屆次</th>
                    <th class="text-center">就職日</th>
                    <th class="text-center">任期屆滿日</th>
                    <th class="text-center">議員</th>
                    <th class="text-center">會期</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($c->terms as $t): ?>
                <tr>
                    <td class="text-center font-weight-bold">第 <?= (int)$t->{'屆次'} ?> 屆</td>
                    <td class="text-center text-muted small"><?= htmlspecialchars($t->{'就職日'} ?? '—') ?></td>
                    <td class="text-center text-muted small"><?= htmlspecialchars($t->{'任期屆滿日'} ?? '進行中') ?></td>
                    <td class="text-center"><?= detail_cell($t->{'councilor_count'}, $t->{'councilor_status'}) ?></td>
                    <td class="text-center"><?= detail_cell($t->{'session_count'}, $t->{'session_status'}) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
