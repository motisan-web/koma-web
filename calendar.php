<?php
/**
 * カレンダー（休み・稼働日の登録。#T-014）
 * 曜日の既定（設定の week_off_days）に対する日付ごとの例外を、日付をクリックして 既定 → 休み → 稼働 → 既定 と切り替える。
 * 保存は api/timer.php の set_day_type。トップの週の状況（目標・遅れ/進み）がこの値を使う。
 */
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
$offDays     = koma_week_off_days($config['week_off_days'] ?? null);
$calendar    = koma_calendar_load();
$daily       = koma_daily_all();

// 表示する月（?month=YYYY-MM。不正なら今月）
$month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) $month = substr($today, 0, 7);
$first     = new DateTime($month . '-01', $tz);
$daysCount = (int)$first->format('t');
$leading   = (int)$first->format('w');   // 月初の曜日 = 先頭の空きマス数
$prevMonth = (clone $first)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $first)->modify('+1 month')->format('Y-m');
$dowNames  = ['日', '月', '火', '水', '木', '金', '土'];
$offNames  = implode('・', array_map(fn($d) => $dowNames[$d], $offDays)) ?: 'なし';
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>カレンダー — コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
    <link rel="stylesheet" href="/assets/css/calendar.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="page-main" style="max-width:760px;">
    <h1 class="page-title">カレンダー</h1>

    <div class="card">
        <p style="font-size:12px;color:var(--text-muted);line-height:1.7;margin-bottom:12px;">
            休みの日・稼働する日を日付ごとに登録します。日付をクリックするたびに 既定 → 休み → 稼働 → 既定 と切り替わります。
            何も登録しない日は、設定の「休みの曜日」（<?= htmlspecialchars($offNames) ?>）に従います。
            トップの「週の状況」は、その週の稼働日数で1日の目標を割ります（登録が影響するのはその週の表示だけです）。
        </p>

        <div class="cal-toolbar">
            <a class="btn-secondary" href="?month=<?= $prevMonth ?>">‹ 前の月</a>
            <div class="cal-toolbar__title"><?= (int)$first->format('Y') ?>年<?= (int)$first->format('n') ?>月</div>
            <a class="btn-secondary" href="?month=<?= $nextMonth ?>">次の月 ›</a>
            <?php if ($month !== substr($today, 0, 7)): ?>
                <a class="btn-secondary" href="?month=<?= substr($today, 0, 7) ?>">今月</a>
            <?php endif; ?>
        </div>

        <div class="cal-grid" id="cal-grid">
            <?php foreach ($dowNames as $i => $n): ?>
                <div class="cal-grid__dow<?= $i === 0 ? ' is-sun' : ($i === 6 ? ' is-sat' : '') ?>"><?= $n ?></div>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $leading; $i++): ?><div class="cal-day cal-day--blank"></div><?php endfor; ?>
            <?php for ($d = 1; $d <= $daysCount; $d++):
                $date    = sprintf('%s-%02d', $month, $d);
                $dow     = ($leading + $d - 1) % 7;
                $exc     = $calendar[$date] ?? '';
                $defOff  = in_array($dow, $offDays, true);
                $koma    = $daily[$date]['koma'] ?? 0.0;
                $classes = 'cal-day';
                if ($exc === 'off')       $classes .= ' is-off';
                elseif ($exc === 'work')  $classes .= ' is-work';
                elseif ($defOff)          $classes .= ' is-default-off';
                if ($date === $today)     $classes .= ' is-today';
            ?>
            <button type="button" class="<?= $classes ?>" data-date="<?= $date ?>" data-exception="<?= $exc ?>" data-default-off="<?= $defOff ? '1' : '0' ?>">
                <span class="cal-day__num"><?= $d ?></span>
                <span class="cal-day__label"></span>
                <span class="cal-day__koma"><?= $koma > 0 ? number_format($koma, 1) . 'コマ' : '' ?></span>
            </button>
            <?php endfor; ?>
        </div>

        <div class="cal-legend">
            <span><i class="cal-legend__chip"></i>稼働（既定）</span>
            <span><i class="cal-legend__chip is-default-off"></i>休み（曜日の既定）</span>
            <span><i class="cal-legend__chip is-off"></i>休み（日付で登録）</span>
            <span><i class="cal-legend__chip is-work"></i>稼働（日付で登録）</span>
        </div>
        <div class="notice notice-error" id="cal-error" hidden></div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/calendar.js"></script>
</body>
</html>
