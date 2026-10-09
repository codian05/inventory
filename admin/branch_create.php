<?php
declare(strict_types=1);

/**
 * branch_create.php
 * Admin only. Staff never see this (enforced by require_admin() below,
 * not just by hiding the "Add Branch" button — same convention as
 * category_create.php / supplier_create.php).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$errors = [];
$old = ['branch_code' => '', 'branch_name' => '', 'address' => '', 'contact_number' => '', 'email' => '', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $code    = clean_input($_POST['branch_code'] ?? '');
    $name    = clean_input($_POST['branch_name'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $contact = clean_input($_POST['contact_number'] ?? '');
    $email   = clean_input($_POST['email'] ?? '');
    $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $old = compact('code', 'name', 'address', 'contact', 'email', 'status');
    $old['branch_code'] = $code;
    $old['branch_name'] = $name;
    $old['contact_number'] = $contact;

    $errors = validate_branch($code, $name);

    if (!$errors && !branch_code_unique($code)) {
        $errors[] = 'A branch with this code already exists.';
    }
    if (!in_array('Branch name is required.', $errors, true) && !branch_name_unique($name)) {
        $errors[] = 'A branch with this name already exists.';
    }
    if ($address !== '' && mb_strlen($address) > 500) {
        $errors[] = 'Address must be 500 characters or fewer.';
    }
    if ($contact !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $contact)) {
        $errors[] = 'Contact number is not valid.';
    }
    if ($email !== '' && !validate_email($email)) {
        $errors[] = 'Email address is not valid.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO branches (branch_code, branch_name, address, contact_number, email, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code,
            $name,
            $address !== '' ? $address : null,
            $contact !== '' ? $contact : null,
            $email !== '' ? $email : null,
            $status,
        ]);

        log_activity(current_user_id(), "Created branch '{$name}' ({$code})");
        set_flash('success', "Branch '{$name}' created successfully.");
        header('Location: branches.php');
        exit;
    }
}

$pageTitle = 'Add Branch';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-shop me-1"></i> Add New Branch</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Branch Code <span class="text-danger">*</span></label>
                            <input type="text" name="branch_code" id="branch_code" class="form-control font-monospace text-uppercase"
                                   maxlength="20" value="<?= e($old['branch_code']) ?>" required
                                   oninput="checkBranchCode(this)" placeholder="e.g. TARLAC">
                            <div id="branchCodeFeedback" class="form-text"></div>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Branch Name <span class="text-danger">*</span></label>
                            <input type="text" name="branch_name" class="form-control"
                                   maxlength="150" value="<?= e($old['branch_name']) ?>" required placeholder="e.g. Tarlac Branch">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2" maxlength="500"><?= e($old['address']) ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" maxlength="20"
                                   value="<?= e($old['contact_number']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" maxlength="100"
                                   value="<?= e($old['email']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $old['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <div class="form-text">Inactive branches can't be selected for Stock In/Out/Transfer or new staff assignment.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Create Branch</button>
                        <a href="branches.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
