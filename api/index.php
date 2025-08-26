<?php
// /api/index.php
session_start();
ini_set('display_errors', 1); // For development only. Should be 0 in production.
error_reporting(E_ALL);

set_exception_handler(function($exception) {
    $response = [
        'error' => 'PHP Exception',
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine()
    ];
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/helpers.php';

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['error' => 'Database connection failed: ' . $conn->connect_error], 500);
}
$conn->set_charset("utf8mb4");

$action = $_GET['action'] ?? '';
$input_data = json_decode(file_get_contents('php://input'), true);

$public_actions = ['login', 'check_session', 'logout'];
if (!in_array($action, $public_actions) && !isset($_SESSION['user_id'])) {
    send_json(['error' => 'Authentication required.'], 401);
}

// ---- Main Routing Table ----
$routes = [
    // Auth Routes
    'login' => ['AuthController', 'login'],
    'logout' => ['AuthController', 'logout'],
    'register' => ['AuthController', 'register'],
    'check_session' => ['AuthController', 'checkSession'],
    'change_password' => ['AuthController', 'changePassword'],
    'admin_reset_password' => ['AuthController', 'adminResetPassword'],
    'delete_user' => ['AuthController', 'deleteUser'],
    
    // Data Fetching Routes
    'get_dashboard_data' => ['DataController', 'getDashboardData'],
    'get_full_customers_list' => ['DataController', 'getFullCustomersList'],
    'get_full_suppliers_list' => ['DataController', 'getFullSuppliersList'],
    'get_full_products_list' => ['DataController', 'getFullProductsList'],
    'get_partners' => ['DataController', 'getPartners'],
    'get_full_accounts_list' => ['DataController', 'getFullAccountsList'],
    'get_entity_by_id' => ['DataController', 'getEntityById'],
    'search_entities' => ['DataController', 'searchEntities'], // <-- مسیر جدید برای جستجو
    
    // Account Routes
    'get_accounts_paginated' => ['AccountController', 'getPaginated'],
    'save_account' => ['AccountController', 'save'],
    'delete_account' => ['AccountController', 'delete'],
    'get_account_transactions' => ['AccountController', 'getAccountTransactions'],

    // Invoice Routes
    'save_sales_invoice' => ['InvoiceController', 'saveSalesInvoice'],
    'delete_salesInvoice' => ['InvoiceController', 'deleteSalesInvoice'],
    'save_purchase_invoice' => ['InvoiceController', 'savePurchaseInvoice'],
    'delete_purchaseInvoice' => ['InvoiceController', 'deletePurchaseInvoice'],
    'mark_as_consignment' => ['InvoiceController', 'markAsConsignment'],
    'return_from_consignment' => ['InvoiceController', 'returnFromConsignment'],

    // Partner Routes
    'save_partner' => ['PartnerController', 'savePartner'],
    'delete_partner' => ['PartnerController', 'deletePartner'],
    'save_partner_transaction' => ['PartnerController', 'savePartnerTransaction'],
    'delete_partnerTransaction' => ['PartnerController', 'deletePartnerTransaction'],

    // Generic Entity Routes
    'get_paginated_data' => ['EntityController', 'getPaginatedData'],
    'save_customer' => ['EntityController', 'saveCustomer'],
    'delete_customer' => ['EntityController', 'deleteCustomer'],
    'save_supplier' => ['EntityController', 'saveSupplier'],
    'delete_supplier' => ['EntityController', 'deleteSupplier'],
    'save_product' => ['EntityController', 'saveProduct'],
    'delete_product' => ['EntityController', 'deleteProduct'],
    'save_expense' => ['EntityController', 'saveExpense'],
    'delete_expense' => ['EntityController', 'deleteExpense'],
    
    // Check Routes
    'cash_check' => ['CheckController', 'cash'],
    'clear_payable_check' => ['CheckController', 'clearPayable'],
    
    // Report Routes
    'get_profit_loss_report' => ['ReportController', 'getProfitLossReport'],
    'get_person_statement' => ['ReportController', 'getPersonStatement'],
    'get_account_statement' => ['ReportController', 'getAccountStatement'],
    'get_invoices_report' => ['ReportController', 'getInvoicesReport'],
    'get_expenses_report' => ['ReportController', 'getExpensesReport'],
    'get_inventory_report' => ['ReportController', 'getInventoryReport'],
    'get_inventory_value_report' => ['ReportController', 'getInventoryValueReport'],
    'get_inventory_ledger_report' => ['ReportController', 'getInventoryLedgerReport'],
];

// ---- Route Dispatcher ----
if (isset($routes[$action])) {
    list($controllerName, $methodName) = $routes[$action];
    
    $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';
    
    if (file_exists($controllerFile)) {
        require_once $controllerFile;
        $controller = new $controllerName($conn);
        $controller->$methodName($input_data);
    } else {
        send_json(['error' => "Server error: Controller file not found for '{$controllerName}'."], 500);
    }
} else {
    send_json(['error' => 'Action not found: ' . htmlspecialchars($action)], 404);
}

if ($conn->thread_id) {
    $conn->close();
}