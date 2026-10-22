<?php
// /api/models/WorkMeeting.php
// Meetings (rules from mz7 src/core/meetings): the creator and attendees see a
// meeting, manage_projects holders see all. Decisions (مصوبه) have an
// assignee and due date and can become a task of the meeting's project once.
require_once __DIR__ . '/WorkProject.php';
require_once __DIR__ . '/WorkTask.php';

class WorkMeeting {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    private static function notFound(): array {
        return self::err('جلسه یافت نشد.', 404);
    }

    /** The meeting row with `attendee_ids` when the viewer may see it, else null. */
    public function visible(int $e, int $uid, bool $seesAll, int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM meetings WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $e);
        $stmt->execute();
        $m = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$m) {
            return null;
        }
        $res = $this->conn->query("SELECT user_id FROM meeting_attendees WHERE meeting_id = {$id}");
        $m['attendee_ids'] = array_map('intval', array_column($res ? $res->fetch_all(MYSQLI_ASSOC) : [], 'user_id'));
        $active = in_array($uid, TeamMember::userIdsForEntity($this->conn, $e), true);
        $mine = $active && ((int)$m['created_by'] === $uid || in_array($uid, $m['attendee_ids'], true));
        return ($seesAll || $mine) ? $m : null;
    }

    private static function canEdit(array $m, int $uid, bool $seesAll): bool {
        return $seesAll || (int)$m['created_by'] === $uid;
    }

    /** Validated columns or error. */
    private function clean(int $e, int $uid, bool $seesAll, array $d, ?array $old = null): array {
        $title = trim((string)($d['title'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
            return self::err('موضوع جلسه را بنویسید (حداکثر ۲۰۰ نویسه).');
        }
        $date = trim((string)($d['date'] ?? ''));
        $time = trim((string)($d['time'] ?? '')) ?: '09:00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return self::err('تاریخ یا ساعت جلسه نامعتبر است.');
        }
        [$h, $i] = array_map('intval', explode(':', $time));
        $startsAt = sprintf('%s %02d:%02d:00', $date, $h, $i);
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $startsAt);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $startsAt) {
            return self::err('تاریخ یا ساعت جلسه نامعتبر است.');
        }
        $texts = [];
        foreach (['location' => 200, 'agenda' => 5000, 'minutes' => 20000] as $k => $max) {
            $v = trim((string)($d[$k] ?? ''));
            if (mb_strlen($v) > $max) {
                return self::err("متن «{$k}» بیش از حد مجاز است.");
            }
            $texts[$k] = $v !== '' ? $v : null;
        }
        $projectId = (int)($d['project_id'] ?? 0) ?: null;
        if ($projectId !== null && $projectId !== (int)($old['project_id'] ?? 0)) {
            $acc = (new WorkProject($this->conn))->access($e, $uid, $seesAll, $projectId);
            if (!$acc || !in_array($acc['project']['status'], ['planning', 'active', 'on_hold'], true)) {
                return self::err('پروژه نامعتبر است.');
            }
        }
        $team = TeamMember::userIdsForEntity($this->conn, $e);
        $attendees = array_values(array_unique(array_map('intval', (array)($d['attendee_ids'] ?? []))));
        foreach ($attendees as $a) {
            if (!in_array($a, $team, true)) {
                return self::err('یکی از حاضران نامعتبر است.');
            }
        }
        return ['title' => $title, 'starts_at' => $startsAt, 'project_id' => $projectId, 'attendees' => $attendees] + $texts;
    }

    private function invite(int $e, int $uid, int $meetingId, array $v, array $userIds): void {
        $who = TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
        $when = gregorian_to_jalali(substr($v['starts_at'], 0, 10)) . ' ساعت ' . substr($v['starts_at'], 11, 5);
        foreach ($userIds as $to) {
            if ($to !== $uid) {
                CrmNotifier::notify($this->conn, $e, $to, 'work_meeting', "دعوت به جلسه: {$v['title']}", "{$who} — {$when}", "./index.php#work/meeting/{$meetingId}", 'meeting', $meetingId);
            }
        }
    }

    public function create(int $e, int $uid, bool $seesAll, array $d): array {
        $v = $this->clean($e, $uid, $seesAll, $d);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare("INSERT INTO meetings (entity_id, title, project_id, starts_at, location, agenda, minutes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isissssi', $e, $v['title'], $v['project_id'], $v['starts_at'], $v['location'], $v['agenda'], $v['minutes'], $uid);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        foreach ($v['attendees'] as $a) {
            $this->conn->query("INSERT IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES ({$id}, {$a})");
        }
        log_activity($this->conn, 'work_meeting_create', "جلسه جدید: {$v['title']}");
        $this->invite($e, $uid, $id, $v, $v['attendees']);
        return ['success' => true, 'id' => $id];
    }

    public function update(int $e, int $uid, bool $seesAll, int $id, array $d): array {
        $m = $this->visible($e, $uid, $seesAll, $id);
        if (!$m) {
            return self::notFound();
        }
        if (!self::canEdit($m, $uid, $seesAll)) {
            return self::err('فقط ثبت‌کننده جلسه می‌تواند آن را ویرایش کند.', 403);
        }
        $v = $this->clean($e, $uid, $seesAll, $d, $m);
        if (isset($v['error'])) {
            return $v;
        }
        $resetReminder = $v['starts_at'] !== $m['starts_at'] ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE meetings SET title = ?, project_id = ?, starts_at = ?, location = ?, agenda = ?, minutes = ?, reminded_at = IF(?, NULL, reminded_at) WHERE id = ?");
        $stmt->bind_param('sissssii', $v['title'], $v['project_id'], $v['starts_at'], $v['location'], $v['agenda'], $v['minutes'], $resetReminder, $id);
        $stmt->execute();
        $keep = $v['attendees'] ? implode(',', $v['attendees']) : '0';
        $this->conn->query("DELETE FROM meeting_attendees WHERE meeting_id = {$id} AND user_id NOT IN ({$keep})");
        $new = array_values(array_diff($v['attendees'], $m['attendee_ids']));
        foreach ($new as $a) {
            $this->conn->query("INSERT IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES ({$id}, {$a})");
        }
        $this->invite($e, $uid, $id, $v, $new);
        return ['success' => true, 'id' => $id];
    }

    public function delete(int $e, int $uid, bool $seesAll, int $id): array {
        $m = $this->visible($e, $uid, $seesAll, $id);
        if (!$m) {
            return self::notFound();
        }
        if (!self::canEdit($m, $uid, $seesAll)) {
            return self::err('اجازه حذف این جلسه را ندارید.', 403);
        }
        $files = WorkFiles::listFor($this->conn, $e, 'meeting', $id);
        $this->conn->begin_transaction();
        $this->conn->query("DELETE FROM meeting_actions WHERE meeting_id = {$id}");
        $this->conn->query("DELETE FROM meeting_attendees WHERE meeting_id = {$id}");
        $this->conn->query("DELETE FROM meetings WHERE id = {$id}");
        $this->conn->commit();
        WorkFiles::deleteRows($this->conn, $files);
        log_activity($this->conn, 'work_meeting_delete', "حذف جلسه «{$m['title']}»");
        return ['success' => true];
    }

    /** filter: upcoming | past | actions (my open decisions). */
    public function listFor(int $e, int $uid, bool $seesAll, string $filter): array {
        $active = in_array($uid, TeamMember::userIdsForEntity($this->conn, $e), true);
        if ($filter === 'actions') {
            if (!$active) {
                return [];
            }
            $res = $this->conn->query("SELECT a.*, m.title AS meeting_title, m.starts_at FROM meeting_actions a
                JOIN meetings m ON m.id = a.meeting_id
                LEFT JOIN project_tasks t ON t.id = a.task_id
                WHERE m.entity_id = {$e} AND a.assignee_user_id = {$uid} AND a.done = 0 AND (t.id IS NULL OR t.status <> 'done')
                ORDER BY a.due_date IS NULL, a.due_date, a.created_at LIMIT 200");
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        // A meeting stays «upcoming» for the rest of the day it started.
        $edge = date('Y-m-d H:i:s', time() - 12 * 3600);
        $vis = $seesAll ? '1' : ($active ? "(m.created_by = {$uid} OR EXISTS (SELECT 1 FROM meeting_attendees x WHERE x.meeting_id = m.id AND x.user_id = {$uid}))" : '0');
        $when = $filter === 'past' ? "m.starts_at < '{$edge}'" : "m.starts_at >= '{$edge}'";
        $order = $filter === 'past' ? 'DESC' : 'ASC';
        $res = $this->conn->query("SELECT m.*, p.title AS project_title, cr.full_name AS created_by_name,
                (SELECT COUNT(*) FROM meeting_attendees x WHERE x.meeting_id = m.id) AS attendee_count,
                (SELECT COUNT(*) FROM meeting_actions y WHERE y.meeting_id = m.id) AS action_count
            FROM meetings m
            LEFT JOIN projects p ON p.id = m.project_id
            LEFT JOIN team_members cr ON cr.entity_id = m.entity_id AND cr.user_id = m.created_by
            WHERE m.entity_id = {$e} AND {$vis} AND {$when} ORDER BY m.starts_at {$order} LIMIT 200");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function detail(int $e, int $uid, bool $seesAll, int $id): array {
        $m = $this->visible($e, $uid, $seesAll, $id);
        if (!$m) {
            return self::notFound();
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $res = $this->conn->query("SELECT a.*, t.status AS task_status, t.project_id AS task_project_id FROM meeting_actions a
            LEFT JOIN project_tasks t ON t.id = a.task_id WHERE a.meeting_id = {$id} ORDER BY a.sort_order, a.created_at");
        $actions = [];
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $a) {
            $a['is_done'] = (int)$a['done'] === 1 || $a['task_status'] === 'done';
            $a['assignee_name'] = $a['assignee_user_id'] ? ($names[(int)$a['assignee_user_id']] ?? null) : null;
            $actions[] = $a;
        }
        $project = null;
        if ($m['project_id']) {
            $r = $this->conn->query("SELECT id, title FROM projects WHERE id = " . (int)$m['project_id']);
            $project = $r ? $r->fetch_assoc() : null;
        }
        return [
            'success' => true,
            'meeting' => $m + ['created_by_name' => $names[(int)$m['created_by']] ?? null],
            'project' => $project,
            'attendees' => array_map(fn($a) => ['user_id' => $a, 'full_name' => $names[$a] ?? ''], $m['attendee_ids']),
            'actions' => $actions,
            'files' => WorkFiles::listFor($this->conn, $e, 'meeting', $id),
            'can_edit' => self::canEdit($m, $uid, $seesAll),
        ];
    }

    // ---------- decisions ----------

    public function addAction(int $e, int $uid, bool $seesAll, int $id, array $d): array {
        $m = $this->visible($e, $uid, $seesAll, $id);
        if (!$m) {
            return self::notFound();
        }
        $text = trim((string)($d['text'] ?? ''));
        if (mb_strlen($text) < 2 || mb_strlen($text) > 1000) {
            return self::err('متن مصوبه را بنویسید (حداکثر ۱۰۰۰ نویسه).');
        }
        $assignee = (int)($d['assignee_user_id'] ?? 0) ?: null;
        if ($assignee !== null && !in_array($assignee, TeamMember::userIdsForEntity($this->conn, $e), true)) {
            return self::err('مسئول مصوبه عضو فعال تیم نیست.');
        }
        $due = trim((string)($d['due_date'] ?? '')) ?: null;
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return self::err('تاریخ مهلت نامعتبر است.');
        }
        $order = (int)$this->conn->query("SELECT COUNT(*) n FROM meeting_actions WHERE meeting_id = {$id}")->fetch_assoc()['n'] + 1;
        $stmt = $this->conn->prepare("INSERT INTO meeting_actions (meeting_id, text, assignee_user_id, due_date, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('isisi', $id, $text, $assignee, $due, $order);
        $stmt->execute();
        $aid = (int)$this->conn->insert_id;
        if ($assignee && $assignee !== $uid) {
            CrmNotifier::notify($this->conn, $e, $assignee, 'work_meeting', "مصوبه جلسه «{$m['title']}» به شما سپرده شد",
                mb_substr($text, 0, 160), "./index.php#work/meeting/{$id}/action/{$aid}", 'meeting_action', $aid);
        }
        return ['success' => true, 'id' => $aid];
    }

    private function actionCtx(int $e, int $uid, bool $seesAll, int $meetingId, int $actionId) {
        $m = $this->visible($e, $uid, $seesAll, $meetingId);
        if (!$m) {
            return self::notFound();
        }
        $res = $this->conn->query("SELECT * FROM meeting_actions WHERE id = {$actionId} AND meeting_id = {$meetingId}");
        $a = $res ? $res->fetch_assoc() : null;
        if (!$a) {
            return self::err('مصوبه یافت نشد.', 404);
        }
        return ['meeting' => $m, 'action' => $a];
    }

    public function setActionDone(int $e, int $uid, bool $seesAll, int $meetingId, int $actionId, bool $done): array {
        $ctx = $this->actionCtx($e, $uid, $seesAll, $meetingId, $actionId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canEdit($ctx['meeting'], $uid, $seesAll) && (int)$ctx['action']['assignee_user_id'] !== $uid) {
            return self::err('فقط مسئول مصوبه یا ثبت‌کننده جلسه.', 403);
        }
        $this->conn->query("UPDATE meeting_actions SET done = " . ($done ? 1 : 0) . " WHERE id = {$actionId}");
        return ['success' => true];
    }

    /** Turn a decision into a task of the meeting's project (once). */
    public function actionToTask(int $e, int $uid, bool $seesAll, int $meetingId, int $actionId): array {
        $ctx = $this->actionCtx($e, $uid, $seesAll, $meetingId, $actionId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $m = $ctx['meeting'];
        $a = $ctx['action'];
        if (!self::canEdit($m, $uid, $seesAll)) {
            return self::err('فقط ثبت‌کننده جلسه می‌تواند مصوبه را به کار تبدیل کند.', 403);
        }
        if (!$m['project_id']) {
            return self::err('جلسه به پروژه‌ای وصل نیست.');
        }
        if ($a['task_id']) {
            return self::err('این مصوبه قبلاً کار شده است.');
        }
        $pid = (int)$m['project_id'];
        $isMember = $a['assignee_user_id'] && (int)$this->conn->query("SELECT COUNT(*) n FROM project_members WHERE project_id = {$pid} AND user_id = " . (int)$a['assignee_user_id'])->fetch_assoc()['n'] > 0;
        $taskId = WorkTask::insertRow($this->conn, $pid, [
            'title' => mb_substr($a['text'], 0, 300),
            'description' => "مصوبه جلسه «{$m['title']}»",
            'assignee_user_id' => $isMember ? (int)$a['assignee_user_id'] : null,
            'due_date' => $a['due_date'],
            'created_by' => $uid,
        ]);
        $this->conn->query("UPDATE meeting_actions SET task_id = {$taskId} WHERE id = {$actionId}");
        if ($isMember && (int)$a['assignee_user_id'] !== $uid) {
            CrmNotifier::notify($this->conn, $e, (int)$a['assignee_user_id'], 'work_task', "کار جدید از جلسه «{$m['title']}»",
                mb_substr($a['text'], 0, 160), "./index.php#work/project/{$pid}/task/{$taskId}", 'project_task', $taskId);
        }
        return ['success' => true, 'task_id' => $taskId, 'assigned' => (bool)$isMember];
    }

    public function deleteAction(int $e, int $uid, bool $seesAll, int $meetingId, int $actionId): array {
        $ctx = $this->actionCtx($e, $uid, $seesAll, $meetingId, $actionId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canEdit($ctx['meeting'], $uid, $seesAll)) {
            return self::err('اجازه حذف مصوبه ندارید.', 403);
        }
        $this->conn->query("DELETE FROM meeting_actions WHERE id = {$actionId}");
        return ['success' => true];
    }

    public function addFiles(int $e, int $uid, bool $seesAll, int $id, array $files, bool $fromUpload = true): array {
        if (!$this->visible($e, $uid, $seesAll, $id)) {
            return self::notFound();
        }
        $r = WorkFiles::store($this->conn, $e, 'meeting', $id, $uid, $files, [], $fromUpload);
        return !empty($r['error']) ? self::err($r['error']) : $r;
    }

    public function deleteFile(int $e, int $uid, bool $seesAll, int $fileId): array {
        $f = WorkFiles::get($this->conn, $e, $fileId);
        $m = ($f && $f['owner_type'] === 'meeting') ? $this->visible($e, $uid, $seesAll, (int)$f['owner_id']) : null;
        if (!$m) {
            return self::err('فایل یافت نشد.', 404);
        }
        if ((int)$f['uploaded_by'] !== $uid && !self::canEdit($m, $uid, $seesAll)) {
            return self::err('فقط آپلودکننده یا ثبت‌کننده جلسه می‌تواند فایل را حذف کند.', 403);
        }
        WorkFiles::deleteRows($this->conn, [$f]);
        return ['success' => true];
    }
}
