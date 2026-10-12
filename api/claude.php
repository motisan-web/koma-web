<?php
/**
 * Claude 用 API（#T-013）
 * 認証: `Authorization: Bearer <token>` だけ。ログイン（セッション）・CSRF とは独立（auth_require_api は使わない）。
 * トークンは設定画面で発行・失効する（includes/claude_token.php）。未発行・不一致は 401。
 *
 * 読み取り（GET ?action=...）
 *   day          ?date=YYYY-MM-DD（省略時は今日）  その日のコマ一覧と合計
 *   daily        ?from=&to=（省略時は今週の日曜〜今日。最大366日）  日別のコマ数
 *   week         ?date=（その日を含む週。省略時は今週）  日曜〜土曜の日別コマ数と合計・目標
 *   week_status  今週の状況（トップの「週の状況」と同じ値。koma_week_status + 今日の分）
 *   stats        ?from=&to=（省略時は今日を含む直近30日。最大366日）&project=（任意）  期間の集計とプロジェクト別
 * 更新（POST JSON）
 *   update_koma  {date, slot, name?, project_id?, silent?}  既存コマの作業内容・プロジェクト・静音タグだけ。
 *                コマの新規作成・移動・時間の編集・タイマー操作は受け付けない
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user.php';
require_once __DIR__ . '/../includes/koma_stats.php';
require_once __DIR__ . '/../includes/claude_token.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function claude_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function claude_error(string $message, int $code = 400): void {
    http_response_code($code);
    if ($code === 401) header('WWW-Authenticate: Bearer');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 認証（トークンだけ。未発行・不一致・ヘッダーなしはすべて 401） ---
if (!claude_token_verify(claude_token_from_request())) {
    claude_error('unauthorized', 401);
}

$tz     = new DateTimeZone('Asia/Tokyo');
$today  = (new DateTime('now', $tz))->format('Y-m-d');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- リクエスト（GET はクエリ、POST は JSON ボディ） ---
$body = [];
if ($method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) claude_error('JSON ボディが不正です');
} elseif ($method !== 'GET') {
    claude_error('method not allowed', 405);
}
$action = $method === 'POST' ? ($body['action'] ?? '') : ($_GET['action'] ?? '');
if (!is_string($action) || $action === '') claude_error('action is required');

/** 日付（Y-m-d）。省略時は $default。不正なら 400 */
function claude_date($v, string $default): string {
    if ($v === null || $v === '') return $default;
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) claude_error('日付は YYYY-MM-DD で指定してください');
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    if (!checkdate($m, $d, $y)) claude_error('存在しない日付です: ' . $v);
    return $v;
}

