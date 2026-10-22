// /js/global-search.js
const GlobalSearch = (function () {
    const NAV_ITEMS = [
        { id: 'dashboard-tab', label: 'داشبورد', aliases: ['خانه', 'صفحه اصلی'], permission: 'view_dashboard', icon: 'bi-speedometer2' },
        { id: 'quotations-tab', label: 'پیش‌فاکتور', aliases: ['پیش فاکتور', 'پیشفاکتور', 'quotation'], permission: 'manage_quotations', icon: 'bi-file-earmark-text' },
        { id: 'sales-invoices-tab', label: 'فاکتور فروش', aliases: ['فروش', 'فاکتور'], permission: 'manage_sales_invoices', icon: 'bi-receipt-cutoff' },
        { id: 'purchase-invoices-tab', label: 'فاکتور خرید', aliases: ['خرید'], permission: 'manage_purchase_invoices', icon: 'bi-bag' },
        { id: 'return-invoices-tab', label: 'فاکتور مرجوعی', aliases: ['مرجوعی', 'برگشت'], permission: 'manage_return_invoices', icon: 'bi-arrow-return-left' },
        { id: 'products-tab', label: 'انبار و محصولات', aliases: ['انبار', 'محصول', 'شناسه', 'موجودی'], permission: 'manage_products', icon: 'bi-box-seam' },
        { id: 'consignment-tab', label: 'محصولات امانی', aliases: ['امانی'], permission: 'manage_consignment', icon: 'bi-truck' },
        { id: 'transactions-tab', label: 'تراکنشات', aliases: ['تراکنش', 'پرداخت', 'دریافت', 'هزینه'], permission: 'manage_transactions', icon: 'bi-arrow-left-right' },
        { id: 'checks-tab', label: 'چک‌ها', aliases: ['چک'], permission: 'manage_checks', icon: 'bi-bank' },
        { id: 'accounts-tab', label: 'بانک و صندوق', aliases: ['بانک', 'صندوق', 'حساب'], permission: 'manage_accounts', icon: 'bi-wallet2' },
        { id: 'customers-tab', label: 'مشتریان', aliases: ['مشتری'], permission: 'manage_customers', icon: 'bi-people' },
        { id: 'suppliers-tab', label: 'تأمین‌کنندگان', aliases: ['تامین', 'تأمین', 'فروشنده'], permission: 'manage_suppliers', icon: 'bi-truck' },
        { id: 'contacts-tab', label: 'سایر اشخاص', aliases: ['سایر شخص', 'پیمانکار', 'اشخاص'], permission: 'manage_contacts', icon: 'bi-person-badge' },
        { id: 'price-list-tab', label: 'لیست قیمت', aliases: ['قیمت'], permission: 'view_price_list', icon: 'bi-tags' },
        { id: 'reports-tab', label: 'گزارشات', aliases: ['گزارش'], permission: 'view_reports', icon: 'bi-bar-chart' },
        { id: 'smart-audit-tab', label: 'حسابرسی هوشمند', aliases: ['حسابرسی', 'ممیزی'], permission: 'manage_settings', icon: 'bi-shield-check' },
        { id: 'tools-tab', label: 'ابزارها', aliases: ['ابزار', 'انبارگردانی', 'اقساط'], permission: null, icon: 'bi-tools' },
        { id: 'settings-tab', label: 'تنظیمات', aliases: ['تنظیم'], permission: null, icon: 'bi-gear' }
    ];

    let flatItems = [];
    let activeIndex = -1;
    let requestSeq = 0;
    let lastRenderedQuery = '';

    function wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function normalizeQuery(raw) {
        let q = String(raw || '').trim();
        if (typeof UI !== 'undefined' && UI.toEnglishDigits) {
            q = UI.toEnglishDigits(q);
        }
        q = q.replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
        q = q.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        q = q.replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim();
        const prefixed = q.match(/^(?:فاکتور(?:\s*(?:فروش|خرید))?|پیش[\s-]*فاکتور|چک|هزینه|حواله|#)\s+#?\s*(.+)$/i);
        if (prefixed && /^\d[\d-]*$/.test(prefixed[1].replace(/\s/g, ''))) {
            q = prefixed[1].replace(/\s/g, '');
        }
        return q;
    }

    function canSeeNav(item) {
        if (item.permission && !App.hasPermission(item.permission)) return false;
        if (item.id === 'tools-tab') {
            const toolsPerms = ['manage_stocktaking', 'manage_installments', 'manage_bank_imports', 'import_export_data'];
            if (!toolsPerms.some((p) => App.hasPermission(p))) return false;
        }
        if (item.id === 'settings-tab') {
            const settingsPerms = ['manage_settings', 'manage_collections'];
            if (!settingsPerms.some((p) => App.hasPermission(p))) return false;
        }
        return true;
    }

    function navMatches(item, q) {
        if (!q) return true;
        const hay = (item.label + ' ' + (item.aliases || []).join(' ')).toLowerCase();
        return hay.includes(q.toLowerCase());
    }

    function showMainTab(tabId) {
        const all = Array.from(document.querySelectorAll('#mainTab [data-bs-toggle="tab"]'));
        let el = all.find((e) => e.id === tabId);
        if (!el && tabId === 'customers-tab') el = all.find((e) => e.getAttribute('data-table-name') === 'customers');
        if (!el && tabId === 'suppliers-tab') el = all.find((e) => e.getAttribute('data-table-name') === 'suppliers');
        if (!el && tabId === 'contacts-tab') el = all.find((e) => e.getAttribute('data-table-name') === 'contacts');
        if (!el) el = document.getElementById(tabId);
        if (el) new bootstrap.Tab(el).show();
        return !!el;
    }

    function fillTableSearch(selector, term) {
        const $el = $(selector);
        if ($el.length) {
            $el.val(term).trigger('input');
        }
    }

    function cacheFind(listName, id) {
        const list = (App.getCache()[listName] || []);
        return list.find((x) => Number(x.id) === Number(id));
    }

    async function closeSearchModal() {
        const el = document.getElementById('quickNavModal');
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) {
            inst.hide();
            await wait(250);
        }
    }

    function highlightInventoryRow(inventoryId, serialLabel) {
        const $rows = $('#serialized-items-container tbody tr');
        let $hit = $rows.filter(`[data-inventory-id="${inventoryId}"]`);
        if (!$hit.length && serialLabel) {
            $hit = $rows.filter(function () {
                return ($(this).text() || '').indexOf(serialLabel) !== -1;
            }).first();
        }
        if ($hit.length) {
            $hit.addClass('table-warning');
            if ($hit[0].scrollIntoView) $hit[0].scrollIntoView({ block: 'center' });
        }
    }

    async function openItem(item) {
        if (!item) return;
        await closeSearchModal();
        if (item.tab) showMainTab(item.tab);
        await wait(120);

        switch (item.type) {
            case 'section':
                return;
            case 'customer': {
                const rec = cacheFind('customers', item.id);
                if (rec && App.getManager('customers')?.prepareModal) {
                    App.getManager('customers').prepareModal(rec);
                } else {
                    fillTableSearch('#customer-search', item.title);
                }
                return;
            }
            case 'supplier': {
                const rec = cacheFind('suppliers', item.id);
                if (rec && App.getManager('suppliers')?.prepareModal) {
                    App.getManager('suppliers').prepareModal(rec);
                } else {
                    fillTableSearch('#supplier-search', item.title);
                }
                return;
            }
            case 'product':
            case 'serial': {
                const productId = item.type === 'serial' ? item.product_id : item.id;
                const rec = cacheFind('products', productId);
                const mgr = App.getManager('products');
                if (rec && mgr?.prepareModal) {
                    await mgr.prepareModal(rec);
                    const inventoryTab = document.querySelector('#product-inventory-tab');
                    if (item.type === 'serial' && inventoryTab) {
                        new bootstrap.Tab(inventoryTab).show();
                        highlightInventoryRow(item.id, item.serial_id);
                    }
                } else {
                    fillTableSearch('#product-search', item.serial_id || item.title);
                }
                return;
            }
            case 'sales_invoice':
            case 'consignment_sales': {
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType: 'salesInvoice', id: item.id });
                UI.hideLoader();
                if (data) InvoiceManager.prepareInvoiceModal('sales', data);
                return;
            }
            case 'purchase_invoice':
            case 'consignment_purchase': {
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType: 'purchaseInvoice', id: item.id });
                UI.hideLoader();
                if (data) InvoiceManager.prepareInvoiceModal('purchase', data);
                return;
            }
            case 'quotation': {
                UI.showLoader();
                const data = await Api.call('get_quotation', { id: item.id });
                UI.hideLoader();
                if (data && !data.error) App.getManager('quotations')?.prepareModal(data);
                return;
            }
            case 'sales_return':
            case 'purchase_return': {
                const entityType = item.type === 'purchase_return' ? 'purchaseReturn' : 'salesReturn';
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType, id: item.id });
                UI.hideLoader();
                if (data && window.ReturnInvoiceManager) {
                    ReturnInvoiceManager.openModal(item.type, data);
                }
                return;
            }
            case 'check': {
                fillTableSearch('#check-search', item.title.replace(/^چک\s+/, ''));
                return;
            }
            case 'account': {
                const mgr = App.getManager('accounts');
                if (mgr?.loadAccountTransactions) {
                    mgr.loadAccountTransactions(item.id);
                }
                return;
            }
            case 'expense': {
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType: 'expense', id: item.id });
                UI.hideLoader();
                if (data) App.getManager('expenses')?.prepareModal(data);
                return;
            }
            case 'income': {
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType: 'income', id: item.id });
                UI.hideLoader();
                if (data) App.getManager('incomes')?.prepareModal(data);
                return;
            }
            case 'payment': {
                UI.showLoader();
                const data = await Api.call('get_entity_by_id', { entityType: 'payment', id: item.id });
                UI.hideLoader();
                if (data) App.getManager('transactions')?.prepareTransactionModal(data);
                return;
            }
            default:
                return;
        }
    }

    function setActive(index) {
        if (!flatItems.length) {
            activeIndex = -1;
            $('#quick-nav-list .gs-item').removeClass('active');
            return;
        }
        activeIndex = (index + flatItems.length) % flatItems.length;
        const nodes = $('#quick-nav-list .gs-item');
        nodes.removeClass('active');
        const current = nodes.eq(activeIndex);
        current.addClass('active');
        if (current[0]?.scrollIntoView) current[0].scrollIntoView({ block: 'nearest' });
    }

    function render(query, groups, loading) {
        const list = $('#quick-nav-list').empty();
        flatItems = [];
        const q = (query || '').trim();

        const navHits = NAV_ITEMS.filter((item) => canSeeNav(item) && navMatches(item, q));
        if (navHits.length) {
            list.append('<div class="gs-group-title"><i class="bi bi-grid me-1"></i>بخش‌ها</div>');
            navHits.forEach((item) => {
                const idx = flatItems.length;
                flatItems.push({ type: 'section', tab: item.id, title: item.label });
                list.append(
                    `<button type="button" class="list-group-item list-group-item-action gs-item" data-gs-index="${idx}">
                        <span class="gs-item-main"><i class="bi ${item.icon || 'bi-folder'} me-2 text-muted"></i>${UI.escapeHtml(item.label)}</span>
                        <span class="gs-item-hint">بخش</span>
                    </button>`
                );
            });
        }

        if (loading) {
            list.append('<div class="list-group-item text-muted text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>در حال جستجو...</div>');
        } else if (Array.isArray(groups)) {
            groups.forEach((group) => {
                if (!group?.items?.length) return;
                list.append(`<div class="gs-group-title"><i class="bi ${UI.escapeHtml(group.icon || 'bi-search')} me-1"></i>${UI.escapeHtml(group.label)}</div>`);
                group.items.forEach((row) => {
                    const idx = flatItems.length;
                    flatItems.push(row);
                    list.append(
                        `<button type="button" class="list-group-item list-group-item-action gs-item" data-gs-index="${idx}">
                            <span class="gs-item-main">${UI.escapeHtml(row.title || '')}</span>
                            <span class="gs-item-sub">${UI.escapeHtml(row.subtitle || '')}</span>
                        </button>`
                    );
                });
            });
        }

        if (!flatItems.length && !loading) {
            if (!q) {
                list.append('<div class="list-group-item text-muted text-center">برای جستجو در کل برنامه تایپ کنید</div>');
            } else {
                list.append('<div class="list-group-item text-muted text-center">موردی یافت نشد</div>');
            }
        }

        setActive(flatItems.length ? 0 : -1);
        lastRenderedQuery = q;
    }

    async function runSearch(raw) {
        const q = normalizeQuery(raw);
        const seq = ++requestSeq;
        if (/^INV-?$/i.test(q)) {
            render(q, [], false);
            return;
        }
        const shouldQueryApi = q.length >= 2 || /^\d+$/.test(q) || /^INV-?\d+$/i.test(q);

        render(q, [], shouldQueryApi);
        if (!shouldQueryApi) return;

        const result = await Api.call('global_search', { query: q });
        if (seq !== requestSeq) return;
        if (result && result.groups) {
            render(q, result.groups, false);
        } else {
            render(q, [], false);
        }
    }

    const debouncedSearch = UI.debounce((value) => runSearch(value), 250);

    function openPalette() {
        const modalEl = document.getElementById('quickNavModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        setTimeout(() => {
            const input = document.getElementById('quick-nav-search');
            if (input) {
                input.focus();
                input.select();
            }
        }, 180);
    }

    function init() {
        const $input = $('#quick-nav-search');

        $(document).on('keydown.globalSearch', function (e) {
            const key = (e.key || '').toLowerCase();
            const modalOpen = $('#quickNavModal').hasClass('show');

            if ((e.ctrlKey || e.metaKey) && key === 'k') {
                if ($('#login-overlay').is(':visible')) return;
                e.preventDefault();
                openPalette();
                return;
            }

            if (!modalOpen) return;

            if (key === 'arrowdown') {
                e.preventDefault();
                setActive(activeIndex + 1);
            } else if (key === 'arrowup') {
                e.preventDefault();
                setActive(activeIndex - 1);
            } else if (key === 'enter') {
                e.preventDefault();
                if (activeIndex >= 0 && flatItems[activeIndex]) openItem(flatItems[activeIndex]);
            }
        });

        $input.on('input', function () {
            debouncedSearch($(this).val());
        });

        $('#quickNavModal').on('shown.bs.modal', function () {
            if (!$input.val()) {
                render('', [], false);
            }
            $input.trigger('focus');
        });

        $('body').on('click', '#quick-nav-list .gs-item', function () {
            const idx = Number($(this).data('gs-index'));
            if (flatItems[idx]) openItem(flatItems[idx]);
        });

        $('#quick-nav-btn').on('click', function () {
            openPalette();
        });
    }

    return { init, open: openPalette };
})();
