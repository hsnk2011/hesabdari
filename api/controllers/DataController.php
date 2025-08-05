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
        $seven_days_ago = date('Y-m-d', strtotime('-7 days'));

        $output = [
            'totalSales' => 0,
            'totalPurchases' => 0,
            'totalExpenses' => 0,
            'profitLoss' => 0,
            'recentSales' => [],
            'dueReceivedChecks' => [],
            'duePayableChecks' => [],
            'expensesByCategory' => [] // داده جدید برای نمودار
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
        
        // --- NEW: Expenses by Category for Chart.js ---
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
     * Fetches all data from all tables for client-side reporting.
     */
    public function getAllDataForReports() {
        $data = [
            'customers' => [], 'suppliers' => [], 'products' => [],
            'sales_invoices' => [], 'purchase_invoices' => [],
            'expenses' => [], 'partners' => [], 'partner_transactions' => [],
            'accounts' => [], 'checks' => []
        ];

        // Using Invoice model to fetch detailed invoices
        require_once __DIR__ . '/../models/Invoice.php';
        $invoiceModel = new Invoice($this->conn);

        // Fetch all regular and consignment invoices
        $all_sales_res = $this->conn->query("SELECT si.*, c.name as customerName FROM `sales_invoices` si LEFT JOIN customers c ON si.customerId = c.id ORDER BY si.date DESC");
        $all_purchases_res = $this->conn->query("SELECT pi.*, s.name as supplierName FROM `purchase_invoices` pi LEFT JOIN suppliers s ON pi.supplierId = s.id ORDER BY pi.date DESC");

        if ($all_sales_res) {
            $sales_invoices = $all_sales_res->fetch_all(MYSQLI_ASSOC);
            $data['sales_invoices'] = $invoiceModel->fetchInvoiceDetails($sales_invoices, 'sales');
        }
        if ($all_purchases_res) {
            $purchase_invoices = $all_purchases_res->fetch_all(MYSQLI_ASSOC);
            $data['purchase_invoices'] = $invoiceModel->fetchInvoiceDetails($purchase_invoices, 'purchase');
        }

        // Fetch other tables
        $tables_to_fetch = [
            'customers' => "SELECT * FROM customers",
            'suppliers' => "SELECT * FROM suppliers",
            'products' => "SELECT p.*, ps.dimensions, ps.quantity FROM products p LEFT JOIN product_stock ps ON p.id = ps.product_id",
            'expenses' => "SELECT * FROM expenses",
            'partners' => "SELECT * FROM partners",
            'partner_transactions' => "SELECT * FROM partner_transactions",
            'accounts' => "SELECT * FROM accounts",
            'checks' => "SELECT * FROM checks"
        ];
        
        // Special handling for products to aggregate stock
        $products_res = $this->conn->query($tables_to_fetch['products']);
        $products_map = [];
        if($products_res) {
            while($row = $products_res->fetch_assoc()) {
                $product_id = $row['id'];
                if(!isset($products_map[$product_id])) {
                    $products_map[$product_id] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'stock' => []
                    ];
                }
                if($row['dimensions']) {
                    $products_map[$product_id]['stock'][] = [
                        'dimensions' => $row['dimensions'],
                        'quantity' => $row['quantity']
                    ];
                }
            }
        }
        $data['products'] = array_values($products_map);
        unset($tables_to_fetch['products']);

        foreach ($tables_to_fetch as $key => $sql) {
            $res = $this->conn->query($sql);
            if ($res) {
                $data[$key] = $res->fetch_all(MYSQLI_ASSOC);
            }
        }
        
        send_json($data);
    }
    
    // --- Methods for fetching full lists for dropdowns ---
    public function getFullCustomersList() {
        $res = $this->conn->query("SELECT id, name FROM `customers` ORDER BY name ASC");
        send_json($res->fetch_all(MYSQLI_ASSOC));
    }
    public function getFullSuppliersList() {
        $res = $this->conn->query("SELECT id, name FROM `suppliers` ORDER BY name ASC");
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