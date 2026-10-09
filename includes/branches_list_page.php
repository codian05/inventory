<?php
declare(strict_types=1);

/**
 * branches_list_page.php
 * Admin-only Branch Management — list, search, filter by status,
 * with per-branch user/inventory counts. Mirrors the
 * categories_list_page.php / suppliers list conventions.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_admin();

$search = clean_input($_GET['search'] ?? '');
$status = clean_input($_GET['status'] ?? ''); // '', 'active', 'inactive'
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = PER_PAGE;

$where = ['deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(branch_name LIKE ? OR branch_code LIKE ? OR address LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$pdo = db();
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

[$totalPages, $offset, $page] = paginate($total, $perPage, $page);

$stmt = $pdo->prepare(
    "SELECT * FROM branches WHERE {$whereSql} ORDER BY branch_name ASC LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$branches = $stmt->fetchAll();

$stats = branch_stats();

$pageTitle = 'Branch Management';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Branch Management</h2>
        <div class="text-muted">Manage branches and their assigned staff.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="branches_deleted.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash me-1"></i> Deleted Branches</a>
        <a href="branch_create.php" class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i> Add Branch</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Branches', $stats['total'],    'shop',         'primary'],
        ['Active',         $stats['active'],   'check-circle', 'success'],
        ['Inactive',       $stats['inactive'], 'x-circle',     'warning'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4">
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
        <i class="bi bi-table me-1"></i> <strong>Branches</strong>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search name, code, or address" value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Branch Name</th>
                        <th>Contact</th>
                        <th class="text-end">Users</th>
                        <th class="text-end">Batches</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$branches): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-shop fs-1 d-block mb-2"></i>
                        No branches found.
                        <?php if (!$search && !$status): ?><br><a href="branch_create.php">Create your first branch</a>.<?php endif; ?>
                    </td></tr>
                <?php else: foreach ($branches as $b): ?>
                    <tr>
                        <td><code><?= e($b['branch_code']) ?></code></td>
                        <td class="fw-semibold"><?= e($b['branch_name']) ?></td>
                        <td class="small">
                            <?= $b['contact_number'] ? e($b['contact_number']) : '' ?>
                            <?php if ($b['contact_number'] && $b['email']): ?><br><?php endif; ?>
                            <?= $b['email'] ? e($b['email']) : '' ?>
                            <?= (!$b['contact_number'] && !$b['email']) ? '—' : '' ?>
                        </td>
                        <td class="text-end"><?= branch_user_count((int) $b['branch_id']) ?></td>
                        <td class="text-end"><?= branch_batch_count((int) $b['branch_id']) ?></td>
                        <td>
                            <?php if ($b['status'] === 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($b['created_at'], 'M j, Y')) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="branch_view.php?id=<?= (int) $b['branch_id'] ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="branch_edit.php?id=<?= (int) $b['branch_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php if ($b['status'] === 'active'): ?>
                                <a href="branch_delete.php?action=deactivate&id=<?= (int) $b['branch_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-warning" title="Deactivate"
                                   onclick="return confirm('Deactivate this branch? Staff assigned to it will keep their account but the branch will no longer accept new inventory operations.')"><i class="bi bi-pause-circle"></i></a>
                            <?php else: ?>
                                <a href="branch_delete.php?action=activate&id=<?= (int) $b['branch_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-success" title="Activate"
                                   onclick="return confirm('Activate this branch?')"><i class="bi bi-play-circle"></i></a>
                            <?php endif; ?>
                            <a href="branch_delete.php?action=delete&id=<?= (int) $b['branch_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                               class="btn btn-sm btn-outline-danger" title="Delete"
                               onclick="return confirm('Delete this branch? Existing inventory and transaction history are preserved and this can be undone from Deleted Branches.')"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> branches
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
