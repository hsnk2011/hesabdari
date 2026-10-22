// /js/quotation-manager.js

const QuotationManager = (function () {

    // --- Private Helper Functions for Rendering ---
    function _renderQuotationRow(q) {
        const statusClasses = {
            'pending': 'bg-warning',
            'converted': 'bg-success',
            'cancelled': 'bg-secondary',
            'expired': 'bg-danger'
        };
        const statusLabels = {
            'pending': 'در انتظار',
            'converted': 'تبدیل شده',
            'cancelled': 'لغو شده',
            'expired': 'منقضی شده'
        };

        const statusClass = statusClasses[q.status] || 'bg-secondary';
        const statusLabel = statusLabels[q.status] || q.status;
        const dateStr = UI.gregorianToPersian(q.date);
        const validUntilStr = q.valid_until ? UI.gregorianToPersian(q.valid_until) : '-';
        const netAmount = parseFloat(q.totalAmount) - parseFloat(q.discount || 0);
        
        // Check if expired (for pending quotations)
        const isExpired = q.status === 'pending' && q.valid_until && new Date(q.valid_until) < new Date();
        if (isExpired && q.status === 'pending') {
            // Mark as visually expired but still pending in DB
        }

        // Disable action buttons based on status
        const isConverted = q.status === 'converted';
        const isCancelled = q.status === 'cancelled' || q.status === 'expired';
        const canEdit = !isConverted;
        const canConvert = q.status === 'pending';
        const canCancel = q.status === 'pending';
        const canDelete = true; // Allow deleting all quotations, even converted ones

        const row = $(`<tr>
            <td>${q.id}</td>
            <td>${dateStr}</td>
            <td>${validUntilStr}</td>
            <td>${q.customerName || ''}</td>
            <td>${UI.formatCurrency(q.totalAmount)}</td>
            <td class="text-info">${UI.formatCurrency(q.discount || 0)}</td>
            <td class="fw-bold">${UI.formatCurrency(netAmount)}</td>
            <td><span class="badge ${statusClass}">${statusLabel}</span></td>
            <td>
                <div class="btn-group">
                    <button class="btn btn-sm btn-info btn-view" title="مشاهده"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-sm btn-success btn-convert" title="تبدیل به فاکتور" ${!canConvert ? 'disabled' : ''} data-permission-action="manage_quotations"><i class="bi bi-arrow-right-circle"></i></button>
                    <button class="btn btn-sm btn-secondary btn-print" title="چاپ"><i class="bi bi-printer"></i></button>
                    <button class="btn btn-sm btn-warning btn-edit" title="ویرایش" ${!canEdit ? 'disabled' : ''} data-permission-action="manage_quotations"><i class="bi bi-pencil-square"></i></button>
                    ${canCancel ? `<button class="btn btn-sm btn-outline-danger btn-cancel" title="لغو" data-permission-action="manage_quotations"><i class="bi bi-x-circle"></i></button>` : ''}
                    <button class="btn btn-sm btn-danger btn-delete" title="${isConverted ? 'حذف پیش فاکتور (فاکتور حفظ می‌شود)' : 'حذف'}" data-permission-action="manage_quotations"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>`);
        
        row.find('.btn-view, .btn-edit, .btn-print, .btn-convert').data('entity', q);
        row.find('.btn-delete, .btn-cancel').data('id', q.id);
        return row;
    }

    const renderers = {
        quotations: (data) => {
            const body = $('#quotations-table-body').empty();
            if (!data || !data.length) {
                return body.html('<tr><td colspan="9" class="text-center">پیش فاکتوری یافت نشد.</td></tr>');
            }
            data.forEach(q => body.append(_renderQuotationRow(q)));
        }
    };

    function calculateQuotationItemTotal(row) {
        const quantity = parseInt(row.find('.quantity').val()) || 0;
        const unitPrice = parseInt((row.find('.unit-price').val() || '').replace(/,/g, '')) || 0;
        row.find('.item-total').val(UI.formatCurrency(quantity * unitPrice));
    }

    function calculateQuotationTotals() {
        const itemsContainer = $('#quotation-items-container');
        let total = 0, totalQuantity = 0, totalArea = 0;

        itemsContainer.find('.dynamic-row').each(function () {
            const unitPrice = Number(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0;
            const quantity = Number($(this).find('.quantity').val()) || 0;
            total += UI.roundMoney(unitPrice * quantity);
            totalQuantity += quantity;
            
            let area = 0;
            let dims = $(this).find('.dimensions-select').val();
            if (dims === 'custom') {
                dims = ($(this).find('.custom-dimension-input').val() || '').trim();
            }

            if (dims) {
                area = UI.dimensionArea(dims.toLowerCase().replace(/\*/g, 'x'));
            }
            totalArea += area * quantity;
        });

        const discount = Number(($('#quotation-discount').val() || '').replace(/,/g, '')) || 0;
        
        // Validate discount
        if (discount > total) {
            $('#quotation-discount').addClass('is-invalid');
            UI.showError('تخفیف نمی‌تواند بیشتر از جمع کل باشد.');
        } else {
            $('#quotation-discount').removeClass('is-invalid');
        }

        const netTotal = total - discount;
        $('#quotation-total-quantity').text(totalQuantity.toLocaleString('fa-IR'));
        // toFixed() returns a string, and String.toLocaleString(locale) ignores the
        // locale arg - convert back to Number so fa-IR grouping actually applies.
        $('#quotation-total-area').text(`${Number(totalArea.toFixed(2)).toLocaleString('fa-IR')} متر مربع`);
        $('#quotation-total').text(UI.formatCurrency(total));
        $('#quotation-net-total').text(UI.formatCurrency(netTotal));
    }

    function populateDimensions(productSelect, preselectedDimension = null) {
        const productId = productSelect.val();
        const row = productSelect.closest('.dynamic-row');
        const dimensionsSelect = row.find('.dimensions-select');
        const dimensionsContainer = row.find('.dimensions-container');
        const standardDimensions = (App.getCache().publicSettings.standard_dimensions || '').split(',').map(d => d.trim()).filter(Boolean);

        dimensionsSelect.empty().prop('disabled', true);
        dimensionsContainer.find('.custom-dimension-wrapper').remove();

        if (!productId) {
            dimensionsSelect.append('<option>ابتدا طرح را انتخاب کنید</option>');
            return;
        }

        dimensionsSelect.prop('disabled', false).append('<option value="">انتخاب کنید...</option>');

        const product = App.getCache().products.find(p => p.id == productId);
        const availableDims = new Set();
        const stockMap = {};

        // Collect available dimensions from stock
        if (product && product.stock) {
            product.stock.forEach(s => {
                availableDims.add(s.dimensions);
                stockMap[s.dimensions] = Number(s.available_quantity ?? s.total_quantity) || 0;
            });
        }

        // Add standard dimensions
        standardDimensions.forEach(dim => availableDims.add(dim));

        // Add preselected dimension if exists
        if (preselectedDimension) availableDims.add(preselectedDimension);

        // Sort and populate
        const sortedDims = Array.from(availableDims).sort();
        sortedDims.forEach(dim => {
            const stock = stockMap[dim] || 0;
            const stockText = stock > 0 ? `(موجودی: ${stock})` : '';
            dimensionsSelect.append(`<option value="${dim}">${dim} ${stockText}</option>`);
        });

        // Allow custom dimensions
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

    function addQuotationItemRow(item = null) {
        const itemsContainer = $('#quotation-items-container');
        const appCache = App.getCache();
        const productList = appCache.products || [];

        let productOptions = productList.map(p => `<option value="${p.id}">${p.name}</option>`).join('');

        const row = $(`<div class="row dynamic-row align-items-end mb-2">
            <input type="hidden" class="item-id" value="${item ? item.id : ''}">
            <div class="col-md-3 product-selection-container">
                <label class="form-label">طرح فرش</label>
                <select class="form-select product-select"><option></option>${productOptions}</select>
            </div>
            <div class="col-md-2 dimensions-container">
                <label class="form-label">ابعاد</label>
                <select class="form-select dimensions-select" disabled><option value="">ابتدا طرح را انتخاب کنید</option></select>
            </div>
            <div class="col-md-2">
                <label class="form-label">تعداد</label>
                <input type="number" class="form-control quantity" value="1" min="1">
            </div>
            <div class="col-md-2">
                <label class="form-label">قیمت واحد</label>
                <input type="text" class="form-control unit-price numeric-input" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">جمع</label>
                <input type="text" class="form-control item-total" readonly>
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger btn-sm remove-item-btn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>`);

        itemsContainer.append(row);

        row.find('.product-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            width: '100%',
            placeholder: "جستجو و انتخاب محصول...",
            dropdownParent: $('#quotationModal')
        });

        if (item) {
            row.find('.product-select').val(item.productId).trigger('change');
            populateDimensions(row.find('.product-select'), item.dimensions);
            row.find('.quantity').val(item.quantity);
            row.find('.unit-price').val(Number(item.unitPrice).toLocaleString('en-US'));
            calculateQuotationItemTotal(row);
        }
    }

    function prepareQuotationModal(quotation = null) {
        const modalId = '#quotationModal';
        const formId = '#quotation-form';

        // Reset modal
        UI.hideModalError(modalId);
        $(`${formId} .select2-hidden-accessible`).select2('destroy');
        $(formId)[0].reset();
        $('#quotation-items-container').empty();

        // Title
        $('#quotation-modal-title').text(quotation ? `ویرایش پیش فاکتور #${quotation.id}` : 'ایجاد پیش فاکتور جدید');

        // Populate customer dropdown
        const customerSelect = $('#quotation-customer');
        customerSelect.empty().append('<option></option>');
        (App.getCache().customers || []).forEach(c => customerSelect.append(`<option value="${c.id}">${c.name}</option>`));
        customerSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: 'انتخاب مشتری...',
            dropdownParent: $(modalId)
        });

        // Populate form if editing
        if (quotation) {
            $('#quotation-id').val(quotation.id);
            $('#quotation-date').val(UI.gregorianToPersian(quotation.date));
            $('#quotation-valid-until').val(quotation.valid_until ? UI.gregorianToPersian(quotation.valid_until) : '');
            $('#quotation-description').val(quotation.description || '');
            $('#quotation-discount').val(Number(quotation.discount || 0).toLocaleString('en-US'));
            customerSelect.val(quotation.customerId).trigger('change');

            // Add items
            if (quotation.items && quotation.items.length > 0) {
                quotation.items.forEach(item => addQuotationItemRow(item));
            }
        } else {
            $('#quotation-id').val('');
            $('#quotation-date').val(UI.today());
            $('#quotation-discount').val('0');
            // Add one empty row for new quotation
            addQuotationItemRow();
        }

        calculateQuotationTotals();
        $(modalId).modal('show');
    }

    async function load(filters = null) {
        UI.showLoader();

        const state = AppConfig.TABLE_STATES['quotations'];
        state.tableName = 'quotations';
        let payload = { ...state };

        // Apply status filter
        const statusFilter = $('#quotation-status-filter').val();
        if (statusFilter) {
            payload.statusFilter = statusFilter;
        }

        if (filters) {
            payload = { ...payload, ...filters };
        }

        const response = await Api.call('get_paginated_data', payload);

        if (response && renderers.quotations) {
            renderers.quotations(response.data);
            UI.renderPagination('quotations', response.totalRecords, state);
            UI.updateSortIndicators('quotations', state);
            App.applyPermissions();
        }
        UI.hideLoader();
    }

    async function saveQuotation() {
        const modalId = '#quotationModal';
        UI.hideModalError(modalId);
        
        const form = $('#quotation-form')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        // Collect items
        const items = [];
        $('#quotation-items-container .dynamic-row').each(function () {
            const productId = $(this).find('.product-select').val();
            if (!productId) return;

            let dims = $(this).find('.dimensions-select').val();
            if (dims === 'custom') {
                dims = ($(this).find('.custom-dimension-input').val() || '').trim();
            }

            const item = {
                productId: parseInt(productId),
                dimensions: dims,
                quantity: parseInt($(this).find('.quantity').val()) || 0,
                unitPrice: parseInt(($(this).find('.unit-price').val() || '').replace(/,/g, '')) || 0
            };

            const itemId = $(this).find('.item-id').val();
            if (itemId) item.id = parseInt(itemId);

            if (item.dimensions && item.quantity > 0) {
                items.push(item);
            }
        });

        if (items.length === 0) {
            UI.showModalError(modalId, 'حداقل یک آیتم باید اضافه شود.');
            return;
        }

        // Validate discount
        const totalAmount = items.reduce((sum, item) => sum + (item.quantity * item.unitPrice), 0);
        const discount = parseInt(($('#quotation-discount').val() || '').replace(/,/g, '')) || 0;
        if (discount > totalAmount) {
            UI.showModalError(modalId, 'تخفیف نمی‌تواند بیشتر از جمع کل باشد.');
            return;
        }

        const data = {
            id: parseInt($('#quotation-id').val()) || null,
            customerId: parseInt($('#quotation-customer').val()),
            date: UI.toGregorian($('#quotation-date')),
            valid_until: $('#quotation-valid-until').val() ? UI.toGregorian($('#quotation-valid-until')) : null,
            description: $('#quotation-description').val() || '',
            discount: discount,
            items: items
        };

        // Disable save button and show loading.
        // The real button is #save-quotation-btn (.btn-success, type="button") in
        // templates/section_quotations.php - not a .btn-primary[type="submit"].
        const saveBtn = $('#save-quotation-btn');
        const originalText = saveBtn.html();
        saveBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> در حال ذخیره...');
        
        UI.showLoader();
        const response = await Api.call('save_quotation', data);
        UI.hideLoader();
        
        // Re-enable save button
        saveBtn.prop('disabled', false).html(originalText);

        if (response.error) {
            UI.showModalError(modalId, response.error);
        } else {
            $(modalId).modal('hide');
            UI.showSuccess(data.id ? 'پیش فاکتور با موفقیت بروزرسانی شد.' : 'پیش فاکتور با موفقیت ثبت شد.');
            load();
        }
    }

    async function deleteQuotation(id) {
        const confirmed = await UI.confirm('آیا از حذف این پیش فاکتور اطمینان دارید؟');
        if (!confirmed) return;

        // Show loading on delete button
        const deleteBtn = $(`#quotations-table-body .btn-delete`).filter(function() {
            return $(this).data('id') == id;
        });
        deleteBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        
        UI.showLoader();
        const response = await Api.call('delete_quotation', { id: id });
        UI.hideLoader();

        if (response.error) {
            UI.showError(response.error);
        } else {
            UI.showSuccess('پیش فاکتور با موفقیت حذف شد.');
            load();
        }
    }

    async function cancelQuotation(id) {
        const confirmed = await UI.confirm('آیا از لغو این پیش فاکتور اطمینان دارید؟');
        if (!confirmed) return;

        UI.showLoader();
        const response = await Api.call('update_quotation_status', { id: id, status: 'cancelled' });
        UI.hideLoader();

        if (response.error) {
            UI.showError(response.error);
        } else {
            UI.showSuccess('پیش فاکتور لغو شد.');
            load();
        }
    }

    async function convertToInvoice(quotation) {
        const confirmed = await UI.confirm(
            `آیا مطمئنید می‌خواهید پیش فاکتور شماره ${quotation.id} را به فاکتور فروش تبدیل کنید؟\n\nتوجه: پس از تبدیل، این پیش فاکتور قابل ویرایش یا حذف نخواهد بود.`,
            'تبدیل به فاکتور'
        );
        if (!confirmed) return;

        // Show loading on convert button
        const convertBtn = $(`#quotations-table-body .btn-convert`).filter(function() {
            return $(this).data('entity')?.id == quotation.id;
        });
        convertBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        
        UI.showLoader();
        const response = await Api.call('convert_quotation_to_invoice', { id: quotation.id });
        UI.hideLoader();

        if (!response) return; // Api already showed the error
        if (response.error) {
            UI.showError(response.error);
        } else {
            UI.showSuccess(response.message || 'پیش فاکتور با موفقیت به فاکتور تبدیل شد.');
            if (Array.isArray(response.warnings) && response.warnings.length) {
                toastr.warning(response.warnings.map(w => UI.escapeHtml(w)).join('<br>'), 'هشدار', { timeOut: 12000 });
            }
            load();
            // Optionally refresh sales invoices list
            if (typeof InvoiceManager !== 'undefined' && InvoiceManager.load) {
                InvoiceManager.load('sales_invoices');
            }
        }
    }

    function viewQuotation(quotation) {
        const modal = $('#quotationViewModal');
        const content = $('#quotation-view-content');
        
        const statusLabels = {
            'pending': 'در انتظار',
            'converted': 'تبدیل شده',
            'cancelled': 'لغو شده',
            'expired': 'منقضی شده'
        };

        let itemsHtml = '';
        let totalQuantity = 0;
        if (quotation.items && quotation.items.length > 0) {
            quotation.items.forEach((item, idx) => {
                totalQuantity += parseInt(item.quantity) || 0;
                itemsHtml += `<tr>
                    <td>${idx + 1}</td>
                    <td>${item.productName || '-'}</td>
                    <td>${item.dimensions}</td>
                    <td>${item.quantity}</td>
                    <td>${UI.formatCurrency(item.unitPrice)}</td>
                    <td>${UI.formatCurrency(item.quantity * item.unitPrice)}</td>
                </tr>`;
            });
        }

        const netAmount = parseFloat(quotation.totalAmount) - parseFloat(quotation.discount || 0);
        
        content.html(`
            <div class="row mb-3">
                <div class="col-md-4">
                    <strong>شماره پیش فاکتور:</strong> ${quotation.id}
                </div>
                <div class="col-md-4">
                    <strong>تاریخ:</strong> ${UI.gregorianToPersian(quotation.date)}
                </div>
                <div class="col-md-4">
                    <strong>معتبر تا:</strong> ${quotation.valid_until ? UI.gregorianToPersian(quotation.valid_until) : '-'}
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>مشتری:</strong> ${quotation.customerName || '-'}
                </div>
                <div class="col-md-6">
                    <strong>وضعیت:</strong> <span class="badge ${quotation.status === 'converted' ? 'bg-success' : quotation.status === 'pending' ? 'bg-warning' : 'bg-secondary'}">${statusLabels[quotation.status] || quotation.status}</span>
                </div>
            </div>
            ${quotation.description ? `<div class="mb-3"><strong>توضیحات:</strong> ${quotation.description}</div>` : ''}
            ${quotation.converted_invoice_id ? `<div class="mb-3 alert alert-info"><strong>فاکتور مرتبط:</strong> فاکتور فروش شماره ${quotation.converted_invoice_id}</div>` : ''}
            <hr>
            <h6>اقلام پیش فاکتور</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>محصول</th>
                            <th>ابعاد</th>
                            <th>تعداد</th>
                            <th>قیمت واحد</th>
                            <th>جمع</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>
            <div class="row justify-content-end">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><td>جمع تعداد:</td><td class="text-start">${totalQuantity}</td></tr>
                        <tr><td>جمع کل:</td><td class="text-start">${UI.formatCurrency(quotation.totalAmount)}</td></tr>
                        <tr><td>تخفیف:</td><td class="text-start text-info">${UI.formatCurrency(quotation.discount || 0)}</td></tr>
                        <tr class="fw-bold"><td>مبلغ نهایی:</td><td class="text-start">${UI.formatCurrency(netAmount)}</td></tr>
                    </table>
                </div>
            </div>
        `);

        modal.data('quotation', quotation);
        modal.modal('show');
    }

    function printQuotation(quotation) {
        const printSettings = UI.getPrintSettings();
        
        // Build items with XSS protection
        let itemsHtml = '';
        let totalQuantity = 0;
        if (quotation.items && quotation.items.length > 0) {
            quotation.items.forEach((item, idx) => {
                totalQuantity += parseInt(item.quantity) || 0;
                itemsHtml += `<tr>
                    <td>${idx + 1}</td>
                    <td>${UI.escapeHtml(item.productName || '-')}</td>
                    <td>${UI.escapeHtml(item.dimensions || '-')}</td>
                    <td>${item.quantity}</td>
                    <td>${UI.formatCurrency(item.unitPrice)}</td>
                    <td>${UI.formatCurrency(item.quantity * item.unitPrice)}</td>
                </tr>`;
            });
        }

        const netAmount = parseFloat(quotation.totalAmount) - parseFloat(quotation.discount || 0);

        // Logo section - URL is validated server-side
        const logoHtml = printSettings.show_logo && printSettings.logo_url 
            ? `<img src="${UI.escapeHtml(printSettings.logo_url)}" alt="لوگو" style="max-height: 60px; max-width: 150px; margin-bottom: 10px;">` 
            : '';

        // Payment info section for quotation (bank details for reference) - escape all values
        const paymentInfoHtml = printSettings.show_payment_info && (printSettings.bank_name || printSettings.card_number) ? `
            <div class="payment-info">
                <h4>اطلاعات پرداخت (جهت واریز)</h4>
                <table class="info-table">
                    ${printSettings.bank_name ? `<tr><td>نام بانک:</td><td>${UI.escapeHtml(printSettings.bank_name)}</td></tr>` : ''}
                    ${printSettings.account_number ? `<tr><td>شماره حساب:</td><td>${UI.escapeHtml(printSettings.account_number)}</td></tr>` : ''}
                    ${printSettings.card_number ? `<tr><td>شماره کارت:</td><td>${UI.escapeHtml(printSettings.card_number)}</td></tr>` : ''}
                    ${printSettings.account_holder ? `<tr><td>به نام:</td><td>${UI.escapeHtml(printSettings.account_holder)}</td></tr>` : ''}
                </table>
            </div>
        ` : '';

        // Signature section
        const signatureHtml = printSettings.show_signature_line ? `
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">امضا و مهر فروشنده</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">امضا خریدار</div>
                </div>
            </div>
        ` : '';

        const printContent = `
            <!DOCTYPE html>
            <html dir="rtl" lang="fa">
            <head>
                <meta charset="UTF-8">
                <title>پیش فاکتور شماره ${quotation.id}</title>
                <style>
                    * { box-sizing: border-box; }
                    body { font-family: Tahoma, Arial; font-size: 12px; margin: 20px; background: #fff; color: #333; }
                    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                    .header-right { text-align: right; }
                    .header-left { text-align: left; }
                    .header h1 { margin: 0 0 5px 0; font-size: 18px; }
                    .header .subtitle { color: #666; font-size: 14px; }
                    .header-text { font-style: italic; color: #666; margin-top: 5px; font-size: 11px; }
                    .company-info { margin-bottom: 15px; }
                    .company-info span { display: block; margin: 3px 0; }
                    .info-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 15px; padding: 10px; background: #f8f8f8; border-radius: 5px; }
                    .info-row .item strong { color: #555; }
                    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                    th { background-color: #f0f0f0; font-weight: bold; }
                    tbody tr:nth-child(even) { background-color: #f9f9f9; }
                    .totals { margin-top: 15px; }
                    .totals-table { width: auto; min-width: 200px; float: left; }
                    .totals-table td { border: none; padding: 5px 15px; }
                    .totals-table tr:last-child { font-weight: bold; font-size: 14px; background: #e8f4f8; }
                    .validity { background: #fff3cd; padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #ffc107; }
                    .payment-info { margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px; page-break-inside: avoid; }
                    .payment-info h4 { margin: 0 0 10px 0; font-size: 13px; }
                    .info-table { width: auto; }
                    .info-table td { border: none; padding: 4px 10px; }
                    .signature-section { display: flex; justify-content: space-between; margin-top: 40px; page-break-inside: avoid; }
                    .signature-box { text-align: center; width: 40%; }
                    .signature-line { border-bottom: 1px solid #999; height: 60px; margin-bottom: 5px; }
                    .signature-label { color: #666; font-size: 11px; }
                    .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; color: #666; font-size: 11px; }
                    .terms { margin-top: 15px; padding: 10px; background: #f9f9f9; border: 1px dashed #ddd; border-radius: 5px; font-size: 10px; }
                    .terms h4 { margin: 0 0 5px 0; font-size: 11px; }
                    .quotation-note { background: #e3f2fd; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 11px; border: 1px solid #90caf9; }
                    .clearfix { clear: both; }
                    @media print { body { margin: 10px; } .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="header-right">
                        ${logoHtml}
                        <h1>${UI.escapeHtml(printSettings.company_name) || 'پیش فاکتور'}</h1>
                        <div class="subtitle">پیش فاکتور</div>
                        ${printSettings.header_text ? `<div class="header-text">${UI.escapeHtml(printSettings.header_text)}</div>` : ''}
                    </div>
                    <div class="header-left">
                        <div><strong>شماره:</strong> ${quotation.id}</div>
                        <div><strong>تاریخ:</strong> ${UI.gregorianToPersian(quotation.date)}</div>
                    </div>
                </div>
                
                ${printSettings.show_company_info && (printSettings.company_phone || printSettings.company_address) ? `
                    <div class="company-info">
                        ${printSettings.company_phone ? `<span><strong>تلفن:</strong> ${UI.escapeHtml(printSettings.company_phone)}</span>` : ''}
                        ${printSettings.company_address ? `<span><strong>آدرس:</strong> ${UI.escapeHtml(printSettings.company_address)}</span>` : ''}
                    </div>
                ` : ''}
                
                <div class="info-row">
                    <div class="item"><strong>مشتری:</strong> ${UI.escapeHtml(quotation.customerName || '-')}</div>
                    <div class="item"><strong>تلفن:</strong> ${UI.escapeHtml(quotation.customerPhone || '-')}</div>
                    <div class="item"><strong>آدرس:</strong> ${UI.escapeHtml(quotation.customerAddress || '-')}</div>
                </div>
                
                <div class="quotation-note">
                    <strong>توجه:</strong> این پیش فاکتور صرفاً جهت اطلاع از قیمت‌ها صادر شده و تعهدی برای فروشنده ایجاد نمی‌کند.
                </div>
                
                ${quotation.valid_until ? `<div class="validity"><strong>✓ معتبر تا تاریخ:</strong> ${UI.gregorianToPersian(quotation.valid_until)}</div>` : ''}
                ${quotation.description ? `<div style="margin-bottom:15px;"><strong>توضیحات:</strong> ${UI.escapeHtml(quotation.description)}</div>` : ''}
                
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>محصول</th>
                            <th style="width:80px">ابعاد</th>
                            <th style="width:60px">تعداد</th>
                            <th style="width:100px">قیمت واحد</th>
                            <th style="width:100px">جمع</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                
                <div class="totals">
                    <table class="totals-table">
                        <tr><td>تعداد کل:</td><td>${totalQuantity}</td></tr>
                        <tr><td>جمع کل:</td><td>${UI.formatCurrency(quotation.totalAmount)}</td></tr>
                        <tr><td>تخفیف:</td><td>${UI.formatCurrency(quotation.discount || 0)}</td></tr>
                        <tr><td>مبلغ قابل پرداخت:</td><td>${UI.formatCurrency(netAmount)}</td></tr>
                    </table>
                </div>
                <div class="clearfix"></div>
                
                ${paymentInfoHtml}
                
                ${printSettings.terms ? `
                    <div class="terms">
                        <h4>شرایط و قوانین</h4>
                        <p>${UI.escapeHtml(printSettings.terms).replace(/\n/g, '<br>')}</p>
                    </div>
                ` : ''}
                
                ${signatureHtml}
                
                ${printSettings.footer_text ? `<div class="footer">${UI.escapeHtml(printSettings.footer_text)}</div>` : ''}
            </body>
            </html>
        `;

        const printWindow = window.open('', '_blank', 'width=800,height=600');
        if (!printWindow) {
            UI.showError('لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return;
        }
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => { printWindow.print(); }, 300);
    }

    // --- Event Binding ---
    function init() {
        // Initialize table state
        if (!AppConfig.TABLE_STATES['quotations']) {
            AppConfig.TABLE_STATES['quotations'] = {
                currentPage: 1,
                limit: 10,
                sortBy: 'id',
                sortOrder: 'DESC',
                searchTerm: ''
            };
        }

        // Add quotation button
        $('#add-quotation-btn').on('click', () => prepareQuotationModal());

        // Add item row button
        $('#add-quotation-item-btn').on('click', () => {
            addQuotationItemRow();
            calculateQuotationTotals();
        });

        // Save quotation button
        $('#save-quotation-btn').on('click', saveQuotation);

        // Status filter change
        $('#quotation-status-filter').on('change', () => load());

        // Table events (delegation)
        $('#quotations-table-body').on('click', '.btn-view', function () {
            const quotation = $(this).data('entity');
            viewQuotation(quotation);
        });

        $('#quotations-table-body').on('click', '.btn-edit', function () {
            const quotation = $(this).data('entity');
            if (quotation.status !== 'converted') {
                prepareQuotationModal(quotation);
            }
        });

        $('#quotations-table-body').on('click', '.btn-delete', function () {
            const id = $(this).data('id');
            deleteQuotation(id);
        });

        $('#quotations-table-body').on('click', '.btn-cancel', function () {
            const id = $(this).data('id');
            cancelQuotation(id);
        });

        $('#quotations-table-body').on('click', '.btn-convert', function () {
            const quotation = $(this).data('entity');
            if (quotation.status === 'pending') {
                convertToInvoice(quotation);
            }
        });

        $('#quotations-table-body').on('click', '.btn-print', function () {
            const quotation = $(this).data('entity');
            printQuotation(quotation);
        });

        // Print from view modal
        $('#print-quotation-btn').on('click', function () {
            const quotation = $('#quotationViewModal').data('quotation');
            if (quotation) printQuotation(quotation);
        });

        // Add customer from quotation modal
        $('#add-customer-from-quotation-btn').on('click', () => {
            $('#quotationModal').modal('hide');
            if (typeof App !== 'undefined' && App.openAddPersonModal) {
                App.openAddPersonModal('customer', 'quotation');
            }
        });

        // Items container events
        $('#quotation-items-container').on('change', '.product-select', function () {
            const row = $(this).closest('.dynamic-row');
            populateDimensions($(this));

            // Try to auto-fill price from product
            const productId = $(this).val();
            if (productId) {
                const product = App.getCache().products.find(p => p.id == productId);
                if (product && product.price) {
                    row.find('.unit-price').val(Number(product.price).toLocaleString('en-US'));
                }
            }
            calculateQuotationTotals();
        });

        $('#quotation-items-container').on('change', '.dimensions-select', function () {
            const row = $(this).closest('.dynamic-row');
            const dimensionsContainer = row.find('.dimensions-container');
            
            if ($(this).val() === 'custom') {
                if (!dimensionsContainer.find('.custom-dimension-wrapper').length) {
                    dimensionsContainer.append('<div class="custom-dimension-wrapper mt-1"><input type="text" class="form-control form-control-sm custom-dimension-input" placeholder="مثال: 2x3"></div>');
                }
            } else {
                dimensionsContainer.find('.custom-dimension-wrapper').remove();
            }

            // Try to get price from collection
            const productId = row.find('.product-select').val();
            const dims = $(this).val();
            if (productId && dims && dims !== 'custom') {
                const product = App.getCache().products.find(p => p.id == productId);
                if (product && product.collection_id) {
                    const collection = App.getCache().collections?.find(c => c.id == product.collection_id);
                    if (collection) {
                        const dimPrice = collection.dimensions?.find(d => d.dimension === dims);
                        if (dimPrice && dimPrice.price_override) {
                            row.find('.unit-price').val(Number(dimPrice.price_override).toLocaleString('en-US'));
                        } else if (collection.price_per_meter) {
                            // Calculate from area
                            const parts = dims.toLowerCase().replace('*', 'x').split('x');
                            if (parts.length === 2) {
                                let w = parseFloat(parts[0]), h = parseFloat(parts[1]);
                                if (!isNaN(w) && !isNaN(h)) {
                                    if (w > 15 || h > 15) { w /= 100; h /= 100; }
                                    const area = w * h;
                                    const price = Math.round(area * parseFloat(collection.price_per_meter));
                                    row.find('.unit-price').val(price.toLocaleString('en-US'));
                                }
                            }
                        }
                    }
                }
            }

            calculateQuotationTotals();
        });

        $('#quotation-items-container').on('input change', '.quantity, .unit-price, .custom-dimension-input', function () {
            const row = $(this).closest('.dynamic-row');
            calculateQuotationItemTotal(row);
            calculateQuotationTotals();
        });

        $('#quotation-items-container').on('click', '.remove-item-btn', function () {
            $(this).closest('.dynamic-row').remove();
            calculateQuotationTotals();
        });

        // Discount change
        $('#quotation-discount').on('input change', calculateQuotationTotals);
    }

    return {
        init: init,
        load: load,
        renderers: renderers,
        prepareModal: prepareQuotationModal
    };

})();

// Note: init() is called from app.js via registerManager
