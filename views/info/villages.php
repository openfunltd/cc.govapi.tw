<?php
$year = $this->village_year ?? null;
$town = $this->village_town ?? null;
$base_url = '/info/' . (int) $this->term_no . '/agencies/villages';
$geo = $this->village_geo ?? null;
$section_name = ($this->has_townships ?? false) ? '鄉鎮市／村里長' : '村里長';
// 候選人姓名（有人物代碼就連到候選人頁）
$person_link = function ($c) {
    $name = htmlspecialchars($c->{'姓名'} ?? '');
    $html = ($c->{'人物代碼'} ?? null) ? '<a href="/info/candidate/' . rawurlencode($c->{'人物代碼'}) . '">' . $name . '</a>' : $name;
    // 補選當選的（任期中途出缺後選出）標出來，跟原本的當選人並列
    return $html . (($c->{'補選'} ?? false) ? '<span class="small text-body-secondary">（補選）</span>' : '');
};
// 首長職稱：鄉鎮的「鄉長／鎮長／市長」，山地原住民區是「區長」
$chief_title = function ($town_name) {
    $last = mb_substr((string) $town_name, -1);
    return ['鄉' => '鄉長', '鎮' => '鎮長', '市' => '市長', '區' => '區長'][$last] ?? '鄉鎮市長';
};
$rep_title = function ($town_name) {
    $last = mb_substr((string) $town_name, -1);
    return ['鄉' => '鄉民代表', '鎮' => '鎮民代表', '市' => '市民代表', '區' => '區民代表'][$last] ?? '代表';
};

// 地圖上每個區塊的資訊：代碼 → [名稱, 副標, 連結]
$map_items = [];
if ($town === null) {
    foreach (($this->village_towns ?? []) as $t) {
        $sub = [];
        foreach ($t['首長'] ?? [] as $c) {
            $sub[] = $chief_title($t['名稱']) . ' ' . ($c->{'姓名'} ?? '') . (($c->{'補選'} ?? false) ? '（補選）' : '');
        }
        if ($t['代表數'] ?? null) {
            $sub[] = $t['代表數'] . ' 位代表';
        }
        $sub[] = $t['村里數'] . ' 個村里';
        $map_items[$t['代碼']] = [$t['名稱'], implode('、', $sub), $base_url . '/' . rawurlencode($t['名稱'])];
    }
} else {
    foreach (($this->village_list ?? []) as $v) {
        $chief = $v['村里長'][0] ?? null;
        $map_items[$v['行政區代碼']] = [
            $v['村里'],
            $chief ? '村里長 ' . ($chief->{'姓名'} ?? '') : '無人當選',
            ($chief && ($chief->{'人物代碼'} ?? null)) ? '/info/candidate/' . rawurlencode($chief->{'人物代碼'}) : null,
        ];
    }
}
?>
<?php if (!$year): ?>
<div class="alert alert-light border">
  這一屆對應的村里長選舉資料還沒有收錄<?php if ($this->village_years ?? []): ?>（目前收錄民國 <?= implode('、', array_map('intval', $this->village_years)) ?> 年的村里長選舉）<?php endif; ?>
</div>
<?php return; endif; ?>

<?php if ($town !== null): ?>
<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= $base_url ?>"><?= $section_name ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($town) ?></li>
  </ol>
</nav>
<?php endif; ?>
<p class="small text-body-secondary mb-2">
  <?php if ($this->township_chief_year ?? null): ?>
  <?php [$chief_word, $rep_word] = ($this->has_townships ?? false) ? ['鄉鎮市長', '代表'] : ['山地原住民區長', '區民代表']; ?>
  民國 <?= (int) $this->township_chief_year ?> 年選出的<?= $chief_word ?><?php if (($this->township_rep_year ?? null) && $this->township_rep_year != $this->township_chief_year): ?>、<?= (int) $this->township_rep_year ?> 年選出的<?= $rep_word ?><?php else: ?>與<?= $rep_word ?><?php endif; ?>、<?= (int) $year ?> 年選出的村（里）長，跟本屆<?= htmlspecialchars(CouncilHelper::t('議員')) ?>同期。
  <?php else: ?>
  民國 <?= (int) $year ?> 年選出的村（里）長，跟本屆<?= htmlspecialchars(CouncilHelper::t('議員')) ?>同期。
  <?php endif; ?>
  <?= $town === null ? '點選行政區查看' . (($this->has_townships ?? false) ? '鄉鎮市長、代表與' : '') . '各村里的村里長。' : '點選姓名查看得票、學經歷與政見。' ?>
</p>

