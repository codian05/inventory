<?php
/**
 * header.php
 * Top HTML, head, navbar, flash messages, toast container.
 * Requires: config.php, session.php, functions.php, auth.php already loaded.
 */

$pageTitle = $pageTitle ?? 'Dashboard';
$bodyClass = $bodyClass ?? '';

$flashes = get_flash();
$role = current_user_role() ?: 'guest';
$currentUserId = current_user_id();
$currentUserName = current_user_name();

$appName = defined('APP_NAME') ? APP_NAME : 'Application';
$appUrl  = defined('APP_URL') ? APP_URL : '';

// Notification bell feed — computed live from products/users tables.
// Wrapped defensively inside the helper itself, so this never breaks
// a page even before any products exist. Staff always see only their
// own branch's alerts here, regardless of which page they're on —
// admin sees the system-wide feed (their dashboard's branch filter is
// a separate, page-local view and doesn't change the global bell).
$navBranchId = $role === 'admin' ? null : current_user_branch_id();
$navNotifications = dashboard_notifications(6, $navBranchId);
$navCriticalCount = notifications_critical_count($navBranchId);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        /* Apply the saved theme before first paint so the page never
           flashes light-then-dark when a user has dark mode saved. */
        (function () {
            try {
                var t = localStorage.getItem('inv-theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-bs-theme', t);
                }
            } catch (e) {}
        })();
    </script>
    <?= csrf_meta() ?>
    <title><?= e($pageTitle) ?> &middot; <?= e($appName) ?></title>

    <link href="<?= e($appUrl) ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($appUrl) ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e($appUrl) ?>/assets/css/fonts.css" rel="stylesheet">
    <link href="<?= e($appUrl) ?>/assets/css/dashboard.css" rel="stylesheet">
</head>
<body class="<?= e($bodyClass) ?>">

<div class="wrapper d-flex">

    <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <nav class="navbar navbar-expand-lg sticky-top topbar">
            <div class="container-fluid">
                <button class="btn btn-light btn-sm d-lg-none me-2" id="sidebarToggle" type="button"
                        aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="sidebar">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <span class="navbar-brand mb-0 h1 d-none d-md-inline"><?= e($pageTitle) ?></span>

                <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
                    <button class="btn btn-sm btn-outline-secondary" id="themeToggle" type="button" title="Toggle theme" aria-label="Toggle dark mode">
                        <i class="bi bi-moon-stars" aria-hidden="true"></i>
                    </button>

                    <div class="dropdown notif-dropdown">
                        <a href="#" class="btn btn-sm btn-outline-secondary position-relative" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications" title="Notifications">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <?php if ($navCriticalCount > 0): ?>
                                <span class="notif-badge badge rounded-pill bg-danger">
                                    <?= $navCriticalCount > 99 ? '99+' : (int) $navCriticalCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end p-0 notif-panel">
                            <div class="notif-panel-header px-3 py-2 d-flex justify-content-between align-items-center">
                                <strong>Notifications</strong>
                                <?php if ($navCriticalCount > 0): ?>
                                    <span class="badge bg-danger-subtle text-danger"><?= (int) $navCriticalCount ?> need attention</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">All clear</span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-panel-body">
                                <?php if (empty($navNotifications)): ?>
                                    <div class="text-center text-muted py-4 px-3">
                                        <i class="bi bi-check2-circle fs-3 d-block mb-1 text-success"></i>
                                        Nothing needs your attention right now.
                                    </div>
                                <?php else: foreach ($navNotifications as $n): ?>
                                    <div class="notif-item d-flex gap-2 px-3 py-2">
                                        <div class="notif-icon text-<?= e($n['type']) ?>">
                                            <i class="bi <?= e($n['icon']) ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="small fw-semibold"><?= e($n['title']) ?></div>
                                            <div class="small text-muted"><?= e($n['description']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="notif-panel-footer px-3 py-2 text-center">
                                <a href="<?= e($appUrl . '/' . ($role === 'admin' ? 'admin' : 'staff') . '/dashboard.php') ?>"
                                   class="small text-decoration-none">View full dashboard</a>
                            </div>
                        </div>
                    </div>

                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle"
                           data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for <?= e($currentUserName) ?>">
                            <span class="me-2 fw-semibold d-none d-sm-inline"><?= e($currentUserName) ?></span>
                            <span class="badge bg-<?= $role === 'admin' ? 'danger' : 'info' ?>">
                                <?= e(ucfirst($role)) ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?= e($appUrl) ?>/<?= $role === 'admin' ? 'admin' : 'staff' ?>/user_profile.php?id=<?= $currentUserId ?>">
                                    <i class="bi bi-person me-2"></i> My Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= e($appUrl) ?>/<?= $role === 'admin' ? 'admin' : 'staff' ?>/user_password.php?id=<?= $currentUserId ?>">
                                    <i class="bi bi-key me-2"></i> Change Password
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= e($appUrl) ?>/logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <main>
            <?php if (!empty($flashes)): ?>
                <?php foreach ($flashes as $f): ?>
                    <?php
                        $type = isset($f['type']) ? (string) $f['type'] : 'info';
                        $message = isset($f['message']) ? (string) $f['message'] : '';
                    ?>
                    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
                        <?= e($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
