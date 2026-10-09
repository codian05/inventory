<?php
declare(strict_types=1);

/**
 * scan_barcode.php (AJAX)
 * Used by Stock In / Stock Out pages: scanning/typing a barcode looks
 * up the matching active product plus its current total stock.
 * Available to both roles — Stock In/Out are staff responsibilities
 * too.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$barcode = trim((string) ($_GET['barcode'] ?? ''));

// Staff: effective_branch_id() ignores this GET value entirely and
// always returns the staff member's own session branch_id. Admin: may
// preview stock for whichever branch is selected on the Stock In/Out
// form (falls back to null = combined total across all branches if
// none chosen yet).
$requestedBranch = (int) ($_GET['branch_id'] ?? 0);
$branchId = effective_branch_id($requestedBranch > 0 ? $requestedBranch : null);

if ($barcode === '') {
    json_response(['success' => false, 'found' => false, 'message' => 'Barcode is required.']);
}

$product = scan_barcode_lookup($barcode, $branchId);

if (!$product) {
    json_response(['success' => true, 'found' => false, 'message' => 'Product not found.']);
}

json_response([
    'success' => true,
    'found' => true,
    'product' => [
        'id' => (int) $product['product_id'],
        'name' => $product['product_name'],
        'unit' => $product['unit'],
        'current_stock' => (int) $product['current_stock'],
        'branch_scoped' => $branchId !== null,
    ],
]);
