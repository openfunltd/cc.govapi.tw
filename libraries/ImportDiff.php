<?php

/**
 * 匯入腳本的增量比對：記住上次寫進 ES 的每份文件內容雜湊，這次內容一樣就不再送，
 * 上次有、這次來源已經沒有的代碼從 ES 刪掉。
 *
 * 用法：
 *   $diff = new ImportDiff('meet_transcript', $reset);
 *   while (...) {
 *       ...組出 $doc...
 *       if ($diff->unchanged($doc_id, $doc)) continue;
 *       Elastic::dbBulkInsert('meet_transcript', $doc_id, $doc);
 *   }
 *   Elastic::dbBulkCommit('meet_transcript');
 *   $diff->finish();
 *
 * 雜湊算的是組好、要寫進 ES 的 $doc（含查 sitting、議員名冊等衍生欄位），不是來源列，
 * 所以衍生來源或匯入程式改了，受影響的文件也會重送。
 *
 * 狀態存在 .import-state/{ELASTIC_PREFIX}{type}.sqlite（gitignored）。這次執行的雜湊先
 * 寫進暫存檔，finish() 成功才取代舊檔；中途失敗、被中斷，舊狀態不動，下次照舊比對。
 * bulk 寫入失敗的文件（Elastic::$_failed_ids）在狀態裡的雜湊清空，下次會重送。
 * 沒有舊狀態（第一次執行、--reset）時每份文件都會送。
 *
 * 刪除只針對「上次由這支腳本寫進去、這次來源沒有」的代碼，同一個 index 裡別的腳本寫的
 * 文件（例如 meet index 裡立法院的會議）不在狀態裡，不會被刪。一次要刪的數量超過舊狀態
 * 的 5%（且多於 100 筆）就不刪，留在狀態裡下次再判斷，需要時加 --allow-mass-delete。
 */
class ImportDiff
{
    const MASS_DELETE_RATIO = 0.05;
    const MASS_DELETE_MIN = 100;

    protected $type;
    protected $path;
    protected $tmp_path;
    protected $db;
    protected $has_old;
    protected $insert;
    protected $update;
    protected $lookup;
    protected $pending = 0;

    public $sent = 0;
    public $skipped = 0;

    public function __construct($type, $reset = false)
    {
        $this->type = $type;
        $dir = __DIR__ . '/../.import-state';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->path = "{$dir}/" . getenv('ELASTIC_PREFIX') . "{$type}.sqlite";
        $this->tmp_path = "{$this->path}.tmp";
        if ($reset && file_exists($this->path)) {
            unlink($this->path);
        }
        @unlink($this->tmp_path);

        $this->db = new PDO('sqlite:' . $this->tmp_path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode = OFF');
        $this->db->exec('PRAGMA synchronous = OFF');
        $this->db->exec('CREATE TABLE docs (id TEXT PRIMARY KEY, hash TEXT NOT NULL) WITHOUT ROWID');
        $this->has_old = file_exists($this->path);
        if ($this->has_old) {
            $this->db->exec('ATTACH DATABASE ' . $this->db->quote($this->path) . ' AS old');
            $this->lookup = $this->db->prepare('SELECT hash FROM old.docs WHERE id = ?');
        }
        $this->insert = $this->db->prepare('INSERT OR IGNORE INTO docs (id, hash) VALUES (?, ?)');
        $this->update = $this->db->prepare('UPDATE docs SET hash = ? WHERE id = ?');
        $this->db->beginTransaction();
    }

    /**
     * 記下這份文件，內容跟上次寫進 ES 的一樣就回傳 true（呼叫端不用再送）
     */
    public function unchanged($id, $doc)
    {
        $id = (string) $id;
        $hash = substr(md5(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)), 0, 16);
        $this->insert->execute([$id, $hash]);
        if ($this->insert->rowCount() === 0) {
            // 來源裡同一個代碼出現不只一次：以最後一筆為準，每次都送，ES 才會跟來源最後一筆一致
            $this->update->execute([$hash, $id]);
            $same = false;
        } elseif ($this->has_old) {
            $this->lookup->execute([$id]);
            $same = $this->lookup->fetchColumn() === $hash;
            $this->lookup->closeCursor();
        } else {
            $same = false;
        }
        if (++$this->pending >= 50000) {
            $this->db->commit();
            $this->db->beginTransaction();
            $this->pending = 0;
        }
        $same ? $this->skipped++ : $this->sent++;
        return $same;
    }

