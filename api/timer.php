<?php
/**
 * Timer API
 * POST JSON: { action, slot, date(optional), ...params }
 * Returns JSON response.
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/hook.php';
require_once __DIR__ . '/../includes/auth.php';
auth_require_api();

header('Content-Type: application/json; charset=utf-8');

// --- Helpers ---

function api_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function now_iso(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c');
}

function today_str(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

function calc_elapsed(array $segments): int {
    $total = 0;
    $tz = new DateTimeZone('Asia/Tokyo');
    foreach ($segments as $seg) {
        $start = new DateTime($seg['start'], $tz);
        $end   = isset($seg['end']) ? new DateTime($seg['end'], $tz) : new DateTime('now', $tz);
        $diff  = $end->getTimestamp() - $start->getTimestamp();
        if ($diff > 0) $total += $diff;
    }
    return $total;
}

function ensure_koma(array &$session, int $slot): int {
    $idx = find_koma_index($session, $slot);
    if ($idx === -1) {
        $session['koma'][] = [
            'id'               => $slot,
            'name'             => '',
            'project_id'       => '',
            'status'           => 'idle',
            'break_after'      => false,
            'segments'         => [],
            'total_seconds'    => 0,
            'overtime_seconds' => 0,
            'completed_at'     => null,
            'date'             => $session['date'],
        ];
        $idx = count($session['koma']) - 1;
    }
    return $idx;
}

function fire_hook(string $event, array $payload = []): void {
    // タイマー操作の応答を待たせすぎないよう、短めのタイムアウトで直接送る
    dispatch_hook($event, $payload, load_config(), 3, 2);
}

/**
 * Close an open segment at the current time (or cap it).
 */
function close_open_segment(array &$segments): void {
    if (empty($segments)) return;
    $last = &$segments[count($segments) - 1];
    if (!isset($last['end'])) {
        $last['end'] = now_iso();
    }
    unset($last);
}

/**
 * Scan sessions older than $days_threshold days and auto_close any running/paused komas.
 * Returns number of komas auto-closed.
 */
function auto_close_old_komas(int $days_threshold, int $koma_duration_sec): int {
    $tz      = new DateTimeZone('Asia/Tokyo');
    $closed  = 0;

    // Scan back up to 90 days looking for stale sessions
    for ($d = $days_threshold; $d <= 90; $d++) {
        $date = (new DateTime("-{$d} days", $tz))->format('Y-m-d');
        $path = session_data_path($date);
        if (!file_exists($path)) continue;

        $session = load_session($date);
        $changed = false;

        foreach ($session['koma'] as &$k) {
            if (!in_array($k['status'], ['running', 'paused', 'overtime'])) continue;

            close_open_segment($k['segments']);
            $elapsed = calc_elapsed($k['segments']);

            $k['total_seconds']    = $elapsed;
            $k['overtime_seconds'] = max(0, $elapsed - $koma_duration_sec);
            $k['status']           = 'auto_closed';
            $k['completed_at']     = now_iso();
            $changed = true;
            $closed++;
        }
        unset($k);

        if ($changed) {
            save_session($session);
            koma_info('auto_closed stale komas', ['date' => $date, 'count' => $closed]);
        }
    }
    return $closed;
}

/**
 * Collect incomplete komas from yesterday (running/paused/overtime).
 * Returns array of { date, koma } objects.
 */
function get_prev_incomplete(int $koma_duration_sec): array {
    $tz       = new DateTimeZone('Asia/Tokyo');
    $result   = [];

    $yesterday = (new DateTime('-1 day', $tz))->format('Y-m-d');
    $path      = session_data_path($yesterday);
    if (!file_exists($path)) return $result;

    $session = load_session($yesterday);
    foreach ($session['koma'] as $k) {
        if (!in_array($k['status'], ['running', 'paused', 'overtime'])) continue;
        // Compute live elapsed
        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds'] = $elapsed;
        if ($elapsed >= $koma_duration_sec) $k['status'] = 'overtime';
        $result[] = ['date' => $yesterday, 'koma' => $k];
    }
    return $result;
}

