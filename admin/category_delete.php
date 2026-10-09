<?php
declare(strict_types=1);

/**
 * category_delete.php
 * Handles admin actions on a category: soft-delete, activate,
 * deactivate, restore. Admin only. Same CSRF pattern as user_delete.php
 * (GET token verified via csrf_verify() against $_SESSION['csrf_token']).
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
$category = fetch_category($id, $includeDeleted);

if (!$category) {
    set_flash('danger', 'Category not found.');
    header('Location: categories.php');
    exit;
}

switch ($action) {
    case 'activate':
        db()->prepare("UPDATE categories SET status='active' WHERE category_id=?")->execute([$id]);
        log_activity(current_user_id(), "Activated category '{$category['category_name']}'");
        set_flash('success', "Category '{$category['category_name']}' activated.");
        header('Location: categories.php');
        break;

    case 'deactivate':
        db()->prepare("UPDATE categories SET status='inactive' WHERE category_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deactivated category '{$category['category_name']}'");
        set_flash('success', "Category '{$category['category_name']}' deactivated.");
        header('Location: categories.php');
        break;

    case 'restore':
        db()->prepare("UPDATE categories SET deleted_at=NULL WHERE category_id=?")->execute([$id]);
        log_activity(current_user_id(), "Restored category '{$category['category_name']}'");
        set_flash('success', "Category '{$category['category_name']}' restored.");
        header('Location: categories_deleted.php');
        break;

    case 'delete':
    default:
        db()->prepare("UPDATE categories SET deleted_at=NOW() WHERE category_id=?")->execute([$id]);
        log_activity(current_user_id(), "Deleted category '{$category['category_name']}'");
        set_flash('success', "Category '{$category['category_name']}' deleted (soft delete).");
        header('Location: categories.php');
        break;
}

exit;
