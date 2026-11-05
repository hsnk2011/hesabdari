<?php
// api/core/audit/rules/operations/OperationsRules.php

require_once __DIR__ . '/../../AbstractRule.php';

class OperationsAuditRules {
    public static function create($db, int $entityId): array {
        return [
            new OpsConsignmentRule($db, $entityId),
            new OpsInstallmentRule($db, $entityId),
            new OpsBankImportRule($db, $entityId),
            new OpsPeriodLockRule($db, $entityId),
            new OpsReturnInvoiceRule($db, $entityId),
        ];
    }
}

class OpsConsignmentRule extends AbstractAuditRule {
    public function getId(): string { return 'consignment_integrity'; }
    public function getCategory(): string { return 'operations'; }
    public function getTitle(): string { return 'یکپارچگی امانی'; }

    public function run(): array {
        $issues = [];
        // consignment_out without sales link already covered; check status vs consignment invoice flag
        $q = "SELECT ii.id, p.name, ii.status, si.is_consignment,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON p.id = ii.product_id
              LEFT JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
              LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
              WHERE p.entity_id = ?
                AND ii.status = 'consignment_out'
                AND (si.id IS NULL OR COALESCE(si.is_consignment,0) = 0)";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "وضعیت امانی خروج ناسازگار: {$row['name']}",
                'details' => "{$row['serial_label']} وضعیت consignment_out دارد ولی به فاکتور امانی فروش وصل نیست.",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['tab' => 'consignment-tab', 'reference_id' => $row['id']],
            ]);
        }

        // Purchase consignment items marked sold oddly
        $q2 = "SELECT ii.id, p.name,
                      COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
               FROM inventory_items ii
               JOIN products p ON p.id = ii.product_id
               JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
               JOIN purchase_invoices pi ON pi.id = pii.invoiceId
               WHERE p.entity_id = ?
                 AND COALESCE(pi.is_consignment,0) = 1
                 AND ii.status = 'sold'
                 AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id = 0)";
        $stmt2 = $this->db->prepare($q2);
        $stmt2->bind_param('i', $this->entityId);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $issues[] = $this->issue([
                'severity' => 'critical',
                'message' => "کالای امانی ورودی با وضعیت sold یتیم: {$row['name']}",
                'details' => $row['serial_label'],
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'delete_orphan_sold',
                'nav' => ['tab' => 'consignment-tab', 'reference_id' => $row['id']],
            ]);
        }

        $q3 = "SELECT pi.id, s.name AS supplier_name, COUNT(ii.id) AS sold_count
               FROM inventory_items ii
               JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
               JOIN purchase_invoices pi ON pi.id = pii.invoiceId
               LEFT JOIN suppliers s ON s.id = pi.supplierId
               LEFT JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
               LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
               WHERE pi.entity_id = ?
                 AND COALESCE(pi.is_consignment,0) = 1
                 AND ii.status IN ('sold','returned')
                 AND ii.sales_invoice_item_id IS NOT NULL
                 AND ii.sales_invoice_item_id <> 0
                 AND si.id IS NOT NULL
                 AND COALESCE(si.is_consignment,0) = 0
               GROUP BY pi.id, s.name";
        $stmt3 = $this->db->prepare($q3);
        $stmt3->bind_param('i', $this->entityId);
        $stmt3->execute();
        foreach (db_stmt_to_assoc_array($stmt3) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'consignment_sold_not_converted',
                'severity' => 'warning',
                'title' => 'فروش از فاکتور امانی تبدیل‌نشده',
                'message' => "فاکتور امانی خرید #{$row['id']} — {$row['supplier_name']}",
                'details' => "{$row['sold_count']} قلم فروخته شده هنوز روی فاکتور امانی است. از تب محصولات امانی «تبدیل اقلام فروخته‌شده» را بزنید.",
                'reference_table' => 'purchase_invoices',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['tab' => 'consignment-tab', 'reference_id' => $row['id']],
            ]);
        }
        return $issues;
    }
}

class OpsInstallmentRule extends AbstractAuditRule {
    public function getId(): string { return 'installment_overdue'; }
    public function getCategory(): string { return 'operations'; }
    public function getTitle(): string { return 'اقساط معوق / ناسازگار'; }

    public function run(): array {
        $issues = [];
        if (!$this->tableExists('installments')) return $issues;
        $today = date('Y-m-d');

        $q = "SELECT id, invoice_id, invoice_type, installment_number, amount, paid_amount, due_date, status
              FROM installments
              WHERE entity_id = ?
                AND status IN ('pending','partially_paid','overdue')
                AND due_date < ?
                AND (amount - COALESCE(paid_amount,0)) > 1";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('is', $this->entityId, $today);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $remain = (float)$row['amount'] - (float)$row['paid_amount'];
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "قسط معوق #{$row['installment_number']} فاکتور {$row['invoice_type']} #{$row['invoice_id']}",
                'details' => "سررسید {$row['due_date']} | مانده " . number_format($remain) . " | وضعیت {$row['status']}",
                'reference_table' => 'installments',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['settings_sub' => 'installments-pane', 'center_panel' => 'results'],
            ]);
        }

        $q2 = "SELECT id, amount, paid_amount, status FROM installments
               WHERE entity_id = ?
                 AND COALESCE(paid_amount,0) > amount + 1";
        $stmt2 = $this->db->prepare($q2);
        $stmt2->bind_param('i', $this->entityId);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'installment_amount_mismatch',
                'severity' => 'warning',
                'title' => 'مبلغ قسط ناسازگار',
                'message' => "پرداخت قسط بیش از مبلغ قسط #{$row['id']}",
                'details' => 'مبلغ: ' . number_format($row['amount']) . ' | پرداختی: ' . number_format($row['paid_amount']),
                'reference_table' => 'installments',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['settings_sub' => 'installments-pane'],
            ]);
        }
        return $issues;
    }
}

