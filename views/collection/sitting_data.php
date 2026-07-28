<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? '') ?>
            <?= $this->escape($d->{'日期'} ?? '') ?>
            <?= $this->escape($d->{'時段'} ?? '') ?>
            <span class="badge badge-secondary"><?= $this->escape($d->{'場次類別'} ?? '') ?></span>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '代碼', '議會代碼', '屆', '會期代碼', '日期', '星期', '時段', '會次',
                    '場次類別', '委員會名稱', '停會原因', '開始時間', '結束時間',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td><?= $this->escape($d->{$f}) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (isset($d->{'議程說明'}) && $d->{'議程說明'} !== ''): ?>
                <tr>
                    <th width="140">議程說明</th>
                    <td style="white-space: pre-wrap;"><?= $this->escape($d->{'議程說明'}) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
