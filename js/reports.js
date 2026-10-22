// /js/reports.js

const ReportGenerator = (function () {
    let appDataCache = {};
    let lastReportData = null;
    let lastReportType = null;
    let lastReportParams = {};

    const formatCurrency = (num) => UI.formatCurrency(num);

    function _formatInvoiceItemLabel(item) {
        const isService = item.is_service == 1;
        const name = isService
            ? (item.item_description || item.productName || 'خدمات')
            : (item.productName || 'حذف شده');
        if (isService) {
            return `${name} <span class="badge bg-info ms-1">خدمات</span>`;
        }
        return item.dimensions ? `${name} (${item.dimensions})` : name;
    }

    function _formatInventorySerialIds(rawValue) {
        if (!rawValue) {
            return '<span class="text-muted">-</span>';
        }

        const codes = Array.isArray(rawValue) ? rawValue : String(rawValue).split(',');
        const formattedCodes = codes
            .map(code => {
                const cleaned = String(code).trim();
                if (!cleaned) return '';
                if (/^INV-\d+$/i.test(cleaned)) return cleaned.toUpperCase();
                if (/^\d+$/.test(cleaned)) return `INV-${cleaned.padStart(6, '0')}`;
                return cleaned;
            })
            .filter(Boolean);

        if (!formattedCodes.length) {
            return '<span class="text-muted">-</span>';
        }

        return `<div class="d-flex flex-wrap gap-1">${formattedCodes
            .map(code => `<span class="badge bg-light text-dark border">${UI.escapeHtml(code)}</span>`)
            .join('')}</div>`;
    }

    function _isNarrowViewport() {
        return typeof window.matchMedia === 'function'
            ? window.matchMedia('(max-width: 991px)').matches
            : (window.innerWidth || 0) <= 991;
    }

    /** Wrap bare tables so wide reports scroll inside the strip, not the page. */
    function _ensureReportTablesScrollable(container) {
        const $root = container && container.jquery ? container : $(container);
        if (!$root.length) return;
        $root.find('table').each(function () {
            const $table = $(this);
            if ($table.closest('.table-responsive, .report-table-scroll, .dataTables_wrapper, .dataTables_scroll').length) {
                return;
            }
            $table.wrap('<div class="table-responsive report-table-scroll"></div>');
        });
    }

    function _initDataTable(selector, options = {}) {
        if ($.fn.DataTable.isDataTable(selector)) {
            $(selector).DataTable().destroy();
        }
        const $table = $(selector);
        const narrow = _isNarrowViewport();
        // On narrow screens DataTables scrollX owns horizontal scroll; wrapping both causes double bars.
        if (!narrow && $table.length && !$table.closest('.table-responsive, .report-table-scroll, .dataTables_scroll').length) {
            $table.wrap('<div class="table-responsive report-table-scroll"></div>');
        }
        // Array-sourced columns without their own renderer are shown as text.
        if (Array.isArray(options.columns) && options.data) {
            options = { ...options, columns: options.columns.map(c => (c && !c.render) ? { ...c, render: $.fn.dataTable.render.text() } : c) };
        }
        const config = {
            language: { url: 'assets/js/i18n/fa.json' },
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "همه"]],
            pageLength: 10,
            stripeClasses: [],
            ...(narrow ? { scrollX: true } : {}),
            ...options
        };
        return $table.DataTable(config);
    }

    async function fetchDataForReport(reportType, params) {
        UI.showLoader();
        let apiData = null;
        let apiAction = '';
        let apiParams = { ...params };

        switch (reportType) {
            case 'profit-loss': apiAction = 'get_profit_loss_report'; break;
            case 'persons': apiAction = 'get_person_statement'; break;
            case 'accounts': apiAction = 'get_account_statement'; break;
            case 'sales-invoices':
            case 'purchase-invoices':
                apiParams.type = reportType.includes('sales') ? 'sales' : 'purchase';
                apiAction = 'get_invoices_report';
                break;
            case 'expenses': apiAction = 'get_expenses_report'; break;
            case 'incomes': apiAction = 'get_incomes_report'; break;
            case 'inventory': apiAction = 'get_inventory_report'; break;
            case 'inventory-value': apiAction = 'get_inventory_value_report'; break;
            case 'inventory-ledger': apiAction = 'get_inventory_ledger_report'; break;
            case 'cogs-profit': apiAction = 'get_cogs_profit_report'; break;
            case 'delivery-report': apiAction = 'get_delivery_report'; break;
            case 'aged-receivables': apiAction = 'get_aged_receivables_report'; break;
            case 'cash-flow': apiAction = 'get_cash_flow_report'; break;
            case 'partner-profit': apiAction = 'get_partner_profit_report'; break;
            case 'checks':
                apiAction = 'get_checks_report';
                apiParams.checkType = $('#report-check-type').val() || 'all';
                apiParams.checkStatus = $('#report-check-status').val() || 'all';
                apiParams.onlyPastDue = $('#report-checks-only-past-due').is(':checked') ? 1 : 0;
                break;
            case 'custom-report':
                apiAction = 'get_custom_report';
                apiParams.source = params.customSource || $('#custom-report-source').val();
                apiParams.columns = params.customColumns || getCustomReportSelectedColumns();
                apiParams.groupBy = params.customGroupBy || $('#custom-report-group-by').val() || '';
                break;
        }

        if (apiAction) {
            apiData = await Api.call(apiAction, apiParams);
        }

        UI.hideLoader();
        return apiData;
    }

    async function generate(reportType, container) {
        container.empty();
        $('#print-report-btn, #export-report-btn').hide();

        const startDate = UI.toGregorian($('#report-start-date'));
        const endDate = UI.toGregorian($('#report-end-date'));
        const person = $('#report-person-select').val();
        const account = $('#report-account-select').val();
        const product = $('#report-product-select').val();
        // *** START: Changed from checkbox to radio buttons ***
        const calculationMethod = $('input[name="profit-calc-method"]:checked').val() || 'cogs';
        // *** END: Changed ***
        const entityId = App.getAppState().currentEntityId;

        if (!entityId && !['partners'].includes(reportType)) {
            UI.showError('لطفا یک مجموعه تجاری را برای گزارش‌گیری انتخاب کنید.');
            return;
        }
        if (['profit-loss', 'sales-invoices', 'purchase-invoices', 'expenses', 'incomes', 'persons', 'accounts', 'inventory-ledger', 'cogs-profit', 'delivery-report', 'aged-receivables', 'cash-flow', 'partner-profit', 'custom-report', 'checks'].includes(reportType) && (!startDate || !endDate)) {
            UI.showError('لطفا بازه زمانی را به درستی مشخص کنید.');
            return;
        }

        if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
            UI.showError('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.');
            return;
        }

        if (reportType === 'persons' && !person) {
            UI.showError('لطفا یک شخص را انتخاب کنید.');
            return;
        }
        if (reportType === 'accounts' && !account) {
            UI.showError('لطفا یک حساب را انتخاب کنید.');
            return;
        }
        if (reportType === 'inventory-ledger' && !product) {
            UI.showError('لطفا یک محصول را انتخاب کنید.');
            return;
        }
        if (reportType === 'custom-report') {
            const selectedCols = getCustomReportSelectedColumns();
            if (!selectedCols.length) {
                UI.showError('برای گزارش سفارشی، حداقل یک ستون را انتخاب کنید.');
                return;
            }
        }

        const [personType, personId] = (person || '-').split('-');
        
        // Person statement options
        const includePendingChecks = $('#include-pending-checks').is(':checked');
        const calculatePendingChecks = $('#calculate-pending-checks').is(':checked');
        const showInvoiceDetails = $('#show-invoice-details').is(':checked');
        const showPreviousBalance = $('#show-previous-balance').is(':checked');
        const includeUndeliveredOrders = $('#include-undelivered-orders').is(':checked');
        const calculateConsignment = $('#calculate-consignment').is(':checked');
        const combineLinked = $('#linked-person-combine-option').is(':visible') && $('#combine-linked-person').is(':checked');

        lastReportType = reportType;
        lastReportParams = {
            startDate,
            endDate,
            personType,
            personId,
            accountId: account,
            productId: product,
            calculationMethod: calculationMethod, // Pass selected method
            entityId: entityId,
            persianStartDate: $('#report-start-date').val(),
            persianEndDate: $('#report-end-date').val(),
            // Person statement options
            includePendingChecks: includePendingChecks,
            calculatePendingChecks: calculatePendingChecks,
            showInvoiceDetails: showInvoiceDetails,
            showPreviousBalance: showPreviousBalance,
            includeUndeliveredOrders: includeUndeliveredOrders,
            calculateConsignment: calculateConsignment,
            combineLinked: combineLinked,
            checkType: $('#report-check-type').val() || 'all',
            checkStatus: $('#report-check-status').val() || 'all',
            onlyPastDue: $('#report-checks-only-past-due').is(':checked'),
            customSource: $('#custom-report-source').val() || 'sales_invoices',
            customColumns: getCustomReportSelectedColumns(),
            customGroupBy: $('#custom-report-group-by').val() || ''
        };

        const apiData = await fetchDataForReport(reportType, lastReportParams);
        if (apiData === null) {
            container.html('<p class="text-danger">خطا در دریافت اطلاعات گزارش.</p>');
            return;
        }
        lastReportData = apiData;
        appDataCache = App.getCache();

        switch (reportType) {
            case 'profit-loss': _generateProfitLossReport(apiData, container); break;
            case 'inventory': _generateInventoryReport(apiData, container); break;
            case 'inventory-value': _generateInventoryValueReport(apiData, container); break;
            case 'inventory-ledger': _generateInventoryLedgerReport(apiData, container); break;
            case 'sales-invoices': _generateInvoicesReport('sales', apiData, container); break;
            case 'purchase-invoices': _generateInvoicesReport('purchase', apiData, container); break;
            case 'expenses': _generateExpensesReport(apiData, container); break;
            case 'incomes': _generateIncomesReport(apiData, container); break;
            case 'persons': _generatePersonStatementReport(apiData, container); break;
            case 'accounts': _generateAccountStatementReport(apiData, container); break;
            case 'cogs-profit': _generateCogsProfitReport(apiData, container); break;
            case 'delivery-report': _generateDeliveryReport(apiData, container); break;
            case 'aged-receivables': _generateAgedReceivablesReport(apiData, container); break;
            case 'cash-flow': _generateCashFlowReport(apiData, container); break;
            case 'partner-profit': _generatePartnerProfitReport(apiData, container); break;
            case 'checks': _generateChecksReport(apiData, container); break;
            case 'custom-report': _generateCustomReport(apiData, container); break;
            default: container.html('<p class="text-danger">نوع گزارش انتخاب شده معتبر نیست.</p>');
        }

        _ensureReportTablesScrollable(container);

        if (container.html().trim() !== "") {
            $('#print-report-btn').show();
            if (['inventory', 'inventory-value', 'expenses', 'incomes', 'persons', 'accounts', 'cogs-profit', 'aged-receivables', 'cash-flow', 'custom-report', 'checks'].includes(reportType)) {
                $('#export-report-btn').show();
            }
        }
    }

    function _getPrintableInvoiceListHtml(type, data) {
        const { invoices } = data;
        const { persianStartDate, persianEndDate } = lastReportParams;
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';

        let reportHtml = `<h4 class="mb-3">گزارش ${title} برای «${entityName}» از ${persianStartDate} تا ${persianEndDate}</h4>`;
        if (!invoices || invoices.length === 0) {
            reportHtml += `<p class="text-center">فاکتوری در این بازه زمانی یافت نشد.</p>`;
            return reportHtml;
        }

        invoices.forEach(inv => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            const invoiceDate = UI.gregorianToPersian(inv.date);
            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { itemsHtml += `<tr><td>${_formatInvoiceItemLabel(item)}</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
            itemsHtml += '</tbody></table>';

            let paymentsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>';
            if (inv.payments && inv.payments.length > 0) (inv.payments || []).forEach(p => { let det = p.description || '-'; if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`; paymentsHtml += `<tr><td>${p.type}</td><td>${UI.gregorianToPersian(p.date)}</td><td>${formatCurrency(p.amount)}</td><td>${det}</td></tr>`; }); else paymentsHtml += `<tr><td colspan="4" class="text-center">پرداختی ندارد</td></tr>`
            paymentsHtml += '</tbody></table>';

            reportHtml += `
                <div class="card mb-3">
                    <div class="card-header">فاکتور #${inv.id} | ${personTitle}: <strong>${personName || ''}</strong> | تاریخ: ${invoiceDate}</div>
                    <div class="card-body row"><div class="col-md-7"><h5>اقلام</h5>${itemsHtml}</div><div class="col-md-5"><h5>پرداخت‌ها</h5>${paymentsHtml}</div></div>
                    <div class="card-footer d-flex justify-content-end gap-3 flex-wrap">
                        <span class="badge bg-primary">کل: ${formatCurrency(inv.totalAmount)}</span>
                        <span class="badge bg-info text-dark">تخفیف: ${formatCurrency(inv.discount || 0)}</span>
                        <span class="badge bg-success">پرداختی: ${formatCurrency(inv.paidAmount)}</span>
                        <span class="badge bg-danger">مانده: ${formatCurrency(inv.remainingAmount)}</span>
                    </div>
                </div>`;
        });
        return reportHtml;
    }

    function _generateInvoicesReport(type, data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { invoices, summary } = data;
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';
        const paymentTypeLabels = {
            cash: 'نقد', card: 'کارتی', transfer: 'انتقال', check: 'چک',
            endorse_check: 'خرج چک', pos: 'پوز', payable_check: 'چک پرداختی', setoff: 'تهاتر / پرداخت به جای'
        };

        let finalHtml = `<div class="invoice-report"><h4 class="mb-3">گزارش ${title} برای «${UI.escapeHtml(entityName)}» از ${UI.escapeHtml(persianStartDate || '')} تا ${UI.escapeHtml(persianEndDate || '')}</h4>`;

        if (summary) {
            finalHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center g-3">
                <div class="col-6 col-md"><div class="fs-6 text-muted">تعداد فاکتورها</div><div class="fs-5 fw-bold">${summary.count || 0}</div></div>
                <div class="col-6 col-md"><div class="fs-6 text-muted">جمع کل</div><div class="fs-5 fw-bold">${formatCurrency(summary.totalAmount)}</div></div>
                <div class="col-6 col-md"><div class="fs-6 text-muted">جمع تخفیف</div><div class="fs-5 fw-bold text-info">${formatCurrency(summary.totalDiscount)}</div></div>
                <div class="col-6 col-md"><div class="fs-6 text-muted">جمع پرداختی</div><div class="fs-5 fw-bold text-success">${formatCurrency(summary.totalPaid)}</div></div>
                <div class="col-12 col-md"><div class="fs-6 text-muted">جمع مانده</div><div class="fs-5 fw-bold text-danger">${formatCurrency(summary.totalRemaining)}</div></div>
            </div></div></div>`;
        }

        if (!invoices || invoices.length === 0) {
            finalHtml += `<p class="text-center">فاکتوری در این بازه زمانی یافت نشد.</p></div>`;
            container.html(finalHtml);
            return;
        }

        const accordionId = `invoicesReportAccordion-${type}`;
        let accordionHtml = `<div class="accordion invoice-report-accordion" id="${accordionId}">`;

        invoices.forEach((inv, index) => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            const invoiceDate = UI.gregorianToPersian(inv.date);
            const invLabel = inv.invoice_number ? UI.escapeHtml(String(inv.invoice_number)) : String(inv.id);

            let itemsHtml = `<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr>
                <th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th>
            </tr></thead><tbody>`;
            (inv.items || []).forEach(item => {
                itemsHtml += `<tr>
                    <td>${_formatInvoiceItemLabel(item)}</td>
                    <td>${item.quantity}</td>
                    <td>${formatCurrency(item.unitPrice)}</td>
                    <td>${formatCurrency(item.quantity * item.unitPrice)}</td>
                </tr>`;
            });
            if (!(inv.items || []).length) {
                itemsHtml += `<tr><td colspan="4" class="text-center text-muted">بدون قلم</td></tr>`;
            }
            itemsHtml += '</tbody></table></div>';

            let paymentsHtml = `<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr>
                <th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th>
            </tr></thead><tbody>`;
            if (inv.payments && inv.payments.length > 0) {
                inv.payments.forEach(p => {
                    let det = p.description || '-';
                    if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`;
                    const typeLabel = paymentTypeLabels[p.type] || p.type || '-';
                    paymentsHtml += `<tr>
                        <td>${UI.escapeHtml(String(typeLabel))}</td>
                        <td>${UI.gregorianToPersian(p.date)}</td>
                        <td>${formatCurrency(p.amount)}</td>
                        <td>${UI.escapeHtml(String(det))}</td>
                    </tr>`;
                });
            } else {
                paymentsHtml += `<tr><td colspan="4" class="text-center text-muted">پرداختی ندارد</td></tr>`;
            }
            paymentsHtml += '</tbody></table></div>';

            accordionHtml += `<div class="accordion-item">
                <h2 class="accordion-header" id="heading-${type}-${index}">
                    <button class="accordion-button collapsed invoice-report-acc-btn" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse-${type}-${index}"
                        aria-expanded="false" aria-controls="collapse-${type}-${index}">
                        <div class="invoice-report-acc-body w-100 pe-2">
                            <div class="invoice-report-acc-title">
                                فاکتور <strong>#${invLabel}</strong>
                                <span class="text-muted">·</span>
                                ${UI.escapeHtml(personTitle)}: <strong>${UI.escapeHtml(personName || '-')}</strong>
                                <span class="text-muted">·</span>
                                ${UI.escapeHtml(invoiceDate || '-')}
                            </div>
                            <div class="invoice-report-acc-badges">
                                <span class="badge bg-primary">کل: ${formatCurrency(inv.totalAmount)}</span>
                                <span class="badge bg-info text-dark">تخفیف: ${formatCurrency(inv.discount || 0)}</span>
                                <span class="badge bg-success">پرداختی: ${formatCurrency(inv.paidAmount)}</span>
                                <span class="badge bg-danger">مانده: ${formatCurrency(inv.remainingAmount)}</span>
                            </div>
                        </div>
                    </button>
                </h2>
                <div id="collapse-${type}-${index}" class="accordion-collapse collapse"
                    data-bs-parent="#${accordionId}" aria-labelledby="heading-${type}-${index}">
                    <div class="accordion-body">
                        <div class="row g-3">
                            <div class="col-12 col-lg-7">
                                <h6 class="mb-2">اقلام</h6>
                                ${itemsHtml}
                            </div>
                            <div class="col-12 col-lg-5">
                                <h6 class="mb-2">پرداخت‌ها</h6>
                                ${paymentsHtml}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        });

        accordionHtml += `</div></div>`;
        finalHtml += accordionHtml;
        container.html(finalHtml);
    }

    function _generateDeliveryReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';

        const statusLabels = {
            'preparing': { label: 'در حال آماده‌سازی', icon: 'bi-clock-history', bg: 'warning', text: 'dark' },
            'ready': { label: 'آماده تحویل', icon: 'bi-check2-circle', bg: 'info', text: 'dark' },
            'delivered': { label: 'تحویل شده', icon: 'bi-truck', bg: 'success', text: 'white' }
        };

        let html = `<h4 class="mb-3">گزارش وضعیت تحویل سفارشات «${entityName}» از ${persianStartDate} تا ${persianEndDate}</h4>`;

        // Render a section for each type (sales / purchase)
        const sections = [
            { key: 'sales', title: 'فاکتورهای فروش', summaryKey: 'salesSummary', personTitle: 'مشتری', personField: 'customerName' },
            { key: 'purchase', title: 'فاکتورهای خرید', summaryKey: 'purchaseSummary', personTitle: 'تأمین‌کننده', personField: 'supplierName' }
        ];

        sections.forEach(section => {
            const invoices = data[section.key] || [];
            const summary = data[section.summaryKey] || {};

            html += `<h5 class="mt-4 mb-3"><i class="bi bi-receipt me-2"></i>${section.title}</h5>`;

            // Summary cards per status
            html += `<div class="row g-3 mb-4">`;
            ['preparing', 'ready', 'delivered'].forEach(status => {
                const s = summary[status] || { count: 0, totalAmount: 0, totalRemaining: 0 };
                const info = statusLabels[status];
                html += `
                <div class="col-md-4">
                    <div class="card border-${info.bg}">
                        <div class="card-header bg-${info.bg} text-${info.text}">
                            <i class="${info.icon} me-1"></i> ${info.label}
                        </div>
                        <div class="card-body text-center">
                            <div class="row">
                                <div class="col">
                                    <div class="text-muted small">تعداد</div>
                                    <div class="fw-bold fs-5">${s.count}</div>
                                </div>
                                <div class="col">
                                    <div class="text-muted small">مبلغ کل</div>
                                    <div class="fw-bold">${formatCurrency(s.totalAmount)}</div>
                                </div>
                                <div class="col">
                                    <div class="text-muted small">مانده</div>
                                    <div class="fw-bold text-danger">${formatCurrency(s.totalRemaining)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            html += `</div>`;

            // Invoices table
            if (invoices.length === 0) {
                html += `<p class="text-center text-muted">فاکتوری در این بازه زمانی یافت نشد.</p>`;
            } else {
                const tableId = `delivery-report-${section.key}-table`;
                html += `<div class="table-responsive"><table class="table table-hover table-bordered" id="${tableId}">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>${section.personTitle}</th>
                            <th>تاریخ</th>
                            <th>مبلغ کل</th>
                            <th>تخفیف</th>
                            <th>پرداختی</th>
                            <th>مانده</th>
                            <th>وضعیت تحویل</th>
                        </tr>
                    </thead>
                    <tbody>`;

                invoices.forEach(inv => {
                    const status = inv.delivery_status || 'preparing';
                    const info = statusLabels[status] || statusLabels['preparing'];
                    html += `<tr>
                        <td>${inv.id}</td>
                        <td>${inv[section.personField] || '-'}</td>
                        <td>${UI.gregorianToPersian(inv.date)}</td>
                        <td>${formatCurrency(inv.totalAmount)}</td>
                        <td>${formatCurrency(inv.discount || 0)}</td>
                        <td>${formatCurrency(inv.paidAmount)}</td>
                        <td class="${inv.remainingAmount > 0 ? 'text-danger' : ''}">${formatCurrency(inv.remainingAmount)}</td>
                        <td><span class="badge bg-${info.bg} text-${info.text}"><i class="${info.icon} me-1"></i>${info.label}</span></td>
                    </tr>`;
                });

                html += `</tbody></table></div>`;
            }
        });

        container.html(html);

        // Initialize DataTables for both tables
        ['sales', 'purchase'].forEach(key => {
            const tableId = `#delivery-report-${key}-table`;
            if ($(tableId).length && $(tableId).find('tbody tr').length > 0) {
                _initDataTable(tableId, { order: [] });
            }
        });
    }

    function print() {
        const container = $('#report-results-container');
        if (!container.length || container.is(':empty')) {
            UI.showError("محتوایی برای چاپ وجود ندارد.");
            return;
        }

        if (lastReportType === 'persons' && lastReportData && lastReportData.person) {
            PersonStatementPrint.open(lastReportData, lastReportParams);
            return;
        }

        const title = container.find('h4').first().text() || "گزارش";
        let contentHtml = '';

        if (lastReportType === 'sales-invoices' || lastReportType === 'purchase-invoices') {
            contentHtml = _getPrintableInvoiceListHtml(lastReportType.includes('sales') ? 'sales' : 'purchase', lastReportData);
        } else {
            const clonedContainer = container.clone();
            clonedContainer.find('.no-print, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate, .accordion-button, .btn-group').remove();
            clonedContainer.find('.accordion-collapse').addClass('show');
            contentHtml = clonedContainer.html();
        }

        const printWin = window.open('', '_blank');
        if (!printWin) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return;
        }
        printWin.document.write(`
            <html>
                <head>
                    <title>${title}</title>
                    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
                    <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
                    <style>
                        body { 
                            font-family: Vazirmatn, sans-serif; 
                            direction: rtl; 
                            background-color: #fff !important; 
                            -webkit-print-color-adjust: exact; 
                        }
                        @media print {
                            body {
                                -webkit-print-color-adjust: exact; 
                                print-color-adjust: exact;
                            }
                            .no-print { 
                                display: none !important; 
                            }
                            .card { 
                                page-break-inside: avoid; 
                                border: 1px solid #dee2e6 !important;
                                box-shadow: none !important;
                            }
                            a {
                                text-decoration: none;
                                color: #000;
                            }
                        }
                        table th { text-align: right !important; }
                    </style>
                </head>
                <body>
                    <div class="container-fluid mt-4">
                        ${contentHtml}
                    </div>
                </body>
            </html>`);
        printWin.document.close();
        printWin.focus();
        setTimeout(() => {
            printWin.print();
            printWin.close();
        }, 500);
    }

    function exportCsv() {
        if (!lastReportType) {
            UI.showError("ابتدا یک گزارش تولید کنید.");
            return;
        }

        const params = new URLSearchParams({
            reportType: lastReportType,
            startDate: lastReportParams.startDate,
            endDate: lastReportParams.endDate,
            personType: lastReportParams.personType || '',
            personId: lastReportParams.personId || '',
            accountId: lastReportParams.accountId || '',
            productId: lastReportParams.productId || '',
            entityId: lastReportParams.entityId || ''
        });

        if (lastReportParams.showInvoiceDetails) {
            params.set('showInvoiceDetails', '1');
        }
        if (lastReportParams.includePendingChecks === false) {
            params.set('includePendingChecks', '0');
        }
        if (lastReportParams.calculatePendingChecks) {
            params.set('calculatePendingChecks', '1');
        }
        if (lastReportParams.showPreviousBalance === false) {
            params.set('showPreviousBalance', '0');
        }
        if (lastReportParams.includeUndeliveredOrders === false) {
            params.set('includeUndeliveredOrders', '0');
        }
        if (lastReportParams.calculateConsignment) {
            params.set('calculateConsignment', '1');
        }
        if (lastReportParams.combineLinked) {
            params.set('combineLinked', '1');
        }
        if (lastReportType === 'checks') {
            params.set('checkType', lastReportParams.checkType || 'all');
            params.set('checkStatus', lastReportParams.checkStatus || 'all');
            params.set('onlyPastDue', lastReportParams.onlyPastDue ? '1' : '0');
        }
        if (lastReportType === 'custom-report') {
            params.set('source', lastReportParams.customSource || 'sales_invoices');
            params.set('groupBy', lastReportParams.customGroupBy || '');
            const cols = Array.isArray(lastReportParams.customColumns) ? lastReportParams.customColumns : [];
            if (cols.length) {
                params.set('columns', cols.join(','));
            }
        }

        window.location.href = `${AppConfig.API_URL}?action=export_report&${params.toString()}`;
    }

    function _generateProfitLossReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';
        const salesReturns = data.salesReturns || 0;
        const purchaseReturns = data.purchaseReturns || 0;
        const netSales = data.grossSales - data.salesDiscounts - salesReturns;
        const netPurchases = data.grossPurchases - purchaseReturns;
        const metrics = data.metrics || {};
        const dataQuality = data.dataQuality || {};
        const salesReturnCogs = Number(data.salesReturnCogs || 0);

        const fmtDate = (d) => d ? (UI.gregorianToPersian ? UI.gregorianToPersian(d) : d) : '-';
        const renderDetailTable = (rows, colDefs) => {
            if (!rows || rows.length === 0) return '';
            let t = `<table class="table table-sm table-borderless mb-0 mt-1" style="font-size:0.78rem"><thead class="table-light"><tr>`;
            colDefs.forEach(c => { t += `<th>${c.label}</th>`; });
            t += `</tr></thead><tbody>`;
            rows.forEach(r => {
                t += '<tr>';
                colDefs.forEach(c => {
                    const val = c.render ? c.render(r) : (r[c.key] ?? '-');
                    t += `<td>${val}</td>`;
                });
                t += '</tr>';
            });
            t += '</tbody></table>';
            return t;
        };
        const detailSection = (id, label, amount, cssClass, rows, colDefs) => {
            if (!amount || amount === 0) return '';
            const hasRows = rows && rows.length > 0;
            return `<div class="py-1 detail-row">
                <div class="d-flex justify-content-between align-items-center">
                    ${hasRows
                        ? `<a class="text-decoration-none d-flex align-items-center" style="cursor:pointer" data-bs-toggle="collapse" href="#${id}" role="button" aria-expanded="false"><i class="bi bi-chevron-left detail-chevron me-1 small"></i>${label}</a>`
                        : `<span>${label}</span>`}
                    <span class="${cssClass} fw-bold">${formatCurrency(amount)}</span>
                </div>
                ${hasRows ? `<div class="collapse" id="${id}"><div class="ps-3 border-start border-2 ms-2 mt-1 ${cssClass.includes('success') ? 'border-success' : 'border-danger'} border-opacity-25">${renderDetailTable(rows, colDefs)}</div></div>` : ''}
            </div>`;
        };
        const dateCol = { key: 'date', label: 'تاریخ', render: r => fmtDate(r.date) };
        const amountCol = { key: 'amount', label: 'مبلغ', render: r => formatCurrency(r.amount || 0) };
        const descCol = { key: 'description', label: 'توضیح', render: r => UI.escapeHtml(r.description || r.category || r.personName || '-') };
        
        // Calculation method info
        let calculationMethodInfo = { icon: '', title: '', class: '', costHtml: '' };
        if (data.calculationMethod === 'cogs') {
            calculationMethodInfo = {
                icon: 'bi-bullseye',
                title: 'روش دقیق (COGS)',
                class: 'info',
                costHtml: `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-box-seam text-info me-2"></i>بهای تمام‌شده فروش (ناخالص)</span>
                        <strong class="text-danger">(${formatCurrency(data.totalCogsBeforeReturns != null ? data.totalCogsBeforeReturns : data.totalCogs)})</strong>
                    </div>
                    ${salesReturnCogs > 0 ? `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-arrow-return-left text-success me-2"></i>برگشت بهای مرجوعی فروش</span>
                        <span class="text-success">${formatCurrency(salesReturnCogs)}</span>
                    </div>` : ''}
                    ${Number(data.stocktakingShrinkage || 0) > 0 ? `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-clipboard-minus text-danger me-2"></i>کسری انبارگردانی</span>
                        <span class="text-danger">(${formatCurrency(data.stocktakingShrinkage)})</span>
                    </div>` : ''}
                    ${Number(data.stocktakingSurplus || 0) > 0 ? `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-clipboard-plus text-success me-2"></i>اضافی انبارگردانی</span>
                        <span class="text-success">${formatCurrency(data.stocktakingSurplus)}</span>
                    </div>` : ''}
                    <div class="d-flex justify-content-between py-2 bg-light rounded px-2 mt-2">
                        <span><i class="bi bi-calculator text-info me-2"></i><strong>بهای تمام‌شده خالص</strong></span>
                        <strong class="text-danger">(${formatCurrency(data.totalCogs)})</strong>
                    </div>`
            };
        } else if (data.calculationMethod === 'inventory') {
            const costOfGoodsSold = data.openingInventory + netPurchases - data.closingInventory;
            calculationMethodInfo = {
                icon: 'bi-box-seam',
                title: 'روش استاندارد حسابداری',
                class: 'success',
                costHtml: `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-archive text-muted me-2"></i>موجودی اول دوره</span>
                        <span>${formatCurrency(data.openingInventory)}</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-cart text-secondary me-2"></i>خرید ناخالص دوره</span>
                        <span>${formatCurrency(data.grossPurchases)}</span>
                    </div>
                    ${purchaseReturns > 0 ? `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-arrow-return-right text-info me-2"></i>مرجوعی خرید</span>
                        <span class="text-danger">(${formatCurrency(purchaseReturns)})</span>
                    </div>` : ''}
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-plus-circle text-success me-2"></i>خرید خالص دوره</span>
                        <span>${formatCurrency(netPurchases)}</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-dash-circle text-warning me-2"></i>موجودی پایان دوره</span>
                        <span class="text-warning">(${formatCurrency(data.closingInventory)})</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 bg-light rounded px-2 mt-2">
                        <span><i class="bi bi-calculator text-info me-2"></i><strong>بهای تمام شده</strong></span>
                        <strong class="text-danger">(${formatCurrency(costOfGoodsSold)})</strong>
                    </div>`
            };
        } else {
            calculationMethodInfo = {
                icon: 'bi-lightning',
                title: 'روش ساده',
                class: 'secondary',
                costHtml: `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-cart text-secondary me-2"></i>خرید ناخالص</span>
                        <span>${formatCurrency(data.grossPurchases)}</span>
                    </div>
                    ${purchaseReturns > 0 ? `
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-arrow-return-right text-info me-2"></i>مرجوعی خرید</span>
                        <span class="text-danger">(${formatCurrency(purchaseReturns)})</span>
                    </div>` : ''}
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span><i class="bi bi-cart-check text-secondary me-2"></i>خرید خالص</span>
                        <strong class="text-danger">(${formatCurrency(netPurchases)})</strong>
                    </div>`
            };
        }

        const capitalSummary = data.capitalSummary || { openingCapital: 0, periodDeposits: 0, periodWithdrawals: 0 };
        const openingCapital = Number(capitalSummary.openingCapital || 0);
        const periodDeposits = Number(capitalSummary.periodDeposits || 0);
        const periodWithdrawals = Number(capitalSummary.periodWithdrawals || 0);
        const closingCapital = capitalSummary.closingCapital != null
            ? Number(capitalSummary.closingCapital)
            : openingCapital + Number(data.netOperatingProfit) + periodDeposits - periodWithdrawals;
        
        // Supplier debts data
        const supplierDebts = data.supplierDebts || { suppliers: [], totalDebt: 0, totalPendingChecks: 0, totalDebtWithPendingChecks: 0 };
        
        // Customer receivables data
        const customerReceivables = data.customerReceivables || { customers: [], totalReceivable: 0, totalPendingChecks: 0, totalReceivableWithPendingChecks: 0 };

        const qualityAlerts = [];
        if (dataQuality.unlinkedSaleLines > 0) {
            qualityAlerts.push(`<li>${dataQuality.unlinkedSaleLines} ردیف فروش بدون لینک انبار (حدود ${formatCurrency(dataQuality.unlinkedSaleValue)}) — بهای تمام‌شده‌شان صفر لحاظ شده</li>`);
        }
        if (dataQuality.zeroCostSoldItems > 0) {
            qualityAlerts.push(`<li>${dataQuality.zeroCostSoldItems} قلم فروخته‌شده با بهای صفر (فروش حدود ${formatCurrency(dataQuality.zeroCostSaleValue)}) — سود را بیشتر نشان می‌دهد</li>`);
        }

        let html = `
        <style>
            .profit-loss-report .stat-card {
                border-radius: 16px;
                border: none;
                box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .profit-loss-report .stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            }
            .profit-loss-report .stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
            }
            .profit-loss-report .section-card {
                border-radius: 12px;
                border: 1px solid #e9ecef;
                overflow: hidden;
            }
            .profit-loss-report .section-header {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                padding: 12px 16px;
                border-bottom: 1px solid #dee2e6;
            }
            .profit-loss-report .person-item {
                transition: background-color 0.15s;
            }
            .profit-loss-report .person-item:hover {
                background-color: #f8f9fa;
            }
            .profit-loss-report .progress-thin {
                height: 6px;
                border-radius: 3px;
            }
            .profit-loss-report .badge-method {
                font-size: 0.75rem;
                padding: 4px 10px;
                border-radius: 20px;
            }
            .profit-loss-report .partner-card {
                border-radius: 10px;
                border: 1px solid #e9ecef;
            }
            .profit-loss-report .detail-row { transition: background 0.15s; padding: 2px 4px; border-radius: 4px; }
            .profit-loss-report .detail-row:hover { background: #f8f9fa; }
            .profit-loss-report .detail-chevron { transition: transform 0.2s; display: inline-block; }
            .profit-loss-report [aria-expanded="true"] .detail-chevron { transform: rotate(-90deg); }
            .profit-loss-report .metric-pill {
                display: inline-flex; align-items: center; gap: 6px;
                background: #f8f9fa; border-radius: 20px; padding: 4px 12px; font-size: 0.85rem;
            }
        </style>
        
        <div class="profit-loss-report">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <div>
                    <h4 class="mb-1"><i class="bi bi-graph-up-arrow text-primary me-2"></i>گزارش سود و زیان</h4>
                    <p class="text-muted mb-0">${entityName} · از ${persianStartDate} تا ${persianEndDate}</p>
                </div>
                <span class="badge badge-method bg-${calculationMethodInfo.class}">
                    <i class="bi ${calculationMethodInfo.icon} me-1"></i>${calculationMethodInfo.title}
                </span>
            </div>

            ${qualityAlerts.length ? `
            <div class="alert alert-warning mb-4" style="border-radius:12px;">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>هشدار کیفیت داده (محاسبه انجام شده، ولی نیاز به اصلاح دستی دارد)</div>
                <ul class="mb-0 small">${qualityAlerts.join('')}</ul>
            </div>` : ''}

            <!-- Main Stats Row -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">فروش خالص</div>
                                    <div class="fs-5 fw-bold text-primary">${formatCurrency(netSales)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                                    <i class="bi bi-cart"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">خرید خالص</div>
                                    <div class="fs-5 fw-bold text-warning">${formatCurrency(netPurchases)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon ${data.grossProfit >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'} me-3">
                                    <i class="bi ${data.grossProfit >= 0 ? 'bi-graph-up' : 'bi-graph-down'}"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">سود ناخالص</div>
                                    <div class="fs-5 fw-bold ${data.grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.grossProfit)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 ${data.netOperatingProfit >= 0 ? 'border-success' : 'border-danger'}" style="border-width: 2px;">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon ${data.netOperatingProfit >= 0 ? 'bg-success text-white' : 'bg-danger text-white'} me-3">
                                    <i class="bi ${data.netOperatingProfit >= 0 ? 'bi-trophy' : 'bi-exclamation-triangle'}"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">سود خالص عملیاتی</div>
                                    <div class="fs-5 fw-bold ${data.netOperatingProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.netOperatingProfit)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
                <span class="metric-pill"><i class="bi bi-percent text-success"></i>حاشیه ناخالص: <strong>${Number(metrics.grossMarginPercent || 0).toFixed(1)}٪</strong></span>
                <span class="metric-pill"><i class="bi bi-percent text-primary"></i>حاشیه خالص: <strong>${Number(metrics.netMarginPercent || 0).toFixed(1)}٪</strong></span>
                <span class="metric-pill"><i class="bi bi-pie-chart text-danger"></i>نسبت هزینه به فروش: <strong>${Number(metrics.expenseRatioPercent || 0).toFixed(1)}٪</strong></span>
                ${data.return_rate != null ? `<span class="metric-pill"><i class="bi bi-arrow-return-left text-warning"></i>نرخ مرجوعی: <strong>${Number(data.return_rate).toFixed(1)}٪</strong></span>` : ''}
                ${data.growth_rate != null ? `<span class="metric-pill"><i class="bi bi-graph-up-arrow text-info"></i>رشد فروش: <strong>${Number(data.growth_rate).toFixed(1)}٪</strong></span>` : ''}
            </div>

            <!-- Main Content -->
            <div class="row g-4">
                <!-- Left Column: Income & Expense -->
                <div class="col-lg-8">
                    <!-- Income Section -->
                    <div class="card section-card mb-4">
                        <div class="section-header">
                            <h6 class="mb-0"><i class="bi bi-currency-dollar text-success me-2"></i>درآمد و هزینه</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-receipt text-success me-2"></i>جمع فروش ناخالص</span>
                                <strong>${formatCurrency(data.grossSales)}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-percent text-danger me-2"></i>تخفیفات فروش</span>
                                <span class="text-danger">(${formatCurrency(data.salesDiscounts)})</span>
                            </div>
                            ${salesReturns > 0 ? `
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-arrow-return-left text-warning me-2"></i>مرجوعی فروش</span>
                                <span class="text-danger">(${formatCurrency(salesReturns)})</span>
                            </div>` : ''}
                            <div class="d-flex justify-content-between py-2 bg-primary bg-opacity-10 rounded px-2 mb-3">
                                <strong><i class="bi bi-check-circle text-primary me-2"></i>فروش خالص</strong>
                                <strong class="text-primary">${formatCurrency(netSales)}</strong>
                            </div>
                            ${calculationMethodInfo.costHtml}
                        </div>
                        <div class="card-footer bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold">سود ناخالص:</span>
                                <span class="fs-5 fw-bold ${data.grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.grossProfit)}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <span class="text-muted">کسر هزینه‌های عملیاتی</span>
                                <span class="text-danger">(${formatCurrency(data.totalCompanyExpenses)})</span>
                            </div>
                            ${Number(data.totalOtherOutgoings) > 0 ? `
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="text-muted">کسر پرداخت متفرقه</span>
                                <span class="text-danger">(${formatCurrency(data.totalOtherOutgoings)})</span>
                            </div>` : ''}
                            ${Number(data.totalCompanyIncome) > 0 ? `
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="text-muted">جمع درآمدهای ثبت‌شده</span>
                                <span class="text-success">${formatCurrency(data.totalCompanyIncome)}</span>
                            </div>` : ''}
                            ${Number(data.totalOtherIncome) > 0 ? `
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="text-muted">جمع درآمد متفرقه (سود بانکی، برگشت کارمزد و …)</span>
                                <span class="text-success">${formatCurrency(data.totalOtherIncome)}</span>
                            </div>` : ''}
                            ${Math.abs(Number(data.totalAssetGain) || 0) > 0 ? `
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="text-muted">سود/زیان فروش دارایی (ارز، طلا، اثاثیه و …)</span>
                                <span class="${Number(data.totalAssetGain) >= 0 ? 'text-success' : 'text-danger'}">${Number(data.totalAssetGain) >= 0 ? formatCurrency(data.totalAssetGain) : `(${formatCurrency(-Number(data.totalAssetGain))})`}</span>
                            </div>` : ''}
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="fw-bold">سود خالص عملیاتی:</span>
                                <span class="fs-5 fw-bold ${data.netOperatingProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.netOperatingProfit)}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Capital Summary -->
                    <div class="card section-card mb-4">
                        <div class="section-header">
                            <h6 class="mb-0"><i class="bi bi-bank text-info me-2"></i>خلاصه وضعیت سرمایه شرکا (دید طلب از شرکت)</h6>
                        </div>
                        <div class="card-body">
                            <div class="small text-muted mb-3">
                                هم‌راستا با صورتحساب اشخاص: واریز/هزینه از جیب/پرداخت برای شرکت = افزایش طلب؛
                                برداشت/دریافت وجوه شرکت = کاهش طلب. انتقال بین شرکا با واسطه شرکت نیز لحاظ می‌شود
                                (ورود = افزایش طلب، خروج = کاهش طلب).
                            </div>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-wallet text-muted me-2"></i>سرمایه / طلب اول دوره</span>
                                <span>${formatCurrency(openingCapital)}</span>
                            </div>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-plus-lg text-success me-2"></i>سود خالص عملیاتی دوره</span>
                                <span class="${data.netOperatingProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.netOperatingProfit)}</span>
                            </div>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-arrow-down-circle text-success me-2"></i>بستانکاری دوره</span>
                                <span class="text-success">${formatCurrency(periodDeposits)}</span>
                            </div>
                            <div class="d-flex justify-content-between py-2 border-bottom">
                                <span><i class="bi bi-arrow-up-circle text-danger me-2"></i>بدهکاری دوره</span>
                                <span class="text-danger">(${formatCurrency(periodWithdrawals)})</span>
                            </div>
                        </div>
                        <div class="card-footer bg-primary bg-opacity-10">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong><i class="bi bi-safe text-primary me-2"></i>سرمایه / طلب پایان دوره:</strong>
                                <span class="fs-5 fw-bold text-primary">${formatCurrency(closingCapital)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Expenses, Debts, Receivables -->
                <div class="col-lg-4">
                    <!-- Expenses -->
                    <div class="card section-card mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-credit-card text-danger me-2"></i>ریز هزینه‌ها</h6>
                            <span class="badge bg-danger">${formatCurrency(data.totalCompanyExpenses)}</span>
                        </div>
                        <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                            ${data.expenseBreakdown && data.expenseBreakdown.length > 0 ? 
                                data.expenseBreakdown.map(exp => {
                                    const pct = data.totalCompanyExpenses > 0 ? (Number(exp.total) / Number(data.totalCompanyExpenses) * 100) : 0;
                                    return `
                                    <div class="py-2 px-3 border-bottom person-item">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate" style="max-width: 150px;">${UI.escapeHtml(exp.category)}</span>
                                            <span class="text-danger">(${formatCurrency(exp.total)})</span>
                                        </div>
                                        <div class="progress progress-thin mt-1"><div class="progress-bar bg-danger" style="width:${pct.toFixed(1)}%"></div></div>
                                        <div class="small text-muted mt-1">${pct.toFixed(1)}٪ از کل هزینه</div>
                                    </div>`;
                                }).join('') :
                                '<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>هزینه‌ای ثبت نشده</div>'
                            }
                        </div>
                    </div>

                    ${Number(data.totalCompanyIncome) > 0 || (data.incomeBreakdown && data.incomeBreakdown.length > 0) ? `
                    <div class="card section-card mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-cash-coin text-success me-2"></i>ریز درآمدها</h6>
                            <span class="badge bg-success">${formatCurrency(data.totalCompanyIncome || 0)}</span>
                        </div>
                        <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                            ${data.incomeBreakdown && data.incomeBreakdown.length > 0 ?
                                data.incomeBreakdown.map(inc => {
                                    const pct = data.totalCompanyIncome > 0 ? (Number(inc.total) / Number(data.totalCompanyIncome) * 100) : 0;
                                    return `
                                    <div class="py-2 px-3 border-bottom person-item">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate" style="max-width: 150px;">${UI.escapeHtml(inc.category)}</span>
                                            <span class="text-success">${formatCurrency(inc.total)}</span>
                                        </div>
                                        <div class="progress progress-thin mt-1"><div class="progress-bar bg-success" style="width:${pct.toFixed(1)}%"></div></div>
                                        <div class="small text-muted mt-1">${pct.toFixed(1)}٪ از کل درآمد</div>
                                    </div>`;
                                }).join('') :
                                '<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>درآمدی ثبت نشده</div>'
                            }
                        </div>
                    </div>` : ''}

                    <!-- Supplier Debts -->
                    <div class="card section-card mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-truck text-warning me-2"></i>بدهی به تأمین‌کنندگان</h6>
                            <span class="badge bg-warning text-dark">${formatCurrency(supplierDebts.totalDebt)}</span>
                        </div>
                        <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                            ${supplierDebts.suppliers && supplierDebts.suppliers.length > 0 ? 
                                supplierDebts.suppliers.map(sup => `
                                    <div class="py-2 px-3 border-bottom person-item">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate fw-medium" style="max-width: 130px;">${UI.escapeHtml(sup.name)}</span>
                                            <span class="text-warning fw-bold">${formatCurrency(sup.debt)}</span>
                                        </div>
                                        ${sup.pendingChecks > 0 ? `
                                            <div class="small text-muted mt-1">
                                                <i class="bi bi-clock text-info me-1"></i>چک سررسیدنرسیده: <span class="text-info">${formatCurrency(sup.pendingChecks)}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                `).join('') :
                                '<div class="text-center text-muted py-4"><i class="bi bi-check-circle fs-3 d-block mb-2 text-success"></i>بدهی‌ای وجود ندارد</div>'
                            }
                        </div>
                        ${supplierDebts.totalPendingChecks > 0 ? `
                            <div class="card-footer bg-info bg-opacity-10 small">
                                <div class="d-flex justify-content-between">
                                    <span><i class="bi bi-clock me-1"></i>جمع چک‌های سررسیدنرسیده:</span>
                                    <strong class="text-info">${formatCurrency(supplierDebts.totalPendingChecks)}</strong>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span><i class="bi bi-calculator me-1"></i>جمع کل (با چک‌ها):</span>
                                    <strong class="text-warning">${formatCurrency(supplierDebts.totalDebtWithPendingChecks)}</strong>
                                </div>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Customer Receivables -->
                    <div class="card section-card mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-people text-success me-2"></i>طلب از مشتریان</h6>
                            <span class="badge bg-success">${formatCurrency(customerReceivables.totalReceivable)}</span>
                        </div>
                        <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                            ${customerReceivables.customers && customerReceivables.customers.length > 0 ? 
                                customerReceivables.customers.map(cust => `
                                    <div class="py-2 px-3 border-bottom person-item">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate fw-medium" style="max-width: 130px;">${UI.escapeHtml(cust.name)}</span>
                                            <span class="text-success fw-bold">${formatCurrency(cust.receivable)}</span>
                                        </div>
                                        ${cust.pendingChecks > 0 ? `
                                            <div class="small text-muted mt-1">
                                                <i class="bi bi-clock text-info me-1"></i>چک در دست: <span class="text-info">${formatCurrency(cust.pendingChecks)}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                `).join('') :
                                '<div class="text-center text-muted py-4"><i class="bi bi-check-circle fs-3 d-block mb-2 text-success"></i>طلبی وجود ندارد</div>'
                            }
                        </div>
                        ${customerReceivables.totalPendingChecks > 0 ? `
                            <div class="card-footer bg-success bg-opacity-10 small">
                                <div class="d-flex justify-content-between">
                                    <span><i class="bi bi-clock me-1"></i>جمع چک‌های در دست:</span>
                                    <strong class="text-info">${formatCurrency(customerReceivables.totalPendingChecks)}</strong>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span><i class="bi bi-calculator me-1"></i>طلب خالص (پس از وصول):</span>
                                    <strong class="text-success">${formatCurrency(customerReceivables.totalReceivableWithPendingChecks)}</strong>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>`;

        // Cash Position section
        const cashPos = data.cashPosition || { accounts: [], total: 0 };
        let cashAccountsHtml = '';
        if (cashPos.accounts && cashPos.accounts.length > 0) {
            cashPos.accounts.forEach(function(acc) {
                const balClass = acc.current_balance >= 0 ? 'text-primary' : 'text-danger';
                cashAccountsHtml += '<div class="d-flex justify-content-between py-2 px-3 border-bottom person-item">' +
                    '<span class="text-truncate" style="max-width: 150px;">' + UI.escapeHtml(acc.name) + '</span>' +
                    '<span class="' + balClass + ' fw-bold">' + formatCurrency(acc.current_balance) + '</span>' +
                    '</div>';
            });
        } else {
            cashAccountsHtml = '<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>حسابی تعریف نشده</div>';
        }

        html += `
            <div class="row g-4">
                <div class="col-12">
                    <div class="card section-card mb-4">
                        <div class="section-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-wallet2 text-primary me-2"></i>موجودی نقدی مجموعه (مانده حساب‌های لحاظ‌شده)</h6>
                            <span class="badge bg-primary">${formatCurrency(cashPos.total)}</span>
                        </div>
                        <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                            ${cashAccountsHtml}
                        </div>
                        <div class="card-footer small text-muted">فقط جمع مانده؛ تراکنش‌های حساب‌های خارج‌شده همچنان در سودوزیان می‌آیند.</div>
                    </div>
                </div>
            </div>`;

        // Partner Status Section — uses backend capital breakdown when available
        if (data.partners && data.partners.length > 0) {
            html += `
            <div class="card section-card mt-4">
                <div class="section-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>صورت وضعیت شرکا (طلب از شرکت)</h6>
                    <span class="small text-muted">مانده = اول دوره + سود سهمی + بستانکاری − بدهکاری</span>
                </div>
                <div class="card-body">`;

            let sumPartnerClosing = 0;
            data.partners.forEach((p, index) => {
                const sharePercentLabel = p.sharePercent != null
                    ? Number(p.sharePercent).toFixed(1)
                    : ((Number(p.shareRatio) || Number(p.share) || 0) * 100).toFixed(0);
                const profitShare = p.netProfitShare != null
                    ? Number(p.netProfitShare)
                    : Number(data.netOperatingProfit) * (Number(p.shareRatio) || 0);
                const grossShare = Number(p.grossProfitShare || 0);
                const cap = p.capital || null;
                const uid = `pl-p${index}`;

                let opening = 0, totalDeposits = 0, totalWithdrawals = 0, finalBalance = profitShare;
                let dep = {}, wd = {}, depRows = {}, wdRows = {};
                if (cap) {
                    opening = Number(cap.opening || 0);
                    totalDeposits = Number(cap.deposits?.total || 0);
                    totalWithdrawals = Number(cap.withdrawals?.total || 0);
                    finalBalance = Number(cap.closing != null ? cap.closing : (opening + profitShare + totalDeposits - totalWithdrawals));
                    dep = cap.deposits || {};
                    wd = cap.withdrawals || {};
                    depRows = dep.rows || {};
                    wdRows = wd.rows || {};
                } else {
                    // Legacy fallback if capital payload missing
                    const partnerAccount = (data.accounts || []).find(acc => acc.partner_id == p.id);
                    const directDeposits = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'receipt').reduce((s, t) => s + Number(t.amount), 0);
                    const directWithdrawals = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'payment').reduce((s, t) => s + Number(t.amount), 0);
                    let expensesFromPersonal = 0, paymentsFromPersonal = 0, paymentsToPersonal = 0, transfersIn = 0, transfersOut = 0, nipOut = 0, nipIn = 0;
                    if (partnerAccount) {
                        expensesFromPersonal = (data.expenses || []).filter(e => e.account_id == partnerAccount.id).reduce((s, e) => s + Number(e.amount), 0);
                        const personalPayments = (data.payments || []).filter(pay => pay.account_id == partnerAccount.id);
                        paymentsFromPersonal = personalPayments.filter(pay => pay.invoiceType === 'purchase').reduce((s, pay) => s + Number(pay.amount), 0);
                        paymentsToPersonal = personalPayments.filter(pay => pay.invoiceType === 'sales').reduce((s, pay) => s + Number(pay.amount), 0);
                        const nonInvoice = (data.non_invoice_person_payments || []).filter(pay => pay.account_id == partnerAccount.id);
                        nipOut = nonInvoice.filter(pay => pay.transaction_type === 'payment').reduce((s, pay) => s + Number(pay.amount), 0);
                        nipIn = nonInvoice.filter(pay => pay.transaction_type === 'receipt').reduce((s, pay) => s + Number(pay.amount), 0);
                        const transfers = (data.transfers || []).filter(t => t.account_id == partnerAccount.id && !Number(t.is_inter_partner));
                        transfersOut = transfers.filter(t => t.transaction_type === 'payment').reduce((s, t) => s + Number(t.amount), 0);
                        transfersIn = transfers.filter(t => t.transaction_type === 'receipt').reduce((s, t) => s + Number(t.amount), 0);
                    }
                    totalDeposits = directDeposits + expensesFromPersonal + paymentsFromPersonal + transfersOut + nipOut;
                    totalWithdrawals = directWithdrawals + paymentsToPersonal + transfersIn + nipIn;
                    finalBalance = profitShare + totalDeposits - totalWithdrawals;
                    dep = { direct: directDeposits, expenses: expensesFromPersonal, purchasePayments: paymentsFromPersonal, transfersToCompany: transfersOut, personPayments: nipOut };
                    wd = { direct: directWithdrawals, salesReceipts: paymentsToPersonal, transfersFromCompany: transfersIn, personReceipts: nipIn };
                }
                sumPartnerClosing += finalBalance;

                html += `
                    <div class="partner-detail-card ${index > 0 ? 'mt-4 pt-4 border-top' : ''}">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3"><i class="bi bi-person-circle text-primary fs-4"></i></div>
                                <div>
                                    <h5 class="mb-0">${UI.escapeHtml(p.name)}</h5>
                                    <span class="badge bg-secondary">${sharePercentLabel}% سهم</span>
                                    <span class="badge bg-light text-dark border ms-1">ناخالص سهمی: ${formatCurrency(grossShare)}</span>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">مانده پایان دوره (طلب)</div>
                                <div class="fs-4 fw-bold ${finalBalance >= 0 ? 'text-primary' : 'text-danger'}">${formatCurrency(finalBalance)}</div>
                                ${finalBalance >= 0 ? '<span class="badge bg-primary">طلبکار از شرکت</span>' : '<span class="badge bg-danger">بدهکار به شرکت</span>'}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-3"><div class="card h-100 border-0 bg-light"><div class="card-body text-center py-3"><div class="small text-muted">مانده اول دوره</div><div class="fs-5 fw-bold">${formatCurrency(opening)}</div></div></div></div>
                            <div class="col-md-3"><div class="card h-100 border-0 bg-light"><div class="card-body text-center py-3"><div class="small text-muted">سود تخصیص‌یافته</div><div class="fs-5 fw-bold ${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</div></div></div></div>
                            <div class="col-md-3">
                                <div class="card h-100 border-success border-opacity-50">
                                    <div class="card-header bg-success bg-opacity-10 py-2"><div class="d-flex justify-content-between align-items-center"><span class="text-success fw-bold small"><i class="bi bi-arrow-down-circle me-1"></i>بستانکاری دوره</span><span class="badge bg-success">${formatCurrency(totalDeposits)}</span></div></div>
                                    <div class="card-body py-2 small">
                                        ${detailSection(`${uid}-dep-direct`, 'واریز مستقیم (نقد)', dep.direct, 'text-success', depRows.direct, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-dep-chkmov`, 'واریز چک از شریک', dep.checks, 'text-success', depRows.checks, [dateCol, amountCol, {key:'checkDueDate',label:'سررسید',render:r=>r.checkDueDate?fmtDate(r.checkDueDate):(r.dueDate?fmtDate(r.dueDate):'-')}, descCol])}
                                        ${detailSection(`${uid}-dep-exp`, 'پرداخت هزینه از حساب شخصی', dep.expenses, 'text-success', depRows.expenses, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-dep-purch`, 'پرداخت فاکتور خرید', dep.purchasePayments, 'text-success', depRows.purchasePayments, [dateCol, amountCol, {key:'personName',label:'طرف',render:r=>UI.escapeHtml(r.personName||'-')}, descCol])}
                                        ${detailSection(`${uid}-dep-ret`, 'دریافت مرجوعی خرید', dep.returnReceipts, 'text-success', depRows.returnReceipts, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-dep-tr`, 'انتقال به شرکت', dep.transfersToCompany, 'text-success', depRows.transfersToCompany, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-dep-p2p`, 'انتقال از شریک دیگر (واسطه شرکت)', dep.interPartnerIn, 'text-success', depRows.interPartnerIn, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-dep-nip`, 'پرداخت به اشخاص', dep.personPayments, 'text-success', depRows.personPayments, [dateCol, amountCol, {key:'personName',label:'طرف',render:r=>UI.escapeHtml(r.personName||'-')}, descCol])}
                                        ${detailSection(`${uid}-dep-chk`, 'پاس چک پرداختی از حساب شخصی', dep.cashedPayables, 'text-success', depRows.cashedPayables, [dateCol, amountCol, descCol])}
                                        ${totalDeposits === 0 ? '<div class="text-center text-muted py-2">بدون تراکنش</div>' : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card h-100 border-danger border-opacity-50">
                                    <div class="card-header bg-danger bg-opacity-10 py-2"><div class="d-flex justify-content-between align-items-center"><span class="text-danger fw-bold small"><i class="bi bi-arrow-up-circle me-1"></i>بدهکاری دوره</span><span class="badge bg-danger">${formatCurrency(totalWithdrawals)}</span></div></div>
                                    <div class="card-body py-2 small">
                                        ${detailSection(`${uid}-wd-direct`, 'برداشت مستقیم (نقد)', wd.direct, 'text-danger', wdRows.direct, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-wd-chkmov`, 'برداشت / واگذاری چک به شریک', wd.checks, 'text-danger', wdRows.checks, [dateCol, amountCol, {key:'checkDueDate',label:'سررسید',render:r=>r.checkDueDate?fmtDate(r.checkDueDate):(r.dueDate?fmtDate(r.dueDate):'-')}, descCol])}
                                        ${detailSection(`${uid}-wd-sales`, 'دریافت فاکتور فروش', wd.salesReceipts, 'text-danger', wdRows.salesReceipts, [dateCol, amountCol, {key:'personName',label:'طرف',render:r=>UI.escapeHtml(r.personName||'-')}, descCol])}
                                        ${detailSection(`${uid}-wd-ret`, 'پرداخت مرجوعی فروش', wd.returnPayments, 'text-danger', wdRows.returnPayments, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-wd-tr`, 'انتقال از شرکت', wd.transfersFromCompany, 'text-danger', wdRows.transfersFromCompany, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-wd-p2p`, 'انتقال به شریک دیگر (واسطه شرکت)', wd.interPartnerOut, 'text-danger', wdRows.interPartnerOut, [dateCol, amountCol, descCol])}
                                        ${detailSection(`${uid}-wd-nip`, 'دریافت از اشخاص', wd.personReceipts, 'text-danger', wdRows.personReceipts, [dateCol, amountCol, {key:'personName',label:'طرف',render:r=>UI.escapeHtml(r.personName||'-')}, descCol])}
                                        ${detailSection(`${uid}-wd-chk`, 'وصول چک به حساب شخصی', wd.cashedReceived, 'text-danger', wdRows.cashedReceived, [dateCol, amountCol, descCol])}
                                        ${totalWithdrawals === 0 ? '<div class="text-center text-muted py-2">بدون تراکنش</div>' : ''}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 p-3 rounded ${finalBalance >= 0 ? 'bg-primary bg-opacity-10' : 'bg-danger bg-opacity-10'}">
                            <div class="row text-center small">
                                <div class="col"><div class="text-muted">اول دوره</div><div class="fw-bold">${formatCurrency(opening)}</div></div>
                                <div class="col-auto d-flex align-items-center">+</div>
                                <div class="col"><div class="text-muted">سود</div><div class="fw-bold ${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</div></div>
                                <div class="col-auto d-flex align-items-center">+</div>
                                <div class="col"><div class="text-muted">بستانکاری</div><div class="fw-bold text-success">${formatCurrency(totalDeposits)}</div></div>
                                <div class="col-auto d-flex align-items-center">−</div>
                                <div class="col"><div class="text-muted">بدهکاری</div><div class="fw-bold text-danger">${formatCurrency(totalWithdrawals)}</div></div>
                                <div class="col-auto d-flex align-items-center">=</div>
                                <div class="col"><div class="text-muted">پایان دوره</div><div class="fw-bold ${finalBalance >= 0 ? 'text-primary' : 'text-danger'}">${formatCurrency(finalBalance)}</div></div>
                            </div>
                        </div>

                        ${(() => {
                            const openChecks = (cap && cap.openChecks) ? cap.openChecks : [];
                            const openTotal = Number(cap?.openChecksTotal || 0);
                            const pendDep = Number(cap?.periodPendingCheckDeposits || dep.pendingChecks || 0);
                            const pendWd = Number(cap?.periodPendingCheckWithdrawals || wd.pendingChecks || 0);
                            if (!openChecks.length && pendDep === 0 && pendWd === 0) return '';
                            const kindLabel = (k) => ({
                                endorsed_to_partner: 'واگذارشده به شریک',
                                from_partner_in_hand: 'دریافتی از شریک (نزد شرکت)',
                                to_partner_payable: 'پرداختنی به شریک'
                            }[k] || k);
                            let rowsHtml = openChecks.map(ch => `
                                <tr>
                                    <td>${UI.escapeHtml(ch.checkNumber || '-')}</td>
                                    <td>${ch.dueDate ? fmtDate(ch.dueDate) : '-'}</td>
                                    <td>${UI.escapeHtml(kindLabel(ch.pendingKind))}</td>
                                    <td class="text-end">${formatCurrency(ch.amount)}</td>
                                    <td class="small text-muted">${UI.escapeHtml(ch.label || ch.description || '')}</td>
                                </tr>`).join('');
                            return `
                            <div class="mt-3 border rounded p-3 bg-warning bg-opacity-10">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <strong><i class="bi bi-clock-history text-warning me-1"></i>چک‌های باز / وصول‌نشده (تا پایان دوره)</strong>
                                    <span class="badge bg-warning text-dark">${formatCurrency(openTotal)}</span>
                                </div>
                                <div class="small text-muted mb-2">
                                    شامل چک واگذارشده به شریک و چک‌های در جریان مرتبط با شریک.
                                    تراکنش‌های دوره${pendDep || pendWd ? ` (بستانکار چک pending: ${formatCurrency(pendDep)} · بدهکار چک pending: ${formatCurrency(pendWd)})` : ''} در مانده بالا لحاظ شده‌اند.
                                </div>
                                ${openChecks.length ? `
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0" style="font-size:0.8rem">
                                        <thead class="table-light"><tr><th>شماره</th><th>سررسید</th><th>وضعیت</th><th class="text-end">مبلغ</th><th>شرح</th></tr></thead>
                                        <tbody>${rowsHtml}</tbody>
                                    </table>
                                </div>` : '<div class="small text-muted">چک بازِ مانده‌ای در پایان دوره ثبت نشده؛ فقط حرکت دوره دارای چک pending بوده است.</div>'}
                            </div>`;
                        })()}
                    </div>`;
            });

            html += `
                    <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                        <span class="fw-bold">جمع مانده طلب شرکا در پایان دوره</span>
                        <span class="fs-5 fw-bold text-primary">${formatCurrency(sumPartnerClosing)}</span>
                    </div>
                    <div class="small text-muted mt-1">باید با «سرمایه / طلب پایان دوره» در خلاصه سرمایه هم‌خوان باشد (${formatCurrency(closingCapital)}).</div>
                </div>
            </div>`;
        }

        html += `
            <!-- Summary Alert -->
            <div class="alert ${data.netOperatingProfit >= 0 ? 'alert-success' : 'alert-danger'} mt-4 d-flex align-items-center justify-content-between" style="border-radius: 12px;">
                <div>
                    <i class="bi ${data.netOperatingProfit >= 0 ? 'bi-trophy-fill' : 'bi-exclamation-triangle-fill'} fs-4 me-2"></i>
                    <strong>سود خالص عملیاتی «${UI.escapeHtml(entityName)}»:</strong>
                </div>
                <div class="fs-3 fw-bold" dir="ltr">${formatCurrency(data.netOperatingProfit)}</div>
            </div>
        </div>`;

        container.html(html);
    }

    // ... (Other report generation functions remain unchanged) ...
    function _generatePersonStatementReport(data, container) {
        const { persianStartDate, persianEndDate, includePendingChecks, calculatePendingChecks, showInvoiceDetails, showPreviousBalance, includeUndeliveredOrders, calculateConsignment } = lastReportParams;
        const { transactions, person } = data;
        const reportTitle = `صورتحساب${data.combinedWith ? ' تجمیعی' : ''}: ${UI.escapeHtml(person.name)}`;
        const personType = lastReportParams.personType || 'customer';
        const isCustomer = personType === 'customer';
        // Customer: positive = person owes company.
        // Supplier/partner: positive = company owes person (person is creditor).
        const labels = isCustomer ? {
            debitCol: 'بدهکار به شرکت',
            creditCol: 'بستانکار از شرکت',
            debitTotal: 'جمع بدهکاری شخص به شرکت',
            creditTotal: 'جمع بستانکاری شخص از شرکت',
            closingHint: 'مانده نهایی (نسبت به شرکت)',
            posBalance: '(بدهکار به شرکت)',
            negBalance: '(بستانکار از شرکت)',
            legend: 'بدهکار به شرکت = این شخص به مجموعه بدهکار است · بستانکار از شرکت = مجموعه به این شخص بدهکار است'
        } : {
            debitCol: 'بدهکار به شرکت',
            creditCol: 'بستانکار از شرکت',
            debitTotal: 'جمع بدهکاری شخص به شرکت',
            creditTotal: 'جمع بستانکاری شخص از شرکت',
            closingHint: 'مانده نهایی (نسبت به شرکت)',
            posBalance: '(بستانکار از شرکت)',
            negBalance: '(بدهکار به شرکت)',
            legend: personType === 'partner'
                ? 'بستانکار از شرکت = طلب شریک از مجموعه (واریز، هزینه از جیب، پرداخت برای شرکت) · بدهکار به شرکت = کاهش طلب (برداشت، دریافت وجه شرکت، تسویه با شرکا). توجه: مانده حساب شخصی در بخش بانک‌ها دید کیف‌پول است و ممکن است با این مانده طلب فرق داشته باشد.'
                : 'بستانکار از شرکت = طلب تأمین‌کننده از مجموعه · بدهکار به شرکت = بدهی تأمین‌کننده به مجموعه یا کاهش طلب او'
        };

        const isPreviousBalanceRow = (t) =>
            t.isPreviousBalance || t.date === 'مانده از قبل' || t.date === 'مانده اولیه';

        // Calculate totals based on options (Number() — MySQL amounts arrive as strings;
        // bare += concatenates and Number("1.00"+"2.00") is NaN → formatCurrency shows ۰)
        // Period totals exclude the previous-balance row (shown separately in summary).
        let totalDebit = 0, totalCredit = 0, pendingDebit = 0, pendingCredit = 0;
        transactions.forEach(t => {
            if (isPreviousBalanceRow(t)) return;
            if (!t.is_consignment || calculateConsignment) {
                const debit = Number(t.debit) || 0;
                const credit = Number(t.credit) || 0;
                if (t.isPendingCheck) {
                    pendingDebit += debit;
                    pendingCredit += credit;
                    if (calculatePendingChecks) {
                        totalDebit += debit;
                        totalCredit += credit;
                    }
                } else {
                    totalDebit += debit;
                    totalCredit += credit;
                }
            }
        });
        
        const hasPreviousRow = showPreviousBalance && transactions.some(isPreviousBalanceRow);
        const openingBalance = showPreviousBalance
            ? Number(data.openingBalance != null ? data.openingBalance : (person.initial_balance || 0))
            : 0;
        const netMovement = isCustomer ? (totalDebit - totalCredit) : (totalCredit - totalDebit);
        const closingBalance = openingBalance + netMovement;
        const closingLabel = closingBalance >= 0 ? labels.posBalance : labels.negBalance;

        let reportHtml = `<h4 class="mb-3">${reportTitle} (از ${persianStartDate} تا ${persianEndDate})</h4>`;

        // Options summary
        let optionsHtml = '<small class="text-muted">';
        optionsHtml += showPreviousBalance
            ? '<i class="bi bi-check-circle text-success"></i> مانده از قبل نمایش داده شده'
            : '<i class="bi bi-x-circle text-secondary"></i> مانده از قبل مخفی';
        if (includePendingChecks) {
            optionsHtml += calculatePendingChecks ? 
                ' | <i class="bi bi-check-circle text-success"></i> چک‌های سررسید نرسیده در محاسبه لحاظ شده' : 
                ' | <i class="bi bi-clock text-warning"></i> چک‌های سررسید نرسیده فقط نمایش داده شده (محاسبه نشده)';
        } else {
            optionsHtml += ' | <i class="bi bi-x-circle text-secondary"></i> چک‌های سررسید نرسیده مخفی';
        }
        if (showInvoiceDetails) {
            optionsHtml += ' | <i class="bi bi-list-check text-info"></i> با جزئیات محصولات';
        }
        optionsHtml += calculateConsignment
            ? ' | <i class="bi bi-check-circle text-success"></i> فاکتورهای امانی در مانده لحاظ شده'
            : ' | <i class="bi bi-x-circle text-secondary"></i> فاکتورهای امانی فقط نمایش داده شده (محاسبه نشده)';
        if (data.combinedWith) {
            optionsHtml += ` | <i class="bi bi-link-45deg text-primary"></i> تجمیع با حساب ${data.combinedWith.type === 'supplier' ? 'تأمین‌کننده' : 'مشتری'} همین شخص`;
        }
        if (personType === 'supplier' || data.combinedWith) {
            optionsHtml += includeUndeliveredOrders
                ? ' | <i class="bi bi-box-seam text-primary"></i> سفارشات تحویل‌نشده انبار سفارش لحاظ شده'
                : ' | <i class="bi bi-x-circle text-secondary"></i> سفارشات تحویل‌نشده انبار سفارش خارج شده';
            if (includeUndeliveredOrders && Number(data.undeliveredOrderCount || 0) > 0) {
                optionsHtml += ` <span class="badge bg-primary">${data.undeliveredOrderCount} فاکتور</span>`;
            }
        }
        optionsHtml += '</small>';

        reportHtml += `<div class="card mb-4"><div class="card-body">
            <div class="row text-center mb-2">
                <div class="col"><div class="fs-6 text-muted">مانده از قبل</div><div class="fs-5 fw-bold">${showPreviousBalance ? formatCurrency(openingBalance) : '—'}</div></div>
                <div class="col"><div class="fs-6 text-muted">${labels.debitTotal}</div><div class="fs-5 fw-bold text-danger">${formatCurrency(totalDebit)}</div></div>
                <div class="col"><div class="fs-6 text-muted">${labels.creditTotal}</div><div class="fs-5 fw-bold text-success">${formatCurrency(totalCredit)}</div></div>
                <div class="col">
                    <div class="fs-6 text-muted">${labels.closingHint}</div>
                    <div class="fs-5 fw-bold text-primary">${formatCurrency(Math.abs(closingBalance))}</div>
                    <div class="small ${closingBalance >= 0 ? (isCustomer ? 'text-danger' : 'text-success') : (isCustomer ? 'text-success' : 'text-danger')}">${closingLabel}</div>
                </div>
            </div>
            <div class="text-center small text-muted border-top pt-2">${labels.legend}</div>`;
        
        // Show pending checks summary if any
        if (includePendingChecks && (pendingDebit > 0 || pendingCredit > 0)) {
            const pendingTotal = isCustomer ? (pendingCredit - pendingDebit) : (pendingDebit - pendingCredit);
            reportHtml += `<div class="row text-center border-top pt-2 mt-2">
                <div class="col-12">
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-clock me-1"></i>
                        چک‌های سررسید نرسیده: ${formatCurrency(Math.abs(pendingTotal))} 
                        ${calculatePendingChecks ? '(در مانده لحاظ شده)' : '(در مانده لحاظ نشده)'}
                    </span>
                </div>
            </div>`;
        }
        
        reportHtml += `<div class="text-center mt-2">${optionsHtml}</div></div></div>`;

        // Filter transactions based on options
        const filteredTransactions = includePendingChecks ? 
            transactions : 
            transactions.filter(t => !t.isPendingCheck);

        if (filteredTransactions.length === 0) {
            container.html(reportHtml + '<p class="text-center">گردش حسابی در این بازه یافت نشد.</p>');
            return;
        }

        reportHtml += `<table id="person-statement-table" class="table table-bordered table-sm" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th style="width:100px">تاریخ</th>
                    <th>شرح</th>
                    <th style="width:140px" class="text-danger">${labels.debitCol}</th>
                    <th style="width:140px" class="text-success">${labels.creditCol}</th>
                    <th style="width:170px">مانده</th>
                </tr>
            </thead><tbody>`;
        
        // Previous-balance row already carries the opening amounts; start running balance at 0.
        // If toggle is on but row missing (opening 0 edge), seed from openingBalance.
        let balance = hasPreviousRow ? 0 : openingBalance;

        filteredTransactions.forEach(t => {
            const currentDebit = t.debit || 0;
            const currentCredit = t.credit || 0;
            const isPending = t.isPendingCheck;
            const isPrev = isPreviousBalanceRow(t);

            // Update balance based on options
            if (!t.is_consignment || calculateConsignment) {
                if (isPending) {
                    if (calculatePendingChecks) {
                        balance += (isCustomer ? (currentDebit - currentCredit) : (currentCredit - currentDebit));
                    }
                } else {
                    balance += (isCustomer ? (currentDebit - currentCredit) : (currentCredit - currentDebit));
                }
            }

            const balanceText = balance >= 0 ? labels.posBalance : labels.negBalance;
            const dateDisplay = isPrev ? t.date : UI.gregorianToPersian(t.date);

            // Build description with check details
            let descHtml = t.description || '';
            
            // Add check details if available
            if (t.checkDetails) {
                const cd = t.checkDetails;
                let checkInfo = `<div class="small text-muted mt-1">
                    <i class="bi bi-credit-card me-1"></i>
                    شماره چک: ${cd.checkNumber || '-'}`;
                if (cd.bankName) checkInfo += ` | بانک: ${cd.bankName}`;
                if (cd.dueDate) checkInfo += ` | سررسید: ${UI.gregorianToPersian(cd.dueDate)}`;
                if (cd.status) {
                    const statusLabels = { 'pending': 'در انتظار', 'cashed': 'وصول شده', 'bounced': 'برگشتی', 'endorsed': 'خرج شده' };
                    const statusClass = cd.status === 'cashed' ? 'text-success' : cd.status === 'bounced' ? 'text-danger' : 'text-warning';
                    checkInfo += ` | وضعیت: <span class="${statusClass}">${statusLabels[cd.status] || cd.status}</span>`;
                }
                checkInfo += '</div>';
                descHtml += checkInfo;
            }
            
            // Add invoice items if available and option enabled
            if (showInvoiceDetails && t.items && t.items.length > 0) {
                let itemsHtml = '<div class="small text-muted mt-1 border-top pt-1">';
                itemsHtml += '<table class="table table-sm table-borderless mb-0" style="font-size:0.8em"><thead><tr><th>محصول</th><th>ابعاد</th><th>تعداد</th><th>شناسه اقلام</th><th>قیمت واحد</th></tr></thead><tbody>';
                t.items.forEach(item => {
                    const isService = item.is_service == 1;
                    const itemName = isService
                        ? (item.item_description || item.productName || 'خدمات')
                        : (item.productName || item.item_description || '-');
                    const serialText = isService ? '-' : ((item.serialIds && item.serialIds.length) ? item.serialIds.join(', ') : '-');
                    itemsHtml += `<tr>
                        <td style="padding:1px 5px">${itemName}${isService ? ' <span class="badge bg-info">خدمات</span>' : ''}</td>
                        <td style="padding:1px 5px;width:60px">${isService ? '-' : (item.dimensions || '-')}</td>
                        <td style="padding:1px 5px;width:40px">${item.quantity} عدد</td>
                        <td style="padding:1px 5px;width:80px">${serialText}</td>
                        <td style="padding:1px 5px;width:90px">${formatCurrency(item.unitPrice)}</td>
                    </tr>`;
                });
                itemsHtml += '</tbody></table></div>';
                descHtml += itemsHtml;
            }

            // Add link to reference
            if (t.refType && t.refId) {
                descHtml = descHtml.replace(/#(\d+)/, `<a href="#" class="report-link" data-id="${t.refId}" data-type="${t.refType}">#$1</a>`);
            }

            // Row styling
            let rowClass = '';
            if (isPrev) {
                rowClass = 'table-secondary fw-semibold';
            } else if (t.isUndeliveredOrder) {
                rowClass = 'table-info';
            } else if (t.is_consignment && !calculateConsignment) {
                rowClass = 'text-muted fst-italic';
            } else if (isPending) {
                rowClass = 'table-warning';
            }

            const debitDisplay = currentDebit > 0 ? formatCurrency(currentDebit) : '-';
            const creditDisplay = currentCredit > 0 ? formatCurrency(currentCredit) : '-';
            const balanceDisplay = isPending && !calculatePendingChecks ? 
                `<span class="text-muted">-</span>` : 
                `<span class="${balance < 0 ? 'text-danger' : ''}">${formatCurrency(Math.abs(balance))} ${balanceText}</span>`;

            reportHtml += `<tr class="${rowClass}">
                <td>${dateDisplay}${isPending ? '<br><small class="badge bg-warning text-dark">سررسید نرسیده</small>' : ''}${t.isUndeliveredOrder ? '<br><small class="badge bg-primary">سفارش تحویل‌نشده</small>' : ''}</td>
                <td>${descHtml}</td>
                <td class="text-danger">${debitDisplay}</td>
                <td class="text-success">${creditDisplay}</td>
                <td>${balanceDisplay}</td>
            </tr>`;
        });
        
        reportHtml += `</tbody></table>`;
        container.html(reportHtml);
        
        if (filteredTransactions.length > 0) {
            _initDataTable('#person-statement-table', { "ordering": false, "pageLength": 25 });
        }
    }

    function _generateAccountStatementReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { account, transactions } = data;
        const opening = Number(data.openingBalance) || 0;
        const rows = Array.isArray(transactions) ? transactions : [];

        // Number() before += — string amounts from API would concatenate ("1.00"+"2.00")
        // and formatCurrency(NaN) renders as ۰ تومان for the whole summary row.
        let totalCredit = 0, totalDebit = 0;
        rows.forEach(tx => {
            totalCredit += Number(tx.credit) || 0;
            totalDebit += Number(tx.debit) || 0;
        });
        const closingBalance = opening + totalCredit - totalDebit;

        let reportHtml = `<h4 class="mb-3">صورتحساب: ${account.name} (از ${persianStartDate} تا ${persianEndDate})</h4>`;

        reportHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center">
            <div class="col"><div class="fs-6 text-muted">مانده اولیه</div><div class="fs-5 fw-bold">${formatCurrency(opening)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع واریزی</div><div class="fs-5 fw-bold text-success">${formatCurrency(totalCredit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع برداشتی</div><div class="fs-5 fw-bold text-danger">${formatCurrency(totalDebit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">مانده نهایی</div><div class="fs-5 fw-bold text-primary">${formatCurrency(closingBalance)}</div></div>
        </div></div></div>`;

        reportHtml += `<table id="account-statement-table" class="table table-bordered" style="width:100%"><thead class="table-light"><tr><th class="text-end">تاریخ</th><th class="text-end">شرح</th><th class="text-end text-success">بستانکار (واریز)</th><th class="text-end text-danger">بدهکار (برداشت)</th><th class="text-end">مانده</th></tr></thead><tbody>`;

        if (rows.length === 0) {
            reportHtml += '<tr><td colspan="5" class="text-center">گردش حسابی در این بازه یافت نشد.</td></tr>';
        }

        let runningBalance = opening;
        rows.forEach(tx => {
            const bostankar = Number(tx.credit) || 0;
            const bedehkar = Number(tx.debit) || 0;

            runningBalance += (bostankar - bedehkar);
            const descHtml = `<a href="#" class="report-link" data-id="${tx.refId}" data-type="${tx.refType}">${tx.description}</a>`;
            const txDate = UI.gregorianToPersian(tx.date);

            reportHtml += `<tr class="text-end"><td>${txDate}</td><td class="text-end">${descHtml}</td>
                <td class="text-end text-success">${bostankar !== 0 ? formatCurrency(bostankar) : '-'}</td>
                <td class="text-end text-danger">${bedehkar !== 0 ? `(${formatCurrency(bedehkar)})` : '-'}</td>
                <td class="text-end fw-bold">${formatCurrency(runningBalance)}</td></tr>`;
        });
        reportHtml += `</tbody></table>`;
        container.html(reportHtml);

        if (rows.length > 0) {
            _initDataTable('#account-statement-table', { "ordering": false, "paging": false, "info": false });
        }
    }
    
    function _generateInventoryLedgerReport(data, container) {
        const { persianStartDate, persianEndDate, productId } = lastReportParams;
        const { openingStock, transactions } = data;
        const product = appDataCache.products.find(p => p.id == productId);
        const productName = product ? product.name : `محصول #${productId}`;

        let reportHtml = `<h4 class="mb-3">کاردکس کالا: ${productName} (از ${persianStartDate} تا ${persianEndDate})</h4>`;
        const tableId = 'inventory-ledger-table';
        reportHtml += `<table id="${tableId}" class="table table-bordered table-striped" style="width:100%">
            <thead class="table-light"><tr><th>تاریخ</th><th>شناسه انبار</th><th>نوع تراکنش</th><th>ابعاد</th><th>شماره مدرک</th><th>ورودی</th><th>خروجی</th><th>موجودی نهایی</th></tr></thead>
            <tbody></tbody></table>`;
        container.html(reportHtml);

        const tableData = [];
        let runningStock = openingStock;

        tableData.push({
            date: 'موجودی اولیه',
            inventoryIds: '-',
            type: '',
            dimensions: '',
            refLink: '',
            quantityIn: '',
            quantityOut: '',
            runningStock: runningStock,
            isOpening: true
        });

        transactions.forEach(tx => {
            let isIn, typeText, refType;
            
            if (tx.type === 'purchase') {
                isIn = true;
                typeText = 'خرید';
                refType = 'purchaseInvoice';
            } else if (tx.type === 'sales') {
                isIn = false;
                typeText = 'فروش';
                refType = 'salesInvoice';
            } else if (tx.type === 'sales_return') {
                isIn = true; // Return from customer increases stock
                typeText = 'مرجوعی فروش';
                refType = 'salesReturn';
            } else if (tx.type === 'purchase_return') {
                isIn = false; // Return to supplier decreases stock
                typeText = 'مرجوعی خرید';
                refType = 'purchaseReturn';
            }
            
            const quantityIn = isIn ? tx.quantity : 0;
            const quantityOut = !isIn ? tx.quantity : 0;
            runningStock += (quantityIn - quantityOut);

            const refLink = `<a href="#" class="report-link" data-id="${tx.refId}" data-type="${refType}">#${tx.refId}</a>`;

            const inventoryIdsText = tx.inventoryIds && tx.inventoryIds.length > 0 
                ? tx.inventoryIds.join(', ') 
                : '-';

            tableData.push({
                date: UI.gregorianToPersian(tx.date),
                inventoryIds: inventoryIdsText,
                type: typeText,
                dimensions: tx.dimensions,
                refLink: refLink,
                quantityIn: quantityIn || '-',
                quantityOut: quantityOut || '-',
                runningStock: runningStock
            });
        });

        _initDataTable(`#${tableId}`, {
            data: tableData,
            ordering: false,
            columns: [
                { data: 'date' },
                { data: 'inventoryIds' },
                { data: 'type' },
                { data: 'dimensions' },
                { data: 'refLink' },
                { data: 'quantityIn', className: 'text-success' },
                { data: 'quantityOut', className: 'text-danger' },
                { data: 'runningStock', className: 'fw-bold' }
            ],
            createdRow: function (row, data, dataIndex) {
                if (data.isOpening) {
                    $(row).addClass('table-secondary fw-bold').find('td:first').attr('colspan', 7);
                    $(row).find('td:gt(0)').not(':last').remove();
                }
            }
        });
    }

    function _generateExpensesReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { expenses, total } = data;
        let reportHtml = `<h4 class="mb-3">گزارش هزینه‌ها از ${persianStartDate} تا ${persianEndDate}</h4>`;
        if (expenses.length === 0) {
            container.html(reportHtml + `<p class="text-center">هیچ هزینه‌ای یافت نشد.</p>`);
            return;
        }
        reportHtml += `<table id="expenses-report-table" class="table table-striped table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>دسته‌بندی</th><th>مبلغ</th><th>توضیحات</th><th>حساب پرداخت</th></tr></thead><tbody>`;
        expenses.forEach(exp => {
            reportHtml += `<tr><td>${UI.gregorianToPersian(exp.date)}</td><td>${exp.category}</td><td>${formatCurrency(exp.amount)}</td><td>${exp.description || '-'}</td><td>${exp.accountName || '-'}</td></tr>`;
        });
        reportHtml += `</tbody><tfoot class="fw-bold"><tr class="table-secondary"><td colspan="2" class="text-end">جمع کل:</td><td colspan="3">${formatCurrency(total)}</td></tr></tfoot></table>`;
        container.html(reportHtml);
        _initDataTable('#expenses-report-table');
    }

    function _generateIncomesReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { incomes, total } = data;
        let reportHtml = `<h4 class="mb-3">گزارش درآمدها از ${persianStartDate} تا ${persianEndDate}</h4>`;
        if (!incomes || incomes.length === 0) {
            container.html(reportHtml + `<p class="text-center">هیچ درآمدی یافت نشد.</p>`);
            return;
        }
        reportHtml += `<table id="incomes-report-table" class="table table-striped table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>دسته‌بندی</th><th>مبلغ</th><th>توضیحات</th><th>حساب دریافت</th></tr></thead><tbody>`;
        incomes.forEach(inc => {
            reportHtml += `<tr><td>${UI.gregorianToPersian(inc.date)}</td><td>${inc.category}</td><td>${formatCurrency(inc.amount)}</td><td>${inc.description || '-'}</td><td>${inc.accountName || '-'}</td></tr>`;
        });
        reportHtml += `</tbody><tfoot class="fw-bold"><tr class="table-secondary"><td colspan="2" class="text-end">جمع کل:</td><td colspan="3">${formatCurrency(total)}</td></tr></tfoot></table>`;
        container.html(reportHtml);
        _initDataTable('#incomes-report-table');
    }

    function _generateInventoryReport(data, container) {
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';
        const { items, summary } = data;
        
        // Separate items by inventory type
        const ownItems = items.filter(item => item.inventoryType === 'own');
        const consignmentOutItems = items.filter(item => item.inventoryType === 'consignment_out');
        const consignmentInItems = items.filter(item => item.inventoryType === 'consignment_in');
        
        let html = `
        <style>
            .inventory-report .stat-card {
                border-radius: 12px;
                border: none;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                transition: transform 0.2s;
            }
            .inventory-report .stat-card:hover {
                transform: translateY(-2px);
            }
            .inventory-report .stat-icon {
                width: 50px;
                height: 50px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
            }
            .inventory-report .consignment-out-badge {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                color: white;
            }
            .inventory-report .consignment-in-badge {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .inventory-report .nav-tabs .nav-link {
                border-radius: 8px 8px 0 0;
                font-weight: 500;
                padding: 12px 20px;
                transition: all 0.3s;
            }
            .inventory-report .nav-tabs .nav-link.active {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
                color: white !important;
                border: none;
            }
            .inventory-report .nav-tabs .nav-link.consignment-out-tab.active {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%) !important;
            }
            .inventory-report .nav-tabs .nav-link.consignment-in-tab.active {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            }
            .inventory-report .tab-content {
                border: 1px solid #dee2e6;
                border-top: none;
                border-radius: 0 0 8px 8px;
                padding: 20px;
                background: white;
            }
        </style>
        
        <div class="inventory-report">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <div>
                    <h4 class="mb-1"><i class="bi bi-box-seam text-primary me-2"></i>گزارش موجودی انبار</h4>
                    <p class="text-muted mb-0">${UI.escapeHtml(entityName)}</p>
                </div>
                <span class="badge bg-primary fs-6 px-3 py-2">
                    <i class="bi bi-calendar3 me-1"></i>${UI.gregorianToPersian(new Date().toISOString().split('T')[0])}
                </span>
            </div>`;

        if (!items || items.length === 0) {
            html += `
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">هیچ محصولی با موجودی یافت نشد</h5>
                    <p class="text-muted">انبار شما خالی است یا تمام محصولات فروخته شده‌اند.</p>
                </div>
            </div>`;
            container.html(html);
            return;
        }

        html += `
            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                                    <i class="bi bi-boxes"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">تعداد کل موجودی</div>
                                    <div class="fs-4 fw-bold text-primary">${Number(summary?.totalQuantity || 0).toLocaleString('fa-IR')}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border-success">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                                    <i class="bi bi-house-check"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">موجودی خودی</div>
                                    <div class="fs-4 fw-bold text-success">${Number(summary?.ownQuantity || 0).toLocaleString('fa-IR')}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border-warning">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                                    <i class="bi bi-send"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">امانی فروش (نزد مشتری)</div>
                                    <div class="fs-4 fw-bold text-warning">${Number(summary?.consignmentOutQuantity || 0).toLocaleString('fa-IR')}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border-info">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">امانی خرید (از تامین‌کننده)</div>
                                    <div class="fs-4 fw-bold text-info">${Number(summary?.consignmentInQuantity || 0).toLocaleString('fa-IR')}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs" id="inventoryTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="own-inv-tab" data-bs-toggle="tab" data-bs-target="#own-inv-content" type="button" role="tab">
                        <i class="bi bi-house-check me-2"></i>موجودی خودی
                        <span class="badge bg-success ms-2">${ownItems.length}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link consignment-out-tab" id="consignment-out-inv-tab" data-bs-toggle="tab" data-bs-target="#consignment-out-inv-content" type="button" role="tab">
                        <i class="bi bi-send me-2"></i>امانی فروش (نزد مشتری)
                        <span class="badge consignment-out-badge ms-2">${consignmentOutItems.length}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link consignment-in-tab" id="consignment-in-inv-tab" data-bs-toggle="tab" data-bs-target="#consignment-in-inv-content" type="button" role="tab">
                        <i class="bi bi-box-arrow-in-down me-2"></i>امانی خرید (از تامین‌کننده)
                        <span class="badge consignment-in-badge ms-2">${consignmentInItems.length}</span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabsContent">
                <!-- Own Inventory Tab -->
                <div class="tab-pane fade show active" id="own-inv-content" role="tabpanel">
                    <div class="alert alert-success d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>موجودی خودی:</strong> کالاهایی که خریداری شده و در انبار شما موجود هستند.</div>
                    </div>
                    ${ownItems.length > 0 ? `
                    <table id="own-inv-table" class="table table-hover" style="width:100%">
                        <thead class="table-success">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه کالا</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>تعداد موجود</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>موجودی خودی وجود ندارد</div>`}
                </div>

                <!-- Consignment Out (Sales) Tab -->
                <div class="tab-pane fade" id="consignment-out-inv-content" role="tabpanel">
                    <div class="alert alert-warning d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>امانی فروش:</strong> کالاهای شما که به صورت امانی نزد مشتری قرار دارند و هنوز فروخته نشده‌اند.</div>
                    </div>
                    ${consignmentOutItems.length > 0 ? `
                    <table id="consignment-out-inv-table" class="table table-hover" style="width:100%">
                        <thead class="table-warning">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه کالا</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>تعداد موجود</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-send fs-1 d-block mb-2"></i>کالای امانی نزد مشتری وجود ندارد</div>`}
                </div>

                <!-- Consignment In (Purchase) Tab -->
                <div class="tab-pane fade" id="consignment-in-inv-content" role="tabpanel">
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>امانی خرید:</strong> کالاهایی که از تامین‌کننده به صورت امانی دریافت کرده‌اید و هنوز تسویه نشده‌اند.</div>
                    </div>
                    ${consignmentInItems.length > 0 ? `
                    <table id="consignment-in-inv-table" class="table table-hover" style="width:100%">
                        <thead class="table-info">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه کالا</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>تعداد موجود</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-box-arrow-in-down fs-1 d-block mb-2"></i>کالای امانی از تامین‌کننده وجود ندارد</div>`}
                </div>
            </div>
        </div>`;
        
        container.html(html);

        // Initialize own inventory table
        if (ownItems.length > 0) {
            _initDataTable('#own-inv-table', {
                data: ownItems,
                columns: [
                    { data: 'name', render: (d) => `<strong>${UI.escapeHtml(d)}</strong>` },
                    { data: 'serialIds', render: (d) => _formatInventorySerialIds(d) },
                    { data: 'dimensions', render: (d) => d ? `<span class="badge bg-secondary">${UI.escapeHtml(d)}</span>` : '<span class="text-muted">-</span>' },
                    { 
                        data: 'quantity', className: 'text-center',
                        render: (d) => {
                            const qty = Number(d);
                            let badgeClass = 'bg-success';
                            if (qty <= 5) badgeClass = 'bg-danger';
                            else if (qty <= 20) badgeClass = 'bg-warning text-dark';
                            return `<span class="badge ${badgeClass} fs-6 px-3">${qty.toLocaleString('fa-IR')}</span>`;
                        }
                    }
                ]
            });
        }

        // Initialize consignment out table on tab show
        if (consignmentOutItems.length > 0) {
            $('#consignment-out-inv-tab').on('shown.bs.tab', function() {
                if (!$.fn.DataTable.isDataTable('#consignment-out-inv-table')) {
                    _initDataTable('#consignment-out-inv-table', {
                        data: consignmentOutItems,
                        columns: [
                            { data: 'name', render: (d) => `<strong>${UI.escapeHtml(d)}</strong>` },
                            { data: 'serialIds', render: (d) => _formatInventorySerialIds(d) },
                            { data: 'dimensions', render: (d) => d ? `<span class="badge bg-secondary">${UI.escapeHtml(d)}</span>` : '<span class="text-muted">-</span>' },
                            { 
                                data: 'quantity', className: 'text-center',
                                render: (d) => `<span class="badge consignment-out-badge fs-6 px-3">${Number(d).toLocaleString('fa-IR')}</span>`
                            }
                        ]
                    });
                }
            });
        }

        // Initialize consignment in table on tab show
        if (consignmentInItems.length > 0) {
            $('#consignment-in-inv-tab').on('shown.bs.tab', function() {
                if (!$.fn.DataTable.isDataTable('#consignment-in-inv-table')) {
                    _initDataTable('#consignment-in-inv-table', {
                        data: consignmentInItems,
                        columns: [
                            { data: 'name', render: (d) => `<strong>${UI.escapeHtml(d)}</strong>` },
                            { data: 'serialIds', render: (d) => _formatInventorySerialIds(d) },
                            { data: 'dimensions', render: (d) => d ? `<span class="badge bg-secondary">${UI.escapeHtml(d)}</span>` : '<span class="text-muted">-</span>' },
                            { 
                                data: 'quantity', className: 'text-center',
                                render: (d) => `<span class="badge consignment-in-badge fs-6 px-3">${Number(d).toLocaleString('fa-IR')}</span>`
                            }
                        ]
                    });
                }
            });
        }
    }

    function _generateInventoryValueReport(data, container) {
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';
        const { items: allItems, totalValue, summary } = data;
        
        // Separate items by inventory type
        const ownItems = allItems.filter(item => item.inventoryType === 'own');
        const consignmentOutItems = allItems.filter(item => item.inventoryType === 'consignment_out');
        const consignmentInItems = allItems.filter(item => item.inventoryType === 'consignment_in');
        
        let html = `
        <style>
            .inventory-value-report .stat-card {
                border-radius: 12px;
                border: none;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                transition: transform 0.2s;
            }
            .inventory-value-report .stat-card:hover {
                transform: translateY(-2px);
            }
            .inventory-value-report .stat-icon {
                width: 50px;
                height: 50px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
            }
            .inventory-value-report .value-highlight {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border-radius: 12px;
            }
            .inventory-value-report .own-card {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
                color: white;
                border-radius: 12px;
            }
            .inventory-value-report .consignment-out-card {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                color: white;
                border-radius: 12px;
            }
            .inventory-value-report .consignment-in-card {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border-radius: 12px;
            }
            .inventory-value-report .consignment-out-badge {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                color: white;
            }
            .inventory-value-report .consignment-in-badge {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .inventory-value-report .nav-tabs .nav-link {
                border-radius: 8px 8px 0 0;
                font-weight: 500;
                padding: 12px 20px;
                transition: all 0.3s;
            }
            .inventory-value-report .nav-tabs .nav-link.active {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
                color: white !important;
                border: none;
            }
            .inventory-value-report .nav-tabs .nav-link.consignment-out-tab.active {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%) !important;
            }
            .inventory-value-report .nav-tabs .nav-link.consignment-in-tab.active {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            }
            .inventory-value-report .tab-content {
                border: 1px solid #dee2e6;
                border-top: none;
                border-radius: 0 0 8px 8px;
                padding: 20px;
                background: white;
            }
        </style>
        
        <div class="inventory-value-report">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <div>
                    <h4 class="mb-1"><i class="bi bi-currency-dollar text-success me-2"></i>گزارش ارزش موجودی انبار</h4>
                    <p class="text-muted mb-0">${UI.escapeHtml(entityName)}</p>
                </div>
                <span class="badge bg-success fs-6 px-3 py-2">
                    <i class="bi bi-calendar3 me-1"></i>${UI.gregorianToPersian(new Date().toISOString().split('T')[0])}
                </span>
            </div>`;

        if (!allItems || allItems.length === 0) {
            html += `
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">هیچ محصولی با موجودی یافت نشد</h5>
                    <p class="text-muted">انبار شما خالی است یا تمام محصولات فروخته شده‌اند.</p>
                </div>
            </div>`;
            container.html(html);
            return;
        }

        html += `
            <!-- Main Value Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card value-highlight h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-safe fs-3 mb-1"></i>
                            <div class="small opacity-75">ارزش کل موجودی</div>
                            <div class="fs-4 fw-bold">${formatCurrency(totalValue)}</div>
                            <div class="small opacity-75">${Number(summary?.totalQuantity || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card own-card h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-house-check fs-3 mb-1"></i>
                            <div class="small opacity-75">موجودی خودی</div>
                            <div class="fs-4 fw-bold">${formatCurrency(summary?.ownValue || 0)}</div>
                            <div class="small opacity-75">${Number(summary?.ownQuantity || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card consignment-out-card h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-send fs-3 mb-1"></i>
                            <div class="small opacity-75">امانی فروش (نزد مشتری)</div>
                            <div class="fs-4 fw-bold">${formatCurrency(summary?.consignmentOutValue || 0)}</div>
                            <div class="small opacity-75">${Number(summary?.consignmentOutQuantity || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card consignment-in-card h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-box-arrow-in-down fs-3 mb-1"></i>
                            <div class="small opacity-75">امانی خرید (از تامین‌کننده)</div>
                            <div class="fs-4 fw-bold">${formatCurrency(summary?.consignmentInValue || 0)}</div>
                            <div class="small opacity-75">${Number(summary?.consignmentInQuantity || 0).toLocaleString('fa-IR')} عدد</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Value Distribution Progress -->
            <div class="card stat-card mb-4">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>توزیع ارزش موجودی</h6>
                    <div class="d-flex align-items-center mb-2">
                        <span class="text-muted small me-2" style="width: 150px;">موجودی خودی:</span>
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: ${totalValue > 0 ? ((summary?.ownValue || 0) / totalValue * 100) : 0}%">
                                ${totalValue > 0 ? Math.round((summary?.ownValue || 0) / totalValue * 100) : 0}%
                            </div>
                        </div>
                        <span class="text-success fw-bold ms-2" style="width: 130px; text-align: left;">${formatCurrency(summary?.ownValue || 0)}</span>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <span class="text-muted small me-2" style="width: 150px;">امانی فروش:</span>
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: ${totalValue > 0 ? ((summary?.consignmentOutValue || 0) / totalValue * 100) : 0}%; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                ${totalValue > 0 ? Math.round((summary?.consignmentOutValue || 0) / totalValue * 100) : 0}%
                            </div>
                        </div>
                        <span class="text-danger fw-bold ms-2" style="width: 130px; text-align: left;">${formatCurrency(summary?.consignmentOutValue || 0)}</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="text-muted small me-2" style="width: 150px;">امانی خرید:</span>
                        <div class="progress flex-grow-1" style="height: 18px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: ${totalValue > 0 ? ((summary?.consignmentInValue || 0) / totalValue * 100) : 0}%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                ${totalValue > 0 ? Math.round((summary?.consignmentInValue || 0) / totalValue * 100) : 0}%
                            </div>
                        </div>
                        <span class="text-primary fw-bold ms-2" style="width: 130px; text-align: left;">${formatCurrency(summary?.consignmentInValue || 0)}</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs" id="inventoryValueTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="own-value-tab" data-bs-toggle="tab" data-bs-target="#own-value-content" type="button" role="tab">
                        <i class="bi bi-house-check me-2"></i>موجودی خودی
                        <span class="badge bg-success ms-2">${ownItems.length}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link consignment-out-tab" id="consignment-out-value-tab" data-bs-toggle="tab" data-bs-target="#consignment-out-value-content" type="button" role="tab">
                        <i class="bi bi-send me-2"></i>امانی فروش
                        <span class="badge consignment-out-badge ms-2">${consignmentOutItems.length}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link consignment-in-tab" id="consignment-in-value-tab" data-bs-toggle="tab" data-bs-target="#consignment-in-value-content" type="button" role="tab">
                        <i class="bi bi-box-arrow-in-down me-2"></i>امانی خرید
                        <span class="badge consignment-in-badge ms-2">${consignmentInItems.length}</span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryValueTabsContent">
                <!-- Own Inventory Value Tab -->
                <div class="tab-pane fade show active" id="own-value-content" role="tabpanel">
                    <div class="alert alert-success d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>موجودی خودی:</strong> کالاهایی که خریداری شده و در انبار شما موجود هستند. ارزش کل: <strong>${formatCurrency(summary?.ownValue || 0)}</strong></div>
                    </div>
                    ${ownItems.length > 0 ? `
                    <table id="own-value-table" class="table table-hover" style="width:100%">
                        <thead class="table-success">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>موجودی</th>
                                <th class="text-end"><i class="bi bi-tag me-1"></i>آخرین قیمت</th>
                                <th class="text-end"><i class="bi bi-currency-dollar me-1"></i>ارزش</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot class="fw-bold table-success">
                            <tr>
                                <td colspan="5" class="text-end">جمع کل:</td>
                                <td class="text-end text-success">${formatCurrency(summary?.ownValue || 0)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>موجودی خودی وجود ندارد</div>`}
                </div>

                <!-- Consignment Out (Sales) Tab -->
                <div class="tab-pane fade" id="consignment-out-value-content" role="tabpanel">
                    <div class="alert alert-warning d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>امانی فروش:</strong> کالاهای شما که نزد مشتری به صورت امانی قرار دارند. ارزش کل: <strong>${formatCurrency(summary?.consignmentOutValue || 0)}</strong></div>
                    </div>
                    ${consignmentOutItems.length > 0 ? `
                    <table id="consignment-out-value-table" class="table table-hover" style="width:100%">
                        <thead class="table-warning">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>موجودی</th>
                                <th class="text-end"><i class="bi bi-tag me-1"></i>آخرین قیمت</th>
                                <th class="text-end"><i class="bi bi-currency-dollar me-1"></i>ارزش</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot class="fw-bold table-warning">
                            <tr>
                                <td colspan="5" class="text-end">جمع کل:</td>
                                <td class="text-end text-danger">${formatCurrency(summary?.consignmentOutValue || 0)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-send fs-1 d-block mb-2"></i>کالای امانی نزد مشتری وجود ندارد</div>`}
                </div>

                <!-- Consignment In (Purchase) Tab -->
                <div class="tab-pane fade" id="consignment-in-value-content" role="tabpanel">
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle fs-4 me-3"></i>
                        <div><strong>امانی خرید:</strong> کالاهایی که از تامین‌کننده به صورت امانی دریافت کرده‌اید. ارزش کل: <strong>${formatCurrency(summary?.consignmentInValue || 0)}</strong></div>
                    </div>
                    ${consignmentInItems.length > 0 ? `
                    <table id="consignment-in-value-table" class="table table-hover" style="width:100%">
                        <thead class="table-info">
                            <tr>
                                <th><i class="bi bi-box me-1"></i>نام محصول</th>
                                <th><i class="bi bi-hash me-1"></i>شناسه</th>
                                <th><i class="bi bi-rulers me-1"></i>ابعاد</th>
                                <th class="text-center"><i class="bi bi-stack me-1"></i>موجودی</th>
                                <th class="text-end"><i class="bi bi-tag me-1"></i>آخرین قیمت</th>
                                <th class="text-end"><i class="bi bi-currency-dollar me-1"></i>ارزش</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot class="fw-bold table-info">
                            <tr>
                                <td colspan="5" class="text-end">جمع کل:</td>
                                <td class="text-end text-primary">${formatCurrency(summary?.consignmentInValue || 0)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    ` : `<div class="text-center py-4 text-muted"><i class="bi bi-box-arrow-in-down fs-1 d-block mb-2"></i>کالای امانی از تامین‌کننده وجود ندارد</div>`}
                </div>
            </div>
        </div>`;
        
        container.html(html);

        // DataTable columns configuration
        const valueTableColumns = [
            { data: 'name', render: (d) => `<strong>${UI.escapeHtml(d)}</strong>` },
            { data: 'serialIds', render: (d) => _formatInventorySerialIds(d) },
            { data: 'dimensions', render: (d) => d ? `<span class="badge bg-secondary">${UI.escapeHtml(d)}</span>` : '<span class="text-muted">-</span>' },
            { data: 'quantity', className: 'text-center', render: (d) => `<span class="badge bg-secondary px-2">${Number(d).toLocaleString('fa-IR')}</span>` },
            { data: 'lastPrice', className: 'text-end', render: (d) => formatCurrency(d) },
            { data: 'rowValue', className: 'text-end', render: (d) => `<strong>${formatCurrency(d)}</strong>` }
        ];

        // Initialize own inventory value table
        if (ownItems.length > 0) {
            _initDataTable('#own-value-table', { data: ownItems, columns: valueTableColumns });
        }

        // Initialize consignment out value table on tab show
        if (consignmentOutItems.length > 0) {
            $('#consignment-out-value-tab').on('shown.bs.tab', function() {
                if (!$.fn.DataTable.isDataTable('#consignment-out-value-table')) {
                    _initDataTable('#consignment-out-value-table', { data: consignmentOutItems, columns: valueTableColumns });
                }
            });
        }

        // Initialize consignment in value table on tab show
        if (consignmentInItems.length > 0) {
            $('#consignment-in-value-tab').on('shown.bs.tab', function() {
                if (!$.fn.DataTable.isDataTable('#consignment-in-value-table')) {
                    _initDataTable('#consignment-in-value-table', { data: consignmentInItems, columns: valueTableColumns });
                }
            });
        }
    }

    function _generateCogsProfitReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { summary, items } = data;

        let finalHtml = `<h4 class="mb-3">گزارش سود بر اساس فروش کالا از ${persianStartDate} تا ${persianEndDate}</h4>`;

        finalHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center">
            <div class="col"><div class="fs-6 text-muted">جمع فروش (خالص)</div><div class="fs-5 fw-bold text-primary">${formatCurrency(summary.totalSale)}</div></div>
            <div class="col"><div class="fs-6 text-muted">بهای تمام شده (خرید)</div><div class="fs-5 fw-bold text-warning">${formatCurrency(summary.totalCogs)}</div></div>
            <div class="col"><div class="fs-6 text-muted">سود ناخالص</div><div class="fs-5 fw-bold text-success">${formatCurrency(summary.totalProfit)}</div></div>
        </div></div></div>`;

        if (!items || items.length === 0) {
            finalHtml += `<p class="text-center">کالایی در این بازه زمانی فروخته نشده است.</p>`;
            container.html(finalHtml);
            return;
        }

        finalHtml += `<table id="cogs-report-table" class="table table-striped table-bordered" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>تاریخ فروش</th>
                    <th>فاکتور</th>
                    <th>نام کالا</th>
                    <th>ابعاد</th>
                    <th>تعداد</th>
                    <th>فروش خالص (ردیف)</th>
                    <th>تخفیف سهمی</th>
                    <th>قیمت خرید (واحد)</th>
                    <th>سود (ردیف)</th>
                </tr>
            </thead>
            <tbody>`;

        items.forEach(item => {
            const profitClass = item.profit >= 0 ? 'text-success' : 'text-danger';
            finalHtml += `<tr>
                <td>${UI.gregorianToPersian(item.date)}</td>
                <td><a href="#" class="report-link" data-id="${item.invoiceId}" data-type="salesInvoice">#${item.invoiceId}</a></td>
                <td>${item.productName}</td>
                <td>${item.dimensions}</td>
                <td>${item.quantity}</td>
                <td class="text-primary">${formatCurrency(item.totalSale)}</td>
                <td class="text-info">${formatCurrency(item.proportionalDiscount)}</td>
                <td class="text-warning">${formatCurrency(item.purchasePrice)}</td>
                <td class="fw-bold ${profitClass}">${formatCurrency(item.profit)}</td>
            </tr>`;
        });

        finalHtml += `</tbody></table>`;
        container.html(finalHtml);
        _initDataTable('#cogs-report-table', {
            "order": [[0, "asc"]]
        });
    }

    // ==================== AGED RECEIVABLES REPORT ====================
    function _generateAgedReceivablesReport(data, container) {
        if (!data || !data.persons || data.persons.length === 0) {
            container.html('<div class="alert alert-info">بدهی معوقه‌ای وجود ندارد.</div>');
            return;
        }

        const { persianStartDate, persianEndDate } = lastReportParams;
        const typeLabel = data.type === 'customer' ? 'مشتریان' : 'تأمین‌کنندگان';
        let html = `<h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>گزارش سن بدهی ${typeLabel}</h5>`;

        // Bucket boundaries come from the ar_aging_buckets setting (default 30,60,90)
        // and must match the keys produced by ReportModel.
        const bucketDefs = (function () {
            const defaults = (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS && AppConfig.DEFAULTS.AR_AGING_BUCKETS) || [30, 60, 90];
            const raw = (typeof UI !== 'undefined' && UI.getAppSetting) ? UI.getAppSetting('ar_aging_buckets', defaults.join(',')) : defaults.join(',');
            const bounds = String(raw).split(/[,،]/).map(s => parseInt(s.trim(), 10)).filter(n => n > 0).sort((a, b) => a - b);
            const b1 = bounds[0] || 30, b2 = bounds[1] || 60, b3 = bounds[2] || 90;
            const toFa = n => Number(n).toLocaleString('fa-IR');
            return [
                { key: 'current', label: 'جاری', cls: 'text-success' },
                { key: `1_${b1}`, label: `۱-${toFa(b1)} روز`, cls: 'text-info' },
                { key: `${b1 + 1}_${b2}`, label: `${toFa(b1 + 1)}-${toFa(b2)} روز`, cls: 'text-warning' },
                { key: `${b2 + 1}_${b3}`, label: `${toFa(b2 + 1)}-${toFa(b3)} روز`, cls: '', style: 'color:orange' },
                { key: `over_${b3}`, label: `بیش از ${toFa(b3)} روز`, cls: 'text-danger' },
            ];
        })();

        // Summary cards
        html += `<div class="row mb-4">`;
        bucketDefs.forEach(b => {
            html += `<div class="col"><div class="card text-center"><div class="card-body py-2">
                <h6 class="text-muted">${b.label}</h6>
                <h4 class="${b.cls}"${b.style ? ` style="${b.style}"` : ''}>${formatCurrency(data.summary?.[b.key] || 0)}</h4>
            </div></div></div>`;
        });
        html += `<div class="col"><div class="card text-center border-dark"><div class="card-body py-2">
                <h6 class="text-muted">کل معوقات</h6>
                <h4 class="fw-bold">${formatCurrency(data.totalOutstanding || 0)}</h4>
            </div></div></div>
        </div>`;

        html += `<table id="aged-receivables-table" class="table table-striped table-sm">
            <thead><tr>
                <th>شخص</th>${bucketDefs.map(b => `<th>${b.label}</th>`).join('')}<th>کل</th>
            </tr></thead><tbody>`;

        data.persons.forEach(p => {
            html += `<tr>
                <td>${p.person_name || '-'}</td>
                ${bucketDefs.map(b => `<td${b.key.startsWith('over_') ? ' class="text-danger"' : ''}>${formatCurrency(p[b.key] || 0)}</td>`).join('')}
                <td class="fw-bold">${formatCurrency(p.total || 0)}</td>
            </tr>`;
        });

        html += `</tbody></table>`;
        container.html(html);
        _initDataTable('#aged-receivables-table');
    }

    // ==================== CASH FLOW REPORT ====================
    function _generateCashFlowReport(data, container) {
        if (!data) {
            container.html('<div class="alert alert-info">داده‌ای برای نمایش وجود ندارد.</div>');
            return;
        }

        const { persianStartDate, persianEndDate } = lastReportParams;
        let html = `<h5 class="mb-3"><i class="bi bi-water me-2"></i>گزارش جریان وجوه نقد از ${persianStartDate} تا ${persianEndDate}</h5>`;

        // Summary cards - use flat keys from PHP
        const totalIn = data.totalInflow || 0;
        const totalOut = data.totalOutflow || 0;
        const netFlow = data.netCashFlow || 0;
        
        html += `<div class="row mb-4">
            <div class="col-md-4"><div class="card border-success text-center"><div class="card-body">
                <h6 class="text-muted"><i class="bi bi-arrow-down-circle text-success me-1"></i>کل دریافتی‌ها</h6>
                <h4 class="text-success">${formatCurrency(totalIn)}</h4>
            </div></div></div>
            <div class="col-md-4"><div class="card border-danger text-center"><div class="card-body">
                <h6 class="text-muted"><i class="bi bi-arrow-up-circle text-danger me-1"></i>کل پرداختی‌ها</h6>
                <h4 class="text-danger">${formatCurrency(totalOut)}</h4>
            </div></div></div>
            <div class="col-md-4"><div class="card border-primary text-center"><div class="card-body">
                <h6 class="text-muted"><i class="bi bi-graph-up me-1"></i>خالص جریان نقد</h6>
                <h4 class="${netFlow >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(netFlow)}</h4>
            </div></div></div>
        </div>`;

        // Cash flow detail
        html += `<div class="card mb-4"><div class="card-body">
            <h6 class="card-title"><i class="bi bi-list-check me-2"></i>جزئیات جریان وجوه</h6>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr class="table-success"><td class="fw-bold" colspan="2">دریافتی‌ها</td></tr>
                    <tr><td>دریافت از مشتریان (نقد/حواله)</td><td class="text-start">${formatCurrency(data.cashReceipts || 0)}</td></tr>
                    <tr><td>چک‌های وصول شده</td><td class="text-start">${formatCurrency(data.checksCashed || 0)}</td></tr>
                    <tr><td>درآمدهای ثبت‌شده</td><td class="text-start">${formatCurrency(data.incomes || 0)}</td></tr>
                    <tr class="table-danger"><td class="fw-bold" colspan="2">پرداختی‌ها</td></tr>
                    <tr><td>پرداخت به تأمین‌کنندگان</td><td class="text-start">${formatCurrency(data.cashPayments || 0)}</td></tr>
                    <tr><td>هزینه‌ها</td><td class="text-start">${formatCurrency(data.expenses || 0)}</td></tr>
                    <tr><td>چک‌های پاس شده</td><td class="text-start">${formatCurrency(data.checksCleared || 0)}</td></tr>
                </tbody>
            </table>
        </div></div>`;

        // Monthly breakdown
        if (data.monthlyBreakdown && data.monthlyBreakdown.length > 0) {
            html += `<h6 class="mb-2"><i class="bi bi-calendar3 me-2"></i>ریز ماهانه</h6>`;
            html += `<table id="cashflow-monthly-table" class="table table-striped table-sm">
                <thead><tr><th>ماه</th><th>دریافتی</th><th>پرداختی</th><th>خالص</th></tr></thead><tbody>`;
            
            data.monthlyBreakdown.forEach(m => {
                const net = (parseFloat(m.inflow) || 0) - (parseFloat(m.outflow) || 0);
                html += `<tr>
                    <td>${m.month}</td>
                    <td class="text-success">${formatCurrency(m.inflow || 0)}</td>
                    <td class="text-danger">${formatCurrency(m.outflow || 0)}</td>
                    <td class="fw-bold ${net >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(net)}</td>
                </tr>`;
            });
            html += `</tbody></table>`;
        }

        // Account balances
        if (data.accounts && data.accounts.length > 0) {
            html += `<h6 class="mb-2 mt-3"><i class="bi bi-bank me-2"></i>مانده حساب‌ها</h6>`;
            html += `<div class="row">`;
            const cashBal = data.totalCashBalance || 0;
            const bankBal = data.totalBankBalance || 0;
            html += `<div class="col-md-3 mb-2"><div class="card text-center border-success"><div class="card-body py-2">
                <small class="text-muted">مجموع صندوق نقدی</small>
                <h5 class="text-success">${formatCurrency(cashBal)}</h5>
            </div></div></div>`;
            html += `<div class="col-md-3 mb-2"><div class="card text-center border-primary"><div class="card-body py-2">
                <small class="text-muted">مجموع بانکی</small>
                <h5 class="text-primary">${formatCurrency(bankBal)}</h5>
            </div></div></div>`;
            html += `<div class="col-md-3 mb-2"><div class="card text-center border-dark"><div class="card-body py-2">
                <small class="text-muted">کل موجودی</small>
                <h5 class="fw-bold">${formatCurrency(data.totalBalance || 0)}</h5>
            </div></div></div>`;
            html += `</div>`;
            html += `<div class="row mt-2">`;
            data.accounts.forEach(acc => {
                const bal = parseFloat(acc.current_balance) || 0;
                html += `<div class="col-md-3 mb-2"><div class="card text-center"><div class="card-body py-2">
                    <small class="text-muted">${acc.name} ${acc.is_cash == 1 ? '(نقد)' : '(بانک)'}</small>
                    <h5 class="${bal >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(bal)}</h5>
                </div></div></div>`;
            });
            html += `</div>`;
        }

        container.html(html);
        if (data.monthlyBreakdown && data.monthlyBreakdown.length > 0) {
            _initDataTable('#cashflow-monthly-table');
        }
    }

    // ==================== PARTNER PROFIT REPORT ====================
    function _generatePartnerProfitReport(data, container) {
        if (!data || !data.partners || data.partners.length === 0) {
            container.html('<div class="alert alert-info">اطلاعات شرکا یافت نشد. لطفاً شرکا و سهم آنها را در تنظیمات ثبت کنید.</div>');
            return;
        }

        const { persianStartDate, persianEndDate } = lastReportParams;
        const grossProfit = data.grossProfit || 0;
        const netProfit = data.netProfit || 0;
        const totalExpenses = data.totalExpenses || 0;

        // Helper to render a transaction detail table
        function renderDetailTable(rows, colDefs) {
            if (!rows || rows.length === 0) return '';
            let t = `<table class="table table-sm table-borderless mb-0 mt-1" style="font-size:0.78rem"><thead class="table-light"><tr>`;
            colDefs.forEach(c => { t += `<th>${c.label}</th>`; });
            t += `</tr></thead><tbody>`;
            rows.forEach(r => {
                t += '<tr>';
                colDefs.forEach(c => {
                    let val = c.render ? c.render(r) : (r[c.key] ?? '-');
                    t += `<td>${val}</td>`;
                });
                t += '</tr>';
            });
            t += '</tbody></table>';
            return t;
        }

        // Helper to render a collapsible detail section
        function detailSection(id, label, amount, cssClass, rows, colDefs) {
            if (!amount || amount === 0) return '';
            const hasRows = rows && rows.length > 0;
            return `<div class="py-1 detail-row">
                <div class="d-flex justify-content-between align-items-center">
                    ${hasRows ? `<a class="text-decoration-none d-flex align-items-center" style="cursor:pointer" data-bs-toggle="collapse" href="#${id}" role="button" aria-expanded="false"><i class="bi bi-chevron-left detail-chevron me-1 small"></i>${label}</a>` : `<span><span class="me-2"></span>${label}</span>`}
                    <span class="${cssClass} fw-bold">${formatCurrency(amount)}</span>
                </div>
                ${hasRows ? `<div class="collapse" id="${id}"><div class="ps-3 border-start border-2 border-opacity-25 ${cssClass.includes('success') ? 'border-success' : 'border-danger'} ms-2 mt-1">${renderDetailTable(rows, colDefs)}</div></div>` : ''}
            </div>`;
        }

        let html = `
        <style>
            .partner-report .stat-card { border-radius: 12px; border: none; box-shadow: 0 3px 10px rgba(0,0,0,0.08); }
            .partner-report .partner-section { border: 1px solid #e9ecef; border-radius: 12px; overflow: hidden; }
            .partner-report .partner-header { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 16px; border-bottom: 1px solid #dee2e6; }
            .partner-report .detail-row { transition: background 0.15s; padding: 2px 4px; border-radius: 4px; }
            .partner-report .detail-row:hover { background: #f8f9fa; }
            .partner-report .detail-chevron { transition: transform 0.2s; display: inline-block; }
            .partner-report [aria-expanded="true"] .detail-chevron { transform: rotate(90deg); }
        </style>
        <div class="partner-report">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <div>
                    <h4 class="mb-1"><i class="bi bi-people text-primary me-2"></i>گزارش سود شرکا</h4>
                    <p class="text-muted mb-0">از ${persianStartDate} تا ${persianEndDate}</p>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card stat-card text-center h-100">
                        <div class="card-body py-3">
                            <i class="bi bi-graph-up fs-3 ${grossProfit >= 0 ? 'text-success' : 'text-danger'}"></i>
                            <div class="text-muted small mt-1">سود ناخالص</div>
                            <div class="fs-4 fw-bold ${grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(grossProfit)}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card text-center h-100">
                        <div class="card-body py-3">
                            <i class="bi bi-credit-card fs-3 text-danger"></i>
                            <div class="text-muted small mt-1">کل هزینه‌ها</div>
                            <div class="fs-4 fw-bold text-danger">${formatCurrency(totalExpenses)}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card text-center h-100 ${netProfit >= 0 ? 'border-success' : 'border-danger'}" style="border-width:2px;">
                        <div class="card-body py-3">
                            <i class="bi ${netProfit >= 0 ? 'bi-trophy text-success' : 'bi-exclamation-triangle text-danger'} fs-3"></i>
                            <div class="text-muted small mt-1">سود خالص عملیاتی</div>
                            <div class="fs-4 fw-bold ${netProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(netProfit)}</div>
                        </div>
                    </div>
                </div>
            </div>`;

        // Common column definitions for detail tables
        const dateCols = { key: 'date', label: 'تاریخ', render: r => r.date ? UI.gregorianToPersian(r.date) : '-' };
        const amountCol = { key: 'amount', label: 'مبلغ', render: r => formatCurrency(r.amount || 0) };
        const descCol = { key: 'description', label: 'توضیح', render: r => r.description || '-' };

        // Detailed partner sections
        data.partners.forEach((p, index) => {
            const share = p.sharePercent || 0;
            const profitShare = p.netProfitShare || 0;
            const grossShare = p.grossProfitShare || 0;
            const uid = `p${index}`;
            const cap = p.capital || null;

            let depositDirectRows = [], expenseRows = [], purchasePayRows = [], transferOutRows = [], nonInvoiceOutRows = [];
            let withdrawDirectRows = [], salesPayRows = [], transferInRows = [], nonInvoiceInRows = [];
            let directDeposits = 0, expensesFromPersonal = 0, paymentsFromPersonal = 0, transfersOut = 0, nonInvoicePaymentsOut = 0;
            let directWithdrawals = 0, paymentsToPersonal = 0, transfersIn = 0, nonInvoicePaymentsIn = 0;
            let opening = 0;

            if (cap) {
                opening = Number(cap.opening || 0);
                directDeposits = Number(cap.deposits?.direct || 0);
                expensesFromPersonal = Number(cap.deposits?.expenses || 0);
                paymentsFromPersonal = Number(cap.deposits?.purchasePayments || 0);
                transfersOut = Number(cap.deposits?.transfersToCompany || 0);
                nonInvoicePaymentsOut = Number(cap.deposits?.personPayments || 0);
                directWithdrawals = Number(cap.withdrawals?.direct || 0);
                paymentsToPersonal = Number(cap.withdrawals?.salesReceipts || 0);
                transfersIn = Number(cap.withdrawals?.transfersFromCompany || 0);
                nonInvoicePaymentsIn = Number(cap.withdrawals?.personReceipts || 0);
                depositDirectRows = cap.deposits?.rows?.direct || [];
                expenseRows = cap.deposits?.rows?.expenses || [];
                purchasePayRows = cap.deposits?.rows?.purchasePayments || [];
                transferOutRows = cap.deposits?.rows?.transfersToCompany || [];
                nonInvoiceOutRows = cap.deposits?.rows?.personPayments || [];
                withdrawDirectRows = cap.withdrawals?.rows?.direct || [];
                salesPayRows = cap.withdrawals?.rows?.salesReceipts || [];
                transferInRows = cap.withdrawals?.rows?.transfersFromCompany || [];
                nonInvoiceInRows = cap.withdrawals?.rows?.personReceipts || [];
            } else {
            // Find partner account
            const partnerAccount = (data.accounts || []).find(acc => acc.partner_id == p.id);

            // --- Deposits (بستانکاری) ---
            depositDirectRows = (data.partner_transactions || [])
                .filter(t => t.partnerId == p.id && t.type === 'receipt');
            directDeposits = depositDirectRows.reduce((sum, t) => sum + Number(t.amount), 0);

            if (partnerAccount) {
                expenseRows = (data.expenses || []).filter(exp => exp.account_id == partnerAccount.id);
                expensesFromPersonal = expenseRows.reduce((sum, exp) => sum + Number(exp.amount), 0);

                const personalPayments = (data.payments || []).filter(pay => pay.account_id == partnerAccount.id);
                purchasePayRows = personalPayments.filter(pay => pay.invoiceType === 'purchase');
                paymentsFromPersonal = purchasePayRows.reduce((sum, pay) => sum + Number(pay.amount), 0);
                salesPayRows = personalPayments.filter(pay => pay.invoiceType === 'sales');
                paymentsToPersonal = salesPayRows.reduce((sum, pay) => sum + Number(pay.amount), 0);

                const nonInvoice = (data.non_invoice_person_payments || []).filter(pay => pay.account_id == partnerAccount.id);
                nonInvoiceOutRows = nonInvoice.filter(pay => pay.transaction_type === 'payment');
                nonInvoicePaymentsOut = nonInvoiceOutRows.reduce((sum, pay) => sum + Number(pay.amount), 0);
                nonInvoiceInRows = nonInvoice.filter(pay => pay.transaction_type === 'receipt');
                nonInvoicePaymentsIn = nonInvoiceInRows.reduce((sum, pay) => sum + Number(pay.amount), 0);

                const transfers = (data.transfers || []).filter(t => t.account_id == partnerAccount.id);
                // Company transfers + inter-partner (company as intermediary).
                transferOutRows = transfers.filter(t => t.transaction_type === 'payment' && !Number(t.is_inter_partner));
                transfersOut = transferOutRows.reduce((sum, t) => sum + Number(t.amount), 0);
                transferInRows = transfers.filter(t => t.transaction_type === 'receipt' && !Number(t.is_inter_partner));
                transfersIn = transferInRows.reduce((sum, t) => sum + Number(t.amount), 0);
                // Inter-partner handled via cap payload when present; legacy path adds below if needed.
            }

            withdrawDirectRows = (data.partner_transactions || [])
                .filter(t => t.partnerId == p.id && t.type === 'payment');
            directWithdrawals = withdrawDirectRows.reduce((sum, t) => sum + Number(t.amount), 0);
            }

            const totalDeposits = directDeposits + expensesFromPersonal + paymentsFromPersonal + transfersOut + nonInvoicePaymentsOut;
            const totalWithdrawals = directWithdrawals + paymentsToPersonal + transfersIn + nonInvoicePaymentsIn;
            const finalBalance = cap && cap.closing != null
                ? Number(cap.closing)
                : (opening + profitShare + totalDeposits - totalWithdrawals);

            html += `
            <div class="partner-section mb-4">
                <div class="partner-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                                <i class="bi bi-person-circle text-primary fs-4"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">${UI.escapeHtml(p.name)}</h5>
                                <span class="badge bg-secondary">${share}% سهم از سود</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">مانده پایان دوره (طلب)</div>
                            <div class="fs-4 fw-bold ${finalBalance >= 0 ? 'text-primary' : 'text-danger'}">${formatCurrency(finalBalance)}</div>
                            ${opening ? `<div class="small text-muted">اول دوره: ${formatCurrency(opening)}</div>` : ''}
                            ${finalBalance >= 0 ?
                                '<span class="badge bg-primary">طلبکار از شرکت</span>' :
                                '<span class="badge bg-danger">بدهکار به شرکت</span>'
                            }
                        </div>
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <!-- Profit Share -->
                        <div class="col-md-4">
                            <div class="card h-100 border-0 bg-light">
                                <div class="card-body text-center py-3">
                                    <i class="bi bi-graph-up-arrow fs-3 ${profitShare >= 0 ? 'text-success' : 'text-danger'} mb-2"></i>
                                    <div class="small text-muted">سود خالص تخصیص‌یافته</div>
                                    <div class="fs-5 fw-bold ${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</div>
                                    <div class="small text-muted mt-1">سود ناخالص: ${formatCurrency(grossShare)}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Deposits (Creditor) -->
                        <div class="col-md-4">
                            <div class="card h-100 border-success border-opacity-50">
                                <div class="card-header bg-success bg-opacity-10 py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-success fw-bold"><i class="bi bi-arrow-down-circle me-1"></i>بستانکاری (واریزی)</span>
                                        <span class="badge bg-success">${formatCurrency(totalDeposits)}</span>
                                    </div>
                                </div>
                                <div class="card-body py-2 small">
                                    ${detailSection(`${uid}-dep-direct`, '<i class="bi bi-person-check text-success me-1"></i>واریز مستقیم', directDeposits, 'text-success', depositDirectRows, [dateCols, amountCol, descCol])}
                                    ${detailSection(`${uid}-dep-expense`, '<i class="bi bi-wallet2 text-success me-1"></i>پرداخت هزینه از حساب', expensesFromPersonal, 'text-success', expenseRows, [dateCols, amountCol, {key:'category',label:'دسته',render:r=>r.category||'-'}, descCol])}
                                    ${detailSection(`${uid}-dep-purchase`, '<i class="bi bi-cart-check text-success me-1"></i>پرداخت فاکتور خرید', paymentsFromPersonal, 'text-success', purchasePayRows, [dateCols, {key:'invoiceId',label:'فاکتور #'}, {key:'personName',label:'تأمین‌کننده'}, amountCol])}
                                    ${detailSection(`${uid}-dep-nonInvOut`, '<i class="bi bi-people text-success me-1"></i>پرداخت به اشخاص', nonInvoicePaymentsOut, 'text-success', nonInvoiceOutRows, [dateCols, {key:'personName',label:'شخص'}, amountCol, descCol])}
                                    ${detailSection(`${uid}-dep-transferOut`, '<i class="bi bi-arrow-left-right text-success me-1"></i>انتقال از حساب شخصی به شرکت', transfersOut, 'text-success', transferOutRows, [dateCols, amountCol, descCol])}
                                    ${totalDeposits === 0 ? '<div class="text-center text-muted py-2">بدون تراکنش</div>' : ''}
                                </div>
                            </div>
                        </div>

                        <!-- Withdrawals (Debtor) -->
                        <div class="col-md-4">
                            <div class="card h-100 border-danger border-opacity-50">
                                <div class="card-header bg-danger bg-opacity-10 py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-danger fw-bold"><i class="bi bi-arrow-up-circle me-1"></i>بدهکاری (برداشتی)</span>
                                        <span class="badge bg-danger">${formatCurrency(totalWithdrawals)}</span>
                                    </div>
                                </div>
                                <div class="card-body py-2 small">
                                    ${detailSection(`${uid}-wd-direct`, '<i class="bi bi-person-dash text-danger me-1"></i>برداشت مستقیم', directWithdrawals, 'text-danger', withdrawDirectRows, [dateCols, amountCol, descCol])}
                                    ${detailSection(`${uid}-wd-sales`, '<i class="bi bi-receipt text-danger me-1"></i>دریافت فاکتور فروش', paymentsToPersonal, 'text-danger', salesPayRows, [dateCols, {key:'invoiceId',label:'فاکتور #'}, {key:'personName',label:'مشتری'}, amountCol])}
                                    ${detailSection(`${uid}-wd-nonInvIn`, '<i class="bi bi-people text-danger me-1"></i>دریافت از اشخاص', nonInvoicePaymentsIn, 'text-danger', nonInvoiceInRows, [dateCols, {key:'personName',label:'شخص'}, amountCol, descCol])}
                                    ${detailSection(`${uid}-wd-transferIn`, '<i class="bi bi-arrow-left-right text-danger me-1"></i>انتقال از شرکت به حساب شخصی', transfersIn, 'text-danger', transferInRows, [dateCols, amountCol, descCol])}
                                    ${totalWithdrawals === 0 ? '<div class="text-center text-muted py-2">بدون تراکنش</div>' : ''}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Partner Summary Formula -->
                    <div class="mt-3 p-3 rounded ${finalBalance >= 0 ? 'bg-primary bg-opacity-10' : 'bg-danger bg-opacity-10'}">
                        <div class="row text-center">
                            <div class="col">
                                <div class="small text-muted">سود خالص</div>
                                <div class="fw-bold ${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</div>
                            </div>
                            <div class="col-auto d-flex align-items-center"><i class="bi bi-plus fs-5 text-muted"></i></div>
                            <div class="col">
                                <div class="small text-muted">بستانکاری</div>
                                <div class="fw-bold text-success">${formatCurrency(totalDeposits)}</div>
                            </div>
                            <div class="col-auto d-flex align-items-center"><i class="bi bi-dash fs-5 text-muted"></i></div>
                            <div class="col">
                                <div class="small text-muted">بدهکاری</div>
                                <div class="fw-bold text-danger">${formatCurrency(totalWithdrawals)}</div>
                            </div>
                            <div class="col-auto d-flex align-items-center"><i class="bi bi-arrow-right fs-5 text-muted"></i></div>
                            <div class="col">
                                <div class="small text-muted">مانده نهایی</div>
                                <div class="fs-5 fw-bold ${finalBalance >= 0 ? 'text-primary' : 'text-danger'}">${formatCurrency(finalBalance)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        });

        html += `</div>`;
        container.html(html);
    }

    const CUSTOM_REPORT_FIELDS = {
        sales_invoices: {
            columns: [
                { value: 'date', label: 'تاریخ', selected: true },
                { value: 'invoice_number', label: 'شماره فاکتور', selected: true },
                { value: 'person', label: 'مشتری', selected: true },
                { value: 'amount', label: 'مبلغ', selected: true },
                { value: 'discount', label: 'تخفیف' },
                { value: 'tax', label: 'مالیات' },
                { value: 'paid', label: 'پرداخت‌شده', selected: true },
                { value: 'remaining', label: 'مانده', selected: true },
                { value: 'delivery_status', label: 'وضعیت تحویل' },
                { value: 'due_date', label: 'سررسید' },
                { value: 'description', label: 'توضیحات' },
                { value: 'id', label: 'شناسه' }
            ],
            groups: [
                { value: '', label: 'بدون گروه‌بندی' },
                { value: 'person', label: 'مشتری' },
                { value: 'delivery_status', label: 'وضعیت تحویل' },
                { value: 'month', label: 'ماه' }
            ]
        },
        purchase_invoices: {
            columns: [
                { value: 'date', label: 'تاریخ', selected: true },
                { value: 'invoice_number', label: 'شماره فاکتور', selected: true },
                { value: 'person', label: 'تأمین‌کننده', selected: true },
                { value: 'amount', label: 'مبلغ', selected: true },
                { value: 'discount', label: 'تخفیف' },
                { value: 'tax', label: 'مالیات' },
                { value: 'paid', label: 'پرداخت‌شده', selected: true },
                { value: 'remaining', label: 'مانده', selected: true },
                { value: 'delivery_status', label: 'وضعیت تحویل' },
                { value: 'due_date', label: 'سررسید' },
                { value: 'description', label: 'توضیحات' },
                { value: 'id', label: 'شناسه' }
            ],
            groups: [
                { value: '', label: 'بدون گروه‌بندی' },
                { value: 'person', label: 'تأمین‌کننده' },
                { value: 'delivery_status', label: 'وضعیت تحویل' },
                { value: 'month', label: 'ماه' }
            ]
        },
        expenses: {
            columns: [
                { value: 'date', label: 'تاریخ', selected: true },
                { value: 'category', label: 'دسته‌بندی', selected: true },
                { value: 'amount', label: 'مبلغ', selected: true },
                { value: 'account', label: 'حساب', selected: true },
                { value: 'person', label: 'طرف‌حساب' },
                { value: 'description', label: 'توضیحات' },
                { value: 'id', label: 'شناسه' }
            ],
            groups: [
                { value: '', label: 'بدون گروه‌بندی' },
                { value: 'category', label: 'دسته‌بندی' },
                { value: 'account', label: 'حساب' },
                { value: 'person', label: 'طرف‌حساب' },
                { value: 'month', label: 'ماه' }
            ]
        },
        incomes: {
            columns: [
                { value: 'date', label: 'تاریخ', selected: true },
                { value: 'category', label: 'دسته‌بندی', selected: true },
                { value: 'amount', label: 'مبلغ', selected: true },
                { value: 'account', label: 'حساب', selected: true },
                { value: 'person', label: 'طرف‌حساب' },
                { value: 'description', label: 'توضیحات' },
                { value: 'id', label: 'شناسه' }
            ],
            groups: [
                { value: '', label: 'بدون گروه‌بندی' },
                { value: 'category', label: 'دسته‌بندی' },
                { value: 'account', label: 'حساب' },
                { value: 'person', label: 'طرف‌حساب' },
                { value: 'month', label: 'ماه' }
            ]
        },
        payments: {
            columns: [
                { value: 'date', label: 'تاریخ', selected: true },
                { value: 'transaction_type', label: 'جریان (دریافت/پرداخت)', selected: true },
                { value: 'amount', label: 'مبلغ', selected: true },
                { value: 'type', label: 'روش پرداخت', selected: true },
                { value: 'person', label: 'شخص', selected: true },
                { value: 'account', label: 'حساب' },
                { value: 'invoice_ref', label: 'فاکتور مرتبط' },
                { value: 'description', label: 'توضیحات' },
                { value: 'id', label: 'شناسه' }
            ],
            groups: [
                { value: '', label: 'بدون گروه‌بندی' },
                { value: 'transaction_type', label: 'جریان' },
                { value: 'type', label: 'روش پرداخت' },
                { value: 'person', label: 'شخص' },
                { value: 'account', label: 'حساب' },
                { value: 'month', label: 'ماه' }
            ]
        }
    };

    function getCustomReportSelectedColumns() {
        return $('#custom-report-columns input[type="checkbox"]:checked')
            .map(function () { return $(this).val(); })
            .get()
            .filter(Boolean);
    }

    function syncCustomReportFields(resetSelection) {
        const source = $('#custom-report-source').val() || 'sales_invoices';
        const spec = CUSTOM_REPORT_FIELDS[source] || CUSTOM_REPORT_FIELDS.sales_invoices;
        const $columns = $('#custom-report-columns');
        const $group = $('#custom-report-group-by');
        if (!$columns.length) return;

        const previousColumns = resetSelection ? [] : getCustomReportSelectedColumns();
        const previousGroup = resetSelection ? '' : ($group.val() || '');

        $columns.empty();
        spec.columns.forEach((col, idx) => {
            const selected = resetSelection ? !!col.selected : previousColumns.includes(col.value);
            const id = `custom-col-${source}-${col.value}-${idx}`;
            $columns.append(`
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="${col.value}" id="${id}"${selected ? ' checked' : ''}>
                    <label class="form-check-label" for="${id}">${UI.escapeHtml(col.label)}</label>
                </div>
            `);
        });

        if ($group.length) {
            $group.empty();
            spec.groups.forEach(group => {
                const selected = group.value === previousGroup ? ' selected' : '';
                $group.append(`<option value="${group.value}"${selected}>${group.label}</option>`);
            });
            if (!$group.find(`option[value="${previousGroup}"]`).length) {
                $group.val('');
            }
        }
    }

    // ==================== CUSTOM REPORT ====================
    function _generateCustomReport(data, container) {
        if (!data || data.error) {
            container.html(`<div class="alert alert-danger">${UI.escapeHtml(data?.error || 'خطا در دریافت گزارش سفارشی.')}</div>`);
            return;
        }
        if (!data.rows || data.rows.length === 0) {
            container.html('<div class="alert alert-info">داده‌ای برای نمایش وجود ندارد. بازه تاریخ یا فیلتر ستون‌ها را بررسی کنید.</div>');
            return;
        }

        const { persianStartDate, persianEndDate } = lastReportParams;
        const sourceLabels = {
            sales_invoices: 'فاکتورهای فروش',
            purchase_invoices: 'فاکتورهای خرید',
            expenses: 'هزینه‌ها',
            incomes: 'درآمدها',
            payments: 'پرداخت‌ها / دریافت‌ها'
        };
        const sourceLabel = sourceLabels[data.source] || data.source;
        const groupLabels = {
            person: 'شخص', category: 'دسته‌بندی', account: 'حساب', month: 'ماه',
            transaction_type: 'جریان', type: 'روش پرداخت', delivery_status: 'وضعیت تحویل'
        };
        const groupLabel = data.summary?.group_by ? (groupLabels[data.summary.group_by] || data.summary.group_by) : '';
        const isInvoiceSource = data.source === 'sales_invoices' || data.source === 'purchase_invoices';

        let html = `<h5 class="mb-3"><i class="bi bi-sliders me-2"></i>گزارش سفارشی: ${UI.escapeHtml(sourceLabel)} از ${UI.escapeHtml(persianStartDate || '')} تا ${UI.escapeHtml(persianEndDate || '')}</h5>`;
        if (groupLabel) {
            html += `<div class="text-muted small mb-2">گروه‌بندی بر اساس ${UI.escapeHtml(groupLabel)}</div>`;
        }
        if (data.truncated) {
            html += `<div class="alert alert-warning py-2">فقط ۱۰۰۰ رکورد اول این بازه خوانده شد. بازه را کوچک‌تر کنید یا خروجی CSV بگیرید.</div>`;
        }

        if (data.summary) {
            const countLabel = data.summary.grouped ? 'تعداد گروه' : 'تعداد ردیف';
            const detailNote = data.summary.grouped ? `<div class="small text-muted">${data.summary.detail_count || 0} رکورد جزئی</div>` : '';
            html += `<div class="row mb-3 g-2">
                <div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body py-2">
                    <small class="text-muted">${countLabel}</small><h5 class="mb-0">${data.summary.total_count || 0}</h5>${detailNote}
                </div></div></div>
                <div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body py-2">
                    <small class="text-muted">مجموع مبلغ</small><h5 class="text-primary mb-0">${formatCurrency(data.summary.total_amount || 0)}</h5>
                </div></div></div>`;
            if (isInvoiceSource) {
                html += `<div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body py-2">
                    <small class="text-muted">مجموع تخفیف</small><h5 class="text-info mb-0">${formatCurrency(data.summary.total_discount || 0)}</h5>
                </div></div></div>
                <div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body py-2">
                    <small class="text-muted">مجموع مانده</small><h5 class="text-danger mb-0">${formatCurrency(data.summary.total_remaining || 0)}</h5>
                </div></div></div>`;
            } else if ((data.summary.total_tax || 0) > 0) {
                html += `<div class="col-6 col-md-3"><div class="card text-center h-100"><div class="card-body py-2">
                    <small class="text-muted">مجموع مالیات</small><h5 class="text-info mb-0">${formatCurrency(data.summary.total_tax || 0)}</h5>
                </div></div></div>`;
            }
            html += `</div>`;
        }

        const columns = data.columns || Object.keys(data.rows[0] || {});
        const colLabels = {
            date: 'تاریخ', person: 'شخص', amount: 'مبلغ', discount: 'تخفیف', tax: 'مالیات',
            description: 'توضیحات', id: 'شناسه', paid: 'پرداخت‌شده', remaining: 'مانده',
            category: 'دسته‌بندی', account: 'حساب', transaction_type: 'جریان', type: 'روش پرداخت',
            month: 'ماه', row_count: 'تعداد', delivery_status: 'وضعیت تحویل', due_date: 'سررسید',
            invoice_number: 'شماره فاکتور', invoice_ref: 'فاکتور مرتبط', person_type: 'نوع شخص'
        };
        const moneyCols = ['amount', 'discount', 'tax', 'paid', 'remaining'];
        const dateCols = ['date', 'due_date'];
        const deliveryMap = { preparing: 'در حال آماده‌سازی', ready: 'آماده تحویل', delivered: 'تحویل شده' };
        const payTypeMap = { cash: 'نقد', card: 'کارتی', transfer: 'انتقال', check: 'چک', endorse_check: 'خرج چک', pos: 'پوز', payable_check: 'چک پرداختی', setoff: 'تهاتر / پرداخت به جای' };

        html += `<div class="table-responsive"><table id="custom-report-table" class="table table-striped table-sm table-bordered"><thead><tr>`;
        columns.forEach(col => { html += `<th>${colLabels[col] || col}</th>`; });
        html += `</tr></thead><tbody>`;

        data.rows.forEach(row => {
            html += `<tr>`;
            columns.forEach(col => {
                let raw = row[col] ?? '';
                let val = raw;
                let useHtml = false;
                if (dateCols.includes(col) && val) val = UI.gregorianToPersian(val);
                if (moneyCols.includes(col)) val = formatCurrency(val || 0);
                if (col === 'transaction_type') val = val === 'receipt' ? 'دریافت' : val === 'payment' ? 'پرداخت' : val;
                if (col === 'type') val = payTypeMap[val] || val;
                if (col === 'delivery_status') val = deliveryMap[val] || val || '-';
                if (col === 'row_count') val = UI.formatNumber ? UI.formatNumber(val) : val;
                const orderAttr = moneyCols.includes(col) || col === 'row_count' ? ` data-order="${UI.escapeHtml(String(raw || 0))}"` : '';
                const cell = useHtml ? String(val) : UI.escapeHtml(String(val ?? ''));
                html += `<td${orderAttr}>${cell}</td>`;
            });
            html += `</tr>`;
        });

        html += `</tbody></table></div>`;
        container.html(html);
        _initDataTable('#custom-report-table', { pageLength: 25 });
    }

    function _generateChecksReport(data, container) {
        if (!data || data.error) {
            container.html(`<div class="alert alert-danger">${UI.escapeHtml(data?.error || 'خطا در دریافت گزارش چک‌ها.')}</div>`);
            return;
        }

        const checks = data.checks || [];
        const summary = data.summary || {};
        const { persianStartDate, persianEndDate } = lastReportParams;
        const entityName = App.getAppState().businessEntities.find(e => e.id == App.getAppState().currentEntityId)?.name || 'مجموعه';

        let html = `<h5 class="mb-3"><i class="bi bi-bank me-2"></i>گزارش چک‌های «${UI.escapeHtml(entityName)}» از ${persianStartDate} تا ${persianEndDate}</h5>`;
        html += `<div class="text-muted mb-3 small">بازه بر اساس تاریخ سررسید — تعداد: ${summary.count || 0}</div>`;

        html += `<div class="row mb-4 g-2">
            <div class="col-md-3"><div class="card text-center border-primary"><div class="card-body py-2">
                <small class="text-muted">کل مبلغ</small>
                <h5 class="fw-bold mb-0">${formatCurrency(summary.total_amount || 0)}</h5>
            </div></div></div>
            <div class="col-md-3"><div class="card text-center border-success"><div class="card-body py-2">
                <small class="text-muted">دریافتی</small>
                <h5 class="text-success mb-0">${formatCurrency(summary.by_type?.received?.amount || 0)}</h5>
                <small class="text-muted">${summary.by_type?.received?.count || 0} فقره</small>
            </div></div></div>
            <div class="col-md-3"><div class="card text-center border-danger"><div class="card-body py-2">
                <small class="text-muted">پرداختی</small>
                <h5 class="text-danger mb-0">${formatCurrency(summary.by_type?.payable?.amount || 0)}</h5>
                <small class="text-muted">${summary.by_type?.payable?.count || 0} فقره</small>
            </div></div></div>
            <div class="col-md-3"><div class="card text-center border-warning"><div class="card-body py-2">
                <small class="text-muted">سررسید گذشته</small>
                <h5 class="text-warning mb-0">${formatCurrency(summary.overdue_amount || 0)}</h5>
                <small class="text-muted">${summary.overdue_count || 0} فقره</small>
            </div></div></div>
        </div>`;

        const statusEntries = Object.values(summary.by_status || {}).filter(s => (s.count || 0) > 0);
        if (statusEntries.length) {
            html += `<div class="row mb-3 g-2">`;
            statusEntries.forEach(s => {
                html += `<div class="col-md-2 col-6"><div class="card text-center"><div class="card-body py-2">
                    <small class="text-muted">${UI.escapeHtml(s.label || '')}</small>
                    <div class="fw-bold">${formatCurrency(s.amount || 0)}</div>
                    <small class="text-muted">${s.count || 0} فقره</small>
                </div></div></div>`;
            });
            html += `</div>`;
        }

        if (!checks.length) {
            html += `<div class="alert alert-info">چکی در این بازه/فیلتر یافت نشد.</div>`;
            container.html(html);
            return;
        }

        html += `<div class="table-responsive"><table id="checks-report-table" class="table table-striped table-sm table-bordered">
            <thead><tr>
                <th>شماره</th><th>نوع</th><th>وضعیت</th><th>مبلغ</th><th>بانک</th><th>صادرکننده</th>
                <th>سررسید</th><th>شخص</th><th>تلفن</th><th>فاکتور</th><th>نزد / وضعیت</th><th>روز تا سررسید</th>
            </tr></thead><tbody>`;

        checks.forEach(c => {
            const days = c.days_to_due;
            let daysHtml = '-';
            if (days != null) {
                if (c.is_overdue) {
                    daysHtml = `<span class="badge bg-danger">${Math.abs(days)} روز گذشته</span>`;
                } else if (days === 0) {
                    daysHtml = `<span class="badge bg-warning text-dark">امروز</span>`;
                } else {
                    daysHtml = `<span class="badge bg-light text-dark border">${days} روز</span>`;
                }
            }
            const rowClass = c.is_overdue ? 'table-warning' : '';
            html += `<tr class="${rowClass}">
                <td>${UI.escapeHtml(c.checkNumber || '-')}</td>
                <td>${UI.escapeHtml(c.type_label || '-')}</td>
                <td>${UI.escapeHtml(c.status_label || '-')}</td>
                <td data-order="${c.amount || 0}">${formatCurrency(c.amount || 0)}</td>
                <td>${UI.escapeHtml(c.bankName || '-')}</td>
                <td>${UI.escapeHtml(c.issuerName || '-')}</td>
                <td data-order="${c.dueDate || ''}">${c.dueDate ? UI.gregorianToPersian(c.dueDate) : '-'}</td>
                <td>${UI.escapeHtml(c.person_name || '-')}${c.person_type_label ? ` <small class="text-muted">(${UI.escapeHtml(c.person_type_label)})</small>` : ''}</td>
                <td>${UI.escapeHtml(c.person_phone || '-')}</td>
                <td>${UI.escapeHtml(c.invoice_label || '-')}</td>
                <td>${UI.escapeHtml(c.current_holder || '-')}${c.current_holder_phone ? `<br><small class="text-muted">${UI.escapeHtml(c.current_holder_phone)}</small>` : ''}</td>
                <td>${daysHtml}</td>
            </tr>`;
        });

        html += `</tbody></table></div>`;
        container.html(html);
        _initDataTable('#checks-report-table', {
            order: [[6, 'asc']],
            pageLength: 25
        });
    }

    function init() {
        $('#print-report-btn').on('click', print);
        $('#export-report-btn').on('click', exportCsv);
        syncCustomReportFields(true);
        $('#custom-report-source').on('change', function () {
            syncCustomReportFields(true);
        });
        $('#custom-report-cols-all').on('click', function () {
            $('#custom-report-columns input[type="checkbox"]').prop('checked', true);
        });
        $('#custom-report-cols-none').on('click', function () {
            $('#custom-report-columns input[type="checkbox"]').prop('checked', false);
        });

        const syncSupplierUndeliveredOption = function () {
            const $opt = $('#supplier-undelivered-orders-option');
            if (!$opt.length) return;
            const reportType = $('#report-type').val();
            const personVal = String($('#report-person-select').val() || '');
            const [type, id] = personVal.split('-');
            const cache = App.getCache() || {};
            // Person who is both customer and supplier: offer the combined statement.
            let linked = false;
            if (reportType === 'persons' && type === 'customer') {
                linked = !!(cache.customers || []).find(c => String(c.id) === id && Number(c.linked_supplier_id) > 0);
            } else if (reportType === 'persons' && type === 'supplier') {
                linked = !!(cache.suppliers || []).find(s => String(s.id) === id && Number(s.linked_customer_id) > 0);
            }
            $('#linked-person-combine-option').toggle(linked);
            const combined = linked && $('#combine-linked-person').is(':checked');
            $opt.toggle(reportType === 'persons' && (type === 'supplier' || combined));
        };
        $(document).on('change', '#report-type, #report-person-select, #combine-linked-person', syncSupplierUndeliveredOption);
        syncSupplierUndeliveredOption();
    }

    return {
        init: init,
        generate: generate,
        print: print,
        exportCsv: exportCsv
    };
})();
