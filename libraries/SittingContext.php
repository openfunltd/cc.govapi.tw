<?php

/**
 * 場次代碼 → 屆／會期代碼，給 meet／meet_note／meet_transcript 匯入補衍生欄位用。
 * 第一次查詢時用 scroll 把整個 sitting index（約5萬筆）一次讀進記憶體，取代每一列各
 * 查一次 ES（meet_note 9千多列逐筆查要15分鐘）。讀取失敗直接丟例外讓匯入中止，
 * 不會因為 ES 暫時出錯就把屆別寫成空值。
 */
class SittingContext
{
    protected static $map = null;

    public static function get($code)
    {
        if (!$code) {
            return ['屆' => null, '會期代碼' => null];
        }
        if (self::$map === null) {
            self::load();
        }
        return self::$map[$code] ?? ['屆' => null, '會期代碼' => null];
    }

    protected static function load()
    {
        self::$map = [];
        $r = Elastic::dbQuery('/{prefix}sitting/_search?scroll=2m', 'POST', json_encode([
            'size' => 5000,
            '_source' => ['屆', '會期代碼'],
            'query' => ['match_all' => new stdClass],
        ]));
        while ($r->hits->hits ?? []) {
            foreach ($r->hits->hits as $hit) {
                self::$map[$hit->_id] = [
                    '屆'       => $hit->_source->{'屆'} ?? null,
                    '會期代碼' => $hit->_source->{'會期代碼'} ?? null,
                ];
            }
            $r = Elastic::dbQuery('/_search/scroll', 'POST', json_encode(['scroll' => '2m', 'scroll_id' => $r->_scroll_id]));
        }
    }
}
