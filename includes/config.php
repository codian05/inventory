<?php
declare(strict_types=1);

/**
 * config.php
 * Database configuration + PDO bootstrap.
 *
 * Deploy this whole "inventory" folder into htdocs (e.g. htdocs/inventory),
 * so the app is served from http://localhost/inventory/
 */

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'inventory_system';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'Automated Inventory Management System';
const APP_URL  = 'http://localhost/inventory';

// Environment flag — flip to 'production' before a real deployment
// (or set the APP_ENV environment variable). Controls whether raw
// PHP errors are ever allowed to reach the browser. Defaults to
// 'development' so nothing changes for local XAMPP testing unless
// this is explicitly switched.
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}

/**
 * N2 — HTTPS enforcement (production only).
 *
 * In development this block does nothing at all — current XAMPP
 * HTTP behavior is completely unchanged.
 *
 * In production, a plain-HTTP request is redirected once to the same
 * URL over HTTPS. "Already HTTPS" is detected two ways: the native
 * $_SERVER['HTTPS'] flag Apache sets when it terminates TLS itself,
 * or the client's request having come in over HTTPS as reported by
 * X-Forwarded-Proto when a reverse proxy terminates TLS in front of
 * this app instead. That header is used for exactly this one narrow
 * purpose — recognizing a connection that's already secure so we
 * don't redirect it again — never for any other trust decision, so
 * there's nothing to gain by forging it: at worst a forged value
 * only affects whether this specific redirect fires, not who can log
 * in or what they can access.
 */
if (APP_ENV !== 'development') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    if (!$isHttps && php_sapi_name() !== 'cli') {
        $host = $_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST);
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

/**
 * N1 — Security headers. Applied in every environment (they're safe
 * and worth testing locally too) — only the redirect above is
 * environment-gated. Bootstrap, Chart.js, Bootstrap Icons, and the
 * Plus Jakarta Sans font are all vendored locally under assets/vendor
 * and assets/fonts (no more cdn.jsdelivr.net / Google Fonts), and the
 * avatar fallback is now an inline data: URI (no more ui-avatars.com)
 * — so every origin below is 'self' or 'data:'; nothing external is
 * loaded at runtime. No nonce system — the app's existing inline
 * <script>/style="" usage has no such infrastructure today, so
 * script-src/style-src need 'unsafe-inline'; everything else here
 * (object-src, base-uri, form-action, frame-ancestors, and the
 * origin allowlists themselves) still provides real restriction.
 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self';");
}

const MAX_FAILED = 5;
const LOCK_MINUTES = 15;
const SESSION_LIFETIME = 1800;
const PER_PAGE = 10;

const UPLOAD_DIR = 'uploads/users/';
const PRODUCT_UPLOAD_DIR = 'uploads/products/';
const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const MAX_IMAGE_SIZE = 2097152; // 2 MB

// Low stock / expiration thresholds (used by Phase 4-6 inventory modules)
const EXPIRING_SOON_DAYS = 30;

// Phase 6 — Inventory Module thresholds (all inventory math is driven
// off inventory_batches, never off a per-product column — see
// functions.php's inventory_overview_stats() and friends).
const LOW_STOCK_THRESHOLD = 20;
const EXPIRING_CRITICAL_DAYS = 7;
const EXPIRING_WARNING_DAYS = 15;
const EXPIRING_NOTICE_DAYS = 30;

// Predefined reasons for Inventory Adjustment — free-text remarks are
// still required regardless of which reason is picked.
const ADJUSTMENT_REASONS = [
    'Damaged',
    'Expired',
    'Lost',
    'Physical Count Correction',
    'Returned to Supplier',
    'Others',
];

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

/**
 * Renders a safe, generic error response — HTML for a normal page
 * request, JSON for an AJAX one (so a fault under /ajax/ never hands
 * the client malformed non-JSON it can't parse). Never includes a
 * message, file path, or stack trace; that detail always still goes
 * to the PHP error log via error_log() at the call site, same as
 * every other catch block in this codebase.
 */
function render_safe_error_response(): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/ajax/'));

    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
        return;
    }

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Something went wrong</title></head>'
        . '<body style="font-family:system-ui,-apple-system,sans-serif;padding:3rem;color:#333;text-align:center">'
        . '<h2>Something went wrong.</h2>'
        . '<p>Please try again, or contact an administrator if the problem continues.</p>'
        . '</body></html>';
}

if (APP_ENV !== 'development') {
    // Production safety net only — with display_errors off above, PHP
    // already won't print raw errors on its own; these two handlers
    // additionally make sure an uncaught fault never leaks partial
    // output (e.g. a broken AJAX response) either. Development mode is
    // left exactly as it already behaves today (no handler installed),
    // so nothing changes for local XAMPP testing.
    set_exception_handler(function (Throwable $e): void {
        error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        render_safe_error_response();
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            render_safe_error_response();
        }
    });
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            if (APP_ENV === 'development') {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
            die('Database connection failed. Please try again later.');
        }
    }

    return $pdo;
}
