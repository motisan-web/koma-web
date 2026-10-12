/**
 * カレンダー（休み・稼働日の登録。#T-014）
 * 日付をクリックして 既定 → 休み → 稼働 → 既定 と切り替え、api/timer.php の set_day_type で保存する。
 */
(function () {
    const NEXT   = { '': 'off', off: 'work', work: '' };       // '' = 既定（例外なし）
    const LABELS = { off: '休み', work: '稼働' };
    const grid  = document.getElementById('cal-grid');
    const errEl = document.getElementById('cal-error');
    if (!grid) return;

    function render(cell) {
        const exc    = cell.dataset.exception;
        const defOff = cell.dataset.defaultOff === '1';
        cell.classList.toggle('is-off', exc === 'off');
        cell.classList.toggle('is-work', exc === 'work');
        cell.classList.toggle('is-default-off', exc === '' && defOff);
        cell.querySelector('.cal-day__label').textContent = exc ? LABELS[exc] : (defOff ? '休(曜日)' : '');
        const state = exc ? LABELS[exc] + '（登録）' : (defOff ? '休み（曜日の既定）' : '稼働（既定）');
        cell.setAttribute('aria-label', `${cell.dataset.date} ${state}`);
    }

    function showError(msg) {
        errEl.textContent = msg;
        errEl.hidden = !msg;
    }

    grid.querySelectorAll('.cal-day[data-date]').forEach(render);

    grid.addEventListener('click', async e => {
        const cell = e.target.closest('.cal-day[data-date]');
        if (!cell || cell.disabled) return;
        const prev = cell.dataset.exception;
        const next = NEXT[prev] ?? '';
        cell.dataset.exception = next;
        render(cell);
        cell.disabled = true;
        showError('');
        const res = await fetch('/api/timer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_day_type', date: cell.dataset.date, type: next === '' ? 'default' : next }),
        }).then(r => r.json()).catch(() => ({ ok: false, error: '通信に失敗しました' }));
        cell.disabled = false;
        if (!res.ok) {
            cell.dataset.exception = prev;   // 保存できなかったので元に戻す
            render(cell);
            showError(res.error || '保存に失敗しました');
        }
    });
})();
