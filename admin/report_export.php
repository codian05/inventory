<?php
declare(strict_types=1);

/**
 * admin/report_export.php
 * Export endpoint for the Reports module.
 *
 * SECURITY:
 *  - require_admin(): same gate as the viewer and the Reports
 *    dashboard — Reports is an admin-only module app-wide.
 *  - Report id is resolved through report_get_definition(), which
 *    only ever returns one of the fixed entries in report_registry()
 *    or null. An unknown id is rejected before any query runs — this
 *    is the "registry callbacks resolve only to known functions"
 *    guarantee the export path shares with the viewer.
 *  - Filters go through report_parse_filters() — the exact same
 *    whitelist-and-clean step the viewer uses — and the query
 *    callback is the exact same function the viewer calls, so export
 *    can never see a different (or unfiltered) dataset than what the
 *    admin was just looking at. No duplicated query logic.
 *  - This is a read-only GET action (no state change), so it follows
 *    the same auth-only convention as other read routes in the app
 *    (e.g. batch_view.php) rather than the CSRF-token convention used
 *    for state-changing POST/action links.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$reportId = clean_input((string) ($_GET['report'] ?? ''));
$definition = report_get_definition($reportId);

if (!$definition) {
    http_response_code(404);
    die('Unknown report.');
}

$format = clean_input((string) ($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'excel'], true)) {
    http_response_code(400);
    die('Unsupported export format.');
}

$filters = report_parse_filters($_GET, $definition['filters']);

/** @var callable $queryFn */
$queryFn = $definition['query'];
$rows = $queryFn($filters);

log_activity(current_user_id(), 'Exported report: ' . $definition['title'] . ' (' . strtoupper($format) . ')');

if ($format === 'excel') {
    report_export_excel($definition, $rows);
} else {
    report_export_csv($definition, $rows);
}
