// /js/config.js
const AppConfig = {
    API_URL: 'api.php',
    // App/PWA version — injected by templates/scripts.php from PHP APP_VERSION
    // (api/core/constants.php). The literal below is only a last-resort fallback.
    VERSION: window.__APP_VERSION__ || '2.0.119',
    TABLE_STATES: {
        accounts: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        customers: { currentPage: 1, limit: 25, sortBy: 'id', sortOrder: 'DESC', searchTerm: '', balanceFilter: '' },
        suppliers: { currentPage: 1, limit: 25, sortBy: 'id', sortOrder: 'DESC', searchTerm: '', balanceFilter: '' },
        contacts: { currentPage: 1, limit: 25, sortBy: 'id', sortOrder: 'DESC', searchTerm: '', balanceFilter: '' },
        products: { currentPage: 1, limit: 10, sortBy: 'name', sortOrder: 'ASC', searchTerm: '', collectionId: '', stockFilter: 'in_stock', warehouseId: '', minArea: '', maxArea: '', shapeFilter: '' },
        sales_invoices: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        purchase_invoices: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        return_invoices: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '', invoiceType: '' },
        quotations: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '', statusFilter: '' },
        expenses: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        incomes: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        checks: { currentPage: 1, limit: 10, sortBy: 'dueDate', sortOrder: 'ASC', searchTerm: '', filterCheckType: '', filterStatus: '', filterDue: '', filterRelated: '', filterDueFrom: '', filterDueTo: '' },
        consignment_sales: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        consignment_purchases: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        users: { currentPage: 1, limit: 10, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        activity_log: { currentPage: 1, limit: 25, sortBy: 'id', sortOrder: 'DESC', searchTerm: '' },
        transactions: { currentPage: 1, limit: 15, sortBy: 'date', sortOrder: 'DESC', searchTerm: '', typeFilter: '', accountFilter: '', dateFrom: '', dateTo: '', includeUncashedChecks: false },
        settings: { currentPage: 1, limit: 10, sortBy: 'setting_key', sortOrder: 'ASC', searchTerm: '' },
        price_list: { currentPage: 1, limit: 100, sortBy: 'name', sortOrder: 'ASC', searchTerm: '' },
        collections: { currentPage: 1, limit: 100, sortBy: 'name', sortOrder: 'ASC', searchTerm: '' }
    },
    // The following arrays are now fetched from the server via App.js
    // EXPENSE_CATEGORIES: [],
    // STANDARD_CARPET_DIMENSIONS: []

    // ---- Shared localStorage keys (single source of truth) ----
    STORAGE_KEYS: {
        THEME: 'theme',
        PWA_IOS_HINT_SEEN: 'pwa_ios_hint_seen',
        LAST_ONLINE: 'hesab_last_online',
        TX_INCLUDE_UNCASHED: 'hesabdari_tx_include_uncashed',
        PERSON_COLS_PREFIX: 'hesabdari_person_cols_',
    },

    // ---- Shared UI timings (ms) ----
    UI_TIMINGS: {
        TOAST: 5000,
        TOAST_EXTENDED: 1000,
        SW_UPDATE_CHECK_INTERVAL: 30 * 60 * 1000,
        COPY_FEEDBACK: 1500,
    },

    // ---- Shared check status labels ----
    // Used by check-manager, period-closing-manager, transaction-manager and
    // return-invoice-manager. Keep in sync with the checks.status enum.
    // NOTE: the three variants intentionally keep their historical wording.
    CHECK_STATUS_LABELS: {
        received: { in_hand: 'نزد ما', endorsed: 'واگذار شده', cashed: 'وصول شده', bounced: 'برگشتی' },
        payable: { payable: 'پرداختنی', cashed: 'پاس شده', bounced: 'برگشتی' },
        period_closing: { in_hand: 'نزد ما', payable: 'پرداختنی', endorsed: 'خرج‌شده', cashed: 'وصول/پاس‌شده', bounced: 'برگشتی' },
    },

    // ---- Smart-audit scoring weights ----
    AUDIT_SCORE_WEIGHTS: { critical: 10, warning: 3, info: 1 },

    // ---- Default business-rule fallbacks (overridden by server settings) ----
    DEFAULTS: {
        CURRENCY_LABEL: 'تومان',
        RIAL_DIVISOR: 10,
        DEFAULT_UNIT: 'عدد',
        DIMENSION_CM_THRESHOLD: 15,
        DIMENSION_AREA_MAP: { 'پادری': 0.4 },
        AR_AGING_BUCKETS: [30, 60, 90],
        CHECK_DISCOUNT_DAYS_PER_MONTH: 30,
    },
};