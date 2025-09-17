// /js/invoice-manager.js

const InvoiceManager = (function () {

    // --- Private Helper Functions for Rendering ---
    function _renderInvoiceRow(inv, type) {
        const isSettled = inv.remainingAmount < 0.01;
        const rowStatusClass = isSettled ? 'status-settled' : 'status-unsettled';
        const badgeStatusClass = isSettled ? 'bg-success' : 'bg-warning';
        const statusText = isSettled ? 'تسویه شده' : 'تسویه نشده';
        const personName = type === 'sales' ? inv.customerName : inv.supplierName;
        const entityType = type === 'sales' ? 'salesInvoice' : 'purchaseInvoice';
        const persianDateStr = UI.gregorianToPersian(inv.date);
        const row = $(`<tr class="${rowStatusClass}">
            <td>${inv.id}</td>
            <td>${persianDateStr}</td>
            <td>${personName || ''}</td>
            <td>${UI.formatCurrency(inv.totalAmount)}</td>
            <td class="text-info">${UI.formatCurrency(inv.discount)}</td>
            <td>${UI.formatCurrency(inv.paidAmount)}</td>
            <td class="fw-bold">${UI.formatCurrency(inv.remainingAmount)}</td>
            <td><span class="badge ${badgeStatusClass}">${statusText}</span></td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-secondary btn-to-consignment" title="ارسال به امانی" data-type="${type}" data-id="${inv.id}"><i class="bi bi-truck"></i></button>
                    <button class="btn btn-sm btn-info btn-print" title="چاپ" data-type="${type}"><i class="bi bi-printer"></i></button>
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" data-type="${entityType}"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-danger btn-delete" title="حذف" data-type="${entityType}" data-id="${inv.id}"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        row.find('.btn-edit, .btn-print').data('entity', inv);
        return row;
    }

    function _renderConsignmentRow(inv, type) {
        const statusClass = inv.remainingAmount == 0 ? 'bg-success' : 'bg-warning';
        const statusText = inv.remainingAmount == 0 ? 'تسویه شده' : 'تسویه نشده';
        const personName = type === 'sales' ? inv.customerName : inv.supplierName;
        const entityType = type === 'sales' ? 'salesInvoice' : 'purchaseInvoice';
        const persianDateStr = UI.gregorianToPersian(inv.date);
        const row = $(`<tr>
            <td>${inv.id}</td>
            <td>${persianDateStr}</td>
            <td>${personName || ''}</td>
            <td>${UI.formatCurrency(inv.totalAmount)}</td>
            <td>${UI.formatCurrency(inv.discount || 0)}</td>
            <td><span class="badge ${statusClass}">${statusText}</span></td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-info btn-print" title="چاپ" data-type="${type}"><i class="bi bi-printer"></i></button> 
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" data-type="${entityType}"><i class="bi bi-pencil-square"></i></button> 
                    <button class="btn btn-sm btn-success btn-from-consignment" title="بازگردانی از امانی" data-type="${type}" data-id="${inv.id}"><i class="bi bi-arrow-return-left"></i></button> 
                    <button class="btn btn-sm btn-danger btn-delete" title="حذف" data-type="${entityType}" data-id="${inv.id}"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        row.find('.btn-edit, .btn-print').data('entity', inv);
        return row;
    }

    const renderers = {
        sales_invoices: (data) => {
            const body = $('#sales-invoices-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="9" class="text-center">فاکتور فروشی یافت نشد.</td></tr>');
            data.forEach(inv => body.append(_renderInvoiceRow(inv, 'sales')));
        },
        purchase_invoices: (data) => {
            const body = $('#purchase-invoices-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="9" class="text-center">فاکتور خریدی یافت نشد.</td></tr>');
            data.forEach(inv => body.append(_renderInvoiceRow(inv, 'purchase')));
        },
        consignment_sales: (data) => {
            const body = $('#consignment-sales-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="7" class="text-center">فاکتور امانی (فروش) یافت نشد.</td></tr>');
            data.forEach(inv => body.append(_renderConsignmentRow(inv, 'sales')));
        },
        consignment_purchases: (data) => {
            const body = $('#consignment-purchases-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="7" class="text-center">فاکتور امانی (خرید) یافت نشد.</td></tr>');
            data.forEach(inv => body.append(_renderConsignmentRow(inv, 'purchase')));
        }
    };

    function calculateInvoiceItemTotal(row) {
        const quantity = parseInt(row.find('.quantity').val()) || 0;
        const unitPrice = parseInt((row.find('.unit-price').val() || '').replace(/,/g, '')) || 0;
        row.find('.item-total').val(UI.formatCurrency(quantity * unitPrice));
    }

    function calculateInvoiceTotals(type) {
        const itemsContainer = $(`#${type}-invoice-items-container`);
        const paymentsContainer = $(`#${type}-invoice-payments-container`);
        let total = 0, totalQuantity = 0, totalArea = 0;

        itemsContainer.find('.dynamic-row').each(function () {
            const unitPrice = Number(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;
            const quantity = Number($(this).find('.quantity').val()) || 0;
            total += unitPrice * quantity;
            totalQuantity += quantity;
            let area = 0;

            let dims = $(this).find('.dimensions-select').val();
            if (dims === 'custom') {
                dims = ($(this).find('.custom-dimension-input').val() || '').trim();
            }

            if (dims) {
                const parts = dims.toLowerCase().replace('*', 'x').split('x');
                if (parts.length === 2) {
                    let width = parseFloat(parts[0]);
                    let height = parseFloat(parts[1]);
                    if (!isNaN(width) && !isNaN(height)) {
                        const CM_THRESHOLD = 15;
                        if (width > CM_THRESHOLD || height > CM_THRESHOLD) {
                            width /= 100;
                            height /= 100;
                        }
                        area = width * height;
                    }
                }
            }
            totalArea += area * quantity;
        });

        const discount = Number(($(`#${type}-invoice-discount`).val() || '').replace(/,/g, '')) || 0;
        let paid = 0;
        paymentsContainer.find('.dynamic-row').each(function () {
            paid += Number(($(this).find('.payment-amount').val() || '').replace(/,/g, '')) || 0;
        });

        const finalAmount = total - discount;
        $(`#${type}-invoice-total-quantity`).text(totalQuantity.toLocaleString('fa-IR'));
        $(`#${type}-invoice-total-area`).text(`${totalArea.toFixed(2).toLocaleString('fa-IR')} متر مربع`);
        $(`#${type}-invoice-total`).text(UI.formatCurrency(total));
        $(`#${type}-invoice-paid`).text(UI.formatCurrency(paid));
        $(`#${type}-invoice-remaining`).text(UI.formatCurrency(finalAmount - paid));
    }

    function addInvoiceItemRow(containerSelector, item = null) {
        const isSales = containerSelector.attr('id').includes('sales');
        const modalId = isSales ? '#salesInvoiceModal' : '#purchaseInvoiceModal';
        const appCache = App.getCache();
        let productList = appCache.products || [];

        const originalInvoiceItems = $(containerSelector).closest('form').data('original-items') || [];

        if (isSales) {
            productList = appCache.products.filter(p => {
                const hasStock = p.stock && p.stock.some(s => s.quantity > 0);
                const isOnThisInvoice = item && p.id == item.productId;
                return hasStock || isOnThisInvoice;
            });
        }

        let productOptions = productList.map(p => `<option value="${p.id}">${p.name}</option>`).join('');

        const newProductButtonHtml = isSales ? '' : `<button class="btn btn-outline-success btn-add-new-product-inline" type="button" title="افزودن محصول جدید"><i class="bi bi-plus-lg"></i></button>`;
        const productSelectionHtml = `<div class="input-group">
                                        <select class="form-select product-select"><option></option>${productOptions}</select>
                                        ${newProductButtonHtml}
                                      </div>
                                      <input type="text" class="form-control new-product-name mt-1" style="display:none;" placeholder="نام محصول جدید...">`;

        const row = $(`<div class="row dynamic-row align-items-end mb-2">
            <div class="col-md-4 product-selection-container"><label class="form-label">طرح فرش</label>${productSelectionHtml}</div>
            <div class="col-md-2 dimensions-container"><label class="form-label">ابعاد</label><select class="form-select dimensions-select" disabled><option value="">ابتدا طرح را انتخاب کنید</option></select></div>
            <div class="col-md-1"><label class="form-label">تعداد</label><input type="number" class="form-control quantity" value="1" min="1"></div>
            <div class="col-md-2"><label class="form-label">قیمت واحد</label><input type="text" class="form-control unit-price numeric-input" value="0"></div>
            <div class="col-md-2"><label class="form-label">جمع</label><input type="text" class="form-control item-total" readonly></div>
            <div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-item-btn"><i class="bi bi-x-lg"></i></button></div>
        </div>`);

        containerSelector.append(row);

        row.find('.product-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "جستجو و انتخاب محصول...",
            dropdownParent: $(modalId)
        });

        if (item) {
            row.find('.product-select').val(item.productId).trigger('change');
            populateDimensions(row.find('.product-select'), item.dimensions);
            row.find('.quantity').val(item.quantity);
            row.find('.unit-price').val(Number(item.unitPrice).toLocaleString('en-US'));
            calculateInvoiceItemTotal(row);
        }
    }

    function populateDimensions(productSelect, preselectedDimension = null) {
        const productId = productSelect.val();
        const row = productSelect.closest('.dynamic-row');
        const dimensionsSelect = row.find('.dimensions-select');
        const dimensionsContainer = row.find('.dimensions-container');
        const isSales = productSelect.closest('form').attr('id').includes('sales');

        dimensionsSelect.empty().prop('disabled', true);
        dimensionsContainer.find('.custom-dimension-wrapper').remove();

        if (!productId) {
            dimensionsSelect.append('<option>ابتدا طرح را انتخاب کنید</option>');
            return;
        }

        dimensionsSelect.prop('disabled', false).append('<option value="">انتخاب کنید...</option>');

        if (!isSales) {
            AppConfig.STANDARD_CARPET_DIMENSIONS.forEach(dim => dimensionsSelect.append(`<option value="${dim}">${dim}</option>`));
            dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');
            if (preselectedDimension) {
                if (AppConfig.STANDARD_CARPET_DIMENSIONS.includes(preselectedDimension)) {
                    dimensionsSelect.val(preselectedDimension);
                } else {
                    dimensionsSelect.val('custom');
                    dimensionsContainer.append(`<div class="mt-2 custom-dimension-wrapper"><input type="text" class="form-control custom-dimension-input" value="${preselectedDimension}"></div>`);
                }
            }
        } else { // Sales
            const product = App.getCache().products.find(p => p.id == productId);
            const originalInvoiceItems = productSelect.closest('form').data('original-items') || [];

            if (product && product.stock) {
                product.stock.forEach(s => {
                    const originalItem = originalInvoiceItems.find(it => it.productId == productId && it.dimensions === s.dimensions);
                    const originalQty = originalItem ? originalItem.quantity : 0;
                    const effectiveStock = s.quantity + originalQty;

                    if (effectiveStock > 0 || s.dimensions === preselectedDimension) {
                        dimensionsSelect.append(`<option value="${s.dimensions}">${s.dimensions} (موجودی: ${s.quantity})</option>`);
                    }
                });
            }
            if (preselectedDimension) dimensionsSelect.val(preselectedDimension);
        }
    }

    function getNewCheckDetailsHtml(check = null) {
        const dueDate = check && check.dueDate ? UI.gregorianToPersian(check.dueDate) : UI.today();
        return `<div class="row g-2">
            <div class="col-4"><label class="form-label-sm">شماره چک</label><input type="text" class="form-control form-control-sm check-number" value="${check ? check.checkNumber : ''}"></div>
            <div class="col-4"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker check-due-date" value="${dueDate}"></div>
            <div class="col-4"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm check-bank-name" value="${check ? check.bankName : ''}"></div>
        </div>`;
    }

    function getEndorseCheckHtml(checkId = null) {
        const availableChecks = (App.getCache().checks || []).filter(c => c.type === 'received' && (c.status === 'in_hand' || c.id == checkId));
        if (availableChecks.length === 0 && !checkId) {
            return '<div><p class="text-danger small mt-2">چک قابل واگذاری وجود ندارد.</p></div>';
        }
        const options = availableChecks.map(c => `<option value="${c.id}" data-amount="${c.amount}" ${c.id == checkId ? 'selected' : ''}>${c.checkNumber} - ${UI.formatCurrency(c.amount)}</option>`).join('');
        return `<div><label class="form-label-sm">انتخاب چک</label><select class="form-select form-select-sm endorsed-check-select"><option value="">-- انتخاب کنید --</option>${options}</select></div>`;
    }

    function addPaymentRow(containerSelector, invoiceType, payment = null) {
        const checkOptionHtml = invoiceType === 'purchase' ? '<option value="endorse_check">خرج چک دریافتی</option>' : '';
        const rowHtml = `<div class="row dynamic-row payment-row align-items-end mb-2">
            <div class="col-md-2"><label class="form-label">نوع پرداخت</label><select class="form-select payment-type"><option value="cash">نقد</option><option value="check">چک جدید</option>${checkOptionHtml}</select></div>
            <div class="col-md-2"><label class="form-label">مبلغ</label><input type="text" class="form-control payment-amount numeric-input" value="0"></div>
            <div class="col-md-2"><label class="form-label">تاریخ</label><input type="text" class="form-control persian-datepicker payment-date"></div>
            <div class="col-md-5 payment-details-container"></div>
            <div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-payment-btn"><i class="bi bi-x-lg"></i></button></div>
        </div>`;
        const row = $(rowHtml);
        const paymentTypeSelect = row.find('.payment-type');
        const paymentAmountInput = row.find('.payment-amount');
        const paymentDateInput = row.find('.payment-date');
        const detailsContainer = row.find('.payment-details-container');

        function updateDetails(type, details) {
            detailsContainer.empty();
            if (type === 'check') {
                detailsContainer.html(getNewCheckDetailsHtml(details ? details.checkDetails : null));
                paymentAmountInput.prop('readonly', false);
                if (details && details.checkDetails) paymentAmountInput.val(details.amount);
            } else if (type === 'endorse_check') {
                detailsContainer.html(getEndorseCheckHtml(details ? details.checkId : null));
                paymentAmountInput.prop('readonly', true);
                if (details) {
                    paymentAmountInput.val(details.amount);
                    detailsContainer.find('.endorsed-check-select').val(details.checkId);
                } else {
                    const select = detailsContainer.find('.endorsed-check-select');
                    if (select.length > 0 && select.find('option').length > 1) {
                        select.trigger('change');
                    } else {
                        paymentAmountInput.val(0);
                    }
                }
            } else { // cash
                let accountOptions = App.getCache().accounts.map(acc => `<option value="${acc.id}">${acc.name}</option>`).join('');
                const accountLabel = invoiceType === 'sales' ? 'واریز به حساب' : 'پرداخت از حساب';
                detailsContainer.html(`
                    <div class="row g-2">
                        <div class="col-7"><label class="form-label-sm">${accountLabel}</label><select class="form-select form-select-sm payment-account-id" required>${accountOptions}</select></div>
                        <div class="col-5"><label class="form-label-sm">توضیحات</label><input type="text" class="form-control form-control-sm payment-description"></div>
                    </div>
                `);
                paymentAmountInput.prop('readonly', false);
                if (details) {
                    detailsContainer.find('.payment-description').val(details.description);
                    detailsContainer.find('.payment-account-id').val(details.account_id);
                }
            }
            UI.initializeDatepickers();
        }

        if (payment) {
            paymentTypeSelect.val(payment.type);
            paymentAmountInput.val(Number(payment.amount).toLocaleString('en-US'));
            paymentDateInput.val(payment.date);
            updateDetails(payment.type, payment);
        } else {
            paymentDateInput.val(UI.today());
            updateDetails('cash', null);
        }
        $(containerSelector).append(row);
        UI.initializeDatepickers();
    }

    function _populateInvoiceHeader(type, formId, invoice = null) {
        const personType = type === 'sales' ? 'customer' : 'supplier';
        const personSelect = $(`${formId} #${type}-invoice-${personType}`);

        if (invoice) {
            $(`${formId} #${type}-invoice-id`).val(invoice.id);
            $(`${formId} #${type}-invoice-date`).val(UI.gregorianToPersian(invoice.date));
            $(`${formId} #${type}-invoice-description`).val(invoice.description);
            $(`${formId} #${type}-invoice-discount`).val(Number(invoice.discount || 0).toLocaleString('en-US'));
        } else {
            $(`${formId} #${type}-invoice-id`).val('');
            $(`${formId} #${type}-invoice-date`).val(UI.today());
            $(`${formId} #${type}-invoice-discount`).val('0');
        }

        personSelect.empty().append('<option></option>');
        const personList = type === 'sales' ? App.getCache().customers : App.getCache().suppliers;
        (personList || []).forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));
        if (invoice) {
            personSelect.val(type === 'sales' ? invoice.customerId : invoice.supplierId);
        }
        personSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: `انتخاب ${personType === 'customer' ? 'مشتری' : 'تأمین‌کننده'}...`,
            dropdownParent: $(`#${type}InvoiceModal`)
        });
        if (invoice) personSelect.trigger('change');
    }

    function _populateInvoiceItems(type, invoice = null) {
        const itemsContainer = $(`#${type}-invoice-items-container`);
        if (invoice && invoice.items && invoice.items.length > 0) {
            invoice.items.forEach(item => addInvoiceItemRow(itemsContainer, item));
        }
    }

    function _populateInvoicePayments(type, invoice = null) {
        const paymentsContainer = $(`#${type}-invoice-payments-container`);
        if (invoice && invoice.payments) {
            invoice.payments.forEach(payment => {
                const pDate = UI.gregorianToPersian(payment.date);
                addPaymentRow(paymentsContainer, type, { ...payment, date: pDate });
            });
        }
    }

    function _resetModal(modalId, formId, type) {
        UI.hideModalError(modalId);
        $(`${formId} .select2-hidden-accessible`).select2('destroy');
        $(formId)[0].reset();
        $(formId).removeData('original-items');
        $(`#${type}-invoice-items-container`).empty();
        $(`#${type}-invoice-payments-container`).empty();
    }

    async function load(tableName) {
        UI.showLoader();
        const state = AppConfig.TABLE_STATES[tableName];
        state.tableName = tableName;
        const response = await Api.call('get_paginated_data', state);
        if (response && renderers[tableName]) {
            renderers[tableName](response.data);
            UI.renderPagination(tableName, response.totalRecords, state);
            UI.updateSortIndicators(tableName, state);
        }
        UI.hideLoader();
    }

    function prepareInvoiceModal(type, invoice = null) {
        const modalId = `#${type}InvoiceModal`;
        const formId = `#${type}-invoice-form`;

        _resetModal(modalId, formId, type);

        if (invoice && invoice.items) {
            $(formId).data('original-items', invoice.items);
        }

        _populateInvoiceHeader(type, formId, invoice);
        _populateInvoiceItems(type, invoice);
        _populateInvoicePayments(type, invoice);

        calculateInvoiceTotals(type);
        $(modalId).modal('show');
    }

    async function saveInvoice(type) {
        const modalId = `#${type}InvoiceModal`;
        UI.hideModalError(modalId);
        const form = $(`#${type}-invoice-form`)[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const items = [];
        $(`#${type}-invoice-items-container .dynamic-row`).each(function () {
            let item = {};
            const pid = $(this).find('.product-select').val();
            const newProdName = $(this).find('.new-product-name').val()?.trim();

            if (type === 'purchase' && newProdName) {
                item.newProductName = newProdName;
            } else if (pid) {
                item.productId = parseInt(pid);
            } else {
                return; // skip if neither is provided
            }

            let dim = $(this).find('.dimensions-select').val();
            if (dim === 'custom') {
                dim = ($(this).find('.custom-dimension-input').val() || '').trim();
            }
            item.dimensions = dim;
            item.quantity = parseInt($(this).find('.quantity').val()) || 0;
            item.unitPrice = parseInt(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;

            if ((!item.productId && !item.newProductName) || !item.dimensions || item.quantity <= 0) return;
            items.push(item);
        });

        const payments = [];
        $(`#${type}-invoice-payments-container .dynamic-row`).each(function () {
            const p = {
                type: $(this).find('.payment-type').val(),
                amount: parseInt(($(this).find('.payment-amount').val() || '').replace(/,/g, '')) || 0,
                date: UI.toGregorian($(this).find('.payment-date')),
                description: $(this).find('.payment-description').val() || '',
                account_id: $(this).find('.payment-account-id').val() || null
            };
            if (p.amount <= 0 || !p.date) return;
            if (p.type === 'check') {
                p.checkDetails = {
                    checkNumber: $(this).find('.check-number').val(),
                    dueDate: UI.toGregorian($(this).find('.check-due-date')),
                    bankName: $(this).find('.check-bank-name').val()
                };
            } else if (p.type === 'endorse_check') {
                p.checkId = parseInt($(this).find('.endorsed-check-select').val());
            }
            payments.push(p);
        });

        const totalAmount = items.reduce((s, i) => s + (i.quantity * i.unitPrice), 0);
        const discount = Number(($(`#${type}-invoice-discount`).val() || '').replace(/,/g, '')) || 0;
        const paidAmount = payments.reduce((s, p) => s + p.amount, 0);

        const data = {
            id: parseInt($(`#${type}-invoice-id`).val()) || null,
            date: UI.toGregorian($(`#${type}-invoice-date`)),
            items,
            payments,
            totalAmount,
            discount,
            paidAmount,
            description: $(`#${type}-invoice-description`).val()
        };

        if (!data.date) {
            UI.showModalError(modalId, 'لطفا تاریخ فاکتور را به درستی وارد کنید.');
            return;
        }

        if (type === 'sales') data.customerId = parseInt($(`#sales-invoice-customer`).val());
        else data.supplierId = parseInt($(`#purchase-invoice-supplier`).val());

        UI.showLoader();
        const result = await Api.call(`save_${type}_invoice`, data);
        UI.hideLoader();

        if (result?.success) {
            $(modalId).modal('hide');
            await App.fetchInitialCache();
            load(`${type}_invoices`);
            App.getManager('products').load();
            App.getManager('accounts').load();
            App.getManager('checks').load();
        } else if (result?.error) {
            UI.showModalError(modalId, result.error);
        }
    }

    async function deleteInvoice(type, id) {
        const typeFa = type === 'salesInvoice' ? 'فاکتور فروش' : 'فاکتور خرید';
        UI.confirmAction(`آیا از حذف این ${typeFa} مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                // **FIX START**: Correctly format the action name to snake_case
                const action = type === 'salesInvoice' ? 'delete_sales_invoice' : 'delete_purchase_invoice';
                const result = await Api.call(action, { id });
                // **FIX END**
                UI.hideLoader();
                if (result?.success) {
                    await App.fetchInitialCache();
                    const tableName = type === 'salesInvoice' ? 'sales_invoices' : 'purchase_invoices';
                    load(tableName);
                    App.getManager('products').load();
                    App.getManager('accounts').load();
                    App.getManager('checks').load();
                }
            }
        });
    }

    function printInvoice(invoice, type) {
        if (!invoice) return;
        const appCache = App.getCache();
        const isSales = type === 'sales';
        const title = isSales ? 'فاکتور فروش' : 'فاکتور خرید';
        const personTitle = isSales ? 'مشتری' : 'تأمین کننده';
        const personData = isSales ? appCache.customers : appCache.suppliers;
        const personId = isSales ? invoice.customerId : invoice.supplierId;
        const person = personData.find(p => p.id == personId);
        const idLabel = isSales ? 'کد ملی' : 'کد اقتصادی';
        const idValue = person ? (isSales ? person.nationalId : person.economicCode) : '-';
        const invoiceDate = UI.gregorianToPersian(invoice.date);

        let itemsHtml = '';
        (invoice.items || []).forEach((item, index) => {
            const product = appCache.products.find(p => p.id == item.productId);
            itemsHtml += `<tr><td>${index + 1}</td><td>${product ? product.name : 'محصول حذف شده'}</td><td>${item.dimensions}</td><td>${item.quantity}</td><td>${UI.formatCurrency(item.unitPrice)}</td><td>${UI.formatCurrency(item.quantity * item.unitPrice)}</td></tr>`;
        });

        let paymentsHtml = '';
        if (invoice.payments && invoice.payments.length > 0) {
            (invoice.payments || []).forEach(p => {
                let typeText = '', detailsText = p.description || '';
                const paymentDate = UI.gregorianToPersian(p.date);
                switch (p.type) {
                    case 'cash': typeText = 'نقد'; break;
                    case 'check':
                        typeText = 'چک جدید';
                        if (p.checkDetails) {
                            const dueDate = UI.gregorianToPersian(p.checkDetails.dueDate);
                            detailsText = `شماره: ${p.checkDetails.checkNumber}, سررسید: ${dueDate}, بانک: ${p.checkDetails.bankName}`;
                        }
                        break;
                    case 'endorse_check':
                        typeText = 'خرج چک';
                        const endorsedCheck = appCache.checks.find(c => c.id == p.checkId);
                        if (endorsedCheck) detailsText = `چک شماره: ${endorsedCheck.checkNumber}, مبلغ: ${UI.formatCurrency(endorsedCheck.amount)}`;
                        break;
                    default: typeText = p.type;
                }
                paymentsHtml += `<tr><td>${typeText}</td><td>${paymentDate}</td><td>${UI.formatCurrency(p.amount)}</td><td>${detailsText}</td></tr>`;
            });
        } else {
            paymentsHtml = '<tr><td colspan="4" class="text-center">پرداختی ثبت نشده است.</td></tr>';
        }

        const printWin = window.open('', '_blank');
        printWin.document.write(`
        <html><head><title>${title} #${invoice.id}</title>
            <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css"><link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
            <style>body { font-family: Vazirmatn, sans-serif; direction: rtl; background-color: #fff !important; font-size: 14px; } .container { -webkit-print-color-adjust: exact; } table td, table th, .list-group-item span { font-weight: 500; vertical-align: middle; } .list-group-item strong, .list-group-item.active, .list-group-item.active span { font-weight: bold; }</style>
        </head><body>
            <div class="container mt-4">
                <h2>${title}</h2><hr>
                <div class="row"><div class="col-6"><strong>${personTitle}:</strong> ${person ? person.name : (isSales ? invoice.customerName : invoice.supplierName) || ''}</div><div class="col-6 text-end"><strong>شماره فاکتور:</strong> ${invoice.id}</div></div>
                <div class="row"><div class="col-6"><strong>آدرس:</strong> ${person?.address || '-'}</div><div class="col-6 text-end"><strong>تاریخ:</strong> ${invoiceDate}</div></div>
                <div class="row mb-3"><div class="col-6"><strong>تلفن:</strong> ${person?.phone || '-'}</div><div class="col-6 text-end"><strong>${idLabel}:</strong> ${idValue}</div></div>
                <h4 class="mt-4">اقلام فاکتور</h4><table class="table table-bordered"><thead><tr><th>ردیف</th><th>شرح کالا</th><th>ابعاد</th><th>تعداد</th><th>قیمت واحد</th><th>مبلغ کل</th></tr></thead><tbody>${itemsHtml}</tbody></table>
                <h4 class="mt-4">جزئیات پرداخت</h4><table class="table table-bordered"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>توضیحات/جزئیات</th></tr></thead><tbody>${paymentsHtml}</tbody></table>
                <div class="row justify-content-end mt-4">
                    <div class="col-5">
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between"><strong>جمع کل:</strong> <span>${UI.formatCurrency(invoice.totalAmount)}</span></li>
                            <li class="list-group-item d-flex justify-content-between"><strong>تخفیف:</strong> <span class="text-info">${UI.formatCurrency(invoice.discount || 0)}</span></li>
                            <li class="list-group-item d-flex justify-content-between"><strong>پرداختی:</strong> <span>${UI.formatCurrency(invoice.paidAmount)}</span></li>
                            <li class="list-group-item d-flex justify-content-between active"><strong>باقی‌مانده:</strong> <span>${UI.formatCurrency(invoice.remainingAmount)}</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </body></html>`);
        printWin.document.close();
        printWin.focus();
        setTimeout(() => { printWin.print(); printWin.close(); }, 250);
    }

    async function handleConsignmentAction(action, type, id) {
        const message = action === 'mark_as_consignment'
            ? 'آیا از انتقال این فاکتور به بخش امانی مطمئن هستید؟'
            : 'آیا از بازگردانی این فاکتور از بخش امانی مطمئن هستید؟';

        UI.confirmAction(message, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call(action, { type, id });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess('وضعیت امانی فاکتور با موفقیت تغییر کرد.');
                    load('sales_invoices');
                    load('purchase_invoices');
                    load('consignment_sales');
                    load('consignment_purchases');
                }
            }
        });
    }

    function attachEvents() {
        const salesSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.sales_invoices;
            state.searchTerm = term;
            state.currentPage = 1;
            load('sales_invoices');
        }, 500);
        $('#sales-invoice-search').on('input', function () { salesSearchHandler($(this).val()); });

        const purchaseSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.purchase_invoices;
            state.searchTerm = term;
            state.currentPage = 1;
            load('purchase_invoices');
        }, 500);
        $('#purchase-invoice-search').on('input', function () { purchaseSearchHandler($(this).val()); });

        $('body').on('input change', '#sales-invoice-form .quantity, #sales-invoice-form .unit-price, #sales-invoice-form .payment-amount, #sales-invoice-discount, #sales-invoice-form .dimensions-select, #sales-invoice-form .custom-dimension-input', () => calculateInvoiceTotals('sales'));
        $('body').on('input change', '#purchase-invoice-form .quantity, #purchase-invoice-form .unit-price, #purchase-invoice-form .payment-amount, #purchase-invoice-discount, #purchase-invoice-form .dimensions-select, #purchase-invoice-form .custom-dimension-input', () => calculateInvoiceTotals('purchase'));


        $('body').on('input change', '.dynamic-row .quantity, .dynamic-row .unit-price', function () {
            calculateInvoiceItemTotal($(this).closest('.dynamic-row'));
        });

        $('body').on('click', '.remove-item-btn, .remove-payment-btn', function () {
            const type = $(this).closest('form').attr('id').includes('sales') ? 'sales' : 'purchase';
            $(this).closest('.dynamic-row').remove();
            calculateInvoiceTotals(type);
        });

        $('#add-sales-invoice-item-btn').on('click', () => addInvoiceItemRow($('#sales-invoice-items-container')));
        $('#add-purchase-invoice-item-btn').on('click', () => addInvoiceItemRow($('#purchase-invoice-items-container')));
        $('#add-sales-invoice-payment-btn').on('click', () => addPaymentRow($('#sales-invoice-payments-container'), 'sales'));
        $('#add-purchase-invoice-payment-btn').on('click', () => addPaymentRow($('#purchase-invoice-payments-container'), 'purchase'));

        $('body').on('change', '.product-select', function () {
            populateDimensions($(this));
        });

        $('body').on('click', '.btn-add-new-product-inline', function () {
            const row = $(this).closest('.dynamic-row');
            const select = row.find('.product-select');
            const input = row.find('.new-product-name');
            const dimensionsSelect = row.find('.dimensions-select');

            if (input.is(':visible')) {
                // Switching back to select mode
                input.hide().val('');
                select.prop('disabled', false).select2('open');
                dimensionsSelect.empty().append('<option>ابتدا طرح را انتخاب کنید</option>').prop('disabled', true);
            } else {
                // Switching to new product input mode
                select.val(null).trigger('change');
                select.prop('disabled', true).select2('close');
                input.show().focus();

                // Manually populate and enable the dimensions dropdown for a new product
                dimensionsSelect.empty().prop('disabled', false).append('<option value="">انتخاب کنید...</option>');
                AppConfig.STANDARD_CARPET_DIMENSIONS.forEach(dim => dimensionsSelect.append(`<option value="${dim}">${dim}</option>`));
                dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');
            }
        });

        $('body').on('change', '.dimensions-select', function () {
            const container = $(this).closest('.dimensions-container');
            container.find('.custom-dimension-wrapper').remove();
            if ($(this).val() === 'custom') {
                container.append(`<div class="mt-2 custom-dimension-wrapper"><input type="text" class="form-control custom-dimension-input" placeholder="مثال: 2.1x3.2"></div>`);
            }
        });

        $('body').on('change', '#sales-invoice-form .quantity, #sales-invoice-form .dimensions-select', function () {
            const row = $(this).closest('.dynamic-row');
            const productId = row.find('.product-select').val();
            const dimensions = row.find('.dimensions-select').val();
            const quantityInput = row.find('.quantity');
            const quantity = parseInt(quantityInput.val()) || 0;

            if (!productId || !dimensions || quantity <= 0) return;

            const product = App.getCache().products.find(p => p.id == productId);
            if (!product) return;

            const stockItem = product.stock.find(s => s.dimensions === dimensions);
            const stockInDb = stockItem ? stockItem.quantity : 0;

            const originalItems = $(this).closest('form').data('original-items') || [];
            const originalItem = originalItems.find(it => it.productId == productId && it.dimensions === dimensions);
            const originalQty = originalItem ? originalItem.quantity : 0;
            const effectiveStock = stockInDb + originalQty;

            if (quantity > effectiveStock) {
                UI.showError(`موجودی برای محصول "${product.name}" با ابعاد ${dimensions} فقط ${effectiveStock} عدد است.`);
                quantityInput.val(effectiveStock);
                quantityInput.trigger('input');
            }
        });

        $('body').on('change', '.payment-type', function () {
            const row = $(this).closest('.payment-row');
            const type = $(this).val();
            const invoiceType = $(this).closest('form').attr('id').includes('sales') ? 'sales' : 'purchase';

            addPaymentRow(row.parent(), invoiceType, { type: type, amount: 0, date: UI.today() });
            row.remove();
            calculateInvoiceTotals(invoiceType);
        });

        $('body').on('change', '.endorsed-check-select', function () {
            const amount = $(this).find('option:selected').data('amount') || 0;
            $(this).closest('.payment-row').find('.payment-amount').val(Number(amount).toLocaleString('en-US'));
            calculateInvoiceTotals('purchase');
        });

        $('#save-sales-invoice-btn').on('click', () => saveInvoice('sales'));
        $('#save-purchase-invoice-btn').on('click', () => saveInvoice('purchase'));

        $('body').on('click', '.btn-edit', function () {
            const type = $(this).data('type');
            if (type === 'salesInvoice') prepareInvoiceModal('sales', $(this).data('entity'));
            if (type === 'purchaseInvoice') prepareInvoiceModal('purchase', $(this).data('entity'));
        });

        $('body').on('click', '.btn-delete', function () {
            const type = $(this).data('type');
            const id = $(this).data('id');
            if (type === 'salesInvoice' || type === 'purchaseInvoice') {
                deleteInvoice(type, id);
            }
        });

        $('body').on('click', '.btn-print', function () {
            const type = $(this).data('type');
            if (type === 'sales' || type === 'purchase') {
                printInvoice($(this).data('entity'), type);
            }
        });

        $('body').on('click', '.btn-to-consignment', function () {
            handleConsignmentAction('mark_as_consignment', $(this).data('type'), $(this).data('id'));
        });
        $('body').on('click', '.btn-from-consignment', function () {
            handleConsignmentAction('return_from_consignment', $(this).data('type'), $(this).data('id'));
        });

        $('#add-customer-from-invoice-btn').on('click', function () {
            const onCustomerSave = async (savedData) => {
                UI.showSuccess('مشتری با موفقیت ذخیره شد.');
                await App.updateCache('customers');
                const personSelect = $('#sales-invoice-customer');

                if (personSelect.data('select2')) {
                    personSelect.select2('destroy');
                }

                personSelect.empty().append('<option></option>');
                const customerList = App.getCache().customers || [];
                customerList.forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));

                personSelect.select2({
                    theme: 'bootstrap-5',
                    dir: 'rtl',
                    placeholder: `انتخاب مشتری...`,
                    dropdownParent: $('#salesInvoiceModal')
                });

                if (savedData.id) {
                    personSelect.val(savedData.id).trigger('change');
                }
            };
            App.getManager('customers').prepareModal(null, onCustomerSave);
        });

        $('#add-supplier-from-invoice-btn').on('click', function () {
            const onSupplierSave = async (savedData) => {
                UI.showSuccess('تامین‌کننده با موفقیت ذخیره شد.');
                await App.updateCache('suppliers');
                const personSelect = $('#purchase-invoice-supplier');

                if (personSelect.data('select2')) {
                    personSelect.select2('destroy');
                }

                personSelect.empty().append('<option></option>');
                const supplierList = App.getCache().suppliers || [];
                supplierList.forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));

                personSelect.select2({
                    theme: 'bootstrap-5',
                    dir: 'rtl',
                    placeholder: `انتخاب تأمین‌کننده...`,
                    dropdownParent: $('#purchaseInvoiceModal')
                });

                if (savedData.id) {
                    personSelect.val(savedData.id).trigger('change');
                }
            };
            App.getManager('suppliers').prepareModal(null, onSupplierSave);
        });
    }

    return {
        init: attachEvents,
        load,
        prepareInvoiceModal,
    };
})();