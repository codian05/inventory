<?php
declare(strict_types=1);

/**
 * category_edit.php
 * Admin only.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$category = fetch_category($id);

if (!$category) {
    http_response_code(404);
    set_flash('danger', 'Category not found.');
    header('Location: categories.php');
    exit;
}

$errors = [];
$old = [
    'category_name' => $category['category_name'],
    'description'   => $category['description'],
    'status'        => $category['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = clean_input($_POST['category_name'] ?? '');
    $description = clean_input($_POST['description'] ?? '');
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $old = compact('description', 'status');
    $old['category_name'] = $name;

    $errors = validate_category_name($name);

    if (!$errors && strtolower($name) !== strtolower((string) $category['category_name'])
        && !category_name_unique($name, $id)) {
        $errors[] = 'A category with this name already exists.';
    }

    if (mb_strlen($description) > 500) {
        $errors[] = 'Description must be 500 characters or fewer.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE categories SET category_name=?, description=?, status=? WHERE category_id=?'
        );
        $stmt->execute([$name, $description !== '' ? $description : null, $status, $id]);

        log_activity(current_user_id(), "Updated category '{$name}'");
        set_flash('success', 'Category updated successfully.');
        header('Location: categories.php');
        exit;
    }
}

$pageTitle = 'Edit Category';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-pencil me-1"></i> Edit Category</div>
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
                               oninput="checkCategoryName(this, <?= $id ?>)">
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
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Save Changes</button>
                        <a href="categories.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
