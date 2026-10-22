<?php
// /api/models/WorkTask.php
// Project tasks (rules from mz7 src/core/projects/task-flow.ts, deps.ts,
// task-extras.ts): finishing is two steps — the doer confirms and the task
// waits in «review»; a project manager approves or sends it back. A manager's
// own confirmation finishes it directly. Prerequisites block start/finish.
require_once __DIR__ . '/WorkProject.php';

class WorkTask {
    const MAX_CHECKS = 50;
    const MAX_DEPS = 20;

    private $conn;
    private $projects;

    public function __construct($db) {
        $this->conn = $db;
        $this->projects = new WorkProject($db);
    }

    private static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    /** Task + project access, or an error array. */
    private function ctx(int $e, int $uid, bool $seesAll, int $taskId) {
        $stmt = $this->conn->prepare("SELECT t.* FROM project_tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ? AND p.entity_id = ?");
        $stmt->bind_param('ii', $taskId, $e);
        $stmt->execute();
        $task = db_stmt_to_assoc_array($stmt)[0] ?? null;
        $acc = $task ? $this->projects->access($e, $uid, $seesAll, (int)$task['project_id']) : null;
        if (!$task || !$acc) {
            return self::err('کار یافت نشد.', 404);
        }
        $acc['task'] = $task;
        $acc['managerIds'] = array_map('intval', array_column(array_filter($acc['members'], fn($m) => $m['role'] === 'manager'), 'user_id'));
        return $acc;
    }

    private static function canMove(array $ctx, int $uid): bool {
        $t = $ctx['task'];
        return $ctx['canManage'] || (int)$t['created_by'] === $uid
            || ($ctx['isMember'] && (empty($t['assignee_user_id']) || (int)$t['assignee_user_id'] === $uid));
    }

    private static function canFullEdit(array $ctx, int $uid): bool {
        return $ctx['canManage'] || (int)$ctx['task']['created_by'] === $uid;
    }

    private function memberIds(array $ctx): array {
        return array_map('intval', array_column($ctx['members'], 'user_id'));
    }

    private function cleanText(?string $s, int $max, string $label) {
        $t = trim((string)$s);
        if (mb_strlen($t) > $max) {
            return self::err("{$label} حداکثر " . $max . ' نویسه است.');
        }
        return $t !== '' ? $t : null;
    }

    private function touch(int $projectId): void {
        $this->conn->query("UPDATE projects SET updated_at = NOW() WHERE id = {$projectId}");
    }

    /** Notify active people of the project, never the actor. */
    private function notify(int $e, array $ctx, array $userIds, int $actor, string $title, string $body): void {
        $active = TeamMember::userIdsForEntity($this->conn, $e);
        $pid = (int)$ctx['project']['id'];
        $tid = (int)$ctx['task']['id'];
        foreach (array_unique(array_map('intval', array_filter($userIds))) as $to) {
            if ($to === $actor || !in_array($to, $active, true)) {
                continue;
            }
            CrmNotifier::notify($this->conn, $e, $to, 'work_task', $title, $body, "./index.php#work/project/{$pid}/task/{$tid}", 'project_task', $tid);
        }
    }

    private function who(int $e, int $uid): string {
        return TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
    }

    private static function stakeholders(array $ctx): array {
        $t = $ctx['task'];
        return array_merge([(int)$t['created_by'], (int)$t['assignee_user_id'], (int)$t['completed_by']], $ctx['managerIds']);
    }

    // ---------- create / edit ----------

