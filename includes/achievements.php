<?php
/**
 * 実績（#T-005）。過去データにさかのぼって判定し、同じ実績は何度でも獲得できる。
 * 獲得は「イベント」（id 付き）として数え、宝箱で開封したイベントの id だけを保存する。
 */

require_once __DIR__ . '/koma_stats.php';
require_once __DIR__ . '/user.php';

const ACH_WEEK_GOAL      = 30;   // ウィークリー30
const ACH_MONTH_GOAL     = 120;  // マンスリー120
const ACH_TOTAL_STEP     = 100;  // 累計コマ: 100コマごと
const ACH_HOURS_STEP     = 100;  // 累計時間: 100時間ごと
const ACH_PROJECT_STEP   = 50;   // 職人: 同じプロジェクトで50コマごと
const ACH_PROJECT_DAYS   = 7;    // 職人は直近7日に触れたプロジェクトだけ表示
const ACH_STREAK_STEPS   = [7, 30];
const ACH_FULL_STREAK    = 3;    // フルデイ3連

// 見た目（形・色・アイコン）。key はイベント id の先頭と同じ
const ACH_LOOKS = [
    'full'     => ['shape' => 'sun',    'c' => ['#f4b942', '#9a6a12'], 'icon' => '☀'],
    'od'       => ['shape' => 'sun',    'c' => ['#ff7a59', '#9c2f17'], 'icon' => '⚡'],
    'hyper'    => ['shape' => 'sun',    'c' => ['#b28dff', '#4b2a8f'], 'icon' => '🚀'],
    'limit'    => ['shape' => 'sun',    'c' => ['#ff4f8b', '#8f1240'], 'icon' => '💥'],
    'week30'   => ['shape' => 'shield', 'c' => ['#6bc5ff', '#1f5f8f'], 'icon' => '📅'],
    'month120' => ['shape' => 'shield', 'c' => ['#b28dff', '#4b2a8f'], 'icon' => '🌙'],
    'perfect'  => ['shape' => 'shield', 'c' => ['#7be0a4', '#1f7a4a'], 'icon' => '🗓'],
    'total'    => ['shape' => 'gem',    'c' => ['#80deea', '#00838f'], 'icon' => '💎'],
    'hours'    => ['shape' => 'gem',    'c' => ['#ffab91', '#bf360c'], 'icon' => '⏱'],
    'proj'     => ['shape' => 'gem',    'c' => ['#a5d6a7', '#2e7d32'], 'icon' => '🛠'],
    'streak7'  => ['shape' => 'medal',  'c' => ['#ff8a65', '#bf360c'], 'icon' => '🔥'],
    'streak30' => ['shape' => 'medal',  'c' => ['#ff7043', '#8f1d00'], 'icon' => '🔥'],
    'full3'    => ['shape' => 'medal',  'c' => ['#ffe082', '#a07800'], 'icon' => '☀'],
];

function ach_svg(string $key, string $label = ''): string {
    $l = ACH_LOOKS[$key];
    [$c1, $c2] = $l['c'];
    $icon = htmlspecialchars($label !== '' ? $label : $l['icon']);
    return match ($l['shape']) {
        'sun'    => "<svg viewBox=\"0 0 64 64\" aria-hidden=\"true\"><circle cx=\"32\" cy=\"32\" r=\"26\" fill=\"$c1\" stroke=\"$c2\" stroke-width=\"3\"/><circle cx=\"32\" cy=\"32\" r=\"19\" fill=\"none\" stroke=\"#fff\" stroke-opacity=\".45\" stroke-width=\"2\" stroke-dasharray=\"3 4\"/><text x=\"32\" y=\"41\" font-size=\"24\" text-anchor=\"middle\">$icon</text></svg>",
        'shield' => "<svg viewBox=\"0 0 64 64\" aria-hidden=\"true\"><path d=\"M32 4 56 12v18c0 15-10 25-24 30C18 55 8 45 8 30V12z\" fill=\"$c1\" stroke=\"$c2\" stroke-width=\"3\"/><text x=\"32\" y=\"40\" font-size=\"22\" text-anchor=\"middle\">$icon</text></svg>",
        'gem'    => "<svg viewBox=\"0 0 64 64\" aria-hidden=\"true\"><path d=\"M12 22 23 7h18l11 15-20 35z\" fill=\"$c1\" stroke=\"$c2\" stroke-width=\"3\" stroke-linejoin=\"round\"/><path d=\"M12 22h40\" stroke=\"#fff\" stroke-opacity=\".45\" stroke-width=\"2\"/><text x=\"32\" y=\"42\" font-size=\"17\" text-anchor=\"middle\">$icon</text></svg>",
        default  => "<svg viewBox=\"0 0 64 64\" aria-hidden=\"true\"><path d=\"M20 3h10l4 18h-8zM44 3H34l-4 18h8z\" fill=\"$c2\"/><circle cx=\"32\" cy=\"39\" r=\"20\" fill=\"$c1\" stroke=\"$c2\" stroke-width=\"3\"/><text x=\"32\" y=\"47\" font-size=\"20\" text-anchor=\"middle\">$icon</text></svg>",
    };
}

