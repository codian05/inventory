<?php
declare(strict_types=1);

/**
 * check_username.php (AJAX)
 * Checks whether a username is available (unique).
 * Used by live validation on create/edit forms. Any logged-in user
 * (admin or staff) may call this — staff need it too when editing
 * their own username.
 *
 * GET params:
 *   username - the proposed username
 *   id       - (optional) current user id to exclude (for edit form)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$username = trim((string) ($_GET['username'] ?? ''));
$exclude  = (int) ($_GET['id'] ?? 0);

if ($username === '') {
    json_response(['success' => false, 'message' => 'Username is required.']);
}
if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
    json_response(['success' => false, 'message' => 'Username must be 3–50 characters.']);
}
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
    json_response([
        'success' => false,
        'message' => 'Only letters, numbers, underscores, dots and hyphens allowed.',
    ]);
}

$available = username_unique($username, $exclude > 0 ? $exclude : null);

json_response([
    'success'   => true,
    'available' => $available,
    'message'   => $available ? 'Username is available.' : 'Username already taken.',
]);
