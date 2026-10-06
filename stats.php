<?php
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/koma_stats.php';
require_once __DIR__ . '/includes/auth.php';
auth_require_page();

$config      = load_config();
$currentUser = get_koma_user();
$tz          = new DateTimeZone('Asia/Tokyo');
$today       = (new DateTime('now', $tz))->format('Y-m-d');
$komaDur     = (int)$config['koma_duration_minutes'];

// --- Date range helpers ---

function week_range(string $date, DateTimeZone $tz): array {
    $dt  = new DateTime($date, $tz);
    $dow = (int)$dt->format('N'); // 1=Mon
    $start = clone $dt;
    $start->modify('-' . ($dow - 1) . ' days');
    $end = clone $start;
    $end->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function month_range(string $date, DateTimeZone $tz): array {
    $dt    = new DateTime($date, $tz);
    $start = (new DateTime($dt->format('Y-m-01'), $tz))->format('Y-m-d');
    $end   = (new DateTime($dt->format('Y-m-t'),  $tz))->format('Y-m-d');
    return [$start, $end];
}

function date_range_list(string $from, string $to, DateTimeZone $tz): array {
    $dates = [];
    $cur   = new DateTime($from, $tz);
    $last  = new DateTime($to,   $tz);
    while ($cur <= $last) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $dates;
}

function load_sessions_range(string $from, string $to): array {
    $sessions = [];
    $tz = new DateTimeZone('Asia/Tokyo');
    $cur  = new DateTime($from, $tz);
    $last = new DateTime($to,   $tz);
    while ($cur <= $last) {
        $date = $cur->format('Y-m-d');
        $path = session_data_path($date);
        if (file_exists($path)) {
            $s = load_session($date);
            if (!empty($s['koma'])) {
                $sessions[$date] = $s;
            }
        }
        $cur->modify('+1 day');
    }
    return $sessions;
}

// --- 異常値（全期間）---
$anomalies = find_anomaly_komas();

// --- Tab / date selection ---
$tab = $_GET['tab'] ?? 'day';
$selDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = $today;

[$weekStart, $weekEnd]   = week_range($selDate, $tz);
[$monthStart, $monthEnd] = month_range($selDate, $tz);

// --- Load data for current tab ---
$daySession  = null;
$rangeSessions = [];

if ($tab === 'day') {
    $daySession = load_session($selDate);
} elseif ($tab === 'week') {
    $rangeSessions = load_sessions_range($weekStart, $weekEnd);
} elseif ($tab === 'month') {
    $rangeSessions = load_sessions_range($monthStart, $monthEnd);
} elseif ($tab === 'project') {
    // Past 90 days
    $projFrom = (new DateTime('-90 days', $tz))->format('Y-m-d');
    $rangeSessions = load_sessions_range($projFrom, $today);
}

// --- Aggregate helpers ---

function aggregate_days(array $sessions, int $komaDurSec): array {
    $days = [];
    foreach ($sessions as $date => $s) {
        $komaCount  = 0;
        $totalSec   = 0;
        $overtimeSec = 0;
        foreach ($s['koma'] as $k) {
            if (in_array($k['status'], ['completed', 'overtime_max', 'closed', 'auto_closed'])) {
                $komaCount++;
            }
            $totalSec   += (int)($k['total_seconds'] ?? 0);
            $overtimeSec += (int)($k['overtime_seconds'] ?? 0);
        }
        $days[$date] = [
            'koma_count'    => $komaCount,
            'total_sec'     => $totalSec,
            'overtime_sec'  => $overtimeSec,
            'koma_raw'      => $s['koma'],
        ];
    }
    return $days;
}

function aggregate_projects(array $sessions): array {
    $proj = [];
    foreach ($sessions as $date => $s) {
        foreach ($s['koma'] as $k) {
            $pid = $k['project_id'] ?: '(未設定)';
            $proj[$pid] = ($proj[$pid] ?? 0) + (int)($k['total_seconds'] ?? 0);
        }
    }
    arsort($proj);
    return $proj;
}

$dayAgg  = [];
$projAgg = [];
if (in_array($tab, ['week', 'month'])) {
    $dayAgg = aggregate_days($rangeSessions, $komaDur * 60);
}
if ($tab === 'project') {
    $projAgg = aggregate_projects($rangeSessions);
}

function fmt_min(int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) return "{$h}時間{$m}分";
    return "{$m}分";
}
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>統計 — コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="page-main">
    <h1 class="page-title">統計</h1>

    <?php if (!empty($anomalies)): ?>
    <div class="notice notice-error anomaly-list">
        <strong>⚠ 異常値のコマが <?= count($anomalies) ?> 件あります</strong>（<?= KOMA_ANOMALY_MINUTES ?>分以上。放置していた場合は時間を直してください）
        <ul>
            <?php foreach ($anomalies as $a): ?>
                <li>
                    <a href="?tab=day&date=<?= urlencode($a['date']) ?>#koma-row-<?= (int)$a['koma']['id'] ?>">
                        <?= htmlspecialchars($a['date']) ?> コマ<?= (int)$a['koma']['id'] ?>
                    </a>
                    — <?= htmlspecialchars($a['koma']['name'] ?: '(内容なし)') ?>
                    （<?= fmt_min((int)$a['koma']['total_seconds']) ?>）
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="page-tabs">
        <?php foreach (['day' => '日別', 'week' => '週別', 'month' => '月別', 'project' => 'プロジェクト別'] as $t => $l): ?>
            <a href="?tab=<?= $t ?>&date=<?= urlencode($selDate) ?>"
               class="page-tab <?= $tab === $t ? 'is-active' : '' ?>">
                <?= $l ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Date nav -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?php
        $prevDate = (new DateTime($selDate, $tz))->modify('-1 day')->format('Y-m-d');
        $nextDate = (new DateTime($selDate, $tz))->modify('+1 day')->format('Y-m-d');
        $prevWeek = (new DateTime($selDate, $tz))->modify('-7 days')->format('Y-m-d');
        $nextWeek = (new DateTime($selDate, $tz))->modify('+7 days')->format('Y-m-d');
        $prevMonth = (new DateTime($selDate, $tz))->modify('-1 month')->format('Y-m-d');
        $nextMonth = (new DateTime($selDate, $tz))->modify('+1 month')->format('Y-m-d');

        if ($tab === 'day'):
        ?>
            <a href="?tab=day&date=<?= $prevDate ?>" class="btn-secondary">‹ 前日</a>
            <strong style="font-size:14px;"><?= htmlspecialchars($selDate) ?></strong>
            <a href="?tab=day&date=<?= $nextDate ?>" class="btn-secondary">翌日 ›</a>
            <a href="?tab=day&date=<?= $today ?>" class="btn-secondary">今日</a>
        <?php elseif ($tab === 'week'): ?>
            <a href="?tab=week&date=<?= $prevWeek ?>" class="btn-secondary">‹ 前週</a>
            <strong style="font-size:14px;"><?= $weekStart ?> 〜 <?= $weekEnd ?></strong>
            <a href="?tab=week&date=<?= $nextWeek ?>" class="btn-secondary">翌週 ›</a>
        <?php elseif ($tab === 'month'): ?>
            <a href="?tab=month&date=<?= $prevMonth ?>" class="btn-secondary">‹ 前月</a>
            <strong style="font-size:14px;"><?= (new DateTime($selDate, $tz))->format('Y年n月') ?></strong>
            <a href="?tab=month&date=<?= $nextMonth ?>" class="btn-secondary">翌月 ›</a>
        <?php elseif ($tab === 'project'): ?>
            <strong style="font-size:14px;">過去90日間</strong>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'day'): ?>
    <!-- ===== DAY VIEW ===== -->
    <?php
    $komas       = $daySession['koma'] ?? [];
    $totalSec    = 0;
    $komaCount   = 0;
    usort($komas, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    foreach ($komas as $k) {
        $totalSec += (int)($k['total_seconds'] ?? 0);
        if (in_array($k['status'], ['completed', 'overtime_max'])) $komaCount++;
    }
    ?>
    <div class="card">
        <div class="card__title">サマリー</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">完了コマ数</div>
                <div style="font-size:24px;font-weight:700;"><?= $komaCount ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">合計作業時間</div>
                <div style="font-size:24px;font-weight:700;"><?= fmt_min($totalSec) ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($komas)): ?>
    <div class="card">
        <div class="card__title">コマ一覧</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th><th>内容</th><th>プロジェクト</th>
                    <th>実時間</th><th>超過</th><th>状態</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($komas as $i => $k):
                $sec     = (int)($k['total_seconds'] ?? 0);
                $overSec = (int)($k['overtime_seconds'] ?? 0);
                $status  = $k['status'] ?? 'idle';
                $badgeCls = match($status) {
                    'completed', 'overtime_max'  => 'badge-green',
                    'running', 'overtime'         => 'badge-blue',
                    'closed', 'auto_closed'       => 'badge-orange',
                    default                       => 'badge-muted',
                };
                $statusLbl = match($status) {
                    'completed'    => '完了',
                    'overtime_max' => '完了(超過)',
                    'running'      => '実行中',
                    'overtime'     => '超過中',
                    'paused'       => '停止中',
                    'closed'       => '中止',
                    'auto_closed'  => '自動中止',
                    default        => '未実行',
                };
            ?>
                <?php $anomaly = koma_is_anomaly($k); $editable = koma_is_done($k) && $status !== 'overtime_max'; ?>
                <tr id="koma-row-<?= (int)$k['id'] ?>" class="<?= $anomaly ? 'is-anomaly' : '' ?>">
                    <td style="color:var(--text-muted);">コマ<?= (int)$k['id'] ?></td>
                    <td><?= htmlspecialchars($k['name'] ?: '—') ?></td>
                    <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($k['project_id'] ?: '—') ?></td>
                    <td>
                        <?= fmt_min($sec) ?>
                        <?php if ($anomaly): ?><span class="badge badge-red">異常値・要編集</span><?php endif; ?>
                        <?php if (!empty($k['edited_at'])): ?><span class="badge badge-muted" title="元の時間: <?= fmt_min((int)($k['original_total_seconds'] ?? 0)) ?>">編集済み</span><?php endif; ?>
                    </td>
                    <td><?= $overSec > 0 ? '<span class="badge badge-orange">+' . fmt_min($overSec) . '</span>' : '—' ?></td>
                    <td><span class="badge <?= $badgeCls ?>"><?= $statusLbl ?></span></td>
                    <td>
                        <?php if ($editable): ?>
                            <button type="button" class="btn-secondary btn-edit-koma" style="font-size:12px;" data-slot="<?= (int)$k['id'] ?>">編集</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($editable): ?>
                <tr class="koma-edit-row" id="koma-edit-<?= (int)$k['id'] ?>" hidden>
                    <td colspan="7">
                        <form class="koma-edit-form" data-slot="<?= (int)$k['id'] ?>">
                            <label>時間（分）<input type="number" name="total_minutes" min="0" max="<?= KOMA_ANOMALY_MINUTES - 1 ?>" step="1" required value="<?= intdiv($sec, 60) ?>"></label>
                            <label>内容<input type="text" name="name" maxlength="200" value="<?= htmlspecialchars($k['name'] ?? '') ?>"></label>
                            <label>プロジェクト<input type="text" name="project_id" maxlength="100" value="<?= htmlspecialchars($k['project_id'] ?? '') ?>"></label>
                            <button type="submit" class="btn btn-complete" style="font-size:12px;">保存</button>
                            <button type="button" class="btn-secondary btn-edit-cancel" style="font-size:12px;">キャンセル</button>
                            <span class="koma-edit-error"></span>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Markdown output -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div class="card__title" style="margin:0;">マークダウン出力</div>
            <div style="display:flex;gap:8px;">
                <button class="btn-secondary" id="btn-copy-md">コピー</button>
                <button class="btn-secondary" id="btn-dl-md">ダウンロード</button>
            </div>
        </div>
        <div class="markdown-output" id="md-output">読み込み中…</div>
    </div>
    <?php else: ?>
        <div class="notice notice-info">この日のコマデータはありません。</div>
    <?php endif; ?>

    <?php elseif ($tab === 'week' || $tab === 'month'): ?>
    <!-- ===== WEEK / MONTH VIEW ===== -->
    <?php
    $rangeLabel = $tab === 'week' ? "{$weekStart} 〜 {$weekEnd}" : (new DateTime($selDate, $tz))->format('Y年n月');
    $allDates   = $tab === 'week'
        ? date_range_list($weekStart, $weekEnd, $tz)
        : date_range_list($monthStart, $monthEnd, $tz);
    $totalSec   = 0;
    $totalKoma  = 0;
    foreach ($dayAgg as $agg) {
        $totalSec  += $agg['total_sec'];
        $totalKoma += $agg['koma_count'];
    }
    $activeDays = count($dayAgg);
    ?>
    <div class="card">
        <div class="card__title">サマリー</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">合計コマ数</div>
                <div style="font-size:24px;font-weight:700;"><?= $totalKoma ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">合計作業時間</div>
                <div style="font-size:24px;font-weight:700;"><?= fmt_min($totalSec) ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">稼働日数</div>
                <div style="font-size:24px;font-weight:700;"><?= $activeDays ?>日</div>
            </div>
            <?php if ($activeDays > 0): ?>
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">平均コマ数/日</div>
                <div style="font-size:24px;font-weight:700;"><?= round($totalKoma / $activeDays, 1) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__title">日別内訳</div>
        <table class="data-table">
            <thead>
                <tr><th>日付</th><th>完了コマ</th><th>作業時間</th><th>超過</th></tr>
            </thead>
            <tbody>
            <?php foreach ($allDates as $d):
                $agg = $dayAgg[$d] ?? null;
            ?>
                <tr>
                    <td>
                        <a href="?tab=day&date=<?= $d ?>" style="color:var(--accent-hover);">
                            <?= (new DateTime($d, $tz))->format('n/j(D)') ?>
                        </a>
                    </td>
                    <td><?= $agg ? $agg['koma_count'] . 'コマ' : '—' ?></td>
                    <td><?= $agg ? fmt_min($agg['total_sec']) : '—' ?></td>
                    <td><?= ($agg && $agg['overtime_sec'] > 0) ? '<span class="badge badge-orange">+'.fmt_min($agg['overtime_sec']).'</span>' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($tab === 'project'): ?>
    <!-- ===== PROJECT VIEW ===== -->
    <div class="card">
        <div class="card__title">プロジェクト別 累計時間（過去90日）</div>
        <?php if (empty($projAgg)): ?>
            <div class="notice notice-info">データがありません。</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>プロジェクト</th><th>累計時間</th></tr></thead>
            <tbody>
            <?php foreach ($projAgg as $pid => $sec): ?>
                <tr>
                    <td><?= htmlspecialchars($pid) ?></td>
                    <td><?= fmt_min($sec) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($tab === "day"): ?>
