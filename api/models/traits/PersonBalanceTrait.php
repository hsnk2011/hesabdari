<?php
// /api/models/traits/PersonBalanceTrait.php

trait PersonBalanceTrait {

    /**
     * Attach open-invoice summary + consolidated net balance (matches person statement / closing reports).
     */
    protected function attachBalanceDataToPersons(array $persons, string $personType, ?string $asOfDate = null): array {
        if (empty($persons)) {
            return [];
        }

        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return $persons;
        }

        $isCustomer = ($personType === 'customer');
        $personIdColumn = $isCustomer ? 'customerId' : 'supplierId';
        $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
        $invoiceType = $isCustomer ? 'sales' : 'purchase';
        $returnType = $isCustomer ? 'sales_return' : 'purchase_return';

        $personIds = array_column($persons, 'id');
        if (empty($personIds)) {
            return $persons;
        }

        $ids_placeholder = implode(',', array_fill(0, count($personIds), '?'));
        $types = str_repeat('i', count($personIds));
        $endDate = $asOfDate ?: date('Y-m-d');

        // Open invoice remainders (display column only)
        $unsettled = [];
        $unsettled_sql = "SELECT {$personIdColumn}, SUM(totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) as total_unsettled
                          FROM {$invoiceTable}
                          WHERE entity_id = ? AND {$personIdColumn} IN ({$ids_placeholder})
                            AND (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) > 0.01
                            AND is_consignment = 0
                          GROUP BY {$personIdColumn}";
        $unsettled_stmt = $this->conn->prepare($unsettled_sql);
        $unsettled_stmt->bind_param('i' . $types, $entityId, ...$personIds);
        $unsettled_stmt->execute();
        foreach (db_stmt_to_assoc_array($unsettled_stmt) as $row) {
            $unsettled[$row[$personIdColumn]] = $row['total_unsettled'];
        }

        // Overpayments on fully-settled invoices (advance credit display)
        $overpayments = [];
        $overpayment_sql = "SELECT {$personIdColumn}, SUM(paidAmount - (totalAmount - discount + COALESCE(tax_amount, 0))) as overpayment
                            FROM {$invoiceTable}
                            WHERE entity_id = ? AND {$personIdColumn} IN ({$ids_placeholder})
                              AND paidAmount > (totalAmount - discount + COALESCE(tax_amount, 0))
                              AND is_consignment = 0
                            GROUP BY {$personIdColumn}";
        $overpayment_stmt = $this->conn->prepare($overpayment_sql);
        $overpayment_stmt->bind_param('i' . $types, $entityId, ...$personIds);
        $overpayment_stmt->execute();
        foreach (db_stmt_to_assoc_array($overpayment_stmt) as $row) {
            $overpayments[$row[$personIdColumn]] = $row['overpayment'];
        }

        // Standalone advance payments (display column)
        $credits = [];
        $credit_sql_logic = $isCustomer
            ? "SUM(IF(transaction_type = 'receipt', amount, -amount))"
            : "SUM(IF(transaction_type = 'payment', amount, -amount))";

        $credit_sql = "SELECT person_id, {$credit_sql_logic} as available_credit
                       FROM payments
                       WHERE entity_id = ? AND person_type = ? AND invoiceId IS NULL AND person_id IN ({$ids_placeholder})
                       GROUP BY person_id";
        $credit_stmt = $this->conn->prepare($credit_sql);
        $credit_stmt->bind_param('is' . $types, $entityId, $personType, ...$personIds);
        $credit_stmt->execute();
        foreach (db_stmt_to_assoc_array($credit_stmt) as $row) {
            $credits[$row['person_id']] = $row['available_credit'];
        }

