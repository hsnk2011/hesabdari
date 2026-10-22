<?php
// /api/index.php

// --- Production-safe error handling + AppLogger ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/AppLogger.php';
$log_dir = dirname(__DIR__) . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
ini_set('error_log', $log_dir . '/php_errors.log');
AppLogger::init(['dir' => $log_dir]);
AppLogger::registerHandlers();
// --- END ---

require_once __DIR__ . '/core/session.php';
app_session_start();

// Include necessary files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/RateLimiter.php';

// Re-apply min level after constants (APP_DEBUG) are loaded
if (defined('APP_DEBUG') && APP_DEBUG === true) {
    AppLogger::init(['min_level' => 'DEBUG']);
} else {
    AppLogger::init(['min_level' => 'INFO']);
}

// *** START: Rate Limiting ***
// Each logged-in user has a private bucket. Sharing one IP (office NAT, or
// every request looking like the proxy) used to freeze the whole staff.
if (!empty($_SESSION['user_id'])) {
    $rate_identifier = 'user:' . (int)$_SESSION['user_id'];
    $rate_limit_max = API_RATE_LIMIT_MAX_REQUESTS;
} else {
    $rate_identifier = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rate_limit_max = API_RATE_LIMIT_ANON_MAX_REQUESTS;
}
$rate_limiter = new RateLimiter($rate_limit_max, API_RATE_LIMIT_WINDOW_SECONDS);
$rate_check = $rate_limiter->check($rate_identifier);

