<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/hook.php';
require_once __DIR__ . '/includes/auth.php';
auth_require_page();

$currentUser = get_koma_user();
$config      = load_config();
$saved       = false;
$saveError   = '';
$testResult  = null;

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_config') {
        $komaCount  = max(1, min(20, (int)($_POST['koma_count'] ?? 6)));
        $komaDur    = max(10, min(480, (int)($_POST['koma_duration_minutes'] ?? 80)));
        $maxDur     = max($komaDur, min(480, (int)($_POST['max_duration_minutes'] ?? 100)));

        $hooks = $config['hooks'];
        foreach (array_keys($hooks) as $event) {
            $hooks[$event]['url']     = trim($_POST["hook_{$event}_url"] ?? '');
            $hooks[$event]['method']  = in_array($_POST["hook_{$event}_method"] ?? '', ['GET', 'POST']) ? $_POST["hook_{$event}_method"] : 'GET';
            $hooks[$event]['enabled'] = isset($_POST["hook_{$event}_enabled"]);
        }

        $newConfig = array_merge($config, [
            'koma_count'             => $komaCount,
            'koma_duration_minutes'  => $komaDur,
            'max_duration_minutes'   => $maxDur,
            'hooks'                  => $hooks,
        ]);

        if (save_config($newConfig)) {
            $config = $newConfig;
            $saved  = true;
        } else {
            $saveError = '設定の保存に失敗しました。';
        }
    }

    if ($_POST['action'] === 'test_hook') {
        $event   = $_POST['test_event'] ?? '';
        $hookCfg = $config['hooks'][$event] ?? null;
        if ($hookCfg && !empty($hookCfg['url'])) {
            // テスト送信は「有効」チェックの有無に関係なく送る
            $testConfig = $config;
            $testConfig['hooks'][$event]['enabled'] = true;
            $res = dispatch_hook($event, ['test' => true], $testConfig);
            $testResult = [
                'event' => $event,
                'code'  => $res['code'],
                'error' => $res['error'],
                'body'  => mb_substr($res['body'], 0, 200),
            ];
        } else {
            $testResult = ['event' => $event, 'error' => 'URLが設定されていません'];
        }
    }
}

$hookLabels = [
    'koma_start'    => 'コマ開始時',
    'koma_complete' => 'コマ完了時',
    'koma_80min'    => '80分経過時',
    'koma_100min'   => '100分経過時',
    'break_notify'  => '10分休憩通知（完了後10分）',
];
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>設定 — コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="page-main" style="max-width:720px;">
    <h1 class="page-title">設定</h1>

    <?php if ($saved): ?>
        <div class="notice notice-success">設定を保存しました。</div>
    <?php endif; ?>
    <?php if ($saveError): ?>
        <div class="notice notice-error"><?= htmlspecialchars($saveError) ?></div>
    <?php endif; ?>
    <?php if ($testResult): ?>
        <div class="notice <?= empty($testResult['error']) && $testResult['code'] >= 200 && $testResult['code'] < 300 ? 'notice-success' : 'notice-error' ?>">
            テスト送信 (<?= htmlspecialchars($testResult['event']) ?>):
            <?php if ($testResult['error']): ?>
                エラー — <?= htmlspecialchars($testResult['error']) ?>
            <?php else: ?>
                HTTP <?= (int)$testResult['code'] ?>
                <?php if ($testResult['body']): ?>
                    — <code style="font-size:11px;"><?= htmlspecialchars($testResult['body']) ?></code>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_config">

        <!-- General -->
        <div class="card">
            <div class="card__title">基本設定</div>
            <div class="form-row">
                <label>1日のコマ数</label>
                <input type="number" name="koma_count" value="<?= (int)$config['koma_count'] ?>" min="1" max="20" style="width:100px;">
            </div>
            <div class="form-row">
                <label>1コマの時間（分）</label>
                <input type="number" name="koma_duration_minutes" value="<?= (int)$config['koma_duration_minutes'] ?>" min="10" max="480" style="width:100px;">
            </div>
            <div class="form-row">
                <label>最大時間（分）— この時間で自動完了</label>
                <input type="number" name="max_duration_minutes" value="<?= (int)$config['max_duration_minutes'] ?>" min="10" max="480" style="width:100px;">
            </div>
        </div>

        <!-- Hooks -->
        <div class="card">
            <div class="card__title">Hook 設定</div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
                各イベント発生時に指定のURLへリクエストを送ります。
                GETはクエリパラメータ付き、POSTはJSONボディで送信されます。
            </p>

            <?php foreach ($hookLabels as $event => $label):
                $hc = $config['hooks'][$event] ?? ['url' => '', 'method' => 'GET', 'enabled' => false];
            ?>
            <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:10px;"><?= htmlspecialchars($label) ?></div>

                <div class="toggle-row" style="margin-bottom:8px;">
                    <input type="checkbox" name="hook_<?= $event ?>_enabled" id="hook-<?= $event ?>-en"
                           <?= $hc['enabled'] ? 'checked' : '' ?>>
                    <label for="hook-<?= $event ?>-en" style="font-size:13px;">有効</label>
                </div>

                <div class="form-row">
                    <label>URL</label>
                    <input type="url" name="hook_<?= $event ?>_url"
                           value="<?= htmlspecialchars($hc['url']) ?>"
                           placeholder="https://example.com/hook">
                </div>
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:4px;">
                    <div class="form-row" style="margin:0;flex:1;">
                        <label>メソッド</label>
                        <select name="hook_<?= $event ?>_method" style="width:100px;">
                            <option value="GET"  <?= $hc['method'] === 'GET'  ? 'selected' : '' ?>>GET</option>
                            <option value="POST" <?= $hc['method'] === 'POST' ? 'selected' : '' ?>>POST</option>
                        </select>
                    </div>
                    <div style="padding-top:18px;">
                        <button type="submit" form="form-test-<?= $event ?>" class="btn-secondary" style="font-size:12px;">テスト送信</button>
                    </div>
                </div>
            </div>

            <!-- Separate form for test (so it doesn't save) -->
            <form id="form-test-<?= $event ?>" method="POST" style="display:none;">
                <input type="hidden" name="action" value="test_hook">
                <input type="hidden" name="test_event" value="<?= $event ?>">
            </form>

            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-complete" style="padding:10px 28px;">保存</button>
    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