    /** Insert a task row (also used for meeting decisions). Returns the new id. */
    public static function insertRow($db, int $projectId, array $v): int {
        $res = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 n FROM project_tasks WHERE project_id = {$projectId}");
        $order = (int)($res ? $res->fetch_assoc()['n'] : 1);
        $stmt = $db->prepare("INSERT INTO project_tasks (project_id, title, description, assignee_user_id, due_date, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $assignee = $v['assignee_user_id'] ?? null;
        $due = $v['due_date'] ?? null;
        $desc = $v['description'] ?? null;
        $by = $v['created_by'] ?? null;
        $stmt->bind_param('issisii', $projectId, $v['title'], $desc, $assignee, $due, $order, $by);
        $stmt->execute();
        return (int)$db->insert_id;
    }

    private function validate(array $ctx, array $d, ?array $old = null): array {
        $title = trim((string)($d['title'] ?? ($old['title'] ?? '')));
        if ($title === '') {
            return self::err('عنوان کار را بنویسید.');
        }
        if (mb_strlen($title) > 300) {
            return self::err('عنوان کار حداکثر ۳۰۰ نویسه است.');
        }
        $desc = array_key_exists('description', $d) ? $this->cleanText($d['description'], 3000, 'توضیح کار') : ($old['description'] ?? null);
        if (is_array($desc)) {
            return $desc;
        }
        $assignee = array_key_exists('assignee_user_id', $d) ? ((int)$d['assignee_user_id'] ?: null) : ($old['assignee_user_id'] ?? null);
        if ($assignee !== null && !in_array((int)$assignee, $this->memberIds($ctx), true)) {
            return self::err('مسئول کار باید عضو پروژه باشد.');
        }
        $due = array_key_exists('due_date', $d) ? (trim((string)$d['due_date']) ?: null) : ($old['due_date'] ?? null);
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return self::err('تاریخ مهلت نامعتبر است.');
        }
        return ['title' => $title, 'description' => $desc, 'assignee_user_id' => $assignee !== null ? (int)$assignee : null, 'due_date' => $due];
    }

    public function create(int $e, int $uid, bool $seesAll, int $projectId, array $d): array {
        $acc = $this->projects->access($e, $uid, $seesAll, $projectId);
        if (!$acc) {
            return self::err('پروژه یافت نشد.', 404);
        }
        if (!$acc['isMember'] && !$acc['canManage']) {
            return self::err('فقط اعضای پروژه می‌توانند کار تعریف کنند.', 403);
        }
        $ctx = $acc + ['task' => ['id' => 0, 'created_by' => $uid, 'assignee_user_id' => null, 'completed_by' => null], 'managerIds' => []];
        $v = $this->validate($ctx, $d);
        if (isset($v['error'])) {
            return $v;
        }
        $id = self::insertRow($this->conn, $projectId, $v + ['created_by' => $uid]);
        if (!empty($d['depends_on'])) {
            $deps = $this->applyDeps($projectId, $id, (array)$d['depends_on']);
            if (isset($deps['error'])) {
                $this->conn->query("DELETE FROM project_tasks WHERE id = {$id}");
                return $deps;
            }
        }
        $this->touch($projectId);
        log_activity($this->conn, 'work_task_create', "کار پروژه: {$v['title']}");
        if ($v['assignee_user_id']) {
            $ctx['task'] = ['id' => $id] + $ctx['task'];
            $this->notify($e, $ctx, [$v['assignee_user_id']], $uid, "کار جدید: «{$v['title']}»", $this->who($e, $uid) . " در پروژه «{$acc['project']['title']}» این کار را به شما سپرد.");
        }
        return ['success' => true, 'id' => $id];
    }

