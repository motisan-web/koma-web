<?php
/**
 * コマの集計ルール（統計・異常値・今後のバッジで共通に使う）
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/config.php';

// これ以上の分数のコマは放置などによる異常値とみなす（#T-003）
define('KOMA_ANOMALY_MINUTES', 1000);

const KOMA_DONE_STATUSES = ['completed', 'overtime_max', 'closed', 'auto_closed'];

function koma_is_done(array $k): bool {
    return in_array($k['status'] ?? 'idle', KOMA_DONE_STATUSES, true);
}

// 完了扱いは記録した時間、作業中・一時停止中は今までの経過で判定する（閉じ忘れたコマも数えないため。#I-015）
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
// コマ数 = 各コマの分数の合計 ÷ 80。完了扱いで20分超〜80分未満は80分扱い、20分以下は実分数。
// 作業中・一時停止中も実分数で含める。0分完了と異常値は数えない。

define('KOMA_UNIT_MINUTES', 80);
define('KOMA_SHORT_MINUTES', 20);

// 1日のコマ数で決まる実績（今日の実績）。カレンダーの色の段階にも使う
const KOMA_DAY_TIERS = [
    ['key' => 'full',  'min' => 6,  'name' => 'フルデイ'],
    ['key' => 'od',    'min' => 8,  'name' => 'オーバードライブ'],
    ['key' => 'hyper', 'min' => 10, 'name' => 'ハイパードライブ'],
    ['key' => 'limit', 'min' => 12, 'name' => 'リミットブレイク'],
];

/** コマの経過秒数。完了扱いは total_seconds、それ以外は segments から今の時刻で計算する */
function koma_seconds(array $k, ?int $now = null): int {
    if (koma_is_done($k)) return (int)($k['total_seconds'] ?? 0);
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
    if (koma_is_done($k) && $min > KOMA_SHORT_MINUTES && $min < KOMA_UNIT_MINUTES) $min = KOMA_UNIT_MINUTES;
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
