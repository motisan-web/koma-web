/**
 * コマタイマー — timer.js
 * Manages all timer UI logic client-side.
 */
'use strict';

const CFG = window.KOMA_CONFIG;

// --- Utilities ---

function fmt(sec) {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

async function apiCall(action, params = {}) {
    try {
        const res = await fetch('/api/timer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...params }),
        });
        return await res.json();
    } catch (e) {
        console.error('apiCall error', e);
        return { ok: false, error: e.message };
    }
}

// --- State ---
// Today's komas: slot -> koma object
const komaState = {};
// Tick intervals: slot -> intervalId
const tickIntervals = {};
// 80/100min notification tracking: slot -> { '80': bool, '100': bool }
const notified = {};

// Prev-incomplete komas: key `date:slot` -> { date, koma }
const prevState = {};
const prevTicks = {};

// --- Status helpers ---

const STATUS_LABEL = {
    idle:         '未実行',
    running:      '実行中',
    paused:       '一時停止',
    overtime:     '超過中',
    overtime_max: '超過完了',
    completed:    '完了',
    closed:       '中止',
    auto_closed:  '自動中止',
};

const STATUS_CLASS = {
    running:      'is-running',
    paused:       'is-paused',
    overtime:     'is-overtime',
    overtime_max: 'is-overtime-max',
    completed:    'is-completed',
    closed:       'is-completed',
    auto_closed:  'is-completed',
};

function isDone(status) {
    return ['completed', 'overtime_max', 'closed', 'auto_closed'].includes(status);
}

// --- Clock offset correction ---
// PHP passes its server timestamp at page render time. We use it to correct
// for browser/server clock skew so that liveElapsed is accurate even when
// the clocks differ by several seconds (a common cause of frozen display).
const _clockOffset = (CFG.serverNow || 0) - Date.now(); // ms: positive if server is ahead

// --- Live elapsed ---

function liveElapsed(k) {
    if (!k || !k.segments || k.segments.length === 0) return 0;
    let total = 0;
    // Use server-aligned browser time to avoid clock-skew errors.
    const now = Date.now() + _clockOffset;
    for (const seg of k.segments) {
        const start = new Date(seg.start).getTime();
        if (isNaN(start)) continue;
        const end = seg.end ? new Date(seg.end).getTime() : now;
        const diff = isNaN(end) ? 0 : Math.floor((end - start) / 1000);
        if (diff > 0) total += diff;
    }
    return total;
}

// ================================================================
// TODAY'S KOMAS
// ================================================================

function initFromState(session) {
    if (!session || !session.koma) return;
    for (const k of session.koma) {
        komaState[k.id] = k;
        notified[k.id]  = { '80': false, '100': false };
    }
    for (let slot = 1; slot <= CFG.komaCount; slot++) {
        renderKoma(slot);
        const k = komaState[slot];
        if (k && (k.status === 'running' || k.status === 'overtime')) {
            startTick(slot);
        }
    }
}

function renderKoma(slot) {
    const card = document.getElementById(`koma-card-${slot}`);
    if (!card) return; // slot is rendered elsewhere (e.g. prev-incomplete area)

    const k      = komaState[slot];
    const status = k ? k.status : 'idle';
    const badge       = document.getElementById(`koma-status-${slot}`);
    const fill        = document.getElementById(`koma-fill-${slot}`);
    const pct         = document.getElementById(`koma-pct-${slot}`);
    const overtimeLbl = document.getElementById(`koma-overtime-${slot}`);
    const elapsedEl   = document.getElementById(`koma-elapsed-${slot}`);
    const remainingEl = document.getElementById(`koma-remaining-${slot}`);
    const btnStart    = document.getElementById(`btn-start-${slot}`);
    const btnPause    = document.getElementById(`btn-pause-${slot}`);
    const btnComplete = document.getElementById(`btn-complete-${slot}`);
    const btnReset    = document.getElementById(`btn-reset-${slot}`);
    const btnRound    = document.getElementById(`btn-round-${slot}`);
    const nameInput   = document.getElementById(`koma-name-${slot}`);
    const projInput   = document.getElementById(`koma-project-${slot}`);
    const breakLabel  = document.getElementById(`koma-break-label-${slot}`);
    const breakChk    = document.getElementById(`koma-break-${slot}`);

    if (k) {
        if (document.activeElement !== nameInput) nameInput.value = k.name || '';
        if (document.activeElement !== projInput)  projInput.value = k.project_id || '';
        if (breakChk)    breakChk.checked = !!k.break_after;
        if (breakLabel)  breakLabel.classList.toggle('is-checked', !!k.break_after);
    }

    // For done komas use stored total_seconds (authoritative, respects manual adjustments like round_to_100min).
    // For active komas use liveElapsed for real-time display.
    const done    = isDone(status);
    const elapsed = k ? (done ? (k.total_seconds ?? 0) : liveElapsed(k)) : 0;
    const remaining = Math.max(0, CFG.komaDurationSec - elapsed);
    const overSec   = Math.max(0, elapsed - CFG.komaDurationSec);
    const progress  = Math.min(100, Math.round(elapsed / CFG.komaDurationSec * 100));

    elapsedEl.textContent   = fmt(elapsed);
    remainingEl.textContent = remaining > 0 ? fmt(remaining) : `-${fmt(overSec)}`;
    remainingEl.classList.toggle('is-negative', remaining === 0 && elapsed > 0);
    fill.style.width  = progress + '%';
    pct.textContent   = progress + '%';

    if (overSec > 0) {
        overtimeLbl.style.display = '';
        overtimeLbl.textContent   = `+${Math.floor(overSec / 60)}分超過`;
    } else {
        overtimeLbl.style.display = 'none';
    }

    badge.textContent = STATUS_LABEL[status] || status;
    badge.className   = `koma-card__status-badge ${status}`;
    card.className    = 'koma-card ' + (STATUS_CLASS[status] || '');

    const isRunning = status === 'running' || status === 'overtime';

    const isPhantom = done && status === 'completed' && k && k.segments.length === 0;

    btnStart.style.display   = isRunning ? 'none' : '';
    btnPause.style.display   = isRunning ? '' : 'none';
    btnStart.disabled        = done;
    btnComplete.disabled     = done || status === 'idle';
    if (btnReset) {
        btnReset.style.display = isPhantom ? '' : 'none';
    }
    if (btnRound) {
        btnRound.style.display = (done && elapsed > CFG.maxDurationSec) ? '' : 'none';
    }
    updateSummary();
}

