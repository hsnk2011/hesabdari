<?php
// api/core/audit/rules/system/SystemRules.php

require_once __DIR__ . '/../../AbstractRule.php';

class SystemAuditRules {
    public static function create($db, int $entityId): array {
        return [
            new SysOrphanRule($db, $entityId),
            new SysFutureDateRule($db, $entityId),
            new SysSettingsRule($db, $entityId),
            new SysDuplicateDataRule($db, $entityId),
        ];
    }
}

class SysOrphanRule extends AbstractAuditRule {
    public function getId(): string { return 'orphaned_record'; }
    public function getCategory(): string { return 'system'; }
    public function getTitle(): string { return 'رکورد یتیم'; }

    public function run(): array {
        $issues = [];

        // Payments pointing to missing invoices (entity-scoped)
        foreach ([
            ['sales', 'sales_invoices'],
            ['purchase', 'purchase_invoices'],
            ['asset_purchase', 'asset_documents'],
            ['asset_sale', 'asset_documents'],
        ] as [$type, $parent]) {
            $q = "SELECT p.id FROM payments p
                  LEFT JOIN {$parent} inv ON inv.id = p.invoiceId
                  WHERE p.entity_id = ? AND p.invoiceId IS NOT NULL AND p.invoiceType = ?
                    AND inv.id IS NULL";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('is', $this->entityId, $type);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'warning',
                    'message' => "پرداخت یتیم #{$row['id']} (فاکتور {$type} حذف شده)",
                    'details' => "ارجاع به {$parent} نامعتبر است.",
                    'reference_table' => 'payments',
                    'reference_id' => $row['id'],
                    'remediation_class' => 'safe_auto',
                    'remediation_action' => 'delete_orphan',
                ]);
            }
        }

        // Invoice items without parent invoice
        foreach ([
            ['sales_invoice_items', 'sales_invoices', 'critical'],
            ['purchase_invoice_items', 'purchase_invoices', 'critical'],
        ] as [$child, $parent, $sev]) {
            $q = "SELECT c.id FROM {$child} c
                  LEFT JOIN {$parent} p ON p.id = c.invoiceId
                  WHERE c.invoiceId IS NOT NULL AND p.id IS NULL";
            $result = $this->db->query($q);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $issues[] = $this->issue([
                        'severity' => $sev,
                        'message' => "ردیف یتیم در {$child} #{$row['id']}",
                        'details' => "فاکتور والد در {$parent} وجود ندارد.",
                        'reference_table' => $child,
                        'reference_id' => $row['id'],
                        'remediation_class' => 'safe_auto',
                        'remediation_action' => 'delete_orphan',
                        'meta' => ['scope_note' => 'global_orphan_item'],
                    ]);
                }
            }
        }

        // Inventory items with missing product (entity via product join failure)
        $qInv = "SELECT ii.id FROM inventory_items ii
                 LEFT JOIN products p ON p.id = ii.product_id
                 WHERE ii.product_id IS NOT NULL AND p.id IS NULL";
        // Prefer entity-scoped inventory if column exists
        $hasEntity = false;
        $col = $this->db->query("SHOW COLUMNS FROM inventory_items LIKE 'entity_id'");
        if ($col && $col->num_rows > 0) $hasEntity = true;
        if ($hasEntity) {
            $qInv = "SELECT ii.id FROM inventory_items ii
                     LEFT JOIN products p ON p.id = ii.product_id
                     WHERE ii.entity_id = ? AND ii.product_id IS NOT NULL AND p.id IS NULL";
            $stmt = $this->db->prepare($qInv);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
        } else {
            $result = $this->db->query($qInv);
            $rows = [];
            if ($result) while ($r = $result->fetch_assoc()) $rows[] = $r;
        }
        foreach ($rows as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "آیتم انبار یتیم #{$row['id']} (محصول حذف شده)",
                'details' => 'product_id به محصول موجود اشاره نمی‌کند.',
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'delete_orphan',
            ]);
        }

        return $issues;
    }
}

class SysFutureDateRule extends AbstractAuditRule {
    public function getId(): string { return 'future_date'; }
    public function getCategory(): string { return 'system'; }
    public function getTitle(): string { return 'تاریخ آینده نامتعارف'; }

