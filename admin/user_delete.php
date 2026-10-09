<?php
declare(strict_types=1);

/**
 * user_delete.php
 * Handles admin actions on a user account: soft-delete, activate,
 * deactivate, restore. Admin only. Admin cannot act on their own
 * account through this endpoint.
 *
 * BUG FIX: this previously validated the GET csrf token against
 * $_SESSION['_csrf'], a key that is never set anywhere — csrf_token()
 * stores it under $_SESSION['csrf_token']. Every link on this page was
 * therefore guaranteed to fail with a 419. Now uses the shared
 * csrf_verify() helper against the correct session key.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id     = (int) ($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'delete';
$token  = (string) ($_GET['csrf'] ?? '');

if (!csrf_verify($token)) {
    http_response_code(419);
    die('Invalid or expired security token. Please go back and try again.');
}

if ($id === current_user_id()) {
    set_flash('danger', 'You cannot delete, activate, or deactivate your own account from here.');
    header('Location: users.php');
    exit;
}

$user = fetch_user($id);
if (!$user) {
    set_flash('danger', 'User not found.');
    header('Location: users.php');
    exit;
}

switch ($action) {
    case 'activate':
        db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
        log_activity(current_user_id(), "Activated user '{$user['username']}'");
        set_flash('success', "User '{$user['username']}' activated.");
        break;

    case 'deactivate':
        db()->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$id]);
        log_activity(current_user_id(), "Deactivated user '{$user['username']}'");
        set_flash('success', "User '{$user['username']}' deactivated.");
        break;

    case 'restore':
        db()->prepare("UPDATE users SET deleted_at=NULL WHERE id=?")->execute([$id]);
        log_activity(current_user_id(), "Restored user '{$user['username']}'");
        set_flash('success', "User '{$user['username']}' restored.");
        break;

    case 'delete':
    default:
        db()->prepare("UPDATE users SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        log_activity(current_user_id(), "Deleted user '{$user['username']}'");
        set_flash('success', "User '{$user['username']}' deleted (soft delete).");
        break;
}

header('Location: users.php');
exit;