function startTick(slot) {
    if (tickIntervals[slot]) return;
    tickIntervals[slot] = setInterval(() => tick(slot), 1000);
}

function stopTick(slot) {
    clearInterval(tickIntervals[slot]);
    delete tickIntervals[slot];
}

function tick(slot) {
    const k = komaState[slot];
    if (!k) return;

    const elapsed = liveElapsed(k);
    notified[slot] = notified[slot] || {};

    if (elapsed >= CFG.komaDurationSec && !notified[slot]['80']) {
        notified[slot]['80'] = true;
        apiCall('notify_80min', { slot });
        k.status = 'overtime';
    }

    renderKoma(slot);
}

async function doStart(slot) {
    const res = await apiCall('start', { slot });
    if (!res.ok) { console.error('start failed', res.error); return; }
    komaState[slot] = res.koma;
    notified[slot]  = notified[slot] || { '80': false, '100': false };
    startTick(slot);
    renderKoma(slot);
}

async function doPause(slot) {
    const res = await apiCall('pause', { slot });
    if (!res.ok) { console.error('pause failed', res.error); return; }
    komaState[slot] = res.koma;
    stopTick(slot);
    renderKoma(slot);
}

async function doComplete(slot) {
    const res = await apiCall('complete', { slot });
    if (!res.ok) { console.error('complete failed', res.error); return; }
    komaState[slot] = res.koma;
    stopTick(slot);
    renderKoma(slot);
}

async function doUpdateMeta(slot, name, projectId) {
    const params = { slot };
    if (name      !== undefined) params.name       = name;
    if (projectId !== undefined) params.project_id = projectId;
    const res = await apiCall('update_meta', params);
    if (res.ok && res.koma) {
        komaState[slot] = res.koma;
        refreshProjectHistory(res.koma.project_id);
    }
}

async function doReset(slot) {
    const res = await apiCall('reset', { slot });
    if (!res.ok) { console.error('reset failed', res.error); return; }
    komaState[slot] = res.koma;
    renderKoma(slot);
}

async function doRoundTo100min(slot) {
    if (!confirm('このコマの記録を100分に丸めますか？')) return;
    const res = await apiCall('round_to_100min', { slot });
    if (!res.ok) { console.error('round failed', res.error); return; }
    komaState[slot] = res.koma;
    renderKoma(slot);
}

async function doSetBreak(slot, value) {
    const res = await apiCall('set_break', { slot, value });
    if (res.ok && res.koma) { komaState[slot] = res.koma; renderKoma(slot); }
}

// ================================================================
// PREV INCOMPLETE KOMAS
// ================================================================

function prevKey(date, slot) { return `${date.replace(/-/g,'')}s${slot}`; }

