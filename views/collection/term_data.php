<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? '') ?> 第 <?= $this->escape($d->{'屆次'} ?? '') ?> 屆
            <?php if ($d->{'現任'} ?? false): ?>
                <span class="badge badge-success ml-2">現任</span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = ['議會代碼', '屆次', '投票日', '就職日', '任期屆滿日', '備註'];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td><?= $this->escape($d->{$f}) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th>現任</th>
                    <td><?= ($d->{'現任'} ?? false) ? '✅ 是' : '❌ 否' ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
