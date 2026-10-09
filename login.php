<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$error = '';
$postedUsername = '';

if (is_logged_in()) {
    redirect_to_role_dashboard();
}

if (isset($_GET['error']) && $_GET['error'] !== '') {
    $error = (string) $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $postedUsername = trim((string) ($_POST['username'] ?? ''));

    if (!csrf_verify($postedToken)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $username = $postedUsername;
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $result = attempt_login($username, $password);

            if ($result['success']) {
                redirect_to_role_dashboard();
            }

            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &middot; <?= e(APP_NAME) ?></title>
    <link href="<?= e(APP_URL) ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/fonts.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/dashboard.css" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <div class="login-brand-icon"><i class="bi bi-boxes"></i></div>
            <div class="login-brand-name"><?= e(APP_NAME) ?></div>
        </div>

        <div class="login-heading">
            <h1>Welcome Back</h1>
            <p>Sign in to continue to your dashboard.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="login-alert"><i class="bi bi-exclamation-circle"></i><span><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="login-field">
                <label class="login-label" for="username">Username</label>
                <div class="login-input-wrap">
                    <i class="bi bi-person login-input-icon"></i>
                    <input type="text" name="username" id="username" class="login-input" required autofocus
                           value="<?= e($postedUsername) ?>">
                </div>
            </div>

            <div class="login-field login-field--last">
                <label class="login-label" for="password">Password</label>
                <div class="login-input-wrap login-input-wrap--pw">
                    <i class="bi bi-lock login-input-icon"></i>
                    <input type="password" name="password" id="password" class="login-input" required>
                    <button class="login-toggle-btn" type="button" onclick="togglePw('password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button class="login-submit-btn" type="submit">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
        </form>
    </div>
</div>

<script>
function togglePw(id, btn){
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
}
</script>
</body>
</html>
