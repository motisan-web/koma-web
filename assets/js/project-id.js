/**
 * プロジェクトIDの接頭辞 `#project/` の扱い（#T-012）。
 * 入力欄には接頭辞を固定表示して「続き」だけ入れる。保存値は従来どおり `#project/xxx`。
 * 規則は PHP の koma_project_normalize()（includes/koma_stats.php）と同じ。変えるときは両方直す。
 */
(function (global) {
    const PREFIX = '#project/';

    // 先頭の `#project/`（重ねて付いていても）を外す。末尾の空白は残す（入力中の値を壊さないため）
    function strip(v) {
        let s = String(v ?? '').replace(/^\s+/, '');
        while (s.startsWith(PREFIX)) s = s.slice(PREFIX.length).replace(/^\s+/, '');
        return s;
    }

    // 入力欄に出す「続き」。前後の空白も除く
    function suffix(v) {
        return strip(v).trim();
    }

    // 保存値にする。続きが空なら空（`#project/` だけは保存しない）
    function full(v) {
        const s = suffix(v);
        return s === '' ? '' : PREFIX + s;
    }

    global.KomaProject = { PREFIX, strip, suffix, full };

    // 貼り付け・入力で `#project/` 付きが来ても、入力欄では二重にしない。
    // 各入力欄の保存処理より先に値を直すため、document の capture で受ける
    document.addEventListener('input', function (e) {
        const el = e.target;
        if (!el || !el.matches || !el.matches('.project-field input')) return;
        const v = strip(el.value);
        if (v !== el.value && el.value.trimStart().startsWith(PREFIX)) el.value = v;
    }, true);
})(window);
