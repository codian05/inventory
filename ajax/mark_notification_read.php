<?php
declare(strict_types=1);

/**
 * mark_notification_read.php (AJAX)
 * Admin only (notifications.php is admin-only). POST + CSRF since
 * this is a state-changing action, not a read.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

csrf_check();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid notification id.']);
}

mark_notification_read($id);

json_response(['success' => true]);
