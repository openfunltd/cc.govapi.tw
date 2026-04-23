<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center">
        <?php if (!empty($d->{'照片'})): ?>
        <img src="<?= $this->escape($d->{'照片'}) ?>" alt="照片" class="rounded-circle mr-3" style="width:60px;height:60px;object-fit:cover;">
        <?php endif; ?>
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'姓名'} ?? '') ?>
            <small class="text-muted ml-2"><?= $this->escape($d->{'黨籍'} ?? '') ?></small>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '議會代碼', '屆代碼', '屆次', '姓名', '職稱', '性別',
                    '黨籍', '區域', '身分別',
                    '出生日期', '出生地',
                    '聯絡電話', '辦公地址', '電子信箱',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="120"><?= $this->escape($f) ?></th>
                    <td>
                        <?php if ($f === '電子信箱'): ?>
                            <a href="mailto:<?= $this->escape($d->{$f}) ?>"><?= $this->escape($d->{$f}) ?></a>
                        <?php else: ?>
                            <?= $this->escape($d->{$f}) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($d->{'學歷'})): ?>
        <div class="mt-3">
            <strong>學歷</strong>
            <p class="mt-1"><?= nl2br($this->escape($d->{'學歷'})) ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($d->{'簡歷'})): ?>
        <div class="mt-3">
            <strong>簡歷</strong>
            <p class="mt-1"><?= nl2br($this->escape($d->{'簡歷'})) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center">
        <?php if (!empty($d->{'照片位址'})): ?>
        <img src="<?= $this->escape($d->{'照片位址'}) ?>" alt="照片" class="rounded-circle mr-3" style="width:60px;height:60px;object-fit:cover;">
        <?php endif; ?>
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'姓名'} ?? '') ?>
            <small class="text-muted ml-2"><?= $this->escape($d->{'黨籍'} ?? '') ?></small>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = [
                    '議會代碼', '屆', '姓名', '性別', '黨籍', '選區名稱',
                    '就任日', '離職日', '離職原因',
                    '電話', '通訊處', '電子信箱', '個人網站',
                ];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                ?>
                <tr>
                    <th width="120"><?= $this->escape($f) ?></th>
                    <td>
                        <?php if ($f === '個人網站' || $f === '電子信箱'): ?>
                            <a href="<?= $f === '電子信箱' ? 'mailto:' : '' ?><?= $this->escape($d->{$f}) ?>" target="_blank"><?= $this->escape($d->{$f}) ?></a>
                        <?php else: ?>
                            <?= $this->escape($d->{$f}) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($d->{'簡歷'})): ?>
        <div class="mt-3">
            <strong>簡歷</strong>
            <p class="mt-1"><?= nl2br($this->escape($d->{'簡歷'})) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
