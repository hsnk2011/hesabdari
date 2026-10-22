<?php
// /api/models/ClosingSnapshotBuilder.php

require_once __DIR__ . '/ReportModel.php';
require_once __DIR__ . '/Account.php';

/**
 * ساخت اسنپ‌شات کامل بستن حساب و سند افتتاحیه دوره بعد
 */
class ClosingSnapshotBuilder {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function build($entityId, $periodStart, $periodEnd, $calculationMethod, array $extra = []) {
        $report = new ReportModel($this->conn);

        $plData = $report->getProfitLossReport([
            'startDate' => $periodStart,
            'endDate' => $periodEnd,
            'entityId' => $entityId,
            'calculationMethod' => $calculationMethod,
        ]);

        $customerReceivables = $this->getCustomerReceivables($periodEnd, $entityId);
        $supplierDebts = $this->getSupplierDebts($periodEnd, $entityId);
        $checks = $this->getChecksPortfolio($periodEnd, $entityId);
        $liquidity = $this->getLiquiditySnapshot($entityId, $periodEnd);
        $inventory = $this->getInventorySnapshot($periodEnd, $entityId, $report);
        $partners = $this->getPartnersSnapshot($entityId, $periodStart, $periodEnd, $extra['currentPartners'] ?? []);
        $periodPayments = $this->getPeriodCashMovements($entityId, $periodStart, $periodEnd);

        $capitalSummary = $plData['capitalSummary'] ?? [];
        $openingCapital = (float)($capitalSummary['openingCapital'] ?? 0);
        $periodDeposits = (float)($capitalSummary['periodDeposits'] ?? 0);
        $periodWithdrawals = (float)($capitalSummary['periodWithdrawals'] ?? 0);
        $closingCapital = $openingCapital + $periodDeposits - $periodWithdrawals;
        $netProfit = (float)($plData['netOperatingProfit'] ?? 0);

        $totalCustomerReceivable = (float)($customerReceivables['totalReceivable'] ?? 0);
        $totalSupplierDebt = (float)($supplierDebts['totalDebt'] ?? 0);
        $checksReceivedAsset = (float)($checks['totals']['receivedInHand'] ?? 0);
        $checksPayableLiability = (float)($checks['totals']['payableOutstanding'] ?? 0);
        // Prefer as-of FIFO; ownValue is aligned to the same basis in getInventorySnapshot.
        $inventoryOwnValue = (float)($inventory['fifoValueAtClose'] ?? $inventory['ownValue'] ?? 0);
        $totalLiquidity = (float)($liquidity['totalInCashPosition'] ?? $liquidity['total'] ?? 0);
        $partnerPositive = (float)($partners['totals']['positiveBalance'] ?? 0);
        $partnerNegative = abs((float)($partners['totals']['negativeBalance'] ?? 0));

        // مالیات بر ارزش افزوده: مطالبات/بدهی‌ها شامل tax_amount هستند اما سود/زیان نه.
        // مابه‌التفاوت (فروش منهای خرید) به‌عنوان بدهی (اگر مثبت) یا طلب (اگر منفی) ترازنامه ظاهر می‌شود.
        $vat = $report->getVatSummary($periodEnd, $entityId);
        $vatNetPayable = (float)($vat['netPayable'] ?? 0);
        $vatPayable = max(0.0, $vatNetPayable);
        $vatReceivable = max(0.0, -$vatNetPayable);

        // Partner personal-account balances are a different concept from capitalSummary
        // (deposits/withdrawals). Including both in Assets/Liabilities AND Equity double-counts
        // and produces a persistent false balance-sheet difference. Keep partners as memo only.
        require_once __DIR__ . '/Asset.php';
        $fixedAssetsValue = Asset::bookValue($this->conn, (int)$entityId, $periodEnd);

        $totalAssets = $totalLiquidity
            + $checksReceivedAsset
            + $totalCustomerReceivable
            + $inventoryOwnValue
            + $fixedAssetsValue
            + $vatReceivable;

        $totalLiabilities = $totalSupplierDebt
            + $checksPayableLiability
            + $vatPayable;

