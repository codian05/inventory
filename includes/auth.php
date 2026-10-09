<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';

/**
 * Attempt to log a user in.
 * Returns: ['success' => bool, 'error' => string]
 */
function attempt_login(string $username, string $password): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, username, fullname, role, branch_id, password, status, deleted_at, locked_until, failed_attempts
         FROM users
         WHERE username = ?
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid username or password.'];
    }

    if (!empty($user['deleted_at'])) {
        return ['success' => false, 'error' => 'This account has been removed.'];
    }

    if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
        $mins = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
        return ['success' => false, 'error' => "Account locked. Try again in {$mins} minute(s)."];
    }

    if (!password_verify($password, (string) $user['password'])) {
        $attempts = ((int) $user['failed_attempts']) + 1;
        $lockUntil = null;

        if ($attempts >= MAX_FAILED) {
            $lockUntil = date('Y-m-d H:i:s', time() + (LOCK_MINUTES * 60));
            $attempts = 0;
        }

        $upd = $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
        $upd->execute([$attempts, $lockUntil, $user['id']]);

        $remaining = MAX_FAILED - $attempts;
        $msg = 'Invalid username or password.';

        if ($lockUntil) {
            $msg = 'Too many failed attempts. Account locked for ' . LOCK_MINUTES . ' minutes.';
            log_activity((int) $user['id'], "Account locked after {$attempts} failed attempt(s)");
        } elseif ($remaining > 0 && $remaining < MAX_FAILED) {
            $msg .= " {$remaining} attempt(s) remaining.";
        }

        return ['success' => false, 'error' => $msg];
    }

    if (($user['status'] ?? 'active') === 'inactive') {
        return ['success' => false, 'error' => 'This account has been deactivated. Contact an administrator.'];
    }

    $pdo->prepare(
        'UPDATE users
         SET failed_attempts = 0,
             locked_until = NULL,
             last_login = NOW()
         WHERE id = ?'
    )->execute([$user['id']]);

    // Prevent session fixation: issue a fresh session id on privilege change.
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['fullname'] = (string) $user['fullname'];
    $_SESSION['role'] = (string) $user['role'];
    // Staff are always pinned to a branch; admin is system-wide unless
    // explicitly assigned one. Re-derived from the DB on every request
    // in require_login() below, never trusted from the client.
    $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;
    $_SESSION['_fingerprint'] = session_fingerprint();
    $_SESSION['_last_activity'] = time();
    $_SESSION['_init'] = true;

    log_activity((int) $user['id'], 'Logged in');

    return ['success' => true, 'error' => ''];
}

/**
 * Central logout routine. All logout entry points must call this
 * function — never destroy the session ad hoc, or activity logging
 * and cookie clearing get inconsistent (this was a real bug).
 */
function logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        log_activity((int) $_SESSION['user_id'], 'Logged out');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?? '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => $p['secure'] ?? false,
            'httponly' => $p['httponly'] ?? true,
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
