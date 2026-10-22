// /js/tools-extra.js
// Tools → فروشندگان و گروه مشتری، هزینه تکراری و بودجه، سطل بازیافت.

const ToolsExtra = (function () {
    const esc = (v) => UI.escapeHtml(v == null ? '' : String(v));
    const money = (v) => UI.formatCurrency(Number(v) || 0);
    const faDate = (d) => (d ? UI.gregorianToPersian(String(d).slice(0, 10)) : '-');
    const freqLabel = { weekly: 'هفتگی', monthly: 'ماهانه', quarterly: 'سه‌ماهه', yearly: 'سالانه' };

    function expenseCategories() {
        const cache = App.getCache();
        const raw = (cache.publicSettings && cache.publicSettings.expense_categories) || (cache.settings && cache.settings.expense_categories) || '';
        return String(raw).split(',').map(c => c.trim()).filter(Boolean);
    }
    const accountOptions = () => (App.getCache().accounts || []).map(a => ({ value: a.id, label: a.name }));

    async function listKind(kind) {
        const res = await Api.call('list_lookup', { kind }, false);
        return res?.items || [];
    }

    async function saveKind(kind, item) {
        UI.showLoader();
        const res = await Api.call('save_lookup', { kind, item });
        UI.hideLoader();
        if (res?.success) UI.showSuccess('ذخیره شد.');
        return !!res?.success;
    }

    async function deleteKind(kind, id, label) {
        const ok = await UI.confirm(`«${esc(label)}» حذف شود؟`, 'حذف');
        if (!ok) return false;
        const res = await Api.call('delete_lookup', { kind, id });
        if (res?.success) UI.showSuccess('حذف شد.');
        return !!res?.success;
    }

    // ---------------- Salespeople ----------------
    let salespeople = [];
    async function loadSalespeople() {
        salespeople = await listKind('salespeople');
        const tb = $('#salespeople-tbody').empty();
        if (!salespeople.length) {
            tb.html('<tr><td colspan="5" class="text-center text-muted">فروشنده‌ای تعریف نشده است.</td></tr>');
            return;
        }
        salespeople.forEach(sp => tb.append(`<tr>
            <td>${esc(sp.name)}</td><td dir="ltr">${esc(sp.phone || '-')}</td><td>${Number(sp.commission_percent)}٪</td>
            <td>${sp.is_active == 1 ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-secondary">غیرفعال</span>'}</td>
            <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary edit-sp" data-id="${sp.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger del-sp" data-id="${sp.id}"><i class="bi bi-trash"></i></button></td></tr>`));
    }
    async function editSalesperson(sp = null) {
        const v = await UI.formDialog({
            title: sp ? 'ویرایش فروشنده' : 'فروشنده جدید',
            fields: [
                { name: 'name', label: 'نام', value: sp?.name, required: true },
                { name: 'phone', label: 'تلفن', value: sp?.phone },
                { name: 'commission_percent', label: 'درصد پورسانت از فروش خالص', type: 'number', value: sp ? sp.commission_percent : 0 },
                { name: 'is_active', label: 'فعال', type: 'checkbox', value: sp ? sp.is_active == 1 : true },
            ],
        });
        if (v && await saveKind('salespeople', { ...v, id: sp?.id, is_active: v.is_active ? 1 : 0 })) loadSalespeople();
    }

    // ---------------- Customer groups ----------------
    let groups = [];
    async function loadGroups() {
        groups = await listKind('customer_groups');
        const tb = $('#customer-groups-tbody').empty();
        if (!groups.length) {
            tb.html('<tr><td colspan="4" class="text-center text-muted">گروهی تعریف نشده است.</td></tr>');
            return;
        }
        groups.forEach(g => tb.append(`<tr><td>${esc(g.name)}</td><td>${Number(g.discount_percent)}٪</td><td>${Number(g.customers_count || 0)}</td>
            <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary edit-cg" data-id="${g.id}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger del-cg" data-id="${g.id}"><i class="bi bi-trash"></i></button></td></tr>`));
    }
    async function editGroup(g = null) {
        const v = await UI.formDialog({
            title: g ? 'ویرایش گروه مشتری' : 'گروه مشتری جدید',
            fields: [
                { name: 'name', label: 'نام گروه', value: g?.name, required: true, placeholder: 'مثلاً همکار، عمده، VIP' },
                { name: 'discount_percent', label: 'درصد تخفیف پیشنهادی', type: 'number', value: g ? g.discount_percent : 0 },
            ],
        });
        if (v && await saveKind('customer_groups', { ...v, id: g?.id })) loadGroups();
    }

    async function loadSalespersonReport() {
        const from = UI.toGregorian('#sp-report-from');
        const to = UI.toGregorian('#sp-report-to');
        const res = await Api.call('get_salesperson_report', { startDate: from, endDate: to });
        if (!res?.success) return;
        const tb = $('#sp-report-tbody').empty();
        if (!res.rows.length) {
            tb.html('<tr><td colspan="8" class="text-center text-muted">فروشنده‌ای تعریف نشده است.</td></tr>');
        }
        let tSales = 0, tComm = 0;
        res.rows.forEach(r => {
            tSales += Number(r.net_sales); tComm += Number(r.commission);
            tb.append(`<tr><td>${esc(r.name)}</td><td>${r.invoice_count}</td><td>${money(r.gross_sales)}</td><td>${money(r.returns_amount)}</td>
                <td class="fw-semibold">${money(r.net_sales)}</td><td>${money(r.collected)}</td><td>${Number(r.commission_percent)}٪</td><td class="text-success fw-semibold">${money(r.commission)}</td></tr>`);
        });
        if (res.rows.length) {
            tb.append(`<tr class="table-light fw-bold"><td colspan="4">جمع</td><td>${money(tSales)}</td><td></td><td></td><td>${money(tComm)}</td></tr>`);
        }
        if (Number(res.unassigned?.c) > 0) {
            tb.append(`<tr><td colspan="8" class="small text-muted">${Number(res.unassigned.c)} فاکتور به مبلغ ${money(res.unassigned.s)} بدون فروشنده ثبت شده است.</td></tr>`);
        }
    }

    // ---------------- Recurring expenses ----------------
    let recurring = [];
    async function loadRecurring() {
        recurring = await listKind('recurring_expenses');
        const tb = $('#recurring-expenses-tbody').empty();
        if (!recurring.length) {
            tb.html('<tr><td colspan="9" class="text-center text-muted">هزینه تکراری تعریف نشده است.</td></tr>');
            return;
        }
        const today = new Date().toISOString().slice(0, 10);
        recurring.forEach(r => {
            const due = r.is_active == 1 && r.next_date <= today;
            tb.append(`<tr class="${due ? 'table-warning' : ''}">
                <td>${esc(r.title)}</td><td>${esc(r.category)}</td><td>${money(r.amount)}</td><td>${esc(r.account_name || '-')}</td>
                <td>${freqLabel[r.frequency] || r.frequency}</td><td>${faDate(r.next_date)}${due ? ' <span class="badge bg-warning text-dark">سررسید</span>' : ''}</td>
                <td>${r.mode === 'auto' ? 'خودکار' : 'یادآوری'}</td>
                <td>${r.is_active == 1 ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-secondary">متوقف</span>'}</td>
                <td class="text-nowrap">
                    ${r.is_active == 1 ? `<button class="btn btn-sm btn-success run-re" data-id="${r.id}" title="ثبت هزینه این نوبت"><i class="bi bi-check2"></i> ثبت</button>` : ''}
                    <button class="btn btn-sm btn-outline-primary edit-re" data-id="${r.id}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger del-re" data-id="${r.id}"><i class="bi bi-trash"></i></button>
                </td></tr>`);
        });
    }
    async function editRecurring(r = null) {
        const cats = expenseCategories().map(c => ({ value: c, label: c }));
        const v = await UI.formDialog({
            title: r ? 'ویرایش هزینه تکراری' : 'هزینه تکراری جدید',
            fields: [
                { name: 'title', label: 'عنوان', value: r?.title, required: true, placeholder: 'مثلاً اجاره مغازه' },
                cats.length ? { name: 'category', label: 'دسته هزینه', type: 'select', options: cats, value: r?.category }
                    : { name: 'category', label: 'دسته هزینه', value: r?.category, required: true },
                { name: 'amount', label: 'مبلغ', type: 'money', value: r?.amount, required: true },
                { name: 'account_id', label: 'پرداخت از حساب', type: 'select', options: accountOptions(), value: r?.account_id, required: true },
                { name: 'frequency', label: 'تکرار', type: 'select', value: r?.frequency || 'monthly', options: Object.keys(freqLabel).map(k => ({ value: k, label: freqLabel[k] })) },
                { name: 'next_date', label: 'تاریخ نوبت بعدی', type: 'date', value: r?.next_date, required: true },
                { name: 'end_date', label: 'تاریخ پایان (اختیاری)', type: 'text', value: r?.end_date ? faDate(r.end_date) : '', placeholder: '1405/12/29' },
                { name: 'mode', label: 'حالت', type: 'select', value: r?.mode || 'remind', options: [{ value: 'remind', label: 'یادآوری (ثبت دستی)' }, { value: 'auto', label: 'ثبت خودکار در سررسید' }] },
                { name: 'description', label: 'توضیحات', type: 'textarea', value: r?.description },
                { name: 'is_active', label: 'فعال', type: 'checkbox', value: r ? r.is_active == 1 : true },
            ],
        });
        if (!v) return;
        if (await saveKind('recurring_expenses', { ...v, id: r?.id, is_active: v.is_active ? 1 : 0 })) loadRecurring();
    }
    async function runRecurring(r) {
        const v = await UI.formDialog({
            title: `ثبت هزینه «${r.title}»`,
            message: `<div class="small">مبلغ ${money(r.amount)} از حساب «${esc(r.account_name || '')}» کسر و به‌عنوان هزینه ثبت می‌شود.</div>`,
            fields: [{ name: 'date', label: 'تاریخ ثبت', type: 'date', value: r.next_date, required: true }],
            submitText: 'ثبت هزینه',
        });
        if (!v) return;
        UI.showLoader();
        const res = await Api.call('run_recurring_expense', { id: r.id, date: v.date });
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'ثبت شد.');
            loadRecurring();
            App.updateCache && App.updateCache(['accounts']);
        }
    }

    // ---------------- Budgets ----------------
    let budgets = [];
    async function loadBudgetReport() {
        const res = await Api.call('get_budget_report', { startDate: UI.toGregorian('#budget-report-from'), endDate: UI.toGregorian('#budget-report-to') });
        if (!res?.success) return;
        budgets = res.rows;
        const tb = $('#budget-report-tbody').empty();
        if (!res.rows.length) {
            tb.html('<tr><td colspan="7" class="text-center text-muted">در این بازه بودجه‌ای تعریف نشده است.</td></tr>');
        }
        res.rows.forEach(b => {
            const pct = Number(b.used_percent);
            const cls = pct > 100 ? 'bg-danger' : (pct > 80 ? 'bg-warning' : 'bg-success');
            tb.append(`<tr><td>${esc(b.category)}</td><td>${esc(b.period_label || '')} <small class="text-muted">${faDate(b.period_start)} تا ${faDate(b.period_end)}</small></td>
                <td>${money(b.amount)}</td><td>${money(b.actual)}</td><td class="${b.remaining < 0 ? 'text-danger' : ''}">${money(b.remaining)}</td>
                <td style="min-width:120px"><div class="progress" style="height:16px"><div class="progress-bar ${cls}" style="width:${Math.min(100, pct)}%">${pct}٪</div></div></td>
                <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary edit-bg" data-id="${b.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger del-bg" data-id="${b.id}"><i class="bi bi-trash"></i></button></td></tr>`);
        });
        if (res.rows.length) {
            tb.append(`<tr class="table-light fw-bold"><td colspan="2">جمع</td><td>${money(res.totals.budget)}</td><td>${money(res.totals.actual)}</td><td>${money(res.totals.remaining)}</td><td colspan="2"></td></tr>`);
        }
        const un = res.unbudgeted || [];
        $('#budget-unbudgeted').html(un.length
            ? '<strong>هزینه‌های بدون بودجه در این بازه:</strong> ' + un.map(u => `${esc(u.category)}: ${money(u.actual)}`).join(' — ')
            : '');
    }
    async function editBudget(b = null) {
        const cats = expenseCategories().map(c => ({ value: c, label: c }));
        const v = await UI.formDialog({
            title: b ? 'ویرایش بودجه' : 'بودجه جدید',
            fields: [
                cats.length ? { name: 'category', label: 'دسته هزینه', type: 'select', options: cats, value: b?.category }
                    : { name: 'category', label: 'دسته هزینه', value: b?.category, required: true },
                { name: 'period_label', label: 'عنوان دوره (اختیاری)', value: b?.period_label, placeholder: 'مثلاً مهر ۱۴۰۵' },
                { name: 'period_start', label: 'از تاریخ', type: 'date', value: b?.period_start, required: true },
                { name: 'period_end', label: 'تا تاریخ', type: 'date', value: b?.period_end, required: true },
                { name: 'amount', label: 'مبلغ بودجه', type: 'money', value: b?.amount, required: true },
            ],
        });
        if (v && await saveKind('budgets', { ...v, id: b?.id })) loadBudgetReport();
    }

    // ---------------- Recycle bin ----------------
    let trash = [];
    async function loadTrash() {
        const res = await Api.call('list_trash', { doc_type: $('#trash-type-filter').val() || '' }, false);
        trash = res?.items || [];
        const tb = $('#trash-tbody').empty();
        if (!trash.length) {
            tb.html('<tr><td colspan="6" class="text-center text-muted">موردی در سطل بازیافت نیست.</td></tr>');
            return;
        }
        trash.forEach(t => tb.append(`<tr class="${t.restored_at ? 'text-muted' : ''}">
            <td>${esc(t.title)}${t.restored_at ? ' <span class="badge bg-success">بازیابی شد</span>' : ''}</td>
            <td>${faDate(t.doc_date)}</td><td>${t.amount !== null ? money(t.amount) : '-'}</td>
            <td>${esc(t.deleted_by_name || '-')}</td><td>${faDate(t.deleted_at)} <small>${esc(String(t.deleted_at || '').slice(11, 16))}</small></td>
            <td class="text-nowrap">
                <button class="btn btn-sm btn-outline-info view-trash" data-id="${t.id}" title="مشاهده"><i class="bi bi-eye"></i></button>
                ${t.restorable ? `<button class="btn btn-sm btn-success restore-trash" data-id="${t.id}" title="بازیابی"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}
                ${t.reopenable ? `<button class="btn btn-sm btn-outline-primary reopen-trash" data-id="${t.id}" title="باز کردن به‌صورت سند جدید"><i class="bi bi-files"></i></button>` : ''}
            </td></tr>`));
    }

    async function getTrash(id) {
        const res = await Api.call('get_trash_item', { id });
        return res?.success ? res.item : null;
    }

    function snapshotHtml(item) {
        const snap = item.snapshot || {};
        const row = snap.row || {};
        const fields = Object.keys(row).filter(k => row[k] !== null && row[k] !== '').map(k => `<tr><td class="text-muted" dir="ltr">${esc(k)}</td><td>${esc(row[k])}</td></tr>`).join('');
        let extra = '';
        if (Array.isArray(snap.items) && snap.items.length) {
            extra += '<h6 class="mt-3">اقلام</h6><table class="table table-sm table-bordered"><tbody>' +
                snap.items.map(i => `<tr><td>${esc(i.productName || i.item_description || '')}</td><td>${esc(i.dimensions || '')}</td><td>${esc(i.quantity)}</td><td>${money(i.unitPrice)}</td></tr>`).join('') + '</tbody></table>';
        }
        if (Array.isArray(snap.payments) && snap.payments.length) {
            extra += '<h6 class="mt-3">پرداخت‌ها</h6><table class="table table-sm table-bordered"><tbody>' +
                snap.payments.map(p => `<tr><td>${faDate(p.date)}</td><td>${esc(p.type)}</td><td>${p.transaction_type === 'receipt' ? 'دریافت' : 'پرداخت'}</td><td>${money(p.amount)}</td><td>${esc(p.checkNumber || p.description || '')}</td></tr>`).join('') + '</tbody></table>';
        }
        return `<div class="table-responsive" style="max-height:420px"><table class="table table-sm">${fields}</table>${extra}</div>`;
    }

    async function reopenTrash(id) {
        const item = await getTrash(id);
        if (!item) return;
        const snap = item.snapshot || {};
        const row = { ...(snap.row || {}) };
        delete row.id;
        delete row.invoice_number;
        if (item.doc_type === 'sales_invoice' || item.doc_type === 'purchase_invoice') {
            const type = item.doc_type === 'sales_invoice' ? 'sales' : 'purchase';
            const inv = { ...row, items: (snap.items || []).map(i => { const c = { ...i }; delete c.id; delete c.invoiceId; return c; }), payments: [] };
            $('#mainTab button[data-bs-target="' + (type === 'sales' ? '#sales-invoices' : '#purchase-invoices') + '"]').tab('show');
            InvoiceManager.prepareInvoiceModal(type, inv);
            UI.showInfo('اقلام فاکتور حذف‌شده بارگذاری شد. پرداخت‌ها را دوباره ثبت و ذخیره کنید.');
        } else if (item.doc_type === 'quotation' && typeof QuotationManager !== 'undefined' && QuotationManager.prepareModal) {
            QuotationManager.prepareModal({ ...row, status: 'pending', items: (snap.items || []).map(i => { const c = { ...i }; delete c.id; return c; }) });
        }
    }

    function init() {
        // lazy loading per pane
        $('button[data-bs-target="#master-data-pane"]').on('shown.bs.tab', () => {
            if (App.hasPermission('manage_customers')) { loadSalespeople(); loadGroups(); }
            if (!$('#sp-report-from').val()) {
                const t = UI.today();
                $('#sp-report-from').val(t.slice(0, 8) + '01');
                $('#sp-report-to').val(t);
            }
        });
        $('button[data-bs-target="#recurring-budget-pane"]').on('shown.bs.tab', () => {
            loadRecurring();
            if (!$('#budget-report-from').val()) {
                $('#budget-report-from').val(UI.firstDayOfYear());
                $('#budget-report-to').val(UI.today().slice(0, 5) + '12/29');
            }
            loadBudgetReport();
        });
        $('button[data-bs-target="#trash-pane"]').on('shown.bs.tab', loadTrash);

        $('#add-salesperson-btn').on('click', () => editSalesperson());
        $('#salespeople-tbody').on('click', '.edit-sp', function () { editSalesperson(salespeople.find(x => x.id == $(this).data('id'))); });
        $('#salespeople-tbody').on('click', '.del-sp', async function () {
            const sp = salespeople.find(x => x.id == $(this).data('id'));
            if (sp && await deleteKind('salespeople', sp.id, sp.name)) loadSalespeople();
        });
        $('#add-customer-group-btn').on('click', () => editGroup());
        $('#customer-groups-tbody').on('click', '.edit-cg', function () { editGroup(groups.find(x => x.id == $(this).data('id'))); });
        $('#customer-groups-tbody').on('click', '.del-cg', async function () {
            const g = groups.find(x => x.id == $(this).data('id'));
            if (g && await deleteKind('customer_groups', g.id, g.name)) loadGroups();
        });
        $('#sp-report-btn').on('click', loadSalespersonReport);

        $('#add-recurring-expense-btn').on('click', () => editRecurring());
        $('#recurring-expenses-tbody').on('click', '.edit-re', function () { editRecurring(recurring.find(x => x.id == $(this).data('id'))); });
        $('#recurring-expenses-tbody').on('click', '.run-re', function () { runRecurring(recurring.find(x => x.id == $(this).data('id'))); });
        $('#recurring-expenses-tbody').on('click', '.del-re', async function () {
            const r = recurring.find(x => x.id == $(this).data('id'));
            if (r && await deleteKind('recurring_expenses', r.id, r.title)) loadRecurring();
        });
        $('#add-budget-btn').on('click', () => editBudget());
        $('#budget-report-btn').on('click', loadBudgetReport);
        $('#budget-report-tbody').on('click', '.edit-bg', function () { editBudget(budgets.find(x => x.id == $(this).data('id'))); });
        $('#budget-report-tbody').on('click', '.del-bg', async function () {
            const b = budgets.find(x => x.id == $(this).data('id'));
            if (b && await deleteKind('budgets', b.id, b.category)) loadBudgetReport();
        });

        $('#trash-type-filter, #trash-refresh-btn').on('change click', function (e) {
            if (e.type === 'click' && this.id !== 'trash-refresh-btn') return;
            loadTrash();
        });
        $('#trash-tbody').on('click', '.view-trash', async function () {
            const item = await getTrash($(this).data('id'));
            if (item) await UI.formDialog({ title: item.title || 'سند حذف‌شده', message: snapshotHtml(item), fields: [], submitText: 'بستن', size: 'modal-lg' });
        });
        $('#trash-tbody').on('click', '.restore-trash', async function () {
            const id = $(this).data('id');
            const ok = await UI.confirm('این مورد بازیابی شود؟ (با شناسه جدید ثبت می‌شود و در صورت هزینه/درآمد، موجودی حساب نیز اصلاح می‌شود)', 'بازیابی');
            if (!ok) return;
            const res = await Api.call('restore_trash_item', { id });
            if (res?.success) {
                UI.showSuccess(res.message || 'بازیابی شد.');
                loadTrash();
                App.fetchInitialCache && App.fetchInitialCache();
            }
        });
        $('#trash-tbody').on('click', '.reopen-trash', function () { reopenTrash($(this).data('id')); });
    }

    return { init };
})();
