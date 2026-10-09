<?php
declare(strict_types=1);

/**
 * categories_deleted.php
 * Admin-only view of soft-deleted categories with restore option.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$categories = db()->query(
    'SELECT * FROM categories WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
)->fetchAll();

$pageTitle = 'Deleted Categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="card chart-card">
    <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-trash3 me-1"></i> <strong>Deleted Categories</strong></span>
        <a href="categories.php" class="btn btn-sm btn-outline-secondary ms-auto">Back to Categories</a>
    </div>
    <div class="card-body">
        <?php if (!$categories): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No deleted categories.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th><th>Name</th><th>Description</th><th>Deleted At</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>#<?= (int) $cat['category_id'] ?></td>
                            <td class="fw-semibold"><?= e($cat['category_name']) ?></td>
                            <td class="text-muted small"><?= e($cat['description'] ?: '—') ?></td>
                            <td><?= e(format_date($cat['deleted_at'])) ?></td>
                            <td class="text-end">
                                <a href="category_delete.php?action=restore&id=<?= (int) $cat['category_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Restore this category?')">
                                   <i class="bi bi-arrow-counterclockwise"></i> Restore
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
