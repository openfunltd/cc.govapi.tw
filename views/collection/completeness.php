<?php
// Helper: render terms_with_data/total_terms + status badge (for councilor/session)
function completeness_cell($type_obj, $label = '') {
    $with_data  = $type_obj->terms_with_data ?? null;
    $total      = $type_obj->total_terms ?? null;
    $status     = $type_obj->status ?? 'missing';

    if (!is_null($with_data) && !is_null($total)) {
        // 議員/會期：顯示 有資料屆/總屆
        if ($status === 'ok') {
            $color = 'success'; $badge_text = 'OK';
        } elseif ($status === 'incomplete') {
            $color = 'warning'; $badge_text = "{$with_data}/{$total}";
        } else {
            $color = 'danger'; $badge_text = '缺';
        }
        $fraction = $status !== 'ok' ? "<small class=\"text-muted\">{$with_data}/{$total} 屆</small> " : '';
        return $fraction . '<span class="badge badge-' . $color . '">' . $badge_text . '</span>';
    }

    // 屆（只有 total + status）
    $total_val = $type_obj->total ?? 0;
    if ($status === 'ok') {
        return '<span class="text-success font-weight-bold">' . $total_val . '</span> <span class="badge badge-success">ok</span>';
    } elseif ($status === 'incomplete') {
        return '<span class="text-warning font-weight-bold">' . $total_val . '</span> <span class="badge badge-warning">不完整</span>';
    }
    return '<span class="text-danger font-weight-bold">' . $total_val . '</span> <span class="badge badge-danger">缺</span>';
}
?>
<?php $this->yield_start('content') ?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">資料完整度</h1>
    <?php if ($this->councils): ?>
        <?php $updated = $this->councils[0]->{'updated_at'} ?? ''; ?>
        <?php if ($updated): ?>
        <small class="text-muted">更新於 <?= htmlspecialchars($updated) ?></small>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($this->cc_code): ?>
    <?= $this->partial('collection/completeness_detail', $this) ?>
<?php else: ?>

<?php
// Split councils: active vs defunct
$active = array_filter($this->councils ?? [], fn($c) => $c->{'現存'} ?? false);
$defunct = array_filter($this->councils ?? [], fn($c) => !($c->{'現存'} ?? false));
?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">現存議會（<?= count($active) ?> 個）</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>議會</th>
                    <th class="text-center">屆</th>
                    <th class="text-center">議員</th>
                    <th class="text-center">會期</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($active as $c): ?>
                <?php $types = $c->types; ?>
                <tr>
                    <td>
                        <a href="<?= viewer_url('/collection/completeness/' . htmlspecialchars($c->{'代碼'})) ?>">
                            <?= htmlspecialchars($c->{'議會名稱'}) ?>
                        </a>
                        <small class="text-muted ml-1"><?= htmlspecialchars($c->{'代碼'}) ?></small>
                    </td>
                    <td class="text-center"><?= completeness_cell($types->term) ?></td>
                    <td class="text-center"><?= completeness_cell($types->councilor) ?></td>
                    <td class="text-center"><?= completeness_cell($types->session) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-secondary">已廢止議會（<?= count($defunct) ?> 個）</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>議會</th>
                    <th class="text-center">屆</th>
                    <th class="text-center">議員</th>
                    <th class="text-center">會期</th>
                </tr>
            </thead>
            <tbody class="text-muted">
            <?php foreach ($defunct as $c): ?>
                <?php $types = $c->types; ?>
                <tr>
                    <td>
                        <a href="<?= viewer_url('/collection/completeness/' . htmlspecialchars($c->{'代碼'})) ?>">
                            <?= htmlspecialchars($c->{'議會名稱'}) ?>
                        </a>
                        <small class="text-muted ml-1"><?= htmlspecialchars($c->{'代碼'}) ?></small>
                    </td>
                    <td class="text-center"><?= completeness_cell($types->term) ?></td>
                    <td class="text-center"><?= completeness_cell($types->councilor) ?></td>
                    <td class="text-center"><?= completeness_cell($types->session) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php endif; ?>
<?php $this->yield_end() ?>

<?= $this->partial('layout/app', $this) ?>