    public function run(): array {
        $issues = [];
        $threshold = date('Y-m-d', strtotime('+1 year'));
        $tables = [
            'sales_invoices' => 'date',
            'purchase_invoices' => 'date',
            'payments' => 'date',
            'checks' => 'dueDate',
            'expenses' => 'date',
        ];
        foreach ($tables as $table => $field) {
            $q = "SELECT id, {$field} as date_val FROM {$table} WHERE entity_id = ? AND {$field} > ?";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('is', $this->entityId, $threshold);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'info',
                    'message' => "تاریخ نامتعارف در {$table} #{$row['id']}",
                    'details' => "تاریخ: {$row['date_val']}",
                    'reference_table' => $table,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'review_date',
                ]);
            }
        }
        return $issues;
    }
}

class SysSettingsRule extends AbstractAuditRule {
    public function getId(): string { return 'missing_setting'; }
    public function getCategory(): string { return 'system'; }
    public function getTitle(): string { return 'تنظیمات ناقص'; }

    public function run(): array {
        $issues = [];
        // Align with actual keys used by the app
        $required = ['app_title'];
        $found = [];
        $result = $this->db->query("SELECT setting_key FROM settings WHERE setting_key IN ('app_title','app_name','currency_symbol')");
        if ($result) {
            while ($row = $result->fetch_assoc()) $found[] = $row['setting_key'];
        }
        if (!in_array('app_title', $found, true) && !in_array('app_name', $found, true)) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => 'عنوان برنامه در تنظیمات یافت نشد',
                'details' => 'کلید app_title / app_name موجود نیست.',
                'reference_table' => 'settings',
                'reference_id' => 'app_title',
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'configure_setting',
                'nav' => ['settings_sub' => 'general-settings-pane'],
            ]);
        }
        return $issues;
    }
}

class SysDuplicateDataRule extends AbstractAuditRule {
    public function getId(): string { return 'duplicate_data'; }
    public function getCategory(): string { return 'system'; }
    public function getTitle(): string { return 'داده تکراری مشکوک'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT amount, date, person_id, person_type, type, transaction_type, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
              FROM payments WHERE entity_id = ?
              GROUP BY amount, date, person_id, person_type, type, transaction_type
              HAVING cnt > 1";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $ids = explode(',', $row['ids']);
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => 'تراکنش‌های تکراری شناسایی شد',
                'details' => "{$row['cnt']} مورد با مبلغ " . number_format($row['amount']) . " در {$row['date']} — {$row['ids']}",
                'reference_table' => 'payments',
                'reference_id' => $ids[0],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'review_duplicates',
                'meta' => ['duplicate_ids' => $ids],
            ]);
        }

        $q2 = "SELECT amount, date, category, description, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
               FROM expenses WHERE entity_id = ?
               GROUP BY amount, date, category, description HAVING cnt > 1";
        $stmt2 = $this->db->prepare($q2);
        $stmt2->bind_param('i', $this->entityId);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $ids = explode(',', $row['ids']);
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => 'هزینه‌های تکراری شناسایی شد',
                'details' => "{$row['cnt']} مورد با مبلغ " . number_format($row['amount']) . " در {$row['date']}",
                'reference_table' => 'expenses',
                'reference_id' => $ids[0],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'review_duplicates',
                'meta' => ['duplicate_ids' => $ids],
            ]);
        }

        $q3 = "SELECT amount, date, category, description, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
               FROM incomes WHERE entity_id = ?
               GROUP BY amount, date, category, description HAVING cnt > 1";
        $stmt3 = $this->db->prepare($q3);
        if ($stmt3) {
            $stmt3->bind_param('i', $this->entityId);
            $stmt3->execute();
            foreach (db_stmt_to_assoc_array($stmt3) as $row) {
                $ids = explode(',', $row['ids']);
                $issues[] = $this->issue([
                    'severity' => 'warning',
                    'message' => 'درآمدهای تکراری شناسایی شد',
                    'details' => "{$row['cnt']} مورد با مبلغ " . number_format($row['amount']) . " در {$row['date']}",
                    'reference_table' => 'incomes',
                    'reference_id' => $ids[0],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'review_duplicates',
                    'meta' => ['duplicate_ids' => $ids],
                ]);
            }
        }
        return $issues;
    }
}
