<?php
/**
 * Main timer page
 * EMBED_MODE: defined by embed.php — suppresses header/footer
 */

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
auth_require_page();

$config      = load_config();
$currentUser = get_koma_user();
$komaCount   = (int)$config['koma_count'];
$today       = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$session     = load_session($today);

// Build koma state map by slot
$komaMap = [];
foreach ($session['koma'] as $k) {
    $komaMap[(int)$k['id']] = $k;
}

// Collect prev_incomplete slots so we can exclude them from today's grid
$tz2 = new DateTimeZone('Asia/Tokyo');
$yesterday = (new DateTime('-1 day', $tz2))->format('Y-m-d');
$prevSession = load_session($yesterday);
$prevIncomplete = [];
$prevIncompleteSlots = [];
foreach ($prevSession['koma'] as $pk) {
    if (!in_array($pk['status'], ['running', 'paused', 'overtime'])) continue;
    // Recompute total_seconds so JS receives an accurate value at page-render time.
    $total = 0;
    $now_dt = new DateTime('now', $tz2);
    foreach (($pk['segments'] ?? []) as $seg) {
        $s = new DateTime($seg['start'], $tz2);
        $e = isset($seg['end']) ? new DateTime($seg['end'], $tz2) : $now_dt;
        $diff = $e->getTimestamp() - $s->getTimestamp();
        if ($diff > 0) $total += $diff;
    }
    $pk['total_seconds'] = $total;
    $prevIncomplete[] = ['date' => $yesterday, 'koma' => $pk];
    $prevIncompleteSlots[(int)$pk['id']] = true;
}

// Render at least $komaCount slots, but also show any extra slots already in today's session.
// Skip slots that are being shown in prev_incomplete to avoid duplicate cards.
// Only count slots that have actually been worked on (have segments or non-idle status)
// so that phantom idle komas from a previous day's dynamic addKoma don't inflate the count.
$maxExistingSlot = 0;
foreach ($komaMap as $slotId => $k) {
    // Exclude only truly empty phantom komas (idle, no segments, no user input).
    // Komas with name/project_id or non-idle status are always counted.
    $isPhantom = $k['status'] === 'idle'
        && empty($k['segments'])
        && empty($k['name'])
        && empty($k['project_id']);
    if (!$isPhantom) {
        $maxExistingSlot = max($maxExistingSlot, (int)$slotId);
    }
}
$renderSlotCount = max($komaCount, (int)($session['slot_count'] ?? 0), $maxExistingSlot);

$isEmbed = defined('EMBED_MODE') && EMBED_MODE;

// Build recent koma history (past 14 days, completed komas with content)
$recentHistory = [];
if (!$isEmbed) {
    $tz3 = new DateTimeZone('Asia/Tokyo');
    for ($d = 1; $d <= 14; $d++) {
        $hDate = (new DateTime("-{$d} days", $tz3))->format('Y-m-d');
        $hPath = session_data_path($hDate);
        if (!file_exists($hPath)) continue;
        $hSession = load_session($hDate);
        foreach ($hSession['koma'] as $hk) {
            if (!in_array($hk['status'], ['completed', 'closed', 'auto_closed'])) continue;
            if (empty($hk['name']) && empty($hk['project_id'])) continue;
            $recentHistory[] = [
                'date'       => $hDate,
                'slot'       => (int)$hk['id'],
                'name'       => $hk['name'] ?? '',
                'project_id' => $hk['project_id'] ?? '',
                'total_seconds' => (int)($hk['total_seconds'] ?? 0),
            ];
        }
    }
    // Sort newest first (already added in date desc order, but sort by date+slot to be safe)
    usort($recentHistory, fn($a, $b) =>
        $b['date'] <=> $a['date'] ?: $b['slot'] <=> $a['slot']
    );
    $recentHistory = array_slice($recentHistory, 0, 40);
}

function status_label(string $status): string {
    return match($status) {
        'running'      => '実行中',
        'paused'       => '一時停止',
        'completed'    => '完了',
        'overtime'     => '超過中',
        'overtime_max' => '超過完了',
        default        => '未開始',
    };
}

function status_class(string $status): string {
    return match($status) {
        'running'      => 'is-running',
        'paused'       => 'is-paused',
        'completed'    => 'is-completed',
        'overtime'     => 'is-overtime',
        'overtime_max' => 'is-overtime-max',
        default        => '',
    };
}
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include __DIR__ . '/includes/header.php'; ?>
<?php endif; ?>