function initPrevIncomplete(items) {
    if (!items || items.length === 0) return;

    const area = document.getElementById('prev-incomplete-area');
    area.innerHTML = `
        <div class="prev-incomplete-area">
            <div class="prev-incomplete-area__heading">前日の未完了コマ</div>
            <div class="prev-incomplete-grid" id="prev-incomplete-grid"></div>
        </div>
    `;
    const grid = document.getElementById('prev-incomplete-grid');

    for (const item of items) {
        const { date, koma: k } = item;
        const key  = prevKey(date, k.id);
        prevState[key] = { date, koma: k };

        const card = buildPrevCard(date, k);
        grid.appendChild(card);
        renderPrevKoma(key);  // 要素を id で探すので、ページに入れてから描く（#I-016）

        if (k.status === 'running' || k.status === 'overtime') {
            startPrevTick(key);
        }
    }
}

function buildPrevCard(date, k) {
    const key    = prevKey(date, k.id);
    const card   = document.createElement('div');
    card.className = 'koma-card koma-card--prev is-paused';
    card.id      = `prev-card-${key}`;

    // Start time label
    const startTime = k.segments && k.segments[0]
        ? new Date(k.segments[0].start).toLocaleString('ja-JP', { month:'numeric', day:'numeric', hour:'2-digit', minute:'2-digit' })
        : date;

    card.innerHTML = `
        <div class="koma-card__date-label">${date} 開始 ${startTime}</div>
        <div class="koma-card__header">
            <span class="koma-card__slot">コマ ${k.id}</span>
            <span class="koma-card__status-badge ${k.status}" id="prev-badge-${key}">${STATUS_LABEL[k.status] || k.status}</span>
        </div>
        <input type="text" class="koma-card__name-input" id="prev-name-${key}"
               value="${escHtml(k.name || '')}" placeholder="作業内容"
               data-key="${key}">
        <input type="text" class="koma-card__project-input" id="prev-project-${key}"
               value="${escHtml(k.project_id || '')}" placeholder="#project/"
               list="project-history-list" data-key="${key}">
        <div class="koma-card__progress-wrap">
            <div class="koma-card__progress-bar">
                <div class="koma-card__progress-fill" id="prev-fill-${key}" style="width:0%"></div>
            </div>
            <div class="koma-card__progress-labels">
                <span class="koma-card__progress-pct" id="prev-pct-${key}">0%</span>
                <span class="koma-card__overtime-label" id="prev-overtime-${key}" style="display:none"></span>
            </div>
        </div>
        <div class="koma-card__time">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">経過</div>
                <div class="koma-card__time-elapsed" id="prev-elapsed-${key}">0:00:00</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">残り</div>
                <div class="koma-card__time-remaining" id="prev-remaining-${key}">-</div>
            </div>
        </div>
        <div class="koma-card__actions">
            <button class="btn btn-start"    id="prev-btn-start-${key}"    data-key="${key}">再開</button>
            <button class="btn btn-pause"    id="prev-btn-pause-${key}"    data-key="${key}" style="display:none">停止</button>
            <button class="btn btn-complete" id="prev-btn-complete-${key}" data-key="${key}">完了</button>
            <button class="btn btn-secondary" id="prev-btn-close-${key}"  data-key="${key}" style="padding:6px 10px;">中止</button>
        </div>
    `;

    // Bind events
    card.querySelector(`#prev-btn-start-${key}`)
        .addEventListener('click', () => doPrevStart(key));
    card.querySelector(`#prev-btn-pause-${key}`)
        .addEventListener('click', () => doPrevPause(key));
    card.querySelector(`#prev-btn-complete-${key}`)
        .addEventListener('click', () => doPrevComplete(key));
    card.querySelector(`#prev-btn-close-${key}`)
        .addEventListener('click', () => doPrevClose(key));

    // Meta save
    const nameInp = card.querySelector(`#prev-name-${key}`);
    const projInp = card.querySelector(`#prev-project-${key}`);
    bindMetaSave(nameInp, key, 'name');
    bindMetaSave(projInp, key, 'project');

    return card;
}

