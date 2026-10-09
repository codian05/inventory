<?php
declare(strict_types=1);

/**
 * supplier_delete.php
 * Handles admin actions on a supplier: soft-delete, activate,
 * deactivate, restore. Admin only. Same CSRF pattern as
 * user_delete.php / category_delete.php.
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
$supplier = fetch_supplier($id, $includeDeleted);

if (!$supplier) {
    set_flash('danger', 'Supplier not found.');
    header('Location: suppliers.php');
    exit;
}

switch ($action) {
    case 'activate':
        db()->prepare("UPDATE suppliers SET status='active' WHERE supplier_id=?")->execute([$id]);
        log_activity(current_user_id(), "Activated supplier '{$supplier['supplier_name']}'");
        set_flash('success', "Supplier '{$supplier['supplier_name']}' activated.");
        header('Location: suppliers.php');
        break;

    case 'deactivate':
        db()->prepare("UPDATE suppliers SET status='inactive' WHERE supplier_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deactivated supplier '{$supplier['supplier_name']}'");
        set_flash('success', "Supplier '{$supplier['supplier_name']}' deactivated.");
        header('Location: suppliers.php');
        break;

    case 'restore':
        db()->prepare("UPDATE suppliers SET deleted_at=NULL WHERE supplier_id=?")->execute([$id]);
        log_activity(current_user_id(), "Restored supplier '{$supplier['supplier_name']}'");
        set_flash('success', "Supplier '{$supplier['supplier_name']}' restored.");
        header('Location: suppliers_deleted.php');
        break;

    case 'delete':
    default:
        db()->prepare("UPDATE suppliers SET deleted_at=NOW() WHERE supplier_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deleted supplier '{$supplier['supplier_name']}'");
        set_flash('success', "Supplier '{$supplier['supplier_name']}' deleted (soft delete).");
        header('Location: suppliers.php');
        break;
}

exit;
