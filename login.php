<?php
/**
 * ログイン / 初回登録 / ログアウト
 * アカウントが1件もなければ登録フォーム、あればログインフォームを出す。
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$config = load_config();

// ローカルではログイン不要
if (auth_is_local()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!auth_check_csrf($_POST['csrf'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } elseif ($action === 'logout') {
        auth_logout();
        header('Location: /login.php');
        exit;
    } elseif ($action === 'register') {
        $error = auth_register(trim($_POST['id'] ?? ''), $_POST['password'] ?? '', $_POST['password_confirm'] ?? '') ?? '';
    } elseif ($action === 'login') {
        $error = auth_login(trim($_POST['id'] ?? ''), $_POST['password'] ?? '') ?? '';
    }
    if ($error === '' && in_array($action, ['register', 'login'], true)) {
        header('Location: /');
        exit;
    }
}

if (auth_current_user() !== null) {
    header('Location: /');
    exit;
}

$isRegister = !auth_has_account();
$csrf       = auth_csrf_token();
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isRegister ? 'アカウント登録' : 'ログイン' ?> — コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
</head>
<body>
<main class="page-main" style="max-width:420px; padding-top:64px;">
    <h1 class="page-title">コマタイマー</h1>

    <?php if ($error): ?>
        <div class="notice notice-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="card">
        <div class="card__title"><?= $isRegister ? 'アカウント登録（初回のみ）' : 'ログイン' ?></div>
        <input type="hidden" name="action" value="<?= $isRegister ? 'register' : 'login' ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="form-row">
            <label for="login-id">ID</label>
            <input type="text" id="login-id" name="id" required autocomplete="username"
                   value="<?= htmlspecialchars($_POST['id'] ?? '') ?>">
        </div>
        <div class="form-row">
            <label for="login-password">パスワード<?= $isRegister ? '（8文字以上）' : '' ?></label>
            <input type="password" id="login-password" name="password" required
                   autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>">
        </div>
        <?php if ($isRegister): ?>
            <div class="form-row">
                <label for="login-password-confirm">パスワード（確認）</label>
                <input type="password" id="login-password-confirm" name="password_confirm" required autocomplete="new-password">
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-complete" style="width:100%;">
            <?= $isRegister ? '登録してはじめる' : 'ログイン' ?>
        </button>
    </form>
</main>
</body>
</html>