        // Consolidated ledger components (same formula as ReportModel closing reports)
        $invoiceTotals = [];
        $invoice_sql = "SELECT {$personIdColumn} as person_id,
                               SUM(totalAmount - discount + COALESCE(tax_amount, 0)) as total
                        FROM {$invoiceTable}
                        WHERE entity_id = ? AND {$personIdColumn} IN ({$ids_placeholder})
                          AND date <= ? AND is_consignment = 0
                        GROUP BY {$personIdColumn}";
        $invoice_stmt = $this->conn->prepare($invoice_sql);
        $invoice_stmt->bind_param('i' . $types . 's', $entityId, ...array_merge($personIds, [$endDate]));
        $invoice_stmt->execute();
        foreach (db_stmt_to_assoc_array($invoice_stmt) as $row) {
            $invoiceTotals[$row['person_id']] = floatval($row['total']);
        }

        $returnTotals = [];
        $return_sql = "SELECT person_id, SUM((totalAmount - discount) - COALESCE(paidAmount, 0)) as total
                       FROM return_invoices
                       WHERE entity_id = ? AND person_id IN ({$ids_placeholder})
                         AND type = ? AND date <= ?
                       GROUP BY person_id";
        $return_stmt = $this->conn->prepare($return_sql);
        $return_stmt->bind_param('i' . $types . 'ss', $entityId, ...array_merge($personIds, [$returnType, $endDate]));
        $return_stmt->execute();
        foreach (db_stmt_to_assoc_array($return_stmt) as $row) {
            $returnTotals[$row['person_id']] = floatval($row['total']);
        }

        $paidOnInvoices = [];
        // Signed like Payment::invoicePaidDelta so a refund on the same invoice
        // does not inflate "paid" and flip a settled person to بستانکار.
        $paidExpr = $isCustomer
            ? "SUM(IF(p.transaction_type = 'receipt', p.amount, -p.amount))"
            : "SUM(IF(p.transaction_type = 'payment', p.amount, -p.amount))";
        $paid_sql = "SELECT inv.{$personIdColumn} as person_id, {$paidExpr} as total
                     FROM payments p
                     INNER JOIN {$invoiceTable} inv ON p.invoiceId = inv.id AND p.invoiceType = ?
                     WHERE inv.entity_id = ? AND inv.{$personIdColumn} IN ({$ids_placeholder})
                       AND inv.is_consignment = 0 AND p.entity_id = ? AND p.date <= ?
                     GROUP BY inv.{$personIdColumn}";
        $paid_stmt = $this->conn->prepare($paid_sql);
        $paid_stmt->bind_param('si' . $types . 'is', $invoiceType, $entityId, ...array_merge($personIds, [$entityId, $endDate]));
        $paid_stmt->execute();
        foreach (db_stmt_to_assoc_array($paid_stmt) as $row) {
            $paidOnInvoices[$row['person_id']] = floatval($row['total']);
        }

        $advancePayments = [];
        $advancePayType = $isCustomer ? 'receipt' : 'payment';
        $advance_pay_sql = "SELECT person_id, SUM(amount) as total
                            FROM payments
                            WHERE entity_id = ? AND person_type = ? AND person_id IN ({$ids_placeholder})
                              AND invoiceId IS NULL AND transaction_type = ? AND date <= ?
                            GROUP BY person_id";
        $advance_pay_stmt = $this->conn->prepare($advance_pay_sql);
        $advance_pay_stmt->bind_param('is' . $types . 'ss', $entityId, $personType, ...array_merge($personIds, [$advancePayType, $endDate]));
        $advance_pay_stmt->execute();
        foreach (db_stmt_to_assoc_array($advance_pay_stmt) as $row) {
            $advancePayments[$row['person_id']] = floatval($row['total']);
        }

        $advanceReceipts = [];
        $advanceRecType = $isCustomer ? 'payment' : 'receipt';
        $advance_rec_sql = "SELECT person_id, SUM(amount) as total
                            FROM payments
                            WHERE entity_id = ? AND person_type = ? AND person_id IN ({$ids_placeholder})
                              AND invoiceId IS NULL AND transaction_type = ? AND date <= ?
                            GROUP BY person_id";
        $advance_rec_stmt = $this->conn->prepare($advance_rec_sql);
        $advance_rec_stmt->bind_param('is' . $types . 'ss', $entityId, $personType, ...array_merge($personIds, [$advanceRecType, $endDate]));
        $advance_rec_stmt->execute();
        foreach (db_stmt_to_assoc_array($advance_rec_stmt) as $row) {
            $advanceReceipts[$row['person_id']] = floatval($row['total']);
        }

