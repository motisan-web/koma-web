<?php
/**
 * ログイン（ID・パスワード）
 * - アカウントは data/auth.json に保存（.htaccess で HTTP から遮断済み）
 * - アカウントが1件もないときだけ初回登録を受け付ける
 * - ローカル開発環境ではログイン不要
 */

require_once __DIR__ . '/data.php';

define('AUTH_PATH', __DIR__ . '/../data/auth.json');
define('AUTH_SESSION_DIR', __DIR__ . '/../data/php_sessions');
define('AUTH_SESSION_LIFETIME', 60 * 60 * 24 * 30);   // 30日
define('AUTH_MAX_FAILURES', 5);
define('AUTH_LOCK_SECONDS', 15 * 60);

/**
 * ループバックからの接続、かつホスト名が .local / localhost のときだけローカル扱い。
 * Host ヘッダーは偽装できるので、REMOTE_ADDR と両方で判定する。
 */
function auth_is_local(): bool {
    if (PHP_SAPI === 'cli') return true;
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    $loopback = in_array($addr, ['127.0.0.1', '::1'], true);
    $localHost = $host === 'localhost' || str_ends_with($host, '.local');
    return $loopback && $localHost;
}

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (!is_dir(AUTH_SESSION_DIR)) mkdir(AUTH_SESSION_DIR, 0700, true);
    // サーバー共通のセッション置き場だと、他アプリの GC 設定で早く消されるため専用フォルダを使う
    session_save_path(AUTH_SESSION_DIR);
    ini_set('session.gc_maxlifetime', (string)AUTH_SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_name('komatimer_sid');
    session_set_cookie_params([
        'lifetime' => AUTH_SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_load(): array {
    return json_read(AUTH_PATH) ?? ['accounts' => []];
}

function auth_has_account(): bool {
    return !empty(auth_load()['accounts']);
}

function auth_current_user(): ?string {
    if (auth_is_local()) return null;
    auth_start_session();
    return $_SESSION['auth_user'] ?? null;
}

function auth_csrf_token(): string {
    auth_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function auth_check_csrf(?string $token): bool {
    auth_start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/**
 * 初回登録。成功時は null、失敗時はエラーメッセージを返す。
 */
function auth_register(string $id, string $password, string $confirm): ?string {
    if (auth_has_account()) return 'アカウントはすでに登録されています。';
    if (!preg_match('/^[A-Za-z0-9_\-]{3,32}$/', $id)) return 'ID は半角英数字・_・- の3〜32文字にしてください。';
    if (strlen($password) < 8) return 'パスワードは8文字以上にしてください。';
    if ($password !== $confirm) return '確認用のパスワードが一致しません。';

    $data = ['accounts' => [[
        'id'            => $id,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at'    => date('c'),
        'failures'      => 0,
        'locked_until'  => null,
    ]]];
    if (!json_write(AUTH_PATH, $data)) return 'アカウントの保存に失敗しました。';
    auth_set_logged_in($id);
    return null;
}

/**
 * ログイン。成功時は null、失敗時はエラーメッセージを返す。
 * 連続で失敗したアカウントは一定時間ロックする。
 */
function auth_login(string $id, string $password): ?string {
    $data  = auth_load();
    $index = null;
    foreach ($data['accounts'] as $i => $acc) {
        if ($acc['id'] === $id) { $index = $i; break; }
    }

    if ($index === null) {
        // ID の有無で応答時間が変わらないよう、ダミーで検証しておく
        password_verify($password, password_hash('dummy', PASSWORD_DEFAULT));
        return 'ID またはパスワードが違います。';
    }

    $acc = &$data['accounts'][$index];
    if (!empty($acc['locked_until']) && strtotime($acc['locked_until']) > time()) {
        return 'ログインに続けて失敗したため、しばらくロックしています。時間をおいて試してください。';
    }

    if (!password_verify($password, $acc['password_hash'])) {
        $acc['failures'] = ($acc['failures'] ?? 0) + 1;
        if ($acc['failures'] >= AUTH_MAX_FAILURES) {
            $acc['locked_until'] = date('c', time() + AUTH_LOCK_SECONDS);
            $acc['failures']     = 0;
        }
        json_write(AUTH_PATH, $data);
        return 'ID またはパスワードが違います。';
    }

    $acc['failures']     = 0;
    $acc['locked_until'] = null;
    if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
        $acc['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    unset($acc);
    json_write(AUTH_PATH, $data);
    auth_set_logged_in($id);
    return null;
}

function auth_set_logged_in(string $id): void {
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = $id;
    $_SESSION['csrf']      = bin2hex(random_bytes(32));
}

function auth_logout(): void {
    auth_start_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

/** ページ用: 未ログインならログイン画面へ移動する */
function auth_require_page(): void {
    if (auth_is_local() || auth_current_user() !== null) return;
    header('Location: /login.php');
    exit;
}

/** API 用: 未ログインなら 401 を返す */
function auth_require_api(): void {
    if (auth_is_local() || auth_current_user() !== null) return;
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'login required'], JSON_UNESCAPED_UNICODE);
    exit;
}
