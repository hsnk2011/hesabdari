// analytics/sales-analytics.js — Sales Analytics page (renders every section from analytics/api.php)
(function () {
  'use strict';

  const API = 'api.php';
  const CUR = window.ANALYTICS_CURRENCY_LABEL || 'تومان';
  const FILTER_KEY = 'sa_filters_v2';
  const TABS = ['overview', 'time', 'products', 'customers', 'collections', 'profit', 'inventory', 'operations', 'guide'];
  const TAB_TITLES = { overview: 'نمای کلی', time: 'زمان و روند', products: 'محصولات', customers: 'مشتریان', collections: 'وصول و نقدینگی', profit: 'سودآوری', inventory: 'انبار', operations: 'عملیات و کیفیت داده', guide: 'راهنما' };

  const state = {
    start: '', end: '', compare: 'prev', gran: 'auto', preset: 'this_year', tab: 'overview',
    products: { collection: 0, ptype: '' },
    meta: null,
    data: {},          // section -> { sig, payload }
    inflight: {},      // section -> AbortController
    view: {},          // per-section UI choices (toggles)
  };
  const charts = {};   // section -> Chart[]
  const tables = {};   // table id -> { section, title, cols, rows, sort, dir, q, limit }

  /* ================================================================
   *  Formatting
   * ================================================================ */
  const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
  const fa = (s) => String(s ?? '').replace(/\d/g, (d) => FA_DIGITS[d]);
  const toEn = (s) => String(s ?? '').replace(/[۰-۹]/g, (d) => FA_DIGITS.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  const normText = (s) => toEn(s).replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[٫،]/g, (c) => (c === '٫' ? '.' : ' '))
    .replace(/[\u200c\u200f\u200e]/g, ' ').toLowerCase()
    .replace(/(\d)([^\d\s.x×*\/-])/g, '$1 $2').replace(/([^\d\s.x×*\/-])(\d)/g, '$1 $2');
  const reEsc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  /** A number token must match a whole number ("6" finds "۶ متری" but not "کد ۲۶" or "1.6"). */
  function tokenMatch(hay, tok) {
    if (/^\d+(\.\d+)?$/.test(tok)) return new RegExp('(^|[^\\d.])' + reEsc(tok) + '(?![\\d]|\\.\\d)').test(hay);
    return hay.includes(tok);
  }
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const nfCache = {};
  function fmtNum(v, d = 0) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
    const k = 'd' + d;
    if (!nfCache[k]) nfCache[k] = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: d, minimumFractionDigits: 0 });
    return nfCache[k].format(Number(v));
  }
  const fmtMoney = (v) => (v === null || v === undefined || isNaN(v)) ? '—' : fmtNum(Math.round(v));
  function fmtCompact(v) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    const a = Math.abs(v), sign = v < 0 ? '−' : '';
    if (a >= 1e9) return sign + fmtNum(a / 1e9, a >= 1e11 ? 0 : 2) + ' میلیارد';
    if (a >= 1e6) return sign + fmtNum(a / 1e6, a >= 1e8 ? 0 : 1) + ' میلیون';
    if (a >= 1e4) return sign + fmtNum(a / 1e3, 0) + ' هزار';
    return sign + fmtNum(a, 0);
  }
  const fmtPct = (v, d = 1) => (v === null || v === undefined || isNaN(v)) ? '—' : fmtNum(v, d) + '٪';
  function fmtBy(format, v) {
    switch (format) {
      case 'money': return fmtCompact(v);
      case 'pct': return fmtPct(v);
      case 'dec': return fmtNum(v, 1);
      case 'days': return v === null || v === undefined ? '—' : fmtNum(v) + ' روز';
      default: return fmtNum(v);
    }
  }
  const moneyTitle = (v) => v === null || v === undefined ? '' : fmtMoney(v) + ' ' + CUR;

  /* ================================================================
   *  Jalali calendar (same algorithm as analytics/lib/Jalali.php)
   * ================================================================ */
  const div = (a, b) => Math.trunc(a / b);
  function g2j(gy, gm, gd) {
    const gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 355666 + 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + gdm[gm - 1];
    let jy = -1595 + 33 * div(days, 12053); days %= 12053;
    jy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { jy += div(days - 1, 365); days = (days - 1) % 365; }
    const jm = days < 186 ? 1 + div(days, 31) : 7 + div(days - 186, 30);
    const jd = days < 186 ? 1 + (days % 31) : 1 + ((days - 186) % 30);
    return [jy, jm, jd];
  }
  function j2g(jy, jm, jd) {
    jy += 1595;
    let days = -355668 + 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    let gy = 400 * div(days, 146097); days %= 146097;
    if (days > 36524) { gy += 100 * div(--days, 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { gy += div(days - 1, 365); days = (days - 1) % 365; }
    let gd = days + 1;
    const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
    const md = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let gm = 0;
    for (gm = 0; gm < 13 && gd > md[gm]; gm++) gd -= md[gm];
    return [gy, gm, gd];
  }
  const pad = (n) => String(n).padStart(2, '0');
  const isoOf = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
  const isoToJ = (iso) => g2j(+iso.slice(0, 4), +iso.slice(5, 7), +iso.slice(8, 10));
  const jToIso = (jy, jm, jd) => isoOf(...j2g(jy, jm, jd));
  const jFmt = (iso) => { if (!iso) return ''; const [y, m, d] = isoToJ(iso); return fa(`${y}/${pad(m)}/${pad(d)}`); };
  function jMonthLen(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    const [y, m] = isoToJ(jToIso(jy, 12, 30));
    return (y === jy && m === 12) ? 30 : 29;
  }
  function todayIso() { const d = new Date(); return isoOf(d.getFullYear(), d.getMonth() + 1, d.getDate()); }
  function addDays(iso, n) { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return isoOf(d.getFullYear(), d.getMonth() + 1, d.getDate()); }
  function parseJalaliInput(text) {
    const t = toEn(text).trim().replace(/[.\-]/g, '/');
    const m = t.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!m) return null;
    const jy = +m[1], jm = +m[2], jd = +m[3];
    if (jm < 1 || jm > 12 || jd < 1 || jd > jMonthLen(jy, jm)) return null;
    return jToIso(jy, jm, jd);
  }

  function presetRange(key) {
    const t = todayIso();
    const [jy, jm] = isoToJ(t);
    const monthStart = (y, m) => jToIso(y, m, 1);
    const monthEnd = (y, m) => jToIso(y, m, jMonthLen(y, m));
    const shiftMonth = (y, m, k) => { let mm = m + k, yy = y; while (mm < 1) { mm += 12; yy--; } while (mm > 12) { mm -= 12; yy++; } return [yy, mm]; };
    const q = Math.floor((jm - 1) / 3);
    switch (key) {
      case '7d': return [addDays(t, -6), t];
      case '30d': return [addDays(t, -29), t];
      case 'this_month': return [monthStart(jy, jm), t];
      case 'last_month': { const [y, m] = shiftMonth(jy, jm, -1); return [monthStart(y, m), monthEnd(y, m)]; }
      case 'this_season': return [monthStart(jy, q * 3 + 1), t];
      case 'last_season': { const [y, m] = shiftMonth(jy, q * 3 + 1, -3); const [y2, m2] = shiftMonth(y, m, 2); return [monthStart(y, m), monthEnd(y2, m2)]; }
      case 'this_year': return [jToIso(jy, 1, 1), t];
      case 'last_year': return [jToIso(jy - 1, 1, 1), monthEnd(jy - 1, 12)];
      case '12m': { const [y, m] = shiftMonth(jy, jm, -11); return [monthStart(y, m), t]; }
      case 'all': {
        const first = state.meta && state.meta.first_date ? state.meta.first_date : jToIso(jy, 1, 1);
        const last = state.meta && state.meta.last_date && state.meta.last_date > t ? state.meta.last_date : t;
        return [first, last];
      }
    }
    return null;
  }

  /* ================================================================
   *  Theme & chart defaults
   * ================================================================ */
  const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  function pal() {
    return {
      s: ['--s1', '--s2', '--s3', '--s4', '--s5', '--s6', '--s7', '--s8'].map(cssVar),
      ink: cssVar('--ink'), ink2: cssVar('--ink-2'), muted: cssVar('--muted'), grid: cssVar('--grid'), axis: cssVar('--axis'),
      surface: cssVar('--surface'), surface2: cssVar('--surface-2'), good: cssVar('--good'), bad: cssVar('--bad'), warn: cssVar('--warn'),
      seq: ['--seq-1', '--seq-2', '--seq-3', '--seq-4', '--seq-5', '--seq-6'].map(cssVar),
    };
  }
  function alpha(color, a) {
    if (Array.isArray(color)) return color.map((x) => alpha(x, a));
    const c = String(color).trim();
    if (c.startsWith('#')) {
      const h = c.length === 4 ? c.slice(1).split('').map((x) => x + x).join('') : c.slice(1);
      const n = parseInt(h, 16);
      return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
    }
    return c;
  }
  function isDark() {
    const t = document.documentElement.getAttribute('data-theme');
    if (t) return t === 'dark';
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  if (window.Chart) {
    Chart.defaults.font.family = "Vazirmatn, system-ui, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.animation.duration = 450;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.responsive = true;
  }

  function destroyCharts(section) {
    (charts[section] || []).forEach((c) => { try { c.destroy(); } catch (e) {} });
    charts[section] = [];
  }
  function mount(section, id, config) {
    const el = document.getElementById(id);
    if (!el || !window.Chart) return null;
    const c = new Chart(el.getContext('2d'), config);
    (charts[section] = charts[section] || []).push(c);
    return c;
  }

  /**
   * Shared Chart.js options. Time runs right→left (RTL) and the value axis sits on the right.
   * fmt: money | pct | int ; titles: optional tooltip titles per index
   */
  function opts({ fmt = 'money', titles = null, stacked = false, horizontal = false, legend = false, max = null, min = null, unit = '' } = {}) {
    const p = pal();
    const valFmt = (v) => fmt === 'pct' ? fmtPct(v) : fmt === 'money' ? fmtMoney(v) + ' ' + CUR : fmtNum(v, 1) + (unit ? ' ' + unit : '');
    const axisFmt = (v) => fmt === 'pct' ? fmtNum(v) + '٪' : fmt === 'money' ? fmtCompact(v) : fmtNum(v, 1);
    const value = {
      stacked, beginAtZero: true, grid: { color: p.grid, drawTicks: false }, border: { display: false },
      ticks: { color: p.muted, padding: 6, maxTicksLimit: 6, callback: axisFmt },
    };
    if (max !== null) value.max = max;
    if (min !== null) value.min = min;
    const cat = {
      stacked, grid: { display: false }, border: { color: p.axis },
      ticks: { color: p.muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 12, callback: function (v) { const l = this.getLabelForValue(v); const s = fa(l); return s.length > 24 ? s.slice(0, 23) + '…' : s; } },
    };
    let scales;
    if (horizontal) { value.reverse = true; value.position = 'bottom'; cat.position = 'right'; scales = { x: value, y: cat }; }
    else { cat.reverse = true; value.position = 'right'; scales = { x: cat, y: value }; }
    return {
      indexAxis: horizontal ? 'y' : 'x',
      interaction: { mode: 'index', intersect: false, axis: horizontal ? 'y' : 'x' },
      layout: { padding: { top: 4, left: 2, right: 2 } },
      scales,
      plugins: {
        legend: { display: legend, position: 'top', align: 'start', rtl: true, labels: { color: p.ink2, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 8, boxHeight: 8, padding: 14, filter: (it) => !String(it.text).startsWith('_') } },
        tooltip: {
          rtl: true, textDirection: 'rtl', backgroundColor: p.ink, titleColor: p.surface, bodyColor: p.surface, footerColor: p.surface,
          padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true, titleFont: { weight: '700' },
          callbacks: {
            title: (items) => items.length ? (titles ? fa(titles[items[0].dataIndex] ?? items[0].label) : fa(items[0].label)) : '',
            label: (ctx) => {
              const v = horizontal ? ctx.parsed.x : ctx.parsed.y;
              if (v === null || v === undefined) return null;
              return ` ${String(ctx.dataset.label).replace(/^_/, '')}: ${fa(valFmt(v))}`;
            },
          },
        },
      },
    };
  }
  function lineDs(label, data, color, extra = {}) {
    return Object.assign({ type: 'line', label, data, borderColor: color, backgroundColor: alpha(color, 0.08), borderWidth: 2, pointRadius: 0,
      pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: pal().surface, pointBackgroundColor: color, tension: 0.3, cubicInterpolationMode: 'monotone', spanGaps: true, fill: false }, extra);
  }
  function barDs(label, data, color, extra = {}) {
    return Object.assign({ type: 'bar', label, data, backgroundColor: color, hoverBackgroundColor: alpha(color, 0.85), borderRadius: 4, maxBarThickness: 34,
      categoryPercentage: 0.72, barPercentage: 0.9, borderColor: pal().surface, borderWidth: { top: 0, bottom: 0, left: 0, right: 0 } }, extra);
  }
  const faLabels = (arr) => (arr || []).map((x) => fa(x));

  /* ================================================================
   *  UI building blocks
   * ================================================================ */
  let uid = 0;
  const nextId = (p) => `${p}-${++uid}`;

  function helpBtn() { return '<button type="button" class="sa-help-btn" data-help title="این چیست؟" aria-label="توضیح"><i class="bi bi-question-circle"></i></button>'; }

  /** Card wrapper: title, optional subtitle/help/tools, body HTML. */
  function card({ title, icon = '', sub = '', help = '', body = '', span = 12, tools = '', id = '' }) {
    return `<div class="sa-card span-${span}" ${id ? `id="${id}"` : ''}>
      <div class="sa-card-head">
        <div><h3 class="sa-card-title">${icon ? `<i class="bi bi-${icon}"></i>` : ''}${title}</h3>${sub ? `<div class="sa-card-sub">${sub}</div>` : ''}</div>
        <div class="sa-card-tools">${tools}${help ? helpBtn() : ''}</div>
      </div>
      ${help ? `<div class="sa-help">${help}</div>` : ''}
      ${body}
    </div>`;
  }
  function chartBox(id, size = '') { return `<div class="sa-chart ${size}"><canvas id="${id}"></canvas></div>`; }
  function empty(text = 'داده‌ای برای این بازه وجود ندارد.') { return `<div class="sa-empty"><i class="bi bi-inbox"></i>${text}</div>`; }
  function legend(items) {
    return `<div class="sa-legend">${items.map((it) => `<span><i class="${it.kind || ''}" style="${it.kind === 'dash' ? 'border-color' : 'background'}:${it.color}"></i>${it.label}</span>`).join('')}</div>`;
  }
  function stat(label, value, hint = '', tone = '', title = '') {
    return `<div class="sa-stat ${tone ? 'tone-' + tone : ''}" ${title ? `title="${esc(title)}"` : ''}><div class="l">${label}</div><div class="v">${value}</div>${hint ? `<div class="h">${hint}</div>` : ''}</div>`;
  }
  function badge(text, tone = '') { return `<span class="sa-badge ${tone}">${text}</span>`; }
  function shareBar(pct, color) {
    const w = Math.max(0, Math.min(100, pct || 0));
    return `<span class="sa-share"><span class="sa-bar"><i style="width:${w}%;${color ? 'background:' + color : ''}"></i></span>${fmtPct(pct)}</span>`;
  }
  function deltaChip(delta, upGood = true, isPoints = false) {
    if (delta === null || delta === undefined) return '<span class="sa-delta neutral">—</span>';
    const up = delta > 0, flat = Math.abs(delta) < 0.05;
    const cls = flat ? 'neutral' : ((up === upGood) ? 'up-good' : 'down-bad');
    const icon = flat ? 'dash' : (up ? 'arrow-up-short' : 'arrow-down-short');
    const txt = isPoints ? fmtNum(Math.abs(delta), 1) + ' واحد' : fmtPct(Math.abs(delta));
    return `<span class="sa-delta ${cls}" title="${isPoints ? 'تغییر به واحد درصد' : 'درصد تغییر'}"><i class="bi bi-${icon}"></i>${txt}</span>`;
  }
  function sparkline(values) {
    const v = (values || []).map((x) => (x === null || x === undefined ? null : Number(x)));
    const nums = v.filter((x) => x !== null);
    if (nums.length < 2) return '<svg class="sa-spark"></svg>';
    const w = 84, h = 26, min = Math.min(...nums), max = Math.max(...nums), span = max - min || 1;
    const n = v.length;
    // RTL: oldest point on the right, newest on the left.
    const pts = v.map((x, i) => x === null ? null : [w - (i * (w - 4)) / (n - 1) - 2, h - 3 - ((x - min) / span) * (h - 6)]).filter(Boolean);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    const last = pts[pts.length - 1];
    return `<svg class="sa-spark" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}" fill="none" stroke="var(--s1)" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/><circle cx="${last[0]}" cy="${last[1]}" r="2.6" fill="var(--s1)"/></svg>`;
  }
  function segToggle(name, options, active) {
    return `<div class="sa-seg-toggle" data-toggle="${name}">${options.map(([v, l]) => `<button type="button" data-value="${v}" class="${v === active ? 'active' : ''}">${l}</button>`).join('')}</div>`;
  }
  const viewOf = (section, name, def) => (state.view[section] && state.view[section][name]) || def;

  /* ---------------- tables ---------------- */
  /**
   * cols: [{ key, label, type: text|money|int|pct|dec|days|share|badge|html, num?, html?(row), csv?(row), title?(row) }]
   */
  function table(section, title, cols, rows, { sort = null, dir = 'desc', limit = 25, search = true, exportable = true, id = null, totals = true } = {}) {
    const tid = id || nextId('t');
    tables[tid] = { section, title, cols, rows: rows || [], sort, dir, q: '', limit, baseLimit: limit, totals };
    if (!rows || !rows.length) return empty();
    return `<div class="sa-table-box" data-table="${tid}">
      <div class="sa-table-tools">
        ${search ? `<input type="search" placeholder="جستجو در جدول…" data-tsearch="${tid}">` : ''}
        <span class="count" data-tcount="${tid}"></span>
        ${exportable ? `<button type="button" class="sa-tool" data-texport="${tid}" title="خروجی CSV این جدول"><i class="bi bi-download"></i></button>` : ''}
      </div>
      <div class="sa-table-wrap"><table class="sa-table"><thead><tr>${cols.map((c) => `<th class="${isNumCol(c) ? 'num' : ''} ${sort === c.key ? 'sorted' : ''}" data-tsort="${tid}" data-key="${c.key}">${c.label}<i class="bi bi-arrow-down-up si"></i></th>`).join('')}</tr></thead>
      <tbody data-tbody="${tid}">${tableBody(tid)}</tbody><tfoot data-tfoot="${tid}">${tableFoot(tid)}</tfoot></table></div>
      <button type="button" class="sa-more" data-tmore="${tid}" hidden>نمایش بیشتر</button>
    </div>`;
  }
  const isNumCol = (c) => c.num !== undefined ? c.num : ['money', 'int', 'pct', 'dec', 'days', 'share', 'moneyfull'].includes(c.type);
  function cellHtml(c, r) {
    if (c.html) return c.html(r);
    const v = r[c.key];
    switch (c.type) {
      case 'money': return v === null || v === undefined ? '—' : `<span title="${esc(moneyTitle(v))}">${fmtCompact(v)}</span>`;
      case 'moneyfull': return fmtMoney(v);
      case 'int': return fmtNum(v);
      case 'dec': return fmtNum(v, 1);
      case 'pct': return fmtPct(v);
      case 'days': return v === null || v === undefined ? '—' : fmtNum(v);
      case 'share': return shareBar(v);
      default: return esc(fa(v ?? '—'));
    }
  }
  function filteredRows(t) {
    let rows = t.rows;
    if (t.q) {
      // Every word must appear somewhere in the row; Persian/English digits and Arabic ی/ک are treated alike.
      const tokens = normText(t.q).split(/\s+/).filter(Boolean);
      rows = rows.filter((r) => {
        // Only text columns: otherwise "27" would also match amounts like 1,270,000.
        // Bare numbers also skip date columns, so "کد 27" does not match a sale on the 27th.
        const textOf = (cols) => normText(cols.map((c) => (c.csv ? c.csv(r) : (r[c.key] ?? ''))).join(' '));
        const textCols = t.cols.filter((c) => !isNumCol(c));
        const hay = textOf(textCols);
        const hayNoDates = textOf(textCols.filter((c) => !DATE_KEYS.has(c.key)));
        return tokens.every((tok) => tokenMatch(/^\d+(\.\d+)?$/.test(tok) ? hayNoDates : hay, tok));
      });
    }
    if (t.sort) {
      const col = t.cols.find((c) => c.key === t.sort);
      const sk = col && col.sortVal ? col.sortVal : (r) => r[t.sort];
      const numeric = col && isNumCol(col);
      rows = rows.slice().sort((a, b) => {
        let x = sk(a), y = sk(b);
        if (x === null || x === undefined) return 1;
        if (y === null || y === undefined) return -1;
        const cmp = (numeric || (typeof x === 'number' && typeof y === 'number')) ? (Number(x) - Number(y)) : String(x).localeCompare(String(y), 'fa');
        return t.dir === 'asc' ? cmp : -cmp;
      });
    }
    return rows;
  }
  function tableBody(tid) {
    const t = tables[tid];
    const rows = filteredRows(t);
    const shown = rows.slice(0, t.limit);
    setTimeout(() => {
      const cnt = document.querySelector(`[data-tcount="${tid}"]`);
      if (cnt) cnt.textContent = fa(`${shown.length} از ${rows.length} ردیف`);
      const more = document.querySelector(`[data-tmore="${tid}"]`);
      if (more) more.hidden = rows.length <= t.limit;
    }, 0);
    if (!rows.length) return `<tr><td colspan="${t.cols.length}" class="muted" style="text-align:center">موردی پیدا نشد</td></tr>`;
    return shown.map((r) => `<tr>${t.cols.map((c) => `<td class="${isNumCol(c) ? 'num' : ''} ${c.wrap ? 'wrap' : ''}" ${c.title ? `title="${esc(c.title(r))}"` : ''}>${cellHtml(c, r)}</td>`).join('')}</tr>`).join('');
  }
  const DATE_KEYS = new Set(['date', 'first', 'last', 'last_sale']);
  // Columns whose plain sum is meaningless (averages, ratios, ages, ids).
  const NO_SUM = new Set(['avg_price', 'aov', 'price_m2', 'avg_interval', 'days_since', 'recency', 'ratio', 'overdue_ratio', 'months_of_stock',
    'avg_age', 'oldest', 'age_days', 'expected', 'lift', 'per_invoice', 'threshold', 'days', 'list_price_m2', 'id']);
  const sumOf = (rows, key) => rows.reduce((a, r) => a + (Number(r[key]) || 0), 0);
  function footCell(c, rows) {
    if (c.foot) return c.foot(rows);
    const has = (k) => rows.length && Object.prototype.hasOwnProperty.call(rows[0], k);
    if (c.key === 'margin' && has('profit') && has('net')) {
      const ok = rows.filter((r) => r.margin !== null && r.margin !== undefined);
      const n = sumOf(ok, 'net');
      return n ? fmtPct(sumOf(ok, 'profit') / n * 100) : '—';
    }
    if (c.key === 'price_m2' && has('area') && has('net')) {
      const ok = rows.filter((r) => r.area > 0);
      const a = sumOf(ok, 'area');
      return a ? `<span title="${esc(moneyTitle(sumOf(ok, 'net') / a))}">${fmtCompact(sumOf(ok, 'net') / a)}</span>` : '—';
    }
    if (c.type === 'share') return fmtPct(sumOf(rows, c.key));
    if (c.nosum || NO_SUM.has(c.key) || !['money', 'moneyfull', 'int', 'dec'].includes(c.type)) return '';
    const v = sumOf(rows, c.key);
    if (c.type === 'money') return `<span title="${esc(moneyTitle(v))}">${fmtCompact(v)}</span>`;
    if (c.type === 'moneyfull') return fmtMoney(v);
    return c.type === 'dec' ? fmtNum(v, 1) : fmtNum(v);
  }
  /** Totals of all rows matching the current search (not just the visible page). */
  function tableFoot(tid) {
    const t = tables[tid];
    if (!t || t.totals === false || t.rows.length < 2) return '';
    const rows = filteredRows(t);
    if (!rows.length) return '';
    const cells = t.cols.map((c, i) => (i === 0 ? '' : footCell(c, rows)));
    if (!cells.some((x) => x)) return '';
    cells[0] = `جمع ${fmtNum(rows.length)} ردیف${t.q ? ' (فیلترشده)' : ''}`;
    return `<tr>${t.cols.map((c, i) => `<td class="${i && isNumCol(c) ? 'num' : ''}">${cells[i]}</td>`).join('')}</tr>`;
  }
  function refreshTable(tid) {
    const body = document.querySelector(`[data-tbody="${tid}"]`);
    if (body) body.innerHTML = tableBody(tid);
    const foot = document.querySelector(`[data-tfoot="${tid}"]`);
    if (foot) foot.innerHTML = tableFoot(tid);
    const t = tables[tid];
    document.querySelectorAll(`[data-tsort="${tid}"]`).forEach((th) => {
      const on = th.dataset.key === t.sort;
      th.classList.toggle('sorted', on);
      th.querySelector('.si').className = 'bi si ' + (on ? (t.dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up');
    });
  }

  /* ---------------- CSV export ---------------- */
  function csvCell(v) {
    const s = String(v ?? '');
    return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }
  function tableCsvRows(t) {
    const out = [[t.title], t.cols.map((c) => c.label.replace(/<[^>]+>/g, ''))];
    filteredRows(t).forEach((r) => out.push(t.cols.map((c) => {
      if (c.csv) return c.csv(r);
      const v = r[c.key];
      if (v === null || v === undefined) return '';
      if (typeof v === 'boolean') return v ? 'بله' : 'خیر';
      return v;
    })));
    return out;
  }
  function downloadCsv(name, rows) {
    const text = '﻿' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n');
    const blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  function exportSection(section) {
    const ids = Object.keys(tables).filter((k) => tables[k].section === section && tables[k].rows.length && document.querySelector(`[data-table="${k}"]`));
    if (!ids.length) { toastr.info('در این بخش جدولی برای خروجی وجود ندارد.'); return; }
    const rows = [['آنالیز فروش — ' + TAB_TITLES[section]], ['بازه', jFmt(state.start) + ' تا ' + jFmt(state.end)], ['واحد پول', CUR], []];
    ids.forEach((k) => { tableCsvRows(tables[k]).forEach((r) => rows.push(r)); rows.push([]); });
    downloadCsv(`sales-analytics-${section}-${toEn(jFmt(state.end)).replace(/\//g, '-')}.csv`, rows);
  }

  /* ================================================================
   *  Data loading
   * ================================================================ */
  function signature(section) {
    const s = [state.start, state.end, state.compare, state.gran];
    if (section === 'products') s.push(state.products.collection, state.products.ptype);
    return s.join('|');
  }
  async function fetchJSON(params, signal) {
    const res = await fetch(API + '?' + new URLSearchParams(params), { credentials: 'same-origin', signal });
    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON */ }
    if (res.status === 401) { window.location.href = '../index.php'; throw new Error('نشست منقضی شده است.'); }
    if (!res.ok || !data || data.error) throw new Error((data && data.error) || `خطا در دریافت اطلاعات (${res.status})`);
    return data;
  }
  async function loadSection(section, force = false) {
    if (section === 'guide') { renderGuide(); return; }
    const el = sectionEl(section);
    const sig = signature(section);
    const cached = state.data[section];
    if (!force && cached && cached.sig === sig) { render(section, cached.payload); return; }
    if (state.inflight[section]) state.inflight[section].abort();
    const ctl = new AbortController();
    state.inflight[section] = ctl;
    destroyCharts(section);
    el.innerHTML = skeleton();
    const params = { action: 'section', section, startDate: state.start, endDate: state.end, compare: state.compare, granularity: state.gran };
    if (section === 'products') { if (state.products.collection) params.collection = state.products.collection; if (state.products.ptype) params.ptype = state.products.ptype; }
    if (force) params.refresh = '1';
    try {
      const payload = await fetchJSON(params, ctl.signal);
      state.data[section] = { sig, payload };
      if (state.tab === section) render(section, payload);
    } catch (e) {
      if (e.name === 'AbortError') return;
      el.innerHTML = `<div class="sa-error"><i class="bi bi-exclamation-triangle"></i> ${esc(e.message)}<br><button class="btn btn-sm btn-outline-danger" data-retry="${section}">تلاش دوباره</button></div>`;
    } finally {
      if (state.inflight[section] === ctl) delete state.inflight[section];
    }
  }
  function skeleton() {
    return `<div class="sa-loading">${'<div class="sa-skel"></div>'.repeat(4)}<div class="sa-skel tall"></div></div>`;
  }
  const sectionEl = (s) => document.querySelector(`.sa-section[data-section="${s}"]`);

  function render(section, d) {
    destroyCharts(section);
    Object.keys(tables).forEach((k) => { if (tables[k].section === section) delete tables[k]; });
    const el = sectionEl(section);
    try {
      const r = RENDER[section](d);
      el.innerHTML = r.html;
      if (r.after) r.after();
    } catch (e) {
      console.error(e);
      el.innerHTML = `<div class="sa-error">نمایش این بخش با خطا مواجه شد: ${esc(e.message)}</div>`;
    }
    const gen = d.generated_at ? ' · محاسبه: ' + fa(d.generated_at.slice(11, 16)) : '';
    document.getElementById('sa-footer').textContent = 'مبالغ به ' + CUR + ' · فاکتورهای امانی در فروش لحاظ نشده‌اند' + gen;
  }

  /* ================================================================
   *  Section renderers
   * ================================================================ */
  const HELP = {
    net_sales: 'جمع مبلغ فاکتورهای فروش پس از کسر تخفیف و مرجوعی‌های همین بازه (بدون مالیات). فاکتورهای امانی حساب نمی‌شوند.',
    gross_profit: 'فروش خالص منهای بهای تمام‌شده کالای فروخته‌شده (قیمت خرید دقیق همان کالاهای فروخته‌شده). سود قبل از هزینه‌های جاری.',
    gross_margin: 'از هر ۱۰۰ تومان فروش، چند تومان سود ناخالص می‌ماند. تغییرش به «واحد درصد» نمایش داده می‌شود.',
    invoices: 'تعداد فاکتورهای فروش (غیر امانی) صادرشده در بازه.',
    aov: 'میانگین مبلغ هر فاکتور (پس از تخفیف). بالا رفتنش یعنی مشتری‌ها در هر خرید بیشتر می‌خرند.',
    customers: 'تعداد مشتریان متفاوتی که در این بازه حداقل یک فاکتور داشته‌اند.',
    new_customers: 'مشتریانی که اولین خرید عمرشان در همین بازه بوده است.',
    units: 'تعداد اقلام (فرش/کالا) فروخته‌شده؛ ردیف‌های خدماتی حساب نمی‌شوند.',
    area: 'جمع متراژ فرش‌های فروخته‌شده بر اساس ابعاد ثبت‌شده در فاکتور.',
    price_per_m2: 'فروش خالص تقسیم بر متراژ فروخته‌شده؛ نشان می‌دهد هر متر فرش به‌طور میانگین چند فروخته شده است.',
    receipts: 'جمع دریافتی‌های نقدی و چکی از مشتریان در این بازه (بابت هر فاکتوری، حتی فاکتورهای قدیمی).',
    collection_ratio: 'دریافتی خالص از مشتریان تقسیم بر فروش خالص همین بازه. زیر ۱۰۰٪ یعنی بخشی از فروش نسیه مانده است.',
    discount_rate: 'جمع تخفیف‌ها تقسیم بر فروش قبل از تخفیف. کمتر بهتر است.',
    return_rate: 'مبلغ مرجوعی‌ها تقسیم بر فروش. کمتر بهتر است.',
    operating_profit: 'سود ناخالص منهای هزینه‌های ثبت‌شده به‌علاوه سایر درآمدها. تقریبی است و جایگزین صورت سود و زیان رسمی نیست.',
    daily_avg: 'فروش خالص تقسیم بر تعداد روزهای بازه (شامل روزهای بدون فروش).',
  };

  /* ---------------- overview ---------------- */
  function kpiTile(k, cmpLabel) {
    const isPts = k.format === 'pct';
    const val = k.format === 'money' ? fmtCompact(k.value) : k.format === 'pct' ? fmtPct(k.value) : k.format === 'dec' ? fmtNum(k.value, 1) : fmtNum(k.value);
    const cmp = k.compare === null || k.compare === undefined ? '' : `${cmpLabel}: ${k.format === 'money' ? fmtCompact(k.compare) : k.format === 'pct' ? fmtPct(k.compare) : fmtNum(k.compare, k.format === 'dec' ? 1 : 0)}`;
    return `<div class="sa-kpi">
      <div class="sa-kpi-label"><span>${k.label}</span>${HELP[k.key] ? helpBtn() : ''}</div>
      <div class="sa-kpi-value" title="${esc(k.format === 'money' ? moneyTitle(k.value) : '')}">${val}${k.format === 'money' && k.value !== null ? `<small>${CUR}</small>` : ''}</div>
      <div class="sa-kpi-foot">
        <div>${state.compare !== 'none' ? deltaChip(k.delta, k.up_is_good, isPts) : ''}<div class="sa-kpi-cmp" title="${esc(cmp)}">${cmp}</div></div>
        ${k.spark ? sparkline(k.spark) : ''}
      </div>
      ${HELP[k.key] ? `<div class="sa-help">${HELP[k.key]}</div>` : ''}
    </div>`;
  }

  function periodCaption(p) {
    const cap = document.getElementById('sa-period-caption');
    if (!p || !cap) return;
    let t = `بازه: <b>${fa(p.label)}</b> (${fmtNum(p.days)} روز) · نمایش ${p.gran_label}`;
    if (p.compare !== 'none' && p.compare_range) t += ` · مقایسه با <b>${fa(p.compare_range)}</b>`;
    cap.innerHTML = t;
  }

  const RENDER = {};

  RENDER.overview = (d) => {
    periodCaption(d.period);
    const s = d.summary, tr = d.trend, f = d.forecast, h = d.health;
    const cmpLabel = state.compare === 'yoy' ? 'سال قبل' : 'دوره قبل';
    const metric = viewOf('overview', 'trend', 'net');
    const hasData = s.invoices > 0;

    const healthHtml = h.score === null ? empty('داده کافی برای امتیازدهی نیست.') : `<div class="sa-health">
        <div class="sa-gauge">${gaugeSvg(h.score)}<div class="num">${fmtNum(h.score)}</div><div class="grade">${h.grade} از ۱۰۰</div></div>
        <div class="sa-health-parts">${h.parts.map((x) => `<div class="sa-hp" title="${esc(x.hint)}"><div class="sa-hp-top"><span>${x.label} <span class="muted">(${fmtPct(x.value)})</span></span><span>${fmtNum(x.score)}</span></div>
          <div class="sa-bar"><i style="width:${x.score}%;background:${x.score >= 70 ? 'var(--good)' : x.score >= 45 ? 'var(--warn)' : 'var(--bad)'}"></i></div></div>`).join('')}</div>
      </div>`;

    const cm = f.current_month;
    const html = `
      <div class="sa-kpis">${d.kpis.map((k) => kpiTile(k, cmpLabel)).join('')}</div>

      <div class="sa-grid" style="margin-top:12px">
        ${card({ title: 'روند فروش', icon: 'activity', span: 8,
          sub: `${d.period.gran_label} · خط خاکستری: ${state.compare === 'none' ? '—' : d.period.compare_label} · خط نارنجی: میانگین متحرک ${fmtNum(tr.moving_window)} دوره‌ای`,
          help: 'روند فروش خالص (یا سود/تعداد فاکتور) در طول بازه. خط خاکستری همان مقدار در دوره مقایسه است که دوره‌به‌دوره کنار هم چیده شده. میانگین متحرک نوسان‌های تصادفی را صاف می‌کند تا جهت واقعی روند دیده شود. زمان از راست به چپ جلو می‌رود.',
          tools: segToggle('overview.trend', [['net', 'فروش'], ['profit', 'سود'], ['invoices', 'فاکتور']], metric),
          body: hasData ? chartBox('ov-trend', 'h-lg') : empty() })}
        ${card({ title: 'سلامت فروش', icon: 'heart-pulse', span: 4,
          help: 'امتیازی ساده از ۰ تا ۱۰۰ که از چند شاخص کلیدی ساخته شده: رشد، حاشیه سود، وصول، مرجوعی، پراکندگی مشتریان و تخفیف. هر ردیف را جداگانه ببینید؛ عدد کلی فقط برای یک نگاه سریع است.',
          body: healthHtml })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'نکته‌های کلیدی این دوره', icon: 'lightbulb', span: 7,
          help: 'این جمله‌ها به‌طور خودکار از کنار هم گذاشتن داده‌های همه بخش‌ها ساخته می‌شوند تا مهم‌ترین اتفاق‌ها (خوب و بد) را بدون جست‌وجو ببینید.',
          body: d.insights.length ? `<div class="sa-insights">${d.insights.map((i) => `<div class="sa-insight ${i.level}"><i class="bi bi-${i.icon}"></i><div>${esc(fa(i.text))}</div></div>`).join('')}</div>` : empty('نکته خاصی پیدا نشد.') })}
        ${card({ title: 'از فروش تا سود', icon: 'bar-chart-steps', span: 5,
          help: 'آبشار سود: از فروش ناخالص شروع می‌کند و قدم‌به‌قدم تخفیف، مرجوعی، بهای تمام‌شده و هزینه‌ها را کم می‌کند تا ببینید هر ریال فروش کجا می‌رود.',
          body: hasData ? chartBox('ov-waterfall', 'h-lg') : empty() })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'پیش‌بینی فروش', icon: 'binoculars', span: 7,
          sub: 'بر اساس روند خطی ۱۲ ماه کامل اخیر (تقویم شمسی)',
          help: 'ستون‌ها فروش واقعی ماه‌های گذشته‌اند و نقطه‌چین‌ها پیش‌بینی ۳ ماه آینده با روند خطی (به‌همراه بازه احتمالی بالا/پایین). این یک برآورد ساده آماری است، نه قول قطعی؛ تخفیف‌های فصلی و اتفاقات خاص را نمی‌بیند.',
          body: f.history.length >= 3 ? chartBox('ov-forecast') + `<div class="sa-note">روند ماهانه: ${f.slope >= 0 ? 'افزایشی' : 'کاهشی'} (${fmtCompact(Math.abs(f.slope))} ${f.slope >= 0 ? 'افزایش' : 'کاهش'} در هر ماه) · میانگین ماهانه: ${fmtCompact(f.avg_month)}</div>` : empty('برای پیش‌بینی حداقل ۳ ماه سابقه لازم است.') })}
        ${card({ title: `سرعت فروش ${fa(cm.label)}`, icon: 'speedometer2', span: 5,
          help: 'فروش ماه جاری تا امروز تقسیم بر روزهای گذشته، ضرب در طول ماه. اگر همین سرعت ادامه پیدا کند، فروش آخر ماه چقدر می‌شود.',
          body: `<div class="sa-stats">
              ${stat('فروش تا امروز', fmtCompact(cm.mtd), `${fmtNum(cm.day)} روز از ${fmtNum(cm.length)} روز`, '', moneyTitle(cm.mtd))}
              ${stat('برآورد پایان ماه', fmtCompact(cm.projected), 'با سرعت فعلی', '', moneyTitle(cm.projected))}
              ${stat('نسبت به میانگین ماهانه', cm.vs_avg === null ? '—' : (cm.vs_avg >= 0 ? '+' : '−') + fmtPct(Math.abs(cm.vs_avg)), 'میانگین ۱۲ ماه اخیر', cm.vs_avg === null ? '' : (cm.vs_avg >= 0 ? 'good' : 'bad'))}
              ${stat('هدف روزانه برای رسیدن به میانگین', cm.length > cm.day && f.avg_month > cm.mtd ? fmtCompact((f.avg_month - cm.mtd) / (cm.length - cm.day)) : '—', 'در روزهای باقیمانده')}
            </div>
            <div class="sa-bar" style="height:10px;margin-top:14px" title="پیشرفت ماه"><i style="width:${Math.min(100, cm.day / cm.length * 100)}%"></i></div>
            <div class="sa-note">${fmtPct(cm.day / cm.length * 100, 0)} از ماه گذشته است.</div>` })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'پرفروش‌ترین محصولات', icon: 'trophy', span: 6, body: table('overview', 'پرفروش‌ترین محصولات', [
          { key: 'name', label: 'محصول', type: 'text', wrap: true },
          { key: 'qty', label: 'تعداد', type: 'int' },
          { key: 'net', label: 'فروش', type: 'money' },
          { key: 'margin', label: 'حاشیه', type: 'pct' },
          { key: 'share', label: 'سهم', type: 'share' },
        ], d.top_products, { search: false, exportable: true }) })}
        ${card({ title: 'برترین مشتریان', icon: 'star', span: 6, body: table('overview', 'برترین مشتریان', [
          { key: 'name', label: 'مشتری', type: 'text', wrap: true },
          { key: 'invoices', label: 'فاکتور', type: 'int' },
          { key: 'net', label: 'خرید', type: 'money' },
          { key: 'balance', label: 'مانده بدهی', type: 'money' },
          { key: 'share', label: 'سهم', type: 'share' },
        ], d.top_customers, { search: false }) })}
      </div>`;

    return {
      html,
      after() {
        const p = pal();
        if (hasData) {
          const series = { net: ['فروش خالص', tr.net, tr.cmp_net, 'money'], profit: ['سود ناخالص', tr.profit, tr.cmp_profit, 'money'], invoices: ['تعداد فاکتور', tr.invoices, tr.cmp_invoices, 'int'] }[metric];
          const ds = [lineDs(series[0], series[1], p.s[0], { fill: 'origin', backgroundColor: alpha(p.s[0], 0.10), pointRadius: tr.labels.length <= 20 ? 3 : 0 })];
          if (state.compare !== 'none') ds.push(lineDs(d.period.compare_label, series[2], p.muted, { borderWidth: 1.5 }));
          if (metric === 'net') ds.push(lineDs('میانگین متحرک', tr.moving_avg, p.s[1], { borderWidth: 1.5 }));
          mount('overview', 'ov-trend', { type: 'line', data: { labels: faLabels(tr.labels), datasets: ds }, options: Object.assign(opts({ fmt: series[3], titles: tr.titles, legend: true })) });

          // Waterfall as floating bars
          let run = 0;
          const wf = d.waterfall.map((w) => {
            if (w.type === 'total') { run = w.value; return [0, w.value]; }
            const from = run; run += w.value; return [from, run];
          });
          const colors = d.waterfall.map((w) => w.type === 'total' ? p.s[0] : w.type === 'plus' ? p.s[2] : p.s[1]);
          const o = opts({ fmt: 'money' });
          o.plugins.tooltip.callbacks.label = (ctx) => ' ' + fa(fmtMoney(d.waterfall[ctx.dataIndex].value)) + ' ' + CUR;
          o.scales.y.beginAtZero = false;
          mount('overview', 'ov-waterfall', { type: 'bar', data: { labels: d.waterfall.map((w) => w.label), datasets: [barDs('مبلغ', wf, colors, { borderSkipped: false, maxBarThickness: 40 })] }, options: o });
        }
        if (f.history.length >= 3) {
          const labels = f.history_labels.concat(f.prediction.map((x) => x.label));
          const n = f.history.length;
          const hist = f.history.concat(f.prediction.map(() => null));
          const pred = f.history.map((v, i) => (i === n - 1 ? v : null)).concat(f.prediction.map((x) => x.value));
          const hi = f.history.map(() => null).concat(f.prediction.map((x) => x.high));
          const lo = f.history.map(() => null).concat(f.prediction.map((x) => x.low));
          mount('overview', 'ov-forecast', { data: { labels: faLabels(labels), datasets: [
            barDs('فروش واقعی', hist, p.s[0]),
            lineDs('پیش‌بینی', pred, p.s[1], { borderDash: [5, 4], pointRadius: 3 }),
            lineDs('_حد بالا', hi, alpha(p.s[1], 0.35), { borderWidth: 1, fill: '+1', backgroundColor: alpha(p.s[1], 0.08) }),
            lineDs('_حد پایین', lo, alpha(p.s[1], 0.35), { borderWidth: 1 }),
          ] }, options: opts({ fmt: 'money', legend: true }) });
        }
      },
    };
  };

  function gaugeSvg(score) {
    const r = 52, c = Math.PI * r;
    const pct = Math.max(0, Math.min(100, score)) / 100;
    const color = score >= 70 ? 'var(--good)' : score >= 45 ? 'var(--warn)' : 'var(--bad)';
    return `<svg viewBox="0 0 120 66" width="132" height="72" aria-hidden="true">
      <path d="M8 60 A52 52 0 0 1 112 60" fill="none" stroke="var(--surface-2)" stroke-width="10" stroke-linecap="round"/>
      <path d="M8 60 A52 52 0 0 1 112 60" fill="none" stroke="${color}" stroke-width="10" stroke-linecap="round" stroke-dasharray="${(c * pct).toFixed(1)} ${c.toFixed(1)}" transform="translate(120,0) scale(-1,1)"/>
    </svg>`;
  }

  /* ---------------- time patterns ---------------- */
  RENDER.time = (d) => {
    periodCaption(d.period);
    const r = d.records, tr = d.trend;
    const wdMode = viewOf('time', 'wd', 'avg');
    const hasData = tr.net.some((x) => x);
    const levels = (v, max) => (v === null ? 'empty' : v <= 0 || !max ? '' : 'l' + Math.max(1, Math.ceil(v / max * 6)));
    const heat = d.heatmap.cells.map((c) => `<i class="${levels(c.value, d.heatmap.max)}" ${c.value !== null ? `data-tip="${esc(fa(c.label) + ' (' + ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'][c.wd] + ')<br>' + (c.n ? fmtNum(c.n) + ' فاکتور · ' + fmtCompact(c.value) : 'بدون فروش'))}"` : ''}></i>`).join('');
    const years = d.seasonality.years;
    const yearMax = (y) => Math.max(...y.values.map((v) => v || 0), 1);
    const seasonTable = years.length ? `<div class="sa-table-wrap"><table class="sa-cohort"><thead><tr><th class="l">سال</th>${d.seasonality.months.map((m) => `<th>${m}</th>`).join('')}<th>جمع</th></tr></thead><tbody>
      ${years.map((y) => `<tr><td class="l">${fa(y.year)}</td>${y.values.map((v) => { const lv = v ? Math.max(1, Math.ceil(v / yearMax(y) * 6)) : 0; return `<td style="background:${lv ? `var(--seq-${lv})` : 'var(--seq-0)'};color:${lv >= 4 ? '#fff' : 'var(--ink)'}" title="${esc(moneyTitle(v))}">${v === null ? '' : fmtCompact(v).replace(' میلیارد', 'B').replace(' میلیون', 'M').replace(' هزار', 'K')}</td>`; }).join('')}<td class="l" style="font-weight:700">${fmtCompact(y.total)}</td></tr>`).join('')}
      </tbody></table></div><div class="sa-note">رنگ هر خانه نسبت به بهترین ماه همان سال است (B = میلیارد، M = میلیون).</div>` : empty();

    const html = `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('روزهای دارای فروش', `${fmtNum(r.active_days)} از ${fmtNum(r.total_days)}`, fmtPct(r.total_days ? r.active_days / r.total_days * 100 : 0, 0) + ' روزها')}
        ${stat('میانگین فروش در روزهای فعال', fmtCompact(r.avg_per_active_day), '', '', moneyTitle(r.avg_per_active_day))}
        ${stat('میانه مبلغ فاکتور', fmtCompact(r.median_invoice), 'نیمی از فاکتورها کمتر از این‌اند', '', moneyTitle(r.median_invoice))}
        ${stat('بهترین روز', r.best_day ? fmtCompact(r.best_day.net) : '—', r.best_day ? fa(r.best_day.label) + ' · ' + fmtNum(r.best_day.invoices) + ' فاکتور' : '', 'good')}
        ${stat('طولانی‌ترین وقفه بی‌فروش', fmtNum(r.longest_gap) + ' روز', r.longest_gap_end ? 'تا ' + fa(r.longest_gap_end) : '', r.longest_gap > 7 ? 'warn' : '')}
        ${stat('طولانی‌ترین زنجیره فروش', fmtNum(r.longest_streak) + ' روز پیاپی')}
      </div></div>

      <div class="sa-grid">
        ${card({ title: 'روند فروش و سود', icon: 'graph-up', span: 12, sub: d.period.gran_label,
          help: 'فروش خالص و سود ناخالص هر دوره کنار هم. فاصله دو خط همان بهای تمام‌شده کالاست؛ اگر فاصله بیشتر شود یعنی حاشیه سود کم شده است.',
          body: hasData ? legend([{ label: 'فروش خالص', color: 'var(--s1)' }, { label: 'سود ناخالص', color: 'var(--s3)' }, { label: 'مرجوعی', color: 'var(--s8)' }]) + chartBox('tm-trend', 'h-lg') : empty() })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'روزهای هفته', icon: 'calendar-week', span: 6,
          help: 'حالت «میانگین هر روز» منصفانه‌تر است: فروش هر روز هفته تقسیم بر تعداد دفعاتی که آن روز در بازه آمده. مثلاً اگر بازه ۵ جمعه و ۴ شنبه داشته باشد، مقایسه جمع خام گمراه‌کننده است.',
          tools: segToggle('time.wd', [['avg', 'میانگین هر روز'], ['total', 'جمع'], ['count', 'تعداد فاکتور']], wdMode),
          body: chartBox('tm-weekday') })}
        ${card({ title: 'روزهای ماه (شمسی)', icon: 'calendar-date', span: 6,
          help: 'فروش بر اساس روزِ ماه شمسی (۱ تا ۳۱). الگوهایی مثل شلوغی اول ماه (بعد از حقوق) یا آخر ماه را نشان می‌دهد.',
          body: chartBox('tm-dom') + `<div class="sa-stats" style="margin-top:8px">${d.thirds.map((t) => stat(t.label, fmtCompact(t.net), fmtNum(t.invoices) + ' فاکتور', '', moneyTitle(t.net))).join('')}</div>` })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'تقویم فروش روزانه', icon: 'grid-3x3', span: 12, sub: 'هر خانه یک روز؛ هرچه پررنگ‌تر، فروش بیشتر (حداکثر ۵۳ هفته آخر بازه)',
          help: 'نقشه حرارتی روزهای بازه. ستون‌ها هفته‌اند (از راست به چپ) و ردیف‌ها شنبه تا جمعه. روی هر خانه بروید تا تاریخ و مبلغ را ببینید. روزهای خالی، فصل‌های کم‌کار و روزهای تعطیل این‌جا به چشم می‌آیند.',
          body: `<div class="sa-heat-wrap"><div class="sa-heat-box"><div class="sa-heat-labels">${['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].map((x) => `<span>${x}</span>`).join('')}</div><div class="sa-heat">${heat}</div></div></div>
            <div class="sa-legend-scale">کمتر ${['--seq-0', '--seq-1', '--seq-2', '--seq-3', '--seq-4', '--seq-5', '--seq-6'].map((v) => `<i style="background:var(${v})"></i>`).join('')} بیشتر</div>` })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'فصلی بودن فروش — ماه‌های شمسی در سال‌های مختلف', icon: 'calendar3', span: 7,
          help: 'هر خط یک سال شمسی است. اگر خطوط سال‌های مختلف در یک ماه با هم بالا یا پایین می‌روند، آن ماه فصلی است و می‌توان برای خرید و موجودی از قبل برنامه ریخت.',
          body: years.length ? chartBox('tm-season', 'h-lg') : empty() })}
        ${card({ title: `فروش تجمعی ${fa(d.ytd.year)} در برابر ${fa(d.ytd.year - 1)}`, icon: 'graph-up-arrow', span: 5,
          help: 'جمع فروش از ابتدای سال شمسی تا هر ماه، برای امسال و پارسال. اگر خط امسال بالای پارسال باشد، از ابتدای سال جلوتر هستید.',
          body: chartBox('tm-ytd', 'h-lg') + `<div class="sa-note">تا ${fa(d.ytd.current.until || '')}: ${fmtCompact(d.ytd.current.total)} · پارسال تا همین روز: ${fmtCompact(d.ytd.previous.total)}</div>` })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'جدول فصلی بودن (ماه × سال)', icon: 'table', span: 12, body: seasonTable })}
        ${d.seasonality.avg_share ? card({ title: 'سهم معمول هر ماه از فروش سال', icon: 'pie-chart', span: 12, sub: 'میانگین سال‌های کامل گذشته',
          help: 'در سال‌های کامل گذشته، به‌طور میانگین چند درصد فروش سالانه در هر ماه اتفاق افتاده است. برای بودجه‌بندی و تعیین هدف ماهانه مفید است (اگر هدف سالانه دارید، هدف هر ماه = هدف سال × این درصد).',
          body: chartBox('tm-share', 'h-sm') }) : ''}
      </div>`;

    return {
      html,
      after() {
        const p = pal();
        if (hasData) mount('time', 'tm-trend', { data: { labels: faLabels(tr.labels), datasets: [
          lineDs('فروش خالص', tr.net, p.s[0], { fill: 'origin', backgroundColor: alpha(p.s[0], 0.08) }),
          lineDs('سود ناخالص', tr.profit, p.s[2]),
          barDs('مرجوعی', tr.returns, alpha(p.s[7], 0.7), { maxBarThickness: 10 }),
        ] }, options: opts({ fmt: 'money', titles: tr.titles }) });
        const wd = d.weekday;
        const wdVals = wd.map((x) => wdMode === 'avg' ? x.avg : wdMode === 'total' ? x.net : x.invoices);
        const wo = opts({ fmt: wdMode === 'count' ? 'int' : 'money' });
        wo.plugins.tooltip.callbacks.footer = (items) => fa(`${wd[items[0].dataIndex].occurrences} بار در بازه · ${wd[items[0].dataIndex].invoices} فاکتور`);
        mount('time', 'tm-weekday', { type: 'bar', data: { labels: wd.map((x) => x.label), datasets: [barDs(wdMode === 'avg' ? 'میانگین فروش هر روز' : wdMode === 'total' ? 'جمع فروش' : 'تعداد فاکتور', wdVals, p.s[0])] }, options: wo });
        const domLabels = d.day_of_month.values.map((_, i) => String(i + 1));
        const dmo = opts({ fmt: 'money' });
        dmo.plugins.tooltip.callbacks.title = (items) => 'روز ' + fa(items[0].label) + ' ماه';
        dmo.plugins.tooltip.callbacks.footer = (items) => fa(d.day_of_month.counts[items[0].dataIndex] + ' فاکتور');
        mount('time', 'tm-dom', { type: 'bar', data: { labels: faLabels(domLabels), datasets: [barDs('فروش', d.day_of_month.values, p.s[0], { categoryPercentage: 0.85 })] }, options: dmo });
        if (years.length) {
          const shown = years.slice(-8);
          mount('time', 'tm-season', { data: { labels: d.seasonality.months, datasets: shown.map((y, i) => lineDs(fa('سال ' + y.year), y.values, p.s[(shown.length - 1 - i) % 8], { pointRadius: 3, borderWidth: i === shown.length - 1 ? 2.5 : 1.6 })) }, options: opts({ fmt: 'money', legend: true }) });
        }
        mount('time', 'tm-ytd', { data: { labels: d.seasonality.months, datasets: [
          lineDs(fa('سال ' + d.ytd.year), d.ytd.current.values, p.s[0], { pointRadius: 3, fill: 'origin', backgroundColor: alpha(p.s[0], 0.08) }),
          lineDs(fa('سال ' + (d.ytd.year - 1)), d.ytd.previous.values, p.muted, { pointRadius: 2 }),
        ] }, options: opts({ fmt: 'money', legend: true }) });
        if (d.seasonality.avg_share) mount('time', 'tm-share', { type: 'bar', data: { labels: d.seasonality.months, datasets: [barDs('سهم از فروش سال', d.seasonality.avg_share, p.s[0])] }, options: opts({ fmt: 'pct' }) });
      },
    };
  };

  /* ---------------- products ---------------- */
  RENDER.products = (d) => {
    periodCaption(d.period);
    const t = d.totals;
    const cols = (state.meta && state.meta.collections) || [];
    const sub = `<div class="sa-subfilters">
        <div class="sa-field"><label>کلکسیون</label><select class="form-select form-select-sm" id="pr-collection"><option value="0">همه کلکسیون‌ها</option>${cols.map((c) => `<option value="${c.id}" ${+state.products.collection === +c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
        <div class="sa-field"><label>نوع</label><select class="form-select form-select-sm" id="pr-type"><option value="">همه</option><option value="machine" ${state.products.ptype === 'machine' ? 'selected' : ''}>ماشینی</option><option value="handmade" ${state.products.ptype === 'handmade' ? 'selected' : ''}>دستباف</option></select></div>
        <div class="sa-note" style="margin:0 0 4px">این فیلترها فقط روی همین بخش اعمال می‌شوند.</div>
      </div>`;
    if (!d.rows.length) return { html: sub + empty('در این بازه/فیلتر فروشی ثبت نشده است.') };
    const abc = d.abc;
    const prodCols = [
      { key: 'name', label: 'محصول', type: 'text', wrap: true, html: (r) => `${esc(fa(r.name))}${r.design_code ? `<div class="muted" style="font-size:.7rem">${esc(fa(r.design_code))}</div>` : ''}`, csv: (r) => r.name },
      { key: 'abc', label: 'رده', type: 'text', html: (r) => badge(r.abc, 'abc-' + r.abc) },
      { key: 'collection', label: 'کلکسیون', type: 'text' },
      { key: 'qty', label: 'تعداد', type: 'int' },
      { key: 'area', label: 'متراژ (m²)', type: 'dec' },
      { key: 'net', label: 'فروش', type: 'money' },
      { key: 'growth', label: 'رشد', type: 'pct', nosum: true, html: (r) => r.growth === null ? '<span class="muted">جدید</span>' : deltaChip(r.growth) },
      { key: 'profit', label: 'سود', type: 'money' },
      { key: 'margin', label: 'حاشیه', type: 'pct', html: (r) => r.margin === null ? (r.cost_unknown ? badge('بهای نامشخص', 'warn') : '—') : `<span style="color:${r.margin < 10 ? 'var(--bad-ink)' : 'inherit'}">${fmtPct(r.margin)}</span>${r.estimated ? ' <i class="bi bi-asterisk muted" title="بهای تمام‌شده تخمینی"></i>' : ''}` },
      { key: 'avg_price', label: 'میانگین قیمت', type: 'money' },
      { key: 'price_m2', label: 'قیمت هر متر', type: 'money' },
      { key: 'discount_pct', label: 'تخفیف', type: 'pct' },
      { key: 'customers', label: 'مشتری', type: 'int' },
      { key: 'returned', label: 'مرجوعی', type: 'int' },
      { key: 'stock', label: 'موجودی', type: 'int', html: (r) => r.stock ? fmtNum(r.stock) : badge('ناموجود', 'warn') },
      { key: 'days_since', label: 'روز از آخرین فروش', type: 'days' },
      { key: 'share', label: 'سهم', type: 'share' },
    ];
    const groupCols = (labelName) => [
      { key: 'label', label: labelName, type: 'text', wrap: true },
      { key: 'qty', label: 'تعداد', type: 'int' },
      { key: 'net', label: 'فروش', type: 'money' },
      { key: 'profit', label: 'سود', type: 'money' },
      { key: 'margin', label: 'حاشیه', type: 'pct' },
      { key: 'area', label: 'متراژ', type: 'dec' },
      { key: 'price_m2', label: 'قیمت هر متر', type: 'money' },
      { key: 'discount_pct', label: 'تخفیف', type: 'pct' },
      { key: 'share', label: 'سهم', type: 'share' },
    ];
    const colTableCols = groupCols('کلکسیون').concat([
      { key: 'stock', label: 'موجودی', type: 'int' },
      { key: 'sell_through', label: 'نرخ فروش رفتن', type: 'pct', title: () => 'فروش ÷ (فروش + موجودی فعلی)' },
      { key: 'realization', label: 'تحقق قیمت لیست', type: 'pct', title: () => 'قیمت واقعی هر متر ÷ قیمت لیست فعلی هر متر' },
    ]);
    const pairTable = (b, name) => b.pairs.length ? table('products', name, [
      { key: 'a', label: 'الف', type: 'text', wrap: true }, { key: 'b', label: 'ب', type: 'text', wrap: true },
      { key: 'count', label: 'دفعات با هم', type: 'int' }, { key: 'confidence', label: 'احتمال همراهی', type: 'pct' },
      { key: 'lift', label: 'ضریب جذب', type: 'dec', title: () => 'بیشتر از ۱ یعنی این دو بیش از حالت تصادفی با هم خریده می‌شوند' },
    ], b.pairs, { search: false, limit: 12 }) : empty('هنوز ترکیبی که حداقل ۲ بار با هم فروخته شده باشد پیدا نشد.');

    const html = sub + `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('محصولات فروخته‌شده', fmtNum(t.products))}
        ${stat('تعداد اقلام', fmtNum(t.qty))}
        ${stat('فروش پس از تخفیف', fmtCompact(t.net), 'پیش از کسر مرجوعی‌ها', '', moneyTitle(t.net))}
        ${stat('سود ناخالص', fmtCompact(t.profit), t.net ? 'حاشیه ' + fmtPct(t.profit / t.net * 100) : '', '', moneyTitle(t.profit))}
        ${stat('رده A (محصولات کلیدی)', fmtNum(abc.A.count) + ' محصول', fmtPct(t.net ? abc.A.net / t.net * 100 : 0) + ' فروش', 'good')}
        ${stat('رده C (دنباله)', fmtNum(abc.C.count) + ' محصول', fmtPct(t.net ? abc.C.net / t.net * 100 : 0) + ' فروش')}
        ${stat('فاکتورهای چندقلمی', fmtPct(d.pairs.multi_share), 'فاکتورهایی با بیش از یک محصول')}
      </div></div>

      <div class="sa-grid">
        ${card({ title: 'قانون ۸۰/۲۰ (تحلیل ABC)', icon: 'bar-chart-line', span: 6, sub: 'محصولات از پرفروش به کم‌فروش؛ محور: درصد تجمعی فروش',
          help: 'محصولات به ترتیب فروش چیده شده‌اند و نمودار نشان می‌دهد با اضافه شدن هر محصول، چند درصد کل فروش پوشش داده می‌شود. رده A محصولاتی‌اند که ۸۰٪ فروش را می‌سازند (هرگز نباید تمام شوند)، B تا ۹۵٪ و C بقیه (کاندید حذف یا حراج).',
          body: chartBox('pr-pareto', 'h-lg') })}
        ${card({ title: 'سهم کلکسیون‌ها از فروش', icon: 'collection', span: 6, help: 'فروش خالص هر کلکسیون. ۱۲ کلکسیون اول نمایش داده می‌شود؛ جدول کامل پایین‌تر است.', body: chartBox('pr-collections', 'h-lg') })}
      </div>

      <div class="sa-grid">${card({ title: 'عملکرد کلکسیون‌ها', icon: 'collection', span: 12,
        help: '«نرخ فروش رفتن» = فروش بازه ÷ (فروش بازه + موجودی فعلی)؛ عدد بالا یعنی کلکسیون خوب می‌چرخد و شاید باید بیشتر خرید شود. «تحقق قیمت لیست» = قیمت واقعی فروش هر متر ÷ قیمت لیست فعلی هر متر کلکسیون؛ نشان می‌دهد عملاً چقدر زیر قیمت لیست می‌فروشید.',
        body: table('products', 'عملکرد کلکسیون‌ها', colTableCols, d.collections, { sort: 'net' }) })}</div>

      <div class="sa-grid">
        ${card({ title: 'سایزهای پرفروش', icon: 'aspect-ratio', span: 6, help: 'تعداد فروش هر سایز (ابعاد یکسان مثل ۲۰۵×۱۵۰ و ۱٫۵×۲٫۰۵ یکی حساب می‌شوند) در کنار موجودی فعلی همان سایز.',
          body: chartBox('pr-sizes', 'h-lg') })}
        ${card({ title: 'فروش بر اساس اندازه فرش', icon: 'bounding-box', span: 6, help: 'دسته‌بندی بر اساس متراژ هر فرش. نشان می‌دهد مشتری‌های شما بیشتر دنبال فرش کوچک‌اند یا بزرگ.',
          body: chartBox('pr-area') + (d.types.length > 1 ? `<div class="sa-stats" style="margin-top:8px">${d.types.map((x) => stat(x.label, fmtCompact(x.net), fmtNum(x.qty) + ' قلم · حاشیه ' + fmtPct(x.margin), '', moneyTitle(x.net))).join('')}</div>` : '') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'بازه‌های قیمت', icon: 'cash-stack', span: 6, help: 'فروش بر اساس قیمت هر قلم. مرزها خودکار از روی داده‌های خودتان انتخاب می‌شوند تا هر بازه سهم معناداری داشته باشد.',
          body: d.price_bands.length ? chartBox('pr-bands') + table('products', 'بازه‌های قیمت', [{ key: 'label', label: 'بازه قیمت', type: 'text' }, { key: 'qty', label: 'تعداد', type: 'int' }, { key: 'net', label: 'فروش', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }, { key: 'share', label: 'سهم', type: 'share' }], d.price_bands, { search: false }) : empty() })}
        ${card({ title: 'جدول ابعاد دقیق', icon: 'rulers', span: 6, body: table('products', 'فروش بر اساس ابعاد دقیق', [{ key: 'label', label: 'ابعاد', type: 'text' }, { key: 'size_label', label: 'متراژ', type: 'text' }, { key: 'qty', label: 'فروش', type: 'int' }, { key: 'stock', label: 'موجودی', type: 'int' }, { key: 'net', label: 'مبلغ', type: 'money' }, { key: 'price_m2', label: 'قیمت هر متر', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }], d.sizes, { search: false, limit: 12 }) })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'فروش به تفکیک متراژ', icon: 'aspect-ratio', span: 12,
          sub: 'هر فرش بر اساس ابعادش نام‌گذاری شده: ۲×۳ یا ۳۰۹×۲۰۱ ← «۶ متری»، ۲٫۵×۳٫۵ ← «۹ متری»؛ فرش‌هایی که طولشان ۱٫۷۵ برابر عرض یا بیشتر است «کناره» حساب می‌شوند (به طول).',
          help: 'چند تخته از هر متراژ فروخته‌اید و الان چند تخته از همان متراژ موجود دارید. برای یک کلکسیون خاص، فیلتر «کلکسیون» بالای همین تب را بزنید؛ برای یک طرح خاص از جدول «طرح × متراژ» پایین‌تر استفاده کنید.',
          body: d.size_names.length ? chartBox('pr-sizenames') + table('products', 'فروش به تفکیک متراژ', [
            { key: 'label', label: 'متراژ', type: 'text', sortVal: (r) => r.sort_area },
            { key: 'qty', label: 'تعداد فروش', type: 'int' }, { key: 'area', label: 'جمع متر مربع', type: 'dec' },
            { key: 'net', label: 'فروش', type: 'money' }, { key: 'avg_price', label: 'میانگین قیمت هر تخته', type: 'money' },
            { key: 'price_m2', label: 'قیمت هر متر', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' },
            { key: 'stock', label: 'موجودی فعلی', type: 'int' }, { key: 'share', label: 'سهم', type: 'share' },
          ], d.size_names, { search: false, sort: 'label', dir: 'asc', limit: 30 }) : empty() })}
      </div>

      <div class="sa-grid">${card({ title: 'طرح × متراژ', icon: 'grid-3x2-gap', span: 12,
        sub: 'جستجو کنید، مثلاً «مراکشی ۶ متری»، «کد ۲۷ ۴ متری» یا «کناره ۳ متری»؛ ردیف «جمع» پایین جدول تعداد و متراژ همان ردیف‌ها را جمع می‌زند.',
        help: 'هر ردیف یک محصول در یک اندازه است: چند تخته فروخته شده، چند متر مربع، به چه قیمتی و الان چند تخته از همان محصول و اندازه در انبار دارید. عدد در جستجو با عدد کامل مقایسه می‌شود؛ پس «۶» با «کد ۲۶» یا «۱٫۶» قاطی نمی‌شود.',
        body: table('products', 'فروش طرح × متراژ', [
          { key: 'product', label: 'محصول', type: 'text', wrap: true, html: (r) => `${esc(fa(r.product))}${r.design_code ? `<div class="muted" style="font-size:.7rem">${esc(fa(r.design_code))}</div>` : ''}`, csv: (r) => r.product + (r.design_code ? ' ' + r.design_code : '') },
          { key: 'collection', label: 'کلکسیون', type: 'text', wrap: true },
          { key: 'size_label', label: 'متراژ', type: 'text', html: (r) => badge(esc(r.size_label), 'info'), sortVal: (r) => r.area_unit },
          { key: 'dims', label: 'ابعاد', type: 'text', html: (r) => `<span dir="ltr">${esc(fa(r.dims))}</span>` },
          { key: 'qty', label: 'تعداد', type: 'int' }, { key: 'area', label: 'متر مربع', type: 'dec' },
          { key: 'net', label: 'فروش', type: 'money' }, { key: 'avg_price', label: 'میانگین قیمت', type: 'money' },
          { key: 'price_m2', label: 'قیمت هر متر', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' },
          { key: 'stock', label: 'موجودی', type: 'int' }, { key: 'last_sale', label: 'آخرین فروش', type: 'text' },
        ], d.product_sizes, { sort: 'qty', limit: 25 }) })}</div>

      <div class="sa-grid">
        ${card({ title: 'چه چیزهایی با هم فروخته می‌شوند؟ (محصول)', icon: 'link-45deg', span: 6,
          help: 'جفت محصولاتی که در یک فاکتور با هم خریده شده‌اند. «احتمال همراهی» یعنی از بین فاکتورهای کالای کم‌فروش‌تر، چند درصد کالای دیگر را هم داشته‌اند. برای پیشنهاد فروش مکمل و چیدمان مغازه عالی است.',
          body: pairTable(d.pairs, 'محصولات هم‌خرید') })}
        ${card({ title: 'کلکسیون‌هایی که با هم خریده می‌شوند', icon: 'diagram-3', span: 6, body: pairTable(d.collection_pairs, 'کلکسیون‌های هم‌خرید') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'بیشترین رشد', icon: 'arrow-up-right-circle', span: 4, sub: 'نسبت به دوره مقایسه', body: d.rising.length ? table('products', 'محصولات با بیشترین رشد', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'cmp_net', label: 'قبل', type: 'money' }, { key: 'net', label: 'اکنون', type: 'money' }], d.rising, { search: false }) : empty('دوره مقایسه ندارد یا رشدی نبوده.') })}
        ${card({ title: 'بیشترین افت', icon: 'arrow-down-right-circle', span: 4, sub: 'نسبت به دوره مقایسه', body: d.falling.length ? table('products', 'محصولات با بیشترین افت', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'cmp_net', label: 'قبل', type: 'money' }, { key: 'net', label: 'اکنون', type: 'money' }], d.falling, { search: false }) : empty('افتی دیده نشد.') })}
        ${card({ title: 'قبلاً فروش داشتند، حالا نه', icon: 'slash-circle', span: 4, help: 'محصولاتی که در دوره مقایسه فروش داشتند ولی در این بازه هیچ فروشی نداشته‌اند — ممکن است تمام شده باشند یا از مد افتاده باشند.',
          body: d.not_sold_again.length ? table('products', 'محصولات بدون فروش در این دوره', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'qty', label: 'فروش قبل', type: 'int' }, { key: 'net', label: 'مبلغ قبل', type: 'money' }], d.not_sold_again, { search: false }) : empty('موردی نیست.') })}
      </div>

      ${d.attributes.length ? `<div class="sa-heading"><i class="bi bi-palette"></i>ویژگی‌های محصول <small>رنگ، جنس، شانه، تراکم و کد طرح — فقط ویژگی‌هایی که در اطلاعات محصول پر شده‌اند</small></div>
      <div class="sa-grid">${d.attributes.map((a) => card({ title: a.label, span: 6, sub: `پوشش داده: ${fmtPct(a.coverage, 0)} ردیف‌ها`, body: table('products', 'فروش بر اساس ' + a.label, [{ key: 'label', label: a.label, type: 'text' }, { key: 'qty', label: 'تعداد', type: 'int' }, { key: 'net', label: 'فروش', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }, { key: 'share', label: 'سهم', type: 'share' }], a.rows, { search: false, limit: 10 }) })).join('')}</div>` : ''}

      <div class="sa-grid">${card({ title: 'جدول کامل محصولات', icon: 'table', span: 12,
        sub: 'برای دیدن جمع متراژ و فروش یک طرح یا کلکسیون، نامش را جستجو کنید (مثلاً «مراکشی» یا «مراکشی کد ۲۷»)؛ ردیف «جمع» پایین جدول فقط ردیف‌های پیدا شده را جمع می‌زند.',
        help: 'همه محصولات فروخته‌شده با جزئیات. روی عنوان هر ستون بزنید تا مرتب شود. «رشد» نسبت به دوره مقایسه است. ستاره کنار حاشیه یعنی بهای تمام‌شده تخمینی است. «روز از آخرین فروش» تا انتهای بازه حساب می‌شود.',
        body: table('products', 'محصولات', prodCols, d.rows, { sort: 'net', limit: 30 }) })}</div>`;

    return {
      html,
      after() {
        const p = pal();
        const po = opts({ fmt: 'pct', max: 100 });
        po.plugins.tooltip.callbacks.title = (items) => fa(`${items[0].dataIndex + 1}. ${d.pareto[items[0].dataIndex].name}`);
        po.plugins.tooltip.callbacks.footer = (items) => 'فروش این محصول: ' + fa(fmtCompact(d.pareto[items[0].dataIndex].net));
        mount('products', 'pr-pareto', { data: { labels: d.pareto.map((_, i) => fa(i + 1)), datasets: [
          lineDs('درصد تجمعی فروش', d.pareto.map((x) => x.cum), p.s[0], { fill: 'origin', backgroundColor: alpha(p.s[0], 0.1), pointRadius: 2, tension: 0.2 }),
          lineDs('مرز ۸۰٪', d.pareto.map(() => 80), p.muted, { borderWidth: 1, borderDash: [4, 4] }),
        ] }, options: po });
        const topCols = d.collections.slice(0, 12);
        mount('products', 'pr-collections', { type: 'bar', data: { labels: topCols.map((c) => c.label), datasets: [barDs('فروش خالص', topCols.map((c) => c.net), p.s[0])] }, options: opts({ fmt: 'money', horizontal: true }) });
        const sz = d.sizes.slice(0, 12);
        mount('products', 'pr-sizes', { type: 'bar', data: { labels: sz.map((s) => s.label), datasets: [barDs('تعداد فروش', sz.map((s) => s.qty), p.s[0]), barDs('موجودی فعلی', sz.map((s) => s.stock), p.s[1])] }, options: opts({ fmt: 'int', horizontal: true, legend: true }) });
        if (d.size_names.length) {
          const sn = d.size_names.slice().sort((a, b) => a.sort_area - b.sort_area || String(a.label).localeCompare(String(b.label), 'fa')).filter((x) => x.qty > 0);
          mount('products', 'pr-sizenames', { type: 'bar', data: { labels: sn.map((x) => x.label), datasets: [barDs('تعداد فروش', sn.map((x) => x.qty), p.s[0]), barDs('موجودی فعلی', sn.map((x) => x.stock), p.s[1])] }, options: opts({ fmt: 'int', legend: true }) });
        }
        mount('products', 'pr-area', { type: 'bar', data: { labels: d.area_classes.map((s) => s.label), datasets: [barDs('فروش خالص', d.area_classes.map((s) => s.net), p.s[0])] }, options: opts({ fmt: 'money' }) });
        if (d.price_bands.length) mount('products', 'pr-bands', { type: 'bar', data: { labels: d.price_bands.map((s) => s.label), datasets: [barDs('تعداد فروش', d.price_bands.map((s) => s.qty), p.s[0])] }, options: opts({ fmt: 'int' }) });
      },
    };
  };

  /* ---------------- customers ---------------- */
  RENDER.customers = (d) => {
    periodCaption(d.period);
    const s = d.summary;
    const segFilter = viewOf('customers', 'segment', '');
    const segRows = segFilter ? d.rfm_customers.filter((c) => c.segment === segFilter) : d.rfm_customers;
    const segLabel = Object.fromEntries(d.segments.map((x) => [x.key, x]));
    const segBadge = (k) => k && segLabel[k] ? badge(segLabel[k].label, segLabel[k].tone === 'muted' ? '' : segLabel[k].tone) : '—';
    const cohortHtml = d.cohorts.length ? `<div class="sa-table-wrap"><table class="sa-cohort"><thead><tr><th class="l">ماه اولین خرید</th><th>مشتری</th>${Array.from({ length: 12 }, (_, i) => `<th>${i === 0 ? 'ماه ۰' : fa('+' + i)}</th>`).join('')}<th>برگشتند</th></tr></thead><tbody>
      ${d.cohorts.map((c) => `<tr><td class="l">${fa(c.label)}</td><td class="l">${fmtNum(c.size)}</td>${c.cells.map((v, i) => {
        if (v === null) return '<td></td>';
        const lv = i === 0 ? 0 : v <= 0 ? 0 : Math.min(6, Math.max(1, Math.ceil(v / 50 * 6)));
        return `<td style="background:${i === 0 ? 'var(--surface-2)' : lv ? `var(--seq-${lv})` : 'var(--seq-0)'};color:${lv >= 4 ? '#fff' : 'var(--ink)'}">${i === 0 ? '' : fmtPct(v, 0)}</td>`;
      }).join('')}<td class="l" style="font-weight:700">${fmtPct(c.came_back, 0)}</td></tr>`).join('')}
      </tbody></table></div>` : empty();

    const custCols = [
      { key: 'name', label: 'مشتری', type: 'text', wrap: true, html: (r) => `${esc(fa(r.name))}${r.is_new ? ' ' + badge('جدید', 'info') : ''}${r.phone ? `<div class="muted" style="font-size:.7rem" dir="ltr">${esc(fa(r.phone))}</div>` : ''}`, csv: (r) => r.name },
      { key: 'phone', label: 'تلفن', type: 'text', html: (r) => esc(fa(r.phone || '—')) },
      { key: 'segment', label: 'دسته', type: 'text', html: (r) => segBadge(r.segment), csv: (r) => (segLabel[r.segment] || {}).label || '' },
      { key: 'invoices', label: 'فاکتور', type: 'int' },
      { key: 'net', label: 'خرید خالص', type: 'money' },
      { key: 'profit', label: 'سود', type: 'money' },
      { key: 'margin', label: 'حاشیه', type: 'pct' },
      { key: 'aov', label: 'میانگین فاکتور', type: 'money' },
      { key: 'discount_pct', label: 'تخفیف', type: 'pct' },
      { key: 'returns', label: 'مرجوعی', type: 'money' },
      { key: 'balance', label: 'مانده بدهی', type: 'money', html: (r) => r.balance > 1 ? `<span style="color:var(--bad-ink)" title="${esc(moneyTitle(r.balance))}">${fmtCompact(r.balance)}</span>` : r.balance < -1 ? `<span class="muted" title="بستانکار">${fmtCompact(r.balance)}</span>` : '—' },
      { key: 'lifetime_invoices', label: 'کل خریدها', type: 'int' },
      { key: 'lifetime_net', label: 'ارزش عمر', type: 'money' },
      { key: 'avg_interval', label: 'فاصله خریدها (روز)', type: 'days' },
      { key: 'first', label: 'اولین خرید', type: 'text' },
      { key: 'last', label: 'آخرین خرید', type: 'text' },
      { key: 'share', label: 'سهم', type: 'share' },
    ];
    const rfmCols = [
      { key: 'name', label: 'مشتری', type: 'text', wrap: true },
      { key: 'phone', label: 'تلفن', type: 'text' },
      { key: 'segment', label: 'دسته', type: 'text', html: (r) => segBadge(r.segment), csv: (r) => (segLabel[r.segment] || {}).label || '' },
      { key: 'recency', label: 'روز از آخرین خرید', type: 'days' },
      { key: 'frequency', label: 'دفعات خرید', type: 'int' },
      { key: 'monetary', label: 'جمع خرید', type: 'money' },
      { key: 'avg_interval', label: 'فاصله معمول (روز)', type: 'days' },
      { key: 'last', label: 'آخرین خرید', type: 'text' },
    ];

    const html = `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('مشتریان فعال', fmtNum(s.active), `${fmtNum(s.new)} جدید · ${fmtNum(s.returning)} قدیمی`)}
        ${stat('خرید هر مشتری', fmtCompact(s.sales_per_customer), 'میانگین در این بازه', '', moneyTitle(s.sales_per_customer))}
        ${stat('نرخ خرید مجدد', fmtPct(s.repeat_rate), 'مشتریانی که بیش از یک بار خریده‌اند (کل سابقه)', s.repeat_rate < 20 ? 'warn' : 'good')}
        ${stat('ارزش طول عمر مشتری', fmtCompact(s.avg_ltv), 'میانگین جمع خرید هر مشتری', '', moneyTitle(s.avg_ltv))}
        ${stat('فاصله معمول بین خریدها', s.median_interval === null ? '—' : fmtNum(s.median_interval) + ' روز', 'میانه، برای مشتریان تکراری')}
        ${stat('سهم ۱۰٪ مشتریان برتر', fmtPct(s.top10_share), '۲۰٪ برتر: ' + fmtPct(s.top20_share), s.top10_share > 50 ? 'warn' : '')}
        ${stat('شاخص تمرکز (HHI)', fmtNum(s.hhi), s.hhi > 2500 ? 'تمرکز بالا' : s.hhi > 1500 ? 'تمرکز متوسط' : 'پراکنده و کم‌ریسک', s.hhi > 2500 ? 'bad' : s.hhi > 1500 ? 'warn' : 'good')}
        ${stat('مطالبات از مشتریان', fmtCompact(s.total_receivable), fmtNum(s.customers_with_debt) + ' مشتری بدهکار', '', moneyTitle(s.total_receivable))}
      </div></div>

      <div class="sa-heading"><i class="bi bi-people"></i>دسته‌بندی هوشمند مشتریان (RFM) <small>بر اساس تازگی آخرین خرید، دفعات خرید و مبلغ خرید — روی هر دسته بزنید</small></div>
      <div class="sa-segments" style="margin-bottom:12px">${d.segments.map((x) => `<button type="button" class="sa-seg ${x.tone} ${segFilter === x.key ? 'active' : ''}" data-segment="${x.key}" title="${esc(x.desc)}">
          <div class="t">${x.label}</div><div class="n">${fmtNum(x.count)}</div><div class="d">${x.desc}<br>خرید: ${fmtCompact(x.monetary)}${x.avg_recency !== null ? ' · میانگین ' + fmtNum(x.avg_recency) + ' روز پیش' : ''}</div></button>`).join('')}</div>
      <div class="sa-grid">${card({ title: segFilter ? 'مشتریان دسته «' + segLabel[segFilter].label + '»' : 'همه مشتریان بر اساس دسته', icon: 'person-lines-fill', span: 12,
        tools: segFilter ? '<button class="sa-tool" data-segment="" title="حذف فیلتر"><i class="bi bi-x-lg"></i></button>' : '',
        help: 'تازگی: خرید در ۳۰ روز اخیر بهترین است، بعد ۹۰، ۱۸۰ و ۳۶۵ روز. دفعات: ۱، ۲، ۳، ۴–۵ و ۶+ بار. مبلغ: نسبت به بقیه مشتریان (پنجک). این دسته‌ها تا پایان بازه انتخابی محاسبه می‌شوند و برای پیگیری تلفنی، پیامک تبلیغاتی و برنامه وفاداری کاربرد دارند.',
        body: table('customers', 'دسته‌بندی RFM مشتریان', rfmCols, segRows, { sort: 'monetary', limit: 20 }) })}</div>

      <div class="sa-grid">
        ${card({ title: 'مشتری جدید یا قدیمی؟', icon: 'person-plus', span: 7, sub: 'تعداد مشتریان هر دوره',
          help: 'در هر دوره، چند مشتری برای اولین بار خرید کرده‌اند و چند نفر مشتری قبلی بوده‌اند. کسب‌وکار سالم هر دو را با هم رشد می‌دهد.',
          tools: segToggle('customers.nr', [['count', 'تعداد'], ['net', 'مبلغ']], viewOf('customers', 'nr', 'count')),
          body: legend([{ label: 'مشتری جدید', color: 'var(--s1)' }, { label: 'مشتری قدیمی', color: 'var(--s2)' }]) + chartBox('cu-newret') })}
        ${card({ title: 'منحنی تمرکز فروش', icon: 'graph-up', span: 5,
          help: 'محور افقی درصد مشتریان (از بزرگ‌ترین به کوچک‌ترین) و محور عمودی درصد فروش. هرچه منحنی زودتر بالا برود، فروش به چند مشتری بزرگ وابسته‌تر است. خط خاکستری حالت کاملاً برابر است.',
          body: chartBox('cu-lorenz') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'دفعات خرید مشتریان (کل سابقه)', icon: 'bar-chart', span: 5,
          help: 'چند مشتری فقط یک بار خریده‌اند و چند نفر بارها برگشته‌اند. اگر اکثریت یک‌بار خریدند، برنامه پیگیری بعد از خرید (تماس، پیامک، تخفیف خرید دوم) سود زیادی دارد.',
          body: chartBox('cu-freq') })}
        ${card({ title: 'نگهداشت مشتری (کوهورت)', icon: 'grid-3x3-gap', span: 7, sub: 'درصد مشتریان هر ماه که در ماه‌های بعد دوباره خرید کرده‌اند',
          help: 'هر ردیف مشتریانی‌اند که اولین خریدشان در آن ماه بوده. خانه «+۳» یعنی چند درصد آن‌ها ۳ ماه بعد دوباره خرید کرده‌اند. ستون آخر: چند درصد هرگز دوباره آمده‌اند. این مهم‌ترین شاخص وفاداری است که معمولاً هیچ‌کس نگاهش نمی‌کند.',
          body: cohortHtml })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'در معرض ریزش — زمان تماس است!', icon: 'telephone-outbound', span: 7,
          help: 'مشتریانی که حداقل ۲ بار خرید کرده‌اند و الان بیش از ۱٫۵ برابر فاصله معمول خودشان است که نیامده‌اند. به ترتیب ارزش خرید. یک تماس یا پیام همین هفته ممکن است برشان گرداند.',
          body: table('customers', 'مشتریان در معرض ریزش', [
            { key: 'name', label: 'مشتری', type: 'text', wrap: true }, { key: 'phone', label: 'تلفن', type: 'text' },
            { key: 'frequency', label: 'دفعات', type: 'int' }, { key: 'monetary', label: 'جمع خرید', type: 'money' },
            { key: 'expected', label: 'فاصله معمول', type: 'days' }, { key: 'recency', label: 'غیبت (روز)', type: 'days' },
            { key: 'overdue_ratio', label: 'چند برابر', type: 'dec' }, { key: 'last', label: 'آخرین خرید', type: 'text' },
          ], d.churn_risk, { search: false, limit: 15 }) })}
        ${card({ title: 'بیشترین مطالبات', icon: 'wallet2', span: 5, help: 'مانده حساب مشتریان تا امروز (فاکتورها منهای دریافتی‌ها و مرجوعی‌ها، با احتساب مانده اول دوره).',
          body: table('customers', 'مشتریان بدهکار', [{ key: 'name', label: 'مشتری', type: 'text', wrap: true }, { key: 'receivable', label: 'مانده', type: 'money' }, { key: 'pending_checks', label: 'چک در جریان', type: 'money' }], d.debtors, { search: false }) })}
      </div>

      <div class="sa-grid">${card({ title: 'جدول کامل مشتریان این بازه', icon: 'table', span: 12,
        help: '«ارزش عمر» جمع کل خریدهای مشتری از ابتدا تا پایان بازه است. «فاصله خریدها» میانگین روزهای بین دو خرید متوالی. «مانده بدهی» تا امروز است. سود و حاشیه نشان می‌دهد کدام مشتری واقعاً سودآور است، نه فقط پرخرید.',
        body: table('customers', 'مشتریان', custCols, d.rows, { sort: 'net', limit: 30 }) })}</div>

      <div class="sa-grid">${card({ title: 'مشتریان قدیمی که در این بازه خرید نکرده‌اند', icon: 'moon', span: 12, sub: 'به ترتیب ارزش خرید تاریخی',
        body: table('customers', 'مشتریان غیرفعال در این بازه', rfmCols, d.dormant, { search: false, limit: 10 }) })}</div>`;

    return {
      html,
      after() {
        const p = pal();
        const nr = d.new_returning, mode = viewOf('customers', 'nr', 'count');
        mount('customers', 'cu-newret', { type: 'bar', data: { labels: faLabels(nr.labels), datasets: [
          barDs('مشتری جدید', mode === 'count' ? nr.new : nr.new_net, p.s[0], { stack: 'a' }),
          barDs('مشتری قدیمی', mode === 'count' ? nr.returning : nr.returning_net, p.s[1], { stack: 'a', borderWidth: { top: 2, bottom: 0, left: 0, right: 0 } }),
        ] }, options: opts({ fmt: mode === 'count' ? 'int' : 'money', stacked: true }) });
        const lo = opts({ fmt: 'pct', max: 100 });
        lo.scales.x = { type: 'linear', min: 0, max: 100, reverse: true, grid: { display: false }, border: { color: p.axis }, ticks: { color: p.muted, callback: (v) => fmtNum(v) + '٪' } };
        lo.plugins.tooltip.callbacks.title = (items) => fa(`${items[0].parsed.x}٪ مشتریان برتر`);
        lo.interaction = { mode: 'nearest', intersect: false, axis: 'x' };
        mount('customers', 'cu-lorenz', { type: 'line', data: { datasets: [
          lineDs('سهم از فروش', d.concentration.map(([x, y]) => ({ x, y })), p.s[0], { fill: 'origin', backgroundColor: alpha(p.s[0], 0.1), tension: 0.15 }),
          lineDs('توزیع برابر', [{ x: 0, y: 0 }, { x: 100, y: 100 }], p.muted, { borderWidth: 1 }),
        ] }, options: lo });
        mount('customers', 'cu-freq', { type: 'bar', data: { labels: d.frequency.labels, datasets: [barDs('تعداد مشتری', d.frequency.counts, p.s[0])] }, options: Object.assign(opts({ fmt: 'int' }), {}) });
      },
    };
  };

  /* ---------------- collections ---------------- */
  RENDER.collections = (d) => {
    periodCaption(d.period);
    const s = d.summary, ch = d.checks;
    const html = `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('دریافتی از مشتریان', fmtCompact(s.receipts), s.refunds ? 'پس‌داده: ' + fmtCompact(s.refunds) : '', '', moneyTitle(s.receipts))}
        ${stat('نسبت وصول به فروش', fmtPct(s.collection_ratio), 'دریافتی ÷ فروش همین بازه', s.collection_ratio >= 90 ? 'good' : s.collection_ratio < 70 ? 'bad' : 'warn')}
        ${stat('مانده فاکتورهای این بازه', fmtCompact(s.period_remaining), 'پرداخت‌نشده روی خود فاکتورها (بدون علی‌الحساب)', s.period_remaining > 0 ? 'warn' : 'good', moneyTitle(s.period_remaining))}
        ${stat('کل مطالبات (امروز)', fmtCompact(s.total_receivable), 'مانده حساب همه مشتریان', '', moneyTitle(s.total_receivable))}
        ${stat('دوره وصول مطالبات (DSO)', s.dso === null ? '—' : fmtNum(s.dso) + ' روز', 'مطالبات ÷ فروش روزانه ۹۰ روز اخیر', s.dso > 60 ? 'bad' : s.dso > 30 ? 'warn' : 'good')}
        ${stat('فاصله فروش تا دریافت', s.avg_days_to_pay === null ? '—' : fmtNum(s.avg_days_to_pay) + ' روز', 'میانگین وزنی بر اساس مبلغ')}
        ${stat('فاصله فروش تا پول نقد', s.avg_days_to_cash === null ? '—' : fmtNum(s.avg_days_to_cash) + ' روز', 'با احتساب سررسید چک‌ها', s.avg_days_to_cash > 45 ? 'warn' : '')}
        ${stat('نقد همان روز فروش', fmtPct(s.same_day_cash_share), 'سهم فروشی که همان روز نقد شد')}
        ${stat('چک‌های در دست', fmtCompact(s.checks_in_hand), fmtNum(s.checks_in_hand_n) + ' فقره', '', moneyTitle(s.checks_in_hand))}
        ${stat('چک سررسیدگذشته در دست', fmtCompact(s.checks_overdue), fmtNum(s.checks_overdue_n) + ' فقره — باید وصول یا خرج شوند', s.checks_overdue_n ? 'bad' : 'good', moneyTitle(s.checks_overdue))}
        ${stat('میانگین مدت چک‌ها', s.avg_check_tenor === null ? '—' : fmtNum(s.avg_check_tenor) + ' روز', 'از دریافت تا سررسید (وزنی)')}
        ${stat('نرخ برگشت چک', fmtPct(s.bounce_rate), 'از کل چک‌های دریافتی', s.bounce_rate > 3 ? 'bad' : 'good')}
      </div>${s.unlinked_receipts ? `<div class="sa-note">${fmtCompact(s.unlinked_receipts)} از دریافتی‌ها به فاکتور خاصی وصل نیستند (علی‌الحساب) و در محاسبه «فاصله فروش تا دریافت» نیامده‌اند.</div>` : ''}</div>

      <div class="sa-grid">
        ${card({ title: 'فروش در برابر دریافتی', icon: 'arrow-left-right', span: 8, sub: d.period.gran_label,
          help: 'ستون آبی فروش خالص هر دوره و ستون نارنجی پولی که در همان دوره از مشتریان گرفته شده (بابت هر فاکتوری). اگر نارنجی‌ها مدام کوتاه‌تر باشند، مطالبات در حال انباشته شدن است.',
          body: chartBox('co-trend', 'h-lg') })}
        ${card({ title: 'روش دریافت', icon: 'credit-card', span: 4, help: 'سهم نقد/واریز در برابر چک از دریافتی‌های این بازه.',
          body: chartBox('co-mix', 'h-sm') + `<div class="sa-stats" style="margin-top:8px">${d.mix.filter((m) => m.amount > 0).map((m) => stat(m.label, fmtCompact(m.amount), fmtPct(m.share), '', moneyTitle(m.amount))).join('')}</div>` })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'وضعیت تسویه فاکتورهای این بازه', icon: 'receipt', span: 4,
          body: `<div class="sa-stats">${d.invoice_status.map((x) => stat(x.label, fmtNum(x.n) + ' فاکتور', fmtCompact(x.amount), x.key === 'paid' ? 'good' : x.key === 'unpaid' ? 'bad' : 'warn', moneyTitle(x.amount))).join('')}</div>` })}
        ${card({ title: 'سن مطالبات', icon: 'hourglass-split', span: 8, sub: 'مانده فاکتورهای باز تا امروز، بر اساس عمر فاکتور',
          help: 'هرچه مطالبه قدیمی‌تر باشد احتمال وصولش کمتر است. مطالبات بالای ۹۰ روز را جدی پیگیری کنید. (این عدد فقط فاکتورهای باز است؛ «کل مطالبات» در بالا پرداخت‌های علی‌الحساب و مانده اول دوره را هم حساب می‌کند.)',
          body: chartBox('co-aging') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'بدهکاران بر اساس سن بدهی', icon: 'person-exclamation', span: 7, body: table('collections', 'سن مطالبات به تفکیک مشتری',
          [{ key: 'name', label: 'مشتری', type: 'text', wrap: true }].concat(d.aging.labels.map((l, i) => ({ key: 'v' + i, label: l, type: 'money', sortVal: (r) => r.values[i], html: (r) => r.values[i] ? `<span title="${esc(moneyTitle(r.values[i]))}">${fmtCompact(r.values[i])}</span>` : '<span class="muted">—</span>', csv: (r) => r.values[i], foot: (rows) => fmtCompact(rows.reduce((a, r) => a + (r.values[i] || 0), 0)) }))).concat([{ key: 'total', label: 'جمع', type: 'money' }, { key: 'invoices', label: 'فاکتور', type: 'int' }]),
          d.aging_persons, { sort: 'total', limit: 15 }) })}
        ${card({ title: 'قدیمی‌ترین فاکتورهای باز', icon: 'clock-history', span: 5, body: table('collections', 'قدیمی‌ترین فاکتورهای باز', [
          { key: 'customer', label: 'مشتری', type: 'text', wrap: true }, { key: 'invoice', label: 'فاکتور', type: 'text' }, { key: 'date', label: 'تاریخ', type: 'text' },
          { key: 'days', label: 'روز', type: 'int' }, { key: 'remaining', label: 'مانده', type: 'money' }], d.oldest_invoices, { search: false }) })}
      </div>

      <div class="sa-heading"><i class="bi bi-journal-check"></i>چک‌های دریافتی از مشتریان</div>
      <div class="sa-grid">
        ${card({ title: 'تقویم سررسید چک‌های در دست', icon: 'calendar-event', span: 7, sub: '۶ ماه آینده',
          help: 'چک‌های «در دست» (نه وصول‌شده، نه خرج‌شده) بر اساس ماه سررسید. برای برنامه‌ریزی نقدینگی و پرداخت به تأمین‌کنندگان استفاده کنید.',
          body: chartBox('co-maturity') + (ch.later.count ? `<div class="sa-note">بعد از این ۶ ماه: ${fmtNum(ch.later.count)} فقره به مبلغ ${fmtCompact(ch.later.amount)}</div>` : '') })}
        ${card({ title: 'وضعیت همه چک‌های دریافتی', icon: 'list-check', span: 5, body: table('collections', 'وضعیت چک‌های دریافتی', [{ key: 'label', label: 'وضعیت', type: 'text' }, { key: 'n', label: 'تعداد', type: 'int' }, { key: 'amount', label: 'مبلغ', type: 'money' }], ch.status, { search: false }) })}
      </div>
      <div class="sa-grid">
        ${card({ title: 'تمرکز بانکی چک‌های در دست', icon: 'bank', span: 6, help: 'اگر بخش بزرگی از چک‌ها مال یک بانک باشد، مشکل آن بانک روی نقدینگی شما اثر مستقیم دارد.',
          body: table('collections', 'چک‌های در دست به تفکیک بانک', [{ key: 'label', label: 'بانک', type: 'text' }, { key: 'amount', label: 'مبلغ', type: 'money' }, { key: 'share', label: 'سهم', type: 'share' }], ch.banks, { search: false, limit: 10 }) })}
        ${card({ title: 'بزرگ‌ترین صادرکنندگان چک', icon: 'person-badge', span: 6, help: 'چه کسانی بیشترین مبلغ چک در دست شما را صادر کرده‌اند — ریسک اعتباری متمرکز.',
          body: table('collections', 'صادرکنندگان چک‌های در دست', [{ key: 'label', label: 'صادرکننده', type: 'text' }, { key: 'amount', label: 'مبلغ', type: 'money' }, { key: 'share', label: 'سهم', type: 'share' }], ch.issuers, { search: false, limit: 10 }) })}
      </div>
      ${(d.online.length || d.installments.overdue_n || d.installments.upcoming_n) ? `<div class="sa-grid">
        ${d.online.length ? card({ title: 'پرداخت آنلاین', icon: 'globe', span: 6, body: `<div class="sa-stats">${d.online.map((o) => stat({ paid: 'موفق', failed: 'ناموفق', pending: 'در انتظار' }[o.status] || esc(o.status), fmtNum(o.n) + ' تراکنش', fmtCompact(o.amount), o.status === 'paid' ? 'good' : o.status === 'failed' ? 'bad' : '')).join('')}</div>` }) : ''}
        ${(d.installments.overdue_n || d.installments.upcoming_n) ? card({ title: 'اقساط فروش', icon: 'calendar2-week', span: 6, body: `<div class="sa-stats">${stat('اقساط معوق', fmtNum(d.installments.overdue_n), fmtCompact(d.installments.overdue_amt), d.installments.overdue_n ? 'bad' : 'good')}${stat('اقساط آینده', fmtNum(d.installments.upcoming_n), fmtCompact(d.installments.upcoming_amt))}</div>` }) : ''}
      </div>` : ''}`;

    return {
      html,
      after() {
        const p = pal();
        mount('collections', 'co-trend', { type: 'bar', data: { labels: faLabels(d.trend.labels), datasets: [barDs('فروش خالص', d.trend.sales, p.s[0]), barDs('دریافتی', d.trend.receipts, p.s[1])] }, options: opts({ fmt: 'money', titles: d.trend.titles, legend: true }) });
        const mix = d.mix.filter((m) => m.amount > 0);
        mount('collections', 'co-mix', { type: 'doughnut', data: { labels: mix.map((m) => m.label), datasets: [{ data: mix.map((m) => m.amount), backgroundColor: mix.map((_, i) => p.s[i]), borderColor: p.surface, borderWidth: 2 }] },
          options: { cutout: '68%', plugins: { legend: { display: true, position: 'bottom', rtl: true, labels: { color: p.ink2, usePointStyle: true, boxWidth: 8 } }, tooltip: { rtl: true, callbacks: { label: (ctx) => ' ' + ctx.label + ': ' + fa(fmtMoney(ctx.parsed)) + ' ' + CUR } } } } });
        // Aging: ordered buckets → one-hue ordinal ramp (older = darker)
        const ramp = ['--seq-2', '--seq-3', '--seq-4', '--seq-5', '--seq-6'].map(cssVar);
        mount('collections', 'co-aging', { type: 'bar', data: { labels: d.aging.labels, datasets: [barDs('مانده', d.aging.values, d.aging.values.map((_, i) => ramp[Math.min(i, ramp.length - 1)]))] }, options: opts({ fmt: 'money' }) });
        const mo = opts({ fmt: 'money' });
        mo.plugins.tooltip.callbacks.footer = (items) => fa(ch.maturity[items[0].dataIndex].count + ' فقره چک');
        mount('collections', 'co-maturity', { type: 'bar', data: { labels: ch.maturity.map((m) => fa(m.label)), datasets: [barDs('مبلغ سررسید', ch.maturity.map((m) => m.amount), p.s[0])] }, options: mo });
      },
    };
  };

  /* ---------------- profitability ---------------- */
  RENDER.profit = (d) => {
    periodCaption(d.period);
    const s = d.summary, c = d.compare_summary, be = d.break_even, dc = d.discount, q = d.quality;
    const delta = (k, pts) => (c && state.compare !== 'none') ? deltaChip(pts ? (s[k] ?? 0) - (c[k] ?? 0) : (c[k] ? (s[k] - c[k]) / Math.abs(c[k]) * 100 : null), true, pts) : '';
    const qualityNote = (q.estimated_lines || q.zero_cost_lines) ? `<div class="sa-insight warn" style="margin-bottom:12px"><i class="bi bi-exclamation-triangle"></i><div>دقت سود: ${q.estimated_lines ? `${fmtNum(q.estimated_lines)} ردیف فروش (به ارزش ${fmtCompact(q.estimated_value)}) به کالای انبار وصل نیستند و بهای تمام‌شده‌شان از آخرین قیمت خرید تخمین زده شد. ` : ''}${q.zero_cost_lines ? `${fmtNum(q.zero_cost_lines)} ردیف کالا (به ارزش ${fmtCompact(q.zero_cost_value)}) بهای تمام‌شده صفر دارند و سودشان بیش از واقع نشان داده می‌شود.` : ''}</div></div>` : '';

    const html = qualityNote + `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('فروش خالص', fmtCompact(s.net_sales) + ' ' + delta('net_sales'), '', '', moneyTitle(s.net_sales))}
        ${stat('بهای تمام‌شده کالا', fmtCompact(s.cogs), q.return_cogs ? 'پس از برگشت ' + fmtCompact(q.return_cogs) + ' بابت مرجوعی' : '', '', moneyTitle(s.cogs))}
        ${stat('سود ناخالص', fmtCompact(s.gross_profit) + ' ' + delta('gross_profit'), '', s.gross_profit >= 0 ? 'good' : 'bad', moneyTitle(s.gross_profit))}
        ${stat('حاشیه سود ناخالص', fmtPct(s.gross_margin) + ' ' + delta('gross_margin', true))}
        ${stat('هزینه‌های جاری', fmtCompact(s.expenses), s.net_sales ? fmtPct(s.expenses / s.net_sales * 100) + ' فروش' : '', '', moneyTitle(s.expenses))}
        ${stat('سود عملیاتی تقریبی', fmtCompact(s.operating_profit) + ' ' + delta('operating_profit'), s.net_sales ? 'حاشیه خالص ' + fmtPct(s.operating_profit / s.net_sales * 100) : '', s.operating_profit >= 0 ? 'good' : 'bad', moneyTitle(s.operating_profit))}
        ${stat('سود هر فاکتور', s.invoices ? fmtCompact(s.gross_profit / s.invoices) : '—', 'سود ناخالص ÷ تعداد فاکتور')}
        ${stat('سود هر متر فرش', s.area ? fmtCompact(s.gross_profit / s.area) : '—', 'سود ناخالص ÷ متراژ')}
      </div></div>

      <div class="sa-grid">
        ${card({ title: 'فروش، سود و هزینه', icon: 'graph-up', span: 8, sub: d.period.gran_label,
          help: 'فروش خالص، سود ناخالص (پس از کسر بهای کالا) و سود عملیاتی (پس از کسر هزینه‌ها) در هر دوره. ماه‌هایی که سود عملیاتی منفی است، فروش کفاف هزینه‌های ثابت را نداده.',
          body: chartBox('pf-trend', 'h-lg') })}
        ${card({ title: 'روند حاشیه سود', icon: 'percent', span: 4, help: 'حاشیه سود ناخالص در هر دوره. افت مداوم آن معمولاً یعنی گران شدن خرید بدون افزایش قیمت فروش، یا تخفیف‌های بیشتر.',
          body: chartBox('pf-margin', 'h-lg') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'نقطه سر به سر', icon: 'bullseye', span: 4,
          help: 'حداقل فروش ماهانه‌ای که لازم است تا هزینه‌های جاری پوشش داده شود = میانگین هزینه ماهانه ÷ حاشیه سود ناخالص. «حاشیه ایمنی» یعنی فروش فعلی چند درصد بالاتر از این حداقل است.',
          body: `<div class="sa-stats">
            ${stat('میانگین هزینه ماهانه', fmtCompact(be.monthly_expenses), '', '', moneyTitle(be.monthly_expenses))}
            ${stat('فروش لازم برای سر به سر', fmtCompact(be.break_even_sales), 'در ماه', '', moneyTitle(be.break_even_sales))}
            ${stat('میانگین فروش ماهانه', fmtCompact(be.monthly_sales), '', '', moneyTitle(be.monthly_sales))}
            ${stat('حاشیه ایمنی', fmtPct(be.safety_margin), 'فروش فعلی بالاتر از سر به سر', be.safety_margin === null ? '' : be.safety_margin > 20 ? 'good' : be.safety_margin > 0 ? 'warn' : 'bad')}
          </div>` })}
        ${card({ title: 'هزینه‌ها به تفکیک', icon: 'wallet', span: 8,
          help: '«درصد از فروش» نشان می‌دهد هر دسته هزینه چند درصد از فروش را می‌خورد؛ «به ازای هر فاکتور» هزینه خدمت‌رسانی به هر فروش است (مثلاً حمل‌ونقل به ازای هر فاکتور).',
          body: d.expense_categories.length ? table('profit', 'هزینه‌ها به تفکیک دسته', [
            { key: 'label', label: 'دسته هزینه', type: 'text', wrap: true }, { key: 'amount', label: 'مبلغ', type: 'money' },
            { key: 'of_sales', label: 'درصد از فروش', type: 'pct', html: (r) => r.of_sales === null ? '—' : fmtNum(r.of_sales, 2) + '٪' },
            { key: 'per_invoice', label: 'به ازای هر فاکتور', type: 'money' }, { key: 'cmp', label: 'دوره مقایسه', type: 'money' },
            { key: 'delta', label: 'تغییر', type: 'pct', html: (r) => deltaChip(r.delta, false) },
          ], d.expense_categories, { search: false, sort: 'amount', limit: 12 }) : empty('هزینه‌ای در این بازه ثبت نشده است.') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'تخفیف چقدر از سود را می‌خورد؟', icon: 'tag', span: 7,
          help: 'فاکتورها بر اساس درصد تخفیف دسته‌بندی شده‌اند و حاشیه سود هر دسته کنارش آمده. اگر حاشیه دسته‌های پرتخفیف خیلی پایین است، سیاست تخفیف را بازبینی کنید.',
          body: `<div class="sa-stats" style="margin-bottom:10px">
              ${stat('جمع تخفیف‌ها', fmtCompact(dc.total), '', '', moneyTitle(dc.total))}
              ${stat('فاکتورهای دارای تخفیف', fmtPct(dc.share_with_discount), fmtNum(dc.invoices_with_discount) + ' فاکتور')}
              ${stat('میانگین تخفیف وقتی داده شده', fmtPct(dc.avg_when_given))}
              ${stat('سود ناخالص بدون تخفیف', fmtCompact(dc.profit_if_no_discount), 'اگر هیچ تخفیفی داده نمی‌شد', '', moneyTitle(dc.profit_if_no_discount))}
            </div>` + table('profit', 'تخفیف و حاشیه سود', [
              { key: 'label', label: 'تخفیف فاکتور', type: 'text' }, { key: 'n', label: 'فاکتور', type: 'int' }, { key: 'net', label: 'فروش', type: 'money' },
              { key: 'disc', label: 'تخفیف', type: 'money' }, { key: 'profit', label: 'سود', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }], dc.bands, { search: false }) })}
        ${card({ title: 'حاشیه سود بر اساس میزان تخفیف', icon: 'bar-chart', span: 5, body: chartBox('pf-disc') })}
      </div>

      <div class="sa-grid">${card({ title: 'فروش‌های زیان‌ده و کم‌سود', icon: 'emoji-frown', span: 12, sub: `${fmtNum(d.loss_count)} ردیف زیر قیمت خرید · ردیف‌هایی با حاشیه کمتر از ۵٪`,
        help: 'ردیف‌هایی از فاکتورها که با حاشیه کمتر از ۵٪ یا حتی زیر قیمت خرید فروخته شده‌اند (پس از سهم تخفیف فاکتور). شاید اشتباه قیمت‌گذاری، شاید حراج آگاهانه — ولی باید بدانید.',
        body: d.loss_lines.length ? table('profit', 'فروش‌های زیان‌ده و کم‌سود', [
          { key: 'date', label: 'تاریخ', type: 'text' }, { key: 'invoice', label: 'فاکتور', type: 'text' }, { key: 'product', label: 'محصول', type: 'text', wrap: true },
          { key: 'dimensions', label: 'ابعاد', type: 'text' }, { key: 'net', label: 'فروش', type: 'money' }, { key: 'cost', label: 'بهای خرید', type: 'money' },
          { key: 'profit', label: 'سود', type: 'money', html: (r) => `<span style="color:${r.profit < 0 ? 'var(--bad-ink)' : 'inherit'}">${fmtCompact(r.profit)}</span>` },
          { key: 'margin', label: 'حاشیه', type: 'pct', html: (r) => fmtPct(r.margin) + (r.estimated ? ' <i class="bi bi-asterisk muted" title="بهای تخمینی"></i>' : '') },
        ], d.loss_lines, { sort: 'profit', dir: 'asc' }) : empty('هیچ فروش زیان‌ده یا کم‌سودی پیدا نشد. 👌') })}</div>

      <div class="sa-grid">
        ${card({ title: 'کم‌سودترین مشتریان', icon: 'person-dash', span: 6, help: 'مشتریانی که خرید از شما با کمترین حاشیه سود (معمولاً به‌خاطر تخفیف زیاد) بوده است.',
          body: table('profit', 'کم‌سودترین مشتریان', [{ key: 'name', label: 'مشتری', type: 'text', wrap: true }, { key: 'net', label: 'خرید', type: 'money' }, { key: 'profit', label: 'سود', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }, { key: 'discount_pct', label: 'تخفیف', type: 'pct' }], d.low_margin_customers, { search: false }) })}
        ${card({ title: 'پرسودترین مشتریان (درصدی)', icon: 'person-check', span: 6,
          body: table('profit', 'پرسودترین مشتریان', [{ key: 'name', label: 'مشتری', type: 'text', wrap: true }, { key: 'net', label: 'خرید', type: 'money' }, { key: 'profit', label: 'سود', type: 'money' }, { key: 'margin', label: 'حاشیه', type: 'pct' }, { key: 'discount_pct', label: 'تخفیف', type: 'pct' }], d.high_margin_customers, { search: false }) })}
      </div>`;

    return {
      html,
      after() {
        const p = pal(), tr = d.trend;
        mount('profit', 'pf-trend', { data: { labels: faLabels(tr.labels), datasets: [
          barDs('فروش خالص', tr.net, alpha(p.s[0], 0.85)),
          barDs('سود ناخالص', tr.profit, p.s[2]),
          lineDs('سود عملیاتی', tr.operating, p.s[1], { pointRadius: 3 }),
        ] }, options: opts({ fmt: 'money', titles: tr.titles, legend: true }) });
        mount('profit', 'pf-margin', { data: { labels: faLabels(tr.labels), datasets: [lineDs('حاشیه سود ناخالص', tr.margin, p.s[0], { pointRadius: 3, fill: 'origin', backgroundColor: alpha(p.s[0], 0.08) })] }, options: opts({ fmt: 'pct', titles: tr.titles }) });
        mount('profit', 'pf-disc', { type: 'bar', data: { labels: dc.bands.map((b) => b.label), datasets: [barDs('حاشیه سود', dc.bands.map((b) => b.margin), p.s[0])] }, options: opts({ fmt: 'pct' }) });
      },
    };
  };

  /* ---------------- inventory ---------------- */
  RENDER.inventory = (d) => {
    periodCaption(d.period);
    const s = d.summary, mode = viewOf('inventory', 'aging', 'value');
    const cs = d.cost_sources;
    const sig = { shortage: badge('کمبود', 'bad'), low: badge('کم', 'warn'), excess: badge('مازاد', 'info'), ok: badge('متعادل', 'good') };
    const cols = (state.meta && state.meta.collections) || [];
    const html = `
      <div class="sa-card" style="margin-bottom:12px"><div class="sa-stats">
        ${stat('اقلام موجود', fmtNum(s.count), fmtNum(s.products) + ' محصول · ' + fmtNum(s.area, 1) + ' متر مربع')}
        ${stat('ارزش موجودی (قیمت خرید)', fmtCompact(s.value), 'شامل کالای امانی', '', moneyTitle(s.value))}
        ${stat('ارزش موجودی متعلق به شما', fmtCompact(s.own_value), 'بدون کالای امانی', '', moneyTitle(s.own_value))}
        ${stat('کالای امانی نزد شما', fmtNum(s.consignment_in_count) + ' قلم', fmtCompact(s.consignment_in_value))}
        ${stat('کالای امانی نزد دیگران', fmtNum(s.consignment_out_count) + ' قلم', fmtCompact(s.consignment_out_value))}
        ${stat('میانگین سن موجودی', s.avg_age === null ? '—' : fmtNum(s.avg_age) + ' روز', 'از تاریخ خرید', s.avg_age > 180 ? 'warn' : '')}
        ${stat('پوشش موجودی', s.coverage_days === null ? '—' : fmtNum(s.coverage_days) + ' روز', 'با سرعت فروش ۹۰ روز اخیر', s.coverage_days > 240 ? 'warn' : '')}
        ${stat('گردش سالانه موجودی', s.turnover === null ? '—' : fmtNum(s.turnover, 1) + ' بار', 'چند بار در سال انبار خالی و پر می‌شود')}
        ${stat('سرمایه خوابیده', fmtCompact(d.dead.value), fmtNum(d.dead.count) + ' قلم بی‌فروش بالای ۶ ماه', d.dead.count ? 'bad' : 'good', moneyTitle(d.dead.value))}
      </div>${cs.last_purchase || cs.unknown ? `<div class="sa-note">ارزش ${fmtNum(cs.last_purchase)} قلم از آخرین قیمت خرید همان محصول تخمین زده شده${cs.unknown ? ` و ${fmtNum(cs.unknown)} قلم قیمت خرید ندارند (صفر حساب شده‌اند)` : ''}.</div>` : ''}</div>

      <div class="sa-grid">
        ${card({ title: 'سن موجودی', icon: 'hourglass', span: 6, help: 'کالاها بر اساس مدتی که در انبار مانده‌اند (از تاریخ فاکتور خرید). کالای بالای ۶ ماه سرمایه را قفل می‌کند و کاندید حراج یا تخفیف است.',
          tools: segToggle('inventory.aging', [['value', 'ارزش'], ['count', 'تعداد']], mode), body: chartBox('in-aging') })}
        ${card({ title: 'سایزها: موجودی در برابر تقاضا', icon: 'aspect-ratio', span: 6,
          help: 'برای هر سایز، فروش بازه انتخابی را با موجودی فعلی مقایسه می‌کند. «کمبود» یعنی فروش داشته ولی الان موجود نیست؛ «کم» یعنی فروش بیش از ۱٫۵ برابر موجودی است؛ «مازاد» یعنی موجودی زیاد بدون فروش. این‌جا معلوم می‌شود چه سایزی را باید سفارش داد.',
          body: table('inventory', 'موجودی در برابر تقاضای سایزها', [
            { key: 'label', label: 'سایز', type: 'text' }, { key: 'sold', label: 'فروش بازه', type: 'int' }, { key: 'stock', label: 'موجودی', type: 'int' },
            { key: 'ratio', label: 'نسبت', type: 'dec' }, { key: 'signal', label: 'وضعیت', type: 'text', html: (r) => sig[r.signal], csv: (r) => ({ shortage: 'کمبود', low: 'کم', excess: 'مازاد', ok: 'متعادل' }[r.signal]) }], d.sizes, { search: false, limit: 12 }) })}
      </div>

      <div class="sa-grid">${card({ title: 'موجودی به تفکیک کلکسیون', icon: 'collection', span: 12,
        help: '«ماه‌های موجودی» = موجودی فعلی ÷ میانگین فروش ماهانه ۹۰ روز اخیر آن کلکسیون؛ یعنی با سرعت فعلی، این موجودی چند ماه دیگر تمام می‌شود. عدد خیلی کم یعنی خطر کمبود، عدد خیلی زیاد یعنی سرمایه راکد.',
        body: table('inventory', 'موجودی به تفکیک کلکسیون', [
          { key: 'label', label: 'کلکسیون', type: 'text', wrap: true }, { key: 'count', label: 'تعداد', type: 'int' }, { key: 'area', label: 'متراژ', type: 'dec' },
          { key: 'value', label: 'ارزش', type: 'money' }, { key: 'share', label: 'سهم از ارزش', type: 'share' }, { key: 'avg_age', label: 'میانگین سن (روز)', type: 'days' },
          { key: 'sold_90', label: 'فروش ۹۰ روز', type: 'int' },
          { key: 'months_of_stock', label: 'ماه‌های موجودی', type: 'dec', html: (r) => r.months_of_stock === null ? badge('بی‌فروش', 'warn') : `<span style="color:${r.months_of_stock < 1 ? 'var(--bad-ink)' : r.months_of_stock > 12 ? 'var(--warn-ink)' : 'inherit'}">${fmtNum(r.months_of_stock, 1)}</span>` },
        ], d.collections, { sort: 'value' }) })}</div>

      <div class="sa-grid">
        ${card({ title: 'سرمایه خوابیده (بی‌فروش بیش از ۶ ماه)', icon: 'moon-stars', span: 8,
          help: 'کالاهایی که بیش از ۱۸۰ روز در انبارند و محصولشان در ۱۸۰ روز اخیر حتی یک فروش هم نداشته. به ترتیب ارزش. پیشنهاد: حراج، تخفیف ویژه، یا عرضه در کانال دیگر.',
          body: table('inventory', 'سرمایه خوابیده', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'collection', label: 'کلکسیون', type: 'text' }, { key: 'sizes', label: 'ابعاد', type: 'text', wrap: true }, { key: 'count', label: 'تعداد', type: 'int' }, { key: 'value', label: 'ارزش', type: 'money' }, { key: 'oldest', label: 'قدیمی‌ترین (روز)', type: 'days' }], d.dead.rows, { sort: 'value', limit: 15 }) })}
        ${card({ title: 'انبارها', icon: 'buildings', span: 4, body: table('inventory', 'موجودی انبارها', [{ key: 'label', label: 'انبار', type: 'text' }, { key: 'count', label: 'تعداد', type: 'int' }, { key: 'value', label: 'ارزش', type: 'money' }], d.warehouses, { search: false }) +
          (d.reorder.length ? `<div class="sa-heading" style="margin-top:14px"><i class="bi bi-bell"></i>هشدار سفارش مجدد</div>` + table('inventory', 'هشدار سفارش مجدد', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'stock', label: 'موجودی', type: 'int' }, { key: 'threshold', label: 'حد سفارش', type: 'int' }], d.reorder, { search: false }) : '') })}
      </div>

      <div class="sa-grid">${card({ title: 'جستجو و محاسبه ارزش موجودی', icon: 'search', span: 12,
        help: 'ارزش موجودی را برای یک کلکسیون یا با جستجوی نام، کد طرح یا ابعاد حساب کنید. ارزش‌گذاری به قیمت خرید است.',
        body: `<div class="sa-subfilters" style="margin-bottom:10px">
            <div class="sa-field"><label>کلکسیون</label><select class="form-select form-select-sm" id="inv-col"><option value="">همه</option>${cols.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
            <div class="sa-field"><label>کلمه کلیدی</label><input class="form-control form-control-sm" id="inv-kw" placeholder="نام، کد طرح، ابعاد…"></div>
            <div class="form-check" style="margin-bottom:6px"><input class="form-check-input" type="checkbox" id="inv-cons" checked><label class="form-check-label" for="inv-cons" style="font-size:.82rem">شامل کالای امانی</label></div>
            <button class="btn btn-primary btn-sm" id="inv-go" style="margin-bottom:2px"><i class="bi bi-calculator"></i> محاسبه</button>
          </div><div id="inv-result"></div>` })}</div>`;

    return {
      html,
      after() {
        const p = pal();
        const ramp = ['--seq-2', '--seq-3', '--seq-4', '--seq-5', '--seq-6'].map(cssVar);
        mount('inventory', 'in-aging', { type: 'bar', data: { labels: d.aging.map((a) => a.label), datasets: [barDs(mode === 'value' ? 'ارزش' : 'تعداد', d.aging.map((a) => mode === 'value' ? a.value : a.count), d.aging.map((_, i) => ramp[i]))] }, options: opts({ fmt: mode === 'value' ? 'money' : 'int' }) });
      },
    };
  };

  async function runInventoryExplorer() {
    const out = document.getElementById('inv-result');
    if (!out) return;
    out.innerHTML = '<div class="sa-empty"><span class="spinner-border spinner-border-sm"></span> در حال محاسبه…</div>';
    try {
      const data = await fetchJSON({ action: 'inventory_items', collectionId: document.getElementById('inv-col').value, keyword: document.getElementById('inv-kw').value, includeConsignment: document.getElementById('inv-cons').checked ? '1' : '0' });
      Object.keys(tables).forEach((k) => { if (tables[k].title === 'اقلام موجودی') delete tables[k]; });
      const src = { purchase: 'فاکتور خرید', cost_price: 'بهای ثبت‌شده', last_purchase: 'آخرین خرید (تخمینی)', unknown: 'نامشخص' };
      out.innerHTML = `<div class="sa-stats" style="margin-bottom:10px">${stat('ارزش کل', fmtCompact(data.total_value), fmtMoney(data.total_value) + ' ' + CUR)}${stat('تعداد اقلام', fmtNum(data.item_count))}${stat('میانگین ارزش هر قلم', data.item_count ? fmtCompact(data.total_value / data.item_count) : '—')}</div>` +
        table('inventory', 'اقلام موجودی', [
          { key: 'id', label: 'شناسه', type: 'text' }, { key: 'product_name', label: 'محصول', type: 'text', wrap: true }, { key: 'design_code', label: 'کد طرح', type: 'text' },
          { key: 'collection', label: 'کلکسیون', type: 'text' }, { key: 'dimensions', label: 'ابعاد', type: 'text' }, { key: 'warehouse', label: 'انبار', type: 'text' },
          { key: 'age_days', label: 'سن (روز)', type: 'int' }, { key: 'cost', label: 'قیمت خرید', type: 'moneyfull' },
          { key: 'cost_source', label: 'مبنای قیمت', type: 'text', html: (r) => esc(src[r.cost_source] || r.cost_source), csv: (r) => src[r.cost_source] || r.cost_source },
          { key: 'is_consignment', label: 'امانی', type: 'text', html: (r) => r.is_consignment ? badge('امانی', 'warn') : '', csv: (r) => r.is_consignment ? 'بله' : '' },
        ], data.items, { limit: 50 });
    } catch (e) {
      out.innerHTML = `<div class="sa-error">${esc(e.message)}</div>`;
    }
  }

  /* ---------------- operations ---------------- */
  RENDER.operations = (d) => {
    periodCaption(d.period);
    const q = d.quotes, r = d.returns;
    const lvl = { bad: 'bad', warn: 'warn', info: 'info' };
    const html = `
      <div class="sa-grid">
        ${card({ title: 'سلامت داده‌ها', icon: 'shield-check', span: 5,
          help: 'گزارش‌ها فقط به اندازه داده‌ها دقیق‌اند. این فهرست مواردی را نشان می‌دهد که روی دقت سود، متراژ و تحلیل مشتری اثر می‌گذارند. صفر یعنی همه‌چیز مرتب است.',
          body: `<div class="sa-insights">${d.health.map((h) => `<div class="sa-insight ${h.n ? lvl[h.level] : 'good'}"><i class="bi bi-${h.n ? (h.level === 'bad' ? 'x-circle' : h.level === 'warn' ? 'exclamation-triangle' : 'info-circle') : 'check-circle'}"></i><div style="flex:1">${h.label}</div><b>${fmtNum(h.n)}</b></div>`).join('')}</div>` })}
        ${card({ title: 'وضعیت تحویل', icon: 'truck', span: 7, sub: `${fmtNum(d.pending_count)} فاکتور در انتظار تحویل (کل سیستم) به ارزش ${fmtCompact(d.pending_value)}`,
          help: 'فاکتورهایی که هنوز «در حال آماده‌سازی» یا «آماده تحویل» هستند، از قدیمی‌ترین. فروش تحویل‌نشده یعنی مشتری منتظر است و ریسک لغو وجود دارد.',
          body: `<div class="sa-stats" style="margin-bottom:10px">${d.delivery.map((x) => stat(x.label, fmtNum(x.n) + ' فاکتور', fmtCompact(x.amount))).join('')}</div>` +
            table('operations', 'فاکتورهای در انتظار تحویل', [{ key: 'id', label: 'فاکتور', type: 'text' }, { key: 'customer', label: 'مشتری', type: 'text', wrap: true }, { key: 'date', label: 'تاریخ', type: 'text' }, { key: 'status', label: 'وضعیت', type: 'text' }, { key: 'days', label: 'روز انتظار', type: 'int', html: (x) => `<span style="color:${x.days > 14 ? 'var(--bad-ink)' : 'inherit'}">${fmtNum(x.days)}</span>` }, { key: 'net', label: 'مبلغ', type: 'money' }], d.pending, { search: false, limit: 10 }) })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'توزیع مبلغ فاکتورها', icon: 'distribute-vertical', span: 7, help: 'چند فاکتور در هر بازه مبلغ قرار دارند. مرزها خودکار از داده‌های شما انتخاب می‌شوند. نشان می‌دهد فروش شما بیشتر از خریدهای کوچک می‌آید یا از چند خرید بزرگ.',
          body: d.invoice_sizes.length ? chartBox('op-sizes') : empty() })}
        ${card({ title: 'تعداد قلم در هر فاکتور', icon: 'basket', span: 5, help: 'اکثر مشتری‌ها یک قلم می‌خرند یا چند قلم؟ بالا بردن سهم فاکتورهای چندقلمی ساده‌ترین راه افزایش میانگین فاکتور است.',
          body: chartBox('op-ipi') })}
      </div>

      <div class="sa-grid">
        ${card({ title: 'پیش‌فاکتورها (قیف فروش)', icon: 'funnel', span: 5, help: 'چند پیش‌فاکتور صادر شده و چند درصدشان به فاکتور قطعی تبدیل شده است.',
          body: q.total ? `<div class="sa-stats">
              ${stat('پیش‌فاکتور صادرشده', fmtNum(q.total), fmtCompact(q.value))}
              ${stat('تبدیل‌شده به فروش', fmtNum(q.converted), fmtCompact(q.converted_value), 'good')}
              ${stat('نرخ تبدیل', fmtPct(q.conversion), '', q.conversion >= 40 ? 'good' : 'warn')}
              ${stat('میانگین زمان تبدیل', q.days_to_convert === null ? '—' : fmtNum(q.days_to_convert) + ' روز')}
              ${stat('در انتظار', fmtNum(q.pending || 0))}
              ${stat('منقضی / لغو', fmtNum((q.expired || 0) + (q.cancelled || 0)), '', 'bad')}
            </div>` : empty('در این بازه پیش‌فاکتوری صادر نشده است.') })}
        ${card({ title: 'مرجوعی‌ها', icon: 'arrow-return-left', span: 7,
          body: `<div class="sa-stats" style="margin-bottom:10px">
              ${stat('تعداد مرجوعی', fmtNum(r.count))}
              ${stat('مبلغ مرجوعی', fmtCompact(r.amount), '', '', moneyTitle(r.amount))}
              ${stat('نرخ مرجوعی', fmtPct(r.rate), 'از فروش', r.rate > 5 ? 'bad' : 'good')}
              ${stat('فاصله فروش تا مرجوعی', r.avg_days === null ? '—' : fmtNum(r.avg_days) + ' روز', 'میانگین')}
            </div>` + (r.products.length ? table('operations', 'محصولات پرمرجوعی', [{ key: 'name', label: 'محصول', type: 'text', wrap: true }, { key: 'qty', label: 'تعداد', type: 'int' }, { key: 'amount', label: 'مبلغ', type: 'money' }], r.products, { search: false, limit: 6 }) : '') })}
      </div>

      ${r.list.length ? `<div class="sa-grid">${card({ title: 'آخرین مرجوعی‌ها', icon: 'list-ul', span: 12, body: table('operations', 'آخرین مرجوعی‌ها', [{ key: 'date', label: 'تاریخ', type: 'text' }, { key: 'customer', label: 'مشتری', type: 'text', wrap: true }, { key: 'amount', label: 'مبلغ', type: 'money' }, { key: 'days', label: 'روز پس از خرید', type: 'days' }, { key: 'description', label: 'توضیح', type: 'text', wrap: true }], r.list, { search: false }) })}</div>` : ''}

      <div class="sa-grid">
        ${card({ title: 'کانال‌های فروش', icon: 'diagram-3', span: 4, help: 'فاکتورها از کجا ثبت شده‌اند: ثبت دستی یا از طریق اتصال‌ها (مثل فروشگاه اینترنتی).',
          body: table('operations', 'کانال‌های فروش', [{ key: 'label', label: 'کانال', type: 'text' }, { key: 'n', label: 'فاکتور', type: 'int' }, { key: 'net', label: 'فروش', type: 'money' }], d.channels, { search: false }) })}
        ${card({ title: 'فروش امانی باز', icon: 'box-arrow-up-right', span: 4, help: 'فاکتورهای امانی هنوز فروش قطعی محسوب نمی‌شوند و در هیچ‌کدام از اعداد فروش این صفحه نیستند.',
          body: `<div class="sa-stats">${stat('فاکتور امانی', fmtNum(d.consignment.open_invoices))}${stat('ارزش', fmtCompact(d.consignment.open_value), '', '', moneyTitle(d.consignment.open_value))}</div>` })}
        ${card({ title: 'مالیات بر ارزش افزوده', icon: 'bank2', span: 4, body: `<div class="sa-stats">${stat('مالیات فاکتورهای بازه', fmtCompact(d.tax.total), '', '', moneyTitle(d.tax.total))}${stat('فاکتورهای مشمول', fmtNum(d.tax.invoices_with_tax))}</div>` })}
      </div>`;
    return {
      html,
      after() {
        const p = pal();
        if (d.invoice_sizes.length) {
          const o = opts({ fmt: 'int' });
          o.plugins.tooltip.callbacks.footer = (items) => 'جمع: ' + fa(fmtCompact(d.invoice_sizes[items[0].dataIndex].net));
          mount('operations', 'op-sizes', { type: 'bar', data: { labels: d.invoice_sizes.map((x) => x.label), datasets: [barDs('تعداد فاکتور', d.invoice_sizes.map((x) => x.n), p.s[0])] }, options: o });
        }
        mount('operations', 'op-ipi', { type: 'bar', data: { labels: d.items_per_invoice.labels, datasets: [barDs('تعداد فاکتور', d.items_per_invoice.counts, p.s[0])] }, options: opts({ fmt: 'int' }) });
      },
    };
  };

  /* ---------------- guide ---------------- */
  function renderGuide() {
    const el = sectionEl('guide');
    if (el.dataset.ready) return;
    el.dataset.ready = '1';
    const items = [
      ['فروش خالص', HELP.net_sales],
      ['سود ناخالص و بهای تمام‌شده', 'بهای تمام‌شده دقیقاً قیمت خرید همان فرش‌هایی است که فروخته شده (از روی اتصال کالای انبار به فاکتور). اگر ردیفی به کالای انبار وصل نباشد، آخرین قیمت خرید همان محصول و سایز جایگزین می‌شود و در بخش سودآوری اعلام می‌شود. بهای کالاهای مرجوعی از بهای تمام‌شده کم می‌شود.'],
      ['حاشیه سود', HELP.gross_margin + ' مقایسه حاشیه‌ها به «واحد درصد» است: اگر حاشیه از ۳۰٪ به ۳۳٪ برسد یعنی ۳ واحد درصد بهتر شده.'],
      ['دوره مقایسه', '«دوره قبل» بازه‌ای با همان طول، درست قبل از بازه انتخابی است. «همین بازه در سال قبل» همان روزها در سال شمسی قبل است و برای کسب‌وکارهای فصلی مقایسه عادلانه‌تری است.'],
      ['میانگین متحرک', 'میانگین چند دوره اخیر (۷ روز، ۴ هفته یا ۳ ماه). نوسان‌های تصادفی را حذف می‌کند تا جهت واقعی روند دیده شود.'],
      ['تحلیل ABC', 'رده A: محصولاتی که روی هم ۸۰٪ فروش را می‌سازند؛ B: ۱۵٪ بعدی؛ C: ۵٪ آخر. رده A هیچ‌وقت نباید ناموجود شود؛ رده C کاندید حذف یا حراج است.'],
      ['نرخ فروش رفتن (Sell-through)', 'فروش بازه ÷ (فروش بازه + موجودی فعلی). نشان می‌دهد چه سهمی از کالای در دسترس فروخته شده است.'],
      ['تحقق قیمت لیست', 'قیمت واقعی فروش هر متر مربع ÷ قیمت فعلی هر متر کلکسیون. اگر ۷۰٪ باشد یعنی به‌طور میانگین ۳۰٪ زیر قیمت لیست فعلی فروخته‌اید (با توجه به تغییر قیمت‌ها در طول زمان تفسیر کنید).'],
      ['دسته‌بندی RFM', 'هر مشتری بر اساس سه چیز امتیاز می‌گیرد: تازگی آخرین خرید (Recency)، دفعات خرید (Frequency) و مبلغ خرید (Monetary). از ترکیب این‌ها دسته‌هایی مثل «قهرمانان»، «در معرض ریزش» و «از دست رفته» ساخته می‌شود.'],
      ['کوهورت (نگهداشت مشتری)', 'مشتریان بر اساس ماه اولین خرید گروه‌بندی می‌شوند و بررسی می‌شود چند درصدشان در ماه‌های بعد برگشته‌اند.'],
      ['شاخص تمرکز HHI', 'جمع مجذور سهم هر مشتری از فروش. زیر ۱۵۰۰ یعنی فروش پراکنده و کم‌ریسک، بالای ۲۵۰۰ یعنی وابستگی شدید به چند مشتری.'],
      ['دوره وصول مطالبات (DSO)', 'کل مطالبات ÷ فروش روزانه (میانگین ۹۰ روز اخیر). یعنی به‌طور میانگین چند روز فروش، در دست مشتریان مانده است.'],
      ['فاصله فروش تا پول نقد', 'مثل «فاصله فروش تا دریافت» است، اما برای چک‌ها تاریخ سررسید را مبنا می‌گیرد؛ چون پول واقعی آن روز به دستتان می‌رسد. اختلاف این دو عدد هزینه پنهان فروش چکی است.'],
      ['پوشش و گردش موجودی', 'پوشش = ارزش موجودی ÷ بهای تمام‌شده روزانه فروش (۹۰ روز اخیر): موجودی فعلی چند روز فروش را جواب می‌دهد. گردش = چند بار در سال موجودی کامل فروخته و جایگزین می‌شود.'],
      ['سرمایه خوابیده', 'کالاهایی که بیش از ۱۸۰ روز در انبارند و محصولشان در ۱۸۰ روز اخیر هیچ فروشی نداشته است.'],
      ['نقطه سر به سر', 'حداقل فروش ماهانه لازم برای پوشش هزینه‌های جاری = میانگین هزینه ماهانه ÷ حاشیه سود ناخالص.'],
      ['پیش‌بینی', 'یک خط روند ساده روی فروش ۱۲ ماه کامل اخیر. بازه بالا/پایین بر اساس پراکندگی ماه‌های گذشته است. برای برنامه‌ریزی تقریبی مفید است، نه پیش‌بینی قطعی.'],
      ['نکات فنی', 'فاکتورهای امانی در هیچ عدد فروشی حساب نمی‌شوند. مالیات جزو فروش نیست. همه ماه‌ها، فصل‌ها و سال‌ها شمسی دقیق‌اند. نتایج تا ۲ دقیقه در حافظه موقت می‌مانند؛ دکمه «به‌روزرسانی» آن‌ها را از نو محاسبه می‌کند.'],
    ];
    el.innerHTML = `<div class="sa-grid">${card({ title: 'راهنمای شاخص‌ها', icon: 'book', span: 12, body: `<div class="sa-guide"><dl>${items.map(([t, dsc]) => `<dt>${t}</dt><dd>${dsc}</dd>`).join('')}</dl></div>` })}
      ${card({ title: 'نکته‌های استفاده', icon: 'lightbulb', span: 12, body: `<div class="sa-guide"><dl>
        <dt>توضیح هر نمودار</dt><dd>روی علامت <i class="bi bi-question-circle"></i> کنار هر کارت بزنید تا توضیح ساده آن نمایش داده شود.</dd>
        <dt>جدول‌ها</dt><dd>روی عنوان ستون بزنید تا مرتب شود؛ با کادر جستجو فیلتر کنید و با دکمه <i class="bi bi-download"></i> همان جدول را به اکسل ببرید. دکمه «خروجی» بالای صفحه همه جدول‌های بخش فعلی را یکجا خروجی می‌دهد.</dd>
        <dt>اعداد خلاصه</dt><dd>مبالغ بزرگ به‌صورت خلاصه (مثلاً «۱٫۲ میلیارد») نمایش داده می‌شوند؛ نشانگر را روی عدد نگه دارید تا مبلغ دقیق را ببینید.</dd>
        <dt>چاپ</dt><dd>دکمه «چاپ» بخش فعلی را برای چاپ یا ذخیره PDF آماده می‌کند.</dd>
      </dl></div>` })}</div>`;
  }

  /* ================================================================
   *  Filters, tabs, events
   * ================================================================ */
  let pickers = {};
  let suppressPicker = false;

  function setDates(start, end, fromPreset) {
    state.start = start; state.end = end;
    if (!fromPreset) state.preset = '';
    const [sy, sm, sd] = isoToJ(start), [ey, em, ed] = isoToJ(end);
    const sIn = document.getElementById('sa-start'), eIn = document.getElementById('sa-end');
    suppressPicker = true;
    try {
      if (pickers.start && pickers.start.setDate) pickers.start.setDate(new Date(start + 'T12:00:00').getTime());
      if (pickers.end && pickers.end.setDate) pickers.end.setDate(new Date(end + 'T12:00:00').getTime());
    } catch (e) { /* picker optional */ }
    suppressPicker = false;
    sIn.value = fa(`${sy}/${pad(sm)}/${pad(sd)}`);
    eIn.value = fa(`${ey}/${pad(em)}/${pad(ed)}`);
    document.querySelectorAll('.sa-presets button').forEach((b) => b.classList.toggle('active', b.dataset.preset === state.preset));
  }
  function saveFilters() {
    try { localStorage.setItem(FILTER_KEY, JSON.stringify({ preset: state.preset, start: state.start, end: state.end, compare: state.compare, gran: state.gran })); } catch (e) {}
  }
  function filtersChanged() {
    saveFilters();
    state.data = {};
    loadSection(state.tab);
  }
  function applyPreset(key) {
    const r = presetRange(key);
    if (!r) return;
    state.preset = key;
    setDates(r[0], r[1], true);
    filtersChanged();
  }
  function onDateInput() {
    const s = parseJalaliInput(document.getElementById('sa-start').value);
    const e = parseJalaliInput(document.getElementById('sa-end').value);
    if (!s || !e) return;
    if (s === state.start && e === state.end) return;
    state.preset = '';
    state.start = s <= e ? s : e; state.end = s <= e ? e : s;
    document.querySelectorAll('.sa-presets button').forEach((b) => b.classList.remove('active'));
    filtersChanged();
  }

  function activateTab(tab, push = true) {
    if (!TABS.includes(tab)) tab = 'overview';
    state.tab = tab;
    document.querySelectorAll('.sa-tabs button').forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === tab ? 'true' : 'false'));
    document.querySelectorAll('.sa-section').forEach((s) => s.classList.toggle('active', s.dataset.section === tab));
    if (push) { try { history.replaceState(null, '', '#' + tab); } catch (e) {} }
    const btn = document.querySelector(`.sa-tabs button[data-tab="${tab}"]`);
    if (btn && btn.scrollIntoView) btn.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    loadSection(tab);
  }

  function bindEvents() {
    document.querySelectorAll('.sa-tabs button').forEach((b) => b.addEventListener('click', () => activateTab(b.dataset.tab)));
    document.querySelectorAll('.sa-presets button').forEach((b) => b.addEventListener('click', () => applyPreset(b.dataset.preset)));
    document.getElementById('sa-compare').addEventListener('change', (e) => { state.compare = e.target.value; filtersChanged(); });
    document.getElementById('sa-gran').addEventListener('change', (e) => { state.gran = e.target.value; filtersChanged(); });
    ['sa-start', 'sa-end'].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('change', onDateInput);
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter') onDateInput(); });
    });
    document.getElementById('sa-refresh').addEventListener('click', () => { state.data = {}; loadSection(state.tab, true); });
    document.getElementById('sa-export').addEventListener('click', () => exportSection(state.tab));
    document.getElementById('sa-print').addEventListener('click', () => window.print());
    document.getElementById('sa-theme').addEventListener('click', () => {
      const next = isDark() ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
      updateThemeIcon();
      const c = state.data[state.tab];
      if (c) render(state.tab, c.payload);
    });

    // Delegated handlers for dynamic content
    document.addEventListener('click', (e) => {
      const h = e.target.closest('[data-help]');
      if (h) { const box = h.closest('.sa-card, .sa-kpi'); if (box) box.classList.toggle('show-help'); return; }
      const th = e.target.closest('[data-tsort]');
      if (th) {
        const t = tables[th.dataset.tsort]; if (!t) return;
        const col = t.cols.find((c) => c.key === th.dataset.key);
        if (t.sort === th.dataset.key) t.dir = t.dir === 'asc' ? 'desc' : 'asc';
        else { t.sort = th.dataset.key; t.dir = col && isNumCol(col) ? 'desc' : 'asc'; }
        refreshTable(th.dataset.tsort); return;
      }
      const more = e.target.closest('[data-tmore]');
      if (more) { const t = tables[more.dataset.tmore]; if (t) { t.limit += t.baseLimit * 2; refreshTable(more.dataset.tmore); } return; }
      const ex = e.target.closest('[data-texport]');
      if (ex) { const t = tables[ex.dataset.texport]; if (t) downloadCsv(`${t.title}.csv`, tableCsvRows(t)); return; }
      const tg = e.target.closest('[data-toggle] button');
      if (tg) {
        const [section, name] = tg.parentElement.dataset.toggle.split('.');
        state.view[section] = state.view[section] || {};
        state.view[section][name] = tg.dataset.value;
        const c = state.data[section]; if (c) render(section, c.payload);
        return;
      }
      const sg = e.target.closest('[data-segment]');
      if (sg) {
        state.view.customers = state.view.customers || {};
        const cur = state.view.customers.segment || '';
        state.view.customers.segment = sg.dataset.segment === cur ? '' : sg.dataset.segment;
        const c = state.data.customers; if (c) render('customers', c.payload);
        return;
      }
      const rt = e.target.closest('[data-retry]');
      if (rt) { loadSection(rt.dataset.retry, true); return; }
      if (e.target.closest('#inv-go')) { runInventoryExplorer(); return; }
    });
    document.addEventListener('input', (e) => {
      const s = e.target.closest('[data-tsearch]');
      if (s) { const t = tables[s.dataset.tsearch]; if (t) { t.q = s.value.trim(); t.limit = t.baseLimit; refreshTable(s.dataset.tsearch); } }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'inv-kw') runInventoryExplorer(); });
    document.addEventListener('change', (e) => {
      if (e.target.id === 'pr-collection') { state.products.collection = +e.target.value; loadSection('products'); }
      if (e.target.id === 'pr-type') { state.products.ptype = e.target.value; loadSection('products'); }
    });

    // HTML tooltip for heatmap cells and other [data-tip] elements
    const tip = document.getElementById('sa-tooltip');
    document.addEventListener('mouseover', (e) => {
      const t = e.target.closest('[data-tip]');
      if (!t) { tip.hidden = true; return; }
      tip.innerHTML = t.dataset.tip;
      tip.hidden = false;
      const r = t.getBoundingClientRect();
      const w = tip.offsetWidth;
      tip.style.top = Math.max(4, r.top - tip.offsetHeight - 8) + 'px';
      tip.style.left = Math.min(window.innerWidth - w - 4, Math.max(4, r.left + r.width / 2 - w / 2)) + 'px';
    });
    window.addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });
    window.addEventListener('hashchange', () => { const t = location.hash.slice(1); if (t && t !== state.tab) activateTab(t, false); });
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (document.documentElement.getAttribute('data-theme')) return;
        updateThemeIcon();
        const c = state.data[state.tab]; if (c) render(state.tab, c.payload);
      });
    }
  }
  function updateThemeIcon() {
    const i = document.querySelector('#sa-theme i');
    if (i) i.className = 'bi ' + (isDark() ? 'bi-sun' : 'bi-moon-stars');
  }

  function initPickers() {
    if (!window.jQuery || !jQuery.fn.persianDatepicker) return;
    const make = (id, key) => {
      try {
        pickers[key] = jQuery('#' + id).persianDatepicker({
          format: 'YYYY/MM/DD', initialValue: false, autoClose: true, observer: false,
          calendar: { persian: { locale: 'fa' } }, toolbox: { calendarSwitch: { enabled: false } },
          onSelect: function () {
            if (suppressPicker) return;
            setTimeout(onDateInput, 0);
          },
        });
      } catch (e) { /* typing still works */ }
    };
    make('sa-start', 'start');
    make('sa-end', 'end');
  }

  async function init() {
    if (window.toastr) toastr.options = { positionClass: 'toast-bottom-left', rtl: true, timeOut: 3500 };
    updateThemeIcon();
    // Restore filters
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(FILTER_KEY) || 'null'); } catch (e) {}
    if (saved) {
      state.compare = ['prev', 'yoy', 'none'].includes(saved.compare) ? saved.compare : 'prev';
      state.gran = saved.gran || 'auto';
    }
    document.getElementById('sa-compare').value = state.compare;
    document.getElementById('sa-gran').value = state.gran;
    initPickers();
    bindEvents();

    try {
      state.meta = await fetchJSON({ action: 'meta' });
      document.getElementById('sa-entity-name').textContent = (state.meta.entity_name || '') + (state.meta.invoice_count ? ' · ' + fmtNum(state.meta.invoice_count) + ' فاکتور فروش' : '');
    } catch (e) {
      state.meta = null;
      if (window.toastr) toastr.error(e.message);
    }

    if (saved && saved.preset && presetRange(saved.preset)) {
      state.preset = saved.preset;
      const r = presetRange(saved.preset);
      setDates(r[0], r[1], true);
    } else if (saved && saved.start && saved.end) {
      setDates(saved.start, saved.end, false);
    } else {
      state.preset = 'this_year';
      const r = presetRange('this_year');
      setDates(r[0], r[1], true);
    }
    const hashTab = location.hash.slice(1);
    activateTab(TABS.includes(hashTab) ? hashTab : 'overview', false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
