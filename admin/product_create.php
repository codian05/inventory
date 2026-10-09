<?php
declare(strict_types=1);

/**
 * product_create.php
 * Admin only. Staff can only view products.
 *
 * BARCODE RECOGNITION: if the submitted barcode already belongs to an
 * existing product, this does NOT create a duplicate record. Instead
 * it blocks the save and shows a link to the existing product — or,
 * if that product was soft-deleted, offers to restore it instead of
 * creating a new one.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$errors = [];
$existingMatch = null; // set when the barcode already belongs to a product
$old = [
    'barcode' => '', 'product_name' => '', 'category_id' => 0, 'supplier_id' => 0,
    'description' => '', 'unit' => 'pcs', 'selling_price' => '', 'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $barcode      = clean_input($_POST['barcode'] ?? '');
    $name         = clean_input($_POST['product_name'] ?? '');
    $categoryId   = (int) ($_POST['category_id'] ?? 0);
    $supplierId   = (int) ($_POST['supplier_id'] ?? 0);
    $description  = clean_input($_POST['description'] ?? '');
    $unit         = clean_input($_POST['unit'] ?? '');
    $sellingPrice = clean_input($_POST['selling_price'] ?? '');
    $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $old = compact('description', 'unit', 'status');
    $old['barcode']       = $barcode;
    $old['product_name']  = $name;
    $old['category_id']   = $categoryId;
    $old['supplier_id']   = $supplierId;
    $old['selling_price'] = $sellingPrice;

    $errors = validate_product($name, $barcode, $unit, $sellingPrice, $categoryId, $supplierId);

    // Barcode recognition: does this barcode already belong to a product?
    if (!$errors || $barcode !== '') {
        $existingMatch = fetch_product_by_barcode($barcode, true);
        if ($existingMatch) {
            $errors[] = $existingMatch['deleted_at']
                ? 'This barcode already belongs to a deleted product. Restore it instead of creating a new one.'
                : 'This barcode already belongs to an existing product. The system will not create a duplicate.';
        }
    }

    if (mb_strlen($description) > 1000) {
        $errors[] = 'Description must be 1000 characters or fewer.';
    }

    // Image upload validated up-front (product row doesn't exist yet,
    // so we validate strictly but store the file after insert once we
    // have the new product_id for the filename).
    $imageFile = $_FILES['product_image'] ?? null;
    $hasImage  = $imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE;

    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO products (barcode, product_name, category_id, supplier_id, description, unit, selling_price, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $barcode, $name, $categoryId, $supplierId,
            $description !== '' ? $description : null, $unit, $sellingPrice, $status,
        ]);
        $newId = (int) db()->lastInsertId();

        if ($hasImage) {
            $uploaded = handle_product_image_upload($imageFile, $newId);
            if ($uploaded !== null) {
                db()->prepare('UPDATE products SET product_image = ? WHERE product_id = ?')
                    ->execute([$uploaded, $newId]);
                set_flash('success', "Product '{$name}' created successfully.");
            } else {
                set_flash('warning', "Product '{$name}' was created, but the image upload failed (must be JPG/PNG/WebP/GIF under 2MB). You can add an image from the product's edit page.");
            }
        } else {
            set_flash('success', "Product '{$name}' created successfully.");
        }

        log_activity(current_user_id(), "Created product '{$name}'");
        header('Location: products.php');
        exit;
    }
}

$categoryChoices = category_options();
$supplierChoices = supplier_options();

$pageTitle = 'Add Product';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-box-seam me-1"></i> Add New Product</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
                        </ul>
                        <?php if ($existingMatch): ?>
                            <hr class="my-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-upc-scan fs-4"></i>
                                <div>
                                    <strong>Found:</strong> <?= e($existingMatch['product_name']) ?>
                                    <?php if ($existingMatch['deleted_at']): ?>
                                        <span class="badge bg-secondary ms-1">Deleted</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($existingMatch['deleted_at']): ?>
                                    <a href="product_delete.php?action=restore&id=<?= (int) $existingMatch['product_id'] ?>&csrf=<?= urlencode(csrf_token()) ?>"
                                       class="btn btn-sm btn-outline-success ms-auto">
                                       <i class="bi bi-arrow-counterclockwise"></i> Restore This Product
                                    </a>
                                <?php else: ?>
                                    <a href="product_view.php?id=<?= (int) $existingMatch['product_id'] ?>"
                                       class="btn btn-sm btn-outline-primary ms-auto">
                                       <i class="bi bi-eye"></i> View Existing Product
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">Barcode <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                            <input type="text" name="barcode" id="barcode" class="form-control font-monospace"
                                   maxlength="100" value="<?= e($old['barcode']) ?>" required
                                   placeholder="Scan or type barcode" oninput="checkBarcode(this)">
                        </div>
                        <div id="barcodeFeedback" class="form-text"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="product_name" class="form-control" maxlength="150"
                               value="<?= e($old['product_name']) ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select category…</option>
                                <?php foreach ($categoryChoices as $cat): ?>
                                    <option value="<?= (int) $cat['category_id'] ?>" <?= $old['category_id'] === (int) $cat['category_id'] ? 'selected' : '' ?>>
                                        <?= e($cat['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$categoryChoices): ?>
                                <div class="form-text text-warning">No active categories yet — <a href="../admin/category_create.php">create one first</a>.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select supplier…</option>
                                <?php foreach ($supplierChoices as $sup): ?>
                                    <option value="<?= (int) $sup['supplier_id'] ?>" <?= $old['supplier_id'] === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                                        <?= e($sup['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$supplierChoices): ?>
                                <div class="form-text text-warning">No active suppliers yet — <a href="../admin/supplier_create.php">create one first</a>.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <input type="text" name="unit" class="form-control" list="unitSuggestions"
                                   maxlength="20" value="<?= e($old['unit']) ?>" required>
                            <datalist id="unitSuggestions">
                                <option value="pcs"><option value="box"><option value="pack">
                                <option value="kg"><option value="g"><option value="L">
                                <option value="mL"><option value="dozen"><option value="sack">
                                <option value="bottle"><option value="can">
                            </datalist>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" name="selling_price" class="form-control" step="0.01" min="0"
                                       value="<?= e($old['selling_price']) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active"   <?= $old['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                                  maxlength="1000"><?= e($old['description']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product Image</label>
                        <input type="file" name="product_image" class="form-control" accept="image/*">
                        <div class="form-text">JPG, PNG, WebP or GIF (max 2MB). Optional.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Create Product</button>
                        <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