function ach_fmt(float $n): string {
    return number_format(round($n, 1), 1);
}

function ach_project_name(string $pid): string {
    return preg_replace('#^\#?project/#', '', $pid);
}

/**
 * 実績をすべて判定する。
 * 返り値:
 *   groups   — サイドバーの一覧 [['label', 'side', 'items' => [...], 'note'], ...]
 *   progress — 次に取れる実績（今日のコマ数で決まる実績は JS が足す）
 *   events   — 獲得イベント [id => key]
 */
function achievements_build(array $daily, string $today): array {
    $tz = new DateTimeZone('Asia/Tokyo');

    // 最初のデータの日から今日まで、日付を欠けなく並べる
    $days = [];
    if ($daily) {
        $d = new DateTime(array_key_first($daily), $tz);
        $end = new DateTime($today, $tz);
        for (; $d <= $end; $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $days[$ymd] = $daily[$ymd]['koma'] ?? 0.0;
        }
    }
    $days[$today] ??= 0.0;
    $todayKoma = $days[$today];
    $weekStart = fn(string $ymd) => (new DateTime($ymd, $tz))->modify('-' . (new DateTime($ymd, $tz))->format('w') . ' days')->format('Y-m-d');
    $curWeek  = $weekStart($today);
    $curMonth = substr($today, 0, 7);
    $events = [];

    // ---- 今日の実績（1日のコマ数） ----
    $dayItems = [];
    foreach (KOMA_DAY_TIERS as $t) {
        $count = 0;
        foreach ($days as $ymd => $v) {
            if ($v >= $t['min']) { $count++; $events["{$t['key']}:$ymd"] = $t['key']; }
        }
        $got = $todayKoma >= $t['min'];
        $dayItems[] = [
            'key' => $t['key'], 'count' => $count, 'todayGot' => $got,
            'tip' => "<b>{$t['name']}</b><br><span class=\"tip__dim\">1日 {$t['min']}コマ以上</span><br>これまで {$count}回"
                . '<div class="tip__next">' . ($got ? '今日は獲得済み' : '今日あと <b>' . ach_fmt($t['min'] - $todayKoma) . '</b> コマで獲得') . '</div>',
        ];
    }

    // ---- 期間 ----
    $weeks = $months = $weekDays = [];
    foreach ($days as $ymd => $v) {
        $w = $weekStart($ymd);
        $weeks[$w] = ($weeks[$w] ?? 0) + $v;
        $months[substr($ymd, 0, 7)] = ($months[substr($ymd, 0, 7)] ?? 0) + $v;
        $weekDays[$w][] = $v;
    }
    $weekCount = $monthCount = $perfectCount = 0;
    foreach ($weeks as $w => $sum) {
        if ($sum >= ACH_WEEK_GOAL) { $weekCount++; $events["week30:$w"] = 'week30'; }
        // 皆勤: 日〜土の7日すべて1コマ以上（今週は土曜まで終わってから）
        if (count($weekDays[$w]) === 7 && min($weekDays[$w]) >= 1) { $perfectCount++; $events["perfect:$w"] = 'perfect'; }
    }
    foreach ($months as $m => $sum) {
        if ($sum >= ACH_MONTH_GOAL) { $monthCount++; $events["month120:$m"] = 'month120'; }
    }
    $weekKoma  = $weeks[$curWeek] ?? 0;
    $monthKoma = $months[$curMonth] ?? 0;
    $thisWeek  = $weekDays[$curWeek] ?? [];
    $pastOfWeek = array_slice($thisWeek, 0, -1);  // 今日より前の今週の日
    $perfectAlive = !$pastOfWeek || min($pastOfWeek) >= 1;
    $perfectDone  = count(array_filter($thisWeek, fn($v) => $v >= 1));

    $periodItems = [
        ['key' => 'week30', 'count' => $weekCount,
         'tip' => "<b>ウィークリー30</b><br><span class=\"tip__dim\">日〜土で30コマ</span><br>これまで {$weekCount}回<div class=\"tip__next\">"
            . ($weekKoma >= ACH_WEEK_GOAL ? '今週は獲得済み' : '今週 ' . ach_fmt($weekKoma) . ' コマ・あと <b>' . ach_fmt(ACH_WEEK_GOAL - $weekKoma) . '</b> コマ（土曜 24:00 まで）') . '</div>'],
        ['key' => 'month120', 'count' => $monthCount,
         'tip' => "<b>マンスリー120</b><br><span class=\"tip__dim\">1か月で120コマ</span><br>これまで {$monthCount}回<div class=\"tip__next\">"
            . ($monthKoma >= ACH_MONTH_GOAL ? '今月は獲得済み' : '今月 ' . ach_fmt($monthKoma) . ' コマ・あと <b>' . ach_fmt(ACH_MONTH_GOAL - $monthKoma) . '</b> コマ（月末まで）') . '</div>'],
        ['key' => 'perfect', 'count' => $perfectCount,
         'tip' => "<b>皆勤</b><br><span class=\"tip__dim\">日〜土の毎日1コマ以上</span><br>これまで {$perfectCount}回<div class=\"tip__next\">"
            . ($perfectAlive ? "今週は {$perfectDone}/7日 達成中" : '今週は達成できません（来週また挑戦）') . '</div>'],
    ];

    // ---- 累計 ----
    $total = array_sum($days);
    $hours = $total * KOMA_UNIT_MINUTES / 60;
    $totalN = (int)floor($total / ACH_TOTAL_STEP);
    $hoursN = (int)floor($hours / ACH_HOURS_STEP);
    for ($i = 1; $i <= $totalN; $i++) $events["total:$i"] = 'total';
    for ($i = 1; $i <= $hoursN; $i++) $events["hours:$i"] = 'hours';
    $totalItems = [
        ['key' => 'total', 'count' => $totalN,
         'tip' => '<b>累計コマ</b><br><span class="tip__dim">100コマごと</span><br>累計 ' . ach_fmt($total) . " コマ・{$totalN}回"
            . '<div class="tip__next">次の ' . (($totalN + 1) * ACH_TOTAL_STEP) . ' コマまで あと <b>' . ach_fmt(($totalN + 1) * ACH_TOTAL_STEP - $total) . '</b></div>'],
        ['key' => 'hours', 'count' => $hoursN,
         'tip' => '<b>累計時間</b><br><span class="tip__dim">100時間ごと</span><br>累計 ' . ach_fmt($hours) . " 時間・{$hoursN}回"
            . '<div class="tip__next">次の ' . (($hoursN + 1) * ACH_HOURS_STEP) . ' 時間まで あと <b>' . ach_fmt(($hoursN + 1) * ACH_HOURS_STEP - $hours) . '</b></div>'],
    ];

    // 職人（プロジェクト別）。直近7日に触れていないプロジェクトは一覧・進捗に出さない
    $projTotal = $projRecent = [];
    $recentFrom = (new DateTime($today, $tz))->modify('-' . (ACH_PROJECT_DAYS - 1) . ' days')->format('Y-m-d');
    foreach ($daily as $ymd => $d) {
        foreach ($d['projects'] as $pid => $v) {
            $projTotal[$pid] = ($projTotal[$pid] ?? 0) + $v;
            if ($ymd >= $recentFrom && $ymd <= $today) $projRecent[$pid] = true;
        }
    }
    arsort($projTotal);
    $projProgress = [];
    $hidden = 0;
    foreach ($projTotal as $pid => $v) {
        $n = (int)floor($v / ACH_PROJECT_STEP);
        for ($i = 1; $i <= $n; $i++) $events["proj:$pid:$i"] = 'proj';
        if (empty($projRecent[$pid])) { $hidden++; continue; }
        $name = htmlspecialchars(ach_project_name($pid)) . ' 職人';
        $totalItems[] = ['key' => 'proj', 'count' => $n,
            'tip' => "<b>$name</b><br><span class=\"tip__dim\">同じプロジェクトで50コマごと</span><br>累計 " . ach_fmt($v) . ' コマ'
                . '<div class="tip__next">次まで あと <b>' . ach_fmt(ACH_PROJECT_STEP - fmod($v, ACH_PROJECT_STEP)) . '</b> コマ</div>'];
        $projProgress[] = ['key' => 'proj', 'name' => ach_project_name($pid) . ' 職人', 'done' => fmod($v, ACH_PROJECT_STEP), 'goal' => ACH_PROJECT_STEP, 'unit' => 'コマ', 'due' => '', 'live' => 0];
    }

    // ---- 連続（区切りの日数に届くたびに獲得） ----
    $run = $fullRun = 0;
    foreach ($days as $ymd => $v) {
        $run = $v >= 1 ? $run + 1 : 0;
        $fullRun = $v >= KOMA_DAY_TIERS[0]['min'] ? $fullRun + 1 : 0;
        foreach (ACH_STREAK_STEPS as $step) {
            if ($run > 0 && $run % $step === 0) $events["streak$step:$ymd"] = "streak$step";
        }
        if ($fullRun > 0 && $fullRun % ACH_FULL_STREAK === 0) $events["full3:$ymd"] = 'full3';
    }
    $streak = koma_streak($daily, $today, 1);
    $full   = koma_streak($daily, $today, KOMA_DAY_TIERS[0]['min']);
    $countOf = fn(string $key) => count(array_filter($events, fn($k) => $k === $key));
    $streakItems = [];
    foreach (ACH_STREAK_STEPS as $step) {
        $c = $countOf("streak$step");
        $rest = $step - $streak['current'] % $step;
        $streakItems[] = ['key' => "streak$step", 'count' => $c,
            'tip' => "<b>{$step}日連続</b><br><span class=\"tip__dim\">1コマ以上の日が{$step}日続く</span><br>これまで {$c}回"
                . "<div class=\"tip__next\">いま {$streak['current']}日連続（最長 {$streak['best']}日）・あと <b>{$rest}</b> 日</div>"];
    }
    $c = $countOf('full3');
    $fullRest = ACH_FULL_STREAK - $full['current'] % ACH_FULL_STREAK;
    $streakItems[] = ['key' => 'full3', 'count' => $c,
        'tip' => "<b>フルデイ3連</b><br><span class=\"tip__dim\">6コマの日が3日続く</span><br>これまで {$c}回"
            . "<div class=\"tip__next\">いまフルデイ {$full['current']}日連続・あと <b>{$fullRest}</b> 日</div>"];

    // ---- 進捗（live: 今日のコマ数が1増えたときに増える量。JS が今日の分を足し直す） ----
    $progress = [];
    if ($weekKoma < ACH_WEEK_GOAL) $progress[] = ['key' => 'week30', 'name' => 'ウィークリー30', 'done' => $weekKoma, 'goal' => ACH_WEEK_GOAL, 'unit' => 'コマ', 'due' => '土曜 24:00 まで', 'live' => 1];
    if ($monthKoma < ACH_MONTH_GOAL) $progress[] = ['key' => 'month120', 'name' => 'マンスリー120', 'done' => $monthKoma, 'goal' => ACH_MONTH_GOAL, 'unit' => 'コマ', 'due' => '月末まで', 'live' => 1];
    if ($perfectAlive) $progress[] = ['key' => 'perfect', 'name' => '皆勤', 'done' => $perfectDone, 'goal' => 7, 'unit' => '日', 'due' => '土曜 24:00 まで', 'live' => 0];
    $progress[] = ['key' => 'total', 'name' => '累計' . (($totalN + 1) * ACH_TOTAL_STEP) . 'コマ', 'done' => fmod($total, ACH_TOTAL_STEP), 'goal' => ACH_TOTAL_STEP, 'unit' => 'コマ', 'due' => '', 'live' => 1];
    $progress[] = ['key' => 'hours', 'name' => '累計' . (($hoursN + 1) * ACH_HOURS_STEP) . '時間', 'done' => fmod($hours, ACH_HOURS_STEP), 'goal' => ACH_HOURS_STEP, 'unit' => '時間', 'due' => '', 'live' => KOMA_UNIT_MINUTES / 60];
    array_push($progress, ...$projProgress);
    foreach (ACH_STREAK_STEPS as $step) {
        $progress[] = ['key' => "streak$step", 'name' => "{$step}日連続", 'done' => $streak['current'] % $step, 'goal' => $step, 'unit' => '日', 'due' => '', 'live' => 0];
    }
    $progress[] = ['key' => 'full3', 'name' => 'フルデイ3連', 'done' => $full['current'] % ACH_FULL_STREAK, 'goal' => ACH_FULL_STREAK, 'unit' => '日', 'due' => '', 'live' => 0];
    foreach ($progress as &$p) $p['svg'] = ach_svg($p['key']);
    unset($p);

    return [
        'groups' => [
            ['label' => '今日', 'side' => ach_fmt($todayKoma) . ' コマ', 'items' => $dayItems],
            ['label' => '期間', 'items' => $periodItems],
            ['label' => '累計', 'items' => $totalItems,
             'note' => $hidden ? "直近7日に触れていないプロジェクトは非表示（{$hidden}件）" : ''],
            ['label' => '連続', 'side' => "いま {$streak['current']}日", 'items' => $streakItems],
        ],
        'progress' => $progress,
        'events'   => $events,
    ];
}

