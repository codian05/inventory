<?php
declare(strict_types=1);

/**
 * transfers_list_page.php
 * Shared by admin/transfers.php and staff/transfers.php. Every branch
 * stock transfer. Admin: all transfers, or filtered to one branch
 * (as either source or destination). Staff: only transfers touching
 * their own branch — never another branch's, even as read-only history.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$myBranchId = current_user_branch_id();

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

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = PER_PAGE;
$total = transfer_history_count($selectedBranchId);
[$totalPages, $offset, $page] = paginate($total, $perPage, $page);
$transfers = transfer_history($selectedBranchId, $perPage, $offset);

$pageTitle = 'Stock Transfers';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Stock Transfers</h2>
        <div class="text-muted">Every branch-to-branch stock movement, fully auditable.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($isAdmin): ?>
            <form method="GET" class="d-flex align-items-center gap-2">
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
        <a href="stock_transfer.php" class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right me-1"></i> New Transfer</a>
    </div>
</div>

<div class="card chart-card">
    <div class="card-header"><i class="bi bi-arrow-left-right me-1"></i> <strong>Transfer History</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Transfer</th>
                        <th>Date</th>
                        <th>Product</th>
                        <th>From</th>
                        <th>To</th>
                        <th class="text-end">Quantity</th>
                        <th>By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$transfers): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-arrow-left-right fs-1 d-block mb-2"></i>
                        No transfers recorded yet.
                    </td></tr>
                <?php else: foreach ($transfers as $t): ?>
                    <tr>
                        <td>#<?= (int) $t['transfer_id'] ?></td>
                        <td class="text-nowrap small"><?= e(format_date($t['created_at'])) ?></td>
                        <td class="fw-semibold"><?= e($t['product_name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($t['from_branch_name'] ?: '—') ?></span></td>
                        <td><span class="badge bg-light text-dark border"><?= e($t['to_branch_name'] ?: '—') ?></span></td>
                        <td class="text-end fw-semibold"><?= (int) $t['quantity'] ?> <span class="text-muted small"><?= e($t['unit']) ?></span></td>
                        <td><?= e($t['fullname'] ?: ($t['username'] ?: 'System')) ?></td>
                        <td class="small text-muted"><?= e($t['remarks'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> transfers
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
