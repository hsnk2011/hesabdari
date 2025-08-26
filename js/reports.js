// /js/reports.js

const ReportGenerator = (function () {
    let appDataCache = {};
    let lastReportData = null;
    let lastReportType = null;
    let lastReportParams = {};

    const formatCurrency = (num) => UI.formatCurrency(num);
    const toEnglishDigits = (str) => UI.toEnglishDigits(str);

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
            case 'profit-loss':
                apiAction = 'get_profit_loss_report';
                break;
            case 'persons':
                apiAction = 'get_person_statement';
                break;
            case 'accounts':
                apiAction = 'get_account_statement';
                break;
            case 'sales-invoices':
            case 'purchase-invoices':
                apiParams.type = reportType.includes('sales') ? 'sales' : 'purchase';
                apiAction = 'get_invoices_report';
                break;
            case 'expenses':
                apiAction = 'get_expenses_report';
                break;
            case 'inventory':
                apiAction = 'get_inventory_report';
                break;
            case 'inventory-value':
                apiAction = 'get_inventory_value_report';
                break;
            case 'inventory-ledger':
                apiAction = 'get_inventory_ledger_report';
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
        $('#print-report-btn').hide();

        const startDate = toEnglishDigits($('#report-start-date').val());
        const endDate = toEnglishDigits($('#report-end-date').val());
        const person = $('#report-person-select').val();
        const account = $('#report-account-select').val();
        const product = $('#report-product-select').val();

        if (['profit-loss', 'sales-invoices', 'purchase-invoices', 'expenses', 'persons', 'accounts', 'inventory-ledger'].includes(reportType) && (!startDate || !endDate)) {
            alert('لطفا بازه زمانی را مشخص کنید.');
            return;
        }
        if (reportType === 'persons' && !person) {
            alert('لطفا یک شخص را انتخاب کنید.');
            return;
        }
        if (reportType === 'accounts' && !account) {
            alert('لطفا یک حساب را انتخاب کنید.');
            return;
        }
        if (reportType === 'inventory-ledger' && !product) {
            alert('لطفا یک محصول را انتخاب کنید.');
            return;
        }

        const [personType, personId] = (person || '-').split('-');

        lastReportType = reportType;
        lastReportParams = { startDate, endDate, personType, personId, accountId: account, productId: product };

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
            default: container.html('<p class="text-danger">نوع گزارش انتخاب شده معتبر نیست.</p>');
        }

        if (container.html().trim() !== "") {
            $('#print-report-btn').show();
        }
    }

    function _getPrintableInvoiceListHtml(type, data) {
        const { invoices } = data;
        const { startDate, endDate } = lastReportParams;
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';

        let reportHtml = `<h4 class="mb-3">گزارش ${title} از ${startDate} تا ${endDate}</h4>`;
        if (!invoices || invoices.length === 0) {
            reportHtml += `<p class="text-center">فاکتوری در این بازه زمانی یافت نشد.</p>`;
            return reportHtml;
        }

        invoices.forEach(inv => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { itemsHtml += `<tr><td>${item.productName || 'حذف شده'} (${item.dimensions})</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
            itemsHtml += '</tbody></table>';

            let paymentsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>';
            if (inv.payments && inv.payments.length > 0) (inv.payments || []).forEach(p => { let det = p.description || '-'; if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`; paymentsHtml += `<tr><td>${p.type}</td><td>${p.date}</td><td>${formatCurrency(p.amount)}</td><td>${det}</td></tr>`; }); else paymentsHtml += `<tr><td colspan="4" class="text-center">پرداختی ندارد</td></tr>`
            paymentsHtml += '</tbody></table>';

            reportHtml += `
                <div class="card mb-3">
                    <div class="card-header">فاکتور #${inv.id} | ${personTitle}: <strong>${personName || ''}</strong> | تاریخ: ${inv.date}</div>
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
        const { startDate, endDate } = lastReportParams;
        const { invoices, summary } = data;
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';

        let finalHtml = `<h4 class="mb-3">گزارش ${title} از ${startDate} تا ${endDate}</h4>`;

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

            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { itemsHtml += `<tr><td>${item.productName || 'حذف شده'} (${item.dimensions})</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
            itemsHtml += '</tbody></table>';

            let paymentsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>';
            if (inv.payments && inv.payments.length > 0) (inv.payments || []).forEach(p => { let det = p.description || '-'; if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`; paymentsHtml += `<tr><td>${p.type}</td><td>${p.date}</td><td>${formatCurrency(p.amount)}</td><td>${det}</td></tr>`; }); else paymentsHtml += `<tr><td colspan="4" class="text-center">پرداختی ندارد</td></tr>`
            paymentsHtml += '</tbody></table>';

            const headerContent = `فاکتور #${inv.id} | ${personTitle}: <strong>${personName || ''}</strong> | تاریخ: ${inv.date}`;
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

    function _generateProfitLossReport(data, container) {
        const { startDate, endDate } = lastReportParams;
        const netSales = data.grossSales - data.salesDiscounts;
        const netPurchases = data.grossPurchases - data.purchaseDiscounts;
        const grossProfit = netSales - netPurchases;
        const netOperatingProfit = grossProfit - data.totalCompanyExpenses;
        const { openingCapital, periodDeposits, periodWithdrawals } = data.capitalSummary;
        const closingCapital = Number(openingCapital) + netOperatingProfit + Number(periodDeposits) - Number(periodWithdrawals);

        let finalHtml = `<h4 class="mb-3">گزارش سود و زیان از ${startDate} تا ${endDate}</h4>`;

        finalHtml += `<div class="row">
            <div class="col-lg-8">
                <div class="card mb-3"><div class="card-header bg-light"><h5>بخش درآمد و بهای تمام شده</h5></div><div class="card-body p-0"><table class="table table-sm table-striped mb-0"><tbody>
                    <tr><td>جمع فروش ناخالص</td><td class="text-end">${formatCurrency(data.grossSales)}</td></tr>
                    <tr><td>- تخفیفات فروش</td><td class="text-end text-danger">(${formatCurrency(data.salesDiscounts)})</td></tr>
                    <tr class="table-primary"><td><strong>فروش خالص</strong></td><td class="text-end fw-bold">${formatCurrency(netSales)}</td></tr>
                    <tr><td>جمع خرید ناخالص</td><td class="text-end">${formatCurrency(data.grossPurchases)}</td></tr>
                    <tr><td>- تخفیفات خرید</td><td class="text-end text-danger">(${formatCurrency(data.purchaseDiscounts)})</td></tr>
                    <tr class="table-secondary"><td><strong>خرید خالص</strong></td><td class="text-end fw-bold">(${formatCurrency(netPurchases)})</td></tr>
                </tbody></table></div><div class="card-footer fs-5"><strong class="me-2">سود ناخالص:</strong> <strong class="${grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(grossProfit)}</strong></div></div>
                
                <div class="card mb-3"><div class="card-header bg-light"><h5>خلاصه وضعیت سرمایه</h5></div><div class="card-body p-0"><table class="table table-sm table-striped mb-0"><tbody>
                    <tr><td>سرمایه اولیه (قبل از دوره)</td><td class="text-end">${formatCurrency(openingCapital)}</td></tr>
                    <tr><td>(+) سود خالص عملیاتی دوره</td><td class="text-end text-success">${formatCurrency(netOperatingProfit)}</td></tr>
                    <tr><td>(+) واریزی شرکا در دوره</td><td class="text-end text-success">${formatCurrency(periodDeposits)}</td></tr>
                    <tr><td>(-) برداشتی شرکا در دوره</td><td class="text-end text-danger">(${formatCurrency(periodWithdrawals)})</td></tr>
                    <tr class="table-primary fw-bold"><td>سرمایه نهایی (در پایان دوره)</td><td class="text-end">${formatCurrency(closingCapital)}</td></tr>
                </tbody></table></div></div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-3"><div class="card-header bg-light"><h5>ریز هزینه‌های شرکت</h5></div><div class="card-body p-0" style="max-height: 450px; overflow-y: auto;"><table class="table table-sm table-striped mb-0"><tbody>`;

        if (data.expenseBreakdown && data.expenseBreakdown.length > 0) {
            data.expenseBreakdown.forEach(exp => {
                finalHtml += `<tr><td>${exp.category}</td><td class="text-end text-danger">(${formatCurrency(exp.total)})</td></tr>`;
            });
        } else {
            finalHtml += `<tr><td colspan="2" class="text-center p-3">هزینه‌ای ثبت نشده</td></tr>`;
        }

        finalHtml += `<tr class="table-secondary"><td class="fw-bold">جمع هزینه‌ها</td><td class="text-end fw-bold text-danger">(${formatCurrency(data.totalCompanyExpenses)})</td></tr>
                </tbody></table></div><div class="card-footer text-muted small">* این بخش شامل تمام هزینه‌های ثبت شده (شرکت و شرکا) می‌باشد.</div></div>
            </div>
        </div>`;

        finalHtml += `<div class="alert ${netOperatingProfit >= 0 ? 'alert-success' : 'alert-danger'} text-center fs-4 mt-3">
            <strong>سود خالص عملیاتی:</strong> <strong dir="ltr" class="ms-2">${formatCurrency(netOperatingProfit)}</strong>
        </div>`;

        if (data.partners && data.partners.length > 0) {
            finalHtml += '<div class="card mb-3"><div class="card-header bg-light"><h5>صورت وضعیت نهایی شرکا</h5></div><div class="card-body p-0"><table class="table table-bordered table-sm mb-0"><thead><tr class="table-secondary"><th>نام شریک</th><th>سود تخصیص‌یافته</th><th>مجموع واریزی (جامع)</th><th>مجموع برداشتی (جامع)</th><th>مانده نهایی سهم</th></tr></thead><tbody>';
            (data.partners).forEach(p => {
                const profitShare = netOperatingProfit * p.share;
                const partnerAccount = (data.accounts || []).find(acc => acc.partner_id == p.id);

                const directDeposits = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'DEPOSIT').reduce((sum, t) => sum + Number(t.amount), 0);
                const directWithdrawals = (data.partner_transactions || []).filter(t => t.partnerId == p.id && t.type === 'WITHDRAWAL').reduce((sum, t) => sum + Number(t.amount), 0);

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
        const { startDate, endDate } = lastReportParams;
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

        let reportHtml = `<h4 class="mb-3">${reportTitle} (از ${startDate} تا ${endDate})</h4>`;

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

            const refType = isCustomer ? 'salesInvoice' : 'purchaseInvoice';
            const descHtml = t.desc.replace(/#(\d+)/, `<a href="#" class="report-link" data-id="$1" data-type="${refType}">#$1</a>`);

            reportHtml += `<tr><td>${t.date}</td><td>${descHtml}</td><td>${formatCurrency(currentDebit)}</td><td>${formatCurrency(currentCredit)}</td><td class="${balance >= 0 ? '' : 'text-danger'}">${formatCurrency(Math.abs(balance))} ${balanceText}</td></tr>`;
        });

        container.html(reportHtml);
        _initDataTable('#person-statement-table', { "ordering": false });
    }

    function _generateAccountStatementReport(data, container) {
        const { startDate, endDate } = lastReportParams;
        const { account, transactions, openingBalance } = data;

        let totalCredit = 0, totalDebit = 0;
        transactions.forEach(tx => {
            if (tx.source.endsWith('_in') || tx.source === 'check' || tx.source === 'partner_personal_in') totalCredit += tx.amount;
            else totalDebit += tx.amount;
        });
        const closingBalance = openingBalance + totalCredit - totalDebit;

        let reportHtml = `<h4 class="mb-3">صورتحساب: ${account.name} (از ${startDate} تا ${endDate})</h4>`;

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
            let bostankar = 0, bedehkar = 0;
            const source = tx.source || '';

            if (source.endsWith('_in') || source === 'check' || source === 'partner_personal_in') bostankar = tx.amount;
            else if (source.endsWith('_out') || source === 'partner_personal_out') bedehkar = tx.amount;

            runningBalance += (bostankar - bedehkar);
            const descHtml = `<a href="#" class="report-link" data-id="${tx.refId}" data-type="${tx.refType}">${tx.description}</a>`;

            reportHtml += `<tr><td class="text-end">${tx.date}</td><td class="text-end">${descHtml}</td>
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
        const { startDate, endDate, productId } = lastReportParams;
        const { openingStock, transactions } = data;
        const product = appDataCache.products.find(p => p.id == productId);
        const productName = product ? product.name : `محصول #${productId}`;

        let reportHtml = `<h4 class="mb-3">کاردکس کالا: ${productName} (از ${startDate} تا ${endDate})</h4>`;
        const tableId = 'inventory-ledger-table';
        reportHtml += `<table id="${tableId}" class="table table-bordered table-striped" style="width:100%">
            <thead class="table-light"><tr><th>تاریخ</th><th>نوع تراکنش</th><th>شماره مدرک</th><th>ورودی</th><th>خروجی</th><th>موجودی نهایی</th></tr></thead>
            <tbody></tbody></table>`;
        container.html(reportHtml);

        const tableData = [];
        let runningStock = openingStock;

        tableData.push({
            date: 'موجودی اولیه',
            type: '',
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
                date: tx.date,
                type: isIn ? 'خرید' : 'فروش',
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
                { data: 'refLink' },
                { data: 'quantityIn', className: 'text-success' },
                { data: 'quantityOut', className: 'text-danger' },
                { data: 'runningStock', className: 'fw-bold' }
            ],
            createdRow: function (row, data, dataIndex) {
                if (data.isOpening) {
                    $(row).addClass('table-secondary fw-bold').find('td:first').attr('colspan', 5);
                    $(row).find('td:gt(0)').not(':last').remove();
                }
            }
        });
    }

    function _generateExpensesReport(data, container) {
        const { startDate, endDate } = lastReportParams;
        const { expenses, total } = data;
        let reportHtml = `<h4 class="mb-3">گزارش هزینه‌ها از ${startDate} تا ${endDate}</h4>`;
        if (expenses.length === 0) {
            container.html(reportHtml + `<p class="text-center">هیچ هزینه‌ای یافت نشد.</p>`);
            return;
        }
        reportHtml += `<table id="expenses-report-table" class="table table-striped table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>دسته‌بندی</th><th>مبلغ</th><th>توضیحات</th><th>حساب پرداخت</th></tr></thead><tbody>`;
        expenses.forEach(exp => {
            reportHtml += `<tr><td>${exp.date}</td><td>${exp.category}</td><td>${formatCurrency(exp.amount)}</td><td>${exp.description || '-'}</td><td>${exp.accountName || '-'}</td></tr>`;
        });
        reportHtml += `</tbody><tfoot class="fw-bold"><tr class="table-secondary"><td colspan="2" class="text-end">جمع کل:</td><td colspan="3">${formatCurrency(total)}</td></tr></tfoot></table>`;
        container.html(reportHtml);
        _initDataTable('#expenses-report-table');
    }

    function _generateInventoryReport(data, container) {
        const tableHtml = `<h4 class="mb-3">گزارش موجودی انبار</h4><table id="inventory-report-table" class="table table-bordered table-striped" style="width:100%"><thead><tr><th>نام طرح</th><th>ابعاد</th><th>تعداد موجود</th></tr></thead><tbody></tbody></table>`;
        container.html(tableHtml);

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
        const tableHtml = `<h4 class="mb-3">گزارش ارزش موجودی انبار</h4><table id="inventory-value-report-table" class="table table-bordered table-striped" style="width:100%"><thead class="table-light"><tr><th>نام محصول</th><th>ابعاد</th><th>موجودی</th><th>آخرین قیمت خرید</th><th>ارزش کل ردیف</th></tr></thead><tbody></tbody><tfoot class="fw-bold"><tr class="table-secondary"><td colspan="4" class="text-end">جمع کل ارزش انبار:</td><td>${formatCurrency(totalValue)}</td></tr></tfoot></table>`;
        container.html(tableHtml);

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


    function init() {
        $('#print-report-btn').on('click', print);
    }

    return {
        init: init,
        generate: generate,
        print: print
    };
})();