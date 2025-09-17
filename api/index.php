<?php
// /api/index.php

/**
 * Main API Router
 * This file routes all incoming API requests to the appropriate controller and method.
 */

// Start session and set headers
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
header("Content-Type: application/json; charset=UTF-8");

// Load essential files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/helpers.php';

// --- SECURITY IMPROVEMENT: CSRF TOKEN VALIDATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    if ($action !== 'login') {
        if (!verify_csrf_token()) {
            send_json(['error' => 'درخواست نامعتبر است. لطفاً صفحه را رفرش کرده و مجدداً تلاش کنید.'], 403);
            exit();
        }
    }
}
// --- END SECURITY IMPROVEMENT ---


// Database Connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['error' => 'Connection failed: ' . $conn->connect_error], 500);
}
$conn->set_charset("utf8mb4");

// Load Controllers
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DataController.php';
require_once __DIR__ . '/controllers/EntityController.php';
require_once __DIR__ . '/controllers/CustomerController.php';
require_once __DIR__ . '/controllers/SupplierController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/ExpenseController.php';
require_once __DIR__ . '/controllers/InvoiceController.php';
require_once __DIR__ . '/controllers/PaymentController.php';
require_once __DIR__ . '/controllers/CheckController.php';
require_once __DIR__ . '/controllers/AccountController.php';
require_once __DIR__ . '/controllers/ReportController.php';
require_once __DIR__ . '/controllers/PartnerController.php';
require_once __DIR__ . '/controllers/TransactionController.php';
require_once __DIR__ . '/controllers/SettingsController.php';
require_once __DIR__ . '/controllers/InventoryController.php'; // This controller has been patched
require_once __DIR__ . '/controllers/ShareController.php';


// Get the requested action
$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Public actions (no session required)
$public_actions = ['login'];

// Check if user is logged in for protected actions
if (!in_array($action, $public_actions) && !isset($_SESSION['user_id'])) {
    send_json(['error' => 'Authentication required.'], 401);
}

// Instantiate controllers
$authController = new AuthController($conn);
$dataController = new DataController($conn);
$entityController = new EntityController($conn);
$customerController = new CustomerController($conn);
$supplierController = new SupplierController($conn);
$productController = new ProductController($conn);
$expenseController = new ExpenseController($conn);
$invoiceController = new InvoiceController($conn);
$paymentController = new PaymentController($conn);
$checkController = new CheckController($conn);
$accountController = new AccountController($conn);
$reportController = new ReportController($conn);
$partnerController = new PartnerController($conn);
$transactionController = new TransactionController($conn);
$settingsController = new SettingsController($conn);
$inventoryController = new InventoryController($conn);
$shareController = new ShareController($conn);


// Route the request
switch ($action) {
    // Auth
    case 'login': $authController->login($data); break;
    case 'logout': $authController->logout(); break;
    case 'register': $authController->register($data); break;
    case 'check_session': $authController->checkSession(); break;
    case 'change_password': $authController->changePassword($data); break;
    case 'admin_reset_password': $authController->adminResetPassword($data); break;
    case 'delete_user': $authController->deleteUser($data); break;

    // Data Fetching (Dropdowns, Dashboard, etc.)
    case 'get_dashboard_data': $dataController->getDashboardData(); break;
    case 'get_notifications': $dataController->getNotifications(); break;
    case 'get_due_checks_list': $dataController->getDueChecksList(); break;
    case 'get_entity_by_id': $dataController->getEntityById($data); break;
    case 'get_full_customers_list': $dataController->getFullCustomersList(); break;
    case 'get_full_suppliers_list': $dataController->getFullSuppliersList(); break;
    case 'get_full_products_list': $dataController->getFullProductsList(); break;
    case 'get_partners': $dataController->getPartners(); break;
    case 'get_full_accounts_list': $dataController->getFullAccountsList(); break;
    
    // Generic Paginated Data
    case 'get_paginated_data':
        $tableName = $data['tableName'] ?? '';
        if ($tableName === 'transactions') {
            $transactionController->getPaginatedData($data);
        } else {
            $entityController->getPaginatedData($data);
        }
        break;

    // Entity Specific Actions (Save/Delete)
    case 'save_customer': $customerController->save($data); break;
    case 'delete_customer': $customerController->delete($data); break;
    case 'save_supplier': $supplierController->save($data); break;
    case 'delete_supplier': $supplierController->delete($data); break;
    case 'save_product': $productController->save($data); break;
    case 'delete_product': $productController->delete($data); break;
    case 'save_expense': $expenseController->save($data); break;
    case 'delete_expense': $expenseController->delete($data); break;
    case 'save_account': $accountController->save($data); break;
    case 'delete_account': $accountController->delete($data); break;
    case 'get_account_transactions': $accountController->getAccountTransactions($data); break;
    case 'save_partner': $partnerController->savePartner($data); break;
    case 'delete_partner': $partnerController->deletePartner($data); break;

    // Invoice Actions
    case 'save_sales_invoice': $invoiceController->saveSalesInvoice($data); break;
    case 'delete_sales_invoice': $invoiceController->deleteSalesInvoice($data); break;
    case 'save_purchase_invoice': $invoiceController->savePurchaseInvoice($data); break;
    case 'delete_purchase_invoice': $invoiceController->deletePurchaseInvoice($data); break;
    case 'mark_as_consignment': $invoiceController->markAsConsignment($data); break;
    case 'return_from_consignment': $invoiceController->returnFromConsignment($data); break;

    // Payment & Check Actions
    case 'save_payment': $paymentController->savePayment($data); break;
    case 'delete_payment': $paymentController->deletePayment($data); break;
    case 'cash_check': $checkController->cash($data); break;
    case 'clear_payable_check': $checkController->clearPayable($data); break;
    case 'save_check': $checkController->save($data); break;
    case 'delete_check': $checkController->delete($data); break;

    // Reports
    case 'get_profit_loss_report': $reportController->getProfitLossReport($data); break;
    case 'get_invoices_report': $reportController->getInvoicesReport($data); break;
    case 'get_inventory_ledger_report': $reportController->getInventoryLedgerReport($data); break;
    case 'get_person_statement': $reportController->getPersonStatement($data); break;
    case 'get_account_statement': $reportController->getAccountStatement($data); break;
    case 'get_expenses_report': $reportController->getExpensesReport($data); break;
    case 'get_inventory_report': $reportController->getInventoryReport($data); break;
    case 'get_inventory_value_report': $reportController->getInventoryValueReport($data); break;
    case 'get_cogs_profit_report': $reportController->getCogsProfitReport($data); break;
    case 'export_report': $reportController->exportReport($_GET); break; // Note: Uses GET

    // Settings
    case 'switch_entity': $settingsController->switchEntity($data); break;
    case 'get_app_settings': $settingsController->getAppSettings(); break;
    case 'save_app_settings': $settingsController->saveAppSettings($data); break;

    // Inventory Module Actions (Temporarily disabled unused parts)
    case 'list_warehouses': $inventoryController->listWarehouses($data); break;
    case 'transfer_stock': $inventoryController->transfer($data); break;
    
    // Product Attributes and Variants
    case 'update_product_attributes': $productController->updateAttributes($data); break;
    case 'list_product_variants': $productController->listVariants($data); break;
    case 'label_data': $productController->labelData($data); break;

    // Sharing Module
    case 'generate_proforma': $shareController->generateProforma($data); break;


    default:
        send_json(['error' => 'Action not found.'], 404);
        break;
}

$conn->close();