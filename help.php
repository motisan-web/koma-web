<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/auth.php';
auth_require_page();
$config      = load_config();
$currentUser = get_koma_user();
?>
<!DOCTYPE html>
<html lang="ja" data-theme="<?= htmlspecialchars($config['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>使い方 — コマタイマー</title>
    <link rel="stylesheet" href="/assets/css/timer.css">
    <style>
        .help-section { margin-bottom: 32px; }
        .help-section h2 { font-size:15px; font-weight:700; margin-bottom:12px; color:var(--text); border-left:3px solid var(--accent); padding-left:10px; }
        .help-section h3 { font-size:13px; font-weight:600; margin:16px 0 8px; color:var(--accent-hover); }
        .help-section p, .help-section li { font-size:13px; color:var(--text-muted); line-height:1.8; }
        .help-section ul, .help-section ol { padding-left:20px; }
        .help-section li { margin-bottom:4px; }
        code { background:var(--bg-input); padding:1px 6px; border-radius:3px; font-size:12px; color:var(--text); font-family:"Consolas","Courier New",monospace; }
        .key { background:var(--bg-input); border:1px solid var(--border); padding:2px 8px; border-radius:3px; font-size:12px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="page-main" style="max-width:760px;">
    <h1 class="page-title">使い方</h1>

    <div class="help-section">
        <h2>概要</h2>
        <p>コマタイマーは、80分を1コマとした作業単位でタスクを計測・記録するツールです。
        1日6コマ（約8時間）の作業を管理します。</p>
    </div>

    <div class="help-section">
        <h2>基本的な使い方</h2>
        <h3>1. 作業内容とプロジェクトを入力する</h3>
        <p>各コマカードの上部にある入力欄に作業内容と <code>#project/xxx</code> 形式のプロジェクトIDを入力します。
        プロジェクトIDは過去に使ったものが補完候補として表示されます。
        開始前でも開始後でも入力・編集できます。</p>

        <h3>2. 開始ボタンを押す</h3>
        <p>「開始」ボタンを押すとタイマーが動き始め、経過時間・残り時間・進捗バーがリアルタイムで更新されます。
        複数のコマを同時に動かすことができます。</p>

        <h3>3. 停止・再開</h3>
        <p>「停止」ボタンで一時停止できます。再度「開始」を押すと再開します。
        停止中の時間は計測されません。合計が80分になるよう累積されます。</p>

        <h3>4. 完了</h3>
        <p>「完了」ボタンを押すといつでもコマを終了できます。
        80分に達していなくても完了できます（早く終わったタスク・今日の作業終了時に使用）。</p>
    </div>

    <div class="help-section">
        <h2>時間の扱い</h2>
        <ul>
            <li>0〜100%（0〜80分）：緑のプログレスバーで進行を表示</li>
            <li>80分到達：超過表示（オレンジ）になり、超過分数が表示されます</li>
            <li>100分到達：自動的に完了としてコマが終了します</li>
            <li>日をまたいで進行中のコマは、翌日以降も停止・完了操作が可能です</li>
        </ul>
    </div>

    <div class="help-section">
        <h2>10分休憩チェック</h2>
        <p>コマカード下部の「10分休憩」チェックをオンにしておくと、コマ完了後にHook通知が10分後に発火します（break_notifyイベント）。
        実際に休憩する必要はなく、「次のコマを10分後に開始する予定」という意図を示すフラグです。</p>
    </div>

    <div class="help-section">
        <h2>統計ページ</h2>
        <ul>
            <li><strong>日別</strong>：指定日のコマ一覧・事後編集・マークダウン出力</li>
            <li><strong>期間</strong>：週・月ごとのコマ数（プロジェクト別の色分け）、前の期間と比べたペース、曜日ごとの平均</li>
            <li><strong>プロジェクト</strong>：割合・週ごとの推移・プロジェクト一覧。名前を押すとそのプロジェクトだけの統計が見られます</li>
            <li><strong>時間帯</strong>：曜日×時刻でいつ作業しているか、始業と終業の推移</li>
            <li><strong>集中度</strong>：コマの長さの分布、一時停止の回数、80分前後で終えた割合</li>
            <li><strong>記録</strong>：自己ベスト、1年のカレンダー、月ごとの推移</li>
        </ul>
        <p>コマ数は「分数 ÷ 80」で数えます（完了したコマで20分を超え80分に届かないものは80分として数えます）。</p>
    </div>

    <div class="help-section">
        <h2>マークダウン出力</h2>
        <p>統計ページの日別ビューから、その日のコマ記録をマークダウン形式でコピー・ダウンロードできます。</p>
        <p>出力フォーマット例：</p>
        <pre style="background:var(--bg-input);padding:14px;border-radius:var(--radius-sm);font-size:12px;color:var(--text);line-height:1.8;overflow-x:auto;">### コマ1(80分)
- [x] **内容**: ツール/改善 dashboard改善
- **プロジェクト**: #project/moti-tool

### コマ2(80分)
- [ ] **内容**: その他/雑務
- **プロジェクト**: #project/others

合計作業時間 160分(2026/4/13)</pre>
    </div>

    <div class="help-section">
        <h2>Hook通知</h2>
        <p>設定ページで各イベントのURLを設定しておくと、イベント発生時に自動でAPIを叩きます。</p>
        <table class="data-table">
            <thead><tr><th>イベント</th><th>タイミング</th><th>備考</th></tr></thead>
            <tbody>
                <tr><td><code>koma_start</code></td><td>コマ開始時</td><td></td></tr>
                <tr><td><code>koma_complete</code></td><td>コマ完了時</td><td>手動完了時</td></tr>
                <tr><td><code>koma_80min</code></td><td>80分経過時</td><td>超過開始タイミング</td></tr>
                <tr><td><code>koma_100min</code></td><td>100分経過時</td><td>コマは完了しない（hook のみ）。ブラウザを閉じていても cron から送られる</td></tr>
                <tr><td><code>break_notify</code></td><td>完了後10分</td><td>10分休憩チェックがオンの場合のみ</td></tr>
            </tbody>
        </table>
        <p style="margin-top:12px;">GETリクエストはクエリパラメータに、POSTリクエストはJSONボディにイベント情報が含まれます。</p>
    </div>

    <div class="help-section">
        <h2>データ保存</h2>
        <ul>
            <li>データはサーバーのJSONファイルに保存されます</li>
            <li>保存先：<code>data/sessions/YYYY/MM-DD/data.json</code></li>
            <li>日をまたいでも前日のデータは保持されます（上書き・削除されません）</li>
            <li>設定：<code>data/config.json</code></li>
        </ul>
    </div>

    <div class="help-section">
        <h2>iframe埋め込み（embed.php）</h2>
        <p><code>/embed.php</code> にアクセスするとヘッダーなしのタイマー画面が表示されます。
        moti tools等へiframeで埋め込む場合はこちらを使用してください。</p>
        <pre style="background:var(--bg-input);padding:14px;border-radius:var(--radius-sm);font-size:12px;color:var(--text);">&lt;iframe src="https://git7.local/embed.php" width="100%" height="600"&gt;&lt;/iframe&gt;</pre>
    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
