<?php
declare(strict_types=1);

/**
 * category_create.php
 * Admin only. Staff can only view categories (enforced by require_admin()
 * below, not just by hiding the "Add Category" button).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$errors = [];
$old = ['category_name' => '', 'description' => '', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = clean_input($_POST['category_name'] ?? '');
    $description = clean_input($_POST['description'] ?? '');
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $old = compact('name', 'description', 'status');
    $old['category_name'] = $name;

    $errors = validate_category_name($name);

    if (!$errors && !category_name_unique($name)) {
        $errors[] = 'A category with this name already exists.';
    }

    if (mb_strlen($description) > 500) {
        $errors[] = 'Description must be 500 characters or fewer.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO categories (category_name, description, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $description !== '' ? $description : null, $status]);

        log_activity(current_user_id(), "Created category '{$name}'");
        set_flash('success', "Category '{$name}' created successfully.");
        header('Location: categories.php');
        exit;
    }
}

$pageTitle = 'Add Category';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-tag me-1"></i> Add New Category</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul class="mb-0">
                        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" id="category_name" class="form-control"
                               maxlength="100" value="<?= e($old['category_name']) ?>" required
                               oninput="checkCategoryName(this)">
                        <div id="categoryNameFeedback" class="form-text"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  maxlength="500"><?= e($old['description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $old['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Create Category</button>
                        <a href="categories.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
