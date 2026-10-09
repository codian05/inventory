<?php
declare(strict_types=1);

/**
 * check_barcode.php (AJAX)
 * Live barcode check for product create/edit forms. Admin only.
 *
 * Unlike the name-uniqueness checks (categories/suppliers/users),
 * this is a genuine "barcode lookup" — if the barcode already belongs
 * to a product, the response includes that product's id/name/status so
 * the form can show "Found: <product>" and link straight to it,
 * satisfying the "automatically recognize the existing product"
 * requirement rather than just saying "taken".
 *
 * GET params:
 *   barcode - the scanned/typed barcode
 *   id      - (optional) current product id to exclude (edit form)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$barcode = trim((string) ($_GET['barcode'] ?? ''));
$exclude = (int) ($_GET['id'] ?? 0);

if ($barcode === '') {
    json_response(['success' => false, 'message' => 'Barcode is required.']);
}
if (!validate_barcode($barcode)) {
    json_response(['success' => false, 'message' => 'Barcode may only contain letters, numbers, and hyphens (3–100 characters).']);
}

$match = fetch_product_by_barcode($barcode, true);

if ($match && ($exclude === 0 || (int) $match['product_id'] !== $exclude)) {
    json_response([
        'success'   => true,
        'available' => false,
        'found'     => true,
        'product'   => [
            'id'      => (int) $match['product_id'],
            'name'    => $match['product_name'],
            'deleted' => $match['deleted_at'] !== null,
        ],
        'message' => $match['deleted_at']
            ? 'This barcode belongs to a deleted product: ' . $match['product_name']
            : 'This barcode already belongs to: ' . $match['product_name'],
    ]);
}

json_response([
    'success'   => true,
    'available' => true,
    'found'     => false,
    'message'   => 'Barcode is available.',
]);
