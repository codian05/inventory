<?php
declare(strict_types=1);

/**
 * check_branch_code.php (AJAX)
 * Live duplicate-code check for the branch create/edit form. Admin
 * only (staff never see the branch management pages that call this).
 *
 * GET params:
 *   code - the proposed branch code
 *   id   - (optional) current branch id to exclude (edit form)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$code    = trim((string) ($_GET['code'] ?? ''));
$exclude = (int) ($_GET['id'] ?? 0);

if ($code === '') {
    json_response(['success' => false, 'message' => 'Branch code is required.']);
}

$available = branch_code_unique($code, $exclude > 0 ? $exclude : null);

json_response([
    'success'   => true,
    'available' => $available,
    'message'   => $available ? 'Branch code is available.' : 'A branch with this code already exists.',
]);
