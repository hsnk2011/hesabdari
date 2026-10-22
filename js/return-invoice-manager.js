// /js/return-invoice-manager.js

const ReturnInvoiceManager = (function () {

    // --- Private Helper Functions for Rendering ---
    // Keep last loaded invoices for selected person to derive dimensions from history
    let lastInvoicesByType = { sales: [], purchase: [] };

    // Normalize Persian/Arabic-Indic digits to English and strip separators
    function toEnglishDigits(str) {
        if (!str) return '';
        const persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        const arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        return String(str)
            .split('')
            .map(ch => {
                const p = persian.indexOf(ch);
                if (p > -1) return String(p);
                const a = arabic.indexOf(ch);
                if (a > -1) return String(a);
                return ch;
            })
            .join('');
    }

    function parseLocalizedInt(str) {
        const s = toEnglishDigits(str).replace(/[٬,\s]/g, '').replace(/[^\d]/g, '');
        return parseInt(s || '0') || 0;
    }

    function getHistoricalUnitPrice(productId, dimension, isSales) {
        if (!productId || !dimension) return null;
        const invoices = isSales ? lastInvoicesByType.sales : lastInvoicesByType.purchase;
        for (const inv of invoices) {
            if (!inv.items) continue;
            for (const item of inv.items) {
                if (String(item.productId) === String(productId) && item.dimensions === dimension) {
                    const up = parseInt(item.unitPrice);
                    if (!isNaN(up) && up > 0) return up;
                }
            }
        }
        return null;
    }

    function _renderReturnInvoiceItemsDetails(inv) {
        if (!inv.items || inv.items.length === 0) {
            return '<tr class="invoice-details-row"><td colspan="9"><div class="p-2 text-muted text-center small">بدون آیتم</div></td></tr>';
        }
        
        let itemsHtml = '<tr class="invoice-details-row"><td colspan="9" class="p-0"><div class="bg-light border-top border-bottom p-2 ms-4">';
        itemsHtml += '<table class="table table-sm table-bordered mb-0 small">';
        itemsHtml += '<thead class="table-secondary"><tr><th>ردیف</th><th>محصول</th><th>ابعاد</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead>';
        itemsHtml += '<tbody>';
        
        inv.items.forEach((item, index) => {
            const itemTotal = (item.quantity || 0) * (item.unitPrice || 0);
            itemsHtml += `<tr>
                <td>${index + 1}</td>
                <td>${item.productName || '-'}</td>
                <td>${item.dimensions || '-'}</td>
                <td>${item.quantity || 0}</td>
                <td>${UI.formatCurrency(item.unitPrice || 0)}</td>
                <td>${UI.formatCurrency(itemTotal)}</td>
            </tr>`;
        });
        
        itemsHtml += '</tbody></table></div></td></tr>';
        return itemsHtml;
    }

    function _renderReturnInvoiceRow(inv, type, showDetails = false) {
        const isSettled = !(Number(inv.remainingAmount) > 0.01);
        const rowStatusClass = isSettled ? 'status-settled' : 'status-unsettled';
        const badgeStatusClass = isSettled ? 'bg-success' : 'bg-warning';
        const statusText = isSettled ? 'تسویه شده' : 'تسویه نشده';
        const personName = inv.personName || '';
        const entityType = type === 'sales_return' ? 'salesReturn' : 'purchaseReturn';
        const persianDateStr = UI.gregorianToPersian(inv.date);
        const typeLabel = type === 'sales_return' ? '<span class="badge bg-primary">فروش</span>' : '<span class="badge bg-success">خرید</span>';

        const row = $(`<tr class="${rowStatusClass}">
            <td>${inv.id}</td>
            <td>${typeLabel}</td>
            <td>${persianDateStr}</td>
            <td>${personName}</td>
            <td>${UI.formatCurrency(inv.totalAmount)}</td>
            <td>${UI.formatCurrency(inv.paidAmount)}</td>
            <td class="fw-bold">${UI.formatCurrency(inv.remainingAmount)}</td>
            <td><span class="badge ${badgeStatusClass}">${statusText}</span></td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-info btn-print" title="چاپ" data-type="${entityType}" data-return-type="${type}"><i class="bi bi-printer"></i></button>
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" data-type="${entityType}" data-permission-action="manage_return_invoices"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-danger btn-delete" title="حذف" data-type="${entityType}" data-id="${inv.id}" data-permission-action="manage_return_invoices"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        row.find('.btn-edit, .btn-print').data('entity', inv);
        
        // If showDetails is enabled, add details row after main row
        if (showDetails) {
            const detailsHtml = _renderReturnInvoiceItemsDetails(inv);
            return row.add($(detailsHtml));
        }
        
        return row;
    }

    const renderers = {
        return_invoices: (data) => {
            const body = $('#return-invoices-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="9" class="text-center">فاکتور مرجوعی یافت نشد.</td></tr>');
            const showDetails = $('#return-invoices-show-details').is(':checked');
            data.forEach(inv => {
                const type = inv.type === 'sales' ? 'sales_return' : 'purchase_return';
                body.append(_renderReturnInvoiceRow(inv, type, showDetails));
            });
        }
    };

    function calculateReturnInvoiceItemTotal(row) {
        const quantity = parseInt(row.find('.quantity').val()) || 0;
        const unitPrice = parseInt((row.find('.unit-price').val() || '').replace(/,/g, '')) || 0;
        row.find('.item-total').val(UI.formatCurrency(quantity * unitPrice));
    }

    function calculateReturnInvoiceTotals(type) {
        const itemsContainer = $(`#${type}-return-invoice-items-container`);
        const paymentsContainer = $(`#${type}-return-invoice-payments-container`);
        let total = 0, totalQuantity = 0, totalArea = 0;

        itemsContainer.find('.invoice-item-row').each(function () {
            const qty = parseInt($(this).find('.quantity').val()) || 0;
            const unitPrice = parseLocalizedInt($(this).find('.unit-price').val());
            const dimensions = $(this).find('.dimensions').val() || '';
            total += UI.roundMoney(qty * unitPrice);
            totalQuantity += qty;
            let area = 0;
            if (dimensions) {
                area = UI.dimensionArea(dimensions.toLowerCase().replace(/\*/g, 'x').replace(/×/g, 'x'));
            }
            totalArea += area * qty;
        });

        let totalPaid = 0;
        paymentsContainer.find('.payment-row').each(function () {
            const raw = $(this).find('.payment-amount-raw').val();
            if (raw != null && raw !== '') {
                totalPaid += parseInt(raw) || 0;
            } else {
                totalPaid += parseLocalizedInt($(this).find('.payment-amount').val());
            }
        });

        // دریافت تخفیف از فیلد ورودی
        const discount = parseLocalizedInt($(`#${type}-return-invoice-discount`).val());

        // محاسبه باقی‌مانده با احتساب تخفیف
        const remaining = total - discount - totalPaid;

        $(`#${type}-return-invoice-total-amount`).text(UI.formatCurrency(total));
        $(`#${type}-return-invoice-total-quantity`).text(totalQuantity);
        $(`#${type}-return-invoice-total-area`).text(totalArea.toFixed(2));
        $(`#${type}-return-invoice-paid-amount`).text(UI.formatCurrency(totalPaid));
        $(`#${type}-return-invoice-remaining-amount`).text(UI.formatCurrency(remaining));
    }

    async function loadAvailableIdentifiers(typePrefix, productId, dimensions) {
        const identifiersSelect = $(`#${typePrefix}-return-identifiers-select`);
        identifiersSelect.html('<option value="">در حال بارگذاری...</option>').prop('disabled', true);

        try {
            const isSalesReturn = typePrefix === 'sales';
            const status = isSalesReturn ? 'sold' : 'in_stock'; // Sales return needs sold items, purchase return needs in_stock items
            const selectedInvoiceId = $(`#${typePrefix}-return-original-invoice-select`).val();
            
            const result = await Api.call('get_available_identifiers_for_return', {
                productId: productId,
                dimensions: dimensions,
                status: status,
                invoiceId: selectedInvoiceId || null // Pass selected invoice ID to filter
            });

            if (result && result.items && result.items.length > 0) {
                identifiersSelect.empty();
                result.items.forEach(item => {
                    const persianDate = UI.gregorianToPersian(item.entry_date);
                    const invoiceInfo = item.invoice_id ? ` (فاکتور #${item.invoice_id})` : '';
                    const option = $('<option></option>')
                        .val(item.id)
                        .text(`شناسه ${item.id} - ${persianDate}${invoiceInfo}`);
                    identifiersSelect.append(option);
                });
                identifiersSelect.prop('disabled', false);
                
                // ذخیره اطلاعات تخفیف فاکتور اصلی برای محاسبه خودکار
                if (result.originalInvoice) {
                    identifiersSelect.data('originalInvoice', result.originalInvoice);
                }
            } else {
                const msg = isSalesReturn ? 'هیچ شناسه فروخته شده‌ای یافت نشد' : 'هیچ شناسه موجودی یافت نشد';
                identifiersSelect.html(`<option value="">${msg}</option>`).prop('disabled', true);
            }
        } catch (error) {
            identifiersSelect.html('<option value="">خطا در بارگذاری شناسه‌ها</option>').prop('disabled', true);
        }
    }

    function addReturnInvoiceItem(type) {
        const productId = $(`#${type}-return-product-select`).val();
        const productName = $(`#${type}-return-product-select option:selected`).text();
        const dimensions = $(`#${type}-return-dimensions-select`).val();
        const selectedIdentifiers = $(`#${type}-return-identifiers-select`).val() || []; // Array of selected identifier IDs
        const quantity = selectedIdentifiers.length; // Quantity is number of selected identifiers
        const unitPrice = parseLocalizedInt($(`#${type}-return-unit-price-input`).val());

        if (!productId || productId <= 0) return UI.showError('لطفاً محصول را انتخاب کنید.');
        if (!dimensions) return UI.showError('لطفاً ابعاد را انتخاب کنید.');
        if (selectedIdentifiers.length === 0) return UI.showError('لطفاً حداقل یک شناسه را انتخاب کنید.');
        // FIX: Allow zero-value returns (e.g., warranty replacements)
        if (unitPrice < 0) return UI.showError('قیمت واحد نمی‌تواند منفی باشد.');

        const itemTotal = quantity * unitPrice;
        const identifiersText = selectedIdentifiers.join(', ');
        
        // محاسبه تخفیف خودکار بر اساس فاکتور اصلی
        const identifiersSelect = $(`#${type}-return-identifiers-select`);
        const originalInvoice = identifiersSelect.data('originalInvoice');
        
        let itemDiscount = 0;
        if (originalInvoice && originalInvoice.discount_per_unit) {
            itemDiscount = Math.round(quantity * originalInvoice.discount_per_unit);
            const currentDiscount = parseLocalizedInt($(`#${type}-return-invoice-discount`).val());
            const newDiscount = currentDiscount + itemDiscount;
            
            $(`#${type}-return-invoice-discount`).val(UI.formatCurrency(newDiscount));
        }
        
        const row = $(`<tr class="invoice-item-row" data-auto-discount="${itemDiscount}">
            <td>
                <input type="hidden" class="product-id" value="${productId}">
                <input type="hidden" class="selected-identifiers" value="${selectedIdentifiers.join(',')}">
                ${productName}
            </td>
            <td><input type="text" class="form-control form-control-sm dimensions" value="${dimensions}" readonly></td>
            <td><small class="text-muted">${identifiersText}</small></td>
            <td><input type="number" class="form-control form-control-sm quantity" value="${quantity}" min="1" readonly></td>
            <td><input type="text" class="form-control form-control-sm unit-price" value="${Number(unitPrice).toLocaleString('en-US')}" readonly></td>
            <td><input type="text" class="form-control form-control-sm item-total" value="${UI.formatCurrency(itemTotal)}" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="bi bi-trash"></i></button></td>
        </tr>`);

        $(`#${type}-return-invoice-items-container`).append(row);
        calculateReturnInvoiceTotals(type);

        // Clear inputs
        $(`#${type}-return-product-select`).val('').trigger('change');
        const dimSel = $(`#${type}-return-dimensions-select`);
        dimSel.empty().append('<option value="">ابتدا محصول را انتخاب کنید</option>').prop('disabled', true);
        const identSel = $(`#${type}-return-identifiers-select`);
        identSel.empty().append('<option value="">ابتدا محصول و ابعاد را انتخاب کنید</option>').prop('disabled', true);
        $(`#${type}-return-unit-price-input`).val('');
    }

    function addReturnPayment(type) {
        const paymentType = $(`#${type}-return-payment-type`).val();
        const amount = parseLocalizedInt($(`#${type}-return-payment-amount`).val());
        const persianDate = $(`#${type}-return-payment-date`).val();
        const gregorianDate = UI.toGregorian(`#${type}-return-payment-date`);
        const description = $(`#${type}-return-payment-description`).val().trim();
        const accountId = $(`#${type}-return-payment-account`).val();

        if (!paymentType) return UI.showError('نوع پرداخت را انتخاب کنید.');
        if (amount <= 0) return UI.showError('مبلغ باید بیشتر از صفر باشد.');
        if (!persianDate) return UI.showError('تاریخ را وارد کنید.');
        if (!gregorianDate) return UI.showError('تاریخ وارد شده نامعتبر است.');
        if (paymentType === 'cash' && !accountId) return UI.showError('برای پرداخت نقدی، انتخاب حساب الزامی است.');

        let checkDetails = null;
        if (paymentType === 'check') {
            const checkNumber = $(`#${type}-return-payment-details-container .check-number`).val().trim();
            const checkBank = $(`#${type}-return-payment-details-container .check-bank-name`).val().trim();
            const checkIssuer = $(`#${type}-return-payment-details-container .check-issuer-name`).val().trim();
            const checkPersianDate = $(`#${type}-return-payment-details-container .check-due-date`).val();
            const checkGregorianDate = UI.toGregorian($(`#${type}-return-payment-details-container .check-due-date`));
            if (!checkNumber || !checkBank || !checkPersianDate) {
                return UI.showError('مشخصات کامل چک را وارد کنید.');
            }
            if (!checkGregorianDate) {
                return UI.showError('تاریخ سررسید چک نامعتبر است.');
            }
            checkDetails = { checkNumber, bankName: checkBank, dueDate: checkGregorianDate, issuerName: checkIssuer };
        } else if (paymentType === 'endorse_check') {
            const selectedCheck = $(`#${type}-return-payment-details-container .endorsed-check-select`).val();
            if (!selectedCheck) {
                return UI.showError('چک جهت خرج کردن را انتخاب کنید.');
            }
            checkDetails = { endorsedCheckId: selectedCheck };
        }

        const accountName = accountId ? $(`#${type}-return-payment-account option:selected`).text() : '';
        const typeText = paymentType === 'cash' ? 'نقدی' : (paymentType === 'check' ? 'چک جدید' : 'خرج چک');

        const row = $(`<tr class="payment-row">
            <td>
                <input type="hidden" class="payment-type" value="${paymentType}">
                ${typeText}
            </td>
            <td>
                <input type="hidden" class="payment-amount-raw" value="${amount}">
                <input type="text" class="form-control form-control-sm payment-amount" value="${UI.formatCurrency(amount)}" readonly>
            </td>
            <td>
                <input type="hidden" class="payment-date" value="${gregorianDate}">
                ${persianDate}
            </td>
            <td>
                <input type="hidden" class="payment-account-id" value="${accountId || ''}">
                ${accountName}
            </td>
            <td>
                <input type="hidden" class="payment-description" value="${description}">
                ${description || '-'}
            </td>
            <td><button type="button" class="btn btn-sm btn-danger remove-payment"><i class="bi bi-trash"></i></button></td>
        </tr>`);

        if (checkDetails) {
            // Build via jQuery .val() - JSON in a single-quoted attribute
            // breaks when text contains apostrophes/quotes.
            const checkDataInput = $('<input>', { type: 'hidden', class: 'payment-check-data' }).val(JSON.stringify(checkDetails));
            row.find('.payment-type').after(checkDataInput);
        }

        $(`#${type}-return-invoice-payments-container`).append(row);
        calculateReturnInvoiceTotals(type);

        // Clear inputs
        $(`#${type}-return-payment-type`).val('cash').trigger('change');
        $(`#${type}-return-payment-amount`).val('');
        $(`#${type}-return-payment-date`).val(UI.today());
        $(`#${type}-return-payment-description`).val('');
        $(`#${type}-return-payment-account`).val('').trigger('change');
    }

    function updateReturnPaymentMethod(type, method) {
        const isSalesReturn = type === 'sales';
        const detailsContainer = $(`#${type}-return-payment-details-container`);
        const amountInput = $(`#${type}-return-payment-amount`);
        detailsContainer.empty().hide();

        if (method === 'check') {
            const dueDate = UI.today();
            detailsContainer.html(`<div class="row g-2 mt-1 mb-2">
                <div class="col-3"><label class="form-label-sm">شماره چک</label><input type="text" class="form-control form-control-sm check-number" required></div>
                <div class="col-3"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker check-due-date" value="${dueDate}" required></div>
                <div class="col-3"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm check-bank-name" required></div>
                <div class="col-3"><label class="form-label-sm">صادرکننده</label><input type="text" class="form-control form-control-sm check-issuer-name" placeholder="نام روی چک"></div>
            </div>`);
            amountInput.prop('readonly', false);
            UI.initializeDatepickers();
            detailsContainer.show();
        } else if (method === 'endorse_check') {
            // For sales return: use received checks (can endorse them out)
            // For purchase return: use payable checks (can endorse them out)
            const checkType = isSalesReturn ? 'received' : 'payable';
            const availableChecks = (App.getCache().checks || []).filter(c => c.type === checkType && c.status === (checkType === 'received' ? 'in_hand' : 'payable'));
            if (availableChecks.length === 0) {
                detailsContainer.html(`<p class="text-danger small mt-2">چک قابل واگذاری برای خرج کردن وجود ندارد.</p>`);
                amountInput.prop('readonly', true);
            } else {
                const options = availableChecks.map(c => `<option value="${c.id}" data-amount="${c.amount}">${c.checkNumber} - ${UI.formatCurrency(c.amount)}</option>`).join('');
                detailsContainer.html(`<div class="mt-1 mb-2"><label class="form-label-sm">انتخاب چک جهت خرج کردن</label><select class="form-select form-select-sm endorsed-check-select" required><option value="">-- انتخاب کنید --</option>${options}</select></div>`);
                
                // Auto-fill amount when check is selected
                detailsContainer.find('.endorsed-check-select').on('change', function() {
                    const selectedAmount = $(this).find('option:selected').data('amount');
                    if (selectedAmount) {
                        amountInput.val(UI.formatCurrency(selectedAmount));
                    }
                });
                amountInput.prop('readonly', true);
            }
            detailsContainer.show();
        } else { // Cash
            amountInput.prop('readonly', false);
        }
    }

    async function openReturnInvoiceModal(type, entity = null) {
        const isSalesReturn = type === 'sales_return';
        const modalId = isSalesReturn ? '#sales-return-invoice-modal' : '#purchase-return-invoice-modal';
        const personLabel = isSalesReturn ? 'مشتری' : 'تامین‌کننده';
        const appCache = App.getCache();
        
        // Check if entity data is incomplete (missing items or payments)
        const needsRefresh = entity && entity.id && (
            !entity.items || !Array.isArray(entity.items) || entity.items.length === 0 ||
            !entity.payments || !Array.isArray(entity.payments)
        );
        
        if (needsRefresh) {
            const entityType = isSalesReturn ? 'salesReturn' : 'purchaseReturn';
            const fresh = await Api.call('get_entity_by_id', { entityType, id: entity.id });
            if (fresh && !fresh.error) entity = fresh;
        }

        // Check if invoice is editable
        const isEditable = !entity || entity.is_editable !== false;
        
        // Remove previous warning alerts
        $(modalId).find('.alert-warning').remove();
        
        $(modalId).find('.modal-title').text(entity ? `${isEditable ? 'ویرایش' : 'مشاهده'} فاکتور مرجوعی ${isSalesReturn ? 'فروش' : 'خرید'}` : `ثبت فاکتور مرجوعی ${isSalesReturn ? 'فروش' : 'خرید'}`);
        
        const typePrefix = isSalesReturn ? 'sales' : 'purchase';
        
        // Populate person dropdown
        const personList = isSalesReturn ? appCache.customers : appCache.suppliers;
        const personSelect = $(`#${typePrefix}-return-invoice-person`);
        personSelect.empty().append('<option value="">انتخاب کنید...</option>');
        if (personList && personList.length) {
            personList.forEach(person => {
                personSelect.append(`<option value="${person.id}">${UI.escapeHtml(person.name)}</option>`);
            });
        }
        personSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: `انتخاب ${personLabel}...`,
            dropdownParent: $(modalId)
        });
        
        // Initialize product dropdown (will be populated when person is selected)
        const productSelect = $(`#${typePrefix}-return-product-select`);
        productSelect.empty().append('<option value="">ابتدا ' + personLabel + ' را انتخاب کنید...</option>');
        productSelect.prop('disabled', true);
        productSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: 'جستجو و انتخاب محصول...',
            dropdownParent: $(modalId)
        });
        
        // Populate account dropdown
        const accountSelect = $(`#${typePrefix}-return-payment-account`);
        accountSelect.empty().append('<option value="">انتخاب کنید...</option>');
        if (appCache.accounts && appCache.accounts.length) {
            appCache.accounts.forEach(account => {
                accountSelect.append(`<option value="${account.id}">${UI.escapeHtml(account.name)}</option>`);
            });
        }
        accountSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: 'انتخاب حساب...',
            dropdownParent: $(modalId)
        });
        
        // Set form values
        $(`#${typePrefix}-return-invoice-id`).val(entity?.id || '');
        $(`#${typePrefix}-return-invoice-person`).val(entity?.person_id || '').trigger('change');
        const invoiceDate = entity?.date ? UI.gregorianToPersian(entity.date) : UI.today();
        $(`#${typePrefix}-return-invoice-date`).val(invoiceDate);
        $(`#${typePrefix}-return-invoice-description`).val(entity?.description || '');

        // Clear items and payments
        $(`#${typePrefix}-return-invoice-items-container`).empty();
        $(`#${typePrefix}-return-invoice-payments-container`).empty();
        
        // Reset buttons to enabled state first
        $(`#add-${typePrefix}-return-item-btn`).prop('disabled', false).show();
        $(`#add-${typePrefix}-return-payment-btn`).prop('disabled', false).show();
        $(`#save-${typePrefix}-return-invoice-btn`).prop('disabled', false).show();
        personSelect.prop('disabled', false);
        $(`#${typePrefix}-return-invoice-date`).prop('readonly', false);
        $(`#${typePrefix}-return-invoice-description`).prop('readonly', false);
        $(`#${typePrefix}-return-invoice-discount`).prop('readonly', false);
        
        // Disable all inputs if not editable
        if (!isEditable) {
            personSelect.prop('disabled', true);
            $(`#${typePrefix}-return-invoice-date`).prop('readonly', true);
            $(`#${typePrefix}-return-invoice-description`).prop('readonly', true);
            $(`#${typePrefix}-return-invoice-discount`).prop('readonly', true);
            $(`#add-${typePrefix}-return-item-btn`).prop('disabled', true).hide();
            $(`#add-${typePrefix}-return-payment-btn`).prop('disabled', true).hide();
            $(`#save-${typePrefix}-return-invoice-btn`).prop('disabled', true).hide();
            
            // Show readonly warning
            const warningHtml = `<div class="alert alert-warning mb-3">
                <i class="bi bi-lock-fill"></i> این فاکتور مرجوعی قابل ویرایش نیست زیرا شناسه‌های آن دوباره فروخته شده‌اند.
            </div>`;
            $(modalId).find('.modal-body').prepend(warningHtml);
        }

        // Add items if editing
        if (entity?.items) {
            entity.items.forEach(item => {
                const identifiersText = (item.identifier_labels && item.identifier_labels.length)
                    ? item.identifier_labels.join(', ')
                    : (item.selected_identifiers ? item.selected_identifiers.join(', ') : '');
                const removeBtn = isEditable ? `<button type="button" class="btn btn-sm btn-danger remove-item"><i class="bi bi-trash"></i></button>` : '';
                const row = $(`<tr class="invoice-item-row">
                    <td>
                        <input type="hidden" class="product-id" value="${item.productId}">
                        <input type="hidden" class="selected-identifiers" value="${item.selected_identifiers ? item.selected_identifiers.join(',') : ''}">
                        ${item.productName || 'محصول حذف شده'}
                    </td>
                    <td><input type="text" class="form-control form-control-sm dimensions" value="${UI.escapeHtml(item.dimensions)}" readonly></td>
                    <td><small class="text-muted">${identifiersText}</small></td>
                    <td><input type="number" class="form-control form-control-sm quantity" value="${item.quantity}" min="1" readonly></td>
                    <td><input type="text" class="form-control form-control-sm unit-price" value="${Number(item.unitPrice).toLocaleString('en-US')}" readonly></td>
                    <td><input type="text" class="form-control form-control-sm item-total" value="${UI.formatCurrency(item.quantity * item.unitPrice)}" readonly></td>
                    <td>${removeBtn}</td>
                </tr>`);
                $(`#${typePrefix}-return-invoice-items-container`).append(row);
            });
        }

        // Add payments if editing
        if (entity?.payments) {
            const accounts = Array.isArray(appCache.accounts) ? appCache.accounts : [];
            entity.payments.forEach(payment => {
                const typeText = payment.type === 'cash' ? 'نقدی' : (payment.type === 'check' ? 'چک جدید' : 'خرج چک');
                const acc = accounts.find(a => String(a.id) === String(payment.account_id));
                const accountName = acc ? acc.name : (payment.account_id ? ('حساب شماره ' + payment.account_id) : '');
                const gDate = (payment.date || '').split(' ')[0];
                const persianDate = UI.gregorianToPersian(gDate || payment.date);
                const removeBtn = isEditable ? `<button type="button" class="btn btn-sm btn-danger remove-payment"><i class="bi bi-trash"></i></button>` : '';
                let detailsText = '';
                if (payment.checkDetails) {
                    const cd = payment.checkDetails;
                    const number = cd.checkNumber || cd.number || '';
                    const bank = cd.bankName || cd.bank || '';
                    detailsText = (number || bank) ? `<div class="small text-muted">${number ? ('چک #' + number) : ''}${bank ? (' - ' + bank) : ''}</div>` : '';
                }
                const row = $(`<tr class="payment-row">
                    <td>
                        <input type="hidden" class="payment-type" value="${payment.type}">
                        ${typeText}${detailsText}
                    </td>
                    <td>
                        <input type="hidden" class="payment-amount-raw" value="${payment.amount}">
                        <input type="text" class="form-control form-control-sm payment-amount" value="${UI.formatCurrency(payment.amount)}" readonly>
                    </td>
                    <td>
                        <input type="hidden" class="payment-date" value="${payment.date}">
                        ${persianDate}
                    </td>
                    <td>
                        <input type="hidden" class="payment-account-id" value="${payment.account_id || ''}">
                        ${accountName || '-'}
                    </td>
                    <td>
                        <input type="hidden" class="payment-description" value="${payment.description || ''}">
                        ${payment.description || '-'}
                    </td>
                    <td>${removeBtn}</td>
                </tr>`);
                
                if (payment.checkDetails) {
                    // Build via jQuery .val() - JSON in a single-quoted attribute
                    // breaks when text contains apostrophes/quotes.
                    const checkDataInput = $('<input>', { type: 'hidden', class: 'payment-check-data' }).val(JSON.stringify(payment.checkDetails));
                    row.find('.payment-type').after(checkDataInput);
                }
                
                $(`#${typePrefix}-return-invoice-payments-container`).append(row);
            });
        }

        calculateReturnInvoiceTotals(typePrefix);
        
        // Set discount value if editing
        if (entity?.discount) {
            $(`#${typePrefix}-return-invoice-discount`).val(UI.formatCurrency(entity.discount));
        } else {
            $(`#${typePrefix}-return-invoice-discount`).val('0');
        }
        
        $(modalId).modal('show');
        
        // Initialize Persian datepickers after modal is shown
        UI.initializeDatepickers();
    }

    function saveReturnInvoice(type) {
        const isSalesReturn = type === 'sales_return';
        const typePrefix = isSalesReturn ? 'sales' : 'purchase';
        
        const id = $(`#${typePrefix}-return-invoice-id`).val();
        const person_id = $(`#${typePrefix}-return-invoice-person`).val();
        const persianDate = $(`#${typePrefix}-return-invoice-date`).val();
        const gregorianDate = UI.toGregorian(`#${typePrefix}-return-invoice-date`);
        const description = $(`#${typePrefix}-return-invoice-description`).val().trim();

        if (!person_id) return UI.showError(`لطفاً ${isSalesReturn ? 'مشتری' : 'تامین‌کننده'} را انتخاب کنید.`);
        if (!persianDate) return UI.showError('تاریخ را وارد کنید.');
        if (!gregorianDate) return UI.showError('تاریخ وارد شده نامعتبر است.');

        const items = [];
        $(`#${typePrefix}-return-invoice-items-container .invoice-item-row`).each(function () {
            const selectedIds = $(this).find('.selected-identifiers').val();
            items.push({
                productId: $(this).find('.product-id').val(),
                dimensions: $(this).find('.dimensions').val(),
                quantity: parseInt($(this).find('.quantity').val()),
                unitPrice: parseLocalizedInt($(this).find('.unit-price').val()),
                selected_identifiers: selectedIds ? selectedIds.split(',').map(id => parseInt(id)) : []
            });
        });

        if (items.length === 0) return UI.showError('حداقل یک قلم کالا اضافه کنید.');

        const payments = [];
        $(`#${typePrefix}-return-invoice-payments-container .payment-row`).each(function () {
            const payment = {
                type: $(this).find('.payment-type').val(),
                amount: parseFloat($(this).find('.payment-amount-raw').val()),
                date: $(this).find('.payment-date').val(),
                description: $(this).find('.payment-description').val(),
                account_id: $(this).find('.payment-account-id').val() || null
            };
            
            const checkData = $(this).find('.payment-check-data').val();
            if (checkData) {
                payment.checkDetails = JSON.parse(checkData);
            }
            
            payments.push(payment);
        });

        const data = {
            id: id || null,
            person_id: parseInt(person_id),
            date: gregorianDate,
            discount: parseLocalizedInt($(`#${typePrefix}-return-invoice-discount`).val()),
            description,
            items,
            payments
        };

        if (!id) {
            const invoices = isSalesReturn ? lastInvoicesByType.sales : lastInvoicesByType.purchase;
            let originalInvoiceId = null;
            if (invoices && invoices.length && items.length) {
                const firstItem = items[0];
                const match = invoices.find(inv => Array.isArray(inv.items) && inv.items.some(it => String(it.productId) === String(firstItem.productId) && it.dimensions === firstItem.dimensions));
                originalInvoiceId = match ? match.id : invoices[0].id;
            }
            if (originalInvoiceId) {
                data.original_invoice_id = originalInvoiceId;
            } else {
                UI.showError('فاکتور اصلی مرتبط با این مرجوعی یافت نشد.');
                return;
            }
        }

        const action = isSalesReturn ? 'save_sales_return' : 'save_purchase_return';
        const modalId = isSalesReturn ? '#sales-return-invoice-modal' : '#purchase-return-invoice-modal';

        Api.call(action, data).then(response => {
            if (!response) return;
            if (response.success) {
                UI.showSuccess(`فاکتور مرجوعی ${isSalesReturn ? 'فروش' : 'خرید'} با موفقیت ذخیره شد.`);
                $(modalId).modal('hide');
                load('return_invoices');
            } else {
                UI.showError(response.error || 'خطا در ذخیره فاکتور مرجوعی.');
            }
        });
    }

    async function deleteReturnInvoice(type, id) {
        if (!await UI.confirmText('آیا از حذف این فاکتور مرجوعی اطمینان دارید؟')) return;

        const action = type === 'salesReturn' ? 'delete_sales_return' : 'delete_purchase_return';

        Api.call(action, { id }).then(async response => {
            if (response.success) {
                UI.showSuccess('فاکتور مرجوعی با موفقیت حذف شد.');
                load('return_invoices');
                if (window.App && typeof App.updateCache === 'function') {
                    await App.updateCache(['checks', 'accounts', 'customers', 'suppliers']);
                }
                if (window.TransactionManager && typeof TransactionManager.load === 'function') {
                    TransactionManager.load();
                }
            } else {
                UI.showError(response.error || 'خطا در حذف فاکتور مرجوعی.');
            }
        });
    }

    async function printReturnInvoice(invoice, type) {
        if (!invoice) return;
        // Normalize type: salesReturn | sales_return | sales
        let normalized = type || '';
        if (normalized === 'salesReturn' || normalized === 'sales') normalized = 'sales_return';
        if (normalized === 'purchaseReturn' || normalized === 'purchase') normalized = 'purchase_return';
        const isSalesReturn = normalized === 'sales_return';
        if (!invoice.items || invoice.items.length === 0 || !Array.isArray(invoice.payments)) {
            const entityType = isSalesReturn ? 'salesReturn' : 'purchaseReturn';
            UI.showLoader();
            const fresh = await Api.call('get_entity_by_id', { entityType, id: invoice.id });
            UI.hideLoader();
            if (fresh) invoice = fresh;
        }

        const appCache = App.getCache();
        const personList = isSalesReturn ? (appCache.customers || []) : (appCache.suppliers || []);
        const person = personList.find(p => String(p.id) === String(invoice.person_id)) || null;
        const title = isSalesReturn ? 'فاکتور مرجوعی فروش' : 'فاکتور مرجوعی خرید';
        const personLabel = isSalesReturn ? 'مشتری' : 'تأمین‌کننده';
        const identifierLabel = 'شناسه‌ها';
        const invoiceDate = invoice.date ? UI.gregorianToPersian(invoice.date) : '-';
        const personName = person?.name || invoice.personName || '-';
        const personPhone = person?.phone || '-';
        const personAddress = person?.address || '-';

        const items = Array.isArray(invoice.items) ? invoice.items : [];
        const itemsHtml = items.length ? items.map((item, idx) => {
            const identifiers = (Array.isArray(item.identifier_labels) && item.identifier_labels.length)
                ? item.identifier_labels.join(', ')
                : (Array.isArray(item.selected_identifiers) && item.selected_identifiers.length
                    ? item.selected_identifiers.join(', ')
                    : (item.identifier_list || '-'));
            return `<tr>
                <td>${idx + 1}</td>
                <td>${item.productName || 'محصول حذف شده'}</td>
                <td>${item.dimensions || '-'}</td>
                <td>${item.quantity || 0}</td>
                <td>${UI.formatCurrency(item.unitPrice || 0)}</td>
                <td>${UI.formatCurrency((item.quantity || 0) * (item.unitPrice || 0))}</td>
                <td>${identifiers || '-'}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="7" class="text-center">هیچ قلمی ثبت نشده است.</td></tr>';

        const payments = Array.isArray(invoice.payments) ? invoice.payments : [];
        const paymentsHtml = payments.length ? payments.map(payment => {
            let typeText = '';
            switch (payment.type) {
                case 'cash': typeText = 'نقد'; break;
                case 'check': typeText = 'چک جدید'; break;
                case 'endorse_check': typeText = 'خرج چک'; break;
                default: typeText = payment.type;
            }
            let details = payment.description || '';
            if (payment.type === 'check' && payment.checkDetails) {
                const due = payment.checkDetails.dueDate ? UI.gregorianToPersian(payment.checkDetails.dueDate) : '-';
                details = `شماره: ${payment.checkDetails.checkNumber || '-'}، بانک: ${payment.checkDetails.bankName || '-'}، سررسید: ${due}${payment.checkDetails.issuerName ? '، صادرکننده: ' + payment.checkDetails.issuerName : ''}`;
            }
            if (payment.type === 'endorse_check' && payment.checkDetails) {
                const due = payment.checkDetails.dueDate ? UI.gregorianToPersian(payment.checkDetails.dueDate) : '-';
                details = `چک شماره ${payment.checkDetails.checkNumber || '-'}، مبلغ ${UI.formatCurrency(payment.checkDetails.amount || payment.amount || 0)}، سررسید ${due}`;
            }
            const paymentDate = payment.date ? UI.gregorianToPersian(payment.date) : '-';
            return `<tr>
                <td>${typeText}</td>
                <td>${paymentDate}</td>
                <td>${UI.formatCurrency(payment.amount || 0)}</td>
                <td>${details || '-'}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="4" class="text-center">پرداختی ثبت نشده است.</td></tr>';

        const printWin = window.open('', '_blank');
        if (!printWin) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return;
        }
        printWin.document.write(`
            <html>
                <head>
                    <title>${title} #${invoice.id}</title>
                    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
                    <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
                    <style>
                        body { font-family: Vazirmatn, sans-serif; direction: rtl; background: #fff; font-size: 14px; }
                        .badge-label { font-weight: 600; }
                        table td, table th { vertical-align: middle; }
                    </style>
                </head>
                <body>
                    <div class="container mt-4">
                        <h2 class="mb-3">${title}</h2>
                        <div class="row mb-2">
                            <div class="col-6"><strong>${personLabel}:</strong> ${personName}</div>
                            <div class="col-6 text-end"><strong>شماره فاکتور:</strong> ${invoice.id}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>تاریخ:</strong> ${invoiceDate}</div>
                            <div class="col-6 text-end"><strong>وضعیت:</strong> ${!(Number(invoice.remainingAmount) > 0.01) ? 'تسویه شده' : 'تسویه نشده'}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>تلفن:</strong> ${personPhone}</div>
                            <div class="col-6 text-end"><strong>آدرس:</strong> ${personAddress}</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-12"><strong>توضیحات:</strong> ${invoice.description || '-'}</div>
                        </div>
                        <h5>اقلام مرجوعی</h5>
                        <table class="table table-bordered">
                            <thead><tr><th>#</th><th>محصول</th><th>ابعاد</th><th>تعداد</th><th>قیمت واحد</th><th>مبلغ</th><th>${identifierLabel}</th></tr></thead>
                            <tbody>${itemsHtml}</tbody>
                        </table>
                        <h5 class="mt-4">پرداخت‌ها</h5>
                        <table class="table table-bordered">
                            <thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead>
                            <tbody>${paymentsHtml}</tbody>
                        </table>
                        <div class="row justify-content-end mt-4">
                            <div class="col-5">
                                <ul class="list-group">
                                    <li class="list-group-item d-flex justify-content-between"><span class="badge-label">جمع کل:</span><span>${UI.formatCurrency(invoice.totalAmount || 0)}</span></li>
                                    <li class="list-group-item d-flex justify-content-between"><span class="badge-label">تخفیف:</span><span>${UI.formatCurrency(invoice.discount || 0)}</span></li>
                                    <li class="list-group-item d-flex justify-content-between"><span class="badge-label">پرداختی:</span><span>${UI.formatCurrency(invoice.paidAmount || 0)}</span></li>
                                    <li class="list-group-item d-flex justify-content-between active"><span class="badge-label">باقی‌مانده:</span><span>${UI.formatCurrency((invoice.totalAmount || 0) - (invoice.discount || 0) + (invoice.tax_amount || 0) - (invoice.paidAmount || 0))}</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </body>
            </html>
        `);
        printWin.document.close();
        printWin.focus();
        setTimeout(() => {
            printWin.print();
            printWin.close();
        }, 250);
    }

    async function load(tableName, filters = null, message = null) {
        UI.showLoader();
        const state = AppConfig.TABLE_STATES[tableName];
        if (!state) {
            console.error('Table state not found for:', tableName);
            UI.hideLoader();
            return;
        }

        state.tableName = tableName;
        let payload = { ...state };

        if (filters) {
            payload = { ...payload, ...filters };
        }

        const response = await Api.call('get_paginated_data', payload);
        
        if (response && renderers[tableName]) {
            renderers[tableName](response.data);
            UI.renderPagination(tableName, response.totalRecords, state);
            UI.updateSortIndicators(tableName, state);
        }
        
        UI.hideLoader();
    }

    // Public API
    return {
        init: function () {
            // Event: Person selection change - update invoice list and product list
            $(document).on('change', '#sales-return-invoice-person, #purchase-return-invoice-person', async function() {
                const isSales = $(this).attr('id').includes('sales');
                const typePrefix = isSales ? 'sales' : 'purchase';
                const personId = $(this).val();
                const invoiceSelect = $(`#${typePrefix}-return-original-invoice-select`);
                const productSelect = $(`#${typePrefix}-return-product-select`);
                const dimensionsSelect = $(`#${typePrefix}-return-dimensions-select`);
                
                invoiceSelect.empty().append('<option value="">همه فاکتورها</option>').prop('disabled', true);
                productSelect.empty().append('<option value="">در حال بارگذاری...</option>');
                productSelect.prop('disabled', true);
                dimensionsSelect.empty().append('<option value="">ابتدا محصول را انتخاب کنید</option>').prop('disabled', true);
                
                if (!personId) {
                    productSelect.empty().append('<option value="">ابتدا ' + (isSales ? 'مشتری' : 'تامین‌کننده') + ' را انتخاب کنید...</option>');
                    return;
                }
                
                // Get invoices for this person to find purchased/sold products
                const invoiceType = isSales ? 'sales' : 'purchase';
                const payload = {
                    tableName: invoiceType + '_invoices',
                    currentPage: 1,
                    limit: -1, // Get all invoices to avoid max page size truncation
                    sortBy: 'id',
                    sortOrder: 'DESC',
                    searchTerm: ''
                };
                
                const response = await Api.call('get_paginated_data', payload);
                
                if (response && response.data) {
                    // Filter invoices for selected person
                    const personField = isSales ? 'customerId' : 'supplierId';
                    const personInvoices = response.data.filter(inv => inv[personField] == personId);
                    // cache for later dimension population
                    if (isSales) lastInvoicesByType.sales = personInvoices; else lastInvoicesByType.purchase = personInvoices;
                    
                    // Populate invoice dropdown
                    invoiceSelect.empty().append('<option value="">همه فاکتورها</option>');
                    if (personInvoices.length > 0) {
                        personInvoices.forEach(inv => {
                            const persianDate = UI.gregorianToPersian(inv.date);
                            const totalFormatted = UI.formatCurrency(inv.totalAmount);
                            invoiceSelect.append(`<option value="${inv.id}">فاکتور #${inv.id} - ${persianDate} - ${totalFormatted}</option>`);
                        });
                        invoiceSelect.prop('disabled', false);
                    }
                    
                    // Extract unique product IDs from invoice items
                    const productIds = new Set();
                    personInvoices.forEach(invoice => {
                        if (invoice.items && invoice.items.length) {
                            invoice.items.forEach(item => {
                                if (item.productId) {
                                    productIds.add(parseInt(item.productId));
                                }
                            });
                        }
                    });
                    
                    // Get full product list and filter
                    const appCache = App.getCache();
                    const availableProducts = appCache.products.filter(p => productIds.has(p.id));
                    
                    productSelect.empty();
                    if (availableProducts.length === 0) {
                        productSelect.append('<option value="">هیچ محصولی با این ' + (isSales ? 'مشتری' : 'تامین‌کننده') + ' معامله نشده است</option>');
                    } else {
                        productSelect.append('<option value="">انتخاب کنید...</option>');
                        availableProducts.forEach(product => {
                            productSelect.append(`<option value="${product.id}">${UI.escapeHtml(product.name)}</option>`);
                        });
                        productSelect.prop('disabled', false);
                    }
                } else {
                    productSelect.empty().append('<option value="">خطا در بارگذاری محصولات</option>');
                }
                
                productSelect.trigger('change');
            });
            
            // Event: Original invoice selection change - filter products based on selected invoice
            $(document).on('change', '#sales-return-original-invoice-select, #purchase-return-original-invoice-select', function() {
                const invoiceId = $(this).val();
                const isSales = $(this).attr('id').includes('sales');
                const typePrefix = isSales ? 'sales' : 'purchase';
                const productSelect = $(`#${typePrefix}-return-product-select`);
                const dimensionsSelect = $(`#${typePrefix}-return-dimensions-select`);
                
                dimensionsSelect.empty().append('<option value="">ابتدا محصول را انتخاب کنید</option>').prop('disabled', true);
                
                const invoices = isSales ? lastInvoicesByType.sales : lastInvoicesByType.purchase;
                
                // Filter invoices if a specific invoice is selected
                const filteredInvoices = invoiceId ? invoices.filter(inv => String(inv.id) === String(invoiceId)) : invoices;
                
                // Extract unique product IDs from filtered invoice items
                const productIds = new Set();
                filteredInvoices.forEach(invoice => {
                    if (invoice.items && invoice.items.length) {
                        invoice.items.forEach(item => {
                            if (item.productId) {
                                productIds.add(parseInt(item.productId));
                            }
                        });
                    }
                });
                
                // Get full product list and filter
                const appCache = App.getCache();
                const availableProducts = appCache.products.filter(p => productIds.has(p.id));
                
                productSelect.empty();
                if (availableProducts.length === 0) {
                    productSelect.append('<option value="">هیچ محصولی در این فاکتور یافت نشد</option>').prop('disabled', true);
                } else {
                    productSelect.append('<option value="">انتخاب کنید...</option>');
                    availableProducts.forEach(product => {
                        productSelect.append(`<option value="${product.id}">${UI.escapeHtml(product.name)}</option>`);
                    });
                    productSelect.prop('disabled', false);
                }
                
                productSelect.trigger('change');
            });

            // Event: Product selection change - populate dimensions from history
            $(document).on('change', '#sales-return-product-select, #purchase-return-product-select', function() {
                const productId = $(this).val();
                const isSales = $(this).attr('id').includes('sales');
                const typePrefix = isSales ? 'sales' : 'purchase';
                const dimensionsSelect = $(`#${typePrefix}-return-dimensions-select`);
                const unitPriceInput = $(`#${typePrefix}-return-unit-price-input`);
                const selectedInvoiceId = $(`#${typePrefix}-return-original-invoice-select`).val();
                
                if (!productId) {
                    dimensionsSelect.empty().append('<option value="">ابتدا محصول را انتخاب کنید</option>').prop('disabled', true);
                    unitPriceInput.val('');
                    return;
                }
                
                const appCache = App.getCache();
                const selectedProduct = appCache.products.find(p => p.id == productId);
                // Build dimensions list from person-specific invoices history
                let invoices = isSales ? lastInvoicesByType.sales : lastInvoicesByType.purchase;
                
                // Filter by selected invoice if one is selected
                if (selectedInvoiceId) {
                    invoices = invoices.filter(inv => String(inv.id) === String(selectedInvoiceId));
                }
                
                const dimsSet = new Set();
                invoices.forEach(inv => {
                    if (inv.items && inv.items.length) {
                        inv.items.forEach(item => {
                            if (String(item.productId) === String(productId) && item.dimensions) {
                                dimsSet.add(item.dimensions);
                            }
                        });
                    }
                });

                dimensionsSelect.empty();
                if (dimsSet.size === 0) {
                    dimensionsSelect.append('<option value="">برای این شخص ابعادی یافت نشد</option>').prop('disabled', true);
                } else {
                    dimensionsSelect.append('<option value="">انتخاب کنید...</option>');
                    Array.from(dimsSet).forEach(d => dimensionsSelect.append(`<option value="${d}">${d}</option>`));
                    dimensionsSelect.prop('disabled', false);
                    if (dimsSet.size === 1) {
                        const onlyDim = Array.from(dimsSet)[0];
                        dimensionsSelect.val(onlyDim).trigger('change'); // Trigger change to load identifiers
                        const histPrice = getHistoricalUnitPrice(productId, onlyDim, isSales);
                        if (histPrice) unitPriceInput.val(Number(histPrice).toLocaleString('en-US'));
                    }
                }

                // Set unit price if available
                if (!unitPriceInput.val() && selectedProduct && selectedProduct.price) {
                    unitPriceInput.val(Number(selectedProduct.price).toLocaleString('en-US'));
                }
            });

            // Event: Dimensions selection change - set price from history if available AND load identifiers
            $(document).on('change', '#sales-return-dimensions-select, #purchase-return-dimensions-select', async function() {
                const isSales = $(this).attr('id').includes('sales');
                const typePrefix = isSales ? 'sales' : 'purchase';
                const productId = $(`#${typePrefix}-return-product-select`).val();
                const dim = $(this).val();
                const unitPriceInput = $(`#${typePrefix}-return-unit-price-input`);
                const identifiersSelect = $(`#${typePrefix}-return-identifiers-select`);

                if (!productId || !dim) {
                    identifiersSelect.prop('disabled', true).html('<option value="">ابتدا محصول و ابعاد را انتخاب کنید</option>');
                    return;
                }

                // Set price from history
                const histPrice = getHistoricalUnitPrice(productId, dim, isSales);
                if (histPrice) {
                    unitPriceInput.val(Number(histPrice).toLocaleString('en-US'));
                }

                // Load available identifiers
                await loadAvailableIdentifiers(typePrefix, productId, dim);
            });

            // Event: Add item button
            $(document).on('click', '#add-sales-return-item-btn', () => addReturnInvoiceItem('sales'));
            $(document).on('click', '#add-purchase-return-item-btn', () => addReturnInvoiceItem('purchase'));

            // Event: Remove item
            $(document).on('click', '.remove-item', function () {
                const type = $(this).closest('.modal').attr('id').includes('sales') ? 'sales' : 'purchase';
                const $row = $(this).closest('tr');
                // FIX: Subtract this row's auto-applied discount from the discount field
                const itemDiscount = parseLocalizedInt($row.attr('data-auto-discount')) || 0;
                if (itemDiscount) {
                    const currentDiscount = parseLocalizedInt($(`#${type}-return-invoice-discount`).val());
                    const newDiscount = Math.max(0, currentDiscount - itemDiscount);
                    $(`#${type}-return-invoice-discount`).val(UI.formatCurrency(newDiscount));
                }
                $row.remove();
                calculateReturnInvoiceTotals(type);
            });

            // Event: Add payment button
            $(document).on('click', '#add-sales-return-payment-btn', () => addReturnPayment('sales'));
            $(document).on('click', '#add-purchase-return-payment-btn', () => addReturnPayment('purchase'));

            // Event: Remove payment
            $(document).on('click', '.remove-payment', function () {
                const type = $(this).closest('.modal').attr('id').includes('sales') ? 'sales' : 'purchase';
                $(this).closest('tr').remove();
                calculateReturnInvoiceTotals(type);
            });

            // Event: Payment type change
            $(document).on('change', '#sales-return-payment-type', function () {
                updateReturnPaymentMethod('sales', $(this).val());
            });
            $(document).on('change', '#purchase-return-payment-type', function () {
                updateReturnPaymentMethod('purchase', $(this).val());
            });

            // (Discount removed for return invoices)

            // Event: Discount field change - recalculate totals
            $(document).on('input change', '#sales-return-invoice-discount, #purchase-return-invoice-discount', function() {
                const isSales = $(this).attr('id').includes('sales');
                const type = isSales ? 'sales' : 'purchase';
                calculateReturnInvoiceTotals(type);
            });

            // Event: Save button
            $(document).on('click', '#save-sales-return-invoice-btn', () => saveReturnInvoice('sales_return'));
            $(document).on('click', '#save-purchase-return-invoice-btn', () => saveReturnInvoice('purchase_return'));

            // Event: New return invoice button
            $(document).on('click', '#new-sales-return-btn', () => openReturnInvoiceModal('sales_return'));
            $(document).on('click', '#new-purchase-return-btn', () => openReturnInvoiceModal('purchase_return'));

            // Event: Return invoice type filter
            $(document).on('change', '#return-invoice-type-filter', function() {
                const filterValue = $(this).val();
                const tableName = 'return_invoices';
                const filters = filterValue ? { invoiceType: filterValue } : {};
                load(tableName, filters);
            });

            // Event: Edit button (always fetch from server for fresh payments/items)
            $(document).on('click', '.btn-edit[data-type="salesReturn"], .btn-edit[data-type="purchaseReturn"]', async function () {
                const tmp = $(this).data('entity') || {};
                const id = tmp.id || $(this).data('id');
                const isSales = $(this).data('type') === 'salesReturn';
                const entityType = isSales ? 'salesReturn' : 'purchaseReturn';
                const type = isSales ? 'sales_return' : 'purchase_return';
                let entity = null;
                if (id) {
                    UI.showLoader();
                    entity = await Api.call('get_entity_by_id', { entityType, id });
                    UI.hideLoader();
                }
                openReturnInvoiceModal(type, entity || tmp);
            });

            // Event: Delete button
            $(document).on('click', '.btn-delete[data-type="salesReturn"], .btn-delete[data-type="purchaseReturn"]', function () {
                const id = $(this).data('id');
                const type = $(this).data('type');
                deleteReturnInvoice(type, id);
            });

            // Event: Print button - only for return invoices
            $(document).on('click', '#return-invoices-table-body .btn-print, .btn-print[data-type="salesReturn"], .btn-print[data-type="purchaseReturn"]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const entity = $(this).data('entity');
                const type = $(this).data('return-type') || $(this).data('type');
                if (!entity) {
                    UI.showError('اطلاعات فاکتور مرجوعی برای چاپ یافت نشد.');
                    return;
                }
                printReturnInvoice(entity, type);
            });

            // Toggle for showing invoice details
            $('#return-invoices-show-details').on('change', function () {
                load('return_invoices');
            });
        },
        load,
        openModal: openReturnInvoiceModal
    };
})();

// Make it available globally
window.ReturnInvoiceManager = ReturnInvoiceManager;