        // Equity must equal Assets − Liabilities for the sheet to balance.
        // Partner capital (واریز/برداشت) alone misses prior retained earnings / inventory equity;
        // adding only this period's P&L still understates equity on a first close.
        // So: total equity = net assets; break down into partner capital + unallocated residual.
        $netAssets = round($totalAssets - $totalLiabilities, 2);
        $partnerCapital = round($closingCapital, 2);
        $unallocatedEarnings = round($netAssets - $partnerCapital, 2);
        $equityCalculated = $netAssets;
        $balanceDifference = round($totalAssets - $totalLiabilities - $equityCalculated, 2);

        $nextPeriodStart = date('Y-m-d', strtotime($periodEnd . ' +1 day'));

        $openingEntry = $this->buildOpeningEntry(
            $nextPeriodStart,
            $customerReceivables,
            $supplierDebts,
            $liquidity,
            $checks,
            $inventory,
            $partners,
            $equityCalculated,
            $netProfit,
            $vatNetPayable
        );

        return [
            'asOfDate' => $periodEnd,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'nextPeriodStart' => $nextPeriodStart,
            'profitLoss' => $plData,
            'liquidity' => $liquidity,
            'customers' => $customerReceivables,
            'suppliers' => $supplierDebts,
            'checks' => $checks,
            'inventory' => $inventory,
            'partners' => $partners,
            'periodCashMovements' => $periodPayments,
            'capital' => [
                'openingCapital' => $openingCapital,
                'periodDeposits' => $periodDeposits,
                'periodWithdrawals' => $periodWithdrawals,
                'closingCapital' => $closingCapital,
                'netProfit' => $netProfit,
                'partnerCapital' => $partnerCapital,
                'unallocatedEarnings' => $unallocatedEarnings,
                'netAssets' => $netAssets,
                'equityWithProfit' => $equityCalculated,
            ],
            'vat' => [
                'salesTax' => round((float)($vat['salesTax'] ?? 0), 2),
                'purchaseTax' => round((float)($vat['purchaseTax'] ?? 0), 2),
                'netPayable' => round($vatNetPayable, 2),
                'payable' => round($vatPayable, 2),
                'receivable' => round($vatReceivable, 2),
            ],
            'balanceSheet' => [
                'assets' => [
                    'liquidity' => $totalLiquidity,
                    'checksReceived' => $checksReceivedAsset,
                    'customerReceivables' => $totalCustomerReceivable,
                    'inventory' => $inventoryOwnValue,
                    'vatReceivable' => round($vatReceivable, 2),
                    'partnerReceivables' => 0,
                    'total' => round($totalAssets, 2),
                ],
                'liabilities' => [
                    'supplierDebts' => $totalSupplierDebt,
                    'checksPayable' => $checksPayableLiability,
                    'vatPayable' => round($vatPayable, 2),
                    'partnerPayables' => 0,
                    'total' => round($totalLiabilities, 2),
                ],
                'equity' => [
                    'capital' => $partnerCapital,
                    'periodProfit' => $netProfit,
                    'unallocatedEarnings' => $unallocatedEarnings,
                    'total' => round($equityCalculated, 2),
                ],
                'partnersMemo' => [
                    'receivables' => round($partnerPositive, 2),
                    'payables' => round($partnerNegative, 2),
                    'note' => 'مانده حساب شخصی شرکا جدا از سرمایه (واریز/برداشت) است و در معادله ترازنامه لحاظ نمی‌شود.',
                ],
                'difference' => $balanceDifference,
                'isBalanced' => abs($balanceDifference) < 1,
            ],
            'openingEntry' => $openingEntry,
        ];
    }

    private function getCustomerReceivables($endDate, $entityId) {
        return (new ReportModel($this->conn))->getCustomerReceivables($endDate, $entityId);
    }

    private function getSupplierDebts($endDate, $entityId) {
        return (new ReportModel($this->conn))->getSupplierDebts($endDate, $entityId);
    }

