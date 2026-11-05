// =================================================================
// UI THEME («قالب») (js/theme.js)
// Loaded in <head> so the theme is on the page before the first paint.
//   <html data-ui="modern|classic">   which template set index.php rendered
//   data-ui-theme  the chosen theme (may be "auto")
//   data-ui-mode   the theme actually shown (auto -> nili / shab)
// Switching between modern themes is instant; to/from classic reloads,
// because classic uses its own templates (templates/classic).
// =================================================================
const UITheme = (function () {
    const THEMES = [
        { key: 'nili', label: 'نیلی', swatch: ['#1e1b4b', '#4f46e5'] },
        { key: 'firoozeh', label: 'فیروزه‌ای', swatch: ['#0f2e2b', '#0f766e'] },
        { key: 'kaghazi', label: 'کاغذی', swatch: ['#f5f0e6', '#1e3a8a'] },
        { key: 'shab', label: 'شب (تاریک)', swatch: ['#0e131d', '#7c8cff'] },
        { key: 'auto', label: 'خودکار', hint: 'روشن یا تاریک، مثل دستگاه', swatch: ['#4f46e5', '#0e131d'] },
        { key: 'classic', label: 'کلاسیک', hint: 'ظاهر قبلی برنامه', swatch: ['#f8f9fa', '#0d6efd'] },
    ];
    const KEYS = THEMES.map(t => t.key);
    // Browser-bar colour per shown theme (matches --ui-nav-bg in tokens.css).
    const BAR_COLOR = { nili: '#1e1b4b', firoozeh: '#0f2e2b', kaghazi: '#fffdf8', shab: '#0a0e16' };
    const root = document.documentElement;
    const info = Object.assign({ theme: 'nili', user_theme: '', default_theme: 'nili' }, window.__UI__ || {});
    const darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    const family = (theme) => (theme === 'classic' ? 'classic' : 'modern');
    const pageFamily = () => root.getAttribute('data-ui') || 'modern';
    const isModern = () => pageFamily() === 'modern';
    const normalize = (theme) => (KEYS.includes(theme) ? theme : 'nili');
    const resolve = (theme) => (theme === 'auto' ? (darkQuery && darkQuery.matches ? 'shab' : 'nili') : theme);

    function apply(theme) {
        theme = normalize(theme);
        info.theme = theme;
        root.setAttribute('data-ui-theme', theme);
        if (!isModern()) return; // classic keeps its own light/dark toggle (ui.js)
        const mode = resolve(theme);
        root.setAttribute('data-ui-mode', mode);
        root.setAttribute('data-bs-theme', mode === 'shab' ? 'dark' : 'light');
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta && BAR_COLOR[mode]) meta.setAttribute('content', BAR_COLOR[mode]);
        renderMenu();
        styleCharts();
        document.dispatchEvent(new CustomEvent('ui-theme-change', { detail: { theme, mode } }));
    }

    // Chart.js draws text and grid lines itself: give it the theme's colours.
    // Charts drawn earlier pick them up the next time their section reloads.
    function styleCharts() {
        if (!window.Chart || !Chart.defaults) return;
        const css = getComputedStyle(root);
        const v = (name) => css.getPropertyValue(name).trim();
        Chart.defaults.color = v('--ui-text-2') || Chart.defaults.color;
        Chart.defaults.borderColor = v('--ui-border') || Chart.defaults.borderColor;
        if (Chart.defaults.font) Chart.defaults.font.family = 'Vazirmatn, system-ui, sans-serif';
    }

    async function save(theme) {
        if (typeof Api === 'undefined') return null;
        const res = await Api.call('save_ui_theme', { theme });
        if (res && res.success) info.user_theme = theme;
        return res;
    }

    async function select(theme) {
        theme = normalize(theme);
        if (family(theme) !== pageFamily()) {
            const res = await save(theme);
            if (res && res.success) location.reload();
            return;
        }
        apply(theme);
        save(theme);
    }

    /**
     * Called with the `ui` block of login / check_session. Returns true when
     * the page is about to reload into the other template set.
     */
    function onSession(ui) {
        if (!ui) return false;
        Object.assign(info, ui);
        // One-time move of the old dark-mode toggle to «شب».
        let migrate = false;
        try {
            migrate = !ui.user_theme && family(ui.default_theme || 'nili') === 'modern' && localStorage.getItem('theme') === 'dark' && !localStorage.getItem('ui_theme_migrated');
            if (migrate) localStorage.setItem('ui_theme_migrated', '1');
        } catch (e) { /* storage blocked */ }
        if (migrate) {
            info.theme = 'shab';
            save('shab').then((res) => {
                if (res && res.success && pageFamily() !== 'modern') location.reload();
            });
            if (pageFamily() === 'modern') apply('shab');
            return false;
        }
        if (family(info.theme) !== pageFamily()) {
            location.reload();
            return true;
        }
        apply(info.theme);
        return false;
    }

    function renderMenu() {
        const menu = document.getElementById('ui-theme-menu');
        if (!menu) return;
        const current = info.theme;
        menu.innerHTML = '<li><h6 class="dropdown-header">انتخاب قالب</h6></li>' + THEMES.map(t => {
            const on = t.key === current;
            const sw = `<span class="ui-theme-swatch" style="background:linear-gradient(135deg, ${t.swatch[0]} 50%, ${t.swatch[1]} 50%)"></span>`;
            const hint = t.hint ? `<small class="ui-theme-hint">${t.hint}</small>` : '';
            const sep = t.key === 'auto' ? '<li><hr class="dropdown-divider"></li>' : '';
            return `${sep}<li><button type="button" class="dropdown-item ui-theme-option${on ? ' active' : ''}" data-ui-theme="${t.key}" aria-pressed="${on}">`
                + `${sw}<span class="ui-theme-label">${t.label}${hint}</span>${on ? '<i class="bi bi-check2 ms-auto"></i>' : ''}</button></li>`;
        }).join('');
    }

    function initMenu() {
        renderMenu();
        document.addEventListener('click', (e) => {
            const opt = e.target.closest && e.target.closest('.ui-theme-option');
            if (!opt) return;
            e.preventDefault();
            select(opt.getAttribute('data-ui-theme'));
        });
    }

    if (darkQuery) {
        const onChange = () => { if (info.theme === 'auto') apply('auto'); };
        if (darkQuery.addEventListener) darkQuery.addEventListener('change', onChange);
        else if (darkQuery.addListener) darkQuery.addListener(onChange);
    }

    apply(info.theme);
    const onReady = () => { initMenu(); if (isModern()) styleCharts(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
    else onReady();

    return { THEMES, apply, select, onSession, isModern, resolve, current: () => info.theme, mode: () => root.getAttribute('data-ui-mode') };
})();
window.UITheme = UITheme;