function renderPrevKoma(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const status = k.status;

    const badge       = document.getElementById(`prev-badge-${key}`);
    const fill        = document.getElementById(`prev-fill-${key}`);
    const pct         = document.getElementById(`prev-pct-${key}`);
    const overtimeLbl = document.getElementById(`prev-overtime-${key}`);
    const elapsedEl   = document.getElementById(`prev-elapsed-${key}`);
    const remainingEl = document.getElementById(`prev-remaining-${key}`);
    const btnStart    = document.getElementById(`prev-btn-start-${key}`);
    const btnPause    = document.getElementById(`prev-btn-pause-${key}`);
    const btnComplete = document.getElementById(`prev-btn-complete-${key}`);
    const btnClose    = document.getElementById(`prev-btn-close-${key}`);
    const card        = document.getElementById(`prev-card-${key}`);

    if (!badge) return;

    // For done komas use stored total_seconds. For running komas use total_seconds
    // (PHP-computed at page render) + browser-side elapsed since page load — this avoids
    // relying on JS date-parsing of old segment timestamps and prevents huge overnight values.
    // For paused komas use liveElapsed (closed segments sum is already correct).
    const isRunningPrev = status === 'running' || status === 'overtime';
    const donePrev = isDone(status);
    let elapsed;
    if (donePrev) {
        elapsed = k.total_seconds ?? 0;
    } else if (isRunningPrev) {
        const sinceLoad = Math.max(0, Math.floor((Date.now() + _clockOffset - CFG.serverNow) / 1000));
        elapsed = (k.total_seconds ?? 0) + sinceLoad;
    } else {
        elapsed = liveElapsed(k); // paused: closed segments, correct
    }
    const remaining = Math.max(0, CFG.komaDurationSec - elapsed);
    const overSec   = Math.max(0, elapsed - CFG.komaDurationSec);
    const progress  = Math.min(100, Math.round(elapsed / CFG.komaDurationSec * 100));

    elapsedEl.textContent   = fmt(elapsed);
    remainingEl.textContent = remaining > 0 ? fmt(remaining) : `-${fmt(overSec)}`;
    remainingEl.classList.toggle('is-negative', remaining === 0);
    fill.style.width = progress + '%';
    pct.textContent  = progress + '%';

    if (overSec > 0) {
        overtimeLbl.style.display = '';
        overtimeLbl.textContent   = `+${Math.floor(overSec / 60)}分超過`;
    } else {
        overtimeLbl.style.display = 'none';
    }

    badge.textContent = STATUS_LABEL[status] || status;
    badge.className   = `koma-card__status-badge ${status}`;
    card.className    = 'koma-card koma-card--prev ' + (STATUS_CLASS[status] || '');

    btnStart.style.display  = isRunningPrev ? 'none' : '';
    btnPause.style.display  = isRunningPrev ? '' : 'none';
    btnStart.disabled       = donePrev;
    btnComplete.disabled    = donePrev;
    btnClose.disabled       = donePrev;
}

function startPrevTick(key) {
    if (prevTicks[key]) return;
    prevTicks[key] = setInterval(() => {
        renderPrevKoma(key);
    }, 1000);
}

function stopPrevTick(key) {
    clearInterval(prevTicks[key]);
    delete prevTicks[key];
}

function removePrevCard(key) {
    stopPrevTick(key);
    const card = document.getElementById(`prev-card-${key}`);
    if (card) card.remove();
    delete prevState[key];

    // Hide entire area if no cards left
    const grid = document.getElementById('prev-incomplete-grid');
    if (grid && grid.children.length === 0) {
        const area = document.getElementById('prev-incomplete-area');
        if (area) area.innerHTML = '';
    }
}

async function doPrevStart(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('start', { slot: k.id, date });
    if (!res.ok) { console.error('prev start failed', res.error); return; }
    entry.koma = res.koma;
    startPrevTick(key);
    renderPrevKoma(key);
}

