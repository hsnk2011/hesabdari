<?php
// api/core/audit/rules/entity/EntityRules.php

require_once __DIR__ . '/../../AbstractRule.php';

class EntityAuditRules {
    public static function create($db, int $entityId): array {
        return [
            new EntDuplicateNameRule($db, $entityId),
            new EntInvalidCodeRule($db, $entityId),
            new EntLockedUserRule($db, $entityId),
        ];
    }
}

class EntDuplicateNameRule extends AbstractAuditRule {
    public function getId(): string { return 'duplicate_entity'; }
    public function getCategory(): string { return 'entity'; }
    public function getTitle(): string { return 'نام تکراری اشخاص'; }

    public function run(): array {
        $issues = [];
        foreach (['customers' => ['مشتری', 'customers-tab'], 'suppliers' => ['تأمین‌کننده', 'suppliers-tab']] as $table => [$label, $tab]) {
            $q = "SELECT name, COUNT(*) as cnt, GROUP_CONCAT(id) as ids FROM {$table}
                  WHERE entity_id = ? GROUP BY name HAVING cnt > 1";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $ids = explode(',', $row['ids']);
                $issues[] = $this->issue([
                    'severity' => 'warning',
                    'message' => "نام تکراری برای {$label}: {$row['name']}",
                    'details' => "تعداد {$row['cnt']} — شناسه‌ها: {$row['ids']}",
                    'reference_table' => $table,
                    'reference_id' => $ids[0],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'manual_review',
                    'meta' => ['duplicate_ids' => $ids],
                    'nav' => ['tab' => $tab, 'table' => $table, 'reference_id' => $ids[0]],
                ]);
            }
        }
        return $issues;
    }
}

class EntInvalidCodeRule extends AbstractAuditRule {
    public function getId(): string { return 'invalid_entity_code'; }
    public function getCategory(): string { return 'entity'; }
    public function getTitle(): string { return 'کد ملی/اقتصادی نامعتبر'; }

    public function run(): array {
        $issues = [];
        $checks = [
            ['customers', 'nationalId', 'مشتری', 'کد ملی', 'customers-tab'],
            ['suppliers', 'economicCode', 'تأمین‌کننده', 'کد اقتصادی', 'suppliers-tab'],
        ];
        foreach ($checks as [$table, $field, $label, $codeName, $tab]) {
            $q = "SELECT id, name, {$field} AS code_val FROM {$table}
                  WHERE entity_id = ?
                    AND ({$field} IS NOT NULL AND {$field} != '')
                    AND ({$field} REGEXP '[^0-9]' OR LENGTH({$field}) < 10)";
            $stmt = $this->db->prepare($q);
            $stmt->bind_param('i', $this->entityId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $issues[] = $this->issue([
                    'severity' => 'info',
                    'message' => "{$codeName} نامعتبر برای {$label}: {$row['name']}",
                    'details' => "مقدار: {$row['code_val']}",
                    'reference_table' => $table,
                    'reference_id' => $row['id'],
                    'remediation_class' => 'navigate_only',
                    'remediation_action' => 'edit_entity',
                    'nav' => ['tab' => $tab, 'table' => $table, 'reference_id' => $row['id']],
                ]);
            }
        }
        return $issues;
    }
}

class EntLockedUserRule extends AbstractAuditRule {
    public function getId(): string { return 'user_locked'; }
    public function getCategory(): string { return 'entity'; }
    public function getTitle(): string { return 'کاربر قفل‌شده'; }

    public function run(): array {
        $issues = [];
        // Global users table — mark as system-wide in details
        $result = $this->db->query("SELECT id, username, lockout_until FROM users WHERE lockout_until > NOW()");
        if (!$result) return $issues;
        while ($row = $result->fetch_assoc()) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "کاربر قفل‌شده: {$row['username']}",
                'details' => "قفل تا {$row['lockout_until']} (سراسری سیستم، نه فقط این مجموعه)",
                'reference_table' => 'users',
                'reference_id' => $row['id'],
                'remediation_class' => 'guided',
                'remediation_action' => 'unlock_user',
                'nav' => ['settings_sub' => 'users-pane', 'reference_id' => $row['id']],
                'meta' => ['scope' => 'global'],
            ]);
        }
        return $issues;
    }
}
