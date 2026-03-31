<!DOCTYPE html>
<html lang="zh-tw">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <title>CCAPI — 地方議會開放 API</title>
</head>
<body class="bg-light">
<main role="main">
  <div class="container" style="max-width: 1120px;">
    <div class="pt-5 pb-4 text-center">
      <h1 class="display-4 fw-semibold">CCAPI</h1>
      <p class="lead">
        地方議會開放資料 API，讓地方議會資料透明易存取。<br>
        使用 <code>{城市代碼}.cc.govapi.tw</code> 存取特定縣市議會資料，或 <code>all.cc.govapi.tw</code> 進行跨議會查詢。
      </p>
    </div>

    <div class="row pb-5">
      <div class="col-md-8">
        <h2 class="fs-4 mb-3">API 使用範例</h2>
        <table class="table table-hover table-light">
          <thead>
            <tr>
              <th>說明</th>
              <th>請求</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>台北市議會第13屆議員名單</td>
              <td><code>GET tpe.cc.govapi.tw/councilors?屆=13</code></td>
            </tr>
            <tr>
              <td>全國民主進步黨議員</td>
              <td><code>GET all.cc.govapi.tw/councilors?黨籍=民主進步黨</code></td>
            </tr>
            <tr>
              <td>特定議員資料</td>
              <td><code>GET tpe.cc.govapi.tw/councilor/tpe/13/王大明</code></td>
            </tr>
            <tr>
              <td>各黨派議員數量統計</td>
              <td><code>GET all.cc.govapi.tw/councilors?agg=黨籍</code></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="col-md-4">
        <h2 class="fs-4 mb-3">支援議會</h2>
        <ul class="list-group">
          <?php foreach ($councils as $code => $name): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($name) ?>
              <span class="badge bg-secondary"><?= htmlspecialchars($code) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <footer class="py-4 border-top text-center text-body-secondary">
      <p>&copy; <?= date('Y') ?> CCAPI — 地方議會開放 API</p>
    </footer>
  </div>
</main>
</body>
</html>
