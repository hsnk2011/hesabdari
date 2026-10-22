// /js/ui.js

const UI = (function () {
    const TIMINGS = (typeof AppConfig !== 'undefined' && AppConfig.UI_TIMINGS) || { TOAST: 5000, TOAST_EXTENDED: 1000 };
    toastr.options = {
        "closeButton": true, "progressBar": true, "positionClass": "toast-top-left",
        "timeOut": String(TIMINGS.TOAST), "extendedTimeOut": String(TIMINGS.TOAST_EXTENDED)
    };

    // Read a public setting from the app cache with a safe fallback.
    // Works at call time (after App init), so settings saved in the panel apply.
    const getAppSetting = (key, fallback) => {
        try {
            const pub = (typeof App !== 'undefined' && App.getCache && App.getCache().publicSettings) || {};
            const val = pub[key];
            return (val === undefined || val === null || val === '') ? fallback : val;
        } catch (e) {
            return fallback;
        }
    };

    const getCurrencyLabel = () => getAppSetting('currency_label',
        (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS && AppConfig.DEFAULTS.CURRENCY_LABEL) || 'تومان');

    const showSuccess = (message, title = 'موفق', options = {}) => toastr.success(message, title, options);
    const showError = (message, title = 'خطا') => toastr.error(message, title);
    const showInfo = (message, title = 'اطلاع') => toastr.info(message, title);
    const showLoader = (message = '') => {
        const $loader = $('#loader');
        const $msg = $('#loader-message');
        if (message) {
            $msg.text(message).show();
        } else {
            $msg.hide().text('');
        }
        $loader.show();
    };
    const hideLoader = () => {
        $('#loader-message').hide().text('');
        $('#loader').hide();
    };

    const formatCurrency = (num) => {
        const label = getCurrencyLabel();
        const val = Number(num);
        if (isNaN(val)) return `۰ ${label}`;
        return `${val.toLocaleString('fa-IR')} ${label}`;
    };

    const formatNumber = (num) => {
        const val = Number(num);
        if (isNaN(val)) return '۰';
        return val.toLocaleString('fa-IR');
    };

    const toEnglishDigits = (str) => String(str).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

    /**
     * Escape HTML to prevent XSS
     */
    const escapeHtml = (text) => {
        const div = document.createElement('div');
        div.textContent = text;
        // Quotes too, so the result is also safe inside attribute values.
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /**
     * Safely set HTML content (use with caution)
     */
    const setHtmlSafe = (element, html) => {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) {
            // Use DOMPurify if available, otherwise basic sanitization
            if (typeof DOMPurify !== 'undefined') {
                element.innerHTML = DOMPurify.sanitize(html);
            } else {
                element.innerHTML = html; // Fallback
            }
        }
    };

    // --- Jalaali Conversion Algorithm (Pure JS) ---
    const Jalaali = {
        toJalaali: function(gy, gm, gd) {
            var g_d_m, jy, jm, jd, gy2, days;
            g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            gy2 = (gm > 2) ? (gy + 1) : gy;
            days = 355666 + (365 * gy) + ~~((gy2 + 3) / 4) - ~~((gy2 + 99) / 100) + ~~((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
            jy = -1595 + (33 * ~~(days / 12053));
            days %= 12053;
            jy += 4 * ~~(days / 1461);
            days %= 1461;
            if (days > 365) {
                jy += ~~((days - 1) / 365);
                days = (days - 1) % 365;
            }
            if (days < 186) {
                jm = 1 + ~~(days / 31);
                jd = 1 + (days % 31);
            } else {
                jm = 7 + ~~((days - 186) / 30);
                jd = 1 + ((days - 186) % 30);
            }
            return [jy, jm, jd];
        },
        toGregorian: function(jy, jm, jd) {
            var gy, gm, gd, days, sal_a, v;
            jy += 1595;
            days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
            gy = 400 * ~~(days / 146097);
            days %= 146097;
            if (days > 36524) {
                days--;
                gy += 100 * ~~(days / 36524);
                days %= 36524;
                if (days >= 365) days++;
            }
            gy += 4 * ~~(days / 1461);
            days %= 1461;
            if (days > 365) {
                gy += ~~((days - 1) / 365);
                days = (days - 1) % 365;
            }
            gd = days + 1;
            sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            for (gm = 0; gm < 13; gm++) {
                v = sal_a[gm];
                if (gd <= v) break;
                gd -= v;
            }
            return [gy, gm, gd];
        }
    };

    // *** PATCH: Fix persian-date library timezone issue ***
    (function() {
        if (typeof persianDate === 'undefined') return;
        
        const now = new Date();
        const jReal = Jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        const pLib = new persianDate();
        
        // Check if library is off by one day (ahead)
        // Example: Real is 3, Lib is 4
        if (pLib.date() !== jReal[2]) {
            // Silently patch persianDate library for timezone issues
            
            const OriginalPersianDate = window.persianDate;
            
            // Override the constructor
            window.persianDate = function(input) {
                // If no input is provided (meaning "now"), we might need to adjust
                if (input === undefined) {
                    const pd = new OriginalPersianDate();
                    // If the library is still wrong for "now", we force it to the correct date
                    // But we must be careful not to break time.
                    // A safer bet is to construct it from the system date explicitly
                    const n = new Date();
                    return new OriginalPersianDate(n.valueOf());
                }
                return new OriginalPersianDate(input);
            };
            
            // Copy prototype and statics
            window.persianDate.prototype = OriginalPersianDate.prototype;
            for (let key in OriginalPersianDate) {
                if (OriginalPersianDate.hasOwnProperty(key)) {
                    window.persianDate[key] = OriginalPersianDate[key];
                }
            }
            
            // Also patch toCalendar to ensure it doesn't drift
            // This is harder. Let's hope the constructor fix is enough.
            // Actually, if new persianDate() uses Date.now(), and Date.now() is correct,
            // then the issue is inside the library's conversion logic or timezone handling.
            // If we pass the timestamp explicitly, it might behave better.
        }
    })();


    const toGregorian = (elementSelector) => {
        try {
            const val = $(elementSelector).val();
            if (!val) return null;
            const parts = toEnglishDigits(val).split('/');
            if (parts.length !== 3) return null;
            
            const jy = parseInt(parts[0]);
            const jm = parseInt(parts[1]);
            const jd = parseInt(parts[2]);
            
            const gDate = Jalaali.toGregorian(jy, jm, jd);
            // Format as YYYY-MM-DD
            return `${gDate[0]}-${String(gDate[1]).padStart(2, '0')}-${String(gDate[2]).padStart(2, '0')}`;
        } catch (e) { return null; }
    };

    const gregorianToPersian = (gregDateStr) => {
        if (!gregDateStr) return '';
        try {
            let dateObj;
            if (typeof gregDateStr === 'string' && gregDateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
                const parts = gregDateStr.split('-');
                dateObj = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            } else {
                dateObj = new Date(gregDateStr);
            }
            
            const jDate = Jalaali.toJalaali(dateObj.getFullYear(), dateObj.getMonth() + 1, dateObj.getDate());
            return `${jDate[0]}/${String(jDate[1]).padStart(2, '0')}/${String(jDate[2]).padStart(2, '0')}`;
        } catch (e) { return gregDateStr; }
    };

    const today = () => {
        const now = new Date();
        const jDate = Jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        return `${jDate[0]}/${String(jDate[1]).padStart(2, '0')}/${String(jDate[2]).padStart(2, '0')}`;
    };
    
    const firstDayOfYear = () => {
        const now = new Date();
        const jDate = Jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        return `${jDate[0]}/01/01`;
    };

    function initializeDatepickers() {
        // Placeholder shows today's Jalali date as the format example (never stale).
        const todayJalali = UI.gregorianToPersian(new Date().toISOString().slice(0, 10));
        // Initialize our custom BDDatepicker
        document.querySelectorAll(".persian-datepicker").forEach(el => {
            if (!el.value && /^\d{4}\/\d{2}\/\d{2}$/.test(el.placeholder || '')) {
                el.placeholder = todayJalali;
            }
            new BDDatepicker(el);
        });
    }

    function renderPagination(tableName, totalRecords, state) {
        const container = $(`.pagination-container[data-table="${tableName.replace(/_/g, '-')}"]`);
        container.empty();
        // Hide pagination when showing all records (limit = -1) or when records fit on one page
        if (state.limit === -1 || totalRecords <= state.limit) return;

        const totalPages = Math.ceil(totalRecords / state.limit);
        const currentPage = state.currentPage;
        
        // تعداد صفحات نمایش داده شده در هر طرف صفحه فعلی
        const maxVisiblePages = 5; // حداکثر 5 صفحه نمایش داده می‌شود
        const sidePages = Math.floor(maxVisiblePages / 2);
        
        let startPage = Math.max(1, currentPage - sidePages);
        let endPage = Math.min(totalPages, currentPage + sidePages);
        
        // تنظیم برای اطمینان از نمایش maxVisiblePages صفحه
        if (endPage - startPage + 1 < maxVisiblePages) {
            if (startPage === 1) {
                endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            } else if (endPage === totalPages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
        }
        
        let html = '<nav><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">';
        
        // دکمه اول
        if (currentPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="1" data-table="${tableName}" title="صفحه اول"><i class="bi bi-chevron-double-right"></i></a></li>`;
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage - 1}" data-table="${tableName}" title="صفحه قبل"><i class="bi bi-chevron-right"></i></a></li>`;
        }
        
        // نقطه‌چین قبل
        if (startPage > 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        
        // صفحات
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i === currentPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}" data-table="${tableName}">${i}</a></li>`;
        }
        
        // نقطه‌چین بعد
        if (endPage < totalPages) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        
        // دکمه آخر
        if (currentPage < totalPages) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage + 1}" data-table="${tableName}" title="صفحه بعد"><i class="bi bi-chevron-left"></i></a></li>`;
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}" data-table="${tableName}" title="صفحه آخر"><i class="bi bi-chevron-double-left"></i></a></li>`;
        }
        
        html += '</ul></nav>';
        
        // نمایش شماره صفحه فعلی از کل
        html += `<small class="text-muted d-block text-center mt-1">صفحه ${currentPage} از ${totalPages}</small>`;
        
        container.html(html);
    }

    function updateSortIndicators(tableName, state) {
        const table = $(`#${tableName.replace(/_/g, '-')}-table`);
        table.find('.sortable-header .sort-indicator').empty();
        const header = table.find(`.sortable-header[data-sort-by="${state.sortBy}"]`);
        if (header.length) {
            header.find('.sort-indicator').html(state.sortOrder === 'ASC' ? ' <i class="bi bi-arrow-up"></i>' : ' <i class="bi bi-arrow-down"></i>');
        }
    }

    const debounce = (func, delay) => {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    };

    function initNumericFormatting() {
        $('body').on('input', '.numeric-input', function (e) {
            let value = $(this).val().replace(/,/g, '');
            if (!isNaN(value) && value.length > 0) {
                $(this).val(Number(value).toLocaleString('en-US'));
            }
        });
    }

    function confirmAction(message, callback) {
        $('#confirmationModalBody').text(message);
        const confirmBtn = $('#confirmActionBtn');
        confirmBtn.off('click').on('click', () => {
            callback(true);
            confirmBtn.blur(); // Fix for aria-hidden warning
            $('#confirmationModal').modal('hide');
        });
        $('#confirmationModal').modal('show');
    }

    // Resolves once the confirmation modal has fully closed, so a second
    // confirm() right after the first one is not swallowed by its hide animation.
    let confirmModalClosed = Promise.resolve();

    // Promise-based confirmation dialog
    function confirm(message, title = 'تأیید') {
        return confirmModalClosed.then(() => new Promise((resolve) => {
            $('#confirmationModalBody').html(message);
            if (title) {
                $('#confirmationModal .modal-title').text(title);
            }
            const confirmBtn = $('#confirmActionBtn');
            const cancelBtn = $('#confirmationModal .btn-secondary');
            
            const finish = (value) => {
                confirmModalClosed = new Promise(r => $('#confirmationModal').one('hidden.bs.modal', () => r()));
                $('#confirmationModal').modal('hide');
                resolve(value);
            };

            confirmBtn.off('click').on('click', () => {
                confirmBtn.blur();
                finish(true);
            });
            
            cancelBtn.off('click').on('click', () => finish(false));
            
            // Handle modal close (X button or backdrop)
            $('#confirmationModal').off('hidden.bs.modal').on('hidden.bs.modal', () => {
                resolve(false);
            });
            
            $('#confirmationModal').modal('show');
        }));
    }

    // confirm() for plain-text messages: escaped, line breaks kept.
    function confirmText(message, title = 'تأیید') {
        return confirm(escapeHtml(String(message ?? '')).split('\n').join('<br>'), title);
    }

    /**
     * Small promise-based form dialog.
     * fields: [{ name, label, type: 'text'|'number'|'money'|'date'|'textarea'|'select'|'checkbox',
     *            value, options: [{value,label}], required, help, placeholder }]
     * Resolves with { name: value } (dates converted to YYYY-MM-DD, money to Number) or null.
     */
    function formDialog({ title = '', message = '', fields = [], submitText = 'ثبت', submitClass = 'btn-primary', size = '' } = {}) {
        return new Promise((resolve) => {
            const id = 'ui-form-dialog-' + Date.now();
            const fieldHtml = fields.map((f, i) => {
                const fid = `${id}-f${i}`;
                const label = `<label class="form-label" for="${fid}">${escapeHtml(f.label || '')}${f.required ? ' <span class="text-danger">*</span>' : ''}</label>`;
                const help = f.help ? `<div class="form-text">${escapeHtml(f.help)}</div>` : '';
                const ph = f.placeholder ? ` placeholder="${escapeHtml(f.placeholder)}"` : '';
                let input;
                switch (f.type) {
                    case 'textarea':
                        input = `<textarea class="form-control" id="${fid}" data-name="${escapeHtml(f.name)}" rows="2"${ph}>${escapeHtml(f.value ?? '')}</textarea>`;
                        break;
                    case 'select':
                        input = `<select class="form-select" id="${fid}" data-name="${escapeHtml(f.name)}">` +
                            (f.options || []).map(o => `<option value="${escapeHtml(o.value)}"${String(o.value) === String(f.value ?? '') ? ' selected' : ''}>${escapeHtml(o.label)}</option>`).join('') +
                            `</select>`;
                        break;
                    case 'checkbox':
                        return `<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="${fid}" data-name="${escapeHtml(f.name)}"${f.value ? ' checked' : ''}><label class="form-check-label" for="${fid}">${escapeHtml(f.label || '')}</label>${help}</div>`;
                    case 'date': {
                        // allowEmpty: optional dates (e.g. a due date) start blank instead of today.
                        const v = f.value ? (/^\d{4}-\d{2}-\d{2}$/.test(f.value) ? gregorianToPersian(f.value) : f.value) : (f.allowEmpty ? '' : today());
                        input = `<input type="text" class="form-control persian-datepicker" id="${fid}" data-name="${escapeHtml(f.name)}" data-kind="date" value="${escapeHtml(v)}" autocomplete="off">`;
                        break;
                    }
                    case 'money':
                        input = `<input type="text" inputmode="numeric" class="form-control numeric-input" id="${fid}" data-name="${escapeHtml(f.name)}" data-kind="money" value="${f.value !== undefined && f.value !== null && f.value !== '' ? Number(f.value).toLocaleString('en-US') : ''}"${ph}>`;
                        break;
                    default:
                        input = `<input type="${f.type === 'number' ? 'number' : 'text'}" class="form-control" id="${fid}" data-name="${escapeHtml(f.name)}" value="${escapeHtml(f.value ?? '')}"${ph}>`;
                }
                return `<div class="mb-3">${label}${input}${help}</div>`;
            }).join('');

            const html = `
<div class="modal fade" id="${id}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered ${size}">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">${escapeHtml(title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button></div>
      <div class="modal-body">${message ? `<div class="mb-3">${message}</div>` : ''}${fieldHtml}<div class="alert alert-danger py-2 d-none ui-form-dialog-error"></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn ${submitClass} ui-form-dialog-submit">${escapeHtml(submitText)}</button></div>
    </div>
  </div>
</div>`;
            const $modal = $(html).appendTo('body');
            $modal.find('.persian-datepicker').each(function () { try { new BDDatepicker(this); } catch (e) { /* plugin optional */ } });
            let result = null;
            $modal.find('.ui-form-dialog-submit').on('click', () => {
                const values = {};
                let missing = null;
                fields.forEach((f, i) => {
                    const $el = $modal.find(`#${id}-f${i}`);
                    let v;
                    if (f.type === 'checkbox') v = $el.is(':checked');
                    else if (f.type === 'date') v = toGregorian($el);
                    else if (f.type === 'money') v = Number(toEnglishDigits($el.val() || '').replace(/[,٬\s]/g, '')) || 0;
                    else v = ($el.val() ?? '').toString().trim();
                    if (f.required && (v === null || v === '' || (f.type === 'money' && !(v > 0)))) missing = f.label;
                    values[f.name] = v;
                });
                if (missing) {
                    $modal.find('.ui-form-dialog-error').text(`«${missing}» الزامی است.`).removeClass('d-none');
                    return;
                }
                result = values;
                bootstrap.Modal.getInstance($modal[0]).hide();
            });
            $modal.on('hidden.bs.modal', () => { $modal.remove(); resolve(result); });
            new bootstrap.Modal($modal[0]).show();
        });
    }

    const showModalError = (modalId, message) => $(`${modalId} .form-error`).text(message).slideDown();
    const hideModalError = (modalId) => $(`${modalId} .form-error`).slideUp().text('');

    /**
     * Validate required fields in a form
     * @param {string|HTMLElement} form - Form selector or element
     * @returns {Object} {valid: boolean, errors: Array}
     */
    const validateForm = (form) => {
        const $form = $(form);
        const errors = [];
        
        $form.find('[required]').each(function() {
            const $field = $(this);
            const value = $field.val()?.trim();
            const label = $field.closest('.form-group').find('label').text() || $field.attr('name');
            
            if (!value) {
                errors.push(`فیلد "${label}" الزامی است.`);
                $field.addClass('is-invalid');
            } else {
                $field.removeClass('is-invalid');
            }
        });
        
        return { valid: errors.length === 0, errors };
    };

    /**
     * Validate numeric input
     * @param {string|number} value - Value to validate
     * @param {Object} options - {min, max, required}
     * @returns {Object} {valid: boolean, error: string}
     */
    const validateNumber = (value, options = {}) => {
        const { min = null, max = null, required = false } = options;
        const numValue = parseFloat(String(value).replace(/,/g, ''));
        
        if (required && (!value || isNaN(numValue))) {
            return { valid: false, error: 'مقدار عددی الزامی است.' };
        }
        
        if (value && isNaN(numValue)) {
            return { valid: false, error: 'مقدار وارد شده باید عدد باشد.' };
        }
        
        if (min !== null && numValue < min) {
            return { valid: false, error: `مقدار باید حداقل ${min} باشد.` };
        }
        
        if (max !== null && numValue > max) {
            return { valid: false, error: `مقدار باید حداکثر ${max} باشد.` };
        }
        
        return { valid: true, error: null };
    };

    /**
     * Validate Persian date
     * @param {string} dateStr - Date string in YYYY/MM/DD format
     * @returns {Object} {valid: boolean, error: string}
     */
    const validatePersianDate = (dateStr) => {
        if (!dateStr) {
            return { valid: false, error: 'تاریخ الزامی است.' };
        }
        
        const parts = toEnglishDigits(dateStr).split('/');
        if (parts.length !== 3) {
            return { valid: false, error: 'فرمت تاریخ باید YYYY/MM/DD باشد.' };
        }
        
        const [year, month, day] = parts.map(p => parseInt(p));
        
        if (year < 1300 || year > 1500) {
            return { valid: false, error: 'سال باید بین 1300 تا 1500 باشد.' };
        }
        
        if (month < 1 || month > 12) {
            return { valid: false, error: 'ماه باید بین 1 تا 12 باشد.' };
        }
        
        if (day < 1 || day > 31) {
            return { valid: false, error: 'روز باید بین 1 تا 31 باشد.' };
        }
        
        return { valid: true, error: null };
    };

    /**
     * Generate print-ready HTML for invoices with customizable settings
     * @param {Object} invoice - Invoice data
     * @param {Object} settings - Print settings from database
     * @param {string} type - 'sales' or 'purchase'
     * @returns {string} Complete HTML document for printing
     */
    const generateInvoicePrintHtml = (invoice, settings, type = 'sales', options = {}) => {
        const isSales = type === 'sales';
        const title = isSales ? 'فاکتور فروش' : 'فاکتور خرید';
        const personTitle = isSales ? 'مشتری' : 'تأمین کننده';
        const invoiceDate = gregorianToPersian(invoice.date);
        options = Object.assign({ showToolbar: false }, options);
        
        // Build items table - escape all user content to prevent XSS
        // Serial / meter columns stay in the DOM; body.hide-* toggles them live.
        const showSerialColumn = settings.show_serial_column !== false;
        const showMeterColumn = settings.show_meter_column === true;
        const serialLabelsForPrint = (item) => {
            if (item.is_service == 1) return [];
            const current = Array.isArray(item.serial_labels) ? item.serial_labels : [];
            const returned = Array.isArray(item.returned_labels) ? item.returned_labels : [];
            const parts = current.map((label) => String(label || '').trim()).filter(Boolean);
            returned.forEach((label) => {
                const text = String(label || '').trim();
                if (!text) return;
                if (!parts.includes(text)) parts.push(text + ' (مرجوع)');
            });
            return parts;
        };
        const meterPriceForPrint = (item, isService) => {
            if (isService) return null;
            const dims = String(item.dimensions || '').trim().toLowerCase().replace(/\*/g, 'x').replace(/×/g, 'x');
            const area = dimensionArea(dims);
            const unitPrice = parseFloat(item.unitPrice) || 0;
            if (!(area > 0) || !(unitPrice > 0)) return null;
            return Math.round(unitPrice / area);
        };
        let itemsHtml = '';
        let totalQuantity = 0;
        (invoice.items || []).forEach((item, idx) => {
            const itemTotal = (item.quantity || 0) * (item.unitPrice || 0);
            totalQuantity += parseInt(item.quantity) || 0;
            const isService = item.is_service == 1;
            const itemName = isService
                ? (item.item_description || item.name || item.productName || 'خدمات')
                : (item.name || item.productName || '-');
            const itemDims = isService ? '-' : (item.dimensions || '-');
            const meterPrice = meterPriceForPrint(item, isService);
            const rawImage = !isService ? (item.image_url || item.primary_image || item.primary_full_image || '') : '';
            let imageSrc = '';
            if (rawImage) {
                try {
                    imageSrc = /^https?:\/\//i.test(rawImage) || String(rawImage).indexOf('data:') === 0
                        ? String(rawImage)
                        : new URL(String(rawImage), window.location.href).href;
                } catch (e) {
                    imageSrc = String(rawImage);
                }
            }
            const imageCell = imageSrc
                ? `<td class="col-image"><img src="${escapeHtml(imageSrc)}" alt=""></td>`
                : `<td class="col-image"><span class="img-placeholder">—</span></td>`;
            itemsHtml += `<tr>
                <td>${idx + 1}</td>
                ${imageCell}
                <td>${escapeHtml(itemName)}${isService ? ' <span style="font-size:0.75em;color:#0dcaf0">(خدمات)</span>' : ''}</td>
                <td>${escapeHtml(itemDims)}</td>
                <td class="col-serial">${serialLabelsForPrint(item).map(escapeHtml).join('<br>') || '-'}</td>
                <td>${item.quantity || 0}</td>
                <td>${formatCurrency(item.unitPrice)}</td>
                <td class="col-meter">${meterPrice != null ? formatCurrency(meterPrice) : '-'}</td>
                <td>${formatCurrency(itemTotal)}</td>
            </tr>`;
        });

        // Build payments table (supports bidirectional receipt/payment)
        const paymentDirectionLabel = (txn) => (txn === 'payment' ? 'پرداخت' : 'دریافت');
        const paymentSettlingSign = (txn, invType) => {
            if (invType === 'sales') return txn === 'receipt' ? 1 : -1;
            return txn === 'payment' ? 1 : -1;
        };

        // Payment notes default OFF — omit column from DOM (display:none on <td>
        // has caused Chrome print preview to hang on "Loading preview...").
        const showPayDesc = options.showPayDesc === true;

        let paymentsHtml = '';
        if (invoice.payments && invoice.payments.length > 0) {
            invoice.payments.forEach(p => {
                const isSetoff = p.type === 'setoff';
                let typeText = '';
                switch (p.type) {
                    case 'cash':
                    case 'setoff':
                        // Setoff is internal; customer/print copy shows it as normal cash.
                        typeText = 'نقد';
                        break;
                    case 'check': typeText = 'چک'; break;
                    case 'endorse_check': typeText = 'خرج چک'; break;
                    default: typeText = p.type || '-';
                }
                const txn = p.transaction_type || (isSales ? 'receipt' : 'payment');
                const dirLabel = paymentDirectionLabel(txn);
                const sign = paymentSettlingSign(txn, type);
                const amt = parseFloat(p.amount) || 0;
                const rowClass = sign < 0 ? 'pay-reverse' : 'pay-normal';
                const amountCell = sign < 0
                    ? `<span class="amt-out">− ${formatCurrency(amt)}</span>`
                    : `<span class="amt-in">${formatCurrency(amt)}</span>`;
                // Never expose setoff/supplier destination notes on print (customer-facing).
                const payDesc = isSetoff ? '-' : (p.description || '-');
                const descCell = showPayDesc
                    ? `<td class="pay-desc">${escapeHtml(payDesc)}</td>`
                    : '';
                paymentsHtml += `<tr class="${rowClass}">
                    <td>${escapeHtml(dirLabel)}</td>
                    <td>${typeText}</td>
                    <td>${gregorianToPersian(p.date)}</td>
                    <td>${amountCell}</td>
                    ${descCell}
                </tr>`;
            });
        }

        const netAmount = (invoice.totalAmount || 0) - (invoice.discount || 0);
        const taxAmount = parseFloat(invoice.tax_amount) || 0;
        const grandTotal = netAmount + taxAmount;
        const paidAmount = parseFloat(invoice.paidAmount) || 0;
        const remainingAmount = grandTotal - paidAmount;
        const dueDate = invoice.due_date ? gregorianToPersian(invoice.due_date) : '';
        const paymentTermsMap = { cash: 'نقدی', net15: '۱۵ روزه', net30: '۳۰ روزه', net60: '۶۰ روزه', net90: '۹۰ روزه', installment: 'اقساطی' };
        const paymentTermsText = paymentTermsMap[invoice.payment_terms] || '';
        const printTemplate = settings.print_template === 'compact'
            ? 'compact'
            : (settings.print_template === 'images' ? 'images' : 'classic');
        const bodyOptClasses = [
            'print-layout-' + printTemplate,
            showSerialColumn ? '' : 'hide-serial',
            showMeterColumn ? '' : 'hide-meter',
            settings.show_logo ? '' : 'hide-logo',
            settings.show_company_info ? '' : 'hide-company',
            settings.show_payment_info ? '' : 'hide-pay',
            settings.show_signature_line ? '' : 'hide-sign'
        ].filter(Boolean).join(' ');

        // Optional blocks stay in the DOM; body.hide-* classes toggle them live (no document rewrite).
        const logoHtml = settings.logo_url
            ? `<img src="${settings.logo_url}" alt="لوگو" class="invoice-logo opt-logo">`
            : '';

        const companyInfoHtml = (settings.company_phone || settings.company_address) ? `
            <div class="company-info opt-company">
                ${settings.company_phone ? `<span><strong>تلفن:</strong> ${escapeHtml(settings.company_phone)}</span>` : ''}
                ${settings.company_address ? `<span><strong>آدرس:</strong> ${escapeHtml(settings.company_address)}</span>` : ''}
            </div>
        ` : '';

        const paymentInfoHtml = (settings.bank_name || settings.account_number || settings.card_number || settings.sheba_number || settings.account_holder) ? `
            <div class="payment-info opt-payinfo">
                <h4>اطلاعات پرداخت</h4>
                <table class="info-table">
                    ${settings.bank_name ? `<tr><td><strong>نام بانک:</strong></td><td>${escapeHtml(settings.bank_name)}</td></tr>` : ''}
                    ${settings.account_number ? `<tr><td><strong>شماره حساب:</strong></td><td>${escapeHtml(settings.account_number)}</td></tr>` : ''}
                    ${settings.card_number ? `<tr><td><strong>شماره کارت:</strong></td><td>${escapeHtml(settings.card_number)}</td></tr>` : ''}
                    ${settings.sheba_number ? `<tr><td><strong>شماره شبا:</strong></td><td>${escapeHtml(settings.sheba_number)}</td></tr>` : ''}
                    ${settings.account_holder ? `<tr><td><strong>صاحب حساب:</strong></td><td>${escapeHtml(settings.account_holder)}</td></tr>` : ''}
                </table>
            </div>
        ` : '';

        const signatureHtml = `
            <div class="signature-section opt-sign">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">امضا و مهر فروشنده</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">امضا خریدار</div>
                </div>
            </div>
        `;

        // Terms section - escape user content then convert newlines
        const termsHtml = settings.terms ? `
            <div class="terms-section">
                <h4>شرایط و قوانین</h4>
                <p>${escapeHtml(settings.terms).replace(/\n/g, '<br>')}</p>
            </div>
        ` : '';

        const totalsRowsHtml = `
            <tr><td>تعداد کل:</td><td>${totalQuantity}</td></tr>
            <tr><td>جمع کل:</td><td>${formatCurrency(invoice.totalAmount)}</td></tr>
            ${(parseFloat(invoice.discount) || 0) > 0.01 ? `<tr><td>تخفیف:</td><td>${formatCurrency(invoice.discount || 0)}</td></tr>` : ''}
            ${taxAmount > 0 ? `<tr><td>مالیات (${invoice.tax_rate || 0}%):</td><td>${formatCurrency(taxAmount)}</td></tr>` : ''}
            <tr><td>مبلغ قابل پرداخت:</td><td>${formatCurrency(grandTotal)}</td></tr>
            ${invoice.paidAmount !== undefined ? `<tr><td>پرداخت شده:</td><td>${formatCurrency(paidAmount)}</td></tr>` : ''}
            ${invoice.paidAmount !== undefined ? `<tr><td>باقی‌مانده:</td><td>${formatCurrency(remainingAmount)}</td></tr>` : ''}
        `;

        const paymentsBlockHtml = paymentsHtml ? `
            <h4 class="section-title">جزئیات پرداخت</h4>
            <table>
                <thead><tr><th>جهت</th><th>نوع</th><th>تاریخ</th><th>مبلغ</th>${showPayDesc ? '<th>توضیحات</th>' : ''}</tr></thead>
                <tbody>${paymentsHtml}</tbody>
            </table>
        ` : '';

        const bottomHtml = `
            <div class="bottom-classic">
                ${paymentsBlockHtml}
                <div class="totals"><table class="totals-table">${totalsRowsHtml}</table></div>
                <div class="clearfix"></div>
            </div>
            <div class="bottom-compact${paymentsHtml ? '' : ' no-payments'}">
                ${paymentsHtml ? `<div class="payments-block">${paymentsBlockHtml}</div>` : '<div></div>'}
                <div class="totals"><table class="totals-table">${totalsRowsHtml}</table></div>
            </div>`;

        const layoutCss = `
        body.hide-serial .col-serial { display: none !important; }
        body.hide-meter .col-meter { display: none !important; }
        body.hide-logo .opt-logo { display: none !important; }
        body.hide-company .opt-company { display: none !important; }
        body.hide-pay .opt-payinfo { display: none !important; }
        body.hide-sign .opt-sign { display: none !important; }
        body.print-layout-classic .bottom-compact,
        body.print-layout-images .bottom-compact { display: none !important; }
        body.print-layout-compact .bottom-classic { display: none !important; }
        body:not(.print-layout-images) .col-image { display: none !important; }
        body.print-layout-images .col-image {
            width: 72px; text-align: center; vertical-align: middle;
        }
        body.print-layout-images .col-image img {
            width: 64px; height: 64px; object-fit: cover; border-radius: 4px;
            display: block; margin: 0 auto; background: #f3f4f6;
        }
        body.print-layout-images .col-image .img-placeholder {
            display: inline-block; width: 64px; height: 64px; line-height: 64px;
            background: #f3f4f6; color: #9ca3af; border-radius: 4px; font-size: 12px;
        }

        body.print-layout-compact { font-size: 11px; padding: 12px; }
        body.print-layout-compact .invoice-logo { max-height: 52px; max-width: 160px; display: block; }
        body.print-layout-compact .header { padding-bottom: 6px; margin-bottom: 8px; }
        body.print-layout-compact .header-left { font-size: 11px; }
        body.print-layout-compact .header h1 { font-size: 16px; }
        body.print-layout-compact .header .subtitle { font-size: 12px; margin-top: 2px; }
        body.print-layout-compact .header-text { margin-top: 3px; font-size: 11px; }
        body.print-layout-compact .company-info { margin-bottom: 6px; font-size: 11px; }
        body.print-layout-compact .company-info span { margin: 1px 0; }
        body.print-layout-compact .info-row { gap: 6px; margin-bottom: 8px; padding: 6px 8px; border-radius: 4px; }
        body.print-layout-compact h4.section-title { margin: 8px 0 4px; font-size: 12px; }
        body.print-layout-compact table { margin: 4px 0 8px; }
        body.print-layout-compact th, body.print-layout-compact td { padding: 4px 6px; vertical-align: top; }
        body.print-layout-compact .col-serial { font-size: 9px; line-height: 1.3; word-break: break-word; max-width: 120px; }
        body.print-layout-compact .pay-desc { font-size: 9.5px; line-height: 1.25; word-break: break-word; max-width: 280px; }
        body.print-layout-compact .bottom-compact {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px 14px;
            align-items: start;
            margin: 6px 0 4px;
            page-break-inside: avoid;
        }
        body.print-layout-compact .bottom-compact.no-payments { grid-template-columns: 1fr; justify-items: end; }
        body.print-layout-compact .payments-block { min-width: 0; }
        body.print-layout-compact .payments-block table { margin: 0; }
        body.print-layout-compact .totals { margin: 0; }
        body.print-layout-compact .totals-table { width: auto; min-width: 220px; margin: 0; }
        body.print-layout-compact .totals-table td { border: none; padding: 3px 10px; }
        body.print-layout-compact .totals-table tr:last-child { font-weight: bold; font-size: 12px; background: #e8f4f8; }
        body.print-layout-compact .payment-info { margin: 8px 0; padding: 8px 10px; border-radius: 4px; }
        body.print-layout-compact .payment-info h4 { margin: 0 0 6px 0; font-size: 12px; }
        body.print-layout-compact .info-table { margin: 0; }
        body.print-layout-compact .info-table td { padding: 2px 8px; }
        body.print-layout-compact .signature-section { margin-top: 18px; }
        body.print-layout-compact .signature-line { height: 36px; margin-bottom: 4px; }
        body.print-layout-compact .signature-label { font-size: 10px; }
        body.print-layout-compact .terms-section { margin-top: 10px; padding: 6px 8px; border-radius: 4px; font-size: 10px; }
        body.print-layout-compact .terms-section h4 { margin: 0 0 4px 0; font-size: 11px; }
        body.print-layout-compact .footer { margin-top: 10px; padding-top: 6px; font-size: 10px; }
        @media print {
            @page { margin: 8mm; }
            body.print-layout-compact { margin: 0; padding: 0; font-size: 10px; }
            .no-print { display: none !important; }
            body.print-layout-compact .header { margin-bottom: 6px; padding-bottom: 4px; }
            body.print-layout-compact .header h1 { font-size: 14px; }
            body.print-layout-compact .info-row { margin-bottom: 6px; padding: 4px 6px; }
            body.print-layout-compact h4.section-title { margin: 6px 0 3px; font-size: 11px; }
            body.print-layout-compact table { margin: 2px 0 6px; }
            body.print-layout-compact th, body.print-layout-compact td { padding: 2px 4px; }
            body.print-layout-compact .pay-desc { font-size: 8.5px; max-width: 240px; }
            body.print-layout-compact .bottom-compact { gap: 6px 10px; page-break-inside: avoid; }
            body.print-layout-compact .totals-table { min-width: 200px; }
            body.print-layout-compact .totals-table td { padding: 2px 8px; }
            body.print-layout-compact .signature-section { margin-top: 12px; }
            body.print-layout-compact .signature-line { height: 28px; }
            body.print-layout-compact .payment-info, body.print-layout-compact .terms-section { margin: 6px 0; padding: 5px 7px; }
            body.print-layout-compact .footer { margin-top: 6px; padding-top: 4px; }
            body.print-layout-compact .invoice-logo { max-height: 42px; max-width: 130px; }
        }

        body.print-layout-classic,
        body.print-layout-images { font-size: 12px; padding: 15px; }
        body.print-layout-classic .invoice-logo,
        body.print-layout-images .invoice-logo { max-height: 70px; max-width: 200px; display: block; }
        body.print-layout-classic .header,
        body.print-layout-images .header { padding-bottom: 10px; margin-bottom: 15px; }
        body.print-layout-classic .header h1,
        body.print-layout-images .header h1 { font-size: 18px; }
        body.print-layout-classic .header .subtitle,
        body.print-layout-images .header .subtitle { font-size: 14px; margin-top: 5px; }
        body.print-layout-classic .header-text,
        body.print-layout-images .header-text { margin-top: 5px; }
        body.print-layout-classic .company-info,
        body.print-layout-images .company-info { margin-bottom: 15px; }
        body.print-layout-classic .company-info span,
        body.print-layout-images .company-info span { margin: 3px 0; }
        body.print-layout-classic .info-row,
        body.print-layout-images .info-row { gap: 10px; margin-bottom: 15px; padding: 10px; border-radius: 5px; }
        body.print-layout-classic h4.section-title,
        body.print-layout-images h4.section-title { margin: 12px 0 6px; font-size: 13px; }
        body.print-layout-classic table,
        body.print-layout-images table { margin: 15px 0; }
        body.print-layout-classic th, body.print-layout-classic td,
        body.print-layout-images th, body.print-layout-images td { padding: 8px; }
        body.print-layout-classic .col-serial,
        body.print-layout-images .col-serial { font-size: 10px; line-height: 1.35; word-break: break-word; max-width: 150px; }
        body.print-layout-classic .pay-desc,
        body.print-layout-images .pay-desc { word-break: break-word; }
        body.print-layout-classic .totals,
        body.print-layout-images .totals { margin: 15px 0; }
        body.print-layout-classic .totals-table,
        body.print-layout-images .totals-table { width: auto; min-width: 280px; float: left; }
        body.print-layout-classic .totals-table td,
        body.print-layout-images .totals-table td { border: none; padding: 5px 15px; }
        body.print-layout-classic .totals-table tr:last-child,
        body.print-layout-images .totals-table tr:last-child { font-weight: bold; font-size: 14px; background: #e8f4f8; }
        body.print-layout-classic .payment-info,
        body.print-layout-images .payment-info { margin: 20px 0; padding: 15px; border-radius: 5px; }
        body.print-layout-classic .payment-info h4,
        body.print-layout-images .payment-info h4 { margin: 0 0 10px 0; }
        body.print-layout-classic .info-table td,
        body.print-layout-images .info-table td { padding: 4px 10px; }
        body.print-layout-classic .signature-section,
        body.print-layout-images .signature-section { margin-top: 40px; }
        body.print-layout-classic .signature-line,
        body.print-layout-images .signature-line { height: 60px; margin-bottom: 5px; }
        body.print-layout-classic .signature-label,
        body.print-layout-images .signature-label { font-size: 11px; }
        body.print-layout-classic .terms-section,
        body.print-layout-images .terms-section { margin-top: 20px; padding: 10px; border-radius: 5px; font-size: 11px; }
        body.print-layout-classic .terms-section h4,
        body.print-layout-images .terms-section h4 { margin: 0 0 5px 0; font-size: 12px; }
        body.print-layout-classic .footer,
        body.print-layout-images .footer { margin-top: 20px; padding-top: 10px; font-size: 11px; }
        @media print {
            body.print-layout-classic,
            body.print-layout-images { margin: 0; padding: 10px; }
            body.print-layout-images .col-image img {
                width: 56px; height: 56px; -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            .no-print { display: none !important; }
        }
        `;

        let printBaseHref = '';
        try { printBaseHref = new URL('.', window.location.href).href; } catch (e) {}

        return `<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <title>${title} #${invoice.id}</title>
    ${printBaseHref ? `<base href="${escapeHtml(printBaseHref)}">` : ''}
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: Tahoma, Arial, sans-serif; 
            margin: 0; 
            background: #fff;
            color: #333;
        }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 2px solid #333; 
        }
        .header-right { text-align: right; }
        .header-left { text-align: left; }
        .header h1 { margin: 0; color: #333; }
        .header .subtitle { color: #666; }
        .header-text { font-style: italic; color: #666; }
        .company-info span { display: block; }
        .info-row { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            background: #f8f8f8;
        }
        .info-row .item strong { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; text-align: right; }
        th { background-color: #f0f0f0; font-weight: bold; }
        tbody tr:nth-child(even) { background-color: #f9f9f9; }
        tr.pay-reverse { background-color: #fff5f5 !important; }
        tr.pay-reverse td { color: #9b1c1c; }
        .amt-out { color: #b91c1c; font-weight: 600; }
        .amt-in { color: #166534; }
        .totals-table tr.remaining-credit td { background: #ecfdf5; color: #166534; }
        .totals-table tr.remaining-debt td { background: #fef2f2; color: #9b1c1c; }
        .payment-info { 
            background: #f5f5f5; 
            page-break-inside: avoid;
        }
        .payment-info h4 { color: #333; }
        .info-table { width: auto; }
        .info-table td { border: none; }
        .signature-section { 
            display: flex; 
            justify-content: space-between; 
            page-break-inside: avoid;
        }
        .signature-box { text-align: center; width: 40%; }
        .signature-line { border-bottom: 1px solid #999; }
        .signature-label { color: #666; }
        .terms-section { 
            background: #fff8dc; 
            border: 1px solid #ddd; 
            page-break-inside: avoid;
        }
        .footer { 
            text-align: center; 
            border-top: 1px solid #ddd; 
            color: #666; 
        }
        .clearfix { clear: both; }
        ${layoutCss}
        .preview-toolbar {
            position: sticky; top: 0; z-index: 1000;
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            background: #1e293b; color: #fff; padding: 10px 14px;
            margin: -15px -15px 18px -15px; box-shadow: 0 2px 8px rgba(0,0,0,.15);
            font-family: Tahoma, Arial, sans-serif;
        }
        .preview-toolbar .btn {
            border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer;
            font-size: 13px; font-family: inherit; color: #fff;
        }
        .preview-toolbar .btn-print { background: #0d6efd; }
        .preview-toolbar .btn-labels { background: #6f42c1; }
        .preview-toolbar .btn-sms { background: #198754; }
        .preview-toolbar .btn-wa { background: #25d366; }
        .preview-toolbar .btn-copy { background: #6c757d; }
        .preview-toolbar .btn-close-win { background: #dc3545; margin-right: auto; }
        .preview-toolbar .btn:hover { filter: brightness(1.08); }
        .preview-toolbar .hint { font-size: 12px; opacity: .85; }
        .preview-options {
            flex: 1 0 100%; display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center;
            background: #0f172a; border-radius: 8px; padding: 8px 10px; font-size: 12px;
        }
        .preview-options select {
            background: #1e293b; color: #fff; border: 1px solid #334155; border-radius: 6px;
            padding: 4px 8px; font-family: inherit; font-size: 12px;
        }
        .preview-options .opt { display: inline-flex; align-items: center; gap: 4px; margin: 0; cursor: pointer; }
        .preview-options .opt-note { opacity: .75; }
        .sms-panel {
            display: none; width: 100%; background: #0f172a; border-radius: 8px;
            padding: 12px; margin-top: 8px;
        }
        .sms-panel.open { display: block; }
        .sms-panel label { display: block; font-size: 12px; margin-bottom: 4px; opacity: .9; }
        .sms-panel select, .sms-panel textarea {
            width: 100%; border-radius: 6px; border: 1px solid #334155;
            background: #1e293b; color: #fff; padding: 8px; font-family: Tahoma, Arial, sans-serif;
            font-size: 13px; margin-bottom: 8px;
        }
        .sms-panel .sms-grid { display:grid; grid-template-columns:1fr 1fr 2fr; gap:10px; }
        .sms-panel .sms-grid .sms-msg-col { grid-column: span 2; }
        .sms-panel .sms-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .sms-panel .status { font-size: 12px; margin-top: 6px; min-height: 18px; }
        .sms-panel .status.ok { color: #86efac; }
        .sms-panel .status.err { color: #fca5a5; }
        @media (max-width: 768px) {
            .preview-toolbar { flex-direction: column; align-items: stretch; }
            .preview-toolbar .btn { width: 100%; }
            .preview-toolbar .btn-close-win { margin-right: 0; }
            .sms-panel .sms-grid { grid-template-columns: 1fr; }
            .sms-panel .sms-grid .sms-msg-col { grid-column: auto; }
        }
    </style>
</head>
<body class="${bodyOptClasses}">
    ${options.showToolbar ? `
    <div class="preview-toolbar no-print" id="invoice-preview-toolbar">
        <button type="button" class="btn btn-print" onclick="InvoicePreviewUI.doPrint()">🖨 چاپ فاکتور</button>
        <button type="button" class="btn btn-labels" onclick="InvoicePreviewUI.printItemLabels()">🏷 چاپ لیبل اقلام</button>
        <button type="button" class="btn btn-sms" onclick="InvoicePreviewUI.toggleSms()">📱 ارسال پیامک</button>
        <button type="button" class="btn btn-wa" onclick="InvoicePreviewUI.openWhatsApp()">واتساپ</button>
        <button type="button" class="btn btn-copy" onclick="InvoicePreviewUI.copySummary()">کپی خلاصه</button>
        <button type="button" class="btn btn-copy" onclick="InvoicePreviewUI.copySmsText()">کپی متن پیامک</button>
        <span class="hint">پیش‌نمایش فاکتور — قبل از چاپ یا ارسال بازبینی کنید</span>
        <button type="button" class="btn btn-close-win" onclick="InvoicePreviewUI.closePreview()">بستن</button>
        <div class="preview-options">
            <label class="opt">قالب چاپ
                <select id="print-template-select">
                    <option value="classic"${printTemplate === 'classic' ? ' selected' : ''}>کلاسیک</option>
                    <option value="compact"${printTemplate === 'compact' ? ' selected' : ''}>فشرده</option>
                    <option value="images"${printTemplate === 'images' ? ' selected' : ''}>با تصویر</option>
                </select>
            </label>
            <label class="opt"><input type="checkbox" id="opt-serial"${showSerialColumn ? ' checked' : ''}> شناسه انبار</label>
            <label class="opt"><input type="checkbox" id="opt-meter"${showMeterColumn ? ' checked' : ''}> متری</label>
            <label class="opt"><input type="checkbox" id="opt-logo"${settings.show_logo ? ' checked' : ''}> لوگو</label>
            <label class="opt"><input type="checkbox" id="opt-company"${settings.show_company_info ? ' checked' : ''}> اطلاعات فروشگاه</label>
            <label class="opt"><input type="checkbox" id="opt-pay"${settings.show_payment_info ? ' checked' : ''}> اطلاعات پرداخت</label>
            <label class="opt"><input type="checkbox" id="opt-sign"${settings.show_signature_line ? ' checked' : ''}> امضا</label>
            <label class="opt"><input type="checkbox" id="opt-pay-desc"${showPayDesc ? ' checked' : ''}> توضیحات پرداخت</label>
            <span class="opt-note">فقط همین پیش‌نمایش</span>
        </div>
        <div class="sms-panel" id="sms-panel">
            <div class="sms-grid">
                <div>
                    <label>قالب پیامک</label>
                    <select id="sms-template-key">
                        <option value="sales">فاکتور فروش</option>
                        <option value="ready">آماده تحویل</option>
                    </select>
                    <label>شماره گیرنده</label>
                    <select id="sms-phone-select"></select>
                    <label>یا شماره دستی</label>
                    <input type="text" id="sms-phone-manual" placeholder="0912xxxxxxx" dir="ltr"
                        style="width:100%;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#fff;padding:8px;margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                        <input type="checkbox" id="sms-include-link" checked> درج لینک فاکتور
                    </label>
                </div>
                <div class="sms-msg-col">
                    <label>متن پیامک (قبل از ارسال قابل ویرایش)</label>
                    <textarea id="sms-message-text" rows="6"></textarea>
                    <div class="form-text" id="sms-mode-hint" style="opacity:.9;font-size:11px;margin-top:4px;"></div>
                    <div class="form-text" id="sms-link-hint" style="opacity:.8;font-size:11px;margin-top:4px;" dir="ltr"></div>
                </div>
            </div>
            <div class="sms-actions">
                <button type="button" class="btn btn-sms" onclick="InvoicePreviewUI.sendSms()">تأیید و ارسال پیامک</button>
                <button type="button" class="btn btn-copy" onclick="InvoicePreviewUI.reloadSmsPreview()">بازخوانی قالب</button>
            </div>
            <div class="status" id="sms-status"></div>
        </div>
    </div>
    <script>
    window.__PRINT_INVOICE__ = ${JSON.stringify(invoice).replace(/</g, '\\u003c')};
    window.__PRINT_SETTINGS__ = ${JSON.stringify(Object.assign({}, settings, { show_serial_column: showSerialColumn, show_meter_column: showMeterColumn, print_template: printTemplate })).replace(/</g, '\\u003c')};
    window.__PRINT_TYPE__ = ${JSON.stringify(type)};
    window.__SHOW_PAY_DESC__ = ${showPayDesc ? 'true' : 'false'};
    window.__INVOICE_META__ = ${JSON.stringify({
        invoice_id: invoice.id || null,
        invoice_type: type,
        customer_name: invoice.customerName || invoice.supplierName || '',
        customer_phone: invoice.customerPhone || invoice.phone || '',
        invoice_number: invoice.invoice_number || invoice.id || '',
        date: invoice.date || '',
        totalAmount: invoice.totalAmount || 0,
        discount: invoice.discount || 0,
        tax_amount: invoice.tax_amount || 0,
        paidAmount: paidAmount,
        grand_total: grandTotal,
        remaining: remainingAmount,
        serialized_ids: (function () {
            const ids = [];
            (invoice.items || []).forEach(function (item) {
                if (item.is_service == 1) return;
                (item.serialized_ids || []).forEach(function (sid) {
                    const n = parseInt(sid, 10);
                    if (n > 0) ids.push(n);
                });
                (item.returned_ids || []).forEach(function (sid) {
                    const n = parseInt(sid, 10);
                    if (n > 0) ids.push(n);
                });
            });
            return Array.from(new Set(ids));
        })()
    })};
    window.__SMS_API__ = ${JSON.stringify(options.smsApi || {})};
    const InvoicePreviewUI = {
        setStatus(msg, ok) {
            const el = document.getElementById('sms-status');
            if (!el) return;
            el.textContent = msg || '';
            el.className = 'status ' + (ok === true ? 'ok' : ok === false ? 'err' : '');
        },
        closePreview() {
            try {
                if (window.frameElement && window.parent && window.parent !== window) {
                    const host = window.parent.document.getElementById('invoice-preview-overlay');
                    if (host) host.remove();
                    return;
                }
            } catch (e) {}
            window.close();
        },
        hostWindow() {
            const pick = function (win) {
                try {
                    return win && win.InvoicePrintHost && typeof win.InvoicePrintHost.generateInvoicePrintHtml === 'function' ? win : null;
                } catch (e) { return null; }
            };
            return pick(window.opener) || pick(window.parent !== window ? window.parent : null);
        },
        bridge() {
            if (window.InvoiceSmsBridge) return window.InvoiceSmsBridge;
            const host = this.hostWindow();
            return host ? host.InvoiceSmsBridge : null;
        },
        labelsBridge() {
            if (window.InvoiceLabelsBridge || window.BarcodeManager) {
                return window.InvoiceLabelsBridge || window.BarcodeManager;
            }
            const host = this.hostWindow();
            if (!host) return null;
            return host.InvoiceLabelsBridge || host.BarcodeManager || null;
        },
        applyPrintOptions() {
            const body = document.body;
            if (!body) return;
            const wantDesc = !!document.getElementById('opt-pay-desc')?.checked;
            // Rebuild when description column visibility changes (avoid display:none on <td>).
            if (wantDesc !== !!window.__SHOW_PAY_DESC__) {
                this.regeneratePrint({ showPayDesc: wantDesc });
                return;
            }
            const tplRaw = document.getElementById('print-template-select')?.value || 'classic';
            const tpl = (tplRaw === 'compact' || tplRaw === 'images') ? tplRaw : 'classic';
            body.classList.remove('print-layout-classic', 'print-layout-compact', 'print-layout-images');
            body.classList.add('print-layout-' + tpl);
            body.classList.toggle('hide-serial', !document.getElementById('opt-serial')?.checked);
            body.classList.toggle('hide-meter', !document.getElementById('opt-meter')?.checked);
            body.classList.toggle('hide-logo', !document.getElementById('opt-logo')?.checked);
            body.classList.toggle('hide-company', !document.getElementById('opt-company')?.checked);
            body.classList.toggle('hide-pay', !document.getElementById('opt-pay')?.checked);
            body.classList.toggle('hide-sign', !document.getElementById('opt-sign')?.checked);
        },
        currentPrintSettings() {
            const settings = Object.assign({}, window.__PRINT_SETTINGS__ || {});
            settings.show_serial_column = !!document.getElementById('opt-serial')?.checked;
            settings.show_meter_column = !!document.getElementById('opt-meter')?.checked;
            settings.show_logo = !!document.getElementById('opt-logo')?.checked;
            settings.show_company_info = !!document.getElementById('opt-company')?.checked;
            settings.show_payment_info = !!document.getElementById('opt-pay')?.checked;
            settings.show_signature_line = !!document.getElementById('opt-sign')?.checked;
            const tplRaw = document.getElementById('print-template-select')?.value || 'classic';
            settings.print_template = (tplRaw === 'compact' || tplRaw === 'images') ? tplRaw : 'classic';
            return settings;
        },
        regeneratePrint(extra) {
            const host = this.hostWindow();
            if (!host || !host.InvoicePrintHost) return;
            const inv = window.__PRINT_INVOICE__;
            if (!inv) return;
            const settings = this.currentPrintSettings();
            const showPayDesc = extra && Object.prototype.hasOwnProperty.call(extra, 'showPayDesc')
                ? !!extra.showPayDesc
                : !!document.getElementById('opt-pay-desc')?.checked;
            const type = window.__PRINT_TYPE__ || 'sales';
            const html = host.InvoicePrintHost.generateInvoicePrintHtml(inv, settings, type, {
                showToolbar: true,
                showPayDesc: showPayDesc,
                smsApi: window.__SMS_API__ || {}
            });
            document.open();
            document.write(html);
            document.close();
            try {
                if (host.InvoiceSmsBridge) window.InvoiceSmsBridge = host.InvoiceSmsBridge;
                if (host.InvoiceLabelsBridge) window.InvoiceLabelsBridge = host.InvoiceLabelsBridge;
                if (host.BarcodeManager) window.BarcodeManager = host.BarcodeManager;
            } catch (e) {}
        },
        /**
         * Print via a clean hidden iframe (no toolbar / no embedded JSON).
         * Calling window.print() on the about:blank preview window hangs Chrome
         * print preview ("Loading preview...") on many setups.
         */
        doPrint() {
            if (this._printing) return;
            const host = this.hostWindow();
            const inv = window.__PRINT_INVOICE__;
            const gen = host && host.InvoicePrintHost
                ? host.InvoicePrintHost.generateInvoicePrintHtml.bind(host.InvoicePrintHost)
                : null;
            if (!gen || !inv) {
                window.print();
                return;
            }
            const settings = this.currentPrintSettings();
            const showPayDesc = !!document.getElementById('opt-pay-desc')?.checked;
            const type = window.__PRINT_TYPE__ || 'sales';
            let html;
            try {
                html = gen(inv, settings, type, {
                    showToolbar: false,
                    showPayDesc: showPayDesc,
                    smsApi: {}
                });
            } catch (e) {
                console.error('invoice print html failed', e);
                window.print();
                return;
            }

            this._printing = true;
            const prev = document.getElementById('invoice-silent-print-frame');
            if (prev) prev.remove();
            const frame = document.createElement('iframe');
            frame.id = 'invoice-silent-print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;pointer-events:none;';
            document.body.appendChild(frame);

            const doc = frame.contentDocument || frame.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();

            let triggered = false;
            const finish = () => {
                this._printing = false;
                try { frame.remove(); } catch (e) {}
            };
            const trigger = () => {
                if (triggered) return;
                triggered = true;
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    try { window.print(); } catch (e2) {}
                }
                // Keep frame briefly so the print dialog can read layout, then remove.
                setTimeout(finish, 1500);
            };

            const waitImagesThenPrint = () => {
                const imgs = Array.prototype.slice.call(doc.images || []);
                if (!imgs.length) {
                    setTimeout(trigger, 30);
                    return;
                }
                let pending = imgs.length;
                const tick = () => {
                    pending -= 1;
                    if (pending <= 0) trigger();
                };
                imgs.forEach((img) => {
                    if (img.complete) tick();
                    else {
                        img.addEventListener('load', tick, { once: true });
                        img.addEventListener('error', tick, { once: true });
                    }
                });
                // Safety: never hang forever if an image stalls.
                setTimeout(trigger, 2500);
            };

            // Allow the iframe document to settle one frame before measuring images.
            setTimeout(waitImagesThenPrint, 0);
        },
        async apiCall(kind, meta) {
            const b = this.bridge();
            if (b) {
                return kind === 'send' ? b.send(meta) : b.preview(meta);
            }
            const cfg = window.__SMS_API__ || {};
            if (!cfg.url || !cfg.csrf) {
                throw new Error('پنجره اصلی برنامه در دسترس نیست. صفحه اصلی را باز نگه دارید و دوباره تلاش کنید.');
            }
            const action = kind === 'send' ? 'send_invoice_sms' : 'preview_invoice_sms';
            const payload = kind === 'send' ? {
                invoice_id: meta.invoice_id,
                invoice_type: 'sales',
                receptor: meta.receptor,
                message: meta.message,
                customer_name: meta.customer_name,
                customer_phone: meta.customer_phone,
                template_key: meta.template_key || 'sales',
                include_invoice_link: !!meta.include_invoice_link,
                user_confirmed: true
            } : {
                invoice_id: meta.invoice_id,
                invoice_type: 'sales',
                customer_name: meta.customer_name,
                customer_phone: meta.customer_phone,
                template_key: meta.template_key || 'sales',
                include_invoice_link: meta.include_invoice_link !== false
            };
            const response = await fetch(cfg.url + '?action=' + action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrf
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(function () { return { error: 'پاسخ سرور نامعتبر است.' }; });
            if (data && data.csrf_token) {
                cfg.csrf = data.csrf_token;
                window.__SMS_API__.csrf = data.csrf_token;
            }
            if (!response.ok) {
                throw new Error((data && data.error) || ('خطای سرور: ' + response.status));
            }
            if (data && data.error) throw new Error(data.error);
            return data;
        },
        printItemLabels() {
            const meta = window.__INVOICE_META__ || {};
            const ids = meta.serialized_ids || [];
            if (!ids.length) {
                alert('برای اقلام این فاکتور شناسه سریال/انبار ثبت نشده است. لیبل فقط برای اقلام دارای سریال قابل چاپ است.');
                return;
            }
            const bridge = this.labelsBridge();
            if (!bridge || typeof (bridge.printBatch || bridge.printInvoiceItemLabels) !== 'function') {
                alert('پنجره اصلی برنامه در دسترس نیست. صفحه اصلی را باز نگه دارید و دوباره تلاش کنید.');
                return;
            }
            if (typeof bridge.printInvoiceItemLabels === 'function') {
                bridge.printInvoiceItemLabels(ids);
            } else {
                bridge.printBatch(ids);
            }
        },
        toggleSms() {
            const panel = document.getElementById('sms-panel');
            const meta = window.__INVOICE_META__ || {};
            if (meta.invoice_type === 'purchase') {
                alert('ارسال پیامک فقط برای فاکتور فروش (مشتری) فعال است.');
                return;
            }
            panel.classList.toggle('open');
            if (panel.classList.contains('open')) {
                try { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
                this.reloadSmsPreview();
            }
        },
        fillPhones(phones) {
            const sel = document.getElementById('sms-phone-select');
            sel.innerHTML = '';
            (phones || []).forEach(function(p, i) {
                const opt = document.createElement('option');
                opt.value = p; opt.textContent = p;
                if (i === 0) opt.selected = true;
                sel.appendChild(opt);
            });
            if (!phones || !phones.length) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = 'شماره‌ای یافت نشد — دستی وارد کنید';
                sel.appendChild(opt);
            }
        },
        async reloadSmsPreview() {
            this.setStatus('در حال آماده‌سازی متن…');
            try {
                const res = await this.apiCall('preview', Object.assign({}, window.__INVOICE_META__, {
                    template_key: document.getElementById('sms-template-key')?.value || 'sales',
                    include_invoice_link: !!document.getElementById('sms-include-link')?.checked
                }));
                if (!res || res.error) { this.setStatus((res && res.error) || 'خطا در پیش‌نمایش', false); return; }
                const msgEl = document.getElementById('sms-message-text');
                const modeHint = document.getElementById('sms-mode-hint');
                const isVerify = res.mode === 'verify';
                msgEl.value = res.message || '';
                msgEl.readOnly = isVerify;
                if (modeHint) {
                    modeHint.textContent = isVerify
                        ? ('حالت ارسال: اعتبارسنجی کاوه‌نگار — الگوی پنل: ' + (res.verify_template || '-') + ' (ویرایش متن در این حالت روی متن واقعی ارسالی اثر ندارد؛ متن واقعی همان الگوی تأییدشده پنل است.)')
                        : 'حالت ارسال: ساده (متن آزاد با خط Sender)';
                }
                this.fillPhones(res.phones || []);
                const hint = document.getElementById('sms-link-hint');
                if (hint) hint.textContent = res.invoice_link ? ('لینک: ' + res.invoice_link) : '';
                if (!res.sms_enabled) this.setStatus('ارسال پیامک در تنظیمات غیرفعال است.', false);
                else if (!res.configured) this.setStatus('کلید API پیامک تنظیم نشده است.', false);
                else this.setStatus(isVerify
                    ? 'آماده ارسال با الگوی اعتبارسنجی. پس از بازبینی، ارسال را تأیید کنید.'
                    : 'متن آماده است. پس از بازبینی، ارسال را تأیید کنید.', true);
            } catch (e) {
                this.setStatus(e.message || 'خطا', false);
            }
        },
        async sendSms() {
            const manual = (document.getElementById('sms-phone-manual').value || '').trim();
            const selected = document.getElementById('sms-phone-select').value || '';
            const receptor = manual || selected;
            const message = document.getElementById('sms-message-text').value || '';
            if (!receptor) { this.setStatus('شماره گیرنده را وارد کنید.', false); return; }
            if (!confirm('تأیید نهایی: پیامک به شماره ' + receptor + ' ارسال شود؟')) return;
            this.setStatus('در حال ارسال…');
            try {
                const res = await this.apiCall('send', Object.assign({}, window.__INVOICE_META__, {
                    receptor: receptor,
                    message: message,
                    template_key: document.getElementById('sms-template-key')?.value || 'sales',
                    include_invoice_link: !!document.getElementById('sms-include-link')?.checked,
                    user_confirmed: true
                }));
                if (!res || res.error) { this.setStatus((res && res.error) || 'ارسال ناموفق', false); return; }
                this.setStatus(res.message || 'ارسال شد.', true);
            } catch (e) {
                this.setStatus(e.message || 'خطا در ارسال', false);
            }
        },
        async copySmsText() {
            const text = document.getElementById('sms-message-text')?.value
                || (await (async () => {
                    try {
                        const res = await this.apiCall('preview', window.__INVOICE_META__);
                        return (res && res.message) || '';
                    } catch (e) { return ''; }
                })());
            if (!text) { alert('متنی برای کپی نیست.'); return; }
            try {
                await navigator.clipboard.writeText(text);
                this.setStatus('متن پیامک کپی شد.', true);
            } catch (e) {
                prompt('متن را کپی کنید:', text);
            }
        },
        copySummary() {
            const m = window.__INVOICE_META__ || {};
            const text = [
                (m.invoice_type === 'purchase' ? 'فاکتور خرید' : 'فاکتور فروش') + ' #' + (m.invoice_number || ''),
                'طرف حساب: ' + (m.customer_name || '-'),
                'تاریخ: ' + (m.date || '-'),
                'مبلغ قابل پرداخت: ' + (m.grand_total || 0),
                'مانده: ' + (m.remaining || 0),
                'تلفن: ' + (m.customer_phone || '-')
            ].join('\\n');
            navigator.clipboard.writeText(text).then(() => alert('خلاصه فاکتور کپی شد.')).catch(() => prompt('کپی کنید:', text));
        },
        openWhatsApp() {
            const m = window.__INVOICE_META__ || {};
            let phone = (document.getElementById('sms-phone-manual')?.value || document.getElementById('sms-phone-select')?.value || m.customer_phone || '').split(new RegExp('[,/،;\\\\]'))[0].trim();
            phone = phone.replace(new RegExp('[^0-9+]', 'g'), '');
            if (phone.indexOf('09') === 0) phone = '98' + phone.slice(1);
            else if (phone.indexOf('+98') === 0) phone = phone.slice(1);
            const msg = document.getElementById('sms-message-text')?.value || '';
            const url = phone
                ? 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg)
                : 'https://wa.me/?text=' + encodeURIComponent(msg || 'فاکتور');
            window.open(url, '_blank');
        }
    };
    window.InvoicePreviewUI = InvoicePreviewUI;
    document.getElementById('sms-template-key')?.addEventListener('change', function(){ InvoicePreviewUI.reloadSmsPreview(); });
    document.getElementById('sms-include-link')?.addEventListener('change', function(){ InvoicePreviewUI.reloadSmsPreview(); });
    ['print-template-select', 'opt-serial', 'opt-meter', 'opt-logo', 'opt-company', 'opt-pay', 'opt-sign', 'opt-pay-desc'].forEach(function (id) {
        document.getElementById(id)?.addEventListener('change', function () { InvoicePreviewUI.applyPrintOptions(); });
    });
    </script>
    ` : ''}
    <div class="header">
        <div class="header-right">
            ${logoHtml}
            <h1>${escapeHtml(settings.company_name) || title}</h1>
            <div class="subtitle">${title}</div>
            ${settings.header_text ? `<div class="header-text">${escapeHtml(settings.header_text)}</div>` : ''}
        </div>
        <div class="header-left">
            <div><strong>شماره فاکتور:</strong> ${invoice.invoice_number || invoice.id}</div>
            <div><strong>تاریخ:</strong> ${invoiceDate}</div>
            ${dueDate ? `<div><strong>سررسید:</strong> ${dueDate}</div>` : ''}
            ${paymentTermsText ? `<div><strong>شرایط پرداخت:</strong> ${paymentTermsText}</div>` : ''}
        </div>
    </div>
    
    ${companyInfoHtml}
    
    <div class="info-row">
        <div class="item"><strong>${personTitle}:</strong> ${escapeHtml(invoice.customerName || invoice.supplierName || '-')}</div>
        <div class="item"><strong>تلفن:</strong> ${escapeHtml(invoice.customerPhone || invoice.phone || '-')}</div>
        <div class="item"><strong>آدرس:</strong> ${escapeHtml(invoice.customerAddress || invoice.address || '-')}</div>
    </div>
    
    <h4 class="section-title">اقلام فاکتور</h4>
    <table>
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th class="col-image" style="width:72px">تصویر</th>
                <th>شرح کالا</th>
                <th style="width:80px">ابعاد</th>
                <th class="col-serial" style="width:120px">شناسه انبار</th>
                <th style="width:60px">تعداد</th>
                <th style="width:100px">قیمت واحد</th>
                <th class="col-meter" style="width:100px">قیمت متری</th>
                <th style="width:100px">مبلغ کل</th>
            </tr>
        </thead>
        <tbody>${itemsHtml}</tbody>
    </table>
    
    ${bottomHtml}
    
    ${paymentInfoHtml}
    ${termsHtml}
    ${signatureHtml}
    
    ${settings.footer_text ? `<div class="footer">${escapeHtml(settings.footer_text)}</div>` : ''}
</body>
</html>`;
    };

    /**
     * Printable physical count sheet for stocktaking (blank columns for handwriting).
     * Column «شناسه انبار» = inventory_items.serial_id (e.g. INV-001266), NOT warehouse_id.
     * blankMode: empty rows so staff can write products that are not in the system.
     */
    const generateStocktakingCountSheetHtml = (meta, items, settings, options = {}) => {
        options = Object.assign({ showToolbar: true, showWarehouseCol: true, blankMode: false, blankRowCount: 20 }, options);
        const blankMode = !!options.blankMode;
        const blankRowCount = Math.min(500, Math.max(1, parseInt(options.blankRowCount, 10) || 20));
        const title = meta.title || (blankMode ? 'فرم خام شمارش فیزیکی' : 'انبارگردانی');
        const sessionDate = meta.date ? gregorianToPersian(meta.date) : '-';
        const warehouseLabel = meta.warehouseLabel || 'همه انبارها';
        const sessionId = meta.sessionId ? String(meta.sessionId) : '-';
        const printedAt = new Date().toLocaleString('fa-IR');
        const showWarehouseCol = !!options.showWarehouseCol;
        const docTitle = blankMode
            ? 'فرم خام شمارش فیزیکی انبار (اقلام خارج از سیستم)'
            : 'برگه شمارش فیزیکی انبار (انبارگردانی)';
        const instructions = blankMode
            ? `<strong>راهنمای فرم خام:</strong>
        این برگه دادهٔ سیستم ندارد. محصولاتی که در نرم‌افزار ثبت نشده‌اند را با دست بنویسید
        (نام محصول، کد طرح، رنگ، ابعاد، شناسه در صورت وجود، تعداد شمارش‌شده).
        پس از اتمام شمارش، در «انبارگردانی» با دکمه «افزودن ردیف» همان اقلام را وارد سیستم کنید.`
            : `<strong>راهنمای شمارش:</strong>
        ستون «موجودی سیستم» مبنای نرم‌افزار است. در ستون «شمارش فیزیکی» تعداد واقعی روی انبار را با دست بنویسید.
        پس از اتمام شمارش، به سیستم برگردید، همان ردیف‌ها را بارگذاری کنید، اعداد واقعی را وارد کنید و اختلاف‌ها را ثبت یا اعمال کنید.`;

        let rowsHtml = '';
        let totalSystem = 0;
        const writeCell = '<td class="col-write"></td>';
        if (blankMode) {
            for (let idx = 0; idx < blankRowCount; idx++) {
                rowsHtml += `<tr class="blank-row">
                    <td class="col-num">${idx + 1}</td>
                    <td class="col-write col-product"></td>
                    ${writeCell}
                    ${writeCell}
                    ${writeCell}
                    ${writeCell}
                    ${showWarehouseCol ? writeCell : ''}
                    ${writeCell}
                    ${writeCell}
                    <td class="col-write-wide"></td>
                </tr>`;
            }
        } else {
            (items || []).forEach((item, idx) => {
                const qty = parseInt(item.system_qty, 10) || 0;
                totalSystem += qty;
                const productName = item.product_name || item.name || '-';
                const designCode = item.design_code || '-';
                const colorway = item.colorway || '-';
                // شناسه انبار = inventory_items.serial_id (مثل INV-001266) — نه warehouse_id و نه product_id
                const serialIds = item.serial_ids || item.serial_id || '';
                rowsHtml += `<tr>
                    <td class="col-num">${idx + 1}</td>
                    <td>${escapeHtml(productName)}</td>
                    <td>${escapeHtml(designCode)}</td>
                    <td>${escapeHtml(colorway)}</td>
                    <td>${escapeHtml(item.dimensions || '-')}</td>
                    <td class="col-serial">${escapeHtml(serialIds || '-')}</td>
                    ${showWarehouseCol ? `<td>${escapeHtml(item.warehouse_name || 'بدون انبار')}</td>` : ''}
                    <td class="col-qty">${formatNumber(qty)}</td>
                    <td class="col-write"></td>
                    <td class="col-write-wide"></td>
                </tr>`;
            });
        }

        const logoHtml = settings.show_logo && settings.logo_url
            ? `<img src="${settings.logo_url}" alt="لوگو" style="max-height: 64px; max-width: 180px;">`
            : '';

        const toolbarHint = blankMode
            ? 'فرم خام — محصولاتی که در سیستم نیستند را با دست بنویسید و بعداً وارد کنید.'
            : 'برگه شمارش فیزیکی — پس از چاپ، شمارش را روی کاغذ انجام دهید و در سیستم ثبت کنید.';
        const toolbarHtml = options.showToolbar ? `
        <div class="preview-toolbar no-print">
            <span class="hint">${toolbarHint}</span>
            <button type="button" class="btn btn-print" onclick="window.print()">🖨 چاپ برگه</button>
            <button type="button" class="btn btn-close-win" onclick="window.close()">بستن</button>
        </div>` : '';
        const rowCount = blankMode ? blankRowCount : (items || []).length;
        const summaryHtml = blankMode
            ? `<div class="summary-box">
        <div><strong>نوع برگه:</strong> فرم خام (بدون داده سیستم)</div>
        <div><strong>تعداد ردیف خالی:</strong> ${formatNumber(blankRowCount)}</div>
    </div>`
            : `<div class="summary-box">
        <div><strong>جمع موجودی سیستم:</strong> ${formatNumber(totalSystem)}</div>
        <div><strong>تعداد اقلام:</strong> ${formatNumber(rowCount)}</div>
    </div>`;

        return `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>${blankMode ? 'فرم خام شمارش فیزیکی' : 'برگه شمارش فیزیکی'} — ${escapeHtml(title)}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Tahoma, Arial, sans-serif; font-size: 12px; color: #222; margin: 0; padding: 15px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #333; padding-bottom: 12px; margin-bottom: 12px; }
        .header h1 { margin: 0 0 4px 0; font-size: 20px; }
        .header .doc-title { font-size: 15px; color: #444; margin-bottom: 6px; }
        .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 20px; margin-bottom: 12px; }
        .meta-grid div strong { color: #555; }
        .instructions {
            background: #fff8dc; border: 1px solid #e0c97a; border-radius: 6px;
            padding: 10px 12px; margin-bottom: 14px; font-size: 11px; line-height: 1.7;
        }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #bbb; padding: 6px 8px; text-align: right; vertical-align: middle; }
        th { background: #eef2f6; font-weight: bold; font-size: 11px; }
        tbody tr:nth-child(even) { background: #fafafa; }
        .col-num { width: 36px; text-align: center; }
        .col-qty { width: 70px; text-align: center; font-weight: 600; }
        .col-serial { font-size: 10px; max-width: 160px; word-break: break-word; }
        .col-write { min-width: 72px; min-height: 28px; background: #fff; }
        .col-write-wide { min-width: 120px; min-height: 28px; background: #fff; }
        .blank-row td { height: 38px; }
        .blank-row .col-product { min-width: 170px; }
        .summary-box {
            margin-top: 12px; padding: 10px; background: #f5f5f5; border-radius: 6px;
            display: flex; flex-wrap: wrap; gap: 16px; font-size: 12px;
        }
        .signature-section {
            display: flex; justify-content: space-between; gap: 20px;
            margin-top: 28px; page-break-inside: avoid;
        }
        .signature-box { flex: 1; text-align: center; }
        .signature-line { border-bottom: 1px solid #888; height: 48px; margin-bottom: 4px; }
        .signature-label { font-size: 11px; color: #666; }
        .footer { text-align: center; margin-top: 16px; font-size: 10px; color: #777; border-top: 1px solid #ddd; padding-top: 8px; }
        .preview-toolbar {
            position: sticky; top: 0; z-index: 1000;
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            background: #1e293b; color: #fff; padding: 10px 14px;
            margin: -15px -15px 18px -15px; box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }
        .preview-toolbar .btn {
            border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer;
            font-size: 13px; color: #fff;
        }
        .preview-toolbar .btn-print { background: #0d6efd; }
        .preview-toolbar .btn-close-win { background: #dc3545; margin-right: auto; }
        @media print {
            body { padding: 8px; }
            .no-print { display: none !important; }
            .col-write, .col-write-wide { min-height: 32px; }
            .blank-row td { height: 40px; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    ${toolbarHtml}
    <div class="header">
        <div>
            ${logoHtml}
            <h1>${escapeHtml(settings.company_name) || 'سیستم حسابداری'}</h1>
            <div class="doc-title">${docTitle}</div>
            ${settings.header_text ? `<div style="font-size:11px;color:#666;margin-top:4px">${escapeHtml(settings.header_text)}</div>` : ''}
        </div>
        <div style="font-size:12px;text-align:left">
            <div><strong>تاریخ چاپ:</strong> ${escapeHtml(printedAt)}</div>
            ${blankMode ? '<div><strong>نوع:</strong> فرم خام</div>' : `<div><strong>شناسه سشن:</strong> ${escapeHtml(sessionId)}</div>`}
        </div>
    </div>

    <div class="meta-grid">
        <div><strong>عنوان:</strong> ${escapeHtml(title)}</div>
        <div><strong>تاریخ انبارگردانی:</strong> ${escapeHtml(sessionDate)}</div>
        <div><strong>انبار:</strong> ${escapeHtml(warehouseLabel)}</div>
        <div><strong>تعداد ردیف:</strong> ${formatNumber(rowCount)}</div>
    </div>

    ${meta.notes ? `<div style="margin-bottom:10px"><strong>توضیحات:</strong> ${escapeHtml(meta.notes)}</div>` : ''}

    <div class="instructions">
        ${instructions}
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>محصول</th>
                <th>کد طرح</th>
                <th>رنگ</th>
                <th>ابعاد</th>
                <th>شناسه انبار</th>
                ${showWarehouseCol ? '<th>انبار</th>' : ''}
                <th>موجودی سیستم</th>
                <th>شمارش فیزیکی</th>
                <th>یادداشت</th>
            </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
    </table>

    ${summaryHtml}

    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">شمارش‌کننده</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">مسئول انبار</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div class="signature-label">تأیید مدیر</div>
        </div>
    </div>

    ${settings.footer_text ? `<div class="footer">${escapeHtml(settings.footer_text)}</div>` : ''}
</body>
</html>`;
    };

    const printStocktakingCountSheet = (meta, items, options = {}) => {
        const blankMode = !!options.blankMode;
        if (!blankMode && (!items || items.length === 0)) {
            return false;
        }
        const settings = getPrintSettings();
        const showWarehouseCol = options.showWarehouseCol !== undefined
            ? options.showWarehouseCol
            : (!blankMode && items.some((item) => item.warehouse_name && parseInt(item.warehouse_id, 10) > 0));
        const html = generateStocktakingCountSheetHtml(meta, items || [], settings, {
            showToolbar: options.showToolbar !== false,
            showWarehouseCol,
            blankMode,
            blankRowCount: options.blankRowCount
        });

        const printWin = window.open('', '_blank', 'width=960,height=720');
        if (!printWin) {
            return false;
        }
        printWin.document.write(html);
        printWin.document.close();
        printWin.focus();
        if (options.autoPrint === true) {
            setTimeout(() => { printWin.print(); }, 300);
        }
        return true;
    };

    /**
     * Blank printable warehouse in/out form (handwritten on paper, entered later).
     * Columns: محصول | رنگ | ابعاد | شناسه انبار | تعداد | تاریخ | یادداشت
     */
    const generateWarehouseInOutSheetHtml = (meta, settings, options = {}) => {
        options = Object.assign({ showToolbar: true, blankRowCount: 15 }, options);
        const blankRowCount = Math.min(500, Math.max(1, parseInt(options.blankRowCount, 10) || 15));

        let rowsHtml = '';
        for (let idx = 0; idx < blankRowCount; idx++) {
            rowsHtml += `<tr>
                <td class="col-num">${idx + 1}</td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>`;
        }

        const logoHtml = settings.show_logo && settings.logo_url
            ? `<img class="logo" src="${settings.logo_url}" alt="لوگو">`
            : '';

        const toolbarHtml = options.showToolbar ? `
        <div class="preview-toolbar no-print">
            <span class="hint">فرم ورود/خروج — روی کاغذ پر کنید و بعد در سیستم ثبت کنید.</span>
            <button type="button" class="btn btn-print" onclick="window.print()">🖨 چاپ برگه</button>
            <button type="button" class="btn btn-close-win" onclick="window.close()">بستن</button>
        </div>` : '';

        return `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فرم ورود / خروج انبار</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Tahoma, "Segoe UI", Arial, sans-serif;
            font-size: 12px; color: #1a1a1a;
            margin: 0; padding: 14mm 12mm;
            background: #fff;
        }
        .sheet { max-width: 210mm; margin: 0 auto; }

        .brand {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 10px;
        }
        .brand-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .logo { max-height: 52px; max-width: 120px; object-fit: contain; }
        .brand h1 { margin: 0; font-size: 18px; font-weight: 700; line-height: 1.3; }
        .doc-title {
            margin: 0; font-size: 15px; font-weight: 700;
            letter-spacing: -0.2px; color: #111;
        }
        .doc-sub { margin: 2px 0 0; font-size: 11px; color: #666; }

        .top-bar {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1.1fr;
            border: 1.5px solid #222;
            margin-bottom: 8px;
        }
        .top-cell {
            display: flex; align-items: stretch; min-height: 42px;
            border-left: 1px solid #ccc;
        }
        .top-cell:last-child { border-left: 0; }
        .top-label {
            flex: 0 0 auto; padding: 8px 10px;
            background: #f0f0f0; border-left: 1px solid #ccc;
            font-weight: 700; font-size: 12px; display: flex; align-items: center;
            white-space: nowrap;
        }
        .top-write { flex: 1; min-width: 80px; padding: 6px 10px; }
        .type-opts {
            flex: 1; display: flex; align-items: center; justify-content: space-around;
            gap: 8px; padding: 6px 10px; font-weight: 700; font-size: 13px;
        }
        .type-opt { display: flex; align-items: center; gap: 8px; }
        .tick {
            width: 18px; height: 18px; border: 1.5px solid #222;
            background: #fff; flex: 0 0 18px;
        }

        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1.5px solid #222; border-top: 0;
            margin-bottom: 10px;
        }
        .meta-cell {
            display: flex; align-items: stretch; min-height: 38px;
            border-top: 1px solid #ccc; border-left: 1px solid #ccc;
        }
        .meta-cell:nth-child(2n) { border-left: 0; }
        .meta-cell.full { grid-column: 1 / -1; border-left: 0; }
        .meta-label {
            flex: 0 0 110px; padding: 8px 10px;
            background: #f5f5f5; border-left: 1px solid #ccc;
            font-weight: 700; font-size: 11px; display: flex; align-items: center;
        }
        .meta-write { flex: 1; min-width: 0; padding: 6px 10px; }

        table.items {
            width: 100%; border-collapse: collapse;
            border: 1.5px solid #222; margin: 0 0 14px;
        }
        table.items th, table.items td {
            border: 1px solid #999; padding: 0 6px;
            text-align: right; vertical-align: middle;
        }
        table.items th {
            background: #e8e8e8; font-weight: 700; font-size: 11px;
            padding: 7px 6px; border-bottom: 1.5px solid #222;
        }
        table.items tbody td { height: 34px; }
        .col-num { width: 26px; text-align: center !important; color: #555; font-size: 11px; }
        .col-product { width: 22%; }
        .col-color { width: 10%; }
        .col-dims { width: 10%; }
        .col-serial { width: 14%; }
        .col-qty { width: 7%; }
        .col-date { width: 11%; }
        .col-note { width: 16%; }

        .signs {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 10px; page-break-inside: avoid;
        }
        .sign {
            border: 1px solid #888; min-height: 72px;
            padding: 8px 10px; text-align: center;
        }
        .sign-label { font-size: 11px; font-weight: 700; color: #333; margin-bottom: 36px; }
        .sign-line { border-top: 1px solid #aaa; margin: 0 12px; padding-top: 4px; font-size: 10px; color: #777; }

        .footer {
            text-align: center; margin-top: 12px; font-size: 10px; color: #777;
            border-top: 1px solid #ddd; padding-top: 6px;
        }

        .preview-toolbar {
            position: sticky; top: 0; z-index: 1000;
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            background: #1e293b; color: #fff; padding: 10px 14px;
            margin: -14mm -12mm 14px -12mm; box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }
        .preview-toolbar .btn {
            border: 0; border-radius: 6px; padding: 8px 12px; cursor: pointer;
            font-size: 13px; color: #fff;
        }
        .preview-toolbar .btn-print { background: #0d6efd; }
        .preview-toolbar .btn-close-win { background: #dc3545; margin-right: auto; }

        @media print {
            body { padding: 8mm 8mm; }
            .no-print { display: none !important; }
            table.items tbody td { height: 32px; }
            tr { page-break-inside: avoid; }
        }
        @page { size: A4; margin: 8mm; }
    </style>
</head>
<body>
    ${toolbarHtml}
    <div class="sheet">
        <div class="brand">
            <div class="brand-main">
                ${logoHtml}
                <div>
                    <h1>${escapeHtml(settings.company_name) || 'سیستم حسابداری'}</h1>
                    ${settings.header_text ? `<div class="doc-sub">${escapeHtml(settings.header_text)}</div>` : ''}
                </div>
            </div>
            <div style="text-align:left">
                <div class="doc-title">فرم ورود / خروج انبار</div>
            </div>
        </div>

        <div class="top-bar">
            <div class="top-cell">
                <div class="top-label">بازه تاریخ</div>
                <div class="top-write"></div>
            </div>
            <div class="top-cell">
                <div class="top-label">شماره فرم</div>
                <div class="top-write"></div>
            </div>
            <div class="top-cell">
                <div class="top-label">نوع حرکت</div>
                <div class="type-opts">
                    <div class="type-opt"><span class="tick"></span><span>ورود</span></div>
                    <div class="type-opt"><span class="tick"></span><span>خروج</span></div>
                </div>
            </div>
        </div>

        <div class="meta">
            <div class="meta-cell">
                <div class="meta-label">عنوان</div>
                <div class="meta-write"></div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">انبار</div>
                <div class="meta-write"></div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">طرف مقابل / مرجع</div>
                <div class="meta-write"></div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">توضیح کلی</div>
                <div class="meta-write"></div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="col-product">محصول</th>
                    <th class="col-color">رنگ</th>
                    <th class="col-dims">ابعاد</th>
                    <th class="col-serial">شناسه انبار</th>
                    <th class="col-qty">تعداد</th>
                    <th class="col-date">تاریخ</th>
                    <th class="col-note">یادداشت</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>

        <div class="signs">
            <div class="sign">
                <div class="sign-label">تحویل‌دهنده</div>
                <div class="sign-line">نام و امضا</div>
            </div>
            <div class="sign">
                <div class="sign-label">تحویل‌گیرنده</div>
                <div class="sign-line">نام و امضا</div>
            </div>
            <div class="sign">
                <div class="sign-label">مسئول انبار</div>
                <div class="sign-line">نام و امضا</div>
            </div>
        </div>

        ${settings.footer_text ? `<div class="footer">${escapeHtml(settings.footer_text)}</div>` : ''}
    </div>
</body>
</html>`;
    };

    const printWarehouseInOutSheet = (meta, options = {}) => {
        const settings = getPrintSettings();
        const html = generateWarehouseInOutSheetHtml(meta || {}, settings, {
            showToolbar: options.showToolbar !== false,
            blankRowCount: options.blankRowCount
        });
        const printWin = window.open('', '_blank', 'width=960,height=720');
        if (!printWin) {
            return false;
        }
        printWin.document.write(html);
        printWin.document.close();
        printWin.focus();
        if (options.autoPrint === true) {
            setTimeout(() => { printWin.print(); }, 300);
        }
        return true;
    };

    /**
     * Get print settings from app cache with defaults
     * Merges publicSettings (global) with entityPrintSettings (per-entity), giving priority to per-entity values.
     */
    const getPrintSettings = () => {
        const cache = App?.getCache?.() || {};
        const global = cache.publicSettings || {};
        const perEntity = cache.entityPrintSettings || {};
        // Merge global then per-entity - perEntity settings override global
        const merged = { ...global, ...perEntity };
        
        // Helper function to check if a setting is explicitly '1'
        const isEnabled = (value) => value === '1' || value === 1 || value === true;
        
        return {
            company_name: merged.print_company_name || '',
            company_phone: merged.print_company_phone || '',
            company_address: merged.print_company_address || '',
            logo_url: merged.print_logo_url || '',
            bank_name: merged.print_bank_name || '',
            account_number: merged.print_account_number || '',
            card_number: merged.print_card_number || '',
            sheba_number: merged.print_sheba_number || '',
            account_holder: merged.print_account_holder || '',
            // Fixed: Use explicit check for '1' instead of !== '0' to properly handle undefined/null
            show_logo: isEnabled(merged.print_show_logo),
            show_payment_info: isEnabled(merged.print_show_payment_info),
            show_company_info: isEnabled(merged.print_show_company_info),
            show_signature_line: isEnabled(merged.print_show_signature_line),
            header_text: merged.print_header_text || '',
            footer_text: merged.print_footer_text || '',
            terms: merged.print_terms || '',
            print_template: (merged.print_template === 'compact' || merged.print_template === 'images')
                ? merged.print_template
                : 'classic'
        };
    };

    /**
     * Open invoice preview window (print + SMS toolbar). Does not auto-print.
     * On mobile, uses an in-page overlay so SMS bridge stays available.
     */
    const printInvoice = (invoice, type = 'sales', options = {}) => {
        const settings = getPrintSettings();
        let apiUrl = 'api.php';
        try {
            apiUrl = new URL((typeof AppConfig !== 'undefined' && AppConfig.API_URL) ? AppConfig.API_URL : 'api.php', window.location.href).href;
        } catch (e) {}
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const html = generateInvoicePrintHtml(invoice, settings, type, {
            showToolbar: options.showToolbar !== false,
            smsApi: { url: apiUrl, csrf: csrf }
        });

        const injectBridges = (win) => {
            if (!win) return;
            try {
                win.InvoiceSmsBridge = window.InvoiceSmsBridge;
                win.InvoiceLabelsBridge = window.InvoiceLabelsBridge;
                if (window.BarcodeManager) win.BarcodeManager = window.BarcodeManager;
            } catch (e) {}
        };

        const useOverlay = options.forceOverlay === true
            || (window.matchMedia && window.matchMedia('(max-width: 900px)').matches);

        if (useOverlay) {
            let host = document.getElementById('invoice-preview-overlay');
            if (host) host.remove();
            host = document.createElement('div');
            host.id = 'invoice-preview-overlay';
            host.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#fff;';
            const iframe = document.createElement('iframe');
            iframe.title = 'پیش‌نمایش فاکتور';
            iframe.style.cssText = 'border:0;width:100%;height:100%;';
            host.appendChild(iframe);
            document.body.appendChild(host);
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
            injectBridges(iframe.contentWindow);
            return true;
        }

        const printWin = window.open('', '_blank', 'width=960,height=720');
        if (!printWin) {
            // Popup blocked — fall back to overlay
            return printInvoice(invoice, type, Object.assign({}, options, { forceOverlay: true }));
        }
        printWin.document.write(html);
        printWin.document.close();
        injectBridges(printWin);
        printWin.focus();
        if (options.autoPrint === true) {
            setTimeout(() => { printWin.print(); }, 300);
        }
        return true;
    };

    // Bridge used by invoice preview popup for SMS API calls (keeps API key server-side)
    window.InvoiceSmsBridge = {
        async preview(meta) {
            return Api.call('preview_invoice_sms', {
                invoice_id: meta.invoice_id,
                invoice_type: 'sales',
                customer_name: meta.customer_name,
                customer_phone: meta.customer_phone,
                template_key: meta.template_key || 'sales',
                include_invoice_link: meta.include_invoice_link !== false,
            });
        },
        async send(meta) {
            if (!meta.user_confirmed) {
                return { error: 'ارسال پیامک بدون تأیید کاربر مجاز نیست.' };
            }
            return Api.call('send_invoice_sms', {
                invoice_id: meta.invoice_id,
                invoice_type: 'sales',
                receptor: meta.receptor,
                message: meta.message,
                customer_name: meta.customer_name,
                customer_phone: meta.customer_phone,
                template_key: meta.template_key || 'sales',
                include_invoice_link: !!meta.include_invoice_link,
                user_confirmed: true,
            });
        }
    };

    window.InvoiceLabelsBridge = {
        printInvoiceItemLabels(serializedIds) {
            if (window.BarcodeManager && typeof window.BarcodeManager.printBatch === 'function') {
                return window.BarcodeManager.printBatch(serializedIds);
            }
            throw new Error('BarcodeManager در دسترس نیست.');
        },
        printBatch(serializedIds) {
            return this.printInvoiceItemLabels(serializedIds);
        }
    };

    /**
     * Open SMS confirmation modal (always requires explicit user confirm before send).
     */
    async function openSmsConfirmModal(opts = {}) {
        const invoiceId = opts.invoice_id;
        const invoiceType = opts.invoice_type || 'sales';
        const templateKey = opts.template_key || 'sales';
        if (!invoiceId) {
            showError('شناسه فاکتور نامعتبر است.');
            return;
        }
        if (invoiceType !== 'sales') {
            showError('ارسال پیامک فقط برای فاکتور فروش (مشتری) فعال است.');
            return;
        }

        $('#sms-confirm-invoice-id').val(invoiceId);
        $('#sms-confirm-invoice-type').val('sales');
        $('#sms-confirm-template').val(templateKey === 'ready' ? 'ready' : 'sales');
        $('#sms-confirm-include-link').prop('checked', opts.include_invoice_link !== false);
        $('#sms-confirm-phone-manual').val('');
        $('#sms-confirm-message').val('');
        $('#sms-confirm-link-preview').text('');

        const modalEl = document.getElementById('smsConfirmModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        await reloadSmsConfirmPreview();
    }

    $(document).on('click', '#sms-confirm-revoke-links', async function () {
        const invoiceId = parseInt($('#sms-confirm-invoice-id').val(), 10);
        if (!invoiceId) return;
        const ok = await confirm('همه لینک‌های عمومی قبلی این فاکتور باطل شوند؟ مشتری با لینک‌های قبلی دیگر نمی‌تواند فاکتور را ببیند یا پرداخت کند. (لینک جدید در ارسال بعدی ساخته می‌شود)', 'باطل کردن لینک');
        if (!ok) return;
        const res = await Api.call('revoke_invoice_links', { invoice_id: invoiceId, invoice_type: $('#sms-confirm-invoice-type').val() || 'sales' });
        if (res?.success) {
            showSuccess(res.message);
            await reloadSmsConfirmPreview();
        }
    });

    async function reloadSmsConfirmPreview() {
        const invoiceId = parseInt($('#sms-confirm-invoice-id').val(), 10);
        const invoiceType = $('#sms-confirm-invoice-type').val() || 'sales';
        const templateKey = $('#sms-confirm-template').val() || 'sales';
        const includeLink = $('#sms-confirm-include-link').is(':checked');
        UI.showLoader();
        const res = await Api.call('preview_invoice_sms', {
            invoice_id: invoiceId,
            invoice_type: invoiceType,
            template_key: templateKey,
            include_invoice_link: includeLink,
        });
        UI.hideLoader();
        if (!res?.success) return;
        $('#sms-confirm-message').val(res.message || '');
        const $phone = $('#sms-confirm-phone').empty();
        (res.phones || []).forEach((p, i) => {
            $phone.append(`<option value="${UI.escapeHtml(p)}" ${i === 0 ? 'selected' : ''}>${UI.escapeHtml(p)}</option>`);
        });
        if (!(res.phones || []).length) {
            $phone.append('<option value="">شماره‌ای یافت نشد</option>');
        }
        $('#sms-confirm-link-preview').text(res.invoice_link || '');
    }

    function initSmsConfirmModal() {
        $('#sms-confirm-template, #sms-confirm-include-link').on('change', function () {
            reloadSmsConfirmPreview();
        });
        $('#sms-confirm-reload-btn').on('click', reloadSmsConfirmPreview);
        $('#sms-confirm-send-btn').on('click', async function () {
            const invoiceId = parseInt($('#sms-confirm-invoice-id').val(), 10);
            const invoiceType = $('#sms-confirm-invoice-type').val() || 'sales';
            const templateKey = $('#sms-confirm-template').val() || 'sales';
            const includeLink = $('#sms-confirm-include-link').is(':checked');
            const manual = ($('#sms-confirm-phone-manual').val() || '').trim();
            const selected = $('#sms-confirm-phone').val() || '';
            const receptor = manual || selected;
            const message = $('#sms-confirm-message').val() || '';
            if (!receptor) {
                showError('شماره گیرنده را وارد کنید.');
                return;
            }
            UI.confirmAction(`تأیید نهایی ارسال پیامک به ${receptor}؟`, async (ok) => {
                if (!ok) return;
                UI.showLoader();
                const res = await Api.call('send_invoice_sms', {
                    invoice_id: invoiceId,
                    invoice_type: invoiceType,
                    template_key: templateKey,
                    include_invoice_link: includeLink,
                    receptor,
                    message,
                    user_confirmed: true,
                });
                UI.hideLoader();
                if (res?.success) {
                    showSuccess(res.message || 'پیامک ارسال شد.');
                    bootstrap.Modal.getInstance(document.getElementById('smsConfirmModal'))?.hide();
                }
            });
        });
    }

    // === Dark Mode Toggle ===
    function initDarkMode() {
        const themeKey = (typeof AppConfig !== 'undefined' && AppConfig.STORAGE_KEYS && AppConfig.STORAGE_KEYS.THEME) || 'theme';
        const savedTheme = localStorage.getItem(themeKey) || 'light';
        applyTheme(savedTheme);

        $('#dark-mode-toggle').on('click', function () {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = current === 'dark' ? 'light' : 'dark';
            applyTheme(newTheme);
            localStorage.setItem(themeKey, newTheme);
        });
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = $('#dark-mode-icon');
        if (theme === 'dark') {
            icon.removeClass('bi-moon-fill').addClass('bi-sun-fill');
        } else {
            icon.removeClass('bi-sun-fill').addClass('bi-moon-fill');
        }
    }

    function init() {
        initDarkMode();
        initSmsConfirmModal();
    }

    // Shared money helpers — single source of truth for line-total math across
    // invoice/quotation/return modules so all three always agree with the server.
    const roundMoney = (num) => Math.round(Number(num || 0) * 100) / 100;

    /**
     * Area (m²) for a carpet dimension string.
     * Named dimensions (e.g. «پادری») come from the `dimension_area_map`
     * setting (JSON); numeric "WxH" sides above the `dimension_cm_threshold`
     * setting are treated as centimeters. Mirrors PriceList::dimensionArea().
     */
    const dimensionArea = (dimension) => {
        const dim = String(dimension || '').trim();
        if (!dim) return 0;
        const defaults = (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS) || {};
        let map = defaults.DIMENSION_AREA_MAP || { 'پادری': 0.4 };
        try {
            const raw = getAppSetting('dimension_area_map', '');
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') map = parsed;
            }
        } catch (e) { /* keep default map */ }
        if (Object.prototype.hasOwnProperty.call(map, dim)) {
            return parseFloat(map[dim]) || 0;
        }
        let threshold = parseFloat(getAppSetting('dimension_cm_threshold', defaults.DIMENSION_CM_THRESHOLD || 15));
        if (!(threshold > 0)) threshold = 15;
        const parts = dim.replace(/\*/g, 'x').split('x');
        if (parts.length !== 2) return 0;
        let w = parseFloat(parts[0]);
        let h = parseFloat(parts[1]);
        if (!(w > 0) || !(h > 0)) return 0;
        if (w > threshold) w /= 100;
        if (h > threshold) h /= 100;
        return w * h;
    };

    window.InvoicePrintHost = { generateInvoicePrintHtml };

    return {
        showLoader, hideLoader, formatCurrency, formatNumber, toEnglishDigits, escapeHtml, setHtmlSafe, toGregorian, gregorianToPersian,
        today, firstDayOfYear, initializeDatepickers, renderPagination, updateSortIndicators,
        debounce, initNumericFormatting, confirmAction, confirm, confirmText, formDialog, showModalError, hideModalError, showSuccess, showError, showInfo,
        validateForm, validateNumber, validatePersianDate, generateInvoicePrintHtml, generateStocktakingCountSheetHtml,
        generateWarehouseInOutSheetHtml, getPrintSettings, printInvoice, printStocktakingCountSheet,
        printWarehouseInOutSheet,
        openSmsConfirmModal, initDarkMode, roundMoney, init,
        getAppSetting, getCurrencyLabel, dimensionArea
    };
})();