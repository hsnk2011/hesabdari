<?php
// /api/models/SmsLog.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/../core/KavenegarSms.php';

class SmsLog extends BaseModel {
    protected $tableName = 'sms_logs';
    protected $hasEntityId = true;

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS `sms_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `entity_id` int(11) NOT NULL DEFAULT 1,
                `user_id` int(11) DEFAULT NULL,
                `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `messageid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `receptor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                `sender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'simple',
                `template_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `verify_template` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `invoice_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `invoice_id` int(11) DEFAULT NULL,
                `cost` int(11) DEFAULT NULL,
                `status` int(11) DEFAULT NULL,
                `status_text` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app',
                `sent_at` datetime DEFAULT NULL,
                `status_checked_at` datetime DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Migrate older/partial schemas (e.g. legacy phone_number column)
        $cols = $this->getExistingColumns();
        if (empty($cols)) {
            return;
        }

        if (isset($cols['phone_number']) && !isset($cols['receptor'])) {
            $this->safeQuery("ALTER TABLE `sms_logs` CHANGE `phone_number` `receptor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''");
            $cols = $this->getExistingColumns();
        } elseif (isset($cols['phone_number']) && isset($cols['receptor'])) {
            $this->safeQuery("UPDATE `sms_logs` SET `receptor` = `phone_number` WHERE (`receptor` IS NULL OR `receptor` = '') AND `phone_number` IS NOT NULL AND `phone_number` != ''");
            $this->safeQuery("ALTER TABLE `sms_logs` DROP COLUMN `phone_number`");
            $cols = $this->getExistingColumns();
        }

        $needed = [
            'entity_id' => "ADD COLUMN `entity_id` int(11) NOT NULL DEFAULT 1",
            'user_id' => "ADD COLUMN `user_id` int(11) DEFAULT NULL",
            'username' => "ADD COLUMN `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'messageid' => "ADD COLUMN `messageid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'receptor' => "ADD COLUMN `receptor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
            'sender' => "ADD COLUMN `sender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'message' => "ADD COLUMN `message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'mode' => "ADD COLUMN `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'simple'",
            'template_key' => "ADD COLUMN `template_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'verify_template' => "ADD COLUMN `verify_template` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'invoice_type' => "ADD COLUMN `invoice_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'invoice_id' => "ADD COLUMN `invoice_id` int(11) DEFAULT NULL",
            'cost' => "ADD COLUMN `cost` int(11) DEFAULT NULL",
            'status' => "ADD COLUMN `status` int(11) DEFAULT NULL",
            'status_text' => "ADD COLUMN `status_text` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL",
            'source' => "ADD COLUMN `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app'",
            'sent_at' => "ADD COLUMN `sent_at` datetime DEFAULT NULL",
            'status_checked_at' => "ADD COLUMN `status_checked_at` datetime DEFAULT NULL",
            'created_at' => "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp()",
        ];

        foreach ($needed as $col => $ddl) {
            if (!isset($cols[$col])) {
                $this->safeQuery("ALTER TABLE `sms_logs` {$ddl}");
            }
        }

        // Soften legacy NOT NULL columns that block inserts
        $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `sms_type` varchar(50) NULL DEFAULT NULL");
        $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `message` text NULL");
        $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `entity_id` int(11) NOT NULL DEFAULT 1");
        $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `receptor` varchar(20) NOT NULL DEFAULT ''");
        $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `status` varchar(20) NULL DEFAULT NULL");

        if (isset($cols['phone_number'])) {
            $this->safeQuery("ALTER TABLE `sms_logs` MODIFY `phone_number` varchar(20) NULL DEFAULT NULL");
        }

        $this->safeQuery("ALTER TABLE `sms_logs` ADD INDEX `idx_entity_created` (`entity_id`, `created_at`)");
        $this->safeQuery("ALTER TABLE `sms_logs` ADD INDEX `idx_messageid` (`messageid`)");
        $this->safeQuery("ALTER TABLE `sms_logs` ADD INDEX `idx_status` (`status`)");
        $this->safeQuery("ALTER TABLE `sms_logs` ADD INDEX `idx_invoice` (`invoice_type`, `invoice_id`)");
    }

    private function safeQuery($sql) {
        try {
            $this->conn->query($sql);
        } catch (Throwable $e) {
            // ignore duplicate index / already-migrated column errors
        }
    }

    private function getExistingColumns() {
        $cols = [];
        $res = @$this->conn->query("SHOW COLUMNS FROM `sms_logs`");
        if (!$res) {
            return $cols;
        }
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = $row;
        }
        return $cols;
    }

    public function createLog(array $data) {
        $entityFallback = defined('DEFAULT_ENTITY_ID') ? DEFAULT_ENTITY_ID : 1;
        $entityId = intval($data['entity_id'] ?? ($_SESSION['current_entity_id'] ?? $entityFallback));
        $userId = array_key_exists('user_id', $data)
            ? ($data['user_id'] !== null ? intval($data['user_id']) : null)
            : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null);
        $username = (string)($data['username'] ?? ($_SESSION['username'] ?? ''));
        $messageid = isset($data['messageid']) && $data['messageid'] !== '' ? (string)$data['messageid'] : '';
        $receptor = (string)($data['receptor'] ?? '');
        $sender = isset($data['sender']) ? (string)$data['sender'] : '';
        $message = isset($data['message']) ? (string)$data['message'] : '';
        $mode = (string)($data['mode'] ?? 'simple');
        $templateKey = isset($data['template_key']) ? (string)$data['template_key'] : '';
        $verifyTemplate = isset($data['verify_template']) ? (string)$data['verify_template'] : '';
        $invoiceType = isset($data['invoice_type']) ? (string)$data['invoice_type'] : '';
        $invoiceId = isset($data['invoice_id']) && $data['invoice_id'] !== null && $data['invoice_id'] !== ''
            ? intval($data['invoice_id']) : null;
        $cost = isset($data['cost']) && $data['cost'] !== null && $data['cost'] !== ''
            ? intval($data['cost']) : null;
        $status = isset($data['status']) && $data['status'] !== null && $data['status'] !== ''
            ? intval($data['status']) : null;
        $statusText = (string)($data['status_text'] ?? ($status !== null ? KavenegarSms::statusLabel($status) : ''));
        $source = (string)($data['source'] ?? 'app');
        $sentAt = $data['sent_at'] ?? date('Y-m-d H:i:s');

        $userIdBind = $userId === null ? '' : (string)$userId;
        $invoiceIdBind = $invoiceId === null ? '' : (string)$invoiceId;
        $costBind = $cost === null ? '' : (string)$cost;
        $statusBind = $status === null ? '' : (string)$status;

        try {
            $cols = $this->getExistingColumns();
            $smsType = $templateKey !== '' ? $templateKey : ($source !== '' ? $source : 'app');
            $hasLegacyPhone = isset($cols['phone_number']);
            $hasLegacySmsType = isset($cols['sms_type']);
            $hasLegacyMessageId = isset($cols['message_id']);

            $fields = ['entity_id', 'user_id', 'username', 'messageid', 'receptor', 'sender', 'message', 'mode', 'template_key', 'verify_template', 'invoice_type', 'invoice_id', 'cost', 'status', 'status_text', 'source', 'sent_at'];
            $placeholders = ['?', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', '?', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', '?', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', 'NULLIF(?, \'\')', '?', '?'];
            $values = [$entityId, $userIdBind, $username, $messageid, $receptor, $sender, $message, $mode, $templateKey, $verifyTemplate, $invoiceType, $invoiceIdBind, $costBind, $statusBind, $statusText, $source, $sentAt];
            $types = 'issssssssssssssss';

            if ($hasLegacyPhone) {
                $fields[] = 'phone_number';
                $placeholders[] = '?';
                $values[] = $receptor;
                $types .= 's';
            }
            if ($hasLegacySmsType) {
                $fields[] = 'sms_type';
                $placeholders[] = '?';
                $values[] = $smsType;
                $types .= 's';
            }
            if ($hasLegacyMessageId) {
                $fields[] = 'message_id';
                $placeholders[] = 'NULLIF(?, \'\')';
                $values[] = $messageid;
                $types .= 's';
            }

            $sql = 'INSERT INTO `sms_logs` (`' . implode('`, `', $fields) . '`) VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $this->conn->prepare($sql);
            $refs = [];
            foreach ($values as $k => $v) {
                $refs[$k] = &$values[$k];
            }
            $stmt->bind_param($types, ...$refs);
            if (!$stmt->execute()) {
                return false;
            }
            return (int)$this->conn->insert_id;
        } catch (Throwable $e) {
            error_log('[SmsLog::createLog] ' . $e->getMessage());
            return false;
        }
    }

    public function listLogs($entityId, $limit = 50, $offset = 0) {
        $entityId = intval($entityId);
        $limit = max(1, min(200, intval($limit)));
        $offset = max(0, intval($offset));
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM `{$this->tableName}`
                 WHERE entity_id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iii', $entityId, $limit, $offset);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            return array_map([$this, 'enrichRow'], $rows ?: []);
        } catch (Throwable $e) {
            error_log('[SmsLog::listLogs] ' . $e->getMessage());
            return [];
        }
    }

    public function countLogs($entityId) {
        $entityId = intval($entityId);
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM `{$this->tableName}` WHERE entity_id = ?");
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            return intval($rows[0]['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Local delivery stats for last N days (fallback when Kavenegar outbox APIs are locked).
     */
    public function countStats($entityId, $days = 7) {
        $entityId = intval($entityId);
        $days = max(1, min(90, intval($days)));
        try {
            $stmt = $this->conn->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 10 THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN status IN (6, 11, 13, 14) THEN 1 ELSE 0 END) AS undelivered
                 FROM `{$this->tableName}`
                 WHERE entity_id = ?
                   AND COALESCE(sent_at, created_at) >= (NOW() - INTERVAL ? DAY)"
            );
            $stmt->bind_param('ii', $entityId, $days);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $row = $rows[0] ?? [];
            return [
                'total' => intval($row['total'] ?? 0),
                'delivered' => intval($row['delivered'] ?? 0),
                'undelivered' => intval($row['undelivered'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'delivered' => 0, 'undelivered' => 0];
        }
    }

    public function getPendingMessageIds($entityId, $limit = 100) {
        $entityId = intval($entityId);
        $limit = max(1, min(500, intval($limit)));
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, messageid FROM `{$this->tableName}`
                 WHERE entity_id = ?
                   AND messageid IS NOT NULL AND messageid != ''
                   AND (status IS NULL OR status IN (1, 2, 4, 5))
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->bind_param('ii', $entityId, $limit);
            $stmt->execute();
            return db_stmt_to_assoc_array($stmt) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getByMessageIds(array $messageIds, $entityId = null) {
        $clean = [];
        foreach ($messageIds as $id) {
            $id = preg_replace('/\D+/', '', (string)$id);
            if ($id !== '') $clean[] = $id;
        }
        $clean = array_values(array_unique($clean));
        if (empty($clean)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $types = str_repeat('s', count($clean));
        $sql = "SELECT * FROM `{$this->tableName}` WHERE messageid IN ($placeholders)";
        $params = $clean;
        if ($entityId !== null) {
            $sql .= ' AND entity_id = ?';
            $types .= 'i';
            $params[] = intval($entityId);
        }
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt) ?: [];
            return array_map([$this, 'enrichRow'], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function updateStatusByMessageId($messageid, $status, $statusText = null, $cost = null) {
        $messageid = (string)$messageid;
        $status = intval($status);
        if ($statusText === null) {
            $statusText = KavenegarSms::statusLabel($status);
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($cost !== null) {
                $cost = intval($cost);
                $stmt = $this->conn->prepare(
                    "UPDATE `{$this->tableName}`
                     SET status = ?, status_text = ?, cost = ?, status_checked_at = ?
                     WHERE messageid = ?"
                );
                $stmt->bind_param('isiss', $status, $statusText, $cost, $now, $messageid);
            } else {
                $stmt = $this->conn->prepare(
                    "UPDATE `{$this->tableName}`
                     SET status = ?, status_text = ?, status_checked_at = ?
                     WHERE messageid = ?"
                );
                $stmt->bind_param('isss', $status, $statusText, $now, $messageid);
            }
            return $stmt->execute();
        } catch (Throwable $e) {
            error_log('[SmsLog::updateStatusByMessageId] ' . $e->getMessage());
            return false;
        }
    }

    private function enrichRow(array $row) {
        if (empty($row['receptor']) && !empty($row['phone_number'])) {
            $row['receptor'] = $row['phone_number'];
        }
        $status = isset($row['status']) && $row['status'] !== null && $row['status'] !== ''
            ? intval($row['status'])
            : null;
        $row['status'] = $status;
        $row['status_label'] = $row['status_text'] ?: ($status !== null ? KavenegarSms::statusLabel($status) : 'نامشخص');
        $row['status_category'] = $status !== null ? KavenegarSms::statusCategory($status) : 'unknown';
        return $row;
    }
}
