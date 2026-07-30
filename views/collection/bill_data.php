<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? '') ?>
            <?php if ($d->{'屆'} ?? null): ?>
            第 <?= $this->escape($d->{'屆'}) ?> 屆
            <?php endif; ?>
            <?= $this->escape($d->{'案號'} ?? '') ?>
            <?php if ($d->{'類別'} ?? null): ?>
            <span class="badge badge-secondary ml-1"><?= $this->escape($d->{'類別'}) ?></span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '代碼', '議會代碼', '屆', '縣市', '類別', '案號',
                    '提案單位', '提案人', '連署人',
                    '案由', '說明', '辦法', '審查意見', '議決',
                    '來源檔案', '來源頁碼',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td style="white-space: pre-wrap;"><?= $this->escape($d->{$f}) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
