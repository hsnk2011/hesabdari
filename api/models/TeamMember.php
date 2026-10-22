<?php
// /api/models/TeamMember.php
// CRM team of a business: partners and employees. A member may be linked to a
// system user (who then receives tasks and notifications) and to a partner row.

class TeamMember {
    const KINDS = ['partner', 'employee'];

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function list(int $entityId, bool $activeOnly = true): array {
        $sql = "SELECT tm.*, u.username, p.name AS partner_name
                FROM team_members tm
                LEFT JOIN users u ON u.id = tm.user_id
                LEFT JOIN partners p ON p.id = tm.partner_id
                WHERE tm.entity_id = ?" . ($activeOnly ? " AND tm.is_active = 1" : "") . "
                ORDER BY tm.is_active DESC, tm.kind = 'partner' DESC, tm.full_name";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function save(int $entityId, array $d): array {
        $id = (int)($d['id'] ?? 0);
        $name = trim((string)($d['full_name'] ?? ''));
        $kind = (string)($d['kind'] ?? 'employee');
        $mobile = trim((string)($d['mobile'] ?? '')) ?: null;
        $userId = (int)($d['user_id'] ?? 0) ?: null;
        $partnerId = (int)($d['partner_id'] ?? 0) ?: null;
        $active = array_key_exists('is_active', $d) ? ((int)$d['is_active'] ? 1 : 0) : 1;

        if ($name === '') {
            return ['error' => 'نام عضو تیم الزامی است.'];
        }
        if (!in_array($kind, self::KINDS, true)) {
            return ['error' => 'نوع عضو نامعتبر است.'];
        }
        if ($userId !== null && !$this->exists("SELECT 1 FROM users WHERE id = ?", [$userId])) {
            return ['error' => 'کاربر انتخاب‌شده یافت نشد.'];
        }
        if ($userId !== null && $this->exists("SELECT 1 FROM team_members WHERE entity_id = ? AND user_id = ? AND id <> ?", [$entityId, $userId, $id])) {
            return ['error' => 'این کاربر قبلاً به‌عنوان عضو تیم ثبت شده است.'];
        }
        if ($partnerId !== null && !$this->exists("SELECT 1 FROM partners WHERE id = ? AND entity_id = ?", [$partnerId, $entityId])) {
            return ['error' => 'شریک انتخاب‌شده متعلق به این مجموعه نیست.'];
        }
        if ($kind === 'employee') {
            $partnerId = null;
        }

        if ($id > 0) {
            if (!$this->exists("SELECT 1 FROM team_members WHERE id = ? AND entity_id = ?", [$id, $entityId])) {
                return ['error' => 'عضو تیم یافت نشد.', 'statusCode' => 404];
            }
            $stmt = $this->conn->prepare("UPDATE team_members SET full_name = ?, mobile = ?, kind = ?, user_id = ?, partner_id = ?, is_active = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('sssiiiii', $name, $mobile, $kind, $userId, $partnerId, $active, $id, $entityId);
            $stmt->execute();
            log_activity($this->conn, 'crm_team_update', "ویرایش عضو تیم: {$name}");
            return ['success' => true, 'id' => $id];
        }
        $stmt = $this->conn->prepare("INSERT INTO team_members (entity_id, full_name, mobile, kind, user_id, partner_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssiii', $entityId, $name, $mobile, $kind, $userId, $partnerId, $active);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id; // before log_activity, which inserts its own row
        log_activity($this->conn, 'crm_team_create', "افزودن عضو تیم: {$name}");
        return ['success' => true, 'id' => $newId];
    }

    public function deactivate(int $entityId, int $id): array {
        $stmt = $this->conn->prepare("UPDATE team_members SET is_active = 0 WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        if ($stmt->affected_rows < 1 && !$this->exists("SELECT 1 FROM team_members WHERE id = ? AND entity_id = ?", [$id, $entityId])) {
            return ['error' => 'عضو تیم یافت نشد.', 'statusCode' => 404];
        }
        log_activity($this->conn, 'crm_team_deactivate', "غیرفعال کردن عضو تیم #{$id}");
        return ['success' => true];
    }

    /** Active members that can log in, i.e. can own tasks and receive notifications. */
    public static function userIdsForEntity($db, int $entityId): array {
        $stmt = $db->prepare("SELECT user_id FROM team_members WHERE entity_id = ? AND is_active = 1 AND user_id IS NOT NULL ORDER BY id");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        return array_map('intval', array_column(db_stmt_to_assoc_array($stmt), 'user_id'));
    }

    /** user_id => full_name for the active team (for labels). */
    public static function namesByUser($db, int $entityId): array {
        $stmt = $db->prepare("SELECT user_id, full_name FROM team_members WHERE entity_id = ? AND user_id IS NOT NULL");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $out = [];
        foreach (db_stmt_to_assoc_array($stmt) as $r) {
            $out[(int)$r['user_id']] = $r['full_name'];
        }
        return $out;
    }

    private function exists(string $sql, array $params): bool {
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        $stmt->execute();
        return !empty(db_stmt_to_assoc_array($stmt));
    }
}
