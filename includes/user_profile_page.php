<?php
declare(strict_types=1);

/**
 * user_profile_page.php
 * Display a user's profile. Admins can view anyone; staff can view
 * only their own. Shared by admin/user_profile.php and
 * staff/user_profile.php so there's one implementation, not two.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$id      = (int) ($_GET['id'] ?? current_user_id());
$isAdmin = current_user_role() === 'admin';
$isSelf  = ($id === current_user_id());

if (!$isAdmin && !$isSelf) {
    http_response_code(403);
    die('Access denied.');
}

$user = fetch_user($id);
if (!$user) {
    http_response_code(404);
    die('User not found.');
}

// Activity log for this user
$logStmt = db()->prepare(
    'SELECT activity, created_at FROM activity_logs
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 15'
);
$logStmt->execute([$id]);
$logs = $logStmt->fetchAll();

$editUrl = ($isAdmin ? 'user_edit.php?id=' : 'user_edit.php?id=') . $id;

$pageTitle = 'User Profile';
require __DIR__ . '/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <img src="<?= e(avatar_url($user['profile_image'], $user['fullname'])) ?>"
                     alt="avatar" class="rounded-circle mb-3 avatar-img" width="120" height="120"
                     style="object-fit:cover">
                <h5 class="mb-1"><?= e($user['fullname']) ?></h5>
                <p class="text-muted mb-2">@<?= e($user['username']) ?></p>
                <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : 'info' ?> mb-2">
                    <?= e(ucfirst($user['role'])) ?>
                </span>
                <div>
                    <?php if ($user['status'] === 'active'): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="d-grid gap-2 mt-3">
                    <a href="<?= e($editUrl) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i> Edit Profile</a>
                    <a href="user_password.php?id=<?= $id ?>" class="btn btn-outline-warning">
                        <i class="bi bi-key me-1"></i> Change Password</a>
                    <?php if ($isAdmin && !$isSelf): ?>
                        <a href="users.php" class="btn btn-outline-secondary">Back to Users</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Account Details</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><th class="w-25">Full Name</th><td class="text-break"><?= e($user['fullname']) ?></td></tr>
                    <tr><th>Username</th><td class="text-break"><?= e($user['username']) ?></td></tr>
                    <tr><th>Email</th><td class="text-break"><?= e($user['email'] ?: '—') ?></td></tr>
                    <tr><th>Role</th><td><?= e(ucfirst($user['role'])) ?></td></tr>
                    <tr><th>Branch</th><td>
                        <?php $userBranch = $user['branch_id'] ? fetch_branch((int) $user['branch_id'], true) : null; ?>
                        <?php if ($userBranch): ?>
                            <span class="badge bg-light text-dark border"><?= e($userBranch['branch_name']) ?></span>
                        <?php elseif ($user['role'] === 'admin'): ?>
                            <span class="text-muted">All Branches (System Administrator)</span>
                        <?php else: ?>
                            <span class="text-danger">Unassigned</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Status</th><td><?= e(ucfirst($user['status'])) ?></td></tr>
                    <tr><th>Last Login</th><td><?= e(format_date($user['last_login'])) ?></td></tr>
                    <tr><th>Account Created</th><td><?= e(format_date($user['created_at'])) ?></td></tr>
                    <tr><th>Last Updated</th><td><?= e(format_date($user['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Recent Activity</div>
            <div class="card-body">
                <?php if (!$logs): ?>
                    <p class="text-muted mb-0">No activity recorded.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($logs as $l): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between">
                                <span><i class="bi bi-dot"></i> <?= e($l['activity']) ?></span>
                                <small class="text-muted"><?= e(format_date($l['created_at'])) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