    public function update(int $e, int $uid, bool $seesAll, int $taskId, array $d): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canFullEdit($ctx, $uid)) {
            return self::err('فقط مدیر پروژه یا تعریف‌کننده کار می‌تواند آن را ویرایش کند.', 403);
        }
        $old = $ctx['task'];
        $v = $this->validate($ctx, $d, $old);
        if (isset($v['error'])) {
            return $v;
        }
        $resetReminder = ($v['due_date'] !== $old['due_date']) ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE project_tasks SET title = ?, description = ?, assignee_user_id = ?, due_date = ?, reminded_on = IF(?, NULL, reminded_on) WHERE id = ?");
        $stmt->bind_param('ssisii', $v['title'], $v['description'], $v['assignee_user_id'], $v['due_date'], $resetReminder, $taskId);
        $stmt->execute();
        if (array_key_exists('depends_on', $d)) {
            $deps = $this->applyDeps((int)$old['project_id'], $taskId, (array)$d['depends_on']);
            if (isset($deps['error'])) {
                return $deps;
            }
        }
        $this->touch((int)$old['project_id']);
        if ($v['assignee_user_id'] && $v['assignee_user_id'] !== (int)$old['assignee_user_id']) {
            $this->notify($e, $ctx, [$v['assignee_user_id']], $uid, "کار جدید: «{$v['title']}»", $this->who($e, $uid) . " در پروژه «{$ctx['project']['title']}» این کار را به شما سپرد.");
        }
        return ['success' => true, 'id' => $taskId];
    }

    public function delete(int $e, int $uid, bool $seesAll, int $taskId): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canFullEdit($ctx, $uid)) {
            return self::err('فقط مدیر پروژه یا تعریف‌کننده کار می‌تواند آن را حذف کند.', 403);
        }
        $this->conn->begin_transaction();
        $this->conn->query("DELETE FROM project_task_checks WHERE task_id = {$taskId}");
        $this->conn->query("DELETE FROM project_task_deps WHERE task_id = {$taskId} OR depends_on_id = {$taskId}");
        $this->conn->query("UPDATE meeting_actions SET task_id = NULL WHERE task_id = {$taskId}");
        $this->conn->query("DELETE FROM project_tasks WHERE id = {$taskId}");
        $this->conn->commit();
        $this->touch((int)$ctx['task']['project_id']);
        log_activity($this->conn, 'work_task_delete', "حذف کار «{$ctx['task']['title']}»");
        return ['success' => true];
    }

    // ---------- status flow ----------

    /** Titles of prerequisites that are not done yet. */
    private function openPrereqs(int $taskId): array {
        $res = $this->conn->query("SELECT t.title FROM project_task_deps d JOIN project_tasks t ON t.id = d.depends_on_id
            WHERE d.task_id = {$taskId} AND t.status <> 'done' ORDER BY t.sort_order, t.id");
        return $res ? array_column($res->fetch_all(MYSQLI_ASSOC), 'title') : [];
    }

    private function prereqError(int $taskId): ?array {
        $open = $this->openPrereqs($taskId);
        if (!$open) {
            return null;
        }
        return self::err('اول این پیش‌نیازها باید انجام شود: ' . implode('، ', array_map(fn($t) => "«{$t}»", $open)), 422);
    }

    /** Only todo ↔ in_progress; review and done go through complete / approve. */
    public function setStatus(int $e, int $uid, bool $seesAll, int $taskId, string $status): array {
        if (!in_array($status, ['todo', 'in_progress'], true)) {
            return self::err('برای اعلام انجام کار از دکمه «انجام شد» استفاده کنید.');
        }
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canMove($ctx, $uid)) {
            return self::err('اجازه تغییر وضعیت این کار را ندارید.', 403);
        }
        $cur = $ctx['task']['status'];
        if ($cur === 'done') {
            return self::err('برای کار انجام‌شده از «باز کردن دوباره» استفاده کنید.');
        }
        if ($cur === 'review' && !$ctx['canManage'] && (int)$ctx['task']['completed_by'] !== $uid) {
            return self::err('این کار منتظر تأیید مدیر پروژه است.');
        }
        if ($status === 'in_progress' && ($blocked = $this->prereqError($taskId))) {
            return $blocked;
        }
        $this->conn->query("UPDATE project_tasks SET status = '{$status}', completed_by = IF('{$cur}' = 'review', NULL, completed_by), completion_note = IF('{$cur}' = 'review', NULL, completion_note) WHERE id = {$taskId}");
        $this->touch((int)$ctx['task']['project_id']);
        return ['success' => true];
    }

    public function complete(int $e, int $uid, bool $seesAll, int $taskId, ?string $note): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canMove($ctx, $uid)) {
            return self::err('اجازه تغییر وضعیت این کار را ندارید.', 403);
        }
        if ($ctx['task']['status'] === 'done') {
            return self::err('این کار قبلاً انجام شده است.');
        }
        if ($ctx['task']['status'] === 'review') {
            return self::err('این کار در انتظار تأیید مدیر پروژه است.');
        }
        if ($blocked = $this->prereqError($taskId)) {
            return $blocked;
        }
        $note = $this->cleanText($note, 1000, 'گزارش انجام');
        if (is_array($note)) {
            return $note;
        }
        $direct = $ctx['canManage'] || !$ctx['managerIds'];
        $status = $direct ? 'done' : 'review';
        $now = date('Y-m-d H:i:s');
        $completedAt = $direct ? $now : null;
        $stmt = $this->conn->prepare("UPDATE project_tasks SET status = ?, completed_at = ?, completed_by = ?, completion_note = ?, reviewed_by = NULL, review_note = NULL WHERE id = ?");
        $stmt->bind_param('ssisi', $status, $completedAt, $uid, $note, $taskId);
        $stmt->execute();
        $this->touch((int)$ctx['task']['project_id']);
        $who = $this->who($e, $uid);
        $title = $ctx['task']['title'];
        if ($direct) {
            $ctx['task']['completed_by'] = $uid;
            $this->notify($e, $ctx, self::stakeholders($ctx), $uid, "«{$title}»: انجام شد", $note ? "{$who}: " . mb_substr($note, 0, 140) : "{$who} در پروژه «{$ctx['project']['title']}»");
        } else {
            $this->notify($e, $ctx, $ctx['managerIds'], $uid, "منتظر تأیید شما: «{$title}»", "{$who} انجام این کار را اعلام کرد" . ($note ? ': ' . mb_substr($note, 0, 120) : '.'));
        }
        log_activity($this->conn, 'work_task_complete', "اعلام انجام کار «{$title}»");
        return ['success' => true, 'status' => $status];
    }

    public function approve(int $e, int $uid, bool $seesAll, int $taskId, ?string $note): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!$ctx['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند انجام کار را تأیید کند.', 403);
        }
        if ($ctx['task']['status'] !== 'review') {
            return self::err('این کار در انتظار تأیید نیست.');
        }
        $note = $this->cleanText($note, 1000, 'یادداشت');
        if (is_array($note)) {
            return $note;
        }
        $doer = (int)($ctx['task']['completed_by'] ?: $ctx['task']['assignee_user_id'] ?: $uid);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("UPDATE project_tasks SET status = 'done', completed_at = ?, completed_by = ?, reviewed_by = ?, review_note = ? WHERE id = ?");
        $stmt->bind_param('siisi', $now, $doer, $uid, $note, $taskId);
        $stmt->execute();
        $this->touch((int)$ctx['task']['project_id']);
        $this->notify($e, $ctx, self::stakeholders($ctx), $uid, "«{$ctx['task']['title']}»: تأیید و انجام شد", $this->who($e, $uid) . ($note ? ': ' . mb_substr($note, 0, 140) : ' انجام کار را تأیید کرد.'));
        log_activity($this->conn, 'work_task_approve', "تأیید کار «{$ctx['task']['title']}»");
        return ['success' => true];
    }

    public function sendBack(int $e, int $uid, bool $seesAll, int $taskId, ?string $reason): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!$ctx['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند کار را برگرداند.', 403);
        }
        if ($ctx['task']['status'] !== 'review') {
            return self::err('این کار در انتظار تأیید نیست.');
        }
        $reason = $this->cleanText($reason, 1000, 'دلیل');
        if (is_array($reason)) {
            return $reason;
        }
        $stmt = $this->conn->prepare("UPDATE project_tasks SET status = 'in_progress', completed_at = NULL, completion_note = NULL, reviewed_by = ?, review_note = ? WHERE id = ?");
        $stmt->bind_param('isi', $uid, $reason, $taskId);
        $stmt->execute();
        $this->touch((int)$ctx['task']['project_id']);
        $t = $ctx['task'];
        $this->notify($e, $ctx, [(int)$t['completed_by'], (int)$t['assignee_user_id'], (int)$t['created_by']], $uid,
            "«{$t['title']}»: برای ادامه برگشت", $this->who($e, $uid) . ($reason ? ': ' . mb_substr($reason, 0, 140) : ' انجام کار را تأیید نکرد.'));
        return ['success' => true];
    }

    public function reopen(int $e, int $uid, bool $seesAll, int $taskId): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if ($ctx['task']['status'] !== 'done') {
            return self::err('این کار انجام‌شده نیست.');
        }
        if (!$ctx['canManage'] && (int)$ctx['task']['completed_by'] !== $uid) {
            return self::err('فقط مدیر پروژه یا انجام‌دهنده می‌تواند کار را دوباره باز کند.', 403);
        }
        $this->conn->query("UPDATE project_tasks SET status = 'in_progress', completed_at = NULL, completed_by = NULL, completion_note = NULL, reviewed_by = NULL, review_note = NULL WHERE id = {$taskId}");
        $this->touch((int)$ctx['task']['project_id']);
        return ['success' => true];
    }

    public function reorder(int $e, int $uid, bool $seesAll, int $projectId, array $orderedIds): array {
        $acc = $this->projects->access($e, $uid, $seesAll, $projectId);
        if (!$acc || (!$acc['isMember'] && !$acc['canManage'])) {
            return self::err('پروژه یافت نشد.', 404);
        }
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));
        if (!$ids) {
            return ['success' => true];
        }
        $res = $this->conn->query("SELECT COUNT(*) n FROM project_tasks WHERE project_id = {$projectId} AND id IN (" . implode(',', $ids) . ")");
        if ((int)$res->fetch_assoc()['n'] !== count($ids)) {
            return self::err('کارها باید از همین پروژه باشند.');
        }
        $stmt = $this->conn->prepare("UPDATE project_tasks SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $pos = $i + 1;
            $stmt->bind_param('ii', $pos, $id);
            $stmt->execute();
        }
        return ['success' => true];
    }

    // ---------- checklist ----------

    private function checkCtx(int $e, int $uid, bool $seesAll, int $checkId) {
        $res = $this->conn->query("SELECT * FROM project_task_checks WHERE id = {$checkId}");
        $check = $res ? $res->fetch_assoc() : null;
        if (!$check) {
            return self::err('مورد چک‌لیست یافت نشد.', 404);
        }
        $ctx = $this->ctx($e, $uid, $seesAll, (int)$check['task_id']);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        return $ctx + ['check' => $check];
    }

    public function addCheck(int $e, int $uid, bool $seesAll, int $taskId, string $text): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canMove($ctx, $uid)) {
            return self::err('اجازه ویرایش چک‌لیست این کار را ندارید.', 403);
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 300) {
            return self::err('متن مورد چک‌لیست باید بین ۱ تا ۳۰۰ نویسه باشد.');
        }
        $n = (int)$this->conn->query("SELECT COUNT(*) n FROM project_task_checks WHERE task_id = {$taskId}")->fetch_assoc()['n'];
        if ($n >= self::MAX_CHECKS) {
            return self::err('هر کار حداکثر ۵۰ مورد چک‌لیست دارد.');
        }
        $order = $n + 1;
        $stmt = $this->conn->prepare("INSERT INTO project_task_checks (task_id, text, sort_order) VALUES (?, ?, ?)");
        $stmt->bind_param('isi', $taskId, $text, $order);
        $stmt->execute();
        return ['success' => true, 'id' => (int)$this->conn->insert_id];
    }

    public function toggleCheck(int $e, int $uid, bool $seesAll, int $checkId, bool $done): array {
        $ctx = $this->checkCtx($e, $uid, $seesAll, $checkId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canMove($ctx, $uid)) {
            return self::err('اجازه ویرایش چک‌لیست این کار را ندارید.', 403);
        }
        if ($done) {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare("UPDATE project_task_checks SET done = 1, done_by = ?, done_at = ? WHERE id = ?");
            $stmt->bind_param('isi', $uid, $now, $checkId);
        } else {
            $stmt = $this->conn->prepare("UPDATE project_task_checks SET done = 0, done_by = NULL, done_at = NULL WHERE id = ?");
            $stmt->bind_param('i', $checkId);
        }
        $stmt->execute();
        return ['success' => true];
    }

    public function deleteCheck(int $e, int $uid, bool $seesAll, int $checkId): array {
        $ctx = $this->checkCtx($e, $uid, $seesAll, $checkId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canMove($ctx, $uid)) {
            return self::err('اجازه ویرایش چک‌لیست این کار را ندارید.', 403);
        }
        $this->conn->query("DELETE FROM project_task_checks WHERE id = {$checkId}");
        return ['success' => true];
    }

    // ---------- prerequisites ----------

    /** Would making $taskId depend on $newDeps close a loop? Port of mz7 deps.ts createsCycle. */
    public static function createsCycle(int $taskId, array $newDeps, array $edges): bool {
        $newDeps = array_map('intval', $newDeps);
        if (in_array($taskId, $newDeps, true)) {
            return true;
        }
        $next = [];
        foreach ($edges as $edge) {
            $t = (int)$edge['task_id'];
            if ($t === $taskId) {
                continue; // replaced by $newDeps
            }
            $next[$t][] = (int)$edge['depends_on_id'];
        }
        $next[$taskId] = $newDeps;
        $seen = [];
        $stack = $newDeps;
        while ($stack) {
            $cur = array_pop($stack);
            if ($cur === $taskId) {
                return true;
            }
            if (isset($seen[$cur])) {
                continue;
            }
            $seen[$cur] = true;
            foreach ($next[$cur] ?? [] as $n) {
                $stack[] = $n;
            }
        }
        return false;
    }

    private function applyDeps(int $projectId, int $taskId, array $ids): array {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_DEPS);
        if ($ids) {
            $n = (int)$this->conn->query("SELECT COUNT(*) n FROM project_tasks WHERE project_id = {$projectId} AND id IN (" . implode(',', $ids) . ")")->fetch_assoc()['n'];
            if ($n !== count($ids)) {
                return self::err('پیش‌نیاز باید از کارهای همین پروژه باشد.');
            }
            $res = $this->conn->query("SELECT d.task_id, d.depends_on_id FROM project_task_deps d JOIN project_tasks t ON t.id = d.task_id WHERE t.project_id = {$projectId}");
            if (self::createsCycle($taskId, $ids, $res ? $res->fetch_all(MYSQLI_ASSOC) : [])) {
                return self::err('این پیش‌نیاز دور ایجاد می‌کند (کاری که خودش منتظر همین کار است).');
            }
        }
        $this->conn->query("DELETE FROM project_task_deps WHERE task_id = {$taskId}");
        foreach ($ids as $dep) {
            $this->conn->query("INSERT INTO project_task_deps (task_id, depends_on_id) VALUES ({$taskId}, {$dep})");
        }
        return ['success' => true];
    }

    public function setDeps(int $e, int $uid, bool $seesAll, int $taskId, array $ids): array {
        $ctx = $this->ctx($e, $uid, $seesAll, $taskId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if (!self::canFullEdit($ctx, $uid)) {
            return self::err('فقط مدیر پروژه یا تعریف‌کننده کار می‌تواند پیش‌نیاز را تغییر دهد.', 403);
        }
        return $this->applyDeps((int)$ctx['task']['project_id'], $taskId, $ids);
    }

    // ---------- listing ----------

    /** All tasks of a project (open first in working order) + the done log grouped by day. */
    public function listForProject(int $e, int $projectId, int $viewerId): array {
        $res = $this->conn->query("SELECT t.*, a.full_name AS assignee_name, c.full_name AS completed_by_name, r.full_name AS reviewed_by_name, cr.full_name AS created_by_name
            FROM project_tasks t
            LEFT JOIN team_members a ON a.entity_id = {$e} AND a.user_id = t.assignee_user_id
            LEFT JOIN team_members c ON c.entity_id = {$e} AND c.user_id = t.completed_by
            LEFT JOIN team_members r ON r.entity_id = {$e} AND r.user_id = t.reviewed_by
            LEFT JOIN team_members cr ON cr.entity_id = {$e} AND cr.user_id = t.created_by
            WHERE t.project_id = {$projectId}
            ORDER BY t.status = 'done', FIELD(t.status, 'review', 'in_progress', 'todo', 'done'), t.sort_order, t.due_date IS NULL, t.due_date, t.id DESC");
        $tasks = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if (!$tasks) {
            return ['tasks' => [], 'done_log' => []];
        }
        $ids = implode(',', array_map(fn($t) => (int)$t['id'], $tasks));
        $checks = [];
        $res = $this->conn->query("SELECT c.*, tm.full_name AS done_by_name FROM project_task_checks c LEFT JOIN team_members tm ON tm.entity_id = {$e} AND tm.user_id = c.done_by WHERE c.task_id IN ({$ids}) ORDER BY c.sort_order, c.id");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $c) {
            $checks[(int)$c['task_id']][] = $c;
        }
        $deps = [];
        $res = $this->conn->query("SELECT task_id, depends_on_id FROM project_task_deps WHERE task_id IN ({$ids})");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $d) {
            $deps[(int)$d['task_id']][] = (int)$d['depends_on_id'];
        }
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(int)$t['id']] = $t;
        }
        $doneLog = [];
        foreach ($tasks as &$t) {
            $id = (int)$t['id'];
            $t['checks'] = $checks[$id] ?? [];
            $t['checks_total'] = count($t['checks']);
            $t['checks_done'] = count(array_filter($t['checks'], fn($c) => (int)$c['done'] === 1));
            $t['depends_on'] = $deps[$id] ?? [];
            $t['blocked_by'] = array_values(array_map(fn($d) => $byId[$d]['title'], array_filter($t['depends_on'], fn($d) => isset($byId[$d]) && $byId[$d]['status'] !== 'done')));
            if ($t['status'] === 'done' && $t['completed_at']) {
                $doneLog[substr($t['completed_at'], 0, 10)][] = $t;
            }
        }
        unset($t);
        krsort($doneLog);
        $log = [];
        foreach ($doneLog as $day => $list) {
            usort($list, fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));
            $log[] = ['day' => $day, 'tasks' => $list];
        }
        return ['tasks' => $tasks, 'done_log' => $log];
    }
}
