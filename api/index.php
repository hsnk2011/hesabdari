<?php
// /api/index.php
session_start();
ini_set('display_errors', 1); // For development only. Should be 0 in production.
error_reporting(E_ALL);

// Set a global exception handler for uncaught exceptions.
set_exception_handler(function($exception) {
    // In a production environment, you should log this error instead of displaying it.
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

// Setup database connection.
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['error' => 'Database connection failed: ' . $conn->connect_error], 500);
}
$conn->set_charset("utf8mb4");

// Get the requested action and input data.
$action = $_GET['action'] ?? '';
$input_data = json_decode(file_get_contents('php://input'), true);

// Define public actions that do not require an active session.
$public_actions = ['login', 'check_session', 'logout'];
if (!in_array($action, $public_actions) && !isset($_SESSION['user_id'])) {
    send_json(['error' => 'Authentication required.'], 401);
}

// ---- Main Routing Table ----
// Maps each action to its corresponding [Controller, Method].
$routes = [
    // Auth Routes -> AuthController
    'login' => ['AuthController', 'login'],
    'logout' => ['AuthController', 'logout'],
    'register' => ['AuthController', 'register'],
    'check_session' => ['AuthController', 'checkSession'],
    'change_password' => ['AuthController', 'changePassword'],
    'admin_reset_password' => ['AuthController', 'adminResetPassword'],
    'delete_user' => ['AuthController', 'deleteUser'],
    
    // Data Fetching Routes -> DataController
    'get_dashboard_data' => ['DataController', 'getDashboardData'],
    'get_all_data_for_reports' => ['DataController', 'getAllDataForReports'],
    'get_full_customers_list' => ['DataController', 'getFullCustomersList'],
    'get_full_suppliers_list' => ['DataController', 'getFullSuppliersList'],
    'get_full_products_list' => ['DataController', 'getFullProductsList'],
    'get_partners' => ['DataController', 'getPartners'],
    
    // --- NEW: Account Routes -> AccountController ---
    'get_full_accounts_list' => ['DataController', 'getFullAccountsList'], // We'll add this method to DataController
    'get_accounts_paginated' => ['AccountController', 'getPaginated'],
    'save_account' => ['AccountController', 'save'],
    'delete_account' => ['AccountController', 'delete'],

    // Invoice Routes -> InvoiceController
    'save_sales_invoice' => ['InvoiceController', 'saveSalesInvoice'],
    'delete_salesInvoice' => ['InvoiceController', 'deleteSalesInvoice'],
    'save_purchase_invoice' => ['InvoiceController', 'savePurchaseInvoice'],
    'delete_purchaseInvoice' => ['InvoiceController', 'deletePurchaseInvoice'],
    'mark_as_consignment' => ['InvoiceController', 'markAsConsignment'],
    'return_from_consignment' => ['InvoiceController', 'returnFromConsignment'],

    // Partner Routes -> PartnerController
    'save_partner' => ['PartnerController', 'savePartner'],
    'delete_partner' => ['PartnerController', 'deletePartner'],
    'save_partner_transaction' => ['PartnerController', 'savePartnerTransaction'],
    'delete_partnerTransaction' => ['PartnerController', 'deletePartnerTransaction'],

    // Generic Entity Routes -> EntityController
    'get_paginated_data' => ['EntityController', 'getPaginatedData'],
    'save_customer' => ['EntityController', 'saveCustomer'],
    'delete_customer' => ['EntityController', 'deleteCustomer'],
    'save_supplier' => ['EntityController', 'saveSupplier'],
    'delete_supplier' => ['EntityController', 'deleteSupplier'],
    'save_product' => ['EntityController', 'saveProduct'],
    'delete_product' => ['EntityController', 'deleteProduct'],
    'save_expense' => ['EntityController', 'saveExpense'],
    'delete_expense' => ['EntityController', 'deleteExpense'],
    'cash_check' => ['CheckController', 'cash'], // Add this line
    'get_account_transactions' => ['AccountController', 'getAccountTransactions'], // <-- ADD THIS

];

// ---- Route Dispatcher ----
if (isset($routes[$action])) {
    list($controllerName, $methodName) = $routes[$action];
    
    // Autoload the required controller file.
    $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';
    
    if (file_exists($controllerFile)) {
        require_once $controllerFile;
        // Instantiate the controller and pass the database connection.
        $controller = new $controllerName($conn);
        
        // Call the designated method on the controller.
        $controller->$methodName($input_data);
    } else {
        send_json(['error' => "Server error: Controller file not found for '{$controllerName}'."], 500);
    }
} else {
    send_json(['error' => 'Action not found: ' . htmlspecialchars($action)], 404);
}

// Gracefully close the database connection at the end of the script.
if ($conn->thread_id) {
    $conn->close();
}