class OpsBankImportRule extends AbstractAuditRule {
    public function getId(): string { return 'bank_import_unmatched'; }
    public function getCategory(): string { return 'operations'; }
    public function getTitle(): string { return 'ردیف‌های بانک تطبیق‌نشده'; }

    public function run(): array {
        $issues = [];
        if (!$this->tableExists('bank_import_items') || !$this->tableExists('bank_imports')) return $issues;

        $q = "SELECT bii.id, bi.id as import_id, bi.file_name, bii.transaction_date, bii.amount, bii.description
              FROM bank_import_items bii
              JOIN bank_imports bi ON bi.id = bii.import_id
              WHERE bi.entity_id = ? AND bii.match_status = 'unmatched'
              ORDER BY bii.transaction_date DESC
              LIMIT 200";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'info',
                'message' => "ردیف بانک تطبیق‌نشده در «{$row['file_name']}»",
                'details' => "{$row['transaction_date']} | " . number_format($row['amount']) . ' | ' . mb_substr((string)$row['description'], 0, 80),
                'reference_table' => 'bank_import_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['settings_sub' => 'bank-import-pane', 'center_panel' => 'results'],
                'meta' => ['import_id' => $row['import_id']],
            ]);
        }
        return $issues;
    }
}

class OpsPeriodLockRule extends AbstractAuditRule {
    public function getId(): string { return 'period_lock_violation'; }
    public function getCategory(): string { return 'operations'; }
    public function getTitle(): string { return 'نقض قفل دوره'; }

    public function run(): array {
        $issues = [];
        if (!$this->tableExists('account_closings')) return $issues;

        $q = "SELECT id, period_start_date AS ps, period_end_date AS pe, closed_at, status
              FROM account_closings
              WHERE entity_id = ? AND status = 'completed'
              ORDER BY period_end_date DESC LIMIT 3";
        $stmt = $this->db->prepare($q);
        if (!$stmt) return $issues;
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        $closings = db_stmt_to_assoc_array($stmt);

        $hasCreatedAt = false;
        $col = $this->db->query("SHOW COLUMNS FROM payments LIKE 'created_at'");
        if ($col && $col->num_rows > 0) $hasCreatedAt = true;

        foreach ($closings as $closing) {
            $ps = $closing['ps'];
            $pe = $closing['pe'];
            $closedAt = $closing['closed_at'] ?? null;
            if (!$ps || !$pe || !$closedAt) continue;

            if ($hasCreatedAt) {
                $qPay = "SELECT id, date, amount, created_at FROM payments
                         WHERE entity_id = ? AND date >= ? AND date <= ?
                           AND created_at > ?
                         LIMIT 30";
                $stmtP = $this->db->prepare($qPay);
                $stmtP->bind_param('isss', $this->entityId, $ps, $pe, $closedAt);
            } else {
                // Without created_at we cannot reliably detect post-lock inserts; skip flood
                continue;
            }
            $stmtP->execute();
            foreach (db_stmt_to_assoc_array($stmtP) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'critical',
                    'message' => "تراکنش پس از بستن دوره: پرداخت #{$row['id']}",
                    'details' => "تاریخ سند {$row['date']} در بازه قفل {$ps}..{$pe} ولی ایجاد پس از بستن ({$closedAt})",
                    'reference_table' => 'payments',
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'manual_review',
                    'meta' => ['closing_id' => $closing['id'], 'period_start' => $ps, 'period_end' => $pe],
                    'nav' => ['settings_sub' => 'account-closing-pane', 'tab' => 'transactions-tab', 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class OpsReturnInvoiceRule extends AbstractAuditRule {
    public function getId(): string { return 'return_invoice_integrity'; }
    public function getCategory(): string { return 'operations'; }
    public function getTitle(): string { return 'یکپارچگی فاکتور مرجوعی'; }

    public function run(): array {
        $issues = [];
        if (!$this->tableExists('return_invoices')) return $issues;

        $q = "SELECT r.id, r.type, r.original_invoice_id, r.original_invoice_type
              FROM return_invoices r
              WHERE r.entity_id = ?";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $parent = $row['original_invoice_type'] === 'sales' ? 'sales_invoices' : 'purchase_invoices';
            $chk = $this->db->prepare("SELECT id FROM {$parent} WHERE id = ? AND entity_id = ? LIMIT 1");
            $oid = (int)$row['original_invoice_id'];
            $chk->bind_param('ii', $oid, $this->entityId);
            $chk->execute();
            if (empty(db_stmt_to_assoc_array($chk))) {
                $issues[] = $this->issue([
                    'severity' => 'critical',
                    'message' => "مرجوعی #{$row['id']} به فاکتور نامعتبر اشاره می‌کند",
                    'details' => "{$row['original_invoice_type']} #{$row['original_invoice_id']} یافت نشد.",
                    'reference_table' => 'return_invoices',
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'manual_review',
                    'nav' => ['tab' => 'return-invoices-tab', 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}