// --- Request parsing ---

$raw = file_get_contents('php://input');
$req = json_decode($raw, true) ?? [];

$action = $req['action'] ?? '';
$slot   = isset($req['slot']) ? (int)$req['slot'] : 0;
// date param lets the frontend target a specific day's session (for prev_incomplete operations)
$req_date = isset($req['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $req['date'])
    ? $req['date'] : null;

if ($action === '') api_error('action is required');

$config        = load_config();
$koma_count    = (int)$config['koma_count'];
$koma_duration = (int)$config['koma_duration_minutes'] * 60;
$max_duration  = (int)$config['max_duration_minutes'] * 60;

// --- Non-slot actions ---

if ($action === 'set_slot_count') {
    $count   = max((int)($req['count'] ?? 0), (int)$config['koma_count']);
    $count   = min($count, 20); // hard cap
    // Use the date sent by the client (CFG.today at page load) so that around midnight
    // the count is always saved to the correct day's session, not tomorrow's.
    $target  = $req_date ?? today_str();
    $session = load_session($target);
    $session['slot_count'] = $count;
    save_session($session);
    api_ok(['slot_count' => $count]);
}

if ($action === 'set_theme') {
    $theme = ($req['theme'] ?? '') === 'light' ? 'light' : 'dark';
    $cfg   = load_config();
    $cfg['theme'] = $theme;
    save_config($cfg);
    api_ok(['theme' => $theme]);
}

// --- get_state ---

if ($action === 'get_state') {
    $today   = today_str();
    $session = load_session($today);

    // 1. Auto-close komas from 2+ days ago
    auto_close_old_komas(2, $koma_duration);

    // 2. Compute live totals for today's running komas
    foreach ($session['koma'] as &$k) {
        if (in_array($k['status'], ['running', 'overtime'])) {
            $k['total_seconds'] = calc_elapsed($k['segments']);
            if ($k['total_seconds'] >= $koma_duration) {
                $k['status'] = 'overtime';
            }
        }
    }
    unset($k);

    // 3. Collect yesterday's incomplete komas
    $prev_incomplete = get_prev_incomplete($koma_duration);

    api_ok([
        'session'            => $session,
        'prev_incomplete'    => $prev_incomplete,
        'config'             => $config,
        'today'              => $today,
        'user'               => get_koma_user(),
        'koma_duration_sec'  => $koma_duration,
        'max_duration_sec'   => $max_duration,
    ]);
}

// --- Actions that need a slot ---

define('SLOT_MAX', 20); // Hard upper limit per day
if ($slot < 1 || $slot > SLOT_MAX) {
    api_error('slot must be 1–' . SLOT_MAX);
}

// Session to operate on:
// - If req_date is provided (prev_incomplete operations), use that date's session.
// - Otherwise always use TODAY's session (no cross-day slot injection).
$target_date = $req_date ?? today_str();
$session     = load_session($target_date);
$idx         = find_koma_index($session, $slot);

switch ($action) {

    case 'start':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if (in_array($k['status'], ['completed', 'closed', 'auto_closed'])) {
            api_error('このコマはすでに終了しています');
        }

        $elapsed = calc_elapsed($k['segments']);

        close_open_segment($k['segments']);

        $k['segments'][] = ['start' => now_iso()];
        $k['status']     = $elapsed >= $koma_duration ? 'overtime' : 'running';
        $k['total_seconds'] = $elapsed;

        save_session($session);
        fire_hook('koma_start', ['slot' => $slot, 'user_id' => CURRENT_USER_ID, 'date' => $target_date]);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'pause':
        if ($idx === -1) api_error('コマが見つかりません');
        $k = &$session['koma'][$idx];

        if (!in_array($k['status'], ['running', 'overtime'])) {
            api_error('実行中ではありません');
        }

        close_open_segment($k['segments']);
        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds']    = $elapsed;
        $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
        $k['status']           = 'paused';

        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'complete':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if (in_array($k['status'], ['completed', 'closed', 'auto_closed'])) {
            api_error('すでに終了しています');
        }

        close_open_segment($k['segments']);
        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds']    = $elapsed;
        $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
        $k['status']           = 'completed';
        // For past-date komas, use the last segment's end time to avoid
        // attributing the completion to today's date in stats.
        $lastSeg = !empty($k['segments']) ? end($k['segments']) : null;
        $k['completed_at'] = ($target_date !== today_str() && $lastSeg && isset($lastSeg['end']))
            ? $lastSeg['end']
            : now_iso();

        save_session($session);

        if (!empty($k['project_id'])) {
            push_project_history($k['project_id']);
        }

        fire_hook('koma_complete', [
            'slot'             => $slot,
            'user_id'          => CURRENT_USER_ID,
            'date'             => $target_date,
            'total_seconds'    => $k['total_seconds'],
            'overtime_seconds' => $k['overtime_seconds'],
        ]);

        if (!empty($k['break_after'])) {
            fire_hook('break_notify', ['slot' => $slot, 'user_id' => CURRENT_USER_ID, 'date' => $target_date]);
        }

        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'reset':
        // Reset a phantom-completed koma (completed with no segments) back to idle.
        // Only allowed when segments is empty so real work is never lost.
        if ($idx === -1) api_error('コマが見つかりません');
        $k = &$session['koma'][$idx];

        if (!empty($k['segments'])) {
            api_error('開始済みのコマはリセットできません');
        }

        $k['status']           = 'idle';
        $k['completed_at']     = null;
        $k['total_seconds']    = 0;
        $k['overtime_seconds'] = 0;

        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'close':
        // Manual abandon — marks as 'closed'
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if (in_array($k['status'], ['completed', 'closed', 'auto_closed'])) {
            api_error('すでに終了しています');
        }

        close_open_segment($k['segments']);
        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds']    = $elapsed;
        $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
        $k['status']           = 'closed';
        $k['completed_at']     = now_iso();

        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'update_meta':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if (isset($req['name'])) {
            $k['name'] = mb_substr(trim($req['name']), 0, 200);
        }
        if (isset($req['project_id'])) {
            $old = $k['project_id'];
            $k['project_id'] = mb_substr(trim($req['project_id']), 0, 100);
            if ($k['project_id'] !== $old && $k['project_id'] !== '') {
                push_project_history($k['project_id']);
            }
        }

        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'set_break':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];
        $k['break_after'] = (bool)($req['value'] ?? false);
        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    case 'notify_80min':
        if ($idx === -1) api_error('コマが見つかりません');
        fire_hook('koma_80min', ['slot' => $slot, 'user_id' => CURRENT_USER_ID, 'date' => $target_date]);
        api_ok();

    case 'notify_100min':
        // Hook-only: no longer auto-completes the koma (廃止: #I-004).
        fire_hook('koma_100min', ['slot' => $slot, 'user_id' => CURRENT_USER_ID, 'date' => $target_date]);
        api_ok(['date' => $target_date]);

    case 'round_to_100min':
        // Cap a completed koma's total_seconds to max_duration (100 min).
        if ($idx === -1) api_error('コマが見つかりません');
        $k = &$session['koma'][$idx];

        if (!in_array($k['status'], ['completed', 'closed', 'auto_closed'])) {
            api_error('完了済みコマのみ丸め可能です');
        }
        if ((int)$k['total_seconds'] <= $max_duration) {
            api_error('100分以下のため丸め不要です');
        }

        $k['total_seconds']    = $max_duration;
        $k['overtime_seconds'] = max(0, $max_duration - $koma_duration);

        save_session($session);
        api_ok(['koma' => $k, 'date' => $target_date]);

    default:
        api_error('unknown action: ' . $action);
}
