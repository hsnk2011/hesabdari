// /js/ui.js

const UI = (function () {
    // Toastr configuration
    toastr.options = {
        "closeButton": true,
        "debug": false,
        "newestOnTop": true,
        "progressBar": true,
        "positionClass": "toast-top-left",
        "preventDuplicates": false,
        "onclick": null,
        "showDuration": "300",
        "hideDuration": "1000",
        "timeOut": "5000",
        "extendedTimeOut": "1000",
        "showEasing": "swing",
        "hideEasing": "linear",
        "showMethod": "fadeIn",
        "hideMethod": "fadeOut"
    };

    const showSuccess = (message, title = 'موفق') => {
        toastr.success(message, title);
    };

    const showError = (message, title = 'خطا') => {
        toastr.error(message, title);
    };

    const showLoader = () => $('#loader').show();
    const hideLoader = () => $('#loader').hide();

    const formatCurrency = (num) => {
        const val = Number(num);
        if (isNaN(val)) return '۰ تومان';
        return `${val.toLocaleString('fa-IR')} تومان`;
    };

    const toEnglishDigits = (str) => {
        if (typeof str !== 'string' && typeof str !== 'number') return str;
        return String(str).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    };

    const toGregorian = (elementSelector) => {
        try {
            const el = $(elementSelector);
            if (!el.length) return null;

            const persianDateStr = el.val();
            if (!persianDateStr) return null;

            const englishDateStr = toEnglishDigits(persianDateStr);
            const parts = englishDateStr.split('/');
            if (parts.length !== 3) return null;

            const year = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10);
            const day = parseInt(parts[2], 10);

            if (isNaN(year) || isNaN(month) || isNaN(day)) return null;

            const jsDate = new persianDate([year, month, day]).toDate();
            const gy = jsDate.getFullYear();
            const gm = String(jsDate.getMonth() + 1).padStart(2, '0');
            const gd = String(jsDate.getDate()).padStart(2, '0');

            return `${gy}-${gm}-${gd}`;
        } catch (e) {
            console.error("Error converting Persian date from element:", elementSelector, e);
            return null;
        }
    };

    const gregorianToPersian = (gregDateStr) => {
        if (!gregDateStr || typeof gregDateStr !== 'string') return '';
        if (gregDateStr.includes('/')) {
            return gregDateStr;
        }
        try {
            const dateObj = new Date(gregDateStr.replace(/-/g, '/'));
            return new persianDate(dateObj).format('YYYY/MM/DD');
        } catch (e) {
            console.error("Error converting Gregorian date string:", gregDateStr, e);
            return gregDateStr;
        }
    };

    const today = () => new persianDate().format('YYYY/MM/DD');
    const firstDayOfMonth = () => new persianDate().startOf('month').format('YYYY/MM/DD');
    const firstDayOfYear = () => new persianDate().startOf('year').format('YYYY/MM/DD');

    function initializeDatepickers() {
        $(".persian-datepicker").pDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            initialValue: false,
            observer: true,
            calendar: { persian: { locale: 'fa' } }
        });
    }

    function renderPagination(tableName, totalRecords, state) {
        const container = $(`.pagination-container[data-table="${tableName.replace(/_/g, '-')}"]`);
        container.empty();
        if (totalRecords <= state.limit) return;

        const totalPages = Math.ceil(totalRecords / state.limit);
        let html = '<nav><ul class="pagination pagination-sm justify-content-center">';
        html += `<li class="page-item ${state.currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${state.currentPage - 1}" data-table="${tableName}">قبلی</a></li>`;

        let start = Math.max(1, state.currentPage - 2), end = Math.min(totalPages, state.currentPage + 2);
        if (totalPages > 5 && state.currentPage > 3) start = Math.min(start, totalPages - 4);
        if (totalPages > 5 && state.currentPage < totalPages - 2) end = Math.max(end, 5);

        if (start > 1) html += `<li class="page-item"><a class="page-link" href="#" data-page="1" data-table="${tableName}">1</a></li>`;
        if (start > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;

        for (let i = start; i <= end; i++) {
            html += `<li class="page-item ${i === state.currentPage ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}" data-table="${tableName}">${i}</a></li>`;
        }

        if (end < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        if (end < totalPages) html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}" data-table="${tableName}">${totalPages}</a></li>`;

        html += `<li class="page-item ${state.currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${state.currentPage + 1}" data-table="${tableName}">بعدی</a></li>`;
        container.html(html + '</ul></nav>');
    }

    function updateSortIndicators(tableName, state) {
        const table = $(`#${tableName.replace(/_/g, '-')}-table`);
        table.find('.sortable-header').each(function () {
            const header = $(this);
            const indicator = header.find('.sort-indicator').empty();
            if (header.data('sort-by') === state.sortBy) {
                indicator.html(state.sortOrder === 'ASC' ? ' <i class="bi bi-arrow-up"></i>' : ' <i class="bi bi-arrow-down"></i>');
            }
        });
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
                let formattedValue = Number(value).toLocaleString('en-US');
                $(this).val(formattedValue);
            }
        });
    }

    function confirmAction(message, callback) {
        $('#confirmationModalBody').text(message);
        const confirmBtn = $('#confirmActionBtn');
        confirmBtn.off('click');
        confirmBtn.on('click', () => {
            callback(true);
            $('#confirmationModal').modal('hide');
        });
        $('#confirmationModal').modal('show');
    }

    function showModalError(modalId, message) {
        const errorDiv = $(`${modalId} .form-error`);
        if (errorDiv.length) {
            errorDiv.text(message).slideDown();
        }
    }

    function hideModalError(modalId) {
        const errorDiv = $(`${modalId} .form-error`);
        if (errorDiv.length) {
            errorDiv.slideUp().text('');
        }
    }

    return {
        showLoader,
        hideLoader,
        formatCurrency,
        toEnglishDigits,
        toGregorian,
        gregorianToPersian,
        today,
        firstDayOfMonth,
        firstDayOfYear,
        initializeDatepickers,
        renderPagination,
        updateSortIndicators,
        debounce,
        initNumericFormatting,
        confirmAction,
        showModalError,
        hideModalError,
        showSuccess,
        showError
    };
})();