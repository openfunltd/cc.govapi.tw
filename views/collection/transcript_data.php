<?php $d = $this->data->data ?? null; ?>
<?php if (!$d): ?>
<div class="alert alert-warning">找不到資料</div>
<?php return; endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">
            <?= $this->escape($d->{'議會代碼'} ?? '') ?>
            <?= $this->escape($d->{'代碼'} ?? '') ?>
        </h6>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tbody>
                <?php
                $fields = ['代碼', '議會代碼', '屆', '會期代碼', '來源分類', '檔案數', '字數', 'updated_at'];
                foreach ($fields as $f):
                    if (!isset($d->{$f}) || $d->{$f} === '' || $d->{$f} === null) continue;
                    $v = $d->{$f};
                    if (is_array($v)) $v = implode('、', $v);
                ?>
                <tr>
                    <th width="140"><?= $this->escape($f) ?></th>
                    <td><?= $this->escape($v) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (isset($d->{'內容'}) && $d->{'內容'} !== ''): ?>
        <h6 class="font-weight-bold mt-3">內容</h6>
        <pre class="border rounded p-3 bg-light" style="max-height: 500px; overflow-y: auto; white-space: pre-wrap; font-family: inherit;"><?= $this->escape($d->{'內容'}) ?></pre>
        <?php endif; ?>
    </div>
</div>
