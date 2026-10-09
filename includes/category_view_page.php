<?php
declare(strict_types=1);

/**
 * category_view_page.php
 * Shared by admin/category_view.php and staff/category_view.php.
 * Both roles can view; only admin sees Edit/Delete actions.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$id      = (int) ($_GET['id'] ?? 0);

$category = fetch_category($id);
if (!$category) {
    http_response_code(404);
    set_flash('danger', 'Category not found.');
    header('Location: categories.php');
    exit;
}

$productCount = category_product_count($id);

// Category-specific activity (activity_logs.activity text contains the
// category name — same convention already used for user actions).
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
    $stmt->execute(['%category \'' . $category['category_name'] . '\'%']);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('category activity query failed: ' . $e->getMessage());
}

$pageTitle = 'Category Details';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2><?= e($category['category_name']) ?></h2>
        <div class="text-muted">Category #<?= (int) $category['category_id'] ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="categories.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        <?php if ($isAdmin): ?>
            <a href="category_edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="glass-card stat-tile mb-3">
            <div class="icon-badge bg-grad-sky"><i class="bi bi-tags-fill"></i></div>
            <div>
                <div class="stat-label">Status</div>
                <div class="stat-value fs-5">
                    <?php if ($category['status'] === 'active'): ?>
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
                <div class="stat-label">Products in Category</div>
                <div class="stat-value"><?= (int) $productCount ?></div>
                <div class="stat-sub">Live data once Phase 4 ships</div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card chart-card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Details</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Name</th><td><?= e($category['category_name']) ?></td></tr>
                    <tr><th>Description</th><td><?= e($category['description'] ?: '—') ?></td></tr>
                    <tr><th>Status</th><td><?= e(ucfirst($category['status'])) ?></td></tr>
                    <tr><th>Created</th><td><?= e(format_date($category['created_at'])) ?></td></tr>
                    <tr><th>Last Updated</th><td><?= e(format_date($category['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Related Activity</div>
            <div class="card-body activity-scroll">
                <?php if (!$logs): ?>
                    <p class="text-muted mb-0">No activity recorded for this category yet.</p>
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
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
