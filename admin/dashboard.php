<?php
declare(strict_types=1);

/**
 * admin/dashboard.php  (Phase 6 — Inventory Management Module)
 *
 * Full admin overview. All stock/expiry/transaction numbers are
 * computed exclusively from inventory_batches / stock_transactions
 * (never from a products column — see functions.php's
 * inventory_overview_stats() and friends).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

// Admin branch selector — "All Branches" (null) or a specific one.
// Validated against the DB, never trusted blindly (fetch_branch()
// returns null for an invalid/deleted id, which we then treat as "All").
$selectedBranchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
    ? (int) $_GET['branch_id']
    : null;
if ($selectedBranchId !== null && !fetch_branch($selectedBranchId, true)) {
    $selectedBranchId = null;
}
$allBranches = branch_options($selectedBranchId);

$userStats       = user_stats();
$invStats        = inventory_dashboard_stats($selectedBranchId);
$stockBreakdown  = stock_status_breakdown($selectedBranchId);
$expiryBands     = expiration_breakdown($selectedBranchId);
$notifications   = dashboard_notifications(8, $selectedBranchId);
$categoryChart   = products_per_category_chart();
$stockInOut      = stock_transaction_chart(7, $selectedBranchId);
$monthlyTx       = monthly_transactions_chart(6, $selectedBranchId);
$expChart        = expiration_chart($selectedBranchId);
$lowStockChart   = low_stock_chart(8, $selectedBranchId);

$logs = [];
try {
    $logSql = 'SELECT a.activity, a.created_at, u.username, u.fullname
               FROM activity_logs a
               LEFT JOIN users u ON u.id = a.user_id';
    $logParams = [];
    // activity_logs has no branch_id column (it's a free-text audit
    // trail across every module, not just inventory) — when a branch
    // is selected, narrow it to that branch's staff members instead,
    // which keeps the "Recent Activity" panel meaningfully scoped
    // without requiring a schema change to an unrelated table.
    if ($selectedBranchId !== null) {
        $logSql .= ' WHERE u.branch_id = ?';
        $logParams[] = $selectedBranchId;
    }
    $logSql .= ' ORDER BY a.created_at DESC LIMIT 12';
    $stmt = db()->prepare($logSql);
    $stmt->execute($logParams);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('admin dashboard recent activity query failed: ' . $e->getMessage());
}

$pageTitle = 'Admin Dashboard';
$pageScripts = [
    APP_URL . '/assets/vendor/chartjs/chart.umd.js',
    APP_URL . '/assets/js/chart-theme.js',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="dash-heading">
    <div>
        <h2>Welcome back, <?= e(explode(' ', current_user_name())[0]) ?> 👋</h2>
        <div class="text-muted">Here's what's happening across the system today, <?= e(date('l, F j, Y')) ?>.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="GET" class="d-flex align-items-center gap-2">
            <label class="text-muted small mb-0 d-none d-sm-inline">Branch:</label>
            <select name="branch_id" class="form-select form-select-sm" style="min-width:170px" onchange="this.form.submit()">
                <option value="">All Branches</option>
                <?php foreach ($allBranches as $b): ?>
                    <option value="<?= (int) $b['branch_id'] ?>" <?= $selectedBranchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                        <?= e($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="stock_in.php" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</a>
        <a href="stock_out.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-up me-1"></i> Stock Out</a>
        <a href="user_create.php" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i> Add User</a>
    </div>
</div>

<!-- ================= STAT CARDS ================= -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Products',   $invStats['total_products'],   'box-seam',              'bg-grad-indigo', false],
        ['Total Categories', $invStats['total_categories'],  'tags',                  'bg-grad-sky',    false],
        ['Total Suppliers',  $invStats['total_suppliers'],   'truck',                 'bg-grad-teal',   false],
        ['Total Users',      $userStats['total'],            'people-fill',           'bg-grad-violet', false],
        ['Total Stock',      $invStats['total_stock_qty'],   'boxes',                 'bg-grad-slate',  false],
        ['Low Stock',        $invStats['low_stock'],         'exclamation-triangle',  'bg-grad-amber',  $invStats['low_stock'] > 0],
        ['Expiring Soon',    $invStats['expiring_soon'],     'hourglass-split',       'bg-grad-amber',  $invStats['expiring_soon'] > 0],
        ['Expired',          $invStats['expired'],           'x-octagon',             'bg-grad-rose',   $invStats['expired'] > 0],
        ['Stock In Today',   $invStats['stock_in_today'],    'box-arrow-in-down',     'bg-grad-emerald', false],
        ['Stock Out Today',  $invStats['stock_out_today'],   'box-arrow-up',          'bg-grad-rose',   false],
        ['Today\'s Transactions', $invStats['today_transactions'], 'arrow-left-right','bg-grad-violet', false],
    ];
    foreach ($cards as $c):
        [$label, $value, $icon, $bg, $attn] = $c;
    ?>
        <div class="col-6 col-lg-4 col-xl-3">
            <div class="glass-card stat-tile fade-in-item <?= $attn ? 'attn' : '' ?>">
                <div class="icon-badge <?= e($bg) ?>"><i class="bi bi-<?= e($icon) ?>"></i></div>
                <div>
                    <div class="stat-label"><?= e($label) ?></div>
                    <div class="stat-value"><?= (int) $value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ================= CHARTS ================= -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-pie-chart me-1"></i> Inventory Overview</span>
                <span class="subtitle">Stock health by product</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <?php if (array_sum($stockBreakdown) === 0): ?>
                        <div class="chart-empty"><i class="bi bi-inboxes fs-2 mb-2"></i> No stock data yet.</div>
                    <?php else: ?>
                        <canvas id="chartInventoryOverview"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-arrow-left-right me-1"></i> Stock In vs Stock Out</span>
                <span class="subtitle">Last 7 days</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <?php if (array_sum($stockInOut['stock_in']) + array_sum($stockInOut['stock_out']) === 0): ?>
                        <div class="chart-empty"><i class="bi bi-arrow-left-right fs-2 mb-2"></i> No stock movements in the last 7 days.</div>
                    <?php else: ?>
                        <canvas id="chartStockInOut"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-calendar-x me-1"></i> Expiration Status</span>
                <span class="subtitle">Active batches by urgency</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <?php if (array_sum($expChart['data']) === 0): ?>
                        <div class="chart-empty"><i class="bi bi-check2-circle fs-2 mb-2 text-success"></i> Nothing expiring soon.</div>
                    <?php else: ?>
                        <canvas id="chartExpiration"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-exclamation-triangle me-1"></i> Low Stock Products</span>
                <span class="subtitle">Lowest remaining stock</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <?php if (empty($lowStockChart['labels'])): ?>
                        <div class="chart-empty"><i class="bi bi-check2-circle fs-2 mb-2 text-success"></i> Nothing is low on stock.</div>
                    <?php else: ?>
                        <canvas id="chartLowStock"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-bar-chart me-1"></i> Product Statistics</span>
                <span class="subtitle">Products per category</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <?php if (empty($categoryChart['labels']) || array_sum($categoryChart['data']) === 0): ?>
                        <div class="chart-empty"><i class="bi bi-tags fs-2 mb-2"></i> No products assigned to a category yet.</div>
                    <?php else: ?>
                        <canvas id="chartCategoryStats"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-graph-up me-1"></i> Monthly Transactions</span>
                <span class="subtitle">Stock In + Out + Adjustments, last 6 months</span>
            </div>
            <div class="card-body">
                <div class="chart-canvas-wrap h-220">
                    <canvas id="chartMonthlyTx"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= EXPIRATION MONITORING ================= -->
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-calendar-x me-1"></i> Expiration Monitoring</span>
                <a href="expiration_monitoring.php" class="subtitle text-decoration-none">View all &raquo;</a>
            </div>
            <div class="card-body">
                <a href="expiration_monitoring.php?band=expired" class="text-decoration-none">
                    <div class="expiry-band band-expired">
                        <span><i class="bi bi-x-octagon-fill me-2"></i> Already Expired</span>
                        <span class="band-count"><?= (int) $expiryBands['expired'] ?></span>
                    </div>
                </a>
                <br>
                <a href="expiration_monitoring.php?band=critical" class="text-decoration-none">
                    <div class="expiry-band band-critical">
                        <span><i class="bi bi-exclamation-octagon-fill me-2"></i> Critical (&le; 7 days)</span>
                        <span class="band-count"><?= (int) $expiryBands['critical'] ?></span>
                    </div>
                </a>
                <br>
                <a href="expiration_monitoring.php?band=warning" class="text-decoration-none">
                    <div class="expiry-band band-warning">
                        <span><i class="bi bi-exclamation-triangle-fill me-2"></i> Warning (&le; 15 days)</span>
                        <span class="band-count"><?= (int) $expiryBands['warning'] ?></span>
                    </div>
                </a>
                <br>
                <a href="expiration_monitoring.php?band=notice" class="text-decoration-none">
                    <div class="expiry-band band-notice">
                        <span><i class="bi bi-info-circle-fill me-2"></i> Notice (&le; 30 days)</span>
                        <span class="band-count"><?= (int) $expiryBands['notice'] ?></span>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- ================= NOTIFICATIONS ================= -->
    <div class="col-lg-7">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-bell me-1"></i> Notifications</span>
                <a href="notifications.php" class="subtitle text-decoration-none">View all &raquo;</a>
            </div>
            <div class="card-body notif-card-list activity-scroll">
                <?php if (empty($notifications)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check2-circle fs-2 d-block mb-2 text-success"></i>
                        All clear — nothing needs attention right now.
                    </div>
                <?php else: foreach ($notifications as $n): ?>
                    <div class="notif-card type-<?= e($n['type']) ?>">
                        <div class="notif-card-icon"><i class="bi <?= e($n['icon']) ?>"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= e($n['title']) ?></div>
                            <div class="text-muted small"><?= e($n['description']) ?></div>
                        </div>
                        <div class="text-muted small text-nowrap"><?= e(time_ago($n['created_at'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================= RECENT ACTIVITY ================= -->
<div class="row g-3">
    <div class="col-12">
        <div class="card chart-card">
            <div class="card-header">
                <span><i class="bi bi-clock-history me-1"></i> Recent Activity</span>
                <span class="subtitle">Latest system events</span>
            </div>
            <div class="card-body activity-scroll">
                <?php if (empty($logs)): ?>
                    <p class="text-muted mb-0">No recent activity.</p>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php foreach ($logs as $l): ?>
                            <?php $displayName = $l['fullname'] ?: ($l['username'] ?: 'System'); ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <div>
                                        <strong><?= e($displayName) ?></strong>
                                        <span class="text-muted"> &middot; <?= e($l['activity']) ?></span>
                                    </div>
                                    <small class="text-muted"><?= e(time_ago($l['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$chartJsPayload = [
    'stockBreakdown' => $stockBreakdown,
    'category'       => $categoryChart,
    'stockInOut'     => $stockInOut,
    'monthlyTx'      => $monthlyTx,
    'expiration'     => $expChart,
    'lowStock'       => $lowStockChart,
];

$pageInlineScript = '
document.addEventListener("DOMContentLoaded", function () {
    const data = ' . json_encode($chartJsPayload, JSON_HEX_TAG | JSON_HEX_APOS) . ';
    const pal = chartTheme.palette();

    Chart.defaults.font.family = "system-ui, -apple-system, Segoe UI, Roboto, sans-serif";
    Chart.defaults.color = pal.label;

    // Shared look for grid lines / tooltips so every chart reads as one
    // consistent system instead of each canvas styling itself.
    const gridOpts = { color: pal.grid, drawTicks: false };
    const tooltipOpts = {
        backgroundColor: pal.tooltipBg,
        titleColor: pal.label,
        bodyColor: pal.label,
        borderColor: pal.tooltipBorder,
        borderWidth: 1,
        padding: 10,
        boxPadding: 4
    };

    const stockCanvas = document.getElementById("chartInventoryOverview");
    if (stockCanvas) {
        new Chart(stockCanvas, {
            type: "doughnut",
            data: {
                labels: ["Available", "Low Stock", "Expired"],
                datasets: [{
                    data: [data.stockBreakdown.available, data.stockBreakdown.low_stock, data.stockBreakdown.expired],
                    backgroundColor: [pal.success, pal.warning, pal.danger],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                plugins: { legend: { position: "bottom" }, tooltip: tooltipOpts },
                cutout: "68%",
                maintainAspectRatio: false
            }
        });
    }

    const inOutCanvas = document.getElementById("chartStockInOut");
    if (inOutCanvas) {
        new Chart(inOutCanvas, {
            type: "bar",
            data: {
                labels: data.stockInOut.labels,
                datasets: [
                    { label: "Stock In", data: data.stockInOut.stock_in, backgroundColor: pal.success, borderRadius: 6, maxBarThickness: 22 },
                    { label: "Stock Out", data: data.stockInOut.stock_out, backgroundColor: pal.danger, borderRadius: 6, maxBarThickness: 22 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: "bottom" }, tooltip: tooltipOpts },
                scales: {
                    x: { grid: gridOpts },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridOpts }
                }
            }
        });
    }

    const expCanvas = document.getElementById("chartExpiration");
    if (expCanvas) {
        new Chart(expCanvas, {
            type: "bar",
            data: {
                labels: data.expiration.labels,
                datasets: [{
                    label: "Batches",
                    data: data.expiration.data,
                    backgroundColor: [pal.danger, pal.critical, pal.warning, pal.info],
                    borderRadius: 8,
                    maxBarThickness: 40
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipOpts },
                scales: {
                    x: { grid: gridOpts },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridOpts }
                }
            }
        });
    }

    const lowStockCanvas = document.getElementById("chartLowStock");
    if (lowStockCanvas) {
        new Chart(lowStockCanvas, {
            type: "bar",
            indexAxis: "y",
            data: {
                labels: data.lowStock.labels,
                datasets: [{
                    label: "Remaining",
                    data: data.lowStock.data,
                    backgroundColor: chartTheme.shadeFamily(pal.secondarySoft, data.lowStock.data.length),
                    borderRadius: 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipOpts },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: gridOpts },
                    y: { grid: gridOpts }
                }
            }
        });
    }

    const catCanvas = document.getElementById("chartCategoryStats");
    if (catCanvas) {
        new Chart(catCanvas, {
            type: "bar",
            data: {
                labels: data.category.labels,
                datasets: [{
                    label: "Products",
                    data: data.category.data,
                    backgroundColor: chartTheme.shadeFamily(pal.primary, data.category.data.length),
                    borderRadius: 8,
                    maxBarThickness: 34
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipOpts },
                scales: {
                    x: { grid: gridOpts },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridOpts }
                }
            }
        });
    }

    const monthlyTxCanvas = document.getElementById("chartMonthlyTx");
    if (monthlyTxCanvas) {
        new Chart(monthlyTxCanvas, {
            type: "line",
            data: {
                labels: data.monthlyTx.labels,
                datasets: [{
                    label: "Transactions",
                    data: data.monthlyTx.data,
                    borderColor: pal.secondary,
                    backgroundColor: pal.secondary + "26",
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointBackgroundColor: pal.secondary
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipOpts },
                scales: {
                    x: { grid: gridOpts },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: gridOpts }
                }
            }
        });
    }
});
';

require __DIR__ . '/../includes/footer.php';