    private function getChecksPortfolio($asOfDate, $entityId) {
        // One row per check via type='check' payment. Exclude bounced (not an asset).
        // Effective cash date = COALESCE(cashed_date, dueDate) — older rows often lack cashed_date.
        // Classify as-of: endorsed if endorse_check date <= asOf; else open received/payable if not cashed yet.
        $stmt = $this->conn->prepare("
            SELECT c.id, c.type, c.status, c.checkNumber, c.dueDate, c.bankName, c.amount,
                   c.cashed_date,
                   COALESCE(c.cashed_date, c.dueDate) as effective_cashed_date,
                   p.person_type, p.person_id,
                   COALESCE(cu.name, su.name, pa.name) as personName,
                   (SELECT MIN(ep.date) FROM payments ep
                    WHERE ep.checkId = c.id AND ep.entity_id = c.entity_id AND ep.type = 'endorse_check'
                   ) as endorsed_date
            FROM checks c
            LEFT JOIN payments p ON p.checkId = c.id AND p.entity_id = c.entity_id AND p.type = 'check'
            LEFT JOIN customers cu ON p.person_type = 'customer' AND p.person_id = cu.id
            LEFT JOIN suppliers su ON p.person_type = 'supplier' AND p.person_id = su.id
            LEFT JOIN partners pa ON p.person_type = 'partner' AND p.person_id = pa.id
            WHERE c.entity_id = ?
              AND c.status <> 'bounced'
              AND (
                    (c.type = 'received' AND c.status IN ('in_hand', 'endorsed', 'cashed'))
                 OR (c.type = 'payable' AND c.status IN ('payable', 'cashed'))
              )
              AND (p.id IS NULL OR p.date <= ?)
              AND (
                    c.status <> 'cashed'
                    OR COALESCE(c.cashed_date, c.dueDate) IS NULL
                    OR COALESCE(c.cashed_date, c.dueDate) > ?
              )
            ORDER BY c.dueDate ASC, c.id ASC
        ");
        $stmt->bind_param('iss', $entityId, $asOfDate, $asOfDate);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $received = [];
        $payable = [];
        $endorsed = [];
        $totals = [
            'receivedInHand' => 0,
            'receivedDue' => 0,
            'payableOutstanding' => 0,
            'endorsed' => 0,
            'count' => 0,
        ];
        $seen = [];

        foreach ($rows as $row) {
            $checkId = (int)$row['id'];
            if (isset($seen[$checkId])) {
                continue;
            }
            $seen[$checkId] = true;

            $amount = (float)$row['amount'];
            $endorsedDate = $row['endorsed_date'] ?? null;
            $wasEndorsedByAsOf = $endorsedDate && $endorsedDate <= $asOfDate;
            $effectiveCashed = $row['effective_cashed_date'] ?? null;
            $wasCashedByAsOf = ($row['status'] === 'cashed')
                && $effectiveCashed
                && $effectiveCashed <= $asOfDate;

            // Already cashed as-of: should have been filtered by SQL; skip defensively.
            if ($wasCashedByAsOf) {
                continue;
            }

            // At as-of, only treat as open received if still in_hand historically
            // (or cashed/endorsed after asOf). Current bounced already excluded by SQL.
            if ($row['type'] === 'received' && $row['status'] === 'endorsed' && !$wasEndorsedByAsOf) {
                $wasEndorsedByAsOf = false;
            }

            $displayStatus = 'in_hand';
            if ($row['type'] === 'payable') {
                $displayStatus = 'payable';
            } elseif ($wasEndorsedByAsOf) {
                $displayStatus = 'endorsed';
            }

            $item = [
                'id' => $checkId,
                'checkNumber' => $row['checkNumber'],
                'dueDate' => $row['dueDate'],
                'bankName' => $row['bankName'],
                'amount' => $amount,
                'personName' => $row['personName'],
                'status' => $displayStatus,
                'isDue' => ($row['dueDate'] <= $asOfDate),
                'endorsedDate' => $endorsedDate,
                'cashedDate' => $row['cashed_date'],
            ];
            $totals['count']++;

            if ($row['type'] === 'received' && $wasEndorsedByAsOf) {
                $endorsed[] = $item;
                $totals['endorsed'] += $amount;
            } elseif ($row['type'] === 'received') {
                $received[] = $item;
                $totals['receivedInHand'] += $amount;
                if ($item['isDue']) {
                    $totals['receivedDue'] += $amount;
                }
            } elseif ($row['type'] === 'payable') {
                // Outstanding at as-of (including currently cashed only if cashed after asOf)
                if ($row['status'] === 'payable' || $row['status'] === 'cashed') {
                    $payable[] = $item;
                    $totals['payableOutstanding'] += $amount;
                }
            }
        }

        return [
            'received' => $received,
            'endorsed' => $endorsed,
            'payable' => $payable,
            'totals' => $totals,
        ];
    }

    private function getLiquiditySnapshot($entityId, $asOfDate = null) {
        // Live calculated balance (no date filter) — used for discrepancy vs current_balance.
        $liveSql = "
            SELECT a.id, a.name, a.bank_name, a.is_cash, a.current_balance, a.initial_balance,
                   a.include_in_cash_position, a.partner_id,
                   (a.initial_balance +
                    COALESCE((SELECT SUM(IF(p.transaction_type='receipt', p.amount, -p.amount))
                        FROM payments p WHERE p.account_id = a.id AND p.type='cash' AND p.entity_id = ?), 0) -
                    COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.account_id = a.id AND e.entity_id = ?), 0) +
                    COALESCE((SELECT SUM(i.amount) FROM incomes i WHERE i.account_id = a.id AND i.entity_id = ?), 0) +
                    COALESCE((SELECT SUM(IF(c.type='received', c.amount, -c.amount))
                        FROM checks c WHERE c.cashed_in_account_id = a.id AND c.status='cashed' AND c.entity_id = ?), 0)
                   ) as live_calculated_balance,
                   (a.initial_balance +
                    COALESCE((SELECT SUM(IF(p.transaction_type='receipt', p.amount, -p.amount))
                        FROM payments p WHERE p.account_id = a.id AND p.type='cash' AND p.entity_id = ?
                          AND (? IS NULL OR p.date <= ?)), 0) -
                    COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.account_id = a.id AND e.entity_id = ?
                          AND (? IS NULL OR e.date <= ?)), 0) +
                    COALESCE((SELECT SUM(i.amount) FROM incomes i WHERE i.account_id = a.id AND i.entity_id = ?
                          AND (? IS NULL OR i.date <= ?)), 0) +
                    COALESCE((SELECT SUM(IF(c.type='received', c.amount, -c.amount))
                        FROM checks c WHERE c.cashed_in_account_id = a.id AND c.status='cashed' AND c.entity_id = ?
                          AND (? IS NULL OR COALESCE(c.cashed_date, c.dueDate) <= ?)), 0)
                   ) as calculated_balance
            FROM accounts a
            WHERE a.entity_id = ? AND a.partner_id IS NULL
            ORDER BY a.is_cash DESC, a.id ASC
        ";
        $stmt = $this->conn->prepare($liveSql);
        // live: pay,exp,inc,chk | as-of: pay,exp,inc,chk | outer
        // Types: i i i i  i s s  i s s  i s s  i s s  i
        $asOf = $asOfDate;
        $stmt->bind_param(
            'iiiiississississi',
            $entityId, $entityId, $entityId, $entityId,
            $entityId, $asOf, $asOf,
            $entityId, $asOf, $asOf,
            $entityId, $asOf, $asOf,
            $entityId, $asOf, $asOf,
            $entityId
        );
        $stmt->execute();
        $accounts = db_stmt_to_assoc_array($stmt);

        $cash = [];
        $bank = [];
        $total = 0;
        $totalInPosition = 0;
        $discrepancies = [];

        foreach ($accounts as $acc) {
            $calculated = (float)$acc['calculated_balance'];
            $liveCalculated = (float)$acc['live_calculated_balance'];
            $stored = (float)$acc['current_balance'];
            // Snapshot totals use as-of calculated when a close date is provided.
            $balance = $asOfDate ? $calculated : $stored;
            $diff = round($stored - $liveCalculated, 2);
            $row = [
                'id' => $acc['id'],
                'name' => $acc['name'],
                'bankName' => $acc['bank_name'],
                'balance' => $balance,
                'storedBalance' => $stored,
                'calculatedBalance' => $calculated,
                'liveCalculatedBalance' => $liveCalculated,
                'difference' => $diff,
                'inCashPosition' => (bool)$acc['include_in_cash_position'],
            ];
            $total += $balance;
            if ($acc['include_in_cash_position']) {
                $totalInPosition += $balance;
            }
            if (abs($diff) > 0.01) {
                $discrepancies[] = $row;
            }
            if ($acc['is_cash']) {
                $cash[] = $row;
            } else {
                $bank[] = $row;
            }
        }

        return [
            'cashAccounts' => $cash,
            'bankAccounts' => $bank,
            'allAccounts' => array_merge($cash, $bank),
            'total' => round($total, 2),
            'totalInCashPosition' => round($totalInPosition, 2),
            'discrepancies' => $discrepancies,
        ];
    }

    private function getInventorySnapshot($asOfDate, $entityId, ReportModel $report) {
        $fifoValue = (float)$report->getInventoryValueAtDate($asOfDate, $entityId);

        $qtyStmt = $this->conn->prepare("
            SELECT COUNT(*) as qty
            FROM inventory_items ii
            JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            LEFT JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
            LEFT JOIN sales_invoices si ON sii.invoiceId = si.id
            WHERE pi.entity_id = ? AND pi.date <= ?
              AND (
                ii.status IN ('in_stock', 'returned')
                OR ii.status = 'consignment_out'
                OR (ii.status = 'sold' AND si.date > ?)
              )
              AND (pi.is_consignment = 0 OR pi.is_consignment IS NULL)
        ");
        $qtyStmt->bind_param('iss', $entityId, $asOfDate, $asOfDate);
        $qtyStmt->execute();
        $qtyRow = db_stmt_to_assoc_array($qtyStmt);
        $asOfQty = (int)($qtyRow[0]['qty'] ?? 0);

        // امانی نزد دیگران: کالای ملکی که با فاکتور فروش امانی (consignment_out) نزد مشتری است.
        $outStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(pii.unitPrice), 0) as totalValue
            FROM inventory_items ii
            JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            WHERE pi.entity_id = ? AND pi.date <= ?
              AND ii.status = 'consignment_out'
              AND (pi.is_consignment = 0 OR pi.is_consignment IS NULL)
        ");
        $outStmt->bind_param('is', $entityId, $asOfDate);
        $outStmt->execute();
        $outRow = db_stmt_to_assoc_array($outStmt);
        $consignmentOutValue = (float)($outRow[0]['totalValue'] ?? 0);

        // امانی نزد ما: کالایی که با فاکتور خرید امانی (is_consignment=1) نزد ماست و هنوز فروخته/مرجوع نشده.
        $inStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(pii.unitPrice), 0) as totalValue
            FROM inventory_items ii
            JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
            JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            WHERE pi.entity_id = ? AND pi.date <= ?
              AND ii.status IN ('in_stock', 'returned')
              AND pi.is_consignment = 1
        ");
        $inStmt->bind_param('is', $entityId, $asOfDate);
        $inStmt->execute();
        $inRow = db_stmt_to_assoc_array($inStmt);
        $consignmentInValue = (float)($inRow[0]['totalValue'] ?? 0);

        return [
            'fifoValueAtClose' => $fifoValue,
            'ownValue' => $fifoValue,
            'consignmentInValue' => $consignmentInValue,
            'consignmentOutValue' => $consignmentOutValue,
            'totalReportValue' => $fifoValue,
            'ownQuantity' => $asOfQty,
            'totalQuantity' => $asOfQty,
            'inStockCount' => $asOfQty,
            'topItems' => [],
        ];
    }

    private function getPartnersSnapshot($entityId, $periodStart, $periodEnd, array $currentPartners) {
        $list = [];
        $positive = 0;
        $negative = 0;

        foreach ($currentPartners as $p) {
            $partnerId = (int)$p['id'];
            $balStmt = $this->conn->prepare("
                SELECT COALESCE(SUM(IF(transaction_type='receipt', amount, -amount)), 0) as balance
                FROM payments
                WHERE entity_id = ? AND person_type = 'partner' AND person_id = ? AND date <= ?
                  AND (description IS NULL OR description NOT LIKE 'CLOSING:%')
            ");
            $balStmt->bind_param('iis', $entityId, $partnerId, $periodEnd);
            $balStmt->execute();
            $balRow = db_stmt_to_assoc_array($balStmt);
            $capitalBalance = (float)($balRow[0]['balance'] ?? 0);

            $accStmt = $this->conn->prepare("
                SELECT a.id, a.name, a.initial_balance,
                       (a.initial_balance +
                        COALESCE((SELECT SUM(IF(p.transaction_type='receipt', p.amount, -p.amount))
                            FROM payments p
                            WHERE p.account_id = a.id AND p.type='cash' AND p.entity_id = ?
                              AND p.date <= ?), 0) -
                        COALESCE((SELECT SUM(e.amount) FROM expenses e
                            WHERE e.account_id = a.id AND e.entity_id = ?
                              AND e.date <= ?), 0) +
                        COALESCE((SELECT SUM(i.amount) FROM incomes i
                            WHERE i.account_id = a.id AND i.entity_id = ?
                              AND i.date <= ?), 0) +
                        COALESCE((SELECT SUM(IF(c.type='received', c.amount, -c.amount))
                            FROM checks c
                            WHERE c.cashed_in_account_id = a.id AND c.status='cashed' AND c.entity_id = ?
                              AND COALESCE(c.cashed_date, c.dueDate) <= ?), 0)
                       ) as as_of_balance
                FROM accounts a
                WHERE a.entity_id = ? AND a.partner_id = ?
                LIMIT 1
            ");
            $accStmt->bind_param('isisisisii', $entityId, $periodEnd, $entityId, $periodEnd, $entityId, $periodEnd, $entityId, $periodEnd, $entityId, $partnerId);
            $accStmt->execute();
            $accRow = db_stmt_to_assoc_array($accStmt);
            $accountBalance = (float)($accRow[0]['as_of_balance'] ?? 0);

            $depStmt = $this->conn->prepare("
                SELECT
                    COALESCE(SUM(IF(transaction_type='receipt', amount, 0)), 0) as deposits,
                    COALESCE(SUM(IF(transaction_type='payment', amount, 0)), 0) as withdrawals
                FROM payments
                WHERE entity_id = ? AND person_type = 'partner' AND person_id = ?
                  AND date BETWEEN ? AND ?
                  AND (description IS NULL OR description NOT LIKE 'CLOSING:%')
            ");
            $depStmt->bind_param('iiss', $entityId, $partnerId, $periodStart, $periodEnd);
            $depStmt->execute();
            $depRow = db_stmt_to_assoc_array($depStmt);
            $deposits = (float)($depRow[0]['deposits'] ?? 0);
            $withdrawals = (float)($depRow[0]['withdrawals'] ?? 0);

            if ($accountBalance > 0) {
                $positive += $accountBalance;
            } elseif ($accountBalance < 0) {
                $negative += $accountBalance;
            }

            $list[] = [
                'id' => $partnerId,
                'name' => $p['name'],
                'share' => (float)$p['share'],
                'capitalBalance' => $capitalBalance,
                'accountBalance' => $accountBalance,
                'periodDeposits' => $deposits,
                'periodWithdrawals' => $withdrawals,
                'accountId' => $accRow[0]['id'] ?? null,
                'accountName' => $accRow[0]['name'] ?? null,
            ];
        }

        return [
            'list' => $list,
            'totals' => [
                'positiveBalance' => round($positive, 2),
                'negativeBalance' => round($negative, 2),
            ],
        ];
    }

    private function getPeriodCashMovements($entityId, $periodStart, $periodEnd) {
        $stmt = $this->conn->prepare("
            SELECT
                COALESCE(SUM(IF(transaction_type='receipt' AND type='cash', amount, 0)), 0) as cashReceipts,
                COALESCE(SUM(IF(transaction_type='payment' AND type='cash', amount, 0)), 0) as cashPayments,
                COALESCE(SUM(IF(transaction_type='receipt' AND type <> 'setoff', amount, 0)), 0) as totalReceipts,
                COALESCE(SUM(IF(transaction_type='payment' AND type <> 'setoff', amount, 0)), 0) as totalPayments
            FROM payments
            WHERE entity_id = ? AND date BETWEEN ? AND ?
        ");
        $stmt->bind_param('iss', $entityId, $periodStart, $periodEnd);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? [];

        $expStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total FROM expenses
            WHERE entity_id = ? AND date BETWEEN ? AND ?
        ");
        $expStmt->bind_param('iss', $entityId, $periodStart, $periodEnd);
        $expStmt->execute();
        $expRow = db_stmt_to_assoc_array($expStmt);

        $incStmt = $this->conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total FROM incomes
            WHERE entity_id = ? AND date BETWEEN ? AND ?
        ");
        $incTotal = 0.0;
        if ($incStmt) {
            $incStmt->bind_param('iss', $entityId, $periodStart, $periodEnd);
            $incStmt->execute();
            $incRow = db_stmt_to_assoc_array($incStmt);
            $incTotal = (float)($incRow[0]['total'] ?? 0);
        }

        return [
            'cashReceipts' => (float)($row['cashReceipts'] ?? 0),
            'cashPayments' => (float)($row['cashPayments'] ?? 0),
            'totalReceipts' => (float)($row['totalReceipts'] ?? 0),
            'totalPayments' => (float)($row['totalPayments'] ?? 0),
            'expenses' => (float)($expRow[0]['total'] ?? 0),
            'incomes' => $incTotal,
        ];
    }

    private function buildOpeningEntry(
        $effectiveDate,
        $customers,
        $suppliers,
        $liquidity,
        $checks,
        $inventory,
        $partners,
        $equity,
        $netProfit,
        $vatNetPayable = 0.0
    ) {
        $lines = [];

        foreach ($customers['customers'] ?? [] as $c) {
            $amount = round((float)$c['receivable'], 2);
            if (abs($amount) < 0.01) {
                continue;
            }
            $isLiability = $amount < 0;
            $lines[] = [
                'side' => $isLiability ? 'liability' : 'asset',
                'category' => 'customer',
                'refId' => $c['id'],
                'name' => $c['name'],
                'amount' => abs($amount),
                'note' => $isLiability ? 'مانده بستانکاری مشتری' : 'مانده حساب مشتری',
            ];
        }

        foreach ($liquidity['allAccounts'] ?? [] as $a) {
            if (empty($a['inCashPosition'])) {
                continue;
            }
            if (abs($a['balance']) < 0.01) {
                continue;
            }
            $bal = (float)$a['balance'];
            $lines[] = [
                'side' => $bal >= 0 ? 'asset' : 'liability',
                'category' => $a['bankName'] ? 'bank' : 'cash',
                'refId' => $a['id'],
                'name' => $a['name'],
                'amount' => round(abs($bal), 2),
                'note' => $bal >= 0 ? 'مانده حساب بانکی/صندوق' : 'مانده منفی (اضافه‌برداشت)',
            ];
        }

        foreach ($checks['received'] ?? [] as $ch) {
            $lines[] = [
                'side' => 'asset',
                'category' => 'check_received',
                'refId' => $ch['id'],
                'name' => 'چک دریافتی ' . $ch['checkNumber'] . ($ch['personName'] ? ' - ' . $ch['personName'] : ''),
                'amount' => round((float)$ch['amount'], 2),
                'note' => 'سررسید: ' . $ch['dueDate'],
            ];
        }

        if (($inventory['ownValue'] ?? 0) > 0) {
            $lines[] = [
                'side' => 'asset',
                'category' => 'inventory',
                'refId' => null,
                'name' => 'موجودی انبار (ملکی)',
                'amount' => round((float)$inventory['ownValue'], 2),
                'note' => $inventory['ownQuantity'] . ' قلم کالا',
            ];
        }

        foreach ($suppliers['suppliers'] ?? [] as $s) {
            $amount = round((float)$s['debt'], 2);
            if (abs($amount) < 0.01) {
                continue;
            }
            $isAsset = $amount < 0;
            $lines[] = [
                'side' => $isAsset ? 'asset' : 'liability',
                'category' => 'supplier',
                'refId' => $s['id'],
                'name' => $s['name'],
                'amount' => abs($amount),
                'note' => $isAsset ? 'مانده بدهکار تأمین‌کننده' : 'بدهی به تأمین‌کننده',
            ];
        }

        foreach ($checks['payable'] ?? [] as $ch) {
            $lines[] = [
                'side' => 'liability',
                'category' => 'check_payable',
                'refId' => $ch['id'],
                'name' => 'چک پرداختنی ' . $ch['checkNumber'],
                'amount' => round((float)$ch['amount'], 2),
                'note' => 'سررسید: ' . $ch['dueDate'],
            ];
        }

        // مالیات بر ارزش افزوده خالص (فروش منهای خرید)؛ مثبت = بدهی، منفی = طلب.
        if (abs($vatNetPayable) >= 0.01) {
            $lines[] = [
                'side' => $vatNetPayable > 0 ? 'liability' : 'asset',
                'category' => 'vat',
                'refId' => null,
                'name' => $vatNetPayable > 0 ? 'بدهی مالیات بر ارزش افزوده' : 'طلب مالیات بر ارزش افزوده',
                'amount' => round(abs($vatNetPayable), 2),
                'note' => 'خالص مالیات فروش منهای مالیات خرید',
            ];
        }

        // Partner personal accounts are memo-only (not opening BS lines); equity line covers capital.

        $lines[] = [
            'side' => 'equity',
            'category' => 'capital',
            'refId' => null,
            'name' => 'سرمایه و اندوخته (خالص دارایی‌ها)',
            'amount' => round($equity, 2),
            'note' => 'سود خالص دوره (تحلیلی): ' . round($netProfit, 2),
        ];

        $assetTotal = 0;
        $liabilityTotal = 0;
        $equityTotal = 0;
        foreach ($lines as $ln) {
            if ($ln['side'] === 'asset') {
                $assetTotal += $ln['amount'];
            } elseif ($ln['side'] === 'liability') {
                $liabilityTotal += $ln['amount'];
            } else {
                $equityTotal += $ln['amount'];
            }
        }

        return [
            'effectiveDate' => $effectiveDate,
            'title' => 'سند افتتاحیه دوره جدید از ' . $effectiveDate,
            'lines' => $lines,
            'totals' => [
                'assets' => round($assetTotal, 2),
                'liabilities' => round($liabilityTotal, 2),
                'equity' => round($equityTotal, 2),
            ],
        ];
    }

    public function buildValidationWarnings(array $snapshot) {
        $warnings = [];

        if (!empty($snapshot['liquidity']['discrepancies'])) {
            $count = count($snapshot['liquidity']['discrepancies']);
            $warnings[] = [
                'level' => 'warning',
                'message' => "{$count} حساب بانکی/صندوق مغایرت مانده دارند. قبل از بستن، ابزار تطبیق حساب‌ها را بررسی کنید.",
            ];
        }

        $diff = (float)($snapshot['balanceSheet']['difference'] ?? 0);
        if (abs($diff) >= 1) {
            $level = abs($diff) >= 1000 ? 'error' : 'warning';
            $warnings[] = [
                'level' => $level,
                'message' => 'اختلاف ترازنامه: ' . number_format($diff) . ' تومان '
                    . '(دارایی منهای بدهی منهای سرمایه). معادله تراز برقرار نیست؛ '
                    . 'نقدینگی، چک‌ها و مطالبات را بازبینی کنید.',
            ];
        }

        $unallocated = (float)($snapshot['capital']['unallocatedEarnings'] ?? $snapshot['balanceSheet']['equity']['unallocatedEarnings'] ?? 0);
        $netProfit = (float)($snapshot['capital']['netProfit'] ?? 0);
        if (abs($unallocated - $netProfit) >= 1000) {
            $warnings[] = [
                'level' => 'warning',
                'message' => 'اندوخته تخصیص‌نیافته (' . number_format($unallocated)
                    . ' تومان) با سود همین دوره (' . number_format($netProfit)
                    . ' تومان) فرق دارد؛ معمولاً اثر موجودی/سود دوره‌های قبل است و مانع بستن نیست.',
            ];
        }

        if (empty($snapshot['partners']['list'])) {
            $warnings[] = [
                'level' => 'error',
                'message' => 'هیچ شریکی برای تسهیم سود تعریف نشده است.',
            ];
        }

        return $warnings;
    }
}
