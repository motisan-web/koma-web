<?php
/**
 * Claude 用 API（api/claude.php）のトークン（#T-013）
 * - 保存先は data/claude_token.json（git 管理外・.htaccess で HTTP から遮断済み）。保存するのはハッシュだけで、平文は保存しない
 * - 平文は発行した直後の1回だけ settings.php に表示する。再発行すると前のトークンは使えなくなる（失効）
 * - 比較は hash_equals。ログ・レスポンスにトークンを出さない
 */

require_once __DIR__ . '/data.php';

define('CLAUDE_TOKEN_PATH', __DIR__ . '/../data/claude_token.json');
define('CLAUDE_TOKEN_PREFIX', 'koma_');

/** 発行済みの情報（ハッシュと発行日時）。未発行なら null */
function claude_token_load(): ?array {
    $data = json_read(CLAUDE_TOKEN_PATH);
    if (!is_array($data) || !isset($data['hash']) || !is_string($data['hash']) || $data['hash'] === '') return null;
    return $data;
}

function claude_token_exists(): bool {
    return claude_token_load() !== null;
}

/** 新しいトークンを発行して保存する。返り値は平文（この1回しか取れない）。失敗時は null。前のトークンは使えなくなる */
function claude_token_issue(): ?string {
    $plain = CLAUDE_TOKEN_PREFIX . bin2hex(random_bytes(32));
    $ok = json_write(CLAUDE_TOKEN_PATH, [
        'hash'       => hash('sha256', $plain),
        'created_at' => (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c'),
    ]);
    return $ok ? $plain : null;
}

/** 失効させる（保存したハッシュを消す） */
function claude_token_revoke(): bool {
    if (!file_exists(CLAUDE_TOKEN_PATH)) return true;
    return unlink(CLAUDE_TOKEN_PATH);
}

/** 平文のトークンが発行済みのものと一致するか */
function claude_token_verify(string $plain): bool {
    $stored = claude_token_load();
    if ($stored === null || $plain === '') return false;
    return hash_equals($stored['hash'], hash('sha256', $plain));
}

/** Authorization: Bearer <token> からトークンを取り出す。なければ空文字 */
function claude_token_from_request(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $h = $value; break; }
        }
    }
    return preg_match('/^Bearer\s+(\S+)\s*$/i', (string)$h, $m) ? $m[1] : '';
}
