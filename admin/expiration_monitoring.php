<?php
declare(strict_types=1);

/**
 * expiration_monitoring.php
 * Admin-only. Every active (unconsumed) batch, grouped/sortable by
 * expiration urgency.
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

$band = clean_input($_GET['band'] ?? ''); // '', 'expired','critical','warning','notice'
$bands = expiration_breakdown($selectedBranchId);

$sql = "SELECT b.*, p.product_name, p.barcode, p.unit, s.supplier_name, br.branch_name
        FROM inventory_batches b
        JOIN products p ON p.product_id = b.product_id
        LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
        LEFT JOIN branches br ON br.branch_id = b.branch_id
        WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL";
$params = [];

if ($selectedBranchId !== null) {
    $sql .= ' AND b.branch_id = ?';
    $params[] = $selectedBranchId;
}

if ($band === 'expired') {
    $sql .= ' AND b.expiration_date < CURDATE()';
} elseif ($band === 'critical') {
    $sql .= ' AND b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_CRITICAL_DAYS . ' DAY)';
} elseif ($band === 'warning') {
    $sql .= ' AND b.expiration_date > DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_CRITICAL_DAYS . ' DAY)
              AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_WARNING_DAYS . ' DAY)';
} elseif ($band === 'notice') {
    $sql .= ' AND b.expiration_date > DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_WARNING_DAYS . ' DAY)
              AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)';
} else {
    $sql .= ' AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)';
}

$sql .= ' ORDER BY b.expiration_date ASC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll();

$pageTitle = 'Expiration Monitoring';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Expiration Monitoring</h2>
        <div class="text-muted">Active batches approaching or past their expiration date.</div>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <?php if ($band): ?><input type="hidden" name="band" value="<?= e($band) ?>"><?php endif; ?>
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

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Expired', $bands['expired'], 'x-octagon', 'bg-grad-rose', 'expired'],
        ['Critical (≤7d)', $bands['critical'], 'exclamation-octagon', 'bg-grad-rose', 'critical'],
        ['Warning (≤15d)', $bands['warning'], 'exclamation-triangle', 'bg-grad-amber', 'warning'],
        ['Notice (≤30d)', $bands['notice'], 'info-circle', 'bg-grad-sky', 'notice'],
    ];
    $branchQs = $selectedBranchId !== null ? '&branch_id=' . $selectedBranchId : '';
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg-3">
            <a href="?band=<?= e($c[4]) ?><?= $branchQs ?>" class="text-decoration-none">
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
    <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-calendar-x me-1"></i> <strong>Batches</strong></span>
        <?php if ($band): ?>
            <a href="expiration_monitoring.php<?= $selectedBranchId !== null ? '?branch_id=' . $selectedBranchId : '' ?>" class="btn btn-sm btn-outline-secondary ms-auto">Clear Filter</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th><th>Branch</th><th>Batch</th><th>Supplier</th>
                        <th class="text-end">Remaining</th><th>Expiration</th><th>Status</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$batches): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
                        Nothing in this window.
                    </td></tr>
                <?php else: foreach ($batches as $b): $st = expiration_status($b['expiration_date']); ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['product_name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($b['branch_name'] ?: '—') ?></span></td>
                        <td>#<?= (int) $b['batch_id'] ?></td>
                        <td><?= e($b['supplier_name'] ?: '—') ?></td>
                        <td class="text-end"><?= (int) $b['remaining_quantity'] ?> <span class="text-muted small"><?= e($b['unit']) ?></span></td>
                        <td><?= e(format_date($b['expiration_date'], 'M j, Y')) ?></td>
                        <td><span class="badge bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                        <td class="text-end"><a href="batch_view.php?id=<?= (int) $b['batch_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
