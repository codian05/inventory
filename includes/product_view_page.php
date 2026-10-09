<?php
declare(strict_types=1);

/**
 * product_view_page.php
 * Shared by admin/product_view.php and staff/product_view.php.
 * Both roles can view; only admin sees Edit action.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$id      = (int) ($_GET['id'] ?? 0);
$myBranchId = current_user_branch_id(); // null for admin

$product = fetch_product($id);
if (!$product) {
    http_response_code(404);
    set_flash('danger', 'Product not found.');
    header('Location: products.php');
    exit;
}

$img = product_image_url($product['product_image']);
// Staff: their own branch's stock only. Admin: total across all
// branches (the per-branch split is shown separately below).
$currentStock = product_stock_total($id, $myBranchId);
$branchBreakdown = $isAdmin ? product_stock_by_branch($id) : [];

// Product Inventory Timeline — chronological Stock In / Stock Out /
// Adjustment history for this product, filterable and paginated.
$timelineType = clean_input($_GET['ttype'] ?? '');
$timelinePage = max(1, (int) ($_GET['tpage'] ?? 1));
$timelinePerPage = 10;

$timelineFilters = array_filter(['product_id' => $id, 'type' => $timelineType, 'branch_id' => $myBranchId]);
$timelineTotal = transaction_history_count($timelineFilters);
[$timelineTotalPages, $timelineOffset, $timelinePage] = paginate($timelineTotal, $timelinePerPage, $timelinePage);
$timeline = transaction_history($timelineFilters, $timelinePerPage, $timelineOffset);

$activeBatches = product_batches($id, true, $myBranchId);

// Product-specific activity ("Product History") — same convention
// already used for categories/suppliers/users.
$logs = [];
try {
    $stmt = db()->prepare(
        "SELECT a.activity, a.created_at, u.username, u.fullname
         FROM activity_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.activity LIKE ?
         ORDER BY a.created_at DESC
         LIMIT 15"
    );
    $stmt->execute(['%product \'' . $product['product_name'] . '\'%']);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('product activity query failed: ' . $e->getMessage());
}

$pageTitle = 'Product Details';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2><?= e($product['product_name']) ?></h2>
        <div class="text-muted">
            Product #<?= (int) $product['product_id'] ?>
            <?php if ($product['barcode']): ?> &middot; <code><?= e($product['barcode']) ?></code><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="products.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <?php if ($product['barcode']): ?>
            <a href="stock_in.php?barcode=<?= e(urlencode((string) $product['barcode'])) ?>" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</a>
            <a href="stock_out.php?barcode=<?= e(urlencode((string) $product['barcode'])) ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-up me-1"></i> Stock Out</a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a href="product_edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card chart-card mb-3">
            <div class="card-body text-center">
                <?php if ($img): ?>
                    <img src="<?= e($img) ?>" alt="<?= e($product['product_name']) ?>"
                         class="rounded mb-2 product-hero-img" style="object-fit:cover">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center rounded bg-body-secondary text-muted mx-auto mb-2"
                         style="width:160px;height:160px"><i class="bi bi-image fs-1"></i></div>
                <?php endif; ?>
                <div>
                    <?php if ($product['status'] === 'active'): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="glass-card stat-tile mb-3">
            <div class="icon-badge bg-grad-indigo"><i class="bi bi-boxes"></i></div>
            <div>
                <div class="stat-label">Current Stock<?= !$isAdmin ? ' (Your Branch)' : ' (All Branches)' ?></div>
                <div class="stat-value"><?= (int) $currentStock ?></div>
                <div class="stat-sub"><?= e($product['unit']) ?> &middot; across <?= count($activeBatches) ?> active batch(es)</div>
            </div>
        </div>

        <?php if ($isAdmin && $branchBreakdown): ?>
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-diagram-3 me-1"></i> Stock by Branch</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($branchBreakdown as $bb): ?>
                        <tr>
                            <td><?= e($bb['branch_name']) ?></td>
                            <td class="text-end fw-semibold"><?= (int) $bb['current_stock'] ?> <span class="text-muted small"><?= e($product['unit']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="glass-card stat-tile mb-3">
            <div class="icon-badge bg-grad-slate"><i class="bi bi-rulers"></i></div>
            <div>
                <div class="stat-label">Unit</div>
                <div class="stat-value fs-4"><?= e($product['unit']) ?></div>
            </div>
        </div>

        <div class="glass-card stat-tile">
            <div class="icon-badge bg-grad-emerald"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="stat-label">Selling Price</div>
                <div class="stat-value">&#8369;<?= number_format((float) $product['selling_price'], 2) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Product Information</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Product Name</th><td><?= e($product['product_name']) ?></td></tr>
                    <tr><th>Barcode</th><td><?= $product['barcode'] ? '<code>' . e($product['barcode']) . '</code>' : '<span class="text-muted">Not assigned</span>' ?></td></tr>
                    <tr><th>Category</th><td><?= e($product['category_name'] ?: '—') ?></td></tr>
                    <tr><th>Supplier</th><td><?= e($product['supplier_name'] ?: '—') ?></td></tr>
                    <tr><th>Unit</th><td><?= e($product['unit']) ?></td></tr>
                    <tr><th>Selling Price</th><td>&#8369;<?= number_format((float) $product['selling_price'], 2) ?></td></tr>
                    <tr><th>Description</th><td><?= nl2br(e($product['description'] ?: '—')) ?></td></tr>
                    <tr><th>Status</th><td><?= e(ucfirst($product['status'])) ?></td></tr>
                    <tr><th>Date Created</th><td><?= e(format_date($product['created_at'])) ?></td></tr>
                    <tr><th>Last Updated</th><td><?= e(format_date($product['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Product History</div>
            <div class="card-body activity-scroll">
                <?php if (!$logs): ?>
                    <p class="text-muted mb-0">No activity recorded for this product yet.</p>
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
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span><i class="bi bi-graph-up-arrow me-1"></i> <strong>Product Inventory Timeline</strong></span>
                <form method="GET" class="ms-auto d-flex gap-2">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <select name="ttype" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                        <option value="">All Movements</option>
                        <option value="Stock In"   <?= $timelineType === 'Stock In'   ? 'selected' : '' ?>>Stock In</option>
                        <option value="Stock Out"  <?= $timelineType === 'Stock Out'  ? 'selected' : '' ?>>Stock Out</option>
                        <option value="Adjustment" <?= $timelineType === 'Adjustment' ? 'selected' : '' ?>>Adjustments</option>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <?php if (!$timeline): ?>
                    <p class="text-muted mb-0">No inventory movements recorded for this product yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th><th>Type</th><th class="text-end">Qty</th>
                                    <?php if ($isAdmin): ?><th>Branch</th><?php endif; ?>
                                    <th>Batch</th><th>Supplier</th><th>User</th><th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($timeline as $t):
                                $badgeClass = match ($t['type']) {
                                    'Stock In' => 'success', 'Stock Out' => 'danger', 'Adjustment' => 'warning',
                                    'Transfer In' => 'info', 'Transfer Out' => 'secondary', default => 'secondary',
                                };
                                $qty = (int) $t['quantity'];
                                $qtyDisplay = in_array($t['type'], ['Adjustment', 'Transfer In'], true) ? ($qty >= 0 ? "+{$qty}" : (string) $qty) : ((in_array($t['type'], ['Stock Out', 'Transfer Out'], true) ? '-' : '+') . abs($qty));
                            ?>
                                <tr>
                                    <td class="text-nowrap small"><?= e(format_date($t['created_at'])) ?></td>
                                    <td><span class="badge bg-<?= $badgeClass ?>"><?= e($t['type']) ?></span></td>
                                    <td class="text-end fw-semibold"><?= e($qtyDisplay) ?></td>
                                    <?php if ($isAdmin): ?><td><span class="badge bg-light text-dark border"><?= e($t['branch_name'] ?? '—') ?></span></td><?php endif; ?>
                                    <td><a href="batch_view.php?id=<?= (int) $t['batch_id'] ?>">#<?= (int) $t['batch_id'] ?></a></td>
                                    <td><?= e($t['supplier_name'] ?: '—') ?></td>
                                    <td><?= e($t['fullname'] ?: ($t['username'] ?: 'System')) ?></td>
                                    <td class="small text-muted"><?= e($t['remarks'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Showing <?= count($timeline) ?> of <?= $timelineTotal ?> movement(s)</div>
                        <?= pagination_links($timelinePage, $timelineTotalPages, 'tpage') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
