// /js/check-manager.js

const CheckManager = (function () {
    const config = {
        tableName: 'checks',
        apiName: 'check',
        nameFa: 'چک',
        modalId: '#transactionModal', // Use the unified transaction modal
        discountModalId: '#checkDiscountModal'
    };
    const state = AppConfig.TABLE_STATES[config.tableName];
    let selectedChecks = []; // Store selected checks for discount/transfer
    const persistentSelectedIds = new Set(); // FIX: Persist selected check IDs across searches
    // Row data captured at selection time, so a check selected on another page/search
    // stays usable for ras-giri even when it is outside the (limited) checks cache.
    const selectedCheckData = new Map();

    function renderTable(data) {
        const getCheckStatusText = (s, t) => {
            if (!s) return '';
            const labels = (typeof AppConfig !== 'undefined' && AppConfig.CHECK_STATUS_LABELS) || {};
            const receivedStatus = labels.received || { in_hand: 'نزد ما', endorsed: 'واگذار شده', cashed: 'وصول شده', bounced: 'برگشتی' };
            const payableStatus = labels.payable || { payable: 'پرداختنی', cashed: 'پاس شده', bounced: 'برگشتی' };
            return t === 'received' ? (receivedStatus[s] || s) : (payableStatus[s] || s);
        };

        const body = $('#checks-table-body').empty();
        // FIX: Don't uncheck select-all yet; update it after rendering if needed
        const previouslyCheckedAll = $('#select-all-checks').prop('checked');
        
        if (!data || !data.length) {
            body.html('<tr><td colspan="10" class="text-center">چکی یافت نشد.</td></tr>');
            updateDiscountButtonState();
            return;
        }

        data.forEach(c => {
            const typeText = c.type === 'received' ? 'دریافتی' : 'پرداختی';
            const typeClass = c.type === 'received' ? 'text-success' : 'text-danger';
            const personName = String(c.relatedPersonName || '').trim();
            let related;
            if (c.invoiceId) {
                const invLabel = `فاکتور ${c.invoiceType === 'sales' ? 'فروش' : 'خرید'} #${c.invoiceId}`;
                related = personName ? `${personName} — ${invLabel}` : invLabel;
            } else {
                related = personName || 'علی‌الحساب';
            }

            // Selection allowed for all checks (print/history); ras-giri still validates type/status
            const isChecked = persistentSelectedIds.has(c.id);
            if (isChecked) rememberCheckData(c);
            const checkboxHtml = `<input type="checkbox" class="form-check-input check-select" data-check-id="${c.id}" data-amount="${c.amount}" data-check-number="${c.checkNumber}" data-bank="${c.bankName || ''}" data-issuer="${c.issuerName || ''}" data-due-date="${c.dueDate}" data-type="${c.type}" data-status="${c.status}" ${isChecked ? 'checked' : ''}>`;

            let actions = '';
            if (c.type === 'received' && c.status === 'in_hand') {
                actions += `<button class="btn btn-sm btn-success btn-cash-check" data-id="${c.id}" title="وصول چک" data-permission-action="manage_checks"><i class="bi bi-check-circle-fill"></i></button> `;
            } else if (c.type === 'payable' && c.status === 'payable') {
                actions += `<button class="btn btn-sm btn-primary btn-clear-check" data-id="${c.id}" title="ثبت پاس شدن چک" data-permission-action="manage_checks"><i class="bi bi-check-all"></i></button> `;
            }

            // Lifecycle: bounce / return / undo
            const lifecycle = [];
            if ((c.type === 'received' && ['in_hand', 'endorsed'].includes(c.status)) || (c.type === 'payable' && c.status === 'payable')) {
                lifecycle.push(`<li><a class="dropdown-item text-danger btn-bounce-check" href="#" data-id="${c.id}"><i class="bi bi-arrow-return-left me-1"></i>ثبت برگشت چک</a></li>`);
            }
            if (c.type === 'received' && c.status === 'endorsed') {
                lifecycle.push(`<li><a class="dropdown-item btn-return-endorsed-check" href="#" data-id="${c.id}"><i class="bi bi-arrow-counterclockwise me-1"></i>عودت چک خرج‌شده</a></li>`);
            }
            if (c.status === 'cashed') {
                lifecycle.push(`<li><a class="dropdown-item btn-undo-cash-check" href="#" data-id="${c.id}"><i class="bi bi-x-circle me-1"></i>${c.type === 'received' ? 'لغو وصول' : 'لغو پاس'}</a></li>`);
            }
            if (c.status === 'bounced') {
                lifecycle.push(`<li><a class="dropdown-item btn-undo-bounce-check" href="#" data-id="${c.id}"><i class="bi bi-arrow-repeat me-1"></i>لغو برگشت</a></li>`);
            }
            if (lifecycle.length) {
                actions += `<div class="btn-group" data-permission-action="manage_checks"><button type="button" class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="عملیات چک"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu">${lifecycle.join('')}</ul></div> `;
            }

            actions += `<button class="btn btn-sm btn-outline-info btn-check-history" data-id="${c.id}" title="گزارش کامل چک / گروه انتقال" data-permission-action="manage_checks"><i class="bi bi-clock-history"></i></button> `;
            actions += `<button class="btn btn-sm btn-outline-secondary btn-print-check" data-id="${c.id}" title="چاپ چک" data-permission-action="manage_checks"><i class="bi bi-printer"></i></button> `;
            const editTitle = (c.type === 'received' && c.status === 'endorsed')
                ? 'ویرایش تراکنش انتقال (خرج چک)'
                : 'ویرایش تراکنش چک';
            actions += `<button class="btn btn-sm btn-warning btn-edit-payment-from-check" data-check-id="${c.id}" title="${editTitle}" data-permission-action="manage_transactions"><i class="bi bi-pencil-square"></i></button> `;

            if (!c.invoiceId && !['cashed', 'endorsed'].includes(c.status)) {
                actions += `<button class="btn btn-sm btn-danger btn-delete" data-type="check" data-id="${c.id}" title="حذف چک و تراکنش" data-permission-action="manage_checks"><i class="bi bi-trash"></i></button>`;
            }

            const dueDateStr = UI.gregorianToPersian(c.dueDate);
            const issuerDisplay = (c.issuerName && String(c.issuerName).trim()) ? String(c.issuerName).trim() : '-';
            const todayIso = localIsoDate();
            const isPastDueOpen = c.dueDate && c.dueDate < todayIso && ['in_hand', 'payable'].includes(c.status);
            const dueCell = isPastDueOpen
                ? `<span class="text-danger fw-semibold">${dueDateStr}</span> <span class="badge bg-danger-subtle text-danger">سررسید گذشته</span>`
                : dueDateStr;

            // Column order must match #checks-table thead exactly:
            // checkbox | type | number | bank | issuer | amount | dueDate | status | related | actions
            body.append(`<tr>
                <td>${checkboxHtml}</td>
                <td class="${typeClass}">${typeText}</td>
                <td>${UI.escapeHtml(c.checkNumber)}</td>
                <td>${UI.escapeHtml(c.bankName || '-')}</td>
                <td class="check-issuer-cell">${UI.escapeHtml(issuerDisplay)}</td>
                <td>${UI.formatCurrency(c.amount)}</td>
                <td>${dueCell}</td>
                <td>${getCheckStatusText(c.status, c.type)}</td>
                <td>${UI.escapeHtml(related)}</td>
                <td><div class="btn-group">${actions}</div></td>
            </tr>`);
        });
        
        // FIX: Update select-all checkbox based on current visible and selected checkboxes
        const totalCheckboxes = $('.check-select').length;
        const checkedCheckboxes = $('.check-select:checked').length;
        $('#select-all-checks').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
        
        updateDiscountButtonState();
        // FIX: Call the function directly on the App object
        App.applyPermissions();
    }

    function updateDiscountButtonState() {
        const selectedCount = persistentSelectedIds.size;
        const transferable = getSelectedChecksData().filter(c => c.type === 'received' && c.status === 'in_hand');
        $('#discount-checks-btn').prop('disabled', transferable.length === 0);
        $('#print-checks-btn').prop('disabled', selectedCount === 0);
        if (transferable.length > 0) {
            $('#discount-checks-btn').text(`راس‌گیری و انتقال (${transferable.length} چک)`);
        } else {
            $('#discount-checks-btn').html('<i class="bi bi-calculator me-2"></i>راس‌گیری و انتقال چک');
        }
        if (selectedCount > 0) {
            $('#print-checks-btn').html(`<i class="bi bi-printer me-1"></i>چاپ انتخاب‌شده (${selectedCount})`);
        } else {
            $('#print-checks-btn').html('<i class="bi bi-printer me-1"></i>چاپ انتخاب‌شده');
        }
    }

    function rememberCheckData(c) {
        selectedCheckData.set(String(c.id), {
            id: c.id,
            amount: parseFloat(c.amount),
            checkNumber: c.checkNumber,
            bankName: c.bankName || '',
            issuerName: c.issuerName || '',
            dueDate: c.dueDate,
            type: c.type,
            status: c.status
        });
    }

    function rememberCheckboxData($cb) {
        rememberCheckData({
            id: $cb.data('check-id'),
            amount: $cb.data('amount'),
            checkNumber: $cb.data('check-number'),
            bankName: $cb.data('bank'),
            issuerName: $cb.data('issuer'),
            dueDate: $cb.data('due-date'),
            type: $cb.data('type'),
            status: $cb.data('status')
        });
    }

    function selectCheck(id, $cb) {
        persistentSelectedIds.add(id);
        if ($cb && $cb.length) rememberCheckboxData($cb);
    }

    function unselectCheck(id) {
        persistentSelectedIds.delete(id);
        selectedCheckData.delete(String(id));
    }

    function getSelectedChecksData() {
        const checks = [];
        const cachedChecks = App.getCache().checks || [];
        // FIX: Build data for ALL persistently selected IDs (across pages),
        // not just the checkboxes currently visible in the table.
        persistentSelectedIds.forEach(id => {
            // Data captured when the check was selected (or last rendered) is the most reliable
            const remembered = selectedCheckData.get(String(id));
            if (remembered) {
                checks.push(remembered);
                return;
            }
            const cached = cachedChecks.find(c => String(c.id) === String(id));
            if (cached) {
                checks.push({
                    id: cached.id,
                    amount: parseFloat(cached.amount),
                    checkNumber: cached.checkNumber,
                    bankName: cached.bankName || '',
                    issuerName: cached.issuerName || '',
                    dueDate: cached.dueDate,
                    type: cached.type,
                    status: cached.status
                });
                return;
            }
            // Fallback to visible checkbox data attributes
            const $cb = $(`.check-select[data-check-id="${id}"]`);
            if ($cb.length) {
                checks.push({
                    id: $cb.data('check-id'),
                    amount: parseFloat($cb.data('amount')),
                    checkNumber: $cb.data('check-number'),
                    bankName: $cb.data('bank'),
                    issuerName: $cb.data('issuer') || '',
                    dueDate: $cb.data('due-date'),
                    type: $cb.data('type'),
                    status: $cb.data('status')
                });
            }
        });
        return checks;
    }

    function calculateDaysBetween(date1, date2) {
        const d1 = new Date(date1);
        const d2 = new Date(date2);
        const diffTime = d2 - d1;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
    }

    function calculateDiscount(amount, days, monthlyRate) {
        if (days <= 0 || monthlyRate <= 0) return 0;
        // Days per month is a configurable convention (check_discount_days_per_month)
        const daysPerMonth = parseFloat(UI.getAppSetting('check_discount_days_per_month',
            (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS && AppConfig.DEFAULTS.CHECK_DISCOUNT_DAYS_PER_MONTH) || 30)) || 30;
        const dailyRate = monthlyRate / daysPerMonth / 100;
        // Simple interest calculation
        const discount = amount * dailyRate * days;
        return Math.round(discount);
    }

    /**
     * محاسبه تاریخ راس میانگین وزنی
     * Calculate weighted average maturity date (Ras date)
     * Formula: Sum(amount * days_to_maturity) / Sum(amount)
     */
    function calculateWeightedRasDate(checks, baseDate) {
        if (!checks || checks.length === 0) return null;
        
        let totalWeightedDays = 0;
        let totalAmount = 0;
        
        checks.forEach(c => {
            const daysToMaturity = calculateDaysBetween(baseDate, c.dueDate);
            totalWeightedDays += c.amount * Math.max(0, daysToMaturity);
            totalAmount += c.amount;
        });
        
        if (totalAmount === 0) return null;
        
        const avgDays = Math.round(totalWeightedDays / totalAmount);
        
        // Calculate the ras date by adding avgDays to baseDate
        const baseDateObj = new Date(baseDate);
        baseDateObj.setDate(baseDateObj.getDate() + avgDays);
        
        // Format as YYYY-MM-DD
        const rasDateStr = baseDateObj.toISOString().split('T')[0];
        
        return {
            date: rasDateStr,
            avgDays: avgDays
        };
    }

    function renderDiscountTable() {
        const checks = selectedChecks;
        const body = $('#discount-checks-table-body').empty();
        const baseDate = UI.toGregorian('#discount-base-date');
        const monthlyRate = parseFloat($('#discount-rate').val()) || 0;

        let totalOriginal = 0;
        let totalDiscount = 0;
        let totalDiscounted = 0;

        checks.forEach(c => {
            const daysToMaturity = calculateDaysBetween(baseDate, c.dueDate);
            const discount = calculateDiscount(c.amount, daysToMaturity, monthlyRate);
            const discountedAmount = c.amount - discount;

            totalOriginal += c.amount;
            totalDiscount += discount;
            totalDiscounted += discountedAmount;

            body.append(`<tr data-check-id="${c.id}">
                <td>${UI.escapeHtml(c.checkNumber)}</td>
                <td>${UI.escapeHtml(c.bankName || '-')}</td>
                <td>${UI.gregorianToPersian(c.dueDate)}</td>
                <td>${daysToMaturity > 0 ? daysToMaturity : '<span class="text-danger">سررسید گذشته</span>'}</td>
                <td>${UI.formatCurrency(c.amount)}</td>
                <td class="text-danger">${UI.formatCurrency(discount)}</td>
                <td class="text-success fw-bold">${UI.formatCurrency(discountedAmount)}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-discount-check" data-check-id="${c.id}" title="خارج کردن از لیست راس‌گیری">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </td>
            </tr>`);
        });

        // Calculate and display weighted average Ras date
        const rasInfo = calculateWeightedRasDate(checks, baseDate);
        if (rasInfo) {
            $('#calculated-ras-date').val(UI.gregorianToPersian(rasInfo.date));
            $('#ras-days-info').text(`${rasInfo.avgDays} روز از تاریخ مبنا`);
        } else {
            $('#calculated-ras-date').val('-');
            $('#ras-days-info').text('');
        }

        $('#total-original-amount').text(UI.formatCurrency(totalOriginal));
        $('#total-discount-amount').text(UI.formatCurrency(totalDiscount));
        $('#total-discounted-amount').text(UI.formatCurrency(totalDiscounted));
    }

    function updateDiscountPersonSelect(personType) {
        const personSelect = $('#discount-person-select').empty().append('<option value="">-- انتخاب کنید --</option>');
        let list = [];
        switch (personType) {
            case 'customer': list = App.getCache().customers; break;
            case 'supplier': list = App.getCache().suppliers; break;
            case 'partner': list = App.getCache().partners; break;
            case 'contact': list = App.getCache().contacts; break;
        }
        list.forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));
    }

    function openDiscountModal() {
        selectedChecks = getSelectedChecksData().filter(c => c.type === 'received' && c.status === 'in_hand');
        if (selectedChecks.length === 0) {
            UI.showError('لطفاً حداقل یک چک دریافتی «نزد ما» را انتخاب کنید.');
            return;
        }

        const modal = $(config.discountModalId);
        UI.hideModalError(config.discountModalId);
        
        // Set default values
        $('#discount-rate').val(0);
        $('#discount-base-date').val(UI.today());
        $('#discount-transaction-date').val(UI.today());
        $('#discount-description').val('');
        
        // Initialize person select
        $('#discount-person-type').val('supplier');
        updateDiscountPersonSelect('supplier');
        
        // Render checks table
        renderDiscountTable();
        
        // Initialize datepickers
        UI.initializeDatepickers();
        
        // Initialize Select2 for person select
        if ($('#discount-person-select').data('select2')) {
            $('#discount-person-select').select2('destroy');
        }
        $('#discount-person-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "جستجو و انتخاب شخص...",
            // Parent inside the scrolling modal content: with the .modal itself as parent,
            // select2 ignores the modal's scrollTop and opens the list far above the field.
            dropdownParent: $('#discount-person-select').parent()
        });
        
        modal.modal('show');
    }

    async function handleDiscountTransfer(e) {
        e.preventDefault();
        UI.hideModalError(config.discountModalId);

        const personId = $('#discount-person-select').val();
        const personType = $('#discount-person-type').val();
        const transactionDate = UI.toGregorian('#discount-transaction-date');
        const description = $('#discount-description').val();
        const discountRateVal = parseFloat($('#discount-rate').val());
        const discountRate = isNaN(discountRateVal) ? 0 : discountRateVal;
        const baseDate = UI.toGregorian('#discount-base-date');

        if (!personId) {
            UI.showModalError(config.discountModalId, 'لطفاً شخص مقصد را انتخاب کنید.');
            return;
        }

        // Prepare data for each check
        const checksData = selectedChecks.map(c => {
            const daysToMaturity = calculateDaysBetween(baseDate, c.dueDate);
            const discountAmount = calculateDiscount(c.amount, daysToMaturity, discountRate);
            return {
                checkId: c.id,
                originalAmount: c.amount,
                discountAmount: discountAmount,
                discountedAmount: c.amount - discountAmount,
                daysToMaturity: daysToMaturity
            };
        });

        const data = {
            checks: checksData,
            person_id: personId,
            person_type: personType,
            transaction_date: transactionDate,
            description: description,
            discount_rate: discountRate,
            base_date: baseDate
        };

        UI.showLoader();
        const result = await Api.call('discount_and_transfer_checks', data);
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess(`${checksData.length} چک با موفقیت راس‌گیری و منتقل شد.`);
            $(config.discountModalId).modal('hide');
            // FIX: Clear persistent selections after successful transfer
            checksData.forEach(c => unselectCheck(c.checkId));
            await App.updateCache(['checks', 'accounts', 'customers', 'suppliers']);
            load();
            App.getManager('transactions').load();

            const transferredIds = (result.checks || []).map(c => c.id);
            if (transferredIds.length) {
                const groupHint = result.transfer_group_id
                    ? ' (بعداً هم از «گزارش کامل چک» می‌توانید گروه انتقال را ببینید و چاپ کنید)'
                    : '';
                UI.confirmAction(`آیا مایل به چاپ چک‌های منتقل‌شده هستید؟${groupHint}`, async (confirmed) => {
                    if (confirmed) {
                        await printChecksByIds(transferredIds, {
                            title: 'چک‌های راس‌گیری و منتقل‌شده',
                            subtitle: result.transferredTo ? `مقصد: ${result.transferredTo.name}` : ''
                        });
                    }
                });
            }
        } else if (result?.error) {
            UI.showModalError(config.discountModalId, result.error);
        }
    }

    let lastHistoryCheckId = null;
    let lastHistoryTransferGroupId = null;
    let lastHistoryEndorsePaymentId = null;

    function generateChecksPrintHtml(checks, options = {}) {
        const title = options.title || 'چاپ چک‌ها';
        const subtitle = options.subtitle || '';
        const forceDetail = !!options.forceDetail;
        const entityName = (App.getCache().currentEntity && App.getCache().currentEntity.name)
            || (typeof App !== 'undefined' && App.getCurrentEntityName && App.getCurrentEntityName())
            || '';
        const headerBlock = `
            <h2>${UI.escapeHtml(title)}</h2>
            ${subtitle ? `<div class="muted">${UI.escapeHtml(subtitle)}</div>` : ''}
            ${entityName ? `<div class="muted">مجموعه: ${UI.escapeHtml(entityName)}</div>` : ''}
            <div class="muted">تعداد: ${checks.length} چک — تاریخ چاپ: ${UI.gregorianToPersian(new Date().toISOString().slice(0, 10))}</div>`;

        // Multi-check (or default bulk): compact list on one/continuous pages — no one-check-per-page
        if (!forceDetail && checks.length !== 1) {
            const totalAmount = checks.reduce((sum, c) => sum + (parseFloat(c.amount) || 0), 0);
            const listRows = checks.map((c, idx) => {
                const person = c.origin_person || {};
                const personLabel = [person.name, person.type_label ? `(${person.type_label})` : ''].filter(Boolean).join(' ');
                return `<tr>
                    <td>${idx + 1}</td>
                    <td>${UI.escapeHtml(c.checkNumber || '-')}</td>
                    <td>${UI.formatCurrency(c.amount || 0)}</td>
                    <td>${UI.escapeHtml(c.bankName || '-')}</td>
                    <td>${UI.escapeHtml(c.issuerName || '-')}</td>
                    <td>${c.dueDate ? UI.gregorianToPersian(c.dueDate) : '-'}</td>
                    <td>${UI.escapeHtml(c.type_label || '-')}</td>
                    <td>${UI.escapeHtml(c.status_label || '-')}</td>
                    <td>${UI.escapeHtml(personLabel || '-')}</td>
                    <td>${UI.escapeHtml(person.phone || '-')}</td>
                    <td>${UI.escapeHtml(c.origin_invoice?.label || '-')}</td>
                    <td>${UI.escapeHtml(c.current_holder?.label || c.status_label || '-')}</td>
                </tr>`;
            }).join('');

            return `<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${UI.escapeHtml(title)}</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;padding:16px;color:#222;font-size:11px}
h2{margin:0 0 6px;font-size:16px}
.muted{color:#666;margin-bottom:4px;font-size:11px}
.list{width:100%;border-collapse:collapse;margin-top:10px}
.list th,.list td{border:1px solid #bbb;padding:5px 6px;text-align:right;vertical-align:top}
.list th{background:#f0f0f0;font-size:10px;white-space:nowrap}
.list td{font-size:10px}
.summary{margin-top:10px;font-weight:bold}
@media print{body{padding:8px} thead{display:table-header-group} tr{page-break-inside:avoid}}
</style></head><body>${headerBlock}
<table class="list">
<thead><tr>
<th>#</th><th>شماره چک</th><th>مبلغ</th><th>بانک</th><th>صادرکننده</th><th>سررسید</th>
<th>نوع</th><th>وضعیت</th><th>شخص</th><th>تلفن</th><th>فاکتور</th><th>نزد / وضعیت</th>
</tr></thead>
<tbody>${listRows}</tbody>
</table>
<div class="summary">جمع مبلغ: ${UI.formatCurrency(totalAmount)}</div>
<script>window.onload=function(){setTimeout(function(){window.print()},200)}</script>
</body></html>`;
        }

        // Single check detail (history / one selected)
        const c = checks[0];
        const timeline = (c.timeline || []).map(ev => {
            const detailParts = [ev.description, ev.person_name, ev.person_phone, ev.account_name].filter(Boolean);
            return `<tr>
                <td>${UI.gregorianToPersian(ev.date)}</td>
                <td>${UI.escapeHtml(ev.title || ev.type || '-')}</td>
                <td>${ev.amount != null ? UI.formatCurrency(ev.amount) : '-'}</td>
                <td>${UI.escapeHtml(detailParts.join(' — ') || '-')}</td>
            </tr>`;
        }).join('');
        const originPhone = c.origin_person?.phone || '';
        const holderPhone = c.current_holder?.phone || '';

        return `<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${UI.escapeHtml(title)}</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#222}
h2{margin:0 0 8px;font-size:18px}h3{margin:16px 0 8px;font-size:14px}
.muted{color:#666;margin-bottom:6px;font-size:12px}
.meta,.timeline{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px}
.meta th,.meta td,.timeline th,.timeline td{border:1px solid #ccc;padding:6px 8px;text-align:right}
.meta th,.timeline th{background:#f5f5f5;width:18%}
@media print{body{padding:0}}
</style></head><body>
<section class="check-sheet">
${headerBlock}
<table class="meta">
    <tr><th>شماره چک</th><td>${UI.escapeHtml(c.checkNumber || '-')}</td><th>مبلغ</th><td>${UI.formatCurrency(c.amount || 0)}</td></tr>
    <tr><th>بانک</th><td>${UI.escapeHtml(c.bankName || '-')}</td><th>صادرکننده</th><td>${UI.escapeHtml(c.issuerName || '-')}</td></tr>
    <tr><th>سررسید</th><td>${c.dueDate ? UI.gregorianToPersian(c.dueDate) : '-'}</td><th>نوع / وضعیت</th><td>${UI.escapeHtml((c.type_label || '') + ' — ' + (c.status_label || ''))}</td></tr>
    <tr><th>مبدأ</th><td>${UI.escapeHtml(c.origin_person?.name || '-')} ${c.origin_person?.type_label ? '(' + UI.escapeHtml(c.origin_person.type_label) + ')' : ''}</td>
        <th>تلفن شخص</th><td>${UI.escapeHtml(originPhone || '-')}</td></tr>
    <tr><th>فاکتور مبدأ</th><td>${UI.escapeHtml(c.origin_invoice?.label || '-')}</td>
        <th>نزد / وضعیت فعلی</th><td>${UI.escapeHtml(c.current_holder?.label || c.status_label || '-')}${holderPhone ? ' — ' + UI.escapeHtml(holderPhone) : ''}</td></tr>
</table>
${timeline ? `<h3>مسیر چک</h3><table class="timeline"><thead><tr><th>تاریخ</th><th>رویداد</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>${timeline}</tbody></table>` : ''}
</section>
<script>window.onload=function(){setTimeout(function(){window.print()},200)}</script>
</body></html>`;
    }

    async function printChecksByIds(ids, options = {}) {
        if (!ids || !ids.length) {
            UI.showError('چکی برای چاپ انتخاب نشده است.');
            return;
        }
        UI.showLoader();
        const result = await Api.call('get_checks_for_print', { checkIds: ids });
        UI.hideLoader();
        if (!result?.success || !result.checks?.length) {
            UI.showError(result?.error || 'دریافت اطلاعات چک برای چاپ ناموفق بود.');
            return;
        }
        const html = generateChecksPrintHtml(result.checks, options);
        const win = window.open('', '_blank', 'width=960,height=720');
        if (!win) {
            UI.showError('پنجره چاپ مسدود شده است. اجازه پاپ‌آپ را فعال کنید.');
            return;
        }
        win.document.write(html);
        win.document.close();
        win.focus();
    }

    function renderTransferGroupHtml(group, currentCheckId) {
        if (!group || !group.checks || !group.checks.length) return '';
        const dest = group.transferredTo || {};
        const destLabel = [dest.name, dest.type_label ? `(${dest.type_label})` : ''].filter(Boolean).join(' ');
        const rows = group.checks.map((c, idx) => {
            const isCurrent = String(c.id) === String(currentCheckId);
            return `<tr class="${isCurrent ? 'table-info' : ''}">
                <td>${idx + 1}</td>
                <td>${UI.escapeHtml(c.checkNumber || '-')}${isCurrent ? ' <span class="badge bg-primary">همین چک</span>' : ''}</td>
                <td>${UI.formatCurrency(c.amount || 0)}</td>
                <td>${UI.escapeHtml(c.bankName || '-')}</td>
                <td>${c.dueDate ? UI.gregorianToPersian(c.dueDate) : '-'}</td>
                <td>${UI.escapeHtml(c.status_label || c.status || '-')}</td>
            </tr>`;
        }).join('');
        return `
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="mb-2"><i class="bi bi-collection me-1"></i>گروه راس‌گیری و انتقال (${group.checkCount || group.checks.length} چک)</h6>
                <div class="small text-muted mb-2">
                    ${group.transfer_date ? `تاریخ انتقال: ${UI.gregorianToPersian(group.transfer_date)} — ` : ''}
                    مقصد: ${UI.escapeHtml(destLabel || '-')}
                    ${dest.phone ? ` — ${UI.escapeHtml(dest.phone)}` : ''}
                    — جمع مبلغ: ${UI.formatCurrency(group.totalAmount || 0)}
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th>#</th><th>شماره</th><th>مبلغ</th><th>بانک</th><th>سررسید</th><th>وضعیت</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div class="small text-muted mt-2 mb-0">اگر یک چک از این انتقال عودت شود، مابقی چک‌های هم‌گروه اینجا باقی می‌مانند.</div>
            </div>`;
    }

    function renderHistoryHtml(check) {
        const timelineRows = (check.timeline || []).map(ev => `
            <tr>
                <td>${ev.date ? UI.gregorianToPersian(ev.date) : '-'}</td>
                <td>${UI.escapeHtml(ev.title || ev.type || '-')}</td>
                <td>${ev.amount != null ? UI.formatCurrency(ev.amount) : '-'}</td>
                <td>${UI.escapeHtml([ev.person_name, ev.person_phone, ev.account_name, ev.invoice_label, ev.description].filter(Boolean).join(' — ') || '-')}</td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted">رویدادی ثبت نشده است</td></tr>';

        return `
            <div class="mb-3">
                <div class="row g-2">
                    <div class="col-md-4"><strong>شماره:</strong> ${UI.escapeHtml(check.checkNumber)}</div>
                    <div class="col-md-4"><strong>مبلغ:</strong> ${UI.formatCurrency(check.amount)}</div>
                    <div class="col-md-4"><strong>وضعیت:</strong> ${UI.escapeHtml(check.status_label || check.status)}</div>
                    <div class="col-md-4"><strong>بانک:</strong> ${UI.escapeHtml(check.bankName || '-')}</div>
                    <div class="col-md-4"><strong>صادرکننده:</strong> ${UI.escapeHtml(check.issuerName || '-')}</div>
                    <div class="col-md-4"><strong>سررسید:</strong> ${check.dueDate ? UI.gregorianToPersian(check.dueDate) : '-'}</div>
                    <div class="col-md-4"><strong>تاریخ وصول/پاس:</strong> ${check.cashed_date ? UI.gregorianToPersian(check.cashed_date) : '-'}</div>
                    ${check.bounced_date ? `<div class="col-md-8 text-danger"><strong>تاریخ برگشت:</strong> ${UI.gregorianToPersian(check.bounced_date)}${check.bounce_reason ? ' — ' + UI.escapeHtml(check.bounce_reason) : ''}</div>` : ''}
                    <div class="col-md-4"><strong>مبدأ:</strong> ${UI.escapeHtml(check.origin_person?.name || '-')}${check.origin_person?.type_label ? ' (' + UI.escapeHtml(check.origin_person.type_label) + ')' : ''}</div>
                    <div class="col-md-4"><strong>تلفن شخص:</strong> ${UI.escapeHtml(check.origin_person?.phone || '-')}</div>
                    <div class="col-md-4"><strong>فاکتور مبدأ:</strong> ${UI.escapeHtml(check.origin_invoice?.label || '-')}</div>
                    <div class="col-md-12"><strong>نزد / وضعیت فعلی:</strong> ${UI.escapeHtml(check.current_holder?.label || '-')}${check.current_holder?.phone ? ' — ' + UI.escapeHtml(check.current_holder.phone) : ''}</div>
                </div>
            </div>
            ${renderTransferGroupHtml(check.transfer_group, check.id)}
            <h6 class="mb-2">مسیر و رویدادها</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>تاریخ</th><th>رویداد</th><th>مبلغ</th><th>جزئیات</th></tr></thead>
                    <tbody>${timelineRows}</tbody>
                </table>
            </div>`;
    }

    async function openCheckHistory(checkId) {
        lastHistoryCheckId = checkId;
        lastHistoryTransferGroupId = null;
        lastHistoryEndorsePaymentId = null;
        $('#print-transfer-group-btn, #edit-transfer-payment-btn').addClass('d-none');
        $('#check-history-body').html('<div class="text-center text-muted py-4">در حال بارگذاری...</div>');
        $('#checkHistoryModal').modal('show');
        UI.showLoader();
        const result = await Api.call('get_check_history', { checkId });
        UI.hideLoader();
        if (!result?.success || !result.checks?.length) {
            $('#check-history-body').html(`<div class="alert alert-danger">${UI.escapeHtml(result?.error || 'اطلاعات چک یافت نشد.')}</div>`);
            return;
        }
        const check = result.checks[0];
        lastHistoryTransferGroupId = check.transfer_group_id || check.transfer_group?.transfer_group_id || null;
        lastHistoryEndorsePaymentId = check.endorse_payment_id || null;
        if (lastHistoryTransferGroupId && (check.transfer_group?.checks?.length || 0) > 0) {
            $('#print-transfer-group-btn').removeClass('d-none');
        }
        if (lastHistoryEndorsePaymentId || check.status === 'endorsed') {
            $('#edit-transfer-payment-btn').removeClass('d-none');
        }
        $('#check-history-body').html(renderHistoryHtml(check));
    }

    async function openPaymentForCheck(checkId, preferType = null) {
        if (!checkId) return;
        UI.showLoader();
        const payload = { entityType: 'payment', id: checkId, by: 'checkId' };
        if (preferType) payload.preferType = preferType;
        const payment = await Api.call('get_entity_by_id', payload);
        UI.hideLoader();
        if (payment && !payment.error) {
            App.getManager('transactions').prepareTransactionModal(payment);
        } else {
            UI.showError(payment?.error || 'تراکنش مرتبط با این چک یافت نشد.');
        }
    }

    function localIsoDate() {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    const CHECK_FILTER_PRESETS = {
        all: { type: '', status: '', due: '', related: '', clearDates: true },
        received_in_hand: { type: 'received', status: 'in_hand', due: '', related: '', clearDates: true },
        payable_open: { type: 'payable', status: 'payable', due: '', related: '', clearDates: true },
        past_due: { type: '', status: 'open', due: 'past_due', related: '', clearDates: true },
        next_7: { type: '', status: 'open', due: 'next_7', related: '', clearDates: true },
        endorsed: { type: '', status: 'endorsed', due: '', related: '', clearDates: true },
        cashed: { type: '', status: 'cashed', due: '', related: '', clearDates: true }
    };

    function readOptionalGregorianDate(selector) {
        const raw = ($(selector).val() || '').toString().trim();
        if (!raw) return '';
        const validation = UI.validatePersianDate(UI.toEnglishDigits(raw));
        if (!validation.valid) {
            UI.showError(validation.error || 'تاریخ فیلتر نامعتبر است.');
            return null;
        }
        return UI.toGregorian(selector) || '';
    }

    function collectCheckListFilters() {
        const dueFrom = readOptionalGregorianDate('#check-filter-due-from');
        if (dueFrom === null) return null;
        const dueTo = readOptionalGregorianDate('#check-filter-due-to');
        if (dueTo === null) return null;

        return {
            filterCheckType: $('#check-filter-type').val() || '',
            filterStatus: $('#check-filter-status').val() || '',
            filterDue: $('#check-filter-due').val() || '',
            filterRelated: $('#check-filter-related').val() || '',
            filterDueFrom: dueFrom,
            filterDueTo: dueTo
        };
    }

    function applyFilterControls(values = {}, options = {}) {
        if (values.filterCheckType !== undefined) $('#check-filter-type').val(values.filterCheckType || '');
        if (values.filterStatus !== undefined && !Array.isArray(values.filterStatus)) {
            $('#check-filter-status').val(values.filterStatus || '');
        }
        if (values.filterDue !== undefined) $('#check-filter-due').val(values.filterDue || '');
        if (values.filterRelated !== undefined) $('#check-filter-related').val(values.filterRelated || '');
        if (options.clearDates || values.clearDates) {
            $('#check-filter-due-from, #check-filter-due-to').val('');
        }
    }

    function applyIncomingCheckFilters(filters) {
        if (!filters) return;
        if (filters.filterType === 'past_due') {
            applyFilterControls({
                filterCheckType: filters.filterCheckType || '',
                filterStatus: filters.filterStatus || 'open',
                filterDue: 'past_due',
                filterRelated: filters.filterRelated || ''
            }, { clearDates: true });
            return;
        }
        applyFilterControls(filters);
    }

    function highlightCheckFilterPreset() {
        const current = {
            type: $('#check-filter-type').val() || '',
            status: $('#check-filter-status').val() || '',
            due: $('#check-filter-due').val() || '',
            related: $('#check-filter-related').val() || '',
            from: ($('#check-filter-due-from').val() || '').trim(),
            to: ($('#check-filter-due-to').val() || '').trim()
        };
        $('#check-filter-presets .check-filter-preset').removeClass('active');
        const match = Object.entries(CHECK_FILTER_PRESETS).find(([, preset]) => {
            return current.type === (preset.type || '')
                && current.status === (preset.status || '')
                && current.due === (preset.due || '')
                && current.related === (preset.related || '')
                && current.from === ''
                && current.to === '';
        });
        const key = match ? match[0] : '';
        if (key) {
            $(`#check-filter-presets .check-filter-preset[data-preset="${key}"]`).addClass('active');
        }
    }

    function applyCheckFilterPreset(presetKey) {
        const preset = CHECK_FILTER_PRESETS[presetKey];
        if (!preset) return;
        applyFilterControls({
            filterCheckType: preset.type,
            filterStatus: preset.status,
            filterDue: preset.due,
            filterRelated: preset.related
        }, { clearDates: true });
        $('#checks-filter-info').empty();
        state.currentPage = 1;
        load();
    }

    function resetCheckFilters() {
        applyCheckFilterPreset('all');
    }

    async function load(filters = null, message = null) {
        UI.showLoader();

        const filterInfoContainer = $('#checks-filter-info').empty();
        state.tableName = config.tableName;

        if (filters) {
            applyIncomingCheckFilters(filters);
            if (message) {
                const clearButton = $('<button class="btn btn-sm btn-warning ms-2">حذف فیلتر</button>');
                clearButton.on('click', () => {
                    $('#check-search').val('');
                    state.searchTerm = '';
                    resetCheckFilters();
                });
                filterInfoContainer.html(`<div class="alert alert-info py-1">${UI.escapeHtml(message)}</div>`).find('div').append(clearButton);
            }
        }

        const collected = collectCheckListFilters();
        if (!collected) {
            UI.hideLoader();
            return;
        }
        Object.assign(state, collected);
        delete state.filterType;
        delete state.filterBank;
        highlightCheckFilterPreset();

        const payload = { ...state, ...collected };
        const response = await Api.call('get_paginated_data', payload);

        if (response) {
            renderTable(response.data);
            UI.renderPagination(config.tableName, response.totalRecords, state);
            UI.updateSortIndicators(config.tableName, state);
        }
        
        // Load checks summary
        await loadChecksSummary();
        
        UI.hideLoader();
    }

    async function loadChecksSummary() {
        const summary = await Api.call('get_checks_summary');
        if (summary) {
            // Received checks
            $('#received-in-hand-count').text(`${summary.received.inHand.count} چک`);
            $('#received-in-hand-amount').text(UI.formatCurrency(summary.received.inHand.total));
            $('#received-past-due-count').text(`${summary.received.pastDue.count} چک`);
            $('#received-past-due-amount').text(UI.formatCurrency(summary.received.pastDue.total));
            
            // Payable checks
            $('#payable-unpaid-count').text(`${summary.payable.unpaid.count} چک`);
            $('#payable-unpaid-amount').text(UI.formatCurrency(summary.payable.unpaid.total));
            $('#payable-past-due-count').text(`${summary.payable.pastDue.count} چک`);
            $('#payable-past-due-amount').text(UI.formatCurrency(summary.payable.pastDue.total));
        }
    }

    async function handleDelete(id) {
        UI.confirmAction(`آیا از حذف این چک و تراکنش مالی مرتبط با آن مطمئن هستید؟ این عملیات قابل بازگشت نیست.`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call('delete_check', { id });
                UI.hideLoader();
                if (result?.success) {
                    // FIX: Remove deleted check from persistent selection
                    unselectCheck(id);
                    UI.showSuccess('چک با موفقیت حذف شد.');
                    await App.updateCache(['checks', 'accounts']);
                    load();
                    App.getManager('transactions').load();
                }
            }
        });
    }

    function attachEvents() {
        $('#add-check-btn').on('click', () => {
            App.getManager('transactions').prepareTransactionModal(null, { type: 'check' });
        });

        // Select all checks checkbox
        $('#select-all-checks').on('change', function() {
            const isChecked = $(this).prop('checked');
            $('.check-select').prop('checked', isChecked);
            // FIX: Update persistent selection set
            $('.check-select').each(function() {
                const checkId = $(this).data('check-id');
                if (isChecked) {
                    selectCheck(checkId, $(this));
                } else {
                    unselectCheck(checkId);
                }
            });
            updateDiscountButtonState();
        });

        // Individual check selection
        $('body').on('change', '.check-select', function() {
            // FIX: Update persistent selection set when checkbox is toggled
            const checkId = $(this).data('check-id');
            if ($(this).prop('checked')) {
                selectCheck(checkId, $(this));
            } else {
                unselectCheck(checkId);
            }
            
            updateDiscountButtonState();
            // Update select-all checkbox state
            const totalCheckboxes = $('.check-select').length;
            const checkedCheckboxes = $('.check-select:checked').length;
            $('#select-all-checks').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
        });

        // Discount button click
        $('#discount-checks-btn').on('click', openDiscountModal);
        $('#print-checks-btn').on('click', async function() {
            const ids = Array.from(persistentSelectedIds);
            await printChecksByIds(ids);
        });

        // Calculate discount button
        $('#calculate-discount-btn').on('click', renderDiscountTable);

        // Remove a check from the ras-giri list before confirming transfer
        $('body').on('click', '#discount-checks-table .btn-remove-discount-check', function () {
            const removeId = $(this).data('check-id');
            selectedChecks = selectedChecks.filter(c => String(c.id) !== String(removeId));
            unselectCheck(removeId);
            $(`.check-select[data-check-id="${removeId}"]`).prop('checked', false);
            updateDiscountButtonState();
            if (selectedChecks.length === 0) {
                $(config.discountModalId).modal('hide');
                UI.showError('همه چک‌ها از لیست راس‌گیری خارج شدند.');
                return;
            }
            renderDiscountTable();
        });

        // Discount rate or base date change - recalculate
        $('#discount-rate, #discount-base-date').on('change', renderDiscountTable);

        // Person type change in discount modal
        $('#discount-person-type').on('change', function() {
            updateDiscountPersonSelect($(this).val());
        });

        // Discount transfer form submit
        $('#discount-transfer-form').on('submit', handleDiscountTransfer);

        const searchHandler = UI.debounce((term) => {
            $('#checks-filter-info').empty();
            state.searchTerm = term;
            state.currentPage = 1;
            load();
        }, 500);
        $('#check-search').on('input', function () { searchHandler($(this).val()); });

        $('#check-filter-type, #check-filter-status, #check-filter-due, #check-filter-related').on('change', function () {
            $('#checks-filter-info').empty();
            state.currentPage = 1;
            load();
        });

        $('#check-filter-due-from, #check-filter-due-to').on('change', function () {
            $('#checks-filter-info').empty();
            state.currentPage = 1;
            load();
        });

        $('#check-filter-presets').on('click', '.check-filter-preset', function () {
            applyCheckFilterPreset($(this).data('preset'));
        });

        $('body').on('click', '.check-summary-filter', function () {
            applyFilterControls({
                filterCheckType: $(this).data('check-type') || '',
                filterStatus: $(this).data('check-status') || '',
                filterDue: $(this).data('check-due') || '',
                filterRelated: ''
            }, { clearDates: true });
            $('#checks-filter-info').empty();
            state.currentPage = 1;
            load();
        });

        $('#check-filter-reset').on('click', function () {
            $('#check-search').val('');
            state.searchTerm = '';
            resetCheckFilters();
        });

        // Limit (rows per page) change handler
        $('#check-limit').on('change', function() {
            state.limit = parseInt($(this).val());
            state.currentPage = 1;
            load();
        });

        UI.initializeDatepickers();

        $('body').on('click', '#checks-table .btn-delete', function () {
            handleDelete($(this).data('id'));
        });

        $('body').on('click', '#checks-table .btn-check-history', function () {

            openCheckHistory($(this).data('id'));

        });

        // ---- Check lifecycle (برگشت / عودت / لغو) ----
        const afterLifecycle = async (res) => {
            if (!res?.success) return;
            UI.showSuccess(res.message || 'انجام شد.');
            await App.updateCache(['checks', 'accounts']);
            load();
            const tx = App.getManager && App.getManager('transactions');
            if (tx && typeof tx.load === 'function') tx.load();
        };

        $('body').on('click', '#checks-table .btn-bounce-check', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const values = await UI.formDialog({
                title: 'ثبت برگشت چک',
                message: '<div class="alert alert-warning py-2 small mb-0">با ثبت برگشت، تسویه انجام‌شده با این چک باطل می‌شود و مانده طرف حساب (و در صورت خرج شدن، مانده دریافت‌کننده چک) دوباره بدهکار/بستانکار می‌شود. موجودی بانک تغییری نمی‌کند.</div>',
                fields: [
                    { name: 'date', label: 'تاریخ برگشت', type: 'date', required: true },
                    { name: 'reason', label: 'علت برگشت (اختیاری)', type: 'text', placeholder: 'مثلاً کسری موجودی' },
                ],
                submitText: 'ثبت برگشت',
                submitClass: 'btn-danger',
            });
            if (!values) return;
            UI.showLoader();
            const res = await Api.call('bounce_check', { checkId: id, date: values.date, reason: values.reason });
            UI.hideLoader();
            await afterLifecycle(res);
        });

        $('body').on('click', '#checks-table .btn-return-endorsed-check', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const values = await UI.formDialog({
                title: 'عودت چک خرج‌شده',
                message: '<div class="small text-muted">چک از طرف دریافت‌کننده (بدون مراجعه به بانک) پس داده شده است. تسویه با او باطل و چک به «نزد ما» برمی‌گردد.</div>',
                fields: [
                    { name: 'date', label: 'تاریخ عودت', type: 'date', required: true },
                    { name: 'note', label: 'توضیح (اختیاری)', type: 'text' },
                ],
                submitText: 'ثبت عودت',
            });
            if (!values) return;
            UI.showLoader();
            const res = await Api.call('return_endorsed_check', { checkId: id, date: values.date, note: values.note });
            UI.hideLoader();
            await afterLifecycle(res);
        });

        $('body').on('click', '#checks-table .btn-undo-cash-check', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const ok = await UI.confirm('وصول/پاس این چک لغو شود؟ مبلغ آن از موجودی حساب بانکی برگشت داده می‌شود و چک به وضعیت قبلی برمی‌گردد.', 'لغو وصول / پاس');
            if (!ok) return;
            UI.showLoader();
            const res = await Api.call('undo_cash_check', { checkId: id });
            UI.hideLoader();
            await afterLifecycle(res);
        });

        $('body').on('click', '#checks-table .btn-undo-bounce-check', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const ok = await UI.confirm('برگشت این چک لغو شود؟ اسناد ابطال تسویه حذف می‌شوند و چک به وضعیت قبلی برمی‌گردد.', 'لغو برگشت چک');
            if (!ok) return;
            UI.showLoader();
            const res = await Api.call('undo_bounce_check', { checkId: id });
            UI.hideLoader();
            await afterLifecycle(res);
        });



        $('body').on('click', '#checks-table .btn-print-check', async function () {

            await printChecksByIds([$(this).data('id')]);

        });



        $('#print-history-check-btn').on('click', async function () {
            if (lastHistoryCheckId) {
                await printChecksByIds([lastHistoryCheckId], { title: 'گزارش کامل چک', forceDetail: true });
            }
        });

        $('#print-transfer-group-btn').on('click', async function () {
            if (!lastHistoryTransferGroupId && !lastHistoryCheckId) return;
            UI.showLoader();
            const group = await Api.call('get_check_transfer_group', {
                transfer_group_id: lastHistoryTransferGroupId || undefined,
                checkId: lastHistoryCheckId || undefined
            });
            UI.hideLoader();
            if (!group?.success || !group.checks?.length) {
                UI.showError(group?.error || 'گروه انتقال یافت نشد.');
                return;
            }
            const ids = group.checks.map(c => c.id);
            const destName = group.transferredTo?.name || '';
            await printChecksByIds(ids, {
                title: 'چک‌های راس‌گیری و منتقل‌شده',
                subtitle: destName ? `مقصد: ${destName}` : ''
            });
        });

        $('#edit-transfer-payment-btn').on('click', async function () {
            if (lastHistoryEndorsePaymentId) {
                UI.showLoader();
                const payment = await Api.call('get_entity_by_id', {
                    entityType: 'payment',
                    id: lastHistoryEndorsePaymentId
                });
                UI.hideLoader();
                if (payment && !payment.error) {
                    $('#checkHistoryModal').modal('hide');
                    App.getManager('transactions').prepareTransactionModal(payment);
                } else {
                    UI.showError(payment?.error || 'تراکنش انتقال یافت نشد.');
                }
                return;
            }
            if (lastHistoryCheckId) {
                $('#checkHistoryModal').modal('hide');
                await openPaymentForCheck(lastHistoryCheckId, 'endorse_check');
            }
        });

        $('body').on('click', '#checks-table .btn-edit-payment-from-check', async function () {
            const checkId = $(this).data('check-id');
            if (!checkId) return;
            const $row = $(this).closest('tr');
            const status = $row.find('.check-select').data('status')
                || (App.getCache().checks || []).find(c => String(c.id) === String(checkId))?.status;
            const preferType = status === 'endorsed' ? 'endorse_check' : 'check';
            await openPaymentForCheck(checkId, preferType);
        });
    }

    return {
        init: attachEvents,
        load,
        openDiscountModal,
        printChecksByIds,
        openCheckHistory
    };
})();