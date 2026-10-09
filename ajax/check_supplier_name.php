<?php
declare(strict_types=1);

/**
 * check_supplier_name.php (AJAX)
 * Live duplicate-name check for supplier create/edit forms. Admin only.
 *
 * GET params:
 *   name - the proposed supplier name
 *   id   - (optional) current supplier id to exclude (edit form)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$name    = trim((string) ($_GET['name'] ?? ''));
$exclude = (int) ($_GET['id'] ?? 0);

if ($name === '') {
    json_response(['success' => false, 'message' => 'Supplier name is required.']);
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    json_response(['success' => false, 'message' => 'Supplier name must be 2–100 characters.']);
}

$available = supplier_name_unique($name, $exclude > 0 ? $exclude : null);

json_response([
    'success'   => true,
    'available' => $available,
    'message'   => $available ? 'Supplier name is available.' : 'A supplier with this name already exists.',
]);
