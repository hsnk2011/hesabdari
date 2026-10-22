<?php
// /api/models/ReportModel.php

require_once __DIR__ . '/../core/AnalyticsCalculations.php';

class ReportModel {
    use AnalyticsCalculations;
    
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function inventorySerialLabels(array $rows): array {
        $labels = [];
        foreach ($rows as $row) {
            if (!empty($row['serial_id'])) {
                $labels[] = $row['serial_id'];
            } else {
                $labels[] = 'INV-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT);
            }
        }
        return $labels;
    }

    private function enrichStatementItemsWithSerialIds(array $items, string $linkColumn, string $itemKeyColumn): array {
        foreach ($items as &$item) {
            if (!empty($item['is_service'])) {
                $item['serialIds'] = [];
                continue;
            }
            $linkId = intval($item[$itemKeyColumn] ?? 0);
            if ($linkId <= 0) {
                $item['serialIds'] = [];
                continue;
            }
            $stmt = $this->conn->prepare("SELECT id, serial_id FROM inventory_items WHERE {$linkColumn} = ?");
            $stmt->bind_param("i", $linkId);
            $stmt->execute();
            $item['serialIds'] = $this->inventorySerialLabels(db_stmt_to_assoc_array($stmt));
        }
        unset($item);
        return $items;
    }

    private function parseBoolParam($data, string $key, bool $default = false): bool {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $val = $data[$key];
        if (is_bool($val)) {
            return $val;
        }
        $parsed = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    /**
     * Purchase invoices for a supplier that target an order warehouse and are not yet delivered.
     * @return int[]
     */
    private function getUndeliveredOrderPurchaseIds(int $entityId, int $supplierId): array {
        $hasWhType = false;
        $col = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
        if ($col && $col->num_rows > 0) {
            $hasWhType = true;
        }
        if (!$hasWhType) {
            return [];
        }
        $hasDelivery = false;
        $dcol = $this->conn->query("SHOW COLUMNS FROM purchase_invoices LIKE 'delivery_status'");
        if ($dcol && $dcol->num_rows > 0) {
            $hasDelivery = true;
        }
        $deliveryClause = $hasDelivery
            ? "AND COALESCE(pi.delivery_status, 'preparing') <> 'delivered'"
            : '';
        $sql = "
            SELECT pi.id
            FROM purchase_invoices pi
            INNER JOIN warehouses w ON w.id = pi.warehouse_id AND w.warehouse_type = 'order'
            WHERE pi.entity_id = ? AND pi.supplierId = ?
            {$deliveryClause}
        ";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $entityId, $supplierId);
        $stmt->execute();
        $ids = [];
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $ids[] = intval($row['id']);
        }
        return $ids;
    }

    /**
     * Signed period opening for person statements (same convention as running balance).
     * Customer/contact-as-debtor: positive = person owes company.
     * Supplier/partner/contact-as-creditor: positive = company owes person.
     */
    private function computePersonOpeningFromTransactions(array $beforeTransactions, string $personType, float $initialBalance, bool $calculateConsignment = false): float {
        $isCustomer = ($personType === 'customer');
        $net = 0.0;
        foreach ($beforeTransactions as $t) {
            if (!$calculateConsignment && !empty($t['is_consignment'])) {
                continue;
            }
            $debit = floatval($t['debit'] ?? 0);
            $credit = floatval($t['credit'] ?? 0);
            $net += $isCustomer ? ($debit - $credit) : ($credit - $debit);
        }
        if (in_array($personType, ['customer', 'supplier', 'contact'], true)) {
            return $initialBalance + $net;
        }
        return $net;
    }

    private function buildPreviousBalanceRow(string $personType, float $openingBalance): array {
        $isCustomer = ($personType === 'customer');
        $debit = 0.0;
        $credit = 0.0;
        if ($openingBalance >= 0) {
            if ($isCustomer) {
                $debit = $openingBalance;
            } else {
                $credit = $openingBalance;
            }
        } else {
            $abs = abs($openingBalance);
            if ($isCustomer) {
                $credit = $abs;
            } else {
                $debit = $abs;
            }
        }
        return [
            'date' => 'مانده از قبل',
            'description' => 'مانده حساب از قبل (اول دوره)',
            'debit' => $debit,
            'credit' => $credit,
            'unix_timestamp' => 0,
            'is_consignment' => 0,
            'isPreviousBalance' => true,
        ];
    }

