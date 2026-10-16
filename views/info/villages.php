<?php
$year = $this->village_year ?? null;
$town = $this->village_town ?? null;
$base_url = '/info/' . (int) $this->term_no . '/agencies/villages';
$geo = $this->village_geo ?? null;

// 地圖上每個區塊的資訊：代碼 → [名稱, 副標, 連結]
$map_items = [];
if ($town === null) {
    foreach (($this->village_towns ?? []) as $t) {
        $map_items[$t['代碼']] = [$t['名稱'], $t['村里數'] . ' 個村里', $base_url . '/' . rawurlencode($t['名稱'])];
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
    <li class="breadcrumb-item"><a href="<?= $base_url ?>">村里長</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($town) ?></li>
  </ol>
</nav>
<?php endif; ?>
<p class="small text-body-secondary mb-2">
  民國 <?= (int) $year ?> 年選出的村（里）長，跟本屆<?= htmlspecialchars(CouncilHelper::t('議員')) ?>同期。<?= $town === null ? '點選行政區查看各村里的村里長。' : '點選姓名查看得票、學經歷與政見。' ?>
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
        <span class="fw-semibold"><?= htmlspecialchars($t['名稱']) ?></span>
        <span class="small text-body-secondary ms-1"><?= (int) $t['村里數'] ?> 個村里</span>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
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
        <?php foreach ($v['村里長'] as $c): ?>
        <?php if ($c->{'人物代碼'} ?? null): ?>
        <a href="/info/candidate/<?= rawurlencode($c->{'人物代碼'}) ?>"><?= htmlspecialchars($c->{'姓名'} ?? '') ?></a>
        <?php else: ?>
        <?= htmlspecialchars($c->{'姓名'} ?? '') ?>
        <?php endif; ?>
        <?php endforeach; ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
