<?php

/**
 * 向量（embedding）index 共用函式：切段、本地向量備份、呼叫 embedding 服務、寫入向量 Elasticsearch。
 *
 * 設定（config.inc.php）：
 *   VEC_ELASTIC_URL／VEC_ELASTIC_USER／VEC_ELASTIC_PASSWORD／VEC_ELASTIC_PREFIX：向量 index 所在的 Elasticsearch
 *     （跟一般資料的 ELASTIC_* 分開，可以是另一台）
 *   VEC_EMBED_URLS：相容 ollama /api/embed 的 embedding 服務 base URL，多台用逗號分隔，會同時分批送
 *   VEC_EMBED_API_KEY：embedding 服務需要的話放在 Authorization: Bearer header，不需要就留空
 *   VEC_STORE_DIR：本地向量備份（SQLite）放哪，預設 .import-state/
 *
 * 本地備份：每個來源單位（一筆議案、一份會議紀錄、一場會議的逐字稿）一列，記內容雜湊與所有段落的
 * float32 向量。內容雜湊沒變就不重算；之後重建 index 或換量化方式時直接從這裡重灌，不用重算向量。
 */
class VectorIndex
{
    const EMBED_MODEL = 'bge-m3';
    const EMBED_DIMS = 1024;
    // 切段規則版本：改了切段長度、段首脈絡格式等會影響向量的規則就要 +1，內容雜湊跟著變，全部重算
    const CHUNK_VERSION = 1;
    const CHUNK_SIZE = 600;
    const CHUNK_STEP = 500;
    // 每次送 embedding 服務的段數；呼叫端一輪湊 32 批（2048 段）再送，多台速度不同時，慢的那台拖住整輪的影響比較小
    const EMBED_BATCH = 64;

    protected static $mh = null;
    protected static $done = [];

    public static function indexName($type)
    {
        return getenv('VEC_ELASTIC_PREFIX') . $type . '-' . self::EMBED_MODEL . '-v' . self::CHUNK_VERSION;
    }

    public static function aliasName($type)
    {
        return getenv('VEC_ELASTIC_PREFIX') . $type;
    }

    // 段首脈絡＋固定長度視窗（重疊 CHUNK_SIZE - CHUNK_STEP 字），回傳每段的 [起點, 內文]
    public static function windows($text)
    {
        $len = mb_strlen($text);
        $out = [];
        for ($i = 0; $i < $len; $i += self::CHUNK_STEP) {
            $out[] = [$i, mb_substr($text, $i, self::CHUNK_SIZE)];
            if ($i + self::CHUNK_SIZE >= $len) {
                break;
            }
        }
        return $out;
    }

    public static function hash($parts)
    {
        return substr(sha1(self::EMBED_MODEL . '|' . self::CHUNK_VERSION . '|' . implode("\x1f", $parts)), 0, 20);
    }

    // 本地向量備份。page_size 要在建表前設成 64KB：預設 4KB 的頁放不下一個 4096 bytes 的向量，
    // 每列都會溢出到下一頁，空間膨脹一倍
    public static function store($type)
    {
        $dir = getenv('VEC_STORE_DIR') ?: __DIR__ . '/../.import-state';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = rtrim($dir, '/') . '/' . self::indexName($type) . '.sqlite';
        $is_new = !file_exists($path);
        $db = new SQLite3($path);
        $db->busyTimeout(60000);
        if ($is_new) {
            $db->exec('PRAGMA page_size=65536');
        }
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->exec('CREATE TABLE IF NOT EXISTS vectors (id TEXT PRIMARY KEY, hash TEXT NOT NULL, vecs BLOB NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
        $stmt = $db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:k, :v)');
        foreach ([
            'model' => self::EMBED_MODEL, 'dims' => self::EMBED_DIMS, 'chunk_version' => self::CHUNK_VERSION,
            'chunk_size' => self::CHUNK_SIZE, 'chunk_step' => self::CHUNK_STEP,
            'format' => 'float32 little-endian, chunks concatenated',
        ] as $k => $v) {
            $stmt->bindValue(':k', $k);
            $stmt->bindValue(':v', (string) $v);
            $stmt->execute();
            $stmt->reset();
        }
        return $db;
    }

