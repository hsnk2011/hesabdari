<?php
// /api/models/DashboardModel.php
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/ReportModel.php';

class DashboardModel {
    private $conn;

    public function __construct($db) {
        // *** FIX START: Corrected the typo from "$this.conn" to "$this->conn" ***
        $this->conn = $db;
        // *** FIX END ***
    }

    public function getDashboardData() {
        $entityId = $_SESSION['current_entity_id'];
        $salesWindowDays = defined('DASHBOARD_SALES_WINDOW_DAYS') ? DASHBOARD_SALES_WINDOW_DAYS : 30;
        $thirty_days_ago = date('Y-m-d', strtotime("-{$salesWindowDays} days"));
        $today = date('Y-m-d');
        
        $output = [
            'salesLast30Days' => $this->getSalesLast30Days($thirty_days_ago, $entityId),
            'topIndebtedSuppliers' => $this->getTopIndebtedSuppliers($entityId),
            'unsettledCustomers' => $this->getUnsettledCustomers($entityId),
            'expensesByCategory' => $this->getExpensesByCategory($entityId),
            'dueReceivedChecks' => $this->getDueChecks('received', 'in_hand', $today, 5, $entityId),
            'duePayableChecks' => $this->getDueChecks('payable', 'payable', $today, 5, $entityId),
            'preparingOrders' => $this->getOrdersByDeliveryStatus('preparing', $entityId),
            'readyOrders' => $this->getOrdersByDeliveryStatus('ready', $entityId),
            'cashPosition' => $this->getCashPosition($entityId),
            'totalReceivables' => $this->getTotalReceivables($entityId),
            'totalPayables' => $this->getTotalPayables($entityId),
            'lowStockCount' => $this->getLowStockCount($entityId),
            'overdueInstallmentsCount' => $this->getOverdueInstallmentsCount($entityId, $today),
            'dashboardWarehouses' => $this->getDashboardWarehouses($entityId),
        ];

        return $output;
    }

    public function getNotificationsData() {
        $entityId = $_SESSION['current_entity_id'];
        $reminderDays = defined('CHECK_REMINDER_DAYS') ? CHECK_REMINDER_DAYS : 7;
        $seven_days_later = date('Y-m-d', strtotime("+{$reminderDays} days"));
        $today = date('Y-m-d');

        $due_received_stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM checks WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate BETWEEN ? AND ?");
        $due_received_stmt->bind_param("iss", $entityId, $today, $seven_days_later);
        $due_received_stmt->execute();
        $received_result = db_stmt_to_assoc_array($due_received_stmt);
        $dueReceivedCount = $received_result[0]['count'] ?? 0;

        return ['due_checks_count' => $dueReceivedCount];
    }

    public function getDueChecksList() {
        $entityId = $_SESSION['current_entity_id'];
        $reminderDays = defined('CHECK_REMINDER_DAYS') ? CHECK_REMINDER_DAYS : 7;
        $seven_days_later = date('Y-m-d', strtotime("+{$reminderDays} days"));
        $today = date('Y-m-d');

        $stmt = $this->conn->prepare("SELECT id, checkNumber, amount, dueDate FROM checks WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate BETWEEN ? AND ? ORDER BY dueDate ASC LIMIT 10");
        $stmt->bind_param("iss", $entityId, $today, $seven_days_later);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }


