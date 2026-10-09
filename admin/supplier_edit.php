<?php
declare(strict_types=1);

/**
 * supplier_edit.php
 * Admin only.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$supplier = fetch_supplier($id);

if (!$supplier) {
    http_response_code(404);
    set_flash('danger', 'Supplier not found.');
    header('Location: suppliers.php');
    exit;
}

$errors = [];
$old = [
    'supplier_name'  => $supplier['supplier_name'],
    'contact_person' => $supplier['contact_person'],
    'contact_number' => $supplier['contact_number'],
    'email'          => $supplier['email'],
    'address'        => $supplier['address'],
    'status'         => $supplier['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name          = clean_input($_POST['supplier_name'] ?? '');
    $contactPerson = clean_input($_POST['contact_person'] ?? '');
    $contactNumber = clean_input($_POST['contact_number'] ?? '');
    $email         = clean_input($_POST['email'] ?? '');
    $address       = clean_input($_POST['address'] ?? '');
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $old = compact('contactPerson', 'contactNumber', 'email', 'address', 'status');
    $old['supplier_name']  = $name;
    $old['contact_person'] = $contactPerson;
    $old['contact_number'] = $contactNumber;

    $errors = validate_supplier($name, $email, $contactNumber);

    if (!$errors && strtolower($name) !== strtolower((string) $supplier['supplier_name'])
        && !supplier_name_unique($name, $id)) {
        $errors[] = 'A supplier with this name already exists.';
    }
    if (mb_strlen($contactPerson) > 100) {
        $errors[] = 'Contact person must be 100 characters or fewer.';
    }
    if (mb_strlen($address) > 500) {
        $errors[] = 'Address must be 500 characters or fewer.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE suppliers SET supplier_name=?, contact_person=?, contact_number=?, email=?, address=?, status=?
             WHERE supplier_id=?'
        );
        $stmt->execute([
            $name,
            $contactPerson !== '' ? $contactPerson : null,
            $contactNumber !== '' ? $contactNumber : null,
            $email !== '' ? $email : null,
            $address !== '' ? $address : null,
            $status,
            $id,
        ]);

        log_activity(current_user_id(), "Updated supplier '{$name}'");
        set_flash('success', 'Supplier updated successfully.');
        header('Location: suppliers.php');
        exit;
    }
}

$pageTitle = 'Edit Supplier';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-pencil me-1"></i> Edit Supplier</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" name="supplier_name" id="supplier_name" class="form-control"
                               maxlength="100" value="<?= e($old['supplier_name']) ?>" required
                               oninput="checkSupplierName(this, <?= $id ?>)">
                        <div id="supplierNameFeedback" class="form-text"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control" maxlength="100"
                                   value="<?= e($old['contact_person']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" maxlength="20"
                                   placeholder="e.g. +63 912 345 6789" value="<?= e($old['contact_number']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               value="<?= e($old['email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"
                                  maxlength="500"><?= e($old['address']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $old['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Save Changes</button>
                        <a href="suppliers.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