<?php if ($geo): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<div id="village-map" class="rounded border mb-1" style="height: 420px;"></div>
<p class="text-end text-body-secondary mb-3" style="font-size: .75rem;">行政區界資料來源：歐噴資料庫（data.openfun.tw）／內政部</p>
<script>
(function () {
  var items = <?= json_encode($map_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var map = L.map('village-map', { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(map);
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  fetch(<?= json_encode($geo) ?>).then(function (r) { return r.json(); }).then(function (data) {
    var layer = L.geoJSON(data, {
      style: function (f) {
        var known = !!items[f.properties['代碼']];
        return { color: '#0d6efd', weight: 1, fillColor: known ? '#0d6efd' : '#adb5bd', fillOpacity: known ? 0.15 : 0.1 };
      },
      onEachFeature: function (f, l) {
        var item = items[f.properties['代碼']];
        var name = item ? item[0] : f.properties['名稱'];
        l.bindTooltip(esc(name) + (item ? '<br><small>' + esc(item[1]) + '</small>' : ''), { sticky: true });
        l.on('mouseover', function () { l.setStyle({ fillOpacity: 0.4 }); });
        l.on('mouseout', function () { layer.resetStyle(l); });
        if (item && item[2]) {
          l.on('click', function () { location.href = item[2]; });
        }
      }
    }).addTo(map);
    map.fitBounds(layer.getBounds(), { padding: [8, 8] });
  });
})();
</script>
<?php endif; ?>

<?php if ($town === null): ?>
<?php if (!($this->village_towns ?? [])): ?>
<div class="alert alert-light border">查無村里長資料</div>
<?php else: ?>
<div class="row g-2">
  <?php foreach ($this->village_towns as $t): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <a class="card h-100 shadow-sm text-decoration-none" href="<?= $base_url ?>/<?= rawurlencode($t['名稱']) ?>">
      <div class="card-body p-2">
        <div class="fw-semibold"><?= htmlspecialchars($t['名稱']) ?></div>
        <?php foreach ($t['首長'] ?? [] as $c): ?>
        <div class="small"><?= $chief_title($t['名稱']) ?> <span class="text-body"><?= htmlspecialchars($c->{'姓名'} ?? '') ?></span><?= ($c->{'補選'} ?? false) ? '<span class="text-body-secondary">（補選）</span>' : '' ?></div>
        <?php endforeach; ?>
        <div class="small text-body-secondary"><?php if ($t['代表數'] ?? null): ?><?= (int) $t['代表數'] ?> 位代表・<?php endif; ?><?= (int) $t['村里數'] ?> 個村里</div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
<?php if ($this->township_chief_race ?? []): ?>
<h6 class="mt-3 mb-2"><?= $chief_title($town) ?><span class="small text-body-secondary fw-normal ms-2">民國 <?= (int) $this->township_chief_year ?> 年選舉</span></h6>
<div class="row g-2 mb-3">
  <?php foreach ($this->township_chief_race as $c): ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="border rounded px-2 py-1 h-100">
      <span class="fw-semibold"><?= $person_link($c) ?></span>
      <span class="small text-body-secondary ms-1"><?= htmlspecialchars($c->{'其他欄位'}->{'推薦之政黨'} ?? '') ?></span>
      <?php if (isset($c->{'得票數'})): ?>
      <div class="small text-body-secondary">得票 <?= number_format($c->{'得票數'}) ?><?= isset($c->{'得票率'}) ? '（' . htmlspecialchars($c->{'得票率'}) . '%）' : '' ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($this->township_reps ?? []): ?>
<h6 class="mt-3 mb-2"><?= $rep_title($town) ?><span class="small text-body-secondary fw-normal ms-2">民國 <?= (int) $this->township_rep_year ?> 年選出，<?= count(array_filter(array_merge(...array_values($this->township_reps)), fn($c) => !($c->{'補選'} ?? false))) ?> 席</span></h6>
<div class="row g-2 mb-3">
  <?php foreach ($this->township_reps as $district => $reps): ?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="border rounded px-2 py-1 h-100">
      <div class="small text-body-secondary"><?= htmlspecialchars($district) ?></div>
      <?= implode('、', array_map($person_link, $reps)) ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (($this->township_chief_race ?? []) || ($this->township_reps ?? [])): ?>
<h6 class="mt-3 mb-2">村（里）長</h6>
<?php endif; ?>
<?php if (!($this->village_list ?? [])): ?>
<div class="alert alert-light border">查無「<?= htmlspecialchars($town) ?>」的村里長資料</div>
<?php else: ?>
<div class="row g-2">
  <?php foreach ($this->village_list as $v): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="border rounded px-2 py-1 h-100">
      <span class="fw-semibold"><?= htmlspecialchars($v['村里']) ?></span>
      <span class="ms-1">
        <?php if (!$v['村里長']): ?>
        <span class="small text-body-secondary">無人當選</span>
        <?php endif; ?>
        <?= implode('、', array_map($person_link, $v['村里長'])) ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
