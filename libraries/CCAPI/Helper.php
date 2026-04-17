<?php

class CCAPI_Helper
{
    public static function ucfirst($str)
    {
        // bill => Bill
        // gazette_agenda => GazetteAgenda
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
    }

    public static function getApiType($uri)
    {
        $url_terms = explode('/', trim($uri, '/'));
        if ($url_terms[0] == '') {
            return null;
        }
        $type_underscore = array_shift($url_terms);

        // 去掉結尾 s 的型式，優先嘗試直接匹配（如 completeness → Completeness.php），
        // 再嘗試去 es（如 completenesses → Completeness），最後去一個 s
        $type_pascal_direct = self::ucfirst($type_underscore);
        if (file_exists(__DIR__ . "/Type/{$type_pascal_direct}.php")) {
            // 直接匹配，不需要去掉結尾的 s（例：completeness、status 等）
            $type_underscore_singular = $type_underscore;
        } elseif (substr($type_underscore, -2) == 'es') {
            $singular_es = substr($type_underscore, 0, -2);
            $type_pascal_es = self::ucfirst($singular_es);
            if (file_exists(__DIR__ . "/Type/{$type_pascal_es}.php")) {
                $type_underscore_singular = $singular_es;
            } else {
                $type_underscore_singular = substr($type_underscore, 0, -1);
            }
        } elseif (substr($type_underscore, -1) == 's') {
            $type_underscore_singular = substr($type_underscore, 0, -1);
        } else {
            $type_underscore_singular = $type_underscore;
        }

        // 檔名是大駝峰
        $type_pascal = self::ucfirst($type_underscore_singular);

        // 如果 s 結尾且後面沒有參數了，就是 collection
        if (0 == count($url_terms) and substr($type_underscore, -1) == 's') {
            if (file_exists(__DIR__ . "/Type/{$type_pascal}.php")) {
                return ['api', 'collections', [$type_underscore_singular]];
            }
        }

        // 如果大駝峰檔案存在就是 item，網址是 laws/123 或 law/123 都可以對應到 Law.php
        if (file_exists(__DIR__ . "/Type/{$type_pascal}.php")) {
            $id_term_count = count(CCAPI_Type::run($type_underscore_singular, 'getIdFields'));
            $id = array_map('urldecode', array_slice($url_terms, 0, $id_term_count));
            $url_terms = array_slice($url_terms, $id_term_count);
            return ['api', 'item', [$type_underscore_singular, $id, $url_terms]];
        }

        return null;
    }
}
