<?php
/**
 * Application Constants
 * Define all magic numbers and repeated values here
 */

// ========== SECURITY CONSTANTS ==========
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME_MINUTES', 15);
define('SESSION_TIMEOUT_MINUTES', 120); // 2 hours
define('CSRF_TOKEN_LENGTH', 32);
define('MIN_PASSWORD_LENGTH', 8);

// Set to true only in development — exposes detailed errors to clients when true
define('APP_DEBUG', false);

// Single source of truth for the app version. Bump on every deploy — it drives
// asset cache-busting (scripts.php, index.php), AppConfig.VERSION and the
// service-worker cache name (via the sw.js ?v= registration query).
define('APP_VERSION', '2.5.2');

// ========== PAGINATION CONSTANTS ==========
define('DEFAULT_PAGE_SIZE', 15);
define('MAX_PAGE_SIZE', 100);

// ========== FINANCIAL CONSTANTS ==========
define('MINIMUM_AMOUNT', 0.01); // Minimum transaction amount
define('DECIMAL_PRECISION', 2);

// ========== ACTIVITY LOG CONSTANTS ==========
define('ACTIVITY_LOG_RETENTION_DAYS', 365);
define('APP_LOG_MAX_FILE_MB', 5);
define('APP_LOG_MAX_ROTATED', 10);

// ========== FILE UPLOAD CONSTANTS ==========
define('MAX_UPLOAD_SIZE_MB', 5);
define('MAX_BACKUP_UPLOAD_SIZE_MB', 1024);
define('ALLOWED_BACKUP_EXTENSIONS', ['sql', 'gz', 'zip']);

// ========== DATE FORMAT CONSTANTS ==========
define('PERSIAN_DATE_FORMAT', 'YYYY/MM/DD');
define('GREGORIAN_DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');

// ========== INVENTORY CONSTANTS ==========
define('INVENTORY_STATUS_IN_STOCK', 'in_stock');
define('INVENTORY_STATUS_SOLD', 'sold');
define('INVENTORY_STATUS_CONSIGNMENT_OUT', 'consignment_out');
define('INVENTORY_STATUS_RETURNED', 'returned');

// ========== INVOICE CONSTANTS ==========
define('INVOICE_TYPE_SALES', 'sales');
define('INVOICE_TYPE_PURCHASE', 'purchase');

// ========== CHECK CONSTANTS ==========
define('CHECK_TYPE_RECEIVED', 'received');
define('CHECK_TYPE_PAYABLE', 'payable');
define('CHECK_STATUS_IN_HAND', 'in_hand');
define('CHECK_STATUS_CASHED', 'cashed');
define('CHECK_STATUS_ENDORSED', 'endorsed');

// ========== PERSON TYPE CONSTANTS ==========
define('PERSON_TYPE_CUSTOMER', 'customer');
define('PERSON_TYPE_SUPPLIER', 'supplier');
define('PERSON_TYPE_PARTNER', 'partner');

// ========== TRANSACTION TYPE CONSTANTS ==========
define('TRANSACTION_TYPE_RECEIPT', 'receipt');
define('TRANSACTION_TYPE_PAYMENT', 'payment');

// ========== PAYMENT TYPE CONSTANTS ==========
define('PAYMENT_TYPE_CASH', 'cash');
define('PAYMENT_TYPE_CHECK', 'check');
define('PAYMENT_TYPE_ENDORSE', 'endorse_check');

// ========== CACHE CONSTANTS ==========
define('CACHE_DURATION_SHORT', 300); // 5 minutes
define('CACHE_DURATION_MEDIUM', 1800); // 30 minutes
define('CACHE_DURATION_LONG', 3600); // 1 hour

// ========== ERROR MESSAGES ==========
define('ERROR_UNAUTHORIZED', 'دسترسی غیرمجاز');
define('ERROR_INVALID_DATA', 'داده‌های ورودی نامعتبر است');
define('ERROR_DATABASE', 'خطا در عملیات دیتابیس');
define('ERROR_NOT_FOUND', 'رکورد مورد نظر یافت نشد');
define('ERROR_DUPLICATE', 'این رکورد قبلاً ثبت شده است');

// ========== API RATE LIMITING ==========
// Logged-in staff get their own bucket. Invoice entry, search, and a few open
// tabs stay well under this; a tight loop still gets stopped.
define('API_RATE_LIMIT_MAX_REQUESTS', 900);
define('API_RATE_LIMIT_WINDOW_SECONDS', 60);
// Before login, the limit is per IP. Login brute-force has its own lockout.
define('API_RATE_LIMIT_ANON_MAX_REQUESTS', 180);

// ========== INTEGRATION / LINK LIFETIMES ==========
define('SSO_TOKEN_TTL_SECONDS', 120);          // one-time SSO deep-link tokens
define('DEFAULT_SHARE_LINK_TTL_HOURS', 168);   // 7 days — overridable via share_link_ttl_hours setting
define('INTEGRATION_API_VERSION', 'integration-1.0');

// ========== FINANCIAL RULE WINDOWS (DAYS) ==========
define('INVOICE_OVERDUE_DAYS', 30);   // sales invoices unpaid beyond this are "overdue"
define('CHECK_REMINDER_DAYS', 7);     // checks due within this window trigger reminders
define('CHECK_UPCOMING_DAYS', 30);    // wider check horizon used by dashboards
define('DASHBOARD_SALES_WINDOW_DAYS', 30); // "recent sales" KPI window on the dashboard

// ========== QUERY LIMITS ==========
define('INVENTORY_DEFAULT_LIMIT', 200);
define('INVENTORY_MAX_LIMIT', 500);
define('REPORT_EXPORT_LIMIT', 1000);
define('NOTIFICATION_LIST_LIMIT', 50);
