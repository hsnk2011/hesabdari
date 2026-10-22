// js/smart-audit-manager.js — Smart Audit Center v2

const SmartAuditManager = {
    lastResult: null,
    lastRunId: null,
    issuesCache: [],
    ignoredCache: [],

    init: function () {
        $(document).off('.smartAudit');

        $(document).on('click.smartAudit', '#audit-center-tabs [data-audit-center]', function () {
            const panel = $(this).data('audit-center');
            SmartAuditManager.showCenterPanel(panel);
        });

        $(document).on('click.smartAudit', '#audit-run-btn', () => SmartAuditManager.runAudit());
        $(document).on('click.smartAudit', '#audit-export-excel-btn', () => SmartAuditManager.exportExcel());
        $(document).on('click.smartAudit', '#audit-compare-btn', () => SmartAuditManager.showCompare());
        $(document).on('click.smartAudit', '#fix-all-critical-btn', () => SmartAuditManager.fixAllCritical());
        $(document).on('click.smartAudit', '#audit-ignore-selected-btn', () => SmartAuditManager.ignoreSelected());

        $(document).on('change.smartAudit input.smartAudit', '#audit-filter-category, #audit-filter-severity, #audit-filter-compare, #audit-filter-search', () => {
            SmartAuditManager.applyFilters();
        });

        $(document).on('change.smartAudit', '.audit-issue-check', () => SmartAuditManager.updateBulkBar());

        $(document).on('click.smartAudit', '.audit-fix-btn', function () {
            const idx = $(this).data('idx');
            SmartAuditManager.resolveIssue(SmartAuditManager.issuesCache[idx]);
        });

        $(document).on('click.smartAudit', '.audit-guided-btn', function () {
            const idx = $(this).data('idx');
            SmartAuditManager.applyGuided(SmartAuditManager.issuesCache[idx]);
        });

        $(document).on('click.smartAudit', '.audit-nav-btn', function () {
            const idx = $(this).data('idx');
            SmartAuditManager.navigateTo(SmartAuditManager.issuesCache[idx]);
        });

        $(document).on('click.smartAudit', '.audit-ignore-one-btn', function () {
            const idx = $(this).data('idx');
            SmartAuditManager.ignoreIssues([SmartAuditManager.issuesCache[idx]]);
        });

        $(document).on('click.smartAudit', '.audit-restore-btn', function () {
            const id = $(this).data('id');
            SmartAuditManager.restoreIssues([id]);
        });

        $(document).on('click.smartAudit', '.audit-history-open', function () {
            const runId = $(this).data('run-id');
            SmartAuditManager.loadRun(runId);
        });

        $(document).on('click.smartAudit', '#redirect-to-smart-audit-inventory', function () {
            SmartAuditManager.openCenter('inventory_link');
        });
        $(document).on('click.smartAudit', '#redirect-to-smart-audit-balance', function () {
            SmartAuditManager.openCenter('balance');
        });
    },

    openCenter: function (panel) {
        const tab = document.querySelector('#smart-audit-tab');
        if (tab) new bootstrap.Tab(tab).show();
        setTimeout(() => {
            SmartAuditManager.showCenterPanel(panel || 'results');
            if (panel === 'inventory_link' && typeof InventoryAuditManager !== 'undefined') {
                InventoryAuditManager.load && InventoryAuditManager.load();
            }
            if (panel === 'balance' && typeof BalanceAuditManager !== 'undefined') {
                BalanceAuditManager.load && BalanceAuditManager.load();
            }
        }, 200);
    },

    showCenterPanel: function (panel) {
        $('#audit-center-tabs [data-audit-center]').removeClass('active');
        $(`#audit-center-tabs [data-audit-center="${panel}"]`).addClass('active');
        $('.audit-center-panel').addClass('d-none');
        $(`#audit-center-panel-${panel}`).removeClass('d-none');
        if (panel === 'history') SmartAuditManager.loadHistory();
        if (panel === 'inventory_link' && window.InventoryAuditManager?.load) InventoryAuditManager.load();
        if (panel === 'balance' && window.BalanceAuditManager?.load) BalanceAuditManager.load();
    },

    runAudit: async function () {
        $('#audit-empty-state').hide();
        $('#audit-content').hide();
        $('#audit-loading').show();
        this.showCenterPanel('results');

        try {
            const response = await Api.call('run_smart_audit');
            $('#audit-loading').hide();
            if (response && response.success) {
                this.lastResult = response;
                this.lastRunId = response.run_id;
                const durationSec = ((response.duration_ms || 0) / 1000).toFixed(1);
                $('#audit-last-run-info').show();
                $('#audit-last-run-time').text(new Date().toLocaleString('fa-IR'));
                $('#audit-run-duration').text(durationSec + ' ثانیه');
                $('#audit-run-id').text(response.run_id || '-');
                const cmp = response.compare?.counts || {};
                $('#audit-cmp-new').text(cmp.new || 0);
                $('#audit-cmp-recurring').text(cmp.recurring || 0);
                $('#audit-cmp-resolved').text(cmp.resolved || 0);
                this.renderResults(response.results || [], response.ignored || [], response.summary || {});
            } else {
                UI.showError(response?.error || 'خطا در اجرای حسابرسی');
            }
        } catch (e) {
            $('#audit-loading').hide();
            UI.showError('خطا در ارتباط با سرور');
            console.error(e);
        }
    },

    loadRun: async function (runId) {
        UI.showLoader();
        const res = await Api.call('get_audit_run', { run_id: runId });
        UI.hideLoader();
        if (!res?.success) {
            UI.showError(res?.error || 'اجرا یافت نشد');
            return;
        }
        this.lastRunId = runId;
        this.showCenterPanel('results');
        const summary = res.run.summary || {};
        this.renderResults(res.run.issues || [], [], summary);
        $('#audit-last-run-info').show();
        $('#audit-run-id').text(runId);
        $('#audit-last-run-time').text(res.run.started_at || '-');
        $('#audit-run-duration').text(((res.run.duration_ms || 0) / 1000).toFixed(1) + ' ثانیه');
    },

    loadHistory: async function () {
        const res = await Api.call('get_audit_runs', { limit: 20 });
        const list = $('#audit-history-list').empty();
        if (!res?.success || !res.runs?.length) {
            list.html('<div class="text-muted text-center py-4">تاریخی ثبت نشده است.</div>');
            return;
        }
        res.runs.forEach(run => {
            const s = run.summary || {};
            list.append(`
                <button type="button" class="list-group-item list-group-item-action audit-history-open" data-run-id="${run.id}">
                    <div class="d-flex justify-content-between">
                        <strong>اجرا #${run.id}</strong>
                        <span class="badge bg-${run.status === 'completed' ? 'success' : 'secondary'}">${UI.escapeHtml(run.status)}</span>
                    </div>
                    <small class="text-muted">${UI.escapeHtml(run.started_at || '')} — سلامت: ${s.health_score ?? '-'} — موارد: ${s.total ?? 0}</small>
                </button>
            `);
        });
    },

    showCompare: async function () {
        if (!this.lastRunId) {
            UI.showInfo('ابتدا یک حسابرسی اجرا کنید.');
            return;
        }
        UI.showLoader();
        const res = await Api.call('compare_audit_runs', { run_id: this.lastRunId });
        UI.hideLoader();
        if (!res?.success) {
            UI.showError(res?.error || 'خطا در مقایسه');
            return;
        }
        const c = res.compare.counts || {};
        UI.showInfo(`مقایسه با اجرای #${res.compare.previous_run_id || '—'}: جدید ${c.new || 0} | تکراری ${c.recurring || 0} | رفع‌شده ${c.resolved || 0}`);
        $('#audit-cmp-new').text(c.new || 0);
        $('#audit-cmp-recurring').text(c.recurring || 0);
        $('#audit-cmp-resolved').text(c.resolved || 0);
        $('#audit-filter-compare').val('new').trigger('change');
    },

    renderResults: function (results, ignored, summary) {
        this.issuesCache = results || [];
        this.ignoredCache = ignored || [];
        $('#audit-content').show();
        $('#audit-empty-state').hide();

        const critical = summary.critical ?? this.issuesCache.filter(i => i.severity === 'critical').length;
        const warning = summary.warning ?? this.issuesCache.filter(i => i.severity === 'warning').length;
        const info = summary.info ?? this.issuesCache.filter(i => i.severity === 'info').length;
        const W = (typeof AppConfig !== 'undefined' && AppConfig.AUDIT_SCORE_WEIGHTS) || { critical: 10, warning: 3, info: 1 };
        const score = summary.health_score ?? Math.max(0, 100 - critical * W.critical - warning * W.warning - info * W.info);

        $('#audit-critical-count').text(critical);
        $('#audit-warning-count').text(warning);
        $('#audit-info-count').text(info);
        $('#audit-health-score').text(score)
            .removeClass('text-success text-warning text-danger')
            .addClass(score >= 80 ? 'text-success' : score >= 50 ? 'text-warning' : 'text-danger');
        $('#audit-health-progress').css('width', score + '%')
            .removeClass('bg-success bg-warning bg-danger')
            .addClass(score >= 80 ? 'bg-success' : score >= 50 ? 'bg-warning' : 'bg-danger');

        const safeCritical = this.issuesCache.filter(i => i.severity === 'critical' && i.remediation_class === 'safe_auto');
        $('#fix-all-critical-btn').toggle(safeCritical.length > 0);

        this.renderIgnored(ignored);
        this.applyFilters();
    },

    applyFilters: function () {
        const cat = $('#audit-filter-category').val() || 'all';
        const sev = $('#audit-filter-severity').val() || 'all';
        const cmp = $('#audit-filter-compare').val() || 'all';
        const q = ($('#audit-filter-search').val() || '').toString().trim().toLowerCase();

        if (cat === 'ignored') {
            $('#audit-issues-grouped').empty();
            $('#audit-ignored-section').show();
            return;
        }
        $('#audit-ignored-section').toggle((this.ignoredCache || []).length > 0);

        let list = this.issuesCache.slice();
        if (cat !== 'all') list = list.filter(i => i.category === cat);
        if (sev !== 'all') list = list.filter(i => i.severity === sev);
        if (cmp !== 'all') list = list.filter(i => (i.compare_tag || 'new') === cmp);
        if (q) list = list.filter(i => ((i.message || '') + ' ' + (i.details || '') + ' ' + (i.rule_id || '')).toLowerCase().includes(q));

        this.renderGrouped(list);
    },

    renderGrouped: function (list) {
        const container = $('#audit-issues-grouped').empty();
        if (!list.length) {
            container.html('<div class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i>موردی با این فیلتر نیست.</div>');
            return;
        }
        const groups = {};
        list.forEach((issue, idx) => {
            // find original index in issuesCache
            const realIdx = this.issuesCache.indexOf(issue);
            const key = issue.rule_id || issue.type || 'other';
            if (!groups[key]) groups[key] = { title: issue.title || key, items: [] };
            groups[key].items.push({ issue, idx: realIdx >= 0 ? realIdx : idx });
        });

        Object.keys(groups).forEach(key => {
            const g = groups[key];
            const wrap = $(`<div class="mb-3 border rounded">
                <button type="button" class="btn btn-light w-100 text-start d-flex justify-content-between align-items-center audit-group-toggle">
                    <span><strong>${UI.escapeHtml(g.title)}</strong> <code class="text-muted">${UI.escapeHtml(key)}</code></span>
                    <span class="badge bg-secondary">${g.items.length}</span>
                </button>
                <div class="list-group list-group-flush audit-group-body"></div>
            </div>`);
            const body = wrap.find('.audit-group-body');
            g.items.forEach(({ issue, idx }) => body.append(this.createIssueItem(issue, idx)));
            wrap.find('.audit-group-toggle').on('click', function () {
                body.toggleClass('d-none');
            });
            container.append(wrap);
        });
        this.updateBulkBar();
    },

    createIssueItem: function (issue, idx) {
        const sevClass = issue.severity === 'critical' ? 'danger' : issue.severity === 'warning' ? 'warning' : 'info';
        const rem = issue.remediation_class || 'navigate_only';
        let actions = '';
        if (rem === 'safe_auto') {
            actions += `<button type="button" class="btn btn-sm btn-outline-primary audit-fix-btn" data-idx="${idx}"><i class="bi bi-wrench me-1"></i>اصلاح خودکار</button>`;
        } else if (rem === 'guided') {
            actions += `<button type="button" class="btn btn-sm btn-outline-primary audit-guided-btn" data-idx="${idx}"><i class="bi bi-magic me-1"></i>اصلاح راهنما</button>`;
        }
        actions += `<button type="button" class="btn btn-sm btn-outline-secondary audit-nav-btn" data-idx="${idx}"><i class="bi bi-box-arrow-up-left me-1"></i>برو به مورد</button>`;
        actions += `<button type="button" class="btn btn-sm btn-outline-danger audit-ignore-one-btn" data-idx="${idx}"><i class="bi bi-eye-slash"></i></button>`;

        const tag = issue.compare_tag ? `<span class="badge bg-light text-dark border ms-1">${UI.escapeHtml(issue.compare_tag)}</span>` : '';

        return $(`
            <div class="list-group-item">
                <div class="d-flex gap-2">
                    <input type="checkbox" class="form-check-input mt-1 audit-issue-check" data-idx="${idx}">
                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge bg-${sevClass}">${UI.escapeHtml(issue.severity || '')}</span>
                            <span class="badge bg-secondary">${UI.escapeHtml(issue.category || '')}</span>
                            ${tag}
                            <strong>${UI.escapeHtml(issue.message || '')}</strong>
                        </div>
                        <div class="small text-muted mb-2">${UI.escapeHtml(issue.details || '')}</div>
                        <div class="small text-muted mb-2">
                            ${UI.escapeHtml(issue.reference_table || '')} #${UI.escapeHtml(String(issue.reference_id ?? ''))}
                            ${issue.remediation_class ? ` · ${UI.escapeHtml(issue.remediation_class)}` : ''}
                        </div>
                        <div class="d-flex flex-wrap gap-1">${actions}</div>
                    </div>
                </div>
            </div>
        `);
    },

    renderIgnored: function (ignored) {
        const box = $('#audit-issues-list-ignored').empty();
        (ignored || []).forEach(row => {
            box.append(`
                <div class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                        <div><strong>${UI.escapeHtml(row.message || row.issue_type || '')}</strong></div>
                        <small class="text-muted">${UI.escapeHtml(row.details || '')} · ${UI.escapeHtml(row.reference_table || '')} #${UI.escapeHtml(String(row.reference_id ?? ''))}</small>
                        <div><small class="text-muted">${UI.escapeHtml(row.ignored_at || '')}</small></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary audit-restore-btn" data-id="${row.id}">بازگردانی</button>
                </div>
            `);
        });
    },

    updateBulkBar: function () {
        const n = $('.audit-issue-check:checked').length;
        $('#selected-count').text(n);
        const bar = $('#audit-bulk-actions');
        if (n > 0) bar.css('display', 'flex').show();
        else bar.hide();
    },

    ignoreSelected: function () {
        const issues = [];
        $('.audit-issue-check:checked').each(function () {
            const idx = $(this).data('idx');
            if (SmartAuditManager.issuesCache[idx]) issues.push(SmartAuditManager.issuesCache[idx]);
        });
        this.ignoreIssues(issues);
    },

    ignoreIssues: async function (issues) {
        if (!issues?.length) return;
        if (!await UI.confirmText(`${issues.length} مورد نادیده گرفته شود؟`)) return;
        const payload = issues.map(i => ({
            reference_table: i.reference_table,
            reference_id: String(i.reference_id),
            type: i.type || i.rule_id,
            fingerprint: i.fingerprint,
            meta: i.meta || {}
        }));
        UI.showLoader();
        const res = await Api.call('ignore_issues', { issues: payload });
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'انجام شد');
            this.runAudit();
        } else UI.showError(res?.error || 'خطا');
    },

    restoreIssues: async function (ids) {
        UI.showLoader();
        const res = await Api.call('restore_issues', { ids });
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'بازگردانی شد');
            this.runAudit();
        } else UI.showError(res?.error || 'خطا');
    },

    resolveIssue: async function (issue) {
        if (!issue) return;
        if (issue.remediation_class !== 'safe_auto') {
            UI.showError('این مورد اصلاح خودکار امن ندارد.');
            return;
        }
        if (!await UI.confirmText('اصلاح خودکار انجام شود؟\n' + (issue.message || ''))) return;
        const payload = {
            type: issue.type || issue.rule_id,
            rule_id: issue.rule_id || issue.type,
            reference_id: issue.reference_id,
            reference_table: issue.reference_table,
            dimensions: issue.meta?.dimensions,
            meta: issue.meta || {}
        };
        UI.showLoader();
        const res = await Api.call('resolve_audit_issue', payload);
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'اصلاح شد');
            this.runAudit();
        } else UI.showError(res?.error || 'خطا در اصلاح');
    },

    applyGuided: async function (issue) {
        if (!issue) return;
        const action = issue.remediation_action || issue.action_needed;
        if (action === 'link_purchase_invoice') {
            this.openCenter('inventory_link');
            UI.showInfo('از ویزارد لینک انبار مورد را متصل کنید. شناسه: ' + issue.reference_id);
            return;
        }
        if (action === 'unlock_user') {
            if (!await UI.confirmText('قفل کاربر باز شود؟')) return;
            UI.showLoader();
            const res = await Api.call('apply_guided_fix', {
                type: 'unlock_user',
                reference_id: issue.reference_id
            });
            UI.hideLoader();
            if (res?.success) {
                UI.showSuccess(res.message || 'انجام شد');
                this.runAudit();
            } else UI.showError(res?.error || 'خطا');
            return;
        }
        if (action === 'fix_check_account_link' || action === 'create_check_payment') {
            this.navigateTo(issue);
            UI.showInfo('لطفاً از صفحه چک‌ها مورد را به‌صورت دستی تکمیل کنید.');
            return;
        }
        this.navigateTo(issue);
    },

    fixAllCritical: async function () {
        const list = this.issuesCache.filter(i => i.severity === 'critical' && i.remediation_class === 'safe_auto');
        if (!list.length) return;
        if (!await UI.confirmText(`${list.length} مورد بحرانی امن اصلاح شود؟`)) return;
        UI.showLoader('در حال اصلاح...');
        let ok = 0, fail = 0;
        for (const issue of list) {
            const res = await Api.call('resolve_audit_issue', {
                type: issue.type || issue.rule_id,
                reference_id: issue.reference_id,
                reference_table: issue.reference_table,
                dimensions: issue.meta?.dimensions,
                meta: issue.meta || {}
            });
            if (res?.success) ok++; else fail++;
        }
        UI.hideLoader();
        UI.showSuccess(`اصلاح شد: ${ok} | ناموفق: ${fail}`);
        this.runAudit();
    },

    showMainTab: function (tabId) {
        if (!tabId) return false;
        const id = String(tabId).replace(/^#/, '');
        const btn = document.getElementById(id);
        if (btn) {
            new bootstrap.Tab(btn).show();
            return true;
        }
        return false;
    },

    showSettingsSub: function (paneId) {
        const settingsTab = document.querySelector('#settings-tab');
        if (settingsTab) new bootstrap.Tab(settingsTab).show();
        const target = String(paneId || '').replace(/^#/, '');
        setTimeout(() => {
            const btn = document.querySelector(`#settings-sub-tab button[data-bs-target="#${target}"]`);
            if (btn) new bootstrap.Tab(btn).show();
        }, 200);
    },

    showToolsSub: function (paneId) {
        const toolsTab = document.querySelector('#tools-tab');
        if (toolsTab) new bootstrap.Tab(toolsTab).show();
        const target = String(paneId || '').replace(/^#/, '');
        setTimeout(() => {
            const btn = document.querySelector(`#tools-sub-tab button[data-bs-target="#${target}"]`);
            if (btn) new bootstrap.Tab(btn).show();
        }, 200);
    },

    closeOpenModals: async function () {
        const openModal = $('.modal.show');
        if (openModal.length > 0) {
            openModal.modal('hide');
            await new Promise(resolve => setTimeout(resolve, 300));
        }
    },

    openEntityByType: async function (entityType, id) {
        if (!entityType || !id) return false;
        UI.showLoader();
        let data = null;
        try {
            if (entityType === 'check') {
                data = await Api.call('get_entity_by_id', { entityType: 'payment', id, by: 'checkId' });
            } else {
                data = await Api.call('get_entity_by_id', { entityType, id });
            }
        } finally {
            UI.hideLoader();
        }
        if (!data || data.error) return false;

        if (entityType === 'salesInvoice') {
            InvoiceManager.prepareInvoiceModal('sales', data);
            return true;
        }
        if (entityType === 'purchaseInvoice') {
            InvoiceManager.prepareInvoiceModal('purchase', data);
            return true;
        }
        if (entityType === 'salesReturn' || entityType === 'purchaseReturn') {
            const returnType = entityType === 'salesReturn' ? 'sales_return' : 'purchase_return';
            this.showMainTab('return-invoices-tab');
            setTimeout(() => {
                if (window.ReturnInvoiceManager) ReturnInvoiceManager.openModal(returnType, data);
            }, 300);
            return true;
        }
        if (entityType === 'expense') {
            App.getManager('expenses')?.prepareModal(data);
            return true;
        }
        if (entityType === 'income') {
            App.getManager('incomes')?.prepareModal(data);
            return true;
        }
        if (entityType === 'payment' || entityType === 'check') {
            App.getManager('transactions')?.prepareTransactionModal(data);
            return true;
        }
        return false;
    },

    resolveInvoiceItemParent: async function (itemTable, itemId) {
        const res = await Api.call('resolve_audit_nav', {
            reference_table: itemTable,
            reference_id: itemId
        });
        if (res?.success && res.open) return res.open;
        return null;
    },

    navigateTo: async function (issue) {
        if (!issue) {
            UI.showError('مورد یافت نشد.');
            return;
        }
        const nav = issue.nav || {};

        // Prefer remapped nav target (e.g. invoice item → parent invoice)
        let table = nav.table || issue.reference_table || '';
        let id = nav.reference_id != null ? nav.reference_id : issue.reference_id;
        id = id != null && id !== '' ? parseInt(id, 10) : 0;

        await this.closeOpenModals();

        // Settings sub-panes (users, bank import, closing, installments, …)
        if (nav.settings_sub) {
            this.showSettingsSub(nav.settings_sub);
            // Still try to open entity if we have a concrete payment/transaction
            if (table === 'payments' && id > 0) {
                await this.openEntityByType('payment', id);
            }
            return;
        }

        // Explicit tab overrides (e.g. consignment) before generic table handling
        if (nav.tab === 'consignment-tab') {
            this.showMainTab('consignment-tab');
            return;
        }

        // Center panels (inventory link wizard / balance analysis)
        if (nav.center_panel && nav.center_panel !== 'results') {
            this.openCenter(nav.center_panel);
            return;
        }

        // Resolve line-items → parent invoice
        if (table === 'sales_invoice_items' || table === 'purchase_invoice_items') {
            const open = await this.resolveInvoiceItemParent(table, id || issue.reference_id);
            if (open?.entityType && open?.id) {
                await this.openEntityByType(open.entityType, open.id);
                return;
            }
            UI.showInfo('فاکتور والد این ردیف یافت نشد.');
            return;
        }

        try {
            switch (table) {
                case 'sales_invoices':
                    if (await this.openEntityByType('salesInvoice', id)) return;
                    break;
                case 'purchase_invoices':
                    if (await this.openEntityByType('purchaseInvoice', id)) return;
                    break;
                case 'return_invoices': {
                    let ok = await this.openEntityByType('salesReturn', id);
                    if (!ok) ok = await this.openEntityByType('purchaseReturn', id);
                    if (ok) return;
                    break;
                }
                case 'payments':
                    if (await this.openEntityByType('payment', id)) return;
                    break;
                case 'expenses':
                    if (await this.openEntityByType('expense', id)) return;
                    break;
                case 'incomes':
                    if (await this.openEntityByType('income', id)) return;
                    break;
                case 'checks': {
                    // Prefer related payment modal; else check history
                    const opened = await this.openEntityByType('check', id);
                    if (opened) return;
                    this.showMainTab('checks-tab');
                    setTimeout(() => {
                        const mgr = App.getManager('checks');
                        if (mgr?.openCheckHistory) mgr.openCheckHistory(id);
                        else {
                            const numMatch = String(issue.message || '').match(/#(\S+)/);
                            const term = numMatch ? numMatch[1] : String(id);
                            $('#check-search').val(term).trigger('input');
                        }
                    }, 300);
                    return;
                }
                case 'accounts': {
                    this.showMainTab('accounts-tab');
                    const acc = (App.getCache()?.accounts || []).find(a => Number(a.id) === id);
                    if (acc) {
                        App.getManager('accounts')?.prepareModal(acc);
                        return;
                    }
                    this.openCenter('balance');
                    return;
                }
                case 'products': {
                    this.showMainTab('products-tab');
                    const product = (App.getCache()?.products || []).find(p => Number(p.id) === id);
                    if (product && App.getManager('products')?.prepareModal) {
                        App.getManager('products').prepareModal(product);
                        return;
                    }
                    UI.showInfo('محصول در کش یافت نشد؛ شناسه: ' + id);
                    return;
                }
                case 'customers': {
                    this.showMainTab('customers-tab');
                    const c = (App.getCache()?.customers || []).find(x => Number(x.id) === id);
                    if (c) {
                        App.getManager('customers')?.prepareModal(c);
                        return;
                    }
                    break;
                }
                case 'suppliers': {
                    this.showMainTab('suppliers-tab');
                    const s = (App.getCache()?.suppliers || []).find(x => Number(x.id) === id);
                    if (s) {
                        App.getManager('suppliers')?.prepareModal(s);
                        return;
                    }
                    break;
                }
                case 'inventory_items':
                    this.openCenter('inventory_link');
                    return;
                case 'users':
                    this.showSettingsSub('users-pane');
                    return;
                case 'installments':
                    this.showToolsSub('installments-pane');
                    return;
                case 'bank_import_items':
                    this.showToolsSub('bank-import-pane');
                    return;
                case 'settings':
                    this.showSettingsSub(nav.settings_sub || 'general-settings-pane');
                    return;
                default:
                    break;
            }
        } catch (e) {
            console.error('audit navigateTo', e);
        }

        // Fallback: switch tab if provided
        if (nav.tab && this.showMainTab(nav.tab)) {
            if (table === 'sales_invoices' && id > 0 && typeof App.setPendingFilter === 'function') {
                App.setPendingFilter('sales_invoices', { exactInvoiceId: id }, `نمایش فاکتور فروش #${id}`);
            } else if (table === 'purchase_invoices' && id > 0 && typeof App.setPendingFilter === 'function') {
                App.setPendingFilter('purchase_invoices', { exactInvoiceId: id }, `نمایش فاکتور خرید #${id}`);
            }
            return;
        }

        UI.showInfo('مورد: ' + (table || '') + (id ? (' #' + id) : ''));
    },

    exportExcel: function () {
        const rows = this.issuesCache || [];
        if (!rows.length) {
            UI.showInfo('نتیجه‌ای برای خروجی نیست.');
            return;
        }
        const header = ['rule_id', 'severity', 'category', 'message', 'details', 'reference_table', 'reference_id', 'remediation_class', 'compare_tag'];
        const lines = [header.join(',')];
        rows.forEach(i => {
            const cols = header.map(h => {
                let v = i[h] ?? '';
                v = String(v).replace(/"/g, '""');
                return `"${v}"`;
            });
            lines.push(cols.join(','));
        });
        const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `smart-audit-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
    },

    // legacy alias
    exportReport: function () { this.exportExcel(); }
};
