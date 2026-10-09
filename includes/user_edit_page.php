<?php
declare(strict_types=1);

/**
 * user_edit.php
 * Edit a user. Admins can edit anyone; staff can edit only their own
 * profile (fullname, username, email, profile picture only).
 *
 * SECURITY FIX: the previous version only *hid* the role/status fields
 * in HTML for non-admins, but still trusted $_POST['role'] /
 * $_POST['status'] from the server side. Any authenticated staff user
 * could POST role=admin directly (bypassing the UI) and self-promote.
 * Role/status are now only ever read from $_POST when $isAdmin is true.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

require_login();

$id      = (int) ($_GET['id'] ?? 0);
$isAdmin = current_user_role() === 'admin';
$isSelf  = ($id === current_user_id());

// Staff can only edit their own profile
if (!$isAdmin && !$isSelf) {
    http_response_code(403);
    die('Access denied.');
}

$user = fetch_user($id);
if (!$user) {
    http_response_code(404);
    die('User not found.');
}

$errors = [];
$old = [
    'fullname' => $user['fullname'],
    'username' => $user['username'],
    'email'    => $user['email'],
    'role'     => $user['role'],
    'status'   => $user['status'],
    'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fullname = clean_input($_POST['fullname'] ?? '');
    $username = clean_input($_POST['username'] ?? '');
    $email    = clean_input($_POST['email'] ?? '');

    // Role/status/branch can ONLY be changed by an admin, and never
    // for self. Non-admins (or an admin editing their own account)
    // keep the existing value no matter what was submitted in the
    // request body — branch assignment is an admin-controlled
    // property, never self-service, so a staff member can never POST
    // a different branch_id (or branch_id="" to try to go global) and
    // have it take effect.
    if ($isAdmin && !$isSelf) {
        $role   = ($_POST['role'] ?? $user['role']) === 'admin' ? 'admin' : 'staff';
        $status = ($_POST['status'] ?? $user['status']) === 'inactive' ? 'inactive' : 'active';
        $branchId = (int) ($_POST['branch_id'] ?? 0);
    } else {
        $role   = $user['role'];
        $status = $user['status'];
        $branchId = $old['branch_id'];
    }

    $old = compact('fullname', 'username', 'email', 'role', 'status');
    $old['branch_id'] = $branchId;

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
    } elseif (strtolower($username) !== strtolower($user['username']) && !username_unique($username, $id)) {
        $errors[] = 'Username already taken.';
    }

    if ($email !== '' && !validate_email($email)) {
        $errors[] = 'Email address is not valid.';
    } elseif ($email !== '' && $email !== $user['email'] && !email_unique($email, $id)) {
        $errors[] = 'Email already registered.';
    }

    if ($isAdmin && !$isSelf) {
        if ($role === 'staff') {
            if ($branchId <= 0) {
                $errors[] = 'Branch is required for a Staff account.';
            } elseif (!fetch_branch($branchId)) {
                $errors[] = 'Selected branch is not valid.';
            }
        } elseif ($branchId > 0 && !fetch_branch($branchId)) {
            $errors[] = 'Selected branch is not valid.';
        }
    }

    // Profile image (optional)
    $imagePath = $user['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = handle_profile_upload($_FILES['profile_image'], $id);
        if ($uploaded === null) {
            $errors[] = 'Profile image upload failed (must be JPG/PNG/WebP/GIF under 2MB).';
        } else {
            if (!empty($user['profile_image']) && is_file(BASE_PATH . '/' . $user['profile_image'])) {
                @unlink(BASE_PATH . '/' . $user['profile_image']);
            }
            $imagePath = $uploaded;
        }
    }

    if (!$errors) {
        $finalBranchId = $branchId > 0 ? $branchId : null;
        $stmt = db()->prepare(
            'UPDATE users SET fullname=?, username=?, email=?, role=?, branch_id=?, status=?, profile_image=?
             WHERE id=?'
        );
        $stmt->execute([$fullname, $username, $email !== '' ? $email : null, $role, $finalBranchId, $status, $imagePath, $id]);

        if ($isSelf) {
            $_SESSION['fullname'] = $fullname;
            $_SESSION['username'] = $username;
        }

        log_activity(current_user_id(), $isSelf
            ? "Updated own profile"
            : "Edited user '{$username}'");
        set_flash('success', 'User updated successfully.');
        header('Location: ' . ($isAdmin ? 'users.php' : 'user_profile.php?id=' . $id));
        exit;
    }
}

$branchChoices = ($isAdmin && !$isSelf) ? branch_options($old['branch_id'] ?: null) : [];

$pageTitle = 'Edit User';
require __DIR__ . '/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="bi bi-pencil me-1"></i> Edit User
                <?php if ($isSelf): ?>
                    <span class="badge bg-info ms-2">Your account</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" id="editForm" novalidate>
                    <?= csrf_field() ?>
                    <div class="text-center mb-3">
                        <img src="<?= e(avatar_url($user['profile_image'], $user['fullname'])) ?>"
                             alt="avatar" class="rounded-circle avatar-img" width="90" height="90"
                             style="object-fit:cover" id="avatarPreview">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Profile Picture</label>
                        <input type="file" name="profile_image" class="form-control" accept="image/*"
                               onchange="previewImage(this, 'avatarPreview')">
                        <div class="form-text">JPG, PNG, WebP or GIF (max 2MB). Leave blank to keep current.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="fullname" class="form-control" maxlength="100"
                               value="<?= e($old['fullname']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="username" class="form-control"
                               maxlength="50" value="<?= e($old['username']) ?>" required
                               oninput="checkUsername(this, <?= $id ?>)">
                        <div id="usernameFeedback" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               value="<?= e($old['email']) ?>">
                    </div>
                    <?php if ($isAdmin && !$isSelf): ?>
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
                        <label class="form-label">Branch <span class="text-danger" id="branchRequired" style="display:<?= $old['role'] === 'staff' ? 'inline' : 'none' ?>">*</span></label>
                        <select name="branch_id" class="form-select">
                            <option value="">— Select branch… —</option>
                            <?php foreach ($branchChoices as $b): ?>
                                <option value="<?= (int) $b['branch_id'] ?>" <?= (int) $old['branch_id'] === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                    <?= e($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Required for Staff. Optional for Admin — leave blank for a system-wide administrator. Takes effect on this user's very next request.</div>
                    </div>
                    <?php elseif ($isSelf): ?>
                        <p class="text-muted small">Role, status, and branch can only be changed by another administrator.</p>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Save Changes</button>
                        <a href="<?= $isAdmin ? 'users.php' : 'user_profile.php?id=' . $id ?>" class="btn btn-outline-secondary">Cancel</a>
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
    }
});
JS;
require __DIR__ . '/footer.php'; ?>
