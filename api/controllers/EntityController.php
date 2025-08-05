<?php
// /api/controllers/EntityController.php

class EntityController {
    private $conn;

    private $modelMapping = [
        'accounts' => 'Account', // <-- این خط اضافه شد
        'customers' => 'Customer',
        'suppliers' => 'Supplier',
        'products' => 'Product',
        'expenses' => 'Expense',
        'transactions' => 'Transaction',
        'users' => 'User',
        'activity_log' => 'ActivityLog',
        'checks' => 'Check',
        'partner_transactions' => 'PartnerTransaction',
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
                return new $modelName($this->conn);
            }
        }
        return null;
    }
    
    // ... (بقیه متدهای این فایل بدون تغییر باقی می‌مانند) ...
    public function getPaginatedData($data) {
        $tableName = $data['tableName'] ?? '';
        $model = $this->getModel($tableName);

        if ($model) {
            if ($tableName === 'sales_invoices' || $tableName === 'purchase_invoices' || $tableName === 'consignment_sales' || $tableName === 'consignment_purchases') {
                $type = (strpos($tableName, 'sales') !== false) ? 'sales' : 'purchase';
                $is_consignment = (strpos($tableName, 'consignment') !== false);
                $data['is_consignment'] = $is_consignment;
                $result = $model->getPaginated($type, $data);
            } else {
                $result = $model->getPaginated($data);
            }
            send_json($result);
        } else {
            send_json(['error' => 'Invalid table specified for pagination.'], 400);
        }
    }

    private function saveEntity($entityName, $data) {
        $model = $this->getModel($entityName . 's');
        if ($model) {
            $result = $model->save($data);
            if (isset($result['success'])) {
                $is_new = empty($data['id']);
                $log_description = "موجودیت «{$data['name']}» از نوع {$entityName} با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
                log_activity($this->conn, 'SAVE_' . strtoupper($entityName), $log_description);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } else {
            send_json(['error' => 'Invalid entity type for saving.'], 400);
        }
    }

    private function deleteEntity($entityName, $data) {
        $id = $data['id'] ?? null;
        $model = $this->getModel($entityName . 's');
        if ($model) {
            $result = $model->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_' . strtoupper($entityName), "موردی از جدول {$entityName}s با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } else {
            send_json(['error' => 'Invalid entity type for deletion.'], 400);
        }
    }

    public function saveCustomer($data) { $this->saveEntity('customer', $data); }
    public function deleteCustomer($data) { $this->deleteEntity('customer', $data); }

    public function saveSupplier($data) { $this->saveEntity('supplier', $data); }
    public function deleteSupplier($data) { $this->deleteEntity('supplier', $data); }

    public function saveProduct($data) { $this->saveEntity('product', $data); }
    public function deleteProduct($data) { $this->deleteEntity('product', $data); }

    public function saveExpense($data) { $this->saveEntity('expense', $data); }
    public function deleteExpense($data) { $this->deleteEntity('expense', $data); }
}