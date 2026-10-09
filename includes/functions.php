<?php
declare(strict_types=1);

// session.php is the sole session bootstrap (cookie name/params, secure
// flags, regeneration, timeout, and fingerprint check all live there).
// Do not start or configure the session again here — a second,
// unconfigured session_start() call is dead code today only because
// session.php always runs first, and it invites exactly the kind of
// "session init logic in more than one place" drift that caused the
// original session bugs.
require_once __DIR__ . '/session.php';

/* =====================================================================
 * OUTPUT / ESCAPING
 * ===================================================================== */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* =====================================================================
 * FLASH MESSAGES
 * ===================================================================== */

if (!function_exists('get_flash')) {
    function get_flash(): array
    {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return [];
        }

        $flashes = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flashes;
    }
}

if (!function_exists('set_flash')) {
    function set_flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

/* =====================================================================
 * AUTH / SESSION HELPERS
 * ===================================================================== */

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role(): string
    {
        return (string) ($_SESSION['role'] ?? '');
    }
}

if (!function_exists('current_user_name')) {
    function current_user_name(): string
    {
        return (string) ($_SESSION['fullname'] ?? 'Guest');
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!is_logged_in()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }

        // Re-check the account against the database on every protected
        // request. Without this, an admin deactivating/deleting a user
        // has no effect on that user's already-open session — they'd
        // keep full access until the session naturally times out (up
        // to SESSION_LIFETIME) or they log out themselves. This is a
        // deliberate DB check (not part of the session fingerprint in
        // session.php), separate concern: session.php verifies "is this
        // still the same browser session", this verifies "is this user
        // still allowed to be logged in at all".
        $user = fetch_user(current_user_id());

        if (!$user || !empty($user['deleted_at']) || ($user['status'] ?? 'active') === 'inactive') {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            header('Location: ' . APP_URL . '/login.php?error=' . urlencode('Your account is no longer active. Please contact an administrator.'));
            exit;
        }

        // Re-sync branch assignment from the DB on every request, same
        // reasoning as the status check above: if an admin reassigns
        // this staff member to a different branch mid-session, that
        // change must take effect immediately, not just on next login.
        // This is the ONLY place branch_id is ever written into the
        // session — it is never read from $_GET/$_POST for a staff
        // user anywhere else in the app.
        $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;
    }
}

/* =====================================================================
 * MULTI-BRANCH HELPERS
 * ===================================================================== */

/**
 * The authenticated user's own branch, or null for a system-wide
 * admin. This is the ONLY source of truth for "which branch is this
 * staff member allowed to touch" — always read from the session
 * (which require_login() keeps in sync with the DB every request),
 * never from a request parameter.
 */
if (!function_exists('current_user_branch_id')) {
    function current_user_branch_id(): ?int
    {
        return isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null
            ? (int) $_SESSION['branch_id']
            : null;
    }
}

/**
 * Resolves which branch_id a request should actually operate on.
 *
 * - Staff: ALWAYS their own assigned branch. $requested is ignored
 *   entirely — a staff user can never widen their own access by
 *   passing a different branch_id in the URL, a POST field, or a
 *   hidden form input.
 * - Admin: the requested branch if provided and valid, otherwise
 *   null (meaning "all branches" for read/reporting contexts, or
 *   "no branch selected yet" for a write form that requires one).
 *
 * Every page that reads or writes branch-scoped data calls this
 * instead of touching $_GET['branch_id'] / $_POST['branch_id']
 * directly.
 */
if (!function_exists('effective_branch_id')) {
    function effective_branch_id(?int $requested): ?int
    {
        if (current_user_role() !== 'admin') {
            return current_user_branch_id();
        }

        return $requested !== null && $requested > 0 ? $requested : null;
    }
}

/**
 * Guards a branch-scoped write/view: staff may only proceed for their
 * own branch; admin may proceed for any branch. Halts the request
 * with 403 otherwise. Call this at the top of any page/action that
 * receives a branch_id from the client and is about to use it for a
 * read or write.
 */
