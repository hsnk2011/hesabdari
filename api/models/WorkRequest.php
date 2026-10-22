<?php
// /api/models/WorkRequest.php
// Cartable (rules from mz7 src/core/requests/flow.ts): payment requests
// pending → approved | rejected → paid, and letters held by one person who
// refers them on or closes them. Paying books a real expense (once).
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/WorkFiles.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class WorkRequest {
    const CATEGORIES = [
        'ADVANCE' => 'مساعده', 'SALARY' => 'حقوق و پاداش', 'PURCHASE' => 'خرید کالا و ملزومات', 'REPAIR' => 'تعمیرات و خدمات',
        'PETTY_CASH' => 'شارژ تنخواه', 'TRANSPORT' => 'حمل‌ونقل و کرایه', 'SUPPLIER' => 'پرداخت به تأمین‌کننده', 'OTHER' => 'سایر',
    ];
    const ACTION_LABEL = ['create' => 'ثبت', 'approve' => 'تأیید', 'reject' => 'رد', 'pay' => 'ثبت پرداخت', 'refer' => 'ارجاع',
        'comment' => 'یادداشت', 'done' => 'بستن با نتیجه', 'cancel' => 'لغو', 'reopen' => 'ارسال دوباره'];
    const NOTE_REQUIRED = ['reject', 'done', 'comment', 'refer'];

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    private static function notFound(): array {
        return self::err('درخواست یافت نشد.', 404);
    }

    /** approve / pay / all from the session permissions. */
    public static function permsFromSession(): array {
        $p = $_SESSION['permissions'] ?? [];
        return ['approve' => in_array('approve_payments', $p, true), 'pay' => in_array('pay_payments', $p, true) && in_array('manage_transactions', $p, true),
            'all' => in_array('manage_projects', $p, true)];
    }

    /** approve / pay / all of a user from their roles (for scheduled jobs and «کارهای من»). */
    public function userPerms(int $uid): array {
        $res = $this->conn->query("SELECT r.is_system, p.permission_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = {$uid}");
        $keys = [];
        $system = false;
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
            $system = $system || (int)$r['is_system'] === 1;
            if ($r['permission_key']) {
                $keys[$r['permission_key']] = true;
            }
        }
        $has = fn($k) => $system || isset($keys[$k]);
        return ['approve' => $has('approve_payments'), 'pay' => $has('pay_payments') && $has('manage_transactions'), 'all' => $has('manage_projects')];
    }

    /** Actions the user may take now (port of mz7 allowedActions). */
    public static function allowedActions(array $req, int $uid, array $perms): array {
        $out = [];
        $creator = (int)$req['created_by'] === $uid;
        if ($req['kind'] === 'payment') {
            if ($req['status'] === 'pending') {
                if ($perms['approve']) {
                    array_push($out, 'approve', 'reject');
                }
                if ($creator) {
                    $out[] = 'cancel';
                }
            } elseif ($req['status'] === 'approved') {
                if ($perms['pay']) {
                    $out[] = 'pay';
                }
                // An approval can be taken back before the money moves.
                if ($perms['approve']) {
                    $out[] = 'reject';
                }
            } elseif (in_array($req['status'], ['rejected', 'cancelled'], true) && $creator) {
                $out[] = 'reopen';
            }
        } else {
            if ($req['status'] === 'pending') {
                if ((int)$req['assignee_user_id'] === $uid) {
                    array_push($out, 'refer', 'done');
                }
                if ($creator) {
                    $out[] = 'cancel';
                }
            } elseif ($creator) {
                $out[] = 'reopen';
            }
        }
        $out[] = 'comment';
        return $out;
    }

    private static function statusAfter(string $status, string $action): string {
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'pay' => 'paid', 'done' => 'done', 'cancel' => 'cancelled', 'reopen' => 'pending'];
        return $map[$action] ?? $status;
    }

    /** Active team users holding a permission (system-role users hold all). */
    public function usersWithPermission(int $e, string $key): array {
        $stmt = $this->conn->prepare("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE r.is_system = 1 OR p.permission_key = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $ids = array_map('intval', array_column(db_stmt_to_assoc_array($stmt), 'user_id'));
        return array_values(array_intersect($ids, TeamMember::userIdsForEntity($this->conn, $e)));
    }

    private function row(int $e, int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM work_requests WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $e);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    private function wasHolder(int $id, int $uid): bool {
        return (int)$this->conn->query("SELECT COUNT(*) n FROM work_request_events WHERE request_id = {$id} AND to_user_id = {$uid}")->fetch_assoc()['n'] > 0;
    }

    /** May the user open this request? */
    private function canSee(array $req, int $uid, array $perms): bool {
        if ($perms['all'] || (int)$req['created_by'] === $uid) {
            return true;
        }
        if ($req['kind'] === 'letter') {
            return (int)$req['assignee_user_id'] === $uid || $this->wasHolder((int)$req['id'], $uid);
        }
        return $perms['approve'] || ($perms['pay'] && in_array($req['status'], ['approved', 'paid'], true));
    }

    private function event(int $id, int $uid, string $action, ?string $note = null, ?int $to = null): void {
        $stmt = $this->conn->prepare("INSERT INTO work_request_events (request_id, actor_user_id, action, note, to_user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iissi', $id, $uid, $action, $note, $to);
        $stmt->execute();
    }

    private function notify(int $e, array $req, array $userIds, int $actor, string $title, string $body): void {
        foreach (array_unique(array_map('intval', array_filter($userIds))) as $to) {
            if ($to !== $actor) {
                CrmNotifier::notify($this->conn, $e, $to, 'work_request', $title, $body, "./index.php#work/request/{$req['id']}", 'work_request', (int)$req['id']);
            }
        }
    }

    private function label(array $req): string {
        return ($req['kind'] === 'payment' ? 'درخواست پرداخت' : 'نامه') . " #{$req['number']}: {$req['title']}";
    }

    // ---------- create ----------

    public function create(int $e, int $uid, array $d, array $files = [], bool $fromUpload = true): array {
        $team = TeamMember::userIdsForEntity($this->conn, $e);
        if (!in_array($uid, $team, true)) {
            return self::err('حساب شما عضو تیم نیست.', 403);
        }
        $kind = $d['kind'] ?? '';
        if (!in_array($kind, ['payment', 'letter'], true)) {
            return self::err('نوع درخواست نامعتبر است.');
        }
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            return self::err('عنوان را بنویسید (حداکثر ۲۰۰ نویسه).');
        }
        $body = trim((string)($d['body'] ?? ''));
        if (mb_strlen($body) > 5000) {
            return self::err('شرح حداکثر ۵۰۰۰ نویسه است.');
        }
        $category = $amount = $payee = $supplier = $personnel = $project = $assignee = null;
        if ($kind === 'payment') {
            $category = isset(self::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'OTHER';
            $amount = parse_money($d['amount'] ?? 0);
            if ($amount <= 0) {
                return self::err('مبلغ را وارد کنید.');
            }
            $payee = mb_substr(trim((string)($d['payee'] ?? '')), 0, 200) ?: null;
            $supplier = (int)($d['supplier_id'] ?? 0) ?: null;
            if ($supplier && !$this->conn->query("SELECT id FROM suppliers WHERE id = {$supplier} AND entity_id = {$e}")->fetch_assoc()) {
                return self::err('تأمین‌کننده نامعتبر است.');
            }
            $personnel = (int)($d['personnel_id'] ?? 0) ?: null;
            if ($personnel && !$this->conn->query("SELECT id FROM hr_personnel WHERE id = {$personnel} AND entity_id = {$e}")->fetch_assoc()) {
                return self::err('پرسنل نامعتبر است.');
            }
            $project = (int)($d['project_id'] ?? 0) ?: null;
            if ($project && !$this->conn->query("SELECT id FROM projects WHERE id = {$project} AND entity_id = {$e}")->fetch_assoc()) {
                return self::err('پروژه نامعتبر است.');
            }
        } else {
            $assignee = (int)($d['assignee_user_id'] ?? 0) ?: null;
            if (!$assignee || !in_array($assignee, $team, true)) {
                return self::err('نامه را به یکی از اعضای تیم بسپارید.');
            }
        }
        $due = trim((string)($d['due_date'] ?? '')) ?: null;
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return self::err('تاریخ مهلت نامعتبر است.');
        }
        $this->conn->begin_transaction();
        try {
            $n = (int)$this->conn->query("SELECT COALESCE(MAX(number), 0) + 1 n FROM work_requests WHERE entity_id = {$e} FOR UPDATE")->fetch_assoc()['n'];
            $stmt = $this->conn->prepare("INSERT INTO work_requests (entity_id, number, kind, title, body, category, amount, payee, supplier_id, personnel_id, project_id, created_by, assignee_user_id, due_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $bodyVal = $body !== '' ? $body : null;
            $stmt->bind_param('iissssdsiiiiis', $e, $n, $kind, $title, $bodyVal, $category, $amount, $payee, $supplier, $personnel, $project, $uid, $assignee, $due);
            $stmt->execute();
            $id = (int)$this->conn->insert_id;
            $this->event($id, $uid, 'create', null, $assignee);
            if ($files) {
                $stored = WorkFiles::store($this->conn, $e, 'request', $id, $uid, $files, [], $fromUpload);
                if (!empty($stored['error'])) {
                    $this->conn->rollback();
                    return self::err($stored['error']);
                }
            }
            $this->conn->commit();
        } catch (Throwable $ex) {
            $this->conn->rollback();
            throw $ex;
        }
        log_activity($this->conn, 'work_request_create', "کارتابل: {$title}");
        $req = $this->row($e, $id);
        $who = TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
        if ($kind === 'payment') {
            $this->notify($e, $req, $this->usersWithPermission($e, 'approve_payments'), $uid, 'درخواست پرداخت جدید: ' . $title,
                "{$who} — " . number_format((float)$amount) . ' — ' . self::CATEGORIES[$category]);
        } else {
            $this->notify($e, $req, [$assignee], $uid, 'نامه جدید: ' . $title, "{$who} این نامه را به شما سپرد.");
        }
        return ['success' => true, 'id' => $id, 'number' => $n];
    }

    // ---------- read ----------

    public function listFor(int $e, int $uid, array $perms, string $tab, ?string $kind): array {
        $where = ["r.entity_id = {$e}"];
        if ($kind === 'payment' || $kind === 'letter') {
            $where[] = "r.kind = '{$kind}'";
        }
        if ($tab === 'mine') {
            $where[] = "r.created_by = {$uid}";
        } elseif ($tab === 'mine_action') {
            $or = ["(r.kind = 'letter' AND r.status = 'pending' AND r.assignee_user_id = {$uid})"];
            if ($perms['approve']) {
                $or[] = "(r.kind = 'payment' AND r.status = 'pending')";
            }
            if ($perms['pay']) {
                $or[] = "(r.kind = 'payment' AND r.status = 'approved')";
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        } elseif (!$perms['all']) {
            $or = ["r.created_by = {$uid}", "r.assignee_user_id = {$uid}", "EXISTS (SELECT 1 FROM work_request_events ev WHERE ev.request_id = r.id AND ev.to_user_id = {$uid})"];
            if ($perms['approve']) {
                $or[] = "r.kind = 'payment'";
            } elseif ($perms['pay']) {
                $or[] = "(r.kind = 'payment' AND r.status IN ('approved','paid'))";
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $res = $this->conn->query("SELECT r.*, cr.full_name AS created_by_name, asg.full_name AS assignee_name, p.title AS project_title
            FROM work_requests r
            LEFT JOIN team_members cr ON cr.entity_id = r.entity_id AND cr.user_id = r.created_by
            LEFT JOIN team_members asg ON asg.entity_id = r.entity_id AND asg.user_id = r.assignee_user_id
            LEFT JOIN projects p ON p.id = r.project_id
            WHERE " . implode(' AND ', $where) . " ORDER BY r.status IN ('pending','approved') DESC, r.updated_at DESC LIMIT 300");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function detail(int $e, int $uid, array $perms, int $id): array {
        $req = $this->row($e, $id);
        if (!$req || !$this->canSee($req, $uid, $perms)) {
            return self::notFound();
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $events = $this->conn->query("SELECT * FROM work_request_events WHERE request_id = {$id} ORDER BY created_at, id")->fetch_all(MYSQLI_ASSOC);
        foreach ($events as &$ev) {
            $ev['actor_name'] = $names[(int)$ev['actor_user_id']] ?? '';
            $ev['to_name'] = $ev['to_user_id'] ? ($names[(int)$ev['to_user_id']] ?? '') : null;
            $ev['label'] = self::ACTION_LABEL[$ev['action']] ?? $ev['action'];
        }
        unset($ev);
        $extra = [];
        if ($req['project_id']) {
            $extra['project_title'] = $this->conn->query("SELECT title FROM projects WHERE id = " . (int)$req['project_id'])->fetch_assoc()['title'] ?? null;
        }
        if ($req['supplier_id']) {
            $extra['supplier_name'] = $this->conn->query("SELECT name FROM suppliers WHERE id = " . (int)$req['supplier_id'])->fetch_assoc()['name'] ?? null;
        }
        if ($req['personnel_id']) {
            $extra['personnel_name'] = $this->conn->query("SELECT full_name FROM hr_personnel WHERE id = " . (int)$req['personnel_id'])->fetch_assoc()['full_name'] ?? null;
        }
        return [
            'success' => true,
            'request' => $req + $extra + ['created_by_name' => $names[(int)$req['created_by']] ?? '', 'assignee_name' => $req['assignee_user_id'] ? ($names[(int)$req['assignee_user_id']] ?? '') : null,
                'category_label' => $req['category'] ? (self::CATEGORIES[$req['category']] ?? 'سایر') : null],
            'events' => $events,
            'files' => WorkFiles::listFor($this->conn, $e, 'request', $id),
            'actions' => self::allowedActions($req, $uid, $perms),
        ];
    }

    // ---------- act ----------

    public function act(int $e, int $uid, array $perms, int $id, string $action, array $d): array {
        $req = $this->row($e, $id);
        if (!$req || !$this->canSee($req, $uid, $perms)) {
            return self::notFound();
        }
        if (!in_array($action, self::allowedActions($req, $uid, $perms), true)) {
            return self::err('این کار برای شما یا در این وضعیت مجاز نیست.', 403);
        }
        $note = trim((string)($d['note'] ?? ''));
        if (mb_strlen($note) > 2000) {
            return self::err('یادداشت حداکثر ۲۰۰۰ نویسه است.');
        }
        if (in_array($action, self::NOTE_REQUIRED, true) && $note === '') {
            return self::err($action === 'done' ? 'نتیجه را بنویسید.' : ($action === 'reject' ? 'دلیل را بنویسید.' : 'یادداشت را بنویسید.'));
        }
        $team = TeamMember::userIdsForEntity($this->conn, $e);
        $who = TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
        $label = $this->label($req);

        if ($action === 'pay') {
            return $this->pay($e, $uid, $req, $d, $note, $who, $label);
        }
        $to = null;
        if ($action === 'refer') {
            $to = (int)($d['to_user_id'] ?? 0);
            if (!$to || !in_array($to, $team, true) || $to === $uid) {
                return self::err('نامه را به یکی دیگر از اعضای تیم ارجاع دهید.');
            }
            $this->conn->query("UPDATE work_requests SET assignee_user_id = {$to} WHERE id = {$id}");
        }
        $status = self::statusAfter($req['status'], $action);
        $closed = in_array($status, ['paid', 'done', 'cancelled', 'rejected'], true) ? 'NOW()' : 'NULL';
        $stmt = $this->conn->prepare("UPDATE work_requests SET status = ?, closed_at = {$closed}, result = IF(? = 'done', ?, result) WHERE id = ?");
        $stmt->bind_param('sssi', $status, $action, $note, $id);
        $stmt->execute();
        $this->event($id, $uid, $action, $note !== '' ? $note : null, $to);
        log_activity($this->conn, 'work_request_' . $action, "{$label}: " . self::ACTION_LABEL[$action]);

        $creator = (int)$req['created_by'];
        switch ($action) {
            case 'approve':
                $this->notify($e, $req, array_merge([$creator], $this->usersWithPermission($e, 'pay_payments')), $uid, "تأیید شد: {$label}", "{$who}" . ($note ? ": {$note}" : ''));
                break;
            case 'reject':
                $this->notify($e, $req, [$creator], $uid, "رد شد: {$label}", "{$who}: {$note}");
                break;
            case 'refer':
                $this->notify($e, $req, [$to], $uid, "ارجاع به شما: {$label}", "{$who}: {$note}");
                break;
            case 'done':
                $this->notify($e, $req, [$creator], $uid, "بسته شد: {$label}", "{$who}: {$note}");
                break;
            case 'reopen':
                if ($req['kind'] === 'payment') {
                    $this->notify($e, $req, $this->usersWithPermission($e, 'approve_payments'), $uid, "دوباره برای تأیید: {$label}", $who);
                } else {
                    $this->notify($e, $req, [(int)$req['assignee_user_id']], $uid, "دوباره باز شد: {$label}", $who);
                }
                break;
            case 'comment':
                $people = array_merge([$creator, (int)$req['assignee_user_id']], array_map('intval', array_column(
                    $this->conn->query("SELECT DISTINCT actor_user_id FROM work_request_events WHERE request_id = {$id}")->fetch_all(MYSQLI_ASSOC), 'actor_user_id')));
                $this->notify($e, $req, $people, $uid, "یادداشت جدید: {$label}", "{$who}: " . mb_substr($note, 0, 140));
                break;
        }
        return ['success' => true, 'status' => $status];
    }

    /**
     * Book the expense exactly once: the request is claimed with a conditional
     * UPDATE first (two payers or a double click cannot both pass), released
     * again if booking fails.
     */
    private function pay(int $e, int $uid, array $req, array $d, string $note, string $who, string $label): array {
        require_once __DIR__ . '/Expense.php';
        require_once __DIR__ . '/Account.php';
        $id = (int)$req['id'];
        $date = trim((string)($d['date'] ?? '')) ?: date('Y-m-d');
        $category = trim((string)($d['expense_category'] ?? ''));
        if ($category === '') {
            return self::err('دسته هزینه را انتخاب کنید.');
        }
        $this->conn->query("UPDATE work_requests SET status = 'paid', expense_id = 0 WHERE id = {$id} AND status = 'approved' AND expense_id IS NULL");
        if ($this->conn->affected_rows !== 1) {
            return self::err('این درخواست قبلاً پرداخت شده یا در وضعیت پرداخت نیست.');
        }
        $person = $req['supplier_id'] ? ['person_type' => 'supplier', 'person_id' => (int)$req['supplier_id']] : [];
        try {
            $res = (new Expense($this->conn))->save($person + [
                'category' => $category, 'date' => $date, 'amount' => (float)$req['amount'],
                'description' => "درخواست پرداخت #{$req['number']}: {$req['title']}" . ($req['payee'] ? " — {$req['payee']}" : ''),
                'account_id' => (int)($d['account_id'] ?? 0), 'project_id' => $req['project_id'] ? (int)$req['project_id'] : null,
            ]);
        } catch (Throwable $ex) {
            $res = ['error' => $ex->getMessage()];
        }
        if (empty($res['success'])) {
            $this->conn->query("UPDATE work_requests SET status = 'approved', expense_id = NULL WHERE id = {$id}");
            return self::err($res['error'] ?? 'ثبت هزینه ناموفق بود.');
        }
        $expenseId = (int)$res['id'];
        $this->conn->query("UPDATE work_requests SET expense_id = {$expenseId}, closed_at = NOW() WHERE id = {$id}");
        // Paid advance / salary for a staff member goes into their file (once).
        if ($req['personnel_id'] && in_array($req['category'], ['ADVANCE', 'SALARY'], true)) {
            $kind = $req['category'] === 'ADVANCE' ? 'advance' : 'other';
            $recNote = ($req['category'] === 'ADVANCE' ? 'مساعده' : 'پرداخت حقوق و پاداش') . " — درخواست #{$req['number']}";
            $stmt = $this->conn->prepare("INSERT IGNORE INTO hr_records (personnel_id, kind, date, amount, note, request_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $amount = (float)$req['amount'];
            $pid = (int)$req['personnel_id'];
            $stmt->bind_param('issdsii', $pid, $kind, $date, $amount, $recNote, $id, $uid);
            $stmt->execute();
        }
        $this->event($id, $uid, 'pay', $note !== '' ? $note : null);
        log_activity($this->conn, 'work_request_pay', "{$label}: پرداخت (هزینه #{$expenseId})");
        $this->notify($e, $req, [(int)$req['created_by']], $uid, "پرداخت شد: {$label}", "{$who} — " . number_format((float)$req['amount']));
        return ['success' => true, 'status' => 'paid', 'expense_id' => $expenseId];
    }

    public function addFiles(int $e, int $uid, array $perms, int $id, array $files, bool $fromUpload = true): array {
        $req = $this->row($e, $id);
        if (!$req || !$this->canSee($req, $uid, $perms)) {
            return self::notFound();
        }
        $r = WorkFiles::store($this->conn, $e, 'request', $id, $uid, $files, [], $fromUpload);
        return !empty($r['error']) ? self::err($r['error']) : $r;
    }

    /** Accounts and expense categories for the pay dialog. */
    public function payOptions(int $e): array {
        $accounts = $this->conn->query("SELECT id, name FROM accounts WHERE entity_id = {$e} ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $raw = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'expense_categories'")->fetch_assoc()['setting_value'] ?? '';
        $cats = array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
        return ['success' => true, 'accounts' => $accounts, 'categories' => $cats ?: ['متفرقه']];
    }
}
