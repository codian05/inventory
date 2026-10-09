<?php
declare(strict_types=1);

/**
 * session.php
 * Secure session bootstrap:
 * - HttpOnly + SameSite cookies
 * - Session regeneration on first init and on login (see auth.php)
 * - Inactivity timeout
 * - Hijacking fingerprint check
 *
 * Must be required AFTER config.php (needs SESSION_LIFETIME / APP_URL).
 */

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 1800); // 30 minutes
}

if (!defined('APP_URL')) {
    define('APP_URL', '/inventory');
}

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('INVSESSID');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    session_start();
}

function redirect_to_login(string $message): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    header('Location: ' . APP_URL . '/login.php?error=' . urlencode($message));
    exit;
}

/**
 * Normalize a client address for LOGGING purposes only (see
 * session_fingerprint() below — the address is no longer part of the
 * enforced fingerprint hash, so this normalization only affects what
 * gets written to the audit log, never whether a session is killed).
 *
 * Kept for the loopback dual-stack case (XAMPP on Windows/macOS listens
 * on both 127.0.0.1 and ::1 for "localhost") so local dev logs don't
 * show phantom "IP changed" noise for every other request.
 */
if (!function_exists('session_fingerprint_addr')) {
    function session_fingerprint_addr(?string $addr): string
    {
        $addr = (string) $addr;

        if ($addr === '::1' || $addr === '0:0:0:0:0:0:0:1' || $addr === '0000:0000:0000:0000:0000:0000:0000:0001') {
            return '127.0.0.1';
        }

        return $addr;
    }
}

/**
 * Reduce a raw User-Agent string down to a coarse, stable browser family.
 *
 * Root cause of the "desktop login works, DevTools mobile emulation fails"
 * bug: the fingerprint used to hash the *entire* HTTP_USER_AGENT string.
 * Chrome DevTools' Device Toolbar doesn't just resize the viewport — when
 * emulating a device it also overrides navigator.userAgent and the actual
 * User-Agent header sent on the wire (e.g. a desktop Chrome UA becomes an
 * iPhone "CriOS/..." or Android "Chrome/... Mobile" UA). That changed the
 * hash on the very next request and tripped the mismatch check, even
 * though it was still the same browser instance and the same user.
 *
 * Binding to a coarse family instead of the raw string survives that:
 * the family only changes if someone is actually using a different
 * browser (a real hijacking signal), not because DevTools spoofed the
 * OS/device tokens inside the same browser's UA string. This is
 * intentionally coarse — it trades UA entropy for stability, which is
 * the correct trade-off for a device/viewport-driven false positive.
 * Order matters: engine tokens that contain another token as a substring
 * (e.g. "CriOS"/"EdgiOS" also contain "Safari" later in the string) are
 * checked first so they aren't mis-bucketed as their parent engine.
 */
if (!function_exists('session_client_family')) {
    function session_client_family(?string $ua): string
    {
        $ua = (string) $ua;

        $map = [
            'EdgiOS'         => 'edge',    // Edge on iOS
            'EdgA'           => 'edge',    // Edge on Android
            'Edg'            => 'edge',    // Edge desktop
            'OPiOS'          => 'opera',   // Opera on iOS
            'OPR'            => 'opera',   // Opera desktop/Android
            'SamsungBrowser' => 'samsung',
            'FxiOS'          => 'firefox', // Firefox on iOS
            'Firefox'        => 'firefox',
            'CriOS'          => 'chrome',  // Chrome on iOS
            'Chrome'         => 'chrome',  // Chrome desktop/Android
            'Trident'        => 'ie',
            'MSIE'           => 'ie',
            'Safari'         => 'safari',  // checked last: Chrome/Edg/etc. UAs also contain "Safari"
        ];

        foreach ($map as $needle => $family) {
            if (stripos($ua, $needle) !== false) {
                return $family;
            }
        }

        return 'other';
    }
}

// Bump this if the fingerprint algorithm ever changes again. Prefixing the
// stored value with it lets us tell an old-format fingerprint (hashed with
// a previous algorithm version, e.g. the full raw User-Agent) apart from a
// current one — see the migration handling below.
if (!defined('SESSION_FINGERPRINT_PREFIX')) {
    define('SESSION_FINGERPRINT_PREFIX', 'v3:');
}

/**
 * The single source of truth for the session fingerprint. auth.php (on
 * login) and session.php (on every request) both call this exact
 * function — never re-derive the hash inline anywhere else, or the two
 * can silently drift apart again the way the UA-hash bug did.
 *
 * v3 — ACTUAL root cause of the remaining false-positive logouts
 * ("F12 opens DevTools and I'm logged out", "navigating between pages
 * eventually logs me out"):
 *
 * The v2 hash also mixed in REMOTE_ADDR and session_id(). Both are
 * unstable for a single legitimate user across ordinary requests, not
 * just on localhost:
 *
 *  - REMOTE_ADDR: any dual-stack client (IPv4 + IPv6) can have a fresh
 *    TCP connection resolve to a different source address than the
 *    previous one (RFC 6555 "Happy Eyeballs" racing, connection reuse
 *    vs. a brand-new connection, mobile carrier / corporate NAT egress
 *    changing IP mid-session, Wi-Fi <-> cellular handoff, etc). The v2
 *    fix only normalized the *loopback* case (127.0.0.1 vs ::1) for
 *    local XAMPP testing — it never addressed the same race happening
 *    for every other dual-stack client on the real internet. Opening
 *    DevTools frequently disables the HTTP cache and forces fresh
 *    connections, which is exactly when this race gets a chance to
 *    pick a different address than the one already stored.
 *  - session_id(): this is redundant (the fingerprint is already only
 *    reachable through the session that owns it — hashing the ID adds
 *    no hijack protection) and fragile: it silently invalidates the
 *    stored fingerprint the instant something legitimately calls
 *    session_regenerate_id() without perfectly re-syncing this value
 *    in the same breath.
 *
 * Binding hard-fail logout to either of those turns ordinary network
 * behavior into a security event. The browser-family bucket is the
 * part of the fingerprint that actually distinguishes "same browser,
 * same person" from "cookie replayed by a different browser/device",
 * so it's the only piece that still causes a forced logout. The
 * client IP is still recorded (see session_fingerprint_addr() call
 * site below) and logged when it changes, for audit/monitoring, but a
 * change by itself no longer destroys a valid session.
 */
