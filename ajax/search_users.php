<?php
declare(strict_types=1);

/**
 * search_users.php (AJAX)
 * Returns a JSON list of users matching the query. Admin only.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$q      = clean_input($_GET['q'] ?? '');
$role   = clean_input($_GET['role'] ?? '');
$status = clean_input($_GET['status'] ?? '');
$limit  = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

$where  = ['deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = '(fullname LIKE ? OR username LIKE ? OR email LIKE ? OR role LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($role, ['admin', 'staff'], true)) {
    $where[] = 'role = ?';
    $params[] = $role;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT id, fullname, username, email, role, status, last_login, created_at, profile_image
        FROM users WHERE {$whereSql}
        ORDER BY created_at DESC LIMIT {$limit}";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$out = array_map(static function (array $r): array {
    return [
        'id'         => (int) $r['id'],
        'fullname'   => $r['fullname'],
        'username'   => $r['username'],
        'email'      => $r['email'],
        'role'       => $r['role'],
        'status'     => $r['status'],
        'avatar'     => avatar_url($r['profile_image'], $r['fullname']),
        'last_login' => $r['last_login'],
        'created_at' => $r['created_at'],
    ];
}, $rows);

json_response(['success' => true, 'count' => count($out), 'data' => $out]);