// ---- 宝箱（開封済みのイベント id だけを保存する） ----

function ach_store_path(string $user_id = CURRENT_USER_ID): string {
    return users_dir() . '/' . $user_id . '_achievements.json';
}

/** 返り値: ['opened' => [id => true], 'initialized' => bool]。initialized は一度でも開封したか */
function ach_load_opened(string $user_id = CURRENT_USER_ID): array {
    $data = json_read(ach_store_path($user_id));
    return [
        'opened'      => array_fill_keys($data['opened'] ?? [], true),
        'initialized' => $data !== null,
    ];
}

/** 未開封のイベントを key ごとにまとめる。返り値: [key => 個数]（獲得の多い順） */
function ach_unopened(array $events, array $opened): array {
    $byKey = [];
    foreach ($events as $id => $key) {
        if (!isset($opened[$id])) $byKey[$key] = ($byKey[$key] ?? 0) + 1;
    }
    arsort($byKey);
    return $byKey;
}

/** 今の未開封イベントをすべて開封済みにして、開けた中身を返す */
function ach_open_all(array $events, string $user_id = CURRENT_USER_ID): array {
    $store = ach_load_opened($user_id);
    $got   = ach_unopened($events, $store['opened']);
    $ids   = array_keys($store['opened'] + array_fill_keys(array_keys($events), true));
    json_write(ach_store_path($user_id), ['opened' => $ids, 'updated_at' => date('c')]);
    return $got;
}

const ACH_NAMES = [
    'full' => 'フルデイ', 'od' => 'オーバードライブ', 'hyper' => 'ハイパードライブ', 'limit' => 'リミットブレイク',
    'week30' => 'ウィークリー30', 'month120' => 'マンスリー120', 'perfect' => '皆勤',
    'total' => '累計コマ', 'hours' => '累計時間', 'proj' => '職人',
    'streak7' => '7日連続', 'streak30' => '30日連続', 'full3' => 'フルデイ3連',
];