<main class="page-main">
    <?php if (!$isEmbed): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h1 class="page-title" style="margin:0;"><?= htmlspecialchars($today) ?> のコマ</h1>
            <span style="font-size:12px;color:var(--text-muted);">
                <?= htmlspecialchars($currentUser['nickname']) ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- 前日の未完了コマエリア（JSで描画） -->
    <div id="prev-incomplete-area"></div>

    <div class="koma-grid" id="koma-grid">
        <?php for ($slot = 1; $slot <= $renderSlotCount; $slot++):
            $k      = $komaMap[$slot] ?? null;
            $status = $k['status'] ?? 'idle';
            $sClass = status_class($status);
            $sLabel = status_label($status);
            $name   = $k['name'] ?? '';
            $projId = $k['project_id'] ?? '';
            $breakAfter = (bool)($k['break_after'] ?? false);
        ?>
        <div class="koma-card <?= $sClass ?>" id="koma-card-<?= $slot ?>" data-slot="<?= $slot ?>">

            <!-- Header row -->
            <div class="koma-card__header">
                <span class="koma-card__slot">コマ <?= $slot ?></span>
                <span class="koma-card__status-badge <?= htmlspecialchars($status) ?>" id="koma-status-<?= $slot ?>">
                    <?= htmlspecialchars($sLabel) ?>
                </span>
            </div>

            <!-- Name input -->
            <input
                type="text"
                class="koma-card__name-input"
                id="koma-name-<?= $slot ?>"
                placeholder="作業内容"
                value="<?= htmlspecialchars($name) ?>"
                data-slot="<?= $slot ?>"
            >

            <!-- Project ID input with datalist -->
            <input
                type="text"
                class="koma-card__project-input"
                id="koma-project-<?= $slot ?>"
                placeholder="#project/"
                value="<?= htmlspecialchars($projId) ?>"
                list="project-history-list"
                data-slot="<?= $slot ?>"
            >

            <!-- Progress bar -->
            <div class="koma-card__progress-wrap">
                <div class="koma-card__progress-bar">
                    <div class="koma-card__progress-fill" id="koma-fill-<?= $slot ?>" style="width:0%"></div>
                </div>
                <div class="koma-card__progress-labels">
                    <span class="koma-card__progress-pct" id="koma-pct-<?= $slot ?>">0%</span>
                    <span class="koma-card__overtime-label" id="koma-overtime-<?= $slot ?>" style="display:none"></span>
                </div>
            </div>

            <!-- Time display -->
            <div class="koma-card__time">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">経過</div>
                    <div class="koma-card__time-elapsed" id="koma-elapsed-<?= $slot ?>">0:00:00</div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">残り</div>
                    <div class="koma-card__time-remaining" id="koma-remaining-<?= $slot ?>">1:20:00</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="koma-card__actions">
                <button class="btn btn-start"    id="btn-start-<?= $slot ?>"    data-slot="<?= $slot ?>">開始</button>
                <button class="btn btn-pause"    id="btn-pause-<?= $slot ?>"    data-slot="<?= $slot ?>" style="display:none">停止</button>
                <button class="btn btn-complete" id="btn-complete-<?= $slot ?>" data-slot="<?= $slot ?>"
                    <?= $status === 'idle' ? 'disabled' : '' ?>>完了</button>
                <button class="btn btn-secondary btn-reset" id="btn-reset-<?= $slot ?>" data-slot="<?= $slot ?>"
                    style="display:none;padding:6px 10px;">リセット</button>
                <button class="btn btn-round" id="btn-round-<?= $slot ?>" data-slot="<?= $slot ?>"
                    style="display:none;padding:6px 10px;">100分に丸める</button>
            </div>

            <!-- Break checkbox -->
            <label class="koma-card__break<?= $breakAfter ? ' is-checked' : '' ?>" id="koma-break-label-<?= $slot ?>">
                <input
                    type="checkbox"
                    id="koma-break-<?= $slot ?>"
                    data-slot="<?= $slot ?>"
                    <?= $breakAfter ? 'checked' : '' ?>
                >
                10分休憩
            </label>

        </div>
        <?php endfor; ?>

        <!-- Add koma button — rendered as a grid cell -->
        <button class="koma-add-btn" id="btn-add-koma" title="コマを追加">
            <span class="koma-add-btn__icon">+</span>
            <span class="koma-add-btn__label">コマを追加</span>
        </button>
    </div>
    <?php if (!$isEmbed && !empty($recentHistory)): ?>
    <!-- 履歴エリア -->
    <div class="koma-history" id="koma-history-area">
        <button class="koma-history__toggle" id="btn-history-toggle">
            <span>履歴</span>
            <span class="koma-history__toggle-icon" id="history-toggle-icon">▼</span>
        </button>
        <div class="koma-history__body" id="koma-history-body">
            <table class="koma-history__table">
                <thead>
                    <tr>
                        <th>日付</th>
                        <th>コマ</th>
                        <th>内容</th>
                        <th>プロジェクト</th>
                        <th>時間</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="koma-history-tbody">
                <?php foreach ($recentHistory as $h):
                    $min = (int)round($h['total_seconds'] / 60);
                ?>
                    <tr class="koma-history__row"
                        data-name="<?= htmlspecialchars($h['name']) ?>"
                        data-project="<?= htmlspecialchars($h['project_id']) ?>">
                        <td class="koma-history__date"><?= htmlspecialchars($h['date']) ?></td>
                        <td class="koma-history__slot">コマ<?= $h['slot'] ?></td>
                        <td class="koma-history__name"><?= htmlspecialchars($h['name'] ?: '—') ?></td>
                        <td class="koma-history__project"><?= htmlspecialchars($h['project_id'] ?: '—') ?></td>
                        <td class="koma-history__time"><?= $min ?>分</td>
                        <td><button class="btn btn-secondary btn-history-copy" style="padding:4px 10px;font-size:12px;">コピー</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Datalist for project history -->
<datalist id="project-history-list">
    <?php foreach ($currentUser['project_history'] as $ph): ?>
        <option value="<?= htmlspecialchars($ph) ?>">
    <?php endforeach; ?>
</datalist>

<?php if (!$isEmbed): ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>

<script>
    window.KOMA_CONFIG = {
        komaCount:       <?= $renderSlotCount ?>,
        komaDurationSec: <?= (int)$config['koma_duration_minutes'] * 60 ?>,
        maxDurationSec:  <?= (int)$config['max_duration_minutes'] * 60 ?>,
        today:           "<?= $today ?>",
        serverNow:       <?= (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->getTimestamp() * 1000 ?>,
        initialState:    <?= json_encode($session, JSON_UNESCAPED_UNICODE) ?>,
        prevIncomplete:  <?= json_encode($prevIncomplete, JSON_UNESCAPED_UNICODE) ?>,
        recentHistory:   <?= json_encode($recentHistory, JSON_UNESCAPED_UNICODE) ?>,
    };
</script>
<script src="/assets/js/timer.js"></script>
</body>
</html>
