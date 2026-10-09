<?php
declare(strict_types=1);

/**
 * inventory_adjust.php
 * Admin only. Manual correction against ONE batch's remaining_quantity
 * — never expiration_date/received_at/supplier_id (batch integrity
 * rule). Reason must come from the predefined list; remarks are always
 * required regardless of reason, enforced both here and inside
 * adjust_inventory() itself.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$batchId = (int) ($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);

$stmt = db()->prepare(
    'SELECT b.*, p.product_name, p.unit, br.branch_name
     FROM inventory_batches b
     JOIN products p ON p.product_id = b.product_id
     LEFT JOIN branches br ON br.branch_id = b.branch_id
     WHERE b.batch_id = ? LIMIT 1'
);
$stmt->execute([$batchId]);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    set_flash('danger', 'Batch not found.');
    header('Location: batches.php');
    exit;
}

$errors = [];
$old = ['new_quantity' => (string) $batch['remaining_quantity'], 'reason' => '', 'remarks' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $newQuantity = clean_input($_POST['new_quantity'] ?? '');
    $reason      = clean_input($_POST['reason'] ?? '');
    $remarks     = clean_input($_POST['remarks'] ?? '');

    $old = compact('newQuantity', 'reason', 'remarks');
    $old['new_quantity'] = $newQuantity;

    if ($newQuantity === '' || !ctype_digit($newQuantity)) {
        $errors[] = 'New quantity must be a whole number.';
    }
    if (!in_array($reason, ADJUSTMENT_REASONS, true)) {
        $errors[] = 'Please select a valid adjustment reason.';
    }
    if ($remarks === '') {
        $errors[] = 'Remarks are required before saving.';
    }

    if (!$errors) {
        $result = adjust_inventory($batchId, (int) $newQuantity, $reason, $remarks, current_user_id());

        if ($result['success']) {
            set_flash('success', 'Inventory adjustment saved for Batch #' . $batchId . '.');
            header('Location: batch_view.php?id=' . $batchId);
            exit;
        }

        $errors[] = $result['error'] ?? 'Adjustment failed.';
    }
}

$pageTitle = 'Inventory Adjustment';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-sliders me-1"></i> Inventory Adjustment — Batch #<?= (int) $batch['batch_id'] ?></div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>

                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i> <?= e($batch['product_name']) ?>
                    <span class="badge bg-light text-dark border"><?= e($batch['branch_name'] ?: 'Branch #' . $batch['branch_id']) ?></span> —
                    current remaining: <strong><?= (int) $batch['remaining_quantity'] ?> <?= e($batch['unit']) ?></strong>
                    (originally received: <?= (int) $batch['quantity'] ?>).
                    Expiration date, received date, and supplier cannot be changed here — only the remaining quantity.
                </div>

                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="batch_id" value="<?= (int) $batchId ?>">

                    <div class="mb-3">
                        <label class="form-label">New Remaining Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="new_quantity" class="form-control" min="0" max="<?= (int) $batch['quantity'] ?>"
                               value="<?= e($old['new_quantity']) ?>" required>
                        <div class="form-text">Must be between 0 and <?= (int) $batch['quantity'] ?> (the original received quantity).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <select name="reason" class="form-select" required>
                            <option value="">Select a reason…</option>
                            <?php foreach (ADJUSTMENT_REASONS as $r): ?>
                                <option value="<?= e($r) ?>" <?= $old['reason'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="3" required
                                  placeholder="Explain what happened…"><?= e($old['remarks']) ?></textarea>
                        <div class="form-text">Required for every adjustment, regardless of reason.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-warning" type="submit"><i class="bi bi-save me-1"></i> Save Adjustment</button>
                        <a href="batch_view.php?id=<?= (int) $batchId ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
