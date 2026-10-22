<?php
// /api/models/WorkProject.php
// میز کار projects (rules from mz7 src/core/projects): members see their
// projects; manage_projects holders see, create and manage all of them; a
// project always keeps at least one member and one manager.
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/WorkFiles.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class WorkProject {
    const STATUSES = ['planning', 'active', 'on_hold', 'done', 'cancelled'];
    const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    const ROLES = ['manager', 'member'];
    const STATUS_LABEL = ['planning' => 'برنامه‌ریزی', 'active' => 'در حال اجرا', 'on_hold' => 'متوقف', 'done' => 'تکمیل‌شده', 'cancelled' => 'لغوشده'];

    protected $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ---------- access ----------

    /** ['project', 'members', 'isMember', 'canManage'] or null when the viewer may not see it. */
    public function access(int $e, int $uid, bool $seesAll, int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $e);
        $stmt->execute();
        $project = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$project) {
            return null;
        }
        $members = $this->members($e, $id);
        $me = null;
        foreach ($members as $m) {
            if ((int)$m['user_id'] === $uid) {
                $me = $m;
            }
        }
        // A deactivated team member is no longer part of any project.
        $activeUsers = TeamMember::userIdsForEntity($this->conn, $e);
        $isMember = $me !== null && in_array($uid, $activeUsers, true);
        if (!$isMember && !$seesAll) {
            return null;
        }
        return [
            'project' => $project,
            'members' => $members,
            'isMember' => $isMember,
            'canManage' => $seesAll || ($isMember && $me['role'] === 'manager'),
            'myRole' => $isMember ? $me['role'] : null,
        ];
    }

    public function members(int $e, int $projectId): array {
        $stmt = $this->conn->prepare("SELECT pm.user_id, pm.role, pm.added_at, tm.full_name, tm.kind, tm.is_active
            FROM project_members pm
            LEFT JOIN team_members tm ON tm.entity_id = ? AND tm.user_id = pm.user_id
            WHERE pm.project_id = ? ORDER BY pm.role = 'manager' DESC, pm.added_at");
        $stmt->bind_param('ii', $e, $projectId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    protected static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    protected static function notFound(): array {
        return self::err('پروژه یافت نشد.', 404);
    }

    // ---------- create / update / delete ----------

    /** Validated column values or ['error' => ...]. $old = current row for updates. */
    private function clean(array $d, ?array $old = null): array {
        $title = trim((string)($d['title'] ?? ($old['title'] ?? '')));
        if ($title === '') {
            return self::err('عنوان پروژه را بنویسید.');
        }
        if (mb_strlen($title) > 200) {
            return self::err('عنوان پروژه حداکثر ۲۰۰ نویسه است.');
        }
        $desc = array_key_exists('description', $d) ? trim((string)$d['description']) : ($old['description'] ?? '');
        if (mb_strlen((string)$desc) > 5000) {
            return self::err('شرح پروژه حداکثر ۵۰۰۰ نویسه است.');
        }
        $status = $d['status'] ?? ($old['status'] ?? 'planning');
        $priority = $d['priority'] ?? ($old['priority'] ?? 'medium');
        if (!in_array($status, self::STATUSES, true) || !in_array($priority, self::PRIORITIES, true)) {
            return self::err('وضعیت یا اولویت نامعتبر است.');
        }
        $dates = [];
        foreach (['start_date', 'due_date'] as $k) {
            $v = array_key_exists($k, $d) ? trim((string)$d[$k]) : (string)($old[$k] ?? '');
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return self::err('تاریخ نامعتبر است.');
            }
            $dates[$k] = $v !== '' ? $v : null;
        }
        if ($dates['start_date'] && $dates['due_date'] && $dates['due_date'] < $dates['start_date']) {
            return self::err('مهلت پایان نمی‌تواند قبل از شروع باشد.');
        }
        $budget = array_key_exists('budget', $d)
            ? (trim((string)$d['budget']) === '' ? null : parse_money($d['budget']))
            : ($old['budget'] ?? null);
        if ($budget !== null && $budget < 0) {
            return self::err('بودجه نمی‌تواند منفی باشد.');
        }
        return ['title' => $title, 'description' => $desc !== '' ? $desc : null, 'status' => $status, 'priority' => $priority,
            'start_date' => $dates['start_date'], 'due_date' => $dates['due_date'], 'budget' => $budget !== null ? (float)$budget : null];
    }

    public function create(int $e, int $uid, bool $canCreate, array $d): array {
        if (!$canCreate) {
            return self::err('ساختن پروژه فقط با دسترسی «مدیریت همه پروژه‌ها».', 403);
        }
        $v = $this->clean($d);
        if (isset($v['error'])) {
            return $v;
        }
        $team = TeamMember::userIdsForEntity($this->conn, $e);
        $members = [$uid => 'manager'];
        foreach ((array)($d['members'] ?? []) as $m) {
            $mu = (int)($m['user_id'] ?? 0);
            $role = in_array($m['role'] ?? '', self::ROLES, true) ? $m['role'] : 'member';
            if ($mu === $uid) {
                continue;
            }
            if (!in_array($mu, $team, true)) {
                return self::err('یکی از اعضا عضو فعال تیم نیست.');
            }
            $members[$mu] = $role;
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("INSERT INTO projects (entity_id, title, description, status, priority, start_date, due_date, budget, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssssdi', $e, $v['title'], $v['description'], $v['status'], $v['priority'], $v['start_date'], $v['due_date'], $v['budget'], $uid);
            $stmt->execute();
            $id = (int)$this->conn->insert_id;
            $ins = $this->conn->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
            foreach ($members as $mu => $role) {
                $ins->bind_param('iis', $id, $mu, $role);
                $ins->execute();
            }
            $this->conn->commit();
        } catch (Throwable $ex) {
            $this->conn->rollback();
            throw $ex;
        }
        log_activity($this->conn, 'work_project_create', "پروژه جدید: {$v['title']}");
        foreach (array_keys($members) as $mu) {
            if ($mu !== $uid) {
                $this->notifyAdded($e, $id, $v['title'], $mu, $uid);
            }
        }
        return ['success' => true, 'id' => $id];
    }

    public function update(int $e, int $uid, bool $seesAll, int $id, array $d): array {
        $acc = $this->access($e, $uid, $seesAll, $id);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند پروژه را ویرایش کند.', 403);
        }
        $v = $this->clean($d, $acc['project']);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare("UPDATE projects SET title = ?, description = ?, status = ?, priority = ?, start_date = ?, due_date = ?, budget = ? WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ssssssdii', $v['title'], $v['description'], $v['status'], $v['priority'], $v['start_date'], $v['due_date'], $v['budget'], $id, $e);
        $stmt->execute();
        log_activity($this->conn, 'work_project_update', "ویرایش پروژه #{$id}");
        return ['success' => true, 'id' => $id];
    }

    /** Project with tasks, reports and files; meetings keep existing but lose the link; expenses lose the link. */
    public function delete(int $e, int $uid, bool $seesAll, int $id): array {
        $acc = $this->access($e, $uid, $seesAll, $id);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند پروژه را حذف کند.', 403);
        }
        $files = WorkFiles::listFor($this->conn, $e, 'project', $id);
        $this->conn->begin_transaction();
        try {
            $this->conn->query("DELETE c FROM project_task_checks c JOIN project_tasks t ON t.id = c.task_id WHERE t.project_id = {$id}");
            $this->conn->query("DELETE d FROM project_task_deps d JOIN project_tasks t ON t.id = d.task_id WHERE t.project_id = {$id}");
            $this->conn->query("UPDATE meeting_actions a JOIN project_tasks t ON t.id = a.task_id SET a.task_id = NULL WHERE t.project_id = {$id}");
            $this->conn->query("DELETE FROM project_tasks WHERE project_id = {$id}");
            $this->conn->query("DELETE FROM project_comments WHERE project_id = {$id}");
            $this->conn->query("DELETE FROM project_members WHERE project_id = {$id}");
            $this->conn->query("UPDATE meetings SET project_id = NULL WHERE project_id = {$id} AND entity_id = {$e}");
            $this->conn->query("UPDATE expenses SET project_id = NULL WHERE project_id = {$id} AND entity_id = {$e}");
            $this->conn->query("UPDATE work_requests SET project_id = NULL WHERE project_id = {$id} AND entity_id = {$e}");
            $this->conn->query("UPDATE ideas SET project_id = NULL WHERE project_id = {$id} AND entity_id = {$e}");
            $this->conn->query("DELETE FROM projects WHERE id = {$id} AND entity_id = {$e}");
            $this->conn->commit();
        } catch (Throwable $ex) {
            $this->conn->rollback();
            throw $ex;
        }
        WorkFiles::deleteRows($this->conn, $files);
        log_activity($this->conn, 'work_project_delete', "حذف پروژه «{$acc['project']['title']}»");
        return ['success' => true];
    }

    // ---------- members ----------

    public function addMember(int $e, int $uid, bool $seesAll, int $id, int $userId, string $role = 'member'): array {
        $acc = $this->access($e, $uid, $seesAll, $id);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند عضو اضافه کند.', 403);
        }
        if (!in_array($userId, TeamMember::userIdsForEntity($this->conn, $e), true)) {
            return self::err('این شخص عضو فعال تیم نیست.');
        }
        $role = in_array($role, self::ROLES, true) ? $role : 'member';
        $stmt = $this->conn->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)");
        $stmt->bind_param('iis', $id, $userId, $role);
        $stmt->execute();
        $added = $stmt->affected_rows === 1;
        log_activity($this->conn, 'work_project_member', "افزودن عضو به پروژه #{$id}");
        if ($added && $userId !== $uid) {
            $this->notifyAdded($e, $id, $acc['project']['title'], $userId, $uid);
        }
        return ['success' => true];
    }

    public function setMemberRole(int $e, int $uid, bool $seesAll, int $id, int $userId, string $role): array {
        $acc = $this->access($e, $uid, $seesAll, $id);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند نقش اعضا را تغییر دهد.', 403);
        }
        if (!in_array($role, self::ROLES, true)) {
            return self::err('نقش نامعتبر است.');
        }
        $target = array_values(array_filter($acc['members'], fn($m) => (int)$m['user_id'] === $userId))[0] ?? null;
        if (!$target) {
            return self::err('این شخص عضو پروژه نیست.');
        }
        $managers = array_filter($acc['members'], fn($m) => $m['role'] === 'manager');
        if ($target['role'] === 'manager' && $role === 'member' && count($managers) <= 1) {
            return self::err('پروژه باید حداقل یک مدیر داشته باشد.');
        }
        $stmt = $this->conn->prepare("UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?");
        $stmt->bind_param('sii', $role, $id, $userId);
        $stmt->execute();
        return ['success' => true];
    }

    /** Removes a member; their unfinished tasks lose the assignee. */
    public function removeMember(int $e, int $uid, bool $seesAll, int $id, int $userId): array {
        $acc = $this->access($e, $uid, $seesAll, $id);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['canManage']) {
            return self::err('فقط مدیر پروژه می‌تواند عضو را بردارد.', 403);
        }
        $target = array_values(array_filter($acc['members'], fn($m) => (int)$m['user_id'] === $userId))[0] ?? null;
        if (!$target) {
            return self::err('این شخص عضو پروژه نیست.');
        }
        if (count($acc['members']) <= 1) {
            return self::err('پروژه باید حداقل یک عضو داشته باشد.');
        }
        $managers = array_filter($acc['members'], fn($m) => $m['role'] === 'manager');
        if ($target['role'] === 'manager' && count($managers) <= 1) {
            return self::err('آخرین مدیر پروژه را نمی‌توان برداشت؛ ابتدا مدیر دیگری تعیین کنید.');
        }
        $this->conn->begin_transaction();
        $this->conn->query("UPDATE project_tasks SET assignee_user_id = NULL WHERE project_id = {$id} AND assignee_user_id = {$userId} AND status <> 'done'");
        $this->conn->query("DELETE FROM project_members WHERE project_id = {$id} AND user_id = {$userId}");
        $this->conn->commit();
        log_activity($this->conn, 'work_project_member', "برداشتن عضو از پروژه #{$id}");
        return ['success' => true];
    }

    protected function notifyAdded(int $e, int $projectId, string $title, int $userId, int $byUid): void {
        $by = TeamMember::namesByUser($this->conn, $e)[$byUid] ?? 'همکار';
        CrmNotifier::notify($this->conn, $e, $userId, 'work_project', "عضو پروژه «{$title}» شدید",
            "{$by} شما را به این پروژه اضافه کرد.", "./index.php#work/project/{$projectId}/members", 'project', $projectId);
    }

    // ---------- reports (گزارش‌ها) ----------

    /** User ids mentioned as @[Name](user:ID). */
    public static function mentionedIds(string $body): array {
        preg_match_all('/@\[[^\]]{1,150}\]\(user:(\d+)\)/u', $body, $m);
        return array_values(array_unique(array_map('intval', $m[1] ?? [])));
    }

    public function addComment(int $e, int $uid, bool $seesAll, int $projectId, string $body, array $files = [], bool $fromUpload = true): array {
        $acc = $this->access($e, $uid, $seesAll, $projectId);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['isMember'] && !$acc['canManage']) {
            return self::err('فقط اعضای پروژه گزارش می‌نویسند.', 403);
        }
        $body = trim($body);
        if ($body === '') {
            return self::err('متن گزارش را بنویسید.');
        }
        if (mb_strlen($body) > 3000) {
            return self::err('متن گزارش حداکثر ۳۰۰۰ نویسه است.');
        }
        $this->conn->begin_transaction();
        $stmt = $this->conn->prepare("INSERT INTO project_comments (project_id, author_user_id, body) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $projectId, $uid, $body);
        $stmt->execute();
        $cid = (int)$this->conn->insert_id;
        if ($files) {
            $stored = WorkFiles::store($this->conn, $e, 'project', $projectId, $uid, $files, ['comment_id' => $cid], $fromUpload);
            if (!empty($stored['error'])) {
                $this->conn->rollback();
                return self::err($stored['error']);
            }
        }
        $this->conn->commit();
        $this->conn->query("UPDATE projects SET updated_at = NOW() WHERE id = {$projectId}");

        $members = array_map('intval', array_column($acc['members'], 'user_id'));
        $active = TeamMember::userIdsForEntity($this->conn, $e);
        $mentioned = array_intersect(self::mentionedIds($body), $members);
        $who = TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
        $title = $acc['project']['title'];
        $plain = mb_substr(preg_replace('/@\[([^\]]+)\]\(user:\d+\)/u', '@$1', $body), 0, 140);
        $link = "./index.php#work/project/{$projectId}/comment/{$cid}";
        foreach ($members as $to) {
            if ($to === $uid || !in_array($to, $active, true)) {
                continue;
            }
            $isMentioned = in_array($to, $mentioned, true);
            CrmNotifier::notify($this->conn, $e, $to, 'work_project',
                $isMentioned ? "{$who} شما را در گزارش «{$title}» نام برد" : "گزارش جدید در «{$title}»",
                $isMentioned ? $plain : "{$who}: {$plain}", $link, 'project_comment', $cid);
        }
        return ['success' => true, 'id' => $cid];
    }

    private function commentCtx(int $e, int $uid, bool $seesAll, int $commentId) {
        $res = $this->conn->query("SELECT * FROM project_comments WHERE id = {$commentId}");
        $c = $res ? $res->fetch_assoc() : null;
        $acc = $c ? $this->access($e, $uid, $seesAll, (int)$c['project_id']) : null;
        if (!$c || !$acc) {
            return self::err('گزارش یافت نشد.', 404);
        }
        return $acc + ['comment' => $c];
    }

    public function editComment(int $e, int $uid, bool $seesAll, int $commentId, string $body): array {
        $ctx = $this->commentCtx($e, $uid, $seesAll, $commentId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if ((int)$ctx['comment']['author_user_id'] !== $uid) {
            return self::err('فقط نویسنده می‌تواند گزارش را ویرایش کند.', 403);
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 3000) {
            return self::err('متن گزارش باید بین ۱ تا ۳۰۰۰ نویسه باشد.');
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("UPDATE project_comments SET body = ?, edited_at = ? WHERE id = ?");
        $stmt->bind_param('ssi', $body, $now, $commentId);
        $stmt->execute();
        return ['success' => true];
    }

    public function deleteComment(int $e, int $uid, bool $seesAll, int $commentId, bool $withFiles): array {
        $ctx = $this->commentCtx($e, $uid, $seesAll, $commentId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if ((int)$ctx['comment']['author_user_id'] !== $uid && !$ctx['canManage']) {
            return self::err('فقط نویسنده یا مدیر پروژه می‌تواند گزارش را حذف کند.', 403);
        }
        $files = array_values(array_filter(WorkFiles::listFor($this->conn, $e, 'project', (int)$ctx['comment']['project_id']), fn($f) => (int)$f['comment_id'] === $commentId));
        if ($withFiles) {
            WorkFiles::deleteRows($this->conn, $files);
        } elseif ($files) {
            // Keep the files in the project's documents.
            $this->conn->query("UPDATE work_files SET comment_id = NULL WHERE comment_id = {$commentId}");
        }
        $this->conn->query("DELETE FROM project_comments WHERE id = {$commentId}");
        log_activity($this->conn, 'work_comment_delete', "حذف گزارش پروژه #{$ctx['comment']['project_id']}");
        return ['success' => true];
    }

    public function comments(int $e, int $projectId): array {
        $res = $this->conn->query("SELECT c.*, tm.full_name AS author_name FROM project_comments c
            LEFT JOIN team_members tm ON tm.entity_id = {$e} AND tm.user_id = c.author_user_id
            WHERE c.project_id = {$projectId} ORDER BY c.created_at DESC, c.id DESC LIMIT 300");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    // ---------- documents (مستندات) ----------

    public function addDocuments(int $e, int $uid, bool $seesAll, int $projectId, array $files, ?string $title, ?string $desc, bool $fromUpload = true): array {
        $acc = $this->access($e, $uid, $seesAll, $projectId);
        if (!$acc) {
            return self::notFound();
        }
        if (!$acc['isMember'] && !$acc['canManage']) {
            return self::err('فقط اعضای پروژه فایل اضافه می‌کنند.', 403);
        }
        $stored = WorkFiles::store($this->conn, $e, 'project', $projectId, $uid, $files, ['title' => $title, 'description' => $desc], $fromUpload);
        if (!empty($stored['error'])) {
            return self::err($stored['error']);
        }
        $this->conn->query("UPDATE projects SET updated_at = NOW() WHERE id = {$projectId}");
        $who = TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
        $active = TeamMember::userIdsForEntity($this->conn, $e);
        $first = (int)$stored['ids'][0];
        foreach (array_map('intval', array_column($acc['members'], 'user_id')) as $to) {
            if ($to !== $uid && in_array($to, $active, true)) {
                CrmNotifier::notify($this->conn, $e, $to, 'work_project', "مستند جدید در «{$acc['project']['title']}»",
                    "{$who} " . count($stored['ids']) . ' فایل اضافه کرد.', "./index.php#work/project/{$projectId}/doc/{$first}", 'work_file', $first);
            }
        }
        return $stored;
    }

    public function deleteDocument(int $e, int $uid, bool $seesAll, int $fileId): array {
        $file = WorkFiles::get($this->conn, $e, $fileId);
        $acc = ($file && $file['owner_type'] === 'project') ? $this->access($e, $uid, $seesAll, (int)$file['owner_id']) : null;
        if (!$acc) {
            return self::err('فایل یافت نشد.', 404);
        }
        if ((int)$file['uploaded_by'] !== $uid && !$acc['canManage']) {
            return self::err('فقط آپلودکننده یا مدیر پروژه می‌تواند فایل را حذف کند.', 403);
        }
        WorkFiles::deleteRows($this->conn, [$file]);
        return ['success' => true];
    }

    // ---------- budget ----------

    /** Budget vs expenses that the accounting side linked to this project. */
    public function budget(int $e, int $projectId): array {
        $res = $this->conn->query("SELECT p.budget, COALESCE((SELECT SUM(x.amount) FROM expenses x WHERE x.project_id = p.id AND x.entity_id = p.entity_id), 0) AS spent
            FROM projects p WHERE p.id = {$projectId} AND p.entity_id = {$e}");
        $row = $res ? $res->fetch_assoc() : null;
        $budget = isset($row['budget']) && $row['budget'] !== null ? (float)$row['budget'] : null;
        $spent = (float)($row['spent'] ?? 0);
        return ['budget' => $budget, 'spent' => $spent, 'remaining' => $budget !== null ? $budget - $spent : null, 'over' => $budget !== null && $spent > $budget + 0.01];
    }

    /** Projects an expense may be linked to (active ones of the entity). */
    public function expenseOptions(int $e): array {
        $res = $this->conn->query("SELECT id, title, status FROM projects WHERE entity_id = {$e} AND status IN ('planning','active','on_hold') ORDER BY title");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    // ---------- lists ----------

    public static function progress(array $statuses): array {
        $total = count($statuses);
        $done = count(array_filter($statuses, fn($s) => $s === 'done'));
        return ['total' => $total, 'done' => $done, 'percent' => $total ? (int)round($done * 100 / $total) : 0];
    }

    /** filter: active (planning/active/on_hold) | mine (member) | done (done/cancelled) | all */
    public function listFor(int $e, int $uid, bool $seesAll, string $filter): array {
        $active = TeamMember::userIdsForEntity($this->conn, $e);
        $where = ['p.entity_id = ?'];
        if ($filter === 'mine' || !$seesAll) {
            $where[] = in_array($uid, $active, true) ? "EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.id AND m.user_id = {$uid})" : '0';
        }
        if ($filter === 'active') {
            $where[] = "p.status IN ('planning','active','on_hold')";
        } elseif ($filter === 'done') {
            $where[] = "p.status IN ('done','cancelled')";
        }
        $stmt = $this->conn->prepare("SELECT p.* FROM projects p WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(p.status, 'active','planning','on_hold','done','cancelled'), p.updated_at DESC LIMIT 300");
        $stmt->bind_param('i', $e);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (!$rows) {
            return [];
        }
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $tasks = [];
        $res = $this->conn->query("SELECT project_id, status, assignee_user_id FROM project_tasks WHERE project_id IN ({$ids})");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
            $tasks[(int)$t['project_id']][] = $t;
        }
        $members = [];
        $res = $this->conn->query("SELECT pm.project_id, pm.user_id, pm.role, tm.full_name FROM project_members pm
            LEFT JOIN team_members tm ON tm.entity_id = {$e} AND tm.user_id = pm.user_id WHERE pm.project_id IN ({$ids})
            ORDER BY pm.role = 'manager' DESC, pm.added_at");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $m) {
            $members[(int)$m['project_id']][] = $m;
        }
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $pt = $tasks[(int)$r['id']] ?? [];
            $r['progress'] = self::progress(array_column($pt, 'status'));
            $r['my_open_tasks'] = count(array_filter($pt, fn($t) => (int)$t['assignee_user_id'] === $uid && $t['status'] !== 'done'));
            $r['members'] = $members[(int)$r['id']] ?? [];
            $r['my_role'] = null;
            foreach ($r['members'] as $m) {
                if ((int)$m['user_id'] === $uid) {
                    $r['my_role'] = $m['role'];
                }
            }
            $r['overdue'] = $r['due_date'] && $r['due_date'] < $today && !in_array($r['status'], ['done', 'cancelled'], true);
        }
        unset($r);
        return $rows;
    }
}
