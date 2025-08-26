<?php
// /api/controllers/DataController.php

class DataController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Fetches all necessary data for the main dashboard view.
     */
    public function getDashboardData() {
        $today = date('Y-m-d');
        
        $output = [
            'totalSales' => 0,
            'totalPurchases' => 0,
            'totalExpenses' => 0,
            'profitLoss' => 0,
            'recentSales' => [],
            'dueReceivedChecks' => [],
            'duePayableChecks' => [],
            'expensesByCategory' => []
        ];

        // --- Basic Stats ---
        $sales_res = $this->conn->query("SELECT SUM(totalAmount - discount) as total FROM sales_invoices WHERE is_consignment = 0");
        if ($sales_res) $output['totalSales'] = $sales_res->fetch_assoc()['total'] ?? 0;

        $purchase_res = $this->conn->query("SELECT SUM(totalAmount - discount) as total FROM purchase_invoices WHERE is_consignment = 0");
        if ($purchase_res) $output['totalPurchases'] = $purchase_res->fetch_assoc()['total'] ?? 0;

        $expenses_res = $this->conn->query("SELECT SUM(amount) as total FROM expenses");
        if ($expenses_res) $output['totalExpenses'] = $expenses_res->fetch_assoc()['total'] ?? 0;

        $output['profitLoss'] = $output['totalSales'] - $output['totalPurchases'];

        // --- Recent Sales ---
        $recent_sales_res = $this->conn->query("
            SELECT si.id, si.totalAmount, c.name as customerName
            FROM sales_invoices si
            LEFT JOIN customers c ON si.customerId = c.id
            WHERE si.is_consignment = 0
            ORDER BY si.id DESC
            LIMIT 5
        ");
        if ($recent_sales_res) $output['recentSales'] = $recent_sales_res->fetch_all(MYSQLI_ASSOC);

        // --- Due Checks ---
        $due_received_res = $this->conn->query("
            SELECT checkNumber, amount, dueDate FROM checks
            WHERE type = 'received' AND status = 'in_hand' AND dueDate >= '{$today}'
            ORDER BY dueDate ASC
            LIMIT 5
        ");
        if ($due_received_res) $output['dueReceivedChecks'] = $due_received_res->fetch_all(MYSQLI_ASSOC);

        $due_payable_res = $this->conn->query("
            SELECT checkNumber, amount, dueDate FROM checks
            WHERE type = 'payable' AND status = 'payable' AND dueDate >= '{$today}'
            ORDER BY dueDate ASC
            LIMIT 5
        ");
        if ($due_payable_res) $output['duePayableChecks'] = $due_payable_res->fetch_all(MYSQLI_ASSOC);
        
        // --- Expenses by Category for Chart.js ---
        $expenses_cat_res = $this->conn->query("
            SELECT category, SUM(amount) as total
            FROM expenses
            GROUP BY category
            ORDER BY total DESC
        ");
        if ($expenses_cat_res) {
            $cats = [];
            while ($row = $expenses_cat_res->fetch_assoc()) {
                $cats[$row['category']] = (float)$row['total'];
            }
            $output['expensesByCategory'] = $cats;
        }

        send_json($output);
    }
    
    /**
     * Fetches a single entity by its ID, including related data.
     * Used for populating modals from clickable report links.
     */
    public function getEntityById($data) {
        $entityType = $data['entityType'] ?? '';
        $id = intval($data['id'] ?? 0);

        if (empty($entityType) || $id <= 0) {
            send_json(['error' => 'اطلاعات نامعتبر است.'], 400);
            return;
        }

        $entity = null;

        switch ($entityType) {
            case 'salesInvoice':
            case 'purchaseInvoice':
                require_once __DIR__ . '/../models/Invoice.php';
                $invoiceModel = new Invoice($this->conn);
                $type = ($entityType === 'salesInvoice') ? 'sales' : 'purchase';
                $table = ($type === 'sales') ? 'sales_invoices' : 'purchase_invoices';
                $res = $this->conn->query("SELECT * FROM `{$table}` WHERE id = {$id}");
                if ($res && $res->num_rows > 0) {
                    $invoice_data = $res->fetch_all(MYSQLI_ASSOC);
                    $entity = $invoiceModel->fetchInvoiceDetails($invoice_data, $type)[0];
                }
                break;
            
            case 'expense':
                $res = $this->conn->query("SELECT * FROM expenses WHERE id = {$id}");
                if ($res) $entity = $res->fetch_assoc();
                break;
        }

        if ($entity) {
            send_json($entity);
        } else {
            send_json(['error' => 'موجودیت یافت نشد.'], 404);
        }
    }
    
    /**
     * Handles server-side searching for Select2 dropdowns.
     * (FIXED to be compatible with older database drivers by avoiding get_result)
     */
    public function searchEntities($data) {
        $entityType = $data['entityType'] ?? '';
        $term = $data['term'] ?? '';
        $results = [];

        switch ($entityType) {
            case 'customers':
            case 'suppliers':
                $table = ($entityType === 'customers') ? 'customers' : 'suppliers';
                $searchTerm = "%{$term}%";
                $stmt = $this->conn->prepare("SELECT id, name AS text FROM `{$table}` WHERE name LIKE ? LIMIT 30");
                $stmt->bind_param("s", $searchTerm);
                $stmt->execute();
                
                // *** FIX: Replaced get_result() with compatible data fetching method ***
                $stmt->store_result();
                $meta = $stmt->result_metadata();
                $row = [];
                $fields = [];
                while ($field = $meta->fetch_field()) {
                    $fields[] = &$row[$field->name];
                }
                call_user_func_array([$stmt, 'bind_result'], $fields);

                while ($stmt->fetch()) {
                    $c = [];
                    foreach ($row as $key => $val) {
                        $c[$key] = $val;
                    }
                    $results[] = $c;
                }
                $stmt->close();
                break;
            
            case 'products':
                require_once __DIR__ . '/../models/Product.php';
                $productModel = new Product($this->conn);
                $products = $productModel->searchByNameWithStock($term);
                // Format for Select2, ensuring other data like 'stock' is preserved
                foreach ($products as $p) {
                    $results[] = [
                        'id' => $p['id'],
                        'text' => $p['name'],
                        'stock' => $p['stock']
                    ];
                }
                break;
        }
        
        send_json(['results' => $results]);
    }


    // --- Methods for fetching full lists for dropdowns ---
    public function getFullCustomersList() {
        $res = $this->conn->query("SELECT * FROM `customers` ORDER BY name ASC");
        send_json($res->fetch_all(MYSQLI_ASSOC));
    }
    
    public function getFullSuppliersList() {
        $res = $this->conn->query("SELECT * FROM `suppliers` ORDER BY name ASC");
        send_json($res->fetch_all(MYSQLI_ASSOC));
    }

    public function getFullProductsList() {
        require_once __DIR__ . '/../models/Product.php';
        $model = new Product($this->conn);
        send_json($model->getFullListWithStock());
    }

    public function getPartners() {
        $res = $this->conn->query("SELECT id, name, share FROM `partners` ORDER BY name ASC");
        send_json($res->fetch_all(MYSQLI_ASSOC));
    }
    
    public function getFullAccountsList() {
        require_once __DIR__ . '/../models/Account.php';
        $model = new Account($this->conn);
        send_json($model->getAll());
    }
}