<?php
// /api/controllers/EntityController.php

class EntityController {
    private $conn;

    private $modelMapping = [
        'accounts' => 'Account',
        'customers' => 'Customer',
        'suppliers' => 'Supplier',
        'products' => 'Product',
        'expenses' => 'Expense',
        'users' => 'User',
        'activity_log' => 'ActivityLog',
        'checks' => 'Check',
        'transactions' => 'Transaction',
        'sales_invoices' => 'Invoice',
        'purchase_invoices' => 'Invoice',
        'consignment_sales' => 'Invoice',
        'consignment_purchases' => 'Invoice',
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
                if ($modelName === 'Invoice' || $modelName === 'BaseModel') {
                     require_once __DIR__ . '/../models/BaseModel.php';
                }
                return new $modelName($this->conn);
            }
        }
        return null;
    }
    
    public function getPaginatedData($data) {
        $tableName = $data['tableName'] ?? '';
        $model = $this->getModel($tableName);

        if ($model) {
            $result = $model->getPaginated($data);
            send_json($result);
        } else {
            send_json(['error' => 'Invalid table specified for pagination.'], 400);
        }
    }

    // NOTE: save and delete methods for entities are now moved to their dedicated controllers.
    // This controller is now only responsible for fetching paginated data.
}