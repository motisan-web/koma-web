/**
 * 統計画面（#T-006）— 期間・プロジェクト・時間帯・集中度・記録
 * PHP（koma_stats_records）が渡す全コマの一覧をブラウザ側で集計してグラフにする。
 * コマ数（v）の規則は includes/koma_stats.php の koma_value() と同じ。
 */
'use strict';

const ST = window.STATS;
const DAY = 86400000;
const WD = ['日', '月', '火', '水', '木', '金', '土'];
const KOMA_GOAL_DAY = 6, KOMA_GOAL_WEEK = 30, KOMA_GOAL_MONTH = 120, KOMA_PROJECT_STEP = 50;

const parseDate = s => new Date(`${s}T00:00:00`);
const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const md = d => `${d.getMonth() + 1}/${d.getDate()}`;
const fmt1 = n => (Math.round(n * 10) / 10).toFixed(1);
const hm = m => `${Math.floor(m / 60)}:${String(Math.round(m % 60)).padStart(2, '0')}`;
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
// 4時より前に始めたコマは前の晩の続きとみなし、「始業」には数えない
const DAY_START_MIN = 4 * 60;
const startMin = k => k.seg[0]?.[0];
const isDayStart = k => startMin(k) >= DAY_START_MIN;
const today = parseDate(ST.today);

const ACTIVE = ['running', 'paused', 'overtime'];
const KOMAS = ST.komas.map(k => ({
    ...k, date: parseDate(k.d), pauses: Math.max(0, k.seg.length - 1), active: ACTIVE.includes(k.st),
}));
// 分数を使う集計（長さ・時間帯）では異常値と作業中のコマを除く
const timed = k => !k.a && !k.active;

const byDay = new Map();
KOMAS.forEach(k => { const a = byDay.get(k.d) ?? []; a.push(k); byDay.set(k.d, a); });
const dayKoma = key => sum(byDay.get(key) ?? [], k => k.v);