if (!$rate_check['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rate_check['retry_after']);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: ' . $rate_check['reset_at']);
    send_json(['error' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً کمی صبر کنید.'], 429);
    exit;
}

// Set rate limit headers
header('X-RateLimit-Limit: ' . $rate_limit_max);
header('X-RateLimit-Remaining: ' . $rate_check['remaining']);
header('X-RateLimit-Reset: ' . $rate_check['reset_at']);
// *** END: Rate Limiting ***

// Database Connection
try {
    $db = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    $db->set_charset("utf8mb4");
    db_sync_timezone($db);
} catch (Exception $e) {
    if (class_exists('AppLogger')) {
        AppLogger::critical('Database connection failed: ' . $e->getMessage(), ['source' => 'db_connect']);
    }
    send_json(['error' => 'خطا در اتصال به دیتابیس. لطفاً با پشتیبانی تماس بگیرید.'], 500);
    exit;
}

// Apply pending schema migrations once per APP_VERSION (outside any transaction).
require_once __DIR__ . '/core/SchemaMigrator.php';
SchemaMigrator::ensureUpToDate($db);

// Permissions and entity access are cached in the session at login. Re-read
// them every minute so revoked roles/entities (or a deleted user) take
// effect without waiting for the user to log out.
if (!empty($_SESSION['user_id']) && (time() - (int)($_SESSION['access_checked_at'] ?? 0)) > 60) {
    $acc_user_id = (int)$_SESSION['user_id'];
    $acc_stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $acc_stmt->bind_param('i', $acc_user_id);
    $acc_stmt->execute();
    if (empty(db_stmt_to_assoc_array($acc_stmt))) {
        session_unset();
        session_destroy();
        send_json(['error' => 'حساب کاربری شما دیگر وجود ندارد.', 'session_expired' => true], 401);
        exit;
    }
    require_once __DIR__ . '/models/User.php';
    $acc_data = (new User($db))->getUserAccessData($acc_user_id);
    $_SESSION['permissions'] = $acc_data['permissions'];
    $_SESSION['allowed_entities'] = array_map('intval', $acc_data['entities']);
    $acc_current = (int)($_SESSION['current_entity_id'] ?? 0);
    if (!in_array($acc_current, $_SESSION['allowed_entities'], true)) {
        $_SESSION['current_entity_id'] = $_SESSION['allowed_entities'][0] ?? 0;
    }
    $_SESSION['access_checked_at'] = time();
}

// Background jobs for hosts without a real cron. Throttled internally and
// disabled entirely when settings.cron_mode = 'external' (see /cron.php).
require_once __DIR__ . '/core/cron_trigger.php';

// Centralized Permission Mapping for the Router
$permission_map = [
    'register' => 'manage_settings',
    'admin_reset_password' => 'manage_settings',
    'delete_user' => 'manage_settings',
    'get_roles_and_permissions' => 'manage_settings',
    'save_role' => 'manage_settings',
    'delete_role' => 'manage_settings',
    'get_users_with_roles' => 'manage_settings',
    'update_user_access' => 'manage_settings',
    'get_app_settings' => 'manage_settings',
    'save_app_settings' => 'manage_settings',
    'get_sms_settings' => 'manage_settings',
    'save_sms_settings' => 'manage_settings',
    'get_zarinpal_settings' => 'manage_settings',
    'save_zarinpal_settings' => 'manage_settings',
    'get_online_payments' => 'manage_settings',
    'retry_online_payment' => 'manage_settings',
    'test_sms' => 'manage_settings',
    'get_sms_logs' => 'manage_settings',
    'get_app_logs' => 'manage_settings',
    'get_app_log_stats' => 'manage_settings',
    'clear_app_logs' => 'manage_settings',
    'download_app_logs' => 'manage_settings',
    'report_client_error' => null, // authenticated user; checked in controller
    'refresh_sms_status' => 'manage_settings',
    'get_kavenegar_outbox' => 'manage_settings',
    'get_kavenegar_outbox_stats' => 'manage_settings',
    'get_kavenegar_inbox' => 'manage_settings',
    'select_sms' => 'manage_settings',
    'preview_invoice_sms' => null, // checked dynamically
    'send_invoice_sms' => null, // checked dynamically
    'get_partners' => ['manage_settings', 'manage_transactions', 'manage_accounts', 'view_reports'],
    'save_partner' => 'manage_settings',
    'delete_partner' => 'manage_settings',
    'get_account_closing_status' => 'manage_settings',
    'get_account_closings' => 'manage_settings',
    'get_account_closing_detail' => 'manage_settings',
    'get_partner_share_history' => 'manage_settings',
    'preview_account_close' => 'manage_settings',
    'execute_account_close' => 'manage_settings',
    'reopen_account_closing' => 'manage_settings',
    'get_dashboard_data' => 'view_dashboard',
    'get_notifications' => 'view_dashboard',
    'get_smart_alerts' => 'view_dashboard',
    'get_due_checks_list' => 'view_dashboard',
    'get_full_customers_list' => ['manage_customers', 'manage_sales_invoices', 'manage_quotations', 'manage_return_invoices', 'manage_consignment', 'manage_transactions', 'manage_checks', 'manage_installments', 'manage_assets', 'view_reports'],
    'get_full_suppliers_list' => ['manage_suppliers', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_consignment', 'manage_transactions', 'manage_checks', 'manage_assets', 'view_reports'],
    'get_full_contacts_list' => ['manage_contacts', 'manage_transactions', 'manage_checks', 'manage_assets', 'view_reports'],
    'get_full_products_list' => ['manage_products', 'manage_collections', 'manage_sales_invoices', 'manage_purchase_invoices', 'manage_quotations', 'manage_return_invoices', 'manage_consignment', 'manage_stocktaking', 'view_price_list'],
    'get_full_accounts_list' => ['manage_accounts', 'manage_transactions', 'manage_sales_invoices', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_checks', 'manage_installments', 'manage_assets', 'manage_bank_imports', 'view_reports'],
    'global_search' => null, // any logged-in user; groups filtered by session permissions
    'get_categories_tree' => 'manage_products',
    'get_categories_list' => 'manage_products',
    'save_category' => 'manage_products',
    'delete_category' => 'manage_products',
    'upload_product_image' => null, // manage_products OR manage_purchase_invoices (checked in controller)
    'delete_product_image' => 'manage_products',
    'set_primary_image' => 'manage_products',
    'get_product_images' => 'manage_products',
    'remove_product_image_background' => 'manage_products',
    'replace_product_image' => 'manage_products',
    'product_image_rembg_status' => null, // manage_products OR manage_purchase_invoices (checked in controller)
    'process_product_image' => null, // manage_products OR manage_purchase_invoices
    'ensure_product_list_thumbs' => 'manage_products',
    'get_warehouses' => null, // manage_products OR manage_purchase_invoices (checked in controller)
    'save_warehouse' => 'manage_products',
    'delete_warehouse' => 'manage_products',
    'get_stock_alerts' => 'manage_products',
    'save_sales_invoice' => 'manage_sales_invoices',
    'delete_sales_invoice' => 'manage_sales_invoices',
    'save_purchase_invoice' => 'manage_purchase_invoices',
    'delete_purchase_invoice' => 'manage_purchase_invoices',
    'save_sales_return' => 'manage_return_invoices',
    'delete_sales_return' => 'manage_return_invoices',
    'save_purchase_return' => 'manage_return_invoices',
    'delete_purchase_return' => 'manage_return_invoices',
    'get_available_identifiers_for_return' => 'manage_return_invoices',
    'get_paginated_data_sales_returns' => 'manage_return_invoices',
    'get_paginated_data_purchase_returns' => 'manage_return_invoices',
    'get_paginated_data_return_invoices' => 'manage_return_invoices',
    'get_paginated_data_quotations' => 'manage_quotations',
    'save_quotation' => 'manage_quotations',
    'delete_quotation' => 'manage_quotations',
    'get_quotation' => 'manage_quotations',
    'update_quotation_status' => 'manage_quotations',
    'convert_quotation_to_invoice' => 'manage_quotations',
    'mark_as_consignment' => 'manage_consignment',
    'return_from_consignment' => 'manage_consignment',
    'partial_return_from_consignment' => 'manage_consignment',
    'get_consignment_invoice_items' => 'manage_consignment',
    'find_sold_consignment_purchases' => 'manage_consignment',
    'convert_sold_consignment_purchases' => 'manage_consignment',
    'update_delivery_status' => 'manage_sales_invoices',
    'get_profit_loss_report' => 'view_reports',
    'get_invoices_report' => 'view_reports',
    'get_delivery_report' => 'view_reports',
    'get_inventory_ledger_report' => 'view_reports',
    'get_person_statement' => 'view_reports',
    'get_account_statement' => 'view_reports',
    'get_expenses_report' => 'view_reports',
    'get_incomes_report' => 'view_reports',
    'get_inventory_report' => 'view_reports',
    'get_inventory_value_report' => 'view_reports',
    'get_cogs_profit_report' => 'view_reports',
    'export_report' => 'view_reports',
    'save_product' => 'manage_products',
    'delete_product' => 'manage_products',
    'get_serialized_items' => 'manage_products',
    'add_serialized_item' => 'manage_products',
    'update_serialized_item' => 'manage_products',
    'delete_serialized_item' => 'manage_products',
    'transfer_inventory_items' => 'manage_products',
    'list_warehouse_stock' => 'manage_products',
    'deliver_order_items' => 'manage_products',
    'list_convertible_orders' => 'manage_products',
    'assign_sold_to_order_warehouse' => 'manage_products',
    'label_data' => 'manage_products',
    'get_batch_label_data' => 'manage_products',
    'get_labels_for_products' => 'manage_products',
    'get_attribute_types' => 'manage_collections',
    'save_attribute_type' => 'manage_collections',
    'delete_attribute_type' => 'manage_collections',
    'get_collections' => 'manage_collections',
    'get_full_collections_list' => ['manage_products', 'manage_collections'],
    'save_collection' => 'manage_collections',
    'delete_collection' => 'manage_collections',
    'get_collection_products' => 'manage_collections',
    'get_all_products_for_selection' => 'manage_collections',
    'add_products_to_collection' => 'manage_collections',
    'remove_products_from_collection' => 'manage_collections',
    'get_price_list_data' => 'view_price_list',
    'update_prices_by_percentage' => 'manage_collections',
    'set_price' => 'manage_collections',
    'get_paginated_data_customers' => 'manage_customers',
    'get_paginated_data_suppliers' => 'manage_suppliers',
    'get_paginated_data_contacts' => 'manage_contacts',
    'get_paginated_data_products' => 'manage_products',
    'get_paginated_data_expenses' => 'manage_transactions',
    'get_paginated_data_incomes' => 'manage_transactions',
    'get_paginated_data_checks' => ['manage_checks', 'manage_transactions', 'manage_purchase_invoices', 'manage_return_invoices', 'manage_assets'],
    'get_paginated_data_accounts' => 'manage_accounts',
    'get_paginated_data_transactions' => 'manage_transactions',
    'get_paginated_data_sales_invoices' => ['manage_sales_invoices', 'manage_installments'],
    'get_paginated_data_purchase_invoices' => ['manage_purchase_invoices', 'manage_installments'],
    'get_paginated_data_consignment_sales' => 'manage_consignment',
    'get_paginated_data_consignment_purchases' => 'manage_consignment',
    'get_paginated_data_users' => 'manage_settings',
    'get_paginated_data_activity_log' => 'manage_settings',
    'save_customer' => 'manage_customers',
    'delete_customer' => 'manage_customers',
    'save_supplier' => 'manage_suppliers',
    'delete_supplier' => 'manage_suppliers',
    'merge_persons_plan' => ['manage_customers', 'manage_suppliers'],
    'merge_persons' => ['manage_customers', 'manage_suppliers'],
    'save_contact' => 'manage_contacts',
    'delete_contact' => 'manage_contacts',
    'save_account' => 'manage_accounts',
    'delete_account' => 'manage_accounts',
    'get_account_transactions' => 'manage_accounts',
    'transfer_money' => 'manage_accounts',
    'delete_transfer' => 'manage_accounts', // *** START: Add new permission ***
    'cash_check' => 'manage_checks',
    'clear_payable_check' => 'manage_checks',
    'delete_check' => 'manage_checks',
    'save_check' => 'manage_checks',
    'get_checks_summary' => 'manage_checks',
    'discount_and_transfer_checks' => 'manage_checks',
    'get_check_history' => 'manage_checks',
    'get_checks_for_print' => 'manage_checks',
    'get_check_transfer_group' => 'manage_checks',
    'bounce_check' => 'manage_checks',
    'undo_bounce_check' => 'manage_checks',
    'return_endorsed_check' => 'manage_checks',
    'undo_cash_check' => 'manage_checks',
    'save_expense' => 'manage_transactions',
    'delete_expense' => 'manage_transactions',
    'save_income' => 'manage_transactions',
    'delete_income' => 'manage_transactions',
    'delete_payment' => null, // Dynamic: invoice permission for invoice payments, else manage_transactions
    'get_person_advance_payments' => 'manage_transactions',
    'attach_advance_payments' => 'manage_transactions',
    'get_open_purchase_invoices' => ['manage_sales_invoices', 'manage_purchase_invoices', 'manage_transactions'],
    'check_auto_backup_status' => null,
    'run_pending_auto_backup' => null,
    'run_partner_check_alerts' => 'manage_settings',
    'list_backups' => 'manage_settings',
    'create_backup' => 'manage_settings',
    'delete_backup' => 'manage_settings',
    'upload_and_restore' => 'manage_settings',
    'download_backup' => 'manage_settings',
    'restore_from_server' => 'manage_settings',
    'preview_backup' => 'manage_settings',
    'run_automatic_backup' => null, 
    'test_remote_backup' => 'manage_settings',
    'test_email_settings' => 'manage_settings',
    'receive_remote_backup' => null,
    'get_unlinked_inventory_items' => 'manage_products',
    'get_possible_purchase_matches' => 'manage_products',
    'link_inventory_item' => 'manage_products',
    'auto_link_inventory_items' => 'manage_products',
    'get_account_balance_analysis' => 'manage_settings',
    'fix_account_balance' => 'manage_settings',
    'run_smart_audit' => 'manage_settings',
    'resolve_audit_issue' => 'manage_settings',
    'transfer_stock' => 'manage_products',
    'ignore_issues' => 'manage_settings',
    'restore_issues' => 'manage_settings',
    'get_audit_runs' => 'manage_settings',
    'get_audit_run' => 'manage_settings',
    'compare_audit_runs' => 'manage_settings',
    'preview_audit_fix' => 'manage_settings',
    'apply_guided_fix' => 'manage_settings',
    'resolve_audit_nav' => 'manage_settings',
    'save_entity_print_settings' => 'manage_settings',
    'create_entity' => 'manage_settings',
    'update_entity' => 'manage_settings',
    'delete_entity' => 'manage_settings',
    // --- New feature permissions ---
    'clone_invoice' => null, // Dynamic: checked in controller
    'get_invoice_history' => null, // Dynamic: sales vs purchase permission in controller
    'prepare_purchase_to_sales' => null, // Dynamic: purchase + sales permissions in controller
    'get_product_recent_prices' => null, // Dynamic: sales vs purchase permission in controller
    'get_aged_receivables_report' => 'view_reports',
    'get_cash_flow_report' => 'view_reports',
    'get_partner_profit_report' => 'view_reports',
    'get_custom_report' => 'view_reports',
    'get_checks_report' => 'view_reports',
    'get_stocktaking_list' => 'manage_stocktaking',
    'save_stocktaking' => 'manage_stocktaking',
    'delete_stocktaking' => 'manage_stocktaking',
    'get_stocktaking' => 'manage_stocktaking',
    'get_stocktaking_products' => 'manage_stocktaking',
    'get_stocktaking_warehouses' => 'manage_stocktaking',
    'get_stocktaking_snapshot' => 'manage_stocktaking',
    'apply_stocktaking' => 'manage_stocktaking',
    'cancel_stocktaking' => 'manage_stocktaking',
    'get_installments' => 'manage_installments',
    'save_installment_plan' => 'manage_installments',
    'pay_installment' => 'manage_installments',
    'delete_installment_plan' => 'manage_installments',
    'import_bank_statement' => 'manage_bank_imports',
    'get_bank_imports' => 'manage_bank_imports',
    'get_bank_import_detail' => 'manage_bank_imports',
    'suggest_bank_matches' => 'manage_bank_imports',
    'propose_bank_matches' => 'manage_bank_imports',
    'confirm_bank_matches' => 'manage_bank_imports',
    'match_bank_item' => 'manage_bank_imports',
    'unmatch_bank_item' => 'manage_bank_imports',
    'ignore_bank_item' => 'manage_bank_imports',
    'unignore_bank_item' => 'manage_bank_imports',
    'create_from_bank_item' => 'manage_bank_imports',
    'delete_bank_import' => 'manage_bank_imports',
    'search_bank_ledger' => 'manage_bank_imports',
    'import_csv_data' => 'import_export_data',
    'export_csv_data' => 'import_export_data',
    'get_export_items' => 'import_export_data',
    'export_all_json' => 'import_export_data',
    'get_data_types' => 'import_export_data',
    'generate_proforma' => null, // Dynamic
    'revoke_invoice_links' => null, // Dynamic (sales vs purchase) in ShareController
    'get_reminders' => 'view_dashboard',
    'dismiss_notification' => 'view_dashboard',
    'generate_notifications' => 'view_dashboard',
    // Master data / reports with per-kind permission checks inside LookupController
    'list_lookup' => null,
    'save_lookup' => null,
    'delete_lookup' => null,
    // دارایی‌ها
    'list_assets' => 'manage_assets',
    'save_asset' => 'manage_assets',
    'delete_asset' => 'manage_assets',
    'get_asset_history' => 'manage_assets',
    'list_asset_documents' => 'manage_assets',
    'get_asset_document' => 'manage_assets',
    'save_asset_document' => 'manage_assets',
    'delete_asset_document' => 'manage_assets',
    'get_asset_prices' => 'manage_assets',
    'refresh_asset_prices' => 'manage_assets',
    'preview_asset_conversion' => 'manage_assets',
    'list_convertible_purchase_invoices' => 'manage_assets',
    'convert_purchase_to_asset' => 'manage_assets',
    'run_recurring_expense' => 'manage_transactions',
    'get_budget_report' => null,
    'get_salesperson_report' => null,
    'list_trash' => 'manage_settings',
    'get_trash_item' => 'manage_settings',
    'restore_trash_item' => 'manage_settings',
    // CRM
    'crm_list_team' => 'use_crm',
    'crm_save_team_member' => 'manage_crm_team',
    'crm_deactivate_team_member' => 'manage_crm_team',
    'push_public_key' => 'use_crm',
    'push_subscribe' => 'use_crm',
    'push_unsubscribe' => 'use_crm',
    'push_test' => 'use_crm',
    'crm_log_activity' => 'use_crm',
    'crm_person_activities' => 'use_crm',
    'crm_save_task' => 'use_crm',
    'crm_get_task' => 'use_crm',
    'crm_set_task_status' => 'use_crm',
    'crm_my_tasks' => 'use_crm',
    'crm_team_tasks' => 'use_crm',
    'crm_send_sms' => 'use_crm',
    'crm_save_lead' => 'use_crm',
    'crm_set_lead_stage' => 'use_crm',
    'crm_list_leads' => 'use_crm',
    'crm_get_lead' => 'use_crm',
    'crm_convert_lead' => 'use_crm',
    'crm_customer_timeline' => 'use_crm',
    'crm_debtors' => 'use_crm',
    'crm_set_customer_owner' => 'use_crm',
    // میز کار
    'work_projects' => 'use_projects',
    'work_project' => 'use_projects',
    'work_save_project' => 'use_projects',
    'work_delete_project' => 'use_projects',
    'work_project_member' => 'use_projects',
    'work_people' => ['use_projects', 'use_crm', 'use_messenger', 'use_cartable', 'use_ideas'],
    'work_task_save' => 'use_projects',
    'work_task_status' => 'use_projects',
    'work_task_complete' => 'use_projects',
    'work_task_approve' => 'use_projects',
    'work_task_return' => 'use_projects',
    'work_task_reopen' => 'use_projects',
    'work_task_delete' => 'use_projects',
    'work_task_order' => 'use_projects',
    'work_task_check' => 'use_projects',
    'work_task_deps' => 'use_projects',
    'work_comment_save' => 'use_projects',
    'work_comment_delete' => 'use_projects',
    'work_doc_upload' => 'use_projects',
    'work_doc_delete' => 'use_projects',
    'work_file' => 'use_projects',
    'work_project_options' => 'manage_transactions',
    'work_meetings' => 'use_projects',
    'work_meeting' => 'use_projects',
    'work_meeting_save' => 'use_projects',
    'work_meeting_delete' => 'use_projects',
    'work_meeting_action' => 'use_projects',
    'work_meeting_files' => 'use_projects',
    'work_my' => ['use_projects', 'use_crm', 'use_messenger', 'use_cartable', 'use_ideas'],
    // پیام‌رسان
    'chat_list' => 'use_messenger',
    'chat_open_direct' => 'use_messenger',
    'chat_create_group' => 'use_messenger',
    'chat_update_group' => 'use_messenger',
    'chat_members' => 'use_messenger',
    'chat_leave' => 'use_messenger',
    'chat_prefs' => 'use_messenger',
    'chat_messages' => 'use_messenger',
    'chat_send' => 'use_messenger',
    'chat_edit' => 'use_messenger',
    'chat_delete' => 'use_messenger',
    'chat_react' => 'use_messenger',
    'chat_pin' => 'use_messenger',
    'chat_read' => 'use_messenger',
    'chat_forward' => 'use_messenger',
    'chat_unread' => 'use_messenger',
    // کارتابل، پرسنل، ایده‌ها
    'office_requests' => 'use_cartable',
    'office_request' => 'use_cartable',
    'office_request_save' => 'use_cartable',
    'office_request_act' => 'use_cartable',
    'office_request_files' => 'use_cartable',
    'office_pay_options' => 'pay_payments',
    'hr_list' => 'manage_hr',
    'hr_save' => 'manage_hr',
    'hr_detail' => 'manage_hr',
    'hr_record' => 'manage_hr',
    'hr_import_team' => 'manage_hr',
    'hr_options' => 'use_cartable',
    'ideas_list' => 'use_ideas',
    'idea_detail' => 'use_ideas',
    'idea_save' => 'use_ideas',
    'idea_delete' => 'use_ideas',
    'idea_comment' => 'use_ideas',
    'idea_vote' => 'use_ideas',
    'idea_decide' => 'use_ideas',
];

$action = $_GET['action'] ?? '';

// Handle both JSON body and FormData (for file uploads)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if ($data === null && !empty($_POST)) {
    // FormData was sent (file upload), use $_POST
    $data = $_POST;
}
$data = $data ?? [];

// Stored-XSS guard: user text (names, descriptions, notes, ...) is shown in
// many HTML templates. Tag characters are neutralized on the way in, except
// for credentials and for admin settings/backup payloads that may hold them.
$markup_exempt_actions = [
    'login', 'change_password', 'admin_reset_password',
    'save_app_settings', 'save_sms_settings', 'save_zarinpal_settings', 'save_entity_print_settings',
    'upload_and_restore', 'receive_remote_backup', 'restore_from_server', 'test_remote_backup', 'test_email_settings',
    'integration_save_settings',
];
if (!in_array($action, $markup_exempt_actions, true)) {
    $data = neutralize_markup($data);
    if (!empty($_POST)) {
        $_POST = neutralize_markup($_POST);
    }
}

// --- Integration API token auth (service-to-service) ---
require_once __DIR__ . '/core/IntegrationAuth.php';
$integration_token = IntegrationAuth::extractBearerOrHeader();
$is_integration_action = strpos($action, 'integration_') === 0;
$integration_authenticated = false;

if ($integration_token !== '') {
    $integrationAuth = new IntegrationAuth($db);
    $integration_authenticated = $integrationAuth->authenticate($integration_token);
    if (!$integration_authenticated && $is_integration_action) {
        send_json(['error' => 'توکن یکپارچه‌سازی نامعتبر است.'], 401);
        exit;
    }
    // A shop token may only call the integration API; the normal routes have
    // no per-user permission check for it (e.g. save_payment skipped checks).
    if ($integration_authenticated && !$is_integration_action) {
        send_json(['error' => 'توکن یکپارچه‌سازی فقط برای عملیات integration_* معتبر است.'], 403);
        exit;
    }
}

if (!in_array($action, ['login', 'check_session', 'get_public_settings', 'download_backup', 'run_automatic_backup', 'receive_remote_backup'])) {
    if (!$integration_authenticated) {
        if (!enforce_session_timeout()) {
            send_json(['error' => 'نشست منقضی شده است. لطفاً دوباره وارد شوید.'], 401);
            exit;
        }
        touch_session_activity();
    }
}

$public_actions = ['login', 'check_session', 'get_public_settings', 'download_backup', 'run_automatic_backup', 'receive_remote_backup'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $public_actions) && !$integration_authenticated) {
    if (!verify_csrf_token()) {
        send_json(['error' => 'جلسه نامعتبر است یا منقضی شده. لطفاً صفحه را رفرش کنید.'], 403);
        exit;
    }
}

// Integration actions: require either valid API token OR manage_settings session
$integration_session_actions = [
    'integration_create_api_token',
    'integration_list_api_tokens',
    'integration_revoke_api_token',
    'integration_save_settings',
    'integration_get_settings',
];
if ($is_integration_action) {
    if ($integration_authenticated) {
        // ok
    } elseif (in_array($action, $integration_session_actions, true)) {
        check_permission('manage_settings');
    } else {
        send_json(['error' => 'این عملیات فقط با توکن یکپارچه‌سازی مجاز است.'], 401);
        exit;
    }
} else {
    $permission_key_for_action = $action;
    if ($action === 'get_paginated_data') {
        $permission_key_for_action = 'get_paginated_data_' . (string)($data['tableName'] ?? '');
        // Unknown tables are refused instead of silently skipping the check.
        if (!array_key_exists($permission_key_for_action, $permission_map)) {
            send_json(['error' => 'جدول درخواستی نامعتبر است.'], 400);
            exit;
        }
    }

    if (isset($permission_map[$permission_key_for_action]) && $permission_map[$permission_key_for_action] !== null) {
        $required_permission = $permission_map[$permission_key_for_action];
        // A page opened before «میز کار» was switched off still polls it: tell it to drop those permissions.
        if (!array_diff((array)$required_permission, WORKSPACE_PERMISSIONS) && !workspace_enabled($db)) {
            $_SESSION['access_checked_at'] = 0;
            send_json(['error' => 'میز کار غیرفعال است.', 'workspace_disabled' => true], 403);
            exit;
        }
        // A list means "any of these" (read-only lists used by several sections).
        is_array($required_permission) ? check_any_permission($required_permission) : check_permission($required_permission);
    }
}

if (in_array($action, ['convert_purchase_to_asset', 'list_convertible_purchase_invoices', 'preview_asset_conversion'], true) && !$integration_authenticated) {
    check_permission('manage_purchase_invoices');
}

// Dynamic permission check for delivery status update based on invoice type
if ($action === 'update_delivery_status' && !$integration_authenticated) {
    $ds_type = $data['type'] ?? 'sales';
    check_permission($ds_type === 'purchase' ? 'manage_purchase_invoices' : 'manage_sales_invoices');
}

// Dynamic permission for invoice SMS (sales vs purchase)
if (($action === 'preview_invoice_sms' || $action === 'send_invoice_sms') && !$integration_authenticated) {
    $sms_inv_type = $data['invoice_type'] ?? 'sales';
    check_permission($sms_inv_type === 'purchase' ? 'manage_purchase_invoices' : 'manage_sales_invoices');
}

if (($action === 'save_payment' || $action === 'delete_payment') && !$integration_authenticated) {
    // Editing/deleting an existing payment is judged by the stored row, not by
    // what the client sends (an invoice payment could otherwise be changed with
    // only transaction access, or vice versa).
    $pay_invoice_perm = [
        'sales' => 'manage_sales_invoices',
        'purchase' => 'manage_purchase_invoices',
        'return' => 'manage_return_invoices',
        'asset_purchase' => 'manage_assets',
        'asset_sale' => 'manage_assets',
    ];
    $pay_invoice_id = !empty($data['invoiceId']) ? (int)$data['invoiceId'] : 0;
    $pay_invoice_type = $data['invoiceType'] ?? 'sales';
    $pay_type = $data['type'] ?? 'cash';
    $pay_existing_id = (int)($data['id'] ?? 0);
    if ($pay_existing_id > 0) {
        $pay_entity = (int)($_SESSION['current_entity_id'] ?? 0);
        $pay_stmt = $db->prepare("SELECT invoiceId, invoiceType, type FROM payments WHERE id = ? AND entity_id = ?");
        $pay_stmt->bind_param('ii', $pay_existing_id, $pay_entity);
        $pay_stmt->execute();
        $pay_row = db_stmt_to_assoc_array($pay_stmt)[0] ?? null;
        if (!$pay_row) {
            send_json(['error' => 'پرداخت یافت نشد.'], 404);
            exit;
        }
        if (!empty($pay_row['invoiceId'])) {
            if ($pay_invoice_id > 0 && $pay_invoice_type !== $pay_row['invoiceType']) {
                check_permission($pay_invoice_perm[$pay_invoice_type] ?? 'manage_sales_invoices');
            }
            $pay_invoice_id = (int)$pay_row['invoiceId'];
            $pay_invoice_type = $pay_row['invoiceType'];
        }
        if ($action === 'delete_payment') {
            $pay_type = $pay_row['type'];
        }
    }
    if ($pay_invoice_id > 0) {
        check_permission($pay_invoice_perm[$pay_invoice_type] ?? 'manage_sales_invoices');
    } elseif (in_array($pay_type, ['check', 'endorse_check'], true)) {
        // «ثبت چک جدید» in the checks section stores a check payment.
        check_any_permission(['manage_transactions', 'manage_checks']);
    } else {
        check_permission('manage_transactions');
    }
}

// Settings actions that reach beyond the current entity. Whole-database
// backups/restores need access to every entity; other cross-entity
// operations are limited to the entities the acting user can access.
$scoped_settings_actions = ['upload_and_restore', 'test_remote_backup', 'create_entity', 'delete_backup', 'download_backup',
    'restore_from_server', 'preview_backup', 'create_backup', 'update_entity', 'delete_entity', 'save_entity_print_settings', 'update_user_access'];
if (!$integration_authenticated && !empty($_SESSION['user_id']) && in_array($action, $scoped_settings_actions, true)) {
    $actor_entities = array_map('intval', $_SESSION['allowed_entities'] ?? []);
    $actor_all_entities = user_has_all_entities($db, $actor_entities);
    $whole_db_actions = ['upload_and_restore', 'test_remote_backup', 'create_entity'];
    $deny_scope = function () {
        send_json(['error' => 'این عملیات فقط برای کاربرانی مجاز است که به همه مجموعه‌ها دسترسی دارند.'], 403);
        exit;
    };
    if (in_array($action, $whole_db_actions, true) && !$actor_all_entities) {
        $deny_scope();
    }
    if (in_array($action, ['delete_backup', 'download_backup', 'restore_from_server', 'preview_backup'], true) && !$actor_all_entities) {
        // Limited admins may handle only the entity backups of their own entities.
        $bk_name = basename((string)($data['filename'] ?? $_GET['filename'] ?? ''));
        if (!preg_match('/^entity_(\d+)_/', $bk_name, $bk_m) || !in_array((int)$bk_m[1], $actor_entities, true)) {
            $deny_scope();
        }
    }
    if ($action === 'create_backup' && !$actor_all_entities) {
        if (($data['type'] ?? 'full') !== 'entity' || !in_array((int)($data['entityId'] ?? 0), $actor_entities, true)) {
            $deny_scope();
        }
    }
    if (in_array($action, ['update_entity', 'delete_entity', 'save_entity_print_settings'], true)) {
        $target_entity = (int)($data['id'] ?? $data['entity_id'] ?? $data['entityId'] ?? 0);
        if ($target_entity > 0 && !in_array($target_entity, $actor_entities, true)) {
            send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
            exit;
        }
    }
    if ($action === 'update_user_access' && !$actor_all_entities) {
        // Grant only the actor's own entities; access to entities outside the
        // actor's scope is left exactly as it was.
        $requested_entities = array_values(array_intersect(
            array_unique(array_map('intval', (array)($data['entities'] ?? []))),
            $actor_entities
        ));
        $target_user = (int)($data['userId'] ?? 0);
        $keep_stmt = $db->prepare("SELECT entity_id FROM user_entity_access WHERE user_id = ?");
        $keep_stmt->bind_param('i', $target_user);
        $keep_stmt->execute();
        foreach (db_stmt_to_assoc_array($keep_stmt) as $kr) {
            $ke = (int)$kr['entity_id'];
            if (!in_array($ke, $actor_entities, true)) {
                $requested_entities[] = $ke;
            }
        }
        $data['entities'] = $requested_entities;
    }
}

$routes = [
    'login' => ['AuthController', 'login'],
    'logout' => ['AuthController', 'logout'],
    'register' => ['AuthController', 'register'],
    'check_session' => ['AuthController', 'checkSession'],
    'change_password' => ['AuthController', 'changePassword'],
    'admin_reset_password' => ['AuthController', 'adminResetPassword'],
    'delete_user' => ['AuthController', 'deleteUser'],
    'get_dashboard_data' => ['DataController', 'getDashboardData'],
    'get_notifications' => ['DataController', 'getNotifications'],
    'get_smart_alerts' => ['DataController', 'getSmartAlerts'],
    'get_due_checks_list' => ['DataController', 'getDueChecksList'],
    'get_entity_by_id' => ['DataController', 'getEntityById'],
    'get_full_customers_list' => ['DataController', 'getFullCustomersList'],
    'get_full_suppliers_list' => ['DataController', 'getFullSuppliersList'],
    'get_full_contacts_list' => ['DataController', 'getFullContactsList'],
    'get_full_products_list' => ['DataController', 'getFullProductsList'],
    'get_full_accounts_list' => ['DataController', 'getFullAccountsList'],
    'global_search' => ['DataController', 'globalSearch'],
    'get_partners' => ['DataController', 'getPartners'],
    'get_profit_loss_report' => ['ReportController', 'getProfitLossReport'],
    'get_invoices_report' => ['ReportController', 'getInvoicesReport'],
    'get_delivery_report' => ['ReportController', 'getDeliveryReport'],
    'get_inventory_ledger_report' => ['ReportController', 'getInventoryLedgerReport'],
    'get_person_statement' => ['ReportController', 'getPersonStatement'],
    'get_account_statement' => ['ReportController', 'getAccountStatement'],
    'get_expenses_report' => ['ReportController', 'getExpensesReport'],
    'get_incomes_report' => ['ReportController', 'getIncomesReport'],
    'get_inventory_report' => ['ReportController', 'getInventoryReport'],
    'get_inventory_value_report' => ['ReportController', 'getInventoryValueReport'],
    'get_cogs_profit_report' => ['ReportController', 'getCogsProfitReport'],
    'export_report' => ['ReportController', 'exportReport'],
    'save_sales_invoice' => ['InvoiceController', 'saveSalesInvoice'],
    'delete_sales_invoice' => ['InvoiceController', 'deleteSalesInvoice'],
    'save_purchase_invoice' => ['InvoiceController', 'savePurchaseInvoice'],
    'delete_purchase_invoice' => ['InvoiceController', 'deletePurchaseInvoice'],
    'save_sales_return' => ['ReturnInvoiceController', 'saveSalesReturn'],
    'delete_sales_return' => ['ReturnInvoiceController', 'deleteSalesReturn'],
    'save_purchase_return' => ['ReturnInvoiceController', 'savePurchaseReturn'],
    'delete_purchase_return' => ['ReturnInvoiceController', 'deletePurchaseReturn'],
    'get_available_identifiers_for_return' => ['ReturnInvoiceController', 'getAvailableIdentifiers'],
    'save_quotation' => ['QuotationController', 'saveQuotation'],
    'delete_quotation' => ['QuotationController', 'deleteQuotation'],
    'get_quotation' => ['QuotationController', 'getQuotation'],
    'update_quotation_status' => ['QuotationController', 'updateQuotationStatus'],
    'convert_quotation_to_invoice' => ['QuotationController', 'convertToInvoice'],
    'mark_as_consignment' => ['InvoiceController', 'markAsConsignment'],
    'return_from_consignment' => ['InvoiceController', 'returnFromConsignment'],
    'partial_return_from_consignment' => ['InvoiceController', 'partialReturnFromConsignment'],
    'get_consignment_invoice_items' => ['InvoiceController', 'getConsignmentInvoiceItems'],
    'find_sold_consignment_purchases' => ['InvoiceController', 'findSoldConsignmentPurchases'],
    'convert_sold_consignment_purchases' => ['InvoiceController', 'convertSoldConsignmentPurchases'],
    'update_delivery_status' => ['InvoiceController', 'updateDeliveryStatus'],
    'save_product' => ['ProductController', 'save'],
    'delete_product' => ['ProductController', 'delete'],
    'get_serialized_items' => ['ProductController', 'getSerializedItems'],
    'add_serialized_item' => ['ProductController', 'addSerializedItem'],
    'update_serialized_item' => ['ProductController', 'updateSerializedItem'],
    'delete_serialized_item' => ['ProductController', 'deleteSerializedItem'],
    'transfer_inventory_items' => ['ProductController', 'transferItems'],
    'list_warehouse_stock' => ['InventoryController', 'listStock'],
    'deliver_order_items' => ['InventoryController', 'deliverOrderItems'],
    'list_convertible_orders' => ['InventoryController', 'listConvertibleOrders'],
    'assign_sold_to_order_warehouse' => ['InventoryController', 'assignSoldToOrderWarehouse'],
    'label_data' => ['ProductController', 'labelData'],
    'get_batch_label_data' => ['ProductController', 'getBatchLabelData'],
    'get_labels_for_products' => ['ProductController', 'getLabelsForProducts'],
    'upload_product_image' => ['ProductController', 'uploadImage'],
    'delete_product_image' => ['ProductController', 'deleteImage'],
    'set_primary_image' => ['ProductController', 'setPrimaryImage'],
    'get_product_images' => ['ProductController', 'getImages'],
    'remove_product_image_background' => ['ProductController', 'removeImageBackground'],
    'replace_product_image' => ['ProductController', 'replaceImage'],
    'product_image_rembg_status' => ['ProductController', 'rembgStatus'],
    'process_product_image' => ['ProductController', 'processImage'],
    'ensure_product_list_thumbs' => ['ProductController', 'ensureProductListThumbs'],
    'get_stock_alerts' => ['ProductController', 'getStockAlerts'],
    'get_categories_tree' => ['CategoryController', 'getTree'],
    'get_categories_list' => ['CategoryController', 'getList'],
    'save_category' => ['CategoryController', 'save'],
    'delete_category' => ['CategoryController', 'delete'],
    'get_warehouses' => ['InventoryController', 'getWarehouses'],
    'save_warehouse' => ['InventoryController', 'saveWarehouse'],
    'delete_warehouse' => ['InventoryController', 'deleteWarehouse'],
    'transfer_stock' => ['InventoryController', 'transferStock'],
    'get_attribute_types' => ['CollectionController', 'getAttributeTypes'],
    'save_attribute_type' => ['CollectionController', 'saveAttributeType'],
    'delete_attribute_type' => ['CollectionController', 'deleteAttributeType'],
    'get_collections' => ['CollectionController', 'getCollections'],
    'get_full_collections_list' => ['CollectionController', 'getFullList'],
    'save_collection' => ['CollectionController', 'saveCollection'],
    'delete_collection' => ['CollectionController', 'deleteCollection'],
    'get_collection_products' => ['CollectionController', 'getCollectionProducts'],
    'get_all_products_for_selection' => ['CollectionController', 'getAllProductsForSelection'],
    'add_products_to_collection' => ['CollectionController', 'addProductsToCollection'],
    'remove_products_from_collection' => ['CollectionController', 'removeProductsFromCollection'],
    'get_price_list_data' => ['PriceListController', 'getPriceListData'],
    'update_prices_by_percentage' => ['PriceListController', 'updatePricesByPercentage'],
    'set_price' => ['PriceListController', 'setPrice'],
    'save_customer' => ['CustomerController', 'save'],
    'delete_customer' => ['CustomerController', 'delete'],
    'save_supplier' => ['SupplierController', 'save'],
    'delete_supplier' => ['SupplierController', 'delete'],
    'merge_persons_plan' => ['PersonLinkController', 'mergePlan'],
    'merge_persons' => ['PersonLinkController', 'merge'],
    'save_contact' => ['ContactController', 'save'],
    'delete_contact' => ['ContactController', 'delete'],
    'save_expense' => ['ExpenseController', 'save'],
    'delete_expense' => ['ExpenseController', 'delete'],
    'save_income' => ['IncomeController', 'save'],
    'delete_income' => ['IncomeController', 'delete'],
    'save_account' => ['AccountController', 'save'],
    'delete_account' => ['AccountController', 'delete'],
    'get_account_transactions' => ['AccountController', 'getAccountTransactions'],
    'transfer_money' => ['AccountController', 'transferMoney'],
    'delete_transfer' => ['AccountController', 'deleteTransfer'], // *** START: Add new route ***
    'cash_check' => ['CheckController', 'cashCheck'],
    'clear_payable_check' => ['CheckController', 'clearPayableCheck'],
    'delete_check' => ['CheckController', 'deleteCheck'],
    'save_check' => ['CheckController', 'saveCheck'],
    'get_checks_summary' => ['CheckController', 'getChecksSummary'],
    'discount_and_transfer_checks' => ['CheckController', 'discountAndTransferChecks'],
    'get_check_history' => ['CheckController', 'getCheckHistory'],
    'get_checks_for_print' => ['CheckController', 'getChecksForPrint'],
    'get_check_transfer_group' => ['CheckController', 'getCheckTransferGroup'],
    'bounce_check' => ['CheckController', 'bounceCheck'],
    'undo_bounce_check' => ['CheckController', 'undoBounce'],
    'return_endorsed_check' => ['CheckController', 'returnEndorsedCheck'],
    'undo_cash_check' => ['CheckController', 'undoCash'],
    'save_payment' => ['PaymentController', 'savePayment'],
    'delete_payment' => ['PaymentController', 'deletePayment'],
    'get_person_advance_payments' => ['PaymentController', 'getPersonAdvancePayments'],
    'attach_advance_payments' => ['PaymentController', 'attachAdvancePayments'],
    'get_open_purchase_invoices' => ['PaymentController', 'getOpenPurchaseInvoices'],
    'save_partner' => ['PartnerController', 'savePartner'],
    'delete_partner' => ['PartnerController', 'deletePartner'],
    'get_account_closing_status' => ['PeriodClosingController', 'getStatus'],
    'get_account_closings' => ['PeriodClosingController', 'getList'],
    'get_account_closing_detail' => ['PeriodClosingController', 'getDetail'],
    'get_partner_share_history' => ['PeriodClosingController', 'getShareHistory'],
    'preview_account_close' => ['PeriodClosingController', 'previewClose'],
    'execute_account_close' => ['PeriodClosingController', 'executeClose'],
    'reopen_account_closing' => ['PeriodClosingController', 'reopenClosing'],
    'switch_entity' => ['SettingsController', 'switchEntity'],
    'get_app_settings' => ['SettingsController', 'getAppSettings'],
    'get_public_settings' => ['SettingsController', 'getPublicSettings'],
    'save_app_settings' => ['SettingsController', 'saveAppSettings'],
    'create_entity' => ['SettingsController', 'createEntity'],
    'update_entity' => ['SettingsController', 'updateEntity'],
    'delete_entity' => ['SettingsController', 'deleteEntity'],
    'get_sms_settings' => ['SmsController', 'getSmsSettings'],
    'save_sms_settings' => ['SmsController', 'saveSmsSettings'],
    'get_zarinpal_settings' => ['PaymentGatewayController', 'getZarinpalSettings'],
    'save_zarinpal_settings' => ['PaymentGatewayController', 'saveZarinpalSettings'],
    'get_online_payments' => ['PaymentGatewayController', 'getOnlinePayments'],
    'retry_online_payment' => ['PaymentGatewayController', 'retryOnlinePayment'],
    'test_sms' => ['SmsController', 'testSms'],
    'get_sms_logs' => ['SmsController', 'getSmsLogs'],
    'refresh_sms_status' => ['SmsController', 'refreshSmsStatus'],
    'get_kavenegar_outbox' => ['SmsController', 'getKavenegarOutbox'],
    'get_kavenegar_outbox_stats' => ['SmsController', 'getKavenegarOutboxStats'],
    'get_kavenegar_inbox' => ['SmsController', 'getKavenegarInbox'],
    'select_sms' => ['SmsController', 'selectSms'],
    'preview_invoice_sms' => ['SmsController', 'previewInvoiceSms'],
    'send_invoice_sms' => ['SmsController', 'sendInvoiceSms'],
    'get_entity_print_settings' => ['SettingsController', 'getEntityPrintSettings'],
    'save_entity_print_settings' => ['SettingsController', 'saveEntityPrintSettings'],
    'get_roles_and_permissions' => ['AccessControlController', 'getRolesAndPermissions'],
    'save_role' => ['AccessControlController', 'saveRole'],
    'delete_role' => ['AccessControlController', 'deleteRole'],
    'get_users_with_roles' => ['AccessControlController', 'getUsersWithRoles'],
    'update_user_access' => ['AccessControlController', 'updateUserAccess'],
    'get_paginated_data' => ['EntityController', 'getPaginatedData'],
    'list_backups' => ['BackupController', 'listBackups'],
    'create_backup' => ['BackupController', 'createBackup'],
    'delete_backup' => ['BackupController', 'deleteBackup'],
    'upload_and_restore' => ['BackupController', 'uploadAndRestore'],
    'download_backup' => ['BackupController', 'downloadBackup'],
    'restore_from_server' => ['BackupController', 'restoreFromServer'],
    'preview_backup' => ['BackupController', 'previewBackup'],
    'run_automatic_backup' => ['BackupController', 'runAutomaticBackup'],
    'check_auto_backup_status' => ['BackupController', 'checkAutoBackupStatus'],
    'run_pending_auto_backup' => ['BackupController', 'runPendingAutoBackup'],
    'test_remote_backup' => ['BackupController', 'testRemoteBackup'],
    'test_email_settings' => ['SettingsController', 'testEmailSettings'],
    'receive_remote_backup' => ['BackupController', 'receiveRemoteBackup'],
    'run_partner_check_alerts' => ['SmsController', 'runPartnerCheckAlerts'],
    'get_unlinked_inventory_items' => ['AuditController', 'getUnlinkedItems'],
    'get_possible_purchase_matches' => ['AuditController', 'getPossibleMatches'],
    'link_inventory_item' => ['AuditController', 'linkItem'],
    'auto_link_inventory_items' => ['AuditController', 'autoLinkItems'],
    'get_account_balance_analysis' => ['AuditController', 'getAccountBalanceAnalysis'],
    'fix_account_balance' => ['AuditController', 'fixAccountBalance'],
    'run_smart_audit' => ['AuditController', 'runSmartAudit'],
    'resolve_audit_issue' => ['AuditController', 'resolveIssue'],
    'ignore_issues' => ['AuditController', 'ignoreIssues'],
    'restore_issues' => ['AuditController', 'restoreIssues'],
    'get_audit_runs' => ['AuditController', 'getAuditRuns'],
    'get_audit_run' => ['AuditController', 'getAuditRun'],
    'compare_audit_runs' => ['AuditController', 'compareAuditRuns'],
    'preview_audit_fix' => ['AuditController', 'previewAuditFix'],
    'apply_guided_fix' => ['AuditController', 'applyGuidedFix'],
    'resolve_audit_nav' => ['AuditController', 'resolveAuditNav'],
    // --- New feature routes ---
    'clone_invoice' => ['InvoiceController', 'cloneInvoice'],
    'get_invoice_history' => ['InvoiceController', 'getInvoiceHistory'],
    'prepare_purchase_to_sales' => ['InvoiceController', 'preparePurchaseToSales'],
    'get_product_recent_prices' => ['InvoiceController', 'getProductRecentPrices'],
    'get_aged_receivables_report' => ['ReportController', 'getAgedReceivablesReport'],
    'get_cash_flow_report' => ['ReportController', 'getCashFlowReport'],
    'get_partner_profit_report' => ['ReportController', 'getPartnerProfitReport'],
    'get_custom_report' => ['ReportController', 'getCustomReport'],
    'get_checks_report' => ['ReportController', 'getChecksReport'],
    'get_stocktaking_list' => ['StocktakingController', 'getList'],
    'save_stocktaking' => ['StocktakingController', 'save'],
    'delete_stocktaking' => ['StocktakingController', 'delete'],
    'get_stocktaking' => ['StocktakingController', 'get'],
    'get_stocktaking_products' => ['StocktakingController', 'getProducts'],
    'get_stocktaking_warehouses' => ['StocktakingController', 'getWarehouses'],
    'get_stocktaking_snapshot' => ['StocktakingController', 'getSnapshot'],
    'apply_stocktaking' => ['StocktakingController', 'apply'],
    'cancel_stocktaking' => ['StocktakingController', 'cancel'],
    'get_app_logs' => ['LogController', 'getAppLogs'],
    'get_app_log_stats' => ['LogController', 'getAppLogStats'],
    'clear_app_logs' => ['LogController', 'clearAppLogs'],
    'download_app_logs' => ['LogController', 'downloadAppLogs'],
    'report_client_error' => ['LogController', 'reportClientError'],
    'get_installments' => ['InstallmentController', 'getInstallments'],
    'save_installment_plan' => ['InstallmentController', 'savePlan'],
    'pay_installment' => ['InstallmentController', 'payInstallment'],
    'delete_installment_plan' => ['InstallmentController', 'deletePlan'],
    'import_bank_statement' => ['BankImportController', 'importStatement'],
    'get_bank_imports' => ['BankImportController', 'getImports'],
    'get_bank_import_detail' => ['BankImportController', 'getImportDetail'],
    'suggest_bank_matches' => ['BankImportController', 'suggestBankMatches'],
    'propose_bank_matches' => ['BankImportController', 'proposeBankMatches'],
    'confirm_bank_matches' => ['BankImportController', 'confirmBankMatches'],
    'match_bank_item' => ['BankImportController', 'matchItem'],
    'unmatch_bank_item' => ['BankImportController', 'unmatchItem'],
    'ignore_bank_item' => ['BankImportController', 'ignoreItem'],
    'unignore_bank_item' => ['BankImportController', 'unignoreItem'],
    'create_from_bank_item' => ['BankImportController', 'createFromBankItem'],
    'delete_bank_import' => ['BankImportController', 'deleteImport'],
    'search_bank_ledger' => ['BankImportController', 'searchLedger'],
    'import_csv_data' => ['ImportExportController', 'importCsv'],
    'export_csv_data' => ['ImportExportController', 'exportCsv'],
    'get_export_items' => ['ImportExportController', 'getExportItems'],
    'export_all_json' => ['ImportExportController', 'exportAllJson'],
    'get_data_types' => ['ImportExportController', 'getDataTypes'],
    'generate_proforma' => ['ShareController', 'generateProforma'],
    'revoke_invoice_links' => ['ShareController', 'revokeLinks'],
    'get_reminders' => ['NotificationController', 'getReminders'],
    'dismiss_notification' => ['NotificationController', 'dismiss'],
    'generate_notifications' => ['NotificationController', 'generate'],
    'list_lookup' => ['LookupController', 'listItems'],
    'save_lookup' => ['LookupController', 'saveItem'],
    'delete_lookup' => ['LookupController', 'deleteItem'],
    'list_assets' => ['AssetController', 'listAssets'],
    'save_asset' => ['AssetController', 'saveAsset'],
    'delete_asset' => ['AssetController', 'deleteAsset'],
    'get_asset_history' => ['AssetController', 'getHistory'],
    'list_asset_documents' => ['AssetController', 'listDocuments'],
    'get_asset_document' => ['AssetController', 'getDocument'],
    'save_asset_document' => ['AssetController', 'saveDocument'],
    'delete_asset_document' => ['AssetController', 'deleteDocument'],
    'get_asset_prices' => ['AssetController', 'getPrices'],
    'refresh_asset_prices' => ['AssetController', 'refreshPrices'],
    'list_convertible_purchase_invoices' => ['AssetController', 'listConvertible'],
    'preview_asset_conversion' => ['AssetController', 'previewConversion'],
    'convert_purchase_to_asset' => ['AssetController', 'convertPurchaseInvoice'],
    // CRM
    'crm_list_team' => ['CrmController', 'listTeam'],
    'crm_save_team_member' => ['CrmController', 'saveTeamMember'],
    'crm_deactivate_team_member' => ['CrmController', 'deactivateTeamMember'],
    'push_public_key' => ['CrmController', 'pushPublicKey'],
    'push_subscribe' => ['CrmController', 'pushSubscribe'],
    'push_unsubscribe' => ['CrmController', 'pushUnsubscribe'],
    'push_test' => ['CrmController', 'pushTest'],
    'crm_log_activity' => ['CrmController', 'logActivity'],
    'crm_person_activities' => ['CrmController', 'personActivities'],
    'crm_save_task' => ['CrmController', 'saveTask'],
    'crm_get_task' => ['CrmController', 'getTask'],
    'crm_set_task_status' => ['CrmController', 'setTaskStatus'],
    'crm_my_tasks' => ['CrmController', 'myTasks'],
    'crm_team_tasks' => ['CrmController', 'teamTasks'],
    'crm_send_sms' => ['SmsController', 'sendCrmSms'],
    'crm_save_lead' => ['CrmController', 'saveLead'],
    'crm_set_lead_stage' => ['CrmController', 'setLeadStage'],
    'crm_list_leads' => ['CrmController', 'listLeads'],
    'crm_get_lead' => ['CrmController', 'getLead'],
    'crm_convert_lead' => ['CrmController', 'convertLead'],
    'crm_customer_timeline' => ['CrmController', 'customerTimeline'],
    'crm_debtors' => ['CrmController', 'debtors'],
    'crm_set_customer_owner' => ['CrmController', 'setCustomerOwner'],
    // میز کار
    'work_projects' => ['WorkController', 'projects'],
    'work_project' => ['WorkController', 'project'],
    'work_save_project' => ['WorkController', 'saveProject'],
    'work_delete_project' => ['WorkController', 'deleteProject'],
    'work_project_member' => ['WorkController', 'projectMember'],
    'work_people' => ['WorkController', 'people'],
    'work_task_save' => ['WorkController', 'taskSave'],
    'work_task_status' => ['WorkController', 'taskStatus'],
    'work_task_complete' => ['WorkController', 'taskComplete'],
    'work_task_approve' => ['WorkController', 'taskApprove'],
    'work_task_return' => ['WorkController', 'taskReturn'],
    'work_task_reopen' => ['WorkController', 'taskReopen'],
    'work_task_delete' => ['WorkController', 'taskDelete'],
    'work_task_order' => ['WorkController', 'taskOrder'],
    'work_task_check' => ['WorkController', 'taskCheck'],
    'work_task_deps' => ['WorkController', 'taskDeps'],
    'work_comment_save' => ['WorkController', 'commentSave'],
    'work_comment_delete' => ['WorkController', 'commentDelete'],
    'work_doc_upload' => ['WorkController', 'docUpload'],
    'work_doc_delete' => ['WorkController', 'docDelete'],
    'work_file' => ['WorkController', 'file'],
    'work_project_options' => ['WorkController', 'projectOptions'],
    'work_meetings' => ['WorkController', 'meetings'],
    'work_meeting' => ['WorkController', 'meeting'],
    'work_meeting_save' => ['WorkController', 'meetingSave'],
    'work_meeting_delete' => ['WorkController', 'meetingDelete'],
    'work_meeting_action' => ['WorkController', 'meetingAction'],
    'work_meeting_files' => ['WorkController', 'meetingFiles'],
    'work_my' => ['WorkController', 'my'],
    // پیام‌رسان
    'chat_list' => ['ChatController', 'listConversations'],
    'chat_open_direct' => ['ChatController', 'openDirect'],
    'chat_create_group' => ['ChatController', 'createGroup'],
    'chat_update_group' => ['ChatController', 'updateGroup'],
    'chat_members' => ['ChatController', 'members'],
    'chat_leave' => ['ChatController', 'leave'],
    'chat_prefs' => ['ChatController', 'prefs'],
    'chat_messages' => ['ChatController', 'messages'],
    'chat_send' => ['ChatController', 'send'],
    'chat_edit' => ['ChatController', 'edit'],
    'chat_delete' => ['ChatController', 'deleteMessage'],
    'chat_react' => ['ChatController', 'react'],
    'chat_pin' => ['ChatController', 'pin'],
    'chat_read' => ['ChatController', 'read'],
    'chat_forward' => ['ChatController', 'forward'],
    'chat_unread' => ['ChatController', 'unread'],
    // کارتابل، پرسنل، ایده‌ها
    'office_requests' => ['OfficeController', 'requests'],
    'office_request' => ['OfficeController', 'request'],
    'office_request_save' => ['OfficeController', 'requestSave'],
    'office_request_act' => ['OfficeController', 'requestAct'],
    'office_request_files' => ['OfficeController', 'requestFiles'],
    'office_pay_options' => ['OfficeController', 'payOptions'],
    'hr_list' => ['OfficeController', 'hrList'],
    'hr_save' => ['OfficeController', 'hrSave'],
    'hr_detail' => ['OfficeController', 'hrDetail'],
    'hr_record' => ['OfficeController', 'hrRecord'],
    'hr_import_team' => ['OfficeController', 'hrImportTeam'],
    'hr_options' => ['OfficeController', 'hrOptions'],
    'ideas_list' => ['OfficeController', 'ideas'],
    'idea_detail' => ['OfficeController', 'idea'],
    'idea_save' => ['OfficeController', 'ideaSave'],
    'idea_delete' => ['OfficeController', 'ideaDelete'],
    'idea_comment' => ['OfficeController', 'ideaComment'],
    'idea_vote' => ['OfficeController', 'ideaVote'],
    'idea_decide' => ['OfficeController', 'ideaDecide'],
    'run_recurring_expense' => ['LookupController', 'runRecurringExpense'],
    'get_budget_report' => ['LookupController', 'budgetReport'],
    'get_salesperson_report' => ['LookupController', 'salespersonReport'],
    'list_trash' => ['TrashController', 'listItems'],
    'get_trash_item' => ['TrashController', 'getItem'],
    'restore_trash_item' => ['TrashController', 'restoreItem'],
    // --- Zeema Home integration ---
    'integration_ping' => ['IntegrationController', 'ping'],
    'integration_create_api_token' => ['IntegrationController', 'createApiToken'],
    'integration_list_api_tokens' => ['IntegrationController', 'listApiTokens'],
    'integration_revoke_api_token' => ['IntegrationController', 'revokeApiToken'],
    'integration_save_settings' => ['IntegrationController', 'saveSettings'],
    'integration_get_settings' => ['IntegrationController', 'getSettings'],
    'integration_upsert_customer' => ['IntegrationController', 'upsertCustomer'],
    'integration_catalog_export' => ['IntegrationController', 'catalogExport'],
    'integration_get_product_images' => ['IntegrationController', 'getProductImages'],
    'integration_receive_product_images' => ['IntegrationController', 'receiveProductImages'],
    'integration_update_product_mode' => ['IntegrationController', 'updateProductMode'],
    'integration_get_stock_snapshot' => ['IntegrationController', 'getStockSnapshot'],
    'integration_create_sales_invoice' => ['IntegrationController', 'createSalesInvoice'],
    'integration_register_payment' => ['IntegrationController', 'registerPayment'],
    'integration_update_delivery_status' => ['IntegrationController', 'updateDeliveryStatus'],
    'integration_get_order_link' => ['IntegrationController', 'getOrderLink'],
    'integration_online_orders_summary' => ['IntegrationController', 'onlineOrdersSummary'],
    'integration_list_outbox' => ['IntegrationController', 'listOutbox'],
    'integration_process_outbox' => ['IntegrationController', 'processOutbox'],
    'integration_create_sso_token' => ['IntegrationController', 'createSsoToken'],
    'integration_upsert_link' => ['IntegrationController', 'upsertLink'],
    'integration_credit_note' => ['IntegrationController', 'creditNote'],
    'integration_upsert_mto_jobs' => ['IntegrationController', 'upsertMtoJobs'],
    'integration_list_mto_jobs' => ['IntegrationController', 'listMtoJobs'],
    'integration_update_mto_status' => ['IntegrationController', 'updateMtoStatus'],
    'integration_reserve_serials' => ['IntegrationController', 'reserveSerials'],
    'integration_alert_stale_mto' => ['IntegrationController', 'alertStaleMto'],
    'integration_reconcile' => ['IntegrationController', 'reconcile'],
];

if ($action === 'download_backup' || $action === 'run_automatic_backup') {
    require_once __DIR__ . "/controllers/BackupController.php";
    $controller = new BackupController($db);
    $methodName = $routes[$action][1];
    $controller->$methodName();
    $db->close();
    exit;
}

if ($action === 'download_app_logs') {
    require_once __DIR__ . "/controllers/LogController.php";
    $controller = new LogController($db);
    $controller->downloadAppLogs($data);
    $db->close();
    exit;
}

// Replay-safe create actions (X-Idempotency-Key).
if (!$integration_authenticated && isset($routes[$action]) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/core/Idempotency.php';
    Idempotency::begin($db, $action);
}

// Recycle bin: snapshot documents before delete actions (stored only if the delete succeeds).
if (!$integration_authenticated && isset($routes[$action])) {
    require_once __DIR__ . '/core/DocumentTrash.php';
    DocumentTrash::captureBeforeAction($db, $action, $data);
}

if (isset($routes[$action])) {
    list($controllerName, $methodName) = $routes[$action];
    $controllerFile = __DIR__ . "/controllers/{$controllerName}.php";

    if (file_exists($controllerFile)) {
        require_once $controllerFile;
        $controller = new $controllerName($db);
        if (method_exists($controller, $methodName)) {
            try {
                $controller->$methodName($data);
            } catch (Throwable $e) {
                send_json(['error' => safe_exception_message($e)], 500);
            }
        } else {
            send_json(['error' => is_app_debug() ? "متد مورد نظر یافت نشد: {$methodName}" : 'عملیات درخواستی یافت نشد.'], 404);
        }
    } else {
        send_json(['error' => is_app_debug() ? "کنترلر مورد نظر یافت نشد: {$controllerName}" : 'عملیات درخواستی یافت نشد.'], 404);
    }
} else {
    send_json(['error' => 'عملیات نامعتبر است.'], 404);
}

$db->close();
