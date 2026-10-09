<?php
declare(strict_types=1);

/**
 * product_delete.php
 * Handles admin actions on a product: soft-delete, activate,
 * deactivate, restore. Admin only. Same CSRF pattern as
 * user_delete.php / category_delete.php / supplier_delete.php.
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
$product = fetch_product($id, $includeDeleted);

if (!$product) {
    set_flash('danger', 'Product not found.');
    header('Location: products.php');
    exit;
}

switch ($action) {
    case 'activate':
        db()->prepare("UPDATE products SET status='active' WHERE product_id=?")->execute([$id]);
        log_activity(current_user_id(), "Activated product '{$product['product_name']}'");
        set_flash('success', "Product '{$product['product_name']}' activated.");
        header('Location: products.php');
        break;

    case 'deactivate':
        db()->prepare("UPDATE products SET status='inactive' WHERE product_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deactivated product '{$product['product_name']}'");
        set_flash('success', "Product '{$product['product_name']}' deactivated.");
        header('Location: products.php');
        break;

    case 'restore':
        db()->prepare("UPDATE products SET deleted_at=NULL WHERE product_id=?")->execute([$id]);
        log_activity(current_user_id(), "Restored product '{$product['product_name']}'");
        set_flash('success', "Product '{$product['product_name']}' restored.");
        header('Location: product_view.php?id=' . $id);
        break;

    case 'delete':
    default:
        db()->prepare("UPDATE products SET deleted_at=NOW() WHERE product_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deleted product '{$product['product_name']}'");
        set_flash('success', "Product '{$product['product_name']}' deleted (soft delete).");
        header('Location: products.php');
        break;
}

exit;
