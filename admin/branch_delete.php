<?php
declare(strict_types=1);

/**
 * branch_delete.php
 * Handles admin actions on a branch: soft-delete, activate,
 * deactivate, restore. Admin only. Same CSRF pattern as
 * category_delete.php (GET token verified via csrf_verify()).
 *
 * Soft delete / deactivate NEVER touch inventory_batches or
 * stock_transactions rows — those keep their branch_id forever, so
 * historical records stay intact and reports remain accurate even
 * after a branch is retired. See branches_list_page.php's confirm()
 * text, which tells the admin exactly that.
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

$includeDeleted = ($action === 'restore');
$branch = fetch_branch($id, $includeDeleted);

if (!$branch) {
    set_flash('danger', 'Branch not found.');
    header('Location: branches.php');
    exit;
}

switch ($action) {
    case 'activate':
        db()->prepare("UPDATE branches SET status='active' WHERE branch_id=?")->execute([$id]);
        log_activity(current_user_id(), "Activated branch '{$branch['branch_name']}'");
        set_flash('success', "Branch '{$branch['branch_name']}' activated.");
        header('Location: branches.php');
        break;

    case 'deactivate':
        db()->prepare("UPDATE branches SET status='inactive' WHERE branch_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deactivated branch '{$branch['branch_name']}'");
        set_flash('success', "Branch '{$branch['branch_name']}' deactivated. Existing inventory and history are untouched; staff assigned here can no longer be selected for new Stock In/Out/Transfer until it's reactivated.");
        header('Location: branches.php');
        break;

    case 'restore':
        db()->prepare("UPDATE branches SET deleted_at=NULL WHERE branch_id=?")->execute([$id]);
        log_activity(current_user_id(), "Restored branch '{$branch['branch_name']}'");
        set_flash('success', "Branch '{$branch['branch_name']}' restored.");
        header('Location: branches_deleted.php');
        break;

    case 'delete':
    default:
        db()->prepare("UPDATE branches SET deleted_at=NOW(), status='inactive' WHERE branch_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deleted branch '{$branch['branch_name']}'");
        set_flash('success', "Branch '{$branch['branch_name']}' deleted (soft delete). Its inventory and transaction history are preserved.");
        header('Location: branches.php');
        break;
}

exit;
