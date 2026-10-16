<?php
/**
 * 匯出鄉鎮市區、村里邊界 GeoJSON，給「行政體系 → 村里長」的地圖用
 *
 * 用法：
 *   OPENFUN_TOKEN=xxx php scripts/export-geo.php
 *
 * 來源是歐噴資料庫 tw.openfun~entity~geo 資料集（內政部社會經濟資料服務平台的行政區界），
 * 需要 data.openfun.tw 的 API token（Device Authorization 取得的短效 token 即可）。
 * 只取現行（_as_of=now）的行政區，輸出：
 *   static/geo/town/{縣市代碼}.json      該縣市所有鄉鎮市區的邊界（FeatureCollection）
 *   static/geo/village/{鄉鎮市區代碼}.json 該鄉鎮市區所有村里的邊界
 * 每個 Feature 的 properties：代碼、名稱。邊界有簡化過（見下方 $simplify），檔案才不會太大。
 * 行政區界很少變動，不需要排程，有改制或村里調整時手動重跑。
 *
 * 授權標注：資料來源：歐噴資料庫（data.openfun.tw）／內政部
 */

include(__DIR__ . '/../init.inc.php');

$token = getenv('OPENFUN_TOKEN');
if (!$token) {
    error_log('ERROR: 請設定 OPENFUN_TOKEN 環境變數（data.openfun.tw API token）');
    exit(1);
}

$fetch = function ($url) use ($token) {
    for ($try = 0; $try < 3; $try++) {
        $curl = curl_init('https://data.openfun.tw/api/v1' . $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 120);
        $res = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($code === 200 && ($json = json_decode($res, true))) {
            return $json;
        }
        error_log("WARNING: HTTP {$code} {$url}，重試");
        sleep(3);
    }
    error_log("ERROR: 無法取得 {$url}");
    exit(1);
};
$records = fn($query) => $fetch('/datasets/tw.openfun~entity~geo/records?_as_of=now&per_page=500&' . $query)['records'] ?? [];

// Douglas-Peucker 簡化：原始邊界點數很多（一個縣市的鄉鎮界 1~2MB），網頁地圖用不到這麼細，
// 鄉鎮層級容許誤差約 30 公尺、村里層級約 8 公尺，簡化後再四捨五入座標
$simplify_line = function ($pts, $tolerance) use (&$simplify_line) {
    $n = count($pts);
    if ($n < 3) {
        return $pts;
    }
    [$ax, $ay] = $pts[0];
    [$bx, $by] = $pts[$n - 1];
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $len2 = $dx * $dx + $dy * $dy;
    $max = 0;
    $index = 0;
    for ($i = 1; $i < $n - 1; $i++) {
        [$px, $py] = $pts[$i];
        if ($len2 == 0) {
            $d = hypot($px - $ax, $py - $ay);
        } else {
            $d = abs($dy * $px - $dx * $py + $bx * $ay - $by * $ax) / sqrt($len2);
        }
        if ($d > $max) {
            $max = $d;
            $index = $i;
        }
    }
    if ($max <= $tolerance) {
        return [$pts[0], $pts[$n - 1]];
    }
    $left = $simplify_line(array_slice($pts, 0, $index + 1), $tolerance);
    $right = $simplify_line(array_slice($pts, $index), $tolerance);
    return array_merge(array_slice($left, 0, -1), $right);
};
// 環狀邊界首尾相同，從中間切成兩段各自簡化，避免整圈被簡化成一條線
$simplify_ring = function ($ring, $tolerance, $precision) use ($simplify_line) {
    $mid = intdiv(count($ring), 2);
    $pts = array_merge(
        array_slice($simplify_line(array_slice($ring, 0, $mid + 1), $tolerance), 0, -1),
        $simplify_line(array_slice($ring, $mid), $tolerance)
    );
    $out = [];
    foreach ($pts as $p) {
        $p = [round($p[0], $precision), round($p[1], $precision)];
        if (!$out || $out[count($out) - 1] !== $p) {
            $out[] = $p;
        }
    }
    return count($out) >= 4 ? $out : null;
};
$simplify = function ($geometry, $tolerance, $precision) use ($simplify_ring) {
    $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
    $result = [];
    foreach ($polygons as $polygon) {
        $rings = [];
        foreach ($polygon as $i => $ring) {
            $r = $simplify_ring($ring, $tolerance, $precision);
            if ($r) {
                $rings[] = $r;
            } elseif ($i === 0) {
                break;  // 外圈簡化後太小（小島），整個多邊形略過
            }
        }
        if ($rings) {
            $result[] = $rings;
        }
    }
    if (!$result) {
        return null;
    }
    return count($result) === 1 ? ['type' => 'Polygon', 'coordinates' => $result[0]] : ['type' => 'MultiPolygon', 'coordinates' => $result];
};
// 依代碼批次取邊界（API 一次上限 200 筆），換成只帶代碼、名稱的 FeatureCollection
$shapes = function ($names, $tolerance, $precision) use ($fetch, $simplify) {
    $features = [];
    foreach (array_chunk(array_keys($names), 200) as $ids) {
        $r = $fetch('/geo/shapes/tw.openfun~entity~geo?ids=' . implode(',', $ids));
        foreach ($r['features'] ?? [] as $f) {
            $id = $f['properties']['record_id'];
            $geometry = $simplify($f['geometry'], $tolerance, $precision);
            if (!$geometry) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => ['代碼' => $id, '名稱' => $names[$id] ?? ''],
                'geometry' => $geometry,
            ];
        }
    }
    usort($features, fn($a, $b) => strcmp($a['properties']['代碼'], $b['properties']['代碼']));
    return ['type' => 'FeatureCollection', 'features' => $features];
};
$write = function ($path, $data) {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
};

$out = __DIR__ . '/../static/geo';
$town_count = $village_count = 0;
foreach ($records('level=county') as $county) {
    $towns = [];
    foreach ($records('level=town&county_id=' . $county['id']) as $t) {
        $towns[$t['id']] = $t['town_name'];
    }
    if (!$towns) {
        continue;
    }
    $write("{$out}/town/{$county['id']}.json", $shapes($towns, 0.0003, 4));
    $town_count += count($towns);
    foreach ($towns as $town_id => $town_name) {
        $villages = [];
        foreach ($records('level=village&county_id=' . $county['id'] . '&town_id=' . $town_id) as $v) {
            $villages[$v['id']] = $v['village_name'];
        }
        if ($villages) {
            $write("{$out}/village/{$town_id}.json", $shapes($villages, 0.00008, 5));
            $village_count += count($villages);
        }
    }
    error_log("{$county['county_name']}：" . count($towns) . ' 個鄉鎮市區');
}
error_log("Done. {$town_count} towns, {$village_count} villages.");
