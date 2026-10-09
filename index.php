<?php
declare(strict_types=1);

/**
 * index.php
 * Generic entry point. Sends the visitor to login, or straight to
 * their role's dashboard if already authenticated.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
redirect_to_role_dashboard();