    private function formatExportDate($date): string {
        if ($date === null || $date === '') {
            return '';
        }
        $date = (string)$date;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return $date;
        }
        return gregorian_to_jalali(substr($date, 0, 10));
    }

    private function appendStatementExportRows(array &$rows, array $tx, float &$balance, bool $isCustomer, bool $isAccount, bool $showInvoiceDetails, bool $calculateConsignment = false): void {
        $debit = $tx['debit'] ?? 0;
        $credit = $tx['credit'] ?? 0;
        $is_consignment = $tx['is_consignment'] ?? 0;
        $description = $tx['description'] ?? ($tx['desc'] ?? '');

        if ((!$is_consignment || $calculateConsignment) && empty($tx['isUnrealized'])) {
            if ($isAccount) {
                $balance += $credit - $debit;
            } else {
                $balance += $isCustomer ? ($debit - $credit) : ($credit - $debit);
            }
        }

        $items = ($showInvoiceDetails && !empty($tx['items'])) ? $tx['items'] : [];
        if (!empty($items)) {
            $first = true;
            foreach ($items as $item) {
                $productName = $item['productName'] ?? $item['item_description'] ?? '-';
                $serialIds = !empty($item['serialIds']) ? implode(', ', $item['serialIds']) : '-';
                if ($showInvoiceDetails) {
                    $rows[] = [
                        $first ? $this->formatExportDate($tx['date']) : '',
                        $first ? $description : '',
                        $productName,
                        $item['dimensions'] ?? '-',
                        $item['quantity'] ?? '',
                        $item['unitPrice'] ?? '',
                        $serialIds,
                        $first ? $debit : '',
                        $first ? $credit : '',
                        $first ? $balance : '',
                    ];
                }
                $first = false;
            }
            return;
        }

        if ($showInvoiceDetails) {
            $rows[] = [$this->formatExportDate($tx['date']), $description, '', '', '', '', '', $debit, $credit, $balance];
        } else {
            $rows[] = [$this->formatExportDate($tx['date']), $description, $debit, $credit, $balance];
        }
    }

    // *** Partner capital source helpers (date-range aware) ***
    private function getTransfersFromPartnerAccountsRange($entityId, $fromDate, $toDate) {
        $sql = "
            SELECT p.id, p.account_id, p.amount, p.transaction_type, p.description, p.date, a.name as accountName
            FROM payments p
            JOIN accounts a ON p.account_id = a.id
            WHERE a.entity_id = ? AND p.entity_id = ?
              AND a.partner_id IS NOT NULL
              AND p.person_id IS NULL
              AND IFNULL(p.person_type,'') <> 'other'
              AND p.invoiceId IS NULL
              AND (p.description IS NULL OR p.description NOT LIKE 'CLOSING:%')
        ";
        $types = 'ii';
        $params = [$entityId, $entityId];
        if ($fromDate) {
            $sql .= " AND p.date >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND p.date <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY p.date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $partnerAcc = [];
        $accStmt = $this->conn->prepare("SELECT id, partner_id FROM accounts WHERE entity_id = ? AND partner_id IS NOT NULL");
        $accStmt->bind_param("i", $entityId);
        $accStmt->execute();
        foreach (db_stmt_to_assoc_array($accStmt) as $acc) {
            $partnerAcc[(int)$acc['id']] = (int)$acc['partner_id'];
        }

        foreach ($rows as &$row) {
            $row['is_inter_partner'] = 0;
            $row['counterparty_account_id'] = null;
            $self = (int)$row['account_id'];
            $desc = (string)($row['description'] ?? '');

            if (preg_match('/^TRANSFER:(\d+):(\d+):/', $desc, $m)) {
                $fromId = (int)$m[1];
                $toId = (int)$m[2];
                $other = ($self === $fromId) ? $toId : $fromId;
                $row['counterparty_account_id'] = $other;
                if (isset($partnerAcc[$other])) {
                    $row['is_inter_partner'] = 1;
                }
                continue;
            }

            $amt = (float)$row['amount'];
            $date = $row['date'];
            $oppType = ($row['transaction_type'] === 'payment') ? 'receipt' : 'payment';
            foreach ($rows as $other) {
                if ((int)$other['id'] === (int)$row['id']) continue;
                if ((int)$other['account_id'] === $self) continue;
                if ($other['date'] !== $date) continue;
                if ($other['transaction_type'] !== $oppType) continue;
                if (abs((float)$other['amount'] - $amt) > 0.01) continue;
                if (!isset($partnerAcc[(int)$other['account_id']])) continue;
                $row['is_inter_partner'] = 1;
                $row['counterparty_account_id'] = (int)$other['account_id'];
                break;
            }
        }
        unset($row);

        return $rows;
    }

    private function getNonInvoicePersonPaymentsFromPartnerAccountsRange($entityId, $fromDate, $toDate) {
        // Customer/supplier always. Partner counterparty only when person_id is NOT
        // the account owner (owner's own partner rows live in partner_transactions).
        $sql = "
            SELECT p.id, p.account_id, p.amount, p.transaction_type, p.person_type, p.person_id, p.description, p.date,
                   COALESCE(c.name, s.name, pa.name) as personName
            FROM payments p
            JOIN accounts a ON p.account_id = a.id
            LEFT JOIN customers c ON p.person_type = 'customer' AND p.person_id = c.id
            LEFT JOIN suppliers s ON p.person_type = 'supplier' AND p.person_id = s.id
            LEFT JOIN partners pa ON p.person_type = 'partner' AND p.person_id = pa.id
            WHERE a.entity_id = ? AND p.entity_id = ?
              AND a.partner_id IS NOT NULL
              AND p.invoiceId IS NULL
              AND p.person_id IS NOT NULL
              AND (
                    p.person_type IN ('customer', 'supplier')
                    OR (p.person_type = 'partner' AND p.person_id <> a.partner_id)
                  )
              AND (p.description IS NULL OR p.description NOT LIKE 'CLOSING:%')
        ";
        $types = 'ii';
        $params = [$entityId, $entityId];
        if ($fromDate) {
            $sql .= " AND p.date >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND p.date <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY p.date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    // *** END partner capital source helpers ***

    // *** START: New helper function to calculate inventory value at a specific date ***
    public function getInventoryValueAtDate($date, $entityId) {
        // Calculates the value based on items purchased ON or BEFORE the date,
        // and either still in stock OR sold AFTER the date.
        $stmt = $this->conn->prepare("\n            SELECT COALESCE(SUM(pii.unitPrice), 0) as totalValue
            FROM inventory_items ii
            JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            LEFT JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
            LEFT JOIN sales_invoices si ON sii.invoiceId = si.id
            WHERE pi.entity_id = ? AND pi.date <= ?
              AND (
                    ii.status IN ('in_stock', 'consignment_out')
                    OR (ii.status = 'sold' AND si.date > ?)
                    -- A returned item only sat in stock on the snapshot date if
                    -- its return had already happened by then.
                    OR (ii.status = 'returned' AND NOT EXISTS (
                        SELECT 1 FROM return_invoice_items rii
                        JOIN return_invoices ri ON rii.return_invoice_id = ri.id
                        WHERE rii.original_invoice_item_id = ii.sales_invoice_item_id
                          AND ri.date > ?
                    ))
                  )
              AND (pi.is_consignment = 0 OR pi.is_consignment IS NULL)
        ");
        $stmt->bind_param("isss", $entityId, $date, $date, $date);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        return $result[0]['totalValue'] ?? 0;
    }
    // *** END: New helper function ***

    public function getProfitLossReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);
        // *** START: Changed from useCogs checkbox to calculationMethod radio ***
        $calculationMethod = $data['calculationMethod'] ?? 'cogs'; // 'standard', 'cogs', 'inventory'
        // *** END: Changed ***

        $result = [];

        $query = "\n            SELECT\n                COALESCE(SUM(CASE WHEN type = 'sale' THEN amount END), 0) as grossSales,\n                COALESCE(SUM(CASE WHEN type = 'sale' THEN discount END), 0) as salesDiscounts,\n                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) as totalCompanyExpenses\n            FROM (\n                SELECT 'sale' as type, totalAmount as amount, discount FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0 AND date BETWEEN ? AND ?\n                UNION ALL\n                SELECT 'expense' as type, amount, 0 as discount FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ?\n            ) as combined_data\n        ";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ississ", $entityId, $startDate, $endDate, $entityId, $startDate, $endDate);
        $stmt->execute();
        $pl_data = db_stmt_to_assoc_array($stmt);
        $result = !empty($pl_data) ? $pl_data[0] : [];
        
        $result['calculationMethod'] = $calculationMethod;
        $result['totalCogs'] = 0;
        $result['openingInventory'] = 0;
        $result['closingInventory'] = 0;
        $result['grossPurchases'] = 0; // Initialize purchase value
        $result['salesReturns'] = 0;
        $result['purchaseReturns'] = 0;

        // Fetch sales and purchase return amounts for the period
        $returns_query = "
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'sales_return' THEN (totalAmount - discount) END), 0) as salesReturns,
                COALESCE(SUM(CASE WHEN type = 'purchase_return' THEN (totalAmount - discount) END), 0) as purchaseReturns
            FROM return_invoices 
            WHERE entity_id = ? AND date BETWEEN ? AND ?
        ";
        $returns_stmt = $this->conn->prepare($returns_query);
        $returns_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $returns_stmt->execute();
        $returns_data = db_stmt_to_assoc_array($returns_stmt)[0] ?? ['salesReturns' => 0, 'purchaseReturns' => 0];
        $result['salesReturns'] = (float)$returns_data['salesReturns'];
        $result['purchaseReturns'] = (float)$returns_data['purchaseReturns'];

        $netSales = (float)$result['grossSales'] - (float)$result['salesDiscounts'] - $result['salesReturns'];

        // Fetch Net Purchases (used in standard and inventory methods)
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
        $result['grossPurchases'] = (float)$purchase_data['grossPurchases'];
        $netPurchases = $result['grossPurchases'] - $result['purchaseReturns'];
        $result['netPurchases'] = $netPurchases;

        if ($calculationMethod === 'cogs') {
            $returnCogs = $this->calculateSalesReturnCogs($startDate, $endDate, $entityId);
            $result['salesReturnCogs'] = $returnCogs;
            $result['totalCogs'] = $this->calculateTotalCogs($startDate, $endDate, $entityId);
            $result['totalCogsBeforeReturns'] = (float)$result['totalCogs'] + $returnCogs;
            // Physical-count adjustments: shortages are a cost, found stock a gain.
            $adjStmt = $this->conn->prepare(
                "SELECT COALESCE(SUM(shrinkage_value), 0) AS shrink, COALESCE(SUM(surplus_value), 0) AS surplus
                 FROM stocktaking WHERE entity_id = ? AND status = 'completed' AND date BETWEEN ? AND ?"
            );
            $adjStmt->bind_param('iss', $entityId, $startDate, $endDate);
            $adjStmt->execute();
            $adj = db_stmt_to_assoc_array($adjStmt)[0] ?? ['shrink' => 0, 'surplus' => 0];
            $result['stocktakingShrinkage'] = (float)$adj['shrink'];
            $result['stocktakingSurplus'] = (float)$adj['surplus'];
            $result['totalCogs'] = (float)$result['totalCogs'] + $result['stocktakingShrinkage'] - $result['stocktakingSurplus'];
            $result['grossProfit'] = $netSales - $result['totalCogs'];
        } 
        elseif ($calculationMethod === 'inventory') {
            $openingDate = date('Y-m-d', strtotime($startDate . ' -1 day'));
            $closingDate = $endDate;
            $result['openingInventory'] = $this->getInventoryValueAtDate($openingDate, $entityId);
            $result['closingInventory'] = $this->getInventoryValueAtDate($closingDate, $entityId);
            $costOfGoodsSoldInventoryMethod = $result['openingInventory'] + $netPurchases - $result['closingInventory'];
            $result['grossProfit'] = $netSales - $costOfGoodsSoldInventoryMethod;
        } 
        else { // Default to 'standard'
            $result['grossProfit'] = $netSales - $netPurchases;
        }
        
        $result['netOperatingProfit'] = $result['grossProfit'] - (float)$result['totalCompanyExpenses'];

        // Miscellaneous cash (person_type=other): bank interest, fee refunds, etc.
        // All accounts count — include_in_cash_position only gates balance/cash-position totals.
        $otherStmt = $this->conn->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'receipt' THEN amount ELSE 0 END), 0) AS totalOtherIncome,
                COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE 0 END), 0) AS totalOtherOutgoings
             FROM payments
             WHERE entity_id = ?
               AND person_type = 'other'
               AND invoiceId IS NULL
               AND date BETWEEN ? AND ?"
        );
        $otherStmt->bind_param('iss', $entityId, $startDate, $endDate);
        $otherStmt->execute();
        $otherRow = db_stmt_to_assoc_array($otherStmt)[0] ?? [];
        $result['totalOtherIncome'] = (float)($otherRow['totalOtherIncome'] ?? 0);
        $result['totalOtherOutgoings'] = (float)($otherRow['totalOtherOutgoings'] ?? 0);

        // Company incomes (registered via «ثبت درآمد») — all accounts, same as expenses.
        $incStmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS totalCompanyIncome
             FROM incomes
             WHERE entity_id = ? AND date BETWEEN ? AND ?"
        );
        $incStmt->bind_param('iss', $entityId, $startDate, $endDate);
        $incStmt->execute();
        $incRow = db_stmt_to_assoc_array($incStmt)[0] ?? [];
        $result['totalCompanyIncome'] = (float)($incRow['totalCompanyIncome'] ?? 0);

        // دارایی‌ها: realized gain/loss of asset sales (not part of sales/gross profit).
        require_once __DIR__ . '/Asset.php';
        $result['totalAssetGain'] = Asset::realizedGain($this->conn, (int)$entityId, $startDate, $endDate);

        $result['netOperatingProfit'] =
            (float)$result['netOperatingProfit']
            + $result['totalCompanyIncome']
            + $result['totalOtherIncome']
            - $result['totalOtherOutgoings']
            + $result['totalAssetGain'];

        $result['expenseBreakdown'] = $this->getExpenseBreakdown($startDate, $endDate, $entityId);
        $result['incomeBreakdown'] = $this->getIncomeBreakdown($startDate, $endDate, $entityId);
        
        // NEW: Add analytics-style metrics for comprehensive reporting
        $salesData = $this->calculateTotalSales($this->conn, $startDate, $endDate, $entityId);
        $totalSales = floatval($salesData['total_sales']);
        
        // Calculate return rate using shared trait method
        $returnData = $this->calculateReturnRate($this->conn, $startDate, $endDate, $entityId, $totalSales);
        $result['return_rate'] = round($returnData['return_rate'], 2);
        $result['total_sales_returns'] = round($returnData['total_returns'], 2);
        
        // Calculate growth rate using shared trait method
        $result['growth_rate'] = round($this->calculateGrowthRate($this->conn, $startDate, $endDate, $entityId, $totalSales), 2);
        
        if (!empty($data['skipPartnerDetails'])) {
            return $result;
        }

        $result['dataQuality'] = $this->getProfitLossDataQuality($startDate, $endDate, $entityId);
        $result['partners'] = $this->buildPartnerProfitAllocation(
            $entityId,
            $startDate,
            $endDate,
            $calculationMethod,
            (float)$result['grossProfit'],
            (float)$result['netOperatingProfit']
        );

        $capitalPack = $this->buildPartnerCapitalDetails($entityId, $startDate, $endDate, $result['partners']);
        $result['capitalSummary'] = $capitalPack['capitalSummary'];
        $result['capitalSummary']['closingCapital'] =
            (float)$result['capitalSummary']['openingCapital']
            + (float)$result['netOperatingProfit']
            + (float)$result['capitalSummary']['periodDeposits']
            - (float)$result['capitalSummary']['periodWithdrawals'];

        foreach ($result['partners'] as &$partnerRow) {
            $pid = (int)$partnerRow['id'];
            $cap = $capitalPack['byPartner'][$pid] ?? null;
            if ($cap) {
                $partnerRow['capital'] = $cap;
            }
        }
        unset($partnerRow);

        $result['accounts'] = $this->getAccountsWithPartnerId($entityId);
        // Legacy flat lists (kept for partner-profit report / older UI paths)
        $result['partner_transactions'] = $this->getPartnerTransactionsRange($entityId, $startDate, $endDate, false);
        $result['expenses'] = $this->getExpensesFromPartnerAccountsRange($entityId, $startDate, $endDate);
        $result['payments'] = $this->getPaymentsFromPartnerAccountsRange($entityId, $startDate, $endDate);
        $result['transfers'] = $this->getTransfersFromPartnerAccountsRange($entityId, $startDate, $endDate);
        $result['non_invoice_person_payments'] = $this->getNonInvoicePersonPaymentsFromPartnerAccountsRange($entityId, $startDate, $endDate);

        $netSalesForMetrics = (float)$result['grossSales'] - (float)$result['salesDiscounts'] - (float)$result['salesReturns'];
        $result['metrics'] = [
            'netSales' => $netSalesForMetrics,
            'grossMarginPercent' => $netSalesForMetrics > 0
                ? round(((float)$result['grossProfit'] / $netSalesForMetrics) * 100, 2) : 0,
            'netMarginPercent' => $netSalesForMetrics > 0
                ? round(((float)$result['netOperatingProfit'] / $netSalesForMetrics) * 100, 2) : 0,
            'expenseRatioPercent' => $netSalesForMetrics > 0
                ? round(((float)$result['totalCompanyExpenses'] / $netSalesForMetrics) * 100, 2) : 0,
        ];

        $result['supplierDebts'] = $this->getSupplierDebts($endDate, $entityId);
        $result['customerReceivables'] = $this->getCustomerReceivables($endDate, $entityId);
        $result['cashPosition'] = $this->getCashPositionForReport($entityId);

        return $result;
    }

    /**
     * تسهیم سود شرکا با در نظر گرفتن تاریخ مؤثر سهم و مرزهای بستن حساب.
     */
    private function buildPartnerProfitAllocation($entityId, $startDate, $endDate, $calculationMethod, $grossProfit, $netOperatingProfit) {
        require_once __DIR__ . '/PeriodClosing.php';
        $closingModel = new PeriodClosing($this->conn);

        $boundaries = $this->getClosingBoundariesInRange($entityId, $startDate, $endDate);
        $segments = $this->buildProfitSegments($startDate, $endDate, $boundaries);
        $allocationByPartner = [];

        foreach ($segments as $segment) {
            $segStart = $segment['start'];
            $segEnd = $segment['end'];
            if ($segStart > $segEnd) {
                continue;
            }

            if (count($segments) === 1) {
                $segGross = $grossProfit;
                $segNet = $netOperatingProfit;
            } else {
                $segReport = $this->getProfitLossReport([
                    'startDate' => $segStart,
                    'endDate' => $segEnd,
                    'entityId' => $entityId,
                    'calculationMethod' => $calculationMethod,
                    'skipPartnerDetails' => true,
                ]);
                $segGross = (float)($segReport['grossProfit'] ?? 0);
                $segNet = (float)($segReport['netOperatingProfit'] ?? 0);
            }

            $partners = $closingModel->getPartnersAtDate($entityId, $segEnd);
            $totalShare = 0;
            foreach ($partners as $p) {
                $totalShare += (float)$p['share'];
            }

            // سهم هر شریک با روش باقیمانده: باقی‌مانده گردکردن به شریک آخر می‌رسد
            // تا جمع سهم‌های نمایشی دقیقاً برابر سود هر بخش باشد.
            $grossTarget = (int)round($segGross);
            $netTarget = (int)round($segNet);
            $allocatedGross = 0;
            $allocatedNet = 0;
            $count = count($partners);
            $idx = 0;
            foreach ($partners as $p) {
                $partnerId = (int)$p['id'];
                $ratio = $totalShare > 0 ? (float)$p['share'] / $totalShare : 0;
                if (!isset($allocationByPartner[$partnerId])) {
                    $allocationByPartner[$partnerId] = [
                        'id' => $partnerId,
                        'name' => $p['name'],
                        'grossProfitShare' => 0,
                        'netProfitShare' => 0,
                    ];
                }
                $isLast = ($idx === $count - 1);
                if ($isLast && $count > 0) {
                    $grossShare = $grossTarget - $allocatedGross;
                    $netShare = $netTarget - $allocatedNet;
                } else {
                    $grossShare = (int)round($grossTarget * $ratio);
                    $netShare = (int)round($netTarget * $ratio);
                    $allocatedGross += $grossShare;
                    $allocatedNet += $netShare;
                }
                $allocationByPartner[$partnerId]['grossProfitShare'] += $grossShare;
                $allocationByPartner[$partnerId]['netProfitShare'] += $netShare;
                $idx++;
            }
        }

        $displayPartners = $closingModel->getPartnersAtDate($entityId, $endDate);
        $displayTotalShare = 0;
        foreach ($displayPartners as $p) {
            $displayTotalShare += (float)$p['share'];
        }

        $results = [];
        $seenIds = [];
        foreach ($displayPartners as $p) {
            $partnerId = (int)$p['id'];
            $seenIds[$partnerId] = true;
            $ratio = $displayTotalShare > 0 ? (float)$p['share'] / $displayTotalShare : 0;
            $alloc = $allocationByPartner[$partnerId] ?? null;
            $results[] = [
                'id' => $partnerId,
                'name' => $p['name'],
                'share' => (float)$p['share'],
                'shareRatio' => round($ratio, 6),
                'sharePercent' => round($ratio * 100, 2),
                'grossProfitShare' => $alloc['grossProfitShare'] ?? round($grossProfit * $ratio),
                'netProfitShare' => $alloc['netProfitShare'] ?? round($netOperatingProfit * $ratio),
            ];
        }

        foreach ($allocationByPartner as $partnerId => $alloc) {
            if (!empty($seenIds[$partnerId])) {
                continue;
            }
            $results[] = [
                'id' => $partnerId,
                'name' => $alloc['name'],
                'share' => 0,
                'shareRatio' => 0,
                'sharePercent' => 0,
                'grossProfitShare' => $alloc['grossProfitShare'],
                'netProfitShare' => $alloc['netProfitShare'],
            ];
        }

        return $results;
    }

    private function getClosingBoundariesInRange($entityId, $startDate, $endDate) {
        $stmt = $this->conn->prepare(
            "SELECT period_end_date
             FROM account_closings
             WHERE entity_id = ? AND status = 'completed'
               AND period_end_date >= ? AND period_end_date < ?
             ORDER BY period_end_date ASC"
        );
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return array_column($rows, 'period_end_date');
    }

    private function buildProfitSegments($startDate, $endDate, array $boundaries) {
        if (empty($boundaries)) {
            return [['start' => $startDate, 'end' => $endDate]];
        }

        $segments = [];
        $segmentStart = $startDate;
        foreach ($boundaries as $boundary) {
            $segments[] = ['start' => $segmentStart, 'end' => $boundary];
            $segmentStart = date('Y-m-d', strtotime($boundary . ' +1 day'));
        }
        $segments[] = ['start' => $segmentStart, 'end' => $endDate];
        return $segments;
    }

    /**
     * Unit cost expression for an inventory item linked to a sale (aliases: ii, pii, si).
     */
    private function inventoryItemCostExpr() {
        return "COALESCE(pii.unitPrice,
                    (SELECT pii2.unitPrice FROM purchase_invoice_items pii2
                     JOIN purchase_invoices pi2 ON pii2.invoiceId = pi2.id
                     WHERE pii2.productId = ii.product_id AND pii2.dimensions = ii.dimensions
                       AND pi2.entity_id = si.entity_id
                       AND pi2.date <= si.date
                     ORDER BY pi2.date DESC, pii2.id DESC LIMIT 1),
                    ii.cost_price, 0)";
    }

    private function calculateTotalCogs($startDate, $endDate, $entityId) {
        $cost = $this->inventoryItemCostExpr();
        $cogs_stmt = $this->conn->prepare("
            SELECT COALESCE(SUM({$cost}), 0) as totalCogs
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON sii.invoiceId = si.id
            -- An item sold, returned and resold is re-linked to the new sale;
            -- returned_from_sales_item_id keeps it counted for the original sale too,
            -- otherwise the original period showed only the return's negative COGS.
            JOIN inventory_items ii ON (ii.sales_invoice_item_id = sii.id OR ii.returned_from_sales_item_id = sii.id)
            LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            WHERE si.entity_id = ?
              AND si.is_consignment = 0
              AND si.date BETWEEN ? AND ?
        ");
        $cogs_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $cogs_stmt->execute();
        $grossCogs = (float)((db_stmt_to_assoc_array($cogs_stmt)[0]['totalCogs'] ?? 0));

        // Reverse COGS for sales returns in the period (same-period or prior-period sales).
        $returnCogs = $this->calculateSalesReturnCogs($startDate, $endDate, $entityId);
        return $grossCogs - $returnCogs;
    }

    private function calculateSalesReturnCogs($startDate, $endDate, $entityId) {
        $cost = $this->inventoryItemCostExpr();
        // Prefer explicit inventory_item_ids on the return line; fall back to items
        // linked to the original sales line / returned_from marker.
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM({$cost}), 0) as returnCogs
            FROM return_invoices ri
            JOIN return_invoice_items rii ON rii.return_invoice_id = ri.id
            JOIN inventory_items ii ON (
                (
                    rii.inventory_item_ids IS NOT NULL AND rii.inventory_item_ids != ''
                    AND FIND_IN_SET(
                        ii.id,
                        REPLACE(REPLACE(REPLACE(rii.inventory_item_ids, '[', ''), ']', ''), ' ', '')
                    )
                )
                OR (
                    (rii.inventory_item_ids IS NULL OR rii.inventory_item_ids = '')
                    AND rii.original_invoice_item_id IS NOT NULL
                    AND (
                        ii.sales_invoice_item_id = rii.original_invoice_item_id
                        OR ii.returned_from_sales_item_id = rii.original_invoice_item_id
                    )
                )
            )
            LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            LEFT JOIN sales_invoice_items sii ON sii.id = COALESCE(
                NULLIF(ii.sales_invoice_item_id, 0),
                NULLIF(ii.returned_from_sales_item_id, 0),
                rii.original_invoice_item_id
            )
            LEFT JOIN sales_invoices si ON sii.invoiceId = si.id
            WHERE ri.entity_id = ?
              AND ri.type = 'sales_return'
              AND ri.date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        return (float)((db_stmt_to_assoc_array($stmt)[0]['returnCogs'] ?? 0));
    }

    private function getExpenseBreakdown($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getIncomeBreakdown($startDate, $endDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT category, SUM(amount) as total FROM incomes WHERE entity_id = ? AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    /**
     * Data-quality signals for the P&L report (does not change totals).
     */
    private function getProfitLossDataQuality($startDate, $endDate, $entityId) {
        $unlinked = $this->conn->prepare("
            SELECT COUNT(*) as cnt,
                   COALESCE(SUM(sii.quantity * sii.unitPrice), 0) as salesValue
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON sii.invoiceId = si.id
            LEFT JOIN inventory_items ii ON ii.sales_invoice_item_id = sii.id
            WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
              AND ii.id IS NULL
              AND COALESCE(sii.is_service, 0) = 0
        ");
        $unlinked->bind_param("iss", $entityId, $startDate, $endDate);
        $unlinked->execute();
        $unlinkedRow = db_stmt_to_assoc_array($unlinked)[0] ?? ['cnt' => 0, 'salesValue' => 0];

        $zeroCost = $this->conn->prepare("
            SELECT COUNT(*) as cnt,
                   COALESCE(SUM(sii.unitPrice), 0) as salesValue
            FROM sales_invoice_items sii
            JOIN sales_invoices si ON sii.invoiceId = si.id
            JOIN inventory_items ii ON ii.sales_invoice_item_id = sii.id
            LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
              AND COALESCE(pii.unitPrice, ii.cost_price, 0) = 0
        ");
        $zeroCost->bind_param("iss", $entityId, $startDate, $endDate);
        $zeroCost->execute();
        $zeroRow = db_stmt_to_assoc_array($zeroCost)[0] ?? ['cnt' => 0, 'salesValue' => 0];

        $returnCogs = $this->calculateSalesReturnCogs($startDate, $endDate, $entityId);

        return [
            'unlinkedSaleLines' => (int)$unlinkedRow['cnt'],
            'unlinkedSaleValue' => (float)$unlinkedRow['salesValue'],
            'zeroCostSoldItems' => (int)$zeroRow['cnt'],
            'zeroCostSaleValue' => (float)$zeroRow['salesValue'],
            'salesReturnCogsReversed' => $returnCogs,
        ];
    }

    /**
     * Build full capital claim breakdown per partner (opening + period + closing).
     * Matches صورتحساب اشخاص capital view (not bank wallet).
     */
    private function buildPartnerCapitalDetails($entityId, $startDate, $endDate, array $partners) {
        $accounts = $this->getAccountsWithPartnerId($entityId);
        $accountByPartner = [];
        foreach ($accounts as $acc) {
            $accountByPartner[(int)$acc['partner_id']] = (int)$acc['id'];
        }

        $openingSources = $this->collectPartnerCapitalSources($entityId, null, date('Y-m-d', strtotime($startDate . ' -1 day')), true);
        $periodSources = $this->collectPartnerCapitalSources($entityId, $startDate, $endDate, false);
        $openChecks = $this->getOpenPartnerChecksAsOf($entityId, $endDate);
        $openByPartner = [];
        foreach ($openChecks as $oc) {
            $pid = (int)$oc['partnerId'];
            if (!isset($openByPartner[$pid])) {
                $openByPartner[$pid] = [];
            }
            $openByPartner[$pid][] = $oc;
        }

        $details = [];
        $sumOpening = 0;
        $sumDeposits = 0;
        $sumWithdrawals = 0;

        foreach ($partners as $p) {
            $partnerId = (int)$p['id'];
            $accountId = $accountByPartner[$partnerId] ?? null;
            $opening = $this->summarizePartnerCapitalSources($openingSources, $partnerId, $accountId);
            $period = $this->summarizePartnerCapitalSources($periodSources, $partnerId, $accountId);
            $profitShare = (float)($p['netProfitShare'] ?? 0);
            $closing = $opening['net'] + $profitShare + $period['deposits']['total'] - $period['withdrawals']['total'];

            $partnerOpenChecks = $openByPartner[$partnerId] ?? [];
            $openCheckTotal = 0;
            foreach ($partnerOpenChecks as $oc) {
                $openCheckTotal += (float)$oc['amount'];
            }

            $details[$partnerId] = [
                'accountId' => $accountId,
                'opening' => $opening['net'],
                'openingDetail' => $opening,
                'deposits' => $period['deposits'],
                'withdrawals' => $period['withdrawals'],
                'profitShare' => $profitShare,
                'closing' => $closing,
                'openChecks' => $partnerOpenChecks,
                'openChecksTotal' => $openCheckTotal,
                'periodPendingCheckDeposits' => (float)($period['deposits']['pendingChecks'] ?? 0),
                'periodPendingCheckWithdrawals' => (float)($period['withdrawals']['pendingChecks'] ?? 0),
            ];
            $sumOpening += $opening['net'];
            $sumDeposits += $period['deposits']['total'];
            $sumWithdrawals += $period['withdrawals']['total'];
        }

        return [
            'byPartner' => $details,
            'capitalSummary' => [
                'openingCapital' => $sumOpening,
                'periodDeposits' => $sumDeposits,
                'periodWithdrawals' => $sumWithdrawals,
            ],
        ];
    }

    /**
     * @param string|null $fromDate inclusive; null = no lower bound
     * @param string $toDate inclusive
     * @param bool $includeClosing include CLOSING:* rows (for opening claim only)
     */
    private function collectPartnerCapitalSources($entityId, $fromDate, $toDate, $includeClosing) {
        return [
            'partner_transactions' => $this->getPartnerTransactionsRange($entityId, $fromDate, $toDate, $includeClosing),
            'expenses' => $this->getExpensesFromPartnerAccountsRange($entityId, $fromDate, $toDate),
            'payments' => $this->getPaymentsFromPartnerAccountsRange($entityId, $fromDate, $toDate),
            'transfers' => $this->getTransfersFromPartnerAccountsRange($entityId, $fromDate, $toDate),
            'non_invoice' => $this->getNonInvoicePersonPaymentsFromPartnerAccountsRange($entityId, $fromDate, $toDate),
            'cashed_checks' => $this->getCashedChecksOnPartnerAccountsRange($entityId, $fromDate, $toDate),
        ];
    }

    private function summarizePartnerCapitalSources(array $sources, $partnerId, $accountId) {
        $directDepRows = [];
        $directWdRows = [];
        $checkDepRows = [];      // واریز چک از شریک / endorse receipt (rare)
        $checkWdRows = [];       // برداشت با چک / واگذاری چک به شریک
        $pendingCheckDepRows = [];
        $pendingCheckWdRows = [];
        foreach ($sources['partner_transactions'] as $t) {
            if ((int)$t['partnerId'] !== (int)$partnerId) {
                continue;
            }
            $payType = $t['payType'] ?? 'cash';
            $isCheckLike = in_array($payType, ['check', 'endorse_check'], true);
            $isPending = !empty($t['isPendingCheck']);
            if ($isCheckLike) {
                $label = $t['description'] ?? '';
                if ($payType === 'endorse_check' && $label !== '' && strpos($label, '(خرج چک)') !== 0) {
                    $t['description'] = '(خرج چک / واگذاری) ' . $label;
                } elseif ($payType === 'check' && $label !== '' && strpos($label, '(چک)') !== 0) {
                    $pendingTag = $isPending ? ' — منتظر اقدام' : '';
                    $t['description'] = '(چک)' . $pendingTag . ' ' . $label;
                }
                if (!empty($t['checkDueDate'])) {
                    $t['dueDate'] = $t['checkDueDate'];
                }
                if ($t['type'] === 'receipt') {
                    $checkDepRows[] = $t;
                    if ($isPending) {
                        $pendingCheckDepRows[] = $t;
                    }
                } elseif ($t['type'] === 'payment') {
                    $checkWdRows[] = $t;
                    if ($isPending) {
                        $pendingCheckWdRows[] = $t;
                    }
                }
                continue;
            }
            if ($t['type'] === 'receipt') {
                $directDepRows[] = $t;
            } elseif ($t['type'] === 'payment') {
                $directWdRows[] = $t;
            }
        }

        $expenseRows = [];
        $purchasePayRows = [];
        $salesPayRows = [];
        $returnPayCreditRows = [];
        $returnPayDebitRows = [];
        $transferOutRows = [];
        $transferInRows = [];
        $interPartnerInRows = [];
        $interPartnerOutRows = [];
        $nipOutRows = [];
        $nipInRows = [];
        $cashedReceivedRows = [];
        $cashedPayableRows = [];

        if ($accountId) {
            foreach ($sources['expenses'] as $e) {
                if ((int)$e['account_id'] === (int)$accountId) {
                    $expenseRows[] = $e;
                }
            }
            foreach ($sources['payments'] as $pay) {
                if ((int)$pay['account_id'] !== (int)$accountId) {
                    continue;
                }
                $invType = $pay['invoiceType'] ?? '';
                if ($invType === 'purchase') {
                    $purchasePayRows[] = $pay;
                } elseif ($invType === 'sales') {
                    $salesPayRows[] = $pay;
                } elseif ($invType === 'return') {
                    if (($pay['transaction_type'] ?? '') === 'receipt') {
                        $returnPayCreditRows[] = $pay;
                    } else {
                        $returnPayDebitRows[] = $pay;
                    }
                }
            }
            foreach ($sources['transfers'] as $t) {
                if ((int)$t['account_id'] !== (int)$accountId) {
                    continue;
                }
                // شرکت واسطه است: ورود از شریک دیگر = افزایش طلب؛ خروج به شریک دیگر = کاهش طلب
                if (!empty($t['is_inter_partner'])) {
                    if ($t['transaction_type'] === 'receipt') {
                        $interPartnerInRows[] = $t;
                    } elseif ($t['transaction_type'] === 'payment') {
                        $interPartnerOutRows[] = $t;
                    }
                    continue;
                }
                if ($t['transaction_type'] === 'payment') {
                    $transferOutRows[] = $t;
                } elseif ($t['transaction_type'] === 'receipt') {
                    $transferInRows[] = $t;
                }
            }
            foreach ($sources['non_invoice'] as $nip) {
                if ((int)$nip['account_id'] !== (int)$accountId) {
                    continue;
                }
                if ($nip['transaction_type'] === 'payment') {
                    $nipOutRows[] = $nip;
                } elseif ($nip['transaction_type'] === 'receipt') {
                    $nipInRows[] = $nip;
                }
            }
            foreach (($sources['cashed_checks'] ?? []) as $ch) {
                if ((int)$ch['account_id'] !== (int)$accountId) {
                    continue;
                }
                if (($ch['check_type'] ?? '') === 'received') {
                    $cashedReceivedRows[] = $ch;
                } else {
                    $cashedPayableRows[] = $ch;
                }
            }
        }

        $sum = function ($rows) {
            $t = 0;
            foreach ($rows as $r) {
                $t += (float)$r['amount'];
            }
            return $t;
        };

        $deposits = [
            'direct' => $sum($directDepRows),
            'checks' => $sum($checkDepRows),
            'pendingChecks' => $sum($pendingCheckDepRows),
            'expenses' => $sum($expenseRows),
            'purchasePayments' => $sum($purchasePayRows),
            'returnReceipts' => $sum($returnPayCreditRows),
            'transfersToCompany' => $sum($transferOutRows),
            'interPartnerIn' => $sum($interPartnerInRows),
            'personPayments' => $sum($nipOutRows),
            'cashedPayables' => $sum($cashedPayableRows),
            'rows' => [
                'direct' => $directDepRows,
                'checks' => $checkDepRows,
                'pendingChecks' => $pendingCheckDepRows,
                'expenses' => $expenseRows,
                'purchasePayments' => $purchasePayRows,
                'returnReceipts' => $returnPayCreditRows,
                'transfersToCompany' => $transferOutRows,
                'interPartnerIn' => $interPartnerInRows,
                'personPayments' => $nipOutRows,
                'cashedPayables' => $cashedPayableRows,
            ],
        ];
        $deposits['total'] = $deposits['direct'] + $deposits['checks'] + $deposits['expenses'] + $deposits['purchasePayments']
            + $deposits['returnReceipts'] + $deposits['transfersToCompany'] + $deposits['interPartnerIn']
            + $deposits['personPayments'] + $deposits['cashedPayables'];

        $withdrawals = [
            'direct' => $sum($directWdRows),
            'checks' => $sum($checkWdRows),
            'pendingChecks' => $sum($pendingCheckWdRows),
            'salesReceipts' => $sum($salesPayRows),
            'returnPayments' => $sum($returnPayDebitRows),
            'transfersFromCompany' => $sum($transferInRows),
            'interPartnerOut' => $sum($interPartnerOutRows),
            'personReceipts' => $sum($nipInRows),
            'cashedReceived' => $sum($cashedReceivedRows),
            'rows' => [
                'direct' => $directWdRows,
                'checks' => $checkWdRows,
                'pendingChecks' => $pendingCheckWdRows,
                'salesReceipts' => $salesPayRows,
                'returnPayments' => $returnPayDebitRows,
                'transfersFromCompany' => $transferInRows,
                'interPartnerOut' => $interPartnerOutRows,
                'personReceipts' => $nipInRows,
                'cashedReceived' => $cashedReceivedRows,
            ],
        ];
        $withdrawals['total'] = $withdrawals['direct'] + $withdrawals['checks'] + $withdrawals['salesReceipts']
            + $withdrawals['returnPayments'] + $withdrawals['transfersFromCompany']
            + $withdrawals['interPartnerOut'] + $withdrawals['personReceipts']
            + $withdrawals['cashedReceived'];

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'net' => $deposits['total'] - $withdrawals['total'],
        ];
    }

    /**
     * Checks cashed into/from a partner personal account without a parallel payment
     * row on that same account (same rule as صورتحساب اشخاص).
     */
    private function getCashedChecksOnPartnerAccountsRange($entityId, $fromDate, $toDate) {
        $sql = "
            SELECT c.id,
                   c.cashed_in_account_id AS account_id,
                   c.amount,
                   c.type AS check_type,
                   COALESCE(c.cashed_date, c.dueDate) AS date,
                   c.checkNumber,
                   c.bankName,
                   CONCAT(
                       CASE WHEN c.type = 'received' THEN 'وصول چک شماره: ' ELSE 'پاس شدن چک شماره: ' END,
                       COALESCE(c.checkNumber, ''),
                       CASE WHEN c.bankName IS NOT NULL AND c.bankName != '' THEN CONCAT(' - ', c.bankName) ELSE '' END
                   ) AS description
            FROM checks c
            JOIN accounts a ON a.id = c.cashed_in_account_id AND a.entity_id = c.entity_id
            LEFT JOIN payments p
              ON p.checkId = c.id
             AND p.account_id = c.cashed_in_account_id
             AND p.entity_id = c.entity_id
             AND p.type = 'check'
            WHERE c.entity_id = ?
              AND a.partner_id IS NOT NULL
              AND c.status = 'cashed'
              AND c.cashed_in_account_id IS NOT NULL
              AND p.id IS NULL
        ";
        $types = 'i';
        $params = [$entityId];
        if ($fromDate) {
            $sql .= " AND COALESCE(c.cashed_date, c.dueDate) >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND COALESCE(c.cashed_date, c.dueDate) <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY COALESCE(c.cashed_date, c.dueDate) ASC, c.id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getPartners($entityId, $asOfDate = null) {
        if ($asOfDate) {
            require_once __DIR__ . '/PeriodClosing.php';
            $closingModel = new PeriodClosing($this->conn);
            return $closingModel->getPartnersAtDate($entityId, $asOfDate);
        }
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
    private function getPartnerTransactionsRange($entityId, $fromDate, $toDate, $includeClosing = false) {
        $sql = "
            SELECT p.id,
                   p.person_id as partnerId,
                   p.transaction_type as type,
                   p.amount,
                   p.description,
                   p.date,
                   p.account_id,
                   p.type as payType,
                   p.checkId,
                   ch.status as checkStatus,
                   ch.checkNumber,
                   ch.bankName as checkBankName,
                   ch.dueDate as checkDueDate,
                   ch.type as checkKind,
                   CASE
                     WHEN p.type = 'endorse_check'
                          AND ch.id IS NOT NULL
                          AND ch.status = 'endorsed'
                     THEN 1
                     WHEN p.type = 'check'
                          AND ch.id IS NOT NULL
                          AND ch.status IN ('in_hand', 'payable')
                     THEN 1
                     ELSE 0
                   END as isPendingCheck
            FROM payments p
            LEFT JOIN checks ch ON ch.id = p.checkId
            WHERE p.entity_id = ?
              AND p.person_type = 'partner'
              AND p.invoiceId IS NULL
        ";
        $types = 'i';
        $params = [$entityId];
        if (!$includeClosing) {
            $sql .= " AND (p.description IS NULL OR p.description NOT LIKE 'CLOSING:%')";
        }
        if ($fromDate) {
            $sql .= " AND p.date >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND p.date <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY p.date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    /**
     * Open (not cashed/bounced) checks linked to partners as of a date —
     * endorsed-to-partner holdings + in-hand/payable checks from/to partners.
     */
    private function getOpenPartnerChecksAsOf($entityId, $asOfDate) {
        $rows = [];

        // Received checks endorsed TO a partner and still endorsed (واگذار شده به شریک).
        $sqlEndorse = "
            SELECT c.id as checkId,
                   p.person_id as partnerId,
                   c.amount,
                   c.checkNumber,
                   c.bankName,
                   c.dueDate,
                   c.status as checkStatus,
                   'endorsed_to_partner' as pendingKind,
                   'payment' as direction,
                   p.date as eventDate,
                   p.description,
                   CONCAT('چک واگذارشده به شریک شماره: ', COALESCE(c.checkNumber, ''),
                          CASE WHEN c.bankName IS NOT NULL AND c.bankName != '' THEN CONCAT(' - ', c.bankName) ELSE '' END) as label
            FROM checks c
            INNER JOIN payments p
              ON p.checkId = c.id
             AND p.entity_id = c.entity_id
             AND p.type = 'endorse_check'
             AND p.person_type = 'partner'
            WHERE c.entity_id = ?
              AND c.status = 'endorsed'
              AND p.date <= ?
              AND NOT EXISTS (
                    SELECT 1 FROM payments p2
                    WHERE p2.checkId = c.id AND p2.entity_id = c.entity_id
                      AND p2.type = 'endorse_check' AND p2.person_type = 'partner'
                      AND (p2.date > p.date OR (p2.date = p.date AND p2.id > p.id))
              )
        ";
        $stmt = $this->conn->prepare($sqlEndorse);
        $stmt->bind_param('is', $entityId, $asOfDate);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $rows[] = $row;
        }

        // Checks from/to partner still in company hand or payable (not yet cashed/endorsed away/bounced).
        $sqlOpen = "
            SELECT c.id as checkId,
                   p.person_id as partnerId,
                   p.amount,
                   c.checkNumber,
                   c.bankName,
                   c.dueDate,
                   c.status as checkStatus,
                   CASE
                     WHEN p.transaction_type = 'receipt' THEN 'from_partner_in_hand'
                     ELSE 'to_partner_payable'
                   END as pendingKind,
                   p.transaction_type as direction,
                   p.date as eventDate,
                   p.description,
                   CONCAT(
                     CASE WHEN p.transaction_type = 'receipt' THEN 'چک دریافتی از شریک شماره: ' ELSE 'چک پرداختی به شریک شماره: ' END,
                     COALESCE(c.checkNumber, ''),
                     CASE WHEN c.bankName IS NOT NULL AND c.bankName != '' THEN CONCAT(' - ', c.bankName) ELSE '' END,
                     ' (', CASE c.status WHEN 'in_hand' THEN 'نزد ما' WHEN 'payable' THEN 'پرداختنی' ELSE c.status END, ')'
                   ) as label
            FROM payments p
            JOIN checks c ON c.id = p.checkId AND c.entity_id = p.entity_id
            WHERE p.entity_id = ?
              AND p.person_type = 'partner'
              AND p.type = 'check'
              AND p.invoiceId IS NULL
              AND p.date <= ?
              AND c.status IN ('in_hand', 'payable')
        ";
        $stmt2 = $this->conn->prepare($sqlOpen);
        $stmt2->bind_param('is', $entityId, $asOfDate);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function getExpensesFromPartnerAccountsRange($entityId, $fromDate, $toDate) {
        $sql = "
            SELECT e.id, e.account_id, e.amount, e.category, e.description, e.date
            FROM expenses e
            JOIN accounts a ON e.account_id = a.id
            WHERE a.entity_id = ? AND e.entity_id = ? AND a.partner_id IS NOT NULL
        ";
        $types = 'ii';
        $params = [$entityId, $entityId];
        if ($fromDate) {
            $sql .= " AND e.date >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND e.date <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY e.date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getPaymentsFromPartnerAccountsRange($entityId, $fromDate, $toDate) {
        $sql = "
            SELECT p.id, p.account_id, p.amount, p.invoiceType, p.invoiceId, p.description, p.date, p.type as payMethod,
                   COALESCE(c.name, s.name) as personName
            FROM payments p
            JOIN accounts a ON p.account_id = a.id
            LEFT JOIN sales_invoices si ON p.invoiceType = 'sales' AND p.invoiceId = si.id
            LEFT JOIN customers c ON si.customerId = c.id
            LEFT JOIN purchase_invoices pui ON p.invoiceType = 'purchase' AND p.invoiceId = pui.id
            LEFT JOIN suppliers s ON pui.supplierId = s.id
            WHERE a.entity_id = ? AND p.entity_id = ? AND a.partner_id IS NOT NULL AND p.invoiceType IS NOT NULL
        ";
        $types = 'ii';
        $params = [$entityId, $entityId];
        if ($fromDate) {
            $sql .= " AND p.date >= ?";
            $types .= 's';
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND p.date <= ?";
            $types .= 's';
            $params[] = $toDate;
        }
        $sql .= " ORDER BY p.date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    // *** START: New methods for supplier debts and customer receivables ***
    
    /**
     * Get all supplier debts. Pending payable checks already reduce the debt via their
     * origin payment, so they are reported separately for display only.
     */
    public function getSupplierDebts($endDate, $entityId) {
        // Get all suppliers with their purchase totals and payments
        $sql = "
            SELECT 
                s.id,
                s.name,
                s.initial_balance,
                COALESCE((
                    SELECT SUM(pi.totalAmount - pi.discount + COALESCE(pi.tax_amount, 0))
                    FROM purchase_invoices pi 
                    WHERE pi.supplierId = s.id AND pi.entity_id = ? AND pi.date <= ? AND pi.is_consignment = 0
                ), 0) as totalPurchases,
                COALESCE((
                    SELECT SUM(IF(p.transaction_type = 'payment', p.amount, -p.amount))
                    FROM payments p
                    INNER JOIN purchase_invoices pi ON p.invoiceId = pi.id AND p.invoiceType = 'purchase'
                    WHERE pi.supplierId = s.id AND pi.entity_id = ? AND pi.date <= ? AND pi.is_consignment = 0
                      AND p.entity_id = ? AND p.date <= ?
                ), 0) as totalPaid,
                COALESCE((
                    SELECT SUM((ri.totalAmount - ri.discount) - COALESCE(ri.paidAmount, 0))
                    FROM return_invoices ri 
                    WHERE ri.person_id = s.id AND ri.entity_id = ? AND ri.date <= ? AND ri.type = 'purchase_return'
                ), 0) as totalReturns,
                COALESCE((
                    SELECT SUM(p.amount)
                    FROM payments p 
                    WHERE p.person_id = s.id AND p.person_type = 'supplier' AND p.entity_id = ? 
                      AND p.date <= ? AND p.invoiceId IS NULL AND p.transaction_type = 'payment'
                ), 0) as creditPayments,
                COALESCE((
                    SELECT SUM(p.amount)
                    FROM payments p 
                    WHERE p.person_id = s.id AND p.person_type = 'supplier' AND p.entity_id = ? 
                      AND p.date <= ? AND p.invoiceId IS NULL AND p.transaction_type = 'receipt'
                ), 0) as debitPayments,
                COALESCE((
                    SELECT SUM(c.amount)
                    FROM checks c
                    JOIN payments p ON c.id = p.checkId
                    WHERE p.person_id = s.id AND p.person_type = 'supplier' AND c.entity_id = ?
                      AND c.status = 'payable' AND c.dueDate > ?
                ), 0) as pendingPayableChecks,
                COALESCE((
                    SELECT SUM(c.amount)
                    FROM checks c
                    JOIN payments p ON c.id = p.checkId
                    WHERE p.person_id = s.id AND p.person_type = 'supplier' AND c.entity_id = ?
                      AND c.status = 'payable' AND c.dueDate <= ?
                ), 0) as duePayableChecks
            FROM suppliers s
            WHERE s.entity_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isisisisisisisisi",
            $entityId, $endDate,
            $entityId, $endDate, $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId
        );
        $stmt->execute();
        $suppliers = db_stmt_to_assoc_array($stmt);
        
        $result = [
            'suppliers' => [],
            'totalDebt' => 0,
            'totalDebtWithPendingChecks' => 0,
            'totalPendingChecks' => 0,
            'totalDueChecks' => 0
        ];
        
        require_once __DIR__ . '/Asset.php';
        $assetNet = Asset::personNet($this->conn, (int)$entityId, 'supplier', array_column($suppliers, 'id'), $endDate);
        foreach ($suppliers as $sup) {
            // Debt = InitialBalance + Purchases - Returns - Paid - CreditPayments + DebitPayments − asset net
            $debt = floatval($sup['initial_balance'])
                  + floatval($sup['totalPurchases'])
                  - floatval($sup['totalReturns'])
                  - floatval($sup['totalPaid'])
                  - floatval($sup['creditPayments'])
                  + floatval($sup['debitPayments'])
                  - floatval($assetNet[(int)$sup['id']] ?? 0);
            
            $pendingChecks = floatval($sup['pendingPayableChecks']);
            $dueChecks = floatval($sup['duePayableChecks']);
            
            // Only include suppliers with non-zero balance
            if (abs($debt) > 0.01 || $pendingChecks > 0.01 || $dueChecks > 0.01) {
                $result['suppliers'][] = [
                    'id' => $sup['id'],
                    'name' => $sup['name'],
                    'debt' => $debt,
                    'pendingChecks' => $pendingChecks,
                    'dueChecks' => $dueChecks,
                    'totalWithPendingChecks' => $debt
                ];
                
                $result['totalDebt'] += $debt;
                $result['totalPendingChecks'] += $pendingChecks;
                $result['totalDueChecks'] += $dueChecks;
            }
        }
        
        $result['totalDebtWithPendingChecks'] = $result['totalDebt'];
        
        // Sort by total debt descending
        usort($result['suppliers'], function($a, $b) {
            return $b['totalWithPendingChecks'] <=> $a['totalWithPendingChecks'];
        });
        
        return $result;
    }
    
    /**
     * Get all customer receivables. Pending received checks already reduce the receivable
     * via their origin payment, so they are reported separately for display only.
     */
    public function getCustomerReceivables($endDate, $entityId) {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.initial_balance,
                COALESCE((
                    SELECT SUM(si.totalAmount - si.discount + COALESCE(si.tax_amount, 0))
                    FROM sales_invoices si 
                    WHERE si.customerId = c.id AND si.entity_id = ? AND si.date <= ? AND si.is_consignment = 0
                ), 0) as totalSales,
                COALESCE((
                    SELECT SUM(IF(p.transaction_type = 'receipt', p.amount, -p.amount))
                    FROM payments p
                    INNER JOIN sales_invoices si ON p.invoiceId = si.id AND p.invoiceType = 'sales'
                    WHERE si.customerId = c.id AND si.entity_id = ? AND si.date <= ? AND si.is_consignment = 0
                      AND p.entity_id = ? AND p.date <= ?
                ), 0) as totalPaid,
                COALESCE((
                    SELECT SUM((ri.totalAmount - ri.discount) - COALESCE(ri.paidAmount, 0))
                    FROM return_invoices ri 
                    WHERE ri.person_id = c.id AND ri.entity_id = ? AND ri.date <= ? AND ri.type = 'sales_return'
                ), 0) as totalReturns,
                COALESCE((
                    SELECT SUM(p.amount)
                    FROM payments p 
                    WHERE p.person_id = c.id AND p.person_type = 'customer' AND p.entity_id = ? 
                      AND p.date <= ? AND p.invoiceId IS NULL AND p.transaction_type = 'receipt'
                ), 0) as creditPayments,
                COALESCE((
                    SELECT SUM(p.amount)
                    FROM payments p 
                    WHERE p.person_id = c.id AND p.person_type = 'customer' AND p.entity_id = ? 
                      AND p.date <= ? AND p.invoiceId IS NULL AND p.transaction_type = 'payment'
                ), 0) as debitPayments,
                COALESCE((
                    SELECT SUM(ch.amount)
                    FROM checks ch
                    JOIN payments p ON ch.id = p.checkId
                    WHERE p.person_id = c.id AND p.person_type = 'customer' AND ch.entity_id = ?
                      AND ch.status = 'in_hand' AND ch.dueDate > ?
                ), 0) as pendingReceivedChecks,
                COALESCE((
                    SELECT SUM(ch.amount)
                    FROM checks ch
                    JOIN payments p ON ch.id = p.checkId
                    WHERE p.person_id = c.id AND p.person_type = 'customer' AND ch.entity_id = ?
                      AND ch.status = 'in_hand' AND ch.dueDate <= ?
                ), 0) as dueReceivedChecks
            FROM customers c
            WHERE c.entity_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isisisisisisisisi",
            $entityId, $endDate,
            $entityId, $endDate, $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId, $endDate,
            $entityId
        );
        $stmt->execute();
        $customers = db_stmt_to_assoc_array($stmt);
        
        $result = [
            'customers' => [],
            'totalReceivable' => 0,
            'totalReceivableWithPendingChecks' => 0,
            'totalPendingChecks' => 0,
            'totalDueChecks' => 0
        ];
        
        require_once __DIR__ . '/Asset.php';
        $assetNet = Asset::personNet($this->conn, (int)$entityId, 'customer', array_column($customers, 'id'), $endDate);
        foreach ($customers as $cust) {
            // Receivable = InitialBalance + Sales - Returns - Paid - CreditPayments + DebitPayments + asset net
            $receivable = floatval($cust['initial_balance'])
                        + floatval($cust['totalSales'])
                        - floatval($cust['totalReturns'])
                        - floatval($cust['totalPaid'])
                        - floatval($cust['creditPayments'])
                        + floatval($cust['debitPayments'])
                        + floatval($assetNet[(int)$cust['id']] ?? 0);
            
            $pendingChecks = floatval($cust['pendingReceivedChecks']);
            $dueChecks = floatval($cust['dueReceivedChecks']);
            
            // Only include customers with non-zero balance
            if (abs($receivable) > 0.01 || $pendingChecks > 0.01 || $dueChecks > 0.01) {
                $result['customers'][] = [
                    'id' => $cust['id'],
                    'name' => $cust['name'],
                    'receivable' => $receivable,
                    'pendingChecks' => $pendingChecks,
                    'dueChecks' => $dueChecks,
                    // Check payments already reduced $receivable via totalPaid/creditPayments;
                    // do not subtract pendingChecks again.
                    'totalWithPendingChecks' => $receivable,
                ];
                
                $result['totalReceivable'] += $receivable;
                $result['totalPendingChecks'] += $pendingChecks;
                $result['totalDueChecks'] += $dueChecks;
            }
        }
        
        $result['totalReceivableWithPendingChecks'] = $result['totalReceivable'];
        
        // Sort by total receivable descending
        usort($result['customers'], function($a, $b) {
            return $b['receivable'] <=> $a['receivable'];
        });
        
        return $result;
    }

    /**
     * فاکتورهای فروش قدیمی (قبل از $overdueDate) که واقعاً تسویه نشده‌اند.
     * مبنا مانده صورتحساب مشتری است (getCustomerReceivables)، نه فقط paidAmount خود فاکتور:
     * اگر مانده مشتری صفر یا بستانکار باشد هیچ فاکتوری از او باز نیست، حتی اگر پرداخت روی فاکتور ثبت نشده باشد.
     * مانده مثبت از جدیدترین فاکتور به عقب پخش می‌شود (FIFO: پرداخت آزاد/برگشتی اول فاکتورهای قدیمی را تسویه می‌کند).
     *
     * @return array<int, array{id:int, customerId:int, date:string, remaining:float}>
     */
    public function getOverdueUnsettledSalesInvoices($entityId, $overdueDate) {
        $balances = [];
        foreach (($this->getCustomerReceivables(date('Y-m-d'), $entityId)['customers'] ?? []) as $row) {
            $balances[(int)$row['id']] = floatval($row['receivable']);
        }

        $stmt = $this->conn->prepare("
            SELECT id, customerId, date,
                   (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) as remaining
            FROM sales_invoices
            WHERE entity_id = ?
              AND (is_consignment = 0 OR is_consignment IS NULL)
              AND (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) > " . MINIMUM_AMOUNT . "
            ORDER BY date DESC, id DESC
        ");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();

        $result = [];
        foreach (db_stmt_to_assoc_array($stmt) as $inv) {
            $cid = (int)$inv['customerId'];
            $left = $balances[$cid] ?? 0.0;
            if ($left <= MINIMUM_AMOUNT) continue;
            $applied = min($left, floatval($inv['remaining']));
            $balances[$cid] = $left - $applied;
            if ($inv['date'] < $overdueDate) {
                $result[] = [
                    'id' => (int)$inv['id'],
                    'customerId' => $cid,
                    'date' => $inv['date'],
                    'remaining' => $applied,
                ];
            }
        }
        return $result;
    }

    /**
     * مالیات بر ارزش افزوده انباشته (تا تاریخ پایان) برای ترازنامه.
     * مطالبات مشتری و بدهی تأمین‌کننده شامل tax_amount هستند اما سود/زیان نه؛
     * این مابه‌التفاوت باید به‌عنوان بدهی/طلب مالیات در ترازنامه ظاهر شود تا معادله تراز بماند.
     */
    public function getVatSummary($endDate, $entityId) {
        $sql = "
            SELECT
                COALESCE((SELECT SUM(tax_amount) FROM sales_invoices
                    WHERE entity_id = ? AND date <= ? AND is_consignment = 0), 0) as salesTax,
                COALESCE((SELECT SUM(tax_amount) FROM purchase_invoices
                    WHERE entity_id = ? AND date <= ? AND is_consignment = 0), 0) as purchaseTax
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isis", $entityId, $endDate, $entityId, $endDate);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? [];
        $salesTax = (float)($row['salesTax'] ?? 0);
        $purchaseTax = (float)($row['purchaseTax'] ?? 0);
        return [
            'salesTax' => $salesTax,
            'purchaseTax' => $purchaseTax,
            'netPayable' => $salesTax - $purchaseTax,
        ];
    }
    // *** END: New methods for supplier debts and customer receivables ***

    private function getCashPositionForReport($entityId) {
        $stmt = $this->conn->prepare("
            SELECT id, name, current_balance 
            FROM accounts 
            WHERE entity_id = ? AND partner_id IS NULL AND include_in_cash_position = 1
            ORDER BY current_balance DESC
        ");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $accounts = db_stmt_to_assoc_array($stmt);
        $total = 0;
        foreach ($accounts as &$a) {
            $a['current_balance'] = floatval($a['current_balance']);
            $total += $a['current_balance'];
        }
        return [
            'accounts' => $accounts,
            'total' => $total,
        ];
    }

    public function getDeliveryReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);
        $deliveryStatus = $data['deliveryStatus'] ?? ''; // optional filter

        $result = ['sales' => [], 'purchase' => [], 'salesSummary' => [], 'purchaseSummary' => []];

        foreach (['sales', 'purchase'] as $type) {
            $isSales = ($type === 'sales');
            $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
            $personTable = $isSales ? 'customers' : 'suppliers';
            $personColumn = $isSales ? 'customerId' : 'supplierId';
            $personNameCol = $isSales ? 'customerName' : 'supplierName';

            // Summary by delivery status
            $summarySQL = "SELECT delivery_status, COUNT(*) as count, 
                COALESCE(SUM(totalAmount), 0) as totalAmount,
                COALESCE(SUM(discount), 0) as totalDiscount,
                COALESCE(SUM(paidAmount), 0) as totalPaid,
                COALESCE(SUM(totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount), 0) as totalRemaining
                FROM `{$invoiceTable}` 
                WHERE entity_id = ? AND date BETWEEN ? AND ? AND is_consignment = 0
                GROUP BY delivery_status";
            $stmt = $this->conn->prepare($summarySQL);
            $stmt->bind_param("iss", $entityId, $startDate, $endDate);
            $stmt->execute();
            $summaryRows = db_stmt_to_assoc_array($stmt);

            $summaryByStatus = [];
            foreach ($summaryRows as $row) {
                $summaryByStatus[$row['delivery_status']] = $row;
            }
            $result[$type . 'Summary'] = $summaryByStatus;

            // Invoices list
            $sql = "SELECT inv.*, p.name as {$personNameCol} 
                FROM `{$invoiceTable}` inv 
                LEFT JOIN `{$personTable}` p ON inv.{$personColumn} = p.id 
                WHERE inv.entity_id = ? AND inv.date BETWEEN ? AND ? AND inv.is_consignment = 0";
            $params = [$entityId, $startDate, $endDate];
            $types = "iss";

            if ($deliveryStatus && in_array($deliveryStatus, ['preparing', 'ready', 'delivered'])) {
                $sql .= " AND inv.delivery_status = ?";
                $params[] = $deliveryStatus;
                $types .= "s";
            }

            $sql .= " ORDER BY inv.delivery_status ASC, inv.date DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $invoices = db_stmt_to_assoc_array($stmt);

            foreach ($invoices as &$inv) {
                // Include tax so per-row remaining reconciles with the summary
                // query, which computes totalAmount - discount + tax - paid.
                $inv['remainingAmount'] = $inv['totalAmount'] - $inv['discount']
                    + (float)($inv['tax_amount'] ?? 0) - $inv['paidAmount'];
            }

            $result[$type] = $invoices;
        }

        return $result;
    }

    // ... (Other report functions: getInvoicesReport, getInventoryLedgerReport, etc. remain unchanged) ...
    public function getInvoicesReport($data) {
        $type = $data['type'];
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);
        
        $isSales = ($type === 'sales');
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $personTable = $isSales ? 'customers' : 'suppliers';
        $personColumn = $isSales ? 'customerId' : 'supplierId';
        $personNameColumn = $isSales ? 'customerName' : 'supplierName';

        $summary_stmt = $this->conn->prepare("\n            SELECT \n                 COUNT(*) as count,\n                COALESCE(SUM(totalAmount), 0) as totalAmount,\n                COALESCE(SUM(discount), 0) as totalDiscount,\n                COALESCE(SUM(paidAmount), 0) as totalPaid,\n                COALESCE(SUM(totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount), 0) as totalRemaining\n            FROM `{$invoiceTable}` WHERE entity_id = ? AND date BETWEEN ? AND ? AND is_consignment = 0\n        ");
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
                    $invoice['remainingAmount'] = $invoice['totalAmount'] - $invoice['discount']
                        + (float)($invoice['tax_amount'] ?? 0) - $invoice['paidAmount'];
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
        $items = db_stmt_to_assoc_array($stmt);
        return array_map('normalize_invoice_item_display', $items);
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
        $entityId = report_entity_id($data);

        $os_purchase_stmt = $this->conn->prepare("SELECT COALESCE(SUM(pii.quantity), 0) as total FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pi.date < ?");
        $os_purchase_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_purchase_stmt->execute();
        $purchases_before = db_stmt_to_assoc_array($os_purchase_stmt)[0]['total'] ?? 0;
        
        $os_sales_stmt = $this->conn->prepare("SELECT COALESCE(SUM(sii.quantity), 0) as total FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE si.entity_id = ? AND sii.productId = ? AND si.date < ?");
        $os_sales_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_sales_stmt->execute();
        $sales_before = db_stmt_to_assoc_array($os_sales_stmt)[0]['total'] ?? 0;

        // Get return invoices before start date
        $os_sales_return_stmt = $this->conn->prepare("SELECT COALESCE(SUM(rii.quantity), 0) as total FROM return_invoice_items rii JOIN return_invoices ri ON rii.return_invoice_id = ri.id WHERE ri.entity_id = ? AND rii.productId = ? AND ri.type = 'sales_return' AND ri.date < ?");
        $os_sales_return_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_sales_return_stmt->execute();
        $sales_return_before = db_stmt_to_assoc_array($os_sales_return_stmt)[0]['total'] ?? 0;

        $os_purchase_return_stmt = $this->conn->prepare("SELECT COALESCE(SUM(rii.quantity), 0) as total FROM return_invoice_items rii JOIN return_invoices ri ON rii.return_invoice_id = ri.id WHERE ri.entity_id = ? AND rii.productId = ? AND ri.type = 'purchase_return' AND ri.date < ?");
        $os_purchase_return_stmt->bind_param("iis", $entityId, $productId, $startDate);
        $os_purchase_return_stmt->execute();
        $purchase_return_before = db_stmt_to_assoc_array($os_purchase_return_stmt)[0]['total'] ?? 0;

        $openingStock = $purchases_before - $sales_before + $sales_return_before - $purchase_return_before;

        $purchase_tx_stmt = $this->conn->prepare("SELECT pi.date, 'purchase' as type, pi.id as refId, pii.id as invoiceItemId, pii.quantity, pii.dimensions FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pii.invoiceId = pi.id WHERE pi.entity_id = ? AND pii.productId = ? AND pi.date BETWEEN ? AND ?");
        $purchase_tx_stmt->bind_param("iiss", $entityId, $productId, $startDate, $endDate);
        $purchase_tx_stmt->execute();
        $purchase_tx = db_stmt_to_assoc_array($purchase_tx_stmt);

        // Get inventory serial labels for purchase transactions
        foreach ($purchase_tx as &$tx) {
            $inv_stmt = $this->conn->prepare("SELECT id, serial_id FROM inventory_items WHERE purchase_invoice_item_id = ?");
            $inv_stmt->bind_param("i", $tx['invoiceItemId']);
            $inv_stmt->execute();
            $invItems = db_stmt_to_assoc_array($inv_stmt);
            $tx['inventoryIds'] = $this->inventorySerialLabels($invItems);
        }
        unset($tx);

        $sales_tx_stmt = $this->conn->prepare("SELECT si.date, 'sales' as type, si.id as refId, sii.id as invoiceItemId, sii.quantity, sii.dimensions FROM sales_invoice_items sii JOIN sales_invoices si ON sii.invoiceId = si.id WHERE si.entity_id = ? AND sii.productId = ? AND si.date BETWEEN ? AND ?");
        $sales_tx_stmt->bind_param("iiss", $entityId, $productId, $startDate, $endDate);
        $sales_tx_stmt->execute();
        $sales_tx = db_stmt_to_assoc_array($sales_tx_stmt);

        // Get inventory serial labels for sales transactions
        foreach ($sales_tx as &$tx) {
            $inv_stmt = $this->conn->prepare("SELECT id, serial_id FROM inventory_items WHERE sales_invoice_item_id = ?");
            $inv_stmt->bind_param("i", $tx['invoiceItemId']);
            $inv_stmt->execute();
            $invItems = db_stmt_to_assoc_array($inv_stmt);
            $tx['inventoryIds'] = $this->inventorySerialLabels($invItems);
        }
        unset($tx);

        // Get return invoice transactions
        $return_tx_stmt = $this->conn->prepare("SELECT ri.date, ri.type, ri.id as refId, rii.id as returnItemId, rii.original_invoice_item_id as originalItemId, rii.quantity, rii.dimensions FROM return_invoice_items rii JOIN return_invoices ri ON rii.return_invoice_id = ri.id WHERE ri.entity_id = ? AND rii.productId = ? AND ri.date BETWEEN ? AND ?");
        $return_tx_stmt->bind_param("iiss", $entityId, $productId, $startDate, $endDate);
        $return_tx_stmt->execute();
        $return_tx = db_stmt_to_assoc_array($return_tx_stmt);

        // Get inventory item IDs for return transactions
        foreach ($return_tx as &$tx) {
            $originalItemId = intval($tx['originalItemId'] ?? 0);
            $invItems = [];

            if ($originalItemId > 0) {
                $columnName = ($tx['type'] === 'sales_return') ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
                $inv_stmt = $this->conn->prepare("SELECT id, serial_id FROM inventory_items WHERE {$columnName} = ?");
                $inv_stmt->bind_param("i", $originalItemId);
                $inv_stmt->execute();
                $invItems = db_stmt_to_assoc_array($inv_stmt);
            }

            $tx['inventoryIds'] = $this->inventorySerialLabels($invItems);
        }
        unset($tx);

        $transactions = array_merge($purchase_tx, $sales_tx, $return_tx);
        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });

        return ['openingStock' => $openingStock, 'transactions' => $transactions];
    }

    public function getPersonStatement($data) {
        $personType = $data['personType'];
        $personId = intval($data['personId']);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);
        
        // New options for enhanced statement
        $showInvoiceDetails = $this->parseBoolParam($data, 'showInvoiceDetails', false);
        $includePendingChecks = isset($data['includePendingChecks']) ? $data['includePendingChecks'] : true;
        $showPreviousBalance = $this->parseBoolParam($data, 'showPreviousBalance', true);
        $includeUndeliveredOrders = $this->parseBoolParam($data, 'includeUndeliveredOrders', false);
        $calculateConsignment = $this->parseBoolParam($data, 'calculateConsignment', false);
        $omitOpeningRow = !empty($data['_omitOpeningRow']);
        
        $personTableMap = ['customer' => 'customers', 'supplier' => 'suppliers', 'partner' => 'partners', 'contact' => 'contacts'];
        $personTable = $personTableMap[$personType] ?? null;
        if (!$personTable) {
            return ['error' => 'نوع شخص نامعتبر است.', 'statusCode' => 400];
        }
        
        $person_stmt = $this->conn->prepare("SELECT * FROM `{$personTable}` WHERE entity_id = ? AND id = ?");
        $person_stmt->bind_param("ii", $entityId, $personId);
        $person_stmt->execute();
        $person = db_stmt_to_assoc_array($person_stmt)[0] ?? null;
    
        if (!$person) {
            return ['error' => 'شخص یافت نشد.', 'statusCode' => 404];
        }

        if (($personType === 'customer' || $personType === 'supplier') && $this->parseBoolParam($data, 'combineLinked', false)) {
            $combined = $this->getCombinedLinkedStatement($data, $person, (int)$entityId);
            if ($combined !== null) {
                return $combined;
            }
        }

        $transactions = [];
        $undeliveredOrderPurchaseIds = [];
        $excludedPurchaseIds = [];
        if ($personType === 'supplier') {
            $undeliveredOrderPurchaseIds = $this->getUndeliveredOrderPurchaseIds(intval($entityId), $personId);
            if (!$includeUndeliveredOrders) {
                $excludedPurchaseIds = array_flip($undeliveredOrderPurchaseIds);
            }
        }
        $undeliveredOrderPurchaseLookup = array_flip($undeliveredOrderPurchaseIds);
        
        // Helper function to get invoice items
        $getInvoiceItems = function($invoiceId, $isCustomer) use ($entityId) {
            $itemsTable = $isCustomer ? 'sales_invoice_items' : 'purchase_invoice_items';
            $stmt = $this->conn->prepare("
                SELECT i.*, p.name as productName 
                FROM `{$itemsTable}` i
                LEFT JOIN products p ON i.productId = p.id
                WHERE i.invoiceId = ?
            ");
            $stmt->bind_param("i", $invoiceId);
            $stmt->execute();
            return array_map('normalize_invoice_item_display', db_stmt_to_assoc_array($stmt));
        };
        
        // Helper function to get return invoice items
        $getReturnInvoiceItems = function($returnId) {
            $stmt = $this->conn->prepare("
                SELECT i.*, p.name as productName 
                FROM return_invoice_items i
                LEFT JOIN products p ON i.productId = p.id
                WHERE i.return_invoice_id = ?
            ");
            $stmt->bind_param("i", $returnId);
            $stmt->execute();
            return db_stmt_to_assoc_array($stmt);
        };
        
        // Period opening (مانده از قبل) is computed after collecting in-range rows.
        // Do not inject stored initial_balance as a standalone row here.
        
        if ($personType === 'customer' || $personType === 'supplier') {
            $isCustomer = ($personType === 'customer');
            $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
            $personColumn = $isCustomer ? 'customerId' : 'supplierId';
            
            $invoice_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount + COALESCE(tax_amount, 0)) as amount, is_consignment FROM `{$invoiceTable}` WHERE `entity_id` = ? AND `{$personColumn}` = ? AND date BETWEEN ? AND ?");
            $invoice_stmt->bind_param("iiss", $entityId, $personId, $startDate, $endDate);
            $invoice_stmt->execute();
            foreach (db_stmt_to_assoc_array($invoice_stmt) as $inv) {
                $invId = intval($inv['id']);
                if (!$isCustomer && isset($excludedPurchaseIds[$invId])) {
                    continue;
                }
                $description = ($inv['is_consignment'] ? '(امانی) ' : '') . "فاکتور " . ($isCustomer ? "فروش" : "خرید") . " #" . $inv['id'];
                $isUndeliveredOrder = !$isCustomer && isset($undeliveredOrderPurchaseLookup[$invId]);
                if ($isUndeliveredOrder) {
                    $description .= ' (سفارش تحویل‌نشده — انبار سفارش)';
                }
                $tx = [ 
                    'date' => $inv['date'], 
                    'description' => $description, 
                    'debit' => $isCustomer ? floatval($inv['amount']) : 0, 
                    'credit' => !$isCustomer ? floatval($inv['amount']) : 0, 
                    'unix_timestamp' => strtotime($inv['date']),
                    'is_consignment' => $inv['is_consignment'],
                    'refType' => $isCustomer ? 'salesInvoice' : 'purchaseInvoice',
                    'refId' => $inv['id'],
                    'isUndeliveredOrder' => $isUndeliveredOrder ? 1 : 0,
                ];
                
                // Add invoice items if requested
                if ($showInvoiceDetails) {
                    $linkCol = $isCustomer ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
                    $tx['items'] = $this->enrichStatementItemsWithSerialIds(
                        $getInvoiceItems($inv['id'], $isCustomer),
                        $linkCol,
                        'id'
                    );
                }
                
                $transactions[] = $tx;
            }
            
            // Add return invoices
            $returnType = $isCustomer ? 'sales_return' : 'purchase_return';
            $return_stmt = $this->conn->prepare("SELECT id, date, (totalAmount - discount) as amount FROM return_invoices WHERE entity_id = ? AND person_id = ? AND type = ? AND date BETWEEN ? AND ?");
            $return_stmt->bind_param("iisss", $entityId, $personId, $returnType, $startDate, $endDate);
            $return_stmt->execute();
            foreach (db_stmt_to_assoc_array($return_stmt) as $ret) {
                $description = "فاکتور مرجوعی " . ($isCustomer ? "فروش" : "خرید") . " #" . $ret['id'];
                $tx = [ 
                    'date' => $ret['date'], 
                    'description' => $description, 
                    'debit' => $isCustomer ? 0 : floatval($ret['amount']),
                    'credit' => $isCustomer ? floatval($ret['amount']) : 0,
                    'unix_timestamp' => strtotime($ret['date']),
                    'is_consignment' => 0,
                    'refType' => $isCustomer ? 'salesReturn' : 'purchaseReturn',
                    'refId' => $ret['id']
                ];
                
                // Add return invoice items if requested
                if ($showInvoiceDetails) {
                    $linkCol = $isCustomer ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
                    $tx['items'] = $this->enrichStatementItemsWithSerialIds(
                        $getReturnInvoiceItems($ret['id']),
                        $linkCol,
                        'original_invoice_item_id'
                    );
                }
                
                $transactions[] = $tx;
            }
        }
        
        $partnerAccountId = null;
        if ($personType === 'partner') {
            $stmt_acc_id = $this->conn->prepare("SELECT id FROM accounts WHERE entity_id = ? AND partner_id = ? LIMIT 1");
            $stmt_acc_id->bind_param("ii", $entityId, $personId);
            $stmt_acc_id->execute();
            $acc_res = db_stmt_to_assoc_array($stmt_acc_id);
            $partnerAccountId = $acc_res[0]['id'] ?? null;
        }

        $sql_payments = "
            SELECT p.*, ch.status as check_status, COALESCE(ch.cashed_date, ch.dueDate) as check_dueDate, ch.dueDate as check_maturity_date, ch.checkNumber, ch.bankName as check_bankName, ch.issuerName as check_issuerName, ch.id as checkId
            FROM payments p
            LEFT JOIN checks ch ON p.checkId = ch.id
            WHERE p.entity_id = ? AND p.date BETWEEN ? AND ?
              AND (
                  (p.person_type = ? AND p.person_id = ?)
        ";
        $payment_params = [$entityId, $startDate, $endDate, $personType, $personId];
        $payment_param_types = 'isssi';

        if ($partnerAccountId) {
             $sql_payments .= " OR (p.person_id IS NULL AND IFNULL(p.person_type,'') <> 'other' AND p.account_id = ?)"; // Include transfers via account_id
             $payment_params[] = $partnerAccountId;
             $payment_param_types .= 'i';
        }
        $sql_payments .= "\n              )\n        ";

        $payment_stmt = $this->conn->prepare($sql_payments);
        $payment_stmt->bind_param($payment_param_types, ...$payment_params);
        $payment_stmt->execute();
        $payment_rows = db_stmt_to_assoc_array($payment_stmt);

        // Ids already listed below — the partner-account query further down must
        // not add them a second time.
        $processed_payment_ids = [];

        foreach ($payment_rows as $pay) {
            $processed_payment_ids[$pay['id']] = true;
            // Keep ledger consistent when undelivered order-warehouse purchases are excluded
            if (!empty($excludedPurchaseIds)
                && !empty($pay['invoiceId'])
                && (($pay['invoiceType'] ?? '') === 'purchase')
                && isset($excludedPurchaseIds[intval($pay['invoiceId'])])) {
                continue;
            }
            $debit = 0; $credit = 0;
            $isUnrealized = false;
            $tx_date = $pay['date'];
            $desc = $pay['description'] ?: "تراکنش علی الحساب";
            $refId = $pay['id'];
            $refType = 'payment';
            
            $isTransfer = ($partnerAccountId && $pay['person_id'] === null && $pay['account_id'] == $partnerAccountId);

            if ($pay['type'] === 'cash' || $pay['type'] === 'endorse_check' || $pay['type'] === 'setoff') {
                if ($pay['invoiceId']) { 
                    if ($pay['invoiceType'] === 'return') {
                        $desc = ($pay['transaction_type'] === 'receipt' 
                            ? "دریافت از فاکتور مرجوعی خرید #" 
                            : "پرداخت بابت فاکتور مرجوعی فروش #") . $pay['invoiceId'];
                    } else {
                        $desc = "پرداخت مربوط به فاکتور #" . $pay['invoiceId'];
                    }
                }
                if ($pay['type'] === 'endorse_check') { $desc = '(خرج چک) ' . $desc; }
                if ($pay['type'] === 'setoff') {
                    $desc = '(تهاتر / پرداخت به جای) ' . ($pay['description'] ?: $desc);
                }

                if ($personType === 'partner') {
                    if ($isTransfer) {
                        // Personal account tracks CLAIM on the company (طلب از شرکت).
                        // Partner↔partner: money out reduces claim (debit), money in increases (credit).
                        // Company↔partner: opposite of bank wallet — receipt from company = برداشت (debit),
                        // payment to company = واریز (credit).
                        $baseDesc = "انتقال وجه";
                        $customDesc = $pay['description'];
                        if (strpos((string)$customDesc, 'TRANSFER:') === 0) {
                            $parts = explode('|', $customDesc, 2);
                            $customDesc = $parts[1] ?? '';
                        }
                        if ($customDesc === '' || strpos($customDesc, 'انتقال وجه از حساب') === 0 || strpos($customDesc, 'انتقال وجه به حساب') === 0) {
                           $desc = $baseDesc; 
                        } else {
                            $desc = $baseDesc . ($customDesc ? ' - ' . $customDesc : '');
                        }

                        $isInterPartner = false;
                        if (preg_match('/^TRANSFER:(\d+):(\d+):/', (string)$pay['description'], $m)) {
                            $otherId = ((int)$pay['account_id'] === (int)$m[1]) ? (int)$m[2] : (int)$m[1];
                            $chk = $this->conn->prepare("SELECT partner_id FROM accounts WHERE id = ? AND entity_id = ? LIMIT 1");
                            $chk->bind_param("ii", $otherId, $entityId);
                            $chk->execute();
                            $otherAcc = db_stmt_to_assoc_array($chk)[0] ?? null;
                            $isInterPartner = !empty($otherAcc['partner_id']);
                        } else {
                            // Legacy unmarked pairs: treat as inter-partner when an opposite
                            // leg exists on another partner account (same date/amount).
                            $isInterPartner = true; // historical partner-debt rows are P2P
                        }

                        if ($isInterPartner) {
                            if ($pay['transaction_type'] === 'payment') { $debit = floatval($pay['amount']); }
                            else { $credit = floatval($pay['amount']); }
                        } else {
                            // company ↔ partner
                            if ($pay['transaction_type'] === 'payment') { $credit = floatval($pay['amount']); }
                            else { $debit = floatval($pay['amount']); }
                        }
                    } else {
                        // Capital claim: واریز / پرداخت برای شرکت → بستانکار (+);
                        // برداشت / گرفتن از شرکت → بدهکار (−).
                        $desc = ($pay['transaction_type'] === 'receipt' ? 'واریز به شرکت' : 'برداشت از شرکت') . ($pay['description'] ? ' - ' . $pay['description'] : '');
                        if ($pay['transaction_type'] === 'receipt') { $credit = floatval($pay['amount']); } 
                        else { $debit = floatval($pay['amount']); }
                    }
                } else {
                    if ($pay['transaction_type'] === 'receipt') { $credit = floatval($pay['amount']); } 
                    else { $debit = floatval($pay['amount']); }
                }
            } 
            elseif ($pay['type'] === 'check') {
                $isCashed = $pay['check_status'] === 'cashed';
                $isPending = !$isCashed && $pay['check_status'] !== 'bounced' && $pay['check_status'] !== 'endorsed';
                $tx_date = $isCashed ? $pay['check_dueDate'] : $pay['date'];
                $desc = "(چک) " . ($pay['description'] ?: "تراکنش علی الحساب");
                if ($isPending) { $desc .= " (منتظر اقدام)"; $isUnrealized = true; }
                
                if ($personType === 'partner') {
                    // Capital claim: payment to partner (برداشت) → debit (−);
                    // receipt from partner (واریز چک به شرکت) → credit (+).
                    if ($pay['transaction_type'] === 'payment') {
                        $debit = floatval($pay['amount']);
                    } else {
                        $credit = floatval($pay['amount']);
                    }
                } else { 
                    if ($pay['transaction_type'] === 'receipt') {
                        if ($isCashed) $credit = floatval($pay['amount']); else $credit = floatval($pay['amount']);
                    } else {
                         if ($isCashed) $debit = floatval($pay['amount']); else $debit = floatval($pay['amount']);
                    }
                }
                $refId = $pay['checkId'];
                $refType = 'check';
            }
            elseif ($pay['type'] === 'check_bounce') {
                // Reversal of a check settlement (bounced / returned check): the
                // original row stays, this row cancels it in the opposite direction.
                $desc = '(برگشت چک' . (!empty($pay['checkNumber']) ? ' #' . $pay['checkNumber'] : '') . ') ' . ($pay['description'] ?: '');
                if ($personType === 'partner') {
                    if ($pay['transaction_type'] === 'payment') { $debit = floatval($pay['amount']); }
                    else { $credit = floatval($pay['amount']); }
                } else {
                    if ($pay['transaction_type'] === 'receipt') { $credit = floatval($pay['amount']); }
                    else { $debit = floatval($pay['amount']); }
                }
                $refId = $pay['checkId'] ?: $pay['id'];
                $refType = $pay['checkId'] ? 'check' : 'payment';
            }

            // Build transaction with check details
            $tx = [ 
                'date' => $tx_date, 
                'description' => $desc, 
                'debit' => $debit, 
                'credit' => $credit, 
                'unix_timestamp' => strtotime($tx_date), 
                'isUnrealized' => $isUnrealized, 
                'refId' => $refId, 
                'refType' => $refType, 
                'is_consignment' => 0
            ];
            
            // Add check details for check payments
            if ($pay['type'] === 'check' && $pay['checkId']) {
                $tx['isPendingCheck'] = !($pay['check_status'] === 'cashed' || $pay['check_status'] === 'bounced' || $pay['check_status'] === 'endorsed');
                $tx['checkDetails'] = [
                    'checkNumber' => $pay['checkNumber'],
                    'bankName' => $pay['check_bankName'] ?? '',
                    'issuerName' => $pay['check_issuerName'] ?? '',
                    'dueDate' => $pay['check_maturity_date'] ?? $pay['check_dueDate'],
                    'cashedDate' => ($pay['check_status'] === 'cashed') ? $pay['check_dueDate'] : null,
                    'status' => $pay['check_status']
                ];
            }
            
            $transactions[] = $tx;
        }

                    if ($personType === 'partner' && $partnerAccountId) {
                        $stmt_cashed_checks = $this->conn->prepare("
                SELECT c.id, c.type, c.amount, COALESCE(c.cashed_date, c.dueDate) as dueDate, c.checkNumber, c.bankName, c.issuerName
                FROM checks c
                LEFT JOIN payments p
                  ON p.checkId = c.id
                 AND p.account_id = ?
                 AND p.entity_id = ?
                 AND p.type = 'check'
                WHERE c.entity_id = ?
                  AND c.cashed_in_account_id = ?
                  AND c.status = 'cashed'
                  AND COALESCE(c.cashed_date, c.dueDate) BETWEEN ? AND ?
                  AND p.id IS NULL
                ORDER BY COALESCE(c.cashed_date, c.dueDate) ASC, c.id ASC
            ");
                        $stmt_cashed_checks->bind_param("iiiiss", $partnerAccountId, $entityId, $entityId, $partnerAccountId, $startDate, $endDate);
                        $stmt_cashed_checks->execute();

                        foreach (db_stmt_to_assoc_array($stmt_cashed_checks) as $check) {
                            $isReceived = ($check['type'] === 'received');
                            $transactions[] = [
                                'date' => $check['dueDate'],
                                'description' => ($isReceived ? 'وصول چک شماره: ' : 'پاس شدن چک شماره: ') . $check['checkNumber'] . ($check['bankName'] ? ' - ' . $check['bankName'] : ''),
                                // Capital claim on personal account: receiving cash/check into
                                // personal = برداشت (−/debit); paying a payable from personal
                                // = واریز (+/credit). Matches Check.php partner-account updates.
                                'debit' => $isReceived ? floatval($check['amount']) : 0,
                                'credit' => $isReceived ? 0 : floatval($check['amount']),
                                'unix_timestamp' => strtotime($check['dueDate']),
                                'isUnrealized' => 0,
                                'refId' => $check['id'],
                                'refType' => 'check',
                                'is_consignment' => 0
                            ];
                        }
                    }

        if ($personType === 'partner' && $partnerAccountId) {
            $stmt_inv_pays = $this->conn->prepare("SELECT * FROM payments WHERE account_id = ? AND invoiceId IS NOT NULL AND date BETWEEN ? AND ?");
            $stmt_inv_pays->bind_param("iss", $partnerAccountId, $startDate, $endDate);
            $stmt_inv_pays->execute();
            foreach(db_stmt_to_assoc_array($stmt_inv_pays) as $pay) {
                if (isset($processed_payment_ids[$pay['id']])) continue; // already in statement
                if ($pay['invoiceType'] === 'return') {
                    // فاکتور مرجوعی
                    // transaction_type = 'receipt' → مرجوعی خرید (دریافت از تامین‌کننده)
                    // transaction_type = 'payment' → مرجوعی فروش (پرداخت به مشتری)
                    $desc = ($pay['transaction_type'] === 'receipt' 
                        ? "دریافت وجه از فاکتور مرجوعی خرید #" 
                        : "پرداخت وجه بابت فاکتور مرجوعی فروش #") . $pay['invoiceId'];
                } else {
                    $desc = ($pay['invoiceType'] === 'sales' ? "دریافت وجه بابت فاکتور فروش #" : "پرداخت وجه بابت فاکتور خرید #") . $pay['invoiceId'];
                }
                $debit = 0; $credit = 0;
                // Capital claim: sales cash into personal = برداشت (−);
                // purchase paid from personal = واریز/طلب (+).
                if ($pay['invoiceType'] === 'sales' || $pay['transaction_type'] === 'receipt') { 
                    $debit = floatval($pay['amount']); 
                } else { 
                    $credit = floatval($pay['amount']); 
                }
                $refType = ($pay['invoiceType'] === 'return' 
                    ? ($pay['transaction_type'] === 'receipt' ? 'purchaseReturn' : 'salesReturn')
                    : ($pay['invoiceType'] === 'sales' ? 'salesInvoice' : 'purchaseInvoice'));
                $transactions[] = [ 'date' => $pay['date'], 'description' => $desc, 'debit' => $debit, 'credit' => $credit, 'unix_timestamp' => strtotime($pay['date']), 'refId' => $pay['invoiceId'], 'refType' => $refType, 'is_consignment' => 0 ];
            }

            $stmt_expenses = $this->conn->prepare("SELECT * FROM expenses WHERE account_id = ? AND date BETWEEN ? AND ?");
            $stmt_expenses->bind_param("iss", $partnerAccountId, $startDate, $endDate);
            $stmt_expenses->execute();
            foreach(db_stmt_to_assoc_array($stmt_expenses) as $exp) {
                $desc = "هزینه از حساب شخصی: " . $exp['category'] . ($exp['description'] ? ' (' . $exp['description'] . ')' : '');
                $transactions[] = [ 'date' => $exp['date'], 'description' => $desc, 'debit' => 0, 'credit' => floatval($exp['amount']), 'unix_timestamp' => strtotime($exp['date']), 'refId' => $exp['id'], 'refType' => 'expense', 'is_consignment' => 0 ];
            }

            $stmt_direct_person_pays = $this->conn->prepare("
                SELECT p.*, ch.status as check_status, COALESCE(ch.cashed_date, ch.dueDate) as check_dueDate, ch.checkNumber,
                       s.name as supplierName, c.name as customerName
                FROM payments p
                LEFT JOIN checks ch ON p.checkId = ch.id
                LEFT JOIN suppliers s ON (p.person_type = 'supplier' AND p.person_id = s.id)
                LEFT JOIN customers c ON (p.person_type = 'customer' AND p.person_id = c.id)
                WHERE p.account_id = ? AND p.invoiceId IS NULL AND p.person_id IS NOT NULL AND p.date BETWEEN ? AND ?
            ");
            $stmt_direct_person_pays->bind_param("iss", $partnerAccountId, $startDate, $endDate);
            $stmt_direct_person_pays->execute();
            foreach (db_stmt_to_assoc_array($stmt_direct_person_pays) as $pay) {
                $debit = 0; $credit = 0; $isUnrealized = false;
                $label = ($pay['person_type'] === 'supplier') ? 'تأمین‌کننده' : 'مشتری';
                $personName = $pay['supplierName'] ?: ($pay['customerName'] ?: '');
                $baseDesc = ($pay['transaction_type'] === 'payment')
                    ? "پرداخت از حساب شخصی برای " . $label
                    : "دریافت به حساب شخصی از " . $label;
                $customDesc = $pay['description'] ? ' (' . $pay['description'] . ')' : '';

                if ($pay['type'] === 'check') {
                    $isCashed = ($pay['check_status'] === 'cashed');
                    $tx_date = $isCashed ? $pay['check_dueDate'] : $pay['date'];
                    $desc = "(چک) " . $baseDesc . ($personName ? " - {$personName}" : '') . $customDesc;
                    if (!$isCashed) { $isUnrealized = true; }
                    // Capital: paying others from personal = contribution (+/credit);
                    // receiving into personal = drawing (−/debit).
                    if ($pay['transaction_type'] === 'payment') { $credit = $isCashed ? floatval($pay['amount']) : 0; }
                    else { $debit = $isCashed ? floatval($pay['amount']) : 0; }
                    $refId = $pay['checkId']; $refType = 'check';
                } else {
                    $tx_date = $pay['date'];
                    $desc = $baseDesc . ($personName ? " - {$personName}" : '') . $customDesc;
                    if ($pay['transaction_type'] === 'payment') { $credit = floatval($pay['amount']); }
                    else { $debit = floatval($pay['amount']); }
                    $refId = $pay['id']; $refType = 'payment';
                }

                $transactions[] = [
                    'date' => $tx_date,
                    'description' => $desc,
                    'debit' => $debit,
                    'credit' => $credit,
                    'unix_timestamp' => strtotime($tx_date),
                    'isUnrealized' => $isUnrealized,
                    'refId' => $refId,
                    'refType' => $refType,
                    'is_consignment' => 0
                ];
            }
            }

        // Linked company expenses on this person's statement (optional person on expense)
        $stmt_linked_expenses = $this->conn->prepare(
            "SELECT id, date, amount, category, description
             FROM expenses
             WHERE entity_id = ? AND person_type = ? AND person_id = ? AND date BETWEEN ? AND ?"
        );
        $stmt_linked_expenses->bind_param('isiss', $entityId, $personType, $personId, $startDate, $endDate);
        $stmt_linked_expenses->execute();
        foreach (db_stmt_to_assoc_array($stmt_linked_expenses) as $exp) {
            $desc = 'هزینه: ' . $exp['category'] . ($exp['description'] ? ' - ' . $exp['description'] : '');
            // Same direction as a cash payment to the person (reduces what we owe / increases receivable)
            $transactions[] = [
                'date' => $exp['date'],
                'description' => $desc,
                'debit' => floatval($exp['amount']),
                'credit' => 0,
                'unix_timestamp' => strtotime($exp['date']),
                'refId' => $exp['id'],
                'refType' => 'expense',
                'is_consignment' => 0
            ];
        }

        // Linked company incomes on this person's statement
        $stmt_linked_incomes = $this->conn->prepare(
            "SELECT id, date, amount, category, description
             FROM incomes
             WHERE entity_id = ? AND person_type = ? AND person_id = ? AND date BETWEEN ? AND ?"
        );
        if ($stmt_linked_incomes) {
            $stmt_linked_incomes->bind_param('isiss', $entityId, $personType, $personId, $startDate, $endDate);
            $stmt_linked_incomes->execute();
            foreach (db_stmt_to_assoc_array($stmt_linked_incomes) as $inc) {
                $desc = 'درآمد: ' . $inc['category'] . ($inc['description'] ? ' - ' . $inc['description'] : '');
                $transactions[] = [
                    'date' => $inc['date'],
                    'description' => $desc,
                    'debit' => 0,
                    'credit' => floatval($inc['amount']),
                    'unix_timestamp' => strtotime($inc['date']),
                    'refId' => $inc['id'],
                    'refType' => 'income',
                    'is_consignment' => 0
                ];
            }
        }

        require_once __DIR__ . '/Asset.php';
        foreach (Asset::statementRows($this->conn, (int)$entityId, $personType, (int)$personId, $startDate, $endDate) as $ar) {
            $transactions[] = [
                'date' => $ar['date'],
                'description' => $ar['description'],
                'debit' => max(0.0, $ar['signed']),
                'credit' => max(0.0, -$ar['signed']),
                'unix_timestamp' => strtotime($ar['date']),
                'refId' => $ar['id'],
                'refType' => $ar['kind'],
                'is_consignment' => 0
            ];
        }

        usort($transactions, function($a, $b) { return $a['unix_timestamp'] - $b['unix_timestamp']; });

        $openingBalance = 0.0;
        if (!$omitOpeningRow) {
            $dayBefore = date('Y-m-d', strtotime($startDate . ' -1 day'));
            $beforeData = $data;
            $beforeData['startDate'] = '1970-01-01';
            $beforeData['endDate'] = $dayBefore;
            $beforeData['showPreviousBalance'] = false;
            $beforeData['_omitOpeningRow'] = true;
            $beforeData['showInvoiceDetails'] = false;
            $beforeResult = $this->getPersonStatement($beforeData);
            $beforeTx = (!empty($beforeResult['error'])) ? [] : ($beforeResult['transactions'] ?? []);
            $initial = in_array($personType, ['customer', 'supplier', 'contact'], true)
                ? floatval($person['initial_balance'] ?? 0)
                : 0.0;
            $openingBalance = $this->computePersonOpeningFromTransactions($beforeTx, $personType, $initial, $calculateConsignment);

            if ($showPreviousBalance) {
                array_unshift($transactions, $this->buildPreviousBalanceRow($personType, $openingBalance));
            }
        }
        
        return [
            'transactions' => $transactions,
            'person' => $person,
            'openingBalance' => $openingBalance,
            'showPreviousBalance' => $showPreviousBalance,
            'includeUndeliveredOrders' => $includeUndeliveredOrders,
            'calculateConsignment' => $calculateConsignment,
            'undeliveredOrderCount' => count($undeliveredOrderPurchaseIds),
        ];
    }
    
    /**
     * One statement for a person who is both customer and supplier: the rows of
     * both records, with the running balance in the requested side's convention.
     * Debit/credit mean the same on both sides (debit = the person owes more), so
     * the rows merge as they are; the openings have opposite signs. Returns null
     * when the person has no linked record.
     */
    private function getCombinedLinkedStatement(array $data, array $person, int $entityId): ?array {
        $personType = $data['personType'];
        if ($personType === 'customer') {
            $twinType = 'supplier';
            $twinId = (int)($person['linked_supplier_id'] ?? 0);
        } else {
            $twinType = 'customer';
            $st = $this->conn->prepare("SELECT id FROM customers WHERE entity_id = ? AND linked_supplier_id = ? LIMIT 1");
            $pid = (int)$person['id'];
            $st->bind_param('ii', $entityId, $pid);
            $st->execute();
            $twinId = (int)(db_stmt_to_assoc_array($st)[0]['id'] ?? 0);
        }
        if ($twinId <= 0) {
            return null;
        }

        $showPreviousBalance = $this->parseBoolParam($data, 'showPreviousBalance', true);
        $inner = $data;
        $inner['combineLinked'] = false;
        $inner['showPreviousBalance'] = false;
        $own = $this->getPersonStatement($inner);
        if (!empty($own['error'])) {
            return $own;
        }
        $inner['personType'] = $twinType;
        $inner['personId'] = $twinId;
        $twin = $this->getPersonStatement($inner);
        if (!empty($twin['error'])) {
            return $own;
        }

        $sideLabel = $twinType === 'customer' ? '[حساب مشتری] ' : '[حساب تأمین‌کننده] ';
        $transactions = $own['transactions'];
        foreach ($twin['transactions'] as $t) {
            $t['description'] = $sideLabel . ($t['description'] ?? '');
            $t['linkedSide'] = $twinType;
            $transactions[] = $t;
        }
        usort($transactions, function ($a, $b) { return $a['unix_timestamp'] - $b['unix_timestamp']; });

        $opening = (float)$own['openingBalance'] - (float)$twin['openingBalance'];
        if ($showPreviousBalance && empty($data['_omitOpeningRow'])) {
            array_unshift($transactions, $this->buildPreviousBalanceRow($personType, $opening));
        }

        $own['transactions'] = $transactions;
        $own['openingBalance'] = $opening;
        $own['showPreviousBalance'] = $showPreviousBalance;
        $own['undeliveredOrderCount'] = (int)$own['undeliveredOrderCount'] + (int)$twin['undeliveredOrderCount'];
        $own['combinedWith'] = ['type' => $twinType, 'id' => $twinId, 'name' => $twin['person']['name'] ?? ''];
        return $own;
    }

    public function getAccountStatement($data) {
        // Scope to the session entity: fetching by id alone would let any user
        // read another tenant's account statement by iterating ids.
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'مجموعه فعال نامعتبر است.', 'statusCode' => 403];
        }
        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ? AND entity_id = ?");
        $account_stmt->bind_param("ii", $data['accountId'], $entityId);
        $account_stmt->execute();
        $account = db_stmt_to_assoc_array($account_stmt)[0] ?? null;

        if (!$account) {
            return ['error' => 'Account not found', 'statusCode' => 404];
        }

        // Partner personal accounts use the same WALLET account statement as banks.
        // Capital claim vs company is only in getPersonStatement (صورتحساب اشخاص).

        $accountId = intval($data['accountId']);
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = $account['entity_id'];
        
        $opening_balance = floatval($account['initial_balance']);
        
        $change_before_start = 0;
        $stmt_pay_before = $this->conn->prepare("SELECT SUM(IF(transaction_type='receipt', amount, -amount)) as total FROM payments WHERE account_id = ? AND type='cash' AND date < ? AND entity_id = ?");
        $stmt_pay_before->bind_param("isi", $accountId, $startDate, $entityId);
        $stmt_pay_before->execute();
        $change_before_start += db_stmt_to_assoc_array($stmt_pay_before)[0]['total'] ?? 0;
        
        $stmt_exp_before = $this->conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE account_id = ? AND date < ? AND entity_id = ?");
        $stmt_exp_before->bind_param("isi", $accountId, $startDate, $entityId);
        $stmt_exp_before->execute();
        $change_before_start -= db_stmt_to_assoc_array($stmt_exp_before)[0]['total'] ?? 0;

        $stmt_inc_before = $this->conn->prepare("SELECT SUM(amount) as total FROM incomes WHERE account_id = ? AND date < ? AND entity_id = ?");
        if ($stmt_inc_before) {
            $stmt_inc_before->bind_param("isi", $accountId, $startDate, $entityId);
            $stmt_inc_before->execute();
            $change_before_start += db_stmt_to_assoc_array($stmt_inc_before)[0]['total'] ?? 0;
        }
        
        $stmt_ch_before = $this->conn->prepare("SELECT SUM(IF(type='received', amount, -amount)) as total FROM checks WHERE cashed_in_account_id = ? AND status='cashed' AND COALESCE(cashed_date, dueDate) < ? AND entity_id = ?");
        $stmt_ch_before->bind_param("isi", $accountId, $startDate, $entityId);
        $stmt_ch_before->execute();
        $change_before_start += db_stmt_to_assoc_array($stmt_ch_before)[0]['total'] ?? 0;

        $opening_balance += $change_before_start;

        $transactions = [];
        $queries = [
            "SELECT id, date, amount, CONCAT('واریز بابت فاکتور فروش #', invoiceId) as description, 'salesInvoice' as refType, invoiceId as refId, 'payment_in' as source, amount as credit, 0 as debit FROM payments WHERE account_id = ? AND transaction_type = 'receipt' AND type = 'cash' AND invoiceId IS NOT NULL AND date BETWEEN ? AND ? AND entity_id = ?",
            "SELECT id, date, amount, CONCAT('پرداخت بابت فاکتور خرید #', invoiceId) as description, 'purchaseInvoice' as refType, invoiceId as refId, 'payment_out' as source, 0 as credit, amount as debit FROM payments WHERE account_id = ? AND transaction_type = 'payment' AND type = 'cash' AND invoiceId IS NOT NULL AND date BETWEEN ? AND ? AND entity_id = ?",
            "SELECT id, date, amount, CONCAT('هزینه: ', category, ' (#', id, ')') as description, 'expense' as refType, id as refId, 'expense_out' as source, 0 as credit, amount as debit FROM expenses WHERE account_id = ? AND date BETWEEN ? AND ? AND entity_id = ?",
            "SELECT id, date, amount, CONCAT('درآمد: ', category, ' (#', id, ')') as description, 'income' as refType, id as refId, 'income_in' as source, amount as credit, 0 as debit FROM incomes WHERE account_id = ? AND date BETWEEN ? AND ? AND entity_id = ?",
            "SELECT p.id, p.date, p.amount, p.description, 'payment' as refType, p.id as refId, IF(p.person_type = 'partner', 'partner_in', 'payment_in') as source, p.amount as credit, 0 as debit FROM payments p WHERE p.account_id = ? AND p.transaction_type = 'receipt' AND p.type = 'cash' AND p.invoiceId IS NULL AND p.date BETWEEN ? AND ? AND p.entity_id = ?", 
            "SELECT p.id, p.date, p.amount, p.description, 'payment' as refType, p.id as refId, IF(p.person_type = 'partner', 'partner_out', 'payment_out') as source, 0 as credit, p.amount as debit FROM payments p WHERE p.account_id = ? AND p.transaction_type = 'payment' AND p.type = 'cash' AND p.invoiceId IS NULL AND p.date BETWEEN ? AND ? AND p.entity_id = ?", 
            "SELECT id, COALESCE(cashed_date, dueDate) as date, amount, CONCAT(IF(type='received', 'وصول چک شماره: ', 'پاس شدن چک شماره: '), checkNumber) as description, 'check' as refType, id as refId, IF(type='received', 'check_in', 'check_out') as source, IF(type='received', amount, 0) as credit, IF(type='payable', amount, 0) as debit FROM checks WHERE cashed_in_account_id = ? AND status = 'cashed' AND COALESCE(cashed_date, dueDate) BETWEEN ? AND ? AND entity_id = ?"
        ];

        foreach ($queries as $sql) {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("issi", $accountId, $startDate, $endDate, $entityId);
            $stmt->execute();
            $transactions = array_merge($transactions, db_stmt_to_assoc_array($stmt));
        }

        usort($transactions, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
        
        return ['account' => $account, 'transactions' => $transactions, 'openingBalance' => $opening_balance];
    }
    
    public function getExpensesReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);
        
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

    public function getIncomesReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);

        $sql = "SELECT i.date, i.category, i.amount, i.description, a.name as accountName FROM incomes i LEFT JOIN accounts a ON i.account_id = a.id WHERE i.entity_id = ? AND i.date BETWEEN ? AND ? ORDER BY i.date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $stmt->execute();
        $incomes = db_stmt_to_assoc_array($stmt);

        $total_stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM incomes WHERE entity_id = ? AND date BETWEEN ? AND ?");
        $total_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $total_stmt->execute();
        $total_array = db_stmt_to_assoc_array($total_stmt);
        $total = !empty($total_array) ? $total_array[0]['total'] : 0;

        return ['incomes' => $incomes, 'total' => $total];
    }

    public function getInventoryReport($data) {
        $entityId = report_entity_id($data);
        $serialCodeExpr = "COALESCE(NULLIF(ii.serial_id, ''), CONCAT('INV-', LPAD(ii.id, 6, '0')))";
        
        // Get inventory with more details - including consignment status (both purchase and sale consignment)
        $sql = "
            SELECT 
                p.id as productId,
                p.name, 
                ii.dimensions, 
                ii.status,
                COUNT(ii.id) as quantity,
                MIN(ii.entry_date) as firstEntryDate,
                MAX(ii.entry_date) as lastEntryDate,
                GROUP_CONCAT(DISTINCT {$serialCodeExpr} ORDER BY ii.id SEPARATOR ', ') as serialIds,
                CASE 
                    WHEN ii.status = 'consignment_out' THEN 'consignment_out'
                    WHEN pi.is_consignment = 1 THEN 'consignment_in'
                    ELSE 'own'
                END as inventoryType
            FROM products p 
            JOIN inventory_items ii ON p.id = ii.product_id 
            LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            LEFT JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            WHERE p.entity_id = ? AND ii.status IN ('in_stock', 'returned', 'consignment_out')
            GROUP BY p.id, p.name, ii.dimensions, ii.status, 
                CASE 
                    WHEN ii.status = 'consignment_out' THEN 'consignment_out'
                    WHEN pi.is_consignment = 1 THEN 'consignment_in'
                    ELSE 'own'
                END
            ORDER BY p.name, ii.dimensions, ii.status
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);
        
        // Calculate totals and statistics
        $totalQuantity = 0;
        $ownQuantity = 0;
        $consignmentOutQuantity = 0;
        $consignmentInQuantity = 0;
        $uniqueProducts = [];
        
        foreach ($items as &$item) {
            $totalQuantity += $item['quantity'];
            $uniqueProducts[$item['productId']] = true;
            
            // Set inventory type based on the computed field
            $invType = $item['inventoryType'] ?? 'own';
            $item['inventoryType'] = $invType;
            
            if ($invType === 'consignment_out') {
                $consignmentOutQuantity += $item['quantity'];
            } elseif ($invType === 'consignment_in') {
                $consignmentInQuantity += $item['quantity'];
            } else {
                $ownQuantity += $item['quantity'];
            }
        }
        unset($item);
        
        return [
            'items' => $items,
            'summary' => [
                'totalQuantity' => $totalQuantity,
                'ownQuantity' => $ownQuantity,
                'consignmentOutQuantity' => $consignmentOutQuantity,
                'consignmentInQuantity' => $consignmentInQuantity,
                'totalRows' => count($items),
                'uniqueProducts' => count($uniqueProducts)
            ]
        ];
    }
    
    public function getInventoryValueReport($data) {
        $entityId = report_entity_id($data);
        $serialCodeExpr = "COALESCE(NULLIF(ii.serial_id, ''), CONCAT('INV-', LPAD(ii.id, 6, '0')))";
        $stock_sql = "
            SELECT 
                p.id as productId, 
                p.name, 
                ii.dimensions, 
                COUNT(ii.id) as quantity,
                ii.status,
                GROUP_CONCAT(DISTINCT {$serialCodeExpr} ORDER BY ii.id SEPARATOR ', ') as serialIds,
                CASE 
                    WHEN ii.status = 'consignment_out' THEN 'consignment_out'
                    WHEN pi.is_consignment = 1 THEN 'consignment_in'
                    ELSE 'own'
                END as inventoryType
            FROM products p 
            JOIN inventory_items ii ON p.id = ii.product_id 
            LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            LEFT JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            WHERE p.entity_id = ? AND ii.status IN ('in_stock', 'returned', 'consignment_out')
            GROUP BY p.id, p.name, ii.dimensions, ii.status,
                CASE 
                    WHEN ii.status = 'consignment_out' THEN 'consignment_out'
                    WHEN pi.is_consignment = 1 THEN 'consignment_in'
                    ELSE 'own'
                END
        ";
        $stock_stmt = $this->conn->prepare($stock_sql);
        $stock_stmt->bind_param("i", $entityId);
        $stock_stmt->execute();
        
        $stockItems = db_stmt_to_assoc_array($stock_stmt);
        $totalValue = 0;
        $ownValue = 0;
        $consignmentOutValue = 0;
        $consignmentInValue = 0;
        $totalQuantity = 0;
        $ownQuantity = 0;
        $consignmentOutQuantity = 0;
        $consignmentInQuantity = 0;
        $uniqueProducts = [];
        
        $purchasePricesCache = [];

        foreach ($stockItems as &$item) {
            $cacheKey = $item['productId'] . '-' . $item['dimensions'];
            $lastPrice = 0;
            $avgPrice = 0;

            if (isset($purchasePricesCache[$cacheKey])) {
                $lastPrice = $purchasePricesCache[$cacheKey]['last'];
                $avgPrice = $purchasePricesCache[$cacheKey]['avg'];
            } else {
                // Get last purchase price
                $price_stmt = $this->conn->prepare("
                    SELECT pii.unitPrice 
                    FROM inventory_items ii 
                    JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id 
                    JOIN purchase_invoices pi ON pii.invoiceId = pi.id 
                    WHERE ii.product_id = ? AND ii.dimensions = ? AND pi.entity_id = ? 
                    ORDER BY pi.date DESC, ii.entry_date DESC 
                    LIMIT 1
                ");
                $price_stmt->bind_param("isi", $item['productId'], $item['dimensions'], $entityId);
                $price_stmt->execute();
                $price_result_arr = db_stmt_to_assoc_array($price_stmt);
                $lastPrice = $price_result_arr[0]['unitPrice'] ?? 0;
                
                // Get average purchase price
                $avg_stmt = $this->conn->prepare("
                    SELECT AVG(pii.unitPrice) as avgPrice 
                    FROM inventory_items ii 
                    JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id 
                    JOIN purchase_invoices pi ON pii.invoiceId = pi.id 
                    WHERE ii.product_id = ? AND ii.dimensions = ? AND pi.entity_id = ?
                ");
                $avg_stmt->bind_param("isi", $item['productId'], $item['dimensions'], $entityId);
                $avg_stmt->execute();
                $avg_result = db_stmt_to_assoc_array($avg_stmt);
                $avgPrice = $avg_result[0]['avgPrice'] ?? 0;
                
                $purchasePricesCache[$cacheKey] = ['last' => $lastPrice, 'avg' => $avgPrice];
            }
            
            $item['lastPrice'] = $lastPrice;
            $item['avgPrice'] = $avgPrice;
            $item['rowValue'] = $item['quantity'] * $lastPrice;
            $item['rowValueAvg'] = $item['quantity'] * $avgPrice;
            $totalValue += $item['rowValue'];
            $totalQuantity += $item['quantity'];
            $uniqueProducts[$item['productId']] = true;
            
            // Track inventory type
            $invType = $item['inventoryType'] ?? 'own';
            $item['inventoryType'] = $invType;
            
            if ($invType === 'consignment_out') {
                $consignmentOutValue += $item['rowValue'];
                $consignmentOutQuantity += $item['quantity'];
            } elseif ($invType === 'consignment_in') {
                $consignmentInValue += $item['rowValue'];
                $consignmentInQuantity += $item['quantity'];
            } else {
                $ownValue += $item['rowValue'];
                $ownQuantity += $item['quantity'];
            }
        }
        unset($item); 
        
        return [
            'items' => $stockItems, 
            'totalValue' => $totalValue,
            'summary' => [
                'totalQuantity' => $totalQuantity,
                'ownQuantity' => $ownQuantity,
                'consignmentOutQuantity' => $consignmentOutQuantity,
                'consignmentInQuantity' => $consignmentInQuantity,
                'totalRows' => count($stockItems),
                'uniqueProducts' => count($uniqueProducts),
                'totalValue' => $totalValue,
                'ownValue' => $ownValue,
                'consignmentOutValue' => $consignmentOutValue,
                'consignmentInValue' => $consignmentInValue
            ]
        ];
    }

    public function getCogsProfitReport($data) {
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $entityId = report_entity_id($data);

        $sales_stmt = $this->conn->prepare("\n            SELECT \n                sii.productId, p.name as productName, sii.dimensions, \n                sii.quantity, sii.unitPrice as salePrice, si.id as invoiceId, si.date,\n                si.totalAmount as invoiceTotalAmount, si.discount as invoiceDiscount,\n                sii.id as sales_invoice_item_id\n            FROM sales_invoice_items sii\n            JOIN sales_invoices si ON sii.invoiceId = si.id\n            JOIN products p ON sii.productId = p.id\n            WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?\n            ORDER BY si.date ASC\n        ");
        $sales_stmt->bind_param("iss", $entityId, $startDate, $endDate);
        $sales_stmt->execute();
        $soldItems = db_stmt_to_assoc_array($sales_stmt);

        if (empty($soldItems)) {
            return ['summary' => ['totalSale' => 0, 'totalCogs' => 0, 'totalProfit' => 0], 'items' => []];
        }

        $salesItemIds = array_column($soldItems, 'sales_invoice_item_id');
        $purchasePrices = [];
        $returnedByLine = [];
        if (!empty($salesItemIds)) {
            $placeholders = implode(',', array_fill(0, count($salesItemIds), '?'));
            $types = str_repeat('i', count($salesItemIds));
            $costExpr = "COALESCE(pii.unitPrice,
                        (SELECT pii2.unitPrice FROM purchase_invoice_items pii2
                         JOIN purchase_invoices pi2 ON pii2.invoiceId = pi2.id
                         WHERE pii2.productId = ii.product_id AND pii2.dimensions = ii.dimensions
                           AND pi2.entity_id = ii.entity_id
                           AND pi2.date <= si.date
                         ORDER BY pi2.date DESC, pii2.id DESC LIMIT 1),
                        ii.cost_price, 0)";
            // Items currently linked to the line, plus items sold on it that were
            // later returned and resold (now linked to another sale).
            foreach (['ii.sales_invoice_item_id', 'ii.returned_from_sales_item_id'] as $linkCol) {
                $extra = $linkCol === 'ii.returned_from_sales_item_id'
                    ? ' AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id <> ii.returned_from_sales_item_id)'
                    : '';
                $stmtCost = $this->conn->prepare("
                    SELECT {$linkCol} AS line_id, COALESCE(SUM({$costExpr}), 0) AS totalCost
                    FROM inventory_items ii
                    LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
                    LEFT JOIN sales_invoice_items sii ON sii.id = {$linkCol}
                    LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
                    WHERE {$linkCol} IN ({$placeholders}){$extra}
                    GROUP BY {$linkCol}
                ");
                $stmtCost->bind_param($types, ...$salesItemIds);
                $stmtCost->execute();
                foreach (db_stmt_to_assoc_array($stmtCost) as $row) {
                    $lid = (int)$row['line_id'];
                    $purchasePrices[$lid] = ($purchasePrices[$lid] ?? 0) + (float)$row['totalCost'];
                }
            }

            // Customer returns of these lines reduce both revenue and cost.
            $stmtRet = $this->conn->prepare("
                SELECT rii.original_invoice_item_id AS line_id,
                       COALESCE(SUM(rii.quantity), 0) AS qty,
                       COALESCE(SUM(rii.quantity * rii.unitPrice), 0) AS amount
                FROM return_invoice_items rii
                JOIN return_invoices ri ON ri.id = rii.return_invoice_id
                WHERE ri.type = 'sales_return' AND ri.entity_id = ?
                  AND rii.original_invoice_item_id IN ({$placeholders})
                GROUP BY rii.original_invoice_item_id
            ");
            $stmtRet->bind_param('i' . $types, $entityId, ...$salesItemIds);
            $stmtRet->execute();
            foreach (db_stmt_to_assoc_array($stmtRet) as $row) {
                $returnedByLine[(int)$row['line_id']] = ['qty' => (int)$row['qty'], 'amount' => (float)$row['amount']];
            }
        }

        $summary = ['totalSale' => 0, 'totalCogs' => 0, 'totalProfit' => 0, 'totalReturns' => 0];
        foreach ($soldItems as &$item) {
            $lineId = (int)$item['sales_invoice_item_id'];
            $totalCostForItem = $purchasePrices[$lineId] ?? 0;

            $itemTotalSaleGross = (float)$item['salePrice'] * (int)$item['quantity'];
            $invoiceTotalAmount = (float)$item['invoiceTotalAmount'];
            $invoiceDiscount = (float)$item['invoiceDiscount'];

            $proportionalDiscount = 0;
            if ($invoiceTotalAmount > 0 && $invoiceDiscount > 0) {
                $proportionalDiscount = ($itemTotalSaleGross / $invoiceTotalAmount) * $invoiceDiscount;
            }

            $quantity = (int)$item['quantity'];
            $ret = $returnedByLine[$lineId] ?? ['qty' => 0, 'amount' => 0.0];
            $returnedQty = min($quantity, (int)$ret['qty']);
            $netQty = max(0, $quantity - $returnedQty);
            $unitCost = ($quantity > 0) ? ($totalCostForItem / $quantity) : 0;

            $item['purchasePrice'] = $unitCost;
            $item['returnedQuantity'] = $returnedQty;
            $item['returnedAmount'] = (float)$ret['amount'];
            $item['totalSale'] = $itemTotalSaleGross - $proportionalDiscount - (float)$ret['amount'];
            $item['totalCost'] = $unitCost * $netQty;
            $item['profit'] = $item['totalSale'] - $item['totalCost'];
            $item['proportionalDiscount'] = $proportionalDiscount;

            $summary['totalSale'] += $item['totalSale'];
            $summary['totalCogs'] += $item['totalCost'];
            $summary['totalProfit'] += $item['profit'];
            $summary['totalReturns'] += (float)$ret['amount'];
        }
        unset($item);

        return ['summary' => $summary, 'items' => $soldItems];
    }
    
    /**
     * Get inventory value with optional filters (for analytics dashboard)
     * This method bridges analytics needs with formal reporting
     * 
     * @param int $entityId Entity ID
     * @param int|null $collectionId Optional collection filter
     * @param string|null $keyword Optional search keyword
     * @param bool $includeConsignment Include consignment items (default: true)
     * @return array ['total_value' => float, 'item_count' => int, 'items' => array]
     */
    public function getInventoryValueForAnalytics($entityId, $collectionId = null, $keyword = null, $includeConsignment = true) {
        // First get the total value using shared trait method
        $summary = $this->calculateInventoryValue($this->conn, $entityId, $collectionId, $keyword, $includeConsignment);
        
        // Then get detailed items list
        $sql = "SELECT ii.id AS id,
                       p.name AS product_name,
                       p.design_code AS design_code,
                       ii.dimensions AS dimensions,
                       COALESCE(pii.unitPrice, 0) AS purchase_price,
                       COALESCE(pi.is_consignment, 0) AS is_consignment
                FROM inventory_items ii
                LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                LEFT JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                JOIN products p ON p.id = ii.product_id
                WHERE ii.status IN ('in_stock', 'returned') AND p.entity_id = ?";
        
        $types = 'i';
        $params = [$entityId];
        
        if ($collectionId && intval($collectionId) > 0) {
            $sql .= " AND p.collection_id = ?";
            $types .= 'i';
            $params[] = intval($collectionId);
        }
        
        if ($keyword && strlen($keyword) > 0) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.design_code LIKE ?)";
            $kw = '%' . $keyword . '%';
            $types .= 'sss';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        
        if (!$includeConsignment) {
            $sql .= " AND (pi.is_consignment = 0 OR pi.id IS NULL)";
        }
        
        $sql .= " ORDER BY p.name, ii.id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        
        $items = [];
        foreach ($rows as $row) {
            $row['purchase_price'] = floatval($row['purchase_price']);
            $row['is_consignment'] = intval($row['is_consignment']);
            $items[] = $row;
        }
        
        return [
            'total_value' => $summary['total_value'],
            'item_count' => $summary['item_count'],
            'items' => $items
        ];
    }

    public function getReportForExport($reportType, $params) {
        $data = [];
        $filename = "report_{$reportType}_" . date('Y-m-d') . ".csv";
        $headers = [];
        $rows = [];
        
        $apiData = null;
        switch ($reportType) {
            case 'inventory': $apiData = $this->getInventoryReport($params); break;
            case 'inventory-value': $apiData = $this->getInventoryValueReport($params); break;
            case 'expenses': $apiData = $this->getExpensesReport($params); break;
            case 'incomes': $apiData = $this->getIncomesReport($params); break;
            case 'persons': $apiData = $this->getPersonStatement($params); break;
            case 'accounts': $apiData = $this->getAccountStatement($params); break;
            case 'cogs-profit': $apiData = $this->getCogsProfitReport($params); break;
            case 'checks': $apiData = $this->getChecksReport($params); break;
            case 'custom-report': $apiData = $this->getCustomReport($params); break;
        }

        if ($apiData === null) {
            return ['error' => 'Report type not supported for export.'];
        }

        switch ($reportType) {
            case 'inventory':
                $headers = ['نام طرح', 'ابعاد', 'تعداد موجود'];
                $rows = array_map(fn($item) => [$item['name'], $item['dimensions'], $item['quantity']], $apiData['items']);
                break;
            
            case 'inventory-value':
                $headers = ['نام محصول', 'ابعاد', 'موجودی', 'آخرین قیمت خرید', 'ارزش کل ردیف'];
                $rows = array_map(fn($item) => [$item['name'], $item['dimensions'], $item['quantity'], $item['lastPrice'], $item['rowValue']], $apiData['items']);
                break;

            case 'expenses':
                $headers = ['تاریخ', 'دسته‌بندی', 'مبلغ', 'توضیحات', 'حساب پرداخت'];
                $rows = array_map(fn($item) => [
                    $this->formatExportDate($item['date']),
                    $item['category'],
                    $item['amount'],
                    $item['description'],
                    $item['accountName']
                ], $apiData['expenses']);
                break;

            case 'incomes':
                $headers = ['تاریخ', 'دسته‌بندی', 'مبلغ', 'توضیحات', 'حساب دریافت'];
                $rows = array_map(fn($item) => [
                    $this->formatExportDate($item['date']),
                    $item['category'],
                    $item['amount'],
                    $item['description'],
                    $item['accountName']
                ], $apiData['incomes']);
                break;
            
            case 'persons':
                $showInvoiceDetails = $this->parseBoolParam($params, 'showInvoiceDetails', false);
                $showPreviousBalance = $this->parseBoolParam($params, 'showPreviousBalance', true);
                $calculateConsignment = $this->parseBoolParam($params, 'calculateConsignment', false);
                $personType = $params['personType'] ?? 'customer';
                if ($personType === 'customer') {
                    $debitHeader = 'بدهکار به شرکت';
                    $creditHeader = 'بستانکار از شرکت';
                } else {
                    // supplier + partner: same column roles relative to the company
                    $debitHeader = 'بدهکار به شرکت';
                    $creditHeader = 'بستانکار از شرکت';
                }
                $headers = $showInvoiceDetails
                    ? ['تاریخ', 'شرح', 'محصول', 'ابعاد', 'تعداد', 'قیمت واحد', 'شناسه اقلام', $debitHeader, $creditHeader, 'مانده']
                    : ['تاریخ', 'شرح', $debitHeader, $creditHeader, 'مانده'];
                $isCustomer = ($personType === 'customer');
                $isAccount = false;
                $openingValue = floatval($apiData['openingBalance'] ?? ($apiData['person']['initial_balance'] ?? 0));
                $balance = $showPreviousBalance ? $openingValue : 0.0;

                if ($showPreviousBalance) {
                    $initialBalanceDesc = '(مانده از قبل / اول دوره)';
                    if ($showInvoiceDetails) {
                        $rows[] = ['---', $initialBalanceDesc, '', '', '', '', '', '', '', $openingValue];
                    } else {
                        $rows[] = ['---', $initialBalanceDesc, '', '', $openingValue];
                    }
                }

                foreach ($apiData['transactions'] as $tx) {
                    $txDate = $tx['date'] ?? '';
                    if (!empty($tx['isPreviousBalance']) || $txDate === 'مانده از قبل' || $txDate === 'مانده اولیه') {
                        // Already represented by the opening summary row above
                        continue;
                    }
                    $this->appendStatementExportRows($rows, $tx, $balance, $isCustomer, $isAccount, $showInvoiceDetails, $calculateConsignment);
                }
                break;

            case 'accounts':
                $showInvoiceDetails = $this->parseBoolParam($params, 'showInvoiceDetails', false);
                $headers = $showInvoiceDetails
                    ? ['تاریخ', 'شرح', 'محصول', 'ابعاد', 'تعداد', 'قیمت واحد', 'شناسه اقلام', 'بدهکار', 'بستانکار', 'مانده']
                    : ['تاریخ', 'شرح', 'بدهکار', 'بستانکار', 'مانده'];
                $balance = $apiData['openingBalance'] ?? ($apiData['person']['initial_balance'] ?? 0);
                $isCustomer = false;
                $isAccount = true;
                $hasInitialRow = false;
                foreach ($apiData['transactions'] as $tx) {
                    if (($tx['date'] ?? '') === 'مانده اولیه') {
                        $hasInitialRow = true;
                        break;
                    }
                }
                if ($hasInitialRow) {
                    $balance = 0;
                }
                
                $initialBalanceDesc = '';
                // Account statements carry a computed openingBalance; person
                // statements fall back to the stored initial_balance.
                $openingValue = $apiData['openingBalance']
                    ?? ($isAccount ? 0 : ($apiData['person']['initial_balance'] ?? 0));
                if (isset($apiData['openingBalance'])) {
                    $initialBalanceDesc = '(مانده اول دوره)';
                } else if (($apiData['person']['initial_balance'] ?? 0) != 0) {
                     $initialBalanceDesc = '(مانده اولیه سیستم)';
                }
                if ($initialBalanceDesc) {
                    if ($showInvoiceDetails) {
                        $rows[] = ['---', $initialBalanceDesc, '', '', '', '', '', '', '', $openingValue];
                    } else {
                        $rows[] = ['---', $initialBalanceDesc, '', '', $openingValue];
                    }
                }

                foreach ($apiData['transactions'] as $tx) {
                    $this->appendStatementExportRows($rows, $tx, $balance, $isCustomer, $isAccount, $showInvoiceDetails);
                }
                break;
            
            case 'cogs-profit':
                $headers = ['تاریخ فروش', 'فاکتور', 'نام کالا', 'ابعاد', 'تعداد', 'فروش خالص (ردیف)', 'تخفیف سهمی', 'قیمت خرید (واحد)', 'سود (ردیف)'];
                $rows = array_map(fn($item) => [
                    $this->formatExportDate($item['date']),
                    $item['invoiceId'],
                    $item['productName'],
                    $item['dimensions'],
                    $item['quantity'],
                    $item['totalSale'],
                    $item['proportionalDiscount'],
                    $item['purchasePrice'],
                    $item['profit']
                ], $apiData['items']);
                break;

            case 'checks':
                $headers = ['شماره چک', 'نوع', 'وضعیت', 'مبلغ', 'بانک', 'صادرکننده', 'سررسید', 'شخص', 'نوع شخص', 'تلفن', 'فاکتور', 'نزد/وضعیت', 'تلفن فعلی', 'روز تا سررسید', 'سررسید گذشته'];
                $rows = array_map(fn($item) => [
                    $item['checkNumber'],
                    $item['type_label'],
                    $item['status_label'],
                    $item['amount'],
                    $item['bankName'],
                    $item['issuerName'],
                    $this->formatExportDate($item['dueDate']),
                    $item['person_name'],
                    $item['person_type_label'],
                    $item['person_phone'],
                    $item['invoice_label'],
                    $item['current_holder'],
                    $item['current_holder_phone'],
                    $item['days_to_due'],
                    !empty($item['is_overdue']) ? 'بله' : 'خیر',
                ], $apiData['checks'] ?? []);
                break;
            
            case 'custom-report':
                if (!empty($apiData['error'])) {
                    return ['error' => $apiData['error']];
                }
                $exportColumns = $apiData['columns'] ?? [];
                $headers = array_map(fn($col) => $this->customReportColumnLabel($col), $exportColumns);
                $rows = array_map(function ($item) use ($exportColumns) {
                    $line = [];
                    foreach ($exportColumns as $col) {
                        $line[] = $this->formatCustomExportValue($col, $item[$col] ?? '');
                    }
                    return $line;
                }, $apiData['rows'] ?? []);
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

    /**
     * گزارش سنی بدهکاران / بستانکاران
     * Aged Receivables/Payables Report
     */
    public function getAgedReceivablesReport($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $type = $data['personType'] ?? 'customer'; // customer or supplier
        $today = date('Y-m-d');

        if ($type === 'customer') {
            $table = 'sales_invoices';
            $personTable = 'customers';
            $personIdCol = 'customerId';
        } else {
            $table = 'purchase_invoices';
            $personTable = 'suppliers';
            $personIdCol = 'supplierId';
        }

        // Get all invoices with remaining balance (tax_amount included, matching all other balance formulas)
        $sql = "SELECT i.id, i.{$personIdCol} as person_id, p.name as person_name,
                    i.date, i.due_date, i.totalAmount, i.discount, i.paidAmount,
                    (i.totalAmount - i.discount + COALESCE(i.tax_amount, 0) - i.paidAmount) as remaining
                FROM `{$table}` i
                LEFT JOIN `{$personTable}` p ON i.{$personIdCol} = p.id
                WHERE i.entity_id = ?
                    AND (i.totalAmount - i.discount + COALESCE(i.tax_amount, 0) - i.paidAmount) > " . MINIMUM_AMOUNT . "
                    AND (i.is_consignment = 0 OR i.is_consignment IS NULL)
                ORDER BY p.name, i.date";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $invoices = db_stmt_to_assoc_array($stmt);

        // Aging buckets (days) — boundaries come from the ar_aging_buckets
        // setting (comma list, default "30,60,90"). With the defaults the
        // bucket keys are exactly the historical ones: 1_30, 31_60, 61_90, over_90.
        $boundaries = array_values(array_filter(array_map('intval', preg_split('/[,،]/', (string)get_app_setting($this->conn, 'ar_aging_buckets', '30,60,90'))), function($v) { return $v > 0; }));
        sort($boundaries);
        $b1 = $boundaries[0] ?? 30;
        $b2 = $boundaries[1] ?? 60;
        $b3 = $boundaries[2] ?? 90;
        $keyB1 = "1_{$b1}";
        $keyB2 = ($b1 + 1) . "_{$b2}";
        $keyB3 = ($b2 + 1) . "_{$b3}";
        $keyOver = "over_{$b3}";
        $buckets = ['current' => 0, $keyB1 => 0, $keyB2 => 0, $keyB3 => 0, $keyOver => 0];
        $personSummary = [];

        foreach ($invoices as $inv) {
            $refDate = $inv['due_date'] ?? $inv['date'];
            $daysOld = (strtotime($today) - strtotime($refDate)) / 86400;
            $remaining = floatval($inv['remaining']);
            $pid = $inv['person_id'];

            if (!isset($personSummary[$pid])) {
                $personSummary[$pid] = array_merge([
                    'person_id' => $pid,
                    'person_name' => $inv['person_name'],
                ], $buckets, [
                    'total' => 0,
                    'invoices' => []
                ]);
            }

            $bucket = 'current';
            if ($daysOld > $b3) $bucket = $keyOver;
            elseif ($daysOld > $b2) $bucket = $keyB3;
            elseif ($daysOld > $b1) $bucket = $keyB2;
            elseif ($daysOld > 0) $bucket = $keyB1;

            $personSummary[$pid][$bucket] += $remaining;
            $personSummary[$pid]['total'] += $remaining;
            $buckets[$bucket] += $remaining;
            $personSummary[$pid]['invoices'][] = [
                'id' => $inv['id'],
                'date' => $inv['date'],
                'due_date' => $inv['due_date'],
                'remaining' => $remaining,
                'daysOld' => round($daysOld),
                'bucket' => $bucket,
            ];
        }

        return [
            'type' => $type,
            'summary' => $buckets,
            'totalOutstanding' => array_sum($buckets),
            'persons' => array_values($personSummary),
        ];
    }

    /**
     * صورت جریان وجوه نقد
     * Cash Flow Statement
     */
    public function getCashFlowReport($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $startDate = $data['startDate'] ?? date('Y-m-01');
        $endDate = $data['endDate'] ?? date('Y-m-d');

        // Cash receipts from sales (cash + check cashed)
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.entity_id = ? AND p.transaction_type = 'receipt' AND p.type = 'cash' AND p.date BETWEEN ? AND ?");
        $stmt->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt->execute();
        $cashReceipts = floatval($stmt->get_result()->fetch_row()[0]);

        // Cash payments for purchases
        $stmt2 = $this->conn->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.entity_id = ? AND p.transaction_type = 'payment' AND p.type = 'cash' AND p.date BETWEEN ? AND ?");
        $stmt2->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt2->execute();
        $cashPayments = floatval($stmt2->get_result()->fetch_row()[0]);

        // Check receipts cashed
        $stmt3 = $this->conn->prepare("SELECT COALESCE(SUM(c.amount), 0) FROM checks c WHERE c.entity_id = ? AND c.type = 'received' AND c.status = 'cashed' AND COALESCE(c.cashed_date, c.dueDate) BETWEEN ? AND ?");
        $stmt3->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt3->execute();
        $checksCashed = floatval($stmt3->get_result()->fetch_row()[0]);

        // Checks paid (payable cleared)
        $stmt4 = $this->conn->prepare("SELECT COALESCE(SUM(c.amount), 0) FROM checks c WHERE c.entity_id = ? AND c.type = 'payable' AND c.status IN ('cashed') AND COALESCE(c.cashed_date, c.dueDate) BETWEEN ? AND ?");
        $stmt4->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt4->execute();
        $checksCleared = floatval($stmt4->get_result()->fetch_row()[0]);

        // Expenses
        $stmt5 = $this->conn->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE e.entity_id = ? AND e.date BETWEEN ? AND ?");
        $stmt5->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt5->execute();
        $expenses = floatval($stmt5->get_result()->fetch_row()[0]);

        // Company incomes
        $stmtInc = $this->conn->prepare("SELECT COALESCE(SUM(i.amount), 0) FROM incomes i WHERE i.entity_id = ? AND i.date BETWEEN ? AND ?");
        $stmtInc->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmtInc->execute();
        $incomes = floatval($stmtInc->get_result()->fetch_row()[0]);

        // Transfers (inter-account) — exclude miscellaneous other-party cash
        $stmt6 = $this->conn->prepare(
            "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
             WHERE p.entity_id = ? AND p.invoiceId IS NULL AND p.person_id IS NULL
               AND IFNULL(p.person_type,'') <> 'other'
               AND p.date BETWEEN ? AND ?"
        );
        $stmt6->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmt6->execute();
        $transfers = floatval($stmt6->get_result()->fetch_row()[0]);

        // Ending balances: only accounts included in company cash position
        $stmt7 = $this->conn->prepare(
            "SELECT id, name, current_balance, is_cash
             FROM accounts
             WHERE entity_id = ? AND partner_id IS NULL AND include_in_cash_position = 1"
        );
        $stmt7->bind_param("i", $entity_id);
        $stmt7->execute();
        $accounts = db_stmt_to_assoc_array($stmt7);

        $totalCashBalance = 0;
        $totalBankBalance = 0;
        foreach ($accounts as $acc) {
            if ($acc['is_cash']) $totalCashBalance += floatval($acc['current_balance']);
            else $totalBankBalance += floatval($acc['current_balance']);
        }

        $totalInflow = $cashReceipts + $checksCashed + $incomes;
        $totalOutflow = $cashPayments + $checksCleared + $expenses;
        $netCashFlow = $totalInflow - $totalOutflow;

        // Monthly breakdown
        $stmtMonthly = $this->conn->prepare("
            SELECT DATE_FORMAT(p.date, '%Y-%m') as month,
                SUM(IF(p.transaction_type='receipt', p.amount, 0)) as inflow,
                SUM(IF(p.transaction_type='payment', p.amount, 0)) as outflow
            FROM payments p 
            WHERE p.entity_id = ? AND p.type = 'cash' AND p.date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(p.date, '%Y-%m')
            ORDER BY month
        ");
        $stmtMonthly->bind_param("iss", $entity_id, $startDate, $endDate);
        $stmtMonthly->execute();
        $monthlyBreakdown = db_stmt_to_assoc_array($stmtMonthly);

        return [
            'cashReceipts' => $cashReceipts,
            'cashPayments' => $cashPayments,
            'checksCashed' => $checksCashed,
            'checksCleared' => $checksCleared,
            'expenses' => $expenses,
            'incomes' => $incomes,
            'totalInflow' => $totalInflow,
            'totalOutflow' => $totalOutflow,
            'netCashFlow' => $netCashFlow,
            'totalCashBalance' => $totalCashBalance,
            'totalBankBalance' => $totalBankBalance,
            'totalBalance' => $totalCashBalance + $totalBankBalance,
            'accounts' => $accounts,
            'monthlyBreakdown' => $monthlyBreakdown,
        ];
    }

    /**
     * گزارش سود تفکیکی شرکا
     * Partner Profit Distribution Report
     */
    public function getPartnerProfitReport($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $startDate = $data['startDate'] ?? date('Y-01-01');
        $endDate = $data['endDate'] ?? date('Y-m-d');

        // Get full P&L data (includes partners, accounts, transactions, etc.)
        $plData = $this->getProfitLossReport($data);

        $grossProfit = $plData['grossProfit'] ?? 0;
        $netProfit = $plData['netOperatingProfit'] ?? 0;
        $totalExpenses = $plData['totalCompanyExpenses'] ?? 0;

        $partnerResults = $plData['partners'] ?? [];

        return [
            'grossProfit' => $grossProfit,
            'netProfit' => $netProfit,
            'totalExpenses' => $totalExpenses,
            'partners' => $partnerResults,
            'startDate' => $startDate,
            'endDate' => $endDate,
            // Pass through detailed data for partner breakdown
            'accounts' => $plData['accounts'] ?? [],
            'partner_transactions' => $plData['partner_transactions'] ?? [],
            'expenses' => $plData['expenses'] ?? [],
            'payments' => $plData['payments'] ?? [],
            'transfers' => $plData['transfers'] ?? [],
            'non_invoice_person_payments' => $plData['non_invoice_person_payments'] ?? [],
            'capitalSummary' => $plData['capitalSummary'] ?? [],
            'metrics' => $plData['metrics'] ?? [],
            'dataQuality' => $plData['dataQuality'] ?? [],
        ];
    }

    /**
     * گزارش‌ساز سفارشی
     * Custom Report Builder — respects selected columns and optional grouping.
     */
    public function getCustomReport($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $source = $data['source'] ?? 'sales_invoices';
        $startDate = $data['startDate'] ?? null;
        $endDate = $data['endDate'] ?? null;
        $catalog = $this->customReportCatalog();

        if (!isset($catalog[$source])) {
            return ['error' => 'منبع داده نامعتبر است.'];
        }

        $meta = $catalog[$source];
        $requestedColumns = $this->parseCustomReportList($data['columns'] ?? null);
        $groupBy = $this->normalizeCustomGroupBy($data['groupBy'] ?? null, $meta['group_by']);
        $columns = $this->resolveCustomColumns($requestedColumns, $meta['columns']);

        $personJoin = "
            LEFT JOIN customers cust ON {alias}.person_type = 'customer' AND {alias}.person_id = cust.id
            LEFT JOIN suppliers supp ON {alias}.person_type = 'supplier' AND {alias}.person_id = supp.id
            LEFT JOIN partners part ON {alias}.person_type = 'partner' AND {alias}.person_id = part.id
            LEFT JOIN contacts ct ON {alias}.person_type = 'contact' AND {alias}.person_id = ct.id
        ";
        $personExpr = "CASE
            WHEN {alias}.person_type = 'other' THEN 'متفرقه'
            ELSE COALESCE(cust.name, supp.name, part.name, ct.name, '-')
        END";

        $allowed_sources = [
            'sales_invoices' => [
                'table' => 'sales_invoices si LEFT JOIN customers c ON si.customerId = c.id',
                'date_col' => 'si.date',
                'entity_col' => 'si.entity_id',
                'fields' => [
                    'si.id', 'si.date', 'si.due_date', 'si.invoice_number', 'si.delivery_status',
                    'c.name as personName', 'si.totalAmount', 'si.discount', 'si.paidAmount',
                    '(si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) as remaining',
                    'si.tax_amount', 'si.description'
                ],
                'where' => '(si.is_consignment = 0 OR si.is_consignment IS NULL)',
            ],
            'purchase_invoices' => [
                'table' => 'purchase_invoices pi LEFT JOIN suppliers s ON pi.supplierId = s.id',
                'date_col' => 'pi.date',
                'entity_col' => 'pi.entity_id',
                'fields' => [
                    'pi.id', 'pi.date', 'pi.due_date', 'pi.invoice_number', 'pi.delivery_status',
                    's.name as personName', 'pi.totalAmount', 'pi.discount', 'pi.paidAmount',
                    '(pi.totalAmount - pi.discount + COALESCE(pi.tax_amount, 0) - pi.paidAmount) as remaining',
                    'pi.tax_amount', 'pi.description'
                ],
                'where' => '(pi.is_consignment = 0 OR pi.is_consignment IS NULL)',
            ],
            'expenses' => [
                'table' => 'expenses e LEFT JOIN accounts a ON e.account_id = a.id ' . str_replace('{alias}', 'e', $personJoin),
                'date_col' => 'e.date',
                'entity_col' => 'e.entity_id',
                'fields' => [
                    'e.id', 'e.date', 'e.category', 'e.amount', 'e.description',
                    'a.name as accountName',
                    str_replace('{alias}', 'e', $personExpr) . ' as personName',
                ],
                'where' => '1=1',
            ],
            'incomes' => [
                'table' => 'incomes i LEFT JOIN accounts a ON i.account_id = a.id ' . str_replace('{alias}', 'i', $personJoin),
                'date_col' => 'i.date',
                'entity_col' => 'i.entity_id',
                'fields' => [
                    'i.id', 'i.date', 'i.category', 'i.amount', 'i.description',
                    'a.name as accountName',
                    str_replace('{alias}', 'i', $personExpr) . ' as personName',
                ],
                'where' => '1=1',
            ],
            'payments' => [
                'table' => 'payments p LEFT JOIN accounts a ON p.account_id = a.id ' . str_replace('{alias}', 'p', $personJoin),
                'date_col' => 'p.date',
                'entity_col' => 'p.entity_id',
                'fields' => [
                    'p.id', 'p.date', 'p.transaction_type', 'p.type', 'p.amount', 'p.description',
                    'a.name as accountName',
                    str_replace('{alias}', 'p', $personExpr) . ' as personName',
                    'p.person_type as personType',
                    'p.invoiceId', 'p.invoiceType',
                ],
                'where' => '1=1',
            ],
        ];

        $src = $allowed_sources[$source];
        $fields = implode(', ', $src['fields']);
        $where = "{$src['entity_col']} = ? AND {$src['where']}";
        $params = [$entity_id];
        $types = "i";

        if ($startDate) { $where .= " AND {$src['date_col']} >= ?"; $params[] = $startDate; $types .= "s"; }
        if ($endDate) { $where .= " AND {$src['date_col']} <= ?"; $params[] = $endDate; $types .= "s"; }

        $orderBy = "ORDER BY {$src['date_col']} DESC, {$src['entity_col']} DESC";
        // Prefer primary key for stable ordering when available in SELECT list
        if (strpos($fields, '.id') !== false) {
            $orderBy = "ORDER BY {$src['date_col']} DESC";
        }
        $exportLimit = defined('REPORT_EXPORT_LIMIT') ? REPORT_EXPORT_LIMIT : 1000;
        $sql = "SELECT {$fields} FROM {$src['table']} WHERE {$where} {$orderBy} LIMIT {$exportLimit}";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['error' => 'خطا در آماده‌سازی گزارش سفارشی.'];
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['error' => 'خطا در اجرای گزارش سفارشی.'];
        }
        $rows = db_stmt_to_assoc_array($stmt);

        $normalizedRows = [];
        if (in_array($source, ['sales_invoices', 'purchase_invoices'], true)) {
            foreach ($rows as $r) {
                $normalizedRows[] = [
                    'id' => $r['id'],
                    'date' => $r['date'],
                    'due_date' => $r['due_date'] ?? '',
                    'invoice_number' => ($r['invoice_number'] ?? '') !== '' ? $r['invoice_number'] : ('#' . $r['id']),
                    'delivery_status' => $r['delivery_status'] ?? '',
                    'person' => $r['personName'] ?? '-',
                    'amount' => $r['totalAmount'],
                    'discount' => $r['discount'],
                    'paid' => $r['paidAmount'],
                    'remaining' => $r['remaining'],
                    'tax' => $r['tax_amount'],
                    'description' => $r['description'] ?? '',
                ];
            }
        } elseif ($source === 'expenses' || $source === 'incomes') {
            foreach ($rows as $r) {
                $normalizedRows[] = [
                    'id' => $r['id'],
                    'date' => $r['date'],
                    'category' => $r['category'] ?? '-',
                    'amount' => $r['amount'],
                    'account' => $r['accountName'] ?? '-',
                    'person' => $r['personName'] ?? '-',
                    'description' => $r['description'] ?? '',
                ];
            }
        } else {
            foreach ($rows as $r) {
                $invoiceRef = '';
                if (!empty($r['invoiceId'])) {
                    $invType = $r['invoiceType'] ?? '';
                    $typeLabel = $invType === 'sales' ? 'فروش' : ($invType === 'purchase' ? 'خرید' : ($invType === 'return' ? 'مرجوعی' : $invType));
                    $invoiceRef = trim($typeLabel . ' #' . $r['invoiceId']);
                }
                $normalizedRows[] = [
                    'id' => $r['id'],
                    'date' => $r['date'],
                    'transaction_type' => $r['transaction_type'],
                    'type' => $r['type'],
                    'amount' => $r['amount'],
                    'person' => $r['personName'] ?? '-',
                    'account' => $r['accountName'] ?? '-',
                    'person_type' => $r['personType'] ?? '',
                    'invoice_ref' => $invoiceRef !== '' ? $invoiceRef : '-',
                    'description' => $r['description'] ?? '',
                ];
            }
        }

        $summary = $this->summarizeCustomReport($source, $normalizedRows);
        $summary['grouped'] = $groupBy !== null;
        $summary['group_by'] = $groupBy;
        $summary['detail_count'] = count($normalizedRows);

        if ($groupBy) {
            $grouped = $this->groupCustomReportRows($groupBy, $normalizedRows, $meta['numeric'], $columns);
            $columns = $grouped['columns'];
            $normalizedRows = $grouped['rows'];
            $summary['total_count'] = count($normalizedRows);
        } else {
            $projected = [];
            foreach ($normalizedRows as $row) {
                $out = [];
                foreach ($columns as $col) {
                    $out[$col] = $row[$col] ?? '';
                }
                $projected[] = $out;
            }
            $normalizedRows = $projected;
            $summary['total_count'] = count($normalizedRows);
        }

        return [
            'source' => $source,
            'columns' => $columns,
            'rows' => $normalizedRows,
            'summary' => $summary,
            'truncated' => count($rows) >= $exportLimit,
        ];
    }

    private function customReportCatalog(): array {
        return [
            'sales_invoices' => [
                'columns' => ['date', 'invoice_number', 'person', 'amount', 'discount', 'paid', 'remaining', 'tax', 'delivery_status', 'due_date', 'description', 'id'],
                'numeric' => ['amount', 'discount', 'paid', 'remaining', 'tax'],
                'group_by' => ['person', 'delivery_status', 'month'],
            ],
            'purchase_invoices' => [
                'columns' => ['date', 'invoice_number', 'person', 'amount', 'discount', 'paid', 'remaining', 'tax', 'delivery_status', 'due_date', 'description', 'id'],
                'numeric' => ['amount', 'discount', 'paid', 'remaining', 'tax'],
                'group_by' => ['person', 'delivery_status', 'month'],
            ],
            'expenses' => [
                'columns' => ['date', 'category', 'amount', 'account', 'person', 'description', 'id'],
                'numeric' => ['amount'],
                'group_by' => ['category', 'account', 'person', 'month'],
            ],
            'incomes' => [
                'columns' => ['date', 'category', 'amount', 'account', 'person', 'description', 'id'],
                'numeric' => ['amount'],
                'group_by' => ['category', 'account', 'person', 'month'],
            ],
            'payments' => [
                'columns' => ['date', 'transaction_type', 'type', 'amount', 'person', 'account', 'invoice_ref', 'description', 'id'],
                'numeric' => ['amount'],
                'group_by' => ['transaction_type', 'type', 'person', 'account', 'month'],
            ],
        ];
    }

    private function parseCustomReportList($raw): array {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $col) {
            if (!is_string($col) && !is_numeric($col)) {
                continue;
            }
            $col = trim((string)$col);
            if ($col !== '') {
                $out[] = $col;
            }
        }
        return array_values(array_unique($out));
    }

    private function resolveCustomColumns(array $requested, array $catalog): array {
        if (!$requested) {
            return $catalog;
        }
        $allowed = array_flip($catalog);
        $picked = [];
        foreach ($requested as $col) {
            if (isset($allowed[$col])) {
                $picked[] = $col;
            }
        }
        return $picked ?: $catalog;
    }

    private function normalizeCustomGroupBy($raw, array $allowed): ?string {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '' || $value === 'none') {
            return null;
        }
        return in_array($value, $allowed, true) ? $value : null;
    }

    private function summarizeCustomReport(string $source, array $rows): array {
        $summary = [
            'total_count' => count($rows),
            'total_amount' => 0,
            'total_discount' => 0,
            'total_remaining' => 0,
            'total_tax' => 0,
            'total_paid' => 0,
        ];
        foreach ($rows as $row) {
            if (in_array($source, ['sales_invoices', 'purchase_invoices'], true)) {
                $summary['total_amount'] += floatval($row['amount'] ?? 0);
                $summary['total_discount'] += floatval($row['discount'] ?? 0);
                $summary['total_remaining'] += floatval($row['remaining'] ?? 0);
                $summary['total_tax'] += floatval($row['tax'] ?? 0);
                $summary['total_paid'] += floatval($row['paid'] ?? 0);
            } else {
                $summary['total_amount'] += floatval($row['amount'] ?? 0);
            }
        }
        return $summary;
    }

    private function groupCustomReportRows(string $groupBy, array $rows, array $numericKeys, array $selectedColumns): array {
        $metrics = array_values(array_intersect($numericKeys, $selectedColumns));
        if (!$metrics) {
            $metrics = in_array('amount', $numericKeys, true) ? ['amount'] : $numericKeys;
        }

        $deliveryLabels = [
            'preparing' => 'در حال آماده‌سازی',
            'ready' => 'آماده تحویل',
            'delivered' => 'تحویل شده',
        ];

        $buckets = [];
        foreach ($rows as $row) {
            if ($groupBy === 'month') {
                $ym = substr((string)($row['date'] ?? ''), 0, 7);
                $key = $ym !== '' ? $this->formatExportMonth($ym) : '-';
            } elseif ($groupBy === 'delivery_status') {
                $raw = trim((string)($row['delivery_status'] ?? ''));
                $key = $deliveryLabels[$raw] ?? ($raw !== '' ? $raw : '-');
            } else {
                $key = trim((string)($row[$groupBy] ?? ''));
                if ($key === '') {
                    $key = '-';
                }
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['row_count' => 0];
                foreach ($metrics as $metric) {
                    $buckets[$key][$metric] = 0;
                }
            }
            $buckets[$key]['row_count']++;
            foreach ($metrics as $metric) {
                $buckets[$key][$metric] += floatval($row[$metric] ?? 0);
            }
        }

        $sortKey = in_array('amount', $metrics, true) ? 'amount' : ($metrics[0] ?? 'row_count');
        uasort($buckets, function ($a, $b) use ($sortKey) {
            return ($b[$sortKey] ?? 0) <=> ($a[$sortKey] ?? 0);
        });

        $labelCol = $groupBy === 'month' ? 'month' : $groupBy;
        $columns = array_merge([$labelCol, 'row_count'], $metrics);
        $out = [];
        foreach ($buckets as $label => $bucket) {
            $line = [$labelCol => $label, 'row_count' => $bucket['row_count']];
            foreach ($metrics as $metric) {
                $line[$metric] = $bucket[$metric];
            }
            $out[] = $line;
        }

        return ['columns' => $columns, 'rows' => $out];
    }

    private function formatExportMonth(string $yearMonth): string {
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            return $yearMonth;
        }
        $jalali = gregorian_to_jalali($yearMonth . '-01');
        return substr($jalali, 0, 7);
    }

    private function customReportColumnLabel(string $col): string {
        $labels = [
            'id' => 'شناسه',
            'date' => 'تاریخ',
            'person' => 'شخص',
            'amount' => 'مبلغ',
            'discount' => 'تخفیف',
            'paid' => 'پرداخت‌شده',
            'remaining' => 'مانده',
            'tax' => 'مالیات',
            'description' => 'توضیحات',
            'category' => 'دسته‌بندی',
            'account' => 'حساب',
            'transaction_type' => 'جریان',
            'type' => 'روش پرداخت',
            'month' => 'ماه',
            'row_count' => 'تعداد',
            'delivery_status' => 'وضعیت تحویل',
            'due_date' => 'سررسید',
            'invoice_number' => 'شماره فاکتور',
            'invoice_ref' => 'فاکتور مرتبط',
            'person_type' => 'نوع شخص',
        ];
        return $labels[$col] ?? $col;
    }

    private function formatCustomExportValue(string $col, $value): string {
        if (in_array($col, ['amount', 'discount', 'paid', 'remaining', 'tax', 'row_count'], true)) {
            return (string)$value;
        }
        if ($col === 'date' || $col === 'due_date') {
            return $this->formatExportDate($value);
        }
        if ($col === 'transaction_type') {
            return $value === 'receipt' ? 'دریافت' : ($value === 'payment' ? 'پرداخت' : (string)$value);
        }
        if ($col === 'type') {
            $map = ['cash' => 'نقد', 'card' => 'کارتی', 'transfer' => 'انتقال', 'check' => 'چک', 'endorse_check' => 'خرج چک', 'pos' => 'پوز', 'payable_check' => 'چک پرداختی'];
            return $map[$value] ?? (string)$value;
        }
        if ($col === 'delivery_status') {
            $map = ['preparing' => 'در حال آماده‌سازی', 'ready' => 'آماده تحویل', 'delivered' => 'تحویل شده'];
            return $map[$value] ?? (string)$value;
        }
        return (string)($value ?? '');
    }

    /**
     * گزارش جامع چک‌ها
     */
    public function getChecksReport($data) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $startDate = $data['startDate'] ?? date('Y-m-01');
        $endDate = $data['endDate'] ?? date('Y-m-d');
        $checkType = $data['checkType'] ?? ($data['filterCheckType'] ?? 'all');
        $checkStatus = $data['checkStatus'] ?? ($data['filterCheckStatus'] ?? 'all');
        $onlyPastDue = $this->parseBoolParam($data, 'onlyPastDue', false);
        $today = date('Y-m-d');

        $sql = "SELECT c.id, c.type, c.status, c.checkNumber, c.bankName, c.issuerName,
                       c.dueDate, c.cashed_date, c.amount, c.invoiceId, c.invoiceType,
                       p.person_type, p.person_id, p.description AS payment_description,
                       CASE
                           WHEN p.person_type = 'customer' THEN cust.name
                           WHEN p.person_type = 'supplier' THEN supp.name
                           WHEN p.person_type = 'partner' THEN part.name
                           ELSE NULL
                       END AS person_name,
                       CASE
                           WHEN p.person_type = 'customer' THEN cust.phone
                           WHEN p.person_type = 'supplier' THEN supp.phone
                           ELSE NULL
                       END AS person_phone,
                       endorse.person_type AS holder_person_type,
                       endorse.person_id AS holder_person_id,
                       CASE
                           WHEN endorse.person_type = 'customer' THEN e_cust.name
                           WHEN endorse.person_type = 'supplier' THEN e_supp.name
                           WHEN endorse.person_type = 'partner' THEN e_part.name
                           ELSE NULL
                       END AS holder_name,
                       CASE
                           WHEN endorse.person_type = 'customer' THEN e_cust.phone
                           WHEN endorse.person_type = 'supplier' THEN e_supp.phone
                           ELSE NULL
                       END AS holder_phone,
                       acc.name AS cashed_account_name
                FROM checks c
                LEFT JOIN payments p ON c.id = p.checkId AND p.type = 'check' AND p.entity_id = c.entity_id
                LEFT JOIN customers cust ON p.person_type = 'customer' AND p.person_id = cust.id
                LEFT JOIN suppliers supp ON p.person_type = 'supplier' AND p.person_id = supp.id
                LEFT JOIN partners part ON p.person_type = 'partner' AND p.person_id = part.id
                LEFT JOIN (
                    SELECT p1.*
                    FROM payments p1
                    INNER JOIN (
                        SELECT checkId, MAX(id) AS max_id
                        FROM payments
                        WHERE type = 'endorse_check' AND entity_id = ?
                        GROUP BY checkId
                    ) latest ON p1.id = latest.max_id
                ) endorse ON endorse.checkId = c.id
                LEFT JOIN customers e_cust ON endorse.person_type = 'customer' AND endorse.person_id = e_cust.id
                LEFT JOIN suppliers e_supp ON endorse.person_type = 'supplier' AND endorse.person_id = e_supp.id
                LEFT JOIN partners e_part ON endorse.person_type = 'partner' AND endorse.person_id = e_part.id
                LEFT JOIN accounts acc ON c.cashed_in_account_id = acc.id
                WHERE c.entity_id = ?
                  AND c.dueDate BETWEEN ? AND ?";

        $types = 'iiss';
        $params = [$entity_id, $entity_id, $startDate, $endDate];

        if (in_array($checkType, ['received', 'payable'], true)) {
            $sql .= " AND c.type = ?";
            $types .= 's';
            $params[] = $checkType;
        }

        $allowedStatuses = ['in_hand', 'payable', 'endorsed', 'cashed', 'bounced'];
        if (in_array($checkStatus, $allowedStatuses, true)) {
            $sql .= " AND c.status = ?";
            $types .= 's';
            $params[] = $checkStatus;
        }

        if ($onlyPastDue) {
            $sql .= " AND c.status IN ('in_hand', 'payable') AND c.dueDate < ?";
            $types .= 's';
            $params[] = $today;
        }

        $sql .= " ORDER BY c.dueDate ASC, c.id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $typeLabels = ['received' => 'دریافتی', 'payable' => 'پرداختی'];
        $statusLabels = [
            'in_hand' => 'نزد ما',
            'endorsed' => 'واگذار شده',
            'cashed' => 'وصول/پاس شده',
            'payable' => 'پرداختنی',
            'bounced' => 'برگشتی'
        ];
        $personTypeLabels = [
            'customer' => 'مشتری',
            'supplier' => 'تأمین‌کننده',
            'partner' => 'شریک'
        ];
        $invoiceTypeLabels = [
            'sales' => 'فاکتور فروش',
            'purchase' => 'فاکتور خرید',
            'return' => 'فاکتور مرجوعی'
        ];

        $summary = [
            'count' => 0,
            'total_amount' => 0,
            'overdue_count' => 0,
            'overdue_amount' => 0,
            'by_type' => [
                'received' => ['count' => 0, 'amount' => 0],
                'payable' => ['count' => 0, 'amount' => 0],
            ],
            'by_status' => [],
        ];
        foreach ($statusLabels as $key => $label) {
            $summary['by_status'][$key] = ['count' => 0, 'amount' => 0, 'label' => $label];
        }

        $checks = [];
        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $status = $row['status'];
            $type = $row['type'];
            $isOverdue = in_array($status, ['in_hand', 'payable'], true) && !empty($row['dueDate']) && $row['dueDate'] < $today;

            $statusLabel = $statusLabels[$status] ?? $status;
            if ($status === 'cashed') {
                $statusLabel = $type === 'payable' ? 'پاس شده' : 'وصول شده';
            }

            $currentHolder = '-';
            $currentHolderPhone = null;
            if ($status === 'cashed') {
                $currentHolder = $row['cashed_account_name']
                    ? ('حساب: ' . $row['cashed_account_name'])
                    : 'وصول/پاس شده';
            } elseif ($status === 'endorsed' && !empty($row['holder_name'])) {
                $currentHolder = $row['holder_name'];
                $currentHolderPhone = $row['holder_phone'] ?? null;
            } elseif ($type === 'received' && $status === 'in_hand') {
                $currentHolder = 'نزد مجموعه';
            } elseif (!empty($row['person_name'])) {
                $currentHolder = $row['person_name'];
                $currentHolderPhone = $row['person_phone'] ?? null;
            }

            $invoiceLabel = null;
            if (!empty($row['invoiceId']) && !empty($row['invoiceType'])) {
                $invoiceLabel = ($invoiceTypeLabels[$row['invoiceType']] ?? 'فاکتور') . ' #' . $row['invoiceId'];
            }

            $checks[] = [
                'id' => (int)$row['id'],
                'checkNumber' => $row['checkNumber'],
                'amount' => $amount,
                'bankName' => $row['bankName'],
                'issuerName' => $row['issuerName'],
                'dueDate' => $row['dueDate'],
                'cashed_date' => $row['cashed_date'],
                'type' => $type,
                'type_label' => $typeLabels[$type] ?? $type,
                'status' => $status,
                'status_label' => $statusLabel,
                'person_type' => $row['person_type'],
                'person_type_label' => $personTypeLabels[$row['person_type']] ?? $row['person_type'],
                'person_name' => $row['person_name'],
                'person_phone' => $row['person_phone'],
                'invoice_label' => $invoiceLabel,
                'current_holder' => $currentHolder,
                'current_holder_phone' => $currentHolderPhone,
                'description' => $row['payment_description'],
                'is_overdue' => $isOverdue,
                'days_to_due' => !empty($row['dueDate'])
                    ? (int)round((strtotime($row['dueDate']) - strtotime($today)) / 86400)
                    : null,
            ];

            $summary['count']++;
            $summary['total_amount'] += $amount;
            if (isset($summary['by_type'][$type])) {
                $summary['by_type'][$type]['count']++;
                $summary['by_type'][$type]['amount'] += $amount;
            }
            if (!isset($summary['by_status'][$status])) {
                $summary['by_status'][$status] = ['count' => 0, 'amount' => 0, 'label' => $statusLabel];
            }
            $summary['by_status'][$status]['count']++;
            $summary['by_status'][$status]['amount'] += $amount;
            if ($isOverdue) {
                $summary['overdue_count']++;
                $summary['overdue_amount'] += $amount;
            }
        }

        return [
            'checks' => $checks,
            'summary' => $summary,
            'filters' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'checkType' => $checkType,
                'checkStatus' => $checkStatus,
                'onlyPastDue' => $onlyPastDue,
            ],
        ];
    }
}
