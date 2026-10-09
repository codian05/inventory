<?php
declare(strict_types=1);

/**
 * user_password_page.php
 * Change password page, shared by admin/user_password.php and
 * staff/user_password.php.
 * - Admins can change anyone's password (without entering current password).
 * - Staff can change only their own password and must provide current password.
 * Password rules: min 12 chars, upper, lower, number, special.
 * Prevents reusing the current password.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$id      = (int) ($_GET['id'] ?? current_user_id());
$isAdmin = current_user_role() === 'admin';
$isSelf  = ($id === current_user_id());

// Staff can change only their own password
if (!$isAdmin && !$isSelf) {
    http_response_code(403);
    die('Access denied.');
}

$user = fetch_user($id);
if (!$user) {
    http_response_code(404);
    die('User not found.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    // Self-change always requires the current password, even for admins
    // changing their own password (an admin resetting someone ELSE's
    // password does not need it).
    if ($isSelf) {
        if ($current === '') {
            $errors[] = 'Current password is required.';
        } elseif (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if ($new === '') {
        $errors[] = 'New password is required.';
    } else {
        $errors = array_merge($errors, validate_password($new));
    }

    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if ($isSelf && $current !== '' && $new !== '' &&
        password_verify($current, $user['password']) && $current === $new) {
        $errors[] = 'New password cannot be the same as the current password.';
    }
    if (!$isSelf && $new !== '' && password_verify($new, $user['password'])) {
        $errors[] = 'New password must be different from the existing password.';
    }

    if (!$errors) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password=?, failed_attempts=0, locked_until=NULL WHERE id=?')
            ->execute([$hash, $id]);

        log_activity(current_user_id(), $isSelf
            ? 'Changed own password'
            : "Reset password for '{$user['username']}'");
        set_flash('success', 'Password changed successfully.');
        header('Location: user_profile.php?id=' . $id);
        exit;
    }
}

$pageTitle = 'Change Password';
require __DIR__ . '/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="bi bi-key me-1"></i> Change Password
                <?php if (!$isSelf): ?>
                    <span class="badge bg-info ms-2">Admin reset for <?= e($user['username']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>

                <div class="alert alert-info py-2 small">
                    <i class="bi bi-info-circle"></i> Password must be at least 12 characters and
                    include uppercase, lowercase, a number, and a special character.
                </div>

                <form method="POST" id="passwordForm" novalidate>
                    <?= csrf_field() ?>
                    <?php if ($isSelf): ?>
                    <div class="mb-3">
                        <label class="form-label">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="current_password" id="current_password"
                                   class="form-control" required>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('current_password', this)"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="new_password"
                                   class="form-control" required oninput="checkPassword(this)">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('new_password', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="pwFeedback" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirm_password"
                                   class="form-control" required oninput="checkConfirm()">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('confirm_password', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="confirmFeedback" class="form-text"></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-shield-lock me-1"></i> Update Password</button>
                        <a href="user_profile.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
