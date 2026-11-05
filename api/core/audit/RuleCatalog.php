<?php
// api/core/audit/RuleCatalog.php

require_once __DIR__ . '/AbstractRule.php';
require_once __DIR__ . '/rules/financial/FinancialRules.php';
require_once __DIR__ . '/rules/inventory/InventoryRules.php';
require_once __DIR__ . '/rules/entity/EntityRules.php';
require_once __DIR__ . '/rules/system/SystemRules.php';
require_once __DIR__ . '/rules/operations/OperationsRules.php';

class AuditRuleCatalog {
    /** @return AuditRuleInterface[] */
    public static function all($db, int $entityId): array {
        $rules = [];
        $rules = array_merge($rules, FinancialAuditRules::create($db, $entityId));
        $rules = array_merge($rules, InventoryAuditRules::create($db, $entityId));
        $rules = array_merge($rules, EntityAuditRules::create($db, $entityId));
        $rules = array_merge($rules, SystemAuditRules::create($db, $entityId));
        $rules = array_merge($rules, OperationsAuditRules::create($db, $entityId));
        return $rules;
    }
}
