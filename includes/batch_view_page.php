<?php
declare(strict_types=1);

/**
 * batch_view_page.php
 * Shared by admin/batch_view.php and staff/batch_view.php. Both roles
 * can view; only admin sees the Adjust action (batch integrity rule:
 * expiration_date/received_at/supplier_id are never editable here —
 * only remaining_quantity, via adjust_inventory()).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT b.*, p.product_name, p.barcode, p.unit, s.supplier_name, u.fullname AS received_by, br.branch_name
     FROM inventory_batches b
     JOIN products p ON p.product_id = b.product_id
     LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
     LEFT JOIN users u ON u.id = b.created_by
     LEFT JOIN branches br ON br.branch_id = b.branch_id
     WHERE b.batch_id = ? LIMIT 1'
);
$stmt->execute([$id]);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    set_flash('danger', 'Batch not found.');
    header('Location: batches.php');
    exit;
}

// Staff can never view a batch belonging to another branch — even by
// guessing/incrementing the id in the URL. Enforced server-side here,
// not just by hiding the link.
require_branch_access((int) $batch['branch_id']);

$expStatus = expiration_status($batch['expiration_date']);
$transactions = transaction_history(['product_id' => (int) $batch['product_id'], 'branch_id' => (int) $batch['branch_id']], 200);
$transactions = array_values(array_filter($transactions, static fn($t) => (int) $t['batch_id'] === $id));

$pageTitle = 'Batch Details';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Batch #<?= (int) $batch['batch_id'] ?></h2>
        <div class="text-muted"><?= e($batch['product_name']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $isAdmin ? 'batches.php' : 'inventory.php' ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <?php if ($isAdmin): ?>
            <a href="inventory_adjust.php?batch_id=<?= $id ?>" class="btn btn-sm btn-warning"><i class="bi bi-sliders me-1"></i> Adjust</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="glass-card stat-tile mb-3">
            <div class="icon-badge bg-grad-slate"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="stat-label">Remaining / Received</div>
                <div class="stat-value fs-4"><?= (int) $batch['remaining_quantity'] ?> / <?= (int) $batch['quantity'] ?></div>
                <div class="stat-sub"><?= e($batch['unit']) ?></div>
            </div>
        </div>
        <div class="glass-card stat-tile">
            <div class="icon-badge bg-<?= e($expStatus['class']) ?>"><i class="bi bi-calendar-event"></i></div>
            <div>
                <div class="stat-label">Expiration Status</div>
                <div class="stat-value fs-5"><span class="badge bg-<?= e($expStatus['class']) ?>"><?= e($expStatus['label']) ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Batch Information</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Product</th><td><?= e($batch['product_name']) ?> <code class="small"><?= e($batch['barcode'] ?: '') ?></code></td></tr>
                    <tr><th>Branch</th><td><span class="badge bg-light text-dark border"><?= e($batch['branch_name'] ?: '—') ?></span></td></tr>
                    <tr><th>Supplier</th><td><?= e($batch['supplier_name'] ?: '—') ?></td></tr>
                    <tr><th>Original Quantity</th><td><?= (int) $batch['quantity'] ?> <?= e($batch['unit']) ?> <span class="text-muted small">(fixed at receipt)</span></td></tr>
                    <tr><th>Remaining Quantity</th><td><?= (int) $batch['remaining_quantity'] ?> <?= e($batch['unit']) ?></td></tr>
                    <tr><th>Expiration Date</th><td><?= $batch['expiration_date'] ? e(format_date($batch['expiration_date'], 'M j, Y')) : 'No expiration' ?> <span class="text-muted small">(fixed at receipt)</span></td></tr>
                    <tr><th>Received At</th><td><?= e(format_date($batch['received_at'])) ?> <span class="text-muted small">(fixed at receipt)</span></td></tr>
                    <tr><th>Received By</th><td><?= e($batch['received_by'] ?: '—') ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Transactions Against This Batch</div>
            <div class="card-body activity-scroll">
                <?php if (!$transactions): ?>
                    <p class="text-muted mb-0">No transactions recorded against this batch yet.</p>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php foreach ($transactions as $t):
                            $badgeClass = match ($t['type']) {
                                'Stock In' => 'success', 'Stock Out' => 'danger', 'Adjustment' => 'warning', default => 'secondary',
                            };
                        ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <div>
                                        <span class="badge bg-<?= $badgeClass ?>"><?= e($t['type']) ?></span>
                                        <strong><?= (int) $t['quantity'] ?> <?= e($batch['unit']) ?></strong>
                                        <span class="text-muted">&middot; <?= e($t['fullname'] ?: $t['username'] ?: 'System') ?></span>
                                        <?php if ($t['remarks']): ?><div class="text-muted small"><?= e($t['remarks']) ?></div><?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= e(time_ago($t['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
