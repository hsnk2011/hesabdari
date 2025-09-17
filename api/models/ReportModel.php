<?php
// /api/models/ReportModel.php

class ReportModel {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getProfitLossReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        $useCogs = !empty($data['useCogs']);

        $result = [];

        // IMPROVEMENT: Replaced multiple subqueries with a more efficient single query using UNION ALL.
        $query = "
            SELECT
                COALESCE(SUM(CASE WHEN type = 'sale' THEN amount END), 0) as grossSales,
                COALESCE(SUM(CASE WHEN type = 'sale' THEN discount END), 0) as salesDiscounts,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) as totalCompanyExpenses
            FROM (
                SELECT 'sale' as type, totalAmount as amount, discount FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0 AND date BETWEEN ? AND ?
                UNION ALL
                SELECT 'expense' as type, amount, 0 as discount FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ?
            ) as combined_data
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ississ", $entityId, $startDate, $endDate, $entityId, $startDate, $endDate);
        $stmt->execute();
        $pl_data = db_stmt_to_assoc_array($stmt);
        $result = !empty($pl_data) ? $pl_data[0] : [];
        
        $result['calculationMethod'] = $useCogs ? 'cogs' : 'standard';
        $result['totalCogs'] = 0;

        $netSales = (float)$result['grossSales'] - (float)$result['salesDiscounts'];

        if ($useCogs) {
            $result['totalCogs'] = $this->calculateTotalCogs($startDate, $endDate, $entityId);
            $result['grossProfit'] = $netSales - $result['totalCogs'];
        } else {
            $purchase_query = "
                SELECT 
                    COALESCE(SUM(totalAmount - discount), 0) as grossPurchases
                FROM purchase_invoices 
                WHERE entity_id = ? AND is_consignment = 0 AND date BETWEEN ? AND ?
            ";
            $purchase_stmt = $this->conn->prepare($purchase_query);
            $purchase_stmt->bind_param("iss", $entityId, $startDate, $endDate);
            $purchase_stmt->execute();
            $purchase_data = db_stmt_to_assoc_array($purchase_stmt)[0] ?? ['grossPurchases' => 0];
            $result = array_merge($result, $purchase_data);
            
            // Note: Purchase discounts are not typically subtracted in this simple P&L model.
            // Net purchases are considered the cost of goods.
            $netPurchases = (float)$result['grossPurchases'];
            $result['grossProfit'] = $netSales - $netPurchases;
        }
        
        $result['netOperatingProfit'] = $result['grossProfit'] - (float)$result['totalCompanyExpenses'];
        $result['expenseBreakdown'] = $this->getExpenseBreakdown($startDate, $endDate, $entityId);
        
        $result['capitalSummary'] = $this->getCapitalSummary($startDate, $endDate, $entityId);
        $result['partners'] = $this->getPartners($entityId);
        $result['accounts'] = $this->getAccountsWithPartnerId($entityId);
        $result['partner_transactions'] = $this->getPartnerTransactions($startDate, $endDate, $entityId);
        $result['expenses'] = $this->getExpensesFromPartnerAccounts($startDate, $endDate, $entityId);
        $result['payments'] = $this->getPaymentsFromPartnerAccounts($startDate, $endDate, $entityId);
        
        return $result;
    }

    private function calculateTotalCogs($startDate, $endDate, $entityId) {
        $sales_items_stmt = $this->conn->prepare("
            SELECT sii.productId, sii.dimensions, sii.quantity
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON sii.invoiceId = si.id
            WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
        ");
        $sales_items_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $sales_items_stmt->execute();
        $soldItems = db_stmt_to_assoc_array($sales_items_stmt);

        if (empty($soldItems)) return 0;
        
        $totalCogs = 0;
        foreach ($soldItems as $item) {
            $purchase_price_stmt = $this->conn->prepare(
                "SELECT pii.unitPrice FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pii.dimensions = ? ORDER BY pi.date DESC, pii.id DESC LIMIT 1"
            );
            $purchase_price_stmt->bind_param("iis", $entityId, $item['productId'], $item['dimensions']);
            $purchase_price_stmt->execute();
            $price_result_arr = db_stmt_to_assoc_array($purchase_price_stmt);
            $lastPurchasePrice = $price_result_arr[0]['unitPrice'] ?? 0;
            $totalCogs += (float)$lastPurchasePrice * (int)$item['quantity'];
        }
        return $totalCogs;
    }

    private function getExpenseBreakdown($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    private function getCapitalSummary($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT 
                (SELECT COALESCE(SUM(IF(transaction_type='receipt', amount, -amount)), 0) FROM payments WHERE entity_id = ? AND person_type='partner' AND date < ?) as openingCapital,
                (SELECT COALESCE(SUM(IF(transaction_type='receipt', amount, 0)), 0) FROM payments WHERE entity_id = ? AND person_type='partner' AND date BETWEEN ? AND ?) as periodDeposits,
                (SELECT COALESCE(SUM(IF(transaction_type='payment', amount, 0)), 0) FROM payments WHERE entity_id = ? AND person_type='partner' AND date BETWEEN ? AND ?) as periodWithdrawals
        ");
        $stmt->bind_param("isississ", $entityId, $startDate, $entityId, $startDate, $endDate, $entityId, $startDate, $endDate);
        $stmt->execute();
        $capital_data = db_stmt_to_assoc_array($stmt);
        return !empty($capital_data) ? $capital_data[0] : [];
    }

    private function getPartners($entityId) {
        $stmt = $this->conn->prepare("SELECT id, name, share FROM partners WHERE entity_id = ?");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    private function getAccountsWithPartnerId($entityId) {
        $stmt = $this->conn->prepare("SELECT id, partner_id FROM accounts WHERE entity_id = ? AND partner_id IS NOT NULL");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    private function getPartnerTransactions($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT person_id as partnerId, transaction_type as type, amount FROM payments WHERE entity_id = ? AND person_type = 'partner' AND date BETWEEN ? AND ?");
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    private function getExpensesFromPartnerAccounts($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT e.account_id, e.amount FROM expenses e JOIN accounts a ON e.account_id = a.id WHERE a.entity_id = ? AND e.entity_id = ? AND a.partner_id IS NOT NULL AND e.date BETWEEN ? AND ?");
        $stmt->bind_param("iiss", $entityId, $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    private function getPaymentsFromPartnerAccounts($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT p.account_id, p.amount, p.invoiceType FROM payments p JOIN accounts a ON p.account_id = a.id WHERE a.entity_id = ? AND p.entity_id = ? AND a.partner_id IS NOT NULL AND p.date BETWEEN ? AND ?");
        $stmt->bind_param("iiss", $entityId, $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getInvoicesReport($data) {
        $type = $data['type'];
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        
        $isSales = ($type === 'sales');
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $personTable = $isSales ? 'customers' : 'suppliers';
        $personColumn = $isSales ? 'customerId' : 'supplierId';
        $personNameColumn = $isSales ? 'customerName' : 'supplierName';

        $summary_stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(totalAmount), 0) as totalAmount,
                COALESCE(SUM(discount), 0) as totalDiscount,
                COALESCE(SUM(paidAmount), 0) as totalPaid,
                COALESCE(SUM(totalAmount - discount - paidAmount), 0) as totalRemaining
            FROM `{$invoiceTable}` WHERE entity_id = ? AND date BETWEEN ? AND ? AND is_consignment = 0
        ");
        $summary_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $summary_stmt->execute();
        $summary_data = db_stmt_to_assoc_array($summary_stmt);
        
        $sql = "SELECT inv.*, p.name as {$personNameColumn} FROM `{$invoiceTable}` inv LEFT JOIN `{$personTable}` p ON inv.{$personColumn} = p.id WHERE inv.entity_id = ? AND inv.date BETWEEN ? AND ? AND inv.is_consignment = 0";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        $invoices = db_stmt_to_assoc_array($stmt);

        if (!empty($invoices)) {
            $invoiceIds = array_column($invoices, 'id');
            if (!empty($invoiceIds)) {
                $ids_placeholder = implode(',', array_fill(0, count($invoiceIds), '?'));
                $types_str = str_repeat('i', count($invoiceIds));

                $allItems = $this->getInvoiceItems($itemTable, $ids_placeholder, $types_str, $invoiceIds);
                $allPayments = $this->getInvoicePayments($type, $ids_placeholder, $types_str, $invoiceIds, $entityId);
                
                $itemsByInvoice = [];
                foreach ($allItems as $item) { $itemsByInvoice[$item['invoiceId']][] = $item; }
                $paymentsByInvoice = [];
                foreach ($allPayments as $payment) { $paymentsByInvoice[$payment['invoiceId']][] = $payment; }

                foreach ($invoices as &$invoice) {
                    $invoice['items'] = $itemsByInvoice[$invoice['id']] ?? [];
                    $invoice['payments'] = $paymentsByInvoice[$invoice['id']] ?? [];
                    $invoice['remainingAmount'] = $invoice['totalAmount'] - $invoice['discount'] - $invoice['paidAmount'];
                }
            }
        }
        
        return [
            'invoices' => $invoices, 
            'summary' => !empty($summary_data) ? $summary_data[0] : []
        ];
    }
    
    private function getInvoiceItems($itemTable, $placeholder, $types, $ids) {
        $sql = "SELECT i.*, p.name as productName FROM `{$itemTable}` i LEFT JOIN products p ON i.productId = p.id WHERE i.invoiceId IN ({$placeholder})";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    private function getInvoicePayments($invoiceType, $placeholder, $types, $ids, $entityId) {
        $sql = "SELECT * FROM `payments` WHERE entity_id = ? AND invoiceType = ? AND invoiceId IN ({$placeholder})";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('is' . $types, $entityId, $invoiceType, ...$ids);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    public function getInventoryLedgerReport($data) {
        $productId = intval($data['productId']);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];

        $os_purchase_stmt = $this->conn->prepare("SELECT COALESCE(SUM(pii.quantity), 0) as total FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pi.date < ?");
        $os_purchase_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_purchase_stmt->execute();
        $purchases_before = db_stmt_to_assoc_array($os_purchase_stmt)[0]['total'] ?? 0;
        
        $os_sales_stmt = $this->conn->prepare("SELECT COALESCE(SUM(sii.quantity), 0) as total FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE si.entity_id = ? AND sii.productId = ? AND si.date < ?");
        $os_sales_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_sales_stmt->execute();
        $sales_before = db_stmt_to_assoc_array($os_sales_stmt)[0]['total'] ?? 0;

        $openingStock = $purchases_before - $sales_before;

        $purchase_tx_stmt = $this->conn->prepare("SELECT pi.date, 'purchase' as type, pi.id as refId, pii.quantity, pii.dimensions FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pi.date BETWEEN ? AND ?");
        $purchase_tx_stmt->bind_param("iiss", $entityId, $productId, $startDate, $endDate);
        $purchase_tx_stmt->execute();
        $purchase_tx = db_stmt_to_assoc_array($purchase_tx_stmt);

        $sales_tx_stmt = $this->conn->prepare("SELECT si.date, 'sales' as type, si.id as refId, sii.quantity, sii.dimensions FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE si.entity_id = ? AND sii.productId = ? AND si.date BETWEEN ? AND ?");
        $sales_tx_stmt->bind_param("iiss", $entityId, $productId, $startDate, $endDate);
        $sales_tx_stmt->execute();
        $sales_tx = db_stmt_to_assoc_array($sales_tx_stmt);

        $transactions = array_merge($purchase_tx, $sales_tx);
        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });

        return ['openingStock' => $openingStock, 'transactions' => $transactions];
    }

    public function getPersonStatement($data) {
        $personType = $data['personType'];
        $personId = intval($data['personId']);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        
        $personTableMap = ['customer' => 'customers', 'supplier' => 'suppliers', 'partner' => 'partners'];
        $personTable = $personTableMap[$personType];
        
        $person_stmt = $this->conn->prepare("SELECT * FROM `{$personTable}` WHERE entity_id = ? AND id = ?");
        $person_stmt->bind_param("ii", $entityId, $personId);
        $person_stmt->execute();
        $person = db_stmt_to_assoc_array($person_stmt)[0] ?? null;
    
        if (!$person) {
            return ['error' => 'شخص یافت نشد.', 'statusCode' => 404];
        }

        $transactions = [];
        
        if (in_array($personType, ['customer', 'supplier']) && isset($person['initial_balance']) && floatval($person['initial_balance']) != 0) {
            $isDebit = ($personType === 'customer');
            $transactions[] = [ 'date' => 'مانده اولیه', 'description' => 'مانده حساب از قبل', 'debit' => $isDebit ? floatval($person['initial_balance']) : 0, 'credit' => !$isDebit ? floatval($person['initial_balance']) : 0, 'unix_timestamp' => 0 ];
        }
        
        if ($personType === 'customer' || $personType === 'supplier') {
            $isCustomer = ($personType === 'customer');
            $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
            $personColumn = $isCustomer ? 'customerId' : 'supplierId';
            
            $invoice_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount) as amount FROM `{$invoiceTable}` WHERE `entity_id` = ? AND `{$personColumn}` = ? AND date BETWEEN ? AND ?");
            $invoice_stmt->bind_param("iiss", $entityId, $personId, $startDate, $endDate);
            $invoice_stmt->execute();
            foreach (db_stmt_to_assoc_array($invoice_stmt) as $inv) {
                $transactions[] = [ 'date' => $inv['date'], 'description' => "فاکتور " . ($isCustomer ? "فروش" : "خرید") . " #" . $inv['id'], 'debit' => $isCustomer ? floatval($inv['amount']) : 0, 'credit' => !$isCustomer ? floatval($inv['amount']) : 0, 'unix_timestamp' => strtotime($inv['date']) ];
            }
        }
        
        $sql_payments = "
            SELECT p.*, ch.status as check_status, ch.dueDate as check_dueDate, ch.checkNumber, ch.id as checkId
            FROM payments p
            LEFT JOIN checks ch ON p.checkId = ch.id
            WHERE p.entity_id = ? AND p.person_type = ? AND p.person_id = ? AND p.date BETWEEN ? AND ?
        ";
        $payment_stmt = $this->conn->prepare($sql_payments);
        $payment_stmt->bind_param("isiss", $entityId, $personType, $personId, $startDate, $endDate);
        $payment_stmt->execute();

        foreach (db_stmt_to_assoc_array($payment_stmt) as $pay) {
            $debit = 0; $credit = 0;
            $isUnrealized = false;
            $tx_date = $pay['date'];
            $desc = $pay['description'] ?: "تراکنش علی الحساب";
            $refId = $pay['id'];
            $refType = 'payment';

            if ($pay['type'] === 'cash' || $pay['type'] === 'endorse_check') {
                if ($pay['invoiceId']) { $desc = "پرداخت مربوط به فاکتور #" . $pay['invoiceId']; }
                if ($pay['type'] === 'endorse_check') { $desc = '(خرج چک) ' . $desc; }

                if ($personType === 'partner') {
                    $desc = ($pay['transaction_type'] === 'receipt' ? 'واریز به شرکت' : 'برداشت از شرکت') . ($pay['description'] ? ' - ' . $pay['description'] : '');
                    if ($pay['transaction_type'] === 'receipt') { $debit = floatval($pay['amount']); } 
                    else { $credit = floatval($pay['amount']); }
                } else {
                    if ($pay['transaction_type'] === 'receipt') { $credit = floatval($pay['amount']); } 
                    else { $debit = floatval($pay['amount']); }
                }
            } 
            elseif ($pay['type'] === 'check') {
                $isCashed = $pay['check_status'] === 'cashed';
                $tx_date = $isCashed ? $pay['check_dueDate'] : $pay['date'];
                $desc = "(چک) " . ($pay['description'] ?: "تراکنش علی الحساب");
                if (!$isCashed) { $desc .= " (منتظر پاس شدن)"; $isUnrealized = true; }
                
                if ($personType === 'partner') {
                    if ($pay['transaction_type'] === 'payment') {
                         if ($isCashed) $credit = floatval($pay['amount']); else $credit = 0;
                    } else {
                         if ($isCashed) $debit = floatval($pay['amount']); else $debit = 0;
                    }
                } else { 
                    if ($pay['transaction_type'] === 'receipt') {
                         if ($isCashed) $credit = floatval($pay['amount']); else $credit = 0;
                    } else {
                         if ($isCashed) $debit = floatval($pay['amount']); else $debit = 0;
                    }
                }
                $refId = $pay['checkId'];
                $refType = 'check';
            }

            $transactions[] = [ 'date' => $tx_date, 'description' => $desc, 'debit' => $debit, 'credit' => $credit, 'unix_timestamp' => strtotime($tx_date), 'isUnrealized' => $isUnrealized, 'refId' => $refId, 'refType' => $refType ];
        }

        if ($personType === 'partner') {
            $stmt_acc = $this->conn->prepare("SELECT id FROM accounts WHERE entity_id = ? AND partner_id = ? LIMIT 1");
            $stmt_acc->bind_param("ii", $entityId, $personId);
            $stmt_acc->execute();
            $acc_res = db_stmt_to_assoc_array($stmt_acc);
            $personalAccountId = $acc_res[0]['id'] ?? null;

            if ($personalAccountId) {
                // Fetch invoice-related payments from personal account (can be cross-entity)
                $stmt_inv_pays = $this->conn->prepare("SELECT * FROM payments WHERE account_id = ? AND invoiceId IS NOT NULL AND date BETWEEN ? AND ?");
                $stmt_inv_pays->bind_param("iss", $personalAccountId, $startDate, $endDate);
                $stmt_inv_pays->execute();
                foreach(db_stmt_to_assoc_array($stmt_inv_pays) as $pay) {
                     $desc = ($pay['invoiceType'] === 'sales' ? "دریافت وجه بابت فاکتور فروش #" : "پرداخت وجه بابت فاکتور خرید #") . $pay['invoiceId'];
                     $debit = 0; $credit = 0;
                     if ($pay['invoiceType'] === 'sales') { $credit = floatval($pay['amount']); } 
                     else { $debit = floatval($pay['amount']); }
                     $transactions[] = [ 'date' => $pay['date'], 'description' => $desc, 'debit' => $debit, 'credit' => $credit, 'unix_timestamp' => strtotime($pay['date']), 'refId' => $pay['invoiceId'], 'refType' => ($pay['invoiceType'] === 'sales' ? 'salesInvoice' : 'purchaseInvoice') ];
                }

                // Fetch expenses from personal account (can be cross-entity)
                $stmt_expenses = $this->conn->prepare("SELECT * FROM expenses WHERE account_id = ? AND date BETWEEN ? AND ?");
                $stmt_expenses->bind_param("iss", $personalAccountId, $startDate, $endDate);
                $stmt_expenses->execute();
                foreach(db_stmt_to_assoc_array($stmt_expenses) as $exp) {
                    $desc = "هزینه از حساب شخصی: " . $exp['category'] . ($exp['description'] ? ' (' . $exp['description'] . ')' : '');
                    $transactions[] = [ 'date' => $exp['date'], 'description' => $desc, 'debit' => floatval($exp['amount']), 'credit' => 0, 'unix_timestamp' => strtotime($exp['date']), 'refId' => $exp['id'], 'refType' => 'expense' ];
                }
            }
        }

        usort($transactions, function($a, $b) { return $a['unix_timestamp'] - $b['unix_timestamp']; });
        
        foreach ($transactions as &$tx) {
            $tx['desc'] = $tx['description'];
            unset($tx['description']);
        }
        
        return ['transactions' => $transactions, 'person' => $person];
    }
    
    public function getAccountStatement($data) {
        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ?");
        $account_stmt->bind_param("i", $data['accountId']);
        $account_stmt->execute();
        $account = db_stmt_to_assoc_array($account_stmt)[0] ?? null;

        if (!$account) {
            return ['error' => 'Account not found', 'statusCode' => 404];
        }

        if ($account['partner_id']) {
            $statementData = $this->getPersonStatement([
                'personType' => 'partner',
                'personId' => $account['partner_id'],
                'startDate' => $data['startDate'],
                'endDate' => $data['endDate'],
                'entityId' => $account['entity_id']
            ]);
            
            $fullStatement = $this->getPersonStatement([
                'personType' => 'partner',
                'personId' => $account['partner_id'],
                'startDate' => '1970-01-01',
                'endDate' => $data['endDate'],
                'entityId' => $account['entity_id']
            ]);
            
            $opening_balance = 0;
            if(isset($fullStatement['transactions'])) {
                foreach ($fullStatement['transactions'] as $tx) {
                    if (strtotime($tx['date']) < strtotime($data['startDate'])) {
                        $opening_balance += ($tx['credit'] - $tx['debit']);
                    }
                }
            }
            
            if(isset($statementData['transactions'])) {
                foreach($statementData['transactions'] as &$tx) {
                    $tx['description'] = $tx['desc'];
                    unset($tx['desc']);
                }
            }
            
            return ['account' => $account, 'transactions' => $statementData['transactions'] ?? [], 'openingBalance' => $opening_balance];
        }

        $accountId = intval($data['accountId']);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        
        $opening_balance = floatval($account['current_balance']);
        $change_after_start = 0;
        
        $stmt_pay = $this->conn->prepare("SELECT SUM(IF(transaction_type='receipt', amount, -amount)) as total FROM payments WHERE account_id = ? AND type='cash' AND date >= ?");
        $stmt_pay->bind_param("is", $accountId, $startDate);
        $stmt_pay->execute();
        $change_after_start += db_stmt_to_assoc_array($stmt_pay)[0]['total'] ?? 0;
        
        $stmt_exp = $this->conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE account_id = ? AND date >= ?");
        $stmt_exp->bind_param("is", $accountId, $startDate);
        $stmt_exp->execute();
        $change_after_start -= db_stmt_to_assoc_array($stmt_exp)[0]['total'] ?? 0;
        
        $stmt_ch = $this->conn->prepare("SELECT SUM(IF(type='received', amount, -amount)) as total FROM checks WHERE cashed_in_account_id = ? AND status='cashed' AND dueDate >= ?");
        $stmt_ch->bind_param("is", $accountId, $startDate);
        $stmt_ch->execute();
        $change_after_start += db_stmt_to_assoc_array($stmt_ch)[0]['total'] ?? 0;
        
        $opening_balance -= $change_after_start;

        $transactions = [];
        $queries = [
            "SELECT id, date, amount, CONCAT('واریز بابت فاکتور فروش #', invoiceId) as description, 'salesInvoice' as refType, invoiceId as refId, 'payment_in' as source, amount as credit, 0 as debit FROM payments WHERE account_id = ? AND transaction_type = 'receipt' AND type = 'cash' AND invoiceId IS NOT NULL AND date BETWEEN ? AND ?",
            "SELECT id, date, amount, CONCAT('پرداخت بابت فاکتور خرید #', invoiceId) as description, 'purchaseInvoice' as refType, invoiceId as refId, 'payment_out' as source, 0 as credit, amount as debit FROM payments WHERE account_id = ? AND transaction_type = 'payment' AND type = 'cash' AND invoiceId IS NOT NULL AND date BETWEEN ? AND ?",
            "SELECT id, date, amount, CONCAT('هزینه: ', category, ' (#', id, ')') as description, 'expense' as refType, id as refId, 'expense_out' as source, 0 as credit, amount as debit FROM expenses WHERE account_id = ? AND date BETWEEN ? AND ?",
            "SELECT p.id, p.date, p.amount, p.description, 'payment' as refType, p.id as refId, 'partner_in' as source, p.amount as credit, 0 as debit FROM payments p WHERE p.account_id = ? AND p.transaction_type = 'receipt' AND p.type = 'cash' AND p.invoiceId IS NULL AND p.date BETWEEN ? AND ?",
            "SELECT p.id, p.date, p.amount, p.description, 'payment' as refType, p.id as refId, 'partner_out' as source, 0 as credit, p.amount as debit FROM payments p WHERE p.account_id = ? AND p.transaction_type = 'payment' AND p.type = 'cash' AND p.invoiceId IS NULL AND p.date BETWEEN ? AND ?",
            "SELECT id, dueDate as date, amount, CONCAT(IF(type='received', 'وصول چک شماره: ', 'پاس شدن چک شماره: '), checkNumber) as description, 'check' as refType, id as refId, IF(type='received', 'check_in', 'check_out') as source, IF(type='received', amount, 0) as credit, IF(type='payable', amount, 0) as debit FROM checks WHERE cashed_in_account_id = ? AND status = 'cashed' AND dueDate BETWEEN ? AND ?"
        ];

        foreach ($queries as $sql) {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iss", $accountId, $startDate, $endDate);
            $stmt->execute();
            $transactions = array_merge($transactions, db_stmt_to_assoc_array($stmt));
        }

        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
        
        return ['account' => $account, 'transactions' => $transactions, 'openingBalance' => $opening_balance];
    }
    
    public function getExpensesReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        
        $sql = "SELECT e.date, e.category, e.amount, e.description, a.name as accountName FROM expenses e LEFT JOIN accounts a ON e.account_id = a.id WHERE e.entity_id = ? AND e.date BETWEEN ? AND ? ORDER BY e.date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        $expenses = db_stmt_to_assoc_array($stmt);
        
        $total_stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ?");
        $total_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $total_stmt->execute();
        $total_array = db_stmt_to_assoc_array($total_stmt);
        $total = !empty($total_array) ? $total_array[0]['total'] : 0;

        return ['expenses' => $expenses, 'total' => $total];
    }

    public function getInventoryReport($data) {
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        $sql = "SELECT p.name, ps.dimensions, ps.quantity FROM products p JOIN product_stock ps ON p.id = ps.product_id WHERE p.entity_id = ? AND ps.quantity > 0 ORDER BY p.name, ps.dimensions";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    public function getInventoryValueReport($data) {
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];
        $stock_sql = "SELECT p.id as productId, p.name, ps.dimensions, ps.quantity FROM products p JOIN product_stock ps ON p.id = ps.product_id WHERE p.entity_id = ? AND ps.quantity > 0";
        $stock_stmt = $this->conn->prepare($stock_sql);
        $stock_stmt->bind_param("i", $entityId);
        $stock_stmt->execute();
        $stockItems = db_stmt_to_assoc_array($stock_stmt);
        $totalValue = 0;
        
        foreach ($stockItems as &$item) {
            $price_stmt = $this->conn->prepare("SELECT unitPrice FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pii.dimensions = ? ORDER BY pi.date DESC, pii.id DESC LIMIT 1");
            $price_stmt->bind_param("iis", $entityId, $item['productId'], $item['dimensions']);
            $price_stmt->execute();
            $price_result_arr = db_stmt_to_assoc_array($price_stmt);

            $lastPrice = $price_result_arr[0]['unitPrice'] ?? 0;
            $item['lastPrice'] = $lastPrice;
            $item['rowValue'] = $item['quantity'] * $lastPrice;
            $totalValue += $item['rowValue'];
        }
        
        return ['items' => $stockItems, 'totalValue' => $totalValue];
    }

    public function getCogsProfitReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $data['entityId'] ?? $_SESSION['current_entity_id'];

        $sales_stmt = $this->conn->prepare("
            SELECT 
                sii.productId, p.name as productName, sii.dimensions, 
                sii.quantity, sii.unitPrice as salePrice, si.id as invoiceId, si.date,
                si.totalAmount as invoiceTotalAmount, si.discount as invoiceDiscount
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON sii.invoiceId = si.id
            JOIN products p ON sii.productId = p.id
            WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
            ORDER BY si.date ASC
        ");
        $sales_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $sales_stmt->execute();
        $soldItems = db_stmt_to_assoc_array($sales_stmt);

        if (empty($soldItems)) {
            return ['summary' => ['totalSale' => 0, 'totalCogs' => 0, 'totalProfit' => 0], 'items' => []];
        }

        $summary = ['totalSale' => 0, 'totalCogs' => 0, 'totalProfit' => 0];
        foreach ($soldItems as &$item) {
            $purchase_price_stmt = $this->conn->prepare("
                SELECT pii.unitPrice FROM purchase_invoice_items pii
                JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                WHERE pi.entity_id = ? AND pii.productId = ? AND pii.dimensions = ? 
                ORDER BY pi.date DESC, pii.id DESC LIMIT 1
            ");
            $purchase_price_stmt->bind_param("iis", $entityId, $item['productId'], $item['dimensions']);
            $purchase_price_stmt->execute();
            $price_result_arr = db_stmt_to_assoc_array($purchase_price_stmt);
            
            $lastPurchasePrice = $price_result_arr[0]['unitPrice'] ?? 0;
            
            $itemTotalSaleGross = (float)$item['salePrice'] * (int)$item['quantity'];
            $invoiceTotalAmount = (float)$item['invoiceTotalAmount'];
            $invoiceDiscount = (float)$item['invoiceDiscount'];
            
            $proportionalDiscount = 0;
            if ($invoiceTotalAmount > 0) {
                $proportionalDiscount = ($itemTotalSaleGross / $invoiceTotalAmount) * $invoiceDiscount;
            }
            
            $item['purchasePrice'] = (float)$lastPurchasePrice;
            $item['totalSale'] = $itemTotalSaleGross - $proportionalDiscount;
            $item['totalCost'] = (float)$lastPurchasePrice * (int)$item['quantity'];
            $item['profit'] = $item['totalSale'] - $item['totalCost'];
            $item['proportionalDiscount'] = $proportionalDiscount;

            $summary['totalSale'] += $item['totalSale'];
            $summary['totalCogs'] += $item['totalCost'];
            $summary['totalProfit'] += $item['profit'];
        }

        return ['summary' => $summary, 'items' => $soldItems];
    }

    public function getReportForExport($reportType, $params) {
        $data = [];
        $filename = "report_{$reportType}_" . date('Y-m-d') . ".csv";
        $headers = [];
        $rows = [];

        switch ($reportType) {
            case 'inventory':
                $data = $this->getInventoryReport($params);
                $headers = ['نام طرح', 'ابعاد', 'تعداد موجود'];
                $rows = array_map(function($item) {
                    return [$item['name'], $item['dimensions'], $item['quantity']];
                }, $data);
                break;
            
            case 'expenses':
                $data = $this->getExpensesReport($params);
                $headers = ['تاریخ', 'دسته‌بندی', 'مبلغ', 'توضیحات', 'حساب پرداخت'];
                $rows = array_map(function($item) {
                    return [$item['date'], $item['category'], $item['amount'], $item['description'], $item['accountName']];
                }, $data['expenses']);
                break;
            
            default:
                return ['error' => 'Report type not supported for export.'];
        }

        return [
            'filename' => $filename,
            'headers' => $headers,
            'rows' => $rows
        ];
    }
}