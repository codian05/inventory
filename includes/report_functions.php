<?php
declare(strict_types=1);

/**
 * report_functions.php
 * Phase 7 — Reports module backend: registry, query/summary/chart
 * callbacks, filter parsing, and export helpers.
 *
 * ARCHITECTURE: mirrors the rest of the app — plain PDO prepared
 * statements, functions guarded with function_exists(), no new
 * framework. Required once from functions.php so any page that
 * already does `require functions.php` gets the whole reports layer
 * for free, same as every other helper group in this file.
 *
 * REGISTRY PATTERN: every report is described by one entry in
 * report_registry(). The viewer (report_view_page.php) and the
 * exporter (admin/report_export.php) both read that entry and drive
 * themselves off it — neither hardcodes per-report markup or SQL.
 * Callback names in the registry are fixed string literals written by
 * us, never built from user input, and are resolved only through
 * report_registry() — so a request can select a report by its (also
 * whitelisted) id, never by an arbitrary function name.
 *
 * DATA SCOPE NOTE: query callbacks return the FULL filtered result
 * set (not just one page). Summary cards and charts are computed from
 * that same full set so they always describe the whole filtered
 * report, not just whatever page the table happens to be showing.
 * report_view_page.php then slices the array in PHP for pagination.
 * This trades a bit of memory for correctness + one simple query per
 * report; for a dataset large enough that this stops being fine, the
 * per-report SQL would need real LIMIT/OFFSET + separate aggregate
 * queries — not needed here.
 *
 * VALUATION NOTE: `products` has no cost_price column (see
 * 5_phase5_products.sql) — only `selling_price`. The Inventory
 * Valuation report is therefore a valuation at selling price, not at
 * cost. This is stated in the report's own description so it's never
 * misread as a cost-basis figure.
 */

/* =====================================================================
 * REGISTRY
 * ===================================================================== */

