<?php
// api/core/audit/AuditEngine.php

require_once __DIR__ . '/RuleCatalog.php';
require_once __DIR__ . '/../../models/AuditRun.php';

class AuditEngine {
    private $db;
    private $entityId;
    private $auditRun;

    public function __construct($db, $entityId) {
        $this->db = $db;
        $this->entityId = (int)$entityId;
        $this->auditRun = new AuditRun($db);
    }

    public function runFullAudit(?int $userId = null): array {
        $t0 = microtime(true);
        $runId = $this->auditRun->startRun($this->entityId, $userId);

        try {
            $issues = [];
            foreach (AuditRuleCatalog::all($this->db, $this->entityId) as $rule) {
                try {
                    $batch = $rule->run();
                    if (is_array($batch) && $batch) {
                        $issues = array_merge($issues, $batch);
                    }
                } catch (Throwable $e) {
                    $issues[] = [
                        'rule_id' => 'rule_execution_error',
                        'type' => 'rule_execution_error',
                        'severity' => 'warning',
                        'category' => 'system',
                        'title' => 'خطای اجرای قانون',
                        'message' => 'قانون «' . $rule->getId() . '» با خطا متوقف شد',
                        'details' => $e->getMessage(),
                        'entity_id' => $this->entityId,
                        'reference_table' => 'audit_rules',
                        'reference_id' => $rule->getId(),
                        'fingerprint' => sha1('rule_execution_error|' . $rule->getId()),
                        'remediation_class' => 'navigate_only',
                        'remediation_action' => 'manual_review',
                        'action_needed' => 'manual_review',
                        'meta' => [],
                        'nav' => ['tab' => 'smart-audit-tab'],
                    ];
                }
            }

            $ignoredRows = $this->getIgnoredItems();
            $ignoredKeys = $this->buildIgnoreLookup($ignoredRows);
            $active = [];
            $ignoredMatched = [];

            foreach ($issues as $issue) {
                $fp = $issue['fingerprint'] ?? '';
                $legacy = ($issue['reference_table'] ?? '') . '_' . ($issue['reference_id'] ?? '') . '_' . ($issue['type'] ?? $issue['rule_id'] ?? '');
                if (($fp && isset($ignoredKeys['fp:' . $fp])) || isset($ignoredKeys['legacy:' . $legacy])) {
                    $issue['status'] = 'ignored';
                    $ignoredMatched[] = $issue;
                } else {
                    $issue['status'] = 'open';
                    $active[] = $issue;
                }
            }

            $summary = $this->buildSummary($active);
            $durationMs = (int)round((microtime(true) - $t0) * 1000);
            $this->auditRun->saveIssues($runId, $active);
            $this->auditRun->completeRun($runId, $summary, $durationMs, 'completed');

            $compare = $this->auditRun->compareRuns($this->entityId, $runId);
            foreach ($active as &$issue) {
                $fp = $issue['fingerprint'] ?? '';
                $tag = 'new';
                foreach ($compare['recurring'] as $r) {
                    if (($r['fingerprint'] ?? '') === $fp) { $tag = 'recurring'; break; }
                }
                $issue['compare_tag'] = $tag;
            }
            unset($issue);

            return [
                'success' => true,
                'run_id' => $runId,
                'results' => $active,
                'ignored' => $this->enrichIgnored($ignoredRows, $ignoredMatched),
                'summary' => $summary,
                'compare' => [
                    'previous_run_id' => $compare['previous_run_id'],
                    'counts' => $compare['counts'],
                ],
                'duration_ms' => $durationMs,
            ];
        } catch (Throwable $e) {
            $durationMs = (int)round((microtime(true) - $t0) * 1000);
            $this->auditRun->completeRun($runId, ['error' => $e->getMessage()], $durationMs, 'failed');
            throw $e;
        }
    }

    private function buildSummary(array $active): array {
        $summary = [
            'total' => count($active),
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'by_category' => [],
            'by_rule' => [],
            'safe_auto' => 0,
        ];
        foreach ($active as $issue) {
            $sev = $issue['severity'] ?? 'info';
            if (isset($summary[$sev])) $summary[$sev]++;
            $cat = $issue['category'] ?? 'other';
            $summary['by_category'][$cat] = ($summary['by_category'][$cat] ?? 0) + 1;
            $rule = $issue['rule_id'] ?? 'unknown';
            $summary['by_rule'][$rule] = ($summary['by_rule'][$rule] ?? 0) + 1;
            if (($issue['remediation_class'] ?? '') === 'safe_auto') {
                $summary['safe_auto']++;
            }
        }
        $score = 100 - ($summary['critical'] * 10) - ($summary['warning'] * 3) - ($summary['info'] * 1);
        $summary['health_score'] = max(0, $score);
        return $summary;
    }

    private function buildIgnoreLookup(array $ignoredRows): array {
        $keys = [];
        foreach ($ignoredRows as $item) {
            if (!empty($item['fingerprint'])) {
                $keys['fp:' . $item['fingerprint']] = true;
            }
            $keys['legacy:' . $item['reference_table'] . '_' . $item['reference_id'] . '_' . $item['issue_type']] = true;
        }
        return $keys;
    }

    private function enrichIgnored(array $ignoredRows, array $matchedIssues): array {
        $byLegacy = [];
        $byFp = [];
        foreach ($matchedIssues as $issue) {
            $byFp[$issue['fingerprint'] ?? ''] = $issue;
            $byLegacy[($issue['reference_table'] ?? '') . '_' . ($issue['reference_id'] ?? '') . '_' . ($issue['type'] ?? '')] = $issue;
        }
        $out = [];
        foreach ($ignoredRows as $row) {
            $extra = null;
            if (!empty($row['fingerprint']) && isset($byFp[$row['fingerprint']])) {
                $extra = $byFp[$row['fingerprint']];
            } else {
                $legacy = $row['reference_table'] . '_' . $row['reference_id'] . '_' . $row['issue_type'];
                $extra = $byLegacy[$legacy] ?? null;
            }
            $out[] = array_merge($row, [
                'message' => $extra['message'] ?? ($row['issue_type'] ?? ''),
                'details' => $extra['details'] ?? '',
                'severity' => $extra['severity'] ?? 'info',
                'category' => $extra['category'] ?? 'system',
                'title' => $extra['title'] ?? ($row['issue_type'] ?? ''),
            ]);
        }
        return $out;
    }

    public function getIgnoredItems(): array {
        $this->auditRun->ensureSchema();
        $stmt = $this->db->prepare("SELECT * FROM audit_ignored_items WHERE entity_id = ? ORDER BY ignored_at DESC");
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getAuditRunModel(): AuditRun {
        return $this->auditRun;
    }
}
