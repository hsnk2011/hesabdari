<?php
// api/core/audit/AbstractRule.php

require_once __DIR__ . '/RuleInterface.php';

abstract class AbstractAuditRule implements AuditRuleInterface {
    protected $db;
    protected $entityId;

    public function __construct($db, $entityId) {
        $this->db = $db;
        $this->entityId = (int)$entityId;
    }

    protected function tableExists(string $table): bool {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safe === '') return false;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('s', $safe);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return !empty($rows);
    }

    protected function issue(array $data): array {
        $ruleId = $data['rule_id'] ?? $this->getId();
        $refTable = (string)($data['reference_table'] ?? '');
        $refId = (string)($data['reference_id'] ?? '0');
        $meta = $data['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $fingerprint = $data['fingerprint'] ?? self::makeFingerprint($ruleId, $refTable, $refId, $meta);
        $remClass = $data['remediation_class'] ?? 'navigate_only';
        $action = $data['remediation_action'] ?? ($data['action_needed'] ?? 'manual_review');

        return [
            'rule_id' => $ruleId,
            'type' => $ruleId, // backward compat with old UI/API
            'severity' => $data['severity'] ?? 'warning',
            'category' => $data['category'] ?? $this->getCategory(),
            'title' => $data['title'] ?? $this->getTitle(),
            'message' => $data['message'] ?? '',
            'details' => $data['details'] ?? '',
            'entity_id' => $this->entityId,
            'reference_table' => $refTable,
            'reference_id' => $refId,
            'fingerprint' => $fingerprint,
            'remediation_class' => $remClass,
            'remediation_action' => $action,
            'action_needed' => $action,
            'meta' => $meta,
            'nav' => $data['nav'] ?? self::defaultNav($refTable, $refId),
        ];
    }

    public static function makeFingerprint(string $ruleId, string $refTable, string $refId, array $meta = []): string {
        ksort($meta);
        $stable = [];
        foreach ($meta as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $stable[$k] = $v;
            }
        }
        return sha1($ruleId . '|' . $refTable . '|' . $refId . '|' . json_encode($stable, JSON_UNESCAPED_UNICODE));
    }

    public static function defaultNav(string $refTable, string $refId): array {
        $map = [
            'sales_invoices' => ['tab' => 'sales-invoices-tab', 'table' => 'sales_invoices'],
            'purchase_invoices' => ['tab' => 'purchase-invoices-tab', 'table' => 'purchase_invoices'],
            'checks' => ['tab' => 'checks-tab', 'table' => 'checks'],
            'payments' => ['tab' => 'transactions-tab', 'table' => 'transactions'],
            'accounts' => ['tab' => 'accounts-tab', 'table' => 'accounts'],
            'products' => ['tab' => 'products-tab', 'table' => 'products'],
            'inventory_items' => ['tab' => 'products-tab', 'table' => 'products'],
            'customers' => ['tab' => 'customers-tab', 'table' => 'customers'],
            'suppliers' => ['tab' => 'suppliers-tab', 'table' => 'suppliers'],
            'expenses' => ['tab' => 'transactions-tab', 'table' => 'transactions'],
            'return_invoices' => ['tab' => 'return-invoices-tab', 'table' => null],
            'installments' => ['center_panel' => 'installments', 'settings_sub' => 'installments-pane'],
            'bank_import_items' => ['center_panel' => 'bank', 'settings_sub' => 'bank-import-pane'],
            'users' => ['settings_sub' => 'users-pane'],
            'settings' => ['settings_sub' => 'general-settings-pane'],
        ];
        $base = $map[$refTable] ?? ['tab' => 'smart-audit-tab'];
        $base['reference_id'] = $refId;
        $base['reference_table'] = $refTable;
        return $base;
    }
}