    /**
     * 呼叫前先 Elastic::dbBulkCommit()。刪掉來源已經沒有的文件，再把這次的狀態存起來
     */
    public function finish()
    {
        global $argv;
        $failed = array_keys(Elastic::$_failed_ids[$this->type] ?? []);
        if ($failed) {
            // 留在狀態裡（才不會被當成來源已經沒有而刪掉），雜湊清空，下次一定重送
            $clear = $this->db->prepare("UPDATE docs SET hash = '' WHERE id = ?");
            foreach ($failed as $id) {
                $clear->execute([$id]);
            }
            error_log("ImportDiff[{$this->type}]: " . count($failed) . " 筆寫入失敗，下次重送");
        }

        $removed = [];
        if ($this->has_old) {
            $removed = $this->db->query('SELECT o.id FROM old.docs o LEFT JOIN docs n ON n.id = o.id WHERE n.id IS NULL')
                ->fetchAll(PDO::FETCH_COLUMN);
            $old_count = (int) $this->db->query('SELECT COUNT(*) FROM old.docs')->fetchColumn();
            $limit = max(self::MASS_DELETE_MIN, (int) ($old_count * self::MASS_DELETE_RATIO));
            if (count($removed) > $limit && !in_array('--allow-mass-delete', $argv ?? [])) {
                error_log(sprintf(
                    "ImportDiff[%s]: 來源少了 %d 筆（舊狀態 %d 筆），超過一次刪除上限 %d，這次不刪；確認要刪的話加 --allow-mass-delete",
                    $this->type, count($removed), $old_count, $limit
                ));
                $this->db->exec('INSERT INTO docs SELECT o.id, o.hash FROM old.docs o LEFT JOIN docs n ON n.id = o.id WHERE n.id IS NULL');
                $removed = [];
            } else {
                $removed = $this->deleteDocs($removed);
            }
        }
        $this->db->commit();
        if ($this->has_old) {
            $this->db->exec('DETACH DATABASE old');
        }
        $this->db = $this->insert = $this->update = $this->lookup = null;
        rename($this->tmp_path, $this->path);
        error_log(sprintf(
            "ImportDiff[%s]: 送出 %d 筆、內容沒變略過 %d 筆、刪除 %d 筆",
            $this->type, $this->sent, $this->skipped, count($removed)
        ));
    }

    /**
     * 回傳刪掉（或本來就不在 ES）的代碼；刪除失敗的放回狀態，下次再刪
     */
    protected function deleteDocs($ids)
    {
        $done = [];
        $restore = $this->db->prepare('INSERT OR IGNORE INTO docs SELECT id, hash FROM old.docs WHERE id = ?');
        foreach (array_chunk($ids, 1000) as $chunk) {
            $body = '';
            foreach ($chunk as $id) {
                $body .= json_encode(['delete' => ['_id' => $id]]) . "\n";
            }
            try {
                $ret = Elastic::dbQuery("/{prefix}{$this->type}/_bulk", 'POST', $body);
                foreach ($ret->items as $item) {
                    if (in_array($item->delete->status, [200, 404])) {
                        $done[] = $item->delete->_id;
                    } else {
                        $restore->execute([$item->delete->_id]);
                    }
                }
            } catch (Exception $e) {
                error_log("ImportDiff[{$this->type}]: 刪除失敗，下次再刪：" . $e->getMessage());
                foreach ($chunk as $id) {
                    $restore->execute([$id]);
                }
            }
        }
        return $done;
    }
}
