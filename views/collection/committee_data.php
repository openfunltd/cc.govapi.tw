<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? '') ?>
            <?= $this->escape($d->{'名稱'} ?? '') ?>
            <span class="badge badge-secondary ml-1"><?= $this->escape($d->{'類別'} ?? '') ?></span>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = ['代碼', '議會代碼', '名稱', '別稱', '類別', '職掌', '生效日期', '廢止日期'];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td><?= $this->escape($d->{$f}) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
