<?php
declare(strict_types=1);

/**
 * product_edit.php
 * Admin only.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$product = fetch_product($id);

if (!$product) {
    http_response_code(404);
    set_flash('danger', 'Product not found.');
    header('Location: products.php');
    exit;
}

$errors = [];
$existingMatch = null;
$old = [
    'barcode'       => $product['barcode'],
    'product_name'  => $product['product_name'],
    'category_id'   => (int) $product['category_id'],
    'supplier_id'   => (int) $product['supplier_id'],
    'description'   => $product['description'],
    'unit'          => $product['unit'],
    'selling_price' => $product['selling_price'],
    'status'        => $product['status'],
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

    if ((!$errors || $barcode !== '') && $barcode !== $product['barcode']) {
        $existingMatch = fetch_product_by_barcode($barcode, true);
        if ($existingMatch && (int) $existingMatch['product_id'] !== $id) {
            $errors[] = $existingMatch['deleted_at']
                ? 'This barcode already belongs to a deleted product. Restore that one instead.'
                : 'This barcode already belongs to another existing product.';
        } else {
            $existingMatch = null;
        }
    }

    if (mb_strlen($description) > 1000) {
        $errors[] = 'Description must be 1000 characters or fewer.';
    }

    $imagePath = $product['product_image'];
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = handle_product_image_upload($_FILES['product_image'], $id);
        if ($uploaded === null) {
            $errors[] = 'Product image upload failed (must be JPG/PNG/WebP/GIF under 2MB).';
        } else {
            if (!empty($product['product_image']) && is_file(BASE_PATH . '/' . $product['product_image'])) {
                @unlink(BASE_PATH . '/' . $product['product_image']);
            }
            $imagePath = $uploaded;
        }
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE products SET barcode=?, product_name=?, category_id=?, supplier_id=?, description=?,
             unit=?, selling_price=?, product_image=?, status=? WHERE product_id=?'
        );
        $stmt->execute([
            $barcode, $name, $categoryId, $supplierId,
            $description !== '' ? $description : null, $unit, $sellingPrice, $imagePath, $status, $id,
        ]);

        log_activity(current_user_id(), "Updated product '{$name}'");
        set_flash('success', 'Product updated successfully.');
        header('Location: products.php');
        exit;
    }
}

$categoryChoices = category_options((int) $old['category_id']);
$supplierChoices = supplier_options((int) $old['supplier_id']);

$pageTitle = 'Edit Product';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header"><i class="bi bi-pencil me-1"></i> Edit Product</div>
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
                                <div><strong>Found:</strong> <?= e($existingMatch['product_name']) ?></div>
                                <a href="product_view.php?id=<?= (int) $existingMatch['product_id'] ?>"
                                   class="btn btn-sm btn-outline-primary ms-auto">
                                   <i class="bi bi-eye"></i> View That Product
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php $img = product_image_url($product['product_image']); ?>
                <?php if ($img): ?>
                    <div class="text-center mb-3">
                        <img src="<?= e($img) ?>" alt="" class="rounded" id="imgPreview"
                             style="max-height:140px; object-fit:cover">
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
                                   oninput="checkBarcode(this, <?= $id ?>)">
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
                                        <?= e($cat['category_name']) ?><?= ($cat['status'] !== 'active' || $cat['deleted_at']) ? ' (inactive)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select supplier…</option>
                                <?php foreach ($supplierChoices as $sup): ?>
                                    <option value="<?= (int) $sup['supplier_id'] ?>" <?= $old['supplier_id'] === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                                        <?= e($sup['supplier_name']) ?><?= ($sup['status'] !== 'active' || $sup['deleted_at']) ? ' (inactive)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                                       value="<?= e((string) $old['selling_price']) ?>" required>
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
                        <label class="form-label">Replace Product Image</label>
                        <input type="file" name="product_image" class="form-control" accept="image/*"
                               onchange="previewImage(this, 'imgPreview')">
                        <div class="form-text">Leave blank to keep the current image.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Save Changes</button>
                        <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
