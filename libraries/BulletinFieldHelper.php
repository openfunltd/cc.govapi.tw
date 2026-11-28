<?php

/**
 * 選舉公報萃取結果的欄位檢查：表格欄位錯位時，學歷／經歷／政見會裝到別的欄位的內容
 * （實測 111 年花蓮縣秀林鄉村長公報的學歷是「中國國民黨」、103 年金門縣烏坵鄉的學歷是「福建省金門縣」，
 * 111 年彰化縣溪州鄉的政見混進整頁的公報標題與別人的資料）。看起來是別的欄位的內容就不採用，
 * 寧可顯示沒有資料，也不要把錯的內容當成這位候選人的學經歷、政見。
 */
class BulletinFieldHelper
{
    // 政黨名稱（去掉空白、換行後整段就是這些之一）
    const PARTIES = ['中國國民黨', '國民黨', '民主進步黨', '民進黨', '台灣民眾黨', '臺灣民眾黨', '時代力量', '親民黨',
        '新黨', '台灣團結聯盟', '臺灣團結聯盟', '無黨團結聯盟', '台灣基進', '綠黨', '無', '無黨籍', '無黨籍及未經政黨推薦'];

    // 公報卡片的「候選人之具結情形」整段文字：版面窄時被切成好幾行混進學經歷、政見
    const DECLARATIONS = ['候選人之具結情形☑候選人聲明確未在大陸地區設有戶籍(含大陸地區居民身分證及定居證)或領用大陸地區護照',
        '具結事項☑候選人聲明確不具中華民國以外國家國籍'];
    // 印在公報頁面上的反賄選標語
    const SLOGANS = ['賄選人士必貪污。', '賄選人士必貪污', '民主ㄟ頭家，選票嘸通賣', '檢舉賄選要勇敢，政治清明有期盼', '全民動起來，反賄為將來'];

    /**
     * 去掉混進學經歷、政見的具結情形片段與反賄選標語，學歷尾端整段重複經歷的去掉
     */
    public static function clean(array $record): array
    {
        $norm = fn($s) => strtr(preg_replace('/[\s\x{3000}□]+/u', '', $s), ['户' => '戶', '（' => '(', '）' => ')', '☐' => '☑']);
        $decl = array_map($norm, self::DECLARATIONS);
        $is_fragment = function ($line) use ($norm, $decl) {
            $flat = str_replace('☑', '', $norm($line));
            if (mb_strlen($flat) < 3) {
                return false;
            }
            foreach ($decl as $d) {
                if (mb_strpos(str_replace('☑', '', $d), $flat) !== false) {
                    return true;
                }
            }
            return false;
        };
        foreach (['學歷', '經歷', '政見'] as $field) {
            if (!is_string($record[$field] ?? null) || $record[$field] === '') {
                continue;
            }
            $text = $record[$field];
            if ($field === '政見') {
                $text = str_replace(self::SLOGANS, '', $text);
            }
            $lines = array_filter(explode("\n", $text), fn($l) => !$is_fragment($l));
            $record[$field] = trim(implode("\n", $lines));
        }
        // 學歷欄把經歷整段也收進來（學歷最後幾行就是經歷）
        $edu = explode("\n", (string) ($record['學歷'] ?? ''));
        $exp = array_values(array_filter(explode("\n", (string) ($record['經歷'] ?? '')), fn($l) => mb_strlen(trim($l)) > 3));
        if ($exp && count($edu) > count($exp) && array_slice($edu, -count($exp)) === $exp) {
            $record['學歷'] = trim(implode("\n", array_slice($edu, 0, -count($exp))));
        }
        return $record;
    }

    /**
     * 回傳去掉可疑欄位（連同「X來源」）的紀錄，與被去掉的欄位名
     */
    public static function sanitize(array $record): array
    {
        $record = self::clean($record);
        // 直書欄位的基本資料每個字中間有換行（「民\n國\n60\n年…」「臺\n灣\n省…」），這幾個欄位本身不含空白
        foreach (['出生年月日', '出生地', '性別', '推薦之政黨'] as $key) {
            if (is_string($record['其他欄位'][$key] ?? null)) {
                $record['其他欄位'][$key] = str_replace('⼥', '女', preg_replace('/[\s\x{3000}]+/u', '', $record['其他欄位'][$key]));
            }
        }
        $dropped = [];
        foreach (['學歷', '經歷', '政見'] as $field) {
            $text = (string) ($record[$field] ?? '');
            if ($text !== '' && self::looksWrong($field, $text)) {
                unset($record[$field], $record[$field . '來源']);
                $dropped[] = $field;
            }
        }
        return [$record, $dropped];
    }

