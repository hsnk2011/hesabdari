<?php
// api/models/AuditRun.php

class AuditRun {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function ensureSchema(): void {
        // Soft ensure for environments that haven't run setup upgrade yet
        $this->conn->query("CREATE TABLE IF NOT EXISTS `audit_runs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `entity_id` int(11) NOT NULL,
            `started_at` datetime NOT NULL,
            `finished_at` datetime DEFAULT NULL,
            `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
            `duration_ms` int(11) DEFAULT NULL,
            `summary_json` longtext DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_entity_started` (`entity_id`, `started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->conn->query("CREATE TABLE IF NOT EXISTS `audit_run_issues` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `run_id` int(11) NOT NULL,
            `rule_id` varchar(100) NOT NULL,
            `severity` varchar(20) NOT NULL,
            `category` varchar(50) NOT NULL,
            `fingerprint` varchar(64) NOT NULL,
            `reference_table` varchar(100) NOT NULL,
            `reference_id` varchar(100) NOT NULL,
            `title` varchar(255) DEFAULT NULL,
            `message` text DEFAULT NULL,
            `payload_json` longtext DEFAULT NULL,
            `status` enum('open','resolved','ignored') NOT NULL DEFAULT 'open',
            `remediation_class` varchar(30) NOT NULL DEFAULT 'navigate_only',
            `nav_json` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_run` (`run_id`),
            KEY `idx_fingerprint` (`fingerprint`),
            KEY `idx_run_rule` (`run_id`, `rule_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // fingerprint column on ignored items
        $col = $this->conn->query("SHOW COLUMNS FROM `audit_ignored_items` LIKE 'fingerprint'");
        if ($col && $col->num_rows === 0) {
            @$this->conn->query("ALTER TABLE `audit_ignored_items` ADD COLUMN `fingerprint` varchar(64) DEFAULT NULL AFTER `issue_type`");
            @$this->conn->query("ALTER TABLE `audit_ignored_items` ADD INDEX `idx_ignore_fingerprint` (`entity_id`, `fingerprint`)");
        }
    }

    public function startRun(int $entityId, ?int $userId): int {
        $this->ensureSchema();
        $started = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare(
            "INSERT INTO audit_runs (entity_id, started_at, status, created_by) VALUES (?, ?, 'running', ?)"
        );
        $stmt->bind_param('isi', $entityId, $started, $userId);
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    public function completeRun(int $runId, array $summary, int $durationMs, string $status = 'completed'): void {
        $finished = date('Y-m-d H:i:s');
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE);
        $stmt = $this->conn->prepare(
            "UPDATE audit_runs SET finished_at = ?, status = ?, duration_ms = ?, summary_json = ? WHERE id = ?"
        );
        $stmt->bind_param('ssisi', $finished, $status, $durationMs, $json, $runId);
        $stmt->execute();
    }

    public function saveIssues(int $runId, array $issues): void {
        if (empty($issues)) return;
        $stmt = $this->conn->prepare(
            "INSERT INTO audit_run_issues
            (run_id, rule_id, severity, category, fingerprint, reference_table, reference_id, title, message, payload_json, status, remediation_class, nav_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($issues as $issue) {
            $ruleId = (string)($issue['rule_id'] ?? $issue['type'] ?? '');
            $severity = (string)($issue['severity'] ?? 'warning');
            $category = (string)($issue['category'] ?? 'system');
            $fp = (string)($issue['fingerprint'] ?? '');
            $refTable = (string)($issue['reference_table'] ?? '');
            $refId = (string)($issue['reference_id'] ?? '0');
            $title = (string)($issue['title'] ?? '');
            $message = (string)($issue['message'] ?? '');
            $status = (string)($issue['status'] ?? 'open');
            $remClass = (string)($issue['remediation_class'] ?? 'navigate_only');
            $payload = json_encode($issue, JSON_UNESCAPED_UNICODE);
            $nav = json_encode($issue['nav'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmt->bind_param(
                'issssssssssss',
                $runId, $ruleId, $severity, $category, $fp, $refTable, $refId,
                $title, $message, $payload, $status, $remClass, $nav
            );
            $stmt->execute();
        }
    }

    public function listRuns(int $entityId, int $limit = 20): array {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $stmt = $this->conn->prepare(
            "SELECT id, entity_id, started_at, finished_at, status, duration_ms, summary_json, created_by
             FROM audit_runs WHERE entity_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        foreach ($rows as &$row) {
            $row['summary'] = json_decode($row['summary_json'] ?? 'null', true);
            unset($row['summary_json']);
        }
        return $rows;
    }

    public function getRun(int $entityId, int $runId): ?array {
        $this->ensureSchema();
        $stmt = $this->conn->prepare(
            "SELECT id, entity_id, started_at, finished_at, status, duration_ms, summary_json, created_by
             FROM audit_runs WHERE entity_id = ? AND id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $entityId, $runId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows)) return null;
        $run = $rows[0];
        $run['summary'] = json_decode($run['summary_json'] ?? 'null', true);
        unset($run['summary_json']);

        $stmt2 = $this->conn->prepare(
            "SELECT id, rule_id, severity, category, fingerprint, reference_table, reference_id,
                    title, message, payload_json, status, remediation_class, nav_json
             FROM audit_run_issues WHERE run_id = ? ORDER BY FIELD(severity,'critical','warning','info'), id"
        );
        $stmt2->bind_param('i', $runId);
        $stmt2->execute();
        $issues = db_stmt_to_assoc_array($stmt2);
        $decoded = [];
        foreach ($issues as $issue) {
            $payload = json_decode($issue['payload_json'] ?? 'null', true);
            if (is_array($payload)) {
                $payload['issue_row_id'] = (int)$issue['id'];
                $payload['compare_status'] = $issue['status'];
                $decoded[] = $payload;
            } else {
                $decoded[] = [
                    'rule_id' => $issue['rule_id'],
                    'type' => $issue['rule_id'],
                    'severity' => $issue['severity'],
                    'category' => $issue['category'],
                    'fingerprint' => $issue['fingerprint'],
                    'reference_table' => $issue['reference_table'],
                    'reference_id' => $issue['reference_id'],
                    'title' => $issue['title'],
                    'message' => $issue['message'],
                    'remediation_class' => $issue['remediation_class'],
                    'nav' => json_decode($issue['nav_json'] ?? '{}', true),
                ];
            }
        }
        $run['issues'] = $decoded;
        return $run;
    }

    public function getPreviousCompletedRunId(int $entityId, int $currentRunId): ?int {
        $stmt = $this->conn->prepare(
            "SELECT id FROM audit_runs
             WHERE entity_id = ? AND status = 'completed' AND id < ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ii', $entityId, $currentRunId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return empty($rows) ? null : (int)$rows[0]['id'];
    }

    public function getFingerprintsForRun(int $runId): array {
        $stmt = $this->conn->prepare("SELECT fingerprint FROM audit_run_issues WHERE run_id = ? AND status = 'open'");
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        $set = [];
        foreach ($rows as $row) {
            $set[$row['fingerprint']] = true;
        }
        return $set;
    }

    public function compareRuns(int $entityId, int $runId, ?int $againstRunId = null): array {
        $current = $this->getRun($entityId, $runId);
        if (!$current) {
            return ['error' => 'اجرای مورد نظر یافت نشد.'];
        }
        if (!$againstRunId) {
            $againstRunId = $this->getPreviousCompletedRunId($entityId, $runId);
        }
        if (!$againstRunId) {
            return [
                'current_run_id' => $runId,
                'previous_run_id' => null,
                'new' => $current['issues'] ?? [],
                'recurring' => [],
                'resolved' => [],
                'counts' => [
                    'new' => count($current['issues'] ?? []),
                    'recurring' => 0,
                    'resolved' => 0,
                ],
            ];
        }
        $previous = $this->getRun($entityId, $againstRunId);
        $prevFp = [];
        foreach (($previous['issues'] ?? []) as $issue) {
            $fp = $issue['fingerprint'] ?? '';
            if ($fp !== '') $prevFp[$fp] = $issue;
        }
        $currFp = [];
        foreach (($current['issues'] ?? []) as $issue) {
            $fp = $issue['fingerprint'] ?? '';
            if ($fp !== '') $currFp[$fp] = $issue;
        }

        $new = [];
        $recurring = [];
        foreach ($currFp as $fp => $issue) {
            if (isset($prevFp[$fp])) {
                $issue['compare_tag'] = 'recurring';
                $recurring[] = $issue;
            } else {
                $issue['compare_tag'] = 'new';
                $new[] = $issue;
            }
        }
        $resolved = [];
        foreach ($prevFp as $fp => $issue) {
            if (!isset($currFp[$fp])) {
                $issue['compare_tag'] = 'resolved';
                $resolved[] = $issue;
            }
        }

        return [
            'current_run_id' => $runId,
            'previous_run_id' => $againstRunId,
            'new' => $new,
            'recurring' => $recurring,
            'resolved' => $resolved,
            'counts' => [
                'new' => count($new),
                'recurring' => count($recurring),
                'resolved' => count($resolved),
            ],
        ];
    }
}
