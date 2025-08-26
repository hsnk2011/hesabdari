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
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];

        $result = [];

        // Core P&L numbers
        $query = "
            SELECT 
                (SELECT COALESCE(SUM(totalAmount), 0) FROM sales_invoices WHERE is_consignment = 0 AND date BETWEEN ? AND ?) as grossSales,
                (SELECT COALESCE(SUM(discount), 0) FROM sales_invoices WHERE is_consignment = 0 AND date BETWEEN ? AND ?) as salesDiscounts,
                (SELECT COALESCE(SUM(totalAmount), 0) FROM purchase_invoices WHERE is_consignment = 0 AND date BETWEEN ? AND ?) as grossPurchases,
                (SELECT COALESCE(SUM(discount), 0) FROM purchase_invoices WHERE is_consignment = 0 AND date BETWEEN ? AND ?) as purchaseDiscounts,
                (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.date BETWEEN ? AND ?) as totalCompanyExpenses
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssssssssss", $startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
        $stmt->execute();
        $pl_data = $this->fetchAssoc($stmt);
        $result = !empty($pl_data) ? $pl_data[0] : [];

        // Expense breakdown by category
        $exp_breakdown_stmt = $this->conn->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
        $exp_breakdown_stmt->bind_param("ss", $startDate, $endDate);
        $exp_breakdown_stmt->execute();
        $result['expenseBreakdown'] = $this->fetchAssoc($exp_breakdown_stmt);

        // Partner capital calculations
        $partner_capital_stmt = $this->conn->prepare("
            SELECT 
                (SELECT COALESCE(SUM(IF(type='DEPOSIT', amount, -amount)), 0) FROM partner_transactions WHERE date < ?) as openingCapital,
                (SELECT COALESCE(SUM(IF(type='DEPOSIT', amount, 0)), 0) FROM partner_transactions WHERE date BETWEEN ? AND ?) as periodDeposits,
                (SELECT COALESCE(SUM(IF(type='WITHDRAWAL', amount, 0)), 0) FROM partner_transactions WHERE date BETWEEN ? AND ?) as periodWithdrawals
        ");
        $partner_capital_stmt->bind_param("sssss", $startDate, $startDate, $endDate, $startDate, $endDate);
        $partner_capital_stmt->execute();
        $capital_data = $this->fetchAssoc($partner_capital_stmt);
        $result['capitalSummary'] = !empty($capital_data) ? $capital_data[0] : [];
        
        // Data for detailed partner P&L
        $partners_res = $this->conn->query("SELECT id, name, share FROM partners");
        $result['partners'] = $partners_res ? $partners_res->fetch_all(MYSQLI_ASSOC) : [];
        
        $accounts_res = $this->conn->query("SELECT id, partner_id FROM accounts");
        $result['accounts'] = $accounts_res ? $accounts_res->fetch_all(MYSQLI_ASSOC) : [];

        $pt_stmt = $this->conn->prepare("SELECT * FROM partner_transactions WHERE date BETWEEN ? AND ?");
        $pt_stmt->bind_param("ss", $startDate, $endDate);
        $pt_stmt->execute();
        $result['partner_transactions'] = $this->fetchAssoc($pt_stmt);
        
        $exp_stmt = $this->conn->prepare("SELECT * FROM expenses WHERE date BETWEEN ? AND ?");
        $exp_stmt->bind_param("ss", $startDate, $endDate);
        $exp_stmt->execute();
        $result['expenses'] = $this->fetchAssoc($exp_stmt);

        $pay_stmt = $this->conn->prepare("SELECT * FROM payments WHERE date BETWEEN ? AND ?");
        $pay_stmt->bind_param("ss", $startDate, $endDate);
        $pay_stmt->execute();
        $result['payments'] = $this->fetchAssoc($pay_stmt);
        
        send_json($result);
    }
    
    public function getInvoicesReport($data) {
        $type = $data['type']; 
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];
        $result = ['invoices' => [], 'summary' => []];

        $isSales = ($type === 'sales');
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $personTable = $isSales ? 'customers' : 'suppliers';
        $personColumn = $isSales ? 'customerId' : 'supplierId';
        $personNameColumn = $isSales ? 'customerName' : 'supplierName';

        // Get Summary
        $summary_stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(totalAmount), 0) as totalAmount,
                COALESCE(SUM(discount), 0) as totalDiscount,
                COALESCE(SUM(paidAmount), 0) as totalPaid,
                COALESCE(SUM(totalAmount - discount - paidAmount), 0) as totalRemaining
            FROM `{$invoiceTable}` WHERE date BETWEEN ? AND ? AND is_consignment = 0
        ");
        $summary_stmt->bind_param("ss", $startDate, $endDate);
        $summary_stmt->execute();
        $summary_data = $this->fetchAssoc($summary_stmt);
        $result['summary'] = !empty($summary_data) ? $summary_data[0] : [];

        // Get Invoices
        $sql = "SELECT inv.*, p.name as {$personNameColumn} FROM `{$invoiceTable}` inv LEFT JOIN `{$personTable}` p ON inv.{$personColumn} = p.id WHERE inv.date BETWEEN ? AND ? AND inv.is_consignment = 0";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $invoices = $this->fetchAssoc($stmt);

        if (empty($invoices)) { send_json($result); return; }

        $invoiceIds = array_column($invoices, 'id');
        $ids_placeholder = implode(',', array_fill(0, count($invoiceIds), '?'));
        $types = str_repeat('i', count($invoiceIds));

        // Get Items
        $item_sql = "SELECT i.*, p.name as productName FROM `{$itemTable}` i LEFT JOIN products p ON i.productId = p.id WHERE i.invoiceId IN ({$ids_placeholder})";
        $item_stmt = $this->conn->prepare($item_sql);
        $item_stmt->bind_param($types, ...$invoiceIds);
        $item_stmt->execute();
        $allItems = $this->fetchAssoc($item_stmt);
        $itemsByInvoice = [];
        foreach ($allItems as $item) { $itemsByInvoice[$item['invoiceId']][] = $item; }

        // Get Payments
        $payment_sql = "SELECT * FROM `payments` WHERE invoiceType = ? AND invoiceId IN ({$ids_placeholder})";
        $payment_stmt = $this->conn->prepare($payment_sql);
        $payment_stmt->bind_param('s' . $types, $type, ...$invoiceIds);
        $payment_stmt->execute();
        $allPayments = $this->fetchAssoc($payment_stmt);
        $paymentsByInvoice = [];
        foreach ($allPayments as $payment) { $paymentsByInvoice[$payment['invoiceId']][] = $payment; }

        foreach ($invoices as &$invoice) {
            $invoice['items'] = $itemsByInvoice[$invoice['id']] ?? [];
            $invoice['payments'] = $paymentsByInvoice[$invoice['id']] ?? [];
            $invoice['remainingAmount'] = $invoice['totalAmount'] - $invoice['discount'] - $invoice['paidAmount'];
        }
        $result['invoices'] = $invoices;

        send_json($result);
    }
    
    public function getInventoryLedgerReport($data) {
        $productId = intval($data['productId'] ?? 0);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];

        if (!$productId) { send_json(['error' => 'شناسه محصول نامعتبر است.'], 400); return; }

        $result = ['openingStock' => 0, 'transactions' => []];
        
        $os_purchase_stmt = $this->conn->prepare("SELECT COALESCE(SUM(pii.quantity), 0) as total FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pii.productId = ? AND pi.date < ?");
        $os_purchase_stmt->bind_param("is", $productId, $startDate);
        $os_purchase_stmt->execute();
        $purchases_before = $this->fetchAssoc($os_purchase_stmt)[0]['total'] ?? 0;
        
        $os_sales_stmt = $this->conn->prepare("SELECT COALESCE(SUM(sii.quantity), 0) as total FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE sii.productId = ? AND si.date < ?");
        $os_sales_stmt->bind_param("is", $productId, $startDate);
        $os_sales_stmt->execute();
        $sales_before = $this->fetchAssoc($os_sales_stmt)[0]['total'] ?? 0;

        $result['openingStock'] = $purchases_before - $sales_before;

        $purchase_tx_stmt = $this->conn->prepare("SELECT pi.date, 'purchase' as type, pi.id as refId, pii.quantity FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pii.productId = ? AND pi.date BETWEEN ? AND ?");
        $purchase_tx_stmt->bind_param("iss", $productId, $startDate, $endDate);
        $purchase_tx_stmt->execute();
        $purchase_tx = $this->fetchAssoc($purchase_tx_stmt);

        $sales_tx_stmt = $this->conn->prepare("SELECT si.date, 'sales' as type, si.id as refId, sii.quantity FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE sii.productId = ? AND si.date BETWEEN ? AND ?");
        $sales_tx_stmt->bind_param("iss", $productId, $startDate, $endDate);
        $sales_tx_stmt->execute();
        $sales_tx = $this->fetchAssoc($sales_tx_stmt);

        $transactions = array_merge($purchase_tx, $sales_tx);
        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });

        $result['transactions'] = $transactions;
        send_json($result);
    }

    public function getPersonStatement($data) {
        $personType = $data['personType']; $personId = intval($data['personId']);
        $startDate = $data['startDate']; $endDate = $data['endDate'];
        $transactions = []; $person = null;
        
        $isCustomer = ($personType === 'customer');
        $isSupplier = ($personType === 'supplier');
        
        if ($isCustomer || $isSupplier) {
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
            
            $invoice_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount) as amount FROM `{$invoiceTable}` WHERE `{$personColumn}` = ? AND date BETWEEN ? AND ?");
            $invoice_stmt->bind_param("iss", $personId, $startDate, $endDate);
            $invoice_stmt->execute();
            foreach ($this->fetchAssoc($invoice_stmt) as $inv) {
                $transactions[] = ['date' => $inv['date'], 'desc' => "فاکتور " . ($isCustomer ? "فروش" : "خرید") . " #" . $inv['id'], 'debit' => $isCustomer ? floatval($inv['amount']) : 0, 'credit' => !$isCustomer ? floatval($inv['amount']) : 0, 'unix_timestamp' => strtotime($inv['date'])];
            }
            
            $payment_stmt = $this->conn->prepare("SELECT p.invoiceId, p.date, p.amount FROM payments p JOIN `{$invoiceTable}` i ON p.invoiceId = i.id WHERE p.invoiceType = ? AND i.`{$personColumn}` = ? AND p.date BETWEEN ? AND ?");
            $payment_stmt->bind_param("siss", $invoiceTypeForPayment, $personId, $startDate, $endDate);
            $payment_stmt->execute();
            foreach ($this->fetchAssoc($payment_stmt) as $pay) {
                $transactions[] = ['date' => $pay['date'], 'desc' => "پرداخت برای فاکتور #" . $pay['invoiceId'], 'debit' => !$isCustomer ? floatval($pay['amount']) : 0, 'credit' => $isCustomer ? floatval($pay['amount']) : 0, 'unix_timestamp' => strtotime($pay['date'])];
            }

        } else if ($personType === 'partner') {
            $partner_stmt = $this->conn->prepare("SELECT id, name FROM partners WHERE id = ?");
            $partner_stmt->bind_param("i", $personId);
            $partner_stmt->execute();
            $person = $this->fetchAssoc($partner_stmt)[0] ?? null;
            if (!$person) { send_json(['error' => 'Partner not found'], 404); return; }

            $pt_stmt = $this->conn->prepare("SELECT date, type, amount, description FROM partner_transactions WHERE partnerId = ? AND date BETWEEN ? AND ?");
            $pt_stmt->bind_param("iss", $personId, $startDate, $endDate);
            $pt_stmt->execute();
            foreach($this->fetchAssoc($pt_stmt) as $t) {
                $isDeposit = $t['type'] === 'DEPOSIT';
                // *** FIX: Corrected debit/credit logic for partner statement ***
                // A deposit is a CREDIT to the partner's capital account (بستانکار).
                // A withdrawal is a DEBIT from the partner's capital account (بدهکار).
                $transactions[] = [
                    'date' => $t['date'], 
                    'desc' => ($isDeposit ? 'واریز به شرکت' : 'برداشت از شرکت') . ' - ' . $t['description'], 
                    'credit' => $isDeposit ? floatval($t['amount']) : 0, 
                    'debit' => !$isDeposit ? floatval($t['amount']) : 0, 
                    'unix_timestamp' => strtotime($t['date'])
                ];
            }
            
            $acc_stmt = $this->conn->prepare("SELECT id FROM accounts WHERE partner_id = ?");
            $acc_stmt->bind_param("i", $personId);
            $acc_stmt->execute();
            $partner_account_arr = $this->fetchAssoc($acc_stmt);
            $partner_account_id = $partner_account_arr[0]['id'] ?? 0;
            
            if ($partner_account_id > 0) {
                $exp_stmt = $this->conn->prepare("SELECT date, amount, category, description FROM expenses WHERE account_id = ? AND date BETWEEN ? AND ?");
                $exp_stmt->bind_param("iss", $partner_account_id, $startDate, $endDate);
                $exp_stmt->execute();
                foreach($this->fetchAssoc($exp_stmt) as $e) {
                     $transactions[] = ['date' => $e['date'], 'desc' => 'هزینه: ' . $e['category'] . ' - ' . $e['description'], 'debit' => floatval($e['amount']), 'credit' => 0, 'unix_timestamp' => strtotime($e['date'])];
                }
                $pay_stmt = $this->conn->prepare("SELECT date, amount, invoiceType, invoiceId FROM payments WHERE account_id = ? AND date BETWEEN ? AND ?");
                $pay_stmt->bind_param("iss", $partner_account_id, $startDate, $endDate);
                $pay_stmt->execute();
                foreach($this->fetchAssoc($pay_stmt) as $p) {
                    $isSales = $p['invoiceType'] === 'sales';
                    $transactions[] = ['date' => $p['date'], 'desc' => ($isSales ? 'دریافت' : 'پرداخت') . ' بابت فاکتور #' . $p['invoiceId'], 'debit' => !$isSales ? floatval($p['amount']) : 0, 'credit' => $isSales ? floatval($p['amount']) : 0, 'unix_timestamp' => strtotime($p['date'])];
                }
            }
        }

        usort($transactions, function($a, $b) { return $a['unix_timestamp'] - $b['unix_timestamp']; });
        send_json(['transactions' => $transactions, 'person' => $person]);
    }
    
    public function getAccountStatement($data) {
        $accountId = intval($data['accountId'] ?? 0); 
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];
        if (!$accountId) { send_json(['error' => 'Account not found'], 404); return; }

        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ?");
        $account_stmt->bind_param("i", $accountId); 
        $account_stmt->execute();
        $account = $this->fetchAssoc($account_stmt)[0] ?? null;
        if (!$account) { send_json(['error' => 'Account not found'], 404); return; }
        
        $opening_balance = floatval($account['current_balance']);
        $all_tx_sql = "
            SELECT 
                SUM(CASE WHEN source = 'payment_in' OR source = 'partner_in' OR source = 'check_in' OR source = 'partner_personal_in' THEN amount ELSE -amount END) as total_change
            FROM (
                (SELECT 'payment_in' as source, p.date, p.amount FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'sales' AND p.type = 'cash')
                UNION ALL (SELECT 'payment_out' as source, p.date, p.amount FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'purchase' AND p.type = 'cash')
                UNION ALL (SELECT 'expense_out' as source, e.date, e.amount FROM expenses e WHERE e.account_id = ?)
                UNION ALL (SELECT 'partner_in' as source, pt.date, pt.amount FROM partner_transactions pt WHERE pt.account_id = ? AND pt.type = 'DEPOSIT')
                UNION ALL (SELECT 'partner_out' as source, pt.date, pt.amount FROM partner_transactions pt WHERE pt.account_id = ? AND pt.type = 'WITHDRAWAL')
                UNION ALL (SELECT 'check_in' as source, c.dueDate as date, c.amount FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed')
                UNION ALL (SELECT 'partner_personal_in' as source, pt.date, pt.amount FROM partner_transactions pt JOIN partners p ON pt.partnerId=p.id JOIN accounts a ON p.id=a.partner_id WHERE a.id=?)
                UNION ALL (SELECT 'partner_personal_out' as source, pt.date, pt.amount FROM partner_transactions pt JOIN partners p ON pt.partnerId=p.id JOIN accounts a ON p.id=a.partner_id WHERE a.id=?)
            ) as all_tx
            WHERE date >= ?
        ";
        $stmt_ob = $this->conn->prepare($all_tx_sql);
        $stmt_ob->bind_param("iiiiiiiis", $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $startDate);
        $stmt_ob->execute();
        $future_transactions = $this->fetchAssoc($stmt_ob);

        if (!empty($future_transactions) && isset($future_transactions[0]['total_change'])) {
            $opening_balance -= $future_transactions[0]['total_change'];
        }
        
        $sql = "
            (SELECT 'payment_in' as source, p.id, p.date, p.amount, CONCAT('واریز بابت فاکتور فروش #', p.invoiceId) as description, 'salesInvoice' as refType, p.invoiceId as refId FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'sales' AND p.type = 'cash' AND p.date BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'payment_out' as source, p.id, p.date, p.amount, CONCAT('پرداخت بابت فاکتور خرید #', p.invoiceId) as description, 'purchaseInvoice' as refType, p.invoiceId as refId FROM payments p WHERE p.account_id = ? AND p.invoiceType = 'purchase' AND p.type = 'cash' AND p.date BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'expense_out' as source, e.id, e.date, e.amount, CONCAT('هزینه: ', e.category, ' (#', e.id, ')') as description, 'expense' as refType, e.id as refId FROM expenses e WHERE e.account_id = ? AND e.date BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'partner_in' as source, pt.id, pt.date, pt.amount, CONCAT('واریز شریک: ', pr.name) as description, 'partnerTransaction' as refType, pt.id as refId FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'DEPOSIT' AND pt.date BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'partner_out' as source, pt.id, pt.date, pt.amount, CONCAT('برداشت شریک: ', pr.name) as description, 'partnerTransaction' as refType, pt.id as refId FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'WITHDRAWAL' AND pt.date BETWEEN ? AND ?)
            UNION ALL 
            (SELECT 'check_in' as source, c.id, c.dueDate as date, c.amount, CONCAT('وصول چک شماره: ', c.checkNumber) as description, 'check' as refType, c.id as refId FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed' AND c.dueDate BETWEEN ? AND ?)
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ississississississ", $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate, $accountId, $startDate, $endDate);
        $stmt->execute();
        $transactions = $this->fetchAssoc($stmt);

        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
        send_json(['account' => $account, 'transactions' => $transactions, 'openingBalance' => $opening_balance]);
    }
    
    public function getExpensesReport($data) {
        $startDate = $data['startDate']; 
        $endDate = $data['endDate'];
        
        $sql = "SELECT e.date, e.category, e.amount, e.description, a.name as accountName FROM expenses e LEFT JOIN accounts a ON e.account_id = a.id WHERE e.date BETWEEN ? AND ? ORDER BY e.date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $expenses = $this->fetchAssoc($stmt);
        
        $total_stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE date BETWEEN ? AND ?");
        $total_stmt->bind_param("ss", $startDate, $endDate);
        $total_stmt->execute();
        $total_array = $this->fetchAssoc($total_stmt);
        $total = !empty($total_array) ? $total_array[0]['total'] : 0;

        send_json(['expenses' => $expenses, 'total' => $total]);
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
}