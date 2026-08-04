<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? $d->{'縣市'} ?? '') ?>
            <?php if ($d->{'年份'} ?? null): ?>
            <?= $this->escape($d->{'年份'}) ?> 年
            <?php endif; ?>
            <?= $this->escape($d->{'姓名'} ?? '') ?>
            <?php if ($d->{'號次'} ?? null): ?>
            <span class="badge badge-secondary ml-1">號次 <?= $this->escape($d->{'號次'}) ?></span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body">
        <?php if ($d->{'相片路徑'} ?? null): ?>
        <img src="<?= $this->escape($d->{'相片路徑'}) ?>" alt="" style="max-width: 160px;" class="img-thumbnail mb-3">
        <?php endif; ?>
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '代碼', '議會代碼', '年份', '縣市', '選舉名稱', '選舉類型',
                    '候選人代碼', '選舉代碼', '行政區代碼', '選區別', 'code_match',
                    '姓名', '號次', '候選人別',
                    '得票數', '得票排名', '得票率',
                    '學歷', '經歷', '政見', '政見來源',
                    '政見圖路徑', 'note', 'extract_method', '來源PDF', '來源頁碼',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td style="white-space: pre-wrap;"><?= $this->escape($d->{$f}) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach (($d->{'其他欄位'} ?? []) as $k => $v): ?>
                <?php if ($v === '' || $v === null) continue; ?>
                <tr>
                    <th width="140"><?= $this->escape($k) ?></th>
                    <td style="white-space: pre-wrap;"><?= $this->escape($v) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