        require_once __DIR__ . '/../Asset.php';
        $assetNet = Asset::personNet($this->conn, (int)$entityId, $personType, $personIds, $endDate);

        foreach ($persons as &$person) {
            $id = $person['id'];
            $openInvoices = floatval($unsettled[$id] ?? 0);
            $returnRemain = floatval($returnTotals[$id] ?? 0);
            $rawCredit = floatval($credits[$id] ?? 0) + floatval($overpayments[$id] ?? 0);
            // Unrefunded returns offset open invoices in the list columns (same net as status).
            $returnOffset = min($openInvoices, max(0.0, $returnRemain));
            $person['total_unsettled'] = $openInvoices - $returnOffset;
            $person['available_credit'] = $rawCredit + $returnRemain - $returnOffset;

            $initial = floatval($person['initial_balance'] ?? 0);
            $invoices = $invoiceTotals[$id] ?? 0;
            $returns = $returnRemain;
            $paid = $paidOnInvoices[$id] ?? 0;
            $advPay = $advancePayments[$id] ?? 0;
            $advRec = $advanceReceipts[$id] ?? 0;

            // Supplier: we owe when positive. Customer: they owe when positive.
            // Invoice receipts/payments are signed; returns are remaining after refunds.
            $person['net_balance'] = $initial + $invoices - $returns - $paid - $advPay + $advRec;
            // Asset documents (positive = owes us): customers +, suppliers −.
            $person['net_balance'] += ($isCustomer ? 1 : -1) * floatval($assetNet[(int)$id] ?? 0);
        }
        unset($person);

