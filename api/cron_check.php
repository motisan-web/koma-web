<?php
/**
 * Cron job: ブラウザを閉じていても koma_80min / koma_100min の hook を送る。
 * コマの状態は変えない（自動完了は廃止: #I-004 / #I-012）。放置したコマは統計画面の編集で直す。
 * 送信済みはコマの hooks_fired に記録されるので、ブラウザ側の通知と二重には送らない。
 * Run every 2-5 minutes via cron.
 *
 * Usage (CLI):  php /path/to/api/cron_check.php
 * Usage (HTTP): GET /api/cron_check.php（ログインが必要）
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/hook.php';
require_once __DIR__ . '/../includes/auth.php';
// cron（CLI）からはそのまま動く。HTTP で叩く場合はログインが必要
auth_require_api();

function calc_elapsed_cron(array $segments): int {
    $total = 0;
    $tz    = new DateTimeZone('Asia/Tokyo');
    foreach ($segments as $seg) {
        $start = new DateTime($seg['start'], $tz);
        $end   = isset($seg['end']) ? new DateTime($seg['end'], $tz) : new DateTime('now', $tz);
        $diff  = $end->getTimestamp() - $start->getTimestamp();
        if ($diff > 0) $total += $diff;
    }
    return $total;
}

// --- Main ---

$config     = load_config();
$komaDurSec = (int)$config['koma_duration_minutes'] * 60;
$maxSec     = (int)$config['max_duration_minutes'] * 60;
$tz         = new DateTimeZone('Asia/Tokyo');

// Scan sessions from the past 2 days (covers day-spanning komas)
$checked = 0;
$fired   = 0;

for ($d = 0; $d <= 1; $d++) {
    $date = (new DateTime("-{$d} days", $tz))->format('Y-m-d');
    $path = session_data_path($date);
    if (!file_exists($path)) continue;

    $session = load_session($date);
    $changed = false;

    foreach ($session['koma'] as &$k) {
        $status = $k['status'] ?? 'idle';
        if (!in_array($status, ['running', 'overtime', 'paused'])) continue;

        $checked++;
        $elapsed = calc_elapsed_cron($k['segments']);
        $payload = ['slot' => $k['id'], 'user_id' => CURRENT_USER_ID, 'date' => $date];

        if ($elapsed >= $komaDurSec && dispatch_koma_hook_once($k, 'koma_80min', $payload, $config)) {
            $changed = true;
            $fired++;
        }
        if ($elapsed >= $maxSec && dispatch_koma_hook_once($k, 'koma_100min', $payload, $config)) {
            $changed = true;
            $fired++;
        }
    }
    unset($k);

    if ($changed) {
        save_session($session);
    }
}

$msg = "cron_check done. checked={$checked} fired={$fired}";
koma_info($msg);

// Output (visible in cron mail / HTTP response)
if (PHP_SAPI === 'cli') {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . PHP_EOL;
}