const projName = id => id === '' ? '（プロジェクトなし）' : id.replace(/^#?project\//, '');
const projLink = id => `?tab=project&p=${encodeURIComponent(id)}`;

// 色はプロジェクトに固定（全期間の上位5件に1〜5番、ほかは「その他」）。表示中の順位では塗り直さない
const projTotalAll = {};
KOMAS.forEach(k => projTotalAll[k.p] = (projTotalAll[k.p] ?? 0) + k.v);
const TOP = Object.entries(projTotalAll).sort((a, b) => b[1] - a[1]).slice(0, 5).map(([id]) => id);
const OTHER = '\u0000other';
const colorOf = id => TOP.includes(id) ? `var(--p${TOP.indexOf(id) + 1})` : 'var(--p-other)';
const groupOf = id => TOP.includes(id) ? id : OTHER;
const GROUPS = [...TOP, OTHER];
const groupName = g => g === OTHER ? 'その他' : projName(g);
const groupColor = g => g === OTHER ? 'var(--p-other)' : colorOf(g);
const groupParts = ks => GROUPS.map(g => ({ g, v: sum(ks.filter(k => groupOf(k.p) === g), k => k.v), color: groupColor(g) }));
const partsTip = parts => parts.filter(p => p.v > 0)
    .map(p => `<div class="tip__row"><span><i style="background:${p.color}"></i>${esc(groupName(p.g))}</span><span>${fmt1(p.v)}</span></div>`).join('');
const legendHtml = () => GROUPS.filter(g => g !== OTHER || KOMAS.some(k => !TOP.includes(k.p)))
    .map(g => `<span><i style="background:${groupColor(g)}"></i>${esc(groupName(g))}</span>`).join('');

// =========================================================
// グラフの部品（SVG を手で組む。軸は1本だけ、目盛りは控えめ）
// =========================================================
let W = 720;  // 描く直前に入れ物の幅へ合わせる（文字が縮まないように）
const el = id => document.getElementById(id);
const widthOf = id => Math.max(300, el(id).clientWidth);

function niceMax(v, step) { return Math.max(step, Math.ceil(v / step) * step); }
function roundTop(x, y, w, h, r) {
    r = Math.min(r, h, w / 2);
    return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
}
/** 縦棒（積み上げ可）。items: [{label, parts:[{v, color}], tip}] */
function barChart(items, { height = 220, step = 2, refs = [], every = 1 } = {}) {
    const padL = 34, padR = 8, padT = 12, padB = 26, H = height;
    const total = it => sum(it.parts, p => p.v);
    const max = niceMax(Math.max(0, ...items.map(total), ...refs.map(r => r.v)) * 1.05, step);
    const plotW = W - padL - padR, plotH = H - padT - padB;
    const band = plotW / Math.max(1, items.length), bw = Math.max(3, Math.min(28, band * .62));
    const y = v => padT + plotH - v / max * plotH;
    let g = '';
    for (let v = 0; v <= max; v += step) {
        g += `<line class="${v === 0 ? 'axis' : 'grid'}" x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/><text x="${padL - 6}" y="${y(v) + 4}" text-anchor="end">${v}</text>`;
    }
    items.forEach((it, i) => {
        const x = padL + band * i + (band - bw) / 2;
        const parts = it.parts.filter(p => p.v > 0);
        let acc = 0;
        parts.forEach((p, j) => {
            const y0 = y(acc), y1 = y(acc + p.v);
            g += j === parts.length - 1
                ? `<path d="${roundTop(x, y1, bw, Math.max(0, y0 - y1), 4)}" fill="${p.color}"/>`
                : `<rect x="${x}" y="${y1}" width="${bw}" height="${Math.max(0, y0 - y1 - 2)}" fill="${p.color}"/>`;
            acc += p.v;
        });
        if (i % every === 0) g += `<text x="${padL + band * i + band / 2}" y="${H - 8}" text-anchor="middle">${it.label}</text>`;
        g += `<rect class="hit" x="${padL + band * i}" y="${padT}" width="${band}" height="${plotH}" data-tip="${esc(it.tip)}"/>`;
    });
    refs.forEach(r => {
        g += `<line class="ref" x1="${padL}" x2="${W - padR}" y1="${y(r.v)}" y2="${y(r.v)}"/><text class="ref-label" x="${W - padR}" y="${y(r.v) - 5}" text-anchor="end">${r.label}</text>`;
    });
    return `<svg viewBox="0 0 ${W} ${H}" role="img">${g}</svg>`;
}
/** 折れ線。series: [{values, color, dash}] */
function lineChart(labels, series, { height = 200, step = 5, tips = [] } = {}) {
    const padL = 34, padR = 10, padT = 12, padB = 26, H = height;
    const max = niceMax(Math.max(0, ...series.flatMap(s => s.values.filter(v => v != null))) * 1.05, step);
    const plotW = W - padL - padR, plotH = H - padT - padB, n = labels.length;
    const x = i => padL + (n === 1 ? plotW / 2 : plotW * i / (n - 1));
    const y = v => padT + plotH - v / max * plotH;
    let g = '';
    for (let v = 0; v <= max; v += step) g += `<line class="${v === 0 ? 'axis' : 'grid'}" x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/><text x="${padL - 6}" y="${y(v) + 4}" text-anchor="end">${v}</text>`;
    const every = Math.ceil(n / 10);
    labels.forEach((l, i) => { if (i % every === 0) g += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${l}</text>`; });
    series.forEach(s => {
        const pts = s.values.map((v, i) => v == null ? null : `${x(i)},${y(v)}`).filter(Boolean);
        g += `<polyline points="${pts.join(' ')}" fill="none" stroke="${s.color}" stroke-width="2" stroke-linejoin="round" ${s.dash ? 'stroke-dasharray="5 4"' : ''}/>`;
        const last = s.values.map((v, i) => [v, i]).filter(([v]) => v != null).pop();
        if (last && !s.dash) g += `<circle cx="${x(last[1])}" cy="${y(last[0])}" r="4" fill="${s.color}" stroke="var(--bg-card)" stroke-width="2"/>`;
    });
    const w = plotW / Math.max(1, n - 1);
    labels.forEach((_, i) => { g += `<rect class="hit" x="${x(i) - w / 2}" y="${padT}" width="${w}" height="${plotH}" data-tip="${esc(tips[i] ?? '')}"/>`; });
    return `<svg viewBox="0 0 ${W} ${H}" role="img">${g}</svg>`;
}
function hbars(rows, max) {
    if (!rows.length) return '<p class="stats-empty">データがありません</p>';
    return rows.map(r => `<div class="hbar"${r.tip ? ` data-tip="${esc(r.tip)}"` : ''}>
        <div class="hbar__name">${r.color ? `<i style="background:${r.color}"></i>` : ''}${r.link ? `<a class="plink" href="${r.link}">${esc(r.name)}</a>` : esc(r.name)}</div>
        <div class="hbar__track"><div class="hbar__fill" style="width:${max ? r.v / max * 100 : 0}%;background:${r.color ?? 'var(--accent)'}"></div></div>
        <div class="hbar__val">${r.val}</div></div>`).join('');
}
function tile(label, value, unit, delta, sub, deltaLabel = '前の期間の同じ日まで比') {
    const cls = delta > 0.05 ? 'up' : delta < -0.05 ? 'down' : '';
    return `<div class="tile"><div class="tile__label">${label}</div>
        <div class="tile__value">${value}<small>${unit}</small></div>
        ${delta != null ? `<div class="tile__delta ${cls}">${delta > 0 ? '▲' : delta < 0 ? '▼' : '±'} ${fmt1(Math.abs(delta))}${unit} ${deltaLabel}</div>` : ''}
        ${sub ? `<div class="tile__sub">${sub}</div>` : ''}</div>`;
}
function heatHtml(ks) {
    const heat = Array.from({ length: 7 }, () => Array(24).fill(0));
    ks.forEach(k => k.seg.forEach(([s, e]) => {
        for (let m = Math.max(0, s); m < e; m++) {
            const dayShift = Math.floor(m / 1440), h = Math.floor(m % 1440 / 60);  // 0時をまたいだ分は翌日に入れる
            heat[(k.date.getDay() + dayShift) % 7][h]++;
        }
    }));
    const max = Math.max(1, ...heat.flat());
    const q = v => v === 0 ? 0 : Math.min(5, Math.ceil(v / max * 5));
    let html = '<span></span>' + Array.from({ length: 24 }, (_, h) => `<span class="heat__hour">${h % 3 === 0 ? h : ''}</span>`).join('');
    [1, 2, 3, 4, 5, 6, 0].forEach(d => {
        html += `<span class="heat__day">${WD[d]}</span>` + heat[d].map((v, h) => `<div class="heat__cell" data-q="${q(v)}" data-tip="${esc(`<b>${WD[d]}曜 ${h}時台</b><br>合計 ${hm(v)}`)}"></div>`).join('');
    });
    return { html, heat };
}
function yearHtml(valueOf, q) {
    const start = new Date(today.getTime() - (today.getDay() + 52 * 7) * DAY), cells = [], months = [];
    let total = 0;
    for (let t = start.getTime(); t <= today.getTime(); t += DAY) {
        const d = new Date(t), v = valueOf(ymd(d));
        total += v;
        cells.push(`<div class="year__cell" data-q="${q(v)}" data-tip="${esc(`<b>${d.getFullYear()}/${md(d)}（${WD[d.getDay()]}）</b><br>${fmt1(v)}コマ`)}"></div>`);
        if (d.getDay() === 0) months.push(d.getDate() <= 7 ? `${d.getMonth() + 1}月` : '');
    }
    return { grid: cells.join(''), months: months.map(m => `<span>${m}</span>`).join(''), total };
}
function daysOf(start, end) { const a = []; for (let t = start.getTime(); t <= end.getTime(); t += DAY) a.push(new Date(t)); return a; }
const weekStartOf = (d, back = 0) => new Date(d.getTime() - (d.getDay() + 7 * back) * DAY);

// =========================================================
// 期間（週・月）
// =========================================================
function periodRange(unit, offset) {
    if (unit === 'week') {
        const s = weekStartOf(today, offset), e = new Date(s.getTime() + 6 * DAY);
        return { start: s, end: e, label: `${s.getFullYear()}/${md(s)} 〜 ${md(e)}` };
    }
    const s = new Date(today.getFullYear(), today.getMonth() - offset, 1);
    return { start: s, end: new Date(s.getFullYear(), s.getMonth() + 1, 0), label: `${s.getFullYear()}年${s.getMonth() + 1}月` };
}
function summarize(days) {
    const ks = days.flatMap(d => byDay.get(ymd(d)) ?? []);
    return {
        ks, koma: sum(ks, k => k.v), min: sum(ks.filter(k => !k.a), k => k.m),
        active: days.filter(d => dayKoma(ymd(d)) > 0).length, full: days.filter(d => dayKoma(ymd(d)) >= KOMA_GOAL_DAY).length,
    };
}
function renderPeriod() {
    const { unit, offset } = ST.period;
    const r = periodRange(unit, offset), prev = periodRange(unit, offset + 1);
    const days = daysOf(r.start, r.end), pdays = daysOf(prev.start, prev.end);
    // 進行中の期間は、前の期間も同じ日数までで比べる
    const elapsed = days.filter(d => d <= today).length;
    const cur = summarize(days.slice(0, elapsed)), old = summarize(pdays.slice(0, elapsed));
    el('period-label').textContent = r.label;
    el('period-next').disabled = offset === 0;
    const goal = unit === 'week' ? KOMA_GOAL_WEEK : KOMA_GOAL_MONTH;
    const avg = s => s.active ? s.koma / s.active : 0;
    el('period-tiles').innerHTML =
        tile('合計', fmt1(cur.koma), 'コマ', cur.koma - old.koma, `${unit === 'week' ? 'ウィークリー30' : 'マンスリー120'}まで ${cur.koma >= goal ? '達成！' : 'あと ' + fmt1(goal - cur.koma)}`)
        + tile('作業時間', fmt1(cur.min / 60), '時間', (cur.min - old.min) / 60)
        + tile('稼働日数', cur.active, '日', cur.active - old.active)
        + tile('平均', fmt1(avg(cur)), 'コマ/日', avg(cur) - avg(old), '稼働した日あたり')
        + tile('フルデイ', cur.full, '日', cur.full - old.full);

    const items = days.map(d => {
        const parts = groupParts(byDay.get(ymd(d)) ?? []);
        const tot = sum(parts, p => p.v);
        return {
            label: unit === 'week' ? `${md(d)} ${WD[d.getDay()]}` : d.getDate(), parts,
            tip: `<b>${md(d)}（${WD[d.getDay()]}）${fmt1(tot)}コマ</b>` + (d > today ? '<br>まだ先の日' : partsTip(parts)),
        };
    });
    W = widthOf('period-bars');
    el('period-bars').innerHTML = barChart(items, { refs: [{ v: KOMA_GOAL_DAY, label: 'フルデイ 6' }], every: unit === 'week' ? 1 : 2 });
    el('period-legend').innerHTML = legendHtml();

    // ペース: 1日目からの累計を前の期間と比べる
    const cum = (ds, cut) => { let s = 0; return ds.map(d => (cut && d > today) ? null : (s += dayKoma(ymd(d)))); };
    const len = Math.max(days.length, pdays.length);
    const a = cum(days, true), b = cum(pdays, false);
    const labels = Array.from({ length: len }, (_, i) => unit === 'week' ? WD[i] : `${i + 1}日`);
    const tips = labels.map((l, i) => `<b>${l}${unit === 'week' ? '曜' : ''}まで</b><div class="tip__row"><span>この期間</span><span>${a[i] == null ? '—' : fmt1(a[i])}</span></div><div class="tip__row"><span>前の期間</span><span>${b[i] == null ? '—' : fmt1(b[i])}</span></div>`);
    W = widthOf('period-pace');
    el('period-pace').innerHTML = lineChart(labels, [{ values: b, color: 'var(--prev)', dash: true }, { values: a, color: 'var(--p1)' }],
        { step: unit === 'week' ? 5 : 20, tips, height: 210 });

    const pt = {};
    cur.ks.forEach(k => pt[k.p] = (pt[k.p] ?? 0) + k.v);
    const rows = Object.entries(pt).filter(([, v]) => v > 0).sort((x, y) => y[1] - x[1])
        .map(([id, v]) => ({ name: projName(id), link: projLink(id), v, color: colorOf(id), val: `<b>${fmt1(v)}</b> コマ・${Math.round(v / cur.koma * 100)}%` }));
    el('period-projects').innerHTML = hbars(rows, rows[0]?.v);

    const wd = Array.from({ length: 7 }, () => ({ s: 0, n: 0 }));
    days.filter(d => d <= today).forEach(d => { const v = dayKoma(ymd(d)); if (v > 0) { wd[d.getDay()].s += v; wd[d.getDay()].n++; } });
    const wrows = wd.map((w, i) => ({ name: `${WD[i]}曜`, v: w.n ? w.s / w.n : 0, val: w.n ? `<b>${fmt1(w.s / w.n)}</b> コマ（${w.n}日）` : '—' }));
    el('period-weekday').innerHTML = hbars(wrows, Math.max(...wrows.map(x => x.v)));

    el('period-table').innerHTML = '<thead><tr><th>日付</th><th class="r">コマ数</th><th class="r">作業時間</th><th class="r">コマの数</th></tr></thead><tbody>'
        + days.filter(d => d <= today).map(d => {
            const ks = byDay.get(ymd(d)) ?? [];
            return `<tr><td><a href="?tab=day&amp;date=${ymd(d)}">${md(d)}（${WD[d.getDay()]}）</a></td><td class="r">${ks.length ? fmt1(dayKoma(ymd(d))) : '—'}</td>
                <td class="r">${ks.length ? hm(sum(ks.filter(k => !k.a), k => k.m)) : '—'}</td><td class="r">${ks.length || '—'}</td></tr>`;
        }).join('') + '</tbody>';

    // 再読み込みしても同じ期間を開けるように URL に残す
    history.replaceState(null, '', `?tab=period&unit=${unit}&offset=${offset}`);
}
function initPeriod() {
    document.querySelectorAll('[data-unit]').forEach(b => {
        b.setAttribute('aria-pressed', String(b.dataset.unit === ST.period.unit));
        b.addEventListener('click', () => {
            ST.period = { unit: b.dataset.unit, offset: 0 };
            document.querySelectorAll('[data-unit]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
            renderPeriod();
        });
    });
    el('period-prev').addEventListener('click', () => { ST.period.offset++; renderPeriod(); });
    el('period-next').addEventListener('click', () => { ST.period.offset = Math.max(0, ST.period.offset - 1); renderPeriod(); });
}

// =========================================================
// プロジェクト（一覧）
// =========================================================
function renderProject() {
    const range = document.querySelector('[data-prange][aria-pressed="true"]').dataset.prange;
    const from = range === 'all' ? new Date(0) : new Date(today.getTime() - (range - 1) * DAY);
    const ks = KOMAS.filter(k => k.date >= from);
    const total = sum(ks, k => k.v);
    const per = {};
    ks.forEach(k => per[k.p] = (per[k.p] ?? 0) + k.v);
    const rows = Object.entries(per).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
    el('proj-total').textContent = `合計 ${fmt1(total)} コマ`;
    el('proj-share').innerHTML = hbars(rows.map(([id, v]) => ({ name: projName(id), link: projLink(id), v, color: colorOf(id), val: `<b>${Math.round(v / total * 100)}%</b>・${fmt1(v)}` })), rows[0]?.[1]);

    // 一覧は全期間のすべてのプロジェクト（選んだ期間に触っていないものも出す）
    const all = {};
    KOMAS.forEach(k => {
        const a = all[k.p] ??= { v: 0, min: 0, n: 0, last: k.date };
        a.v += k.v; if (timed(k)) { a.min += k.m; a.n++; } if (k.date > a.last) a.last = k.date;
    });
    const label = range === 'all' ? '全期間' : `${range}日`;
    el('proj-table').innerHTML = `<thead><tr><th>プロジェクト</th><th class="r">${label}</th><th class="r">全期間</th><th class="r">作業時間</th><th class="r">平均の長さ</th><th>最後に触った日</th><th>職人まで</th></tr></thead><tbody>`
        + Object.entries(all).sort((a, b) => b[1].v - a[1].v).map(([id, a]) => {
            const ago = Math.round((today - a.last) / DAY);
            return `<tr><td><i class="swatch" style="background:${colorOf(id)}"></i><a class="plink" href="${projLink(id)}">${esc(projName(id))}</a></td>
                <td class="r">${per[id] ? fmt1(per[id]) : '—'}</td><td class="r">${fmt1(a.v)}</td><td class="r">${hm(a.min)}</td><td class="r">${a.n ? Math.round(a.min / a.n) + '分' : '—'}</td>
                <td>${ago === 0 ? '今日' : ago + '日前'}${ago > 7 ? '（職人は非表示）' : ''}</td>
                <td><span class="mini-meter"><span style="width:${(a.v % KOMA_PROJECT_STEP) / KOMA_PROJECT_STEP * 100}%"></span></span>あと ${fmt1(KOMA_PROJECT_STEP - a.v % KOMA_PROJECT_STEP)}</td></tr>`;
        }).join('') + '</tbody>';

    const weeks = [];
    for (let i = 11; i >= 0; i--) {
        const s = weekStartOf(today, i), wks = daysOf(s, new Date(s.getTime() + 6 * DAY)).flatMap(d => byDay.get(ymd(d)) ?? []);
        const parts = groupParts(wks);
        weeks.push({ label: md(s), parts, tip: `<b>${md(s)} の週 ${fmt1(sum(parts, p => p.v))}コマ</b>` + partsTip(parts) });
    }
    W = widthOf('proj-weekly');
    el('proj-weekly').innerHTML = barChart(weeks, { step: 10, refs: [{ v: KOMA_GOAL_WEEK, label: 'ウィークリー30' }], every: 2 });
    el('proj-legend').innerHTML = legendHtml();
}
function initProject() {
    document.querySelectorAll('[data-prange]').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('[data-prange]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
        renderProject();
    }));
}

// =========================================================
// プロジェクト単体（?tab=project&p=<id>）
// =========================================================
function renderProjectDetail() {
    const id = ST.project;
    const ks = KOMAS.filter(k => k.p === id);
    const done = ks.filter(timed);
    const total = sum(ks, k => k.v);
    const days = new Set(ks.map(k => k.d));
    el('pd-name').innerHTML = `<i style="background:${colorOf(id)}"></i>${esc(projName(id))}`;
    if (!ks.length) { el('pd-span').textContent = 'このプロジェクトのコマはありません'; return; }
    const first = ks[0].date, last = ks.at(-1).date, ago = Math.round((today - last) / DAY);
    const inRange = (a, b) => sum(ks.filter(k => k.date > new Date(today.getTime() - a * DAY) && k.date <= new Date(today.getTime() - b * DAY)), k => k.v);
    const last30 = inRange(30, 0), prev30 = inRange(60, 30);
    el('pd-span').textContent = `${first.getFullYear()}/${md(first)} 〜 ${last.getFullYear()}/${md(last)}（最後に触ったのは ${ago ? ago + '日前' : '今日'}）`;
    el('pd-tiles').innerHTML =
        tile('累計', fmt1(total), 'コマ', null, `全体の ${Math.round(total / Math.max(0.001, sum(KOMAS, k => k.v)) * 100)}%`)
        + tile('作業時間', fmt1(sum(ks.filter(k => !k.a), k => k.m) / 60), '時間')
        + tile('触った日数', days.size, '日', null, `1日あたり ${fmt1(total / days.size)} コマ`)
        + tile('平均の長さ', done.length ? Math.round(sum(done, k => k.m) / done.length) : '—', '分', null, done.length ? `一時停止なし ${Math.round(done.filter(k => k.pauses === 0).length / done.length * 100)}%` : '')
        + tile('直近30日', fmt1(last30), 'コマ', last30 - prev30, null, 'その前の30日比')
        + tile('職人', Math.floor(total / KOMA_PROJECT_STEP), '回', null, `次まで あと ${fmt1(KOMA_PROJECT_STEP - total % KOMA_PROJECT_STEP)} コマ`);

    const weeks = [];
    for (let i = 25; i >= 0; i--) {
        const s = weekStartOf(today, i), e = new Date(s.getTime() + 7 * DAY);
        const w = ks.filter(k => k.date >= s && k.date < e), v = sum(w, k => k.v);
        weeks.push({ label: md(s), parts: [{ v, color: colorOf(id) }], tip: `<b>${md(s)} の週</b><br>${fmt1(v)} コマ・${w.length} 個` });
    }
    W = widthOf('pd-weekly');
    el('pd-weekly').innerHTML = barChart(weeks, { step: 5, every: 3, height: 200 });

    const names = {};
    ks.forEach(k => { const nm = k.n || '（作業内容なし）'; const a = names[nm] ??= { v: 0, n: 0, last: k.date }; a.v += k.v; a.n++; if (k.date > a.last) a.last = k.date; });
    const nrows = Object.entries(names).sort((a, b) => b[1].v - a[1].v).slice(0, 15);
    el('pd-names').innerHTML = hbars(nrows.map(([nm, a]) => ({ name: nm, v: a.v, color: colorOf(id),
        val: `<b>${fmt1(a.v)}</b> コマ・${Math.round(a.v / total * 100)}%`, tip: `<b>${esc(nm)}</b><br>${a.n} 回・最後は ${md(a.last)}` })), nrows[0]?.[1].v);

    el('pd-heat').innerHTML = heatHtml(ks.filter(timed)).html;

    const year = yearHtml(key => sum((byDay.get(key) ?? []).filter(k => k.p === id), k => k.v), v => v <= 0 ? 0 : Math.min(5, Math.ceil(v)));
    el('pd-year-grid').innerHTML = year.grid;
    el('pd-year-months').innerHTML = year.months;

    el('pd-recent').innerHTML = '<thead><tr><th>日付</th><th>コマ</th><th>作業内容</th><th class="r">時間</th><th class="r">一時停止</th></tr></thead><tbody>'
        + ks.slice(-20).reverse().map(k => `<tr><td><a href="?tab=day&amp;date=${k.d}">${k.date.getFullYear()}/${md(k.date)}（${WD[k.date.getDay()]}）</a></td><td>コマ${k.s}</td><td>${esc(k.n || '—')}</td>
            <td class="r">${Math.round(k.m)}分${k.active ? '（作業中）' : ''}${k.a ? '（異常値）' : ''}</td><td class="r">${k.pauses}回</td></tr>`).join('') + '</tbody>';
}

// =========================================================
// 時間帯
// =========================================================
function renderRhythm() {
    const from = new Date(today.getTime() - 55 * DAY);
    const recent = KOMAS.filter(k => k.date >= from && timed(k));
    const { html, heat } = heatHtml(recent);
    el('heat').innerHTML = html;

    const hourSum = Array(24).fill(0);
    heat.forEach(r => r.forEach((v, h) => hourSum[h] += v));
    const peak = hourSum.indexOf(Math.max(...hourSum));
    const days30 = daysOf(new Date(today.getTime() - 29 * DAY), today).map(d => ({ d, ks: (byDay.get(ymd(d)) ?? []).filter(k => k.seg.length && !k.a) }));
    const worked = days30.filter(x => x.ks.length);
    const startOf = ks => Math.min(...(ks.some(isDayStart) ? ks.filter(isDayStart) : ks).map(startMin)), endOf = ks => Math.max(...ks.map(k => k.seg.at(-1)[1]));
    const avg = a => a.length ? sum(a, v => v) / a.length : null;
    const sAvg = avg(worked.map(x => startOf(x.ks))), eAvg = avg(worked.map(x => endOf(x.ks)));
    const morning = recent.length ? recent.filter(k => k.seg[0]?.[0] < 12 * 60).length / recent.length : null;
    el('rhythm-empty').hidden = recent.length > 0;
    el('rhythm-tiles').innerHTML = tile('いちばん作業している時間帯', recent.length ? `${peak}〜${peak + 1}` : '—', '時', null, '直近8週')
        + tile('平均の始業', sAvg == null ? '—' : hm(sAvg), '', null, '直近30日・最初のコマの開始')
        + tile('平均の終業', eAvg == null ? '—' : hm(eAvg), '', null, '直近30日・最後のコマの終了')
        + tile('午前に始めたコマ', morning == null ? '—' : Math.round(morning * 100), '%', null, '直近8週・12時より前に開始');

    W = widthOf('rhythm-range');
    const H = 230, padL = 40, padR = 8, padT = 10, padB = 26, plotW = W - padL - padR, plotH = H - padT - padB;
    const ends = worked.map(x => endOf(x.ks));
    const t0 = 6 * 60, t1 = Math.max(24 * 60, Math.ceil(Math.max(0, ...ends) / 240) * 240);
    const y = m => padT + (Math.max(t0, m) - t0) / (t1 - t0) * plotH;
    const band = plotW / days30.length;
    let g = '';
    for (let h = 6; h * 60 <= t1; h += 3) g += `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${y(h * 60)}" y2="${y(h * 60)}"/><text x="${padL - 6}" y="${y(h * 60) + 4}" text-anchor="end">${h}:00</text>`;
    days30.forEach((x, i) => {
        const cx = padL + band * i + band / 2;
        if (i % 3 === 0) g += `<text x="${cx}" y="${H - 8}" text-anchor="middle">${md(x.d)}</text>`;
        if (!x.ks.length) return;
        const s = startOf(x.ks), e = endOf(x.ks);
        g += `<line x1="${cx}" x2="${cx}" y1="${y(s)}" y2="${y(e)}" stroke="var(--seq2)" stroke-width="${Math.max(3, band * .35)}" stroke-linecap="round"/>`
            + `<circle cx="${cx}" cy="${y(s)}" r="4" fill="var(--p1)" stroke="var(--bg-card)" stroke-width="2"/><circle cx="${cx}" cy="${y(e)}" r="4" fill="var(--p2)" stroke="var(--bg-card)" stroke-width="2"/>`
            + `<rect class="hit" x="${padL + band * i}" y="${padT}" width="${band}" height="${plotH}" data-tip="${esc(`<b>${md(x.d)}（${WD[x.d.getDay()]}）</b><br>始業 ${hm(s)} 〜 終業 ${hm(e)}<br>${fmt1(dayKoma(ymd(x.d)))}コマ`)}"/>`;
    });
    el('rhythm-range').innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img">${g}</svg>`;
}

// =========================================================
// 集中度
// =========================================================
function renderFocus() {
    const from = new Date(today.getTime() - 89 * DAY);
    const ks = KOMAS.filter(k => k.date >= from && timed(k));
    const n = ks.length;
    const pct = f => n ? Math.round(ks.filter(f).length / n * 100) : '—';
    el('focus-empty').hidden = n > 0;
    el('focus-tiles').innerHTML = tile('平均の長さ', n ? Math.round(sum(ks, k => k.m) / n) : '—', '分', null, '目安は80分')
        + tile('80分前後で終えた', pct(k => k.m >= 75 && k.m <= 95), '%', null, '75〜95分')
        + tile('100分を超えた', pct(k => k.m > 100), '%', null, '超過しがちかどうか')
        + tile('一度も止めずに終えた', pct(k => k.pauses === 0), '%', null, '一時停止0回')
        + tile('中止したコマ', pct(k => k.st === 'closed' || k.st === 'auto_closed'), '%', null, '前日の未完了を中止');

    const bins = Array.from({ length: 16 }, (_, i) => ({ from: i * 10, n: 0 }));
    ks.forEach(k => bins[Math.min(15, Math.floor(k.m / 10))].n++);
    W = widthOf('focus-hist');
    el('focus-hist').innerHTML = barChart(bins.map(b => ({
        label: b.from === 150 ? '150+' : b.from,
        parts: [{ v: b.n, color: b.from >= 80 && b.from < 100 ? 'var(--accent)' : b.from >= 100 ? 'var(--highlight)' : 'var(--seq2)' }],
        tip: `<b>${b.from === 150 ? '150分以上' : `${b.from}〜${b.from + 9}分`}</b><br>${b.n}コマ（${n ? Math.round(b.n / n * 100) : 0}%）`,
    })), { step: Math.max(2, niceMax(Math.max(...bins.map(b => b.n)) / 5, 2)), every: 2, height: 220 });

    const pc = [0, 1, 2, 3].map(p => ks.filter(k => (p === 3 ? k.pauses >= 3 : k.pauses === p)).length);
    el('focus-pauses').innerHTML = hbars(pc.map((c, i) => ({ name: i === 3 ? '3回以上' : `${i}回`, v: c, val: `<b>${n ? Math.round(c / n * 100) : 0}%</b>・${c}コマ` })), Math.max(...pc));

    const weeks = [];
    for (let i = 11; i >= 0; i--) {
        const s = weekStartOf(today, i), e = new Date(s.getTime() + 7 * DAY);
        const w = KOMAS.filter(k => k.date >= s && k.date < e && timed(k));
        weeks.push({ label: md(s), v: w.length ? sum(w, k => k.m) / w.length : null });
    }
    W = widthOf('focus-trend');
    el('focus-trend').innerHTML = lineChart(weeks.map(w => w.label), [{ values: weeks.map(w => w.v), color: 'var(--p1)' }],
        { step: 20, height: 150, tips: weeks.map(w => `<b>${w.label} の週</b><br>平均 ${w.v == null ? '—' : Math.round(w.v) + '分'}`) });
}

// =========================================================
// 記録
// =========================================================
function renderRecords() {
    const keys = [...byDay.keys()].filter(k => dayKoma(k) > 0);
    if (!keys.length) { el('bests').innerHTML = '<p class="stats-empty">データがありません</p>'; return; }
    const bestDay = keys.reduce((b, k) => dayKoma(k) > dayKoma(b) ? k : b);
    const wk = {}, mo = {};
    keys.forEach(k => {
        const s = ymd(weekStartOf(parseDate(k)));
        wk[s] = (wk[s] ?? 0) + dayKoma(k); mo[k.slice(0, 7)] = (mo[k.slice(0, 7)] ?? 0) + dayKoma(k);
    });
    const bestWeek = Object.entries(wk).sort((a, b) => b[1] - a[1])[0], bestMonth = Object.entries(mo).sort((a, b) => b[1] - a[1])[0];
    let run = 0, best = 0, bestEnd = null;
    for (let t = parseDate(keys[0]).getTime(); t <= today.getTime(); t += DAY) {
        run = dayKoma(ymd(new Date(t))) >= 1 ? run + 1 : 0;
        if (run > best) { best = run; bestEnd = new Date(t); }
    }
    const fin = KOMAS.filter(timed);
    const longest = fin.length ? fin.reduce((b, k) => k.m > b.m ? k : b) : null;
    const started = KOMAS.filter(k => k.seg.length && !k.a && isDayStart(k));
    const early = started.length ? started.reduce((b, k) => k.seg[0][0] < b.seg[0][0] ? k : b) : null;
    const proj = {};
    KOMAS.forEach(k => { const key = `${k.d}|${k.p}`; proj[key] = (proj[key] ?? 0) + k.v; });
    const [pk, pv] = Object.entries(proj).sort((a, b) => b[1] - a[1])[0];
    const [pkDate, ...pkRest] = pk.split('|');
    const dl = s => { const d = parseDate(s); return `${d.getFullYear()}/${md(d)}`; };
    const card = (ico, label, value, when) => `<div class="best"><div class="best__ico" aria-hidden="true">${ico}</div><div class="best__label">${label}</div><div class="best__value">${value}</div><div class="best__when">${when}</div></div>`;
    el('bests').innerHTML =
        card('☀', 'いちばんコマが多い日', `${fmt1(dayKoma(bestDay))} コマ`, `<a href="?tab=day&amp;date=${bestDay}">${dl(bestDay)}</a>`)
        + card('📅', 'いちばんコマが多い週', `${fmt1(bestWeek[1])} コマ`, `${dl(bestWeek[0])} の週`)
        + card('🌙', 'いちばんコマが多い月', `${fmt1(bestMonth[1])} コマ`, `${+bestMonth[0].slice(0, 4)}年${+bestMonth[0].slice(5)}月`)
        + card('🔥', '最長の連続', `${best} 日`, best ? `${dl(ymd(new Date(bestEnd.getTime() - (best - 1) * DAY)))} 〜 ${md(bestEnd)}` : '—')
        + (longest ? card('⏱', 'いちばん長いコマ', `${Math.round(longest.m)} 分`, `${dl(longest.d)}・${esc(longest.n || '—')}`) : '')
        + (early ? card('🌅', 'いちばん早い始業', hm(early.seg[0][0]), dl(early.d)) : '')
        + card('🛠', '1日で同じプロジェクト', `${fmt1(pv)} コマ`, `${dl(pkDate)}・${esc(projName(pkRest.join('|')))}`)
        + card('🏅', 'フルデイの日', `${keys.filter(k => dayKoma(k) >= KOMA_GOAL_DAY).length} 日`, '全期間');

    const year = yearHtml(dayKoma, v => v <= 0 ? 0 : v < 2 ? 1 : v < 4 ? 2 : v < 6 ? 3 : v < 10 ? 4 : 5);
    el('year-grid').innerHTML = year.grid;
    el('year-months').innerHTML = year.months;
    el('year-sub').textContent = `直近53週・合計 ${fmt1(year.total)} コマ`;

    const ms = [];
    for (let i = 11; i >= 0; i--) {
        const s = new Date(today.getFullYear(), today.getMonth() - i, 1), key = `${s.getFullYear()}-${String(s.getMonth() + 1).padStart(2, '0')}`;
        const parts = groupParts(KOMAS.filter(k => k.d.startsWith(key)));
        ms.push({ label: `${s.getMonth() + 1}月`, parts, tip: `<b>${s.getFullYear()}年${s.getMonth() + 1}月 ${fmt1(sum(parts, p => p.v))}コマ</b>` + partsTip(parts) });
    }
    W = widthOf('monthly');
    el('monthly').innerHTML = barChart(ms, { step: 30, refs: [{ v: KOMA_GOAL_MONTH, label: 'マンスリー120' }] });
    el('monthly-legend').innerHTML = legendHtml();
}

// =========================================================
// 起動・ツールチップ
// =========================================================
const RENDER = { period: renderPeriod, project: ST.project !== null ? renderProjectDetail : renderProject, rhythm: renderRhythm, focus: renderFocus, records: renderRecords };

function initTooltip() {
    const tip = document.createElement('div');
    tip.className = 'tip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    document.addEventListener('mouseover', e => {
        const t = e.target.closest('[data-tip]');
        if (!t || !t.dataset.tip) { tip.classList.remove('is-show'); return; }
        tip.innerHTML = t.dataset.tip;  // 中身はこのファイルが組み立てた HTML（利用者の入力はエスケープ済み）
        tip.classList.add('is-show');
        const r = t.getBoundingClientRect(), b = tip.getBoundingClientRect();
        let y = r.top - b.height - 8;
        if (y < 8) y = r.bottom + 8;
        tip.style.left = `${Math.max(8, Math.min(r.left + r.width / 2 - b.width / 2, innerWidth - b.width - 8))}px`;
        tip.style.top = `${y}px`;
    });
    addEventListener('scroll', () => tip.classList.remove('is-show'), { passive: true });
}

document.addEventListener('DOMContentLoaded', () => {
    initTooltip();
    if (ST.tab === 'period') initPeriod();
    if (ST.tab === 'project' && ST.project === null) initProject();
    RENDER[ST.tab]?.();
    // 幅が変わったら描き直す（グラフの文字を縮ませないため）
    let timer = null;
    addEventListener('resize', () => { clearTimeout(timer); timer = setTimeout(() => RENDER[ST.tab]?.(), 200); });
});
