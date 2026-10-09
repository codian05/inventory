<?php
declare(strict_types=1);

/**
 * inventory_list_page.php
 * Shared by admin/inventory.php and staff/inventory.php — "View
 * Inventory" for both roles. Shows current, live stock per product,
 * computed entirely from inventory_batches (never from a products
 * column, per the Phase 6 rule).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$pdo     = db();

// Staff: always their own branch, never a request parameter. Admin:
// "All Branches" (null) or a validated selection.
if ($isAdmin) {
    $selectedBranchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
        ? (int) $_GET['branch_id']
        : null;
    if ($selectedBranchId !== null && !fetch_branch($selectedBranchId, true)) {
        $selectedBranchId = null;
    }
} else {
    $selectedBranchId = current_user_branch_id();
}
$allBranches = branch_options($selectedBranchId);

$stats = inventory_dashboard_stats($selectedBranchId);

$search       = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? ''); // '', 'low', 'expiring', 'expired', 'ok'
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = PER_PAGE;

// Aggregate current stock + nearest expiration per product, computed
// live from inventory_batches. The branch join condition (not a WHERE
// clause) is what scopes this: WHERE would drop products with zero
// batches in the selected branch entirely, but a LEFT JOIN condition
// keeps every product visible with 0 stock instead.
$branchJoin = $selectedBranchId !== null ? ' AND b.branch_id = ?' : '';
$sql = "SELECT p.product_id, p.barcode, p.product_name, p.unit, p.product_image,
               c.category_name,
               COALESCE(SUM(CASE WHEN b.expiration_date IS NULL OR b.expiration_date >= CURDATE()
                                  THEN b.remaining_quantity ELSE 0 END), 0) AS current_stock,
               MIN(CASE WHEN b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                        AND b.expiration_date >= CURDATE() THEN b.expiration_date END) AS next_expiry,
               SUM(CASE WHEN b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                        AND b.expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired_batches
        FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN inventory_batches b ON b.product_id = p.product_id{$branchJoin}
        WHERE p.deleted_at IS NULL";
$params = [];
if ($selectedBranchId !== null) {
    $params[] = $selectedBranchId;
}

if ($search !== '') {
    $sql .= ' AND (p.product_name LIKE ? OR p.barcode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}

$sql .= ' GROUP BY p.product_id, p.barcode, p.product_name, p.unit, p.product_image, c.category_name';

if ($statusFilter === 'expired') {
    $sql .= ' HAVING expired_batches > 0';
} elseif ($statusFilter === 'low') {
    $sql .= ' HAVING expired_batches = 0 AND current_stock <= ' . LOW_STOCK_THRESHOLD;
} elseif ($statusFilter === 'expiring') {
    $sql .= ' HAVING next_expiry IS NOT NULL AND next_expiry <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)';
} elseif ($statusFilter === 'ok') {
    $sql .= ' HAVING expired_batches = 0 AND current_stock > ' . LOW_STOCK_THRESHOLD;
}

$sql .= ' ORDER BY p.product_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

$total = count($allRows);
[$totalPages, $offset, $page] = paginate($total, $perPage, $page);
$rows = array_slice($allRows, $offset, $perPage);

$currentBranch = $selectedBranchId ? fetch_branch($selectedBranchId, true) : null;

$pageTitle = 'Inventory';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Inventory</h2>
        <div class="text-muted">
            Live stock levels — computed from received batches, never edited directly.
            <?php if (!$isAdmin && $currentBranch): ?>
                <span class="fw-semibold"> &middot; <i class="bi bi-geo-alt-fill"></i> <?= e($currentBranch['branch_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($isAdmin): ?>
            <form method="GET" class="d-flex align-items-center gap-2">
                <?php foreach (['search' => $search, 'status' => $statusFilter] as $k => $v): if ($v !== ''): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endif; endforeach; ?>
                <select name="branch_id" class="form-select form-select-sm" style="min-width:170px" onchange="this.form.submit()">
                    <option value="">All Branches</option>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int) $b['branch_id'] ?>" <?= $selectedBranchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                            <?= e($b['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="stock_in.php" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</a>
        <a href="stock_out.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-up me-1"></i> Stock Out</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Stock', $stats['total_stock_qty'], 'boxes', 'bg-grad-slate', 'status='],
        ['Low Stock', $stats['low_stock'], 'exclamation-triangle', 'bg-grad-amber', 'status=low'],
        ['Expiring Soon', $stats['expiring_soon'], 'hourglass-split', 'bg-grad-amber', 'status=expiring'],
        ['Expired', $stats['expired'], 'x-octagon', 'bg-grad-rose', 'status=expired'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg-3">
            <a href="?<?= e($c[4]) ?>" class="text-decoration-none">
                <div class="glass-card stat-tile fade-in-item">
                    <div class="icon-badge <?= e($c[3]) ?>"><i class="bi bi-<?= e($c[2]) ?>"></i></div>
                    <div>
                        <div class="stat-label"><?= e($c[0]) ?></div>
                        <div class="stat-value text-body"><?= (int) $c[1] ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="card chart-card">
    <div class="card-header">
        <span><i class="bi bi-table me-1"></i> <strong>Current Stock by Product</strong></span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search product or barcode" value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All Stock Status</option>
                    <option value="ok"       <?= $statusFilter === 'ok'       ? 'selected' : '' ?>>Available</option>
                    <option value="low"      <?= $statusFilter === 'low'      ? 'selected' : '' ?>>Low Stock</option>
                    <option value="expiring" <?= $statusFilter === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
                    <option value="expired"  <?= $statusFilter === 'expired'  ? 'selected' : '' ?>>Has Expired Stock</option>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">Current Stock</th>
                        <th>Nearest Expiration</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-boxes fs-1 d-block mb-2"></i>
                        No inventory records match this view.
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $hasExpired = (int) $r['expired_batches'] > 0;
                    $stock = (int) $r['current_stock'];
                    $expStatus = $r['next_expiry'] ? expiration_status($r['next_expiry']) : null;
                ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= e($r['product_name']) ?>
                            <?php if ($r['barcode']): ?><br><code class="small"><?= e($r['barcode']) ?></code><?php endif; ?>
                        </td>
                        <td><?= e($r['category_name'] ?: '—') ?></td>
                        <td class="text-end fw-semibold"><?= $stock ?> <span class="text-muted small"><?= e($r['unit']) ?></span></td>
                        <td>
                            <?php if ($r['next_expiry']): ?>
                                <?= e(format_date($r['next_expiry'], 'M j, Y')) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasExpired): ?>
                                <span class="badge bg-danger">Has Expired Stock</span>
                            <?php elseif ($stock <= LOW_STOCK_THRESHOLD): ?>
                                <span class="badge bg-warning text-dark">Low Stock</span>
                            <?php elseif ($expStatus && in_array($expStatus['status'], ['critical', 'warning'], true)): ?>
                                <span class="badge bg-<?= e($expStatus['class']) ?>"><?= e($expStatus['label']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-success">Available</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="product_view.php?id=<?= (int) $r['product_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Product"><i class="bi bi-eye"></i></a>
                            <a href="stock_in.php?barcode=<?= e(urlencode((string) $r['barcode'])) ?>" class="btn btn-sm btn-outline-success" title="Stock In"><i class="bi bi-box-arrow-in-down"></i></a>
                            <a href="stock_out.php?barcode=<?= e(urlencode((string) $r['barcode'])) ?>" class="btn btn-sm btn-outline-danger" title="Stock Out"><i class="bi bi-box-arrow-up"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> products
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
