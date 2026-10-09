<?php
declare(strict_types=1);

/**
 * staff/dashboard.php  (Phase 6 — Inventory Management Module)
 *
 * Staff overview. Deliberately excludes anything admin-only: no user
 * counts/management, no role management, no settings, no deleted
 * records, no batch/adjustment/notification-center admin tools. All
 * stock/expiry numbers computed exclusively from inventory_batches /
 * stock_transactions, same as the admin dashboard.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_staff();

// Staff are ALWAYS scoped to their own session branch — never a
// request parameter, never a form choice. current_user_branch_id()
// is the only source (kept in sync with the DB every request).
$myBranchId = current_user_branch_id();
$myBranch = $myBranchId ? fetch_branch($myBranchId, true) : null;

$invStats       = inventory_dashboard_stats($myBranchId);
$stockBreakdown = stock_status_breakdown($myBranchId);
$expiryBands    = expiration_breakdown($myBranchId);
$notifications  = dashboard_notifications(8, $myBranchId);
$stockInOut     = stock_transaction_chart(7, $myBranchId);

$logs = [];
try {
    $stmt = db()->prepare(
        'SELECT activity, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 12'
    );
    $stmt->execute([current_user_id()]);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('staff dashboard activity query failed: ' . $e->getMessage());
}

$pageTitle = 'Staff Dashboard';
$pageScripts = [
    APP_URL . '/assets/vendor/chartjs/chart.umd.js',
    APP_URL . '/assets/js/chart-theme.js',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="dash-heading">
    <div>
        <h2>Hi, <?= e(explode(' ', current_user_name())[0]) ?> 👋</h2>
        <div class="text-muted">
            Here's your inventory snapshot for <?= e(date('l, F j, Y')) ?>
            <?php if ($myBranch): ?> — <span class="fw-semibold"><i class="bi bi-geo-alt-fill"></i> <?= e($myBranch['branch_name']) ?></span><?php endif; ?>.
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="stock_in.php" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</a>
        <a href="stock_out.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-up me-1"></i> Stock Out</a>
    </div>
</div>

<!-- ================= STAT CARDS (staff subset only) ================= -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Products',        $invStats['total_products'],       'box-seam',             'bg-grad-indigo',  false],
        ['Low Stock',              $invStats['low_stock'],            'exclamation-triangle', 'bg-grad-amber',   $invStats['low_stock'] > 0],
        ['Expiring Products',      $invStats['expiring_soon'],        'hourglass-split',      'bg-grad-amber',   $invStats['expiring_soon'] > 0],
        ['Today\'s Transactions',  $invStats['today_transactions'],   'arrow-left-right',     'bg-grad-emerald', false],
    ];
    foreach ($cards as $c):
        [$label, $value, $icon, $bg, $attn] = $c;
    ?>
        <div class="col-6 col-lg-3">
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

<!-- ================= CHARTS (staff-relevant subset) ================= -->
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-pie-chart me-1"></i> Inventory Overview</span>
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

    <div class="col-lg-7">
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
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-calendar-x me-1"></i> Expiration Monitoring</span>
            </div>
            <div class="card-body">
                <div class="expiry-band band-expired">
                    <span><i class="bi bi-x-octagon-fill me-2"></i> Already Expired</span>
                    <span class="band-count"><?= (int) $expiryBands['expired'] ?></span>
                </div>
                <div class="expiry-band band-critical">
                    <span><i class="bi bi-exclamation-octagon-fill me-2"></i> Critical (&le; 7 days)</span>
                    <span class="band-count"><?= (int) $expiryBands['critical'] ?></span>
                </div>
                <div class="expiry-band band-warning">
                    <span><i class="bi bi-exclamation-triangle-fill me-2"></i> Warning (&le; 15 days)</span>
                    <span class="band-count"><?= (int) $expiryBands['warning'] ?></span>
                </div>
                <div class="expiry-band band-notice">
                    <span><i class="bi bi-info-circle-fill me-2"></i> Notice (&le; 30 days)</span>
                    <span class="band-count"><?= (int) $expiryBands['notice'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= NOTIFICATIONS ================= -->
    <div class="col-lg-7">
        <div class="card chart-card h-100">
            <div class="card-header">
                <span><i class="bi bi-bell me-1"></i> Notifications</span>
                <span class="subtitle"><?= count($notifications) ?> item(s)</span>
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

<!-- ================= MY RECENT ACTIVITY ================= -->
<div class="row g-3">
    <div class="col-12">
        <div class="card chart-card">
            <div class="card-header">
                <span><i class="bi bi-clock-history me-1"></i> My Recent Activity</span>
            </div>
            <div class="card-body activity-scroll">
                <?php if (empty($logs)): ?>
                    <p class="text-muted mb-0">No recent activity.</p>
                <?php else: ?>
                    <div class="activity-timeline">
                        <?php foreach ($logs as $l): ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <span class="text-muted"><?= e($l['activity']) ?></span>
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
    'stockInOut'     => $stockInOut,
];

$pageInlineScript = '
document.addEventListener("DOMContentLoaded", function () {
    const data = ' . json_encode($chartJsPayload, JSON_HEX_TAG | JSON_HEX_APOS) . ';
    const pal = chartTheme.palette();

    Chart.defaults.font.family = "system-ui, -apple-system, Segoe UI, Roboto, sans-serif";
    Chart.defaults.color = pal.label;

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
});
';

require __DIR__ . '/../includes/footer.php';
