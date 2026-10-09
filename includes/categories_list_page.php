<?php
declare(strict_types=1);

/**
 * categories_list_page.php
 * Shared by admin/categories.php and staff/categories.php.
 * Admin: full CRUD action buttons. Staff: view-only (no Edit/Delete
 * buttons rendered, and the underlying pages 403 if hit directly).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$pdo     = db();
$stats   = category_stats();

// ---- Query params ---------------------------------------------
$search    = clean_input($_GET['search'] ?? '');
$status    = clean_input($_GET['status'] ?? '');
$sort      = $_GET['sort'] ?? 'created_at';
$direction = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = PER_PAGE;

$allowedSort = ['category_id', 'category_name', 'status', 'created_at', 'updated_at'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'created_at';
}
$sortColumn = $sort === 'category_id' ? 'c.category_id' : ('c.' . $sort);

// ---- Build query ------------------------------------------------
$where  = ['c.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(c.category_name LIKE ? OR c.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM categories c WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

[$totalPages, $offset, $page] = paginate($total, $perPage, $page);

$sql = "SELECT c.*, COUNT(p.product_id) AS product_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.category_id
        WHERE {$whereSql}
        GROUP BY c.category_id
        ORDER BY {$sortColumn} {$direction}
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$pageTitle = 'Categories';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Categories</h2>
        <div class="text-muted">Product categories used across the inventory.</div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="d-flex gap-2">
            <a href="categories_deleted.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-trash3 me-1"></i> Deleted
            </a>
            <a href="category_create.php" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add Category
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Categories', $stats['total'],    'tags-fill',    'bg-grad-sky'],
        ['Active',           $stats['active'],   'check-circle', 'bg-grad-emerald'],
        ['Inactive',         $stats['inactive'], 'pause-circle', 'bg-grad-slate'],
        ['Deleted',          $stats['deleted'],  'trash3',       'bg-grad-rose'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg-3">
            <div class="glass-card stat-tile fade-in-item">
                <div class="icon-badge <?= e($c[3]) ?>"><i class="bi bi-<?= e($c[2]) ?>"></i></div>
                <div>
                    <div class="stat-label"><?= e($c[0]) ?></div>
                    <div class="stat-value"><?= (int) $c[1] ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card chart-card">
    <div class="card-header">
        <span><i class="bi bi-table me-1"></i> <strong>Category List</strong></span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name or description" value="<?= e($search) ?>">
                </div>
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
                        'created_at' => 'Date Created', 'category_name' => 'Name',
                        'status' => 'Status', 'updated_at' => 'Last Updated',
                    ];
                    foreach ($sorts as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="dir" class="form-select">
                    <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Desc</option>
                    <option value="asc"  <?= $direction === 'asc'  ? 'selected' : '' ?>>Asc</option>
                </select>
            </div>
            <div class="col-6 col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-center">Products</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$categories): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-tags fs-1 d-block mb-2"></i>
                        No categories found.
                        <?php if ($isAdmin): ?>
                            <div class="mt-2"><a href="category_create.php" class="btn btn-sm btn-primary">Add the first category</a></div>
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ($categories as $cat): ?>
                    <tr>
                        <td>#<?= (int) $cat['category_id'] ?></td>
                        <td class="fw-semibold"><?= e($cat['category_name']) ?></td>
                        <td class="text-muted small">
                            <?= e(mb_strlen((string) $cat['description']) > 60
                                ? mb_substr((string) $cat['description'], 0, 60) . '…'
                                : ($cat['description'] ?: '—')) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary-subtle text-primary"><?= (int) $cat['product_count'] ?></span>
                        </td>
                        <td>
                            <?php if ($cat['status'] === 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($cat['created_at'], 'M j, Y')) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="category_view.php?id=<?= (int) $cat['category_id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if ($isAdmin): ?>
                                <a href="category_edit.php?id=<?= (int) $cat['category_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php if ($cat['status'] === 'active'): ?>
                                    <a href="category_delete.php?action=deactivate&id=<?= (int) $cat['category_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-warning" title="Deactivate"
                                       onclick="return confirm('Deactivate this category?')"><i class="bi bi-pause-circle"></i></a>
                                <?php else: ?>
                                    <a href="category_delete.php?action=activate&id=<?= (int) $cat['category_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-success" title="Activate"
                                       onclick="return confirm('Activate this category?')"><i class="bi bi-play-circle"></i></a>
                                <?php endif; ?>
                                <a href="category_delete.php?action=delete&id=<?= (int) $cat['category_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Delete this category? <?= (int) $cat['product_count'] ?> product(s) reference it.')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> categories
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
