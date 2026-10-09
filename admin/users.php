<?php
declare(strict_types=1);

/**
 * users.php
 * List users with search, filters, sort, pagination, and stat cards.
 * Admin only.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();
$pdo = db();
$stats = user_stats();

// ---- Query params ---------------------------------------------
$search    = clean_input($_GET['search']    ?? '');
$role      = clean_input($_GET['role']      ?? '');
$status    = clean_input($_GET['status']    ?? '');
$sort      = $_GET['sort'] ?? 'created_at';
$direction = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = PER_PAGE;

// Allowed sort columns (whitelist prevents SQL injection via ORDER BY)
$allowedSort = ['id', 'fullname', 'username', 'email', 'role', 'status', 'last_login', 'created_at'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'created_at';
}

// ---- Build query ----------------------------------------------
// Every filter column below is prefixed with u. since the branches
// table (joined for display) shares several column names (status,
// email, deleted_at, created_at) with users — without the prefix,
// MySQL would reject this as an ambiguous column reference.
$where  = ['u.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.role LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($role, ['admin', 'staff'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

[$totalPages, $offset, $page] = paginate($total, $perPage, $page);

// Fetch page (sort/direction are whitelisted above, safe to interpolate)
$sql = "SELECT u.*, br.branch_name FROM users u
        LEFT JOIN branches br ON br.branch_id = u.branch_id
        WHERE {$whereSql}
        ORDER BY u.{$sort} {$direction}
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Users Management';
require __DIR__ . '/../includes/header.php';
?>
<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Users',  $stats['total'],    'people-fill',   'primary'],
        ['Total Admin',  $stats['admin'],    'shield-fill',   'danger'],
        ['Total Staff',  $stats['staff'],    'person-badge',  'info'],
        ['Active',       $stats['active'],   'check-circle',  'success'],
        ['Inactive',     $stats['inactive'], 'x-circle',      'warning'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="bi bi-<?= e($c[2]) ?> fs-2 text-<?= e($c[3]) ?> me-3"></i>
                    <div>
                        <div class="text-muted small"><?= e($c[0]) ?></div>
                        <div class="fs-4 fw-bold"><?= (int) $c[1] ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <i class="bi bi-table me-1"></i> <strong>Users</strong>
        <a href="user_create.php" class="btn btn-sm btn-success ms-auto">
            <i class="bi bi-person-plus"></i> Add User
        </a>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3" id="filterForm">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name, username, email, role"
                           value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="role" class="form-select">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="sort" class="form-select">
                    <?php
                    $sorts = [
                        'created_at' => 'Date Created', 'id' => 'ID', 'fullname' => 'Full Name',
                        'username' => 'Username', 'email' => 'Email', 'role' => 'Role',
                        'status' => 'Status', 'last_login' => 'Last Login',
                    ];
                    foreach ($sorts as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="dir" class="form-select">
                    <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Desc</option>
                    <option value="asc"  <?= $direction === 'asc'  ? 'selected' : '' ?>>Asc</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <!-- Users table -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Date Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="11" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No users found.
                    </td></tr>
                <?php else: foreach ($users as $u):
                    $isSelf = ((int) $u['id'] === current_user_id());
                    $img    = avatar_url($u['profile_image'], $u['fullname']);
                ?>
                    <tr>
                        <td><?= (int) $u['id'] ?></td>
                        <td><img src="<?= e($img) ?>" alt="avatar" class="rounded-circle"
                                 width="40" height="40" style="object-fit:cover"></td>
                        <td><?= e($u['fullname']) ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><?= e($u['email'] ?: '—') ?></td>
                        <td>
                            <span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'info' ?>">
                                <?= e(ucfirst($u['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['branch_name']): ?>
                                <span class="badge bg-light text-dark border"><?= e($u['branch_name']) ?></span>
                            <?php elseif ($u['role'] === 'admin'): ?>
                                <span class="text-muted small">All Branches</span>
                            <?php else: ?>
                                <span class="text-danger small">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(time_ago($u['last_login'])) ?></td>
                        <td><?= e(format_date($u['created_at'], 'M j, Y')) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="user_profile.php?id=<?= (int) $u['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="user_edit.php?id=<?= (int) $u['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="user_password.php?id=<?= (int) $u['id'] ?>"
                               class="btn btn-sm btn-outline-warning" title="Change Password"><i class="bi bi-key"></i></a>
                            <?php if (!$isSelf): ?>
                                <?php if ($u['status'] === 'active'): ?>
                                    <a href="user_delete.php?action=deactivate&id=<?= (int) $u['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-warning" title="Deactivate"
                                       onclick="return confirm('Deactivate this user?')"><i class="bi bi-pause-circle"></i></a>
                                <?php else: ?>
                                    <a href="user_delete.php?action=activate&id=<?= (int) $u['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-success" title="Activate"
                                       onclick="return confirm('Activate this user?')"><i class="bi bi-play-circle"></i></a>
                                <?php endif; ?>
                                <a href="user_delete.php?action=delete&id=<?= (int) $u['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Delete this user? This can be undone from Deleted Users.')"><i class="bi bi-trash"></i></a>
                            <?php else: ?>
                                <span class="badge bg-light text-muted" title="This is you">(You)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?>
                of <?= $total ?> users
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
