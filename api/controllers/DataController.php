<?php
// /api/controllers/DataController.php

require_once __DIR__ . '/../models/DashboardModel.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Invoice.php';

class DataController {
    private $conn;
    private $dashboardModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->dashboardModel = new DashboardModel($db);
    }

    public function getDashboardData() {
        $data = $this->dashboardModel->getDashboardData();
        send_json($data);
    }

    public function getNotifications() {
        $data = $this->dashboardModel->getNotificationsData();
        send_json($data);
    }

    public function getDueChecksList() {
        $data = $this->dashboardModel->getDueChecksList();
        send_json($data);
    }
    
    public function getEntityById($data) {
        $entityType = $data['entityType'] ?? '';
        $id = intval($data['id'] ?? 0);
        $findBy = $data['by'] ?? 'id'; // Default to finding by primary key 'id'

        if (empty($entityType) || $id <= 0) {
            send_json(['error' => 'اطلاعات نامعتبر است.'], 400);
            return;
        }

        $entity = null;
        
        $entityTableMap = [
            'salesInvoice' => 'sales_invoices',
            'purchaseInvoice' => 'purchase_invoices',
            'expense' => 'expenses',
            'payment' => 'payments',
            'check' => 'checks'
        ];

        if (!isset($entityTableMap[$entityType])) {
            send_json(['error' => 'نوع موجودیت نامعتبر است.'], 400);
            return;
        }
        
        $table = $entityTableMap[$entityType];
        $columnToSearch = ($findBy === 'checkId' && $table === 'payments') ? 'checkId' : 'id';

        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE `{$columnToSearch}` = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);

        if (!empty($result)) {
            $entity = $result[0];
            if ($entityType === 'salesInvoice' || $entityType === 'purchaseInvoice') {
                $invoiceModel = new Invoice($this->conn);
                $type = ($entityType === 'salesInvoice') ? 'sales' : 'purchase';
                $entity = $invoiceModel->fetchInvoiceDetails([$entity], $type)[0];
            }
        }

        if ($entity) {
            send_json($entity);
        } else {
            send_json(['error' => 'موجودیت یافت نشد.'], 404);
        }
    }

    // --- Methods for fetching full lists for dropdowns ---
    public function getFullCustomersList() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT * FROM `customers` WHERE entity_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        send_json(db_stmt_to_assoc_array($stmt));
    }
    
    public function getFullSuppliersList() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT * FROM `suppliers` WHERE entity_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        send_json(db_stmt_to_assoc_array($stmt));
    }

    public function getFullProductsList() {
        $model = new Product($this->conn);
        send_json($model->getFullListWithStock());
    }

    public function getPartners() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT id, name, share FROM `partners` WHERE entity_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        send_json(db_stmt_to_assoc_array($stmt));
    }
    
    public function getFullAccountsList() {
        $model = new Account($this->conn);
        send_json($model->getAll());
    }
}