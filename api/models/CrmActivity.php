<?php
// /api/models/CrmActivity.php
// Calls, meetings, visits and notes about a person, with an optional payment
// promise and an optional next follow-up task.
require_once __DIR__ . '/CrmPerson.php';
require_once __DIR__ . '/CrmTask.php';

class CrmActivity {
    const KINDS = ['call_out', 'call_in', 'meeting', 'visit', 'note'];

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create(int $entityId, int $userId, array $d): array {
        $type = (string)($d['person_type'] ?? '');
        $personId = (int)($d['person_id'] ?? 0);
        if (!CrmPerson::exists($this->conn, $entityId, $type, $personId)) {
            return ['error' => 'شخص انتخاب‌شده یافت نشد.'];
        }
        $kind = in_array($d['kind'] ?? '', self::KINDS, true) ? $d['kind'] : 'note';
        $outcome = mb_substr(trim((string)($d['outcome'] ?? '')), 0, 50) ?: null;
        $body = trim((string)($d['body'] ?? '')) ?: null;

        $amount = isset($d['promise_amount']) && $d['promise_amount'] !== '' ? parse_money($d['promise_amount']) : null;
        $promiseDate = trim((string)($d['promise_date'] ?? '')) ?: null;
        if ($amount !== null || $promiseDate !== null) {
            if ($amount === null || $amount <= 0 || $promiseDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $promiseDate)) {
                return ['error' => 'برای قول پرداخت، مبلغ و تاریخ هر دو لازم است.'];
            }
        }
        $promiseStatus = $amount !== null ? 'open' : null;
        if ($body === null && $outcome === null && $amount === null) {
            return ['error' => 'متن یا نتیجه را وارد کنید.'];
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO crm_activities (entity_id, person_type, person_id, user_id, kind, outcome, body, promise_amount, promise_date, promise_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isiisssdss', $entityId, $type, $personId, $userId, $kind, $outcome, $body, $amount, $promiseDate, $promiseStatus);
            $stmt->execute();
            $id = (int)$this->conn->insert_id;

            $taskId = null;
            if (!empty($d['next_task']) && is_array($d['next_task'])) {
                $next = $d['next_task'] + [
                    'kind' => ($amount !== null || $type === 'customer' && $outcome === 'promised') ? 'collection' : ($type === 'lead' ? 'sales' : 'followup'),
                ];
                $next['person_type'] = $type;
                $next['person_id'] = $personId;
                $r = (new CrmTask($this->conn))->create($entityId, $userId, $next);
                if (!empty($r['error'])) {
                    $this->conn->rollback();
                    return $r;
                }
                $taskId = $r['id'];
            }
            // Talking to a lead moves it out of «جدید».
            if ($type === 'lead') {
                $this->conn->query("UPDATE crm_leads SET stage = 'contacted' WHERE id = {$personId} AND stage = 'new'");
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
        log_activity($this->conn, 'crm_activity', "ثبت فعالیت CRM ({$kind}) برای {$type} #{$personId}");
        return ['success' => true, 'id' => $id, 'task_id' => $taskId];
    }

    public function listForPerson(int $entityId, string $type, int $id, int $limit = 50): array {
        $stmt = $this->conn->prepare(
            "SELECT a.*, tm.full_name AS user_name FROM crm_activities a
             LEFT JOIN team_members tm ON tm.entity_id = a.entity_id AND tm.user_id = a.user_id
             WHERE a.entity_id = ? AND a.person_type = ? AND a.person_id = ?
             ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, $limit)
        );
        $stmt->bind_param('isi', $entityId, $type, $id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
}