if (!function_exists('report_registry')) {
    /**
     * @return array<string,array<string,mixed>> report_id => definition
     */
    function report_registry(): array
    {
        return [
            'inventory' => [
                'id' => 'inventory',
                'title' => 'Inventory Report',
                'description' => 'Current on-hand stock per product, computed from inventory_batches.',
                'icon' => 'bi-boxes',
                'color' => 'bg-grad-indigo',
                'filters' => ['branch_id', 'category_id', 'supplier_id', 'status'],
                'columns' => [
                    ['key' => 'barcode', 'label' => 'Barcode', 'type' => 'text'],
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'category_name', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text'],
                    ['key' => 'unit', 'label' => 'Unit', 'type' => 'text'],
                    ['key' => 'total_qty', 'label' => 'On Hand', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'batch_count', 'label' => 'Batches', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ],
                'query' => 'report_query_inventory',
                'summary' => 'report_summary_inventory',
                'charts' => ['report_chart_inventory_by_category'],
            ],
            'low_stock' => [
                'id' => 'low_stock',
                'title' => 'Low Stock Report',
                'description' => 'Products at or below the low-stock threshold (' . LOW_STOCK_THRESHOLD . ' units), non-expired stock only.',
                'icon' => 'bi-exclamation-triangle',
                'color' => 'bg-grad-amber',
                'filters' => ['branch_id', 'category_id', 'supplier_id'],
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'category_name', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text'],
                    ['key' => 'total_qty', 'label' => 'On Hand', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'threshold', 'label' => 'Threshold', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'deficit', 'label' => 'Deficit', 'type' => 'number', 'align' => 'end'],
                ],
                'query' => 'report_query_low_stock',
                'summary' => 'report_summary_low_stock',
                'charts' => ['report_chart_low_stock'],
            ],
            'expiration' => [
                'id' => 'expiration',
                'title' => 'Expiration Report',
                'description' => 'Active batches with an expiration date, bucketed by urgency.',
                'icon' => 'bi-calendar-x',
                'color' => 'bg-grad-rose',
                'filters' => ['branch_id', 'category_id', 'supplier_id', 'status', 'date_from', 'date_to'],
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'batch_id', 'label' => 'Batch #', 'type' => 'text'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text'],
                    ['key' => 'remaining_quantity', 'label' => 'Qty', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'expiration_date', 'label' => 'Expires', 'type' => 'date'],
                    ['key' => 'days_left', 'label' => 'Days Left', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'band', 'label' => 'Status', 'type' => 'status'],
                ],
                'query' => 'report_query_expiration',
                'summary' => 'report_summary_expiration',
                'charts' => ['report_chart_expiration_band'],
            ],
            'stock_movement' => [
                'id' => 'stock_movement',
                'title' => 'Stock Movement Report',
                'description' => 'Every stock transaction (in, out, adjustment) in the selected range.',
                'icon' => 'bi-arrow-left-right',
                'color' => 'bg-grad-sky',
                'filters' => ['branch_id', 'date_from', 'date_to', 'movement_type', 'product_id'],
                'columns' => [
                    ['key' => 'created_at', 'label' => 'Date', 'type' => 'datetime'],
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'type', 'label' => 'Type', 'type' => 'status'],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'batch_id', 'label' => 'Batch #', 'type' => 'text'],
                    ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'text'],
                    ['key' => 'created_by_name', 'label' => 'By', 'type' => 'text'],
                ],
                'query' => 'report_query_stock_movement',
                'summary' => 'report_summary_stock_movement',
                'charts' => ['report_chart_stock_movement_daily'],
            ],
            'supplier_delivery' => [
                'id' => 'supplier_delivery',
                'title' => 'Supplier Delivery Report',
                'description' => 'Batches received (Stock In) grouped by supplier.',
                'icon' => 'bi-truck',
                'color' => 'bg-grad-teal',
                'filters' => ['branch_id', 'date_from', 'date_to', 'supplier_id', 'category_id'],
                'columns' => [
                    ['key' => 'received_at', 'label' => 'Received', 'type' => 'datetime'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text'],
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'Qty Received', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'unit', 'label' => 'Unit', 'type' => 'text'],
                    ['key' => 'batch_id', 'label' => 'Batch #', 'type' => 'text'],
                ],
                'query' => 'report_query_supplier_delivery',
                'summary' => 'report_summary_supplier_delivery',
                'charts' => ['report_chart_supplier_delivery'],
            ],
            'product_movement' => [
                'id' => 'product_movement',
                'title' => 'Product Movement Report',
                'description' => 'Stock in/out totals per product for the selected range.',
                'icon' => 'bi-graph-up-arrow',
                'color' => 'bg-grad-violet',
                'filters' => ['branch_id', 'date_from', 'date_to', 'category_id', 'product_id'],
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'category_name', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'total_in', 'label' => 'Stock In', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'total_out', 'label' => 'Stock Out', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'net_change', 'label' => 'Net Change', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'transaction_count', 'label' => 'Transactions', 'type' => 'number', 'align' => 'end'],
                ],
                'query' => 'report_query_product_movement',
                'summary' => 'report_summary_product_movement',
                'charts' => ['report_chart_product_movement_top'],
            ],
            'inventory_valuation' => [
                'id' => 'inventory_valuation',
                'title' => 'Inventory Valuation Report',
                'description' => 'On-hand stock valued at selling price (no cost-price field exists on products, so this is a retail-value figure, not a cost basis).',
                'icon' => 'bi-cash-stack',
                'color' => 'bg-grad-emerald',
                'filters' => ['branch_id', 'category_id', 'supplier_id', 'status'],
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'category_name', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'total_qty', 'label' => 'On Hand', 'type' => 'number', 'align' => 'end'],
                    ['key' => 'selling_price', 'label' => 'Unit Price', 'type' => 'money', 'align' => 'end'],
                    ['key' => 'total_value', 'label' => 'Total Value', 'type' => 'money', 'align' => 'end'],
                ],
                'query' => 'report_query_inventory_valuation',
                'summary' => 'report_summary_inventory_valuation',
                'charts' => ['report_chart_valuation_by_category'],
            ],
            'user_activity' => [
                'id' => 'user_activity',
                'title' => 'User Activity Report',
                'description' => 'Login and system activity log per user.',
                'icon' => 'bi-person-lines-fill',
                'color' => 'bg-grad-slate',
                'filters' => ['branch_id', 'date_from', 'date_to', 'user_id', 'role'],
                'columns' => [
                    ['key' => 'created_at', 'label' => 'Date', 'type' => 'datetime'],
                    ['key' => 'fullname', 'label' => 'User', 'type' => 'text'],
                    ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                    ['key' => 'role', 'label' => 'Role', 'type' => 'status'],
                    ['key' => 'activity', 'label' => 'Activity', 'type' => 'text'],
                ],
                'query' => 'report_query_user_activity',
                'summary' => 'report_summary_user_activity',
                'charts' => ['report_chart_user_activity_daily'],
            ],
        ];
    }
}

if (!function_exists('report_get_definition')) {
    /**
     * Resolves a report id against the whitelist in report_registry().
     * Returns null for anything not in the registry — callers must
     * treat null as "unknown report" (404), never fall back to a
     * default report or attempt to guess a callback name.
     */
    function report_get_definition(string $reportId): ?array
    {
        $registry = report_registry();

        return $registry[$reportId] ?? null;
    }
}

