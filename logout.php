<?php
declare(strict_types=1);

/**
 * logout.php
 * The ONE logout entry point for the whole app (admin and staff both
 * link here). Previously there were two inconsistent copies of this
 * logic — this file plus admin/logout.php — which is exactly the kind
 * of duplication that leads to one path skipping activity logging.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

logout();

header('Location: ' . APP_URL . '/login.php');
exit;