function claude_add_days(string $date, int $n): string {
    return (new DateTime($date, new DateTimeZone('Asia/Tokyo')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

/** 期間 [from, to, 日数]。省略時は $defaultFrom〜$defaultTo。最大366日 */
function claude_range($from, $to, string $defaultFrom, string $defaultTo): array {
    $from = claude_date($from, $defaultFrom);
    $to   = claude_date($to, $defaultTo);
    if ($from > $to) claude_error('from は to 以前にしてください');
    $tz = new DateTimeZone('Asia/Tokyo');
    $days = (int)(new DateTime($from, $tz))->diff(new DateTime($to, $tz))->days + 1;
    if ($days > 366) claude_error('期間は366日以内にしてください');
    return [$from, $to, $days];
}

/** コマ1つの応答用の形 */
function claude_koma_view(array $k): array {
    return [
        'slot'         => (int)$k['id'],
        'name'         => (string)($k['name'] ?? ''),
        'project_id'   => (string)($k['project_id'] ?? ''),
        'status'       => $k['status'] ?? 'idle',
        'minutes'      => round(koma_seconds($k) / 60, 1),
        'koma'         => round(koma_value($k), 2),
        'silent'       => koma_is_silent($k),
        'anomaly'      => koma_is_anomaly($k),
        'started_at'   => $k['segments'][0]['start'] ?? null,
        'completed_at' => $k['completed_at'] ?? null,
    ];
}

/** その日のコマ（スロット順）。セッションファイルがなければ空（読むだけで作らない） */
function claude_day_komas(string $date): array {
    if (!file_exists(session_data_path($date))) return [];
    $komas = load_session($date)['koma'] ?? [];
    usort($komas, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    return $komas;
}

function claude_day_sum(string $date): float {
    $sum = 0.0;
    foreach (claude_day_komas($date) as $k) $sum += koma_value($k);
    return $sum;
}

switch ($action) {

    case 'day': {
        $date  = claude_date($_GET['date'] ?? null, $today);
        $total = 0.0; $silent = 0.0; $seconds = 0;
        $list  = [];
        foreach (claude_day_komas($date) as $k) {
            $v = koma_value($k);
            $total += $v;
            if (koma_is_silent($k)) $silent += $v;
            if ($v > 0) $seconds += koma_seconds($k);
            $list[] = claude_koma_view($k);
        }
        claude_ok([
            'date'        => $date,
            'koma'        => round($total, 2),
            'silent_koma' => round($silent, 2),
            'minutes'     => round($seconds / 60, 1),
            'komas'       => $list,
        ]);
    }

    case 'daily': {
        [$from, $to] = claude_range($_GET['from'] ?? null, $_GET['to'] ?? null, koma_week_start($today), $today);
        $days = []; $total = 0.0;
        for ($d = $from; $d <= $to; $d = claude_add_days($d, 1)) {
            $v = claude_day_sum($d);
            $total += $v;
            $days[] = ['date' => $d, 'koma' => round($v, 2)];
        }
        claude_ok(['from' => $from, 'to' => $to, 'total' => round($total, 2), 'days' => $days]);
    }

    case 'week': {
        $date  = claude_date($_GET['date'] ?? null, $today);
        $start = koma_week_start($date);
        $days = []; $total = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $d = claude_add_days($start, $i);
            $v = claude_day_sum($d);
            $total += $v;
            $days[] = ['date' => $d, 'dow' => $i, 'koma' => round($v, 2)];
        }
        claude_ok([
            'week_start' => $start,
            'week_end'   => claude_add_days($start, 6),
            'goal'       => koma_week_goal(load_config()['week_goal'] ?? null),
            'total'      => round($total, 2),
            'days'       => $days,
        ]);
    }

    case 'week_status': {
        $daily = koma_daily_all();
        $w     = koma_week_status($daily, $today, load_config());
        $p     = koma_week_progress($w, (float)($daily[$today]['koma'] ?? 0.0));
        claude_ok([
            'today'                  => $today,
            'week_start'             => $w['weekStart'],
            'goal'                   => $w['goal'],
            'off_days'               => $w['offDays'],
            'work_days'              => $w['workDays'],
            'per_day'                => round($w['perDay'], 2),
            'elapsed_work_days'      => $w['elapsedWorkDays'],
            'remain_work_days'       => $w['remainWorkDays'],
            'done'                   => round($p['done'], 2),
            'rest'                   => round($p['rest'], 2),
            'reached'                => $p['reached'],
            'target_until_yesterday' => round($p['target'], 2),
            'diff'                   => round($p['diff'], 2),
            'pace'                   => $p['pace'],
            'need_per_day'           => $p['need'] === null ? null : round($p['need'], 2),
        ]);
    }

    case 'stats': {
        [$from, $to, $dayCount] = claude_range($_GET['from'] ?? null, $_GET['to'] ?? null, claude_add_days($today, -29), $today);
        $projFilter = null;
        if (isset($_GET['project']) && is_string($_GET['project']) && trim($_GET['project']) !== '') {
            $projFilter = koma_project_normalize($_GET['project']);
        }
        $total = 0.0; $silent = 0.0; $seconds = 0; $count = 0; $activeDays = 0;
        $byProject = []; $byDay = [];
        for ($d = $from; $d <= $to; $d = claude_add_days($d, 1)) {
            $daySum = 0.0;
            foreach (claude_day_komas($d) as $k) {
                $pid = trim((string)($k['project_id'] ?? ''));
                if ($projFilter !== null && $pid !== $projFilter) continue;
                $v = koma_value($k);
                if ($v <= 0) continue;
                $sec = koma_seconds($k);
                $daySum += $v; $total += $v; $seconds += $sec; $count++;
                if (koma_is_silent($k)) $silent += $v;
                $byProject[$pid] ??= ['project_id' => $pid, 'koma' => 0.0, 'minutes' => 0.0, 'count' => 0];
                $byProject[$pid]['koma']    += $v;
                $byProject[$pid]['minutes'] += $sec / 60;
                $byProject[$pid]['count']++;
            }
            if ($daySum > 0) { $activeDays++; $byDay[] = ['date' => $d, 'koma' => round($daySum, 2)]; }
        }
        $projects = array_values($byProject);
        usort($projects, fn($a, $b) => $b['koma'] <=> $a['koma']);
        foreach ($projects as &$p) { $p['koma'] = round($p['koma'], 2); $p['minutes'] = round($p['minutes'], 1); }
        unset($p);
        claude_ok([
            'from'        => $from,
            'to'          => $to,
            'days'        => $dayCount,
            'active_days' => $activeDays,
            'koma'        => round($total, 2),
            'silent_koma' => round($silent, 2),
            'minutes'     => round($seconds / 60, 1),
            'count'       => $count,
            'by_project'  => $projects,
            'by_day'      => $byDay,
        ]);
    }

    case 'update_koma': {
        if ($method !== 'POST') claude_error('POST で呼んでください', 405);
        // 更新できるのは作業内容・プロジェクト・静音タグだけ。ほかのフィールド（時間・ステータス・移動先など）は受け付けない
        $allowed = ['action', 'date', 'slot', 'name', 'project_id', 'silent'];
        $extra = array_diff(array_map('strval', array_keys($body)), $allowed);
        if ($extra) claude_error('更新できるのは name / project_id / silent だけです（未対応: ' . implode(', ', $extra) . '）');

        if (!isset($body['date'])) claude_error('date is required');
        $date = claude_date($body['date'], $today);
        $slot = $body['slot'] ?? null;
        if (!is_int($slot) && !(is_string($slot) && preg_match('/^\d+$/', $slot))) claude_error('slot must be an integer');
        $slot = (int)$slot;
        if ($slot < 1 || $slot > 20) claude_error('slot must be 1–20');
        if (!isset($body['name']) && !isset($body['project_id']) && !isset($body['silent'])) {
            claude_error('name / project_id / silent のいずれかを指定してください');
        }
        foreach (['name', 'project_id', 'silent'] as $key) {
            if (isset($body[$key]) && !is_string($body[$key])) claude_error("$key は文字列で指定してください");
        }

        $session = load_session($date);
        $idx = find_koma_index($session, $slot);
        if ($idx === -1) claude_error('コマが見つかりません（新規作成はできません）', 404);
        $k = &$session['koma'][$idx];

        if (isset($body['name'])) {
            $k['name'] = mb_substr(trim($body['name']), 0, 200);   // timer.php の update_meta と同じ上限
        }
        if (isset($body['silent'])) {
            $named = koma_set_silent_tag((string)($k['name'] ?? ''), $body['silent'], 200);
            if ($named === null) claude_error('silent は 無音 / 音楽 / ブラウンノイズ（空文字で解除）のいずれかです');
            $k['name'] = $named;
        }
        $projectChanged = false;
        if (isset($body['project_id'])) {
            $old = (string)($k['project_id'] ?? '');
            $k['project_id'] = koma_project_normalize($body['project_id']);   // 100文字まで・#project/ を補う
            $projectChanged = $k['project_id'] !== $old && $k['project_id'] !== '';
        }
        if (!save_session($session)) claude_error('保存に失敗しました', 500);
        if ($projectChanged) push_project_history($k['project_id']);

        claude_ok(['date' => $date, 'koma' => claude_koma_view($k)]);
    }

    default:
        claude_error('unknown action: ' . $action);
}