async function doPrevPause(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('pause', { slot: k.id, date });
    if (!res.ok) { console.error('prev pause failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
}

async function doPrevComplete(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('complete', { slot: k.id, date });
    if (!res.ok) { console.error('prev complete failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
    setTimeout(() => removePrevCard(key), 1500);
}

async function doPrevClose(key) {
    const entry = prevState[key];
    if (!entry) return;
    const { date, koma: k } = entry;
    const res = await apiCall('close', { slot: k.id, date });
    if (!res.ok) { console.error('prev close failed', res.error); return; }
    entry.koma = res.koma;
    stopPrevTick(key);
    renderPrevKoma(key);
    setTimeout(() => removePrevCard(key), 1500);
}

function bindMetaSave(input, key, field) {
    if (!input) return;
    let t = null;
    const save = async () => {
        const entry = prevState[key];
        if (!entry) return;
        const params = { slot: entry.koma.id, date: entry.date };
        if (field === 'name')    params.name       = input.value;
        if (field === 'project') params.project_id = input.value;
        const res = await apiCall('update_meta', params);
        if (res.ok && res.koma) {
            entry.koma = res.koma;
            refreshProjectHistory(res.koma.project_id);
        }
    };
    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
}

// ================================================================
// ADD KOMA (dynamic slot creation)
// ================================================================

const SLOT_MAX = 20;

function currentMaxSlot() {
    let max = CFG.komaCount;
    document.querySelectorAll('.koma-card[data-slot]').forEach(card => {
        const s = parseInt(card.dataset.slot, 10);
        if (s > max) max = s;
    });
    return max;
}

function buildKomaCard(slot) {
    const div = document.createElement('div');
    div.className   = 'koma-card';
    div.id          = `koma-card-${slot}`;
    div.dataset.slot = slot;
    div.innerHTML = `
        <div class="koma-card__header">
            <span class="koma-card__slot">コマ ${slot}</span>
            <span class="koma-card__status-badge idle" id="koma-status-${slot}">未実行</span>
        </div>
        <input type="text" class="koma-card__name-input"
               id="koma-name-${slot}" placeholder="作業内容" value=""
               data-slot="${slot}">
        <input type="text" class="koma-card__project-input"
               id="koma-project-${slot}" placeholder="#project/"
               value="" list="project-history-list" data-slot="${slot}">
        <div class="koma-card__progress-wrap">
            <div class="koma-card__progress-bar">
                <div class="koma-card__progress-fill" id="koma-fill-${slot}" style="width:0%"></div>
            </div>
            <div class="koma-card__progress-labels">
                <span class="koma-card__progress-pct" id="koma-pct-${slot}">0%</span>
                <span class="koma-card__overtime-label" id="koma-overtime-${slot}" style="display:none"></span>
            </div>
        </div>
        <div class="koma-card__time">
            <div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">経過</div>
                <div class="koma-card__time-elapsed" id="koma-elapsed-${slot}">0:00:00</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">残り</div>
                <div class="koma-card__time-remaining" id="koma-remaining-${slot}">1:20:00</div>
            </div>
        </div>
        <div class="koma-card__actions">
            <button class="btn btn-start"    id="btn-start-${slot}"    data-slot="${slot}">開始</button>
            <button class="btn btn-pause"    id="btn-pause-${slot}"    data-slot="${slot}" style="display:none">停止</button>
            <button class="btn btn-complete" id="btn-complete-${slot}" data-slot="${slot}" disabled>完了</button>
            <button class="btn btn-secondary btn-reset" id="btn-reset-${slot}" data-slot="${slot}" style="display:none;padding:6px 10px;">リセット</button>
            <button class="btn btn-round" id="btn-round-${slot}" data-slot="${slot}" style="display:none;padding:6px 10px;">100分に丸める</button>
        </div>
        <label class="koma-card__break" id="koma-break-label-${slot}">
            <input type="checkbox" id="koma-break-${slot}" data-slot="${slot}">
            10分休憩
        </label>
    `;
    return div;
}

function addKoma() {
    const next = currentMaxSlot() + 1;
    if (next > SLOT_MAX) {
        alert(`1日の上限（${SLOT_MAX}コマ）に達しています。`);
        return;
    }

    const grid   = document.getElementById('koma-grid');
    const addBtn = document.getElementById('btn-add-koma');
    const card   = buildKomaCard(next);

    // Insert before the add button
    grid.insertBefore(card, addBtn);

    // Initialize state
    notified[next] = { '80': false, '100': false };
    renderKoma(next);

    // Bind events for the new card
    card.querySelector(`#btn-start-${next}`)
        .addEventListener('click', () => doStart(next));
    card.querySelector(`#btn-pause-${next}`)
        .addEventListener('click', () => doPause(next));
    card.querySelector(`#btn-complete-${next}`)
        .addEventListener('click', () => doComplete(next));
    card.querySelector(`#btn-reset-${next}`)
        .addEventListener('click', () => doReset(next));
    card.querySelector(`#btn-round-${next}`)
        .addEventListener('click', () => doRoundTo100min(next));

    const nameInp = card.querySelector(`#koma-name-${next}`);
    const projInp = card.querySelector(`#koma-project-${next}`);

    let tN = null, tP = null;
    nameInp.addEventListener('blur', () => doUpdateMeta(next, nameInp.value, undefined));
    nameInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doUpdateMeta(next, nameInp.value, undefined); nameInp.blur(); } });
    nameInp.addEventListener('input', () => { clearTimeout(tN); tN = setTimeout(() => doUpdateMeta(next, nameInp.value, undefined), 800); });

    projInp.addEventListener('blur', () => doUpdateMeta(next, undefined, projInp.value));
    projInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doUpdateMeta(next, undefined, projInp.value); projInp.blur(); } });
    projInp.addEventListener('input', () => { clearTimeout(tP); tP = setTimeout(() => doUpdateMeta(next, undefined, projInp.value), 800); });

    card.querySelector(`#koma-break-${next}`)
        .addEventListener('change', e => doSetBreak(next, e.target.checked));

    // Persist the new slot count so page reload shows the same number of cards.
    // Pass CFG.today so the count is always saved to the current session date,
    // preventing midnight edge cases from writing slot_count to the next day's session.
    apiCall('set_slot_count', { count: next, date: CFG.today });

    // Update CFG.komaCount so tick/notify logic covers the new slot
    CFG.komaCount = next;

    // Scroll into view
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ================================================================
// PROJECT HISTORY DATALIST
// ================================================================

