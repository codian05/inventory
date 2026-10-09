<?php
declare(strict_types=1);

/**
 * batches.php
 * Admin-only "Batch Monitoring" — every batch across every product,
 * with expiration status and remaining quantity.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$search = clean_input($_GET['search'] ?? '');
$status = clean_input($_GET['status'] ?? ''); // '', 'active', 'depleted', 'expired'
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = PER_PAGE;

$selectedBranchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
    ? (int) $_GET['branch_id']
    : null;
if ($selectedBranchId !== null && !fetch_branch($selectedBranchId, true)) {
    $selectedBranchId = null;
}
$allBranches = branch_options($selectedBranchId);

$where = ['1=1'];
$params = [];

if ($selectedBranchId !== null) {
    $where[] = 'b.branch_id = ?';
    $params[] = $selectedBranchId;
}
if ($search !== '') {
    $where[] = '(p.product_name LIKE ? OR p.barcode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if ($status === 'active') {
    $where[] = 'b.remaining_quantity > 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())';
} elseif ($status === 'depleted') {
    $where[] = 'b.remaining_quantity = 0';
} elseif ($status === 'expired') {
    $where[] = 'b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()';
}
$whereSql = implode(' AND ', $where);

$pdo = db();
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM inventory_batches b JOIN products p ON p.product_id = b.product_id WHERE {$whereSql}"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

[$totalPages, $offset, $page] = paginate($total, $perPage, $page);

$stmt = $pdo->prepare(
    "SELECT b.*, p.product_name, p.barcode, p.unit, s.supplier_name, br.branch_name
     FROM inventory_batches b
     JOIN products p ON p.product_id = b.product_id
     LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
     LEFT JOIN branches br ON br.branch_id = b.branch_id
     WHERE {$whereSql}
     ORDER BY b.received_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$batches = $stmt->fetchAll();

$pageTitle = 'Batch Monitoring';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Batch Monitoring</h2>
        <div class="text-muted">Every inventory batch ever received, across all products.</div>
    </div>
</div>

<div class="card chart-card">
    <div class="card-header"><i class="bi bi-boxes me-1"></i> <strong>All Batches</strong></div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Product or barcode" value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    <?php foreach ($allBranches as $b): ?>
                        <option value="<?= (int) $b['branch_id'] ?>" <?= $selectedBranchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                            <?= e($b['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Batches</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active (has stock)</option>
                    <option value="depleted" <?= $status === 'depleted' ? 'selected' : '' ?>>Depleted (0 remaining)</option>
                    <option value="expired"  <?= $status === 'expired'  ? 'selected' : '' ?>>Expired (unconsumed)</option>
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
                        <th>Batch</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th>Supplier</th>
                        <th class="text-end">Received</th>
                        <th class="text-end">Remaining</th>
                        <th>Expiration</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$batches): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No batches match these filters.
                    </td></tr>
                <?php else: foreach ($batches as $b):
                    $expStatus = expiration_status($b['expiration_date']);
                    $depleted = (int) $b['remaining_quantity'] === 0;
                ?>
                    <tr>
                        <td>#<?= (int) $b['batch_id'] ?></td>
                        <td class="fw-semibold"><?= e($b['product_name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($b['branch_name'] ?: '—') ?></span></td>
                        <td><?= e($b['supplier_name'] ?: '—') ?></td>
                        <td class="text-end"><?= (int) $b['quantity'] ?> <span class="text-muted small"><?= e($b['unit']) ?></span></td>
                        <td class="text-end fw-semibold"><?= (int) $b['remaining_quantity'] ?></td>
                        <td><?= e(format_date($b['expiration_date'], 'M j, Y')) ?></td>
                        <td>
                            <?php if ($depleted): ?>
                                <span class="badge bg-secondary">Depleted</span>
                            <?php else: ?>
                                <span class="badge bg-<?= e($expStatus['class']) ?>"><?= e($expStatus['label']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="batch_view.php?id=<?= (int) $b['batch_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> batches
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
