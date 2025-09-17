// /js/reports.js

const ReportGenerator = (function () {
    let appDataCache = {};
    let lastReportData = null;
    let lastReportType = null;
    let lastReportParams = {};

    const formatCurrency = (num) => UI.formatCurrency(num);

    function _initDataTable(selector, options = {}) {
        if ($.fn.DataTable.isDataTable(selector)) {
            $(selector).DataTable().destroy();
        }
        const config = {
            language: { url: 'assets/js/i18n/fa.json' },
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "همه"]],
            pageLength: 10,
            stripeClasses: [],
            ...options
        };
        return $(selector).DataTable(config);
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
            case 'inventory': apiAction = 'get_inventory_report'; break;
            case 'inventory-value': apiAction = 'get_inventory_value_report'; break;
            case 'inventory-ledger': apiAction = 'get_inventory_ledger_report'; break;
            case 'cogs-profit': apiAction = 'get_cogs_profit_report'; break;
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
        const useCogs = $('#cogs-calculation-checkbox').is(':checked');
        const entityId = $('#report-entity-select').val();

        if (!entityId && !['partners'].includes(reportType) /* Add other global reports here */) {
            UI.showError('لطفا یک مجموعه تجاری را برای گزارش‌گیری انتخاب کنید.');
            return;
        }
        if (['profit-loss', 'sales-invoices', 'purchase-invoices', 'expenses', 'persons', 'accounts', 'inventory-ledger', 'cogs-profit'].includes(reportType) && (!startDate || !endDate)) {
            UI.showError('لطفا بازه زمانی را به درستی مشخص کنید.');
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

        const [personType, personId] = (person || '-').split('-');

        lastReportType = reportType;
        lastReportParams = {
            startDate,
            endDate,
            personType,
            personId,
            accountId: account,
            productId: product,
            useCogs: useCogs,
            entityId: entityId, // Add entityId to params
            persianStartDate: $('#report-start-date').val(),
            persianEndDate: $('#report-end-date').val()
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
            case 'persons': _generatePersonStatementReport(apiData, container); break;
            case 'accounts': _generateAccountStatementReport(apiData, container); break;
            case 'cogs-profit': _generateCogsProfitReport(apiData, container); break;
            default: container.html('<p class="text-danger">نوع گزارش انتخاب شده معتبر نیست.</p>');
        }

        if (container.html().trim() !== "") {
            $('#print-report-btn').show();
            if (['inventory', 'expenses'].includes(reportType)) {
                $('#export-report-btn').show();
            }
        }
    }

    function _getPrintableInvoiceListHtml(type, data) {
        const { invoices } = data;
        const { persianStartDate, persianEndDate } = lastReportParams;
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';
        const entityName = $('#report-entity-select option:selected').text();

        let reportHtml = `<h4 class="mb-3">گزارش ${title} برای «${entityName}» از ${persianStartDate} تا ${persianEndDate}</h4>`;
        if (!invoices || invoices.length === 0) {
            reportHtml += `<p class="text-center">فاکتوری در این بازه زمانی یافت نشد.</p>`;
            return reportHtml;
        }

        invoices.forEach(inv => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            const invoiceDate = UI.gregorianToPersian(inv.date);
            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { itemsHtml += `<tr><td>${item.productName || 'حذف شده'} (${item.dimensions})</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
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
        const entityName = $('#report-entity-select option:selected').text();

        let finalHtml = `<h4 class="mb-3">گزارش ${title} برای «${entityName}» از ${persianStartDate} تا ${persianEndDate}</h4>`;

        if (summary) {
            finalHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center">
                <div class="col"><div class="fs-6 text-muted">تعداد فاکتورها</div><div class="fs-5 fw-bold">${summary.count}</div></div>
                <div class="col"><div class="fs-6 text-muted">جمع کل</div><div class="fs-5 fw-bold">${formatCurrency(summary.totalAmount)}</div></div>
                <div class="col"><div class="fs-6 text-muted">جمع تخفیف</div><div class="fs-5 fw-bold text-info">${formatCurrency(summary.totalDiscount)}</div></div>
                <div class="col"><div class="fs-6 text-muted">جمع پرداختی</div><div class="fs-5 fw-bold text-success">${formatCurrency(summary.totalPaid)}</div></div>
                <div class="col"><div class="fs-6 text-muted">جمع مانده</div><div class="fs-5 fw-bold text-danger">${formatCurrency(summary.totalRemaining)}</div></div>
            </div></div></div>`;
        }

        if (!invoices || invoices.length === 0) {
            finalHtml += `<p class="text-center">فاکتوری در این بازه زمانی یافت نشد.</p>`;
            container.html(finalHtml);
            return;
        }

        const accordionId = `invoicesReportAccordion-${type}`;
        let accordionHtml = `<div class="accordion" id="${accordionId}">`;
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';

        invoices.forEach((inv, index) => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            const invoiceDate = UI.gregorianToPersian(inv.date);

            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { itemsHtml += `<tr><td>${item.productName || 'حذف شده'} (${item.dimensions})</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
            itemsHtml += '</tbody></table>';

            let paymentsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>';
            if (inv.payments && inv.payments.length > 0) (inv.payments || []).forEach(p => { let det = p.description || '-'; if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`; paymentsHtml += `<tr><td>${p.type}</td><td>${UI.gregorianToPersian(p.date)}</td><td>${formatCurrency(p.amount)}</td><td>${det}</td></tr>`; }); else paymentsHtml += `<tr><td colspan="4" class="text-center">پرداختی ندارد</td></tr>`
            paymentsHtml += '</tbody></table>';

            const headerContent = `فاکتور #${inv.id} | ${personTitle}: <strong>${personName || ''}</strong> | تاریخ: ${invoiceDate}`;
            const footerContent = `<span class="badge bg-primary">کل: ${formatCurrency(inv.totalAmount)}</span>
                                 <span class="badge bg-info text-dark">تخفیف: ${formatCurrency(inv.discount || 0)}</span>
                                 <span class="badge bg-success">پرداختی: ${formatCurrency(inv.paidAmount)}</span>
                                 <span class="badge bg-danger">مانده: ${formatCurrency(inv.remainingAmount)}</span>`;
            const bodyContent = `<div class="row"><div class="col-md-7"><h5>اقلام</h5>${itemsHtml}</div><div class="col-md-5"><h5>پرداخت‌ها</h5>${paymentsHtml}</div></div>`;

            accordionHtml += `<div class="accordion-item">
                <h2 class="accordion-header" id="heading-${type}-${index}">
                    <button class="accordion-button collapsed d-flex justify-content-between align-items-center flex-wrap" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${type}-${index}">
                        <div class="me-auto">${headerContent}</div>
                        <div class="d-flex gap-2">${footerContent}</div>
                    </button>
                </h2>
                <div id="collapse-${type}-${index}" class="accordion-collapse collapse" data-bs-parent="#${accordionId}">
                    <div class="accordion-body">${bodyContent}</div>
                </div>
            </div>`;
        });

        accordionHtml += `</div>`;
        finalHtml += accordionHtml;
        container.html(finalHtml);
    }

    function print() {
        const container = $('#report-results-container');
        const title = container.find('h4').first().text();
        let content = '';

        if (lastReportType === 'sales-invoices' || lastReportType === 'purchase-invoices') {
            content = _getPrintableInvoiceListHtml(lastReportType.includes('sales') ? 'sales' : 'purchase', lastReportData);
        } else {
            content = container.html();
        }

        const printWin = window.open('', '_blank');
        printWin.document.write(
            `<html><head><title>${title}</title>
            <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
            <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
            <style>
                body { font-family: Vazirmatn, sans-serif; direction: rtl; background-color: #fff !important; }
                @media print { .no-print, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate, .accordion-button { display: none !important; } .card { page-break-inside: avoid; } }
                table th { text-align: right !important; }
                .card { border: 1px solid #dee2e6; margin-bottom: 1rem; }
            </style></head><body>
            <div class="container-fluid mt-4">${content}</div>
            </body></html>`
        );
        printWin.document.close();
        printWin.focus();
        setTimeout(() => { printWin.print(); printWin.close(); }, 500);
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
            entityId: lastReportParams.entityId
        });

        window.location.href = `${AppConfig.API_URL}?action=export_report&${params.toString()}`;
    }

    function _generateProfitLossReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const entityName = $('#report-entity-select option:selected').text();
        const netSales = data.grossSales - data.salesDiscounts;
        const costSectionHtml = data.calculationMethod === 'cogs'
            ? `<tr class="table-info"><td class="fw-bold">بهای تمام شده کالاهای فروش رفته</td><td class="text-end fw-bold">(${formatCurrency(data.totalCogs)})</td></tr>`
            : `<tr><td>جمع خرید ناخالص</td><td class="text-end">${formatCurrency(data.grossPurchases)}</td></tr>
               <tr><td>- تخفیفات خرید</td><td class="text-end text-danger">(${formatCurrency(data.purchaseDiscounts)})</td></tr>
               <tr class="table-secondary"><td><strong>خرید خالص</strong></td><td class="text-end fw-bold">(${formatCurrency(data.grossPurchases - data.purchaseDiscounts)})</td></tr>`;

        const calculationNote = data.calculationMethod === 'cogs'
            ? `<div class="alert alert-info small"><strong>توجه:</strong> سود بر اساس روش دقیق (بهای تمام شده کالاهای فروش رفته) محاسبه شده است.</div>`
            : `<div class="alert alert-secondary small"><strong>توجه:</strong> سود بر اساس روش ساده (کل فروش - کل خرید) محاسبه شده است.</div>`;

        const { openingCapital, periodDeposits, periodWithdrawals } = data.capitalSummary;
        const closingCapital = Number(openingCapital) + data.netOperatingProfit + Number(periodDeposits) - Number(periodWithdrawals);

        let finalHtml = `<h4 class="mb-3">گزارش سود و زیان برای «${entityName}» از ${persianStartDate} تا ${persianEndDate}</h4>`;
        finalHtml += calculationNote;

        finalHtml += `<div class="row">
            <div class="col-lg-8">
                <div class="card mb-3"><div class="card-header bg-light"><h5>بخش درآمد و هزینه</h5></div><div class="card-body p-0"><table class="table table-sm table-striped mb-0"><tbody>
                    <tr><td>جمع فروش ناخالص</td><td class="text-end">${formatCurrency(data.grossSales)}</td></tr>
                    <tr><td>- تخفیفات فروش</td><td class="text-end text-danger">(${formatCurrency(data.salesDiscounts)})</td></tr>
                    <tr class="table-primary"><td><strong>فروش خالص</strong></td><td class="text-end fw-bold">${formatCurrency(netSales)}</td></tr>
                    ${costSectionHtml}
                </tbody></table></div><div class="card-footer fs-5"><strong class="me-2">سود ناخالص:</strong> <strong class="${data.grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(data.grossProfit)}</strong></div></div>
                
                <div class="card mb-3"><div class="card-header bg-light"><h5>خلاصه وضعیت سرمایه (کلی)</h5></div><div class="card-body p-0"><table class="table table-sm table-striped mb-0"><tbody>
                    <tr><td>سرمایه اولیه (قبل از دوره)</td><td class="text-end">${formatCurrency(openingCapital)}</td></tr>
                    <tr><td>(+) سود خالص عملیاتی دوره (مربوط به همه مجموعه‌ها)</td><td class="text-end text-success">${formatCurrency(data.netOperatingProfit)}</td></tr>
                    <tr><td>(+) واریزی شرکا در دوره</td><td class="text-end text-success">${formatCurrency(periodDeposits)}</td></tr>
                    <tr><td>(-) برداشتی شرکا در دوره</td><td class="text-end text-danger">(${formatCurrency(periodWithdrawals)})</td></tr>
                    <tr class="table-primary fw-bold"><td>سرمایه نهایی (در پایان دوره)</td><td class="text-end">${formatCurrency(closingCapital)}</td></tr>
                </tbody></table></div><div class="card-footer text-muted small">* وضعیت سرمایه و شرکا به صورت کلی و فارغ از مجموعه تجاری انتخاب شده نمایش داده می‌شود.</div></div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-3"><div class="card-header bg-light"><h5>ریز هزینه‌های مجموعه «${entityName}»</h5></div><div class="card-body p-0" style="max-height: 450px; overflow-y: auto;"><table class="table table-sm table-striped mb-0"><tbody>`;

        if (data.expenseBreakdown && data.expenseBreakdown.length > 0) {
            data.expenseBreakdown.forEach(exp => {
                finalHtml += `<tr><td>${exp.category}</td><td class="text-end text-danger">(${formatCurrency(exp.total)})</td></tr>`;
            });
        } else {
            finalHtml += `<tr><td colspan="2" class="text-center p-3">هزینه‌ای ثبت نشده</td></tr>`;
        }

        finalHtml += `<tr class="table-secondary"><td class="fw-bold">جمع هزینه‌ها</td><td class="text-end fw-bold text-danger">(${formatCurrency(data.totalCompanyExpenses)})</td></tr>
                </tbody></table></div></div>
            </div>
        </div>`;

        finalHtml += `<div class="alert ${data.netOperatingProfit >= 0 ? 'alert-success' : 'alert-danger'} text-center fs-4 mt-3">
            <strong>سود خالص عملیاتی برای «${entityName}»:</strong> <strong dir="ltr" class="ms-2">${formatCurrency(data.netOperatingProfit)}</strong>
        </div>`;

        if (data.partners && data.partners.length > 0) {
            finalHtml += '<div class="card mb-3"><div class="card-header bg-light"><h5>صورت وضعیت نهایی شرکا (کلی)</h5></div><div class="card-body p-0"><table class="table table-bordered table-sm mb-0"><thead><tr class="table-secondary"><th>نام شریک</th><th>سود تخصیص‌یافته (از این مجموعه)</th><th>مجموع واریزی (جامع)</th><th>مجموع برداشتی (جامع)</th><th>مانده نهایی سهم</th></tr></thead><tbody>';
            (data.partners).forEach(p => {
                const profitShare = data.netOperatingProfit * p.share;
                const partnerAccount = (data.accounts || []).find(acc => acc.partner_id == p.id);
                const directDeposits = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'receipt').reduce((sum, t) => sum + Number(t.amount), 0);
                const directWithdrawals = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'payment').reduce((sum, t) => sum + Number(t.amount), 0);
                let expensesFromPersonal = 0, paymentsFromPersonal = 0, paymentsToPersonal = 0;
                if (partnerAccount) {
                    expensesFromPersonal = (data.expenses || []).filter(exp => exp.account_id == partnerAccount.id).reduce((sum, exp) => sum + Number(exp.amount), 0);
                    const personalAccountPayments = (data.payments || []).filter(pay => pay.account_id == partnerAccount.id);
                    paymentsFromPersonal = personalAccountPayments.filter(pay => pay.invoiceType === 'purchase').reduce((sum, pay) => sum + Number(pay.amount), 0);
                    paymentsToPersonal = personalAccountPayments.filter(pay => pay.invoiceType === 'sales').reduce((sum, pay) => sum + Number(pay.amount), 0);
                }
                const totalDepositsComprehensive = directDeposits + expensesFromPersonal + paymentsFromPersonal;
                const totalWithdrawalsComprehensive = directWithdrawals + paymentsToPersonal;
                const finalBalance = profitShare + totalDepositsComprehensive - totalWithdrawalsComprehensive;
                finalHtml += `<tr>
                    <td><strong>${p.name} (${(p.share * 100).toFixed(0)}%)</strong></td>
                    <td class="text-end ${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</td>
                    <td class="text-end text-success">${formatCurrency(totalDepositsComprehensive)}</td>
                    <td class="text-end text-danger">(${formatCurrency(totalWithdrawalsComprehensive)})</td>
                    <td class="text-end fw-bold ${finalBalance >= 0 ? 'text-primary' : 'text-danger'}">${formatCurrency(finalBalance)}</td>
                </tr>`;
            });
            finalHtml += '</tbody></table></div></div>';
        }

        container.html(finalHtml);
    }

    function _generatePersonStatementReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { transactions, person } = data;
        const reportTitle = `صورتحساب: ${person.name}`;

        let totalDebit = 0, totalCredit = 0;
        transactions.forEach(t => {
            totalDebit += t.debit || 0;
            totalCredit += t.credit || 0;
        });
        const openingBalance = Number(person.initial_balance || 0);
        const isCustomer = lastReportParams.personType === 'customer';
        const closingBalance = openingBalance + (isCustomer ? (totalDebit - totalCredit) : (totalCredit - totalDebit));

        let reportHtml = `<h4 class="mb-3">${reportTitle} (از ${persianStartDate} تا ${persianEndDate})</h4>`;

        reportHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center">
            <div class="col"><div class="fs-6 text-muted">مانده اولیه</div><div class="fs-5 fw-bold">${formatCurrency(openingBalance)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع بدهکار</div><div class="fs-5 fw-bold text-danger">${formatCurrency(totalDebit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع بستانکار</div><div class="fs-5 fw-bold text-success">${formatCurrency(totalCredit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">مانده نهایی</div><div class="fs-5 fw-bold text-primary">${formatCurrency(closingBalance)}</div></div>
        </div></div></div>`;

        if (transactions.length === 0) {
            container.html(reportHtml + '<p class="text-center">گردش حسابی در این بازه یافت نشد.</p>');
            return;
        }

        reportHtml += `<table id="person-statement-table" class="table table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>`;
        let balance = openingBalance;

        transactions.forEach(t => {
            const currentDebit = t.debit || 0;
            const currentCredit = t.credit || 0;
            balance += (isCustomer ? (currentDebit - currentCredit) : (currentCredit - currentDebit));
            const balanceText = balance >= 0 ? (isCustomer ? '(بدهکار)' : '(بستانکار)') : (isCustomer ? '(بستانکار)' : '(بدهکار)');

            let descHtml = t.desc || '';
            if (descHtml && descHtml.startsWith('فاکتور')) {
                const refType = isCustomer ? 'salesInvoice' : 'purchaseInvoice';
                descHtml = descHtml.replace(/#(\d+)/, `<a href="#" class="report-link" data-id="$1" data-type="${refType}">#$1</a>`);
            } else if (t.refType === 'payment') {
                descHtml = `<a href="#" class="report-link" data-id="${t.refId}" data-type="payment">${descHtml}</a>`;
            }

            const dateDisplay = t.date === 'مانده اولیه' ? t.date : UI.gregorianToPersian(t.date);
            const rowClass = t.isUnrealized ? 'text-muted fst-italic' : '';
            reportHtml += `<tr class="${rowClass}"><td>${dateDisplay}</td><td>${descHtml}</td><td>${formatCurrency(currentDebit)}</td><td>${formatCurrency(currentCredit)}</td><td class="${balance >= 0 ? '' : 'text-danger'}">${formatCurrency(Math.abs(balance))} ${balanceText}</td></tr>`;
        });
        reportHtml += `</tbody></table>`
        container.html(reportHtml);
        _initDataTable('#person-statement-table', { "ordering": false });
    }

    function _generateAccountStatementReport(data, container) {
        const { persianStartDate, persianEndDate } = lastReportParams;
        const { account, transactions, openingBalance } = data;

        let totalCredit = 0, totalDebit = 0;
        transactions.forEach(tx => {
            totalCredit += tx.credit || 0;
            totalDebit += tx.debit || 0;
        });
        const closingBalance = openingBalance + totalCredit - totalDebit;

        let reportHtml = `<h4 class="mb-3">صورتحساب: ${account.name} (از ${persianStartDate} تا ${persianEndDate})</h4>`;

        reportHtml += `<div class="card mb-4"><div class="card-body"><div class="row text-center">
            <div class="col"><div class="fs-6 text-muted">مانده اولیه</div><div class="fs-5 fw-bold">${formatCurrency(openingBalance)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع واریزی</div><div class="fs-5 fw-bold text-success">${formatCurrency(totalCredit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">جمع برداشتی</div><div class="fs-5 fw-bold text-danger">${formatCurrency(totalDebit)}</div></div>
            <div class="col"><div class="fs-6 text-muted">مانده نهایی</div><div class="fs-5 fw-bold text-primary">${formatCurrency(closingBalance)}</div></div>
        </div></div></div>`;

        reportHtml += `<table id="account-statement-table" class="table table-bordered" style="width:100%"><thead class="table-light"><tr><th class="text-end">تاریخ</th><th class="text-end">شرح</th><th class="text-end text-success">بستانکار (واریز)</th><th class="text-end text-danger">بدهکار (برداشت)</th><th class="text-end">مانده</th></tr></thead><tbody>`;

        if (transactions.length === 0) {
            reportHtml += '<tr><td colspan="5" class="text-center">گردش حسابی در این بازه یافت نشد.</td></tr>';
        }

        let runningBalance = openingBalance;
        transactions.forEach(tx => {
            const bostankar = tx.credit || 0;
            const bedehkar = tx.debit || 0;

            runningBalance += (bostankar - bedehkar);
            const descHtml = `<a href="#" class="report-link" data-id="${tx.refId}" data-type="${tx.refType}">${tx.description}</a>`;
            const txDate = UI.gregorianToPersian(tx.date);

            reportHtml += `<tr><td class="text-end">${txDate}</td><td class="text-end">${descHtml}</td>
                <td class="text-end text-success">${bostankar !== 0 ? formatCurrency(bostankar) : '-'}</td>
                <td class="text-end text-danger">${bedehkar !== 0 ? `(${formatCurrency(bedehkar)})` : '-'}</td>
                <td class="text-end fw-bold">${formatCurrency(runningBalance)}</td></tr>`;
        });
        reportHtml += `</tbody></table>`;
        container.html(reportHtml);

        if (transactions.length > 0) {
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
            <thead class="table-light"><tr><th>تاریخ</th><th>نوع تراکنش</th><th>ابعاد</th><th>شماره مدرک</th><th>ورودی</th><th>خروجی</th><th>موجودی نهایی</th></tr></thead>
            <tbody></tbody></table>`;
        container.html(reportHtml);

        const tableData = [];
        let runningStock = openingStock;

        tableData.push({
            date: 'موجودی اولیه',
            type: '',
            dimensions: '',
            refLink: '',
            quantityIn: '',
            quantityOut: '',
            runningStock: runningStock,
            isOpening: true
        });

        transactions.forEach(tx => {
            const isIn = tx.type === 'purchase';
            const quantityIn = isIn ? tx.quantity : 0;
            const quantityOut = !isIn ? tx.quantity : 0;
            runningStock += (quantityIn - quantityOut);

            const refType = isIn ? 'purchaseInvoice' : 'salesInvoice';
            const refLink = `<a href="#" class="report-link" data-id="${tx.refId}" data-type="${refType}">#${tx.refId}</a>`;

            tableData.push({
                date: UI.gregorianToPersian(tx.date),
                type: isIn ? 'خرید' : 'فروش',
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
                { data: 'type' },
                { data: 'dimensions' },
                { data: 'refLink' },
                { data: 'quantityIn', className: 'text-success' },
                { data: 'quantityOut', className: 'text-danger' },
                { data: 'runningStock', className: 'fw-bold' }
            ],
            createdRow: function (row, data, dataIndex) {
                if (data.isOpening) {
                    $(row).addClass('table-secondary fw-bold').find('td:first').attr('colspan', 6);
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

    function _generateInventoryReport(data, container) {
        let reportHtml = `<h4 class="mb-3">گزارش موجودی انبار</h4>`;
        if (!data || data.length === 0) {
            reportHtml += `<p class="text-center">هیچ محصولی با موجودی یافت نشد.</p>`;
            container.html(reportHtml);
            return;
        }

        reportHtml += `<table id="inventory-report-table" class="table table-bordered table-striped" style="width:100%"><thead><tr><th>نام طرح</th><th>ابعاد</th><th>تعداد موجود</th></tr></thead><tbody></tbody></table>`;
        container.html(reportHtml);

        _initDataTable('#inventory-report-table', {
            data: data,
            columns: [
                { data: 'name' },
                { data: 'dimensions' },
                { data: 'quantity' }
            ]
        });
    }

    function _generateInventoryValueReport(data, container) {
        const { items, totalValue } = data;
        let reportHtml = `<h4 class="mb-3">گزارش ارزش موجودی انبار</h4>`;

        if (!items || items.length === 0) {
            reportHtml += `<p class="text-center">هیچ محصولی با موجودی یافت نشد.</p>`;
            container.html(reportHtml);
            return;
        }

        reportHtml += `<table id="inventory-value-report-table" class="table table-bordered table-striped" style="width:100%"><thead class="table-light"><tr><th>نام محصول</th><th>ابعاد</th><th>موجودی</th><th>آخرین قیمت خرید</th><th>ارزش کل ردیف</th></tr></thead><tbody></tbody><tfoot class="fw-bold"><tr class="table-secondary"><td colspan="4" class="text-end">جمع کل ارزش انبار:</td><td>${formatCurrency(totalValue)}</td></tr></tfoot></table>`;
        container.html(reportHtml);

        _initDataTable('#inventory-value-report-table', {
            data: items,
            columns: [
                { data: 'name' },
                { data: 'dimensions' },
                { data: 'quantity' },
                { data: 'lastPrice', render: (d) => formatCurrency(d) },
                { data: 'rowValue', render: (d) => formatCurrency(d) }
            ]
        });
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

    function init() {
        $('#print-report-btn').on('click', print);
        $('#export-report-btn').on('click', exportCsv);
    }

    return {
        init: init,
        generate: generate,
        print: print,
        exportCsv: exportCsv
    };
})();