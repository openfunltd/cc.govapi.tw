<table class="table table-sm table-bordered">
    <thead>
    <tr>
        <th>欄位</th>
        <th>值</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (TypeHelper::getRecordList($this->data->data) as $record) { ?>
    <tr>
        <td><code><?= $this->escape($record['key']) ?></code></td>
        <td><?= $this->escape($record['value']) ?></td>
    </tr>
    <?php } ?>
    </tbody>
</table>
