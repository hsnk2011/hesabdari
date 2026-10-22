<?php
// /api/controllers/EntityController.php

class EntityController {
    private $conn;

    private $modelMapping = [
        'accounts' => 'Account',
        'customers' => 'Customer',
        'suppliers' => 'Supplier',
        'contacts' => 'Contact',
        'products' => 'Product',
        'expenses' => 'Expense',
        'incomes' => 'Income',
        'users' => 'User',
        'activity_log' => 'ActivityLog',
        'checks' => 'Check',
        'transactions' => 'Transaction',
        'sales_invoices' => 'Invoice',
        'purchase_invoices' => 'Invoice',
        'consignment_sales' => 'Invoice',
        'consignment_purchases' => 'Invoice',
        'return_invoices' => 'ReturnInvoice',
        'sales_returns' => 'ReturnInvoice',
        'purchase_returns' => 'ReturnInvoice',
        'quotations' => 'Quotation',
    ];

    public function __construct($db) {
        $this->conn = $db;
    }

    private function getModel($entityType) {
        if (isset($this->modelMapping[$entityType])) {
            $modelName = $this->modelMapping[$entityType];
            $modelFile = __DIR__ . '/../models/' . $modelName . '.php';
            if (file_exists($modelFile)) {
                require_once $modelFile;
                return new $modelName($this->conn);
            }
        }
        return null;
    }
    
    public function getPaginatedData($data) {
        try {
            $tableName = $data['tableName'] ?? '';
            $model = $this->getModel($tableName);

            if ($model) {
                // The permission check is now handled centrally in the router (api/index.php)
                $result = $model->getPaginated($data);
                send_json($result);
            } else {
                send_json(['error' => 'جدول نامعتبر برای صفحه‌بندی مشخص شده است.'], 400);
            }
        } catch (Throwable $e) {
            // Never answer with an empty list on failure: users read "no invoices"
            // as real data. Log and report the error instead.
            send_json(['error' => safe_exception_message($e, 'خطا در دریافت لیست. لطفاً دوباره تلاش کنید.')], 500);
        }
    }
}