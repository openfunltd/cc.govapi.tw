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

    /**
     * 回傳去掉可疑欄位（連同「X來源」）的紀錄，與被去掉的欄位名
     */
    public static function sanitize(array $record): array
    {
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
}