    private function getSalesLast30Days($startDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT SUM(totalAmount - discount) as total FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0 AND date >= ?");
        $stmt->bind_param("is", $entityId, $startDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        return $result[0]['total'] ?? 0;
    }

    private function getTopIndebtedSuppliers($entityId) {
        require_once __DIR__ . '/Asset.php';
        $sql = "
            SELECT 
                s.id, s.name,
                (
                    s.initial_balance + 
                    (SELECT COALESCE(SUM(pi.totalAmount - pi.discount + COALESCE(pi.tax_amount, 0) - pi.paidAmount), 0) FROM purchase_invoices pi WHERE pi.supplierId = s.id AND pi.entity_id = ? AND pi.is_consignment = 0) -
                    (SELECT COALESCE(SUM((ri.totalAmount - ri.discount) - COALESCE(ri.paidAmount, 0)), 0) FROM return_invoices ri WHERE ri.person_id = s.id AND ri.entity_id = ? AND ri.type = 'purchase_return') -
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'supplier' AND p.person_id = s.id AND p.invoiceId IS NULL AND p.transaction_type = 'payment' AND p.entity_id = ?) +
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'supplier' AND p.person_id = s.id AND p.invoiceId IS NULL AND p.transaction_type = 'receipt' AND p.entity_id = ?) -
                    " . Asset::sqlPersonNet('s.id', 'supplier', $entityId) . "
                ) as total_debt,
                COALESCE((
                    SELECT SUM(c.amount)
                    FROM checks c
                    JOIN payments p ON c.id = p.checkId
                    WHERE p.person_id = s.id AND p.person_type = 'supplier' AND c.entity_id = ?
                      AND c.status = 'payable'
                ), 0) as pending_checks
            FROM suppliers s
            WHERE s.entity_id = ?
            HAVING total_debt > 0 OR pending_checks > 0
            ORDER BY total_debt DESC
            LIMIT 5
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiiii", $entityId, $entityId, $entityId, $entityId, $entityId, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getUnsettledCustomers($entityId) {
        require_once __DIR__ . '/Asset.php';
        $sql = "
            SELECT 
                c.id, c.name,
                (
                    c.initial_balance +
                    (SELECT COALESCE(SUM(si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount), 0) FROM sales_invoices si WHERE si.customerId = c.id AND si.entity_id = ? AND si.is_consignment = 0) -
                    (SELECT COALESCE(SUM((ri.totalAmount - ri.discount) - COALESCE(ri.paidAmount, 0)), 0) FROM return_invoices ri WHERE ri.person_id = c.id AND ri.entity_id = ? AND ri.type = 'sales_return') -
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'customer' AND p.person_id = c.id AND p.invoiceId IS NULL AND p.transaction_type = 'receipt' AND p.entity_id = ?) +
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'customer' AND p.person_id = c.id AND p.invoiceId IS NULL AND p.transaction_type = 'payment' AND p.entity_id = ?) +
                    " . Asset::sqlPersonNet('c.id', 'customer', $entityId) . "
                ) as total_debt
            FROM customers c
            WHERE c.entity_id = ?
            HAVING total_debt > 0
            ORDER BY total_debt DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $entityId, $entityId, $entityId, $entityId, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getExpensesByCategory($entityId) {
        $sql = "
            SELECT category, SUM(amount) as total
            FROM expenses
            WHERE entity_id = ?
            GROUP BY category
            ORDER BY total DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        $categories = [];
        foreach ($result as $row) {
            $categories[$row['category']] = (float)$row['total'];
        }
        return $categories;
    }

    private function getDueChecks($type, $status, $today, $limit, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT 
                c.checkNumber, 
                c.amount, 
                c.dueDate,
                COALESCE(cust.name, sup.name, prt.name, 'ثبت مستقل') as personName
            FROM checks c
            LEFT JOIN payments p ON c.id = p.checkId
            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'
            LEFT JOIN suppliers sup ON p.person_id = sup.id AND p.person_type = 'supplier'
            LEFT JOIN partners prt ON p.person_id = prt.id AND p.person_type = 'partner'
            WHERE c.entity_id = ? AND c.type = ? AND c.status = ? AND c.dueDate >= ?
            ORDER BY c.dueDate ASC
            LIMIT ?
        ");
        $stmt->bind_param("isssi", $entityId, $type, $status, $today, $limit);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    private function getOrdersByDeliveryStatus($status, $entityId) {
        // Sales invoices
        $stmt = $this->conn->prepare("
            SELECT si.id, si.date, si.totalAmount, si.discount, si.delivery_status,
                   c.name as personName, 'sales' as invoiceType,
                   (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) as remainingAmount
            FROM sales_invoices si
            LEFT JOIN customers c ON si.customerId = c.id
            WHERE si.entity_id = ? AND si.delivery_status = ? AND (si.is_consignment = 0 OR si.is_consignment IS NULL)
            ORDER BY si.date ASC
            LIMIT 10
        ");
        $stmt->bind_param("is", $entityId, $status);
        $stmt->execute();
        $salesOrders = db_stmt_to_assoc_array($stmt);

        // Purchase invoices
        $stmt2 = $this->conn->prepare("
            SELECT pi.id, pi.date, pi.totalAmount, pi.discount, pi.delivery_status,
                   s.name as personName, 'purchase' as invoiceType,
                   (pi.totalAmount - pi.discount + COALESCE(pi.tax_amount, 0) - pi.paidAmount) as remainingAmount
            FROM purchase_invoices pi
            LEFT JOIN suppliers s ON pi.supplierId = s.id
            WHERE pi.entity_id = ? AND pi.delivery_status = ? AND (pi.is_consignment = 0 OR pi.is_consignment IS NULL)
            ORDER BY pi.date ASC
            LIMIT 10
        ");
        $stmt2->bind_param("is", $entityId, $status);
        $stmt2->execute();
        $purchaseOrders = db_stmt_to_assoc_array($stmt2);

        return array_merge($salesOrders, $purchaseOrders);
    }

    public function getSmartAlerts() {
        $entityId = $_SESSION['current_entity_id'];
        $alerts = [];
        $today = date('Y-m-d');
        $overdueDays = defined('INVOICE_OVERDUE_DAYS') ? INVOICE_OVERDUE_DAYS : 30;
        $overdueDate = date('Y-m-d', strtotime("-{$overdueDays} days"));

        // Alert 1
        $stmt1 = $this->conn->prepare("
            SELECT COUNT(DISTINCT p.id) as count
            FROM products p
            WHERE p.entity_id = ? AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status = 'in_stock') AND NOT EXISTS (SELECT 1 FROM purchase_invoice_items pii WHERE pii.productId = p.id)
        ");
        $stmt1->bind_param("i", $entityId);
        $stmt1->execute();
        $alerts['inventory_without_purchase_history'] = db_stmt_to_assoc_array($stmt1)[0]['count'] ?? 0;

        // Alert 2
        $stmt2 = $this->conn->prepare("
            SELECT COUNT(ii.id) as count FROM inventory_items ii JOIN products p ON ii.product_id = p.id
            WHERE p.entity_id = ? AND (ii.purchase_invoice_item_id IS NULL OR ii.purchase_invoice_item_id = 0) AND ii.status = 'in_stock'
        ");
        $stmt2->bind_param("i", $entityId);
        $stmt2->execute();
        $alerts['unlinked_inventory_items'] = db_stmt_to_assoc_array($stmt2)[0]['count'] ?? 0;

        // Alert 3: old sales invoices still open against the customer's statement balance
        $report = new ReportModel($this->conn);
        $alerts['overdue_unsettled_invoices'] = count($report->getOverdueUnsettledSalesInvoices($entityId, $overdueDate));

        // Alert 4
        $stmt4 = $this->conn->prepare("SELECT COUNT(*) as count FROM checks WHERE entity_id = ? AND status IN ('in_hand', 'payable') AND dueDate < ?");
        $stmt4->bind_param("is", $entityId, $today);
        $stmt4->execute();
        $alerts['past_due_checks'] = db_stmt_to_assoc_array($stmt4)[0]['count'] ?? 0;

        // Alert 5: فاکتور با مبلغ نهایی صفر/منفی یا دارای حداقل یک ردیف کالا با قیمت واحد صفر
        $stmt5 = $this->conn->prepare("
            SELECT COUNT(DISTINCT si.id) as count
            FROM sales_invoices si
            WHERE si.entity_id = ?
              AND (
                  (si.totalAmount - si.discount) <= 0
                  OR EXISTS (
                      SELECT 1 FROM sales_invoice_items sii
                      WHERE sii.invoiceId = si.id AND sii.is_service = 0 AND sii.unitPrice <= 0
                  )
              )
        ");
        $stmt5->bind_param("i", $entityId);
        $stmt5->execute();
        $sales_negative = db_stmt_to_assoc_array($stmt5)[0]['count'] ?? 0;
        $stmt6 = $this->conn->prepare("
            SELECT COUNT(DISTINCT pi.id) as count
            FROM purchase_invoices pi
            WHERE pi.entity_id = ?
              AND (
                  (pi.totalAmount - pi.discount) <= 0
                  OR EXISTS (
                      SELECT 1 FROM purchase_invoice_items pii
                      WHERE pii.invoiceId = pi.id AND pii.is_service = 0 AND pii.unitPrice <= 0
                  )
              )
        ");
        $stmt6->bind_param("i", $entityId);
        $stmt6->execute();
        $purchase_negative = db_stmt_to_assoc_array($stmt6)[0]['count'] ?? 0;
        $alerts['negative_total_invoices'] = [
            'count' => $sales_negative + $purchase_negative,
            'sales_count' => $sales_negative,
            'purchase_count' => $purchase_negative
        ];
        
        // Alert 6
        $stmt7 = $this->conn->prepare("
            SELECT COUNT(DISTINCT si.id) as count
            FROM sales_invoices si JOIN sales_invoice_items sii ON si.id = sii.invoiceId
            LEFT JOIN products p ON sii.productId = p.id
            WHERE si.entity_id = ? AND sii.is_service = 0 AND p.id IS NULL
        ");
        $stmt7->bind_param("i", $entityId);
        $stmt7->execute();
        $alerts['orphan_invoice_items'] = db_stmt_to_assoc_array($stmt7)[0]['count'] ?? 0;

        // Alert 7: Account balance discrepancy check (regular + personal/partner accounts)
        // 7a) Regular accounts via SQL
        $stmt8 = $this->conn->prepare("\n            SELECT a.id, a.name\n            FROM accounts a\n            WHERE a.partner_id IS NULL AND a.entity_id = ? AND ABS(a.current_balance - (\n                a.initial_balance +\n                COALESCE((SELECT SUM(IF(p.transaction_type = 'receipt', p.amount, -p.amount)) FROM payments p WHERE p.account_id = a.id AND p.type = 'cash' AND p.entity_id = ?), 0) -\n                COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.account_id = a.id AND e.entity_id = ?), 0) +\n                COALESCE((SELECT SUM(i.amount) FROM incomes i WHERE i.account_id = a.id AND i.entity_id = ?), 0) +\n                COALESCE((SELECT SUM(IF(c.type = 'received', c.amount, -c.amount)) FROM checks c WHERE c.cashed_in_account_id = a.id AND c.status = 'cashed' AND c.entity_id = ?), 0)\n            )) > 0.01\n        ");
        $stmt8->bind_param("iiiii", $entityId, $entityId, $entityId, $entityId, $entityId);
        $stmt8->execute();
        $mismatched_accounts_list = db_stmt_to_assoc_array($stmt8);

        // 7b) Personal/partner accounts via ledger computation
        $accountModel = new Account($this->conn);
        $stmtPartners = $this->conn->prepare("SELECT id, name FROM accounts WHERE partner_id IS NOT NULL AND entity_id = ?");
        $stmtPartners->bind_param("i", $entityId);
        $stmtPartners->execute();
        $partner_accounts = db_stmt_to_assoc_array($stmtPartners);
        foreach ($partner_accounts as $acc) {
            $ledgerData = $accountModel->getLedger(intval($acc['id']));
            if (isset($ledgerData['error'])) continue;
            $stored_balance = (float)$ledgerData['account']['current_balance'];
            $opening_balance = (float)$ledgerData['account']['initial_balance'];
            $transactions = $ledgerData['transactions'] ?? [];

            $running_balance = $opening_balance;
            foreach ($transactions as $tx) {
                $amount = (float)$tx['amount'];
                $source = $tx['source'];
                // Consignment invoices do not affect balance
                if (isset($tx['is_consignment']) && $tx['is_consignment'] == 1) continue;
                if (str_ends_with($source, '_out') || $source === 'expense') {
                    $running_balance -= $amount;
                } else {
                    $running_balance += $amount;
                }
            }
            $calculated_balance = round($running_balance, 2);
            if (abs($stored_balance - $calculated_balance) > 0.01) {
                $mismatched_accounts_list[] = [
                    'id' => intval($acc['id']),
                    'name' => $acc['name']
                ];
            }
        }

        $alerts['account_balance_discrepancy'] = [
            'count' => count($mismatched_accounts_list),
            'accounts' => $mismatched_accounts_list
        ];

        // Alert 8: Sold items without a purchase record (logical negative stock / virtual sold)
        $stmt9 = $this->conn->prepare("
            SELECT COUNT(ii.id) as count
            FROM inventory_items ii
            JOIN products p ON ii.product_id = p.id
            WHERE ii.status = 'sold'
              AND (ii.purchase_invoice_item_id IS NULL OR ii.purchase_invoice_item_id = 0)
              AND p.entity_id = ?
        ");
        $stmt9->bind_param("i", $entityId);
        $stmt9->execute();
        $alerts['sold_items_without_purchase'] = db_stmt_to_assoc_array($stmt9)[0]['count'] ?? 0;

        // Alert 8b: Sold without sales invoice link (phantom sold / double-sold UI)
        $stmt9b = $this->conn->prepare("
            SELECT COUNT(ii.id) as count
            FROM inventory_items ii
            JOIN products p ON ii.product_id = p.id
            WHERE ii.status IN ('sold', 'consignment_out')
              AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id = 0)
              AND p.entity_id = ?
        ");
        $stmt9b->bind_param("i", $entityId);
        $stmt9b->execute();
        $alerts['sold_without_sales_link'] = db_stmt_to_assoc_array($stmt9b)[0]['count'] ?? 0;

        // Alert 8c: Sold inventory rows exceed actual sales qty (product+dimensions)
        $stmt9c = $this->conn->prepare("
            SELECT COUNT(*) as count FROM (
                SELECT ii.product_id, ii.dimensions
                FROM inventory_items ii
                JOIN products p ON p.id = ii.product_id
                WHERE p.entity_id = ? AND ii.status = 'sold'
                GROUP BY ii.product_id, ii.dimensions
                HAVING COUNT(ii.id) > COALESCE((
                    SELECT SUM(sii.quantity)
                    FROM sales_invoice_items sii
                    JOIN sales_invoices si ON si.id = sii.invoiceId
                    WHERE si.entity_id = ?
                      AND sii.productId = ii.product_id
                      AND sii.dimensions = ii.dimensions
                      AND COALESCE(sii.is_service, 0) = 0
                ), 0)
            ) t
        ");
        $stmt9c->bind_param("ii", $entityId, $entityId);
        $stmt9c->execute();
        $alerts['duplicate_sold_inventory'] = db_stmt_to_assoc_array($stmt9c)[0]['count'] ?? 0;

        // Alert 8d: Inventory pointing to deleted purchase invoice items
        $stmt9d = $this->conn->prepare("
            SELECT COUNT(ii.id) as count
            FROM inventory_items ii
            JOIN products p ON p.id = ii.product_id
            LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
            WHERE p.entity_id = ?
              AND ii.purchase_invoice_item_id IS NOT NULL AND ii.purchase_invoice_item_id != 0
              AND pii.id IS NULL
        ");
        $stmt9d->bind_param("i", $entityId);
        $stmt9d->execute();
        $alerts['broken_purchase_links'] = db_stmt_to_assoc_array($stmt9d)[0]['count'] ?? 0;

        // Alert 9: Purchase items with missing serialized entries
        $stmt10 = $this->conn->prepare("\n            SELECT COUNT(*) as count\n            FROM purchase_invoice_items pii\n            JOIN purchase_invoices pi ON pii.invoiceId = pi.id\n            WHERE pi.entity_id = ?\n              AND pii.is_service = 0\n              AND (\n                  SELECT COUNT(*) FROM inventory_items ii WHERE ii.purchase_invoice_item_id = pii.id\n              ) < pii.quantity\n        ");
        $stmt10->bind_param("i", $entityId);
        $stmt10->execute();
        $alerts['missing_serials'] = db_stmt_to_assoc_array($stmt10)[0]['count'] ?? 0;

        // Alert 10: Sales invoices without serialized inventory items
        // Count a line as linked if inventory still points at it, was returned from it, or a return invoice recorded it.
        $stmt11 = $this->conn->prepare("
            SELECT COUNT(DISTINCT si.id) as count
            FROM sales_invoices si
            JOIN sales_invoice_items sii ON si.id = sii.invoiceId
            WHERE si.entity_id = ?
              AND sii.is_service = 0
              AND NOT EXISTS (
                  SELECT 1 FROM inventory_items ii
                  WHERE ii.sales_invoice_item_id = sii.id
                     OR ii.returned_from_sales_item_id = sii.id
              )
              AND NOT EXISTS (
                  SELECT 1 FROM return_invoice_items rii
                  WHERE rii.original_invoice_item_id = sii.id
              )
        ");
        $stmt11->bind_param("i", $entityId);
        $stmt11->execute();
        $alerts['sales_without_serials'] = db_stmt_to_assoc_array($stmt11)[0]['count'] ?? 0;

        return $alerts;
    }

    private function getCashPosition($entityId) {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(current_balance), 0) as total FROM accounts WHERE entity_id = ? AND partner_id IS NULL AND include_in_cash_position = 1");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        return $result[0]['total'] ?? 0;
    }

    /**
     * کل مطالبات: جمع مانده‌های مثبت مشتریان تا امروز.
     * همان فرمول صورتحساب/تراز (مانده اولیه + فروش − برگشت − پرداخت فاکتور − پیش‌دریافت + پرداخت آزاد).
     */
    private function getTotalReceivables($entityId) {
        $report = new ReportModel($this->conn);
        $data = $report->getCustomerReceivables(date('Y-m-d'), $entityId);
        $total = 0.0;
        foreach (($data['customers'] ?? []) as $row) {
            $bal = floatval($row['receivable'] ?? 0);
            if ($bal > 0.01) {
                $total += $bal;
            }
        }
        return $total;
    }

    /**
     * کل بدهی‌ها: جمع مانده‌های مثبت تأمین‌کنندگان تا امروز.
     * همان فرمول صورتحساب/تراز (مانده اولیه + خرید − برگشت − پرداخت فاکتور − پیش‌پرداخت + دریافت آزاد).
     */
    private function getTotalPayables($entityId) {
        $report = new ReportModel($this->conn);
        $data = $report->getSupplierDebts(date('Y-m-d'), $entityId);
        $total = 0.0;
        foreach (($data['suppliers'] ?? []) as $row) {
            $bal = floatval($row['debt'] ?? 0);
            if ($bal > 0.01) {
                $total += $bal;
            }
        }
        return $total;
    }

    private function getDashboardWarehouses($entityId) {
        if (!$this->tableExists('warehouses') || !$this->tableHasColumn('warehouses', 'show_on_dashboard')) {
            return [];
        }
        if (!$this->tableHasColumn('warehouses', 'warehouse_type')) {
            $this->conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical' AFTER `show_on_dashboard`");
            if (!$this->tableHasColumn('warehouses', 'warehouse_type')) {
                return [];
            }
        }
        $stmt = $this->conn->prepare("
            SELECT w.id, w.name, w.warehouse_type,
                   (SELECT COUNT(*) FROM inventory_items ii
                    WHERE ii.warehouse_id = w.id AND ii.status IN ('in_stock', 'returned', 'sold')) AS item_count
            FROM warehouses w
            WHERE w.entity_id = ? AND w.is_active = 1
              AND (w.show_on_dashboard = 1 OR w.warehouse_type = 'order')
            ORDER BY w.warehouse_type DESC, w.name ASC
        ");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $warehouses = db_stmt_to_assoc_array($stmt);
        if (empty($warehouses)) {
            return [];
        }

        $ids = array_map(function ($w) { return intval($w['id']); }, $warehouses);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $this->conn->prepare("
            SELECT ii.id, ii.warehouse_id, ii.product_id, ii.dimensions, ii.status, ii.entry_date,
                   COALESCE(NULLIF(ii.serial_id, ''), CONCAT('INV-', LPAD(ii.id, 6, '0'))) AS serial_id,
                   p.name AS product_name, p.design_code, p.colorway,
                   si.id AS sales_invoice_id, si.invoice_number AS sales_invoice_number,
                   c.name AS customer_name,
                   sii.unitPrice AS sales_unit_price,
                   sii.dimensions AS sales_dimensions,
                   pii.unitPrice AS purchase_unit_price,
                   pii.dimensions AS purchase_dimensions
            FROM inventory_items ii
            INNER JOIN products p ON p.id = ii.product_id
            LEFT JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
            LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
            LEFT JOIN customers c ON c.id = si.customerId
            LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
            WHERE ii.entity_id = ? AND ii.warehouse_id IN ($placeholders)
              AND ii.status IN ('in_stock', 'returned', 'sold')
            ORDER BY ii.status DESC, p.name ASC, ii.serial_id ASC
        ");
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$entityId], $ids);
        $itemStmt->bind_param($types, ...$params);
        $itemStmt->execute();
        $items = db_stmt_to_assoc_array($itemStmt);

        $typeById = [];
        foreach ($warehouses as $warehouse) {
            $typeById[intval($warehouse['id'])] = $warehouse['warehouse_type'] ?? 'physical';
        }
        $byWarehouse = [];
        foreach ($items as $item) {
            $wid = intval($item['warehouse_id']);
            $isOrder = ($typeById[$wid] ?? 'physical') === 'order';
            if (!$isOrder && $item['status'] === 'sold') {
                continue;
            }
            if (!isset($byWarehouse[$wid])) {
                $byWarehouse[$wid] = [];
            }
            $byWarehouse[$wid][] = $item;
        }
        foreach ($warehouses as &$warehouse) {
            $warehouse['items'] = $byWarehouse[intval($warehouse['id'])] ?? [];
        }
        unset($warehouse);
        return $warehouses;
    }

    private function getLowStockCount($entityId) {
        if (!$this->tableHasColumn('products', 'min_stock')) {
            return 0;
        }
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt
            FROM products p
            WHERE p.entity_id = ? AND p.is_active = 1 AND p.min_stock IS NOT NULL
            AND (
                SELECT COUNT(*) FROM inventory_items ii
                WHERE ii.product_id = p.id AND ii.status IN ('in_stock', 'returned')
            ) <= p.min_stock
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $entityId);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = db_stmt_to_assoc_array($stmt);
        return intval($row[0]['cnt'] ?? 0);
    }

    private function getOverdueInstallmentsCount($entityId, $today) {
        if (!$this->tableExists('installments')) {
            return 0;
        }
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as cnt FROM installments
            WHERE entity_id = ? AND status != 'paid' AND due_date < ?
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("is", $entityId, $today);
        if (!$stmt->execute()) {
            return 0;
        }
        $row = db_stmt_to_assoc_array($stmt);
        return intval($row[0]['cnt'] ?? 0);
    }

    private function tableExists($tableName) {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $tableName);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return !empty($rows);
    }

    private function tableHasColumn($tableName, $columnName) {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $tableName, $columnName);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return !empty($rows);
    }
}