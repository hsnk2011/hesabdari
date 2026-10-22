<?php
// /api/models/CrmTask.php
// Tasks and follow-ups owned by one team member (who must be able to log in).
require_once __DIR__ . '/CrmPerson.php';
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class CrmTask {
    const KINDS = ['followup', 'collection', 'sales', 'other'];
    const STATUSES = ['open', 'done', 'cancelled'];
    const NO_LOGIN_ERROR = 'این عضو تیم کاربر سیستم ندارد و نمی‌توان کار به او سپرد.';

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /** Validated row values or ['error' => ...]. */
    private function clean(int $entityId, array $d): array {
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') {
            return ['error' => 'عنوان کار الزامی است.'];
        }
        $due = self::normalizeDateTime($d['due_at'] ?? '');
        if ($due === null) {
            return ['error' => 'زمان انجام کار نامعتبر است.'];
        }
        $assignee = (int)($d['assignee_user_id'] ?? 0);
        if (!in_array($assignee, TeamMember::userIdsForEntity($this->conn, $entityId), true)) {
            return ['error' => self::NO_LOGIN_ERROR];
        }
        $kind = in_array($d['kind'] ?? '', self::KINDS, true) ? $d['kind'] : 'followup';
        $personType = $d['person_type'] ?? null;
        $personId = (int)($d['person_id'] ?? 0);
        if ($personType || $personId) {
            if (!CrmPerson::exists($this->conn, $entityId, (string)$personType, $personId)) {
                return ['error' => 'شخص انتخاب‌شده یافت نشد.'];
            }
        } else {
            $personType = null;
            $personId = null;
        }
        $desc = trim((string)($d['description'] ?? '')) ?: null;
        return compact('title', 'due', 'assignee', 'kind', 'personType', 'personId', 'desc');
    }

    public function create(int $entityId, int $createdBy, array $d): array {
        $v = $this->clean($entityId, $d);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare(
            "INSERT INTO crm_tasks (entity_id, title, description, person_type, person_id, assignee_user_id, created_by, due_at, kind)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssiiiss', $entityId, $v['title'], $v['desc'], $v['personType'], $v['personId'], $v['assignee'], $createdBy, $v['due'], $v['kind']);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        log_activity($this->conn, 'crm_task_create', "کار CRM: {$v['title']}");
        if ($v['assignee'] !== $createdBy) {
            $this->notifyAssigned($entityId, $id, $v['assignee'], $v['title'], $createdBy);
        }
        return ['success' => true, 'id' => $id];
    }

    public function update(int $entityId, int $id, array $d, int $actorUserId): array {
        $old = $this->get($entityId, $id);
        if (!$old) {
            return ['error' => 'کار یافت نشد.', 'statusCode' => 404];
        }
        // Fields the caller leaves out keep their current value.
        $v = $this->clean($entityId, $d + [
            'kind' => $old['kind'], 'person_type' => $old['person_type'], 'person_id' => $old['person_id'],
            'description' => $old['description'], 'title' => $old['title'], 'due_at' => $old['due_at'],
            'assignee_user_id' => $old['assignee_user_id'],
        ]);
        if (isset($v['error'])) {
            return $v;
        }
        // A new due time means a new reminder.
        $resetReminder = $v['due'] !== $old['due_at'] ? 1 : 0;
        $stmt = $this->conn->prepare(
            "UPDATE crm_tasks SET title = ?, description = ?, person_type = ?, person_id = ?, assignee_user_id = ?, due_at = ?, kind = ?,
                reminded_at = IF(?, NULL, reminded_at)
             WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('sssiissiii', $v['title'], $v['desc'], $v['personType'], $v['personId'], $v['assignee'], $v['due'], $v['kind'], $resetReminder, $id, $entityId);
        $stmt->execute();
        log_activity($this->conn, 'crm_task_update', "ویرایش کار CRM #{$id}");
        if ($v['assignee'] !== (int)$old['assignee_user_id'] && $v['assignee'] !== $actorUserId) {
            $this->notifyAssigned($entityId, $id, $v['assignee'], $v['title'], $actorUserId);
        }
        return ['success' => true, 'id' => $id];
    }

    public function setStatus(int $entityId, int $id, string $status, int $userId): array {
        if (!in_array($status, self::STATUSES, true)) {
            return ['error' => 'وضعیت نامعتبر است.'];
        }
        if (!$this->get($entityId, $id)) {
            return ['error' => 'کار یافت نشد.', 'statusCode' => 404];
        }
        if ($status === 'open') {
            $stmt = $this->conn->prepare("UPDATE crm_tasks SET status = 'open', done_at = NULL, done_by = NULL WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ii', $id, $entityId);
        } else {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare("UPDATE crm_tasks SET status = ?, done_at = ?, done_by = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ssiii', $status, $now, $userId, $id, $entityId);
        }
        $stmt->execute();
        if ($status !== 'open') {
            // A finished task should not keep ringing in the bell.
            $this->conn->query("UPDATE notifications SET is_dismissed = 1 WHERE entity_id = {$entityId} AND reference_type = 'crm_task' AND reference_id = {$id}");
        }
        log_activity($this->conn, 'crm_task_status', "کار CRM #{$id}: {$status}");
        return ['success' => true];
    }

    public function get(int $entityId, int $id): ?array {
        $stmt = $this->conn->prepare("SELECT t.*, " . CrmPerson::nameSelect() . ", tm.full_name AS assignee_name
            FROM crm_tasks t " . CrmPerson::joinSql('t') . "
            LEFT JOIN team_members tm ON tm.entity_id = t.entity_id AND tm.user_id = t.assignee_user_id
            WHERE t.id = ? AND t.entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    /** Open tasks of one user split by due date (Tehran calendar day). */
    public function listForUser(int $entityId, int $userId): array {
        $rows = $this->query($entityId, ['assignee_user_id' => $userId, 'status' => 'open']);
        $today = date('Y-m-d');
        $out = ['overdue' => [], 'today' => [], 'upcoming' => []];
        foreach ($rows as $r) {
            $day = substr($r['due_at'], 0, 10);
            $out[$day < $today ? 'overdue' : ($day === $today ? 'today' : 'upcoming')][] = $r;
        }
        return $out;
    }

    public function listTeam(int $entityId, array $f = []): array {
        return $this->query($entityId, $f);
    }

    public function listForPerson(int $entityId, string $type, int $id): array {
        return $this->query($entityId, ['person_type' => $type, 'person_id' => $id]);
    }

    private function query(int $entityId, array $f): array {
        $where = ['t.entity_id = ?'];
        $types = 'i';
        $params = [$entityId];
        if (!empty($f['assignee_user_id'])) {
            $where[] = 't.assignee_user_id = ?'; $types .= 'i'; $params[] = (int)$f['assignee_user_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 't.status = ?'; $types .= 's'; $params[] = $f['status'];
        }
        if (!empty($f['person_type']) && CrmPerson::isType($f['person_type'])) {
            $where[] = 't.person_type = ? AND t.person_id = ?'; $types .= 'si'; $params[] = $f['person_type']; $params[] = (int)($f['person_id'] ?? 0);
        }
        $limit = max(1, min(500, (int)($f['limit'] ?? 300)));
        // Open tasks by due time; finished ones most recent first.
        $stmt = $this->conn->prepare("SELECT t.*, " . CrmPerson::nameSelect() . ", tm.full_name AS assignee_name
            FROM crm_tasks t " . CrmPerson::joinSql('t') . "
            LEFT JOIN team_members tm ON tm.entity_id = t.entity_id AND tm.user_id = t.assignee_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.status = 'open' DESC, IF(t.status = 'open', t.due_at, NULL) ASC, t.done_at DESC, t.id DESC
            LIMIT {$limit}");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function notifyAssigned(int $entityId, int $taskId, int $assignee, string $title, int $byUserId): void {
        $by = TeamMember::namesByUser($this->conn, $entityId)[$byUserId] ?? 'همکار';
        CrmNotifier::notify($this->conn, $entityId, $assignee, 'crm_task_assigned', 'کار جدید: ' . $title,
            "{$by} این کار را به شما سپرد.", './index.php#crm/task/' . $taskId, 'crm_task', $taskId);
    }

    /** "2026-10-18 10:00", "2026-10-18T10:00" or a bare date (→ 09:00) to "Y-m-d H:i:s"; null when invalid. */
    public static function normalizeDateTime($value): ?string {
        $s = trim(str_replace('T', ' ', (string)$value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $s .= ' 09:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) {
            $s .= ':00';
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
        return ($dt && $dt->format('Y-m-d H:i:s') === $s) ? $s : null;
    }
}
