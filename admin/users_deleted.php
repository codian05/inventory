<?php
declare(strict_types=1);

/**
 * users_deleted.php
 * Admin-only view of soft-deleted users with restore option.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$users = db()->query(
    'SELECT * FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
)->fetchAll();

$pageTitle = 'Deleted Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm">
    <div class="card-header d-flex align-items-center">
        <i class="bi bi-trash3 me-1"></i> <strong>Deleted Users</strong>
        <a href="users.php" class="btn btn-sm btn-outline-secondary ms-auto">Back to Users</a>
    </div>
    <div class="card-body">
        <?php if (!$users): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No deleted users.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th><th>Full Name</th><th>Username</th><th>Email</th>
                            <th>Role</th><th>Deleted At</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= (int) $u['id'] ?></td>
                            <td><?= e($u['fullname']) ?></td>
                            <td><?= e($u['username']) ?></td>
                            <td><?= e($u['email'] ?: '—') ?></td>
                            <td><span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'info' ?>">
                                <?= e(ucfirst($u['role'])) ?></span></td>
                            <td><?= e(format_date($u['deleted_at'])) ?></td>
                            <td class="text-end">
                                <a href="user_delete.php?action=restore&id=<?= (int) $u['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Restore this user?')">
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
