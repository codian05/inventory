<?php
declare(strict_types=1);

/**
 * report_view_page.php
 * Shared by admin/report_view.php. One dynamic viewer for all 8
 * reports — driven entirely by the registry entry in
 * includes/report_functions.php (report_registry()). Adding a report
 * means adding a registry entry + its query/summary/chart callbacks;
 * this file never needs a per-report branch.
 *
 * SECURITY: $reportId comes straight from $_GET, but it is only ever
 * used as an array key lookup into the fixed, server-defined
 * report_registry() array (report_get_definition()) — never
 * interpolated into SQL, a file path, or a function-name string. An
 * unknown id gets a 404, not a fallback report.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_admin();

$reportId = clean_input((string) ($_GET['report'] ?? ''));
$definition = report_get_definition($reportId);

if (!$definition) {
    http_response_code(404);
    $pageTitle = 'Report Not Found';
    require __DIR__ . '/header.php';
    echo '<div class="card chart-card"><div class="card-body text-center py-5">';
    echo '<i class="bi bi-question-circle fs-1 d-block mb-3 text-muted"></i>';
    echo '<h4>Report not found</h4>';
    echo '<p class="text-muted">That report doesn\'t exist. Pick one from the Reports dashboard.</p>';
    echo '<a href="reports.php" class="btn btn-primary btn-sm">Back to Reports</a>';
    echo '</div></div>';
    require __DIR__ . '/footer.php';
    return;
}

$filters = report_parse_filters($_GET, $definition['filters']);

/** @var callable $queryFn */
$queryFn = $definition['query'];
$allRows = $queryFn($filters);

/** @var callable $summaryFn */
$summaryFn = $definition['summary'];
$summaryCards = $summaryFn($allRows);

$charts = [];
foreach ($definition['charts'] as $chartFn) {
    $charts[] = $chartFn($allRows);
}

// ---- Pagination (table display only — summary/charts use $allRows) ----
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = PER_PAGE;
$total = count($allRows);
[$totalPages, $offset, $page] = paginate($total, $perPage, $page);
$pageRows = array_slice($allRows, $offset, $perPage);

// ---- Filter option lists (only fetched for the widgets this report needs) ----
$categoryFilterOptions = in_array('category_id', $definition['filters'], true) ? category_options() : [];
$supplierFilterOptions = in_array('supplier_id', $definition['filters'], true) ? supplier_options() : [];
$productFilterOptions  = in_array('product_id', $definition['filters'], true) ? product_options() : [];
$userFilterOptions     = in_array('user_id', $definition['filters'], true) ? user_options() : [];
// Reports are admin-only (require_admin() above) — there is no
// staff-facing report view to restrict, so this is always an "All
// Branches or a specific one" selector, never a forced single branch.
$branchFilterOptions   = in_array('branch_id', $definition['filters'], true) ? branch_options((int) ($filters['branch_id'] ?? 0) ?: null) : [];

/**
 * Bootstrap badge class for the known status-ish values this app
 * uses across products/batches/transactions/users. Falls back to a
 * neutral badge for anything unrecognized rather than guessing.
 */
if (!function_exists('report_badge_class')) {
    function report_badge_class(string $value): string
    {
        return match ($value) {
            'active', 'Stock In', 'OK', 'admin' => 'success',
            'inactive', 'staff' => 'secondary',
            'Critical', 'Expired', 'Stock Out' => 'danger',
            'Warning', 'Adjustment' => 'warning',
            'Notice' => 'info',
            default => 'light text-dark border',
        };
    }
}

// Query string of current filters (without page), reused for export links.
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQueryString = http_build_query($exportQuery);

$pageTitle = $definition['title'];
$pageScripts = [
    APP_URL . '/assets/vendor/chartjs/chart.umd.js',
    APP_URL . '/assets/js/chart-theme.js',
    APP_URL . '/assets/js/reports.js',
];
require __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="<?= e($appUrl) ?>/assets/css/reports.css">

<div class="dash-heading no-print">
    <div>
        <h2><i class="bi <?= e($definition['icon']) ?> me-2"></i><?= e($definition['title']) ?></h2>
        <div class="text-muted"><?= e($definition['description']) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="reports.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> All Reports
        </a>
        <button type="button" id="reportPrintBtn" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($appUrl) ?>/admin/report_export.php?report=<?= e($reportId) ?>&format=csv&<?= e($exportQueryString) ?>">
            <i class="bi bi-filetype-csv me-1"></i> CSV
        </a>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($appUrl) ?>/admin/report_export.php?report=<?= e($reportId) ?>&format=excel&<?= e($exportQueryString) ?>">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
        </a>
    </div>
</div>

