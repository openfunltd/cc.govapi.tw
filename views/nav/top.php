<?php
// $active: 'home' | 'viewer' | 'swagger' | 'about'
$_nav_active = $active ?? '';
$_postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
$_home_url = 'https://all' . $_postfix . '/';
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
    <?php if (isset($cc_code) && $cc_code !== 'all'): ?>
    <span class="navbar-text text-light small">
      🏛 <?= htmlspecialchars($council_name ?? $cc_code) ?>
    </span>
    <?php endif; ?>
  </div>
</nav>
