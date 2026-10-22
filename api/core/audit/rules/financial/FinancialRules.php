<?php
// api/core/audit/rules/financial/FinancialRules.php

require_once __DIR__ . '/../../AbstractRule.php';
require_once __DIR__ . '/../../../../models/Account.php';

class FinancialAuditRules {
    public static function create($db, int $entityId): array {
        return [
            new FinInvoiceTotalRule($db, $entityId),
            new FinPaymentMismatchRule($db, $entityId),
            new FinOverpaymentRule($db, $entityId),
            new FinNegativeInvoiceRule($db, $entityId),
            new FinOverdueUnsettledInvoiceRule($db, $entityId),
            new FinAccountBalanceRule($db, $entityId),
            new FinOverdueCheckRule($db, $entityId),
            new FinDuplicateCheckRule($db, $entityId),
            new FinCheckPaymentIntegrityRule($db, $entityId),
            new FinLegacyPosRule($db, $entityId),
        ];
    }
}

class FinInvoiceTotalRule extends AbstractAuditRule {
    public function getId(): string { return 'financial_discrepancy'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'مغایرت جمع فاکتور'; }

    public function run(): array {
        $issues = [];
        foreach ([
            ['sales_invoices', 'sales_invoice_items', 'sales-invoices-tab'],
            ['purchase_invoices', 'purchase_invoice_items', 'purchase-invoices-tab'],
        ] as [$inv, $items, $tab]) {
            // totalAmount in this system is sum of line items (discount/tax stored separately)
            $q = "
                SELECT i.id, i.totalAmount, i.discount, COALESCE(i.tax_amount,0) as tax_amount,
                       COALESCE(SUM(ii.quantity * ii.unitPrice),0) as calculated_total
                FROM {$inv} i
                LEFT JOIN {$items} ii ON i.id = ii.invoiceId
                WHERE i.entity_id = ?
                GROUP BY i.id
                HAVING ABS(i.totalAmount - calculated_total) > 1.00
            ";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $net = (float)$row['totalAmount'] - (float)$row['discount'] + (float)$row['tax_amount'];
                $issues[] = $this->issue([
                    'severity' => 'critical',
                    'message' => "مغایرت در جمع کل فاکتور #{$row['id']} ({$inv})",
                    'details' => 'مبلغ اقلام ثبت‌شده: ' . number_format($row['totalAmount']) .
                        ' | محاسبه از ردیف‌ها: ' . number_format($row['calculated_total']) .
                        ' | خالص با تخفیف/مالیات: ' . number_format($net),
                    'reference_table' => $inv,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'safe_auto',
                    'remediation_action' => 'fix_invoice_total',
                    'meta' => [
                        'stored_total' => (float)$row['totalAmount'],
                        'calculated_total' => (float)$row['calculated_total'],
                        'discount' => (float)$row['discount'],
                        'tax_amount' => (float)$row['tax_amount'],
                    ],
                    'nav' => ['tab' => $tab, 'table' => $inv, 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class FinPaymentMismatchRule extends AbstractAuditRule {
    public function getId(): string { return 'payment_mismatch'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'مغایرت مبلغ پرداختی'; }

    public function run(): array {
        $issues = [];
        foreach ([
            ['sales_invoices', 'sales', 'sales-invoices-tab'],
            ['purchase_invoices', 'purchase', 'purchase-invoices-tab'],
        ] as [$inv, $type, $tab]) {
            $paidExpr = $type === 'sales'
                ? "COALESCE(SUM(IF(p.transaction_type = 'receipt', p.amount, -p.amount)),0)"
                : "COALESCE(SUM(IF(p.transaction_type = 'payment', p.amount, -p.amount)),0)";
            $q = "
                SELECT i.id, i.paidAmount, {$paidExpr} as calculated_paid
                FROM {$inv} i
                LEFT JOIN payments p ON p.invoiceId = i.id AND p.invoiceType = ? AND p.entity_id = ?
                WHERE i.entity_id = ?
                GROUP BY i.id
                HAVING ABS(i.paidAmount - calculated_paid) > 1.00
            ";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('sii', $type, $this->entityId, $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'warning',
                    'message' => "مغایرت در مبلغ پرداختی فاکتور #{$row['id']} ({$inv})",
                    'details' => 'پرداختی فاکتور: ' . number_format($row['paidAmount']) .
                        ' | جمع پرداخت‌ها: ' . number_format($row['calculated_paid']),
                    'reference_table' => $inv,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'safe_auto',
                    'remediation_action' => 'fix_invoice_paid_amount',
                    'nav' => ['tab' => $tab, 'table' => $inv, 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class FinOverpaymentRule extends AbstractAuditRule {
    public function getId(): string { return 'overpayment'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'پرداخت مازاد'; }

    public function run(): array {
        $issues = [];
        foreach (['sales_invoices' => 'sales-invoices-tab', 'purchase_invoices' => 'purchase-invoices-tab'] as $inv => $tab) {
            // Compare against payable amount (total - discount + tax)
            $q = "
                SELECT id, totalAmount, discount, COALESCE(tax_amount,0) as tax_amount, paidAmount,
                       (totalAmount - discount + COALESCE(tax_amount,0)) as payable
                FROM {$inv}
                WHERE entity_id = ?
                  AND paidAmount > (totalAmount - discount + COALESCE(tax_amount,0) + 1)
            ";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $diff = (float)$row['paidAmount'] - (float)$row['payable'];
                $issues[] = $this->issue([
                    'severity' => 'info',
                    'message' => "پرداخت مازاد برای فاکتور #{$row['id']} ({$inv})",
                    'details' => 'قابل پرداخت: ' . number_format($row['payable']) .
                        ' | پرداختی: ' . number_format($row['paidAmount']) .
                        ' (مازاد: ' . number_format($diff) . ')',
                    'reference_table' => $inv,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'manual_review',
                    'nav' => ['tab' => $tab, 'table' => $inv, 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class FinNegativeInvoiceRule extends AbstractAuditRule {
    public function getId(): string { return 'negative_total_invoice'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'فاکتور با مبلغ نامعتبر'; }

    public function run(): array {
        $issues = [];
        foreach (['sales_invoices' => 'sales-invoices-tab', 'purchase_invoices' => 'purchase-invoices-tab'] as $inv => $tab) {
            $q = "
                SELECT id, totalAmount, discount
                FROM {$inv}
                WHERE entity_id = ?
                  AND (totalAmount <= 0 OR (totalAmount - discount) <= 0)
            ";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'warning',
                    'message' => "فاکتور #{$row['id']} با مبلغ صفر/منفی ({$inv})",
                    'details' => 'totalAmount=' . number_format($row['totalAmount']) . ' | discount=' . number_format($row['discount']),
                    'reference_table' => $inv,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'manual_review',
                    'nav' => ['tab' => $tab, 'table' => $inv, 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class FinOverdueUnsettledInvoiceRule extends AbstractAuditRule {
    public function getId(): string { return 'overdue_unsettled_invoice'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'فاکتور تسویه‌نشده قدیمی'; }

    public function run(): array {
        $issues = [];
        $overdueDays = defined('INVOICE_OVERDUE_DAYS') ? INVOICE_OVERDUE_DAYS : 30;
        $overdueDate = date('Y-m-d', strtotime("-{$overdueDays} days"));
        require_once __DIR__ . '/../../../../models/ReportModel.php';
        $report = new ReportModel($this->db);
        foreach ($report->getOverdueUnsettledSalesInvoices($this->entityId, $overdueDate) as $row) {
            if ($row['remaining'] <= 1) continue;
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "فاکتور فروش تسویه‌نشده قدیمی #{$row['id']}",
                'details' => "تاریخ {$row['date']} | مانده: " . number_format($row['remaining']),
                'reference_table' => 'sales_invoices',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['tab' => 'sales-invoices-tab', 'table' => 'sales_invoices', 'reference_id' => $row['id']],
            ]);
        }
        return $issues;
    }
}

class FinAccountBalanceRule extends AbstractAuditRule {
    public function getId(): string { return 'account_balance_mismatch'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'مغایرت موجودی حساب'; }

    public function run(): array {
        $issues = [];
        $accountModel = new Account($this->db);
        $stmt = $this->db->prepare("SELECT id, name, current_balance FROM accounts WHERE entity_id = ?");
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $account) {
            $ledgerData = $accountModel->getLedger($account['id']);
            if (isset($ledgerData['error'])) continue;
            $calculated = (float)$ledgerData['account']['initial_balance'];
            foreach ($ledgerData['transactions'] ?? [] as $tx) {
                if (isset($tx['is_consignment']) && $tx['is_consignment'] == 1) continue;
                $amount = (float)$tx['amount'];
                $source = $tx['source'] ?? '';
                if (str_ends_with($source, '_out') || $source === 'expense') {
                    $calculated -= $amount;
                } else {
                    $calculated += $amount;
                }
            }
            $calculated = round($calculated, 2);
            $current = round((float)$account['current_balance'], 2);
            if (abs($calculated - $current) > 1.00) {
                $issues[] = $this->issue([
                    'severity' => 'critical',
                    'message' => "مغایرت موجودی در حساب «{$account['name']}»",
                    'details' => 'موجودی فعلی: ' . number_format($current) . ' | موجودی واقعی: ' . number_format($calculated),
                    'reference_table' => 'accounts',
                    'reference_id' => $account['id'],
                    'remediation_class' => 'safe_auto',
                    'remediation_action' => 'fix_account_balance',
                    'meta' => ['stored' => $current, 'calculated' => $calculated],
                    'nav' => ['center_panel' => 'balance', 'tab' => 'accounts-tab', 'reference_id' => $account['id']],
                ]);
            }
        }
        return $issues;
    }
}

class FinOverdueCheckRule extends AbstractAuditRule {
    public function getId(): string { return 'overdue_check'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'چک سررسید گذشته'; }

    public function run(): array {
        $issues = [];
        $today = date('Y-m-d');
        $q = "SELECT id, checkNumber, dueDate, amount, type FROM checks
              WHERE entity_id = ? AND status IN ('in_hand','payable') AND dueDate < ?";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('is', $this->entityId, $today);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $typeStr = $row['type'] == 'received' ? 'دریافتی' : 'پرداختی';
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "چک {$typeStr} سررسید گذشته #{$row['checkNumber']}",
                'details' => "سررسید: {$row['dueDate']} | مبلغ: " . number_format($row['amount']),
                'reference_table' => 'checks',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
            ]);
        }
        return $issues;
    }
}

class FinDuplicateCheckRule extends AbstractAuditRule {
    public function getId(): string { return 'duplicate_check'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'شماره چک تکراری'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT checkNumber, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
              FROM checks WHERE entity_id = ? GROUP BY checkNumber HAVING cnt > 1";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $ids = explode(',', $row['ids']);
            $issues[] = $this->issue([
                'severity' => 'critical',
                'message' => "شماره چک تکراری: {$row['checkNumber']}",
                'details' => "تعداد {$row['cnt']} — شناسه‌ها: {$row['ids']}",
                'reference_table' => 'checks',
                'reference_id' => $ids[0],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'meta' => ['duplicate_ids' => $ids],
            ]);
        }
        return $issues;
    }
}

class FinCheckPaymentIntegrityRule extends AbstractAuditRule {
    public function getId(): string { return 'check_payment_integrity'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'یکپارچگی پرداخت چک'; }

    public function run(): array {
        $issues = [];

        // An endorsed received check must be backed by at least ONE payment
        // (type='check' or type='endorse_check'). Requiring each type
        // separately made the two sub-rules contradict each other and kept
        // re-flagging legitimately endorsed checks.
        $q1 = "SELECT c.id, c.checkNumber FROM checks c
               LEFT JOIN payments p ON c.id = p.checkId AND p.type IN ('check','endorse_check')
               WHERE c.entity_id = ? AND c.status = 'endorsed' AND c.type = 'received' AND p.id IS NULL";
        $stmt = $this->db->prepare($q1);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'missing_endorse_payment',
                'severity' => 'warning',
                'title' => 'چک واگذار شده بدون تراکنش',
                'message' => "چک واگذار شده بدون تراکنش مرتبط: #{$row['checkNumber']}",
                'details' => 'وضعیت endorsed است ولی هیچ پرداخت check/endorse_check به این چک وصل نیست.',
                'reference_table' => 'checks',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
            ]);
        }

        $q3 = "SELECT p.id, p.type FROM payments p
               LEFT JOIN checks c ON p.checkId = c.id
               WHERE p.entity_id = ? AND p.type IN ('check','endorse_check') AND (p.checkId IS NULL OR c.id IS NULL)";
        $stmt3 = $this->db->prepare($q3);
        $stmt3->bind_param('i', $this->entityId);
        $stmt3->execute();
        foreach (db_stmt_to_assoc_array($stmt3) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'orphan_check_payment',
                'severity' => 'critical',
                'title' => 'تراکنش چکی یتیم',
                'message' => "تراکنش چکی یتیم #{$row['id']}",
                'details' => "پرداخت {$row['type']} به چک معتبر وصل نیست.",
                'reference_table' => 'payments',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
            ]);
        }

        $q4 = "SELECT id, checkNumber FROM checks
               WHERE entity_id = ? AND status = 'cashed' AND cashed_in_account_id IS NULL";
        $stmt4 = $this->db->prepare($q4);
        $stmt4->bind_param('i', $this->entityId);
        $stmt4->execute();
        foreach (db_stmt_to_assoc_array($stmt4) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'cashed_check_missing_account',
                'severity' => 'critical',
                'title' => 'چک وصول‌شده بدون حساب',
                'message' => "چک وصول‌شده بدون حساب مقصد #{$row['checkNumber']}",
                'details' => 'cashed_in_account_id خالی است.',
                'reference_table' => 'checks',
                'reference_id' => $row['id'],
                'remediation_class' => 'guided',
                'remediation_action' => 'fix_check_account_link',
            ]);
        }

        return $issues;
    }
}

class FinLegacyPosRule extends AbstractAuditRule {
    public function getId(): string { return 'legacy_pos_payment'; }
    public function getCategory(): string { return 'financial'; }
    public function getTitle(): string { return 'تراکنش POS قدیمی'; }

    public function run(): array {
        $issues = [];
        $stmt = $this->db->prepare("SELECT id, amount, date FROM payments WHERE entity_id = ? AND type = 'pos'");
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "تراکنش POS قدیمی #{$row['id']}",
                'details' => 'مبلغ ' . number_format($row['amount']) . " | تاریخ {$row['date']}",
                'reference_table' => 'payments',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
            ]);
        }
        return $issues;
    }
}
