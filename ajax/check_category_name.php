<?php
declare(strict_types=1);

/**
 * check_category_name.php (AJAX)
 * Live duplicate-name check for category create/edit forms. Admin only
 * (staff never see the create/edit forms that call this).
 *
 * GET params:
 *   name - the proposed category name
 *   id   - (optional) current category id to exclude (edit form)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$name    = trim((string) ($_GET['name'] ?? ''));
$exclude = (int) ($_GET['id'] ?? 0);

$errors = validate_category_name($name);
if ($errors) {
    json_response(['success' => false, 'message' => $errors[0]]);
}

$available = category_name_unique($name, $exclude > 0 ? $exclude : null);

json_response([
    'success'   => true,
    'available' => $available,
    'message'   => $available ? 'Category name is available.' : 'A category with this name already exists.',
]);
