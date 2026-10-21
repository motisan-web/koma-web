<?php
/**
 * コマの集計ルール（統計・異常値・今後のバッジで共通に使う）
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calendar.php';

// これ以上の分数のコマは放置などによる異常値とみなす（#T-003）
define('KOMA_ANOMALY_MINUTES', 1000);

// ---- プロジェクトID（#T-012） ----
// 保存値は `#project/xxx` か空。入力欄には接頭辞を固定表示して続きだけ入れる。
// 規則は JS の KomaProject（assets/js/project-id.js）と同じ。変えるときは両方直す。

const KOMA_PROJECT_PREFIX    = '#project/';
const KOMA_PROJECT_MAX_CHARS = 100;

/** 保存用に整える。空・`#project/` だけは空。`#project/` で始まらなければ補い、重なっていれば1つにする。長さは timer.php と同じ100文字まで */
function koma_project_normalize($v): string {
    $s = koma_project_suffix($v);
    if ($s === '') return '';
    return rtrim(mb_substr(KOMA_PROJECT_PREFIX . $s, 0, KOMA_PROJECT_MAX_CHARS));
}

/** 入力欄に出す「続き」だけ。保存値から接頭辞を外す */
function koma_project_suffix($v): string {
    if (!is_scalar($v)) return '';
    $s = trim((string)$v);
    while (str_starts_with($s, KOMA_PROJECT_PREFIX)) $s = trim(substr($s, strlen(KOMA_PROJECT_PREFIX)));
    return $s;
}

// 「終わった」コマ（経過時間が total_seconds で確定している）。中止（closed / auto_closed）も含む
const KOMA_ENDED_STATUSES = ['completed', 'overtime_max', 'closed', 'auto_closed'];
// 「完了した」コマ。[x]・80分への切り上げ・完了個数はこちらだけ。中止は含めない（#I-017）
const KOMA_COMPLETED_STATUSES = ['completed', 'overtime_max'];

function koma_is_ended(array $k): bool {
    return in_array($k['status'] ?? 'idle', KOMA_ENDED_STATUSES, true);
}

function koma_is_completed(array $k): bool {
    return in_array($k['status'] ?? 'idle', KOMA_COMPLETED_STATUSES, true);
}

// 終わったコマ（完了・中止）は記録した時間、作業中・一時停止中は今までの経過で判定する（閉じ忘れたコマも数えないため。#I-015）
function koma_is_anomaly(array $k): bool {
    return koma_seconds($k) >= KOMA_ANOMALY_MINUTES * 60;
}

/**
 * $daysThreshold 日以上前の「作業中・一時停止中」のコマを自動中止（auto_closed）にする。#I-015
 * 全期間が対象。開いたままの区間は今の時刻で閉じるので、長く放置したコマは異常値になり、
 * 統計の日別表示で時間を直せる。トップ・統計の表示時と get_state で呼ぶ。返り値: 閉じたコマの数
 */
