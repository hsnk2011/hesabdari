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
        // Permission check is handled by the router
        try {
            $data = $this->dashboardModel->getDashboardData();
            send_json($data);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت داده‌های داشبورد: ' . $e->getMessage()], 500);
        }
    }

    public function getNotifications() {
        // Permission check is handled by the router
        try {
            $data = $this->dashboardModel->getNotificationsData();
            send_json($data);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت اعلان‌ها: ' . $e->getMessage()], 500);
        }
    }

    // START: New method for Smart Alerts
    public function getSmartAlerts() {
        // Permission check is handled by the router
        try {
            $data = $this->dashboardModel->getSmartAlerts();
            send_json($data);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت هشدارهای هوشمند: ' . $e->getMessage()], 500);
        }
    }
    // END: New method

    public function getDueChecksList() {
        // Permission check is handled by the router
        try {
            $data = $this->dashboardModel->getDueChecksList();
            send_json($data);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت لیست چک‌ها: ' . $e->getMessage()], 500);
        }
    }
    
    public function getEntityById($data) {
        try {
            if (!isset($_SESSION['user_id'])) {
                send_json(['error' => 'دسترسی غیرمجاز.'], 401);
                return;
            }

            $entityType = $data['entityType'] ?? '';
            $id = intval($data['id'] ?? 0);
            $findBy = $data['by'] ?? 'id';

            if (empty($entityType) || $id <= 0) {
                send_json(['error' => 'اطلاعات نامعتبر است.'], 400);
                return;
            }

            // Permission check based on entity type
            $permissionMap = [
                'salesInvoice' => 'manage_sales_invoices',
                'purchaseInvoice' => 'manage_purchase_invoices',
                'salesReturn' => 'manage_return_invoices',
                'purchaseReturn' => 'manage_return_invoices',
                'expense' => 'manage_transactions',
                'income' => 'manage_transactions',
                'payment' => 'manage_transactions',
                // Reading a check while editing an endorse payment needs either checks or transactions access
                'check' => ['manage_checks', 'manage_transactions']
            ];

            if (isset($permissionMap[$entityType])) {
                $required = $permissionMap[$entityType];
                if (is_array($required)) {
                    $userPerms = $_SESSION['permissions'] ?? [];
                    $ok = false;
                    foreach ($required as $perm) {
                        if (in_array($perm, $userPerms, true)) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
                        return;
                    }
                    $allowed_entities = $_SESSION['allowed_entities'] ?? [];
                    $current_entity = $_SESSION['current_entity_id'] ?? null;
                    if ($current_entity === null || !in_array($current_entity, $allowed_entities)) {
                        send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
                        return;
                    }
                } else {
                    check_permission($required);
                }
            } else {
                send_json(['error' => 'نوع موجودیت برای بررسی دسترسی نامعتبر است.'], 400);
                return;
            }

            $entity = null;
            
            $entityTableMap = [
                'salesInvoice' => 'sales_invoices',
                'purchaseInvoice' => 'purchase_invoices',
                'salesReturn' => 'return_invoices',
                'purchaseReturn' => 'return_invoices',
                'expense' => 'expenses',
                'income' => 'incomes',
                'payment' => 'payments',
                'check' => 'checks'
            ];

            if (!isset($entityTableMap[$entityType])) {
                send_json(['error' => 'نوع موجودیت نامعتبر است.'], 400);
                return;
            }
            
            $table = $entityTableMap[$entityType];
            $columnToSearch = ($findBy === 'checkId' && $table === 'payments') ? 'checkId' : 'id';

            $currentEntity = (int)($_SESSION['current_entity_id'] ?? 0);
            $typeFilter = '';
            if ($entityType === 'salesReturn') $typeFilter = " AND `type` = 'sales_return'";
            if ($entityType === 'purchaseReturn') $typeFilter = " AND `type` = 'purchase_return'";

            // For payments looked up by checkId there can be several rows
            // (original receipt + endorse + bounce). Prefer the right one.
            $preferType = isset($data['preferType']) ? trim((string)$data['preferType']) : '';
            $allowedPrefer = ['check', 'endorse_check', 'check_bounce', 'cash', 'setoff'];
            if ($columnToSearch === 'checkId' && $preferType === '' && $table === 'payments') {
                $stStatus = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ? LIMIT 1");
                $stStatus->bind_param('ii', $id, $currentEntity);
                $stStatus->execute();
                $chkStatus = db_stmt_to_assoc_array($stStatus)[0]['status'] ?? null;
                if ($chkStatus === 'endorsed') {
                    $preferType = 'endorse_check';
                } else {
                    $preferType = 'check';
                }
            }

            $result = [];
            if ($columnToSearch === 'checkId' && $preferType !== '' && in_array($preferType, $allowedPrefer, true)) {
                $stmt = $this->conn->prepare(
                    "SELECT * FROM `{$table}` WHERE `checkId` = ? AND entity_id = ? AND `type` = ? ORDER BY id DESC LIMIT 1"
                );
                $stmt->bind_param("iis", $id, $currentEntity, $preferType);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);
            }
            if (empty($result)) {
                $orderBy = ($columnToSearch === 'checkId') ? ' ORDER BY id DESC' : '';
                $stmt = $this->conn->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$columnToSearch}` = ? AND entity_id = ?{$typeFilter}{$orderBy} LIMIT 1"
                );
                $stmt->bind_param("ii", $id, $currentEntity);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);
            }

            if (!empty($result)) {
                $entity = $result[0];
                if ($entityType === 'salesInvoice' || $entityType === 'purchaseInvoice') {
                    $invoiceModel = new Invoice($this->conn);
                    $type = ($entityType === 'salesInvoice') ? 'sales' : 'purchase';
                    $entity = $invoiceModel->fetchInvoiceDetails([$entity], $type)[0];
                } else if ($entityType === 'salesReturn' || $entityType === 'purchaseReturn') {
                    require_once __DIR__ . '/../models/ReturnInvoice.php';
                    $returnInvoiceModel = new ReturnInvoice($this->conn);
                    $type = ($entityType === 'salesReturn') ? 'sales_return' : 'purchase_return';
                    $entity = $returnInvoiceModel->fetchReturnInvoiceDetails([$entity], $type)[0];
                }
            }

            if ($entity) {
                send_json($entity);
            } else {
                send_json(['error' => 'موجودیت یافت نشد.'], 404);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت موجودیت: ' . $e->getMessage()], 500);
        }
    }

    public function getFullCustomersList() {
        // Permission check is handled by the router
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("SELECT * FROM `customers` WHERE entity_id = ? ORDER BY name ASC");
            $stmt->bind_param("i", $entity_id);
            $stmt->execute();
            send_json(db_stmt_to_assoc_array($stmt));
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getFullSuppliersList() {
        // Permission check is handled by the router
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("SELECT s.*, c.id AS linked_customer_id FROM `suppliers` s LEFT JOIN customers c ON c.linked_supplier_id = s.id AND c.entity_id = s.entity_id WHERE s.entity_id = ? ORDER BY s.name ASC");
            $stmt->bind_param("i", $entity_id);
            $stmt->execute();
            send_json(db_stmt_to_assoc_array($stmt));
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getFullContactsList() {
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("SELECT * FROM `contacts` WHERE entity_id = ? ORDER BY name ASC");
            $stmt->bind_param("i", $entity_id);
            $stmt->execute();
            send_json(db_stmt_to_assoc_array($stmt));
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getFullProductsList() {
        // Permission check is handled by the router
        try {
            $model = new Product($this->conn); // CORRECTED: Was "new ProductModel"
            send_json($model->getFullListWithStock());
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function getPartners() {
        // Permission check is handled by the router
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("SELECT id, name, phone, share FROM `partners` WHERE entity_id = ? ORDER BY name ASC");
            $stmt->bind_param("i", $entity_id);
            $stmt->execute();
            send_json(db_stmt_to_assoc_array($stmt));
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    public function getFullAccountsList() {
        // Permission check is handled by the router
        try {
            $model = new Account($this->conn);
            send_json($model->getAll());
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    public function globalSearch($data) {
        require_once __DIR__ . '/../models/GlobalSearch.php';
        try {
            $model = new GlobalSearch($this->conn);
            $result = $model->search($data['query'] ?? '');
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 400);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام جستجو: ' . $e->getMessage()], 500);
        }
    }
}