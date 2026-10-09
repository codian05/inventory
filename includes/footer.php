        </main>
        <footer class="app-footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>
        </footer>
    </div><!-- /.main-content -->
</div><!-- /.wrapper -->

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<!-- Loading spinner overlay -->
<div class="spinner-overlay" id="globalSpinner" style="display:none">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<?php
/**
 * Mobile-only Floating Action Button — quick access to Stock In /
 * Stock Out / (admin) Adjustment. Every link below reuses an existing
 * route already built in Phase 6; nothing new is implemented here.
 * Hidden on desktop/tablet via CSS (d-lg-none), shown only on phones.
 */
$fabRoleFolder = (current_user_role() === 'admin') ? 'admin' : 'staff';
$fabBase = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/' . $fabRoleFolder;
?>
<div class="fab-backdrop" id="fabBackdrop"></div>
<div class="fab-wrap d-lg-none" id="fabWrap">
    <div class="fab-menu" id="fabMenu">
        <a href="<?= e($fabBase) ?>/stock_in.php" class="fab-item">
            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Stock In
        </a>
        <a href="<?= e($fabBase) ?>/stock_out.php" class="fab-item">
            <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Stock Out
        </a>
        <?php if ($fabRoleFolder === 'admin'): ?>
            <a href="<?= e($fabBase) ?>/batches.php" class="fab-item">
                <i class="bi bi-sliders" aria-hidden="true"></i> Adjustment
            </a>
        <?php endif; ?>
    </div>
    <button type="button" class="fab-main" id="fabToggle" aria-label="Quick inventory actions" aria-expanded="false" aria-controls="fabMenu">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
    </button>
</div>

<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php
/**
 * Optional per-page extra scripts (e.g. Chart.js + a chart init block
 * on dashboard pages). Every existing page leaves $pageScripts unset,
 * so this block renders nothing extra for them — fully backward
 * compatible with everything built in Phase 1.
 */
if (!empty($pageScripts) && is_array($pageScripts)):
    foreach ($pageScripts as $src): ?>
        <script src="<?= e($src) ?>"></script>
    <?php endforeach;
endif;

if (!empty($pageInlineScript)): ?>
    <script><?= $pageInlineScript ?></script>
<?php endif; ?>
</body>
</html>
