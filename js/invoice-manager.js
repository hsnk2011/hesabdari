// /js/invoice-manager.js

const InvoiceManager = (function () {
    // While prepareInvoiceModal fills header/items, change handlers fire many times.
    // Skip toast spam and price-history fetches until the form is ready.
    let isInvoicePopulating = false;

    function allInvoiceItemSerialLabels(item) {
        const current = Array.isArray(item?.serial_labels) ? item.serial_labels : [];
        const returned = Array.isArray(item?.returned_labels) ? item.returned_labels : [];
        return [...new Set([...current, ...returned])];
    }

    function formatInvoiceItemSerials(item) {
        const current = Array.isArray(item?.serial_labels) ? item.serial_labels : [];
        const returned = Array.isArray(item?.returned_labels) ? item.returned_labels : [];
        const parts = current.slice();
        returned.forEach(label => {
            if (!parts.includes(label)) {
                parts.push(`${label} (مرجوع)`);
            }
        });
        return parts.length ? parts.join(', ') : '-';
    }

    // --- Private Helper Functions for Rendering ---
    function _renderInvoiceItemsDetails(inv, colspan = 10) {
        const span = Math.max(1, parseInt(colspan, 10) || 10);
        if (!inv.items || inv.items.length === 0) {
            return `<tr class="invoice-details-row"><td colspan="${span}"><div class="p-2 text-muted text-center small">بدون آیتم</div></td></tr>`;
        }
        
        let itemsHtml = `<tr class="invoice-details-row"><td colspan="${span}" class="p-0"><div class="bg-light border-top border-bottom p-2 ms-4">`;
        itemsHtml += '<table class="table table-sm table-bordered mb-0 small">';
        itemsHtml += '<thead class="table-secondary"><tr><th>ردیف</th><th>محصول</th><th>ابعاد</th><th>تعداد</th><th>شناسه</th><th>قیمت واحد</th><th>جمع</th></tr></thead>';
        itemsHtml += '<tbody>';
        
        inv.items.forEach((item, index) => {
            const itemTotal = (item.quantity || 0) * (item.unitPrice || 0);
            const returnedBadge = item.returned_ids && item.returned_ids.length > 0 
                ? `<span class="badge bg-danger ms-1">مرجوعی: ${item.returned_ids.length}</span>` 
                : '';
            const isService = item.is_service == 1;
            const itemName = isService ? (item.item_description || item.productName || 'خدمات') : (item.productName || '-');
            const serviceBadge = isService ? '<span class="badge bg-info ms-1">خدمات</span>' : '';
            const serialText = isService ? '-' : formatInvoiceItemSerials(item);
            itemsHtml += `<tr${isService ? ' class="table-info"' : ''}>
                <td>${index + 1}</td>
                <td>${itemName}${serviceBadge}${returnedBadge}</td>
                <td>${isService ? '-' : (item.dimensions || '-')}</td>
                <td>${item.quantity || 0}</td>
                <td>${isService ? '-' : serialText}</td>
                <td>${UI.formatCurrency(item.unitPrice || 0)}</td>
                <td>${UI.formatCurrency(itemTotal)}</td>
            </tr>`;
        });
        
        itemsHtml += '</tbody></table></div></td></tr>';
        return itemsHtml;
    }

    function _renderInvoiceRow(inv, type, showDetails = false) {
        const isSettled = inv.remainingAmount < 0.01;
        const rowStatusClass = isSettled ? 'status-settled' : 'status-unsettled';
        const badgeStatusClass = isSettled ? 'bg-success' : 'bg-warning';
        const statusText = isSettled ? 'تسویه شده' : 'تسویه نشده';
        const personName = type === 'sales' ? inv.customerName : inv.supplierName;
        const entityType = type === 'sales' ? 'salesInvoice' : 'purchaseInvoice';
        const persianDateStr = UI.gregorianToPersian(inv.date);
        const perm_prefix = type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        const consignment_perm = 'manage_consignment';

        // Check if invoice has returned items
        const hasReturnedItems = inv.items && inv.items.some(item => item.returned_ids && item.returned_ids.length > 0);
        const returnIcon = hasReturnedItems ? '<i class="bi bi-arrow-return-left text-danger ms-1" title="دارای کالای مرجوعی"></i>' : '';

        // Check if invoice has no serialized items (for sales invoices) - exclude service items
        const productItems = inv.items ? inv.items.filter(item => item.is_service != 1) : [];
        const hasNoSerials = type === 'sales' && productItems.length > 0 &&
            productItems.every(item => (!item.serialized_ids || item.serialized_ids.length === 0) &&
                (!item.returned_ids || item.returned_ids.length === 0));
        const noSerialIcon = hasNoSerials ? '<i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="بدون شناسه انبار - نیاز به تطبیق"></i>' : '';

        const isOrderWarehouse = type === 'purchase' && (
            String(inv.is_order_warehouse) === '1'
            || inv.warehouseType === 'order'
            || inv.warehouse_type === 'order'
        );
        const orderWhTitle = inv.warehouseName || inv.warehouse_name || 'انبار سفارش';
        const orderWarehouseBadge = isOrderWarehouse
            ? `<span class="badge text-bg-primary ms-1 invoice-order-warehouse-badge" title="${UI.escapeHtml(orderWhTitle)}"><i class="bi bi-building-gear me-1"></i>سفارش کارخانه</span>`
            : '';

        // Delivery status
        const deliveryStatus = inv.delivery_status || 'preparing';
        const deliveryStatusMap = {
            'preparing': { label: 'در حال آماده‌سازی', badge: 'bg-secondary', icon: 'bi-hourglass-split' },
            'ready': { label: 'آماده تحویل', badge: 'bg-info', icon: 'bi-box-seam' },
            'delivered': { label: 'تحویل شده', badge: 'bg-success', icon: 'bi-check2-circle' }
        };
        const ds = deliveryStatusMap[deliveryStatus] || deliveryStatusMap['preparing'];

        const row = $(`<tr class="${rowStatusClass}${isOrderWarehouse ? ' purchase-order-warehouse-row' : ''}">
            <td>${returnIcon}${noSerialIcon}${UI.escapeHtml(String(inv.invoice_number || inv.id))}${orderWarehouseBadge}</td>
            <td>${persianDateStr}</td>
            <td>${personName || ''}${isOrderWarehouse ? `<div class="small text-primary mt-1"><i class="bi bi-building-gear me-1"></i>${UI.escapeHtml(orderWhTitle)}</div>` : ''}</td>
            <td>${UI.formatCurrency(inv.totalAmount)}</td>
            <td class="text-info">${UI.formatCurrency(inv.discount)}</td>
            <td>${UI.formatCurrency(inv.paidAmount)}</td>
            <td class="fw-bold">${UI.formatCurrency(inv.remainingAmount)}</td>
            <td><span class="badge ${badgeStatusClass}">${statusText}</span></td>
            <td>
                <div class="dropdown delivery-status-dropdown">
                    <button class="btn btn-sm ${ds.badge} dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-permission-action="manage_${perm_prefix}">
                        <i class="bi ${ds.icon} me-1"></i>${ds.label}
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item delivery-status-option ${deliveryStatus === 'preparing' ? 'active' : ''}" href="#" data-status="preparing" data-type="${type}" data-id="${inv.id}"><i class="bi bi-hourglass-split me-2"></i>در حال آماده‌سازی</a></li>
                        <li><a class="dropdown-item delivery-status-option ${deliveryStatus === 'ready' ? 'active' : ''}" href="#" data-status="ready" data-type="${type}" data-id="${inv.id}"><i class="bi bi-box-seam me-2"></i>آماده تحویل</a></li>
                        <li><a class="dropdown-item delivery-status-option ${deliveryStatus === 'delivered' ? 'active' : ''}" href="#" data-status="delivered" data-type="${type}" data-id="${inv.id}"><i class="bi bi-check2-circle me-2"></i>تحویل شده</a></li>
                    </ul>
                </div>
            </td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-secondary btn-to-consignment" title="ارسال به امانی" data-type="${type}" data-id="${inv.id}" data-permission-action="${consignment_perm}"><i class="bi bi-truck"></i></button>
                    ${type === 'purchase'
                        ? `<button type="button" class="btn btn-sm btn-outline-primary btn-copy-purchase-invoice" title="کپی فاکتور" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-clipboard"></i></button>`
                        : `<button class="btn btn-sm btn-outline-primary btn-clone-invoice" title="کپی فاکتور" data-type="${type}" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-clipboard"></i></button>`}
                    <button class="btn btn-sm btn-info btn-print" title="چاپ" data-type="${type}"><i class="bi bi-printer"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-dark btn-invoice-history" title="تاریخچه و اقلام مرتبط" data-type="${type}" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-clock-history"></i></button>
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" data-type="${entityType}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-danger btn-delete" title="حذف" data-type="${entityType}" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        row.find('.btn-edit, .btn-print').data('entity', inv);
        
        // If showDetails is enabled, add details row after main row
        if (showDetails) {
            const detailsHtml = _renderInvoiceItemsDetails(inv);
            return row.add($(detailsHtml));
        }
        
        return row;
    }

    function _renderConsignmentRow(inv, type, showDetails = false) {
        const statusClass = inv.remainingAmount == 0 ? 'bg-success' : 'bg-warning';
        const statusText = inv.remainingAmount == 0 ? 'تسویه شده' : 'تسویه نشده';
        const personName = type === 'sales' ? inv.customerName : inv.supplierName;
        const entityType = type === 'sales' ? 'salesInvoice' : 'purchaseInvoice';
        const persianDateStr = UI.gregorianToPersian(inv.date);
        const perm_prefix = type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        const consignment_perm = 'manage_consignment';

        const row = $(`<tr>
            <td>${UI.escapeHtml(String(inv.invoice_number || inv.id))}</td>
            <td>${persianDateStr}</td>
            <td>${personName || ''}</td>
            <td>${UI.formatCurrency(inv.totalAmount)}</td>
            <td>${UI.formatCurrency(inv.discount || 0)}</td>
            <td><span class="badge ${statusClass}">${statusText}</span></td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-info btn-print" title="چاپ" data-type="${type}"><i class="bi bi-printer"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-dark btn-invoice-history" title="تاریخچه و اقلام مرتبط" data-type="${type}" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-clock-history"></i></button>
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" data-type="${entityType}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-pencil-square"></i></button> 
                    <button class="btn btn-sm btn-success btn-from-consignment" title="بازگردانی از امانی" data-type="${type}" data-id="${inv.id}" data-permission-action="${consignment_perm}"><i class="bi bi-arrow-return-left"></i></button> 
                    <button class="btn btn-sm btn-danger btn-delete" title="حذف" data-type="${entityType}" data-id="${inv.id}" data-permission-action="manage_${perm_prefix}"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        row.find('.btn-edit, .btn-print').data('entity', inv);
        if (showDetails) {
            return row.add($(_renderInvoiceItemsDetails(inv, 7)));
        }
        return row;
    }

    const renderers = {
        sales_invoices: (data) => {
            const body = $('#sales-invoices-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="10" class="text-center">فاکتور فروشی یافت نشد.</td></tr>');
            const showDetails = $('#sales-invoices-show-details').is(':checked');
            data.forEach(inv => body.append(_renderInvoiceRow(inv, 'sales', showDetails)));
        },
        purchase_invoices: (data) => {
            const body = $('#purchase-invoices-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="10" class="text-center">فاکتور خریدی یافت نشد.</td></tr>');
            const showDetails = $('#purchase-invoices-show-details').is(':checked');
            data.forEach(inv => body.append(_renderInvoiceRow(inv, 'purchase', showDetails)));
        },
        consignment_sales: (data) => {
            const body = $('#consignment-sales-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="7" class="text-center">فاکتور امانی (فروش) یافت نشد.</td></tr>');
            const showDetails = $('#consignment-invoices-show-details').is(':checked');
            data.forEach(inv => body.append(_renderConsignmentRow(inv, 'sales', showDetails)));
        },
        consignment_purchases: (data) => {
            const body = $('#consignment-purchases-table-body').empty();
            if (!data || !data.length) return body.html('<tr><td colspan="7" class="text-center">فاکتور امانی (خرید) یافت نشد.</td></tr>');
            const showDetails = $('#consignment-invoices-show-details').is(':checked');
            data.forEach(inv => body.append(_renderConsignmentRow(inv, 'purchase', showDetails)));
        }
    };

    function parseInvoiceNumber(raw) {
        const n = Number(String(raw || '').replace(/,/g, '').trim());
        return Number.isFinite(n) ? n : 0;
    }

    /** مساحت متر مربع از ابعاد ردیف فاکتور — قواعد مشترک (نگاشت/آستانه از تنظیمات) */
    function getInvoiceRowArea(row) {
        let dims = row.find('.dimensions-select').val();
        if (dims === 'custom') {
            dims = (row.find('.custom-dimension-input').val() || '').trim();
        }
        if (!dims) return 0;
        return UI.dimensionArea(String(dims).trim().toLowerCase().replace(/\*/g, 'x').replace(/×/g, 'x'));
    }

    function applyMeterPriceToUnit(row) {
        if (row.find('.price-entry-mode').val() !== 'meter') return;
        const area = getInvoiceRowArea(row);
        const meterPrice = parseInvoiceNumber(row.find('.meter-price').val());
        if (area > 0 && meterPrice > 0) {
            const unit = Math.round(area * meterPrice);
            row.find('.unit-price').val(unit.toLocaleString('en-US'));
        }
    }

    /** پر کردن قیمت متری از روی قیمت واحد ÷ متراژ */
    function applyUnitPriceToMeter(row) {
        const area = getInvoiceRowArea(row);
        const unitPrice = parseInvoiceNumber(row.find('.unit-price').val());
        if (area > 0 && unitPrice > 0) {
            const meter = Math.round(unitPrice / area);
            row.find('.meter-price').val(meter.toLocaleString('en-US'));
        }
    }

    function syncPriceEntryMode(row) {
        const mode = row.find('.price-entry-mode').val() || 'unit';
        const meterInput = row.find('.meter-price');
        const unitInput = row.find('.unit-price');
        if (mode === 'meter') {
            meterInput.show();
            unitInput.prop('readonly', true).attr('title', 'محاسبه‌شده از قیمت متری × متراژ');
            // با باز کردن حالت متری، از قیمت واحد فعلی مقدار متری را بساز
            applyUnitPriceToMeter(row);
        } else {
            meterInput.hide();
            unitInput.prop('readonly', false).removeAttr('title');
            // همگام‌سازی پنهان فیلد متری برای سوییچ بعدی
            applyUnitPriceToMeter(row);
        }
        calculateInvoiceItemTotal(row);
    }

    function calculateInvoiceItemTotal(row) {
        const quantity = parseInt(row.find('.quantity').val()) || 0;
        const unitPrice = parseInvoiceNumber(row.find('.unit-price').val());
        row.find('.item-total').val(UI.formatCurrency(quantity * unitPrice));
    }

    function calculateInvoiceTotals(type, opts = {}) {
        const itemsContainer = $(`#${type}-invoice-items-container`);
        const paymentsContainer = $(`#${type}-invoice-payments-container`);
        let total = 0, totalQuantity = 0, totalArea = 0;

        itemsContainer.find('.dynamic-row').each(function () {
            const row = $(this);
            if (row.find('.price-entry-mode').val() === 'meter') {
                applyMeterPriceToUnit(row);
            }
            const unitPrice = parseInvoiceNumber(row.find('.unit-price').val());
            const quantity = Number(row.find('.quantity').val()) || 0;
            total += UI.roundMoney(unitPrice * quantity);
            totalQuantity += quantity;
            const area = getInvoiceRowArea(row);
            totalArea += area * quantity;
        });

        const discount = parseInvoiceNumber($(`#${type}-invoice-discount`).val());
        
        // While rows are still being filled, discount is already set but the
        // running total is incomplete — don't toast that as a user error.
        if (discount > total + 0.009) {
            $(`#${type}-invoice-discount`).addClass('is-invalid');
            if (!isInvoicePopulating && !opts.quiet) {
                UI.showError('تخفیف نمی‌تواند بیشتر از جمع کل باشد.');
            }
        } else {
            $(`#${type}-invoice-discount`).removeClass('is-invalid');
        }
        
        let paid = 0;
        paymentsContainer.find('.dynamic-row').each(function () {
            const amount = UI.roundMoney(parseInvoiceNumber($(this).find('.payment-amount').val()));
            const txn = $(this).find('.payment-transaction-type').val() || (type === 'sales' ? 'receipt' : 'payment');
            const settling = (type === 'sales' && txn === 'receipt') || (type === 'purchase' && txn === 'payment');
            paid += settling ? amount : -amount;
        });

        const afterDiscount = total - discount;
        const taxRate = parseFloat($(`#${type}-invoice-tax-rate`).val()) || 0;
        const taxAmount = Math.round(afterDiscount * taxRate / 100);
        $(`#${type}-invoice-tax-amount`).val(taxAmount.toLocaleString('en-US'));
        const finalAmount = afterDiscount + taxAmount;

        $(`#${type}-invoice-total-quantity`).text(totalQuantity.toLocaleString('fa-IR'));
        // toFixed() returns a string, and String.toLocaleString(locale) ignores the
        // locale arg - convert back to Number so fa-IR grouping actually applies.
        $(`#${type}-invoice-total-area`).text(`${Number(totalArea.toFixed(2)).toLocaleString('fa-IR')} متر مربع`);
        $(`#${type}-invoice-total`).text(UI.formatCurrency(total));
        $(`#${type}-invoice-grand-total`).text(UI.formatCurrency(finalAmount));
        $(`#${type}-invoice-paid`).text(UI.formatCurrency(paid));
        $(`#${type}-invoice-remaining`).text(UI.formatCurrency(finalAmount - paid));
    }

    // موجودی قابل فروش (بدون فرش‌های فروخته‌شدهِ تحویل‌نشده در انبار سفارش)
    function availableQty(stockEntry) {
        const v = stockEntry.available_quantity ?? stockEntry.total_quantity;
        return Number(v) || 0;
    }

    function renumberInvoiceItemRows(container) {
        const $c = container && container.jquery ? container : $(container);
        if (!$c || !$c.length) return;
        $c.find('.dynamic-row.invoice-item-card').each(function (idx) {
            const n = idx + 1;
            $(this).attr('data-row-index', n);
            $(this).find('.invoice-row-number').text(n);
        });
    }

    function renumberInvoicePaymentRows(container) {
        const $c = container && container.jquery ? container : $(container);
        if (!$c || !$c.length) return;
        $c.find('.dynamic-row.payment-row').each(function (idx) {
            const n = idx + 1;
            $(this).attr('data-row-index', n);
            $(this).find('.invoice-row-number').text(n);
        });
    }

    function addInvoiceItemRow(containerSelector, item = null) {
        const isSales = containerSelector.attr('id').includes('sales');
        const modalId = isSales ? '#salesInvoiceModal' : '#purchaseInvoiceModal';
        const appCache = App.getCache();
        let productList = appCache.products || [];

        const originalInvoiceItems = $(containerSelector).closest('form').data('original-items') || [];

        if (isSales) {
            // فقط طرح‌هایی که حداقل یک موجودی دارند
            productList = (appCache.products || []).filter(p => {
                if (item && item.productId && String(item.productId) === String(p.id)) {
                    return true; // هنگام ویرایش، طرح فعلی ردیف حتی بدون موجودی بماند
                }
                if (Number(p.total_stock) > 0) return true;
                return (p.stock || []).some(s => availableQty(s) > 0);
            });
        }

        let productOptions = productList.map(p => {
            const dc = p.design_code || '';
            const serials = (p.stock || []).map(s => (s.serial_ids || []).join(',')).filter(Boolean).join(',');
            return '<option value="' + p.id + '" data-design-code="' + dc + '" data-serials="' + serials + '">' + p.name + (dc ? ' (' + dc + ')' : '') + '</option>';
        }).join('');

        const newProductButtonHtml = isSales ? '' : `<button class="btn btn-outline-success btn-add-new-product-inline" type="button" title="افزودن محصول جدید"><i class="bi bi-plus-lg"></i></button>`;
        const productSelectionHtml = `<div class="input-group">
                                        <select class="form-select product-select"><option></option>${productOptions}</select>
                                        ${newProductButtonHtml}
                                   </div>
                                      <input type="text" class="form-control new-product-name mt-1" style="display:none;" placeholder="نام محصول جدید...">
                                      <div class="new-product-images-wrap mt-1" style="display:none;">
                                        <input type="file" class="new-product-images visually-hidden" accept="image/*" multiple tabindex="-1" aria-hidden="true">
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-pick-product-images w-100">
                                          <i class="bi bi-images"></i> انتخاب / افزودن تصویر
                                        </button>
                                      </div>
                                      <small class="new-product-images-hint text-muted d-block" style="display:none;"></small>
                                      <div class="new-product-images-preview mt-1" style="display:none;"></div>`;

        // Check if this item has been returned (identifiers have status='returned')
        const isReturned = item && item.is_returned === true;
        const returnedClass = isReturned ? 'bg-light' : '';
        const returnedTitle = isReturned ? 'title="این ردیف قابل ویرایش نیست (کالا مرجوع شده)"' : '';

        // In purchase: serial field is editable text for new items, readonly for saved items
        // In sales: serial field is a multi-select dropdown (populated from stock cache)
        const hasSavedSerials = item && allInvoiceItemSerialLabels(item).length > 0;
        const serialReadonly = hasSavedSerials ? 'readonly' : '';
        const serialPlaceholder = hasSavedSerials ? '' : 'خودکار / دلخواه';
        const serialTitle = 'شناسه دلخواه وارد کنید یا خالی بگذارید (خودکار)';
        const serialFieldHtml = isSales
            ? '<select class="form-select form-select-sm item-serial-select" multiple style="width:100%"></select>'
            : `<input type="text" class="form-control form-control-sm item-serial-display" ${serialReadonly} placeholder="${serialPlaceholder}" title="${serialTitle}">`;

        const clientKey = 'r_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        const row = $(`<div class="row dynamic-row invoice-item-card align-items-end g-2 ${returnedClass}" data-client-key="${clientKey}" ${returnedTitle}>
            <input type="hidden" class="item-id" value="${item ? item.id : ''}">
            <div class="col-12 invoice-row-toolbar d-md-none">
                <span class="invoice-row-label"><span class="invoice-row-number badge text-bg-secondary me-1">0</span><i class="bi bi-box-seam me-1"></i>کالا</span>
                <button type="button" class="btn btn-outline-danger btn-sm remove-item-btn" title="حذف"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="col-auto d-none d-md-flex invoice-item-row-num align-items-end">
                <span class="invoice-row-number" title="شماره ردیف">0</span>
            </div>
            <div class="col-md-3 product-selection-container"><label class="form-label">طرح فرش</label>${productSelectionHtml}</div>
            <div class="col-md-2 dimensions-container"><label class="form-label">ابعاد</label><select class="form-select dimensions-select" disabled><option value="">ابتدا طرح را انتخاب کنید</option></select></div>
            <div class="col-md-2"><label class="form-label">شناسه‌ها</label>${serialFieldHtml}</div>
            <div class="col-6 col-md-1"><label class="form-label">تعداد</label><input type="number" class="form-control quantity" value="1" min="1"></div>
            <div class="col-md-2 col-12 invoice-price-col">
                <label class="form-label">قیمت</label>
                <select class="form-select form-select-sm price-entry-mode mb-1">
                    <option value="unit" selected>قیمت واحد</option>
                    <option value="meter">قیمت متری</option>
                </select>
                <input type="text" class="form-control form-control-sm meter-price numeric-input mb-1" value="0" placeholder="مبلغ متری" style="display:none;" title="قیمت هر متر مربع">
                <input type="text" class="form-control unit-price numeric-input" value="0" placeholder="قیمت واحد">
            </div>
            <div class="col-6 col-md-1"><label class="form-label">جمع</label><input type="text" class="form-control item-total" readonly></div>
            <div class="col-md-1 d-none d-md-flex align-items-end"><button type="button" class="btn btn-danger btn-sm remove-item-btn" title="حذف"><i class="bi bi-x-lg"></i></button></div>
            <div class="col-12 price-suggestions-wrap">
                <div class="price-suggestions" hidden></div>
            </div>
        </div>`);

        containerSelector.append(row);

        row.find('.product-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            width: '100%',
            placeholder: "جستجو با نام / شناسه / کد طرح...",
            dropdownParent: $(modalId),
            matcher: function(params, data) {
                if (!params.term || params.term.trim() === '') return data;
                var term = params.term.toLowerCase();
                // Search in product name (text)
                if (data.text && data.text.toLowerCase().indexOf(term) > -1) return data;
                // Search by option value (product ID)
                if (data.id && String(data.id) === term) return data;
                var el = data.element;
                if (el) {
                    // Search by design_code  
                    var dc = el.getAttribute('data-design-code') || '';
                    if (dc && dc.toLowerCase().indexOf(term) > -1) return data;
                    // Search by inventory serial IDs (INV-XXXXXX)
                    var serials = el.getAttribute('data-serials') || '';
                    if (serials && serials.toLowerCase().indexOf(term) > -1) return data;
                }
                return null;
            }
        });

        // Initialize serial Select2 for sales invoices (empty state)
        if (isSales) {
            row.find('.item-serial-select').select2({
                theme: 'bootstrap-5',
                dir: 'rtl',
                width: '100%',
                placeholder: 'ابتدا کالا و ابعاد را انتخاب کنید',
                dropdownParent: $(modalId),
            });
        }

        if (item) {
            // Avoid .trigger('change'): that re-runs populateDimensions without the
            // saved size and stacks price-history requests for every row.
            const productSelect = row.find('.product-select');
            productSelect.val(item.productId);
            if (productSelect.hasClass('select2-hidden-accessible')) {
                productSelect.trigger('change.select2');
            }
            populateDimensions(productSelect, item.dimensions);
            row.find('.quantity').val(item.quantity);
            row.find('.unit-price').val(Number(item.unitPrice).toLocaleString('en-US'));

            // Populate linked serial IDs (from inventory_items)
            if (isSales) {
                populateSerialSelect(row, allInvoiceItemSerialLabels(item), true);
            } else if (allInvoiceItemSerialLabels(item).length > 0) {
                row.find('.item-serial-display').val(allInvoiceItemSerialLabels(item).join(', '));
            }

            // Make row readonly if identifiers were returned
            if (isReturned) {
                row.find('.product-select').prop('disabled', true);
                row.find('.dimensions-select').prop('disabled', true);
                row.find('.item-serial-select').prop('disabled', true);
                row.find('.quantity').prop('readonly', true);
                row.find('.unit-price').prop('readonly', true);
                row.find('.meter-price').prop('readonly', true);
                row.find('.price-entry-mode').prop('disabled', true);
                row.find('.remove-item-btn').prop('disabled', true).addClass('disabled');
            }

            calculateInvoiceItemTotal(row);
            if (!isInvoicePopulating) {
                refreshRowPriceSuggestions(row);
            }
        }
        renumberInvoiceItemRows(containerSelector);
    }

    function getInvoiceRowType(row) {
        const formId = row.closest('form').attr('id') || '';
        return formId.includes('purchase') ? 'purchase' : 'sales';
    }

    function getInvoiceRowDimensions(row) {
        let dims = row.find('.dimensions-select').val() || '';
        if (dims === 'custom') {
            dims = (row.find('.custom-dimension-input').val() || '').trim();
        }
        return String(dims || '').trim();
    }

    function getInvoicePersonId(type) {
        const sel = type === 'sales' ? '#sales-invoice-customer' : '#purchase-invoice-supplier';
        return parseInt($(sel).val()) || 0;
    }

    function getInvoiceEditingId(type) {
        return parseInt($(`#${type}-invoice-id`).val()) || 0;
    }

    function matchLabelForPrice(p, type) {
        const similar = !p.is_exact_product;
        if (String(p.match || '').startsWith('person_exact')) {
            return type === 'sales'
                ? (similar ? 'همین مشتری · محصول مشابه' : 'همین مشتری · همین سایز')
                : (similar ? 'همین تأمین‌کننده · محصول مشابه' : 'همین تأمین‌کننده · همین سایز');
        }
        if (String(p.match || '').startsWith('person_similar') || p.match === 'person_similar') {
            return type === 'sales'
                ? (similar ? 'همین مشتری · محصول مشابه' : 'همین مشتری')
                : (similar ? 'همین تأمین‌کننده · محصول مشابه' : 'همین تأمین‌کننده');
        }
        if (p.match === 'similar_product' || String(p.match || '').includes('similar_product')) {
            return 'محصول مشابه';
        }
        if (p.match === 'global_exact') {
            return 'سایر · همین سایز';
        }
        return 'آخرین فاکتورها';
    }

    function getRowProductName(row) {
        const newNameInput = row.find('.new-product-name');
        if (newNameInput.length && newNameInput.is(':visible')) {
            return (newNameInput.val() || '').trim();
        }
        const opt = row.find('.product-select option:selected');
        if (!opt.length || !opt.val()) return '';
        // Strip trailing " (design_code)" added in option text
        return String(opt.text() || '').replace(/\s*\([^)]*\)\s*$/, '').trim();
    }

    function renderPriceSuggestions(row, prices, type) {
        const box = row.find('.price-suggestions');
        const wrap = row.find('.price-suggestions-wrap');
        if (!box.length) return;
        if (!prices || !prices.length) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        const chips = prices.map((p) => {
            const meter = Number(p.meter_price) || 0;
            if (meter <= 0) return '';
            const unit = Number(p.unit_price) || 0;
            const dateFa = p.date ? UI.gregorianToPersian(p.date) : '';
            const dims = p.dimensions ? UI.escapeHtml(p.dimensions) : '';
            const who = p.person_name ? UI.escapeHtml(p.person_name) : '';
            const prod = p.product_name ? UI.escapeHtml(p.product_name) : '';
            const match = matchLabelForPrice(p, type);
            const titleParts = ['قیمت متری', match];
            if (prod) titleParts.push(prod);
            if (who) titleParts.push(who);
            if (dims) titleParts.push('از سایز ' + dims);
            if (dateFa) titleParts.push(dateFa);
            if (p.invoice_id) titleParts.push('#' + p.invoice_id);
            if (unit > 0) titleParts.push('معادل واحد: ' + UI.formatCurrency(unit));
            const title = titleParts.join(' · ');
            const badgeClass = [
                'price-suggest-chip',
                p.from_person ? 'is-person' : '',
                !p.is_exact_product ? 'is-similar' : ''
            ].filter(Boolean).join(' ');
            return `<button type="button" class="btn btn-sm ${badgeClass}" data-meter-price="${meter}" data-unit-price="${unit}" title="${title}">${UI.formatCurrency(meter)}<span class="price-suggest-unit"> /م</span></button>`;
        }).filter(Boolean).join('');
        if (!chips) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        let sourceNote = 'سابقه آخرین قیمت متری';
        if (prices.some((p) => p.from_person)) {
            sourceNote = type === 'sales' ? 'سابقه قیمت متری این مشتری' : 'سابقه قیمت متری این تأمین‌کننده';
        } else if (prices.some((p) => !p.is_exact_product)) {
            sourceNote = 'سابقه قیمت متری محصولات مشابه';
        }
        box.html(`<span class="price-suggestions-label">${sourceNote}:</span><span class="price-suggestions-chips">${chips}</span>`).removeAttr('hidden');
        wrap.addClass('is-visible');
    }

    function getPriceHistorySettings() {
        const pub = (App.getCache().publicSettings || {});
        const full = (App.getCache().settings || {});
        const pick = (key, fallback) => {
            const v = pub[key] ?? full[key];
            return (v === undefined || v === null || v === '') ? fallback : v;
        };
        const limit = parseInt(pick('invoice_price_history_limit', '3'), 10);
        return {
            enabled: String(pick('invoice_price_history_enabled', '1')) !== '0',
            similar: String(pick('invoice_price_history_similar', '1')) !== '0',
            personPrefer: String(pick('invoice_price_history_person_prefer', '1')) !== '0',
            limit: Number.isFinite(limit) ? Math.max(1, Math.min(5, limit)) : 3
        };
    }

    async function refreshRowPriceSuggestions(row, { force = false } = {}) {
        if (!row || !row.length || row.data('is-service') == 1 || row.hasClass('service-row')) {
            return;
        }
        // Opening an invoice already has prices; wait for user focus/edit.
        if (isInvoicePopulating && !force) {
            return;
        }
        const box = row.find('.price-suggestions');
        const wrap = row.find('.price-suggestions-wrap');
        if (!box.length) return;
        const hist = getPriceHistorySettings();
        if (!hist.enabled) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        if (row.find('.unit-price').prop('readonly') && row.find('.price-entry-mode').prop('disabled')) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        const type = getInvoiceRowType(row);
        const productId = parseInt(row.find('.product-select').val()) || 0;
        const productName = getRowProductName(row);
        if (productId <= 0 && productName.length < 2) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        if (!hist.similar && productId <= 0) {
            box.empty().attr('hidden', true);
            wrap.removeClass('is-visible');
            return;
        }
        const dimensions = getInvoiceRowDimensions(row);
        const personId = hist.personPrefer ? getInvoicePersonId(type) : 0;
        const excludeInvoiceId = getInvoiceEditingId(type);
        const reqKey = [type, productId, productName, personId, dimensions, excludeInvoiceId, hist.similar, hist.limit].join('|');
        if (box.data('req-key') === reqKey && box.data('req-inflight')) {
            return;
        }
        box.data('req-key', reqKey);
        box.data('req-inflight', true);
        wrap.addClass('is-visible');
        box.html('<span class="price-suggestions-label text-muted">در حال بارگذاری سابقه قیمت…</span>').removeAttr('hidden');
        try {
            const result = await Api.call('get_product_recent_prices', {
                type,
                productId,
                productName,
                personId,
                dimensions,
                excludeInvoiceId,
                allowSimilar: hist.similar ? 1 : 0,
                limit: hist.limit
            });
            if (box.data('req-key') !== reqKey) return;
            if (result?.success && Array.isArray(result.prices)) {
                renderPriceSuggestions(row, result.prices, type);
            } else {
                box.empty().attr('hidden', true);
                wrap.removeClass('is-visible');
            }
        } catch (e) {
            if (box.data('req-key') === reqKey) {
                box.empty().attr('hidden', true);
                wrap.removeClass('is-visible');
            }
        } finally {
            if (box.data('req-key') === reqKey) {
                box.removeData('req-inflight');
            }
        }
    }

    function refreshAllPriceSuggestions(type) {
        $(`#${type}-invoice-items-container .dynamic-row`).each(function () {
            refreshRowPriceSuggestions($(this));
        });
    }

    function applySuggestedPrice(row, unitPrice, meterPrice) {
        const meter = Number(meterPrice) || 0;
        if (meter <= 0) return;

        // Always apply as meter price, then derive unit from current dimensions when possible
        row.find('.meter-price').val(meter.toLocaleString('en-US'));
        const area = getInvoiceRowArea(row);
        if (area > 0) {
            const unit = Math.round(area * meter);
            row.find('.unit-price').val(unit.toLocaleString('en-US'));
            if (row.find('.price-entry-mode').val() !== 'meter') {
                // Keep current mode, but meter field stays synced for later switch
            }
        } else {
            // No dimensions yet: switch to meter entry so the value is visible/usable
            row.find('.price-entry-mode').val('meter');
            syncPriceEntryMode(row);
            const fallbackUnit = Number(unitPrice) || 0;
            if (fallbackUnit > 0) {
                row.find('.unit-price').val(fallbackUnit.toLocaleString('en-US'));
            }
        }
        calculateInvoiceItemTotal(row);
        const type = getInvoiceRowType(row);
        calculateInvoiceTotals(type);
    }

    function revokePendingImageUrls(row) {
        const el = row && row[0];
        const urls = (el && el._pendingImageUrls) || [];
        urls.forEach((u) => {
            try {
                URL.revokeObjectURL(u);
            } catch (_) {}
        });
        if (el) el._pendingImageUrls = [];
    }

    function getPendingImages(row) {
        const el = row && row[0];
        return Array.from((el && el._pendingImages) || []);
    }

    function setPendingImages(row, files) {
        const el = row && row[0];
        if (!el) return;
        el._pendingImages = Array.from(files || []);
    }

    function clearPendingProductImages(row) {
        revokePendingImageUrls(row);
        setPendingImages(row, []);
        row.find('.new-product-images').val('');
        row.find('.new-product-images-preview').empty().hide();
        row.find('.new-product-images-hint').text('اختیاری: تصاویر محصول را انتخاب کنید').show();
        row.find('.btn-pick-product-images').html('<i class="bi bi-images"></i> انتخاب / افزودن تصویر');
    }

    function renderPendingProductImages(row) {
        const files = getPendingImages(row);
        const $box = row.find('.new-product-images-preview');
        const $hint = row.find('.new-product-images-hint');
        revokePendingImageUrls(row);

        if (!files.length) {
            $box.empty().hide();
            $hint.text('اختیاری: تصاویر محصول را انتخاب کنید').show();
            row.find('.btn-pick-product-images').html('<i class="bi bi-images"></i> انتخاب / افزودن تصویر');
            return;
        }

        const urls = files.map((f) => URL.createObjectURL(f));
        if (row[0]) row[0]._pendingImageUrls = urls;
        setPendingImages(row, files);
        row.find('.btn-pick-product-images').html('<i class="bi bi-plus-lg"></i> افزودن تصویر دیگر');

        let html = '<div class="d-flex flex-wrap gap-2 align-items-start">';
        files.forEach((f, i) => {
            const name = (f.name || `تصویر ${i + 1}`).replace(/[<>&"]/g, '');
            html += `<div class="position-relative border rounded overflow-hidden bg-light" style="width:64px;height:64px;" title="${name}">
                <img src="${urls[i]}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">
                <button type="button" class="btn btn-danger btn-sm p-0 btn-remove-pending-image" data-idx="${i}" title="حذف این تصویر"
                    style="position:absolute;top:2px;left:2px;width:20px;height:20px;line-height:1;font-size:12px;border-radius:50%;">×</button>
            </div>`;
        });
        html += `</div>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                <small class="text-muted">${files.length} تصویر آماده آپلود</small>
                <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-clear-pending-images">حذف همه</button>
            </div>`;
        $box.html(html).show();
        $hint.hide();
    }

    function snapshotPurchaseRows() {
        const snaps = [];
        $('#purchase-invoice-items-container .dynamic-row').each(function () {
            const row = $(this);
            const nameInput = row.find('.new-product-name');
            snaps.push({
                key: row.attr('data-client-key'),
                name: nameInput.val() || '',
                nameVisible: nameInput.is(':visible'),
                dim: row.find('.dimensions-select').val() || '',
                custom: row.find('.custom-dimension-input').val() || '',
                qty: row.find('.quantity').val(),
                unit: row.find('.unit-price').val(),
                meter: row.find('.meter-price').val(),
                mode: row.find('.price-entry-mode').val()
            });
        });
        return snaps;
    }

    function restorePurchaseRows(snaps) {
        (snaps || []).forEach((s) => {
            if (!s || !s.key || (!s.nameVisible && !s.name && !s.custom)) return;
            const row = $('#purchase-invoice-items-container .dynamic-row').filter(function () {
                return $(this).attr('data-client-key') === s.key;
            });
            if (!row.length) return;
            row.find('.product-select').prop('disabled', true);
            row.find('.new-product-name').show().val(s.name);
            row.find('.new-product-images-wrap').show();
            const dimensionsSelect = row.find('.dimensions-select');
            if (!dimensionsSelect.find('option[value="custom"]').length) {
                dimensionsSelect.empty().prop('disabled', false).append('<option value="">انتخاب کنید...</option>');
                const standardDimensions = (App.getCache().publicSettings?.standard_dimensions || '').split(',').map(d => d.trim()).filter(Boolean);
                standardDimensions.forEach((dim) => dimensionsSelect.append(`<option value="${dim}">${dim}</option>`));
                dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');
            } else {
                dimensionsSelect.prop('disabled', false);
            }
            if (s.dim === 'custom' || s.custom) {
                dimensionsSelect.val('custom');
                if (!row.find('.custom-dimension-input').length) {
                    row.find('.dimensions-container').append('<div class="mt-2 custom-dimension-wrapper"><input type="text" class="form-control custom-dimension-input" placeholder="مثال: 2.1x3.2"></div>');
                }
                row.find('.custom-dimension-input').val(s.custom);
            } else if (s.dim) {
                dimensionsSelect.val(s.dim);
            }
            if (s.qty != null && s.qty !== '') row.find('.quantity').val(s.qty);
            if (s.unit != null) row.find('.unit-price').val(s.unit);
            if (s.meter != null) row.find('.meter-price').val(s.meter);
            if (s.mode) row.find('.price-entry-mode').val(s.mode);
        });
    }

    function populateDimensions(productSelect, preselectedDimension = null) {
        const productId = productSelect.val();
        const row = productSelect.closest('.dynamic-row');
        // New-product rows keep the size the user already picked. A later
        // product-select change (modal hide / select2) must not wipe it.
        if (!preselectedDimension && row.find('.new-product-name').is(':visible')) {
            return;
        }
        const dimensionsSelect = row.find('.dimensions-select');
        const dimensionsContainer = row.find('.dimensions-container');
        const isSales = productSelect.closest('form').attr('id').includes('sales');
        const standardDimensions = (App.getCache().publicSettings.standard_dimensions || '').split(',').map(d => d.trim()).filter(Boolean);

        dimensionsSelect.empty().prop('disabled', true);
        dimensionsContainer.find('.custom-dimension-wrapper').remove();

        if (!productId) {
            dimensionsSelect.append('<option>ابتدا طرح را انتخاب کنید</option>');
            return;
        }

        dimensionsSelect.prop('disabled', false).append('<option value="">انتخاب کنید...</option>');

        if (!isSales) {
            standardDimensions.forEach(dim => dimensionsSelect.append(`<option value="${dim}">${dim}</option>`));
            dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');
            if (preselectedDimension) {
                if (standardDimensions.includes(preselectedDimension)) {
                    dimensionsSelect.val(preselectedDimension);
                } else {
                    dimensionsSelect.val('custom').trigger('change');
                    dimensionsContainer.find('.custom-dimension-input').val(preselectedDimension);
                }
            }
        } else { // Sales
            const product = App.getCache().products.find(p => p.id == productId);
            const originalInvoiceItems = productSelect.closest('form').data('original-items') || [];

            // Collect all available dimensions (Stocked + Standard)
            const availableDims = new Set();
            const stockMap = {};

            if (product && product.stock) {
                product.stock.forEach(s => {
                    availableDims.add(s.dimensions);
                    stockMap[s.dimensions] = availableQty(s);
                });
            }

            // Add preselected dimension if exists
            if (preselectedDimension) availableDims.add(preselectedDimension);

            // Convert to array and sort
            const sortedDims = Array.from(availableDims).sort();

            sortedDims.forEach(dim => {
                const originalItem = originalInvoiceItems.find(it => it.productId == productId && it.dimensions === dim);
                const originalQty = originalItem ? Number(originalItem.quantity) : 0;
                const currentStock = stockMap[dim] || 0;
                const effectiveStock = currentStock + originalQty;

                // Only show if there is effective stock or if it's the currently selected dimension (even if stock is 0, though effectiveStock handles that)
                if (effectiveStock > 0 || dim === preselectedDimension) {
                    // برچسب: موجودی واقعی انبار؛ اقلامی که همین فاکتور برداشته جدا نمایش داده می‌شود
                    // (effectiveStock فقط برای نمایش گزینه و اعتبارسنجی تعداد در حالت ویرایش است)
                    const stockText = originalQty > 0
                        ? `(موجودی: ${currentStock} | در این فاکتور: ${originalQty})`
                        : `(موجودی: ${currentStock})`;
                    dimensionsSelect.append(`<option value="${dim}">${dim} ${stockText}</option>`);
                }
            });

            // Allow custom dimensions for sales too (backorder custom size)
            dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');

            if (preselectedDimension) {
                if (sortedDims.includes(preselectedDimension)) {
                    dimensionsSelect.val(preselectedDimension);
                } else {
                    dimensionsSelect.val('custom').trigger('change');
                    dimensionsContainer.find('.custom-dimension-input').val(preselectedDimension);
                }
            }
        }
    }

    function populateSerialSelect(row, preSelectedSerials = [], keepSelections = false) {
        const serialSelect = row.find('.item-serial-select');
        if (!serialSelect.length) return;

        const productId = row.find('.product-select').val();
        let dimension = row.find('.dimensions-select').val();
        if (dimension === 'custom') {
            dimension = (row.find('.custom-dimension-input').val() || '').trim();
        }
        const qty = parseInt(row.find('.quantity').val()) || 1;
        const modalId = '#' + row.closest('.modal').attr('id');

        // Destroy existing Select2 instance
        if (serialSelect.hasClass('select2-hidden-accessible')) {
            serialSelect.select2('destroy');
        }
        serialSelect.empty();

        if (!productId || !dimension) {
            serialSelect.select2({
                theme: 'bootstrap-5',
                dir: 'rtl',
                width: '100%',
                placeholder: 'ابتدا کالا و ابعاد را انتخاب کنید',
                dropdownParent: $(modalId),
            });
            return;
        }

        // Get available serial IDs from stock cache (in_stock items)
        const product = (App.getCache().products || []).find(p => p.id == productId);
        let availableSerials = [];
        if (product && product.stock) {
            const stockEntry = product.stock.find(s => s.dimensions === dimension);
            // فرش‌های فروخته‌شدهِ انبار سفارش قابل فروش مجدد نیستند
            const sellable = stockEntry ? (stockEntry.available_serial_ids || stockEntry.serial_ids) : null;
            if (sellable) {
                availableSerials = [...sellable];
            }
        }

        // Merge with pre-selected serials (edit mode: currently assigned, status='sold')
        const allSerials = [...new Set([...preSelectedSerials, ...availableSerials])];

        // Add options
        allSerials.forEach(serial => {
            serialSelect.append(new Option(serial, serial, false, false));
        });

        // Initialize Select2
        serialSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            width: '100%',
            placeholder: allSerials.length > 0 ? 'انتخاب شناسه...' : 'موجودی ندارد',
            dropdownParent: $(modalId),
            maximumSelectionLength: qty,
        });

        // Select appropriate serials
        if (keepSelections && preSelectedSerials.length > 0) {
            const toSelect = preSelectedSerials.slice(0, qty);
            serialSelect.val(toSelect).trigger('change');
        } else if (availableSerials.length > 0) {
            // Auto-select first N from available (FIFO for new invoices)
            const autoSelect = availableSerials.slice(0, qty);
            serialSelect.val(autoSelect).trigger('change');
        }
    }

    function addServiceItemRow(containerSelector, item = null) {
        const row = $(`<div class="row dynamic-row service-row invoice-item-card align-items-end g-2" data-is-service="1">
            <input type="hidden" class="item-id" value="${item ? item.id : ''}">
            <div class="col-12 invoice-row-toolbar d-md-none">
                <span class="invoice-row-label text-info"><span class="invoice-row-number badge text-bg-secondary me-1">0</span><i class="bi bi-tools me-1"></i>خدمات / هزینه</span>
                <button type="button" class="btn btn-outline-danger btn-sm remove-item-btn" title="حذف"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="col-auto d-none d-md-flex invoice-item-row-num align-items-end">
                <span class="invoice-row-number" title="شماره ردیف">0</span>
            </div>
            <div class="col-md-5"><label class="form-label"><i class="bi bi-tools me-1 text-info"></i>شرح خدمات/هزینه</label><input type="text" class="form-control service-description" placeholder="مثال: خدمات حمل و نقل، کمیسیون، نصب و ..." value="${item ? UI.escapeHtml(item.item_description || item.productName || '') : ''}"></div>
            <div class="col-6 col-md-2"><label class="form-label">تعداد</label><input type="number" class="form-control quantity" value="${item ? item.quantity : 1}" min="1"></div>
            <div class="col-6 col-md-2"><label class="form-label">قیمت واحد</label><input type="text" class="form-control unit-price numeric-input" value="${item ? Number(item.unitPrice).toLocaleString('en-US') : '0'}"></div>
            <div class="col-10 col-md-2"><label class="form-label">جمع</label><input type="text" class="form-control item-total" readonly></div>
            <div class="col-md-1 d-none d-md-flex align-items-end"><button type="button" class="btn btn-danger btn-sm remove-item-btn" title="حذف"><i class="bi bi-x-lg"></i></button></div>
        </div>`);

        containerSelector.append(row);

        if (item) {
            calculateInvoiceItemTotal(row);
        }

        // Apply numeric formatting to the new row
        UI.initNumericFormatting();
        renumberInvoiceItemRows(containerSelector);
    }

    function getNewCheckDetailsHtml(check = null) {
        const dueDate = check && check.dueDate ? UI.gregorianToPersian(check.dueDate) : UI.today();
        const issuer = check && check.issuerName ? UI.escapeHtml(check.issuerName) : '';
        return `<div class="row g-2 check-details-grid">
            <div class="col-6 col-md-3 pay-col-check-no"><label class="form-label-sm">شماره چک</label><div class="input-group input-group-sm"><input type="text" class="form-control form-control-sm check-number" inputmode="numeric" autocomplete="off" value="${check ? UI.escapeHtml(check.checkNumber) : ''}"><button type="button" class="btn btn-outline-secondary scan-sayad-btn" title="اسکن بارکد صیاد" aria-label="اسکن بارکد صیاد"><i class="bi bi-qr-code-scan"></i></button></div></div>
            <div class="col-6 col-md-3 pay-col-due"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker check-due-date" value="${dueDate}"></div>
            <div class="col-6 col-md-3 pay-col-bank"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm check-bank-name" value="${check ? UI.escapeHtml(check.bankName || '') : ''}"></div>
            <div class="col-6 col-md-3 pay-col-issuer"><label class="form-label-sm">صادرکننده</label><input type="text" class="form-control form-control-sm check-issuer-name" value="${issuer}" placeholder="نام روی چک"></div>
        </div>`;
    }

    function getEndorseCheckHtml(checkId = null, checkDetails = null) {
        const availableChecks = (App.getCache().checks || []).filter(c => c.type === 'received' && (c.status === 'in_hand' || c.id == checkId));
        
        // If we have checkDetails from the API but the check is not in cache (e.g., already endorsed),
        // we need to add it to the options manually
        let hasSelectedCheckInList = checkId && availableChecks.some(c => c.id == checkId);
        
        if (availableChecks.length === 0 && !checkId && !checkDetails) {
            return '<div><p class="text-danger small mt-2">چک قابل واگذاری وجود ندارد.</p></div>';
        }
        
        let options = availableChecks.map(c => `<option value="${c.id}" data-amount="${c.amount}" ${c.id == checkId ? 'selected' : ''}>${c.checkNumber} - ${UI.formatCurrency(c.amount)}</option>`).join('');
        
        // If the selected check is not in the list but we have checkDetails, add it manually
        if (checkId && !hasSelectedCheckInList && checkDetails) {
            const checkLabel = `${checkDetails.checkNumber} - ${UI.formatCurrency(checkDetails.amount)} (خرج شده)`;
            options += `<option value="${checkId}" data-amount="${checkDetails.amount}" selected>${checkLabel}</option>`;
        }
        
        return `<div><label class="form-label-sm">انتخاب چک</label><select class="form-select form-select-sm endorsed-check-select"><option value="">-- انتخاب کنید --</option>${options}</select></div>`;
    }

    function addPaymentRow(containerSelector, invoiceType, payment = null) {
        const checkOptionHtml = invoiceType === 'purchase' ? '<option value="endorse_check">خرج چک دریافتی</option>' : '';
        const setoffOptionHtml = invoiceType === 'sales'
            ? '<option value="setoff">تهاتر / پرداخت به جای</option>'
            : (payment && payment.type === 'setoff' ? '<option value="setoff">تهاتر / پرداخت به جای</option>' : '');
        const defaultTxn = invoiceType === 'sales' ? 'receipt' : 'payment';
        const txn = (payment && payment.transaction_type) ? payment.transaction_type : defaultTxn;
        const txnOptions = invoiceType === 'sales'
            ? `<option value="receipt">دریافت از مشتری</option><option value="payment">پرداخت به مشتری</option>`
            : `<option value="payment">پرداخت به تأمین‌کننده</option><option value="receipt">دریافت از تأمین‌کننده</option>`;
        const existingId = payment && (payment.existing_payment_id || payment.id) ? (payment.existing_payment_id || payment.id) : '';
        const fromAdvance = !!(payment && (payment.from_advance || payment.is_allocated == 1));
        // Rows whose check already moved money/changed hands, and bounce reversals,
        // are history: the server keeps them as-is, so the form shows them read-only.
        const isBounceRow = !!(payment && payment.type === 'check_bounce');
        const isSetoffOnPurchase = !!(payment && payment.type === 'setoff' && invoiceType === 'purchase');
        const chkStatus = payment && payment.checkDetails ? payment.checkDetails.status : null;
        const isProcessedCheck = !!(payment && (
            (payment.type === 'check' && chkStatus && !['in_hand', 'payable'].includes(chkStatus)) ||
            (payment.type === 'endorse_check' && chkStatus && chkStatus !== 'endorsed')
        ));
        const lockedRow = fromAdvance || isBounceRow || isProcessedCheck || isSetoffOnPurchase;
        const statusLabels = { cashed: 'وصول/پاس شده', endorsed: 'خرج شده', bounced: 'برگشتی', in_hand: 'نزد ما' };
        const lockNote = isBounceRow
            ? '<i class="bi bi-arrow-return-left"></i> سند برگشت/عودت چک — فقط از بخش چک‌ها قابل لغو است'
            : (isSetoffOnPurchase
                ? '<i class="bi bi-arrow-left-right"></i> تهاتر — فقط از فاکتور فروش قابل حذف/ویرایش است'
                : (isProcessedCheck ? `<i class="bi bi-lock"></i> چک در وضعیت «${statusLabels[chkStatus] || chkStatus}» است و از روی فاکتور قابل تغییر نیست` : ''));
        const rowHtml = `<div class="row dynamic-row payment-row invoice-payment-card align-items-end g-2 ${fromAdvance ? 'is-from-advance' : ''} ${lockedRow ? 'is-locked-payment' : ''}" data-existing-payment-id="${existingId || ''}" data-from-advance="${fromAdvance ? '1' : '0'}" data-locked="${lockedRow ? '1' : '0'}">
            <input type="hidden" class="payment-check-id" value="${payment && payment.checkId ? payment.checkId : ''}">
            <input type="hidden" class="payment-existing-id" value="${existingId || ''}">
            <div class="col-12 invoice-row-toolbar d-md-none">
                <span class="invoice-row-label"><span class="invoice-row-number badge text-bg-secondary me-1">0</span><i class="bi bi-wallet2 me-1"></i>ردیف پرداخت</span>
                <button type="button" class="btn btn-outline-danger btn-sm remove-payment-btn" title="حذف"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="col-auto d-none d-md-flex invoice-item-row-num pay-col-num align-items-end">
                <span class="invoice-row-number" title="شماره ردیف">0</span>
            </div>
            <div class="col-6 col-md-2 pay-col-dir"><label class="form-label">جهت</label><select class="form-select payment-transaction-type">${txnOptions}</select></div>
            <div class="col-6 col-md-2 pay-col-type"><label class="form-label">نوع</label><select class="form-select payment-type"><option value="cash">نقد</option><option value="check">چک جدید</option>${checkOptionHtml}${setoffOptionHtml}${isBounceRow ? '<option value="check_bounce">برگشت/عودت چک</option>' : ''}</select></div>
            <div class="col-6 col-md-2 pay-col-amount"><label class="form-label">مبلغ</label><input type="text" class="form-control payment-amount numeric-input" value="0"></div>
            <div class="col-6 col-md-2 pay-col-date"><label class="form-label">تاریخ</label><input type="text" class="form-control persian-datepicker payment-date"></div>
            <div class="col-12 col-md-3 payment-details-container pay-col-details"></div>
            <div class="col-md-1 d-none d-md-flex align-items-end pay-col-remove"><button type="button" class="btn btn-danger btn-sm remove-payment-btn" title="حذف"><i class="bi bi-x-lg"></i></button></div>
            ${fromAdvance ? '<div class="col-12 payment-advance-note"><small class="text-info"><i class="bi bi-link-45deg"></i> متصل از علی‌الحساب — بدون تغییر موجودی بانک</small></div>' : ''}
            ${lockNote ? `<div class="col-12 payment-advance-note"><small class="text-warning">${lockNote}</small></div>` : ''}
            ${payment && payment.type === 'setoff' && invoiceType === 'sales' ? '<div class="col-12 payment-advance-note"><small class="text-info"><i class="bi bi-arrow-left-right"></i> بدون واریز به حساب مجموعه — مانده مشتری و طرف مقابل هم‌زمان اصلاح می‌شود</small></div>' : ''}
        </div>`;
        const row = $(rowHtml);
        const paymentTypeSelect = row.find('.payment-type');
        const txnSelect = row.find('.payment-transaction-type');
        const paymentAmountInput = row.find('.payment-amount');
        const paymentDateInput = row.find('.payment-date');
        const detailsContainer = row.find('.payment-details-container');

        function accountLabelForTxn(t) {
            if (invoiceType === 'sales') {
                return t === 'receipt' ? 'واریز به حساب' : 'برداشت از حساب';
            }
            return t === 'payment' ? 'پرداخت از حساب' : 'واریز به حساب';
        }

        function updateDetails(type, details) {
            detailsContainer.empty();
            row.attr('data-payment-kind', type || 'cash');
            if (type === 'check') {
                detailsContainer.html(getNewCheckDetailsHtml(details ? details.checkDetails : null));
                paymentAmountInput.prop('readonly', false);
                if (details && details.checkDetails) paymentAmountInput.val(details.amount);
            } else if (type === 'endorse_check') {
                detailsContainer.html(getEndorseCheckHtml(details ? details.checkId : null, details ? details.checkDetails : null));
                paymentAmountInput.prop('readonly', true);
                if (details) {
                    paymentAmountInput.val(details.amount);
                    setTimeout(() => {
                        detailsContainer.find('.endorsed-check-select').val(details.checkId);
                    }, 0);
                } else {
                    const select = detailsContainer.find('.endorsed-check-select');
                    if (select.length > 0 && select.find('option').length > 1) {
                        select.trigger('change');
                    } else {
                        paymentAmountInput.val(0);
                    }
                }
            } else if (type === 'check_bounce') {
                detailsContainer.html(`<div class="small text-muted pt-2">${UI.escapeHtml((details && details.description) || 'برگشت چک')}</div>`);
                paymentAmountInput.prop('readonly', true);
            } else if (type === 'setoff') {
                const suppliers = App.getCache().suppliers || [];
                const contacts = App.getCache().contacts || [];
                const cpType = (details && details.setoff_person_type) || 'supplier';
                const cpId = details && details.setoff_person_id ? String(details.setoff_person_id) : '';
                const supplierOpts = suppliers.map(s => `<option value="${s.id}">${UI.escapeHtml(s.name)}</option>`).join('');
                const contactOpts = contacts.map(c => `<option value="${c.id}">${UI.escapeHtml(c.name)}</option>`).join('');
                detailsContainer.html(`
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label-sm">نوع مقصد</label>
                            <select class="form-select form-select-sm setoff-person-type">
                                <option value="supplier">تأمین‌کننده</option>
                                <option value="contact">سایر اشخاص</option>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label-sm">شخص مقصد</label>
                            <select class="form-select form-select-sm setoff-person-id" required>
                                <option value="">-- انتخاب --</option>
                            </select>
                        </div>
                        <div class="col-12 setoff-invoice-wrap"><label class="form-label-sm">فاکتور خرید (اختیاری)</label>
                            <select class="form-select form-select-sm setoff-invoice-id">
                                <option value="">علی‌الحساب / بدون فاکتور</option>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label-sm">توضیحات</label>
                            <input type="text" class="form-control form-control-sm payment-description" placeholder="مثلاً واریز مستقیم به شبا…">
                        </div>
                    </div>
                `);
                paymentAmountInput.prop('readonly', false);
                txnSelect.val('receipt').prop('disabled', true);

                const $type = detailsContainer.find('.setoff-person-type');
                const $person = detailsContainer.find('.setoff-person-id');
                const $inv = detailsContainer.find('.setoff-invoice-id');
                const $invWrap = detailsContainer.find('.setoff-invoice-wrap');

                function fillPersons(t) {
                    const opts = t === 'contact' ? contactOpts : supplierOpts;
                    $person.html(`<option value="">-- انتخاب --</option>${opts}`);
                    $invWrap.toggle(t === 'supplier');
                    if (t !== 'supplier') {
                        $inv.html('<option value="">علی‌الحساب / بدون فاکتور</option>');
                    }
                }

                async function loadOpenPurchases(supplierId, selectedInvoiceId) {
                    $inv.html('<option value="">علی‌الحساب / بدون فاکتور</option>');
                    if (!supplierId) return;
                    const res = await Api.call('get_open_purchase_invoices', { supplier_id: supplierId, limit: 40 });
                    const rows = (res && res.data) || [];
                    rows.forEach(r => {
                        const label = `#${r.invoice_number || r.id} — مانده ${Number(r.remaining).toLocaleString('en-US')}`;
                        $inv.append(`<option value="${r.id}">${UI.escapeHtml(label)}</option>`);
                    });
                    if (selectedInvoiceId) $inv.val(String(selectedInvoiceId));
                }

                fillPersons(cpType);
                $type.val(cpType);
                if (cpId) $person.val(cpId);
                if (details) {
                    detailsContainer.find('.payment-description').val(details.description || '');
                }
                $type.on('change', function () {
                    fillPersons($(this).val());
                });
                $person.on('change', function () {
                    if ($type.val() === 'supplier') {
                        loadOpenPurchases($(this).val(), null);
                    }
                });
                if (cpType === 'supplier' && cpId) {
                    loadOpenPurchases(cpId, details && details.setoff_invoice_id ? details.setoff_invoice_id : null);
                }
            } else { // cash
                let accountOptions = App.getCache().accounts.map(acc => `<option value="${acc.id}">${UI.escapeHtml(acc.name)}</option>`).join('');
                const accountLabel = accountLabelForTxn(txnSelect.val() || defaultTxn);
                detailsContainer.html(`
                    <div class="row g-2">
                        <div class="col-12 col-md-7 pay-col-account"><label class="form-label-sm">${accountLabel}</label><select class="form-select form-select-sm payment-account-id" required>${accountOptions}</select></div>
                        <div class="col-12 col-md-5 pay-col-note"><label class="form-label-sm">توضیحات</label><input type="text" class="form-control form-control-sm payment-description"></div>
                    </div>
                `);
                paymentAmountInput.prop('readonly', false);
                if (details) {
                    detailsContainer.find('.payment-description').val(details.description || '');
                    detailsContainer.find('.payment-account-id').val(details.account_id);
                }
            }
            UI.initializeDatepickers();
        }

        txnSelect.val(txn);
        if (payment) {
            paymentTypeSelect.val(payment.type);
            paymentAmountInput.val(Number(payment.amount).toLocaleString('en-US'));
            paymentDateInput.val(payment.date);
            updateDetails(payment.type, payment);
        } else {
            paymentDateInput.val(UI.today());
            updateDetails('cash', null);
        }

        if (lockedRow) {
            row.find('.remove-payment-btn').prop('disabled', isBounceRow || isProcessedCheck || isSetoffOnPurchase);
            txnSelect.prop('disabled', true);
            paymentTypeSelect.prop('disabled', true);
            paymentAmountInput.prop('readonly', true);
            paymentDateInput.prop('readonly', true);
            detailsContainer.find('input, select, button').prop('disabled', true);
        }

        txnSelect.on('change', function () {
            if (paymentTypeSelect.val() === 'cash') {
                updateDetails('cash', {
                    description: detailsContainer.find('.payment-description').val(),
                    account_id: detailsContainer.find('.payment-account-id').val()
                });
            }
            calculateInvoiceTotals(invoiceType);
        });

        $(containerSelector).append(row);
        UI.initializeDatepickers();
        renumberInvoicePaymentRows(containerSelector);
        return row;
    }

    function _setInvoiceDueDateVisible(type, visible) {
        const wrapper = $(`#${type}-due-date-wrapper`);
        wrapper.toggle(!!visible);
        wrapper.closest('.invoice-meta-row-main').toggleClass('has-due-date', !!visible);
    }

    function _populateInvoiceHeader(type, formId, invoice = null) {
        const personType = type === 'sales' ? 'customer' : 'supplier';
        const personSelect = $(`${formId} #${type}-invoice-${personType}`);

        if (invoice) {
            $(`${formId} #${type}-invoice-id`).val(invoice.id);
            $(`${formId} #${type}-invoice-date`).val(UI.gregorianToPersian(invoice.date));
            $(`${formId} #${type}-invoice-description`).val(invoice.description);
            $(`${formId} #${type}-invoice-discount`).val(Number(invoice.discount || 0).toLocaleString('en-US'));
            // Payment terms & due date
            const terms = invoice.payment_terms || 'cash';
            $(`${formId} #${type}-invoice-payment-terms`).val(terms);
            if (terms && terms !== 'cash') {
                _setInvoiceDueDateVisible(type, true);
                $(`${formId} #${type}-invoice-due-date`).val(invoice.due_date ? UI.gregorianToPersian(invoice.due_date) : '');
            } else {
                _setInvoiceDueDateVisible(type, false);
                $(`${formId} #${type}-invoice-due-date`).val('');
            }
            // Tax toggle
            const hasTax = parseFloat(invoice.tax_rate || 0) > 0;
            $(`${formId} #${type}-invoice-tax-enabled`).prop('checked', hasTax);
            $(`${formId} #${type}-invoice-tax-rate`).val(invoice.tax_rate || 0);
            $(`${formId} #${type}-invoice-tax-amount`).val(Number(invoice.tax_amount || 0).toLocaleString('en-US'));
            $(`.${type}-tax-row`).toggle(hasTax);
            $(`${formId} #${type}-invoice-number`).val(invoice.invoice_number || '').attr('placeholder', String(invoice.id));
            if (type === 'purchase') {
                loadPurchaseWarehouseOptions(invoice.warehouse_id || '', false);
            }
        } else {
            $(`${formId} #${type}-invoice-id`).val('');
            $(`${formId} #${type}-invoice-date`).val(UI.today());
            $(`${formId} #${type}-invoice-discount`).val('0');
            // Reset payment terms & due date
            $(`${formId} #${type}-invoice-payment-terms`).val('cash');
            _setInvoiceDueDateVisible(type, false);
            $(`${formId} #${type}-invoice-due-date`).val('');
            // Reset tax (off by default)
            $(`${formId} #${type}-invoice-tax-enabled`).prop('checked', false);
            $(`${formId} #${type}-invoice-tax-rate`).val('0');
            $(`${formId} #${type}-invoice-tax-amount`).val('0');
            $(`.${type}-tax-row`).hide();
            $(`${formId} #${type}-invoice-number`).val('').attr('placeholder', 'خودکار');
            if (type === 'purchase') {
                loadPurchaseWarehouseOptions('', true);
            }
        }

        personSelect.empty().append('<option></option>');
        const personList = type === 'sales' ? App.getCache().customers : App.getCache().suppliers;
        (personList || []).forEach(p => personSelect.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`));
        if (invoice) {
            personSelect.val(type === 'sales' ? invoice.customerId : invoice.supplierId);
        }
        personSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: `انتخاب ${personType === 'customer' ? 'مشتری' : 'تأمین‌کننده'}...`,
            dropdownParent: $(`#${type}InvoiceModal`)
        });
        // Only reload on user/person change — initial fetch is once in prepareInvoiceModal
        // (after payments are populated, so already-linked advances are marked correctly).
        personSelect.off('change.advanceLog').on('change.advanceLog', () => {
            loadAdvanceLog(type);
            if (type === 'sales') updateCustomerGroupHint();
        });
    }

    async function loadPurchaseWarehouseOptions(selectedId, preferDefault) {
        const select = $('#purchase-invoice-warehouse');
        if (!select.length) return;
        const wanted = selectedId ? String(selectedId) : '';
        select.empty().append('<option value="">بدون انبار</option>');
        try {
            const result = await Api.call('get_warehouses');
            const warehouses = result?.warehouses || [];
            let defaultId = '';
            warehouses.forEach(w => {
                const typeLabel = w.warehouse_type === 'order' ? ' (سفارش)' : '';
                select.append(`<option value="${w.id}">${UI.escapeHtml(w.name || ('انبار #' + w.id))}${typeLabel}</option>`);
                if (String(w.is_default) === '1') defaultId = String(w.id);
            });
            select.val(wanted || (preferDefault ? defaultId : '') || '');
        } catch (e) {
            select.val('');
        }
    }

    function _populateInvoiceItems(type, invoice = null) {
        const itemsContainer = $(`#${type}-invoice-items-container`);
        if (invoice && invoice.items && invoice.items.length > 0) {
            invoice.items.forEach(item => {
                if (item.is_service == 1) {
                    addServiceItemRow(itemsContainer, item);
                } else {
                    addInvoiceItemRow(itemsContainer, item);
                }
            });
        }
    }

    function _populateInvoicePayments(type, invoice = null) {
        const paymentsContainer = $(`#${type}-invoice-payments-container`);
        if (invoice && invoice.payments) {
            invoice.payments.forEach(payment => {
                const pDate = UI.gregorianToPersian(payment.date);
                addPaymentRow(paymentsContainer, type, {
                    ...payment,
                    date: pDate,
                    existing_payment_id: payment.id,
                    from_advance: payment.is_allocated == 1
                });
            });
        }
    }

    async function loadAdvanceLog(type) {
        const personType = type === 'sales' ? 'customer' : 'supplier';
        const personId = parseInt($(`#${type}-invoice-${personType}`).val()) || 0;
        const box = $(`#${type}-advance-log`);
        const btn = $(`#${type}-attach-advance-btn`);
        btn.prop('disabled', true);
        if (!personId) {
            box.html(`<span class="text-muted">ابتدا ${personType === 'customer' ? 'مشتری' : 'تأمین‌کننده'} را انتخاب کنید.</span>`);
            return;
        }
        box.html('<span class="text-muted">در حال بارگذاری...</span>');
        const res = await Api.call('get_person_advance_payments', {
            person_id: personId,
            person_type: personType,
            limit: 10
        });
        if (!res?.success) {
            box.html(`<span class="text-danger">${res?.error || 'خطا در دریافت علی‌الحساب'}</span>`);
            return;
        }
        const rows = res.data || [];
        if (!rows.length) {
            box.html('<span class="text-muted">تراکنش علی‌الحسابی ثبت نشده است.</span>');
            return;
        }
        const alreadyLinked = new Set();
        $(`#${type}-invoice-payments-container .payment-existing-id`).each(function () {
            const id = parseInt($(this).val());
            if (id) alreadyLinked.add(id);
        });
        let html = `<div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
            <thead><tr>
                <th style="width:32px"></th>
                <th>تاریخ</th>
                <th>جهت</th>
                <th>مبلغ</th>
                <th>حساب</th>
                <th>شرح</th>
            </tr></thead><tbody>`;
        rows.forEach(r => {
            const linked = alreadyLinked.has(parseInt(r.id));
            const dir = r.transaction_type === 'receipt' ? 'دریافت' : 'پرداخت';
            const dirClass = r.transaction_type === 'receipt' ? 'text-success' : 'text-danger';
            html += `<tr class="${linked ? 'table-secondary' : ''}">
                <td><input type="checkbox" class="form-check-input advance-pick" value="${r.id}"
                    data-amount="${r.amount}" data-date="${r.date}" data-type="${r.type || 'cash'}"
                    data-transaction-type="${r.transaction_type}" data-account-id="${r.account_id || ''}"
                    data-description="${(r.description || '').replace(/"/g, '&quot;')}"
                    ${linked ? 'disabled' : ''}></td>
                <td>${UI.gregorianToPersian(r.date)}</td>
                <td class="${dirClass}">${dir}</td>
                <td>${UI.formatCurrency(r.amount)}</td>
                <td>${r.account_name || '—'}</td>
                <td>${r.description || '—'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        box.html(html);
        btn.prop('disabled', false);
    }

    function attachSelectedAdvances(type) {
        const container = $(`#${type}-invoice-payments-container`);
        let added = 0;
        $(`#${type}-advance-log .advance-pick:checked`).each(function () {
            const $cb = $(this);
            const id = parseInt($cb.val());
            if (!id) return;
            const already = container.find(`.payment-existing-id`).filter(function () {
                return parseInt($(this).val()) === id;
            }).length > 0;
            if (already) return;
            addPaymentRow(container, type, {
                id,
                existing_payment_id: id,
                from_advance: true,
                is_allocated: 1,
                type: $cb.data('type') || 'cash',
                transaction_type: $cb.data('transaction-type'),
                amount: parseFloat($cb.data('amount')) || 0,
                date: UI.gregorianToPersian(String($cb.data('date'))),
                account_id: $cb.data('account-id') || null,
                description: $cb.attr('data-description') || ''
            });
            added++;
        });
        if (added === 0) {
            UI.showError('تراکنشی برای افزودن انتخاب نشده است.');
            return;
        }
        calculateInvoiceTotals(type);
        loadAdvanceLog(type);
    }


    // ---------------------------------------------------------------
    // Draft autosave (new invoices only) + one idempotency key per form
    // ---------------------------------------------------------------
    const formKeys = {};
    const newFormKey = (type) => {
        formKeys[type] = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36).slice(2));
        return formKeys[type];
    };
    const draftKey = (type) => `hesab_invoice_draft:${App.getAppState().currentEntityId || 0}:${type}`;
    const readDraft = (type) => {
        try { return JSON.parse(localStorage.getItem(draftKey(type)) || 'null'); } catch (e) { return null; }
    };
    const clearDraft = (type) => { try { localStorage.removeItem(draftKey(type)); } catch (e) { /* storage blocked */ } };

    function collectDraft(type) {
        const personField = type === 'sales' ? 'customerId' : 'supplierId';
        const personType = type === 'sales' ? 'customer' : 'supplier';
        const num = (v) => Number(String(v || '').replace(/[,٬]/g, '')) || 0;
        const draft = {
            [personField]: parseInt($(`#${type}-invoice-${personType}`).val()) || null,
            date: UI.toGregorian($(`#${type}-invoice-date`)),
            discount: num($(`#${type}-invoice-discount`).val()),
            description: $(`#${type}-invoice-description`).val() || '',
            payment_terms: $(`#${type}-invoice-payment-terms`).val() || 'cash',
            due_date: UI.toGregorian($(`#${type}-invoice-due-date`)) || null,
            tax_rate: $(`#${type}-invoice-tax-enabled`).is(':checked') ? (parseFloat($(`#${type}-invoice-tax-rate`).val()) || 0) : 0,
            invoice_number: $(`#${type}-invoice-number`).val() || '',
            salesperson_id: parseInt($('#sales-invoice-salesperson').val()) || null,
            items: [],
            payments: [],
            savedAt: Date.now(),
        };
        $(`#${type}-invoice-items-container .dynamic-row`).each(function () {
            const $r = $(this);
            if ($r.data('is-service') == 1) {
                draft.items.push({ is_service: 1, item_description: $r.find('.service-description').val() || '', quantity: parseInt($r.find('.quantity').val()) || 0, unitPrice: num($r.find('.unit-price').val()) });
                return;
            }
            const pid = parseInt($r.find('.product-select').val()) || null;
            let dim = $r.find('.dimensions-select').val();
            if (dim === 'custom') dim = ($r.find('.custom-dimension-input').val() || '').trim();
            if (!pid) return;
            draft.items.push({ productId: pid, productName: $r.find('.product-select option:selected').text(), dimensions: dim || '', quantity: parseInt($r.find('.quantity').val()) || 0, unitPrice: num($r.find('.unit-price').val()) });
        });
        $(`#${type}-invoice-payments-container .dynamic-row`).each(function () {
            const $r = $(this);
            if ($r.data('locked') == 1 || $r.data('from-advance') == 1) return;
            const p = {
                type: $r.find('.payment-type').val(),
                transaction_type: $r.find('.payment-transaction-type').val(),
                amount: num($r.find('.payment-amount').val()),
                date: UI.toGregorian($r.find('.payment-date')),
                description: $r.find('.payment-description').val() || '',
                account_id: $r.find('.payment-account-id').val() || null,
            };
            if (p.type === 'check') {
                p.checkDetails = { checkNumber: $r.find('.check-number').val(), dueDate: UI.toGregorian($r.find('.check-due-date')), bankName: $r.find('.check-bank-name').val(), issuerName: $r.find('.check-issuer-name').val() };
            } else if (p.type === 'endorse_check') {
                p.checkId = parseInt($r.find('.endorsed-check-select').val()) || null;
            } else if (p.type === 'setoff') {
                p.transaction_type = 'receipt';
                p.account_id = null;
                p.setoff_person_type = $r.find('.setoff-person-type').val() || '';
                p.setoff_person_id = parseInt($r.find('.setoff-person-id').val()) || 0;
                p.setoff_invoice_id = parseInt($r.find('.setoff-invoice-id').val()) || null;
            }
            if (p.amount > 0) draft.payments.push(p);
        });
        return draft;
    }

    const saveDraftDebounced = {};
    function scheduleDraftSave(type) {
        if (isInvoicePopulating) return;
        if (parseInt($(`#${type}-invoice-id`).val())) return; // only new invoices
        clearTimeout(saveDraftDebounced[type]);
        saveDraftDebounced[type] = setTimeout(() => {
            const d = collectDraft(type);
            const meaningful = d.items.length > 0 || d.payments.length > 0 || d.customerId || d.supplierId;
            try {
                if (meaningful) localStorage.setItem(draftKey(type), JSON.stringify(d));
            } catch (e) { /* quota / private mode */ }
        }, 1200);
    }

    function offerDraftRestore(type) {
        const draft = readDraft(type);
        const modal = $(`#${type}InvoiceModal`);
        modal.find('.invoice-draft-banner').remove();
        if (!draft || (!draft.items?.length && !draft.payments?.length)) return;
        const when = new Date(draft.savedAt || Date.now());
        const whenFa = UI.gregorianToPersian(when.toISOString().slice(0, 10)) + ' ' + when.toTimeString().slice(0, 5);
        const banner = $(`<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 invoice-draft-banner">
            <span><i class="bi bi-clock-history me-1"></i>پیش‌نویس ذخیره‌نشده (${UI.escapeHtml(whenFa)}) با ${draft.items.length} ردیف موجود است.</span>
            <span><button type="button" class="btn btn-sm btn-warning draft-restore">بازیابی پیش‌نویس</button>
            <button type="button" class="btn btn-sm btn-outline-secondary draft-discard">حذف</button></span></div>`);
        modal.find('.modal-body').first().prepend(banner);
        banner.find('.draft-discard').on('click', () => { clearDraft(type); banner.remove(); });
        banner.find('.draft-restore').on('click', () => {
            banner.remove();
            const inv = { ...draft };
            delete inv.savedAt;
            prepareInvoiceModal(type, inv);
            if (draft.salesperson_id) $('#sales-invoice-salesperson').val(draft.salesperson_id);
        });
    }

    function _resetModal(modalId, formId, type) {
        UI.hideModalError(modalId);
        $(`${formId} .select2-hidden-accessible`).select2('destroy');
        $(formId)[0].reset();
        $(formId).removeData('original-items');
        $(`#${type}-invoice-items-container`).empty();
        $(`#${type}-invoice-payments-container`).empty();
        // بخش علی‌الحساب به‌صورت پیش‌فرض بسته بماند
        const collapseEl = document.getElementById(`${type}-advance-log-collapse`);
        if (collapseEl) {
            const inst = bootstrap.Collapse.getInstance(collapseEl);
            if (inst) inst.hide();
            else collapseEl.classList.remove('show');
        }
        $(`#${type}-advance-log-card [data-bs-toggle="collapse"]`).attr('aria-expanded', 'false');
    }

    async function load(tableName, filters = null, message = null) {
        UI.showLoader();

        // FIX: Filter-info containers in templates use underscore ids (e.g. sales_invoices-filter-info)
        const filterInfoContainer = $(`#${tableName}-filter-info`).empty();
        const searchInputIds = {
            sales_invoices: 'sales-invoice-search',
            purchase_invoices: 'purchase-invoice-search',
            consignment_sales: 'consignment-sales-invoice-search',
            consignment_purchases: 'consignment-purchase-invoice-search'
        };
        const searchInputId = searchInputIds[tableName] || '';
        const state = AppConfig.TABLE_STATES[tableName];
        state.tableName = tableName;
        let payload = { ...state };

        if (filters) {
            payload = { ...payload, ...filters };
            if (filters.filterType) state.filterType = filters.filterType;
            if (filters.exactInvoiceId) payload.exactInvoiceId = filters.exactInvoiceId;
            if (message) {
                const clearButton = $('<button class="btn btn-sm btn-warning ms-2">حذف فیلتر</button>');
                clearButton.on('click', () => {
                    delete state.filterType;
                    const searchInput = $(`#${searchInputId}`);
                    if (searchInput.length) {
                        searchInput.val('');
                        state.searchTerm = '';
                    }
                    load(tableName);
                });
                filterInfoContainer.html(`<div class="alert alert-info py-1">${message}</div>`).find('div').append(clearButton);
            }
        } else {
            delete state.filterType;
        }

        // Delivery/warehouse filters belong to the regular invoice lists.
        if (tableName === 'sales_invoices' || tableName === 'purchase_invoices') {
            const type = tableName === 'sales_invoices' ? 'sales' : 'purchase';
            const deliveryFilter = $(`#${type}-delivery-status-filter`).val();
            if (deliveryFilter) {
                payload.deliveryStatusFilter = deliveryFilter;
            }
        }
        if (tableName === 'purchase_invoices') {
            const whTypeFilter = $('#purchase-warehouse-type-filter').val();
            if (whTypeFilter) {
                payload.warehouseTypeFilter = whTypeFilter;
            }
        }

        const response = await Api.call('get_paginated_data', payload);

        if (response && renderers[tableName]) {
            renderers[tableName](response.data);
            UI.renderPagination(tableName, response.totalRecords, state);
            UI.updateSortIndicators(tableName, state);
            App.applyPermissions();
        }
        UI.hideLoader();
    }

    let customerGroupsCache = null;
    /** Show the customer's group discount with a one-click «apply» button. */
    async function updateCustomerGroupHint() {
        const box = $('#salesInvoiceModal .customer-group-hint').hide().empty();
        const customerId = parseInt($('#sales-invoice-customer').val()) || 0;
        const customer = (App.getCache().customers || []).find(c => Number(c.id) === customerId);
        if (!customer || !customer.group_id) return;
        if (customerGroupsCache === null) {
            const res = await Api.call('list_lookup', { kind: 'customer_groups' }, false);
            customerGroupsCache = res?.items || [];
        }
        const group = customerGroupsCache.find(g => Number(g.id) === Number(customer.group_id));
        if (!group) return;
        const pct = Number(group.discount_percent) || 0;
        let html = `<div class="alert alert-info py-1 px-2 mb-0 small d-flex align-items-center gap-2 flex-wrap"><i class="bi bi-people"></i>گروه مشتری: <strong>${UI.escapeHtml(group.name)}</strong>`;
        if (pct > 0) html += ` — تخفیف ${pct}٪ <button type="button" class="btn btn-sm btn-outline-primary py-0 apply-group-discount">اعمال تخفیف گروه</button>`;
        if (customer.credit_limit) html += ` <span class="ms-2">سقف اعتبار: ${UI.formatCurrency(customer.credit_limit)}</span>`;
        html += '</div>';
        box.html(html).show();
        box.find('.apply-group-discount').on('click', () => {
            let gross = 0;
            $('#sales-invoice-items-container .dynamic-row').each(function () {
                const q = parseInt($(this).find('.quantity').val()) || 0;
                const u = Number(String($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;
                gross += q * u;
            });
            const discount = Math.round(gross * pct / 100);
            $('#sales-invoice-discount').val(discount.toLocaleString('en-US')).trigger('input');
            calculateInvoiceTotals('sales');
        });
    }

    let salespeopleCache = null;
    async function populateSalespersonSelect(selectedId) {
        const sel = $('#sales-invoice-salesperson');
        if (!sel.length) return;
        if (salespeopleCache === null) {
            const res = await Api.call('list_lookup', { kind: 'salespeople' }, false);
            salespeopleCache = (res && res.items) ? res.items : [];
        }
        sel.empty().append('<option value="">— بدون فروشنده —</option>');
        salespeopleCache.filter(sp => sp.is_active == 1 || String(sp.id) === String(selectedId)).forEach(sp => {
            sel.append(`<option value="${sp.id}">${UI.escapeHtml(sp.name)}${sp.commission_percent > 0 ? ` (${sp.commission_percent}٪)` : ''}</option>`);
        });
        sel.val(selectedId ? String(selectedId) : '');
        sel.closest('.salesperson-wrapper').toggle(salespeopleCache.length > 0);
    }

    function prepareInvoiceModal(type, invoice = null) {
        const modalId = `#${type}InvoiceModal`;
        const formId = `#${type}-invoice-form`;

        _resetModal(modalId, formId, type);

        if (invoice && invoice.id && invoice.items) {
            $(formId).data('original-items', invoice.items);
        }

        isInvoicePopulating = true;
        try {
            _populateInvoiceHeader(type, formId, invoice);
            _populateInvoiceItems(type, invoice);
            _populateInvoicePayments(type, invoice);
        } finally {
            isInvoicePopulating = false;
        }
        loadAdvanceLog(type);

        // Existing invoices: quiet — progressive row fill already made discount
        // look "too high" relative to a partial total; final numbers are fine.
        calculateInvoiceTotals(type, { quiet: !!(invoice && invoice.id) });
        const copyBtn = $('#copy-purchase-invoice-btn');
        if (type === 'purchase' && invoice && invoice.id) {
            copyBtn.removeClass('d-none').attr('data-id', invoice.id);
        } else if (type === 'purchase') {
            copyBtn.addClass('d-none').removeAttr('data-id');
        }
        newFormKey(type);
        if (type === 'sales') {
            populateSalespersonSelect(invoice ? invoice.salesperson_id : null);
            updateCustomerGroupHint();
        }
        $(modalId).modal('show');
        if (!invoice) {
            offerDraftRestore(type);
        } else {
            $(modalId).find('.invoice-draft-banner').remove();
        }
    }

    function askPurchaseCopyDestination() {
        return new Promise((resolve) => {
            const modal = $('#confirmationModal');
            const footer = modal.find('.modal-footer');
            const dialog = modal.find('.modal-dialog');
            const savedFooter = footer.html();
            const savedTitle = modal.find('.modal-title').text();
            dialog.removeClass('modal-sm');
            modal.find('.modal-title').text('کپی فاکتور');
            $('#confirmationModalBody').text('این فاکتور برای خرید ساخته شود یا برای فروش؟');
            const salesBtn = App.hasPermission('manage_sales_invoices')
                ? '<button type="button" class="btn btn-success" data-copy-dest="sales">فاکتور فروش</button>'
                : '';
            footer.html(
                '<button type="button" class="btn btn-secondary" data-copy-dest="">انصراف</button>'
                + '<button type="button" class="btn btn-primary" data-copy-dest="purchase">فاکتور خرید</button>'
                + salesBtn
            );
            let choice = null;
            let settled = false;
            const settle = () => {
                if (settled) return;
                settled = true;
                footer.off('click.copyDest');
                footer.html(savedFooter);
                modal.find('.modal-title').text(savedTitle);
                dialog.addClass('modal-sm');
                modal.off('hidden.bs.modal.copyDest');
                resolve(choice);
            };
            footer.on('click.copyDest', '[data-copy-dest]', function () {
                choice = $(this).attr('data-copy-dest') || null;
                bootstrap.Modal.getInstance(document.getElementById('confirmationModal'))?.hide();
            });
            modal.on('hidden.bs.modal.copyDest', settle);
            modal.modal('show');
        });
    }

    async function copyPurchaseInvoice(purchaseId) {
        const id = parseInt(purchaseId, 10) || 0;
        if (!id) {
            UI.showError('ابتدا فاکتور خرید را ذخیره کنید.');
            return;
        }
        const dest = await askPurchaseCopyDestination();
        if (!dest) return;
        if (dest === 'sales') {
            await openSalesDraftFromPurchase(id);
            return;
        }
        UI.showLoader();
        const result = await Api.call('clone_invoice', { id, type: 'purchase' });
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess(result.message || 'فاکتور خرید کپی شد.');
            load('purchase_invoices');
        } else {
            UI.showError(result?.error || 'خطا در کپی فاکتور');
        }
    }

    async function openSalesDraftFromPurchase(purchaseId) {
        const id = parseInt(purchaseId, 10) || 0;
        if (!id) {
            UI.showError('ابتدا فاکتور خرید را ذخیره کنید.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('prepare_purchase_to_sales', { id });
        UI.hideLoader();
        if (!result?.success || !result.invoice) {
            UI.showError(result?.error || 'تبدیل به فاکتور فروش ممکن نشد.');
            return;
        }

        const purchaseModalEl = document.getElementById('purchaseInvoiceModal');
        if (purchaseModalEl?.classList.contains('show')) {
            await new Promise((resolve) => {
                $(purchaseModalEl).one('hidden.bs.modal', () => resolve());
                bootstrap.Modal.getInstance(purchaseModalEl)?.hide();
                setTimeout(resolve, 450);
            });
        }

        prepareInvoiceModal('sales', result.invoice);
        const skipped = parseInt(result.skipped, 10) || 0;
        if (skipped > 0) {
            UI.showInfo(`${skipped} قلم قبلاً فروخته شده یا در انبار نمانده و به فاکتور فروش منتقل نشد.`);
        } else {
            UI.showInfo('فرم فاکتور فروش پر شد. مشتری را انتخاب کنید و قیمت فروش را در صورت نیاز اصلاح کنید.');
        }
    }

    async function saveInvoice(type, confirmEdit = false) {
        const modalId = `#${type}InvoiceModal`;
        UI.hideModalError(modalId);
        const form = $(`#${type}-invoice-form`)[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const invoiceId = parseInt($(`#${type}-invoice-id`).val()) || null;

        // Check if editing an invoice with returned items
        if (invoiceId) {
            const originalItems = $(`#${type}-invoice-form`).data('original-items') || [];
            const hasReturnedItems = originalItems.some(item => item.returned_ids && item.returned_ids.length > 0);

            if (hasReturnedItems) {
                const confirmed = await UI.confirm(
                    'این فاکتور دارای کالای مرجوع شده است. ویرایش این فاکتور ممکن است بر فاکتورهای مرجوعی تأثیر بگذارد. مطمئنی که می‌خواهی ویرایش کنی؟',
                    'هشدار'
                );
                if (!confirmed) {
                    return;
                }
            }
        }

        const items = [];
        const blocked = [];
        const placeholderDims = ['', 'custom', 'ابتدا طرح را انتخاب کنید', 'انتخاب کنید...'];
        $(`#${type}-invoice-items-container .dynamic-row`).each(function () {
            let item = {};
            const itemId = $(this).find('.item-id').val();
            if (itemId) item.id = parseInt(itemId);

            // Check if this is a service/non-product row
            if ($(this).data('is-service') == 1) {
                item.is_service = 1;
                item.item_description = $(this).find('.service-description').val()?.trim() || '';
                item.quantity = parseInt($(this).find('.quantity').val()) || 0;
                item.unitPrice = parseInt(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;
                if (!item.item_description || item.quantity <= 0) return;
                items.push(item);
                return;
            }

            const pid = $(this).find('.product-select').val();
            const newProdName = $(this).find('.new-product-name').val()?.trim();
            const nameVisible = $(this).find('.new-product-name').is(':visible');
            const pendingCount = type === 'purchase' ? getPendingImages($(this)).length : 0;
            const rowNo = ($(this).find('.invoice-row-number').first().text() || '').trim() || '؟';
            const wantsNewProduct = type === 'purchase' && (nameVisible || pendingCount > 0 || !!newProdName);

            if (wantsNewProduct) {
                if (!newProdName) {
                    blocked.push(`ردیف ${rowNo}: نام محصول جدید را وارد کنید.`);
                    return;
                }
                item.newProductName = newProdName;
                item.client_key = $(this).attr('data-client-key') || null;
            } else if (pid) {
                item.productId = parseInt(pid);
            } else {
                return;
            }

            let dim = $(this).find('.dimensions-select').val();
            if (dim === 'custom') {
                dim = ($(this).find('.custom-dimension-input').val() || '').trim();
            }
            item.dimensions = dim;
            item.quantity = parseInt($(this).find('.quantity').val()) || 0;
            item.unitPrice = parseInt(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;

            // For sales: collect selected serial IDs from dropdown
            const serialSelect = $(this).find('.item-serial-select');
            if (serialSelect.length) {
                const selectedSerials = serialSelect.val() || [];
                if (selectedSerials.length > 0) {
                    item.selected_serial_ids = selectedSerials;
                }
            } else {
                // For purchase: custom serial_ids from text input
                const serialInput = $(this).find('.item-serial-display').val()?.trim();
                if (serialInput) {
                    item.custom_serial_ids = serialInput.split(',').map(s => s.trim()).filter(Boolean);
                }
            }

            if (wantsNewProduct && (placeholderDims.includes(String(item.dimensions || '').trim()) || item.quantity <= 0)) {
                blocked.push(`ردیف ${rowNo}: برای «${newProdName || 'محصول جدید'}» ابعاد و تعداد را مشخص کنید.`);
                return;
            }
            if ((!item.productId && !item.newProductName) || !item.dimensions || item.quantity <= 0) return;
            items.push(item);
        });

        if (blocked.length) {
            UI.showModalError(modalId, blocked.join(' — '));
            return;
        }

        if (type === 'purchase' || type === 'sales') {
            const merged = [];
            const indexByKey = {};
            const serialKey = type === 'sales' ? 'selected_serial_ids' : 'custom_serial_ids';
            items.forEach((item) => {
                if (item.is_service) {
                    merged.push(item);
                    return;
                }
                const key = `${item.productId || ('new:' + (item.newProductName || ''))}|${item.dimensions}`;
                if (indexByKey[key] === undefined) {
                    indexByKey[key] = merged.length;
                    merged.push({
                        ...item,
                        [serialKey]: [...(item[serialKey] || [])]
                    });
                    return;
                }
                const target = merged[indexByKey[key]];
                target.quantity += item.quantity;
                // Prefer keeping an existing DB row id so edit-save updates instead of insert+delete
                if (item.id && !target.id) target.id = item.id;
                const serials = [...(target[serialKey] || []), ...(item[serialKey] || [])];
                target[serialKey] = [...new Set(serials.filter(Boolean))];
            });
            if (merged.length < items.length) {
                UI.showInfo('ردیف‌های تکراری با همان طرح و ابعاد در یک ردیف جمع شدند.');
            }
            items.length = 0;
            items.push(...merged);
        }

        const payments = [];
        $(`#${type}-invoice-payments-container .dynamic-row`).each(function () {
            const existingId = parseInt($(this).find('.payment-existing-id').val()) || null;
            const defaultTxn = type === 'sales' ? 'receipt' : 'payment';
            const p = {
                type: $(this).find('.payment-type').val(),
                transaction_type: $(this).find('.payment-transaction-type').val() || defaultTxn,
                amount: parseInt(($(this).find('.payment-amount').val() || '').replace(/,/g, '')) || 0,
                date: UI.toGregorian($(this).find('.payment-date')),
                description: $(this).find('.payment-description').val() || '',
                account_id: $(this).find('.payment-account-id').val() || null
            };
            if (existingId) {
                p.existing_payment_id = existingId;
            }
            if (p.amount <= 0 || !p.date) return;
            if (p.type === 'check') {
                p.checkId = parseInt($(this).find('.payment-check-id').val()) || null;
                p.checkDetails = {
                    checkNumber: $(this).find('.check-number').val(),
                    dueDate: UI.toGregorian($(this).find('.check-due-date')),
                    bankName: $(this).find('.check-bank-name').val(),
                    issuerName: $(this).find('.check-issuer-name').val()
                };
            } else if (p.type === 'endorse_check') {
                const endorsedCheckId = parseInt($(this).find('.endorsed-check-select').val());
                if (!endorsedCheckId || isNaN(endorsedCheckId)) {
                    // Mark as invalid but don't skip - we'll validate later
                    p.checkId = null;
                    p.invalidEndorseCheck = true;
                } else {
                    p.checkId = endorsedCheckId;
                }
            } else if (p.type === 'setoff') {
                p.transaction_type = 'receipt';
                p.account_id = null;
                p.setoff_person_type = $(this).find('.setoff-person-type').val() || '';
                p.setoff_person_id = parseInt($(this).find('.setoff-person-id').val()) || 0;
                p.setoff_invoice_id = parseInt($(this).find('.setoff-invoice-id').val()) || null;
                if (!p.setoff_person_id || !['supplier', 'contact'].includes(p.setoff_person_type)) {
                    p.invalidSetoff = true;
                }
            }
            payments.push(p);
        });

        const data = {
            id: parseInt($(`#${type}-invoice-id`).val()) || null,
            date: UI.toGregorian($(`#${type}-invoice-date`)),
            items,
            payments,
            discount: Number(($(`#${type}-invoice-discount`).val() || '').replace(/,/g, '')) || 0,
            description: $(`#${type}-invoice-description`).val(),
            confirmEdit: confirmEdit,
            due_date: UI.toGregorian($(`#${type}-invoice-due-date`)) || null,
            payment_terms: $(`#${type}-invoice-payment-terms`).val() || null,
            tax_rate: parseFloat($(`#${type}-invoice-tax-rate`).val()) || 0,
            tax_amount: Number(($(`#${type}-invoice-tax-amount`).val() || '').replace(/,/g, '')) || 0,
            invoice_number: $(`#${type}-invoice-number`).val() || null,
            _idempotency_key: formKeys[type] || newFormKey(type),
        };
        if (type === 'sales') {
            data.salesperson_id = parseInt($('#sales-invoice-salesperson').val()) || null;
        }

        if (!data.date) {
            UI.showModalError(modalId, 'لطفا تاریخ فاکتور را به درستی وارد کنید.');
            return;
        }

        // Validate endorse_check payments have a check selected
        const invalidEndorsePayments = payments.filter(p => p.invalidEndorseCheck);
        if (invalidEndorsePayments.length > 0) {
            UI.showModalError(modalId, 'برای پرداخت "خرج چک دریافتی"، لطفاً یک چک انتخاب کنید.');
            return;
        }
        const invalidSetoff = payments.filter(p => p.invalidSetoff);
        if (invalidSetoff.length > 0) {
            UI.showModalError(modalId, 'برای تهاتر، تأمین‌کننده یا سایر اشخاص مقصد را انتخاب کنید.');
            return;
        }
        if (type === 'purchase' && payments.some(p => p.type === 'setoff' && !p.existing_payment_id)) {
            UI.showModalError(modalId, 'ثبت تهاتر جدید فقط از فاکتور فروش امکان‌پذیر است.');
            return;
        }

        if (type === 'sales') data.customerId = parseInt($(`#sales-invoice-customer`).val());
        else {
            data.supplierId = parseInt($(`#purchase-invoice-supplier`).val());
            data.warehouse_id = parseInt($('#purchase-invoice-warehouse').val()) || null;
        }

        UI.showLoader();
        // Capture pending images before modal DOM is cleared
        const pendingImageUploads = [];
        if (type === 'purchase') {
            $(`#${type}-invoice-items-container .dynamic-row`).each(function () {
                const key = $(this).attr('data-client-key');
                const files = getPendingImages($(this));
                if (key && files && files.length) {
                    pendingImageUploads.push({ client_key: key, files: Array.from(files) });
                }
            });
        }

        const result = await Api.call(`save_${type}_invoice`, data);
        UI.hideLoader();

        if (result?.success) {
            if (!data.id) clearDraft(type);
            newFormKey(type);
            // Upload images for products created inline on purchase invoice
            if (type === 'purchase' && pendingImageUploads.length && Array.isArray(result.created_products)) {
                UI.showLoader();
                let uploaded = 0;
                try {
                    for (const cp of result.created_products) {
                        const match = pendingImageUploads.find(p => p.client_key === cp.client_key);
                        if (!match || !cp.product_id) continue;
                        for (const file of match.files) {
                            const formData = new FormData();
                            formData.append('image', file);
                            formData.append('product_id', cp.product_id);
                            const up = await Api.uploadFile('upload_product_image', formData);
                            if (up?.success) uploaded++;
                        }
                    }
                } finally {
                    UI.hideLoader();
                }
                if (uploaded > 0) {
                    UI.showSuccess(`${uploaded} تصویر برای محصول(های) جدید آپلود شد.`);
                }
            }

            $(modalId).modal('hide');
            if (Array.isArray(result.warnings) && result.warnings.length) {
                toastr.warning(result.warnings.map(w => UI.escapeHtml(w)).join('<br>'), 'هشدار', { timeOut: 12000 });
            }
            await App.fetchInitialCache();
            load(`${type}_invoices`);
            if (type === 'sales') {
                const conv = result.consignment_conversion || {};
                if (parseInt(conv.converted_count, 10) > 0) {
                    UI.showSuccess(conv.message || `${conv.converted_count} قلم از فاکتور امانی به فاکتور خرید تبدیل شد.`);
                    load('consignment_purchases');
                    load('purchase_invoices');
                }
            }
            App.getManager('products').load();
            App.getManager('accounts').load();
            App.getManager('checks').load();
        } else if (result?.requiresConfirmation && result?.soldItems) {
            // Show warning about sold items
            let message = '<div class="alert alert-warning"><strong>هشدار:</strong> محصولات زیر در این فاکتور فروخته یا مرجوع شده‌اند:</div>';
            message += '<ul class="text-start">';
            result.soldItems.forEach(item => {
                const statuses = [...new Set(item.statuses)].join('، ');
                message += `<li><strong>${UI.escapeHtml(item.productName)}</strong> (${UI.escapeHtml(item.dimensions)}) - ${item.count} عدد (${statuses})</li>`;
            });
            message += '</ul>';
            message += '<p class="text-danger"><strong>توجه:</strong> ویرایش این فاکتور ممکن است بر داده‌های فروش تأثیر بگذارد.</p>';
            message += '<p>آیا مطمئن هستید که می‌خواهید ادامه دهید؟</p>';

            const confirmed = await UI.confirm(message, 'تأیید ویرایش فاکتور خرید');
            if (confirmed) {
                await saveInvoice(type, true); // Retry with confirmation
            }
        } else if (result?.error) {
            UI.showModalError(modalId, result.error);
        }
    }

    async function deleteInvoice(type, id) {
        const typeFa = type === 'salesInvoice' ? 'فاکتور فروش' : 'فاکتور خرید';
        UI.confirmAction(`آیا از حذف این ${typeFa} مطمئن هستید؟`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const action = type === 'salesInvoice' ? 'delete_sales_invoice' : 'delete_purchase_invoice';
                const result = await Api.call(action, { id });
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
        const personData = isSales ? appCache.customers : appCache.suppliers;
        const personId = isSales ? invoice.customerId : invoice.supplierId;
        const person = personData.find(p => p.id == personId);
        
        // Prepare invoice data with product names and person info
        const preparedInvoice = {
            ...invoice,
            customerName: person?.name || invoice.customerName || invoice.supplierName || '-',
            supplierName: person?.name || invoice.supplierName || '-',
            customerPhone: person?.phone || '-',
            customerAddress: person?.address || '-',
            items: (invoice.items || []).map(item => {
                if (item.is_service == 1) {
                    return {
                        ...item,
                        name: item.item_description || item.productName || 'خدمات',
                        productName: item.item_description || item.productName || 'خدمات',
                        image_url: '',
                        serialized_ids: []
                    };
                }
                const product = (appCache.products || []).find(p => p.id == item.productId);
                const rawImage = item.primary_full_image
                    || item.primary_image
                    || item.image_url
                    || product?.primary_full_image
                    || product?.primary_image
                    || '';
                let imageUrl = '';
                if (rawImage) {
                    try {
                        imageUrl = /^https?:\/\//i.test(rawImage) || String(rawImage).indexOf('data:') === 0
                            ? String(rawImage)
                            : new URL(String(rawImage), window.location.href).href;
                    } catch (e) {
                        imageUrl = String(rawImage);
                    }
                }
                return {
                    ...item,
                    name: product?.name || item.productName || 'محصول حذف شده',
                    productName: product?.name || item.productName || 'محصول حذف شده',
                    image_url: imageUrl,
                    serialized_ids: [...(item.serialized_ids || []), ...(item.returned_ids || [])]
                };
            }),
            payments: (invoice.payments || []).map(p => {
                // Setoff notes name the supplier/third party — keep that internal only.
                if (p.type === 'setoff') {
                    return { ...p, description: '' };
                }
                let description = p.description || '';
                if (p.type === 'check' && p.checkDetails) {
                    const dueDate = UI.gregorianToPersian(p.checkDetails.dueDate);
                    description = `شماره: ${p.checkDetails.checkNumber}, سررسید: ${dueDate}, بانک: ${p.checkDetails.bankName}${p.checkDetails.issuerName ? '، صادرکننده: ' + p.checkDetails.issuerName : ''}`;
                } else if (p.type === 'endorse_check') {
                    const endorsedCheck = appCache.checks.find(c => c.id == p.checkId);
                    if (endorsedCheck) description = `چک شماره: ${endorsedCheck.checkNumber}`;
                }
                return { ...p, description };
            })
        };

        // Use the shared print function from UI
        UI.printInvoice(preparedInvoice, type);
    }

    async function handleConsignmentAction(action, type, id) {
        if (action === 'mark_as_consignment') {
            // Mark as consignment - simple confirmation
            UI.confirmAction('آیا از انتقال این فاکتور به بخش امانی مطمئن هستید؟', async (confirmed) => {
                if (confirmed) {
                    UI.showLoader();
                    const result = await Api.call(action, { type, id });
                    UI.hideLoader();
                    if (result?.success) {
                        UI.showSuccess('فاکتور با موفقیت به بخش امانی منتقل شد.');
                        load('sales_invoices');
                        load('purchase_invoices');
                        load('consignment_sales');
                        load('consignment_purchases');
                    }
                }
            });
        } else {
            // Return from consignment - show partial conversion modal
            await openPartialConsignmentModal(type, id);
        }
    }

    async function openPartialConsignmentModal(type, invoiceId) {
        UI.showLoader();
        const result = await Api.call('get_consignment_invoice_items', { type, id: invoiceId });
        UI.hideLoader();

        if (!result?.success) {
            UI.showError(result?.error || 'خطا در دریافت اطلاعات فاکتور');
            return;
        }

        const { invoice, items } = result;
        const isSales = type === 'sales';

        // Populate modal
        $('#pc-invoice-id').text(invoice.id);
        $('#pc-invoice-date').text(UI.gregorianToPersian(invoice.date));
        $('#pc-invoice-person').text(invoice.personName || '-');
        $('#pc-person-label').text(isSales ? 'مشتری:' : 'تامین‌کننده:');
        $('#pc-invoice-type').val(type);
        $('#pc-invoice-id-input').val(invoiceId);

        // Populate items
        const itemsBody = $('#pc-items-body').empty();
        items.forEach(item => {
            const itemTotal = parseInt(item.quantity) * parseFloat(item.unitPrice);
            const row = $(`<tr>
                <td>
                    <input class="form-check-input pc-item-check" type="checkbox" 
                           value="${item.id}" 
                           data-quantity="${item.quantity}"
                           data-unit-price="${item.unitPrice}"
                           data-total="${itemTotal}">
                </td>
                <td>${item.productName || item.item_description || 'محصول نامشخص'}</td>
                <td>${item.dimensions || '-'}</td>
                <td>${item.quantity}</td>
                <td>${UI.formatCurrency(item.unitPrice)}</td>
                <td>${UI.formatCurrency(itemTotal)}</td>
            </tr>`);
            itemsBody.append(row);
        });

        // Reset selection summary
        updatePartialConsignmentSummary(invoice);

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('partialConsignmentModal'));
        modal.show();
    }

    function updatePartialConsignmentSummary(invoice) {
        const checkedItems = $('#pc-items-body .pc-item-check:checked');
        let selectedCount = 0;
        let selectedTotal = 0;

        checkedItems.each(function() {
            selectedCount++;
            selectedTotal += parseFloat($(this).data('total')) || 0;
        });

        // Calculate proportional discount
        const totalInvoiceAmount = parseFloat(invoice?.totalAmount) || 0;
        const totalDiscount = parseFloat(invoice?.discount) || 0;
        let proportionalDiscount = 0;

        if (totalInvoiceAmount > 0 && totalDiscount > 0 && selectedTotal > 0) {
            proportionalDiscount = Math.round((selectedTotal / totalInvoiceAmount) * totalDiscount);
        }

        $('#pc-selected-count').text(selectedCount);
        $('#pc-selected-total').text(UI.formatCurrency(selectedTotal));
        $('#pc-selected-discount').text(UI.formatCurrency(proportionalDiscount));

        // Enable/disable confirm button
        $('#pc-confirm-btn').prop('disabled', selectedCount === 0);
    }

    async function executePartialConsignmentConversion() {
        const type = $('#pc-invoice-type').val();
        const invoiceId = parseInt($('#pc-invoice-id-input').val());
        const selectedItems = [];

        $('#pc-items-body .pc-item-check:checked').each(function() {
            selectedItems.push(parseInt($(this).val()));
        });

        if (selectedItems.length === 0) {
            UI.showError('لطفاً حداقل یک قلم کالا انتخاب کنید.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('partial_return_from_consignment', {
            type,
            id: invoiceId,
            selectedItems
        });
        UI.hideLoader();

        if (result?.success) {
            bootstrap.Modal.getInstance(document.getElementById('partialConsignmentModal'))?.hide();
            UI.showSuccess(result.message || 'عملیات با موفقیت انجام شد.');
            
            // Refresh all relevant tables
            load('sales_invoices');
            load('purchase_invoices');
            load('consignment_sales');
            load('consignment_purchases');
        } else {
            UI.showError(result?.error || 'خطا در تبدیل فاکتور امانی');
        }
    }

    async function openSoldConsignmentReview() {
        UI.showLoader();
        const result = await Api.call('find_sold_consignment_purchases', {});
        UI.hideLoader();
        if (!result?.success) {
            UI.showError(result?.error || 'خطا در بررسی اقلام امانی فروخته‌شده');
            return;
        }

        const items = result.items || [];
        const body = $('#sold-consignment-body').empty();
        $('#sold-consignment-count').text(items.length ? `${items.length} قلم` : '');
        $('#sold-consignment-convert-btn').prop('disabled', items.length === 0);

        if (!items.length) {
            $('#sold-consignment-empty').show();
            $('#sold-consignment-table-wrap').hide();
        } else {
            $('#sold-consignment-empty').hide();
            $('#sold-consignment-table-wrap').show();
            items.forEach(item => {
                body.append(`<tr>
                    <td>#${item.consignment_invoice_id}</td>
                    <td>${item.supplier_name || '-'}</td>
                    <td>${item.product_name || '-'} <small class="text-muted">${item.dimensions || ''}</small></td>
                    <td><code>${item.serial_id || '-'}</code></td>
                    <td>#${item.sales_invoice_id || '-'}</td>
                    <td>${item.customer_name || '-'}</td>
                    <td>${item.sales_date ? UI.gregorianToPersian(item.sales_date) : '-'}</td>
                </tr>`);
            });
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('soldConsignmentReviewModal')).show();
    }

    async function executeSoldConsignmentConversion() {
        const reviewEl = document.getElementById('soldConsignmentReviewModal');
        if (reviewEl?.classList.contains('show')) {
            await new Promise((resolve) => {
                $(reviewEl).one('hidden.bs.modal', () => resolve());
                bootstrap.Modal.getInstance(reviewEl)?.hide();
                setTimeout(resolve, 450);
            });
            $('.modal.modal-underlay').removeClass('modal-underlay');
        }

        const confirmed = await UI.confirm(
            'اقلام فروخته‌شده از فاکتور امانی به فاکتور خرید تبدیل می‌شوند و بقیه اقلام روی فاکتور امانی می‌مانند. ادامه می‌دهید؟',
            'تبدیل اقلام فروخته‌شده'
        );
        if (!confirmed) {
            if (reviewEl) {
                bootstrap.Modal.getOrCreateInstance(reviewEl).show();
            }
            return;
        }

        UI.showLoader();
        const result = await Api.call('convert_sold_consignment_purchases', {});
        UI.hideLoader();
        if (result?.success) {
            if (parseInt(result.converted_count, 10) > 0) {
                UI.showSuccess(result.message || 'اقلام فروخته‌شده به فاکتور خرید تبدیل شد.');
            } else {
                UI.showSuccess('موردی برای تبدیل وجود نداشت.');
            }
            load('consignment_purchases');
            load('purchase_invoices');
        } else {
            UI.showError(result?.error || 'خطا در تبدیل اقلام امانی فروخته‌شده');
            if (reviewEl) {
                bootstrap.Modal.getOrCreateInstance(reviewEl).show();
            }
        }
    }

    function updatePersonDropdown(type, newPerson) {
        const isSales = type === 'sales';
        const personType = isSales ? 'customer' : 'supplier';
        const modalId = isSales ? '#salesInvoiceModal' : '#purchaseInvoiceModal';
        const personSelect = $(`#${type}-invoice-${personType}`);

        if (personSelect.data('select2')) {
            personSelect.select2('destroy');
        }

        personSelect.empty().append('<option></option>');
        const personList = isSales ? App.getCache().customers : App.getCache().suppliers;
        (personList || []).forEach(p => personSelect.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`));

        personSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: `انتخاب ${personType === 'customer' ? 'مشتری' : 'تأمین‌کننده'}...`,
            dropdownParent: $(modalId)
        });

        if (newPerson && newPerson.id) {
            personSelect.val(newPerson.id).trigger('change');
        }
    }

    // ---------- Invoice history & linked items ----------
    const HISTORY_ACTIONS = {
        created: { label: 'ایجاد', icon: 'bi-plus-circle', color: 'success' },
        updated: { label: 'ویرایش', icon: 'bi-pencil-square', color: 'warning' },
        deleted: { label: 'حذف', icon: 'bi-trash', color: 'danger' },
        consignment_marked: { label: 'انتقال به امانی', icon: 'bi-truck', color: 'secondary' },
        consignment_returned: { label: 'بازگردانی از امانی', icon: 'bi-arrow-return-left', color: 'info' },
        consignment_partial: { label: 'تبدیل بخشی امانی', icon: 'bi-scissors', color: 'info' },
        delivery_status: { label: 'تغییر وضعیت تحویل', icon: 'bi-box-seam', color: 'primary' },
    };
    const DELIVERY_LABELS = { preparing: 'در حال آماده‌سازی', ready: 'آماده تحویل', delivered: 'تحویل شده' };
    const STOCK_STATUS_LABELS = {
        in_stock: 'در انبار', sold: 'فروخته‌شده', consignment_out: 'امانی نزد مشتری', returned: 'مرجوعی',
        returned_to_supplier: 'مرجوع به تأمین‌کننده', reserved: 'رزرو', damaged: 'معیوب',
    };
    const MONEY_FIELDS = ['totalAmount', 'discount', 'tax_amount', 'paidAmount'];

    function formatHistoryValue(field, value) {
        if (value === null || value === undefined || value === '') return '<span class="text-muted">—</span>';
        if (MONEY_FIELDS.includes(field)) return UI.formatCurrency(value);
        if (field === 'date' || field === 'due_date') return UI.gregorianToPersian(value);
        if (field === 'delivery_status') return DELIVERY_LABELS[value] || UI.escapeHtml(value);
        if (field === 'is_consignment') return String(value) === '1' ? 'بله' : 'خیر';
        if (field === 'tax_rate') return `${parseFloat(value)}٪`;
        return UI.escapeHtml(String(value));
    }

    function formatHistoryTimestamp(ts) {
        if (!ts) return '';
        const [d, t] = String(ts).split(' ');
        return `${UI.gregorianToPersian(d)} ${t ? t.slice(0, 5) : ''}`;
    }

    function historyItemLabel(it) {
        const dims = it.dimensions ? ` <span class="text-muted small">(${UI.escapeHtml(it.dimensions)})</span>` : '';
        return `${UI.escapeHtml(it.productName || it.item_description || '—')}${dims}`;
    }

    function renderHistoryChanges(c) {
        const parts = [];
        if (c.header && c.header.length) {
            const rows = c.header.map(h => `<tr><td>${UI.escapeHtml(h.label)}</td><td class="text-danger">${formatHistoryValue(h.field, h.old)}</td><td class="text-success">${formatHistoryValue(h.field, h.new)}</td></tr>`).join('');
            parts.push(`<table class="table table-sm table-bordered mb-2"><thead class="table-light"><tr><th>فیلد</th><th>قبل</th><th>بعد</th></tr></thead><tbody>${rows}</tbody></table>`);
        }
        const rows = [];
        (c.items_added || []).forEach(it => rows.push(`<tr class="table-success"><td><i class="bi bi-plus-lg"></i> افزوده</td><td>${historyItemLabel(it)}</td><td>${it.quantity}</td><td>${UI.formatCurrency(it.unitPrice)}</td></tr>`));
        (c.items_removed || []).forEach(it => rows.push(`<tr class="table-danger"><td><i class="bi bi-dash-lg"></i> حذف</td><td>${historyItemLabel(it)}</td><td>${it.quantity}</td><td>${UI.formatCurrency(it.unitPrice)}</td></tr>`));
        (c.items_changed || []).forEach(it => {
            const qty = it.old_quantity != it.new_quantity ? `<s class="text-danger">${it.old_quantity}</s> ← ${it.new_quantity}` : it.new_quantity;
            const price = Math.abs(it.old_unitPrice - it.new_unitPrice) > 0.004
                ? `<s class="text-danger">${UI.formatCurrency(it.old_unitPrice)}</s> ← ${UI.formatCurrency(it.new_unitPrice)}`
                : UI.formatCurrency(it.new_unitPrice);
            rows.push(`<tr class="table-warning"><td><i class="bi bi-arrow-left-right"></i> تغییر</td><td>${historyItemLabel(it)}</td><td>${qty}</td><td>${price}</td></tr>`);
        });
        if (rows.length) {
            parts.push(`<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th style="width:90px">اقلام</th><th>کالا</th><th>تعداد</th><th>فی</th></tr></thead><tbody>${rows.join('')}</tbody></table>`);
        }
        return parts.join('');
    }

    function renderHistorySnapshot(snap) {
        const h = snap.header || {};
        const items = (snap.items || []).map(it => `<tr><td>${historyItemLabel(it)}</td><td>${it.quantity}</td><td>${UI.formatCurrency(it.unitPrice)}</td></tr>`).join('');
        return `<div class="small text-muted mb-1">طرف حساب: ${UI.escapeHtml(h.personName || '—')} · تاریخ: ${formatHistoryValue('date', h.date)} · جمع: ${formatHistoryValue('totalAmount', h.totalAmount)}</div>
            ${items ? `<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>کالا</th><th>تعداد</th><th>فی</th></tr></thead><tbody>${items}</tbody></table>` : ''}`;
    }

    function renderHistoryTimeline(events) {
        if (!events.length) {
            return `<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>هنوز تغییری برای این فاکتور ثبت نشده است.<div class="small mt-1">ثبت تاریخچه از زمان فعال‌شدن این قابلیت انجام می‌شود.</div></div>`;
        }
        return events.map(ev => {
            const a = HISTORY_ACTIONS[ev.action] || { label: ev.action, icon: 'bi-dot', color: 'secondary' };
            let body = '';
            if (ev.changes) {
                body = renderHistoryChanges(ev.changes);
            } else if ((ev.action === 'created' || ev.action === 'deleted') && ev.snapshot) {
                body = renderHistorySnapshot(ev.snapshot);
            }
            return `<div class="card mb-2 border-${a.color}">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                    <span><span class="badge bg-${a.color}"><i class="bi ${a.icon} me-1"></i>${a.label}</span>
                        ${ev.note ? `<span class="small ms-2">${UI.escapeHtml(ev.note)}</span>` : ''}</span>
                    <span class="small text-muted"><i class="bi bi-person me-1"></i>${UI.escapeHtml(ev.username || 'سیستم')} · <span dir="ltr">${formatHistoryTimestamp(ev.created_at)}</span></span>
                </div>
                ${body ? `<div class="card-body py-2">${body}</div>` : ''}
            </div>`;
        }).join('');
    }

    function renderLinkedItems(type, linked) {
        const links = linked.links || [];
        const returns = linked.returns || [];
        const otherType = type === 'sales' ? 'purchase' : 'sales';
        const otherLabel = type === 'sales' ? 'فاکتور خرید' : 'فاکتور فروش';
        const personLabel = type === 'sales' ? 'تأمین‌کننده' : 'مشتری';
        let html = '';

        if (!links.length) {
            html += `<div class="text-center text-muted py-3">هیچ قلم انباری از این فاکتور به ${otherLabel} دیگری متصل نیست.</div>`;
        } else {
            const rows = links.map(l => {
                let target;
                if (l.linked_invoice_id) {
                    target = `<a href="#" class="invoice-history-open fw-bold" data-type="${otherType}" data-id="${l.linked_invoice_id}" title="باز کردن فاکتور">#${l.linked_invoice_id}</a>`
                        + ` <a href="#" class="invoice-history-jump text-muted ms-1" data-type="${otherType}" data-id="${l.linked_invoice_id}" title="تاریخچه و اقلام مرتبط این فاکتور"><i class="bi bi-clock-history"></i></a>`
                        + (String(l.linked_is_consignment) === '1' ? ' <span class="badge bg-secondary">امانی</span>' : '');
                } else if (type === 'purchase') {
                    target = `<span class="badge bg-light text-dark border">${STOCK_STATUS_LABELS[l.stock_status] || UI.escapeHtml(l.stock_status || '—')}</span>`;
                } else {
                    target = '<span class="text-muted small">بدون فاکتور خرید (موجودی اولیه/دستی)</span>';
                }
                const returned = l.returned_qty > 0 ? ` <span class="badge bg-danger">${l.returned_qty} مرجوعی</span>` : '';
                return `<tr>
                    <td>${historyItemLabel(l)}</td>
                    <td>${target}</td>
                    <td>${l.linked_invoice_id ? UI.gregorianToPersian(l.linked_date) : ''}</td>
                    <td>${UI.escapeHtml(l.linked_person || '')}</td>
                    <td>${l.qty}${returned}</td>
                    <td>${l.linked_invoice_id && l.linked_unit_price != null ? UI.formatCurrency(l.linked_unit_price) : ''}</td>
                    <td class="small text-muted" style="max-width:220px">${UI.escapeHtml(l.serials || '')}</td>
                </tr>`;
            }).join('');
            html += `<div class="table-responsive"><table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-light"><tr><th>کالا</th><th>${otherLabel}</th><th>تاریخ</th><th>${personLabel}</th><th>تعداد</th><th>فی ${type === 'sales' ? 'خرید' : 'فروش'}</th><th>شناسه‌ها</th></tr></thead>
                <tbody>${rows}</tbody></table></div>`;
        }

        if (returns.length) {
            const rows = returns.map(r => `<tr><td>#${r.return_id}</td><td>${UI.gregorianToPersian(r.date)}</td><td>${historyItemLabel(r)}</td><td>${r.quantity}</td><td>${UI.formatCurrency(r.amount)}</td></tr>`).join('');
            html += `<h6 class="mt-3"><i class="bi bi-arrow-return-left me-1"></i>فاکتورهای مرجوعی این فاکتور</h6>
                <div class="table-responsive"><table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th>شماره مرجوعی</th><th>تاریخ</th><th>کالا</th><th>تعداد</th><th>مبلغ</th></tr></thead>
                <tbody>${rows}</tbody></table></div>`;
        }
        return html;
    }

    // Navigation stack inside the history modal (jumping between linked invoices)
    let historyStack = [];
    let historyRequestSeq = 0;

    function historyInvoiceLabel(entry) {
        return `${entry.type === 'sales' ? 'فروش' : 'خرید'} #${entry.id}`;
    }

    function renderHistoryNav() {
        const $nav = $('#invoice-history-nav');
        if (historyStack.length <= 1) {
            $nav.addClass('d-none');
            return;
        }
        const crumbs = historyStack.map((e, i) => i === historyStack.length - 1
            ? `<span class="fw-bold">${historyInvoiceLabel(e)}</span>`
            : `<a href="#" class="invoice-history-crumb" data-index="${i}">${historyInvoiceLabel(e)}</a>`
        ).join(' <i class="bi bi-chevron-left mx-1 text-muted"></i> ');
        $('#invoice-history-breadcrumb').html(crumbs);
        $nav.removeClass('d-none');
    }

    /**
     * @param {object} opts  mode: 'open' (fresh from a list row), 'push' (jump to a linked invoice),
     *                       'stack' (back/breadcrumb — stack already adjusted)
     */
    async function openInvoiceHistory(type, id, opts = {}) {
        const mode = opts.mode || 'open';
        const el = document.getElementById('invoiceHistoryModal');
        const activeTarget = $(el).find('.nav-link.active').attr('data-bs-target');

        const seq = ++historyRequestSeq;
        UI.showLoader();
        const result = await Api.call('get_invoice_history', { type, id });
        UI.hideLoader();
        // A newer click superseded this request — drop the stale response.
        if (seq !== historyRequestSeq) return;
        if (!result?.success) {
            UI.showError(result?.error || 'خطا در دریافت تاریخچه فاکتور');
            return;
        }

        if (mode === 'open') {
            historyStack = [{ type, id: String(id) }];
        } else if (mode === 'push') {
            historyStack.push({ type, id: String(id) });
        }
        renderHistoryNav();

        const typeFa = type === 'sales' ? 'فاکتور فروش' : 'فاکتور خرید';
        const history = result.history || [];
        const linked = result.linked || { links: [], returns: [] };
        const linkedInvoiceCount = new Set((linked.links || []).filter(l => l.linked_invoice_id).map(l => l.linked_invoice_id)).size;

        $('#invoice-history-title').text(`تاریخچه ${typeFa} #${id}${result.exists ? '' : ' (حذف‌شده)'}`);
        $('#invoice-history-count').text(history.length);
        $('#invoice-history-links-label').text(type === 'sales' ? 'اقلام متصل به فاکتورهای خرید' : 'اقلام متصل به فاکتورهای فروش');
        $('#invoice-history-links-count').text(linkedInvoiceCount);
        $('#invoice-history-timeline').html(renderHistoryTimeline(history));
        $('#invoice-history-links').html(
            `<div class="alert alert-light border py-2 small mb-2"><i class="bi bi-receipt me-1"></i>اقلام ${typeFa} <strong>#${id}</strong></div>`
            + renderLinkedItems(type, linked)
        );

        // Fresh open starts on the history tab; navigating keeps the tab the user is on.
        const targetTab = (mode === 'open' || !activeTarget) ? '#invoice-history-tab-changes' : activeTarget;
        bootstrap.Tab.getOrCreateInstance(el.querySelector(`[data-bs-target="${targetTab}"]`)).show();
        bootstrap.Modal.getOrCreateInstance(el).show();
        el.querySelector('.modal-body').scrollTop = 0;
    }

    function goToHistoryIndex(index) {
        if (index < 0 || index >= historyStack.length - 1) return;
        historyStack = historyStack.slice(0, index + 1);
        const target = historyStack[index];
        openInvoiceHistory(target.type, target.id, { mode: 'stack' });
    }

    function attachEvents() {
        const salesSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.sales_invoices;
            delete state.filterType; // Clear special filters when searching
            $('#sales_invoices-filter-info').empty();
            state.searchTerm = term;
            state.currentPage = 1;
            load('sales_invoices');
        }, 500);
        $('#sales-invoice-search').on('input', function () { salesSearchHandler($(this).val()); });

        $('#filter-online-store-invoices-btn').on('click', function () {
            load('sales_invoices', { filterType: 'online_store' }, 'نمایش فاکتورهای فروشگاه آنلاین');
        });

        const purchaseSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.purchase_invoices;
            delete state.filterType; // Clear special filters when searching
            $('#purchase_invoices-filter-info').empty();
            state.searchTerm = term;
            state.currentPage = 1;
            load('purchase_invoices');
        }, 500);
        $('#purchase-invoice-search').on('input', function () { purchaseSearchHandler($(this).val()); });

        const consignmentPurchaseSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.consignment_purchases;
            state.searchTerm = term;
            state.currentPage = 1;
            load('consignment_purchases');
        }, 500);
        $('#consignment-purchase-invoice-search').on('input', function () { consignmentPurchaseSearchHandler($(this).val()); });

        const consignmentSalesSearchHandler = UI.debounce((term) => {
            const state = AppConfig.TABLE_STATES.consignment_sales;
            state.searchTerm = term;
            state.currentPage = 1;
            load('consignment_sales');
        }, 500);
        $('#consignment-sales-invoice-search').on('input', function () { consignmentSalesSearchHandler($(this).val()); });

        $('body').on('input change', '#sales-invoice-form .quantity, #sales-invoice-form .unit-price, #sales-invoice-form .meter-price, #sales-invoice-form .price-entry-mode, #sales-invoice-form .payment-amount, #sales-invoice-form .payment-transaction-type, #sales-invoice-discount, #sales-invoice-form .dimensions-select, #sales-invoice-form .custom-dimension-input', () => calculateInvoiceTotals('sales'));
        $('body').on('input change', '#purchase-invoice-form .quantity, #purchase-invoice-form .unit-price, #purchase-invoice-form .meter-price, #purchase-invoice-form .price-entry-mode, #purchase-invoice-form .payment-amount, #purchase-invoice-form .payment-transaction-type, #purchase-invoice-discount, #purchase-invoice-form .dimensions-select, #purchase-invoice-form .custom-dimension-input', () => calculateInvoiceTotals('purchase'));


        $('body').on('input change', '.dynamic-row .quantity, .dynamic-row .unit-price, .dynamic-row .meter-price', function () {
            const row = $(this).closest('.dynamic-row');
            if ($(this).hasClass('meter-price') || row.find('.price-entry-mode').val() === 'meter') {
                applyMeterPriceToUnit(row);
            } else if ($(this).hasClass('unit-price')) {
                applyUnitPriceToMeter(row);
            }
            calculateInvoiceItemTotal(row);
        });

        $('body').on('change', '.dynamic-row .price-entry-mode', function () {
            const row = $(this).closest('.dynamic-row');
            syncPriceEntryMode(row);
            const type = row.closest('form').attr('id').includes('sales') ? 'sales' : 'purchase';
            calculateInvoiceTotals(type);
        });

        $('body').on('change', '.dynamic-row .dimensions-select, .dynamic-row .custom-dimension-input', function () {
            const row = $(this).closest('.dynamic-row');
            if (row.find('.price-entry-mode').val() === 'meter') {
                applyMeterPriceToUnit(row);
            } else {
                applyUnitPriceToMeter(row);
            }
            calculateInvoiceItemTotal(row);
        });

        $('body').on('click', '.remove-item-btn, .remove-payment-btn', function () {
            const form = $(this).closest('form');
            const type = form.attr('id').includes('sales') ? 'sales' : 'purchase';
            const isItem = $(this).hasClass('remove-item-btn');
            $(this).closest('.dynamic-row').remove();
            if (isItem) {
                renumberInvoiceItemRows($(`#${type}-invoice-items-container`));
            } else {
                renumberInvoicePaymentRows($(`#${type}-invoice-payments-container`));
            }
            calculateInvoiceTotals(type);
        });

        $('#add-sales-invoice-item-btn').on('click', () => addInvoiceItemRow($('#sales-invoice-items-container')));
        $('#add-purchase-invoice-item-btn').on('click', () => addInvoiceItemRow($('#purchase-invoice-items-container')));
        $('#add-sales-service-item-btn').on('click', () => addServiceItemRow($('#sales-invoice-items-container')));
        $('#add-purchase-service-item-btn').on('click', () => addServiceItemRow($('#purchase-invoice-items-container')));
        $('#add-sales-invoice-payment-btn').on('click', () => addPaymentRow($('#sales-invoice-payments-container'), 'sales'));
        $('#add-purchase-invoice-payment-btn').on('click', () => addPaymentRow($('#purchase-invoice-payments-container'), 'purchase'));

        $('body').on('change', '.product-select', function () {
            if (isInvoicePopulating) return;
            populateDimensions($(this));
            const row = $(this).closest('.dynamic-row');
            // Clear serial display/select when product changes
            row.find('.item-serial-display').val('');
            if (row.find('.item-serial-select').length) {
                populateSerialSelect(row);
            }
            refreshRowPriceSuggestions(row);
        });

        // Sales: populate serial dropdown when dimension is selected
        $('body').on('change', '.dimensions-select', function () {
            if (isInvoicePopulating) return;
            const row = $(this).closest('.dynamic-row');
            const isSales = row.closest('form').attr('id').includes('sales');
            if (isSales) {
                populateSerialSelect(row);
            }
            refreshRowPriceSuggestions(row);
        });

        $('body').on('change blur', '.custom-dimension-input', function () {
            if (isInvoicePopulating) return;
            refreshRowPriceSuggestions($(this).closest('.dynamic-row'));
        });

        $('body').on('focus', '.unit-price, .meter-price', function () {
            const row = $(this).closest('.dynamic-row');
            if (row.find('.price-suggestions').is('[hidden]') || !row.find('.price-suggestions').children().length) {
                refreshRowPriceSuggestions(row);
            }
        });

        $('body').on('click', '.price-suggest-chip', function (e) {
            e.preventDefault();
            const row = $(this).closest('.dynamic-row');
            applySuggestedPrice(row, $(this).data('unit-price'), $(this).data('meter-price'));
        });

        $('body').on('change', '#sales-invoice-customer', function () {
            refreshAllPriceSuggestions('sales');
        });
        $('body').on('change', '#purchase-invoice-supplier', function () {
            refreshAllPriceSuggestions('purchase');
        });

        // Sales: update serial dropdown when quantity changes
        $('body').on('change', '.quantity', function () {
            const row = $(this).closest('.dynamic-row');
            const isSales = row.closest('form').attr('id').includes('sales');
            if (!isSales) return;
            const serialSelect = row.find('.item-serial-select');
            if (!serialSelect.length) return;
            const qty = parseInt($(this).val()) || 1;
            const currentSelected = serialSelect.val() || [];
            // Trim excess selections if qty decreased, then re-populate with new max
            const trimmed = currentSelected.slice(0, qty);
            populateSerialSelect(row, trimmed, true);
        });

        $('body').on('click', '.btn-add-new-product-inline', function () {
            const row = $(this).closest('.dynamic-row');
            const select = row.find('.product-select');
            const input = row.find('.new-product-name');
            const imagesWrap = row.find('.new-product-images-wrap');
            const imagesHint = row.find('.new-product-images-hint');
            const imagesPreview = row.find('.new-product-images-preview');
            const dimensionsSelect = row.find('.dimensions-select');

            if (input.is(':visible')) {
                input.hide().val('');
                imagesWrap.hide();
                clearPendingProductImages(row);
                imagesHint.hide();
                imagesPreview.hide();
                select.prop('disabled', false).select2('open');
                dimensionsSelect.empty().append('<option>ابتدا طرح را انتخاب کنید</option>').prop('disabled', true);
                row.find('.price-suggestions').empty().attr('hidden', true);
                row.find('.price-suggestions-wrap').removeClass('is-visible');
            } else {
                select.val(null).trigger('change');
                select.prop('disabled', true).select2('close');
                input.show().focus();
                imagesWrap.show();
                imagesPreview.empty().hide();
                imagesHint.show().text('اختیاری: تصاویر محصول را انتخاب کنید');

                dimensionsSelect.empty().prop('disabled', false).append('<option value="">انتخاب کنید...</option>');
                const standardDimensions = (App.getCache().publicSettings.standard_dimensions || '').split(',').map(d => d.trim()).filter(Boolean);
                standardDimensions.forEach(dim => dimensionsSelect.append(`<option value="${dim}">${dim}</option>`));
                dimensionsSelect.append('<option value="custom">سایز سفارشی...</option>');
            }
        });

        let newProductNameTimer = null;
        $('body').on('input', '.new-product-name', function () {
            const row = $(this).closest('.dynamic-row');
            clearTimeout(newProductNameTimer);
            newProductNameTimer = setTimeout(() => refreshRowPriceSuggestions(row), 350);
        });
        $('body').on('blur', '.new-product-name', function () {
            refreshRowPriceSuggestions($(this).closest('.dynamic-row'));
        });

        $('body').on('click', '.btn-pick-product-images', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const input = $(this).closest('.new-product-images-wrap').find('.new-product-images')[0];
            if (input) input.click();
        });

        $('body').on('click', '.btn-remove-pending-image', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const row = $(this).closest('.dynamic-row');
            const idx = parseInt($(this).attr('data-idx'), 10);
            const files = getPendingImages(row);
            if (Number.isNaN(idx) || idx < 0 || idx >= files.length) return;
            files.splice(idx, 1);
            setPendingImages(row, files);
            renderPendingProductImages(row);
        });

        $('body').on('click', '.btn-clear-pending-images', function (e) {
            e.preventDefault();
            e.stopPropagation();
            clearPendingProductImages($(this).closest('.dynamic-row'));
        });

        $('body').on('change', '.new-product-images', async function () {
            const row = $(this).closest('.dynamic-row');
            const input = this;
            const files = Array.from(input.files || []);
            const invoiceModalEl = document.getElementById('purchaseInvoiceModal');
            const invoiceModal = invoiceModalEl ? bootstrap.Modal.getOrCreateInstance(invoiceModalEl) : null;
            input.value = '';
            if (!files.length) return;

            if (!window.ProductImageEditor) {
                setPendingImages(row, getPendingImages(row).concat(files));
                renderPendingProductImages(row);
                return;
            }

            UI.showLoader();
            let edited = [];
            let invoiceModalWasOpen = false;
            const rowSnap = snapshotPurchaseRows();
            try {
                invoiceModalWasOpen = !!invoiceModalEl?.classList.contains('show');
                if (invoiceModalWasOpen && invoiceModalEl) {
                    const waitInvoiceHidden = new Promise((resolve) => {
                        const onHidden = () => resolve();
                        invoiceModalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
                        setTimeout(() => {
                            invoiceModalEl.removeEventListener('hidden.bs.modal', onHidden);
                            resolve();
                        }, 800);
                    });
                    invoiceModal?.hide();
                    await waitInvoiceHidden;
                }
                UI.hideLoader();
                edited = await ProductImageEditor.openQueue(files, { mode: 'return' });
                if (window.ProductImageEditor?.hideEditorModal) {
                    await ProductImageEditor.hideEditorModal();
                }
            } catch (e) {
                UI.hideLoader();
                // در صورت خطا، فایل‌های اصلی را نگه دار
                setPendingImages(row, getPendingImages(row).concat(files));
                renderPendingProductImages(row);
                UI.showError(e?.message || 'خطا در ویرایش تصویر');
                return;
            } finally {
                if (invoiceModalWasOpen) {
                    invoiceModal?.show();
                }
                restorePurchaseRows(rowSnap);
            }

            // اگر کاربر همه را انصراف داد، چیزی اضافه نکن؛ اگر ذخیره کرد فایل ویرایش‌شده را بگیر
            if (edited && edited.length) {
                setPendingImages(row, getPendingImages(row).concat(edited));
            }
            renderPendingProductImages(row);
        });

        $('body').on('change', '.dimensions-select', function () {
            const container = $(this).closest('.dimensions-container');
            if ($(this).val() === 'custom') {
                if (!container.find('.custom-dimension-input').length) {
                    container.append(`<div class="mt-2 custom-dimension-wrapper"><input type="text" class="form-control custom-dimension-input" placeholder="مثال: 2.1x3.2"></div>`);
                }
                return;
            }
            container.find('.custom-dimension-wrapper').remove();
        });

        $('body').on('change', '#sales-invoice-form .quantity, #sales-invoice-form .dimensions-select', function () {
            const currentRow = $(this).closest('.dynamic-row');
            // Skip stock validation for service rows
            if (currentRow.data('is-service') == 1) return;
            const productId = currentRow.find('.product-select').val();
            const dimensions = currentRow.find('.dimensions-select').val();
            const quantityInput = currentRow.find('.quantity');
            const requestedQuantity = parseInt(quantityInput.val()) || 0;

            if (!productId || !dimensions) return;

            const product = App.getCache().products.find(p => p.id == productId);
            if (!product) return;

            // 1. موجودی فعلی در دیتابیس
            const stockItem = product.stock.find(s => s.dimensions === dimensions);
            const stockInDb = stockItem ? availableQty(stockItem) : 0;

            // 2. تعداد این کالا که در فاکتور اصلی (قبل از ویرایش) وجود داشت
            const originalItems = $(this).closest('form').data('original-items') || [];
            const originalItem = originalItems.find(it => it.productId == productId && it.dimensions === dimensions);
            const originalQtyOnInvoice = originalItem ? Number(originalItem.quantity) : 0;

            // 3. مجموع تعدادی که در حال حاضر در تمام ردیف‌های مودال برای این کالا درخواست شده
            let currentTotalRequested = 0;
            $('#sales-invoice-items-container .dynamic-row').each(function () {
                if ($(this).find('.product-select').val() == productId && $(this).find('.dimensions-select').val() === dimensions) {
                    currentTotalRequested += parseInt($(this).find('.quantity').val()) || 0;
                }
            });

            // 4. کل موجودی قابل دسترس برای این جلسه ویرایش
            const totalAvailableStock = stockInDb + originalQtyOnInvoice;

            // 5. بررسی نهایی
            if (currentTotalRequested > totalAvailableStock) {
                UI.showError(`موجودی کل برای این کالا ${totalAvailableStock} عدد است. شما ${currentTotalRequested} عدد درخواست کرده‌اید.`);
                // مقدار را به حداکثر مجاز کاهش می‌دهیم
                const overshoot = currentTotalRequested - totalAvailableStock;
                quantityInput.val(requestedQuantity - overshoot);
                quantityInput.trigger('input'); // برای به‌روزرسانی جمع ردیف
            }
        });

        $('body').on('change', '.payment-type', function () {
            const row = $(this).closest('.payment-row');
            if (row.attr('data-from-advance') === '1') return;
            const type = $(this).val();
            const invoiceType = $(this).closest('form').attr('id').includes('sales') ? 'sales' : 'purchase';
            const txn = row.find('.payment-transaction-type').val();
            const amount = row.find('.payment-amount').val();
            const date = row.find('.payment-date').val();

            const parent = row.parent();
            const newRow = addPaymentRow(parent, invoiceType, {
                type: type,
                transaction_type: txn,
                amount: parseInvoiceNumber(amount) || 0,
                date: date || UI.today()
            });
            row.before(newRow);
            row.remove();
            renumberInvoicePaymentRows(parent);
            calculateInvoiceTotals(invoiceType);
        });

        $('body').on('change', '.payment-transaction-type', function () {
            const invoiceType = $(this).closest('form').attr('id').includes('sales') ? 'sales' : 'purchase';
            calculateInvoiceTotals(invoiceType);
        });

        $('#sales-attach-advance-btn').on('click', () => attachSelectedAdvances('sales'));
        $('#purchase-attach-advance-btn').on('click', () => attachSelectedAdvances('purchase'));

        $('body').on('change', '.endorsed-check-select', function () {
            const amount = $(this).find('option:selected').data('amount') || 0;
            $(this).closest('.payment-row').find('.payment-amount').val(Number(amount).toLocaleString('en-US'));
            calculateInvoiceTotals('purchase');
        });

        $('#save-sales-invoice-btn').on('click', () => saveInvoice('sales'));
        ['sales', 'purchase'].forEach(t => {
            $(`#${t}-invoice-form`).on('input change', () => scheduleDraftSave(t));
        });
        $('#save-purchase-invoice-btn').on('click', () => saveInvoice('purchase'));

        // Tax toggle handler
        $('body').on('change', '#sales-invoice-tax-enabled, #purchase-invoice-tax-enabled', function () {
            const type = $(this).attr('id').includes('sales') ? 'sales' : 'purchase';
            const enabled = $(this).is(':checked');
            if (enabled) {
                const defaultRate = parseFloat((App.getCache().settings || {}).default_tax_rate) || 0;
                $(`#${type}-invoice-tax-rate`).val(defaultRate);
                $(`.${type}-tax-row`).show();
            } else {
                $(`#${type}-invoice-tax-rate`).val('0');
                $(`.${type}-tax-row`).hide();
            }
            calculateInvoiceTotals(type);
        });

        // Payment terms handler - show/hide due date and auto-calculate
        $('body').on('change', '#sales-invoice-payment-terms, #purchase-invoice-payment-terms', function () {
            const type = $(this).attr('id').includes('sales') ? 'sales' : 'purchase';
            const terms = $(this).val();
            const dueDateInput = $(`#${type}-invoice-due-date`);

            if (terms === 'cash' || !terms) {
                _setInvoiceDueDateVisible(type, false);
                dueDateInput.val('');
            } else {
                _setInvoiceDueDateVisible(type, true);
                // Auto-calculate due date for net terms
                const daysMap = { net15: 15, net30: 30, net60: 60, net90: 90 };
                if (daysMap[terms]) {
                    const gregDate = UI.toGregorian(`#${type}-invoice-date`);
                    if (gregDate) {
                        const d = new Date(gregDate);
                        d.setDate(d.getDate() + daysMap[terms]);
                        const isoStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
                        dueDateInput.val(UI.gregorianToPersian(isoStr));
                    }
                }
            }
        });

        $('body').on('click', '.btn-copy-purchase-invoice, #copy-purchase-invoice-btn', function (e) {
            e.preventDefault();
            const id = $(this).attr('data-id') || $('#purchase-invoice-id').val();
            copyPurchaseInvoice(id);
        });

        // Invoice history & linked items
        $('body').on('click', '.btn-invoice-history', function (e) {
            e.preventDefault();
            openInvoiceHistory($(this).attr('data-type'), $(this).attr('data-id'), { mode: 'open' });
        });
        $('body').on('click', '.invoice-history-jump', function (e) {
            e.preventDefault();
            openInvoiceHistory($(this).attr('data-type'), $(this).attr('data-id'), { mode: 'push' });
        });
        // Linked invoice number → close the history modal and open that invoice
        $('body').on('click', '.invoice-history-open', async function (e) {
            e.preventDefault();
            const type = $(this).attr('data-type');
            const id = $(this).attr('data-id');
            UI.showLoader();
            const data = await Api.call('get_entity_by_id', { entityType: type === 'sales' ? 'salesInvoice' : 'purchaseInvoice', id });
            UI.hideLoader();
            if (!data || data.error) {
                UI.showError(data?.error || 'فاکتور یافت نشد.');
                return;
            }
            const historyEl = document.getElementById('invoiceHistoryModal');
            const historyModal = bootstrap.Modal.getInstance(historyEl);
            if (historyModal && historyEl.classList.contains('show')) {
                $(historyEl).one('hidden.bs.modal', () => prepareInvoiceModal(type, data));
                historyModal.hide();
            } else {
                prepareInvoiceModal(type, data);
            }
        });
        $('body').on('click', '#invoice-history-back', function (e) {
            e.preventDefault();
            goToHistoryIndex(historyStack.length - 2);
        });
        $('body').on('click', '.invoice-history-crumb', function (e) {
            e.preventDefault();
            goToHistoryIndex(parseInt($(this).attr('data-index'), 10));
        });

        // Clone invoice button
        $('body').on('click', '.btn-clone-invoice', async function (e) {
            e.preventDefault();
            const id = $(this).attr('data-id');
            const type = $(this).attr('data-type');
            if (!await UI.confirmText('آیا از کپی این فاکتور مطمئن هستید؟')) return;
            const result = await Api.call('clone_invoice', { id, type });
            if (result?.success) {
                UI.showSuccess(result.message || 'فاکتور با موفقیت کپی شد.');
                load(`${type}_invoices`);
            } else {
                UI.showError(result?.error || 'خطا در کپی فاکتور');
            }
        });

        // Product search is now integrated into Select2 matcher (searches by ID, design_code, name, serial IDs)

        // Delivery status change handler
        $('body').on('click', '.delivery-status-option', async function (e) {
            e.preventDefault();
            const newStatus = $(this).data('status');
            const type = $(this).data('type');
            const id = $(this).data('id');
            
            const result = await Api.call('update_delivery_status', {
                type: type,
                id: id,
                delivery_status: newStatus
            });
            
            if (result?.success) {
                UI.showSuccess(result.message);
                load(`${type}_invoices`);
                // Offer ready-for-delivery SMS — never auto-send; user must confirm in modal
                if (type === 'sales' && newStatus === 'ready') {
                    UI.confirmAction('وضعیت به «آماده تحویل» تغییر کرد. آیا می‌خواهید پیامک آماده تحویل برای مشتری ارسال شود؟', async (wantSms) => {
                        if (!wantSms) return;
                        await UI.openSmsConfirmModal({
                            invoice_id: id,
                            invoice_type: 'sales',
                            template_key: 'ready',
                            include_invoice_link: true,
                        });
                    });
                }
            } else if (result?.error) {
                UI.showError(result.error);
            }
        });

        // Delivery status filter change handler
        $('body').on('change', '.delivery-status-filter', function () {
            const type = $(this).data('type');
            load(`${type}_invoices`);
        });
        $('#purchase-warehouse-type-filter').on('change', function () {
            const state = AppConfig.TABLE_STATES.purchase_invoices;
            state.currentPage = 1;
            load('purchase_invoices');
        });

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

        // Partial consignment modal events
        let currentConsignmentInvoice = null;

        $('#partialConsignmentModal').on('shown.bs.modal', function () {
            // Store invoice data for summary calculations
            const invoiceId = $('#pc-invoice-id').text();
            const type = $('#pc-invoice-type').val();
            Api.call('get_consignment_invoice_items', { type, id: parseInt(invoiceId) }).then(result => {
                if (result?.success) {
                    currentConsignmentInvoice = result.invoice;
                }
            });
        });

        $('#partialConsignmentModal').on('hidden.bs.modal', function () {
            currentConsignmentInvoice = null;
            $('#pc-items-body').empty();
            $('#pc-select-all').prop('checked', false);
        });

        // Select all checkbox
        $('#pc-select-all').on('change', function () {
            const isChecked = $(this).is(':checked');
            $('#pc-items-body .pc-item-check').prop('checked', isChecked);
            updatePartialConsignmentSummary(currentConsignmentInvoice);
        });

        // Individual item checkbox
        $('body').on('change', '.pc-item-check', function () {
            const allChecks = $('#pc-items-body .pc-item-check');
            const checkedChecks = $('#pc-items-body .pc-item-check:checked');
            $('#pc-select-all').prop('checked', allChecks.length === checkedChecks.length);
            updatePartialConsignmentSummary(currentConsignmentInvoice);
        });

        // Confirm button
        $('#pc-confirm-btn').on('click', function () {
            executePartialConsignmentConversion();
        });

        $('#sold-consignment-review-btn').on('click', function () {
            openSoldConsignmentReview();
        });
        $('#sold-consignment-convert-btn').on('click', function () {
            executeSoldConsignmentConversion();
        });

        $('#add-customer-from-invoice-btn').on('click', function () {
            $('#customerModal').data('source', 'sales-invoice');
            App.getManager('customers').prepareModal(null);
        });

        $('#add-supplier-from-invoice-btn').on('click', function () {
            $('#supplierModal').data('source', 'purchase-invoice');
            App.getManager('suppliers').prepareModal(null);
        });

        // Toggle for showing invoice details
        $('#sales-invoices-show-details').on('change', function () {
            load('sales_invoices');
        });
        $('#purchase-invoices-show-details').on('change', function () {
            load('purchase_invoices');
        });
        $('#consignment-invoices-show-details').on('change', function () {
            load('consignment_purchases');
            load('consignment_sales');
        });
    }

    return {
        init: attachEvents,
        load,
        prepareInvoiceModal,
        updatePersonDropdown,
    };
})();
