<?php
declare(strict_types=1);

/**
 * supplier_view_page.php
 * Shared by admin/supplier_view.php and staff/supplier_view.php.
 * Both roles can view; only admin sees Edit action.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$id      = (int) ($_GET['id'] ?? 0);

$supplier = fetch_supplier($id);
if (!$supplier) {
    http_response_code(404);
    set_flash('danger', 'Supplier not found.');
    header('Location: suppliers.php');
    exit;
}

$productCount = supplier_product_count($id);
$deliveries = supplier_history($id, 100);

// Supplier-specific activity (same convention as categories/users).
$logs = [];
try {
    $stmt = db()->prepare(
        "SELECT a.activity, a.created_at, u.username, u.fullname
         FROM activity_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.activity LIKE ?
         ORDER BY a.created_at DESC
         LIMIT 10"
    );
    $stmt->execute(['%supplier \'' . $supplier['supplier_name'] . '\'%']);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('supplier activity query failed: ' . $e->getMessage());
}

$pageTitle = 'Supplier Details';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2><?= e($supplier['supplier_name']) ?></h2>
        <div class="text-muted">Supplier #<?= (int) $supplier['supplier_id'] ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="suppliers.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <?php if ($isAdmin): ?>
            <a href="supplier_edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="glass-card stat-tile mb-3">
            <div class="icon-badge bg-grad-teal"><i class="bi bi-truck"></i></div>
            <div>
                <div class="stat-label">Status</div>
                <div class="stat-value fs-5">
                    <?php if ($supplier['status'] === 'active'): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="glass-card stat-tile">
            <div class="icon-badge bg-grad-indigo"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="stat-label">Products Supplied</div>
                <div class="stat-value"><?= (int) $productCount ?></div>
            </div>
        </div>

        <div class="glass-card stat-tile mt-3">
            <div class="icon-badge bg-grad-emerald"><i class="bi bi-truck"></i></div>
            <div>
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-value"><?= count($deliveries) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Supplier Information</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Supplier Name</th><td><?= e($supplier['supplier_name']) ?></td></tr>
                    <tr><th>Contact Person</th><td><?= e($supplier['contact_person'] ?: '—') ?></td></tr>
                    <tr><th>Contact Number</th><td><?= e($supplier['contact_number'] ?: '—') ?></td></tr>
                    <tr><th>Email</th><td><?= e($supplier['email'] ?: '—') ?></td></tr>
                    <tr><th>Address</th><td><?= nl2br(e($supplier['address'] ?: '—')) ?></td></tr>
                    <tr><th>Status</th><td><?= e(ucfirst($supplier['status'])) ?></td></tr>
                    <tr><th>Date Created</th><td><?= e(format_date($supplier['created_at'])) ?></td></tr>
                    <tr><th>Last Updated</th><td><?= e(format_date($supplier['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Recent Supplier Activities</div>
            <div class="card-body activity-scroll">
                <?php if (!$logs): ?>
                    <p class="text-muted mb-0">No activity recorded for this supplier yet.</p>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php foreach ($logs as $l): ?>
                            <?php $displayName = $l['fullname'] ?: ($l['username'] ?: 'System'); ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <div><strong><?= e($displayName) ?></strong> <span class="text-muted">&middot; <?= e($l['activity']) ?></span></div>
                                    <small class="text-muted"><?= e(time_ago($l['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card chart-card mt-3">
            <div class="card-header"><i class="bi bi-truck me-1"></i> <strong>Delivery Timeline</strong></div>
            <div class="card-body">
                <?php if (!$deliveries): ?>
                    <p class="text-muted mb-0">No deliveries recorded from this supplier yet.</p>
                <?php else: ?>
                    <div class="accordion" id="deliveryAccordion">
                        <?php foreach ($deliveries as $i => $d): ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#delivery<?= $i ?>">
                                        <div class="d-flex justify-content-between w-100 me-3 flex-wrap">
                                            <span>
                                                <strong><?= e($d['product_name']) ?></strong>
                                                <span class="text-muted small">Batch #<?= (int) $d['batch_id'] ?></span>
                                            </span>
                                            <span class="text-muted small"><?= e(format_date($d['received_at'])) ?></span>
                                        </div>
                                    </button>
                                </h2>
                                <div id="delivery<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#deliveryAccordion">
                                    <div class="accordion-body">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr><th class="w-25">Received Quantity</th><td><?= (int) $d['quantity'] ?> <?= e($d['unit']) ?></td></tr>
                                            <tr><th>Remaining Now</th><td><?= (int) $d['remaining_quantity'] ?> <?= e($d['unit']) ?></td></tr>
                                            <tr><th>Expiration Date</th><td><?= $d['expiration_date'] ? e(format_date($d['expiration_date'], 'M j, Y')) : 'No expiration' ?></td></tr>
                                            <tr><th>Received By</th><td><?= e($d['received_by'] ?: '—') ?></td></tr>
                                            <tr><th>Remarks</th><td><?= e($d['remarks'] ?: '—') ?></td></tr>
                                        </table>
                                        <a href="batch_view.php?id=<?= (int) $d['batch_id'] ?>" class="btn btn-sm btn-outline-primary mt-2">
                                            <i class="bi bi-eye me-1"></i> View Batch
                                        </a>
                                    </div>
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
