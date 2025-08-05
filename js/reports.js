// /js/reports.js

const ReportGenerator = (function () {
    let appData = {};
    let lastReportData = null;
    let lastReportType = null;

    const formatCurrency = (num) => appData.formatCurrency(num);
    const toEnglishDigits = (str) => appData.toEnglishDigits(str);

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

    function generate(reportType, reportData, container) {
        container.empty();
        $('#print-report-btn').hide();
        lastReportData = reportData;
        lastReportType = reportType;

        switch (reportType) {
            case 'profit-loss': _generateProfitLossReport(reportData, container); break;
            case 'inventory': _generateInventoryReport(reportData, container); break;
            case 'inventory-value': _generateInventoryValueReport(reportData, container); break;
            case 'sales-invoices': _generateInvoicesReport('sales', reportData, container); break;
            case 'purchase-invoices': _generateInvoicesReport('purchase', reportData, container); break;
            case 'expenses': _generateExpensesReport(reportData, container); break;
            case 'persons': _generatePersonStatementReport(reportData, container); break;
            case 'accounts': _generateAccountStatementReport(reportData, container); break;
            default: container.html('<p class="text-danger">نوع گزارش انتخاب شده معتبر نیست.</p>');
        }

        if (container.html().trim() !== "") {
            $('#print-report-btn').show();
        }
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
        printWin.document.write(`
            <html><head><title>${title}</title>
            <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
            <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
            <style>
                body { font-family: Vazirmatn, sans-serif; direction: rtl; background-color: #fff !important; }
                @media print { .no-print, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate { display: none; } }
                table th { text-align: right !important; }
                .card { border: 1px solid #dee2e6; margin-bottom: 1rem; }
            </style></head><body>
            <div class="container-fluid mt-4">${content}</div>
            </body></html>`);
        printWin.document.close();
        printWin.focus();
        setTimeout(() => { printWin.print(); printWin.close(); }, 500);
    }

    function _generateProfitLossReport(data, container) {
        const startDateStr = toEnglishDigits($('#report-start-date').val()), endDateStr = toEnglishDigits($('#report-end-date').val());
        if (!startDateStr || !endDateStr) { alert('لطفا بازه زمانی را مشخص کنید.'); return; }
        const startUnix = new persianDate(startDateStr.split('/').map(Number)).unix();
        const endUnix = new persianDate(endDateStr.split('/').map(Number)).endOf('day').unix();

        const inDateRange = (dateStr) => {
            const unix = new persianDate(toEnglishDigits(dateStr).split('/').map(Number)).unix();
            return unix >= startUnix && unix <= endUnix;
        };

        const salesInvoices = (data.sales_invoices || []).filter(inv => !inv.is_consignment && inDateRange(inv.date));
        const purchaseInvoices = (data.purchase_invoices || []).filter(inv => !inv.is_consignment && inDateRange(inv.date));
        const expenses = (data.expenses || []).filter(exp => inDateRange(exp.date));
        const partner_tx = (data.partner_transactions || []).filter(t => inDateRange(t.date));

        const grossSales = salesInvoices.reduce((sum, inv) => sum + inv.totalAmount, 0);
        const salesDiscounts = salesInvoices.reduce((sum, inv) => sum + (inv.discount || 0), 0);
        const netSales = grossSales - salesDiscounts;

        const grossPurchases = purchaseInvoices.reduce((sum, inv) => sum + inv.totalAmount, 0);
        const purchaseDiscounts = purchaseInvoices.reduce((sum, inv) => sum + (inv.discount || 0), 0);
        const netPurchases = grossPurchases - purchaseDiscounts;

        const grossProfit = netSales - netPurchases;

        const expensesByCategory = expenses.reduce((acc, exp) => {
            acc[exp.category] = (acc[exp.category] || 0) + exp.amount;
            return acc;
        }, {});
        const totalExpenses = expenses.reduce((sum, exp) => sum + exp.amount, 0);

        const netOperatingProfit = grossProfit - totalExpenses;

        const totalDeposits = partner_tx.filter(t => t.type === 'DEPOSIT').reduce((sum, t) => sum + t.amount, 0);
        const totalWithdrawals = partner_tx.filter(t => t.type === 'WITHDRAWAL').reduce((sum, t) => sum + t.amount, 0);

        let expenseRows = '';
        for (const category in expensesByCategory) {
            expenseRows += `<tr><td>${category}</td><td class="text-danger">(${formatCurrency(expensesByCategory[category])})</td></tr>`;
        }

        let partnersHtml = '';
        if (data.partners && data.partners.length > 0) {
            partnersHtml = `<h5 class="mt-4">تفکیک سود و عملکرد مالی شرکا</h5><table class="table table-bordered table-sm">
                <thead class="table-light"><tr><th>نام شریک</th><th>درصد سهم</th><th>مجموع واریزی</th><th>مجموع برداشتی</th><th>سود تخصیص‌یافته</th></tr></thead><tbody>`;
            (data.partners || []).forEach(p => {
                const profitShare = netOperatingProfit * p.share;
                const partnerSpecificTxs = partner_tx.filter(t => t.partnerId == p.id);
                const partnerDeposits = partnerSpecificTxs.filter(t => t.type === 'DEPOSIT').reduce((sum, t) => sum + t.amount, 0);
                const partnerWithdrawals = partnerSpecificTxs.filter(t => t.type === 'WITHDRAWAL').reduce((sum, t) => sum + t.amount, 0);

                partnersHtml += `
                    <tr>
                        <td>${p.name}</td>
                        <td>${(p.share * 100).toFixed(2)}%</td>
                        <td class="text-success">${formatCurrency(partnerDeposits)}</td>
                        <td class="text-danger">(${formatCurrency(partnerWithdrawals)})</td>
                        <td class="${profitShare >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(profitShare)}</td>
                    </tr>`;
            });
            partnersHtml += `</tbody></table>`;
        }

        const reportHtml = `
            <h4 class="mb-3">گزارش سود و زیان از ${startDateStr} تا ${endDateStr}</h4>
            <div class="card"><div class="card-body">
                <table class="table table-sm">
                    <tbody>
                        <tr><td>جمع فروش ناخالص</td><td class="text-end">${formatCurrency(grossSales)}</td></tr>
                        <tr><td>- تخفیفات فروش</td><td class="text-end text-danger">(${formatCurrency(salesDiscounts)})</td></tr>
                        <tr class="table-light"><td class="fw-bold">فروش خالص</td><td class="text-end fw-bold">${formatCurrency(netSales)}</td></tr>
                        <tr><td colspan="2">&nbsp;</td></tr>
                        <tr><td>جمع خرید ناخالص</td><td class="text-end">${formatCurrency(grossPurchases)}</td></tr>
                        <tr><td>- تخفیفات خرید</td><td class="text-end text-danger">(${formatCurrency(purchaseDiscounts)})</td></tr>
                        <tr class="table-light"><td class="fw-bold">بهای تمام شده کالای فروش رفته (خرید خالص)</td><td class="text-end fw-bold">(${formatCurrency(netPurchases)})</td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr class="fs-5"><td class="fw-bold">سود ناخالص</td><td class="text-end fw-bold ${grossProfit >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(grossProfit)}</td></tr>
                    </tbody>
                </table>
                <h5 class="mt-4">هزینه‌های عملیاتی</h5>
                <table class="table table-sm">
                    <tbody>
                        ${expenseRows}
                        <tr class="table-light"><td class="fw-bold">جمع هزینه‌های عملیاتی</td><td class="text-end fw-bold">(${formatCurrency(totalExpenses)})</td></tr>
                    </tbody>
                </table>
                <hr>
                <div class="alert ${netOperatingProfit >= 0 ? 'alert-success' : 'alert-danger'} text-center fs-4">
                    <strong>سود خالص عملیاتی:</strong>
                    <strong dir="ltr" class="ms-2">${formatCurrency(netOperatingProfit)}</strong>
                </div>

                <h5 class="mt-4">خلاصه فعالیت‌های مالی شرکا (در این دوره)</h5>
                <table class="table table-sm">
                    <tbody>
                        <tr><td>+ مجموع واریز شرکا</td><td class="text-end text-success">${formatCurrency(totalDeposits)}</td></tr>
                        <tr><td>- مجموع برداشت شرکا</td><td class="text-end text-danger">(${formatCurrency(totalWithdrawals)})</td></tr>
                    </tbody>
                </table>

                ${partnersHtml}
            </div></div>`;
        container.html(reportHtml);
    }

    function _getPrintableInvoiceListHtml(type, data) {
        const startDateStr = toEnglishDigits($('#report-start-date').val()), endDateStr = toEnglishDigits($('#report-end-date').val());
        const startUnix = new persianDate(startDateStr.split('/').map(Number)).unix();
        const endUnix = new persianDate(endDateStr.split('/').map(Number)).endOf('day').unix();
        const isSales = type === 'sales', title = isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید';
        const invoiceList = isSales ? data.sales_invoices : data.purchase_invoices;
        const personTitle = isSales ? 'مشتری' : 'تامین‌کننده';
        const filteredInvoices = (invoiceList || []).filter(inv => { const d = new persianDate(toEnglishDigits(inv.date).split('/').map(Number)).unix(); return d >= startUnix && d <= endUnix; });

        let reportHtml = `<h4 class="mb-3">گزارش ${title} از ${startDateStr} تا ${endDateStr}</h4>`;
        filteredInvoices.forEach(inv => {
            const personName = isSales ? inv.customerName : inv.supplierName;
            let itemsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody>';
            (inv.items || []).forEach(item => { const p = data.products.find(prod => prod.id == item.productId); itemsHtml += `<tr><td>${p ? p.name : 'حذف شده'} (${item.dimensions})</td><td>${item.quantity}</td><td>${formatCurrency(item.unitPrice)}</td><td>${formatCurrency(item.quantity * item.unitPrice)}</td></tr>`; });
            itemsHtml += '</tbody></table>';
            let paymentsHtml = '<table class="table table-sm table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>';
            if (inv.payments && inv.payments.length > 0) (inv.payments || []).forEach(p => { let det = p.description || '-'; if (p.checkDetails) det = `ش:${p.checkDetails.checkNumber}, بانک:${p.checkDetails.bankName}`; paymentsHtml += `<tr><td>${p.type}</td><td>${p.date}</td><td>${formatCurrency(p.amount)}</td><td>${det}</td></tr>`; }); else paymentsHtml += `<tr><td colspan="4" class="text-center">پرداختی ندارد</td></tr>`
            paymentsHtml += '</tbody></table>';

            reportHtml += `
                <div class="card">
                    <div class="card-header">فاکتور #${inv.id} | ${personTitle}: <strong>${personName || ''}</strong> | تاریخ: ${inv.date}</div>
                    <div class="card-body row"><div class="col-md-7"><h5>اقلام</h5>${itemsHtml}</div><div class="col-md-5"><h5>پرداخت‌ها</h5>${paymentsHtml}</div></div>
                    <div class="card-footer d-flex justify-content-end gap-3">
                        <span class="badge bg-primary">کل: ${formatCurrency(inv.totalAmount)}</span>
                        <span class="badge bg-info">تخفیف: ${formatCurrency(inv.discount || 0)}</span>
                        <span class="badge bg-success">پرداختی: ${formatCurrency(inv.paidAmount)}</span>
                        <span class="badge bg-danger">مانده: ${formatCurrency(inv.remainingAmount)}</span>
                    </div>
                </div>`;
        });
        return reportHtml;
    }

    function _generateInvoicesReport(type, data, container) {
        const reportHtml = _getPrintableInvoiceListHtml(type, data);
        const tempContainer = $('<div>').html(reportHtml);
        tempContainer.find('.card').removeClass('card').addClass('accordion-item');
        tempContainer.find('.card-header').removeClass('card-header').addClass('accordion-header').wrapInner(button => `<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${$(button).parent().closest('.accordion-item').index()}"></button>`);
        tempContainer.find('.card-body').removeClass('card-body').addClass('accordion-body');
        tempContainer.find('.accordion-header button').each(function () {
            const footerContent = $(this).closest('.accordion-item').find('.card-footer').html();
            $(this).append(`<div class="ms-auto d-flex gap-2">${footerContent}</div>`);
        });
        tempContainer.find('.card-footer').remove();

        tempContainer.children().wrapAll('<div class="accordion" id="invoicesReportAccordion"></div>');
        container.html(tempContainer.html());
    }

    function _generatePersonStatementReport(data, container) {
        const selectedPerson = $('#report-person-select').val();
        if (!selectedPerson) { alert('لطفا یک شخص را انتخاب کنید.'); return; }

        const [personType, personId] = selectedPerson.split('-');
        const startDateStr = toEnglishDigits($('#report-start-date').val()), endDateStr = toEnglishDigits($('#report-end-date').val());
        if (!startDateStr || !endDateStr) { alert('لطفا بازه زمانی را مشخص کنید.'); return; }
        const startUnix = new persianDate(startDateStr.split('/').map(Number)).unix();
        const endUnix = new persianDate(endDateStr.split('/').map(Number)).endOf('day').unix();

        let person, transactions = [], reportTitle = '';

        if (personType === 'customer' || personType === 'supplier') {
            const isCustomer = personType === 'customer';
            person = isCustomer ? data.customers.find(p => p.id == personId) : data.suppliers.find(p => p.id == personId);
            const invoiceList = isCustomer ? data.sales_invoices : data.purchase_invoices;
            reportTitle = `صورتحساب ${isCustomer ? 'مشتری' : 'تامین‌کننده'}: ${person.name}`;

            if (person.initial_balance && parseFloat(person.initial_balance) !== 0) {
                transactions.push({
                    date: 'پیش از دوره',
                    desc: 'مانده اولیه',
                    debit: isCustomer ? parseFloat(person.initial_balance) : 0,
                    credit: !isCustomer ? parseFloat(person.initial_balance) : 0,
                    unix: 0,
                    type: 'balance'
                });
            }

            (invoiceList || []).filter(inv => inv[isCustomer ? 'customerId' : 'supplierId'] == personId).forEach(inv => {
                const invDateUnix = new persianDate(toEnglishDigits(inv.date).split('/').map(Number)).unix();
                const finalAmount = inv.totalAmount - (inv.discount || 0);
                transactions.push({ date: inv.date, desc: `فاکتور ${isCustomer ? 'فروش' : 'خرید'} #${inv.id}`, debit: isCustomer ? finalAmount : 0, credit: !isCustomer ? finalAmount : 0, unix: invDateUnix, type: 'invoice' });
                (inv.payments || []).forEach(p => {
                    transactions.push({ date: p.date, desc: `پرداخت برای فاکتور #${inv.id}`, debit: !isCustomer ? p.amount : 0, credit: isCustomer ? p.amount : 0, unix: new persianDate(toEnglishDigits(p.date).split('/').map(Number)).unix(), type: 'payment' });
                });
            });
        } else { // Partners
            person = data.partners.find(p => p.id == personId);
            reportTitle = `صورتحساب شریک: ${person.name}`;
            (data.partner_transactions || []).filter(t => t.partnerId == personId).forEach(t => {
                transactions.push({ date: t.date, desc: t.description || (t.type === 'DEPOSIT' ? 'واریز' : 'برداشت'), debit: t.type === 'WITHDRAWAL' ? t.amount : 0, credit: t.type === 'DEPOSIT' ? t.amount : 0, unix: new persianDate(toEnglishDigits(t.date).split('/').map(Number)).unix(), type: 'payment' });
            });
        }

        const filteredTransactions = transactions.filter(t => t.unix === 0 || (t.unix >= startUnix && t.unix <= endUnix)).sort((a, b) => a.unix - b.unix);

        let reportHtml = `<h4 class="mb-3">${reportTitle} (از ${startDateStr} تا ${endDateStr})</h4>`;
        if (filteredTransactions.length === 0) { container.html(reportHtml + '<p class="text-center">گردش حسابی در این بازه یافت نشد.</p>'); return; }

        reportHtml += `<table id="person-statement-table" class="table table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>`;
        let balance = 0, totalDebit = 0, totalCredit = 0;
        filteredTransactions.forEach(t => {
            const currentDebit = t.debit || 0;
            const currentCredit = t.credit || 0;
            balance += (personType === 'customer' ? currentDebit : currentCredit) - (personType === 'customer' ? currentCredit : currentDebit);
            totalDebit += currentDebit;
            totalCredit += currentCredit;
            const balanceText = personType === 'customer' ? (balance >= 0 ? '(بدهکار)' : '(بستانکار)') : (balance >= 0 ? '(بستانکار)' : '(بدهکار)');
            reportHtml += `<tr class="tr-type-${t.type}"><td>${t.date}</td><td>${t.desc}</td><td>${formatCurrency(currentDebit)}</td><td>${formatCurrency(currentCredit)}</td><td class="${balance >= 0 ? '' : 'text-danger'}">${formatCurrency(Math.abs(balance))} ${balanceText}</td></tr>`;
        });
        const finalBalanceText = personType === 'customer' ? (balance >= 0 ? '(بدهکار)' : '(بستانکار)') : (balance >= 0 ? '(بستانکار)' : '(بدهکار)');
        reportHtml += `</tbody><tfoot class="fw-bold"><tr class="table-secondary"><td>جمع کل</td><td></td><td>${formatCurrency(totalDebit)}</td><td>${formatCurrency(totalCredit)}</td><td>${formatCurrency(Math.abs(balance))} ${finalBalanceText}</td></tr></tfoot></table>`;
        container.html(reportHtml);
        _initDataTable('#person-statement-table', { "ordering": false });
    }

    async function _generateAccountStatementReport(data, container) {
        const selectedAccountId = $('#report-account-select').val();
        if (!selectedAccountId) { alert('لطفا یک حساب را انتخاب کنید.'); return; }

        const account = data.accounts.find(acc => acc.id == selectedAccountId);
        const startDateStr = toEnglishDigits($('#report-start-date').val()), endDateStr = toEnglishDigits($('#report-end-date').val());
        if (!startDateStr || !endDateStr) { alert('لطفا بازه زمانی را مشخص کنید.'); return; }

        UI.showLoader();
        const transactions = await Api.call('get_account_transactions', { accountId: selectedAccountId });
        UI.hideLoader();

        const startUnix = new persianDate(startDateStr.split('/').map(Number)).unix();
        const endUnix = new persianDate(endDateStr.split('/').map(Number)).endOf('day').unix();
        const inDateRange = (dateStr) => {
            const unix = new persianDate(toEnglishDigits(dateStr).split('/').map(Number)).unix();
            return unix >= startUnix && unix <= endUnix;
        };

        const filteredTransactions = (transactions || []).filter(tx => inDateRange(tx.date));

        let reportHtml = `<h4 class="mb-3">صورتحساب: ${account.name} (از ${startDateStr} تا ${endDateStr})</h4>`;
        if (filteredTransactions.length === 0) {
            container.html(reportHtml + '<p class="text-center">گردش حسابی در این بازه یافت نشد.</p>');
            return;
        }

        reportHtml += `<table id="account-statement-table" class="table table-bordered" style="width:100%"><thead class="table-light"><tr><th class="text-end">تاریخ</th><th class="text-end">شرح</th><th class="text-end text-success">بستانکار (واریز)</th><th class="text-end text-danger">بدهکار (برداشت)</th><th class="text-end">مانده</th></tr></thead><tbody>`;

        let balanceBefore = parseFloat(account.current_balance);
        (transactions || []).filter(tx => new persianDate(toEnglishDigits(tx.date).split('/').map(Number)).unix() >= startUnix).forEach(tx => {
            let bostankar = 0, bedehkar = 0;
            switch (tx.source) {
                case 'payment': bostankar = tx.invoiceType === 'sales' ? tx.amount : 0; bedehkar = tx.invoiceType === 'purchase' ? tx.amount : 0; break;
                case 'expense': bedehkar = tx.amount; break;
                case 'partner': bostankar = tx.type === 'واریز شریک' ? tx.amount : 0; bedehkar = tx.type === 'برداشت شریک' ? tx.amount : 0; break;
                case 'check': bostankar = tx.amount; break;
            }
            balanceBefore -= (bostankar - bedehkar);
        });

        // --- FIX: Correct row structure for DataTable ---
        reportHtml += `<tr class="table-secondary fw-bold">
            <td></td>
            <td>مانده از قبل</td>
            <td></td>
            <td></td>
            <td>${formatCurrency(balanceBefore)}</td>
        </tr>`;

        let runningBalance = balanceBefore;
        filteredTransactions.forEach(tx => {
            let description = tx.description || '';
            let bostankar = 0;
            let bedehkar = 0;

            switch (tx.source) {
                case 'payment': bostankar = tx.invoiceType === 'sales' ? tx.amount : 0; bedehkar = tx.invoiceType === 'purchase' ? tx.amount : 0; description = `فاکتور ${tx.invoiceType === 'sales' ? 'فروش' : 'خرید'} #${tx.invoiceId} - ${description}`; break;
                case 'expense': bedehkar = tx.amount; description = `${tx.type} - ${description}`; break;
                case 'partner': bostankar = tx.type === 'واریز شریک' ? tx.amount : 0; bedehkar = tx.type === 'برداشت شریک' ? tx.amount : 0; description = `${tx.type} - ${description}`; break;
                case 'check': bostankar = tx.amount; break;
            }

            runningBalance += (bostankar - bedehkar);

            reportHtml += `<tr>
                <td>${tx.date}</td>
                <td>${description}</td>
                <td class="text-success">${bostankar !== 0 ? formatCurrency(bostankar) : '-'}</td>
                <td class="text-danger">${bedehkar !== 0 ? `(${formatCurrency(bedehkar)})` : '-'}</td>
                <td class="fw-bold">${formatCurrency(runningBalance)}</td>
            </tr>`;
        });
        reportHtml += `</tbody></table>`;
        container.html(reportHtml);
        _initDataTable('#account-statement-table', { "ordering": false });
    }

    function _generateExpensesReport(data, container) {
        const startDateStr = toEnglishDigits($('#report-start-date').val()), endDateStr = toEnglishDigits($('#report-end-date').val());
        if (!startDateStr || !endDateStr) { alert('لطفا بازه زمانی را مشخص کنید.'); return; }
        const startUnix = new persianDate(startDateStr.split('/').map(Number)).unix();
        const endUnix = new persianDate(endDateStr.split('/').map(Number)).endOf('day').unix();
        const filteredExpenses = (data.expenses || []).filter(exp => { const d = new persianDate(toEnglishDigits(exp.date).split('/').map(Number)).unix(); return d >= startUnix && d <= endUnix; });
        let reportHtml = `<h4 class="mb-3">گزارش هزینه‌ها از ${startDateStr} تا ${endDateStr}</h4>`;
        if (filteredExpenses.length === 0) { container.html(reportHtml + `<p class="text-center">هیچ هزینه‌ای یافت نشد.</p>`); return; }
        reportHtml += `<table id="expenses-report-table" class="table table-striped table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>دسته‌بندی</th><th>مبلغ</th><th>توضیحات</th></tr></thead><tbody>`;
        let totalExpenses = 0;
        filteredExpenses.forEach(exp => { totalExpenses += exp.amount; reportHtml += `<tr><td>${exp.date}</td><td>${exp.category}</td><td>${formatCurrency(exp.amount)}</td><td>${exp.description || '-'}</td></tr>`; });
        reportHtml += `</tbody><tfoot class="fw-bold"><tr><td colspan="2" class="text-end">جمع کل:</td><td colspan="2">${formatCurrency(totalExpenses)}</td></tr></tfoot></table>`;
        container.html(reportHtml);
        _initDataTable('#expenses-report-table');
    }

    function _generateInventoryReport(data, container) {
        let tableHtml = `<h4 class="mb-3">گزارش موجودی انبار</h4><table id="inventory-report-table" class="table table-bordered table-striped" style="width:100%"><thead><tr><th>نام طرح</th><th>ابعاد</th><th>تعداد موجود</th></tr></thead><tbody>`;
        (data.products || []).forEach(p => { (p.stock || []).filter(s => s.quantity > 0).forEach(stockItem => { tableHtml += `<tr><td>${p.name}</td><td>${stockItem.dimensions}</td><td>${stockItem.quantity}</td></tr>`; }); });
        tableHtml += `</tbody></table>`; container.html(tableHtml); _initDataTable('#inventory-report-table');
    }

    function _generateInventoryValueReport(data, container) {
        let tableHtml = `<h4 class="mb-3">گزارش ارزش موجودی انبار</h4><table id="inventory-value-report-table" class="table table-bordered table-striped" style="width:100%"><thead class="table-light"><tr><th>نام محصول</th><th>ابعاد</th><th>موجودی</th><th>آخرین قیمت خرید</th><th>ارزش کل ردیف</th></tr></thead><tbody>`;
        let totalInventoryValue = 0;
        (data.products || []).forEach(p => { if (p.stock && p.stock.length > 0) { p.stock.filter(s => s.quantity > 0).forEach(stockItem => { let lastPrice = 0; const invoicesWithProduct = (data.purchase_invoices || []).filter(inv => inv.items.some(item => item.productId == p.id && item.dimensions == stockItem.dimensions)).sort((a, b) => new persianDate(toEnglishDigits(b.date).split('/').map(Number)).unix() - new persianDate(toEnglishDigits(a.date).split('/').map(Number)).unix()); if (invoicesWithProduct.length > 0) { const lastItem = invoicesWithProduct[0].items.find(item => item.productId == p.id && item.dimensions == stockItem.dimensions); if (lastItem) lastPrice = lastItem.unitPrice; } const rowValue = stockItem.quantity * lastPrice; totalInventoryValue += rowValue; tableHtml += `<tr><td>${p.name}</td><td>${stockItem.dimensions}</td><td>${stockItem.quantity}</td><td>${formatCurrency(lastPrice)}</td><td>${formatCurrency(rowValue)}</td></tr>`; }); } });
        tableHtml += `</tbody><tfoot><tr><th colspan="4" class="text-end">جمع کل ارزش انبار:</th><th>${formatCurrency(totalInventoryValue)}</th></tr></tfoot></table>`; container.html(tableHtml); _initDataTable('#inventory-value-report-table');
    }

    function init(appDependencies) {
        appData = appDependencies;
        $('#report-type').on('change', function () {
            const reportType = $(this).val();
            $('#report-person-controls').toggle(reportType === 'persons');
            $('#report-account-controls').toggle(reportType === 'accounts');
            $('#report-date-filter').toggle(!['inventory', 'inventory-value'].includes(reportType));
        }).trigger('change');
    }

    return {
        init: init,
        generate: generate,
        print: print
    };
})();