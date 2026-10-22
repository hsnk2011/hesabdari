// /js/app.js

const App = (function () {
    let appCache = { customers: [], suppliers: [], contacts: [], products: [], partners: [], checks: [], accounts: [], settings: {}, collections: [], publicSettings: {}, entityPrintSettings: {} };
    let managers = {};
    let notificationInterval;
    let appState = {
        currentUser: null,
        currentEntityId: null,
        businessEntities: [],
        permissions: [],
        allowedEntities: [],
        pendingFilter: null // New state for smart alerts
    };

    function getManager(name) {
        return managers[name];
    }

    function registerManager(name, manager) {
        managers[name] = manager;
    }

    function getCache() {
        return appCache;
    }

    function getAppState() {
        return appState;
    }

    function findVisibleMainTabButton(opts = {}) {
        const { tableName, target, id } = opts;
        const all = Array.from(document.querySelectorAll('#mainTab [data-bs-toggle="tab"]'));
        const matches = all.filter((el) => {
            if (tableName && el.getAttribute('data-table-name') === tableName) return true;
            if (target && el.getAttribute('data-bs-target') === target) return true;
            if (id && el.id === id) return true;
            return false;
        });
        const visible = matches.find((el) => {
            const item = el.closest('.nav-item');
            if (item && window.getComputedStyle(item).display === 'none') return false;
            return window.getComputedStyle(el).display !== 'none';
        });
        return visible || matches[0] || null;
    }

    function setPendingFilter(tableName, filters, message) {
        appState.pendingFilter = { tableName, filters, message };
        const targetTabButton = findVisibleMainTabButton({ tableName });
        if (targetTabButton) {
            new bootstrap.Tab(targetTabButton).show();
        }
    }

    function hasPermission(key) {
        return appState.permissions.includes(key);
    }

    // Show/hide by permission. The perm-hidden class (display:none !important) is
    // needed because Bootstrap display utilities such as d-md-block are
    // !important too and would otherwise keep a hidden element visible.
    function setPermissionVisible($el, allowed) {
        $el.toggleClass('perm-hidden', !allowed);
        if (allowed) $el.show(); else $el.hide();
    }

    function applyPermissions() {
        $('[data-permission]').each(function () {
            setPermissionVisible($(this), hasPermission($(this).data('permission')));
        });

        $('[data-permission-any]').each(function () {
            const keys = String($(this).data('permission-any')).split(',').map(s => s.trim()).filter(Boolean);
            setPermissionVisible($(this), keys.some(k => hasPermission(k)));
        });

        $('[data-permission-action]').each(function () {
            setPermissionVisible($(this), hasPermission($(this).data('permission-action')));
        });

        // Without manage_settings the Settings tab only holds «کلکسیون‌ها»: name it that way.
        const $settingsTab = $('#settings-tab');
        if (!$settingsTab.data('original-html')) $settingsTab.data('original-html', $settingsTab.html());
        if (!hasPermission('manage_settings') && hasPermission('manage_collections')) {
            $settingsTab.html('<i class="bi bi-sliders me-2"></i>کلکسیون‌ها');
        } else {
            $settingsTab.html($settingsTab.data('original-html'));
        }

        const anyPerson = hasPermission('manage_customers') || hasPermission('manage_suppliers') || hasPermission('manage_contacts');
        setPermissionVisible($('#persons-dropdown'), anyPerson);
        if (!anyPerson) {
            setPermissionVisible($('#customers-tab-mobile-item, #suppliers-tab-mobile-item, #contacts-tab-mobile-item'), false);
        }

        // Start page: the dashboard when allowed, otherwise the first section the user may open.
        const activeTabButton = $('#mainTab .nav-link.active, #mainTab .dropdown-item.active');
        const activeHidden = !activeTabButton.length || activeTabButton.closest('li').hasClass('perm-hidden') || activeTabButton.closest('li').is(':hidden');
        $('#no-access-notice').remove();
        if (activeHidden || (activeTabButton.attr('id') === 'dashboard-tab' && !hasPermission('view_dashboard'))) {
            if (hasPermission('view_dashboard')) {
                $('#dashboard-tab').tab('show');
            } else {
                const first = $('#mainTab > li').not('.perm-hidden').filter(function () { return $(this).css('display') !== 'none'; })
                    .find('button[data-bs-toggle="tab"]').first();
                if (first.length) {
                    first.tab('show');
                } else {
                    $('#dashboard').addClass('show active').prepend(
                        '<div class="alert alert-warning" id="no-access-notice">برای حساب کاربری شما هیچ دسترسی‌ای تعریف نشده است. لطفاً با مدیر برنامه تماس بگیرید.</div>'
                    );
                }
            }
        }
        // Dashboard content needs view_dashboard; without it the empty widgets stay hidden.
        $('#dashboard').children().not('#no-access-notice').toggleClass('perm-hidden', !hasPermission('view_dashboard'));
    }


    async function refreshAllData() {
        await fetchInitialCache();
        applyPermissions();
        await loadActiveTab();
    }

    // Load the data of the section that is currently open (the start page after
    // login may be any section when the user has no dashboard permission).
    async function loadActiveTab() {
        // Only real tab buttons: a dropdown toggle (اشخاص) is marked active too but has no section.
        const activeTab = document.querySelector('#mainTab [data-bs-toggle="tab"].active[data-bs-target]');
        if (activeTab) {
            const tabId = activeTab.id;
            const tableName = activeTab.dataset.tableName;

            if (tabId === 'dashboard-tab' && hasPermission('view_dashboard')) {
                await Dashboard.load();
            } else if (tableName) {
                const manager = getManager(tableName);
                if (manager) {
                    await manager.load();
                } else if (tableName.includes('invoice') || tableName.includes('consignment')) {
                    await InvoiceManager.load(tableName);
                }
            } else {
                // Sections without a table (assets, tools, returns, ...) load on shown.bs.tab.
                $(activeTab).trigger('shown.bs.tab');
            }
        } else if (hasPermission('view_dashboard')) {
            $('#dashboard-tab').tab('show');
        }
    }

    function updateAccess(sessionData) {
        appState.permissions = sessionData.permissions || [];
        appState.allowedEntities = normalizeEntityIds(sessionData.allowed_entities || []);
        if (sessionData.current_entity_id !== undefined) {
            appState.currentEntityId = Number(sessionData.current_entity_id) || null;
        }
        updateEntitySwitcherUI();
        applyPermissions();
        refreshAllData();
    }

    // Called after entities are added/renamed/deleted so the header switcher reflects the change.
    function setBusinessEntities(entities, allowedEntities) {
        if (Array.isArray(entities)) {
            appState.businessEntities = entities;
        }
        if (Array.isArray(allowedEntities)) {
            appState.allowedEntities = normalizeEntityIds(allowedEntities);
        }
        updateEntitySwitcherUI();
    }

    async function switchEntity(entityId) {
        const targetId = Number(entityId);
        if (!Number.isFinite(targetId) || targetId <= 0) return;
        if (Number(appState.currentEntityId) === targetId) return;

        UI.showLoader();
        try {
            const result = await Api.call('switch_entity', { entity_id: targetId });
            if (result?.success) {
                appState.currentEntityId = Number(result.new_entity_id);
                updateEntitySwitcherUI();
                await refreshAllData();
                const name = appState.businessEntities.find(e => Number(e.id) === targetId)?.name || '';
                UI.showSuccess(`مجموعه فعال به «${name}» تغییر کرد.`);
            }
        } finally {
            UI.hideLoader();
        }
    }

    function normalizeEntityIds(list) {
        return (Array.isArray(list) ? list : [])
            .map((v) => Number(v))
            .filter((n) => Number.isFinite(n) && n > 0);
    }

    function updateEntitySwitcherUI() {
        const currentId = Number(appState.currentEntityId);
        const currentEntity = (appState.businessEntities || []).find(e => Number(e.id) === currentId);
        if (currentEntity) {
            $('#current-entity-name').text(currentEntity.name);
        } else {
            $('#current-entity-name').text('انتخاب مجموعه');
        }

        const allowed = new Set(normalizeEntityIds(appState.allowedEntities));
        const list = $('#entity-switcher-list').empty();
        (appState.businessEntities || []).forEach(entity => {
            const id = Number(entity.id);
            if (!allowed.has(id)) return;
            const isActive = id === currentId;
            const item = $(`<li>
                <button type="button" class="dropdown-item ${isActive ? 'active' : ''}" data-entity-id="${id}">
                    ${UI.escapeHtml(entity.name)}
                </button>
            </li>`);
            list.append(item);
        });

        if (!list.children().length) {
            list.append('<li><span class="dropdown-item-text text-muted">مجموعه‌ای در دسترس نیست</span></li>');
        }
    }


    async function updateCache(keys) {
        if (!Array.isArray(keys) || keys.length === 0) return;

        UI.showLoader();
        const promises = [];
        const keyMap = {
            customers: () => Api.call('get_full_customers_list'),
            suppliers: () => Api.call('get_full_suppliers_list'),
            contacts: () => Api.call('get_full_contacts_list'),
            products: () => Api.call('get_full_products_list'),
            partners: () => Api.call('get_partners'),
            accounts: () => Api.call('get_full_accounts_list'),
            // Performance: Only fetch active checks (in_hand, payable) with reasonable limit
            checks: () => Api.call('get_paginated_data', { 
                tableName: 'checks', 
                limit: 200, // Reduced from 1000 for better performance
                sortBy: 'id', 
                sortOrder: 'DESC',
                // Only fetch checks that are still actionable
                filterStatus: ['in_hand', 'payable'] 
            }),
            settings: () => Api.call('get_app_settings'),
            collections: () => Api.call('get_full_collections_list'),
            publicSettings: () => Api.call('get_public_settings', {}, false),
            entityPrintSettings: () => Api.call('get_entity_print_settings', {}, false)
        };

        // If settings or publicSettings is requested, also refresh entityPrintSettings for print settings
        if ((keys.includes('settings') || keys.includes('publicSettings')) && !keys.includes('entityPrintSettings')) {
            keys.push('entityPrintSettings');
        }
        if (keys.includes('settings') && !keys.includes('publicSettings')) {
            keys.push('publicSettings');
        }

        keys.forEach(key => {
            if (keyMap[key]) {
                const promise = keyMap[key]();
                promises.push(promise.then(data => ({ key, data })));
            }
        });

        const results = await Promise.all(promises);

        results.forEach(result => {
            if (result.data && !result.data.error) {
                if (result.key === 'checks') {
                    appCache[result.key] = result.data.data || [];
                } else if (result.key === 'settings') {
                    appCache[result.key] = result.data.settings || {};
                } else if (result.key === 'entityPrintSettings') {
                    appCache[result.key] = result.data || {};
                } else if (result.key === 'publicSettings') {
                    appCache[result.key] = result.data || {};
                } else {
                    appCache[result.key] = result.data;
                }
            } else if (result.data && result.data.error) {
                console.warn(`Could not update cache for '${result.key}':`, result.data.error);
            }
        });
        if (keys.includes('partners') && hasPermission('manage_settings')) PartnerManager.refreshUI();
        if (hasPermission('view_reports')) {
            if (keys.includes('customers') || keys.includes('suppliers') || keys.includes('partners')) populatePersonSelectForReports();
            if (keys.includes('accounts')) populateAccountSelectForReports();
            if (keys.includes('products')) populateProductSelectForReports();
        }
        if (keys.includes('settings') || keys.includes('publicSettings')) {
            applyBrandingFromSettings();
        }
        UI.hideLoader();
    }

    // Bind settings-driven labels (app title, currency unit) into the live DOM.
    function applyBrandingFromSettings() {
        // Full settings are only present for admins; fall back to them when publicSettings lacks a key.
        const pub = appCache.publicSettings || {};
        const full = appCache.settings || {};
        const pick = k => (pub[k] ?? full[k] ?? '');
        const title = String(pick('app_title')).trim();
        if (title) {
            document.title = title;
            const headerEl = document.getElementById('app-title-header');
            if (headerEl) headerEl.textContent = title;
        }
        // Elements marked with data-currency-label get the configured currency unit.
        const curLabel = String(pick('currency_label')).trim()
            || (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS && AppConfig.DEFAULTS.CURRENCY_LABEL) || 'تومان';
        document.querySelectorAll('[data-currency-label]').forEach(el => {
            el.textContent = curLabel;
        });
        const divisorEl = document.getElementById('bank-import-rial-divisor');
        if (divisorEl) {
            const d = parseFloat(pick('currency_rial_divisor'));
            divisorEl.textContent = Number.isFinite(d) && d > 0
                ? d.toLocaleString('fa-IR')
                : '۱۰';
        }
    }

    async function fetchInitialCache(options = {}) {
        const { runAutoBackup = false } = options;
        const publicSettingsData = await Api.call('get_public_settings', {}, false);
        if (publicSettingsData) {
            appCache.publicSettings = publicSettingsData;
        }
        
        // Also fetch entity print settings
        const entityPrintSettingsData = await Api.call('get_entity_print_settings', {}, false);
        if (entityPrintSettingsData && !entityPrintSettingsData.error) {
            appCache.entityPrintSettings = entityPrintSettingsData;
        }

        // Picker lists are readable by every section that needs them (same lists as api/index.php).
        const any = (...keys) => keys.some(k => hasPermission(k));
        const permissionsMap = {
            customers: any('manage_customers', 'manage_sales_invoices', 'manage_quotations', 'manage_return_invoices', 'manage_consignment', 'manage_transactions', 'manage_checks', 'manage_installments', 'manage_assets', 'view_reports'),
            suppliers: any('manage_suppliers', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_consignment', 'manage_transactions', 'manage_checks', 'manage_assets', 'view_reports'),
            contacts: any('manage_contacts', 'manage_transactions', 'manage_checks', 'manage_assets', 'view_reports'),
            products: any('manage_products', 'manage_collections', 'manage_sales_invoices', 'manage_purchase_invoices', 'manage_quotations', 'manage_return_invoices', 'manage_consignment', 'manage_stocktaking', 'view_price_list'),
            partners: any('manage_settings', 'manage_transactions', 'manage_accounts', 'view_reports'),
            accounts: any('manage_accounts', 'manage_transactions', 'manage_sales_invoices', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_checks', 'manage_installments', 'manage_assets', 'manage_bank_imports', 'view_reports'),
            checks: any('manage_checks', 'manage_transactions', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_assets'),
            settings: hasPermission('manage_settings'),
            collections: hasPermission('manage_collections') || hasPermission('manage_products')
        };
        const keysToFetch = Object.keys(permissionsMap).filter(key => permissionsMap[key]);

        await updateCache(keysToFetch);
        // Auto backup only on explicit request (initial login), not on every cache refresh
        if (runAutoBackup) {
            try {
                if (App.getManager('backup')?.maybeRunAutoBackup) {
                    await App.getManager('backup').maybeRunAutoBackup();
                }
            } catch (e) { /* ignore */ }
        }
    }

    async function updateNotifications() {
        if (!hasPermission('view_dashboard')) return;

        const bell = $('#notification-bell');
        const countBadge = $('#notification-count');
        const checksSection = $('#notif-section-checks');
        const remindersSection = $('#notif-section-reminders');
        let totalCount = 0;

        // 1. Load due checks
        try {
            const notificationData = await Api.call('get_notifications', {}, false);
            checksSection.empty();

            if (notificationData && notificationData.due_checks_count > 0) {
                totalCount += notificationData.due_checks_count;
                const checksList = await Api.call('get_due_checks_list', {}, false);

                if (checksList && checksList.length > 0) {
                    checksSection.append('<div class="dropdown-header small text-primary"><i class="bi bi-bank me-1"></i>چک‌های نزدیک سررسید</div>');
                    checksList.forEach(chk => {
                        const dueDate = UI.gregorianToPersian(chk.dueDate);
                        const item = $(`<a class="dropdown-item notification-item" href="#">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold small">چک #${chk.checkNumber}</span>
                                <small class="text-muted">${dueDate}</small>
                            </div>
                            <div class="small text-muted">${UI.formatCurrency(chk.amount)}</div>
                        </a>`);
                        item.on('click', function (e) {
                            e.preventDefault();
                            const checksTabButton = document.querySelector('#mainTab button[data-bs-target="#checks"]');
                            if (checksTabButton) { new bootstrap.Tab(checksTabButton).show(); }
                        });
                        checksSection.append(item);
                    });
                    checksSection.append('<div class="dropdown-divider"></div>');
                }
            }
        } catch (e) { console.error('Error loading check notifications:', e); }

        // 2. Load reminders
        try {
            const remindersResult = await Api.call('get_reminders', {}, false);
            remindersSection.empty();
            const allReminders = remindersResult?.items || (Array.isArray(remindersResult) ? remindersResult : []);
            // فیلتر کردن یادآوری‌های چک چون قبلاً در بخش چک‌ها نمایش داده شده‌اند
            const reminders = allReminders.filter(n => n.type !== 'check_due' && n.type !== 'payable_check_due');
            if (reminders.length > 0) {
                totalCount += reminders.length;
                remindersSection.append('<div class="dropdown-header small text-warning"><i class="bi bi-bell me-1"></i>یادآوری‌ها</div>');
                const typeIcons = { check_due: 'bi-bank', invoice_overdue: 'bi-receipt-cutoff', installment_due: 'bi-calendar-event', installment_overdue: 'bi-calendar-event', crm_task_assigned: 'bi-person-check', crm_task_due: 'bi-alarm', crm_digest: 'bi-list-check', work_task: 'bi-check2-square', work_project: 'bi-kanban', work_meeting: 'bi-calendar-event' };
                reminders.forEach(n => {
                    const icon = typeIcons[n.type] || 'bi-bell';
                    // CRM rows carry a title and open their task in the CRM tab.
                    const isCrm = String(n.type || '').startsWith('crm_') || String(n.type || '').startsWith('work_');
                    // Rows with an in-app link open it (میز کار / CRM); older CRM rows open their task.
                    const crmAttr = n.link
                        ? ` data-link="${UI.escapeHtml(n.link)}" role="button"`
                        : (n.reference_type === 'crm_task' && n.reference_id ? ` data-crm-task="${Number(n.reference_id)}" role="button"` : '');
                    const text = isCrm ? `<b>${UI.escapeHtml(n.title || '')}</b><br>${UI.escapeHtml(n.message || '')}` : n.message;
                    remindersSection.append(`<div class="dropdown-item notification-item d-flex justify-content-between align-items-start small"${crmAttr}>
                        <div><i class="bi ${icon} me-1"></i>${text}</div>
                        <button class="btn btn-sm btn-link text-muted p-0 ms-2 dismiss-notification-header" data-id="${n.id}" title="رد کردن"><i class="bi bi-x"></i></button>
                    </div>`);
                });
            }
        } catch (e) { console.error('Error loading reminders:', e); }

        // 3. Update badge and visibility
        if (totalCount > 0) {
            countBadge.text(totalCount).show();
            bell.show();
        } else {
            countBadge.hide();
            bell.hide();
        }
    }

    function populatePersonSelectForReports() {
        const personSelect = $('#report-person-select').empty().append('<option></option>');
        personSelect.append('<optgroup label="مشتریان"></optgroup>');
        const bothTag = ' (مشتری و تأمین‌کننده)';
        (appCache.customers || []).forEach(c => personSelect.append(`<option value="customer-${c.id}">${UI.escapeHtml(c.name)}${Number(c.linked_supplier_id) > 0 ? bothTag : ''}</option>`));
        personSelect.append('<optgroup label="تامین‌کنندگان"></optgroup>');
        (appCache.suppliers || []).forEach(s => personSelect.append(`<option value="supplier-${s.id}">${UI.escapeHtml(s.name)}${Number(s.linked_customer_id) > 0 ? bothTag : ''}</option>`));
        personSelect.append('<optgroup label="شرکا"></optgroup>');
        (appCache.partners || []).forEach(p => personSelect.append(`<option value="partner-${p.id}">${UI.escapeHtml(p.name)}</option>`));
        personSelect.append('<optgroup label="سایر اشخاص"></optgroup>');
        (appCache.contacts || []).forEach(c => personSelect.append(`<option value="contact-${c.id}">${UI.escapeHtml(c.name)}</option>`));
        if (personSelect.data('select2')) { personSelect.select2('destroy'); }
        personSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب شخص..." });
    }

    function populateAccountSelectForReports() {
        const accountSelect = $('#report-account-select').empty().append('<option></option>');
        (appCache.accounts || []).forEach(acc => accountSelect.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`));
        if (accountSelect.data('select2')) { accountSelect.select2('destroy'); }
        accountSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب حساب..." });
    }

    function populateProductSelectForReports() {
        const productSelect = $('#report-product-select').empty().append('<option></option>');
        (appCache.products || []).forEach(p => {
            const manualIds = p.manual_serial_ids || [];
            const serialHint = manualIds.length ? ` [${manualIds.join(', ')}]` : '';
            productSelect.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}${serialHint}</option>`);
        });
        if (productSelect.data('select2')) { productSelect.select2('destroy'); }
        productSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب محصول..." });
    }

    // *** START FIX: Remove function populateEntitySelectForReports ***
    // function populateEntitySelectForReports() { ... } // Function removed
    // *** END FIX ***

    const PERSON_PHONE_SEP = ' / ';

    function splitPersonPhones(phone) {
        if (!phone) return [''];
        const parts = String(phone)
            .split(/\s*\/\s*|\s*،\s*|\s*,\s*|\n+/)
            .map(s => s.trim())
            .filter(Boolean);
        return parts.length ? parts : [''];
    }

    function collectPersonPhones(listSelector) {
        const phones = [];
        $(listSelector).find('.person-phone-input').each(function () {
            const v = String($(this).val() || '').trim();
            if (v) phones.push(v);
        });
        return phones.join(PERSON_PHONE_SEP);
    }

    function renderPersonPhoneFields(listSelector, phoneValue) {
        const list = $(listSelector).empty();
        const phones = splitPersonPhones(phoneValue);
        phones.forEach((phone, index) => {
            list.append(`
                <div class="input-group mb-1 person-phone-row">
                    <input type="tel" class="form-control person-phone-input" value="${UI.escapeHtml(phone)}" placeholder="شماره تلفن">
                    <button type="button" class="btn btn-outline-danger person-phone-remove" title="حذف">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `);
        });
    }

    function formatPhonesForList(phone) {
        if (!phone) return '';
        return UI.escapeHtml(String(phone)).replace(/\s*\/\s*/g, '<br>');
    }

    // Person who is both customer and supplier (linked records).
    function linkedPersonBadge(person) {
        if (!(Number(person.linked_id) > 0)) return '';
        return ' <span class="badge text-bg-info" title="این شخص هم مشتری و هم تأمین‌کننده است">مشتری و تأمین‌کننده</span>';
    }

    // Combined net of both sides, in the list's own convention:
    // customer list positive = owes us; supplier list positive = we owe them.
    function linkedNetHtml(person, ownType) {
        if (!(Number(person.linked_id) > 0)) return '';
        const net = Number(person.combined_balance) || 0;
        const other = ownType === 'customer' ? 'تأمین‌کننده' : 'مشتری';
        const owesUs = ownType === 'customer' ? net > 0.01 : net < -0.01;
        const weOwe = ownType === 'customer' ? net < -0.01 : net > 0.01;
        const text = owesUs ? `بدهکار ${UI.formatCurrency(Math.abs(net))}`
            : (weOwe ? `بستانکار ${UI.formatCurrency(Math.abs(net))}` : 'تسویه');
        return `<div class="small text-muted mt-1" title="مانده این بخش + مانده بخش ${other} همین شخص">خالص با حساب ${other}: <span class="fw-bold ${owesUs ? 'text-danger' : (weOwe ? 'text-success' : '')}">${text}</span></div>`;
    }

    function mergePersonButton(person, type) {
        return `<button class="btn btn-sm btn-outline-secondary btn-merge-person" data-person-id="${person.id}" data-person-type="${type}" data-person-name="${UI.escapeHtml(person.name || '')}" title="ادغام با شخص دیگر"><i class="bi bi-intersect"></i></button>`;
    }

    // «پروژه» in the expense form: shown only when the entity has active projects (میز کار budget).
    async function fillExpenseProjects(selectedId) {
        const $wrap = $('#expense-project-wrap');
        const $sel = $('#expense-project-id').empty().append('<option value="">— بدون پروژه —</option>');
        $sel.off('change.project').on('change.project', function () { $(this).data('changed', true); });
        const res = hasPermission('manage_transactions') ? await Api.call('work_project_options', {}, false) : null;
        const projects = (res && res.projects) || [];
        projects.forEach(p => $sel.append(`<option value="${p.id}">${UI.escapeHtml(p.title)}</option>`));
        // An expense linked to a finished project still shows its project.
        if (selectedId && !projects.some(p => String(p.id) === String(selectedId))) {
            $sel.append(`<option value="${Number(selectedId)}">پروژه #${Number(selectedId)}</option>`);
        }
        $sel.val(selectedId ? String(selectedId) : '');
        $wrap.toggleClass('d-none', projects.length === 0 && !selectedId);
    }

    function updateExpensePersonSelect(personType) {
        const sel = $('#expense-person-id').empty().append('<option value="">— بدون طرف‌حساب —</option>');
        if (!personType) {
            return;
        }
        const cache = appCache || {};
        const map = {
            customer: cache.customers || [],
            supplier: cache.suppliers || [],
            partner: cache.partners || [],
            contact: cache.contacts || []
        };
        (map[personType] || []).forEach(p => {
            sel.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`);
        });
    }

    function updateIncomePersonSelect(personType) {
        const sel = $('#income-person-id').empty().append('<option value="">— بدون طرف‌حساب —</option>');
        if (!personType) {
            return;
        }
        const cache = appCache || {};
        const map = {
            customer: cache.customers || [],
            supplier: cache.suppliers || [],
            partner: cache.partners || [],
            contact: cache.contacts || []
        };
        (map[personType] || []).forEach(p => {
            sel.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`);
        });
    }

    function initEntityManagers() {
        // تلفن‌های چندتایی مشتری/تامین‌کننده
        $('#customer-add-phone-btn').on('click', function () {
            $('#customer-phones-list').append(`
                <div class="input-group mb-1 person-phone-row">
                    <input type="tel" class="form-control person-phone-input" placeholder="شماره تلفن">
                    <button type="button" class="btn btn-outline-danger person-phone-remove" title="حذف"><i class="bi bi-trash"></i></button>
                </div>
            `);
        });
        $('#supplier-add-phone-btn').on('click', function () {
            $('#supplier-phones-list').append(`
                <div class="input-group mb-1 person-phone-row">
                    <input type="tel" class="form-control person-phone-input" placeholder="شماره تلفن">
                    <button type="button" class="btn btn-outline-danger person-phone-remove" title="حذف"><i class="bi bi-trash"></i></button>
                </div>
            `);
        });
        $('#contact-add-phone-btn').on('click', function () {
            $('#contact-phones-list').append(`
                <div class="input-group mb-1 person-phone-row">
                    <input type="tel" class="form-control person-phone-input" placeholder="شماره تلفن">
                    <button type="button" class="btn btn-outline-danger person-phone-remove" title="حذف"><i class="bi bi-trash"></i></button>
                </div>
            `);
        });
        $('body').on('click', '.person-phone-remove', function () {
            const list = $(this).closest('#customer-phones-list, #supplier-phones-list');
            const rows = list.find('.person-phone-row');
            if (rows.length <= 1) {
                rows.find('.person-phone-input').val('');
                return;
            }
            $(this).closest('.person-phone-row').remove();
        });

        registerManager('customers', createEntityManager({
            tableName: 'customers', apiName: 'customer', nameFa: 'مشتری',
            addBtnId: '#add-customer-btn', modalId: '#customerModal', formId: '#customer-form',
            renderTable: (data) => {
                PersonListUI.renderRows('customers', data, (c) => {
                    const finalBalance = parseFloat(c.net_balance);
                    const fallbackBalance = (parseFloat(c.initial_balance) || 0) + (parseFloat(c.total_unsettled) || 0) - (parseFloat(c.available_credit) || 0);
                    const balance = Number.isFinite(finalBalance) ? finalBalance : fallbackBalance;
                    let statusHtml = '';
                    if (balance > 0.01) {
                        statusHtml = `<span class="badge bg-danger">بدهکار: ${UI.formatCurrency(balance)}</span>`;
                    } else if (balance < -0.01) {
                        statusHtml = `<span class="badge bg-success">بستانکار: ${UI.formatCurrency(Math.abs(balance))}</span>`;
                    } else {
                        statusHtml = `<span class="badge bg-secondary">تسویه شده</span>`;
                    }
                    return {
                        name: UI.escapeHtml(c.name || '') + linkedPersonBadge(c),
                        phone: `<span class="small">${formatPhonesForList(c.phone)}</span>`,
                        job: UI.escapeHtml(c.job || '—'),
                        address: `<span class="small text-muted">${UI.escapeHtml(c.address || '—')}</span>`,
                        nationalId: UI.escapeHtml(c.nationalId || '—'),
                        initial_balance: UI.formatCurrency(c.initial_balance || 0),
                        total_unsettled: `<span class="text-danger">${UI.formatCurrency(c.total_unsettled || 0)}</span>`,
                        available_credit: `<span class="text-success">${UI.formatCurrency(c.available_credit || 0)}</span>`,
                        status: statusHtml + linkedNetHtml(c, 'customer'),
                        actions: `<div class="btn-group">
                            ${(parseFloat(c.available_credit) || 0) > 0.01 && (parseFloat(c.total_unsettled) || 0) > 0.01
                                ? `<button class="btn btn-sm btn-outline-success btn-allocate-credit" data-person-id="${c.id}" data-person-type="customer" title="اعمال اعتبار علی‌الحساب به فاکتورهای باز"><i class="bi bi-lightning-charge"></i></button>`
                                : ''}
                            ${hasPermission('use_crm') ? `<button class="btn btn-sm btn-outline-primary btn-crm-file" data-customer-id="${c.id}" title="پرونده CRM"><i class="bi bi-person-lines-fill"></i></button>` : ''}
                            ${mergePersonButton(c, 'customer')}
                            <button class="btn btn-sm btn-warning btn-edit" data-type="customer"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="customer" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                        </div>`,
                    };
                });
            },
            prepareModal: (customer = null) => {
                $('#customer-form')[0].reset();
                $('#customer-id').val(customer ? customer.id : '');
                $('#customer-name').val(customer ? customer.name : '');
                $('#customer-address').val(customer ? customer.address : '');
                renderPersonPhoneFields('#customer-phones-list', customer ? customer.phone : '');
                $('#customer-national-id').val(customer ? customer.nationalId : '');
                $('#customer-job').val(customer ? (customer.job || '') : '');
                $('#customer-is-both').prop('checked', !!(customer && Number(customer.linked_id || customer.linked_supplier_id) > 0));
                $('#customer-initial-balance').val(customer ? Number(customer.initial_balance).toLocaleString('en-US') : '0');
                $('#customer-credit-limit').val(customer && customer.credit_limit !== null && customer.credit_limit !== undefined && customer.credit_limit !== '' ? Number(customer.credit_limit).toLocaleString('en-US') : '');
                const groupSelect = $('#customer-group');
                groupSelect.find('option:not(:first)').remove();
                Api.call('list_lookup', { kind: 'customer_groups' }, false).then(res => {
                    (res?.items || []).forEach(g => groupSelect.append(`<option value="${g.id}">${UI.escapeHtml(g.name)}${Number(g.discount_percent) > 0 ? ` (${g.discount_percent}٪ تخفیف)` : ''}</option>`));
                    groupSelect.val(customer && customer.group_id ? String(customer.group_id) : '');
                });
                $('#customerModal').modal('show');
            },
            getFormData: () => ({
                id: $('#customer-id').val(),
                name: $('#customer-name').val(),
                address: $('#customer-address').val(),
                phone: collectPersonPhones('#customer-phones-list'),
                nationalId: $('#customer-national-id').val(),
                job: $('#customer-job').val(),
                is_both: $('#customer-is-both').is(':checked') ? 1 : 0,
                initial_balance: $('#customer-initial-balance').val().replace(/,/g, ''),
                credit_limit: ($('#customer-credit-limit').val() || '').replace(/,/g, ''),
                group_id: $('#customer-group').val() || null
            }),
            onSuccess: async (result, formData) => {
                const modal = $('#customerModal');
                if (result.warning) UI.showError(result.warning);
                await App.updateCache(['customers', 'suppliers']);
                if (managers.suppliers && (result.linked_id || formData.id)) managers.suppliers.load();

                if (modal.data('source') === 'sales-invoice') {
                    InvoiceManager.updatePersonDropdown('sales', { id: result.id, name: formData.name });
                } else {
                    managers['customers'].load();
                }
                modal.removeData('source');
            },
            refreshCacheKeys: ['customers']
        }));

        registerManager('suppliers', createEntityManager({
            tableName: 'suppliers', apiName: 'supplier', nameFa: 'تأمین‌کننده',
            addBtnId: '#add-supplier-btn', modalId: '#supplierModal', formId: '#supplier-form',
            renderTable: (data) => {
                PersonListUI.renderRows('suppliers', data, (s) => {
                    const finalBalance = parseFloat(s.net_balance);
                    const fallbackBalance = (parseFloat(s.initial_balance) || 0) + (parseFloat(s.total_unsettled) || 0) - (parseFloat(s.available_credit) || 0);
                    const balance = Number.isFinite(finalBalance) ? finalBalance : fallbackBalance;
                    let statusHtml = '';
                    if (balance > 0.01) {
                        statusHtml = `<span class="badge bg-danger">بستانکار: ${UI.formatCurrency(balance)}</span>`;
                    } else if (balance < -0.01) {
                        statusHtml = `<span class="badge bg-success">بدهکار: ${UI.formatCurrency(Math.abs(balance))}</span>`;
                    } else {
                        statusHtml = `<span class="badge bg-secondary">تسویه شده</span>`;
                    }
                    return {
                        name: UI.escapeHtml(s.name || '') + linkedPersonBadge(s),
                        phone: `<span class="small">${formatPhonesForList(s.phone)}</span>`,
                        job: UI.escapeHtml(s.job || '—'),
                        address: `<span class="small text-muted">${UI.escapeHtml(s.address || '—')}</span>`,
                        economicCode: UI.escapeHtml(s.economicCode || '—'),
                        initial_balance: UI.formatCurrency(s.initial_balance || 0),
                        total_unsettled: `<span class="text-danger">${UI.formatCurrency(s.total_unsettled || 0)}</span>`,
                        available_credit: `<span class="text-success">${UI.formatCurrency(s.available_credit || 0)}</span>`,
                        status: statusHtml + linkedNetHtml(s, 'supplier'),
                        actions: `<div class="btn-group">
                            ${(parseFloat(s.available_credit) || 0) > 0.01 && (parseFloat(s.total_unsettled) || 0) > 0.01
                                ? `<button class="btn btn-sm btn-outline-success btn-allocate-credit" data-person-id="${s.id}" data-person-type="supplier" title="اعمال اعتبار علی‌الحساب به فاکتورهای باز"><i class="bi bi-lightning-charge"></i></button>`
                                : ''}
                            ${mergePersonButton(s, 'supplier')}
                            <button class="btn btn-sm btn-warning btn-edit" data-type="supplier"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="supplier" data-id="${s.id}"><i class="bi bi-trash"></i></button>
                        </div>`,
                    };
                });
            },
            prepareModal: (supplier = null) => {
                $('#supplier-form')[0].reset();
                $('#supplier-id').val(supplier ? supplier.id : '');
                $('#supplier-name').val(supplier ? supplier.name : '');
                $('#supplier-address').val(supplier ? supplier.address : '');
                renderPersonPhoneFields('#supplier-phones-list', supplier ? supplier.phone : '');
                $('#supplier-economic-code').val(supplier ? supplier.economicCode : '');
                $('#supplier-job').val(supplier ? (supplier.job || '') : '');
                $('#supplier-is-both').prop('checked', !!(supplier && Number(supplier.linked_id || supplier.linked_customer_id) > 0));
                $('#supplier-initial-balance').val(supplier ? Number(supplier.initial_balance).toLocaleString('en-US') : '0');
                $('#supplierModal').modal('show');
            },
            getFormData: () => ({
                id: $('#supplier-id').val(),
                name: $('#supplier-name').val(),
                address: $('#supplier-address').val(),
                phone: collectPersonPhones('#supplier-phones-list'),
                economicCode: $('#supplier-economic-code').val(),
                job: $('#supplier-job').val(),
                is_both: $('#supplier-is-both').is(':checked') ? 1 : 0,
                initial_balance: $('#supplier-initial-balance').val().replace(/,/g, '')
            }),
            onSuccess: async (result, formData) => {
                const modal = $('#supplierModal');
                if (result.warning) UI.showError(result.warning);
                await App.updateCache(['suppliers', 'customers']);
                if (managers.customers && (result.linked_id || formData.id)) managers.customers.load();

                if (modal.data('source') === 'purchase-invoice') {
                    InvoiceManager.updatePersonDropdown('purchase', { id: result.id, name: formData.name });
                } else {
                    managers['suppliers'].load();
                }
                modal.removeData('source');
            },
            refreshCacheKeys: ['suppliers']
        }));

        registerManager('contacts', createEntityManager({
            tableName: 'contacts', apiName: 'contact', nameFa: 'سایر شخص',
            addBtnId: '#add-contact-btn', modalId: '#contactModal', formId: '#contact-form',
            renderTable: (data) => {
                PersonListUI.renderRows('contacts', data, (c) => {
                    const balance = parseFloat(c.net_balance);
                    let statusHtml = '';
                    if (balance > 0.01) {
                        statusHtml = `<span class="badge bg-danger">بستانکار: ${UI.formatCurrency(balance)}</span>`;
                    } else if (balance < -0.01) {
                        statusHtml = `<span class="badge bg-success">بدهکار: ${UI.formatCurrency(Math.abs(balance))}</span>`;
                    } else {
                        statusHtml = `<span class="badge bg-secondary">تسویه شده</span>`;
                    }
                    return {
                        name: UI.escapeHtml(c.name || ''),
                        phone: `<span class="small">${formatPhonesForList(c.phone)}</span>`,
                        job: UI.escapeHtml(c.job || '—'),
                        notes: `<span class="small text-muted">${UI.escapeHtml(c.notes || '—')}</span>`,
                        nationalId: UI.escapeHtml(c.nationalId || '—'),
                        initial_balance: UI.formatCurrency(c.initial_balance || 0),
                        status: statusHtml,
                        actions: `<div class="btn-group">
                            <button class="btn btn-sm btn-warning btn-edit" data-type="contact"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="contact" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                        </div>`,
                    };
                });
            },
            prepareModal: (contact = null) => {
                $('#contact-form')[0].reset();
                $('#contact-id').val(contact ? contact.id : '');
                $('#contact-name').val(contact ? contact.name : '');
                $('#contact-notes').val(contact ? (contact.notes || '') : '');
                renderPersonPhoneFields('#contact-phones-list', contact ? contact.phone : '');
                $('#contact-national-id').val(contact ? contact.nationalId : '');
                $('#contact-job').val(contact ? (contact.job || '') : '');
                $('#contact-initial-balance').val(contact ? Number(contact.initial_balance).toLocaleString('en-US') : '0');
                $('#contactModal').modal('show');
            },
            getFormData: () => ({
                id: $('#contact-id').val(),
                name: $('#contact-name').val(),
                notes: $('#contact-notes').val(),
                phone: collectPersonPhones('#contact-phones-list'),
                nationalId: $('#contact-national-id').val(),
                job: $('#contact-job').val(),
                initial_balance: $('#contact-initial-balance').val().replace(/,/g, '')
            }),
            onSuccess: async () => {
                await App.updateCache(['contacts']);
                managers['contacts'].load();
            },
            refreshCacheKeys: ['contacts']
        }));

        registerManager('products', ProductManager.init());

        // *** START FIX: Replaced 'refreshTables' with a more explicit 'onSuccess' handler ***
        registerManager('expenses', createEntityManager({
            tableName: 'expenses', apiName: 'expense', nameFa: 'هزینه',
            modalId: '#expenseModal', formId: '#expense-form',
            prepareModal: (expense = null) => {
                $('#expense-form')[0].reset();
                const accountSelect = $('#expense-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
                App.getCache().accounts.forEach(acc => {
                    accountSelect.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`);
                });
                const categorySelect = $('#expense-category').empty().append('<option value="">-- انتخاب کنید --</option>');
                const expenseCategories = (App.getCache().publicSettings.expense_categories || '').split(',').map(c => c.trim()).filter(Boolean);
                expenseCategories.forEach(cat => {
                    categorySelect.append(`<option value="${cat}">${cat}</option>`);
                });
                $('#expense-id').val(expense ? expense.id : '');

                if (expense && expense.date) {
                    const persianDateStr = UI.gregorianToPersian(expense.date);
                    $('#expense-date').val(persianDateStr);
                } else {
                    $('#expense-date').val(UI.today());
                }

                $('#expense-category').val(expense ? expense.category : '');
                $('#expense-amount').val(expense ? Number(expense.amount).toLocaleString('en-US') : '0');
                $('#expense-account-id').val(expense ? expense.account_id : '');
                $('#expense-description').val(expense ? expense.description : '');
                const personType = expense?.person_type || '';
                $('#expense-person-type').val(personType);
                updateExpensePersonSelect(personType);
                if (expense?.person_id) {
                    $('#expense-person-id').val(String(expense.person_id));
                }
                // Rows from the transactions list carry no project_id: then the link is only
                // sent if the user changes the field, so an edit never clears it by accident.
                $('#expense-project-id')
                    .data('known', !expense || Object.prototype.hasOwnProperty.call(expense, 'project_id'))
                    .data('changed', false);
                fillExpenseProjects(expense?.project_id || '');
                $('#expenseModal').modal('show');
            },
            getFormData: () => ({
                id: $('#expense-id').val(),
                category: $('#expense-category').val(),
                date: UI.toGregorian('#expense-date'),
                amount: $('#expense-amount').val().replace(/,/g, ''),
                account_id: $('#expense-account-id').val(),
                description: $('#expense-description').val(),
                person_type: $('#expense-person-type').val() || null,
                person_id: $('#expense-person-id').val() || null,
                // Sent only when the field is shown, so an expense keeps its project otherwise.
                ...(!$('#expense-project-wrap').hasClass('d-none') && ($('#expense-project-id').data('known') || $('#expense-project-id').data('changed'))
                    ? { project_id: $('#expense-project-id').val() || null } : {})
            }),
            onSuccess: async () => {
                // This custom handler ensures the correct managers are reloaded.
                await App.updateCache(['accounts']);
                await App.getManager('accounts').load();
                await App.getManager('transactions').load();
            }
        }));
        // *** END FIX ***

        registerManager('incomes', createEntityManager({
            tableName: 'incomes', apiName: 'income', nameFa: 'درآمد',
            modalId: '#incomeModal', formId: '#income-form',
            prepareModal: (income = null) => {
                $('#income-form')[0].reset();
                const accountSelect = $('#income-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
                App.getCache().accounts.forEach(acc => {
                    accountSelect.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`);
                });
                const categorySelect = $('#income-category').empty().append('<option value="">-- انتخاب کنید --</option>');
                const incomeCategories = (App.getCache().publicSettings.income_categories || '').split(',').map(c => c.trim()).filter(Boolean);
                incomeCategories.forEach(cat => {
                    categorySelect.append(`<option value="${cat}">${cat}</option>`);
                });
                $('#income-id').val(income ? income.id : '');

                if (income && income.date) {
                    $('#income-date').val(UI.gregorianToPersian(income.date));
                } else {
                    $('#income-date').val(UI.today());
                }

                $('#income-category').val(income ? income.category : '');
                $('#income-amount').val(income ? Number(income.amount).toLocaleString('en-US') : '0');
                $('#income-account-id').val(income ? income.account_id : '');
                $('#income-description').val(income ? income.description : '');
                const personType = income?.person_type || '';
                $('#income-person-type').val(personType);
                updateIncomePersonSelect(personType);
                if (income?.person_id) {
                    $('#income-person-id').val(String(income.person_id));
                }
                $('#incomeModal').modal('show');
            },
            getFormData: () => ({
                id: $('#income-id').val(),
                category: $('#income-category').val(),
                date: UI.toGregorian('#income-date'),
                amount: $('#income-amount').val().replace(/,/g, ''),
                account_id: $('#income-account-id').val(),
                description: $('#income-description').val(),
                person_type: $('#income-person-type').val() || null,
                person_id: $('#income-person-id').val() || null
            }),
            onSuccess: async () => {
                await App.updateCache(['accounts']);
                await App.getManager('accounts').load();
                await App.getManager('transactions').load();
            }
        }));

        $('#expense-person-type').on('change', function () {
            updateExpensePersonSelect($(this).val());
        });
        $('#income-person-type').on('change', function () {
            updateIncomePersonSelect($(this).val());
        });


        registerManager('users', createEntityManager({
            tableName: 'users', apiName: 'user', nameFa: 'کاربر',
            renderTable: (data) => {
                const body = $('#users-table-body').empty();
                if (!data || !data.length) return;
                const currentUser = Auth.getCurrentUser();
                data.forEach(user => {
                    let actions = '';
                    if (user.username !== currentUser) {
                        actions = `<button class="btn btn-sm btn-info btn-edit-access" data-id="${user.id}" title="ویرایش دسترسی‌ها"><i class="bi bi-shield-lock-fill"></i></button>
                                   <button class="btn btn-sm btn-warning btn-reset-password" data-id="${user.id}" data-username="${UI.escapeHtml(user.username)}"><i class="bi bi-key"></i></button> 
                                   <button class="btn btn-sm btn-danger btn-delete-user" data-id="${user.id}" data-username="${UI.escapeHtml(user.username)}"><i class="bi bi-trash"></i></button>`;
                    }
                    body.append(`<tr><td>${user.id}</td><td>${UI.escapeHtml(user.username)}</td><td>${actions}</td></tr>`);
                });
            },
            handleDelete: (id, username) => {
                UI.confirmAction(`آیا از حذف کاربر «${username}» مطمئن هستید؟`, async (confirmed) => {
                    if (confirmed) {
                        const result = await Api.call('delete_user', { userId: id });
                        if (result?.success) {
                            managers['users'].load();
                        }
                    }
                });
            }
        }));

        registerManager('activity_log', createEntityManager({
            tableName: 'activity_log',
            renderTable: (data) => {
                const body = $('#activity-log-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="4" class="text-center">هیچ فعالیتی ثبت نشده است.</td></tr>');
                data.forEach(log => body.append(`<tr><td>${UI.escapeHtml(log.username)}</td><td>${UI.escapeHtml(log.action_type)}</td><td>${UI.escapeHtml(log.description)}</td><td>${new Date(log.timestamp).toLocaleString('fa-IR')}</td></tr>`));
            }
        }));

        if (typeof ErrorLogManager !== 'undefined') {
            registerManager('error_log', ErrorLogManager);
        }

        registerManager('accounts', AccountManager);
        registerManager('transactions', TransactionManager);
        registerManager('checks', CheckManager);
        registerManager('settings', SettingsManager);
        registerManager('partners', PartnerManager);
        registerManager('access_control', AccessControlManager);
        registerManager('collections', CollectionManager);
        registerManager('price_list', PriceListManager);
        registerManager('invoices', InvoiceManager);
        registerManager('return_invoices', ReturnInvoiceManager);
        registerManager('quotations', QuotationManager);
        registerManager('backup', BackupManager);
        registerManager('inventory_audit', InventoryAuditManager);
        registerManager('balance_audit', BalanceAuditManager);
        registerManager('account_closing', PeriodClosingManager);

        for (const key in managers) {
            if (managers[key] && typeof managers[key].init === 'function') {
                managers[key].init();
            }
        }

        if (typeof PersonListUI !== 'undefined') {
            PersonListUI.init('customers', () => managers.customers && managers.customers.load());
            PersonListUI.init('suppliers', () => managers.suppliers && managers.suppliers.load());
            PersonListUI.init('contacts', () => managers.contacts && managers.contacts.load());
        }

        $('body').on('click', '.btn-delete-user', function (e) {
            e.preventDefault();
            managers['users'].handleDelete($(this).data('id'), $(this).data('username'));
        });

        $('body').on('click', '.btn-allocate-credit', async function (e) {
            e.preventDefault();
            const personId = $(this).data('person-id');
            const personType = $(this).data('person-type');
            if (!personId || !personType) return;
            UI.showLoader();
            const result = await Api.call('allocate_advance_credit', {
                person_id: personId,
                person_type: personType
            });
            UI.hideLoader();
            if (result?.success) {
                const allocated = parseFloat(result.allocated || 0);
                if (allocated > 0.01) {
                    UI.showSuccess(`مبلغ ${UI.formatCurrency(allocated)} به فاکتورهای باز تخصیص داده شد.`);
                } else {
                    UI.showSuccess('اعتبار قابل تخصیص یا فاکتور باز برای اعمال یافت نشد.');
                }
                await App.updateCache(['customers', 'suppliers']);
                if (managers.customers) managers.customers.load();
                if (managers.suppliers) managers.suppliers.load();
                if (typeof InvoiceManager !== 'undefined') {
                    InvoiceManager.load('sales_invoices');
                    InvoiceManager.load('purchase_invoices');
                }
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        // Merge two persons: a customer with a supplier becomes one person who is
        // both; two of the same type fold the selected one into the other.
        const mergeState = { type: null, id: 0, plan: null };
        const personTypeFa = (t) => (t === 'customer' ? 'مشتری' : 'تأمین‌کننده');
        const syncMergeSubmit = () => {
            const plan = mergeState.plan;
            const ok = plan && (plan.mode === 'link' || (plan.mode === 'merge' && $('#merge-persons-ack').is(':checked')));
            $('#merge-persons-submit').prop('disabled', !ok);
        };
        const renderMergePlan = (plan) => {
            const box = $('#merge-persons-plan').empty();
            $('#merge-persons-ack-wrap').toggle(plan?.mode === 'merge');
            $('#merge-persons-ack').prop('checked', false);
            if (!plan) return;
            if (plan.blocked) {
                box.html(`<div class="alert alert-warning mb-0">${UI.escapeHtml(plan.blocked)}</div>`);
                return;
            }
            const src = `«${UI.escapeHtml(plan.source.name)}» (${personTypeFa(plan.source.type)})`;
            const dst = `«${UI.escapeHtml(plan.target.name)}» (${personTypeFa(plan.target.type)})`;
            if (plan.mode === 'link') {
                box.html(`<div class="alert alert-info mb-0">
                    ${src} و ${dst} یک شخص می‌شوند: در هر دو بخش با برچسب «مشتری و تأمین‌کننده» نمایش داده می‌شود و حساب هر بخش جدا می‌ماند، با مانده خالص کنار آن.
                    <ul class="mb-0 mt-2 small">
                        <li>هیچ سندی جابه‌جا یا حذف نمی‌شود.</li>
                        <li>نام، آدرس و شغل ${dst} برای هر دو استفاده می‌شود و تلفن‌ها ترکیب می‌شوند.</li>
                        <li>برای جدا کردن بعدی، تیک «... هم هست» را در فرم ویرایش بردارید.</li>
                    </ul></div>`);
                return;
            }
            const docs = Object.entries(plan.documents || {});
            const docsHtml = docs.length
                ? `<ul class="mb-2 small">${docs.map(([label, n]) => `<li>${Number(n).toLocaleString('fa-IR')} ${UI.escapeHtml(label)}</li>`).join('')}</ul>`
                : '<div class="small mb-2">این شخص سندی ندارد.</div>';
            box.html(`<div class="alert alert-danger mb-0">
                همه اسناد ${src} به ${dst} منتقل می‌شود و ${src} حذف می‌شود:
                ${docsHtml}
                <div class="small">مانده اول دوره ${dst} پس از ادغام: <b>${UI.formatCurrency(plan.initial_balance || 0)}</b> (جمع هر دو)</div>
                ${plan.moves_link ? `<div class="small mt-1">اتصال ${src} به ${personTypeFa(plan.source.type === 'customer' ? 'supplier' : 'customer')} به ${dst} منتقل می‌شود.</div>` : ''}
            </div>`);
        };

        $('body').on('click', '.btn-merge-person', function (e) {
            e.preventDefault();
            mergeState.type = String($(this).data('person-type'));
            mergeState.id = Number($(this).data('person-id'));
            mergeState.plan = null;
            const name = String($(this).data('person-name') || '');
            $('#merge-persons-source').text(`${name} (${personTypeFa(mergeState.type)})`);

            const cache = App.getCache() || {};
            const sel = $('#merge-persons-target');
            if (sel.data('select2')) sel.select2('destroy');
            sel.empty().append('<option></option>');
            [['customer', 'مشتریان', cache.customers], ['supplier', 'تأمین‌کنندگان', cache.suppliers]].forEach(([type, label, list]) => {
                const group = $('<optgroup>').attr('label', label);
                (list || []).forEach(p => {
                    if (type === mergeState.type && Number(p.id) === mergeState.id) return;
                    group.append($('<option>').val(`${type}-${p.id}`).text(p.name || ''));
                });
                sel.append(group);
            });
            sel.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: 'جستجو و انتخاب شخص...', dropdownParent: $('#mergePersonsModal') });
            renderMergePlan(null);
            syncMergeSubmit();
            $('#mergePersonsModal').modal('show');

            // Common case: the same name was entered on the other side.
            const otherType = mergeState.type === 'customer' ? 'supplier' : 'customer';
            const twin = (cache[`${otherType}s`] || []).find(p => String(p.name || '').trim() === name.trim());
            if (twin) sel.val(`${otherType}-${twin.id}`).trigger('change');
        });

        $('#merge-persons-target').on('change', async function () {
            const [targetType, targetId] = String($(this).val() || '').split('-');
            mergeState.plan = null;
            renderMergePlan(null);
            syncMergeSubmit();
            if (!targetType || !targetId) return;
            const plan = await Api.call('merge_persons_plan', {
                source_type: mergeState.type, source_id: mergeState.id,
                target_type: targetType, target_id: Number(targetId)
            });
            if ($(this).val() !== `${targetType}-${targetId}`) return; // selection changed meanwhile
            mergeState.plan = plan && !plan.blocked ? plan : null;
            renderMergePlan(plan);
            syncMergeSubmit();
        });
        $('#merge-persons-ack').on('change', syncMergeSubmit);

        $('#merge-persons-submit').on('click', async function () {
            const plan = mergeState.plan;
            if (!plan) return;
            UI.showLoader();
            const result = await Api.call('merge_persons', {
                source_type: plan.source.type, source_id: plan.source.id,
                target_type: plan.target.type, target_id: plan.target.id
            });
            UI.hideLoader();
            if (!result?.success) return;
            $('#mergePersonsModal').modal('hide');
            UI.showSuccess(result.mode === 'link'
                ? `«${UI.escapeHtml(result.target.name)}» اکنون هم مشتری و هم تأمین‌کننده است.`
                : `«${UI.escapeHtml(result.source.name)}» در «${UI.escapeHtml(result.target.name)}» ادغام شد.`);
            await App.updateCache(['customers', 'suppliers']);
            if (managers.customers) managers.customers.load();
            if (managers.suppliers) managers.suppliers.load();
        });
    }

    function attachMainEvents() {
        $('body').on('click', '.pagination-container .page-link', function (e) {
            e.preventDefault();
            const page = $(this).data('page');
            const tableName = $(this).data('table');
            if (page && tableName) {
                const manager = getManager(tableName);
                const state = AppConfig.TABLE_STATES[tableName];
                if (state) {
                    state.currentPage = page;
                    if (manager) manager.load();
                    else InvoiceManager.load(tableName);
                }
            }
        });

        $('body').on('change', '.pagination-limit-select', function () {
            const limit = $(this).val();
            const tableName = $(this).data('table');
            const state = AppConfig.TABLE_STATES[tableName];

            if (state) {
                state.limit = parseInt(limit);
                state.currentPage = 1;

                const manager = App.getManager(tableName);
                if (manager) {
                    manager.load();
                } else if (tableName.includes('invoice')) {
                    InvoiceManager.load(tableName);
                }
            }
        });

        $('body').on('click', '.sortable-header', function () {
            const newSortBy = $(this).data('sort-by');
            const tableName = $(this).closest('table').attr('id').replace(/-table$/, '').replace(/-/g, '_');
            const manager = getManager(tableName);
            const state = AppConfig.TABLE_STATES[tableName];

            if (state) {
                if (state.sortBy === newSortBy) {
                    state.sortOrder = state.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    state.sortBy = newSortBy;
                    state.sortOrder = 'ASC';
                }
                state.currentPage = 1;

                if (manager) manager.load();
                else InvoiceManager.load(tableName);
            }
        });

        // همگام‌سازی فوری قبل از اتمام fade — جلوگیری از race روی تنظیمات/حسابرس
        $('#mainTab [data-bs-toggle="tab"]').on('show.bs.tab', function (e) {
            const paneTarget = $(e.target).attr('data-bs-target');
            syncMainTabUI(paneTarget);
        });

        // اگر تب از قبل active باشد Bootstrap کاری نمی‌کند؛ برای بازیابی حالت گیرکرده مجبوریم sync کنیم
        $('#mainTab').on('click', '[data-bs-toggle="tab"]', function () {
            const paneTarget = this.getAttribute('data-bs-target');
            if (paneTarget && this.classList.contains('active')) {
                syncMainTabUI(paneTarget);
            }
        });

        $('#mainTab [data-bs-toggle="tab"]').on('shown.bs.tab', async function (e) {
            $('.modal.show').modal('hide');
            const target = $(e.target);
            const tabId = target.attr('id');
            const tableName = target.data('table-name');
            const paneTarget = target.attr('data-bs-target');

            // جلوگیری از نمایش هم‌زمان چند تب اصلی (مثل حسابرس + تنظیمات)
            syncMainTabUI(paneTarget);

            if (appState.pendingFilter && appState.pendingFilter.tableName === tableName) {
                const { filters, message } = appState.pendingFilter;
                appState.pendingFilter = null;

                const manager = getManager(tableName);
                if (manager && typeof manager.load === 'function') {
                    await manager.load(filters, message);
                } else if (tableName.includes('invoice')) {
                    await InvoiceManager.load(tableName, filters, message);
                }
                return;
            }

            if (tabId === 'dashboard-tab' && hasPermission('view_dashboard')) {
                await Dashboard.load();
            } else if (tabId === 'consignment-tab' && hasPermission('manage_consignment')) {
                await InvoiceManager.load('consignment_sales');
                await InvoiceManager.load('consignment_purchases');
            } else if (tabId === 'return-invoices-tab' && hasPermission('manage_return_invoices')) {
                await ReturnInvoiceManager.load('return_invoices');
            } else if (tabId === 'quotations-tab' && hasPermission('manage_quotations')) {
                await QuotationManager.load();
            } else if (tabId === 'reports-tab' && hasPermission('view_reports')) {
                // *** START FIX: Removed call to populateEntitySelectForReports ***
                // No action needed here anymore, dropdown is gone.
                // *** END FIX ***
            } else if (tabId === 'tools-tab') {
                activateFirstVisibleToolsSubTab();
            } else if (tabId === 'settings-tab') {
                activateFirstVisibleSettingsSubTab();
                if (hasPermission('manage_settings')) {
                    try {
                        await getManager('settings').load();
                        await getManager('partners').load();
                        await getManager('users').load();
                        await getManager('activity_log').load();
                        await getManager('access_control').load();
                    } catch (err) {
                        console.error('Settings tab load failed:', err);
                        UI.hideLoader();
                        UI.showError('بارگذاری تنظیمات با خطا مواجه شد. دوباره روی تب تنظیمات کلیک کنید.');
                    }
                }
            } else if (tableName) {
                const manager = getManager(tableName);
                if (manager) {
                    await manager.load();
                } else if (tableName.includes('invoice')) {
                    await InvoiceManager.load(tableName);
                }
            }
        });

        $('body').on('click', '#header-price-list-btn', function (e) {
            e.preventDefault();
            const priceListTabButton = document.querySelector('#price-list-tab');
            if (priceListTabButton) {
                const tab = new bootstrap.Tab(priceListTabButton);
                tab.show();
            }
        });

        $('#report-type').on('change', function () {
            const reportType = $(this).val();
            $('#report-person-controls').toggle(reportType === 'persons');
            $('#report-account-controls').toggle(reportType === 'accounts');
            $('#report-product-controls').toggle(reportType === 'inventory-ledger');
            $('#report-date-filter-start, #report-date-filter-end').toggle(!['inventory', 'inventory-value'].includes(reportType));
            $('#cogs-calc-wrapper').toggle(reportType === 'profit-loss');
        }).trigger('change');

        $('#generate-report-btn').on('click', function () {
            const reportType = $('#report-type').val();
            const container = $('#report-results-container');
            ReportGenerator.generate(reportType, container);
        });

        $('#export-report-btn').on('click', function () {
            ReportGenerator.exportCsv();
        });

        // *** START FIX: Use 'hide.bs.modal' to fix aria-hidden warning ***
        $('body').on('hide.bs.modal', '.modal', function () {
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
        });
        // *** END FIX ***

        $(document).on('show.bs.modal', '.modal', function () {
            // مودال داخل tab-pane.fade باعث می‌شود صفحه پشت فرم هم محو شود.
            if (this.parentElement !== document.body) {
                document.body.appendChild(this);
            }
            $('.modal.modal-underlay').removeClass('modal-underlay');
            const openCount = $('.modal.show').not(this).length;
            $(this).css('z-index', 1055 + (openCount * 20));
        });
        $(document).on('shown.bs.modal', '.modal', function () {
            const z = parseInt($(this).css('z-index'), 10) || 1055;
            const backdrops = $('.modal-backdrop');
            if (backdrops.length > 1) {
                backdrops.not(':last').remove();
            }
            $('.modal-backdrop').last().css('z-index', Math.max(1040, z - 5));
            document.body.classList.add('modal-open');
        });
        $(document).on('hidden.bs.modal', '.modal', function () {
            $(this).css('z-index', '');
            $('.modal.modal-underlay').removeClass('modal-underlay');
            const stillOpen = $('.modal.show');
            if (stillOpen.length === 0) {
                $('.modal-backdrop').remove();
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
                return;
            }
            if ($('.modal-backdrop').length === 0) {
                $('<div class="modal-backdrop fade show"></div>').appendTo(document.body);
            } else if ($('.modal-backdrop').length > 1) {
                $('.modal-backdrop').not(':last').remove();
            }
            const z = parseInt(stillOpen.last().css('z-index'), 10) || 1055;
            $('.modal-backdrop').last().css('z-index', Math.max(1040, z - 5));
            document.body.classList.add('modal-open');
        });

        $('body').on('click', '.report-link', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const type = $(this).data('type');

            if (!id || !type) return;

            const openModal = $('.modal.show');
            if (openModal.length > 0) {
                openModal.modal('hide');
                await new Promise(resolve => setTimeout(resolve, 300));
            }

            UI.showLoader();
            let entityData;

            if (type === 'check') {
                entityData = await Api.call('get_entity_by_id', { entityType: 'payment', id: id, by: 'checkId' });
            } else {
                entityData = await Api.call('get_entity_by_id', { entityType: type, id: id });
            }
            UI.hideLoader();

            if (entityData) {
                if (type === 'salesInvoice') {
                    InvoiceManager.prepareInvoiceModal('sales', entityData);
                } else if (type === 'purchaseInvoice') {
                    InvoiceManager.prepareInvoiceModal('purchase', entityData);
                } else if (type === 'salesReturn' || type === 'purchaseReturn') {
                    // فاکتور مرجوعی
                    const returnType = type === 'salesReturn' ? 'sales_return' : 'purchase_return';
                    if (window.ReturnInvoiceManager) {
                        // Switch to return invoices tab
                        const returnInvoicesTab = document.querySelector('#return-invoices-tab');
                        if (returnInvoicesTab) {
                            const tab = new bootstrap.Tab(returnInvoicesTab);
                            tab.show();
                        }
                        // Wait a bit for tab to load, then open modal
                        setTimeout(() => {
                            ReturnInvoiceManager.openModal(returnType, entityData);
                        }, 300);
                    }
                } else if (type === 'expense') {
                    App.getManager('expenses').prepareModal(entityData);
                } else if (type === 'income') {
                    App.getManager('incomes').prepareModal(entityData);
                } else if (type === 'payment' || type === 'check') {
                    App.getManager('transactions').prepareTransactionModal(entityData);
                }
            }
        });

        $('body').on('click', '#dashboard .clickable-row', function () {
            const row = $(this);
            const reportType = row.data('report-type');

            if (reportType === 'persons') {
                const reportTabButton = document.querySelector('#mainTab button[data-bs-target="#reports"]');
                if (!reportTabButton) return;
                new bootstrap.Tab(reportTabButton).show();

                const personId = row.data('person-id');
                const personType = row.data('person-type');

                $('#report-type').val('persons').trigger('change');
                setTimeout(() => {
                    $('#report-person-select').val(`${personType}-${personId}`).trigger('change');
                    $('#generate-report-btn').click();
                }, 200);

            } else if (reportType === 'checks') {
                const checkNumber = row.data('check-number');
                const checksTabButton = document.querySelector('#mainTab button[data-bs-target="#checks"]');
                if (!checksTabButton) return;

                new bootstrap.Tab(checksTabButton).show();

                if (checkNumber) {
                    setTimeout(() => {
                        const searchInput = $('#check-search');
                        searchInput.val(checkNumber);
                        searchInput.trigger('input');
                    }, 250);
                }
            } else if (reportType === 'invoice') {
                const invoiceId = row.data('invoice-id');
                if (invoiceId) {
                    setPendingFilter('sales_invoices', { exactInvoiceId: invoiceId }, `نمایش فاکتور فروش شماره ${invoiceId}`);
                }
            } else if (reportType === 'purchase-invoice') {
                const invoiceId = row.data('invoice-id');
                if (invoiceId) {
                    setPendingFilter('purchase_invoices', { exactInvoiceId: invoiceId }, `نمایش فاکتور خرید شماره ${invoiceId}`);
                }
            }
        });

        $('body').on('click', '.btn-clear-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('ثبت پاس شدن چک');
            $('#cash-check-help-text').text('چک از کدام حساب برداشت (پاس) شد؟');
            $('#cash-check-date-label').text('تاریخ پاس شدن');
            $('#cash-check-id').val(checkId);
            $('#cash-check-date').val(UI.today());
            $('#cash-check-form').data('action', 'clear_payable_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`);
            });

            UI.initializeDatepickers();
            modal.modal('show');
        });

        $('body').on('click', '.btn-cash-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('وصول چک');
            $('#cash-check-help-text').text('چک به کدام حساب واریز شود؟');
            $('#cash-check-date-label').text('تاریخ وصول');
            $('#cash-check-id').val(checkId);
            $('#cash-check-date').val(UI.today());
            $('#cash-check-form').data('action', 'cash_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`);
            });

            UI.initializeDatepickers();
            modal.modal('show');
        });

        $('#cash-check-form').on('submit', async function (e) {
            e.preventDefault();
            const action = $(this).data('action');
            const cashedDate = UI.toGregorian('#cash-check-date');
            if (!cashedDate) {
                UI.showError('تاریخ وصول/پاس نامعتبر است.');
                return;
            }
            const data = {
                checkId: $('#cash-check-id').val(),
                accountId: $('#cash-check-account-id').val(),
                cashedDate: cashedDate
            };

            UI.showLoader();
            const result = await Api.call(action, data);
            UI.hideLoader();

            if (result?.success) {
                $('#cashCheckModal').modal('hide');
                UI.showSuccess('عملیات با موفقیت ثبت شد.');
                await App.updateCache(['checks', 'accounts']);
                App.getManager('checks').load();
                App.getManager('accounts').load();
                App.getManager('transactions').load();
            }
        });

        $('body').on('click', '#execute-print-btn', () => {
            window.print();
        });
    }

    /**
     * همگام‌سازی دکمه‌ها و paneهای تب اصلی.
     * Bootstrap با کلاس fade گاهی با تأخیر show را حذف/اضافه می‌کند و حالت
     * «دکمه تنظیمات active است ولی محتوا نیست» پیش می‌آید؛ کلیک بعدی هم no-op می‌شود.
     */
    function syncMainTabUI(activeTarget) {
        if (!activeTarget) return;

        $('#mainTabContent > .tab-pane').each(function () {
            const isTarget = ('#' + this.id) === activeTarget;
            this.classList.toggle('active', isTarget);
            this.classList.toggle('show', isTarget);
        });

        $('#mainTab [data-bs-toggle="tab"]').each(function () {
            const isTarget = this.getAttribute('data-bs-target') === activeTarget;
            this.classList.toggle('active', isTarget);
            if (this.getAttribute('role') === 'tab' || this.classList.contains('nav-link') || this.classList.contains('dropdown-item')) {
                this.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                if (isTarget) {
                    this.removeAttribute('tabindex');
                } else {
                    this.setAttribute('tabindex', '-1');
                }
            }
        });
    }

    // سازگاری با نام قبلی
    function syncMainTabPanes(activeTarget) {
        syncMainTabUI(activeTarget);
    }


    function activateFirstVisibleSettingsSubTab() {
        // فقط وقتی خود تب تنظیمات فعال است زیر تب فرعی را دست بزن
        if (!$('#settings-tab').hasClass('active') && !$('#settings').hasClass('active')) {
            return;
        }
        const visibleItems = $('#settings-sub-tab .nav-item:visible');
        if (!visibleItems.length) return;
        const activeInVisible = visibleItems.filter(function () {
            return $(this).find('.nav-link').hasClass('active');
        });
        if (activeInVisible.length) return;
        const firstLink = visibleItems.first().find('.nav-link');
        if (firstLink.length) {
            new bootstrap.Tab(firstLink[0]).show();
        }
    }


    function activateFirstVisibleToolsSubTab() {
        if (!$('#tools-tab').hasClass('active') && !$('#tools').hasClass('active')) {
            return;
        }
        const visibleItems = $('#tools-sub-tab .nav-item:visible');
        if (!visibleItems.length) return;
        const activeInVisible = visibleItems.filter(function () {
            return $(this).find('.nav-link').hasClass('active');
        });
        if (activeInVisible.length) return;
        const firstLink = visibleItems.first().find('.nav-link');
        if (firstLink.length) {
            new bootstrap.Tab(firstLink[0]).show();
        }
    }

    function initSessionExpiryWarning(expiresAt) {
        if (!expiresAt) return;
        const warnBeforeMs = 5 * 60 * 1000;
        let warned = false;

        const check = () => {
            const remaining = (expiresAt * 1000) - Date.now();
            if (remaining <= 0) return;
            if (!warned && remaining <= warnBeforeMs) {
                warned = true;
                UI.showInfo('نشست شما تا ۵ دقیقه دیگر منقضی می‌شود. برای ادامه کار، یک عملیات انجام دهید یا صفحه را رفرش کنید.', 'هشدار انقضای نشست', { timeOut: 15000 });
            }
        };

        setInterval(check, 60000);
        check();
    }

    function initPersonsDropdownMobileFix() {
        // On mobile, customers/suppliers are direct tabs (no dropdown).
        // Keep desktop dropdown working and sync active state.
        const btn = document.getElementById('persons-dropdown-btn');
        if (btn && window.bootstrap?.Dropdown) {
            bootstrap.Dropdown.getOrCreateInstance(btn, { autoClose: true });
        }

        const syncPersonsActive = (targetSelector) => {
            $('#persons-dropdown .dropdown-item').removeClass('active');
            $('#customers-tab-mobile, #suppliers-tab-mobile, #contacts-tab-mobile').removeClass('active');
            $(`#persons-dropdown .dropdown-item[data-bs-target="${targetSelector}"]`).addClass('active');
            $(`#mainTab > .nav-item > .nav-link[data-bs-target="${targetSelector}"]`).addClass('active');
            if (targetSelector === '#customers' || targetSelector === '#suppliers' || targetSelector === '#contacts') {
                $('#persons-dropdown-btn').addClass('active');
            } else {
                $('#persons-dropdown-btn').removeClass('active');
            }
        };

        $('#mainTab [data-bs-toggle="tab"]').on('shown.bs.tab.personsSync', function (e) {
            const target = $(e.target).data('bs-target') || $(e.target).attr('data-bs-target');
            if (!target) return;
            if (target === '#customers' || target === '#suppliers' || target === '#contacts') {
                syncPersonsActive(target);
                const dd = btn && bootstrap.Dropdown.getInstance(btn);
                if (dd) dd.hide();
            } else {
                $('#persons-dropdown-btn').removeClass('active');
                $('#persons-dropdown .dropdown-item').removeClass('active');
            }
        });
    }

    function initQuickNavigation() {
        if (typeof GlobalSearch !== 'undefined' && typeof GlobalSearch.init === 'function') {
            GlobalSearch.init();
        }
    }

    function initMainApp(sessionData) {
        appState.currentUser = sessionData.username;
        appState.currentEntityId = Number(sessionData.current_entity_id) || null;
        appState.businessEntities = sessionData.business_entities || [];
        appState.permissions = sessionData.permissions || [];
        appState.allowedEntities = normalizeEntityIds(sessionData.allowed_entities || []);

        applyPermissions();
        // همگام‌سازی تب اصلی تا فقط یک pane دیده شود (جلوگیری از قاطی شدن حسابرس/تنظیمات)
        const activeMainTarget = $('#mainTab [data-bs-toggle="tab"].active[data-bs-target]').first().attr('data-bs-target')
            || $('#mainTab .nav-link.active[data-bs-target]').first().attr('data-bs-target')
            || '#dashboard';
        syncMainTabUI(activeMainTarget);

        updateEntitySwitcherUI();

        // Event delegation for entity switcher (survives list rebuilds)
        $('#entity-switcher-list').off('click.entitySwitch').on('click.entitySwitch', '[data-entity-id]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            switchEntity($(this).data('entity-id'));
        });

        UI.initializeDatepickers();
        UI.initNumericFormatting();
        UI.init();
        initQuickNavigation();
        initPersonsDropdownMobileFix();
        initSessionExpiryWarning(sessionData.session_expires_at);

        if (hasPermission('view_reports')) {
            $('#report-start-date').val(UI.firstDayOfYear());
            $('#report-end-date').val(UI.today());
        }

        initEntityManagers();

        if (typeof SmartAuditManager !== 'undefined' && typeof SmartAuditManager.init === 'function') {
            SmartAuditManager.init();
        }
        if (typeof ToolsExtra !== 'undefined') {
            ToolsExtra.init();
        }

        if (hasPermission('view_reports')) {
            ReportGenerator.init();
        }

        $('#add-sales-invoice-btn').on('click', () => App.getManager('invoices').prepareInvoiceModal('sales'));
        $('#add-purchase-invoice-btn').on('click', () => App.getManager('invoices').prepareInvoiceModal('purchase'));

        // Header notification handlers
        $('body').on('click', '.dismiss-notification-header', async function (e) {
            e.stopPropagation();
            e.preventDefault();
            const id = $(this).data('id');
            const result = await Api.call('dismiss_notification', { id });
            if (result?.success) {
                $(this).closest('.notification-item').fadeOut(300, function () { $(this).remove(); });
                const count = parseInt($('#notification-count').text()) - 1;
                $('#notification-count').text(Math.max(0, count));
                if (count <= 0) $('#notification-count').hide();
            }
        });
        $('#generate-reminders-header-btn').on('click', async function (e) {
            e.stopPropagation();
            const result = await Api.call('generate_notifications');
            if (result?.success) {
                UI.showSuccess(result.message || 'یادآوری‌ها بررسی شد.');
                updateNotifications();
            }
        });

        fetchInitialCache({ runAutoBackup: true }).then(() => {
            if (hasPermission('view_dashboard')) {
                Dashboard.init();
                Dashboard.load();
                updateNotifications();
                notificationInterval = setInterval(updateNotifications, 300000);
            } else {
                loadActiveTab();
            }
        });
    }

    function init() {
        $(document).ready(function () {
            attachMainEvents();
            Auth.init();
        });
    }

    return {
        init,
        initMainApp,
        fetchInitialCache,
        updateCache,
        getManager,
        registerManager,
        getCache,
        getAppState,
        hasPermission,
        applyPermissions,
        updateAccess,
        setBusinessEntities,
        setPendingFilter // Expose the new function
    };

})();

App.init();
if (typeof AppClientLogger !== 'undefined') {
    AppClientLogger.init();
}
