<?php
/**
 * Site header — only included when not in EMBED_MODE
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$navItems = [
    'index'    => ['label' => 'タイマー', 'href' => '/'],
    'stats'    => ['label' => '統計',     'href' => '/stats.php'],
    'settings' => ['label' => '設定',     'href' => '/settings.php'],
    'help'     => ['label' => '使い方',   'href' => '/help.php'],
];
?>
<header class="site-header">
    <div class="site-header__inner">
        <a href="/" class="site-header__logo">コマタイマー</a>
        <nav class="site-header__nav">
            <?php foreach ($navItems as $page => $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>"
                   class="site-header__nav-link<?= $currentPage === $page ? ' is-active' : '' ?>">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <button class="btn-theme" id="btn-theme-toggle" title="テーマ切り替え">🌙</button>
        <div class="site-header__user">
            <?= htmlspecialchars($currentUser['nickname'] ?? 'もちさん') ?>
        </div>
        <?php if (function_exists('auth_current_user') && auth_current_user() !== null): ?>
            <form method="POST" action="/login.php" class="site-header__logout">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(auth_csrf_token()) ?>">
                <button type="submit" class="btn-secondary" style="font-size:12px;">ログアウト</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<script>
(function() {
    var btn = document.getElementById('btn-theme-toggle');
    function applyTheme(t) {
        document.documentElement.dataset.theme = t;
        btn.textContent = t === 'light' ? '🌙' : '☀️';
    }
    if (btn) {
        btn.addEventListener('click', function() {
            var next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
            applyTheme(next);
            fetch('/api/timer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_theme', theme: next }),
            });
        });
        // Sync button icon to current theme
        applyTheme(document.documentElement.dataset.theme || 'dark');
    }
})();
</script>
