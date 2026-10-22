<?php
// /api/core/SmartAuditor.php — thin bridge to AuditEngine (v2)

require_once __DIR__ . '/audit/AuditEngine.php';

class SmartAuditor {
    private $engine;

    public function __construct($db, $entityId) {
        $this->engine = new AuditEngine($db, $entityId);
    }

    public function runFullAudit() {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $result = $this->engine->runFullAudit($userId);
        // Legacy shape expected by older callers
        return $result['results'] ?? [];
    }

    public function runFullAuditDetailed(?int $userId = null): array {
        return $this->engine->runFullAudit($userId);
    }

    public function getIgnoredItems() {
        return $this->engine->getIgnoredItems();
    }

    public function getEngine(): AuditEngine {
        return $this->engine;
    }
}
