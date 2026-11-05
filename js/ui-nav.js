// =================================================================
// GROUPED NAVIGATION (js/ui-nav.js) — redesigned template set only
// The section buttons live inside group dropdowns of #mainTab. app.js keeps
// switching sections exactly as before; this module only follows along:
//   - the group of the open section is highlighted,
//   - the group menu (and the phone drawer) closes after a section opens,
//   - the light bar shows «group › section».
// =================================================================
const UINav = (function () {
    let nav = null;

    function labelOf(el) {
        if (!el) return '';
        const span = el.querySelector(':scope > span');
        return (span ? span.textContent : el.textContent).replace(/\s+/g, ' ').trim();
    }

    function activeSection() {
        return nav && nav.querySelector('[data-bs-toggle="tab"].active[data-bs-target]');
    }

    function sync() {
        if (!nav) return;
        const active = activeSection();
        nav.querySelectorAll('.ui-navgroup').forEach((group) => {
            const toggle = group.querySelector(':scope > .dropdown-toggle');
            const on = !!(active && group.contains(active));
            if (toggle && toggle.classList.contains('active') !== on) toggle.classList.toggle('active', on);
        });
        const groupEl = active && active.closest('.ui-navgroup');
        const groupName = groupEl ? labelOf(groupEl.querySelector(':scope > .dropdown-toggle')) : '';
        const page = document.getElementById('ui-crumb-page');
        const group = document.getElementById('ui-crumb-group');
        const sep = document.getElementById('ui-crumb-sep');
        if (page) page.textContent = labelOf(active) || 'داشبورد';
        if (group) group.textContent = groupName;
        if (sep) sep.style.display = groupName ? '' : 'none';
    }

    function closeMenus() {
        if (!nav) return;
        nav.querySelectorAll('.ui-navgroup > .dropdown-toggle').forEach((toggle) => {
            const dd = window.bootstrap && bootstrap.Dropdown.getInstance(toggle);
            if (dd) dd.hide();
            toggle.setAttribute('aria-expanded', 'false');
        });
        // Bootstrap's tab plugin opens the menu of the group it activates.
        nav.querySelectorAll('.dropdown-menu.show').forEach((m) => m.classList.remove('show'));
        const drawer = document.getElementById('ui-nav-drawer');
        const oc = drawer && window.bootstrap && bootstrap.Offcanvas.getInstance(drawer);
        if (oc && drawer.classList.contains('show')) oc.hide();
    }

    function init() {
        if (!(window.UITheme && UITheme.isModern())) return;
        nav = document.getElementById('mainTab');
        if (!nav) return;
        nav.addEventListener('shown.bs.tab', () => { closeMenus(); sync(); window.scrollTo({ top: 0 }); });
        // app.js also switches sections by toggling classes directly (syncMainTabUI).
        new MutationObserver(() => sync()).observe(nav, { subtree: true, attributes: true, attributeFilter: ['class'] });
        sync();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    return { sync };
})();
