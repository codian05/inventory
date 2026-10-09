<?php
declare(strict_types=1);

/**
 * stock_transfer_page.php
 * Shared by admin/stock_transfer.php and staff/stock_transfer.php.
 *
 * Staff: source branch is always their own session branch — never a
 * request parameter. They may pick any other active branch as the
 * destination. Admin: may pick both source and destination.
 *
 * FIFO is automatic on the source side (same as Stock Out); the
 * destination always receives a single fresh batch (same as Stock In).
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
$old = [
    'barcode' => '',
    'quantity' => '',
    'remarks' => '',
    'from_branch_id' => $isAdmin ? 0 : (int) $myBranchId,
    'to_branch_id' => 0,
];

if (isset($_GET['barcode']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $old['barcode'] = clean_input($_GET['barcode']);
    $product = scan_barcode_lookup($old['barcode'], $isAdmin ? null : $myBranchId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $barcode  = clean_input($_POST['barcode'] ?? '');
    $quantity = clean_input($_POST['quantity'] ?? '');
    $remarks  = clean_input($_POST['remarks'] ?? '');
    $postedFromBranch = (int) ($_POST['from_branch_id'] ?? 0);
    $toBranchId = (int) ($_POST['to_branch_id'] ?? 0);

    // Source branch: staff can NEVER change this via POST — the same
    // effective_branch_id() enforcement point used by Stock In/Out.
    // Destination is a free (but server-validated) choice for anyone.
    $fromBranchId = effective_branch_id($postedFromBranch > 0 ? $postedFromBranch : null);

    $old = compact('barcode', 'quantity', 'remarks');
    $old['from_branch_id'] = $fromBranchId ?? 0;
    $old['to_branch_id'] = $toBranchId;

    $product = scan_barcode_lookup($barcode, $fromBranchId);

    if (!$product) {
        $errors[] = 'No active product matches this barcode.';
    }
    if ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
        $errors[] = 'Quantity must be a whole number greater than zero.';
    }
    if ($fromBranchId === null || $fromBranchId <= 0) {
        $errors[] = $isAdmin ? 'Please select a source branch.' : 'No branch is assigned to your account. Contact an administrator.';
    }
    if ($toBranchId <= 0) {
        $errors[] = 'Please select a destination branch.';
    }
    if ($fromBranchId !== null && $fromBranchId > 0 && $fromBranchId === $toBranchId) {
        $errors[] = 'Source and destination branch must be different.';
    }

    if (!$errors) {
        $outcome = stock_transfer(
            (int) $product['product_id'],
            (int) $fromBranchId,
            $toBranchId,
            (int) $quantity,
            $remarks,
            current_user_id()
        );

        if ($outcome['success']) {
            set_flash('success', "Transfer #{$outcome['transfer_id']} recorded: {$quantity} {$product['unit']} — {$product['product_name']}.");
            header('Location: inventory.php?product_id=' . $product['product_id']);
            exit;
        }

        if (($outcome['error'] ?? '') === 'insufficient_stock') {
            $insufficientInfo = ['available' => $outcome['available'], 'requested' => $outcome['requested']];
            $errors[] = 'Insufficient available inventory in the source branch.';
        } else {
            $errors[] = $outcome['error'] ?? 'Transfer failed.';
        }
    }
}

$previewFromBranch = $isAdmin ? ($old['from_branch_id'] ?: null) : $myBranchId;
$fifoPreview = ($product && $previewFromBranch && $old['quantity'] !== '' && ctype_digit($old['quantity']))
    ? fifo_preview((int) $product['product_id'], (int) $previewFromBranch, (int) $old['quantity'])
    : [];

$fromBranchChoices = $isAdmin ? branch_options($old['from_branch_id'] ?: null) : [];
$toBranchChoices = branch_options($old['to_branch_id'] ?: null);
$myBranch = !$isAdmin && $myBranchId ? fetch_branch($myBranchId, true) : null;

$pageTitle = 'Stock Transfer';
require __DIR__ . '/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-arrow-left-right me-1"></i> Stock Transfer</div>
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

                <div class="mb-3">
                    <label class="form-label">Scan or Enter Barcode <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" id="barcodeScan" class="form-control font-monospace"
                               placeholder="Scan barcode…" value="<?= e($old['barcode']) ?>" autofocus>
                        <button class="btn btn-outline-primary" type="button" id="barcodeScanBtn">
                            <i class="bi bi-search"></i> Find
                        </button>
                    </div>
                    <div id="scanResult" class="form-text"></div>
                </div>

                <form method="POST" id="transferForm" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="barcode" id="barcodeHidden" value="<?= e($old['barcode']) ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Source Branch <span class="text-danger">*</span></label>
                            <?php if ($isAdmin): ?>
                                <select name="from_branch_id" id="fromBranchSelect" class="form-select" required>
                                    <option value="">Select branch…</option>
                                    <?php foreach ($fromBranchChoices as $b): ?>
                                        <option value="<?= (int) $b['branch_id'] ?>" <?= (int) $old['from_branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                            <?= e($b['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" value="<?= e($myBranch['branch_name'] ?? 'Your branch') ?>" disabled>
                                <input type="hidden" name="from_branch_id" value="<?= (int) $myBranchId ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Destination Branch <span class="text-danger">*</span></label>
                            <select name="to_branch_id" id="toBranchSelect" class="form-select" required>
                                <option value="">Select branch…</option>
                                <?php foreach ($toBranchChoices as $b): ?>
                                    <option value="<?= (int) $b['branch_id'] ?>" <?= (int) $old['to_branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                        <?= e($b['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="productPreview" class="<?= $product ? '' : 'd-none' ?> mb-3 p-3 rounded bg-body-secondary">
                        <strong><i class="bi bi-check-circle-fill text-success me-1"></i> Found:</strong>
                        <span id="productPreviewName"><?= $product ? e($product['product_name']) : '' ?></span>
                        &middot; Available at source: <span id="productPreviewStock"><?= $product ? (int) $product['current_stock'] : '' ?></span> <?= $product ? e($product['unit']) : '' ?>
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
                        <label class="form-label">Notes</label>
                        <textarea name="remarks" class="form-control" rows="2"
                                  placeholder="e.g. Rebalancing stock, branch request…"><?= e($old['remarks']) ?></textarea>
                    </div>

                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i> FIFO is automatic on the source side. The destination
                        branch receives a new batch with no expiration date carried over — the same as a fresh Stock In.
                    </div>

                    <?php if ($fifoPreview): ?>
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">This will draw from (source branch):</div>
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

                    <button class="btn btn-primary" type="submit" id="transferSubmit" disabled>
                        <i class="bi bi-save me-1"></i> Save Transfer
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
    const submitBtn = document.getElementById("transferSubmit");
    const fromBranchSelect = document.getElementById("fromBranchSelect");

    function lookup() {
        const v = scanInput.value.trim();
        hidden.value = v;
        if (v.length < 3) return;
        scanResult.textContent = "Looking up…";
        let url = "../ajax/scan_barcode.php?barcode=" + encodeURIComponent(v);
        if (isAdmin && fromBranchSelect && fromBranchSelect.value) {
            url += "&branch_id=" + encodeURIComponent(fromBranchSelect.value);
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
    if (fromBranchSelect) fromBranchSelect.addEventListener("change", () => { if (scanInput.value.trim().length >= 3) lookup(); });
    if (scanInput.value.trim().length >= 3) lookup();
});
JS;
require __DIR__ . '/footer.php';
