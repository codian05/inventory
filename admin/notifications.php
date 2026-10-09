<?php
declare(strict_types=1);

/**
 * notifications.php
 * Admin-only persisted notification center. Live low-stock/expiring/
 * expired alerts also shown (read-only, not persisted rows) so this
 * page is a complete picture — see dashboard_notifications().
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        mark_all_notifications_read();
        set_flash('success', 'All notifications marked as read.');
        header('Location: notifications.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'delete_read') {
        $count = delete_read_notifications();
        set_flash('success', $count . ' read notification(s) deleted.');
        header('Location: notifications.php');
        exit;
    }
}

$notifications = recent_notifications(50);

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash-heading">
    <div>
        <h2>Notifications</h2>
        <div class="text-muted">Persisted system events — Stock In, Stock Out, and Adjustments.</div>
    </div>
    <div class="d-flex gap-2">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-check2-all me-1"></i> Mark All Read</button>
        </form>
        <form method="POST" onsubmit="return confirm('Permanently delete all read notifications?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_read">
            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash3 me-1"></i> Clear Read</button>
        </form>
    </div>
</div>

<div class="card chart-card">
    <div class="card-header"><i class="bi bi-bell me-1"></i> <strong>All Notifications</strong></div>
    <div class="card-body notif-card-list">
        <?php if (!$notifications): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No notifications yet. They'll appear here as Stock In, Stock Out, and Adjustments happen.
            </div>
        <?php else: foreach ($notifications as $n):
            $icon = match ($n['type']) {
                'danger' => 'bi-x-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill',
                'success' => 'bi-check-circle-fill', default => 'bi-info-circle-fill',
            };
        ?>
            <div class="notif-card type-<?= e($n['type']) ?> <?= $n['is_read'] ? 'opacity-75' : '' ?>" id="notif-<?= (int) $n['notification_id'] ?>">
                <div class="notif-card-icon"><i class="bi <?= e($icon) ?>"></i></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold small">
                        <?= e($n['title']) ?>
                        <?php if (!$n['is_read']): ?><span class="badge bg-primary ms-1">New</span><?php endif; ?>
                    </div>
                    <div class="text-muted small"><?= e($n['message']) ?></div>
                    <div class="text-muted" style="font-size:.72rem"><?= e(format_date($n['created_at'])) ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                    <button class="btn btn-sm btn-outline-secondary mark-read-btn" data-id="<?= (int) $n['notification_id'] ?>">
                        <i class="bi bi-check2"></i>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php
$pageInlineScript = <<<'JS'
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".mark-read-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-id");
            const meta = document.querySelector('meta[name="csrf-token"]');
            fetch("../ajax/mark_notification_read.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "id=" + encodeURIComponent(id) + "&csrf_token=" + encodeURIComponent(meta ? meta.content : "")
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    const card = document.getElementById("notif-" + id);
                    if (card) {
                        card.classList.add("opacity-75");
                        const badge = card.querySelector(".badge");
                        if (badge) badge.remove();
                        btn.remove();
                    }
                }
            });
        });
    });
});
JS;
require __DIR__ . '/../includes/footer.php';
