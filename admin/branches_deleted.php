<?php
declare(strict_types=1);

/**
 * branches_deleted.php
 * Admin-only view of soft-deleted branches with restore option.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$branches = db()->query(
    'SELECT * FROM branches WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
)->fetchAll();

$pageTitle = 'Deleted Branches';
require __DIR__ . '/../includes/header.php';
?>
<div class="card chart-card">
    <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-trash3 me-1"></i> <strong>Deleted Branches</strong></span>
        <a href="branches.php" class="btn btn-sm btn-outline-secondary ms-auto">Back to Branches</a>
    </div>
    <div class="card-body">
        <?php if (!$branches): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No deleted branches.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th><th>Name</th><th>Address</th><th>Deleted At</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($branches as $b): ?>
                        <tr>
                            <td><code><?= e($b['branch_code']) ?></code></td>
                            <td class="fw-semibold"><?= e($b['branch_name']) ?></td>
                            <td class="text-muted small"><?= e($b['address'] ?: '—') ?></td>
                            <td><?= e(format_date($b['deleted_at'])) ?></td>
                            <td class="text-end">
                                <a href="branch_delete.php?action=restore&id=<?= (int) $b['branch_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Restore this branch?')">
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