function refreshProjectHistory(newProject) {
    if (!newProject) return;
    const dl = document.getElementById('project-history-list');
    if (!dl) return;
    for (const opt of dl.options) {
        if (opt.value === newProject) return;
    }
    const opt = document.createElement('option');
    opt.value = newProject;
    dl.prepend(opt);
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ================================================================
// EVENT BINDING (TODAY'S KOMAS)
// ================================================================

function bindEvents() {
    document.querySelectorAll('.btn-start').forEach(btn => {
        btn.addEventListener('click', () => doStart(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-pause').forEach(btn => {
        btn.addEventListener('click', () => doPause(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-complete').forEach(btn => {
        btn.addEventListener('click', () => doComplete(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-reset').forEach(btn => {
        btn.addEventListener('click', () => doReset(+btn.dataset.slot));
    });
    document.querySelectorAll('.btn-round').forEach(btn => {
        btn.addEventListener('click', () => doRoundTo100min(+btn.dataset.slot));
    });

    document.querySelectorAll('.koma-card__name-input').forEach(input => {
        let t = null;
        const save = () => doUpdateMeta(+input.dataset.slot, input.value, undefined);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
    });

    document.querySelectorAll('.koma-card__project-input').forEach(input => {
        let t = null;
        const save = () => doUpdateMeta(+input.dataset.slot, undefined, input.value);
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); input.blur(); } });
        input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(save, 800); });
    });

    document.querySelectorAll('input[id^="koma-break-"]').forEach(chk => {
        chk.addEventListener('change', () => doSetBreak(+chk.dataset.slot, chk.checked));
    });
}

// ================================================================
// HISTORY
// ================================================================

function findFirstIdleSlot() {
    for (let slot = 1; slot <= CFG.komaCount; slot++) {
        const k = komaState[slot];
        if (k && k.status !== 'idle') continue;
        // 未実行でも作業内容・プロジェクトが入っていれば空きではない（保存前の入力欄も見る）
        const name    = (k?.name ?? '') + (document.getElementById(`koma-name-${slot}`)?.value ?? '');
        const project = (k?.project_id ?? '') + (document.getElementById(`koma-project-${slot}`)?.value ?? '');
        if (name.trim() === '' && project.trim() === '') return slot;
    }
    return null;
}

function copyHistoryEntry(name, projectId) {
    const slot = findFirstIdleSlot();
    if (slot === null) {
        alert('空きコマがありません。コマを追加してから再度お試しください。');
        return;
    }
    const nameEl = document.getElementById(`koma-name-${slot}`);
    const projEl = document.getElementById(`koma-project-${slot}`);
    if (nameEl) nameEl.value = name;
    if (projEl) projEl.value = projectId;
    doUpdateMeta(slot, name, projectId);
    const card = document.getElementById(`koma-card-${slot}`);
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function initHistoryEvents() {
    // Copy buttons
    document.querySelectorAll('.btn-history-copy').forEach(btn => {
        btn.addEventListener('click', () => {
            const row       = btn.closest('.koma-history__row');
            const name      = row.dataset.name      || '';
            const projectId = row.dataset.project   || '';
            copyHistoryEntry(name, projectId);
            btn.textContent = 'コピー済み';
            setTimeout(() => { btn.textContent = 'コピー'; }, 2000);
        });
    });
}

// ================================================================
// SUMMARY（今日のコマ数・Lv。コマ数の規則は includes/koma_stats.php と同じ）
// ================================================================

const KOMA_UNIT_SEC  = 80 * 60;
const KOMA_SHORT_SEC = 20 * 60;
const DAY_TIERS = CFG.homeStats?.dayTiers ?? [{ min: 6, name: 'フルデイ' }];

function fmt1(n) { return (Math.round(n * 10) / 10).toFixed(1); }

function komaValue(k) {
    if (!k || !k.segments) return 0;
    const done = isDone(k.status);
    const sec  = done ? (k.total_seconds ?? 0) : liveElapsed(k);
    if (sec <= 0) return 0;
    if (sec >= CFG.anomalyMinutes * 60) return 0;  // 閉じ忘れの作業中コマも数えない（#I-015）
    return (done && sec > KOMA_SHORT_SEC && sec < KOMA_UNIT_SEC ? KOMA_UNIT_SEC : sec) / KOMA_UNIT_SEC;
}

function todayKoma() {
    return Object.values(komaState).reduce((sum, k) => sum + komaValue(k), 0);
}

function updateSummary() {
    const today = todayKoma();
    const [, m, d] = CFG.today.split('-').map(Number);
    const title = document.getElementById('today-title');
    if (title) title.textContent = `${m}/${String(d).padStart(2, '0')}（${fmt1(today)}コマ）`;
    const next = DAY_TIERS.find(t => today < t.min);
    const nextEl = document.getElementById('today-next');
    if (nextEl) nextEl.textContent = next ? `${next.name}まで あと ${fmt1(next.min - today)} コマ` : '';

    const st = CFG.homeStats;
    if (!st) return;
    const total = st.pastTotal + today;
    const lv    = Math.floor(total / 6);
    document.getElementById('level-lv').textContent   = lv;
    document.getElementById('level-rest').textContent = fmt1((lv + 1) * 6 - total);
    document.getElementById('level-bar').style.width  = `${(total % 6) / 6 * 100}%`;
    document.getElementById('stat-total').textContent = fmt1(total);
    const side = document.getElementById('ach-today-side');
    if (side) side.textContent = `${fmt1(today)} コマ`;
    renderLevelVisual(lv);
    renderProgress(today);
    renderInfo(today);
}

// お知らせ（#T-004）: 0時までの残り・次の今日の実績・ウィークリー30
function renderInfo(today) {
    const remainEl = document.getElementById('remain-time');
    if (!remainEl) return;
    const now = new Date(Date.now() + _clockOffset);
    const midnight = new Date(now); midnight.setHours(24, 0, 0, 0);
    const min = Math.max(0, Math.floor((midnight - now) / 60000));
    remainEl.textContent = `${Math.floor(min / 60)}時間${min % 60}分`;
    document.getElementById('remain-koma').textContent = fmt1(min / 80);

    const next = DAY_TIERS.find(t => today < t.min);
    document.getElementById('info-day-text').innerHTML = next
        ? `${escHtml(next.name)}まで <b>あと ${fmt1(next.min - today)} コマ</b><br><span class="sub">締め切り: 今日 0:00</span>`
        : '今日の実績はすべて獲得しました';

    const st = CFG.homeStats;
    const week = st.progress.find(p => p.key === 'week30');
    const weekEl = document.getElementById('info-week-text');
    if (!week) { weekEl.textContent = '今週のウィークリー30は獲得済みです'; return; }
    const rest = week.goal - (week.done + (today - st.todayAtRender));
    weekEl.innerHTML = rest > 0
        ? `ウィークリー30まで <b>あと ${fmt1(rest)} コマ</b><br><span class="sub">締め切り: 土曜 24:00</span>`
        : '今週のウィークリー30は獲得済みです';
}

// Lv の絵: 30Lv ごとに実が1つ増える（最大5つ）
let _visualStage = -1;
function renderLevelVisual(lv) {
    const el = document.getElementById('level-visual');
    const stage = Math.min(5, Math.floor(lv / 30) + 1);
    if (!el || stage === _visualStage) return;
    _visualStage = stage;
    const fruits = [[36, 36], [60, 30], [48, 50], [28, 54], [68, 52]].slice(0, stage)
        .map(([x, y]) => `<circle cx="${x}" cy="${y}" r="4.5" class="lv-fruit"/>`).join('');
    el.innerHTML = `<svg viewBox="0 0 96 96">
        <ellipse cx="48" cy="88" rx="36" ry="5" class="lv-ground"/><rect x="44" y="50" width="8" height="38" rx="2" class="lv-trunk"/>
        <circle cx="48" cy="40" r="26" class="lv-leaf"/><circle cx="30" cy="52" r="14" class="lv-leaf2"/><circle cx="66" cy="52" r="14" class="lv-leaf2"/>
        <circle cx="48" cy="24" r="14" class="lv-leaf3"/>${fruits}</svg>`;
}

// ================================================================
// ACHIEVEMENTS（#T-005。判定は includes/achievements.php、ここは今日の分の足し直しと宝箱だけ）
// ================================================================

let _progressHtml = '';
function renderProgress(today) {
    const st = CFG.homeStats;
    const el = document.getElementById('ach-progress');
    if (!st || !el) return;
    const delta = today - st.todayAtRender;  // ページを開いてから増えた今日のコマ数
    const items = st.progress.map(p => ({ ...p, done: p.done + delta * p.live }));
    const next = DAY_TIERS.find(t => today < t.min);
    if (next) items.push({ key: next.key, name: next.name, done: today, goal: next.min, unit: 'コマ', due: '今日 0:00 まで', svg: st.daySvgs[next.key] });

    const html = items
        .filter(p => p.done < p.goal)
        .map(p => ({ ...p, pct: p.done / p.goal * 100 }))
        .sort((a, b) => b.pct - a.pct)
        .map(p => `<div class="prog__row">
            <div class="prog__ico">${p.svg}</div>
            <div class="prog__text">${escHtml(p.name)}獲得まで あと<b>${p.unit === '日' ? p.goal - p.done : fmt1(p.goal - p.done)}</b>${p.unit}</div>
            <div class="prog__meta">${Math.floor(p.pct)}%${p.due ? `・${p.due}` : ''}</div>
            <div class="meter"><div class="meter__fill" style="width:${Math.min(100, p.pct)}%"></div></div>
        </div>`).join('');
    if (html !== _progressHtml) el.innerHTML = _progressHtml = html;
}

function initChest() {
    const chest = document.getElementById('btn-chest');
    const modal = document.getElementById('chest-modal');
    if (!chest || !modal) return;
    const close = () => { modal.hidden = true; };
    document.getElementById('chest-modal-close').addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    chest.addEventListener('click', async () => {
        if (chest.classList.contains('is-open')) return;
        chest.classList.add('is-open');
        const res = await apiCall('open_achievements');
        if (!res.ok) { chest.classList.remove('is-open'); alert('実績を受け取れませんでした。'); return; }
        document.getElementById('chest-modal-list').innerHTML = res.items.map((it, i) => `
            <div class="modal__item" style="animation-delay:${i * 60}ms">
                <div class="ach">${it.svg}${it.count > 1 ? `<span class="ach__count">${it.count}</span>` : ''}</div>
                <div class="modal__name">${escHtml(it.name)}</div>
            </div>`).join('');
        setTimeout(() => {
            modal.hidden = false;
            document.getElementById('chest-modal-close').focus();
            chest.closest('.info-row').remove();
        }, 400);
    });
}

// ================================================================
// CALENDAR（直近26週。色の段階は今日の実績の段階にそろえる）
// ================================================================

function calendarLevel(v) {
    return v <= 0 ? 0 : v < 3 ? 1 : v < 6 ? 2 : v < 8 ? 3 : v < 10 ? 4 : 5;
}

function renderCalendar() {
    const st   = CFG.homeStats;
    const grid = document.getElementById('cal-grid');
    if (!st || !grid || grid.childElementCount) return;
    const cells = [], months = [];
    const day   = new Date(`${st.calStart}T00:00:00`);
    const end   = new Date(`${CFG.today}T00:00:00`);
    for (; day <= end; day.setDate(day.getDate() + 1)) {
        const ymd = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
        const isToday = ymd === CFG.today;
        const v = isToday ? todayKoma() : (st.calendar[ymd] ?? 0);
        const got = DAY_TIERS.filter(t => v >= t.min).map(t => t.name);
        const tip = `<b>${day.getMonth() + 1}/${String(day.getDate()).padStart(2, '0')}（${fmt1(v)}コマ）</b>`
            + (isToday ? ' <span class="tip__dim">今日</span>' : '') + (got.length ? `<br>${got.join('・')}` : '');
        cells.push(`<div class="cal__cell${isToday ? ' is-today' : ''}" data-lv="${calendarLevel(v)}" data-tip="${escHtml(tip)}"></div>`);
        if (day.getDay() === 0) months.push(day.getDate() <= 7 ? `${day.getMonth() + 1}月` : '');
    }
    grid.innerHTML = cells.join('');
    document.getElementById('cal-months').innerHTML = months.map(m => `<span>${m}</span>`).join('');
}

// ================================================================
// FOLD（カレンダー・履歴の開け閉め）と TOOLTIP
// ================================================================

function initFolds() {
    document.querySelectorAll('.fold__toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const fold   = btn.closest('.fold');
            const closed = fold.classList.toggle('is-closed');
            btn.setAttribute('aria-expanded', String(!closed));
            btn.querySelector('.fold__icon').textContent = closed ? '▼' : '▲';
            if (!closed && fold.id === 'calendar-panel') renderCalendar();
        });
    });
}