    public static function looksWrong(string $field, string $text): bool
    {
        $flat = preg_replace('/\s+/u', '', $text);
        if ($flat === '') {
            return false;
        }
        // 整段就是政黨名稱、出生地（「福建省金門縣」「臺灣省彰化縣」「臺北市」）或出生年月日
        if (in_array($flat, self::PARTIES, true)) {
            return true;
        }
        if (preg_match('/^(臺灣省|台灣省|福建省)?\p{Han}{1,3}[縣市]$/u', $flat)) {
            return true;
        }
        if (preg_match('/^\d{2,3}年\d{1,2}月\d{1,2}日$/u', $flat)) {
            return true;
        }
        // 混進公報標題、或別位候選人的整列資料（「…村長選舉候選人：」）
        if (preg_match('/選舉候選人[:：]|選舉公報|候選人所填報之政見/u', $flat)) {
            return true;
        }
        return false;
    }

    /**
     * 公報的「推薦之政黨」：直書欄位萃取出來常常每個字中間有換行（「中\n國\n國\n民\n黨」），
     * 政黨名稱本身不含空白，全部去掉；去掉後是空字串，或是欄位標題「推薦之政黨」被打散的，回傳 null
     */
    public static function normalizeParty(?string $party): ?string
    {
        $flat = preg_replace('/[\s\x{3000}]+/u', '', (string) $party);
        if ($flat === '' || count(array_diff(mb_str_split($flat), mb_str_split('推薦之政黨'))) === 0) {
            return null;
        }
        return $flat;
    }

    /**
     * 欄位補充檔（IMPORT_CANDIDATE_FIELD_SUPPLEMENT_JSONL，逗號分隔）：對照公報頁面重新照抄的結果，
     * 每行 {候選人代碼, 學歷?, 經歷?, 政見?, 出生年月日?, 性別?, 出生地?, 來源?}，同一代碼多行時後面的蓋前面的
     */
    public static function loadFieldSupplement(): array
    {
        $supplement = [];
        foreach (array_filter(array_map('trim', explode(',', (string) getenv('IMPORT_CANDIDATE_FIELD_SUPPLEMENT_JSONL')))) as $path) {
            if (!file_exists($path)) {
                error_log("WARNING: 找不到欄位補充檔（IMPORT_CANDIDATE_FIELD_SUPPLEMENT_JSONL）：{$path}");
                continue;
            }
            foreach (file($path) as $line) {
                $d = json_decode($line, true);
                if (!empty($d['候選人代碼'])) {
                    $supplement[$d['候選人代碼']] = array_merge($supplement[$d['候選人代碼']] ?? [], $d);
                }
            }
        }
        return $supplement;
    }

    /**
     * 套用欄位補充：有的欄位直接取代原本的值；值是空字串代表公報上那一格確實是空的，原本的值（多半是錯位裝進來的
     * 別欄文字）拿掉。出生年月日、性別、出生地放進「其他欄位」
     */
    public static function applyFieldSupplement(array $doc, ?array $fix): array
    {
        if (!$fix) {
            return $doc;
        }
        foreach (['學歷', '經歷', '政見'] as $field) {
            if (!array_key_exists($field, $fix)) {
                continue;
            }
            $value = trim((string) $fix[$field]);
            if ($value === '') {
                unset($doc[$field], $doc[$field . '來源']);
            } else {
                $doc[$field] = $value;
                $doc[$field . '來源'] = $fix['來源'] ?? 'page-image-vision';
            }
        }
        foreach (['出生年月日', '性別', '出生地'] as $field) {
            if (trim((string) ($fix[$field] ?? '')) !== '') {
                $doc['其他欄位'][$field] = trim($fix[$field]);
            }
        }
        return $doc;
    }
}
