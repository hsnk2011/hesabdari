// /js/person-list-ui.js — filters, pagination limit, column visibility for customers/suppliers

const PersonListUI = (function () {
    const STORAGE_PREFIX = (typeof AppConfig !== 'undefined' && AppConfig.STORAGE_KEYS && AppConfig.STORAGE_KEYS.PERSON_COLS_PREFIX) || 'hesabdari_person_cols_';

    const COLUMN_DEFS = {
        customers: [
            { id: 'name', label: 'نام', required: true, defaultVisible: true, thClass: 'sortable-header', sortBy: 'name' },
            { id: 'phone', label: 'تلفن', defaultVisible: true },
            { id: 'job', label: 'شغل', defaultVisible: false },
            { id: 'address', label: 'آدرس', defaultVisible: false },
            { id: 'nationalId', label: 'کد ملی', defaultVisible: false },
            { id: 'initial_balance', label: 'مانده اولیه', defaultVisible: false },
            { id: 'total_unsettled', label: 'جمع فاکتورهای باز', defaultVisible: true },
            { id: 'available_credit', label: 'اعتبار علی‌الحساب', defaultVisible: true },
            { id: 'status', label: 'وضعیت نهایی', defaultVisible: true },
            { id: 'actions', label: 'عملیات', required: true, defaultVisible: true },
        ],
        suppliers: [
            { id: 'name', label: 'نام', required: true, defaultVisible: true, thClass: 'sortable-header', sortBy: 'name' },
            { id: 'phone', label: 'تلفن', defaultVisible: true },
            { id: 'job', label: 'شغل', defaultVisible: false },
            { id: 'address', label: 'آدرس', defaultVisible: false },
            { id: 'economicCode', label: 'کد اقتصادی', defaultVisible: false },
            { id: 'initial_balance', label: 'مانده اولیه', defaultVisible: false },
            { id: 'total_unsettled', label: 'جمع فاکتورهای باز', defaultVisible: true },
            { id: 'available_credit', label: 'اعتبار علی‌الحساب', defaultVisible: true },
            { id: 'status', label: 'وضعیت نهایی', defaultVisible: true },
            { id: 'actions', label: 'عملیات', required: true, defaultVisible: true },
        ],
        contacts: [
            { id: 'name', label: 'نام', required: true, defaultVisible: true, thClass: 'sortable-header', sortBy: 'name' },
            { id: 'phone', label: 'تلفن', defaultVisible: true },
            { id: 'job', label: 'شغل', defaultVisible: false },
            { id: 'notes', label: 'یادداشت', defaultVisible: false },
            { id: 'nationalId', label: 'کد ملی', defaultVisible: false },
            { id: 'initial_balance', label: 'مانده اولیه', defaultVisible: false },
            { id: 'status', label: 'وضعیت نهایی', defaultVisible: true },
            { id: 'actions', label: 'عملیات', required: true, defaultVisible: true },
        ],
    };

    function getDefaults(tableKey) {
        const defs = COLUMN_DEFS[tableKey] || [];
        const out = {};
        defs.forEach((c) => { out[c.id] = c.defaultVisible !== false; });
        return out;
    }

    function loadVisibility(tableKey) {
        try {
            const raw = localStorage.getItem(STORAGE_PREFIX + tableKey);
            if (raw) {
                return { ...getDefaults(tableKey), ...JSON.parse(raw) };
            }
        } catch (e) { /* ignore */ }
        return getDefaults(tableKey);
    }

    function saveVisibility(tableKey, visibility) {
        try {
            localStorage.setItem(STORAGE_PREFIX + tableKey, JSON.stringify(visibility));
        } catch (e) { /* ignore */ }
    }

    function isVisible(tableKey, colId) {
        const v = loadVisibility(tableKey);
        return v[colId] !== false;
    }

    function syncTableHeader(tableKey) {
        const defs = COLUMN_DEFS[tableKey];
        if (!defs) return;
        const visibility = loadVisibility(tableKey);
        const $thead = $(`#${tableKey.replace(/_/g, '-')}-table thead tr`).empty();
        defs.forEach((col) => {
            if (!visibility[col.id] && !col.required) return;
            let thClass = col.thClass || '';
            if (col.id === 'total_unsettled') thClass += ' text-danger';
            if (col.id === 'available_credit') thClass += ' text-success';
            const sortAttr = col.sortBy ? ` data-sort-by="${col.sortBy}"` : '';
            const sortIndicator = col.sortBy ? ' <span class="sort-indicator"></span>' : '';
            $thead.append(`<th class="${thClass.trim()}"${sortAttr}>${col.label}${sortIndicator}</th>`);
        });
    }

    function buildColumnMenu(tableKey) {
        const $menu = $(`#${tableKey.replace(/_/g, '-')}-column-menu`).empty();
        const visibility = loadVisibility(tableKey);
        (COLUMN_DEFS[tableKey] || []).forEach((col) => {
            if (col.required) return;
            const checked = visibility[col.id] !== false ? 'checked' : '';
            $menu.append(`
                <label class="dropdown-item d-flex align-items-center gap-2 mb-0">
                    <input type="checkbox" class="form-check-input m-0 person-col-toggle" data-table="${tableKey}" data-col="${col.id}" ${checked}>
                    <span>${col.label}</span>
                </label>
            `);
        });
    }

    function renderRows(tableKey, data, cellBuilders) {
        const defs = COLUMN_DEFS[tableKey] || [];
        const visibility = loadVisibility(tableKey);
        const visibleCols = defs.filter((c) => visibility[c.id] !== false || c.required);
        const body = $(`#${tableKey.replace(/_/g, '-')}-table-body`).empty();

        if (!data || !data.length) {
            const emptyLabel = tableKey === 'customers' ? 'مشتری' : (tableKey === 'contacts' ? 'شخص' : 'تأمین‌کننده');
            return body.html(`<tr><td colspan="${visibleCols.length}" class="text-center">${emptyLabel}‌ای یافت نشد.</td></tr>`);
        }

        data.forEach((person) => {
            const cells = cellBuilders(person);
            const tds = visibleCols.map((col) => `<td>${cells[col.id] ?? ''}</td>`).join('');
            const $row = $(`<tr>${tds}</tr>`);
            $row.find('.btn-edit').data('entity', person);
            body.append($row);
        });
    }

    function init(tableKey, onReload) {
        syncTableHeader(tableKey);
        buildColumnMenu(tableKey);

        $(`#${tableKey.replace(/_/g, '-')}-balance-filter`).on('change', function () {
            const state = AppConfig.TABLE_STATES[tableKey];
            if (!state) return;
            state.balanceFilter = $(this).val();
            state.currentPage = 1;
            if (typeof onReload === 'function') onReload();
        });

        $('body').on('change', `.person-col-toggle[data-table="${tableKey}"]`, function () {
            const colId = $(this).data('col');
            const vis = loadVisibility(tableKey);
            vis[colId] = $(this).is(':checked');
            saveVisibility(tableKey, vis);
            syncTableHeader(tableKey);
            if (typeof onReload === 'function') onReload();
        });
    }

    return {
        COLUMN_DEFS,
        init,
        syncTableHeader,
        buildColumnMenu,
        isVisible,
        renderRows,
        loadVisibility,
    };
})();
