// /js/ui.js

const UI = (function () {
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

    const today = () => new persianDate().format('YYYY/MM/DD');
    const firstDayOfMonth = () => new persianDate().startOf('month').format('YYYY/MM/DD');

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

    // --- NEW CONFIRMATION MODAL FUNCTION ---
    function confirmAction(message, callback) {
        $('#confirmationModalBody').text(message);
        const confirmBtn = $('#confirmActionBtn');

        // Remove previous event handler to prevent multiple executions
        confirmBtn.off('click');

        // Add the new callback
        confirmBtn.on('click', () => {
            callback(true);
            $('#confirmationModal').modal('hide');
        });

        $('#confirmationModal').modal('show');
    }

    return {
        showLoader,
        hideLoader,
        formatCurrency,
        toEnglishDigits,
        today,
        firstDayOfMonth,
        initializeDatepickers,
        renderPagination,
        updateSortIndicators,
        debounce,
        initNumericFormatting,
        confirmAction // <-- Add this
    };
})();