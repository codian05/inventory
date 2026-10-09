<?php
declare(strict_types=1);

/**
 * stock_out_page.php
 * Shared by admin/stock_out.php and staff/stock_out.php.
 *
 * FIFO is fully automatic here — there is no batch-selection control
 * anywhere on this page. stock_out() decides which batch(es) to draw
 * from (earliest expiration first, then oldest received).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$isAdmin = current_user_role() === 'admin';
$myBranchId = current_user_branch_id();

$errors = [];
$insufficientInfo = null;
$product = null;
$result = null;
$old = ['barcode' => '', 'quantity' => '', 'remarks' => '', 'branch_id' => $isAdmin ? 0 : (int) $myBranchId];

if (isset($_GET['barcode']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $old['barcode'] = clean_input($_GET['barcode']);
    $product = scan_barcode_lookup($old['barcode'], $isAdmin ? null : $myBranchId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $barcode  = clean_input($_POST['barcode'] ?? '');
    $quantity = clean_input($_POST['quantity'] ?? '');
    $remarks  = clean_input($_POST['remarks'] ?? '');
    // Staff: effective_branch_id() always returns their own session
    // branch, ignoring whatever was posted — this is the server-side
    // authorization point, not the hidden field's presence.
    $postedBranchId = (int) ($_POST['branch_id'] ?? 0);
    $branchId = effective_branch_id($postedBranchId > 0 ? $postedBranchId : null);

    $old = compact('barcode', 'quantity', 'remarks');
    $old['branch_id'] = $branchId ?? 0;
    $product = scan_barcode_lookup($barcode, $branchId);

    if (!$product) {
        $errors[] = 'No active product matches this barcode.';
    }
    if ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
        $errors[] = 'Quantity must be a whole number greater than zero.';
    }
    if ($remarks === '') {
        $errors[] = 'Remarks are required for every Stock Out.';
    }
    if ($branchId === null || $branchId <= 0) {
        $errors[] = $isAdmin ? 'Please select a branch.' : 'No branch is assigned to your account. Contact an administrator.';
    }

    if (!$errors) {
        $outcome = stock_out((int) $product['product_id'], (int) $branchId, (int) $quantity, $remarks, current_user_id());

        if ($outcome['success']) {
            $batchCount = count($outcome['batches']);
            set_flash('success', "Stock Out recorded: -{$quantity} {$product['unit']} — {$product['product_name']} (FIFO across {$batchCount} batch" . ($batchCount === 1 ? '' : 'es') . ").");
            header('Location: inventory.php?product_id=' . $product['product_id']);
            exit;
        }

        if (($outcome['error'] ?? '') === 'no_usable_stock') {
            $insufficientInfo = ['available' => $outcome['available'], 'requested' => $outcome['requested']];
            $errors[] = 'No usable inventory available. Remaining stock is expired.';
        } elseif (($outcome['error'] ?? '') === 'insufficient_stock') {
            $insufficientInfo = ['available' => $outcome['available'], 'requested' => $outcome['requested']];
            $errors[] = 'Insufficient available inventory.';
        } else {
            $errors[] = $outcome['error'] ?? 'Stock Out failed.';
        }
    }
}

$previewBranchId = $isAdmin ? ($old['branch_id'] ?: null) : $myBranchId;
$fifoPreview = ($product && $previewBranchId && $old['quantity'] !== '' && ctype_digit($old['quantity']))
    ? fifo_preview((int) $product['product_id'], (int) $previewBranchId, (int) $old['quantity'])
    : [];

$branchChoices = $isAdmin ? branch_options($old['branch_id'] ?: null) : [];
$myBranch = !$isAdmin && $myBranchId ? fetch_branch($myBranchId, true) : null;

$pageTitle = 'Stock Out';
require __DIR__ . '/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-box-arrow-up me-1"></i> Stock Out</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                        </ul>
                        <?php if ($insufficientInfo): ?>
                            <hr class="my-2">
                            <div class="d-flex gap-4">
                                <div><strong>Available:</strong> <?= (int) $insufficientInfo['available'] ?></div>
                                <div><strong>Requested:</strong> <?= (int) $insufficientInfo['requested'] ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$isAdmin): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-geo-alt-fill me-1"></i> Deducting stock from
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

                <form method="POST" id="stockOutForm" novalidate>
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
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="qtyInput" class="form-control" min="1" step="1"
                                   value="<?= e($old['quantity']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="2" required
                                  placeholder="e.g. Sold to walk-in customer, damaged during transfer…"><?= e($old['remarks']) ?></textarea>
                    </div>

                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i> FIFO is automatic — the system always deducts from the
                        oldest / earliest-expiring batch first, within this branch only. You never choose a batch manually.
                    </div>

                    <?php if ($fifoPreview): ?>
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">This will consume:</div>
                            <?php foreach ($fifoPreview as $p): ?>
                                <div class="d-flex justify-content-between border rounded px-3 py-2 mb-1 small">
                                    <span>Batch #<?= (int) $p['batch_id'] ?>
                                        <?php if ($p['expiration_date']): ?>
                                            <span class="text-muted">(exp. <?= e(format_date($p['expiration_date'], 'M j, Y')) ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="fw-semibold"><?= (int) $p['take'] ?> units</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-primary" type="submit" id="stockOutSubmit" disabled>
                        <i class="bi bi-save me-1"></i> Save Stock Out
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
    const submitBtn = document.getElementById("stockOutSubmit");
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