<script>
// 完了コマの事後編集（#T-002）
(() => {
    const date = <?= json_encode($selDate) ?>;
    document.querySelectorAll(".btn-edit-koma").forEach(btn => {
        btn.addEventListener("click", () => {
            const row = document.getElementById(`koma-edit-${btn.dataset.slot}`);
            row.hidden = !row.hidden;
        });
    });
    document.querySelectorAll(".btn-edit-cancel").forEach(btn => {
        btn.addEventListener("click", () => { btn.closest(".koma-edit-row").hidden = true; });
    });
    document.querySelectorAll(".koma-edit-form").forEach(form => {
        form.addEventListener("submit", async e => {
            e.preventDefault();
            const err = form.querySelector(".koma-edit-error");
            err.textContent = "";
            const res = await fetch("/api/timer.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "edit_koma",
                    date,
                    slot: +form.dataset.slot,
                    total_minutes: +form.elements.namedItem("total_minutes").value,
                    name: form.elements.namedItem("name").value,
                    project_id: form.elements.namedItem("project_id").value,
                }),
            }).then(r => r.json()).catch(() => ({ ok: false, error: "通信に失敗しました" }));
            if (res.ok) {
                location.reload();
            } else {
                err.textContent = res.error || "保存に失敗しました";
            }
        });
    });
})();
</script>
<?php endif; ?>
<?php if ($tab === 'day' && !empty($komas)): ?>
<script>
(async () => {
    const date = <?= json_encode($selDate) ?>;
    const res  = await fetch(`/api/output.php?date=${date}`);
    const data = await res.json();
    const mdEl = document.getElementById('md-output');
    if (data.ok) {
        mdEl.textContent = data.markdown;
    } else {
        mdEl.textContent = 'エラー: ' + (data.error || '不明');
    }

    document.getElementById('btn-copy-md').addEventListener('click', () => {
        navigator.clipboard.writeText(mdEl.textContent).then(() => {
            const btn = document.getElementById('btn-copy-md');
            btn.textContent = 'コピー済み!';
            setTimeout(() => btn.textContent = 'コピー', 2000);
        });
    });

    document.getElementById('btn-dl-md').addEventListener('click', () => {
        const blob = new Blob([mdEl.textContent], { type: 'text/plain;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = `koma-${date}.md`;
        a.click();
        URL.revokeObjectURL(url);
    });
})();
</script>
<?php endif; ?>
</body>
</html>
