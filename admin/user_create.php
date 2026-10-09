<?php
declare(strict_types=1);

/**
 * user_create.php
 * Admin creates a new user (admin or staff).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$errors = [];
$old    = ['fullname' => '', 'username' => '', 'email' => '', 'role' => 'staff', 'status' => 'active', 'branch_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fullname = clean_input($_POST['fullname'] ?? '');
    $username = clean_input($_POST['username'] ?? '');
    $email    = clean_input($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');
    $role     = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
    $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $branchId = (int) ($_POST['branch_id'] ?? 0);

    $old = compact('fullname', 'username', 'email', 'role', 'status', 'branchId');
    $old['branch_id'] = $branchId;

    // ---- validation ------------------------------------------
    if ($fullname === '') {
        $errors[] = 'Full name is required.';
    }
    if (mb_strlen($fullname) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
        $errors[] = 'Username must be 3–50 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $errors[] = 'Username may only contain letters, numbers, _, . and -.';
    } elseif (!username_unique($username)) {
        $errors[] = 'Username already taken.';
    }

    if ($email !== '' && !validate_email($email)) {
        $errors[] = 'Email address is not valid.';
    } elseif ($email !== '' && !email_unique($email)) {
        $errors[] = 'Email already registered.';
    }

    $errors = array_merge($errors, validate_password($password));
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Branch: required for staff, optional (system-wide) for admin.
    // Whatever is submitted is re-validated against the DB here — a
    // branch_id is never trusted purely because it was present in
    // the POST body.
    if ($role === 'staff') {
        if ($branchId <= 0) {
            $errors[] = 'Branch is required for a Staff account.';
        } elseif (!fetch_branch($branchId)) {
            $errors[] = 'Selected branch is not valid.';
        }
    } else {
        // Admin may optionally be pinned to a branch too, but it's
        // not required — preserves the existing system-wide admin
        // architecture unless the operator explicitly narrows it.
        if ($branchId > 0 && !fetch_branch($branchId)) {
            $errors[] = 'Selected branch is not valid.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $finalBranchId = $branchId > 0 ? $branchId : null;
        $stmt = db()->prepare(
            'INSERT INTO users (fullname, username, email, password, role, branch_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$fullname, $username, $email !== '' ? $email : null, $hash, $role, $finalBranchId, $status]);

        log_activity(current_user_id(), "Created user '{$username}' ({$role})");
        set_flash('success', "User '{$username}' created successfully.");
        header('Location: users.php');
        exit;
    }
}

$branchChoices = branch_options($old['branch_id'] ?: null);

$pageTitle = 'Add New User';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-person-plus me-1"></i> Add New User</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
                <form method="POST" id="createForm" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="fullname" class="form-control" maxlength="100"
                               value="<?= e($old['fullname']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="username" class="form-control"
                               maxlength="50" value="<?= e($old['username']) ?>" required
                               oninput="checkUsername(this)">
                        <div id="usernameFeedback" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               value="<?= e($old['email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control" required
                                   oninput="checkPassword(this)">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('password', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="pwFeedback" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm" id="confirm" class="form-control" required>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePw('confirm', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="confirmFeedback" class="form-text"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" id="roleSelect" class="form-select">
                                <option value="staff" <?= $old['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                <option value="admin" <?= $old['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active"   <?= $old['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="branchLabel">Branch <span class="text-danger" id="branchRequired">*</span></label>
                        <select name="branch_id" class="form-select">
                            <option value="">— <span id="branchNoneLabel">Select branch…</span> —</option>
                            <?php foreach ($branchChoices as $b): ?>
                                <option value="<?= (int) $b['branch_id'] ?>" <?= (int) $old['branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                    <?= e($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Required for Staff. Optional for Admin — leave blank for a system-wide administrator.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Create User</button>
                        <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$pageInlineScript = <<<'JS'
document.addEventListener("DOMContentLoaded", function () {
    const roleSelect = document.getElementById("roleSelect");
    const branchRequired = document.getElementById("branchRequired");
    function syncBranchRequirement() {
        if (!roleSelect || !branchRequired) return;
        branchRequired.style.display = roleSelect.value === "staff" ? "inline" : "none";
    }
    if (roleSelect) {
        roleSelect.addEventListener("change", syncBranchRequirement);
        syncBranchRequirement();
    }
});
JS;
require __DIR__ . '/../includes/footer.php'; ?>
