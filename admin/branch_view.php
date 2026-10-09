<?php
declare(strict_types=1);

/**
 * branch_view.php
 * Admin-only branch detail: branch info, assigned staff, and an
 * inventory/transaction summary computed the same way the dashboard
 * computes branch-scoped stats (inventory_overview_stats() with this
 * branch's id), so the numbers here always agree with the dashboard.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$branch = fetch_branch($id, true);

if (!$branch) {
    http_response_code(404);
    set_flash('danger', 'Branch not found.');
    header('Location: branches.php');
    exit;
}

$stats = inventory_overview_stats($id);

$staff = [];
try {
    $stmt = db()->prepare(
        'SELECT id, fullname, username, role, status, last_login
         FROM users WHERE branch_id = ? AND deleted_at IS NULL ORDER BY fullname ASC'
    );
    $stmt->execute([$id]);
    $staff = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('branch_view staff query failed: ' . $e->getMessage());
}

$recentTransactions = transaction_history(['branch_id' => $id], 10);

$pageTitle = 'Branch Details';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash-heading">
    <div>
        <h2><?= e($branch['branch_name']) ?> <code class="fs-6"><?= e($branch['branch_code']) ?></code></h2>
        <div class="text-muted">
            <?php if ($branch['status'] === 'active'): ?>
                <span class="badge bg-success">Active</span>
            <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
            <?php endif; ?>
            <?php if ($branch['deleted_at']): ?><span class="badge bg-danger ms-1">Deleted</span><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="branches.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <?php if (!$branch['deleted_at']): ?>
            <a href="branch_edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Stock',   $stats['total_stock_qty'],   'boxes',                'bg-grad-slate'],
        ['Low Stock',     $stats['low_stock'],          'exclamation-triangle', 'bg-grad-amber'],
        ['Expiring Soon', $stats['expiring_soon'],      'hourglass-split',      'bg-grad-amber'],
        ['Expired',       $stats['expired'],            'x-octagon',            'bg-grad-rose'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg-3">
            <div class="glass-card stat-tile fade-in-item">
                <div class="icon-badge <?= e($c[3]) ?>"><i class="bi bi-<?= e($c[2]) ?>"></i></div>
                <div>
                    <div class="stat-label"><?= e($c[0]) ?></div>
                    <div class="stat-value"><?= (int) $c[1] ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Branch Information</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Branch Code</th><td><code><?= e($branch['branch_code']) ?></code></td></tr>
                    <tr><th>Branch Name</th><td><?= e($branch['branch_name']) ?></td></tr>
                    <tr><th>Address</th><td><?= nl2br(e($branch['address'] ?: '—')) ?></td></tr>
                    <tr><th>Contact Number</th><td><?= e($branch['contact_number'] ?: '—') ?></td></tr>
                    <tr><th>Email</th><td><?= e($branch['email'] ?: '—') ?></td></tr>
                    <tr><th>Status</th><td><?= e(ucfirst($branch['status'])) ?></td></tr>
                    <tr><th>Date Created</th><td><?= e(format_date($branch['created_at'])) ?></td></tr>
                    <tr><th>Last Updated</th><td><?= e(format_date($branch['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-people me-1"></i> Assigned Staff (<?= count($staff) ?>)</div>
            <div class="card-body p-0">
                <?php if (!$staff): ?>
                    <p class="text-muted mb-0 p-3">No staff assigned to this branch yet.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($staff as $s): ?>
                            <tr>
                                <td>
                                    <a href="user_profile.php?id=<?= (int) $s['id'] ?>"><?= e($s['fullname']) ?></a>
                                    <span class="text-muted small">@<?= e($s['username']) ?></span>
                                </td>
                                <td><span class="badge bg-<?= $s['role'] === 'admin' ? 'danger' : 'info' ?>"><?= e(ucfirst($s['role'])) ?></span></td>
                                <td><?= $s['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card chart-card">
            <div class="card-header d-flex align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> <strong>Recent Transactions</strong></span>
                <a href="transactions.php?branch_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-auto">View All</a>
            </div>
            <div class="card-body">
                <?php if (!$recentTransactions): ?>
                    <p class="text-muted mb-0">No transactions recorded for this branch yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th>User</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentTransactions as $t):
                                $badgeClass = match ($t['type']) {
                                    'Stock In' => 'success', 'Stock Out' => 'danger', 'Adjustment' => 'warning',
                                    'Transfer In' => 'info', 'Transfer Out' => 'secondary', default => 'secondary',
                                };
                            ?>
                                <tr>
                                    <td class="text-nowrap small"><?= e(format_date($t['created_at'])) ?></td>
                                    <td><?= e($t['product_name']) ?></td>
                                    <td><span class="badge bg-<?= $badgeClass ?>"><?= e($t['type']) ?></span></td>
                                    <td class="text-end fw-semibold"><?= (int) $t['quantity'] ?></td>
                                    <td><?= e($t['fullname'] ?: ($t['username'] ?: 'System')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
