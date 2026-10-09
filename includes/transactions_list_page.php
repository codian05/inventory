<?php
declare(strict_types=1);

/**
 * transactions_list_page.php
 * Shared by admin/transactions.php and staff/transactions.php.
 * Every Stock In / Stock Out / Adjustment, filterable by product,
 * supplier, user, barcode, type, and date range.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$myBranchId = current_user_branch_id();

$search     = clean_input($_GET['search'] ?? '');
$type       = clean_input($_GET['type'] ?? '');
$barcode    = clean_input($_GET['barcode'] ?? '');
$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$userId     = (int) ($_GET['user_id'] ?? 0);
$range      = clean_input($_GET['range'] ?? ''); // 'today','week','month'
$dateFrom   = clean_input($_GET['date_from'] ?? '');
$dateTo     = clean_input($_GET['date_to'] ?? '');
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = PER_PAGE;

// Admin: "All Branches" or a validated selection. Staff: always their
// own branch — never a request parameter.
if ($isAdmin) {
    $selectedBranchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
        ? (int) $_GET['branch_id']
        : null;
    if ($selectedBranchId !== null && !fetch_branch($selectedBranchId, true)) {
        $selectedBranchId = null;
    }
} else {
    $selectedBranchId = $myBranchId;
}
$allBranches = branch_options($selectedBranchId);

if ($range === 'today') {
    $dateFrom = $dateTo = date('Y-m-d');
} elseif ($range === 'week') {
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $dateTo = date('Y-m-d');
} elseif ($range === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
}

$filters = array_filter([
    'search' => $search,
    'type' => $type,
    'barcode' => $barcode,
    'supplier_id' => $supplierId ?: null,
    'user_id' => $userId ?: null,
    'branch_id' => $selectedBranchId ?: null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);

$total = transaction_history_count($filters);
[$totalPages, $offset, $page] = paginate($total, $perPage, $page);
$rows = inventory_history($filters, $perPage, $offset);

$supplierChoices = supplier_options();
$userChoices = db()->query("SELECT id, fullname FROM users WHERE deleted_at IS NULL ORDER BY fullname ASC")->fetchAll();

$pageTitle = 'Transaction History';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Transaction History</h2>
        <div class="text-muted">Every stock movement, fully auditable.</div>
    </div>
</div>

<div class="card chart-card">
    <div class="card-header"><i class="bi bi-clock-history me-1"></i> <strong>All Transactions</strong></div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Product or barcode" value="<?= e($search) ?>">
                </div>
            </div>
            <?php if ($isAdmin): ?>
            <div class="col-6 col-md-2">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int) $b['branch_id'] ?>" <?= $selectedBranchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                            <?= e($b['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="Stock In"     <?= $type === 'Stock In'     ? 'selected' : '' ?>>Stock In</option>
                    <option value="Stock Out"    <?= $type === 'Stock Out'    ? 'selected' : '' ?>>Stock Out</option>
                    <option value="Adjustment"   <?= $type === 'Adjustment'   ? 'selected' : '' ?>>Adjustment</option>
                    <option value="Transfer Out" <?= $type === 'Transfer Out' ? 'selected' : '' ?>>Transfer Out</option>
                    <option value="Transfer In"  <?= $type === 'Transfer In'  ? 'selected' : '' ?>>Transfer In</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    <?php foreach ($supplierChoices as $sup): ?>
                        <option value="<?= (int) $sup['supplier_id'] ?>" <?= $supplierId === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                            <?= e($sup['supplier_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isAdmin): ?>
            <div class="col-6 col-md-2">
                <select name="user_id" class="form-select">
                    <option value="">All Users</option>
                    <?php foreach ($userChoices as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= e($u['fullname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <select name="range" class="form-select">
                    <option value="">Any Date</option>
                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week"  <?= $range === 'week'  ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>
            </div>
            <div class="col-6 col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>Product</th>
                        <?php if ($isAdmin): ?><th>Branch</th><?php endif; ?>
                        <th>Barcode</th>
                        <th>Type</th>
                        <th class="text-end">Quantity</th>
                        <th>Batch</th>
                        <th>Expiration</th>
                        <th>Remaining</th>
                        <th>Supplier</th>
                        <th>User</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $isAdmin ? 12 : 11 ?>" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No transactions match these filters.
                    </td></tr>
                <?php else: foreach ($rows as $t):
                    $badgeClass = match ($t['type']) {
                        'Stock In' => 'success', 'Stock Out' => 'danger', 'Adjustment' => 'warning',
                        'Transfer In' => 'info', 'Transfer Out' => 'secondary', default => 'secondary',
                    };
                    $qty = (int) $t['quantity'];
                    $qtyDisplay = in_array($t['type'], ['Adjustment', 'Transfer In'], true) ? ($qty >= 0 ? "+{$qty}" : (string) $qty) : ((in_array($t['type'], ['Stock Out', 'Transfer Out'], true) ? '-' : '+') . abs($qty));
                ?>
                    <tr>
                        <td class="text-nowrap small"><?= e(format_date($t['created_at'])) ?></td>
                        <td class="fw-semibold"><?= e($t['product_name']) ?></td>
                        <?php if ($isAdmin): ?><td><span class="badge bg-light text-dark border"><?= e($t['branch_name'] ?? '—') ?></span></td><?php endif; ?>
                        <td><code class="small"><?= e($t['barcode'] ?: '—') ?></code></td>
                        <td><span class="badge bg-<?= $badgeClass ?>"><?= e($t['type']) ?></span></td>
                        <td class="text-end fw-semibold"><?= e($qtyDisplay) ?> <span class="text-muted small"><?= e($t['unit']) ?></span></td>
                        <td>#<?= (int) $t['batch_id'] ?></td>
                        <td><?= $t['expiration_date'] ? e(format_date($t['expiration_date'], 'M j, Y')) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $t['batch_remaining'] !== null ? (int) $t['batch_remaining'] : '<span class="text-muted">—</span>' ?></td>
                        <td><?= e($t['supplier_name'] ?: '—') ?></td>
                        <td><?= e($t['fullname'] ?: ($t['username'] ?: 'System')) ?></td>
                        <td class="small text-muted"><?= e($t['remarks'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> transactions
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
