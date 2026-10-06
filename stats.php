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

// --- 異常値（全期間）---
$anomalies = find_anomaly_komas();

// --- タブ（#T-006）。旧 URL の週別・月別は「期間」に寄せる ---
$tabs = ['day' => '日別', 'period' => '期間', 'project' => 'プロジェクト', 'rhythm' => '時間帯', 'focus' => '集中度', 'records' => '記録'];
$query = fn(string $key): ?string => is_string($_GET[$key] ?? null) ? $_GET[$key] : null;  // 配列などは無視する
$tab  = $query('tab') ?? 'day';
$periodUnit = $query('unit') === 'month' ? 'month' : 'week';
if ($tab === 'week' || $tab === 'month') { $periodUnit = $tab; $tab = 'period'; }
if (!isset($tabs[$tab])) $tab = 'day';
$periodOffset = max(0, (int)($query('offset') ?? 0));
$project = $tab === 'project' ? $query('p') : null;

$selDate = $query('date') ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = $today;
$daySession = $tab === 'day' ? load_session($selDate) : null;

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
        <?php foreach ($tabs as $t => $l): ?>
            <a href="?tab=<?= $t ?><?= $t === 'day' ? '&amp;date=' . urlencode($selDate) : '' ?>"
               class="page-tab <?= $tab === $t ? 'is-active' : '' ?>"<?= $tab === $t ? ' aria-current="page"' : '' ?>>
                <?= $l ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'day'): ?>
    <!-- Date nav -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?php
        $prevDate = (new DateTime($selDate, $tz))->modify('-1 day')->format('Y-m-d');
        $nextDate = (new DateTime($selDate, $tz))->modify('+1 day')->format('Y-m-d');
        ?>
        <a href="?tab=day&date=<?= $prevDate ?>" class="btn-secondary">‹ 前日</a>
        <strong style="font-size:14px;"><?= htmlspecialchars($selDate) ?></strong>
        <a href="?tab=day&date=<?= $nextDate ?>" class="btn-secondary">翌日 ›</a>
        <a href="?tab=day&date=<?= $today ?>" class="btn-secondary">今日</a>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'day'): ?>
    <!-- ===== DAY VIEW ===== -->
    <?php
    $komas       = $daySession['koma'] ?? [];
    $totalSec    = 0;
    $komaCount   = 0;
    $komaValue   = 0.0;  // トップと同じ数え方（分数 ÷ 80。短い完了は80分扱い）
    usort($komas, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    foreach ($komas as $k) {
        $totalSec += (int)($k['total_seconds'] ?? 0);
        if (koma_is_done($k)) $komaCount++;
        $komaValue += koma_value($k);
    }
    ?>
    <div class="card">
        <div class="card__title">サマリー</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">コマ数</div>
                <div style="font-size:24px;font-weight:700;"><?= number_format($komaValue, 1) ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">完了したコマ</div>
                <div style="font-size:24px;font-weight:700;"><?= $komaCount ?>個</div>
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

    <?php elseif ($tab === 'period'): ?>
    <!-- ===== 期間（週・月） ===== -->
    <div class="stats-toolbar">
        <div class="seg" role="group" aria-label="期間の単位">
            <button type="button" data-unit="week">週</button>
            <button type="button" data-unit="month">月</button>
        </div>
        <button type="button" class="btn-secondary" id="period-prev">‹ 前</button>
        <strong class="stats-range" id="period-label"></strong>
        <button type="button" class="btn-secondary" id="period-next">次 ›</button>
    </div>
    <div class="tiles" id="period-tiles"></div>
    <section class="panel">
        <h2 class="panel__title">日別のコマ数<span class="sub">色はプロジェクト・点線はフルデイ（6コマ）</span></h2>
        <div class="chart" id="period-bars"></div>
        <div class="legend" id="period-legend"></div>
    </section>
    <div class="grid2">
        <section class="panel">
            <h2 class="panel__title">ペース<span class="sub">累計コマ数を前の期間と比べる</span></h2>
            <div class="chart" id="period-pace"></div>
            <div class="legend"><span><i class="line" style="background:var(--p1)"></i>この期間</span><span><i class="dash"></i>前の期間</span></div>
        </section>
        <section class="panel">
            <h2 class="panel__title">プロジェクトの内訳</h2>
            <div class="hbars" id="period-projects"></div>
        </section>
    </div>
    <section class="panel">
        <h2 class="panel__title">曜日ごとの平均<span class="sub">この期間・稼働した日だけ</span></h2>
        <div class="hbars" id="period-weekday"></div>
        <details class="stats-details"><summary>日別の表を見る</summary><table class="data-table" id="period-table"></table></details>
    </section>

    <?php elseif ($tab === 'project' && $project === null): ?>
    <!-- ===== プロジェクト（一覧） ===== -->
    <div class="stats-toolbar">
        <div class="seg" role="group" aria-label="集計する期間">
            <button type="button" data-prange="30" aria-pressed="true">30日</button>
            <button type="button" data-prange="90" aria-pressed="false">90日</button>
            <button type="button" data-prange="all" aria-pressed="false">全期間</button>
        </div>
    </div>
    <div class="grid2">
        <section class="panel">
            <h2 class="panel__title">割合<span class="sub" id="proj-total"></span></h2>
            <div class="hbars" id="proj-share"></div>
        </section>
        <section class="panel">
            <h2 class="panel__title">週ごとの推移<span class="sub">直近12週・上位5件＋その他</span></h2>
            <div class="chart" id="proj-weekly"></div>
            <div class="legend" id="proj-legend"></div>
        </section>
    </div>
    <section class="panel">
        <h2 class="panel__title">プロジェクト一覧<span class="sub">全期間のすべてのプロジェクト・名前を押すと単体の統計へ</span></h2>
        <div class="table-scroll"><table class="data-table" id="proj-table"></table></div>
    </section>

    <?php elseif ($tab === 'project'): ?>
    <!-- ===== プロジェクト単体 ===== -->
    <a class="stats-back" href="?tab=project">‹ プロジェクト一覧へ</a>
    <div class="pd-head"><h2 id="pd-name"></h2><span class="sub" id="pd-span"></span></div>
    <div class="tiles" id="pd-tiles"></div>
    <section class="panel">
        <h2 class="panel__title">週ごとのコマ数<span class="sub">直近26週</span></h2>
        <div class="chart" id="pd-weekly"></div>
    </section>
    <div class="grid2">
        <section class="panel">
            <h2 class="panel__title">作業内容の内訳<span class="sub">全期間・上位15件</span></h2>
            <div class="hbars" id="pd-names"></div>
        </section>
        <section class="panel">
            <h2 class="panel__title">曜日 × 時刻<span class="sub">全期間・このプロジェクトだけ</span></h2>
            <div class="heat" id="pd-heat"></div>
            <div class="seq-legend">少ない <i></i><i data-q="1"></i><i data-q="2"></i><i data-q="3"></i><i data-q="4"></i><i data-q="5"></i> 多い</div>
        </section>
    </div>
    <section class="panel">
        <h2 class="panel__title">1年のカレンダー<span class="sub">このプロジェクトのコマ数</span></h2>
        <div class="year"><div class="year__months" id="pd-year-months"></div><div class="year__grid" id="pd-year-grid"></div></div>
        <div class="seq-legend">0 <i></i><i data-q="1"></i><i data-q="2"></i><i data-q="3"></i><i data-q="4"></i><i data-q="5"></i> 5+</div>
    </section>
    <section class="panel">
        <h2 class="panel__title">最近のコマ<span class="sub">新しい順に20件</span></h2>
        <div class="table-scroll"><table class="data-table" id="pd-recent"></table></div>
    </section>

    <?php elseif ($tab === 'rhythm'): ?>
    <!-- ===== 時間帯 ===== -->
    <p class="notice notice-info" id="rhythm-empty" hidden>直近8週に完了したコマがないため、時間帯の集計は空になっています。</p>
    <div class="tiles" id="rhythm-tiles"></div>
    <section class="panel">
        <h2 class="panel__title">曜日 × 時刻<span class="sub">直近8週・作業していた分数の合計</span></h2>
        <div class="heat" id="heat"></div>
        <div class="seq-legend">少ない <i></i><i data-q="1"></i><i data-q="2"></i><i data-q="3"></i><i data-q="4"></i><i data-q="5"></i> 多い</div>
    </section>
    <section class="panel">
        <h2 class="panel__title">始業と終業<span class="sub">直近30日・最初のコマの開始〜最後のコマの終了</span></h2>
        <div class="chart" id="rhythm-range"></div>
        <div class="legend"><span><i class="dot" style="background:var(--p1)"></i>始業</span><span><i class="dot" style="background:var(--p2)"></i>終業</span></div>
    </section>

    <?php elseif ($tab === 'focus'): ?>
    <!-- ===== 集中度 ===== -->
    <p class="notice notice-info" id="focus-empty" hidden>直近90日に完了したコマがないため、集中度の集計は空になっています。</p>
    <div class="tiles" id="focus-tiles"></div>
    <div class="grid2">
        <section class="panel">
            <h2 class="panel__title">コマの長さ<span class="sub">直近90日・10分ごと</span></h2>
            <div class="chart" id="focus-hist"></div>
            <div class="legend"><span><i style="background:var(--seq2)"></i>80分未満</span><span><i style="background:var(--accent)"></i>80〜99分</span><span><i style="background:var(--highlight)"></i>100分以上</span></div>
        </section>
        <section class="panel">
            <h2 class="panel__title">一時停止の回数<span class="sub">直近90日・1コマあたり</span></h2>
            <div class="hbars" id="focus-pauses"></div>
            <h2 class="panel__title" style="margin-top:18px">平均の長さの推移<span class="sub">週ごと</span></h2>
            <div class="chart" id="focus-trend"></div>
        </section>
    </div>

    <?php elseif ($tab === 'records'): ?>
    <!-- ===== 記録 ===== -->
    <section class="panel">
        <h2 class="panel__title">自己ベスト</h2>
        <div class="bests" id="bests"></div>
    </section>
    <section class="panel">
        <h2 class="panel__title">1年のカレンダー<span class="sub" id="year-sub"></span></h2>
        <div class="year"><div class="year__months" id="year-months"></div><div class="year__grid" id="year-grid"></div></div>
        <div class="seq-legend">0 <i></i><i data-q="1"></i><i data-q="2"></i><i data-q="3"></i><i data-q="4"></i><i data-q="5"></i> 10+</div>
    </section>
    <section class="panel">
        <h2 class="panel__title">月ごとの推移<span class="sub">直近12か月・点線はマンスリー120</span></h2>
        <div class="chart" id="monthly"></div>
        <div class="legend" id="monthly-legend"></div>
    </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php if ($tab !== 'day'): ?>
<script>
    window.STATS = {
        tab:     <?= json_encode($tab) ?>,
        today:   <?= json_encode($today) ?>,
        period:  { unit: <?= json_encode($periodUnit) ?>, offset: <?= $periodOffset ?> },
        project: <?= json_encode($project, JSON_UNESCAPED_UNICODE) ?>,
        komas:   <?= json_encode(koma_stats_records(), JSON_UNESCAPED_UNICODE) ?>,
    };
</script>
<script src="/assets/js/stats.js"></script>
<?php endif; ?>

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