    // 回傳 [id => [hash, 段數]]
    public static function storeLookup($db, $ids)
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $part) {
            $stmt = $db->prepare('SELECT id, hash, length(vecs) AS len FROM vectors WHERE id IN (' . implode(',', array_fill(0, count($part), '?')) . ')');
            foreach ($part as $i => $id) {
                $stmt->bindValue($i + 1, $id);
            }
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $out[$row['id']] = [$row['hash'], intdiv($row['len'], self::EMBED_DIMS * 4)];
            }
        }
        return $out;
    }

    public static function storeSave($db, $units)
    {
        $db->exec('BEGIN');
        $stmt = $db->prepare('INSERT OR REPLACE INTO vectors (id, hash, vecs) VALUES (:id, :hash, :vecs)');
        foreach ($units as $u) {
            $blob = '';
            foreach ($u['vecs'] as $v) {
                $blob .= pack('g*', ...$v);
            }
            $stmt->bindValue(':id', $u['id']);
            $stmt->bindValue(':hash', $u['hash']);
            $stmt->bindValue(':vecs', $blob, SQLITE3_BLOB);
            $stmt->execute();
            $stmt->reset();
        }
        $db->exec('COMMIT');
    }

    // 回傳 [id => [[float...], ...]]（各段向量）
    public static function storeVectors($db, $ids)
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $part) {
            $stmt = $db->prepare('SELECT id, vecs FROM vectors WHERE id IN (' . implode(',', array_fill(0, count($part), '?')) . ')');
            foreach ($part as $i => $id) {
                $stmt->bindValue($i + 1, $id);
            }
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $out[$row['id']] = array_map(function ($chunk) {
                    return array_values(unpack('g*', $chunk));
                }, str_split($row['vecs'], self::EMBED_DIMS * 4));
            }
        }
        return $out;
    }

    // 回傳 [id => 段數]，給整份跑完後找出來源已經沒有的單位
    public static function storeAll($db)
    {
        $out = [];
        $res = $db->query('SELECT id, length(vecs) AS len FROM vectors');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[$row['id']] = intdiv($row['len'], self::EMBED_DIMS * 4);
        }
        return $out;
    }

    public static function storeDelete($db, $ids)
    {
        $db->exec('BEGIN');
        $stmt = $db->prepare('DELETE FROM vectors WHERE id = :id');
        foreach ($ids as $id) {
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $stmt->reset();
        }
        $db->exec('COMMIT');
    }

    // 所有 HTTP 請求共用一個 curl_multi：等 embedding 的時候，背景那筆 ES 寫入也會一起往前送
    public static function start($ch)
    {
        if (self::$mh === null) {
            self::$mh = curl_multi_init();
        }
        curl_multi_add_handle(self::$mh, $ch);
        return $ch;
    }

    // 等其中一個請求完成，回傳它在 $handles 裡的 key
    public static function waitAny($handles)
    {
        while (true) {
            foreach ($handles as $k => $ch) {
                if (isset(self::$done[(int) $ch])) {
                    return $k;
                }
            }
            curl_multi_exec(self::$mh, $running);
            while ($info = curl_multi_info_read(self::$mh)) {
                self::$done[(int) $info['handle']] = $info['result'];
            }
            foreach ($handles as $k => $ch) {
                if (isset(self::$done[(int) $ch])) {
                    return $k;
                }
            }
            curl_multi_select(self::$mh, 1);
        }
    }

    // 等這個請求完成，回傳 [回應內容, HTTP 狀態碼, curl 錯誤]
    public static function wait($ch)
    {
        self::waitAny([$ch]);
        $result = self::$done[(int) $ch];
        unset(self::$done[(int) $ch]);
        $response = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle(self::$mh, $ch);
        return [$response, $code, $result === CURLE_OK ? null : curl_strerror($result)];
    }

    public static function esHandle($method, $path, $body = null, $ndjson = false)
    {
        $ch = curl_init(rtrim(getenv('VEC_ELASTIC_URL'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => getenv('VEC_ELASTIC_USER') . ':' . getenv('VEC_ELASTIC_PASSWORD'),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . ($ndjson ? 'application/x-ndjson' : 'application/json')],
            // HEAD 沒有 body，不設 NOBODY 的話 curl 會一直等到逾時
            CURLOPT_NOBODY => $method === 'HEAD',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        return $ch;
    }

    public static function es($method, $path, $body = null, $ndjson = false)
    {
        list($response, $code) = self::wait(self::start(self::esHandle($method, $path, $body, $ndjson)));
        return [$code, json_decode((string) $response, true) ?? $response];
    }

    protected static function properties()
    {
        return [
            '類型' => ['type' => 'keyword'],
            '來源代碼' => ['type' => 'keyword'],
            '段號' => ['type' => 'integer'],
            '議會代碼' => ['type' => 'keyword'],
            '縣市' => ['type' => 'keyword'],
            '屆' => ['type' => 'integer'],
            '日期' => ['type' => 'keyword'],
            '會議代碼' => ['type' => 'keyword'],
            '場次代碼' => ['type' => 'keyword'],
            '標題' => ['type' => 'text'],
            '內容' => ['type' => 'text'],
            '發言人' => ['type' => 'keyword'],
            '發言議員' => ['type' => 'keyword'],
            '提案人' => ['type' => 'keyword'],
            '連署人' => ['type' => 'keyword'],
            '議員代碼' => ['type' => 'keyword'],
            '人物代碼' => ['type' => 'keyword'],
            '逐字稿代碼' => ['type' => 'keyword'],
            '起始順序' => ['type' => 'integer'],
            '結束順序' => ['type' => 'integer'],
            'API路徑' => ['type' => 'keyword', 'index' => false],
            '向量時間' => ['type' => 'date'],
            'vec' => [
                'type' => 'dense_vector', 'dims' => self::EMBED_DIMS, 'index' => true, 'similarity' => 'cosine',
                'index_options' => ['type' => 'bbq_hnsw', 'm' => 16, 'ef_construction' => 100],
            ],
        ];
    }

    // index 不存在就建立並掛上 alias（{prefix}{type} 與跨型別的 {prefix}all）。回傳是否新建。
    // vec 不放進 _source（省一半以上空間），要重建時從本地備份讀
    public static function ensureIndex($type)
    {
        $index = self::indexName($type);
        list($code) = self::es('HEAD', '/' . $index);
        if ($code === 200) {
            // 已經存在的 index 補上後來新增的欄位（已有的欄位定義不變，重送不會出錯）
            list($code, $r) = self::es('PUT', '/' . $index . '/_mapping', ['properties' => self::properties()]);
            if ($code !== 200) {
                throw new Exception("更新 {$index} 欄位失敗：" . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
            return false;
        }
        list($code, $r) = self::es('PUT', '/' . $index, [
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
            'mappings' => [
                '_source' => ['excludes' => ['vec']],
                'properties' => self::properties(),
            ],
        ]);
        if ($code !== 200) {
            throw new Exception("建立 {$index} 失敗：" . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        // alias 已經指向別的 index（換版重建中）就不動，等新 index 全部算完再手動切換
        $actions = [];
        foreach ([self::aliasName($type), getenv('VEC_ELASTIC_PREFIX') . 'all'] as $alias) {
            list($code, $r) = self::es('GET', '/_alias/' . $alias);
            $others = $code === 200 ? array_filter(array_keys($r), function ($i) use ($type) {
                return strpos($i, self::aliasName($type) . '-') === 0;
            }) : [];
            if ($others) {
                error_log("alias {$alias} 已指向 " . implode(',', $others) . "，{$index} 算完後要手動切換");
                continue;
            }
            $actions[] = ['add' => ['index' => $index, 'alias' => $alias]];
        }
        if ($actions) {
            list($code, $r) = self::es('POST', '/_aliases', ['actions' => $actions]);
            if ($code !== 200) {
                throw new Exception('設定 alias 失敗：' . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }
        return true;
    }

    // 大量寫入時關掉 refresh、translog 改非同步，寫完改回預設
    public static function setBulkMode($type, $on)
    {
        list($code, $r) = self::es('PUT', '/' . self::indexName($type) . '/_settings', ['index' => [
            'refresh_interval' => $on ? '-1' : null,
            'translog.durability' => $on ? 'async' : 'request',
        ]]);
        if ($code !== 200) {
            throw new Exception('修改 index 設定失敗：' . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }

    public static function isBulkMode($type)
    {
        list($code, $r) = self::es('GET', '/' . self::indexName($type) . '/_settings/index.refresh_interval');
        return $code === 200 && (reset($r)['settings']['index']['refresh_interval'] ?? null) === '-1';
    }

    protected static function embedHandle($url, $texts)
    {
        $ch = curl_init(rtrim($url, '/') . '/api/embed');
        $headers = ['Content-Type: application/json'];
        if (getenv('VEC_EMBED_API_KEY')) {
            $headers[] = 'Authorization: Bearer ' . getenv('VEC_EMBED_API_KEY');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => $headers,
            // keep_alive：模型閒置超過這段時間才卸載，批次之間不用重新載入
            CURLOPT_POSTFIELDS => json_encode(['model' => self::EMBED_MODEL, 'input' => $texts, 'keep_alive' => '30m'],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return $ch;
    }

    // 把所有段落分成 EMBED_BATCH 一批，VEC_EMBED_URLS 的每台服務各送一批，哪台先回來就補下一批給它。
    // 單批失敗重試 6 次（每次多等 10 秒），還是失敗就丟例外，呼叫端不前進、已存的進度保留
    public static function embed($texts)
    {
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) getenv('VEC_EMBED_URLS')))));
        if (!$urls) {
            throw new Exception('沒有設定 VEC_EMBED_URLS');
        }
        $batches = array_chunk($texts, self::EMBED_BATCH);
        $result = [];
        $queue = array_keys($batches);
        $tries = [];
        $running = []; // url index => [batch index, handle]
        while ($queue || $running) {
            foreach ($urls as $ui => $url) {
                if (!isset($running[$ui]) && $queue) {
                    $bi = array_shift($queue);
                    $running[$ui] = [$bi, self::start(self::embedHandle($url, $batches[$bi]))];
                }
            }
            // 等任一台回來（其他台和背景的 ES 寫入同時在跑）
            $ui = self::waitAny(array_map(function ($r) {
                return $r[1];
            }, $running));
            list($bi, $ch) = $running[$ui];
            unset($running[$ui]);
            list($response, , $err) = self::wait($ch);
            $r = json_decode((string) $response, true);
            if (isset($r['embeddings']) && count($r['embeddings']) === count($batches[$bi])) {
                $result[$bi] = $r['embeddings'];
                continue;
            }
            $tries[$bi] = ($tries[$bi] ?? 0) + 1;
            $msg = $err ?? substr((string) $response, 0, 300);
            if ($tries[$bi] >= 6) {
                throw new Exception("embedding 連續失敗：{$msg}");
            }
            error_log("embedding 失敗（{$urls[$ui]} 第 {$tries[$bi]} 次）：{$msg}");
            sleep(10 * $tries[$bi]);
            array_unshift($queue, $bi);
        }
        ksort($result);
        return array_merge(...array_values($result));
    }

    // 一批單位的 bulk 內容：每段一份文件（_id = "{來源代碼}#{段號}"），段數比上次少的刪掉多出來的舊段
    public static function bulkBody($units)
    {
        // 向量是 float32，9 位有效數字就能完整還原；預設 17 位會讓 bulk 大一倍
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', 9);
        $lines = [];
        $now = date('c');
        foreach ($units as $u) {
            foreach ($u['chunks'] as $i => $chunk) {
                $lines[] = json_encode(['index' => ['_id' => "{$u['id']}#{$i}"]], JSON_UNESCAPED_UNICODE);
                $lines[] = json_encode(array_merge($u['meta'], $chunk['meta'] ?? [], [
                    '來源代碼' => $u['id'],
                    '段號' => $i,
                    '標題' => $u['header'],
                    '內容' => $chunk['text'],
                    '向量時間' => $now,
                    'vec' => $u['vecs'][$i],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            for ($i = count($u['chunks']); $i < $u['old_chunks']; $i++) {
                $lines[] = json_encode(['delete' => ['_id' => "{$u['id']}#{$i}"]], JSON_UNESCAPED_UNICODE);
            }
        }
        ini_set('serialize_precision', $precision);
        return $lines ? implode("\n", $lines) . "\n" : null;
    }

    // bulk 回應是否全部成功（刪除不存在的舊段回 404 不算錯）
    public static function bulkOk($code, $r)
    {
        if ($code !== 200 || !is_array($r)) {
            error_log("ES bulk 失敗 {$code}：" . substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 300));
            return false;
        }
        $bad = array_filter($r['items'] ?? [], function ($item) {
            $op = reset($item);
            return ($op['status'] ?? 500) >= 300 && !(key($item) === 'delete' && $op['status'] === 404);
        });
        if ($bad) {
            error_log('ES bulk 部分失敗：' . json_encode(array_slice(array_values($bad), 0, 2), JSON_UNESCAPED_UNICODE));
        }
        return !$bad;
    }
}
