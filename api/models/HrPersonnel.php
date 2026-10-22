<?php
// /api/models/HrPersonnel.php
// Staff files (rules from mz7 src/core/hr): a person (with or without a login)
// and dated records — warnings, notices, fines, advances, bonuses, commendations.
require_once __DIR__ . '/TeamMember.php';

class HrPersonnel {
    /** key => [label, carries money] */
    const KINDS = [
        'warning' => ['تذکر شفاهی', false], 'notice' => ['اخطار کتبی', false], 'fine' => ['جریمه', true],
        'advance' => ['مساعده', true], 'bonus' => ['پاداش', true], 'commendation' => ['تشویق', false], 'other' => ['سایر', false],
    ];

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    public function list(int $e, ?string $q, bool $inactive): array {
        $sql = "SELECT p.*, (SELECT COUNT(*) FROM hr_records r WHERE r.personnel_id = p.id) AS record_count FROM hr_personnel p WHERE p.entity_id = ?";
        $types = 'i';
        $params = [$e];
        if (!$inactive) {
            $sql .= " AND p.is_active = 1";
        }
        if ($q !== null && trim($q) !== '') {
            $sql .= " AND (p.full_name LIKE ? OR p.job_title LIKE ? OR p.phone LIKE ?)";
            $like = '%' . trim($q) . '%';
            array_push($params, $like, $like, $like);
            $types .= 'sss';
        }
        $stmt = $this->conn->prepare($sql . " ORDER BY p.is_active DESC, p.full_name LIMIT 500");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function save(int $e, int $uid, array $d): array {
        $id = (int)($d['id'] ?? 0);
        $name = trim((string)($d['full_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            return self::err('نام را بنویسید (حداکثر ۱۵۰ نویسه).');
        }
        $userId = (int)($d['user_id'] ?? 0) ?: null;
        if ($userId !== null && !$this->conn->query("SELECT id FROM users WHERE id = {$userId}")->fetch_assoc()) {
            return self::err('حساب کاربری نامعتبر است.');
        }
        $job = mb_substr(trim((string)($d['job_title'] ?? '')), 0, 150) ?: null;
        $phone = mb_substr(trim((string)($d['phone'] ?? '')), 0, 50) ?: null;
        $nid = mb_substr(trim((string)($d['national_id'] ?? '')), 0, 20) ?: null;
        $hired = trim((string)($d['hired_at'] ?? '')) ?: null;
        if ($hired !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hired)) {
            return self::err('تاریخ استخدام نامعتبر است.');
        }
        $note = trim((string)($d['note'] ?? ''));
        if (mb_strlen($note) > 2000) {
            return self::err('یادداشت حداکثر ۲۰۰۰ نویسه است.');
        }
        $note = $note !== '' ? $note : null;
        $active = array_key_exists('is_active', $d) ? ((int)$d['is_active'] ? 1 : 0) : 1;
        if ($id > 0) {
            if (!$this->conn->query("SELECT id FROM hr_personnel WHERE id = {$id} AND entity_id = {$e}")->fetch_assoc()) {
                return self::err('پرونده یافت نشد.', 404);
            }
            $stmt = $this->conn->prepare("UPDATE hr_personnel SET full_name = ?, user_id = ?, job_title = ?, phone = ?, national_id = ?, hired_at = ?, note = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param('sisssssii', $name, $userId, $job, $phone, $nid, $hired, $note, $active, $id);
            $stmt->execute();
            log_activity($this->conn, 'hr_update', "ویرایش پرونده پرسنلی: {$name}");
            return ['success' => true, 'id' => $id];
        }
        $stmt = $this->conn->prepare("INSERT INTO hr_personnel (entity_id, full_name, user_id, job_title, phone, national_id, hired_at, note, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isisssssi', $e, $name, $userId, $job, $phone, $nid, $hired, $note, $active);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id;
        log_activity($this->conn, 'hr_create', "پرونده پرسنلی جدید: {$name}");
        return ['success' => true, 'id' => $newId];
    }

    public function detail(int $e, int $id): array {
        $p = $this->conn->query("SELECT * FROM hr_personnel WHERE id = {$id} AND entity_id = {$e}")->fetch_assoc();
        if (!$p) {
            return self::err('پرونده یافت نشد.', 404);
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $records = $this->conn->query("SELECT r.*, wr.number AS request_number FROM hr_records r LEFT JOIN work_requests wr ON wr.id = r.request_id
            WHERE r.personnel_id = {$id} ORDER BY r.date DESC, r.id DESC")->fetch_all(MYSQLI_ASSOC);
        $summary = [];
        foreach (self::KINDS as $k => $_) {
            $summary[$k] = ['count' => 0, 'total' => 0.0];
        }
        foreach ($records as &$r) {
            $r['kind_label'] = self::KINDS[$r['kind']][0] ?? $r['kind'];
            $r['created_by_name'] = $names[(int)$r['created_by']] ?? '';
            $summary[$r['kind']]['count']++;
            $summary[$r['kind']]['total'] += (float)($r['amount'] ?? 0);
        }
        unset($r);
        if ($p['user_id']) {
            $p['username'] = $this->conn->query("SELECT username FROM users WHERE id = " . (int)$p['user_id'])->fetch_assoc()['username'] ?? null;
        }
        return ['success' => true, 'person' => $p, 'records' => $records, 'summary' => $summary];
    }

    public function addRecord(int $e, int $uid, int $id, array $d): array {
        if (!$this->conn->query("SELECT id FROM hr_personnel WHERE id = {$id} AND entity_id = {$e}")->fetch_assoc()) {
            return self::err('پرونده یافت نشد.', 404);
        }
        $kind = (string)($d['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            return self::err('نوع سابقه نامعتبر است.');
        }
        $date = trim((string)($d['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return self::err('تاریخ نامعتبر است.');
        }
        $note = trim((string)($d['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 2000) {
            return self::err('توضیح را بنویسید (حداکثر ۲۰۰۰ نویسه).');
        }
        $amount = null;
        if (self::KINDS[$kind][1]) {
            $amount = parse_money($d['amount'] ?? 0);
            if ($amount <= 0) {
                return self::err('مبلغ را وارد کنید.');
            }
        }
        $stmt = $this->conn->prepare("INSERT INTO hr_records (personnel_id, kind, date, amount, note, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issdsi', $id, $kind, $date, $amount, $note, $uid);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id;
        log_activity($this->conn, 'hr_record_add', "سابقه «" . self::KINDS[$kind][0] . "» در پرونده #{$id}");
        return ['success' => true, 'id' => $newId];
    }

    public function deleteRecord(int $e, int $uid, int $recordId): array {
        $r = $this->conn->query("SELECT r.* FROM hr_records r JOIN hr_personnel p ON p.id = r.personnel_id WHERE r.id = {$recordId} AND p.entity_id = {$e}")->fetch_assoc();
        if (!$r) {
            return self::err('مورد یافت نشد.', 404);
        }
        if ($r['request_id']) {
            return self::err('این مورد از یک درخواست پرداخت ثبت شده و از همان‌جا پیگیری می‌شود.');
        }
        if ((int)$r['created_by'] !== $uid) {
            return self::err('فقط ثبت‌کننده می‌تواند این مورد را حذف کند.', 403);
        }
        $this->conn->query("DELETE FROM hr_records WHERE id = {$recordId}");
        log_activity($this->conn, 'hr_record_delete', "حذف سابقه از پرونده #{$r['personnel_id']}");
        return ['success' => true];
    }

    /** A file for every active team member with a login who has none yet. Returns how many were made. */
    public function importTeam(int $e, int $uid): int {
        $made = 0;
        foreach ((new TeamMember($this->conn))->list($e, true) as $m) {
            $mu = (int)$m['user_id'];
            if ($mu <= 0 || $this->conn->query("SELECT id FROM hr_personnel WHERE entity_id = {$e} AND user_id = {$mu}")->fetch_assoc()) {
                continue;
            }
            $r = $this->save($e, $uid, ['full_name' => $m['full_name'], 'user_id' => $mu, 'phone' => $m['mobile'] ?? null, 'job_title' => $m['kind'] === 'partner' ? 'شریک' : null]);
            if (!empty($r['success'])) {
                $made++;
            }
        }
        return $made;
    }

    /** Active staff for the payment-request form. */
    public function options(int $e): array {
        return $this->conn->query("SELECT id, full_name FROM hr_personnel WHERE entity_id = {$e} AND is_active = 1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
    }
}