if (!function_exists('require_branch_access')) {
    function require_branch_access(int $branchId): void
    {
        if (current_user_role() === 'admin') {
            return;
        }

        if ($branchId !== current_user_branch_id()) {
            http_response_code(403);
            die('Access denied: you are not assigned to this branch.');
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_login();

        if (current_user_role() !== 'admin') {
            redirect_to_role_dashboard();
        }
    }
}

if (!function_exists('require_staff')) {
    function require_staff(): void
    {
        require_login();

        if (current_user_role() !== 'staff') {
            redirect_to_role_dashboard();
        }
    }
}

/**
 * Central "send this authenticated user to the dashboard that matches
 * their role" helper. Used whenever someone hits a page they aren't
 * authorized for, or a generic entry point (index.php / login.php).
 */
if (!function_exists('redirect_to_role_dashboard')) {
    function redirect_to_role_dashboard(): void
    {
        $role = current_user_role();

        if ($role === 'admin') {
            header('Location: ' . APP_URL . '/admin/dashboard.php');
            exit;
        }

        if ($role === 'staff') {
            header('Location: ' . APP_URL . '/staff/dashboard.php');
            exit;
        }

        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

/* =====================================================================
 * CSRF PROTECTION
 * ===================================================================== */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
    }
}

/**
 * Constant-time check against the token stored in the session.
 * Used for GET-based action links (e.g. delete/activate) where the
 * token travels as a query parameter instead of a POST field.
 */
if (!function_exists('csrf_verify')) {
    function csrf_verify(string $token): bool
    {
        return $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}

/**
 * Validates the csrf_token POST field for state-changing form submissions.
 * Halts the request with 419 on failure.
 */
if (!function_exists('csrf_check')) {
    function csrf_check(): void
    {
        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!csrf_verify($token)) {
            http_response_code(419);
            die('Invalid or expired security token. Please refresh the page and try again.');
        }
    }
}

/* =====================================================================
 * INPUT VALIDATION
 * ===================================================================== */

if (!function_exists('clean_input')) {
    function clean_input(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('validate_email')) {
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Password policy: 12+ chars, upper, lower, number, special character.
 * Returns an array of human-readable error strings (empty = valid).
 */
if (!function_exists('validate_password')) {
    function validate_password(string $password): array
    {
        $errors = [];

        if (mb_strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must include at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must include at least one special character.';
        }

        return $errors;
    }
}

if (!function_exists('username_unique')) {
    function username_unique(string $username, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE username = ?';
        $params = [$username];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

if (!function_exists('email_unique')) {
    function email_unique(string $email, ?int $excludeId = null): bool
    {
        if ($email === '') {
            return true;
        }

        $sql = 'SELECT COUNT(*) FROM users WHERE email = ?';
        $params = [$email];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

/* =====================================================================
 * USER LOOKUP / DISPLAY HELPERS
 * ===================================================================== */

if (!function_exists('fetch_user')) {
    function fetch_user(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }
}

if (!function_exists('avatar_url')) {
    function avatar_url(?string $profileImage, string $fullname): string
    {
        if (!empty($profileImage) && is_file(BASE_PATH . '/' . ltrim($profileImage, '/'))) {
            return rtrim(APP_URL, '/') . '/' . ltrim($profileImage, '/');
        }

        $initials = strtoupper(substr(trim($fullname), 0, 1) ?: 'U');

        // Offline replacement for the old ui-avatars.com fallback: a tiny
        // inline SVG (initials on a filled circle) encoded as a data: URI,
        // so the <img> tag never triggers a network request at all.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
            . '<rect width="128" height="128" rx="64" fill="#0d6efd"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" '
            . 'font-family="Segoe UI, Roboto, Arial, sans-serif" font-size="56" '
            . 'font-weight="700" fill="#ffffff">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</text>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $datetime, string $format = 'M j, Y g:i A'): string
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }

        $timestamp = strtotime($datetime);

        return $timestamp !== false ? date($format, $timestamp) : '—';
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        if (empty($datetime)) {
            return 'Never';
        }

        $time = strtotime($datetime);
        if ($time === false) {
            return 'Unknown time';
        }

        $diff = time() - $time;
        if ($diff < 0) {
            return 'Just now';
        }
        if ($diff < 60) {
            return $diff . ' second' . ($diff === 1 ? '' : 's') . ' ago';
        }

        $minutes = (int) floor($diff / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }

        $hours = (int) floor($diff / 3600);
        if ($hours < 24) {
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }

        $days = (int) floor($diff / 86400);
        if ($days < 7) {
            return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        }

        $weeks = (int) floor($diff / 604800);
        if ($weeks < 5) {
            return $weeks . ' week' . ($weeks === 1 ? '' : 's') . ' ago';
        }

        $months = (int) floor($diff / 2592000);
        if ($months < 12) {
            return $months . ' month' . ($months === 1 ? '' : 's') . ' ago';
        }

        $years = (int) floor($diff / 31536000);
        return $years . ' year' . ($years === 1 ? '' : 's') . ' ago';
    }
}

/**
 * Canonical user statistics used by both the admin dashboard and the
 * users list stat cards. Counts exclude soft-deleted accounts.
 */
if (!function_exists('user_stats')) {
    function user_stats(): array
    {
        $stats = ['total' => 0, 'admin' => 0, 'staff' => 0, 'active' => 0, 'inactive' => 0];

        try {
            $pdo = db();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
            $stats['total'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role = 'admin'");
            $stats['admin'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND role = 'staff'");
            $stats['staff'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active'");
            $stats['active'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'inactive'");
            $stats['inactive'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('user_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

/* =====================================================================
 * PAGINATION
 * ===================================================================== */

/**
 * @return array{0:int,1:int,2:int} [$totalPages, $offset, $currentPage]
 */
if (!function_exists('paginate')) {
    function paginate(int $total, int $perPage, int $page): array
    {
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [$totalPages, $offset, $page];
    }
}

if (!function_exists('pagination_links')) {
    function pagination_links(int $page, int $totalPages, string $paramName = 'page'): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $params = $_GET;
        $html = '<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0 flex-wrap">';

        $prevDisabled = $page <= 1 ? ' disabled' : '';
        $params[$paramName] = max(1, $page - 1);
        $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="?' . e(http_build_query($params)) . '">&laquo;</a></li>';

        // Windowed page list: page 1, last page, and a small range around the
        // current page, with an ellipsis for any gap. Prevents rendering
        // dozens of <li> page links in a single unwrapped row once a list
        // grows past a handful of pages.
        $window = 1;
        $pagesToShow = [];
        $pagesToShow[1] = true;
        $pagesToShow[$totalPages] = true;
        for ($i = $page - $window; $i <= $page + $window; $i++) {
            if ($i >= 1 && $i <= $totalPages) {
                $pagesToShow[$i] = true;
            }
        }
        $sortedPages = array_keys($pagesToShow);
        sort($sortedPages);

        $prev = 0;
        foreach ($sortedPages as $i) {
            if ($prev !== 0 && $i - $prev > 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            $params[$paramName] = $i;
            $active = $i === $page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . e(http_build_query($params)) . '">' . $i . '</a></li>';
            $prev = $i;
        }

        $nextDisabled = $page >= $totalPages ? ' disabled' : '';
        $params[$paramName] = min($totalPages, $page + 1);
        $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="?' . e(http_build_query($params)) . '">&raquo;</a></li>';

        $html .= '</ul></nav>';

        return $html;
    }
}

/* =====================================================================
 * FILE UPLOAD
 * ===================================================================== */

/**
 * Validates and stores a profile picture upload.
 * Returns the relative path (e.g. "uploads/users/user_5_169...png") on
 * success, or null on any validation failure.
 */
if (!function_exists('handle_profile_upload')) {
    function handle_profile_upload(array $file, int $userId): ?string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return null;
        }

        if ($file['size'] <= 0 || $file['size'] > MAX_IMAGE_SIZE) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime === false || !in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
            return null;
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };

        if ($extension === null) {
            return null;
        }

        $dir = BASE_PATH . '/' . UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $filename = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return null;
        }

        return UPLOAD_DIR . $filename;
    }
}

/* =====================================================================
 * JSON RESPONSES (AJAX endpoints)
 * ===================================================================== */

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

/* =====================================================================
 * ACTIVITY LOGGING
 * ===================================================================== */

/**
 * Logs an activity against the `activity_logs` table.
 * IMPORTANT: this matches the actual schema (log_id, user_id, activity,
 * created_at) — earlier code inserted non-existent `action`,
 * `ip_address`, `user_agent` columns and silently failed on every call.
 */
if (!function_exists('log_activity')) {
    function log_activity(int $userId, string $activity): void
    {
        try {
            $stmt = db()->prepare(
                'INSERT INTO activity_logs (user_id, activity, created_at) VALUES (?, ?, NOW())'
            );
            $stmt->execute([$userId, $activity]);
        } catch (Throwable $e) {
            // Never let logging failures break login/logout/CRUD flows.
            error_log('log_activity failed: ' . $e->getMessage());
        }
    }
}

/* =====================================================================
 * PHASE 2 — DASHBOARD / NOTIFICATION HELPERS
 *
 * These are read-only reporting queries against tables that already
 * exist in the schema (products, categories, suppliers,
 * stock_transactions). No CRUD, no writes, no schema changes — Phase 2
 * is dashboard-only. Every function is wrapped defensively so an empty
 * or not-yet-populated table (expected until Phase 4+) renders as a
 * clean zero/empty state instead of a fatal error.
 * ===================================================================== */

/**
 * Headline counts for the admin dashboard's stat cards.
 *
 * PHASE 6 RULE: every stock/expiry/transaction number here is computed
 * exclusively from `inventory_batches` / `stock_transactions` — never
 * from a column on `products` (there isn't one; see Phase 5). Only
 * total_products/categories/suppliers/users are simple table counts.
 */
if (!function_exists('inventory_overview_stats')) {
    /**
     * $branchId null = consolidated totals across every branch (admin,
     * "All Branches" view). Non-null = figures for that one branch
     * only (staff's own branch, or an admin's selected branch).
     * total_products/categories/suppliers/users are catalog-level
     * counts and are never branch-scoped — the catalog is shared.
     */
    function inventory_overview_stats(?int $branchId = null): array
    {
        $stats = [
            'total_products'     => 0,
            'total_categories'   => 0,
            'total_suppliers'    => 0,
            'total_users'        => 0,
            'total_stock_qty'    => 0,
            'low_stock'          => 0,
            'expiring_soon'      => 0,
            'expired'            => 0,
            'stock_in_today'     => 0,
            'stock_out_today'    => 0,
            'today_transactions' => 0,
        ];

        try {
            $pdo = db();
            $bp = $branchId !== null ? ' AND b.branch_id = ?' : '';
            $tp = $branchId !== null ? ' AND branch_id = ?' : '';
            $bParams = $branchId !== null ? [$branchId] : [];

            $stats['total_products']   = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL')->fetchColumn();
            $stats['total_categories'] = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL')->fetchColumn();
            $stats['total_suppliers']  = (int) $pdo->query('SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL')->fetchColumn();
            $stats['total_users']      = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(b.remaining_quantity),0)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0
                   AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $stats['total_stock_qty'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0
                   AND b.expiration_date IS NOT NULL
                   AND b.expiration_date >= CURDATE()
                   AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $stats['expiring_soon'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $stats['expired'] = (int) $stmt->fetchColumn();

            // Low stock = distinct (product, branch) pairs whose total
            // non-expired remaining stock is at or below the threshold.
            // Scoped to one branch: per-product totals within that
            // branch. All branches: per-product-per-branch totals, so
            // a product that's fine overall but low in one branch is
            // still counted.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM (
                    SELECT b.product_id, b.branch_id, SUM(b.remaining_quantity) AS total_qty
                    FROM inventory_batches b
                    JOIN products p ON p.product_id = b.product_id
                    WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                      AND p.deleted_at IS NULL' . $bp . '
                    GROUP BY b.product_id, b.branch_id
                    HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
                 ) low'
            );
            $stmt->execute($bParams);
            $stats['low_stock'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM stock_transactions WHERE type = 'Stock In' AND DATE(created_at) = CURDATE()" . $tp
            );
            $stmt->execute($bParams);
            $stats['stock_in_today'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM stock_transactions WHERE type = 'Stock Out' AND DATE(created_at) = CURDATE()" . $tp
            );
            $stmt->execute($bParams);
            $stats['stock_out_today'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_transactions WHERE DATE(created_at) = CURDATE()' . $tp
            );
            $stmt->execute($bParams);
            $stats['today_transactions'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('inventory_overview_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

/**
 * Three-bucket stock health breakdown (per PRODUCT), used by the
 * "Inventory Overview" doughnut chart. Sourced entirely from
 * inventory_batches. A product with any expired-but-unconsumed batch
 * counts as expired; otherwise its non-expired total decides
 * low-stock vs available.
 */
if (!function_exists('stock_status_breakdown')) {
    function stock_status_breakdown(?int $branchId = null): array
    {
        $out = ['available' => 0, 'low_stock' => 0, 'expired' => 0];
        $bp = $branchId !== null ? ' AND b.branch_id = ?' : '';
        $bParams = $branchId !== null ? [$branchId] : [];

        try {
            $pdo = db();

            $stmt = $pdo->prepare(
                'SELECT DISTINCT b.product_id, b.branch_id
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $expiredRows = $stmt->fetchAll();
            $expiredSet = [];
            foreach ($expiredRows as $row) {
                $expiredSet[$row['product_id'] . ':' . $row['branch_id']] = true;
            }
            $out['expired'] = count($expiredRows);

            $stmt = $pdo->prepare(
                'SELECT b.product_id, b.branch_id, SUM(b.remaining_quantity) AS total_qty
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                   AND p.deleted_at IS NULL' . $bp . '
                 GROUP BY b.product_id, b.branch_id'
            );
            $stmt->execute($bParams);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $key = $row['product_id'] . ':' . $row['branch_id'];
                if (isset($expiredSet[$key])) {
                    continue; // already counted as expired above
                }
                if ((int) $row['total_qty'] <= LOW_STOCK_THRESHOLD) {
                    $out['low_stock']++;
                } else {
                    $out['available']++;
                }
            }
        } catch (Throwable $e) {
            error_log('stock_status_breakdown failed: ' . $e->getMessage());
        }

        return $out;
    }
}

/**
 * Non-overlapping expiration bands (per BATCH, active/unconsumed only)
 * for the "Expiration Monitoring" panel. Sourced entirely from
 * inventory_batches.
 */
if (!function_exists('expiration_breakdown')) {
    function expiration_breakdown(?int $branchId = null): array
    {
        $out = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'notice' => 0];
        $bp = $branchId !== null ? ' AND b.branch_id = ?' : '';
        $bParams = $branchId !== null ? [$branchId] : [];

        try {
            $pdo = db();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $out['expired'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                   AND b.expiration_date >= CURDATE()
                   AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_CRITICAL_DAYS . ' DAY)
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $out['critical'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                   AND b.expiration_date > DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_CRITICAL_DAYS . ' DAY)
                   AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_WARNING_DAYS . ' DAY)
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $out['warning'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                   AND b.expiration_date > DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_WARNING_DAYS . ' DAY)
                   AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $out['notice'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('expiration_breakdown failed: ' . $e->getMessage());
        }

        return $out;
    }
}

/**
 * Product count per category, for the "Product Statistics" bar chart.
 * Returns ['labels' => [...], 'data' => [...]].
 */
if (!function_exists('products_per_category_chart')) {
    function products_per_category_chart(int $limit = 8): array
    {
        $labels = [];
        $data   = [];

        try {
            $stmt = db()->prepare(
                'SELECT c.category_name, COUNT(p.product_id) AS cnt
                 FROM categories c
                 LEFT JOIN products p ON p.category_id = c.category_id AND p.deleted_at IS NULL
                 WHERE c.deleted_at IS NULL
                 GROUP BY c.category_id, c.category_name
                 ORDER BY cnt DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                $labels[] = $row['category_name'];
                $data[] = (int) $row['cnt'];
            }
        } catch (Throwable $e) {
            error_log('products_per_category_chart failed: ' . $e->getMessage());
        }

        return ['labels' => $labels, 'data' => $data];
    }
}


/**
 * Builds the dashboard/notification-bell feed by combining several
 * live signals. As of Phase 5, `products.expiration_date` and
 * `products.quantity` no longer exist (moved to the Phase 6
 * Merges two sources, each dated:
 *   1. Persisted event notifications (notifications table) — created by
 *      stock_in()/stock_out()/adjust_inventory() when they happen.
 *      These support is_read tracking (see mark_notification_read()).
 *   2. Live, always-current alerts computed fresh from
 *      inventory_batches — expired batches, low-stock products,
 *      expiring-soon batches. These are recalculated on every call
 *      rather than persisted, since "is this still low stock" is a
 *      question about current state, not a one-time event.
 *
 * Each entry: ['type','icon','title','description','created_at']
 * `type` is one of: danger, warning, info, success (drives card color).
 */
if (!function_exists('dashboard_notifications')) {
    /**
     * $branchId null = system-wide feed (used only for admin "All
     * Branches"). Non-null = only alerts relevant to that one branch.
     * Persisted notifications (notifications table) have no branch_id
     * column — see BRANCH ARCHITECTURE note in the final summary: they
     * are treated as global/system events (e.g. "new user created")
     * and are only ever shown to admin's system-wide view, never
     * mixed into a staff member's branch-scoped feed.
     */
    function dashboard_notifications(int $limit = 8, ?int $branchId = null): array
    {
        $items = [];
        $bp = $branchId !== null ? ' AND b.branch_id = ?' : '';
        $bParams = $branchId !== null ? [$branchId] : [];

        try {
            $pdo = db();

            // ---- Persisted event notifications ----
            // Global/system-wide only: never shown in a branch-scoped
            // (staff) feed, since they carry no branch_id to verify.
            if ($branchId === null) {
                $persisted = $pdo->query(
                    'SELECT notification_id, title, message, type, is_read, created_at
                     FROM notifications ORDER BY created_at DESC LIMIT 15'
                )->fetchAll();
                foreach ($persisted as $n) {
                    $icon = match ($n['type']) {
                        'danger'  => 'bi-x-octagon-fill',
                        'warning' => 'bi-exclamation-triangle-fill',
                        'success' => 'bi-check-circle-fill',
                        default   => 'bi-info-circle-fill',
                    };
                    $items[] = [
                        'type' => $n['type'],
                        'icon' => $icon,
                        'title' => $n['title'],
                        'description' => $n['message'],
                        'created_at' => $n['created_at'],
                    ];
                }
            }

            // ---- Live: expired batches (still holding stock) ----
            $stmt = $pdo->prepare(
                "SELECT p.product_name, b.expiration_date, b.remaining_quantity, br.branch_name
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN branches br ON br.branch_id = b.branch_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()
                   AND p.deleted_at IS NULL" . $bp . "
                 ORDER BY b.expiration_date ASC LIMIT 5"
            );
            $stmt->execute($bParams);
            $expired = $stmt->fetchAll();
            foreach ($expired as $b) {
                $branchTag = $branchId === null && $b['branch_name'] ? ' (' . $b['branch_name'] . ')' : '';
                $items[] = [
                    'type' => 'danger',
                    'icon' => 'bi-x-octagon-fill',
                    'title' => 'Batch expired',
                    'description' => $b['product_name'] . $branchTag . ' — ' . (int) $b['remaining_quantity']
                        . ' unit(s) expired on ' . format_date($b['expiration_date'], 'M j, Y') . '.',
                    'created_at' => $b['expiration_date'],
                ];
            }

            // ---- Live: expiring soon ----
            $stmt = $pdo->prepare(
                'SELECT p.product_name, b.expiration_date, b.remaining_quantity, br.branch_name
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN branches br ON br.branch_id = b.branch_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL
                   AND b.expiration_date >= CURDATE()
                   AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_NOTICE_DAYS . ' DAY)
                   AND p.deleted_at IS NULL' . $bp . '
                 ORDER BY b.expiration_date ASC LIMIT 5'
            );
            $stmt->execute($bParams);
            $expiring = $stmt->fetchAll();
            foreach ($expiring as $b) {
                $branchTag = $branchId === null && $b['branch_name'] ? ' (' . $b['branch_name'] . ')' : '';
                $items[] = [
                    'type' => 'warning',
                    'icon' => 'bi-hourglass-split',
                    'title' => 'Expiring soon',
                    'description' => $b['product_name'] . $branchTag . ' — ' . (int) $b['remaining_quantity']
                        . ' unit(s) expire on ' . format_date($b['expiration_date'], 'M j, Y') . '.',
                    'created_at' => $b['expiration_date'],
                ];
            }

            // ---- Live: low stock products ----
            $lowStockSql = $branchId !== null
                ? 'SELECT p.product_name, SUM(b.remaining_quantity) AS total_qty
                   FROM inventory_batches b
                   JOIN products p ON p.product_id = b.product_id
                   WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                     AND p.deleted_at IS NULL AND b.branch_id = ?
                   GROUP BY b.product_id, p.product_name
                   HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
                   ORDER BY total_qty ASC LIMIT 5'
                : 'SELECT p.product_name, br.branch_name, SUM(b.remaining_quantity) AS total_qty
                   FROM inventory_batches b
                   JOIN products p ON p.product_id = b.product_id
                   LEFT JOIN branches br ON br.branch_id = b.branch_id
                   WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                     AND p.deleted_at IS NULL
                   GROUP BY b.product_id, b.branch_id, p.product_name, br.branch_name
                   HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
                   ORDER BY total_qty ASC LIMIT 5';
            $stmt = $pdo->prepare($lowStockSql);
            $stmt->execute($bParams);
            $lowStock = $stmt->fetchAll();
            foreach ($lowStock as $p) {
                $branchTag = $branchId === null && !empty($p['branch_name']) ? ' (' . $p['branch_name'] . ')' : '';
                $items[] = [
                    'type' => 'warning',
                    'icon' => 'bi-exclamation-triangle-fill',
                    'title' => 'Low stock',
                    'description' => $p['product_name'] . $branchTag . ' has ' . (int) $p['total_qty'] . ' unit(s) remaining.',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
        } catch (Throwable $e) {
            error_log('dashboard_notifications failed: ' . $e->getMessage());
        }

        // Danger first, then warning, then info/success — recency within each tier.
        $severity = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
        usort($items, static function (array $a, array $b) use ($severity): int {
            $sa = $severity[$a['type']] ?? 9;
            $sb = $severity[$b['type']] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
        });

        return array_slice($items, 0, $limit);
    }
}

/**
 * Count used for the notification bell badge in the top navbar:
 * unread persisted notifications + live expired-batch + low-stock
 * product counts.
 */
if (!function_exists('notifications_critical_count')) {
    function notifications_critical_count(?int $branchId = null): int
    {
        try {
            $pdo = db();
            $bp = $branchId !== null ? ' AND b.branch_id = ?' : '';
            $bParams = $branchId !== null ? [$branchId] : [];

            // Persisted notifications are global/system events (no
            // branch_id column) — only counted for admin's system-wide
            // bell, never mixed into a staff member's branch count.
            $unread = $branchId === null
                ? (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn()
                : 0;

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE b.remaining_quantity > 0 AND b.expiration_date IS NOT NULL AND b.expiration_date < CURDATE()
                   AND p.deleted_at IS NULL' . $bp
            );
            $stmt->execute($bParams);
            $expired = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM (
                    SELECT b.product_id, b.branch_id, SUM(b.remaining_quantity) AS total_qty
                    FROM inventory_batches b
                    JOIN products p ON p.product_id = b.product_id
                    WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                      AND p.deleted_at IS NULL' . $bp . '
                    GROUP BY b.product_id, b.branch_id
                    HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
                 ) low'
            );
            $stmt->execute($bParams);
            $lowStock = (int) $stmt->fetchColumn();

            return $unread + $expired + $lowStock;
        } catch (Throwable $e) {
            error_log('notifications_critical_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* =====================================================================
 * PHASE 3 — CATEGORIES MODULE
 * ===================================================================== */

/**
 * Stat-card counts for the categories list page. Excludes soft-deleted
 * rows except for `deleted`, which counts them specifically.
 */
if (!function_exists('category_stats')) {
    function category_stats(): array
    {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'deleted' => 0];

        try {
            $pdo = db();

            $stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL");
            $stats['total'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL AND status = 'active'");
            $stats['active'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL AND status = 'inactive'");
            $stats['inactive'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NOT NULL");
            $stats['deleted'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('category_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

/**
 * Fetch a single category by id. Excludes soft-deleted rows unless
 * $includeDeleted is true (used by the restore screen).
 */
if (!function_exists('fetch_category')) {
    function fetch_category(int $id, bool $includeDeleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM categories WHERE category_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

/**
 * Case-insensitive duplicate-name check, scoped to non-deleted rows —
 * a soft-deleted category's name can be reused.
 */
if (!function_exists('category_name_unique')) {
    function category_name_unique(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM categories WHERE LOWER(category_name) = LOWER(?) AND deleted_at IS NULL';
        $params = [$name];

        if ($excludeId !== null) {
            $sql .= ' AND category_id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

/**
 * Basic category name validation. Returns an array of error strings
 * (empty = valid).
 */
if (!function_exists('validate_category_name')) {
    function validate_category_name(string $name): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Category name is required.';
        } elseif (mb_strlen($name) < 2) {
            $errors[] = 'Category name must be at least 2 characters.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Category name must be 100 characters or fewer.';
        } elseif (!preg_match('/^[\p{L}0-9 &\-\/\.,()]+$/u', $name)) {
            $errors[] = 'Category name contains invalid characters.';
        }

        return $errors;
    }
}

/**
 * How many products currently reference this category. Read-only —
 * the products table exists in the schema already but has no CRUD
 * until Phase 4, so this safely returns 0 until then.
 */
if (!function_exists('category_product_count')) {
    function category_product_count(int $categoryId): int
    {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ? AND deleted_at IS NULL');
            $stmt->execute([$categoryId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('category_product_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* =====================================================================
 * BRANCHES MODULE (Multi-Branch Support)
 * Mirrors the categories/suppliers CRUD helper pattern above. Admin
 * only manages this table; every other module reads it through
 * branch_options()/fetch_branch() for display and validation.
 * ===================================================================== */

if (!function_exists('branch_stats')) {
    function branch_stats(): array
    {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'deleted' => 0];

        try {
            $pdo = db();

            $stats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL")->fetchColumn();
            $stats['active'] = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND status = 'active'")->fetchColumn();
            $stats['inactive'] = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND status = 'inactive'")->fetchColumn();
            $stats['deleted'] = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE deleted_at IS NOT NULL")->fetchColumn();
        } catch (Throwable $e) {
            error_log('branch_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

if (!function_exists('fetch_branch')) {
    function fetch_branch(int $id, bool $includeDeleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM branches WHERE branch_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('branch_code_unique')) {
    function branch_code_unique(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM branches WHERE LOWER(branch_code) = LOWER(?) AND deleted_at IS NULL';
        $params = [$code];

        if ($excludeId !== null) {
            $sql .= ' AND branch_id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

if (!function_exists('branch_name_unique')) {
    function branch_name_unique(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM branches WHERE LOWER(branch_name) = LOWER(?) AND deleted_at IS NULL';
        $params = [$name];

        if ($excludeId !== null) {
            $sql .= ' AND branch_id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

/**
 * Validates branch_code / branch_name formatting rules. Uniqueness is
 * checked separately (branch_code_unique()/branch_name_unique()) so
 * callers can give a specific "already exists" message.
 */
if (!function_exists('validate_branch')) {
    function validate_branch(string $code, string $name): array
    {
        $errors = [];

        if ($code === '') {
            $errors[] = 'Branch code is required.';
        } elseif (!preg_match('/^[A-Za-z0-9\-]{2,20}$/', $code)) {
            $errors[] = 'Branch code must be 2–20 characters: letters, numbers, and hyphens only.';
        }

        if ($name === '') {
            $errors[] = 'Branch name is required.';
        } elseif (mb_strlen($name) < 2) {
            $errors[] = 'Branch name must be at least 2 characters.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Branch name must be 150 characters or fewer.';
        }

        return $errors;
    }
}

/**
 * Dropdown option list of active branches. Used by user create/edit,
 * Stock In/Out branch pickers, transfer forms, and report filters.
 * $currentId (soft-deleted or inactive) is still included so an
 * existing assignment never silently disappears from an edit form.
 */
if (!function_exists('branch_options')) {
    function branch_options(?int $currentId = null): array
    {
        try {
            $sql = "SELECT * FROM branches WHERE (deleted_at IS NULL AND status = 'active')";
            $params = [];
            if ($currentId !== null) {
                $sql .= ' OR branch_id = ?';
                $params[] = $currentId;
            }
            $sql .= ' ORDER BY branch_name ASC';

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('branch_options failed: ' . $e->getMessage());
            return [];
        }
    }
}

/** How many users (any status, non-deleted) are assigned to this branch. */
if (!function_exists('branch_user_count')) {
    function branch_user_count(int $branchId): int
    {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE branch_id = ? AND deleted_at IS NULL');
            $stmt->execute([$branchId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('branch_user_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/** How many inventory_batches rows (any state) reference this branch. */
if (!function_exists('branch_batch_count')) {
    function branch_batch_count(int $branchId): int
    {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM inventory_batches WHERE branch_id = ?');
            $stmt->execute([$branchId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('branch_batch_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* =====================================================================
 * PHASE 4 — SUPPLIERS MODULE
 * ===================================================================== */

/**
 * Stat-card counts for the suppliers list page.
 */
if (!function_exists('supplier_stats')) {
    function supplier_stats(): array
    {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'deleted' => 0];

        try {
            $pdo = db();

            $stmt = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL");
            $stats['total'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL AND status = 'active'");
            $stats['active'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL AND status = 'inactive'");
            $stats['inactive'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NOT NULL");
            $stats['deleted'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('supplier_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

/**
 * Fetch a single supplier by id. Excludes soft-deleted rows unless
 * $includeDeleted is true (used by the restore screen).
 */
if (!function_exists('fetch_supplier')) {
    function fetch_supplier(int $id, bool $includeDeleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM suppliers WHERE supplier_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

/**
 * Case-insensitive duplicate-name check, scoped to non-deleted rows —
 * same convention as category_name_unique().
 */
if (!function_exists('supplier_name_unique')) {
    function supplier_name_unique(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) AND deleted_at IS NULL';
        $params = [$name];

        if ($excludeId !== null) {
            $sql .= ' AND supplier_id != ?';
            $params[] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}

/**
 * Validates contact-number format. Accepts digits, spaces, and the
 * symbols people actually use in phone numbers (+, -, (, )), 7-20
 * characters long. Optional field — empty is valid.
 */
if (!function_exists('validate_contact_number')) {
    function validate_contact_number(string $number): bool
    {
        if ($number === '') {
            return true;
        }

        return (bool) preg_match('/^[0-9+\-() ]{7,20}$/', $number);
    }
}

/**
 * Full supplier field validation for create/edit forms. Returns an
 * array of error strings (empty = valid).
 */
if (!function_exists('validate_supplier')) {
    function validate_supplier(string $name, string $email, string $contactNumber): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Supplier name is required.';
        } elseif (mb_strlen($name) < 2) {
            $errors[] = 'Supplier name must be at least 2 characters.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Supplier name must be 100 characters or fewer.';
        }

        if ($email !== '' && !validate_email($email)) {
            $errors[] = 'Email address is not valid.';
        }

        if (!validate_contact_number($contactNumber)) {
            $errors[] = 'Contact number format is invalid (digits, spaces, +, -, ( ) only, 7–20 characters).';
        }

        return $errors;
    }
}

/**
 * How many products currently reference this supplier.
 */
if (!function_exists('supplier_product_count')) {
    function supplier_product_count(int $supplierId): int
    {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ? AND deleted_at IS NULL');
            $stmt->execute([$supplierId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('supplier_product_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* =====================================================================
 * PHASE 5 — PRODUCTS MODULE
 *
 * Products is product-INFORMATION management only. `quantity` and
 * `expiration_date` deliberately do not exist on this table (see
 * database/5_phase5_products.sql) — those belong to the Phase 6
 * Inventory/Stock module, keyed off product_id. Nothing in this
 * section writes to a quantity or expiration column anywhere.
 * ===================================================================== */

/**
 * Stat-card counts for the products list page.
 */
if (!function_exists('product_stats')) {
    function product_stats(): array
    {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'deleted' => 0, 'no_barcode' => 0];

        try {
            $pdo = db();

            $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL");
            $stats['total'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status = 'active'");
            $stats['active'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status = 'inactive'");
            $stats['inactive'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NOT NULL");
            $stats['deleted'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND (barcode IS NULL OR barcode = '')");
            $stats['no_barcode'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('product_stats failed: ' . $e->getMessage());
        }

        return $stats;
    }
}

/**
 * Fetch a single product by id, with category/supplier names joined
 * in for display. Excludes soft-deleted rows unless $includeDeleted.
 */
if (!function_exists('fetch_product')) {
    function fetch_product(int $id, bool $includeDeleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql = 'SELECT p.*, c.category_name, s.supplier_name
                FROM products p
                LEFT JOIN categories c ON c.category_id = p.category_id
                LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
                WHERE p.product_id = ?';
        if (!$includeDeleted) {
            $sql .= ' AND p.deleted_at IS NULL';
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

/**
 * Look up a product by its barcode — the "scan an existing barcode and
 * the system recognizes the product" requirement. Includes soft-deleted
 * products by default so a duplicate-barcode check can tell the caller
 * "this belongs to a deleted product, restore it instead of creating a
 * new one" rather than just failing.
 */
if (!function_exists('fetch_product_by_barcode')) {
    function fetch_product_by_barcode(string $barcode, bool $includeDeleted = true): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $sql = 'SELECT p.*, c.category_name, s.supplier_name
                FROM products p
                LEFT JOIN categories c ON c.category_id = p.category_id
                LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
                WHERE p.barcode = ?';
        if (!$includeDeleted) {
            $sql .= ' AND p.deleted_at IS NULL';
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute([$barcode]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}


/**
 * Barcode format validation: letters, numbers, and hyphens, 3-100
 * characters (covers common EAN/UPC numeric codes as well as
 * alphanumeric internal SKUs).
 */
if (!function_exists('validate_barcode')) {
    function validate_barcode(string $barcode): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-]{3,100}$/', $barcode);
    }
}

/**
 * Full product field validation for create/edit forms. Returns an
 * array of error strings (empty = valid). Does not touch barcode
 * uniqueness or category/supplier existence — callers check those
 * separately since the error messaging differs (duplicate vs invalid).
 */
if (!function_exists('validate_product')) {
    function validate_product(
        string $name,
        string $barcode,
        string $unit,
        string $sellingPrice,
        int $categoryId,
        int $supplierId
    ): array {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Product name is required.';
        } elseif (mb_strlen($name) < 2) {
            $errors[] = 'Product name must be at least 2 characters.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Product name must be 150 characters or fewer.';
        }

        if ($barcode === '') {
            $errors[] = 'Barcode is required.';
        } elseif (!validate_barcode($barcode)) {
            $errors[] = 'Barcode may only contain letters, numbers, and hyphens (3–100 characters).';
        }

        if ($unit === '') {
            $errors[] = 'Unit is required (e.g. pcs, box, kg, sack, bottle).';
        } elseif (mb_strlen($unit) > 20) {
            $errors[] = 'Unit must be 20 characters or fewer.';
        }

        if ($sellingPrice === '' || !is_numeric($sellingPrice) || (float) $sellingPrice < 0) {
            $errors[] = 'Selling price must be a valid non-negative number.';
        } elseif ((float) $sellingPrice > 99999999.99) {
            $errors[] = 'Selling price is too large.';
        }

        if ($categoryId <= 0) {
            $errors[] = 'Please select a category.';
        }
        if ($supplierId <= 0) {
            $errors[] = 'Please select a supplier.';
        }

        return $errors;
    }
}

/**
 * Active (or currently-assigned) categories for a product form's
 * dropdown. Always includes $currentId even if it's since been
 * deactivated or deleted, so editing an older product never silently
 * drops its existing category from the list — it's just labeled.
 */
if (!function_exists('category_options')) {
    function category_options(?int $currentId = null): array
    {
        $options = [];

        try {
            $stmt = db()->query(
                "SELECT category_id, category_name, status, deleted_at FROM categories
                 WHERE deleted_at IS NULL AND status = 'active'
                 ORDER BY category_name ASC"
            );
            $options = $stmt->fetchAll();

            if ($currentId) {
                $already = array_filter($options, static fn($c) => (int) $c['category_id'] === $currentId);
                if (!$already) {
                    $cat = fetch_category($currentId, true);
                    if ($cat) {
                        $options[] = $cat;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('category_options failed: ' . $e->getMessage());
        }

        return $options;
    }
}

/**
 * Active (or currently-assigned) suppliers for a product form's
 * dropdown. Same "never silently drop the current selection" rule as
 * category_options() above.
 */
if (!function_exists('supplier_options')) {
    function supplier_options(?int $currentId = null): array
    {
        $options = [];

        try {
            $stmt = db()->query(
                "SELECT supplier_id, supplier_name, status, deleted_at FROM suppliers
                 WHERE deleted_at IS NULL AND status = 'active'
                 ORDER BY supplier_name ASC"
            );
            $options = $stmt->fetchAll();

            if ($currentId) {
                $already = array_filter($options, static fn($s) => (int) $s['supplier_id'] === $currentId);
                if (!$already) {
                    $sup = fetch_supplier($currentId, true);
                    if ($sup) {
                        $options[] = $sup;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('supplier_options failed: ' . $e->getMessage());
        }

        return $options;
    }
}

/**
 * Validates and stores a product image upload. Same validation rules
 * as handle_profile_upload() but stored under uploads/products/ and
 * named by product id rather than user id.
 */
if (!function_exists('handle_product_image_upload')) {
    function handle_product_image_upload(array $file, int $productId): ?string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        if ($file['size'] <= 0 || $file['size'] > MAX_IMAGE_SIZE) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime === false || !in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
            return null;
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };

        if ($extension === null) {
            return null;
        }

        $dir = BASE_PATH . '/' . PRODUCT_UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $filename = 'product_' . $productId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return null;
        }

        return PRODUCT_UPLOAD_DIR . $filename;
    }
}

/**
 * Resolves a product image to a displayable URL, or null if there is
 * none (the view is responsible for showing a placeholder icon box —
 * unlike avatar_url(), there's no sensible "generate one from the
 * name" fallback for a physical product photo).
 */
if (!function_exists('product_image_url')) {
    function product_image_url(?string $path): ?string
    {
        if (!empty($path) && is_file(BASE_PATH . '/' . ltrim($path, '/'))) {
            return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
        }

        return null;
    }
}

/* =====================================================================
 * PHASE 6 — INVENTORY MANAGEMENT MODULE
 *
 * FIFO, batches, stock in/out, adjustments, notifications, and every
 * history/timeline view. Rules enforced throughout this section:
 *   - Stock never goes negative (validated before any write).
 *   - FIFO is fully automatic — callers never choose a batch.
 *   - expiration_date / received_at / supplier_id are set once at
 *     batch creation and never updated again — only remaining_quantity
 *     changes, via stock_out() or adjust_inventory().
 *   - Every action writes BOTH a stock_transactions row AND an
 *     activity_logs row, inside one real DB transaction so a failure
 *     partway through leaves nothing half-written.
 *   - stock_out() and adjust_inventory() lock the batch row(s) with
 *     SELECT ... FOR UPDATE for the duration of the transaction, so
 *     two concurrent requests can't both deduct from the same stock.
 * ===================================================================== */

/* ---------------------------------------------------------------------
 * Notifications (persisted event log, separate from the live-computed
 * alerts in dashboard_notifications() above)
 * --------------------------------------------------------------------- */

if (!function_exists('create_notification')) {
    function create_notification(string $title, string $message, string $type = 'info'): void
    {
        if (!in_array($type, ['info', 'warning', 'danger', 'success'], true)) {
            $type = 'info';
        }

        try {
            db()->prepare('INSERT INTO notifications (title, message, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())')
                ->execute([$title, $message, $type]);
        } catch (Throwable $e) {
            error_log('create_notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('upsert_low_stock_notification')) {
    /**
     * Same "Low Stock" notification create_notification() would make,
     * but deduplicated per product while the condition is still open:
     * if an UNREAD "Low Stock" notification for this exact product
     * already exists, its message/timestamp is refreshed in place
     * instead of inserting another row. Once that notification is
     * marked read (or deleted), the next low-stock Stock Out is free
     * to create a fresh one — this only suppresses re-notifying about
     * a condition that's still open and already unread, not genuinely
     * new events.
     *
     * The notifications table has no product_id column (dropped by
     * design in Phase 6 — see 6_phase6_inventory.sql), so the product
     * name embedded at the start of the message is the matching key;
     * LIKE wildcard characters in the name are escaped so a product
     * literally named e.g. "50% Off Widget" can't affect the match.
     */
    function upsert_low_stock_notification(string $productName, string $message): void
    {
        try {
            $pdo = db();
            $escapedName = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $productName);

            $stmt = $pdo->prepare(
                "SELECT notification_id FROM notifications
                 WHERE type = 'warning' AND title = 'Low Stock' AND is_read = 0
                   AND message LIKE ? ESCAPE '\\\\'
                 LIMIT 1"
            );
            $stmt->execute([$escapedName . ' — remaining:%']);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $pdo->prepare('UPDATE notifications SET message = ?, created_at = NOW() WHERE notification_id = ?')
                    ->execute([$message, $existingId]);
            } else {
                create_notification('Low Stock', $message, 'warning');
            }
        } catch (Throwable $e) {
            error_log('upsert_low_stock_notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('recent_notifications')) {
    function recent_notifications(int $limit = 20, bool $unreadOnly = false): array
    {
        try {
            $sql = 'SELECT * FROM notifications';
            if ($unreadOnly) {
                $sql .= ' WHERE is_read = 0';
            }
            $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, $limit);
            return db()->query($sql)->fetchAll();
        } catch (Throwable $e) {
            error_log('recent_notifications failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(int $id): void
    {
        try {
            db()->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ?')->execute([$id]);
        } catch (Throwable $e) {
            error_log('mark_notification_read failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('mark_all_notifications_read')) {
    function mark_all_notifications_read(): void
    {
        try {
            db()->exec('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
        } catch (Throwable $e) {
            error_log('mark_all_notifications_read failed: ' . $e->getMessage());
        }
    }
}

/* ---------------------------------------------------------------------
 * Expiration status helper (shared badge logic across every screen)
 * --------------------------------------------------------------------- */

if (!function_exists('expiration_status')) {
    /**
     * @return array{status:string,label:string,class:string}
     */
    function expiration_status(?string $expirationDate): array
    {
        if (empty($expirationDate)) {
            return ['status' => 'none', 'label' => 'No Expiration', 'class' => 'secondary'];
        }

        $days = (int) floor((strtotime($expirationDate) - strtotime(date('Y-m-d'))) / 86400);

        if ($days < 0) {
            return ['status' => 'expired', 'label' => 'Expired', 'class' => 'danger'];
        }
        if ($days <= EXPIRING_CRITICAL_DAYS) {
            return ['status' => 'critical', 'label' => "Expires in {$days}d", 'class' => 'danger'];
        }
        if ($days <= EXPIRING_WARNING_DAYS) {
            return ['status' => 'warning', 'label' => "Expires in {$days}d", 'class' => 'warning'];
        }
        if ($days <= EXPIRING_NOTICE_DAYS) {
            return ['status' => 'notice', 'label' => "Expires in {$days}d", 'class' => 'info'];
        }

        return ['status' => 'ok', 'label' => 'OK', 'class' => 'success'];
    }
}

/* ---------------------------------------------------------------------
 * Product-level stock lookups
 * --------------------------------------------------------------------- */

/**
 * Current sellable stock for a product: sum of remaining_quantity
 * across batches that are not expired. Computed from
 * inventory_batches only, per the Phase 6 rule.
 */
if (!function_exists('product_stock_total')) {
    function product_stock_total(int $productId, ?int $branchId = null): int
    {
        try {
            $sql = 'SELECT COALESCE(SUM(remaining_quantity),0) FROM inventory_batches
                    WHERE product_id = ? AND (expiration_date IS NULL OR expiration_date >= CURDATE())';
            $params = [$productId];
            if ($branchId !== null) {
                $sql .= ' AND branch_id = ?';
                $params[] = $branchId;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('product_stock_total failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Per-branch stock breakdown for a single product — every active
 * branch, with that branch's own current stock (0 if none). Used on
 * the product view page so "same product, independent stock per
 * branch" is directly visible, per the multi-branch spec's example.
 */
if (!function_exists('product_stock_by_branch')) {
    function product_stock_by_branch(int $productId): array
    {
        try {
            $stmt = db()->prepare(
                "SELECT br.branch_id, br.branch_name, br.branch_code,
                        COALESCE(SUM(CASE WHEN b.remaining_quantity > 0
                                          AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                                     THEN b.remaining_quantity ELSE 0 END), 0) AS current_stock
                 FROM branches br
                 LEFT JOIN inventory_batches b ON b.branch_id = br.branch_id AND b.product_id = ?
                 WHERE br.deleted_at IS NULL AND br.status = 'active'
                 GROUP BY br.branch_id, br.branch_name, br.branch_code
                 ORDER BY br.branch_name ASC"
            );
            $stmt->execute([$productId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('product_stock_by_branch failed: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * All batches for a product (any state), most recently received first.
 * Used for batch monitoring / product inventory timeline displays.
 */
if (!function_exists('product_batches')) {
    function product_batches(int $productId, bool $activeOnly = false, ?int $branchId = null): array
    {
        try {
            $sql = 'SELECT b.*, s.supplier_name, br.branch_name, br.branch_code
                    FROM inventory_batches b
                    LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
                    LEFT JOIN branches br ON br.branch_id = b.branch_id
                    WHERE b.product_id = ?';
            $params = [$productId];
            if ($activeOnly) {
                $sql .= ' AND b.remaining_quantity > 0';
            }
            if ($branchId !== null) {
                $sql .= ' AND b.branch_id = ?';
                $params[] = $branchId;
            }
            $sql .= ' ORDER BY b.received_at DESC, b.batch_id DESC';

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('product_batches failed: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * The FIFO-ordered queue of consumable batches for a product: oldest
 * expiration first, then oldest received as a tiebreaker (batches with
 * no expiration date sort last, since a dated batch should always be
 * cleared before an undated one under FIFO). Locks the rows
 * (FOR UPDATE) — must be called inside an open transaction.
 */
if (!function_exists('fifo_queue_for_product')) {
    function fifo_queue_for_product(int $productId, int $branchId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $stmt = $pdo->prepare(
            'SELECT * FROM inventory_batches
             WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0
               AND (expiration_date IS NULL OR expiration_date >= CURDATE())
             ORDER BY expiration_date ASC, received_at ASC, batch_id ASC
             FOR UPDATE'
        );
        $stmt->execute([$productId, $branchId]);
        return $stmt->fetchAll();
    }
}

/**
 * Read-only FIFO preview for the Stock Out form: shows which batches
 * WOULD be consumed for a given quantity, without locking or writing
 * anything. Purely informational — the real consumption happens in
 * stock_out() under a row lock.
 */
if (!function_exists('fifo_preview')) {
    function fifo_preview(int $productId, int $branchId, int $quantity): array
    {
        $preview = [];
        try {
            $stmt = db()->prepare(
                'SELECT * FROM inventory_batches
                 WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0
                   AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                 ORDER BY expiration_date ASC, received_at ASC, batch_id ASC'
            );
            $stmt->execute([$productId, $branchId]);
            $needed = $quantity;

            foreach ($stmt->fetchAll() as $batch) {
                if ($needed <= 0) {
                    break;
                }
                $take = min($needed, (int) $batch['remaining_quantity']);
                $preview[] = [
                    'batch_id' => (int) $batch['batch_id'],
                    'take' => $take,
                    'remaining_before' => (int) $batch['remaining_quantity'],
                    'expiration_date' => $batch['expiration_date'],
                ];
                $needed -= $take;
            }
        } catch (Throwable $e) {
            error_log('fifo_preview failed: ' . $e->getMessage());
        }

        return $preview;
    }
}

/* ---------------------------------------------------------------------
 * Stock In / Stock Out / Adjustment — the atomic, audited write paths
 * --------------------------------------------------------------------- */

/**
 * Receives new stock: creates one inventory_batches row and one
 * stock_transactions row, inside a single DB transaction.
 *
 * @return array{success:bool,error?:string,batch_id?:int}
 */
if (!function_exists('stock_in')) {
    function stock_in(
        int $productId,
        int $branchId,
        ?int $supplierId,
        int $quantity,
        ?string $expirationDate,
        ?string $remarks,
        int $userId
    ): array {
        if ($quantity <= 0) {
            return ['success' => false, 'error' => 'Quantity must be greater than zero.'];
        }

        if ($branchId <= 0) {
            return ['success' => false, 'error' => 'A valid branch is required.'];
        }

        $branch = fetch_branch($branchId);
        if (!$branch || $branch['status'] !== 'active') {
            return ['success' => false, 'error' => 'Selected branch is not available.'];
        }

        $product = fetch_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        if ($expirationDate !== null && $expirationDate !== '' && !strtotime($expirationDate)) {
            return ['success' => false, 'error' => 'Invalid expiration date.'];
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_batches
                    (product_id, branch_id, supplier_id, quantity, remaining_quantity, expiration_date, received_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $stmt->execute([
                $productId,
                $branchId,
                $supplierId ?: null,
                $quantity,
                $quantity,
                $expirationDate !== '' ? $expirationDate : null,
                $userId,
            ]);
            $batchId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO stock_transactions (product_id, branch_id, batch_id, type, quantity, remarks, created_by, created_at)
                 VALUES (?, ?, ?, 'Stock In', ?, ?, ?, NOW())"
            )->execute([$productId, $branchId, $batchId, $quantity, $remarks !== '' ? $remarks : null, $userId]);

            $expText = $expirationDate ? (' — Exp: ' . $expirationDate) : '';
            log_activity($userId, "Added Stock '{$product['product_name']}' +{$quantity} {$product['unit']} (Batch #{$batchId}) at {$branch['branch_name']}{$expText}");

            create_notification(
                'Stock Added',
                $quantity . ' ' . $product['unit'] . ' added — ' . $product['product_name'] . ' (' . $branch['branch_name'] . ')',
                'info'
            );

            $pdo->commit();

            return ['success' => true, 'batch_id' => $batchId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('stock_in failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Stock In failed. Please try again.'];
        }
    }
}

/**
 * Issues stock out using FIFO, fully automatic — no batch is ever
 * chosen by the caller. Locks every candidate batch row for the
 * product (SELECT ... FOR UPDATE) before consuming, so two concurrent
 * stock-outs can't both draw from the same units. Never allows the
 * total to go negative: validates full availability before writing
 * anything.
 *
 * @return array{success:bool,error?:string,available?:int,requested?:int,batches?:array}
 */
if (!function_exists('stock_out')) {
    function stock_out(int $productId, int $branchId, int $quantity, ?string $remarks, int $userId): array
    {
        if ($quantity <= 0) {
            return ['success' => false, 'error' => 'Quantity must be greater than zero.'];
        }

        if ($branchId <= 0) {
            return ['success' => false, 'error' => 'A valid branch is required.'];
        }

        // Same guard stock_in() and stock_transfer() apply: an
        // inactive/deleted branch can still be viewed (historical
        // data stays intact) but must never receive new inventory
        // activity. Existing stock isn't touched — this only blocks
        // new Stock Out from being recorded against it.
        $branch = fetch_branch($branchId, true);
        if (!$branch || $branch['deleted_at'] || $branch['status'] !== 'active') {
            return ['success' => false, 'error' => 'This branch is not active. Stock Out is unavailable.'];
        }

        $product = fetch_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock every usable (non-expired) batch row for this
            // product AND THIS BRANCH ONLY for the duration of this
            // transaction (concurrency safety). Expired batches are
            // excluded entirely — Stock Out must never consume expired
            // inventory. Batches belonging to any other branch are
            // never even selected, so cross-branch consumption is
            // structurally impossible here, not just filtered out.
            $batches = fifo_queue_for_product($productId, $branchId, $pdo);

            $available = array_sum(array_map(static fn($b) => (int) $b['remaining_quantity'], $batches));

            if ($available < $quantity) {
                // Distinguish "nothing usable because it's all expired"
                // from a plain "not enough stock" — check whether any
                // expired-but-unconsumed stock exists for this product
                // IN THIS BRANCH.
                $expiredCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM inventory_batches
                     WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0
                       AND expiration_date IS NOT NULL AND expiration_date < CURDATE()'
                );
                $expiredCheck->execute([$productId, $branchId]);
                $hasExpiredStock = (int) $expiredCheck->fetchColumn() > 0;

                $pdo->rollBack();

                if ($available === 0 && $hasExpiredStock) {
                    return [
                        'success' => false,
                        'error' => 'no_usable_stock',
                        'available' => 0,
                        'requested' => $quantity,
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'insufficient_stock',
                    'available' => $available,
                    'requested' => $quantity,
                ];
            }

            $needed = $quantity;
            $consumed = [];

            foreach ($batches as $batch) {
                if ($needed <= 0) {
                    break;
                }

                $take = min($needed, (int) $batch['remaining_quantity']);
                $newRemaining = (int) $batch['remaining_quantity'] - $take;

                $pdo->prepare('UPDATE inventory_batches SET remaining_quantity = ? WHERE batch_id = ? AND branch_id = ?')
                    ->execute([$newRemaining, $batch['batch_id'], $branchId]);

                $pdo->prepare(
                    "INSERT INTO stock_transactions (product_id, branch_id, batch_id, type, quantity, remarks, created_by, created_at)
                     VALUES (?, ?, ?, 'Stock Out', ?, ?, ?, NOW())"
                )->execute([$productId, $branchId, $batch['batch_id'], $take, $remarks !== '' ? $remarks : null, $userId]);

                $consumed[] = [
                    'batch_id' => (int) $batch['batch_id'],
                    'taken' => $take,
                    'remaining_after' => $newRemaining,
                    'expiration_date' => $batch['expiration_date'],
                ];

                $needed -= $take;
            }

            $branch = fetch_branch($branchId, true);
            $branchName = $branch ? $branch['branch_name'] : "Branch #{$branchId}";

            $batchNote = count($consumed) > 1
                ? ' (FIFO across ' . count($consumed) . ' batches)'
                : ' (FIFO)';
            log_activity($userId, "Stock Out '{$product['product_name']}' -{$quantity} {$product['unit']}{$batchNote} at {$branchName}");

            // Low-stock check on the resulting total FOR THIS BRANCH
            // (still inside the transaction's consistent view of the
            // data).
            $stmtRemain = $pdo->prepare(
                'SELECT COALESCE(SUM(remaining_quantity),0) FROM inventory_batches
                 WHERE product_id = ? AND branch_id = ? AND (expiration_date IS NULL OR expiration_date >= CURDATE())'
            );
            $stmtRemain->execute([$productId, $branchId]);
            $remainingTotal = (int) $stmtRemain->fetchColumn();

            if ($remainingTotal <= LOW_STOCK_THRESHOLD) {
                upsert_low_stock_notification(
                    $product['product_name'] . ' @ ' . $branchName,
                    $product['product_name'] . ' — remaining: ' . $remainingTotal . ' ' . $product['unit'] . ' (' . $branchName . ')'
                );
            }

            $pdo->commit();

            return ['success' => true, 'batches' => $consumed];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('stock_out failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Stock Out failed. Please try again.'];
        }
    }
}

/**
 * Manual inventory correction against a single batch. Remarks are
 * ALWAYS required regardless of reason. Cannot push remaining_quantity
 * below 0 or above the batch's original received quantity. Only
 * remaining_quantity is ever touched — expiration_date, received_at,
 * and supplier_id are immutable once a batch is created.
 *
 * @return array{success:bool,error?:string}
 */
/**
 * Moves stock from one branch to another for a single product,
 * atomically: deducts from the source branch's FIFO batches, creates
 * a NEW batch in the destination branch (received_at = now, no
 * expiration carried over from the source — the destination branch
 * receives it as fresh stock, consistent with how Stock In always
 * creates a new batch), and logs both a 'Transfer Out' and a
 * 'Transfer In' stock_transactions row plus one stock_transfers
 * record linking them. Either both branches' stock changes and all
 * three rows are written, or nothing is — same FOR UPDATE + ROLLBACK
 * discipline as stock_out().
 */
if (!function_exists('stock_transfer')) {
    function stock_transfer(int $productId, int $fromBranchId, int $toBranchId, int $quantity, ?string $remarks, int $userId): array
    {
        if ($quantity <= 0) {
            return ['success' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        if ($fromBranchId <= 0 || $toBranchId <= 0) {
            return ['success' => false, 'error' => 'Both a source and destination branch are required.'];
        }
        if ($fromBranchId === $toBranchId) {
            return ['success' => false, 'error' => 'Source and destination branch must be different.'];
        }

        $fromBranch = fetch_branch($fromBranchId, true);
        $toBranch = fetch_branch($toBranchId, true);
        if (!$fromBranch || $fromBranch['deleted_at'] || $fromBranch['status'] !== 'active') {
            return ['success' => false, 'error' => 'Source branch is not available.'];
        }
        if (!$toBranch || $toBranch['deleted_at'] || $toBranch['status'] !== 'active') {
            return ['success' => false, 'error' => 'Destination branch is not available.'];
        }

        $product = fetch_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found.'];
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            // ---- Deduct from source (identical FIFO discipline to
            // stock_out(), scoped to the source branch only) ----
            $batches = fifo_queue_for_product($productId, $fromBranchId, $pdo);
            $available = array_sum(array_map(static fn($b) => (int) $b['remaining_quantity'], $batches));

            if ($available < $quantity) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error' => 'insufficient_stock',
                    'available' => $available,
                    'requested' => $quantity,
                ];
            }

            // Create the transfer record first so both transaction rows
            // can reference its id.
            $pdo->prepare(
                'INSERT INTO stock_transfers (product_id, from_branch_id, to_branch_id, quantity, remarks, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$productId, $fromBranchId, $toBranchId, $quantity, $remarks !== '' ? $remarks : null, $userId]);
            $transferId = (int) $pdo->lastInsertId();

            $needed = $quantity;
            foreach ($batches as $batch) {
                if ($needed <= 0) {
                    break;
                }
                $take = min($needed, (int) $batch['remaining_quantity']);
                $newRemaining = (int) $batch['remaining_quantity'] - $take;

                $pdo->prepare('UPDATE inventory_batches SET remaining_quantity = ? WHERE batch_id = ? AND branch_id = ?')
                    ->execute([$newRemaining, $batch['batch_id'], $fromBranchId]);

                $pdo->prepare(
                    "INSERT INTO stock_transactions (product_id, branch_id, batch_id, transfer_id, type, quantity, remarks, created_by, created_at)
                     VALUES (?, ?, ?, ?, 'Transfer Out', ?, ?, ?, NOW())"
                )->execute([$productId, $fromBranchId, $batch['batch_id'], $transferId, $take, $remarks !== '' ? $remarks : null, $userId]);

                $needed -= $take;
            }

            // ---- Receive into destination as a brand-new batch,
            // exactly like a Stock In would (no expiration carried
            // over — see docblock). This keeps the destination
            // branch's FIFO queue simple and honest: it never inherits
            // an expiration date it didn't itself receive stock under. ----
            $pdo->prepare(
                'INSERT INTO inventory_batches
                    (product_id, branch_id, supplier_id, quantity, remaining_quantity, expiration_date, received_at, created_by)
                 VALUES (?, ?, NULL, ?, ?, NULL, NOW(), ?)'
            )->execute([$productId, $toBranchId, $quantity, $quantity, $userId]);
            $newBatchId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO stock_transactions (product_id, branch_id, batch_id, transfer_id, type, quantity, remarks, created_by, created_at)
                 VALUES (?, ?, ?, ?, 'Transfer In', ?, ?, ?, NOW())"
            )->execute([$productId, $toBranchId, $newBatchId, $transferId, $quantity, $remarks !== '' ? $remarks : null, $userId]);

            log_activity(
                $userId,
                "Transferred Stock '{$product['product_name']}' {$quantity} {$product['unit']} from {$fromBranch['branch_name']} to {$toBranch['branch_name']} (Transfer #{$transferId})"
            );

            create_notification(
                'Stock Transferred',
                $quantity . ' ' . $product['unit'] . ' — ' . $product['product_name'] . ' moved from ' . $fromBranch['branch_name'] . ' to ' . $toBranch['branch_name'],
                'info'
            );

            $pdo->commit();

            return ['success' => true, 'transfer_id' => $transferId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('stock_transfer failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Transfer failed. Please try again.'];
        }
    }
}

/** Paginated transfer history, newest first. $branchId scopes to
 * transfers where that branch is either the source OR destination —
 * this is what staff should see (their branch's inbound + outbound
 * transfers), and what an admin sees when a branch filter is applied. */
if (!function_exists('transfer_history')) {
    function transfer_history(?int $branchId = null, int $limit = 50, int $offset = 0): array
    {
        try {
            $sql = "SELECT st.*, p.product_name, p.unit,
                           fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name,
                           u.fullname, u.username
                    FROM stock_transfers st
                    JOIN products p ON p.product_id = st.product_id
                    LEFT JOIN branches fb ON fb.branch_id = st.from_branch_id
                    LEFT JOIN branches tb ON tb.branch_id = st.to_branch_id
                    LEFT JOIN users u ON u.id = st.created_by";
            $params = [];
            if ($branchId !== null) {
                $sql .= ' WHERE st.from_branch_id = ? OR st.to_branch_id = ?';
                $params = [$branchId, $branchId];
            }
            $sql .= ' ORDER BY st.created_at DESC, st.transfer_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('transfer_history failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('transfer_history_count')) {
    function transfer_history_count(?int $branchId = null): int
    {
        try {
            $sql = 'SELECT COUNT(*) FROM stock_transfers';
            $params = [];
            if ($branchId !== null) {
                $sql .= ' WHERE from_branch_id = ? OR to_branch_id = ?';
                $params = [$branchId, $branchId];
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('transfer_history_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('adjust_inventory')) {
    function adjust_inventory(int $batchId, int $newRemainingQuantity, string $reason, string $remarks, int $userId): array
    {
        $remarks = trim($remarks);
        if ($remarks === '') {
            return ['success' => false, 'error' => 'Remarks are required for every inventory adjustment.'];
        }
        if (!in_array($reason, ADJUSTMENT_REASONS, true)) {
            return ['success' => false, 'error' => 'Please select a valid adjustment reason.'];
        }
        if ($newRemainingQuantity < 0) {
            return ['success' => false, 'error' => 'Remaining quantity cannot be negative.'];
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT b.*, p.product_name, p.unit, br.branch_name
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN branches br ON br.branch_id = b.branch_id
                 WHERE b.batch_id = ? FOR UPDATE'
            );
            $stmt->execute([$batchId]);
            $batch = $stmt->fetch();

            if (!$batch) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Batch not found.'];
            }

            if ($newRemainingQuantity > (int) $batch['quantity']) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Remaining quantity cannot exceed the batch's original received quantity (" . (int) $batch['quantity'] . ').'];
            }

            $delta = $newRemainingQuantity - (int) $batch['remaining_quantity'];

            if ($delta === 0) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'New quantity is the same as the current quantity — nothing to adjust.'];
            }

            $pdo->prepare('UPDATE inventory_batches SET remaining_quantity = ? WHERE batch_id = ?')
                ->execute([$newRemainingQuantity, $batchId]);

            // branch_id is never taken from a parameter here — it is
            // always the batch's own branch, since a batch can never
            // move branches (only a stock transfer creates a new batch
            // in the destination branch). This keeps the transaction
            // log's branch_id trustworthy no matter who calls this.
            $fullRemarks = $reason . ': ' . $remarks;
            $pdo->prepare(
                "INSERT INTO stock_transactions (product_id, branch_id, batch_id, type, quantity, remarks, created_by, created_at)
                 VALUES (?, ?, ?, 'Adjustment', ?, ?, ?, NOW())"
            )->execute([$batch['product_id'], $batch['branch_id'], $batchId, $delta, $fullRemarks, $userId]);

            $sign = $delta > 0 ? '+' : '';
            $branchName = $batch['branch_name'] ?: ('Branch #' . $batch['branch_id']);
            log_activity(
                $userId,
                "Inventory Adjustment '{$batch['product_name']}' Batch #{$batchId} {$sign}{$delta} {$batch['unit']} at {$branchName} — Reason: {$reason}"
            );

            $pdo->commit();

            return ['success' => true];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('adjust_inventory failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Adjustment failed. Please try again.'];
        }
    }
}

/* ---------------------------------------------------------------------
 * Barcode scanning (Stock In / Stock Out entry point)
 * --------------------------------------------------------------------- */

/**
 * Looks up a product by barcode for the Stock In/Out scan workflow.
 * Only matches active (non-deleted) products — you can't move stock
 * for a product that's been removed from the catalog.
 */
if (!function_exists('scan_barcode_lookup')) {
    /**
     * The barcode identifies a PRODUCT (global), never a branch. The
     * caller supplies which branch's stock figure to attach — staff
     * pages always pass their own session branch_id, admin pages pass
     * whichever branch is currently selected. If no branch is given at
     * all, current_stock reports the product's total across every
     * branch (used only in branch-agnostic/global contexts).
     */
    function scan_barcode_lookup(string $barcode, ?int $branchId = null): ?array
    {
        $product = fetch_product_by_barcode($barcode, false);
        if (!$product) {
            return null;
        }

        $product['current_stock'] = product_stock_total((int) $product['product_id'], $branchId);
        return $product;
    }
}

/* ---------------------------------------------------------------------
 * History / timeline queries (product page, supplier page, dedicated
 * Transaction History screen all share this one function)
 * --------------------------------------------------------------------- */

/**
 * Unified transaction history query used by the Product History
 * timeline, Supplier History, and the standalone Transaction History
 * page. One parameterized function instead of near-duplicate queries
 * per screen.
 *
 * @param array{product_id?:int,supplier_id?:int,user_id?:int,type?:string,barcode?:string,search?:string,date_from?:string,date_to?:string} $filters
 */
if (!function_exists('transaction_history')) {
    function transaction_history(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['product_id'])) {
            $where[] = 't.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 't.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'b.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 't.created_by = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['type']) && in_array($filters['type'], ['Stock In', 'Stock Out', 'Adjustment', 'Transfer Out', 'Transfer In'], true)) {
            $where[] = 't.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['barcode'])) {
            $where[] = 'p.barcode LIKE ?';
            $params[] = '%' . $filters['barcode'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.product_name LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(t.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(t.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT t.*, p.product_name, p.barcode, p.unit, b.expiration_date, b.remaining_quantity AS batch_remaining,
                       s.supplier_name, u.fullname, u.username, br.branch_name, br.branch_code
                FROM stock_transactions t
                JOIN products p ON p.product_id = t.product_id
                LEFT JOIN inventory_batches b ON b.batch_id = t.batch_id
                LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
                LEFT JOIN users u ON u.id = t.created_by
                LEFT JOIN branches br ON br.branch_id = t.branch_id
                WHERE {$whereSql}
                ORDER BY t.created_at DESC, t.transaction_id DESC
                LIMIT {$limit} OFFSET {$offset}";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('transaction_history failed: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Row count for transaction_history()'s filters, used for pagination.
 */
if (!function_exists('transaction_history_count')) {
    function transaction_history_count(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['product_id'])) {
            $where[] = 't.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 't.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'b.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 't.created_by = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['type']) && in_array($filters['type'], ['Stock In', 'Stock Out', 'Adjustment', 'Transfer Out', 'Transfer In'], true)) {
            $where[] = 't.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['barcode'])) {
            $where[] = 'p.barcode LIKE ?';
            $params[] = '%' . $filters['barcode'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.product_name LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(t.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(t.created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM stock_transactions t
                JOIN products p ON p.product_id = t.product_id
                LEFT JOIN inventory_batches b ON b.batch_id = t.batch_id
                WHERE {$whereSql}";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('transaction_history_count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Batches received from a given supplier — "Supplier History" section
 * on the supplier detail page.
 */
if (!function_exists('supplier_history')) {
    function supplier_history(int $supplierId, int $limit = 50): array
    {
        try {
            $stmt = db()->prepare(
                'SELECT b.*, p.product_name, p.barcode, p.unit, u.fullname AS received_by,
                        t.remarks
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN users u ON u.id = b.created_by
                 LEFT JOIN stock_transactions t ON t.batch_id = b.batch_id AND t.type = \'Stock In\'
                 WHERE b.supplier_id = ?
                 ORDER BY b.received_at DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('supplier_history failed: ' . $e->getMessage());
            return [];
        }
    }
}

/* ---------------------------------------------------------------------
 * Chart data (admin dashboard)
 * --------------------------------------------------------------------- */

/**
 * Stock In vs Stock Out quantities per day for the last $days days —
 * "Stock In Analytics" / "Stock Out Analytics" charts.
 */
if (!function_exists('stock_in_out_chart')) {
    function stock_in_out_chart(int $days = 7, ?int $branchId = null): array
    {
        $labels = [];
        $inData = [];
        $outData = [];

        try {
            $pdo = db();
            $inBuckets = [];
            $outBuckets = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $key = date('Y-m-d', strtotime("-{$i} days"));
                $inBuckets[$key] = 0;
                $outBuckets[$key] = 0;
                $labels[] = date('M j', strtotime("-{$i} days"));
            }

            $branchSql = $branchId !== null ? ' AND branch_id = ?' : '';
            $stmt = $pdo->prepare(
                "SELECT DATE(created_at) AS d, type, SUM(quantity) AS qty
                 FROM stock_transactions
                 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND type IN ('Stock In','Stock Out')" . $branchSql . "
                 GROUP BY d, type"
            );
            $stmt->bindValue(1, $days - 1, PDO::PARAM_INT);
            if ($branchId !== null) {
                $stmt->bindValue(2, $branchId, PDO::PARAM_INT);
            }
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                $d = $row['d'];
                if ($row['type'] === 'Stock In' && array_key_exists($d, $inBuckets)) {
                    $inBuckets[$d] = (int) $row['qty'];
                } elseif ($row['type'] === 'Stock Out' && array_key_exists($d, $outBuckets)) {
                    $outBuckets[$d] = abs((int) $row['qty']);
                }
            }

            $inData = array_values($inBuckets);
            $outData = array_values($outBuckets);
        } catch (Throwable $e) {
            error_log('stock_in_out_chart failed: ' . $e->getMessage());
        }

        return ['labels' => $labels, 'stock_in' => $inData, 'stock_out' => $outData];
    }
}

/**
 * Transaction count per month for the last $months months —
 * "Monthly Transactions" chart. Zero-filled, contiguous range.
 */
if (!function_exists('monthly_transactions_chart')) {
    function monthly_transactions_chart(int $months = 6, ?int $branchId = null): array
    {
        $labels = [];
        $counts = [];

        try {
            $pdo = db();
            $buckets = [];

            for ($i = $months - 1; $i >= 0; $i--) {
                $key = date('Y-m', strtotime("-{$i} months"));
                $buckets[$key] = 0;
                $labels[] = date('M Y', strtotime("-{$i} months"));
            }

            $branchSql = $branchId !== null ? ' AND branch_id = ?' : '';
            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
                 FROM stock_transactions
                 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)" . $branchSql . "
                 GROUP BY ym"
            );
            $stmt->bindValue(1, $months, PDO::PARAM_INT);
            if ($branchId !== null) {
                $stmt->bindValue(2, $branchId, PDO::PARAM_INT);
            }
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                if (array_key_exists($row['ym'], $buckets)) {
                    $buckets[$row['ym']] = (int) $row['cnt'];
                }
            }

            $counts = array_values($buckets);
        } catch (Throwable $e) {
            error_log('monthly_transactions_chart failed: ' . $e->getMessage());
        }

        return ['labels' => $labels, 'data' => $counts];
    }
}

/**
 * Top N lowest-stock products (non-expired remaining total), for the
 * "Low Stocks" chart.
 */
if (!function_exists('low_stock_chart_data')) {
    function low_stock_chart_data(int $limit = 8, ?int $branchId = null): array
    {
        $labels = [];
        $data = [];

        try {
            $branchSql = $branchId !== null ? ' AND b.branch_id = ?' : '';
            $stmt = db()->prepare(
                'SELECT p.product_name, SUM(b.remaining_quantity) AS total_qty
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 WHERE (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())' . $branchSql . '
                 GROUP BY b.product_id, p.product_name
                 HAVING total_qty <= ' . LOW_STOCK_THRESHOLD . '
                 ORDER BY total_qty ASC
                 LIMIT ?'
            );
            $paramIdx = 1;
            if ($branchId !== null) {
                $stmt->bindValue($paramIdx++, $branchId, PDO::PARAM_INT);
            }
            $stmt->bindValue($paramIdx, $limit, PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                $labels[] = $row['product_name'];
                $data[] = (int) $row['total_qty'];
            }
        } catch (Throwable $e) {
            error_log('low_stock_chart_data failed: ' . $e->getMessage());
        }

        return ['labels' => $labels, 'data' => $data];
    }
}

/* ---------------------------------------------------------------------
 * Naming-compliance aliases + combined history helpers.
 * These deliberately delegate to the functions above rather than
 * reimplementing anything, per "no duplicate logic".
 * --------------------------------------------------------------------- */

if (!function_exists('inventory_dashboard_stats')) {
    function inventory_dashboard_stats(?int $branchId = null): array
    {
        return inventory_overview_stats($branchId);
    }
}

if (!function_exists('stock_transaction_chart')) {
    function stock_transaction_chart(int $days = 7, ?int $branchId = null): array
    {
        return stock_in_out_chart($days, $branchId);
    }
}

if (!function_exists('low_stock_chart')) {
    function low_stock_chart(int $limit = 8, ?int $branchId = null): array
    {
        return low_stock_chart_data($limit, $branchId);
    }
}

/**
 * Expiration bands formatted for a Chart.js bar/doughnut ("Expiration
 * Status" chart) — same underlying data as expiration_breakdown(),
 * just label/data-array shaped.
 */
if (!function_exists('expiration_chart')) {
    function expiration_chart(?int $branchId = null): array
    {
        $bands = expiration_breakdown($branchId);

        return [
            'labels' => ['Expired', 'Critical (≤7d)', 'Warning (≤15d)', 'Notice (≤30d)'],
            'data'   => [$bands['expired'], $bands['critical'], $bands['warning'], $bands['notice']],
        ];
    }
}

/**
 * Whole-inventory transaction feed (no product/supplier/user filter
 * pre-applied) — the data source behind the standalone Transaction
 * History screen before the user applies any filters. Thin alias over
 * transaction_history() so there's one query implementation, not two.
 */
if (!function_exists('inventory_history')) {
    function inventory_history(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return transaction_history($filters, $limit, $offset);
    }
}

/**
 * Permanently removes every already-read notification. Unread ones
 * are never touched by this.
 */
if (!function_exists('delete_read_notifications')) {
    function delete_read_notifications(): int
    {
        try {
            $stmt = db()->prepare('DELETE FROM notifications WHERE is_read = 1');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('delete_read_notifications failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* =====================================================================
 * PHASE 7 — REPORTS
 *
 * Registry, query/summary/chart callbacks, filter parsing, and export
 * helpers all live in report_functions.php so this file doesn't grow
 * by another ~900 lines. Required here (not just from the report
 * pages) so anything that already does `require functions.php` gets
 * the whole reports layer for free, same as every other helper group
 * above.
 * ===================================================================== */
require_once __DIR__ . '/report_functions.php';
