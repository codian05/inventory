<?php
declare(strict_types=1);

/**
 * products_deleted.php
 * Admin-only view of soft-deleted products with restore option.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$products = db()->query(
    "SELECT p.*, c.category_name FROM products p
     LEFT JOIN categories c ON c.category_id = p.category_id
     WHERE p.deleted_at IS NOT NULL
     ORDER BY p.deleted_at DESC"
)->fetchAll();

$pageTitle = 'Deleted Products';
require __DIR__ . '/../includes/header.php';
?>
<div class="card chart-card">
    <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-trash3 me-1"></i> <strong>Deleted Products</strong></span>
        <a href="products.php" class="btn btn-sm btn-outline-secondary ms-auto">Back to Products</a>
    </div>
    <div class="card-body">
        <?php if (!$products): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No deleted products.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Barcode</th><th>Name</th><th>Category</th><th>Deleted At</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= $p['barcode'] ? '<code>' . e($p['barcode']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                            <td class="fw-semibold"><?= e($p['product_name']) ?></td>
                            <td class="text-muted small"><?= e($p['category_name'] ?: '—') ?></td>
                            <td><?= e(format_date($p['deleted_at'])) ?></td>
                            <td class="text-end">
                                <a href="product_delete.php?action=restore&id=<?= (int) $p['product_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Restore this product?')">
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
