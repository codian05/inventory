<?php
declare(strict_types=1);

/**
 * stock_in_page.php
 * Shared by admin/stock_in.php and staff/stock_in.php — both roles
 * can receive stock (per Phase 6 spec's Staff module list).
 *
 * Workflow: scan/type a barcode -> AJAX lookup finds the product ->
 * fill quantity/expiration/supplier/remarks -> submit -> stock_in()
 * creates one new batch + one stock_transactions row, atomically.
 * Previous batches are never touched by this page.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';

// Staff: always their own branch (never a request parameter). Admin:
// must actively pick an active branch — there is no sensible "All
// Branches" default for a WRITE operation, so the form starts
// unselected and stock_in() itself re-validates whatever is posted.
$myBranchId = current_user_branch_id();

$errors = [];
$product = null;
$old = ['barcode' => '', 'quantity' => '', 'expiration_date' => '', 'supplier_id' => 0, 'remarks' => '', 'branch_id' => $isAdmin ? 0 : (int) $myBranchId];

// Pre-fill from a "Stock In this product" link elsewhere in the app.
if (isset($_GET['barcode']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $old['barcode'] = clean_input($_GET['barcode']);
    $product = scan_barcode_lookup($old['barcode'], $isAdmin ? null : $myBranchId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $barcode        = clean_input($_POST['barcode'] ?? '');
    $quantity       = clean_input($_POST['quantity'] ?? '');
    $expirationDate = clean_input($_POST['expiration_date'] ?? '');
    $supplierId     = (int) ($_POST['supplier_id'] ?? 0);
    $remarks        = clean_input($_POST['remarks'] ?? '');
    // effective_branch_id() ignores the posted value entirely for
    // staff and substitutes their session branch — this is the only
    // place branch authorization for this write is decided.
    $postedBranchId = (int) ($_POST['branch_id'] ?? 0);
    $branchId       = effective_branch_id($postedBranchId > 0 ? $postedBranchId : null);

    // NOTE: compact() matches by variable NAME, not by array key —
    // it was previously called as compact('expiration_date',
    // 'supplier_id') but the actual PHP variables are camelCase
    // ($expirationDate, $supplierId), so compact() silently failed to
    // find them (PHP warning) and $old['expiration_date'] /
    // $old['supplier_id'] were never populated. Built explicitly here
    // instead so the array keys (used by the form's old-value echoes
    // and supplier_options()/branch_options() below) always exist.
    $old = [
        'barcode' => $barcode,
        'quantity' => $quantity,
        'expiration_date' => $expirationDate,
        'supplier_id' => $supplierId,
        'remarks' => $remarks,
    ];
    $old['branch_id'] = $branchId ?? 0;

    $product = scan_barcode_lookup($barcode, $branchId);

    if (!$product) {
        $errors[] = 'No active product matches this barcode.';
    }
    if ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
        $errors[] = 'Quantity must be a whole number greater than zero.';
    }
    if ($expirationDate !== '' && !strtotime($expirationDate)) {
        $errors[] = 'Expiration date is not valid.';
    }
    if ($supplierId <= 0) {
        $errors[] = 'Please select a supplier.';
    }
    if ($remarks === '') {
        $errors[] = 'Remarks are required for every Stock In.';
    }
    if ($branchId === null || $branchId <= 0) {
        $errors[] = $isAdmin ? 'Please select a branch.' : 'No branch is assigned to your account. Contact an administrator.';
    }

    if (!$errors) {
        $result = stock_in(
            (int) $product['product_id'],
            (int) $branchId,
            $supplierId,
            (int) $quantity,
            $expirationDate !== '' ? $expirationDate : null,
            $remarks,
            current_user_id()
        );

        if ($result['success']) {
            set_flash('success', "Stock In recorded: +{$quantity} {$product['unit']} — {$product['product_name']} (Batch #{$result['batch_id']}).");
            header('Location: inventory.php?product_id=' . $product['product_id']);
            exit;
        }

        $errors[] = $result['error'] ?? 'Stock In failed.';
    }
}

$supplierChoices = supplier_options($old['supplier_id'] ?: null);
$branchChoices = $isAdmin ? branch_options($old['branch_id'] ?: null) : [];
$myBranch = !$isAdmin && $myBranchId ? fetch_branch($myBranchId, true) : null;

$pageTitle = 'Stock In';
require __DIR__ . '/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>

                <?php if (!$isAdmin): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-geo-alt-fill me-1"></i> Receiving stock into
                        <strong><?= e($myBranch['branch_name'] ?? 'your assigned branch') ?></strong>.
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Scan or Enter Barcode <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" id="barcodeScan" class="form-control font-monospace"
                               placeholder="Scan barcode…" value="<?= e($old['barcode']) ?>" autofocus>
                        <button class="btn btn-outline-primary" type="button" id="barcodeScanBtn">
                            <i class="bi bi-search"></i> Find
                        </button>
                        <button class="btn btn-outline-secondary d-lg-none" type="button" id="cameraScanBtn"
                                aria-label="Scan with camera (coming soon)" title="Camera scanning coming soon">
                            <i class="bi bi-camera" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="scanResult" class="form-text"></div>
                </div>

                <form method="POST" id="stockInForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="barcode" id="barcodeHidden" value="<?= e($old['barcode']) ?>">

                    <?php if ($isAdmin): ?>
                        <div class="mb-3">
                            <label class="form-label">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" id="branchSelect" class="form-select" required>
                                <option value="">Select branch…</option>
                                <?php foreach ($branchChoices as $b): ?>
                                    <option value="<?= (int) $b['branch_id'] ?>" <?= (int) $old['branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                        <?= e($b['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?= (int) $myBranchId ?>">
                    <?php endif; ?>

                    <div id="productPreview" class="<?= $product ? '' : 'd-none' ?> mb-3 p-3 rounded bg-body-secondary">
                        <strong><i class="bi bi-check-circle-fill text-success me-1"></i> Found:</strong>
                        <span id="productPreviewName"><?= $product ? e($product['product_name']) : '' ?></span>
                        &middot; Current stock<?= $isAdmin ? ' (selected branch)' : '' ?>: <span id="productPreviewStock"><?= $product ? (int) $product['current_stock'] : '' ?></span> <?= $product ? e($product['unit']) : '' ?>
                    </div>

                    <div id="notFoundNotice" class="alert alert-warning d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i> No active product matches that barcode.
                        <?php if (current_user_role() === 'admin'): ?>
                            <a href="product_create.php" class="alert-link">Create a new product</a> first, then come back here.
                        <?php else: ?>
                            Ask an administrator to add this product first.
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="1" step="1"
                                   value="<?= e($old['quantity']) ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expiration Date</label>
                            <input type="date" name="expiration_date" class="form-control"
                                   value="<?= e($old['expiration_date']) ?>">
                            <div class="form-text">Leave blank if this product doesn't expire.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select supplier…</option>
                                <?php foreach ($supplierChoices as $sup): ?>
                                    <option value="<?= (int) $sup['supplier_id'] ?>" <?= (int) $old['supplier_id'] === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                                        <?= e($sup['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="2" required
                                  placeholder="e.g. Delivery receipt #, PO number…"><?= e($old['remarks']) ?></textarea>
                    </div>

                    <button class="btn btn-primary" type="submit" id="stockInSubmit" disabled>
                        <i class="bi bi-save me-1"></i> Save Stock In
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = [];
$isAdminJs = $isAdmin ? 'true' : 'false';
$pageInlineScript = <<<JS
document.addEventListener("DOMContentLoaded", function () {
    const isAdmin = {$isAdminJs};
    const scanInput = document.getElementById("barcodeScan");
    const scanBtn = document.getElementById("barcodeScanBtn");
    const scanResult = document.getElementById("scanResult");
    const hidden = document.getElementById("barcodeHidden");
    const preview = document.getElementById("productPreview");
    const previewName = document.getElementById("productPreviewName");
    const previewStock = document.getElementById("productPreviewStock");
    const notFound = document.getElementById("notFoundNotice");
    const submitBtn = document.getElementById("stockInSubmit");
    const branchSelect = document.getElementById("branchSelect");

    function lookup() {
        const v = scanInput.value.trim();
        hidden.value = v;
        if (v.length < 3) return;
        scanResult.textContent = "Looking up…";
        let url = "../ajax/scan_barcode.php?barcode=" + encodeURIComponent(v);
        if (isAdmin && branchSelect && branchSelect.value) {
            url += "&branch_id=" + encodeURIComponent(branchSelect.value);
        }
        fetch(url)
            .then(r => r.json())
            .then(d => {
                if (d.found) {
                    previewName.textContent = d.product.name;
                    previewStock.textContent = d.product.current_stock;
                    preview.classList.remove("d-none");
                    notFound.classList.add("d-none");
                    scanResult.textContent = "";
                    submitBtn.disabled = false;
                } else {
                    preview.classList.add("d-none");
                    notFound.classList.remove("d-none");
                    scanResult.textContent = "";
                    submitBtn.disabled = true;
                }
            })
            .catch(() => { scanResult.textContent = "Lookup failed."; });
    }

    scanBtn.addEventListener("click", lookup);
    scanInput.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); lookup(); } });
    if (branchSelect) branchSelect.addEventListener("change", () => { if (scanInput.value.trim().length >= 3) lookup(); });
    if (scanInput.value.trim().length >= 3) lookup();

    const cameraBtn = document.getElementById("cameraScanBtn");
    if (cameraBtn) {
        cameraBtn.addEventListener("click", () => {
            if (typeof showToast === "function") {
                showToast("Camera barcode scanning is coming soon — enter the barcode manually for now.", "secondary");
            }
            scanInput.focus();
        });
    }
});
JS;
require __DIR__ . '/footer.php';