        return $persons;
    }

    /**
     * For persons who are both customer and supplier: the linked record on the
     * other side, its balance, and the combined net balance in this list's
     * convention (customer list: positive = owes us; supplier list: positive = we owe).
     */
    protected function attachLinkedPersonData(array $persons, string $personType): array {
        if (empty($persons)) {
            return $persons;
        }
        require_once __DIR__ . '/../PersonLink.php';
        $twins = (new PersonLink($this->conn))->twinRows($personType, array_column($persons, 'id'));
        if (empty($twins)) {
            return $persons;
        }
        $otherType = PersonLink::otherType($personType);
        $twinBalances = [];
        foreach ($this->attachBalanceDataToPersons(array_values($twins), $otherType) as $t) {
            $twinBalances[(int)$t['id']] = (float)($t['net_balance'] ?? 0);
        }
        foreach ($persons as &$person) {
            $twin = $twins[(int)$person['id']] ?? null;
            if (!$twin) {
                continue;
            }
            $twinBalance = $twinBalances[(int)$twin['id']] ?? 0.0;
            $person['linked_id'] = (int)$twin['id'];
            $person['linked_type'] = $otherType;
            $person['linked_net_balance'] = $twinBalance;
            $person['combined_balance'] = (float)($person['net_balance'] ?? 0) - $twinBalance;
        }
        unset($person);
        return $persons;
    }

    /**
     * The opening balance feeds every statement and closing snapshot; once a
     * period is closed it must not change retroactively.
     * Returns an error message or null.
     */
    protected function initialBalanceChangeError($id, float $newInitial): ?string {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $stmt = $this->conn->prepare("SELECT initial_balance FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$row || abs((float)$row['initial_balance'] - $newInitial) < 0.005) {
            return null;
        }
        $closed = get_entity_last_closed_date($this->conn, $entityId);
        if ($closed) {
            return "مانده اول دوره پس از بستن حساب (تا {$closed}) قابل تغییر نیست؛ برای اصلاح، سند/تراکنش اصلاحی ثبت کنید یا بستن حساب را بازگشایی کنید.";
        }
        return null;
    }

    /**
     * Why a person cannot be deleted (documents that reference them).
     * Payments/expenses/returns have no FK to persons, so deleting would orphan them.
     */
    protected function personDeleteBlockers(string $personType, int $id): array {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $checks = [
            ["SELECT COUNT(*) c FROM payments WHERE entity_id = ? AND person_type = ? AND person_id = ?", 'تراکنش/پرداخت'],
            ["SELECT COUNT(*) c FROM expenses WHERE entity_id = ? AND person_type = ? AND person_id = ?", 'هزینه'],
            ["SELECT COUNT(*) c FROM incomes WHERE entity_id = ? AND person_type = ? AND person_id = ?", 'درآمد'],
            ["SELECT COUNT(*) c FROM return_invoices WHERE entity_id = ? AND person_type = ? AND person_id = ?", 'فاکتور مرجوعی'],
        ];
        if ($personType === 'customer') {
            $checks[] = ["SELECT COUNT(*) c FROM sales_invoices WHERE entity_id = ? AND ? = 'customer' AND customerId = ?", 'فاکتور فروش'];
            $checks[] = ["SELECT COUNT(*) c FROM quotations WHERE entity_id = ? AND ? = 'customer' AND customerId = ?", 'پیش فاکتور'];
        } elseif ($personType === 'supplier') {
            $checks[] = ["SELECT COUNT(*) c FROM purchase_invoices WHERE entity_id = ? AND ? = 'supplier' AND supplierId = ?", 'فاکتور خرید'];
        }
        $found = [];
        foreach ($checks as $def) {
            try {
                $st = $this->conn->prepare($def[0]);
                $st->bind_param('isi', $entityId, $personType, $id);
                $st->execute();
                $n = (int)(db_stmt_to_assoc_array($st)[0]['c'] ?? 0);
                if ($n > 0) {
                    $found[] = "{$n} {$def[1]}";
                }
            } catch (Throwable $e) { /* optional table */ }
        }
        return $found;
    }

    protected function filterPersonsByBalance(array $persons, string $balanceFilter): array {
        if ($balanceFilter === '') {
            return $persons;
        }
        return array_values(array_filter($persons, function ($p) use ($balanceFilter) {
            $bal = floatval($p['net_balance'] ?? 0);
            switch ($balanceFilter) {
                case 'positive':
                    return $bal > 0.01;
                case 'negative':
                    return $bal < -0.01;
                case 'settled':
                    return abs($bal) <= 0.01;
                default:
                    return true;
            }
        }));
    }

    /**
     * Optional job/occupation column on person tables (customers/suppliers/contacts).
     */
    protected function ensurePersonJobColumn(): void {
        static $done = [];
        $table = $this->tableName ?? '';
        if ($table === '' || isset($done[$table])) {
            return;
        }
        $done[$table] = true;
        try {
            $tbl = $this->conn->query("SHOW TABLES LIKE '{$table}'");
            if (!($tbl && $tbl->num_rows)) {
                return;
            }
            $col = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'job'");
            if ($col && $col->num_rows > 0) {
                return;
            }
            $this->conn->query(
                "ALTER TABLE `{$table}` ADD COLUMN `job` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `phone`"
            );
        } catch (Exception $e) {
            // ignore if no permission / race
        }
    }

    protected function paginatePersonList(array $persons, array $input): array {
        $page = max(1, intval($input['currentPage'] ?? 1));
        $requestedLimit = intval($input['limit'] ?? (defined('DEFAULT_PAGE_SIZE') ? DEFAULT_PAGE_SIZE : 10));
        $maxLimit = defined('MAX_PAGE_SIZE') ? MAX_PAGE_SIZE : 500;
        $limit = ($requestedLimit == -1) ? 999999 : min(max(1, $requestedLimit), $maxLimit);
        $total = count($persons);
        $offset = ($page - 1) * $limit;
        return [
            'data' => array_slice($persons, $offset, $limit),
            'totalRecords' => $total,
        ];
    }
}
