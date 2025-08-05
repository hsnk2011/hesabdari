<?php
// /api/controllers/ReportController.php

class ReportController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function fetchAssoc($stmt) {
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            $stmt->close();
            return [];
        }
        $meta = $stmt->result_metadata();
        $fields = []; $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        
        $result = [];
        while ($stmt->fetch()) {
            $c = [];
            foreach($row as $key => $val) {
                $c[$key] = $val;
            }
            $result[] = $c;
        }
        $stmt->close();
        return $result;
    }

    public function getProfitLossReport($data) {
        // NOTE: Date filtering is now done on the client. We fetch all data.
        $query = "
            SELECT 
                (SELECT COALESCE(SUM(totalAmount), 0) FROM sales_invoices WHERE is_consignment = 0) as grossSales,
                (SELECT COALESCE(SUM(discount), 0) FROM sales_invoices WHERE is_consignment = 0) as salesDiscounts,
                (SELECT COALESCE(SUM(totalAmount), 0) FROM purchase_invoices WHERE is_consignment = 0) as grossPurchases,
                (SELECT COALESCE(SUM(discount), 0) FROM purchase_invoices WHERE is_consignment = 0) as purchaseDiscounts,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses) as totalExpenses,
                (SELECT COALESCE(SUM(IF(type='DEPOSIT', amount, 0)), 0) FROM partner_transactions) as partnerDeposits,
                (SELECT COALESCE(SUM(IF(type='WITHDRAWAL', amount, 0)), 0) FROM partner_transactions) as partnerWithdrawals
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result_array = $this->fetchAssoc($stmt);
        $result = !empty($result_array) ? $result_array[0] : array_fill_keys(['grossSales', 'salesDiscounts', 'grossPurchases', 'purchaseDiscounts', 'totalExpenses', 'partnerDeposits', 'partnerWithdrawals'], 0);

        // Also fetch all transactions to be filtered on the client
        $exp_stmt = $this->conn->prepare("SELECT date, category, SUM(amount) as total FROM expenses GROUP BY category, date");
        $exp_stmt->execute();
        $result['allExpenses'] = $this->fetchAssoc($exp_stmt);
        
        $pt_stmt = $this->conn->prepare("SELECT date, type, amount FROM partner_transactions");
        $pt_stmt->execute();
        $result['allPartnerTransactions'] = $this->fetchAssoc($pt_stmt);
        
        $si_stmt = $this->conn->prepare("SELECT date, totalAmount, discount FROM sales_invoices WHERE is_consignment = 0");
        $si_stmt->execute();
        $result['allSalesInvoices'] = $this->fetchAssoc($si_stmt);
        
        $pi_stmt = $this->conn->prepare("SELECT date, totalAmount, discount FROM purchase_invoices WHERE is_consignment = 0");
        $pi_stmt->execute();
        $result['allPurchaseInvoices'] = $this->fetchAssoc($pi_stmt);

        $partners_stmt = $this->conn->prepare("SELECT id, name, share FROM partners");
        $partners_stmt->execute();
        $result['partners'] = $this->fetchAssoc($partners_stmt);

        send_json($result);
    }
    
    public function getPersonStatement($data) {
        $personType = $data['personType']; $personId = intval($data['personId']);
        $transactions = []; $person = null; $isCustomer = ($personType === 'customer');
        $personTable = $isCustomer ? 'customers' : 'suppliers';
        $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
        $personColumn = $isCustomer ? 'customerId' : 'supplierId';
        $invoiceTypeForPayment = $isCustomer ? 'sales' : 'purchase';

        $person_stmt = $this->conn->prepare("SELECT id, name, initial_balance FROM `{$personTable}` WHERE id = ?");
        $person_stmt->bind_param("i", $personId);
        $person_stmt->execute();
        $person = $this->fetchAssoc($person_stmt)[0] ?? null;

        if (!$person) { send_json(['error' => 'Person not found'], 404); return; }

        if (floatval($person['initial_balance']) != 0) {
             $transactions[] = ['date' => 'مانده اولیه', 'desc' => 'مانده حساب از قبل', 'debit' => $isCustomer ? floatval($person['initial_balance']) : 0, 'credit' => !$isCustomer ? floatval($person['initial_balance']) : 0 ];
        }
        
        $invoice_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount) as amount FROM `{$invoiceTable}` WHERE `{$personColumn}` = ?");
        $invoice_stmt->bind_param("i", $personId);
        $invoice_stmt->execute();
        foreach ($this->fetchAssoc($invoice_stmt) as $inv) {
            $transactions[] = ['date' => $inv['date'], 'desc' => "فاکتور " . ($isCustomer ? "فروش" : "خرید") . " #" . $inv['id'], 'debit' => $isCustomer ? floatval($inv['amount']) : 0, 'credit' => !$isCustomer ? floatval($inv['amount']) : 0];
        }
        
        $payment_stmt = $this->conn->prepare("SELECT invoiceId, date, amount FROM payments WHERE invoiceType = ? AND invoiceId IN (SELECT id FROM `{$invoiceTable}` WHERE `{$personColumn}` = ?)");
        $payment_stmt->bind_param("si", $invoiceTypeForPayment, $personId);
        $payment_stmt->execute();
        foreach ($this->fetchAssoc($payment_stmt) as $pay) {
            $transactions[] = ['date' => $pay['date'], 'desc' => "پرداخت برای فاکتور #" . $pay['invoiceId'], 'debit' => !$isCustomer ? floatval($pay['amount']) : 0, 'credit' => $isCustomer ? floatval($pay['amount']) : 0];
        }

        send_json(['transactions' => $transactions, 'person' => $person]);
    }
    
    public function getPersonStatement($data) {
        $personType = $data['personType']; $personId = intval($data['personId']);
        $startDate = $data['startDate']; $endDate = $data['endDate'];
        $transactions = []; $person = null; $isCustomer = ($personType === 'customer');
        $personTable = $isCustomer ? 'customers' : 'suppliers';
        $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
        $personColumn = $isCustomer ? 'customerId' : 'supplierId';
        $invoiceTypeForPayment = $isCustomer ? 'sales' : 'purchase';

        $person_stmt = $this->conn->prepare("SELECT id, name, initial_balance FROM `{$personTable}` WHERE id = ?");
        $person_stmt->bind_param("i", $personId);
        $person_stmt->execute();
        $person = $this->fetchAssoc($person_stmt)[0] ?? null;

        if (!$person) { send_json(['error' => 'Person not found'], 404); return; }

        if (floatval($person['initial_balance']) != 0) {
             $transactions[] = ['date' => 'مانده اولیه', 'desc' => 'مانده حساب از قبل', 'debit' => $isCustomer ? floatval($person['initial_balance']) : 0, 'credit' => !$isCustomer ? floatval($person['initial_balance']) : 0, 'unix_timestamp' => 0];
        }
        
        $invoice_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount) as amount FROM `{$invoiceTable}` WHERE `{$personColumn}` = ? AND " . $this->getSortableDateSQL('date') . " BETWEEN ? AND ?");
        $invoice_stmt->bind_param("iss", $personId, $startDate, $endDate);
        $invoice_stmt->execute();
        foreach ($this->fetchAssoc($invoice_stmt) as $inv) {
            $transactions[] = ['date' => $inv['date'], 'desc' => "فاکتور " . ($isCustomer ? "فروش" : "خرید") . " #" . $inv['id'], 'debit' => $isCustomer ? floatval($inv['amount']) : 0, 'credit' => !$isCustomer ? floatval($inv['amount']) : 0, 'unix_timestamp' => strtotime(str_replace('/', '-', $inv['date']))];
        }
        
        $payment_stmt = $this->conn->prepare("SELECT invoiceId, date, amount FROM payments WHERE invoiceType = ? AND invoiceId IN (SELECT id FROM `{$invoiceTable}` WHERE `{$personColumn}` = ?) AND " . $this->getSortableDateSQL('date') . " BETWEEN ? AND ?");
        $payment_stmt->bind_param("siss", $invoiceTypeForPayment, $personId, $startDate, $endDate);
        $payment_stmt->execute();
        foreach ($this->fetchAssoc($payment_stmt) as $pay) {
            $transactions[] = ['date' => $pay['date'], 'desc' => "پرداخت برای فاکتور #" . $pay['invoiceId'], 'debit' => !$isCustomer ? floatval($pay['amount']) : 0, 'credit' => $isCustomer ? floatval($pay['amount']) : 0, 'unix_timestamp' => strtotime(str_replace('/', '-', $pay['date']))];
        }

        usort($transactions, function($a, $b) { return $a['unix_timestamp'] - $b['unix_timestamp']; });
        send_json(['transactions' => $transactions, 'person' => $person]);
    }

    public function getAccountStatement($data) {
        $accountId = intval($data['accountId'] ?? 0); 
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];

        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ?");
        $account_stmt->bind_param("i", $accountId); 
        $account_stmt->execute();
        $account = $this->fetchAssoc($account_stmt)[0] ?? null;

        if (!$account) { send_json(['error' => 'Account not found'], 404); return; }

        $sql = "
            (SELECT 'payment_in' as source, p.date, p.amount, CONCAT('واریز بابت فاکتور فروش #', p.invoiceId) as description FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'sales' AND p.type = 'cash' AND {$this->getSortableDateSQL('p.date')} BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'payment_out' as source, p.date, p.amount, CONCAT('پرداخت بابت فاکتور خرید #', p.invoiceId) as description FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'purchase' AND p.type = 'cash' AND {$this->getSortableDateSQL('p.date')} BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'expense_out' as source, e.date, e.amount, CONCAT('هزینه: ', e.category) as description FROM expenses e WHERE e.account_id = ? AND {$this->getSortableDateSQL('e.date')} BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'partner_in' as source, pt.date, pt.amount, CONCAT('واریز شریک: ', pr.name) as description FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'DEPOSIT' AND {$this->getSortableDateSQL('pt.date')} BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'partner_out' as source, pt.date, pt.amount, CONCAT('برداشت شریک: ', pr.name) as description FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'WITHDRAWAL' AND {$this->getSortableDateSQL('pt.date')} BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'check_in' as source, c.dueDate as date, c.amount, CONCAT('وصول چک شماره: ', c.checkNumber) as description FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed' AND {$this->getSortableDateSQL('c.dueDate')} BETWEEN ? AND ?)
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ississississississ", $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate);
        $stmt->execute();
        $transactions = $this->fetchAssoc($stmt);

        usort($transactions, function($a, $b) { return strtotime(str_replace('/', '-', $a['date'])) - strtotime(str_replace('/', '-', $b['date'])); });
        send_json(['account' => $account, 'transactions' => $transactions]);
    }
    
    public function getInvoicesReport($data) {
        $type = $data['type']; 
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];

        $isSales = ($type === 'sales');
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $personTable = $isSales ? 'customers' : 'suppliers';
        $personColumn = $isSales ? 'customerId' : 'supplierId';
        $personNameColumn = $isSales ? 'customerName' : 'supplierName';

        $sql = "SELECT inv.*, p.name as {$personNameColumn} FROM `{$invoiceTable}` inv LEFT JOIN `{$personTable}` p ON inv.{$personColumn} = p.id WHERE " . $this->getSortableDateSQL('inv.date') . " BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $invoices = $this->fetchAssoc($stmt);

        if (empty($invoices)) { send_json([]); return; }

        $invoiceIds = array_column($invoices, 'id');
        $ids_placeholder = implode(',', array_fill(0, count($invoiceIds), '?'));

        $item_sql = "SELECT i.*, p.name as productName FROM `{$itemTable}` i LEFT JOIN products p ON i.productId = p.id WHERE i.invoiceId IN ({$ids_placeholder})";
        $item_stmt = $this->conn->prepare($item_sql);
        $item_stmt->bind_param(str_repeat('i', count($invoiceIds)), ...$invoiceIds);
        $item_stmt->execute();
        $allItems = $this->fetchAssoc($item_stmt);

        $payment_sql = "SELECT * FROM `payments` WHERE invoiceType = ? AND invoiceId IN ({$ids_placeholder})";
        $payment_stmt = $this->conn->prepare($payment_sql);
        $payment_stmt->bind_param('s' . str_repeat('i', count($invoiceIds)), $type, ...$invoiceIds);
        $payment_stmt->execute();
        $allPayments = $this->fetchAssoc($payment_stmt);
        
        $itemsByInvoice = [];
        foreach ($allItems as $item) $itemsByInvoice[$item['invoiceId']][] = $item;
        $paymentsByInvoice = [];
        foreach ($allPayments as $payment) $paymentsByInvoice[$payment['invoiceId']][] = $payment;

        foreach ($invoices as &$invoice) {
            $invoice['items'] = $itemsByInvoice[$invoice['id']] ?? [];
            $invoice['payments'] = $paymentsByInvoice[$invoice['id']] ?? [];
            $invoice['remainingAmount'] = $invoice['totalAmount'] - $invoice['discount'] - $invoice['paidAmount'];
        }

        send_json($invoices);
    }
    
    public function getInventoryReport() {
        $sql = "SELECT p.name, ps.dimensions, ps.quantity FROM products p JOIN product_stock ps ON p.id = ps.product_id WHERE ps.quantity > 0 ORDER BY p.name, ps.dimensions";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        send_json($this->fetchAssoc($stmt));
    }
    
    public function getInventoryValueReport() {
        $stock_sql = "SELECT p.id as productId, p.name, ps.dimensions, ps.quantity FROM products p JOIN product_stock ps ON p.id = ps.product_id WHERE ps.quantity > 0";
        $stock_stmt = $this->conn->prepare($stock_sql);
        $stock_stmt->execute();
        $stockItems = $this->fetchAssoc($stock_stmt);
        $totalValue = 0;
        foreach ($stockItems as &$item) {
            $price_stmt = $this->conn->prepare("SELECT unitPrice FROM purchase_invoice_items WHERE productId = ? AND dimensions = ? ORDER BY id DESC LIMIT 1");
            $price_stmt->bind_param("is", $item['productId'], $item['dimensions']);
            $price_stmt->execute();
            $price_result = $this->fetchAssoc($price_stmt);
            $lastPrice = $price_result[0]['unitPrice'] ?? 0;
            $item['lastPrice'] = $lastPrice;
            $item['rowValue'] = $item['quantity'] * $lastPrice;
            $totalValue += $item['rowValue'];
        }
        send_json(['items' => $stockItems, 'totalValue' => $totalValue]);
    }

    public function getExpensesReport($data) {
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];
        
        $sql = "SELECT e.date, e.category, e.amount, e.description, a.name as accountName FROM expenses e LEFT JOIN accounts a ON e.account_id = a.id WHERE " . $this->getSortableDateSQL('e.date') . " BETWEEN ? AND ? ORDER BY " . $this->getSortableDateSQL('e.date') . " DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $expenses = $this->fetchAssoc($stmt);
        
        $total_stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE " . $this->getSortableDateSQL('date') . " BETWEEN ? AND ?");
        $total_stmt->bind_param("ss", $startDate, $endDate);
        $total_stmt->execute();
        $total_array = $this->fetchAssoc($total_stmt);
        $total = !empty($total_array) ? $total_array[0]['total'] : 0;

        send_json(['expenses' => $expenses, 'total' => $total]);
    }
}