// data-tip に入れた HTML をマウスオーバー・フォーカスで出す（中身はこのファイルが組み立てたものだけ）
function initTooltip() {
    const tip = document.createElement('div');
    tip.className = 'tip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    const show = el => {
        tip.innerHTML = el.dataset.tip;
        tip.classList.add('is-show');
        const r = el.getBoundingClientRect(), t = tip.getBoundingClientRect();
        let y = r.bottom + 8;
        if (y + t.height > innerHeight - 8) y = r.top - t.height - 8;
        tip.style.left = `${Math.max(8, Math.min(r.left + r.width / 2 - t.width / 2, innerWidth - t.width - 8))}px`;
        tip.style.top  = `${y}px`;
    };
    const hide = () => tip.classList.remove('is-show');
    document.addEventListener('mouseover', e => { const el = e.target.closest('[data-tip]'); el ? show(el) : hide(); });
    document.addEventListener('focusin', e => { const el = e.target.closest('[data-tip]'); if (el) show(el); });
    document.addEventListener('focusout', hide);
    addEventListener('scroll', hide, { passive: true });
}

// ================================================================
// BOOT
// ================================================================

document.addEventListener('DOMContentLoaded', () => {
    initFromState(CFG.initialState);
    initPrevIncomplete(CFG.prevIncomplete);
    bindEvents();
    initHistoryEvents();
    initFolds();
    initTooltip();
    initChest();
    setInterval(() => renderInfo(todayKoma()), 30000);  // 実行中のコマがなくても残り時間を進める
    updateSummary();

    const addBtn = document.getElementById('btn-add-koma');
    if (addBtn) addBtn.addEventListener('click', addKoma);
});
