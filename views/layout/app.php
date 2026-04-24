<!DOCTYPE html>
<html lang="zh-tw">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $this->escape(getenv('APP_NAME') ?: 'DataCC 地方議會資料瀏覽') ?></title>
    <link rel="shortcut icon" href="/static/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="/static/css/sb-admin-2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables.net-bs4/1.11.2/dataTables.bootstrap4.min.css" integrity="sha512-+RecGjm1x5bWxA/jwm9sqcn5EV0tNej3Xxq5HtIOLM9YM9VgI2LbhEDn099Xhxg6HuvrmsXR0J6JQxL7tLHFHw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <?= $this->yield('head-load') ?>
</head>
<body id="page-top">
    <div id="wrapper">

        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="<?= viewer_url() ?>">
                <div class="sidebar-brand-icon">
                    <i class="fas fa-city"></i>
                </div>
                <div class="sidebar-brand-text mx-3">DataCC</div>
            </a>
            <hr class="sidebar-divider my-0">

            <!-- 議會切換 -->
            <?php
                $current_code = CouncilHelper::getCurrentCode();
                $current_name = CouncilHelper::getName($current_code);
            ?>
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseCouncil"
                   aria-expanded="false" aria-controls="collapseCouncil">
                    <i class="fas fa-fw fa-landmark"></i>
                    <span class="text-truncate" style="max-width:130px; display:inline-block; vertical-align:middle;"><?= $this->escape($current_name) ?></span>
                    <i class="fas fa-caret-down ml-1"></i>
                </a>
                <div id="collapseCouncil" class="collapse" aria-labelledby="headingCouncil" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded" style="max-height:300px;overflow-y:auto;">
                        <h6 class="collapse-header">切換議會</h6>
                        <?php foreach (CouncilHelper::getAll() as $code => $name): ?>
                            <a class="collapse-item <?= $code === $current_code ? 'active' : '' ?>"
                               href="<?= $this->escape(CouncilHelper::getSwitchUrl($code)) ?>">
                                <?= $this->escape($name) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </li>

            <hr class="sidebar-divider">
            <li class="nav-item <?= $this->if($this->type == 'dashboard', 'active') ?>">
                <a class="nav-link" href="<?= viewer_url() ?>">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>首頁</span>
                </a>
            </li>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">資料</div>
            <?php foreach (TypeHelper::getTypeConfig() as $key => $config) { ?>
                <li class="nav-item <?= $this->if($this->type == $key, 'active') ?>">
                    <a class="nav-link" href="<?= viewer_url('/collection/list/' . $key) ?>">
                        <i class="<?= $this->escape($config['icon']) ?>"></i>
                        <span><?= $this->escape($config['name']) ?> / <?= $this->escape($key) ?></span>
                    </a>
                </li>
            <?php } ?>
            <hr class="sidebar-divider">
            <div class="sidebar-heading">工具</div>
            <li class="nav-item <?= $this->if($this->type == 'completeness', 'active') ?>">
                <a class="nav-link" href="<?= viewer_url('/collection/completeness') ?>">
                    <i class="fas fa-fw fa-tasks"></i>
                    <span>資料完整度</span>
                </a>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>

            <hr class="sidebar-divider">
            <div class="sidebar-heading">外部連結</div>
            <li class="nav-item">
                <a class="nav-link" href="/">
                    <i class="fas fa-fw fa-home"></i>
                    <span>CCAPI 首頁</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/about">
                    <i class="fas fa-fw fa-book-open"></i>
                    <span>說明文件</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/swagger" target="_blank">
                    <i class="fas fa-fw fa-code"></i>
                    <span>API 文件</span>
                </a>
            </li>
        </ul>
        <!-- End of Sidebar -->

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <div class="container-fluid">
                    <?= $this->yield('content') ?>
                </div>
            </div>

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">本頁面使用 API</h5>
                            <ul id="api-log">
                                <?php foreach (CCAPI::getLogs() as $log) { ?>
                                    <li>
                                        <a href="<?= $this->escape($log[0]) ?>" target="_blank"><?= $this->escape($log[1]) ?></a>
                                    </li>
                                <?php } ?>
                            </ul>
                            <script id="tmpl-api-log" type="text/html">
                            <li><a href="" target="_blank" class="link"></a></li>
                            </script>
                        </div>
                    </div>
                    <div class="copyright text-center my-auto">
                        <span>
                            DataCC 由 <a href="https://openfun.tw" target="_blank">歐噴有限公司</a> 開發，讓地方議會資料更透明更容易被使用
                        </span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js" integrity="sha512-igl8WEUuas9k5dtnhKqyyld6TzzRjvMqLC79jkgT3z02FvJyHAuUtyemm/P/jYSne1xwFI06ezQxEwweaiV7VA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-easing/1.3/jquery.easing.min.js" integrity="sha512-ahmSZKApTDNd3gVuqL5TQ3MBTj8tL5p2tYV05Xxzcfu6/ecvt1A0j6tfudSGBVuteSoTRMqMljbfdU0g2eDNUA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="/static/js/sb-admin-2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js" integrity="sha512-BkpSL20WETFylMrcirBahHfSnY++H2O1W+UnEEO4yNIl+jI2+zowyoGJpbtk6bx97fBXf++WJHSSK2MV4ghPcg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datatables.net-bs4/1.11.2/dataTables.bootstrap4.min.js" integrity="sha512-9o2JT4zBJghTU0EEIgPvzzHOulNvo0jq2spTfo6mMmZ6S3jK+gljrfo0mKDAxoMnrkZa6ml2ZgByBQ5ga8noDQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?= $this->yield('body-load') ?>
</body>
</html>
