<?php
// Helper: render terms_with_data/total_terms + status badge（給 completeness_detail 用）
function completeness_cell($type_obj, $label = '') {
    $with_data  = $type_obj->terms_with_data ?? null;
    $total      = $type_obj->total_terms ?? null;
    $status     = $type_obj->status ?? 'missing';

    if (!is_null($with_data) && !is_null($total)) {
        // 議員/會期/場次/逐字稿：顯示 有資料屆/總屆
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

// Helper：依某個型別的 status 把議會分成 ok/incomplete/missing 三桶
function completeness_buckets($councils, $type_key) {
    $buckets = ['ok' => [], 'incomplete' => [], 'missing' => []];
    foreach ($councils as $c) {
        $status = $c->types->{$type_key}->status ?? 'missing';
        if (!array_key_exists($status, $buckets)) {
            $status = 'missing';
        }
        $buckets[$status][] = $c;
    }
    return $buckets;
}

// Helper：單一議會的小徽章連結，帶 data-defunct 供 JS 切換顯示/隱藏
// $extra_text 可選，用來附加「共 N 屆」這類補充說明（目前只在場次/逐字稿的完整桶使用）
// $title 可選，滑鼠停留時顯示的說明（目前用來顯示議案/逐字稿「缺」是官方無法提供還是我們規劃中）
function completeness_council_badge($c, $extra_text = null, $title = null) {
    $defunct = !($c->{'現存'} ?? true);
    $label = htmlspecialchars($c->{'議會名稱'});
    if ($extra_text) {
        $label .= ' <span class="text-muted">(' . htmlspecialchars($extra_text) . ')</span>';
    }
    return sprintf(
        '<a href="%s" class="badge badge-light border text-dark council-badge" data-defunct="%d"%s>%s</a>',
        htmlspecialchars(viewer_url('/collection/completeness/' . $c->{'代碼'})),
        $defunct ? 1 : 0,
        $title ? ' title="' . htmlspecialchars($title) . '" style="cursor: help;"' : '',
        $label
    );
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

<div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" id="toggle-defunct">
    <label class="form-check-label" for="toggle-defunct">顯示已廢止議會</label>
</div>

<?php
$zones = [
    'councilor'  => ['title' => '👤 議員區',  'color' => 'primary'],
    'session'    => ['title' => '📅 會期區',  'color' => 'primary'],
    'sitting'    => ['title' => '🗓 場次區',  'color' => 'primary'],
    'transcript' => ['title' => '📄 逐字稿區', 'color' => 'primary'],
    'bill'       => ['title' => '📋 議案區',  'color' => 'primary'],
];
$bucket_labels = [
    'ok'         => ['icon' => '🟢', 'label' => '完整',   'text' => 'success'],
    'incomplete' => ['icon' => '🟡', 'label' => '部分缺漏', 'text' => 'warning'],
    'missing'    => ['icon' => '🔴', 'label' => '缺',     'text' => 'danger'],
];
?>

<?php foreach ($zones as $type_key => $zone): ?>
<?php $buckets = completeness_buckets($this->councils ?? [], $type_key); ?>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-<?= $zone['color'] ?>"><?= $zone['title'] ?></h6>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($bucket_labels as $bucket_key => $bl): ?>
            <div class="col-md-4 mb-3 mb-md-0">
                <h6 class="text-<?= $bl['text'] ?>">
                    <?= $bl['icon'] ?> <?= $bl['label'] ?>
                    (<span class="bucket-count" data-zone="<?= $type_key ?>" data-bucket="<?= $bucket_key ?>"><?= count($buckets[$bucket_key]) ?></span>)
                </h6>
                <div class="d-flex flex-wrap" style="gap: 0.35rem;" data-zone="<?= $type_key ?>" data-bucket="<?= $bucket_key ?>">
                    <?php foreach ($buckets[$bucket_key] as $c): ?>
                        <?php
                        // 場次／逐字稿的「完整」「部分缺漏」桶，額外標出屆數：
                        // 完整 → 共 N 屆；部分缺漏 → 有資料屆數/總屆數，
                        // 讓小議會（例如只有 8 屆）跟大議會（例如 14 屆）不會被誤認為同一回事
                        $extra_text = null;
                        $title = null;
                        if (in_array($type_key, ['sitting', 'transcript', 'bill'], true)) {
                            $total_terms = $c->types->{$type_key}->total_terms ?? null;
                            $with_data   = $c->types->{$type_key}->terms_with_data ?? null;
                            if ($bucket_key === 'ok' && !is_null($total_terms)) {
                                $extra_text = "共 {$total_terms} 屆";
                            } elseif ($bucket_key === 'incomplete' && !is_null($total_terms) && !is_null($with_data)) {
                                $extra_text = "{$with_data}/{$total_terms} 屆";
                            }
                        }
                        // 議案／逐字稿的「缺」，上游有提供原因說明的話顯示 tooltip，
                        // 讓使用者知道是官方無法提供，還是我們這邊還沒處理
                        if ($bucket_key === 'missing' && in_array($type_key, ['transcript', 'bill'], true)) {
                            $title = $c->types->{$type_key}->upstream_note ?? null;
                        }
                        ?>
                        <?= completeness_council_badge($c, $extra_text, $title) ?>
                    <?php endforeach; ?>
                    <?php if (empty($buckets[$bucket_key])): ?>
                    <span class="text-muted small">（無）</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    var toggle = document.getElementById('toggle-defunct');

    function updateCounts() {
        document.querySelectorAll('[data-zone][data-bucket].bucket-count').forEach(function (el) {
            var zone = el.dataset.zone, bucket = el.dataset.bucket;
            var container = document.querySelector('div[data-zone="' + zone + '"][data-bucket="' + bucket + '"]');
            var visible = container.querySelectorAll('.council-badge:not(.d-none)').length;
            el.textContent = visible;
        });
    }

    function applyToggle() {
        var show = toggle.checked;
        document.querySelectorAll('.council-badge[data-defunct="1"]').forEach(function (el) {
            el.classList.toggle('d-none', !show);
        });
        updateCounts();
    }

    toggle.addEventListener('change', applyToggle);
    applyToggle();
})();
</script>

<?php endif; ?>
<?php $this->yield_end() ?>

<?= $this->partial('layout/app', $this) ?>
