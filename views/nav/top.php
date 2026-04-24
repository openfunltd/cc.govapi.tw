<?php
// $active: 'home' | 'viewer' | 'swagger' | 'about'
// $cc_code, $council_name: optionally passed by caller
$_nav_active = $active ?? '';
$_postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$_home_url = 'https://all' . $_postfix . '/about';
$_nav_cc_code = $cc_code ?? CouncilHelper::getCurrentCode();
$_nav_council_name = $council_name ?? CouncilHelper::getName($_nav_cc_code);
$_nav_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<nav class="navbar navbar-expand-md navbar-dark bg-dark px-3">
  <a class="navbar-brand fw-semibold" href="<?= htmlspecialchars($_home_url) ?>">
    <span class="me-1">🏛</span> CCAPI
  </a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#ccapiNav">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="ccapiNav">
    <ul class="navbar-nav me-auto">
      <li class="nav-item">
        <a class="nav-link <?= $_nav_active === 'viewer' ? 'active' : '' ?>" href="/viewer">
          📊 資料瀏覽器
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $_nav_active === 'swagger' ? 'active' : '' ?>" href="/swagger">
          📄 API 文件
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $_nav_active === 'about' ? 'active' : '' ?>" href="/about">
          📖 說明
        </a>
      </li>
    </ul>
    <ul class="navbar-nav">
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?= $_nav_cc_code === 'all' ? '🌐 全國' : '🏛 ' . htmlspecialchars($_nav_council_name) ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header">切換議會</h6></li>
          <?php foreach (CouncilHelper::getAll() as $_c_code => $_c_name): ?>
          <li>
            <a class="dropdown-item <?= $_c_code === $_nav_cc_code ? 'active' : '' ?>"
               href="<?= htmlspecialchars('https://' . $_c_code . $_postfix . $_nav_path) ?>">
              <?= htmlspecialchars($_c_name) ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </li>
    </ul>
  </div>
</nav>
