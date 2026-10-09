<?php
declare(strict_types=1);

/**
 * reports_dashboard_page.php
 * Shared by admin/reports.php (Reports module is admin-only — see
 * sidebar.php and the require_admin() call below; several of these
 * reports, e.g. User Activity and Supplier Delivery, surface data
 * staff shouldn't see).
 *
 * Pure registry rendering — this file has no per-report logic. Add a
 * report by adding an entry to report_registry() in
 * includes/report_functions.php; nothing here needs to change.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_admin();

$reports = report_registry();

$pageTitle = 'Reports';
require __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="<?= e($appUrl) ?>/assets/css/reports.css">

<div class="dash-heading">
    <div>
        <h2>Reports</h2>
        <div class="text-muted">Choose a report to view, filter, chart, and export.</div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($reports as $report): ?>
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <a href="report_view.php?report=<?= e($report['id']) ?>" class="text-decoration-none">
                <div class="glass-card report-tile fade-in-item h-100">
                    <div class="icon-badge <?= e($report['color']) ?>">
                        <i class="bi <?= e($report['icon']) ?>"></i>
                    </div>
                    <div>
                        <div class="report-tile-title"><?= e($report['title']) ?></div>
                        <div class="report-tile-desc"><?= e($report['description']) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
