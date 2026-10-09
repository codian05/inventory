<?php
declare(strict_types=1);

/**
 * sidebar.php
 * Left navigation sidebar. Auth.php + functions.php must already be loaded.
 *
 * NOTE ON SCOPE: Dashboard, Inventory (Stock In/Out, Transactions,
 * Batches, Expiration/Low-Stock Monitoring, Notifications), Stock
 * Transfer, Products, Categories, Suppliers, Branches (admin), Users
 * (admin), Reports (admin), My Profile, and Change Password all exist
 * now.
 */

$role        = current_user_role() ?: 'guest';
$currentPage = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$baseUrl     = rtrim(defined('APP_URL') ? APP_URL : '', '/');
$userId      = current_user_id();
$roleFolder  = $role === 'admin' ? 'admin' : 'staff';

$dashboardUrl    = $baseUrl . '/' . $roleFolder . '/dashboard.php';
$categoriesUrl   = $baseUrl . '/' . $roleFolder . '/categories.php';
$suppliersUrl    = $baseUrl . '/' . $roleFolder . '/suppliers.php';
$productsUrl     = $baseUrl . '/' . $roleFolder . '/products.php';
$inventoryUrl    = $baseUrl . '/' . $roleFolder . '/inventory.php';
$stockInUrl      = $baseUrl . '/' . $roleFolder . '/stock_in.php';
$stockOutUrl     = $baseUrl . '/' . $roleFolder . '/stock_out.php';
$transactionsUrl = $baseUrl . '/' . $roleFolder . '/transactions.php';
$transferUrl     = $baseUrl . '/' . $roleFolder . '/stock_transfer.php';
$transfersUrl    = $baseUrl . '/' . $roleFolder . '/transfers.php';
$profileUrl      = $baseUrl . '/' . $roleFolder . '/user_profile.php?id=' . $userId;
$passwordUrl     = $baseUrl . '/' . $roleFolder . '/user_password.php?id=' . $userId;
$categoryPages   = ['categories.php', 'category_view.php', 'category_create.php', 'category_edit.php'];
$supplierPages   = ['suppliers.php', 'supplier_view.php', 'supplier_create.php', 'supplier_edit.php'];
$productPages    = ['products.php', 'product_view.php', 'product_create.php', 'product_edit.php'];
$branchPages     = ['branches.php', 'branch_view.php', 'branch_create.php', 'branch_edit.php', 'branches_deleted.php'];
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header text-center">
        <span class="sidebar-brand-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span>
        <div class="fw-bold mt-1"><?= e(defined('APP_NAME') ? APP_NAME : 'AIMS') ?></div>
        <small><?= $role === 'admin' ? 'Admin Panel' : 'Staff Panel' ?></small>
    </div>

    <nav class="nav flex-column p-2 sidebar-nav">
        <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
           href="<?= e($dashboardUrl) ?>">
            <i class="bi bi-speedometer2 me-2"></i> <span class="nav-label">Dashboard</span>
        </a>

        <div class="sidebar-section-label">Inventory</div>

        <a class="nav-link <?= $currentPage === 'inventory.php' ? 'active' : '' ?>"
           href="<?= e($inventoryUrl) ?>">
            <i class="bi bi-boxes me-2"></i> <span class="nav-label">Inventory</span>
        </a>

        <a class="nav-link <?= $currentPage === 'stock_in.php' ? 'active' : '' ?>"
           href="<?= e($stockInUrl) ?>">
            <i class="bi bi-box-arrow-in-down me-2"></i> <span class="nav-label">Stock In</span>
        </a>

        <a class="nav-link <?= $currentPage === 'stock_out.php' ? 'active' : '' ?>"
           href="<?= e($stockOutUrl) ?>">
            <i class="bi bi-box-arrow-up me-2"></i> <span class="nav-label">Stock Out</span>
        </a>

        <a class="nav-link <?= $currentPage === 'transactions.php' ? 'active' : '' ?>"
           href="<?= e($transactionsUrl) ?>">
            <i class="bi bi-clock-history me-2"></i> <span class="nav-label">Transaction History</span>
        </a>

        <a class="nav-link <?= $currentPage === 'stock_transfer.php' ? 'active' : '' ?>"
           href="<?= e($transferUrl) ?>">
            <i class="bi bi-arrow-left-right me-2"></i> <span class="nav-label">Stock Transfer</span>
        </a>

        <a class="nav-link <?= $currentPage === 'transfers.php' ? 'active' : '' ?>"
           href="<?= e($transfersUrl) ?>">
            <i class="bi bi-clock-history me-2"></i> <span class="nav-label">Transfer History</span>
        </a>

        <?php if ($role === 'admin'): ?>
            <a class="nav-link <?= $currentPage === 'batches.php' || $currentPage === 'batch_view.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/batches.php">
                <i class="bi bi-layers me-2"></i> <span class="nav-label">Batch Monitoring</span>
            </a>

            <a class="nav-link <?= $currentPage === 'expiration_monitoring.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/expiration_monitoring.php">
                <i class="bi bi-calendar-x me-2"></i> <span class="nav-label">Expiration Monitoring</span>
            </a>

            <a class="nav-link <?= $currentPage === 'low_stock_monitoring.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/low_stock_monitoring.php">
                <i class="bi bi-exclamation-triangle me-2"></i> <span class="nav-label">Low Stock Monitoring</span>
            </a>

            <a class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/notifications.php">
                <i class="bi bi-bell me-2"></i> <span class="nav-label">Notifications</span>
            </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Catalog</div>

        <a class="nav-link <?= in_array($currentPage, $productPages, true) ? 'active' : '' ?>"
           href="<?= e($productsUrl) ?>">
            <i class="bi bi-box-seam me-2"></i> <span class="nav-label">Products</span>
        </a>

        <a class="nav-link <?= in_array($currentPage, $categoryPages, true) ? 'active' : '' ?>"
           href="<?= e($categoriesUrl) ?>">
            <i class="bi bi-tags me-2"></i> <span class="nav-label">Categories</span>
        </a>

        <a class="nav-link <?= in_array($currentPage, $supplierPages, true) ? 'active' : '' ?>"
           href="<?= e($suppliersUrl) ?>">
            <i class="bi bi-truck me-2"></i> <span class="nav-label">Suppliers</span>
        </a>

        <?php if ($role === 'admin'): ?>
            <a class="nav-link <?= $currentPage === 'products_deleted.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/products_deleted.php">
                <i class="bi bi-trash3 me-2"></i> <span class="nav-label">Deleted Products</span>
            </a>

            <a class="nav-link <?= $currentPage === 'categories_deleted.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/categories_deleted.php">
                <i class="bi bi-trash3 me-2"></i> <span class="nav-label">Deleted Categories</span>
            </a>

            <a class="nav-link <?= $currentPage === 'suppliers_deleted.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/suppliers_deleted.php">
                <i class="bi bi-trash3 me-2"></i> <span class="nav-label">Deleted Suppliers</span>
            </a>

            <div class="sidebar-section-label">Administration</div>

            <a class="nav-link <?= in_array($currentPage, $branchPages, true) ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/branches.php">
                <i class="bi bi-shop me-2"></i> <span class="nav-label">Branches</span>
            </a>

            <a class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/users.php">
                <i class="bi bi-people me-2"></i> <span class="nav-label">Users</span>
            </a>

            <a class="nav-link <?= $currentPage === 'user_create.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/user_create.php">
                <i class="bi bi-person-plus me-2"></i> <span class="nav-label">Add User</span>
            </a>

            <a class="nav-link <?= $currentPage === 'users_deleted.php' ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/users_deleted.php">
                <i class="bi bi-trash3 me-2"></i> <span class="nav-label">Deleted Users</span>
            </a>

            <a class="nav-link <?= in_array($currentPage, ['reports.php', 'report_view.php'], true) ? 'active' : '' ?>"
               href="<?= e($baseUrl) ?>/admin/reports.php">
                <i class="bi bi-file-earmark-bar-graph me-2"></i> <span class="nav-label">Reports</span>
            </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Account</div>

        <a class="nav-link <?= $currentPage === 'user_profile.php' ? 'active' : '' ?>"
           href="<?= e($profileUrl) ?>">
            <i class="bi bi-person-circle me-2"></i> <span class="nav-label">My Profile</span>
        </a>

        <a class="nav-link <?= $currentPage === 'user_password.php' ? 'active' : '' ?>"
           href="<?= e($passwordUrl) ?>">
            <i class="bi bi-key me-2"></i> <span class="nav-label">Change Password</span>
        </a>

        <hr class="sidebar-divider">

        <a class="nav-link text-danger" href="<?= e($baseUrl) ?>/logout.php">
            <i class="bi bi-box-arrow-right me-2"></i> <span class="nav-label">Logout</span>
        </a>
    </nav>
</aside>
