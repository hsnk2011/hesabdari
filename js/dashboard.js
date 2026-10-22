// /js/dashboard.js

const Dashboard = (function () {

    function render(data) {
        if (!data) return;

        $('#dashboard-total-sales').text(UI.formatCurrency(data.salesLast30Days));
        
        // New summary widgets
        $('#dashboard-cash-position').text(UI.formatCurrency(data.cashPosition || 0));
        $('#dashboard-total-receivables').text(UI.formatCurrency(data.totalReceivables || 0));
        $('#dashboard-total-payables').text(UI.formatCurrency(data.totalPayables || 0));

        // Operational alerts (low stock, overdue installments)
        const lowStock = parseInt(data.lowStockCount || 0, 10);
        const overdueInst = parseInt(data.overdueInstallmentsCount || 0, 10);
        let hasOperationalAlert = false;
        if (lowStock > 0 && App.hasPermission('manage_products')) {
            $('#dashboard-low-stock-alert').html(`<a href="#" class="alert-link" id="dash-goto-products">${lowStock} محصول زیر حداقل موجودی</a>`).show();
            hasOperationalAlert = true;
        } else {
            $('#dashboard-low-stock-alert').hide();
        }
        if (overdueInst > 0 && App.hasPermission('manage_installments')) {
            $('#dashboard-overdue-installments-alert').html(`<a href="#" class="alert-link" id="dash-goto-installments">${overdueInst} قسط سررسید گذشته</a>`).show();
            hasOperationalAlert = true;
        } else {
            $('#dashboard-overdue-installments-alert').hide();
        }
        $('#dashboard-operational-alerts').toggle(hasOperationalAlert);

        const warehouseHost = $('#dashboard-warehouses');
        const warehouseList = $('#dashboard-warehouses-list');
        const pinned = data.dashboardWarehouses || [];
        if (warehouseHost.length && warehouseList.length && pinned.length && App.hasPermission('manage_products')) {
            warehouseList.empty();
            pinned.forEach(w => {
                const isOrder = w.warehouse_type === 'order';
                const items = w.items || [];
                const count = items.length || parseInt(w.item_count, 10) || 0;
                const rows = items.length
                    ? items.map(item => {
                        const isSold = item.status === 'sold';
                        let stage = isOrder
                            ? '<span class="badge bg-warning text-dark">در انتظار کارخانه — بدون مشتری</span>'
                            : '<span class="badge bg-warning text-dark">در انتظار کارخانه</span>';
                        if (isSold) {
                            stage = '<span class="badge bg-primary">فروخته‌شده، تحویل نشده</span>';
                        } else if (item.status === 'returned') {
                            stage = '<span class="badge bg-info text-dark">مرجوعی — بدون مشتری</span>';
                        }
                        // فقط فرش فروخته‌شده مشتری دارد؛ مرجوعی هنوز لینک فاکتور قبلی را نگه می‌دارد
                        const customer = isSold && item.customer_name
                            ? `${UI.escapeHtml(item.customer_name)}${item.sales_invoice_number ? ' / ' + UI.escapeHtml(item.sales_invoice_number) : (item.sales_invoice_id ? ' / #' + item.sales_invoice_id : '')}`
                            : '-';
                        const deliverBtn = isOrder
                            ? `<button type="button" class="btn btn-sm btn-success py-0 dashboard-deliver-order"
                                data-id="${item.id}"
                                data-sold="${isSold ? 1 : 0}"
                                data-dimensions="${UI.escapeHtml(item.dimensions || '')}"
                                data-serial="${UI.escapeHtml(item.serial_id || '')}"
                                data-name="${UI.escapeHtml(item.product_name || '')}"
                                data-sales-price="${isSold ? UI.escapeHtml(String(item.sales_unit_price ?? '')) : ''}"
                                data-purchase-price="${UI.escapeHtml(String(item.purchase_unit_price ?? ''))}"
                                data-sales-dimensions="${UI.escapeHtml(item.sales_dimensions || item.dimensions || '')}"
                                data-purchase-dimensions="${UI.escapeHtml(item.purchase_dimensions || item.dimensions || '')}"
                                title="${isSold ? 'تحویل به مشتری' : 'تحویل به انبار فیزیکی'}"
                              >${isSold ? 'تحویل' : 'تحویل به انبار'}</button>`
                            : '';
                        return `<tr>
                            <td><code>${UI.escapeHtml(item.serial_id || '-')}</code></td>
                            <td>${UI.escapeHtml(item.product_name || '-')}</td>
                            <td>${UI.escapeHtml(item.design_code || '-')}</td>
                            <td>${UI.escapeHtml(item.colorway || '-')}</td>
                            <td>${UI.escapeHtml(item.dimensions || '-')}</td>
                            <td>${stage}</td>
                            <td>${customer}</td>
                            <td>${deliverBtn}</td>
                        </tr>`;
                    }).join('')
                    : `<tr><td colspan="8" class="text-center text-muted">${isOrder ? 'سفارش بازی در این انبار نیست.' : 'فرشی در این انبار نیست.'}</td></tr>`;
                warehouseList.append(`
                    <div class="border-bottom">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light">
                            <div class="d-flex align-items-center gap-2">
                                <strong>${UI.escapeHtml(w.name)}</strong>
                                <span class="badge ${isOrder ? 'text-bg-primary' : 'text-bg-secondary'}">${isOrder ? 'انبار سفارش' : 'غیر فیزیکی'}</span>
                                <span class="badge text-bg-dark">${count.toLocaleString('fa-IR')} فرش</span>
                            </div>
                            <div class="d-flex gap-2">
                                ${isOrder ? `<button type="button" class="btn btn-sm btn-outline-primary dashboard-import-sold-orders" data-id="${w.id}">افزودن فروش‌های قبلی</button>` : ''}
                                <button type="button" class="btn btn-sm btn-outline-primary dashboard-warehouse-card" data-id="${w.id}">مشاهده در محصولات</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>شناسه</th>
                                        <th>نام فرش</th>
                                        <th>کد طرح</th>
                                        <th>رنگ</th>
                                        <th>ابعاد سفارش</th>
                                        <th>مرحله</th>
                                        <th>مشتری</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>
                `);
            });
            warehouseHost.show();
        } else if (warehouseHost.length) {
            warehouseHost.hide();
            warehouseList.empty();
        }

        const supplierChartCanvas = document.getElementById('topSuppliersChart');
        if (supplierChartCanvas) {
            const existingChart = Chart.getChart("topSuppliersChart");
            if (existingChart) {
                existingChart.destroy();
            }
            if (data.topIndebtedSuppliers && data.topIndebtedSuppliers.length > 0) {
                const labels = data.topIndebtedSuppliers.map(s => s.name);
                const debtValues = data.topIndebtedSuppliers.map(s => parseFloat(s.total_debt) || 0);
                const pendingCheckValues = data.topIndebtedSuppliers.map(s => parseFloat(s.pending_checks) || 0);
                const hasPendingChecks = pendingCheckValues.some(v => v > 0);

                const datasets = [{
                    label: 'بدهی قطعی',
                    data: debtValues,
                    backgroundColor: '#ffc107',
                    borderColor: '#ffc107',
                    borderWidth: 1
                }];

                if (hasPendingChecks) {
                    datasets.push({
                        label: 'چک پاس نشده (بدهی بالقوه)',
                        data: pendingCheckValues,
                        backgroundColor: '#dc3545',
                        borderColor: '#dc3545',
                        borderWidth: 1
                    });
                }

                new Chart(supplierChartCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: datasets
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: hasPendingChecks, position: 'top', labels: { font: { family: 'Vazirmatn', size: 11 } } },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return context.dataset.label + ': ' + UI.formatCurrency(context.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                stacked: true,
                                ticks: {
                                    callback: function (value, index, values) {
                                        if (value >= 1000000) return value / 1000000 + ' M';
                                        if (value >= 1000) return value / 1000 + ' K';
                                        return value;
                                    }
                                }
                            },
                            y: {
                                stacked: true
                            }
                        }
                    }
                });
            }
        }

        const unsettledCustomersBody = $('#dashboard-unsettled-customers');
        unsettledCustomersBody.empty();
        if (data.unsettledCustomers && data.unsettledCustomers.length > 0) {
            data.unsettledCustomers.forEach(c => {
                const row = $(`<tr class="clickable-row" data-report-type="persons" data-person-id="${c.id}" data-person-type="customer" style="cursor: pointer;">
                    <td>${UI.escapeHtml(c.name)}</td>
                    <td class="text-danger fw-bold">${UI.formatCurrency(c.total_debt)}</td>
                </tr>`);
                unsettledCustomersBody.append(row);
            });
        } else {
            unsettledCustomersBody.html('<tr><td colspan="2" class="text-center">مشتری بدهکاری یافت نشد.</td></tr>');
        }

        let receivedHtml = '';
        (data.dueReceivedChecks || []).forEach(chk => {
            const dueDate = UI.gregorianToPersian(chk.dueDate);
            receivedHtml += `<tr class="clickable-row" data-report-type="checks" data-check-number="${UI.escapeHtml(chk.checkNumber)}" style="cursor: pointer;">
                <td>${UI.escapeHtml(chk.checkNumber)}</td>
                <td>${UI.escapeHtml(chk.personName)}</td>
                <td>${UI.formatCurrency(chk.amount)}</td>
                <td>${dueDate}</td>
            </tr>`;
        });
        $('#dashboard-due-received-checks').html(receivedHtml || '<tr><td colspan="4" class="text-center">چکی یافت نشد.</td></tr>');

        let payableHtml = '';
        (data.duePayableChecks || []).forEach(chk => {
            const dueDate = UI.gregorianToPersian(chk.dueDate);
            payableHtml += `<tr class="clickable-row" data-report-type="checks" data-check-number="${UI.escapeHtml(chk.checkNumber)}" style="cursor: pointer;">
                <td>${UI.escapeHtml(chk.checkNumber)}</td>
                <td>${UI.escapeHtml(chk.personName)}</td>
                <td>${UI.formatCurrency(chk.amount)}</td>
                <td>${dueDate}</td>
            </tr>`;
        });
        $('#dashboard-due-payable-checks').html(payableHtml || '<tr><td colspan="4" class="text-center">چکی یافت نشد.</td></tr>');

        // Render preparing orders
        const preparingBody = $('#dashboard-preparing-orders').empty();
        if (data.preparingOrders && data.preparingOrders.length > 0) {
            data.preparingOrders.forEach(order => {
                const persianDate = UI.gregorianToPersian(order.date);
                const amount = UI.formatCurrency(order.totalAmount - (order.discount || 0));
                const isSales = order.invoiceType === 'sales';
                const typeBadge = isSales
                    ? '<span class="badge bg-success">فروش</span>'
                    : '<span class="badge bg-primary">خرید</span>';
                const reportType = isSales ? 'invoice' : 'purchase-invoice';
                preparingBody.append(`
                    <tr class="clickable-row" data-report-type="${reportType}" data-invoice-id="${order.id}" style="cursor: pointer;">
                        <td>${order.id}</td>
                        <td>${typeBadge}</td>
                        <td>${order.personName || '-'}</td>
                        <td>${persianDate}</td>
                        <td>${amount}</td>
                        <td>
                            <button class="btn btn-sm btn-info dashboard-mark-ready" data-id="${order.id}" data-invoice-type="${order.invoiceType}" title="تغییر به آماده تحویل">
                                <i class="bi bi-box-seam"></i>
                            </button>
                            <button class="btn btn-sm btn-success dashboard-mark-delivered" data-id="${order.id}" data-invoice-type="${order.invoiceType}" title="تغییر به تحویل شده">
                                <i class="bi bi-check2-circle"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        } else {
            preparingBody.html('<tr><td colspan="6" class="text-center text-muted">سفارش در حال آماده‌سازی وجود ندارد.</td></tr>');
        }

        // Render ready orders
        const readyBody = $('#dashboard-ready-orders').empty();
        if (data.readyOrders && data.readyOrders.length > 0) {
            data.readyOrders.forEach(order => {
                const persianDate = UI.gregorianToPersian(order.date);
                const amount = UI.formatCurrency(order.totalAmount - (order.discount || 0));
                const isSales = order.invoiceType === 'sales';
                const typeBadge = isSales
                    ? '<span class="badge bg-success">فروش</span>'
                    : '<span class="badge bg-primary">خرید</span>';
                const reportType = isSales ? 'invoice' : 'purchase-invoice';
                readyBody.append(`
                    <tr class="clickable-row" data-report-type="${reportType}" data-invoice-id="${order.id}" style="cursor: pointer;">
                        <td>${order.id}</td>
                        <td>${typeBadge}</td>
                        <td>${order.personName || '-'}</td>
                        <td>${persianDate}</td>
                        <td>${amount}</td>
                        <td>
                            <button class="btn btn-sm btn-success dashboard-mark-delivered" data-id="${order.id}" data-invoice-type="${order.invoiceType}" title="تغییر به تحویل شده">
                                <i class="bi bi-check2-circle"></i> تحویل شد
                            </button>
                        </td>
                    </tr>
                `);
            });
        } else {
            readyBody.html('<tr><td colspan="6" class="text-center text-muted">سفارش آماده تحویل وجود ندارد.</td></tr>');
        }

        const expenseChartCanvas = document.getElementById('expensesPieChart');
        if (expenseChartCanvas) {
            const existingChart = Chart.getChart("expensesPieChart");
            if (existingChart) {
                existingChart.destroy();
            }
            const expenseData = data.expensesByCategory;
            if (expenseData && typeof expenseData === 'object' && Object.keys(expenseData).length > 0) {
                const labels = Object.keys(expenseData);
                const dataValues = Object.values(expenseData);
                new Chart(expenseChartCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'مبلغ هزینه',
                            data: dataValues,
                            backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#8B0000', '#2E8B57', '#B8860B'],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { font: { family: "Vazirmatn" } }
                            }
                        }
                    }
                });
            }
        }
    }

    function renderSmartAlerts(alerts) {
        const container = $('#smart-alerts-container').empty();
        let hasAlerts = false;

        const appendAlertItem = (icon, htmlText, onClick) => {
            const item = $(`
                <a href="#" class="list-group-item list-group-item-action list-group-item-warning">
                    <div class="d-flex w-100 justify-content-between">
                        <p class="mb-1"><i class="bi ${icon} me-2"></i> ${htmlText}</p>
                        <small>مشاهده</small>
                    </div>
                </a>
            `);
            item.on('click', (e) => {
                e.preventDefault();
                onClick();
            });
            container.append(item);
        };

        const alertDefinitions = {
            sold_items_without_purchase: {
                icon: 'bi-exclamation-diamond-fill',
                text: (count) => `<strong>${count} قلم کالا</strong> به عنوان "فروخته شده" ثبت شده‌اند اما سابقه خرید ندارند (مغایرت انبار).`,
                action: () => {
                    if (typeof SmartAuditManager !== 'undefined') {
                        SmartAuditManager.openCenter('inventory_link');
                    }
                }
            },
            account_balance_discrepancy: {
                icon: 'bi-bank2',
                text: (alertData) => `موجودی نهایی <strong>${alertData.count} حساب</strong> با جمع تراکنش‌هایشان مغایرت دارد.`,
                // *** START FIX: The action now receives the alert data and passes it to the audit manager ***
                action: (alertData) => {
                    if (typeof SmartAuditManager !== 'undefined') {
                        SmartAuditManager.openCenter('balance');
                    }
                    setTimeout(() => {
                        if (App.getManager('balance_audit')?.load) {
                            App.getManager('balance_audit').load({
                                mismatchedAccounts: alertData.accounts,
                                message: 'فقط حساب‌های دارای مغایرت (شناسایی شده توسط سیستم) نمایش داده شده‌اند.'
                            });
                        }
                    }, 250);
                }
                // *** END FIX ***
            },
            orphan_invoice_items: {
                icon: 'bi-journal-x',
                text: (count) => `<strong>${count} فاکتور فروش</strong> حاوی ردیف‌هایی هستند که محصول آن‌ها حذف شده (نیاز به بازبینی).`,
                action: () => App.setPendingFilter('sales_invoices', { filterType: 'orphan_items' }, 'نمایش فاکتورهای فروش دارای ردیف ناقص')
            },
            unlinked_inventory_items: {
                icon: 'bi-question-circle-fill',
                text: (count) => `<strong>${count} قلم کالا</strong> در انبار به فاکتور خریدشان متصل نیستند (نیاز به بازبینی).`,
                action: () => {
                    if (typeof SmartAuditManager !== 'undefined') {
                        SmartAuditManager.openCenter('inventory_link');
                    }
                    setTimeout(() => {
                        const mgr = App.getManager('inventory_audit');
                        if (mgr && typeof mgr.setMode === 'function') {
                            mgr.setMode('in_stock');
                            mgr.load();
                        }
                    }, 250);
                }
            },
            inventory_without_purchase_history: {
                icon: 'bi-box-seam-fill',
                text: (count) => `<strong>${count} نوع محصول</strong> در انبار موجود است اما هیچ سابقه خریدی برای آن‌ها ثبت نشده.`,
                action: () => App.setPendingFilter('products', { filterType: 'no_purchase_history' }, 'نمایش کالاهای بدون هیچ سابقه خرید')
            },
            overdue_unsettled_invoices: {
                icon: 'bi-receipt-cutoff',
                text: (count) => `<strong>${count} فاکتور فروش</strong> با عمر بیش از ۳۰ روز تسویه نشده‌اند.`,
                action: () => App.setPendingFilter('sales_invoices', { filterType: 'overdue_unsettled' }, 'نمایش فاکتورهای فروش تسویه نشده (قدیمی)')
            },
            past_due_checks: {
                icon: 'bi-bank',
                text: (count) => `<strong>${count} چک</strong> از سررسیدشان گذشته اما اقدامی روی آن‌ها ثبت نشده.`,
                action: () => App.setPendingFilter('checks', { filterType: 'past_due' }, 'نمایش چک‌های سررسید گذشته')
            },
            negative_total_invoices: {
                icon: 'bi-calculator-fill',
                text: (alertData) => {
                    const count = alertData.count || alertData;
                    return `<strong>${count} فاکتور</strong> با مبلغ نهایی صفر/منفی یا دارای ردیف کالا با قیمت صفر یافت شد (نیاز به بازبینی).`;
                },
                action: (alertData) => {
                    const salesCount = alertData.sales_count || 0;
                    const purchaseCount = alertData.purchase_count || 0;
                    if (salesCount > 0) {
                        App.setPendingFilter('sales_invoices', { filterType: 'negative_total' }, `فاکتورهای فروش با مشکل قیمت‌گذاری (${salesCount} فاکتور)`);
                    } else if (purchaseCount > 0) {
                        App.setPendingFilter('purchase_invoices', { filterType: 'negative_total' }, `فاکتورهای خرید با مشکل قیمت‌گذاری (${purchaseCount} فاکتور)`);
                    }
                }
            },
            missing_serials: {
                icon: 'bi-upc-scan',
                text: (count) => `<strong>${count} ردیف خرید</strong> دارای اختلاف تعداد با آیتم‌های انبار هستند (ثبت سریال ناقص).`,
                action: () => App.setPendingFilter('purchase_invoices', { filterType: 'missing_serials' }, 'نمایش فاکتورهای خرید با سریال‌های ناقص')
            },
            sales_without_serials: {
                icon: 'bi-receipt',
                text: (count) => `<strong>${count} فاکتور فروش</strong> به شناسه‌های انبار متصل نیستند (نیاز به تطبیق انبار).`,
                action: () => App.setPendingFilter('sales_invoices', { filterType: 'sales_without_serials' }, 'نمایش فاکتورهای فروش بدون شناسه انبار')
            },
            sold_without_sales_link: {
                icon: 'bi-exclamation-octagon-fill',
                text: (count) => `<strong>${count} ردیف انبار</strong> وضعیت «فروخته‌شده» دارند ولی به فاکتور فروش وصل نیستند (فروش جعلی/تکراری).`,
                action: () => {
                    if (typeof SmartAuditManager !== 'undefined') SmartAuditManager.openCenter('results');
                    setTimeout(() => SmartAuditManager.runAudit(), 200);
                }
            },
            duplicate_sold_inventory: {
                icon: 'bi-files',
                text: (count) => `<strong>${count} ترکیب کالا/ابعاد</strong> ردیف sold بیشتری از فروش واقعی دارند (نمایش چندباره فروخته‌شده).`,
                action: () => {
                    if (typeof SmartAuditManager !== 'undefined') SmartAuditManager.openCenter('results');
                    setTimeout(() => SmartAuditManager.runAudit(), 200);
                }
            },
            broken_purchase_links: {
                icon: 'bi-link-break',
                text: (count) => `<strong>${count} ردیف انبار</strong> به قلم خرید حذف‌شده اشاره می‌کنند (اتصال شکسته).`,
                action: () => {
                    if (typeof SmartAuditManager !== 'undefined') SmartAuditManager.openCenter('results');
                    setTimeout(() => SmartAuditManager.runAudit(), 200);
                }
            }
        };

        for (const key in alerts) {
            const alertData = alerts[key];
            const count = (typeof alertData === 'object' && alertData !== null) ? alertData.count : alertData;

            if (key === 'negative_total_invoices' && typeof alertData === 'object' && alertData !== null) {
                const salesCount = alertData.sales_count || 0;
                const purchaseCount = alertData.purchase_count || 0;

                if (salesCount > 0) {
                    hasAlerts = true;
                    appendAlertItem(
                        'bi-calculator-fill',
                        `<strong>${salesCount} فاکتور فروش</strong> با مبلغ نهایی صفر/منفی یا ردیف کالا با قیمت صفر یافت شد (نیاز به بازبینی).`,
                        () => App.setPendingFilter('sales_invoices', { filterType: 'negative_total' }, `فاکتورهای فروش با مشکل قیمت‌گذاری (${salesCount} فاکتور)`)
                    );
                }

                if (purchaseCount > 0) {
                    hasAlerts = true;
                    appendAlertItem(
                        'bi-calculator-fill',
                        `<strong>${purchaseCount} فاکتور خرید</strong> با مبلغ نهایی صفر/منفی یا ردیف کالا با قیمت صفر یافت شد (نیاز به بازبینی).`,
                        () => App.setPendingFilter('purchase_invoices', { filterType: 'negative_total' }, `فاکتورهای خرید با مشکل قیمت‌گذاری (${purchaseCount} فاکتور)`)
                    );
                }

                continue;
            }

            if (count > 0 && alertDefinitions[key]) {
                hasAlerts = true;
                const def = alertDefinitions[key];
                appendAlertItem(def.icon, def.text(alertData), () => def.action(alertData));
            }
        }

        if (!hasAlerts) {
            container.append(`
                <div class="list-group-item list-group-item-success">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    داده‌ها در وضعیت مطلوبی قرار دارند و هشدار مهمی یافت نشد.
                </div>
            `);
        }
    }

    function attachEvents() {
        let convertOrderWarehouseId = null;
        let convertOrdersTimer = null;

        async function loadConvertibleOrders() {
            const body = $('#convert-orders-body');
            body.html('<tr><td colspan="7" class="text-center">در حال بارگذاری...</td></tr>');
            const result = await Api.call('list_convertible_orders', {
                search: $('#convert-orders-search').val() || ''
            });
            const items = result?.items || [];
            if (!items.length) {
                body.html('<tr><td colspan="7" class="text-center text-muted">فروش تحویل‌نشده‌ای برای انتقال پیدا نشد.</td></tr>');
                return;
            }
            body.empty();
            items.forEach(item => {
                const invoice = item.invoice_number || ('#' + item.sales_invoice_id);
                body.append(`<tr>
                    <td><input type="checkbox" class="convert-order-checkbox" value="${item.id}"></td>
                    <td><code>${UI.escapeHtml(item.serial_id || '-')}</code></td>
                    <td>${UI.escapeHtml(item.product_name || '-')}</td>
                    <td>${UI.escapeHtml(item.dimensions || '-')}</td>
                    <td>${UI.escapeHtml(item.customer_name || '-')}</td>
                    <td>${UI.escapeHtml(invoice)}</td>
                    <td>${UI.escapeHtml(item.warehouse_name || 'بدون انبار')}</td>
                </tr>`);
            });
        }

        $('body').on('click', '.dashboard-import-sold-orders', async function () {
            convertOrderWarehouseId = $(this).data('id');
            $('#convert-orders-search').val('');
            $('#convert-orders-select-all').prop('checked', false);
            $('#convertSoldOrdersModal').modal('show');
            await loadConvertibleOrders();
        });

        $('body').on('input', '#convert-orders-search', function () {
            clearTimeout(convertOrdersTimer);
            convertOrdersTimer = setTimeout(loadConvertibleOrders, 300);
        });

        $('body').on('change', '#convert-orders-select-all', function () {
            $('.convert-order-checkbox').prop('checked', $(this).is(':checked'));
        });

        $('body').on('click', '#convert-orders-assign-btn', async function () {
            const itemIds = [];
            $('.convert-order-checkbox:checked').each(function () {
                itemIds.push(parseInt($(this).val(), 10));
            });
            if (!convertOrderWarehouseId || !itemIds.length) {
                UI.showError('حداقل یک فرش را انتخاب کنید.');
                return;
            }
            const result = await Api.call('assign_sold_to_order_warehouse', {
                warehouse_id: convertOrderWarehouseId,
                item_ids: itemIds
            });
            if (result?.success) {
                UI.showSuccess(`${result.moved} فرش به انبار سفارش منتقل شد.`);
                $('#convertSoldOrdersModal').modal('hide');
                Dashboard.load();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        let deliverOrderContext = null;

        function updateDeliverOrderPreview() {
            if (!deliverOrderContext) return;
            const newDims = String($('#deliver-order-dimensions').val() || '').trim();
            const invOldDims = deliverOrderContext.dimensions || '';
            const salesOldDims = deliverOrderContext.salesDimensions || invOldDims;
            const purchaseOldDims = deliverOrderContext.purchaseDimensions || invOldDims;
            const invOldArea = UI.dimensionArea(invOldDims);
            const newArea = UI.dimensionArea(newDims);
            $('#deliver-preview-area-old').text(invOldArea > 0 ? invOldArea.toFixed(2) + ' م²' : '—');
            $('#deliver-preview-area-new').text(newArea > 0 ? newArea.toFixed(2) + ' م²' : '—');

            const salesOldArea = UI.dimensionArea(salesOldDims);
            const purchaseOldArea = UI.dimensionArea(purchaseOldDims);
            const salesScale = (salesOldArea > 0 && newArea > 0) ? (newArea / salesOldArea) : 1;
            const purchaseScale = (purchaseOldArea > 0 && newArea > 0) ? (newArea / purchaseOldArea) : 1;
            const salesOld = parseFloat(deliverOrderContext.salesPrice);
            const purchaseOld = parseFloat(deliverOrderContext.purchasePrice);

            if (!isNaN(salesOld) && salesOld >= 0) {
                const salesNew = UI.roundMoney(salesOld * salesScale);
                $('#deliver-preview-sales-old').text(UI.formatCurrency(salesOld));
                $('#deliver-preview-sales-new').text(UI.formatCurrency(salesNew));
            } else {
                $('#deliver-preview-sales-old, #deliver-preview-sales-new').text('—');
            }
            if (!isNaN(purchaseOld) && purchaseOld >= 0) {
                const purchaseNew = UI.roundMoney(purchaseOld * purchaseScale);
                $('#deliver-preview-purchase-old').text(UI.formatCurrency(purchaseOld));
                $('#deliver-preview-purchase-new').text(UI.formatCurrency(purchaseNew));
            } else {
                $('#deliver-preview-purchase-old, #deliver-preview-purchase-new').text('—');
            }

            const err = $('#deliver-order-error');
            if (newDims && newDims !== invOldDims && (!(invOldArea > 0) || !(newArea > 0))) {
                err.text('متراژ ابعاد قابل محاسبه نیست. فرمت را بررسی کنید (مثلاً 2x3).').removeClass('d-none');
            } else {
                err.addClass('d-none').text('');
            }
        }

        $('body').on('click', '.dashboard-deliver-order', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            deliverOrderContext = {
                id: $btn.data('id'),
                dimensions: $btn.attr('data-dimensions') || '',
                serial: $btn.attr('data-serial') || '',
                name: $btn.attr('data-name') || 'فرش',
                salesPrice: $btn.attr('data-sales-price') || '',
                purchasePrice: $btn.attr('data-purchase-price') || '',
                salesDimensions: $btn.attr('data-sales-dimensions') || '',
                purchaseDimensions: $btn.attr('data-purchase-dimensions') || '',
                isSold: String($btn.attr('data-sold')) === '1'
            };
            // بدون مشتری (فروش حذف/لغو یا مرجوع شده) → فرش وارد انبار فیزیکی می‌شود
            $('#deliver-order-sales-row').toggle(deliverOrderContext.isSold);
            $('#deliver-order-target-wrap').toggleClass('d-none', deliverOrderContext.isSold);
            $('#deliver-order-mode-note').text(deliverOrderContext.isSold
                ? 'فرش به مشتری تحویل و از انبار سفارش خارج می‌شود.'
                : 'این فرش مشتری ندارد (فروش حذف/لغو یا مرجوع شده). با تحویل، وارد انبار انتخاب‌شده می‌شود و فقط فاکتور خرید اصلاح می‌گردد.');
            if (!deliverOrderContext.isSold) {
                loadDeliverTargetWarehouses();
            }
            $('#deliver-order-product-name').text('فرش: ' + deliverOrderContext.name);
            $('#deliver-order-dimensions').val(deliverOrderContext.dimensions);
            $('#deliver-order-serial').val(deliverOrderContext.serial);
            $('#deliver-order-error').addClass('d-none').text('');
            updateDeliverOrderPreview();
            const modalEl = document.getElementById('deliverOrderModal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });

        async function loadDeliverTargetWarehouses() {
            const select = $('#deliver-order-target-warehouse').empty()
                .append('<option value="">در حال بارگذاری...</option>');
            const result = await Api.call('get_warehouses');
            const physical = (result?.warehouses || []).filter(w => w.warehouse_type !== 'order' && String(w.is_active) === '1');
            select.empty();
            if (!physical.length) {
                select.append('<option value="">انبار فیزیکی فعالی وجود ندارد</option>');
                return;
            }
            physical.forEach(w => {
                select.append(`<option value="${w.id}">${UI.escapeHtml(w.name)}${String(w.is_default) === '1' ? ' (پیش‌فرض)' : ''}</option>`);
            });
            const def = physical.find(w => String(w.is_default) === '1') || physical[0];
            select.val(String(def.id));
        }

        $('body').on('input', '#deliver-order-dimensions', function () {
            updateDeliverOrderPreview();
        });

        $('body').on('click', '#deliver-order-confirm-btn', async function () {
            if (!deliverOrderContext?.id) return;
            const dimensions = String($('#deliver-order-dimensions').val() || '').trim();
            const serialId = String($('#deliver-order-serial').val() || '').trim();
            if (!dimensions) {
                $('#deliver-order-error').text('ابعاد تحویل را وارد کنید.').removeClass('d-none');
                return;
            }
            if (!serialId) {
                $('#deliver-order-error').text('شناسه انبار را وارد کنید.').removeClass('d-none');
                return;
            }
            const oldArea = UI.dimensionArea(deliverOrderContext.dimensions || '');
            const newArea = UI.dimensionArea(dimensions);
            if (dimensions !== deliverOrderContext.dimensions && (!(oldArea > 0) || !(newArea > 0))) {
                $('#deliver-order-error').text('متراژ ابعاد قابل محاسبه نیست.').removeClass('d-none');
                return;
            }
            const $btn = $(this).prop('disabled', true);
            try {
                const payload = { id: deliverOrderContext.id, dimensions, serial_id: serialId };
                if (!deliverOrderContext.isSold) {
                    const target = parseInt($('#deliver-order-target-warehouse').val(), 10);
                    if (!target) {
                        $('#deliver-order-error').text('انبار مقصد را انتخاب کنید.').removeClass('d-none');
                        return;
                    }
                    payload.target_warehouse_id = target;
                }
                const result = await Api.call('deliver_order_items', { items: [payload] });
                if (result?.success) {
                    UI.showSuccess(deliverOrderContext.isSold
                        ? 'تحویل ثبت شد؛ ابعاد، شناسه و قیمت فاکتورها به‌روز شد.'
                        : 'فرش وارد انبار شد؛ ابعاد، شناسه و قیمت فاکتور خرید به‌روز شد.');
                    const modalEl = document.getElementById('deliverOrderModal');
                    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    deliverOrderContext = null;
                    Dashboard.load();
                } else if (result?.error) {
                    $('#deliver-order-error').text(result.error).removeClass('d-none');
                }
            } finally {
                $btn.prop('disabled', false);
            }
        });

        $('body').on('click', '.dashboard-warehouse-card', function () {
            const warehouseId = String($(this).data('id') || '');
            if (!warehouseId) return;
            const state = AppConfig.TABLE_STATES.products;
            state.warehouseId = warehouseId;
            state.stockFilter = 'in_stock';
            state.currentPage = 1;
            const tab = document.querySelector('#products-tab');
            if (tab) new bootstrap.Tab(tab).show();
        });

        $('body').on('click', '#dash-goto-products', function (e) {
            e.preventDefault();
            const tab = document.querySelector('#products-tab');
            if (tab) new bootstrap.Tab(tab).show();
        });

        $('body').on('click', '#dash-goto-installments', function (e) {
            e.preventDefault();
            const toolsTab = document.querySelector('#tools-tab');
            if (toolsTab) new bootstrap.Tab(toolsTab).show();
            setTimeout(() => {
                const instTab = document.querySelector('#installments-tab');
                if (instTab) new bootstrap.Tab(instTab).show();
            }, 150);
        });

        // Quick-add buttons
        $('body').on('click', '.quick-add-btn', function () {
            const action = $(this).data('action');
            switch (action) {
                case 'sales':
                    const salesTab = document.querySelector('#sales-invoices-tab');
                    if (salesTab) { new bootstrap.Tab(salesTab).show(); }
                    setTimeout(() => { App.getManager('invoices')?.prepareInvoiceModal('sales', null); }, 200);
                    break;
                case 'purchase':
                    const purchaseTab = document.querySelector('#purchase-invoices-tab');
                    if (purchaseTab) { new bootstrap.Tab(purchaseTab).show(); }
                    setTimeout(() => { App.getManager('invoices')?.prepareInvoiceModal('purchase', null); }, 200);
                    break;
                case 'expense':
                    const expenseTab = document.querySelector('#transactions-tab');
                    if (expenseTab) { new bootstrap.Tab(expenseTab).show(); }
                    break;
                case 'payment':
                    const paymentTab = document.querySelector('#transactions-tab');
                    if (paymentTab) { new bootstrap.Tab(paymentTab).show(); }
                    break;
            }
        });

        // Dashboard delivery status quick-action buttons
        $('body').on('click', '.dashboard-mark-ready', async function (e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const invoiceType = $(this).data('invoice-type') || 'sales';
            const result = await Api.call('update_delivery_status', { type: invoiceType, id, delivery_status: 'ready' });
            if (result?.success) {
                UI.showSuccess(result.message);
                load();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        $('body').on('click', '.dashboard-mark-delivered', async function (e) {
            e.stopPropagation();
            const id = $(this).data('id');
            const invoiceType = $(this).data('invoice-type') || 'sales';
            const result = await Api.call('update_delivery_status', { type: invoiceType, id, delivery_status: 'delivered' });
            if (result?.success) {
                UI.showSuccess(result.message);
                load();
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });
    }

    async function load() {
        UI.showLoader();

        // 1. Load Main Dashboard Data (Fast)
        try {
            const data = await Api.call('get_dashboard_data');
            if (data) render(data);
        } catch (error) {
            console.error('Error loading dashboard main data:', error);
            UI.showError('خطا در بارگذاری اطلاعات اصلی داشبورد.');
        } finally {
            UI.hideLoader();
        }

        // 2. Load Smart Alerts (Slower - Background)
        loadSmartAlerts();
    }

    async function loadSmartAlerts() {
        const container = $('#smart-alerts-container');
        // Show local spinner for alerts
        container.html(`
            <div class="list-group-item">
                <div class="d-flex align-items-center">
                    <strong>در حال بررسی هوشمند سیستم...</strong>
                    <div class="spinner-border spinner-border-sm ms-auto" role="status" aria-hidden="true"></div>
                </div>
            </div>
        `);

        try {
            const alerts = await Api.call('get_smart_alerts');
            if (alerts) renderSmartAlerts(alerts);
        } catch (error) {
            console.error('Error loading smart alerts:', error);
            container.html(`
                <div class="list-group-item list-group-item-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    خطا در بارگذاری هشدارهای هوشمند.
                    <button class="btn btn-sm btn-outline-danger float-end" onclick="Dashboard.loadSmartAlerts()">تلاش مجدد</button>
                </div>
            `);
        }
    }

    return {
        init: attachEvents,
        load: load,
        loadSmartAlerts: loadSmartAlerts
    };
})();