function koma_auto_close_stale(int $daysThreshold = 2, ?int $now = null): int {
    $now  ??= time();
    $tz    = new DateTimeZone('Asia/Tokyo');
    $limit = (new DateTime('@' . $now))->setTimezone($tz)->modify("-{$daysThreshold} days")->format('Y-m-d');
    $nowIso = (new DateTime('@' . $now))->setTimezone($tz)->format('c');
    $komaSec = (int)(load_config()['koma_duration_minutes'] ?? KOMA_UNIT_MINUTES) * 60;
    $closed = 0;
    foreach (glob(__DIR__ . '/../data/sessions/*/*/data.json') ?: [] as $file) {
        if (!preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', str_replace('\\', '/', $file), $m)) continue;
        $date = $m[1] . '-' . $m[2];
        if ($date > $limit) continue;
        $session = load_session($date);
        $changed = 0;
        foreach ($session['koma'] as &$k) {
            if (!in_array($k['status'] ?? '', ['running', 'paused', 'overtime'], true)) continue;
            $last = count($k['segments'] ?? []) - 1;
            if ($last >= 0 && !isset($k['segments'][$last]['end'])) $k['segments'][$last]['end'] = $nowIso;
            $elapsed = koma_seconds(['status' => 'running'] + $k, $now);
            $k['total_seconds']    = $elapsed;
            $k['overtime_seconds'] = max(0, $elapsed - $komaSec);
            $k['status']           = 'auto_closed';
            $k['completed_at']     = $nowIso;
            $changed++;
        }
        unset($k);
        if ($changed) {
            save_session($session);
            koma_info('auto_closed stale komas', ['date' => $date, 'count' => $changed]);
            $closed += $changed;
        }
    }
    return $closed;
}

/**
 * 全期間の異常値コマを新しい日付順に返す。
 * 返り値: [['date' => 'Y-m-d', 'koma' => array], ...]
 */
function find_anomaly_komas(): array {
    $result = [];
    foreach (glob(__DIR__ . '/../data/sessions/*/*/data.json') ?: [] as $file) {
        if (!preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', str_replace('\\', '/', $file), $m)) continue;
        $date = $m[1] . '-' . $m[2];
        foreach (load_session($date)['koma'] ?? [] as $k) {
            if (koma_is_anomaly($k)) $result[] = ['date' => $date, 'koma' => $k];
        }
    }
    usort($result, fn($a, $b) => [$b['date'], (int)$a['koma']['id']] <=> [$a['date'], (int)$b['koma']['id']]);
    return $result;
}

// ---- コマ数の換算（#T-005 の規則。トップのLv・カレンダー・実績で共通に使う） ----
// コマ数 = 各コマの分数の合計 ÷ 80。完了（completed）で20分超〜80分未満は80分扱い、20分以下は実分数。
// 中止（closed / auto_closed）・作業中・一時停止中は実分数のまま（切り上げない。#I-017）。0分と異常値は数えない。

define('KOMA_UNIT_MINUTES', 80);
define('KOMA_SHORT_MINUTES', 20);

// 1日のコマ数で決まる実績（今日の実績）。カレンダーの色の段階にも使う
const KOMA_DAY_TIERS = [
    ['key' => 'full',  'min' => 6,  'name' => 'フルデイ'],
    ['key' => 'od',    'min' => 8,  'name' => 'オーバードライブ'],
    ['key' => 'hyper', 'min' => 10, 'name' => 'ハイパードライブ'],
    ['key' => 'limit', 'min' => 12, 'name' => 'リミットブレイク'],
];

/** コマの経過秒数。終わったコマ（完了・中止）は total_seconds、それ以外は segments から今の時刻で計算する */
function koma_seconds(array $k, ?int $now = null): int {
    if (koma_is_ended($k)) return (int)($k['total_seconds'] ?? 0);
    $now   ??= time();
    $total = 0;
    foreach ($k['segments'] ?? [] as $seg) {
        $s = strtotime($seg['start'] ?? '');
        $e = isset($seg['end']) ? strtotime($seg['end']) : $now;
        if ($s !== false && $e !== false && $e > $s) $total += $e - $s;
    }
    return $total;
}

/** コマ1つ分のコマ数（小数） */
function koma_value(array $k, ?int $now = null): float {
    if (koma_is_anomaly($k)) return 0.0;
    $min = koma_seconds($k, $now) / 60;
    if ($min <= 0) return 0.0;
    if (koma_is_completed($k) && $min > KOMA_SHORT_MINUTES && $min < KOMA_UNIT_MINUTES) $min = KOMA_UNIT_MINUTES;
    return $min / KOMA_UNIT_MINUTES;
}

// silent コマ = 作業内容に静音タグ（動画・ラジオを見ずに作業したコマ）が付いたもの。定義は Obsidian の frontmatter-daily.md
const KOMA_SILENT_TAGS = ['@無音', '@音楽', '@ブラウンノイズ'];

function koma_is_silent(array $k): bool {
    $name = (string)($k['name'] ?? '');
    foreach (KOMA_SILENT_TAGS as $tag) {
        if (mb_strpos($name, $tag) !== false) return true;
    }
    return false;
}

/**
 * 作業内容の静音タグを付け替える（#T-013。api/claude.php から使う）。
 * 既存の静音タグ（@無音 @音楽 @ブラウンノイズ）をすべて外し、$tag（`無音` / `@無音` など。空なら付けない）を最後に ` @タグ` で足す。
 * 返り値は null（$tag が静音タグではない）か、付け替えたあとの作業内容。長さの上限（$maxChars）は本文側を切ってタグを残す
 */
function koma_set_silent_tag(string $name, string $tag, int $maxChars = 200): ?string {
    $tag = ltrim(trim($tag), '@＠');
    if ($tag !== '' && !in_array('@' . $tag, KOMA_SILENT_TAGS, true)) return null;
    foreach (KOMA_SILENT_TAGS as $t) {
        $name = preg_replace('/\s*' . preg_quote($t, '/') . '/u', '', $name);
    }
    $name = trim($name);
    if ($tag === '') return mb_substr($name, 0, $maxChars);
    $suffix = ' @' . $tag;
    $base = rtrim(mb_substr($name, 0, max(0, $maxChars - mb_strlen($suffix))));
    return ltrim($base . $suffix);
}

/**
 * 全期間の日別データ。返り値: ['Y-m-d' => ['koma' => float, 'projects' => [project_id => float]], ...]（日付順）
 */
function koma_daily_all(?int $now = null): array {
    $days = [];
    foreach (glob(__DIR__ . '/../data/sessions/*/*/data.json') ?: [] as $file) {
        if (!preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', str_replace('\\', '/', $file), $m)) continue;
        $date = $m[1] . '-' . $m[2];
        $sum = 0.0;
        $projects = [];
        foreach (load_session($date)['koma'] ?? [] as $k) {
            $v = koma_value($k, $now);
            if ($v <= 0) continue;
            $sum += $v;
            $pid = trim((string)($k['project_id'] ?? ''));
            if ($pid !== '') $projects[$pid] = ($projects[$pid] ?? 0) + $v;
        }
        $days[$date] = ['koma' => $sum, 'projects' => $projects];
    }
    ksort($days);
    return $days;
}

/**
 * 条件を満たす日が何日続いているか。今日がまだ満たしていなければ昨日までを数える。
 * 返り値: ['current' => int, 'best' => int]
 */
function koma_streak(array $daily, string $today, float $minKoma): array {
    $ok = fn(string $d) => ($daily[$d]['koma'] ?? 0) >= $minKoma;
    $tz = new DateTimeZone('Asia/Tokyo');

    $current = 0;
    $d = new DateTime($today, $tz);
    if (!$ok($today)) $d->modify('-1 day');
    while ($ok($d->format('Y-m-d'))) { $current++; $d->modify('-1 day'); }

    $best = 0;
    $run  = 0;
    $prev = null;
    foreach ($daily as $date => $_) {
        if (!$ok($date)) { $run = 0; $prev = $date; continue; }
        $run  = ($prev !== null && $ok($prev) && (new DateTime($prev, $tz))->modify('+1 day')->format('Y-m-d') === $date) ? $run + 1 : 1;
        $best = max($best, $run);
        $prev = $date;
    }
    return ['current' => $current, 'best' => max($best, $current)];
}

/**
 * 統計画面（#T-006）に渡す全コマの一覧。JS が集計するので、1コマ1行の小さな形にする。
 *   d: 日付 / s: スロット / p: project_id / n: 作業内容 / st: ステータス
 *   m: 分数 / v: コマ数（koma_value） / a: 異常値なら1
 *   seg: その日の0時からの分数で [開始, 終了] の一覧（一時停止の回数 = seg の数 - 1）
 */
function koma_stats_records(?int $now = null): array {
    $now ??= time();
    $tz  = new DateTimeZone('Asia/Tokyo');
    $out = [];
    foreach (glob(__DIR__ . '/../data/sessions/*/*/data.json') ?: [] as $file) {
        if (!preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', str_replace('\\', '/', $file), $m)) continue;
        $date = $m[1] . '-' . $m[2];
        $midnight = (new DateTime($date, $tz))->getTimestamp();
        foreach (load_session($date)['koma'] ?? [] as $k) {
            $sec = koma_seconds($k, $now);
            if ($sec <= 0) continue;
            $segs = [];
            foreach ($k['segments'] ?? [] as $seg) {
                $s = strtotime($seg['start'] ?? '');
                $e = isset($seg['end']) ? strtotime($seg['end']) : $now;
                if ($s === false || $e === false || $e <= $s) continue;
                $segs[] = [(int)round(($s - $midnight) / 60), (int)round(($e - $midnight) / 60)];
            }
            $out[] = [
                'd'   => $date,
                's'   => (int)$k['id'],
                'p'   => trim((string)($k['project_id'] ?? '')),
                'n'   => trim((string)($k['name'] ?? '')),
                'st'  => $k['status'] ?? 'idle',
                'm'   => round($sec / 60, 1),
                'v'   => round(koma_value($k, $now), 3),
                'a'   => koma_is_anomaly($k) ? 1 : 0,
                'seg' => $segs,
            ];
        }
    }
    usort($out, fn($a, $b) => [$a['d'], $a['s']] <=> [$b['d'], $b['s']]);
    return $out;
}

// ---- 週の状況（#T-011。トップの「週の状況」パネル） ----
// 週は日曜始まり。曜日は 0（日）〜6（土）。その週の各日が稼働日か休みかは「日付の例外（data/calendar.json。#T-014）→ 曜日の既定（week_off_days）」の順で決める。
// 1日の目標 = 週の目標 ÷ その週の稼働日数（稼働日が0なら 0。ゼロ除算しない）。遅れ/進み = 今日を含む実績 − 1日の目標 × 今週の日曜〜昨日の稼働日数。
// 休みの日の作業は目標累計に足さず実績にだけ入る（前倒し）。次週以降の例外は今週に影響しない（今週の日付だけを見る）。
// 今日の分は JS（timer.js の renderWeek()）が毎秒足し直す。PHP と JS で同じ規則を持つので、変えるときは両方直す。

const KOMA_WEEK_GOAL_DEFAULT = 30;
const KOMA_WEEK_GOAL_MIN     = 1;
const KOMA_WEEK_GOAL_MAX     = 200;
const KOMA_WEEK_OFF_DEFAULT  = [4, 5];  // 木・金

/** 目標コマ数を 1〜200 の整数にそろえる。不正値はデフォルト */
function koma_week_goal($v): int {
    if (is_string($v) && !preg_match('/^\s*\d+\s*$/', $v)) return KOMA_WEEK_GOAL_DEFAULT;
    if (!is_int($v) && !is_string($v) && !is_float($v)) return KOMA_WEEK_GOAL_DEFAULT;
    $n = (int)$v;
    return ($n >= KOMA_WEEK_GOAL_MIN && $n <= KOMA_WEEK_GOAL_MAX) ? $n : KOMA_WEEK_GOAL_DEFAULT;
}

/** 休みの曜日（0〜6 の整数の配列）をそろえる。不正値は除き、7日すべて休みならデフォルト */
function koma_week_off_days($v): array {
    if (!is_array($v)) return KOMA_WEEK_OFF_DEFAULT;
    $out = [];
    foreach ($v as $d) {
        if ((is_int($d) || (is_string($d) && preg_match('/^[0-6]$/', $d))) && (int)$d >= 0 && (int)$d <= 6) $out[(int)$d] = true;
    }
    $out = array_keys($out);
    sort($out);
    return count($out) >= 7 ? KOMA_WEEK_OFF_DEFAULT : $out;
}

/** その週の日曜の日付（Y-m-d） */
function koma_week_start(string $date): string {
    $d = new DateTime($date, new DateTimeZone('Asia/Tokyo'));
    return $d->modify('-' . (int)$d->format('w') . ' days')->format('Y-m-d');
}

/**
 * 週の状況の材料。今日の分は含めない（JS が足す）。
 * $calendar は日付の例外（koma_calendar_load() の値。null なら読み込む）。
 * 返り値: goal / offDays（曜日の既定の休み）/ workDays（その週の稼働日数）/ perDay（1日の目標。稼働日0なら0）/
 *   dow（今日の曜日）/ todayOff（今日が休みか。日付の例外込み）/ days（日曜〜土曜の [date, off, koma]。koma は今日より前だけ）/ stage（ステージ番号）/
 *   pastWeekTotal（日曜〜昨日のコマ数）/ elapsedWorkDays（日曜〜昨日の稼働日数）/
 *   remainWorkDays（今日〜土曜の稼働日数。今日が休みなら今日は含めない）/ weekStart
 */
function koma_week_status(array $daily, string $today, array $config, ?array $calendar = null): array {
    $calendar ??= koma_calendar_load();
    $goal = koma_week_goal($config['week_goal'] ?? null);
    $off  = koma_week_off_days($config['week_off_days'] ?? null);
    $tz   = new DateTimeZone('Asia/Tokyo');
    $dow  = (int)(new DateTime($today, $tz))->format('w');
    $start = koma_week_start($today);

    $past = 0.0;
    foreach ($daily as $date => $d) {
        if ($date >= $start && $date < $today) $past += $d['koma'];
    }
    $elapsed = 0;
    $remain  = 0;
    $work    = 0;
    $days    = [];
    for ($w = 0; $w < 7; $w++) {
        $date  = (new DateTime($start, $tz))->modify("+{$w} days")->format('Y-m-d');
        $isOff = koma_day_is_off($date, $off, $calendar);
        // 今日以降は 0（今日の分は JS が足す）。ステージ表示（#T-015）が日ごとの達成を描くのに使う
        $days[] = ['date' => $date, 'off' => $isOff, 'koma' => $date < $today ? round($daily[$date]['koma'] ?? 0, 2) : 0];
        if ($isOff) continue;
        $work++;
        if ($date < $today) $elapsed++; else $remain++;
    }
    return [
        'goal'           => $goal,
        'offDays'        => $off,
        'workDays'       => $work,
        'perDay'         => $work > 0 ? $goal / $work : 0.0,
        'dow'            => $dow,
        'todayOff'       => koma_day_is_off($today, $off, $calendar),
        'days'           => $days,
        'weekStart'      => $start,
        'stage'          => koma_stage_number($daily, $start),
        'pastWeekTotal'  => $past,
        'elapsedWorkDays'=> $elapsed,
        'remainWorkDays' => $remain,
    ];
}

/**
 * 週の状況に今日の分を足した進捗（#T-013。api/claude.php 用。トップの JS の weekStatus() と同じ規則）。
 * $w は koma_week_status() の返り値、$today は今日のコマ数。
 * 稼働日が0の週（offWeek）は1日の目標が0なので target = 0、diff = 実績（休みの日の作業は前倒しとして進みに入る）。
 * 返り値: done（今週の合計）/ rest（目標までの残り）/ reached / offWeek / target（昨日までの目標累計）/ diff（実績 − 目標累計）/
 *   pace（'ahead' | 'behind' | 'even'。小数1桁で0なら even）/ need（未達で残り稼働日があるとき、1日あたりの必要コマ数。それ以外は null）
 */
function koma_week_progress(array $w, float $today): array {
    $done   = $w['pastWeekTotal'] + $today;
    $rest   = max(0.0, $w['goal'] - $done);
    $target = $w['perDay'] * $w['elapsedWorkDays'];
    $diff   = $done - $target;
    $rounded = (int)round(abs($diff) * 10);
    return [
        'done'    => $done,
        'rest'    => $rest,
        'reached' => $rest <= 0,
        'offWeek' => (int)$w['workDays'] === 0,
        'target'  => $target,
        'diff'    => $diff,
        'pace'    => $rounded === 0 ? 'even' : ($diff > 0 ? 'ahead' : 'behind'),
        'need'    => ($rest > 0 && $w['remainWorkDays'] > 0) ? $rest / $w['remainWorkDays'] : null,
    ];
}

/** ステージ番号（#T-015）。最初にコマを記録した週を1とする通し番号。記録がまだなければ今週が1 */
function koma_stage_number(array $daily, string $weekStart): int {
    $first = $weekStart;
    foreach ($daily as $date => $d) {
        if ($d['koma'] > 0) { $first = koma_week_start($date); break; }
    }
    $tz = new DateTimeZone('Asia/Tokyo');
    $diff = (new DateTime($first, $tz))->diff(new DateTime($weekStart, $tz))->days;
    return intdiv(max(0, (int)$diff), 7) + 1;
}

/**
 * その週のステージをクリアしたか（#T-015）。その週の稼働日（日付の例外 → 曜日の既定）のすべてで
 * 1日の目標（週の目標 ÷ 稼働日）以上のコマ数があること。稼働日が0の週はクリアにならない。
 * 休みの日の作業は数えない。目標・休みの曜日・日付の例外は今の設定で判定する（過去の週も同じ）。
 */
function koma_week_cleared(array $daily, string $weekStart, int $goal, array $off, array $calendar): bool {
    $tz = new DateTimeZone('Asia/Tokyo');
    $dates = [];
    for ($w = 0; $w < 7; $w++) {
        $date = (new DateTime($weekStart, $tz))->modify("+{$w} days")->format('Y-m-d');
        if (!koma_day_is_off($date, $off, $calendar)) $dates[] = $date;
    }
    if (!$dates) return false;
    $perDay = $goal / count($dates);
    foreach ($dates as $date) {
        if (($daily[$date]['koma'] ?? 0) < $perDay - 1e-9) return false;
    }
    return true;
}