if (!function_exists('session_fingerprint')) {
    function session_fingerprint(): string
    {
        return SESSION_FINGERPRINT_PREFIX . hash(
            'sha256',
            session_client_family($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }
}

if (empty($_SESSION['_init'])) {
    session_regenerate_id(true);
    $_SESSION['_init'] = true;
}

// Inactivity timeout
if (
    isset($_SESSION['_last_activity']) &&
    (time() - (int) $_SESSION['_last_activity']) > SESSION_LIFETIME
) {
    redirect_to_login('Your session expired. Please log in again.');
}

$_SESSION['_last_activity'] = time();

// Fingerprint check (browser-family binding — helps detect session
// hijacking without hard-failing on ordinary IP churn; see the
// session_fingerprint() doc comment above for why IP was removed from
// the enforced hash).
$fingerprint = session_fingerprint();

// Non-blocking IP audit trail: record when the client's address changes
// mid-session so it can be reviewed/logged, without destroying an
// otherwise-valid session over it (dual-stack racing, mobile/carrier
// NAT, and Wi-Fi<->cellular handoff all change this legitimately).
$currentAddr = session_fingerprint_addr($_SERVER['REMOTE_ADDR'] ?? '');
if (isset($_SESSION['_last_ip']) && $_SESSION['_last_ip'] !== $currentAddr) {
    error_log(sprintf(
        '[SESSION-IP-CHANGE] user_id=%s sid_hash=%s old_ip=%s new_ip=%s',
        isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : 'unset',
        substr(hash('sha256', session_id()), 0, 12),
        $_SESSION['_last_ip'],
        $currentAddr
    ));
}
$_SESSION['_last_ip'] = $currentAddr;

/* =====================================================================
 * DIAGNOSTIC LOGGING — session verification investigation.
 * Safe fields only (no passwords, hashes, cookies, or CSRF tokens).
 * OFF by default now that the fingerprint root cause (below) is
 * confirmed and fixed. To re-enable for future debugging, define
 * SESSION_DEBUG as true in config.php before session.php is loaded.
 * ===================================================================== */
if (defined('SESSION_DEBUG') && SESSION_DEBUG) {
    error_log(sprintf(
        '[SESSION-DEBUG] uri=%s status=%d name=%s sid_hash=%s user_id=%s role=%s init=%s fp_stored_present=%s fp_stored_format=%s fp_calc=%s fp_stored=%s remote_addr=%s remote_addr_norm=%s ua_family=%s',
        $_SERVER['REQUEST_URI'] ?? '',
        session_status(),
        session_name(),
        substr(hash('sha256', session_id()), 0, 12),
        isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : 'unset',
        $_SESSION['role'] ?? 'unset',
        !empty($_SESSION['_init']) ? 'true' : 'false',
        isset($_SESSION['_fingerprint']) ? 'yes' : 'no',
        isset($_SESSION['_fingerprint']) && str_starts_with((string) $_SESSION['_fingerprint'], SESSION_FINGERPRINT_PREFIX) ? 'current' : (isset($_SESSION['_fingerprint']) ? 'legacy' : 'n/a'),
        substr($fingerprint, 0, 20),
        isset($_SESSION['_fingerprint']) ? substr((string) $_SESSION['_fingerprint'], 0, 20) : 'n/a',
        $_SERVER['REMOTE_ADDR'] ?? '',
        session_fingerprint_addr($_SERVER['REMOTE_ADDR'] ?? ''),
        session_client_family($_SERVER['HTTP_USER_AGENT'] ?? '')
    ));
}
/* ===================================================================== */

if (isset($_SESSION['_fingerprint'])) {
    $storedFingerprint = (string) $_SESSION['_fingerprint'];

    if (str_starts_with($storedFingerprint, SESSION_FINGERPRINT_PREFIX)) {
        if (!hash_equals($storedFingerprint, $fingerprint)) {
            redirect_to_login('Session verification failed. Please log in again.');
        }
    } else {
        // Legacy fingerprint (created before this fix, hashed with the
        // old algorithm). We cannot meaningfully compare it against the
        // new format — it will never match — so treat this one time as
        // an upgrade rather than a hijack signal and just re-issue it
        // under the current algorithm. This only fires once per
        // already-authenticated session; every check after that is a
        // normal strict comparison. Deliberately not a security
        // regression: reaching this branch already requires a session ID
        // that PHP resumed as a still-valid, still-authenticated session.
        $_SESSION['_fingerprint'] = $fingerprint;
    }
} else {
    $_SESSION['_fingerprint'] = $fingerprint;
}
