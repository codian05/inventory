<?php
declare(strict_types=1);

/**
 * low_stock_monitoring.php
 * Admin-only. Every product whose current non-expired stock is at or
 * below LOW_STOCK_THRESHOLD, sorted lowest first.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$selectedBranchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
    ? (int) $_GET['branch_id']
    : null;
if ($selectedBranchId !== null && !fetch_branch($selectedBranchId, true)) {
    $selectedBranchId = null;
}
$allBranches = branch_options($selectedBranchId);

// Grouped per (product, branch) — mirrors inventory_overview_stats()'s
// low_stock calculation exactly (built from inventory_batches, not
// products, so it only surfaces product+branch combinations that
// actually have batch history — a product never stocked in a branch
// doesn't show up as a false "0 units low stock" row there).
$branchWhere = $selectedBranchId !== null ? ' AND b.branch_id = ?' : '';
$params = $selectedBranchId !== null ? [$selectedBranchId] : [];

$stmt = db()->prepare(
    'SELECT p.product_id, p.product_name, p.barcode, p.unit, c.category_name,
            b.branch_id, br.branch_name,
            SUM(b.remaining_quantity) AS total_qty
     FROM inventory_batches b
     JOIN products p ON p.product_id = b.product_id
     LEFT JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN branches br ON br.branch_id = b.branch_id
     WHERE p.deleted_at IS NULL
       AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())' . $branchWhere . '
     GROUP BY p.product_id, p.product_name, p.barcode, p.unit, c.category_name, b.branch_id, br.branch_name
     HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
     ORDER BY total_qty ASC'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Low Stock Monitoring';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Low Stock Monitoring</h2>
        <div class="text-muted">Products at or below the low-stock threshold (<?= LOW_STOCK_THRESHOLD ?> units), per branch.</div>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted small mb-0 d-none d-sm-inline">Branch:</label>
        <select name="branch_id" class="form-select form-select-sm" style="min-width:170px" onchange="this.form.submit()">
            <option value="">All Branches</option>
            <?php foreach ($allBranches as $b): ?>
                <option value="<?= (int) $b['branch_id'] ?>" <?= $selectedBranchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                    <?= e($b['branch_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card chart-card">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-1"></i> <strong><?= count($rows) ?> Row(s)</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr><th>Product</th><th>Branch</th><th>Category</th><th class="text-end">Remaining</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
                        No products are low on stock right now.
                    </td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['product_name']) ?> <code class="small"><?= e($r['barcode'] ?: '') ?></code></td>
                        <td><span class="badge bg-light text-dark border"><?= e($r['branch_name'] ?: '—') ?></span></td>
                        <td><?= e($r['category_name'] ?: '—') ?></td>
                        <td class="text-end">
                            <span class="badge bg-<?= (int) $r['total_qty'] === 0 ? 'danger' : 'warning text-dark' ?>">
                                <?= (int) $r['total_qty'] ?> <?= e($r['unit']) ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="product_view.php?id=<?= (int) $r['product_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            <a href="stock_in.php?barcode=<?= e(urlencode((string) $r['barcode'])) ?>" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down"></i> Stock In</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
