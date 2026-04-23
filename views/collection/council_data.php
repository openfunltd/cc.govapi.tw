<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會名稱'} ?? '') ?>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '代碼', '議會名稱', '議會類別', '內政部行政區代碼',
                    'ISO碼', '生效日期', '廢止日期', '現存', '維基條目', 'wikidata_id', '最新屆期代碼',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f})) continue;
                ?>
                <tr>
                    <th width="160"><?= $this->escape($f) ?></th>
                    <td>
                        <?php if ($f === '維基條目' && $d->{$f}): ?>
                            <a href="<?= $this->escape($d->{$f}) ?>" target="_blank"><?= $this->escape($d->{$f}) ?></a>
                        <?php elseif ($f === '現存'): ?>
                            <?= $d->{$f} ? '✅ 現存' : '❌ 已廢止' ?>
                        <?php elseif ($f === '最新屆期代碼' && $d->{$f}): ?>
                            <a href="<?= viewer_url('/collection/item/term/' . $this->escape(rawurlencode($d->{$f}))) ?>"><?= $this->escape($d->{$f}) ?></a>
                        <?php else: ?>
                            <?= $this->escape($d->{$f}) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($d->{'維基條目'})): ?>
        <a href="<?= $this->escape($d->{'維基條目'}) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-2">
            <i class="fas fa-external-link-alt"></i> 維基百科
        </a>
        <?php endif; ?>
    </div>
</div>
