<?php
declare(strict_types=1);

/**
 * products_list_page.php
 * Shared by admin/products.php and staff/products.php.
 * Admin: full CRUD action buttons. Staff: view-only.
 *
 * SCOPE REMINDER: this lists product INFORMATION only. No quantity/
 * stock/expiration columns exist on `products` as of Phase 5 — those
 * belong to the Phase 6 Inventory/Stock module.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$pdo     = db();
$stats   = product_stats();

// ---- Query params ---------------------------------------------
$search     = clean_input($_GET['search'] ?? '');
$status     = clean_input($_GET['status'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$sort       = $_GET['sort'] ?? 'created_at';
$direction  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = PER_PAGE;

$allowedSort = ['product_id', 'product_name', 'barcode', 'selling_price', 'status', 'created_at', 'updated_at'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'created_at';
}
$sortColumn = $sort === 'product_id' ? 'p.product_id' : ('p.' . $sort);

// ---- Build query ------------------------------------------------
$where  = ['p.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(p.product_name LIKE ? OR p.barcode LIKE ? OR p.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($supplierId > 0) {
    $where[] = 'p.supplier_id = ?';
    $params[] = $supplierId;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM products p WHERE {$whereSql}"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

[$totalPages, $offset, $page] = paginate($total, $perPage, $page);

$sql = "SELECT p.*, c.category_name, s.supplier_name
        FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
        WHERE {$whereSql}
        ORDER BY {$sortColumn} {$direction}
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categoryFilterOptions = category_options($categoryId ?: null);
$supplierFilterOptions = supplier_options($supplierId ?: null);

$pageTitle = 'Products';
require __DIR__ . '/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Products</h2>
        <div class="text-muted">Product catalog — information management only. Stock &amp; expiration live in the Inventory module.</div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="d-flex gap-2">
            <a href="products_deleted.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-trash3 me-1"></i> Deleted
            </a>
            <a href="product_create.php" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add Product
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Products', $stats['total'],      'box-seam',    'bg-grad-indigo'],
        ['Active',         $stats['active'],     'check-circle','bg-grad-emerald'],
        ['Inactive',       $stats['inactive'],   'pause-circle','bg-grad-slate'],
        ['No Barcode',     $stats['no_barcode'], 'upc',         'bg-grad-amber'],
        ['Deleted',        $stats['deleted'],    'trash3',      'bg-grad-rose'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg">
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
        <span><i class="bi bi-table me-1"></i> <strong>Product List</strong></span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name, barcode, description" value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="category_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryFilterOptions as $cat): ?>
                        <option value="<?= (int) $cat['category_id'] ?>" <?= $categoryId === (int) $cat['category_id'] ? 'selected' : '' ?>>
                            <?= e($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="supplier_id" class="form-select">
                    <option value="">All Suppliers</option>
                    <?php foreach ($supplierFilterOptions as $sup): ?>
                        <option value="<?= (int) $sup['supplier_id'] ?>" <?= $supplierId === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                            <?= e($sup['supplier_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-md-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <select name="sort" class="form-select form-select-sm w-auto">
                    <?php
                    $sorts = [
                        'created_at' => 'Date Created', 'product_name' => 'Name',
                        'barcode' => 'Barcode', 'selling_price' => 'Price', 'status' => 'Status',
                    ];
                    foreach ($sorts as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>>Sort: <?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="dir" class="form-select form-select-sm w-auto">
                    <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descending</option>
                    <option value="asc"  <?= $direction === 'asc'  ? 'selected' : '' ?>>Ascending</option>
                </select>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th></th>
                        <th>Barcode</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Unit</th>
                        <th class="text-end">Price</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                        No products found.
                        <?php if ($isAdmin): ?>
                            <div class="mt-2"><a href="product_create.php" class="btn btn-sm btn-primary">Add the first product</a></div>
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ($products as $p):
                    $img = product_image_url($p['product_image']);
                ?>
                    <tr>
                        <td>
                            <?php if ($img): ?>
                                <img src="<?= e($img) ?>" alt="" class="rounded" width="40" height="40" style="object-fit:cover">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center rounded bg-body-secondary text-muted"
                                     style="width:40px;height:40px"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['barcode']): ?>
                                <code><?= e($p['barcode']) ?></code>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning">No barcode</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= e($p['product_name']) ?></td>
                        <td><?= e($p['category_name'] ?: '—') ?></td>
                        <td><?= e($p['supplier_name'] ?: '—') ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($p['unit']) ?></span></td>
                        <td class="text-end">&#8369;<?= number_format((float) $p['selling_price'], 2) ?></td>
                        <td>
                            <?php if ($p['status'] === 'active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="product_view.php?id=<?= (int) $p['product_id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php if ($isAdmin): ?>
                                <a href="product_edit.php?id=<?= (int) $p['product_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php if ($p['status'] === 'active'): ?>
                                    <a href="product_delete.php?action=deactivate&id=<?= (int) $p['product_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-warning" title="Deactivate"
                                       onclick="return confirm('Deactivate this product?')"><i class="bi bi-pause-circle"></i></a>
                                <?php else: ?>
                                    <a href="product_delete.php?action=activate&id=<?= (int) $p['product_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-success" title="Activate"
                                       onclick="return confirm('Activate this product?')"><i class="bi bi-play-circle"></i></a>
                                <?php endif; ?>
                                <a href="product_delete.php?action=delete&id=<?= (int) $p['product_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Delete this product?')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> products
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