/* =====================================================================
 * FILTER PARSING
 * ===================================================================== */

if (!function_exists('report_parse_filters')) {
    /**
     * Reads only the filter keys a given report declares in its
     * registry entry out of $_GET, cleaning/casting each one. Anything
     * not in $allowedKeys is ignored, so a report can never be handed
     * a filter it doesn't understand.
     */
    function report_parse_filters(array $get, array $allowedKeys): array
    {
        $filters = [];

        foreach ($allowedKeys as $key) {
            switch ($key) {
                case 'category_id':
                case 'supplier_id':
                case 'product_id':
                case 'user_id':
                case 'branch_id':
                    $val = (int) ($get[$key] ?? 0);
                    if ($val > 0) {
                        $filters[$key] = $val;
                    }
                    break;

                case 'date_from':
                case 'date_to':
                    $val = clean_input((string) ($get[$key] ?? ''));
                    if ($val !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                        $filters[$key] = $val;
                    }
                    break;

                case 'status':
                    $val = clean_input((string) ($get[$key] ?? ''));
                    if (in_array($val, ['active', 'inactive', 'expired', 'critical', 'warning', 'notice'], true)) {
                        $filters[$key] = $val;
                    }
                    break;

                case 'movement_type':
                    $val = clean_input((string) ($get[$key] ?? ''));
                    if (in_array($val, ['Stock In', 'Stock Out', 'Adjustment'], true)) {
                        $filters[$key] = $val;
                    }
                    break;

                case 'role':
                    $val = clean_input((string) ($get[$key] ?? ''));
                    if (in_array($val, ['admin', 'staff'], true)) {
                        $filters[$key] = $val;
                    }
                    break;
            }
        }

        return $filters;
    }
}

/* =====================================================================
 * OPTION LISTS (for filter dropdowns)
 * ===================================================================== */