<div class="print-header d-none">
    <h3><?= e($definition['title']) ?></h3>
    <div class="text-muted"><?= e(APP_NAME) ?> &middot; Generated <?= e(date('M j, Y g:i A')) ?> by <?= e(current_user_name()) ?></div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4 no-print">
    <?php foreach ($summaryCards as $card): ?>
        <div class="col-6 col-lg">
            <div class="glass-card stat-tile fade-in-item">
                <div class="icon-badge <?= e($card['color']) ?>"><i class="bi bi-<?= e($card['icon']) ?>"></i></div>
                <div>
                    <div class="stat-label"><?= e($card['label']) ?></div>
                    <div class="stat-value"><?= e((string) $card['value']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<?php if (!empty($definition['filters'])): ?>
<div class="card chart-card mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 report-filter-form">
            <input type="hidden" name="report" value="<?= e($reportId) ?>">

            <?php foreach ($definition['filters'] as $filterKey): ?>
                <?php switch ($filterKey):
                    case 'branch_id': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Branch</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">All Branches</option>
                                <?php foreach ($branchFilterOptions as $b): ?>
                                    <option value="<?= (int) $b['branch_id'] ?>" <?= (int) ($filters['branch_id'] ?? 0) === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                        <?= e($b['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'date_from': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm"
                                   value="<?= e($filters['date_from'] ?? '') ?>">
                        </div>
                        <?php break;

                    case 'date_to': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm"
                                   value="<?= e($filters['date_to'] ?? '') ?>">
                        </div>
                        <?php break;

                    case 'category_id': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Category</label>
                            <select name="category_id" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($categoryFilterOptions as $cat): ?>
                                    <option value="<?= (int) $cat['category_id'] ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $cat['category_id'] ? 'selected' : '' ?>>
                                        <?= e($cat['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'supplier_id': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Supplier</label>
                            <select name="supplier_id" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($supplierFilterOptions as $sup): ?>
                                    <option value="<?= (int) $sup['supplier_id'] ?>" <?= (int) ($filters['supplier_id'] ?? 0) === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                                        <?= e($sup['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'product_id': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Product</label>
                            <select name="product_id" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($productFilterOptions as $prod): ?>
                                    <option value="<?= (int) $prod['product_id'] ?>" <?= (int) ($filters['product_id'] ?? 0) === (int) $prod['product_id'] ? 'selected' : '' ?>>
                                        <?= e($prod['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'user_id': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">User</label>
                            <select name="user_id" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach ($userFilterOptions as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['fullname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'movement_type': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Type</label>
                            <select name="movement_type" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach (['Stock In', 'Stock Out', 'Adjustment'] as $mt): ?>
                                    <option value="<?= e($mt) ?>" <?= ($filters['movement_type'] ?? '') === $mt ? 'selected' : '' ?>><?= e($mt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;

                    case 'role': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Role</label>
                            <select name="role" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="admin" <?= ($filters['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="staff" <?= ($filters['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff</option>
                            </select>
                        </div>
                        <?php break;

                    case 'status': ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php
                                $statusOptions = $reportId === 'expiration'
                                    ? ['expired' => 'Expired', 'critical' => 'Critical', 'warning' => 'Warning', 'notice' => 'Notice']
                                    : ['active' => 'Active', 'inactive' => 'Inactive'];
                                foreach ($statusOptions as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= ($filters['status'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;
                endswitch; ?>
            <?php endforeach; ?>

            <div class="col-6 col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="report_view.php?report=<?= e($reportId) ?>" class="btn btn-outline-secondary btn-sm" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Charts -->
<?php if (!empty($charts)): ?>
<div class="row g-3 mb-4 no-print">
    <?php foreach ($charts as $i => $chart): ?>
        <div class="col-12 col-lg-<?= count($charts) > 1 ? '6' : '12' ?>">
            <div class="card chart-card h-100">
                <div class="card-header"><span><i class="bi bi-bar-chart me-1"></i> <strong><?= e($chart['title']) ?></strong></span></div>
                <div class="card-body">
                    <div class="chart-canvas-wrap h-260">
                        <?php if (empty($chart['labels'])): ?>
                            <div class="chart-empty"><i class="bi bi-inboxes fs-2 mb-2"></i> No data for the current filters.</div>
                        <?php else: ?>
                            <canvas id="reportChart<?= (int) $i ?>"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Data table -->
<div class="card chart-card">
    <div class="card-header">
        <span><i class="bi bi-table me-1"></i> <strong><?= e($definition['title']) ?> Data</strong></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle report-table">
                <thead class="table-light">
                    <tr>
                        <?php foreach ($definition['columns'] as $col): ?>
                            <th class="<?= isset($col['align']) ? 'text-' . e($col['align']) : '' ?>"><?= e($col['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$pageRows): ?>
                    <tr><td colspan="<?= count($definition['columns']) ?>" class="text-center py-5 text-muted">
                        <i class="bi bi-inboxes fs-1 d-block mb-2"></i>
                        No data found for the current filters.
                    </td></tr>
                <?php else: foreach ($pageRows as $row): ?>
                    <tr>
                        <?php foreach ($definition['columns'] as $col):
                            $val = $row[$col['key']] ?? '';
                            $cellClass = isset($col['align']) ? 'text-' . $col['align'] : '';
                        ?>
                            <td class="<?= e($cellClass) ?>">
                                <?php if ($col['type'] === 'money'): ?>
                                    <?= e(format_money((float) $val)) ?>
                                <?php elseif ($col['type'] === 'date'): ?>
                                    <?= e($val ? format_date((string) $val, 'M j, Y') : '—') ?>
                                <?php elseif ($col['type'] === 'datetime'): ?>
                                    <?= e($val ? format_date((string) $val) : '—') ?>
                                <?php elseif ($col['type'] === 'number'): ?>
                                    <?= number_format((float) $val) ?>
                                <?php elseif ($col['type'] === 'status'): ?>
                                    <span class="badge bg-<?= e(report_badge_class((string) $val)) ?>"><?= e((string) $val ?: '—') ?></span>
                                <?php else: ?>
                                    <?= e((string) $val !== '' ? (string) $val : '—') ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center no-print">
            <div class="text-muted small">
                Showing <?= $total ? ($offset + 1) : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= $total ?> row(s)
            </div>
            <?= pagination_links($page, $totalPages) ?>
        </div>
    </div>
</div>

<?php
$pageInlineScript = '
document.addEventListener("DOMContentLoaded", function () {
    window.reportsCharts.renderAll("reportChart", ' . json_encode($charts, JSON_HEX_TAG | JSON_HEX_APOS) . ');
});
';
require __DIR__ . '/footer.php';
