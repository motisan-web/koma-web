<?php
/**
 * Markdown output API
 * GET ?date=YYYY-MM-DD  → returns markdown string for that day
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/koma_stats.php';
auth_require_api();

header('Content-Type: application/json; charset=utf-8');

function api_ok(array $d): void {
    echo json_encode(array_merge(['ok' => true], $d), JSON_UNESCAPED_UNICODE);
    exit;
}
function api_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = $_GET['date'] ?? (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    api_error('invalid date format');
}

$config  = load_config();
$komaDur = (int)$config['koma_duration_minutes'];
$session = load_session($date);
$komas   = $session['koma'] ?? [];

if (empty($komas)) {
    api_ok(['markdown' => "（{$date} のコマデータはありません）"]);
}

// Sort by id
usort($komas, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);

$lines        = [];
$totalSeconds = 0;
$komaNum      = 0;
$komaTotal    = 0.0;
$silentTotal  = 0.0;

foreach ($komas as $k) {
    $komaNum++;
    $status    = $k['status'] ?? 'idle';
    $done      = in_array($status, ['completed', 'overtime_max', 'closed', 'auto_closed']);
    $checkBox  = $done ? '[x]' : '[ ]';
    $name      = $k['name'] ?: '（未記入）';
    $projId    = $k['project_id'] ?: '';

    // Actual time in minutes (capped to max for display, show overtime if any)
    $seconds = (int)($k['total_seconds'] ?? 0);
    if ($status === 'running' || $status === 'overtime') {
        // Live koma — include running time
        $seconds = max($seconds, 0);
    }
    $minutes = (int)round($seconds / 60);
    $totalSeconds += $seconds;
    $v = koma_value($k);
    $komaTotal += $v;
    if (koma_is_silent($k)) $silentTotal += $v;

    $overtimeSec = (int)($k['overtime_seconds'] ?? 0);
    $overtimeNote = $overtimeSec > 0 ? sprintf(' (%d分超過)', (int)round($overtimeSec / 60)) : '';

    $lines[] = "### コマ{$komaNum}({$komaDur}分){$overtimeNote}";
    $lines[] = "- {$checkBox} **内容**: {$name}";
    if ($projId !== '') {
        $lines[] = "- **プロジェクト**: {$projId}";
    }
    $lines[] = "- **時間(分)**: {$minutes}";
    $lines[] = '';
}

$totalMinutes = (int)round($totalSeconds / 60);
$displayDate  = (new DateTime($date))->format('Y/n/j');
$komaTotal    = number_format($komaTotal, 1, '.', '');
$silentTotal  = number_format($silentTotal, 1, '.', '');
$lines[] = "合計作業時間 {$totalMinutes}分({$displayDate}) コマ数 {$komaTotal} silentコマ {$silentTotal}";

$markdown = implode("\n", $lines);

api_ok(['markdown' => $markdown, 'date' => $date]);