if (!function_exists('product_options')) {
    function product_options(): array
    {
        $options = [];

        try {
            $options = db()->query(
                "SELECT product_id, product_name FROM products
                 WHERE deleted_at IS NULL ORDER BY product_name ASC"
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('product_options failed: ' . $e->getMessage());
        }

        return $options;
    }
}

if (!function_exists('user_options')) {
    function user_options(): array
    {
        $options = [];

        try {
            $options = db()->query(
                "SELECT id, fullname, username FROM users
                 WHERE deleted_at IS NULL ORDER BY fullname ASC"
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('user_options failed: ' . $e->getMessage());
        }

        return $options;
    }
}

/* =====================================================================
 * FORMATTING HELPERS
 * ===================================================================== */

if (!function_exists('format_money')) {
    function format_money(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }
}

/* =====================================================================
 * REPORT 1 — INVENTORY
 * ===================================================================== */

if (!function_exists('report_query_inventory')) {
    function report_query_inventory(array $filters): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'p.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        // The branch filter goes on the JOIN condition (not WHERE):
        // that's what lets a product with zero batches in the selected
        // branch still appear in the list with total_qty = 0, instead
        // of disappearing from the report entirely.
        $branchJoin = '';
        if (!empty($filters['branch_id'])) {
            $branchJoin = ' AND b.branch_id = ' . (int) $filters['branch_id'];
        }

        try {
            $stmt = db()->prepare(
                "SELECT p.product_id, p.barcode, p.product_name, c.category_name, s.supplier_name,
                        p.unit, p.status,
                        COALESCE(SUM(CASE WHEN b.remaining_quantity > 0
                                           AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                                      THEN b.remaining_quantity ELSE 0 END), 0) AS total_qty,
                        COUNT(DISTINCT CASE WHEN b.remaining_quantity > 0 THEN b.batch_id END) AS batch_count
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
                 LEFT JOIN inventory_batches b ON b.product_id = p.product_id{$branchJoin}
                 WHERE {$whereSql}
                 GROUP BY p.product_id, p.barcode, p.product_name, c.category_name, s.supplier_name, p.unit, p.status
                 ORDER BY p.product_name ASC"
            );
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('report_query_inventory failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_inventory')) {
    function report_summary_inventory(array $rows): array
    {
        $totalUnits = 0;
        $totalBatches = 0;
        $outOfStock = 0;

        foreach ($rows as $r) {
            $totalUnits += (int) $r['total_qty'];
            $totalBatches += (int) $r['batch_count'];
            if ((int) $r['total_qty'] === 0) {
                $outOfStock++;
            }
        }

        return [
            ['label' => 'Products', 'value' => count($rows), 'icon' => 'box-seam', 'color' => 'bg-grad-indigo'],
            ['label' => 'Total Units On Hand', 'value' => $totalUnits, 'icon' => 'boxes', 'color' => 'bg-grad-emerald'],
            ['label' => 'Active Batches', 'value' => $totalBatches, 'icon' => 'layers', 'color' => 'bg-grad-sky'],
            ['label' => 'Out of Stock', 'value' => $outOfStock, 'icon' => 'exclamation-circle', 'color' => 'bg-grad-rose'],
        ];
    }
}

if (!function_exists('report_chart_inventory_by_category')) {
    function report_chart_inventory_by_category(array $rows): array
    {
        $byCategory = [];
        foreach ($rows as $r) {
            $cat = $r['category_name'] ?: 'Uncategorized';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + (int) $r['total_qty'];
        }
        arsort($byCategory);
        $byCategory = array_slice($byCategory, 0, 10, true);

        return [
            'type' => 'bar',
            'title' => 'On-Hand Units by Category',
            'labels' => array_keys($byCategory),
            'datasets' => [['label' => 'Units', 'data' => array_values($byCategory)]],
        ];
    }
}

/* =====================================================================
 * REPORT 2 — LOW STOCK
 * ===================================================================== */

if (!function_exists('report_query_low_stock')) {
    function report_query_low_stock(array $filters): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'p.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }
        $whereSql = implode(' AND ', $where);

        $branchJoin = '';
        if (!empty($filters['branch_id'])) {
            $branchJoin = ' AND b.branch_id = ' . (int) $filters['branch_id'];
        }

        try {
            $stmt = db()->prepare(
                "SELECT p.product_id, p.product_name, c.category_name, s.supplier_name,
                        COALESCE(SUM(CASE WHEN b.remaining_quantity > 0
                                           AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                                      THEN b.remaining_quantity ELSE 0 END), 0) AS total_qty
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
                 LEFT JOIN inventory_batches b ON b.product_id = p.product_id{$branchJoin}
                 WHERE {$whereSql}
                 GROUP BY p.product_id, p.product_name, c.category_name, s.supplier_name
                 HAVING total_qty <= " . LOW_STOCK_THRESHOLD . "
                 ORDER BY total_qty ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$row) {
                $row['threshold'] = LOW_STOCK_THRESHOLD;
                $row['deficit'] = max(0, LOW_STOCK_THRESHOLD - (int) $row['total_qty']);
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('report_query_low_stock failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_low_stock')) {
    function report_summary_low_stock(array $rows): array
    {
        $totalDeficit = 0;
        $outOfStock = 0;
        foreach ($rows as $r) {
            $totalDeficit += (int) $r['deficit'];
            if ((int) $r['total_qty'] === 0) {
                $outOfStock++;
            }
        }

        return [
            ['label' => 'Low Stock Products', 'value' => count($rows), 'icon' => 'exclamation-triangle', 'color' => 'bg-grad-amber'],
            ['label' => 'Out of Stock', 'value' => $outOfStock, 'icon' => 'x-octagon', 'color' => 'bg-grad-rose'],
            ['label' => 'Total Deficit (units)', 'value' => $totalDeficit, 'icon' => 'dash-circle', 'color' => 'bg-grad-slate'],
        ];
    }
}

if (!function_exists('report_chart_low_stock')) {
    function report_chart_low_stock(array $rows): array
    {
        $slice = array_slice($rows, 0, 10);

        return [
            'type' => 'horizontalBar',
            'title' => 'Lowest Stock Products',
            'labels' => array_map(static fn($r) => $r['product_name'], $slice),
            'datasets' => [['label' => 'On Hand', 'data' => array_map(static fn($r) => (int) $r['total_qty'], $slice)]],
        ];
    }
}

/* =====================================================================
 * REPORT 3 — EXPIRATION
 * ===================================================================== */

if (!function_exists('report_query_expiration')) {
    function report_query_expiration(array $filters): array
    {
        $where = ['b.remaining_quantity > 0', 'b.expiration_date IS NOT NULL', 'p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'b.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'b.expiration_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'b.expiration_date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $whereSql = implode(' AND ', $where);

        try {
            $stmt = db()->prepare(
                "SELECT b.batch_id, p.product_name, s.supplier_name, b.remaining_quantity, b.expiration_date
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
                 WHERE {$whereSql}
                 ORDER BY b.expiration_date ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $today = new DateTimeImmutable('today');
            foreach ($rows as &$row) {
                $exp = new DateTimeImmutable((string) $row['expiration_date']);
                $days = (int) $today->diff($exp)->format('%r%a');
                $row['days_left'] = $days;

                if ($days < 0) {
                    $row['band'] = 'Expired';
                } elseif ($days <= EXPIRING_CRITICAL_DAYS) {
                    $row['band'] = 'Critical';
                } elseif ($days <= EXPIRING_WARNING_DAYS) {
                    $row['band'] = 'Warning';
                } elseif ($days <= EXPIRING_NOTICE_DAYS) {
                    $row['band'] = 'Notice';
                } else {
                    $row['band'] = 'OK';
                }
            }
            unset($row);

            if (!empty($filters['status'])) {
                $wanted = ucfirst($filters['status']);
                $rows = array_values(array_filter($rows, static fn($r) => $r['band'] === $wanted));
            }

            return $rows;
        } catch (Throwable $e) {
            error_log('report_query_expiration failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_expiration')) {
    function report_summary_expiration(array $rows): array
    {
        $expired = 0;
        $critical = 0;
        $qtyAtRisk = 0;

        foreach ($rows as $r) {
            if ($r['band'] === 'Expired') {
                $expired++;
                $qtyAtRisk += (int) $r['remaining_quantity'];
            } elseif ($r['band'] === 'Critical') {
                $critical++;
                $qtyAtRisk += (int) $r['remaining_quantity'];
            }
        }

        return [
            ['label' => 'Expired Batches', 'value' => $expired, 'icon' => 'x-octagon', 'color' => 'bg-grad-rose'],
            ['label' => 'Critical (≤' . EXPIRING_CRITICAL_DAYS . 'd)', 'value' => $critical, 'icon' => 'exclamation-triangle', 'color' => 'bg-grad-amber'],
            ['label' => 'Units at Risk', 'value' => $qtyAtRisk, 'icon' => 'box-seam', 'color' => 'bg-grad-slate'],
            ['label' => 'Batches Listed', 'value' => count($rows), 'icon' => 'layers', 'color' => 'bg-grad-indigo'],
        ];
    }
}

if (!function_exists('report_chart_expiration_band')) {
    function report_chart_expiration_band(array $rows): array
    {
        $bands = ['Expired' => 0, 'Critical' => 0, 'Warning' => 0, 'Notice' => 0, 'OK' => 0];
        foreach ($rows as $r) {
            $bands[$r['band']] = ($bands[$r['band']] ?? 0) + 1;
        }

        return [
            'type' => 'doughnut',
            'title' => 'Batches by Urgency',
            'labels' => array_keys($bands),
            'datasets' => [['label' => 'Batches', 'data' => array_values($bands)]],
        ];
    }
}

/* =====================================================================
 * REPORT 4 — STOCK MOVEMENT
 * ===================================================================== */

if (!function_exists('report_query_stock_movement')) {
    function report_query_stock_movement(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(t.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(t.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['movement_type'])) {
            $where[] = 't.type = ?';
            $params[] = $filters['movement_type'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 't.product_id = ?';
            $params[] = $filters['product_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 't.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $whereSql = implode(' AND ', $where);

        try {
            $stmt = db()->prepare(
                "SELECT t.transaction_id, t.created_at, p.product_name, t.type, t.quantity,
                        t.batch_id, t.remarks, u.fullname AS created_by_name, br.branch_name
                 FROM stock_transactions t
                 JOIN products p ON p.product_id = t.product_id
                 LEFT JOIN users u ON u.id = t.created_by
                 LEFT JOIN branches br ON br.branch_id = t.branch_id
                 WHERE {$whereSql}
                 ORDER BY t.created_at DESC"
            );
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('report_query_stock_movement failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_stock_movement')) {
    function report_summary_stock_movement(array $rows): array
    {
        $in = 0;
        $out = 0;
        $adj = 0;
        foreach ($rows as $r) {
            $qty = (int) $r['quantity'];
            if ($r['type'] === 'Stock In') {
                $in += $qty;
            } elseif ($r['type'] === 'Stock Out') {
                $out += $qty;
            } else {
                $adj += $qty;
            }
        }

        return [
            ['label' => 'Transactions', 'value' => count($rows), 'icon' => 'arrow-left-right', 'color' => 'bg-grad-sky'],
            ['label' => 'Stock In', 'value' => $in, 'icon' => 'box-arrow-in-down', 'color' => 'bg-grad-emerald'],
            ['label' => 'Stock Out', 'value' => $out, 'icon' => 'box-arrow-up', 'color' => 'bg-grad-rose'],
            ['label' => 'Adjustments', 'value' => ($adj >= 0 ? '+' : '') . $adj, 'icon' => 'sliders', 'color' => 'bg-grad-amber'],
            ['label' => 'Net Change', 'value' => $in - $out + $adj, 'icon' => 'graph-up', 'color' => 'bg-grad-indigo'],
        ];
    }
}

if (!function_exists('report_chart_stock_movement_daily')) {
    function report_chart_stock_movement_daily(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $day = substr((string) $r['created_at'], 0, 10);
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['in' => 0, 'out' => 0];
            }
            if ($r['type'] === 'Stock In') {
                $byDay[$day]['in'] += (int) $r['quantity'];
            } elseif ($r['type'] === 'Stock Out') {
                $byDay[$day]['out'] += (int) $r['quantity'];
            }
        }
        ksort($byDay);
        $byDay = array_slice($byDay, -30, null, true);

        return [
            'type' => 'bar',
            'title' => 'Stock In vs Out by Day',
            'labels' => array_keys($byDay),
            'datasets' => [
                ['label' => 'Stock In', 'data' => array_map(static fn($d) => $d['in'], $byDay)],
                ['label' => 'Stock Out', 'data' => array_map(static fn($d) => $d['out'], $byDay)],
            ],
        ];
    }
}

/* =====================================================================
 * REPORT 5 — SUPPLIER DELIVERY
 * ===================================================================== */

if (!function_exists('report_query_supplier_delivery')) {
    function report_query_supplier_delivery(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(b.received_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(b.received_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'b.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $whereSql = implode(' AND ', $where);

        try {
            $stmt = db()->prepare(
                "SELECT b.batch_id, b.received_at, s.supplier_name, p.product_name, b.quantity, p.unit, br.branch_name
                 FROM inventory_batches b
                 JOIN products p ON p.product_id = b.product_id
                 LEFT JOIN suppliers s ON s.supplier_id = b.supplier_id
                 LEFT JOIN branches br ON br.branch_id = b.branch_id
                 WHERE {$whereSql}
                 ORDER BY b.received_at DESC"
            );
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('report_query_supplier_delivery failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_supplier_delivery')) {
    function report_summary_supplier_delivery(array $rows): array
    {
        $totalQty = 0;
        $suppliers = [];
        foreach ($rows as $r) {
            $totalQty += (int) $r['quantity'];
            $suppliers[$r['supplier_name'] ?: '—'] = true;
        }

        return [
            ['label' => 'Deliveries', 'value' => count($rows), 'icon' => 'truck', 'color' => 'bg-grad-teal'],
            ['label' => 'Units Received', 'value' => $totalQty, 'icon' => 'box-seam', 'color' => 'bg-grad-emerald'],
            ['label' => 'Suppliers', 'value' => count($suppliers), 'icon' => 'building', 'color' => 'bg-grad-indigo'],
        ];
    }
}

if (!function_exists('report_chart_supplier_delivery')) {
    function report_chart_supplier_delivery(array $rows): array
    {
        $bySupplier = [];
        foreach ($rows as $r) {
            $name = $r['supplier_name'] ?: 'Unknown';
            $bySupplier[$name] = ($bySupplier[$name] ?? 0) + (int) $r['quantity'];
        }
        arsort($bySupplier);
        $bySupplier = array_slice($bySupplier, 0, 10, true);

        return [
            'type' => 'bar',
            'title' => 'Units Received by Supplier',
            'labels' => array_keys($bySupplier),
            'datasets' => [['label' => 'Units', 'data' => array_values($bySupplier)]],
        ];
    }
}

/* =====================================================================
 * REPORT 6 — PRODUCT MOVEMENT
 * ===================================================================== */

if (!function_exists('report_query_product_movement')) {
    function report_query_product_movement(array $filters): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        $joinCond = '1=1';
        $joinParams = [];
        if (!empty($filters['date_from'])) {
            $joinCond .= ' AND DATE(t.created_at) >= ?';
            $joinParams[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $joinCond .= ' AND DATE(t.created_at) <= ?';
            $joinParams[] = $filters['date_to'];
        }
        if (!empty($filters['branch_id'])) {
            $joinCond .= ' AND t.branch_id = ?';
            $joinParams[] = $filters['branch_id'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 'p.product_id = ?';
            $params[] = $filters['product_id'];
        }
        $whereSql = implode(' AND ', $where);

        // Join condition params must precede WHERE params positionally
        // since the JOIN clause is written before WHERE in the SQL.
        $allParams = array_merge($joinParams, $params);

        try {
            $stmt = db()->prepare(
                "SELECT p.product_id, p.product_name, c.category_name,
                        COALESCE(SUM(CASE WHEN t.type = 'Stock In' THEN t.quantity ELSE 0 END), 0) AS total_in,
                        COALESCE(SUM(CASE WHEN t.type = 'Stock Out' THEN t.quantity ELSE 0 END), 0) AS total_out,
                        COUNT(t.transaction_id) AS transaction_count
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN stock_transactions t ON t.product_id = p.product_id AND {$joinCond}
                 WHERE {$whereSql}
                 GROUP BY p.product_id, p.product_name, c.category_name
                 HAVING transaction_count > 0
                 ORDER BY (total_in + total_out) DESC"
            );
            $stmt->execute($allParams);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$row) {
                $row['net_change'] = (int) $row['total_in'] - (int) $row['total_out'];
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('report_query_product_movement failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_product_movement')) {
    function report_summary_product_movement(array $rows): array
    {
        $totalIn = 0;
        $totalOut = 0;
        foreach ($rows as $r) {
            $totalIn += (int) $r['total_in'];
            $totalOut += (int) $r['total_out'];
        }

        return [
            ['label' => 'Products Moved', 'value' => count($rows), 'icon' => 'box-seam', 'color' => 'bg-grad-violet'],
            ['label' => 'Total Stock In', 'value' => $totalIn, 'icon' => 'box-arrow-in-down', 'color' => 'bg-grad-emerald'],
            ['label' => 'Total Stock Out', 'value' => $totalOut, 'icon' => 'box-arrow-up', 'color' => 'bg-grad-rose'],
            ['label' => 'Net Change', 'value' => $totalIn - $totalOut, 'icon' => 'graph-up-arrow', 'color' => 'bg-grad-indigo'],
        ];
    }
}

if (!function_exists('report_chart_product_movement_top')) {
    function report_chart_product_movement_top(array $rows): array
    {
        $slice = array_slice($rows, 0, 8);

        return [
            'type' => 'bar',
            'title' => 'Top Products by Movement Volume',
            'labels' => array_map(static fn($r) => $r['product_name'], $slice),
            'datasets' => [
                ['label' => 'Stock In', 'data' => array_map(static fn($r) => (int) $r['total_in'], $slice)],
                ['label' => 'Stock Out', 'data' => array_map(static fn($r) => (int) $r['total_out'], $slice)],
            ],
        ];
    }
}

/* =====================================================================
 * REPORT 7 — INVENTORY VALUATION
 * ===================================================================== */

if (!function_exists('report_query_inventory_valuation')) {
    function report_query_inventory_valuation(array $filters): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'p.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $branchJoin = '';
        if (!empty($filters['branch_id'])) {
            $branchJoin = ' AND b.branch_id = ' . (int) $filters['branch_id'];
        }

        try {
            $stmt = db()->prepare(
                "SELECT p.product_id, p.product_name, c.category_name, p.selling_price,
                        COALESCE(SUM(CASE WHEN b.remaining_quantity > 0
                                           AND (b.expiration_date IS NULL OR b.expiration_date >= CURDATE())
                                      THEN b.remaining_quantity ELSE 0 END), 0) AS total_qty
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN inventory_batches b ON b.product_id = p.product_id{$branchJoin}
                 WHERE {$whereSql}
                 GROUP BY p.product_id, p.product_name, c.category_name, p.selling_price
                 HAVING total_qty > 0
                 ORDER BY total_qty * p.selling_price DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$row) {
                $row['total_value'] = round((int) $row['total_qty'] * (float) $row['selling_price'], 2);
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('report_query_inventory_valuation failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_inventory_valuation')) {
    function report_summary_inventory_valuation(array $rows): array
    {
        $totalValue = 0.0;
        $totalQty = 0;
        foreach ($rows as $r) {
            $totalValue += (float) $r['total_value'];
            $totalQty += (int) $r['total_qty'];
        }

        return [
            ['label' => 'Products Valued', 'value' => count($rows), 'icon' => 'box-seam', 'color' => 'bg-grad-emerald'],
            ['label' => 'Total Units', 'value' => $totalQty, 'icon' => 'boxes', 'color' => 'bg-grad-indigo'],
            ['label' => 'Total Value', 'value' => format_money($totalValue), 'icon' => 'cash-stack', 'color' => 'bg-grad-teal', 'raw' => false],
        ];
    }
}

if (!function_exists('report_chart_valuation_by_category')) {
    function report_chart_valuation_by_category(array $rows): array
    {
        $byCategory = [];
        foreach ($rows as $r) {
            $cat = $r['category_name'] ?: 'Uncategorized';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + (float) $r['total_value'];
        }
        arsort($byCategory);
        $byCategory = array_slice($byCategory, 0, 10, true);

        return [
            'type' => 'pie',
            'title' => 'Value by Category',
            'labels' => array_keys($byCategory),
            'datasets' => [['label' => 'Value', 'data' => array_map(static fn($v) => round($v, 2), array_values($byCategory))]],
        ];
    }
}

/* =====================================================================
 * REPORT 8 — USER ACTIVITY
 * ===================================================================== */

if (!function_exists('report_query_user_activity')) {
    function report_query_user_activity(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['role'])) {
            $where[] = 'u.role = ?';
            $params[] = $filters['role'];
        }
        // activity_logs has no branch_id (it's a system-wide audit
        // trail, not an inventory table) — when a branch filter is
        // applied, narrow to that branch's staff members instead,
        // same approach as the admin dashboard's Recent Activity panel.
        if (!empty($filters['branch_id'])) {
            $where[] = 'u.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $whereSql = implode(' AND ', $where);

        try {
            $stmt = db()->prepare(
                "SELECT a.log_id, a.created_at, a.activity, u.fullname, u.username, u.role
                 FROM activity_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 WHERE {$whereSql}
                 ORDER BY a.created_at DESC"
            );
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('report_query_user_activity failed: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('report_summary_user_activity')) {
    function report_summary_user_activity(array $rows): array
    {
        $users = [];
        $logins = 0;
        foreach ($rows as $r) {
            if (!empty($r['username'])) {
                $users[$r['username']] = true;
            }
            if (stripos((string) $r['activity'], 'logged in') === 0) {
                $logins++;
            }
        }

        return [
            ['label' => 'Activities Logged', 'value' => count($rows), 'icon' => 'journal-text', 'color' => 'bg-grad-slate'],
            ['label' => 'Active Users', 'value' => count($users), 'icon' => 'people', 'color' => 'bg-grad-indigo'],
            ['label' => 'Logins', 'value' => $logins, 'icon' => 'box-arrow-in-right', 'color' => 'bg-grad-emerald'],
        ];
    }
}

if (!function_exists('report_chart_user_activity_daily')) {
    function report_chart_user_activity_daily(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $day = substr((string) $r['created_at'], 0, 10);
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }
        ksort($byDay);
        $byDay = array_slice($byDay, -30, null, true);

        return [
            'type' => 'line',
            'title' => 'Activity by Day',
            'labels' => array_keys($byDay),
            'datasets' => [['label' => 'Activities', 'data' => array_values($byDay)]],
        ];
    }
}

/* =====================================================================
 * EXPORT ENGINE
 * ===================================================================== */

if (!function_exists('report_csv_safe_text')) {
    /**
     * Neutralizes CSV/formula injection: if free text starts with a
     * character a spreadsheet app treats as a formula trigger
     * (=, +, -, @), prefix it with a single quote so it renders as
     * plain text instead of being evaluated. Only applies to the
     * free-text export path — money/number/date values below are
     * already formatted by us and can't carry arbitrary DB content.
     */
    function report_csv_safe_text(string $val): string
    {
        if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
            return "'" . $val;
        }

        return $val;
    }
}

if (!function_exists('report_export_value')) {
    /**
     * Renders one cell value as plain text for export, following the
     * column's declared `type` — same rules the on-screen table uses,
     * minus HTML/badges.
     */
    function report_export_value(array $column, array $row): string
    {
        $key = $column['key'];
        $val = $row[$key] ?? '';

        switch ($column['type'] ?? 'text') {
            case 'money':
                return number_format((float) $val, 2);
            case 'date':
                return $val ? format_date((string) $val, 'Y-m-d') : '';
            case 'datetime':
                return $val ? format_date((string) $val, 'Y-m-d H:i:s') : '';
            case 'number':
                return (string) (int) $val;
            default:
                return report_csv_safe_text((string) $val);
        }
    }
}

if (!function_exists('report_export_csv')) {
    /**
     * Streams the report as CSV directly to the response. Caller must
     * have already checked authorization and validated the report id.
     */
    function report_export_csv(array $definition, array $rows): void
    {
        $filename = $definition['id'] . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel on Windows doesn't mangle non-ASCII text.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array_map(static fn($c) => $c['label'], $definition['columns']));

        foreach ($rows as $row) {
            $line = [];
            foreach ($definition['columns'] as $col) {
                $line[] = report_export_value($col, $row);
            }
            fputcsv($out, $line);
        }

        fclose($out);
    }
}

if (!function_exists('report_export_excel')) {
    /**
     * Excel-compatible export without any external library: an HTML
     * table served with an .xls filename and the Excel MIME type.
     * Excel opens this natively (it's the same trick phpMyAdmin and
     * countless legacy apps use for a dependency-free "Export to
     * Excel" button) — no PhpSpreadsheet/xlsxwriter install required.
     */
    function report_export_excel(array $definition, array $rows): void
    {
        $filename = $definition['id'] . '_' . date('Ymd_His') . '.xls';

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<table border="1"><thead><tr>';
        foreach ($definition['columns'] as $col) {
            echo '<th>' . e($col['label']) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($definition['columns'] as $col) {
                echo '<td>' . e(report_export_value($col, $row)) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
    }
}
