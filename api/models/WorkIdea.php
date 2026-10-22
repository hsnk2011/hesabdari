<?php
// /api/models/WorkIdea.php
// Ideas (rules from mz7 src/core/ideas): submit (optionally anonymous),
// comment, vote agree/disagree, and a decision — approve (becomes a project)
// or reject with a note. Everyone with use_ideas sees every idea (small team).
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/WorkProject.php';
require_once __DIR__ . '/WorkRequest.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class WorkIdea {
    private $conn;
    /** Who decides (default: holders of manage_projects); replaceable in tests. */
    public $decidersResolver = null;

    public function __construct($db) {
        $this->conn = $db;
    }

    private static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    private function deciders(int $e): array {
        if (is_callable($this->decidersResolver)) {
            return ($this->decidersResolver)($e);
        }
        return (new WorkRequest($this->conn))->usersWithPermission($e, 'manage_projects');
    }

    private function row(int $e, int $id): ?array {
        return $this->conn->query("SELECT * FROM ideas WHERE id = {$id} AND entity_id = {$e}")->fetch_assoc() ?: null;
    }

    private function notify(int $e, int $id, array $to, int $actor, string $title, string $body): void {
        foreach (array_unique(array_map('intval', $to)) as $u) {
            if ($u !== $actor) {
                CrmNotifier::notify($this->conn, $e, $u, 'work_idea', $title, $body, "./index.php#work/idea/{$id}", 'idea', $id);
            }
        }
    }

    private function clean(array $d): array {
        $title = trim((string)($d['title'] ?? ''));
        $body = trim((string)($d['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            return self::err('عنوان ایده را بنویسید (حداکثر ۲۰۰ نویسه).');
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            return self::err('شرح ایده را بنویسید (حداکثر ۵۰۰۰ نویسه).');
        }
        return ['title' => $title, 'body' => $body, 'anon' => !empty($d['is_anonymous']) ? 1 : 0];
    }

    public function create(int $e, int $uid, array $d): array {
        if (!in_array($uid, TeamMember::userIdsForEntity($this->conn, $e), true)) {
            return self::err('حساب شما عضو تیم نیست.', 403);
        }
        $v = $this->clean($d);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare("INSERT INTO ideas (entity_id, author_user_id, title, body, is_anonymous) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iissi', $e, $uid, $v['title'], $v['body'], $v['anon']);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        log_activity($this->conn, 'idea_create', "ایده جدید: {$v['title']}");
        $who = $v['anon'] ? 'یک همکار (ناشناس)' : (TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار');
        $this->notify($e, $id, $this->deciders($e), $uid, "ایده جدید: {$v['title']}", $who);
        return ['success' => true, 'id' => $id];
    }

    public function update(int $e, int $uid, int $id, array $d): array {
        $idea = $this->row($e, $id);
        if (!$idea) {
            return self::err('ایده یافت نشد.', 404);
        }
        if ((int)$idea['author_user_id'] !== $uid) {
            return self::err('فقط نویسنده ایده را ویرایش می‌کند.', 403);
        }
        if ($idea['status'] !== 'pending') {
            return self::err('ایده‌ای که درباره‌اش تصمیم گرفته شده قابل ویرایش نیست.');
        }
        $v = $this->clean($d);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare("UPDATE ideas SET title = ?, body = ?, is_anonymous = ? WHERE id = ?");
        $stmt->bind_param('ssii', $v['title'], $v['body'], $v['anon'], $id);
        $stmt->execute();
        return ['success' => true, 'id' => $id];
    }

    public function delete(int $e, int $uid, int $id): array {
        $idea = $this->row($e, $id);
        if (!$idea) {
            return self::err('ایده یافت نشد.', 404);
        }
        if ((int)$idea['author_user_id'] !== $uid) {
            return self::err('فقط نویسنده ایده را حذف می‌کند.', 403);
        }
        if ($idea['status'] !== 'pending') {
            return self::err('ایده‌ای که درباره‌اش تصمیم گرفته شده قابل حذف نیست.');
        }
        $this->conn->query("DELETE FROM idea_votes WHERE idea_id = {$id}");
        $this->conn->query("DELETE FROM idea_comments WHERE idea_id = {$id}");
        $this->conn->query("DELETE FROM ideas WHERE id = {$id}");
        return ['success' => true];
    }

    /** Hide the author of an anonymous idea from everyone but the author. */
    private function serialize(array $r, int $viewer, array $names): array {
        $mine = (int)$r['author_user_id'] === $viewer;
        $hidden = (int)$r['is_anonymous'] === 1 && !$mine;
        return [
            'id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'status' => $r['status'],
            'is_anonymous' => (int)$r['is_anonymous'] === 1, 'is_mine' => $mine,
            'author_user_id' => $hidden ? null : (int)$r['author_user_id'],
            'author_name' => $hidden ? null : ($names[(int)$r['author_user_id']] ?? ''),
            'decided_by_name' => $r['decided_by'] ? ($names[(int)$r['decided_by']] ?? '') : null,
            'decided_at' => $r['decided_at'], 'decision_note' => $r['decision_note'],
            'project_id' => $r['project_id'] !== null ? (int)$r['project_id'] : null,
            'created_at' => $r['created_at'],
            'agree' => (int)($r['agree'] ?? 0), 'disagree' => (int)($r['disagree'] ?? 0),
            'comments' => (int)($r['comment_count'] ?? 0),
            'my_vote' => $r['my_vote'] === null ? null : ((int)$r['my_vote'] === 1 ? 'agree' : 'disagree'),
        ];
    }

    private function selectSql(int $e, int $uid): string {
        return "SELECT i.*,
                (SELECT COUNT(*) FROM idea_votes v WHERE v.idea_id = i.id AND v.agree = 1) AS agree,
                (SELECT COUNT(*) FROM idea_votes v WHERE v.idea_id = i.id AND v.agree = 0) AS disagree,
                (SELECT COUNT(*) FROM idea_comments c WHERE c.idea_id = i.id) AS comment_count,
                (SELECT v.agree FROM idea_votes v WHERE v.idea_id = i.id AND v.user_id = {$uid}) AS my_vote
            FROM ideas i WHERE i.entity_id = {$e}";
    }

    public function listFor(int $e, int $uid, bool $canDecide): array {
        $names = TeamMember::namesByUser($this->conn, $e);
        $rows = $this->conn->query($this->selectSql($e, $uid) . " ORDER BY i.status = 'pending' DESC, i.created_at DESC LIMIT 300")->fetch_all(MYSQLI_ASSOC);
        return array_map(fn($r) => $this->serialize($r, $uid, $names), $rows);
    }

    public function detail(int $e, int $uid, bool $canDecide, int $id): array {
        $r = $this->conn->query($this->selectSql($e, $uid) . " AND i.id = {$id}")->fetch_assoc();
        if (!$r) {
            return self::err('ایده یافت نشد.', 404);
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $comments = $this->conn->query("SELECT * FROM idea_comments WHERE idea_id = {$id} ORDER BY created_at, id")->fetch_all(MYSQLI_ASSOC);
        $hideAuthor = (int)$r['is_anonymous'] === 1 && (int)$r['author_user_id'] !== $uid;
        foreach ($comments as &$c) {
            $byAuthor = (int)$c['author_user_id'] === (int)$r['author_user_id'];
            $c['is_mine'] = (int)$c['author_user_id'] === $uid;
            // The anonymous author's own comments must not reveal them.
            $c['author_name'] = ($byAuthor && $hideAuthor) ? 'نویسنده ایده' : ($names[(int)$c['author_user_id']] ?? '');
            if ($byAuthor && $hideAuthor) {
                unset($c['author_user_id']);
            }
        }
        unset($c);
        $project = null;
        if ($r['project_id']) {
            $project = $this->conn->query("SELECT id, title, status FROM projects WHERE id = " . (int)$r['project_id'])->fetch_assoc();
        }
        return ['success' => true, 'idea' => $this->serialize($r, $uid, $names), 'comments' => $comments, 'project' => $project, 'can_decide' => $canDecide];
    }

    public function comment(int $e, int $uid, int $id, string $body): array {
        $idea = $this->row($e, $id);
        if (!$idea) {
            return self::err('ایده یافت نشد.', 404);
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            return self::err('متن نظر را بنویسید (حداکثر ۲۰۰۰ نویسه).');
        }
        $stmt = $this->conn->prepare("INSERT INTO idea_comments (idea_id, author_user_id, body) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $id, $uid, $body);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id;
        $who = ((int)$idea['is_anonymous'] === 1 && (int)$idea['author_user_id'] === $uid)
            ? 'نویسنده ایده' : (TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار');
        $people = array_merge([(int)$idea['author_user_id']], array_map('intval', array_column(
            $this->conn->query("SELECT DISTINCT author_user_id FROM idea_comments WHERE idea_id = {$id}")->fetch_all(MYSQLI_ASSOC), 'author_user_id')));
        $this->notify($e, $id, $people, $uid, "نظر جدید درباره ایده «{$idea['title']}»", "{$who}: " . mb_substr($body, 0, 140));
        return ['success' => true, 'id' => $newId];
    }

    /** Agree / disagree; the same vote again removes it. */
    public function vote(int $e, int $uid, int $id, bool $agree): array {
        if (!$this->row($e, $id)) {
            return self::err('ایده یافت نشد.', 404);
        }
        $cur = $this->conn->query("SELECT agree FROM idea_votes WHERE idea_id = {$id} AND user_id = {$uid}")->fetch_assoc();
        if ($cur && (int)$cur['agree'] === ($agree ? 1 : 0)) {
            $this->conn->query("DELETE FROM idea_votes WHERE idea_id = {$id} AND user_id = {$uid}");
        } else {
            $this->conn->query("REPLACE INTO idea_votes (idea_id, user_id, agree) VALUES ({$id}, {$uid}, " . ($agree ? 1 : 0) . ")");
        }
        return ['success' => true];
    }

    /** approve → a project (decider manager; author and chosen people members); reject needs a note. */
    public function decide(int $e, int $uid, bool $canDecide, int $id, string $decision, array $d): array {
        if (!$canDecide) {
            return self::err('تصمیم درباره ایده‌ها با «مدیریت همه پروژه‌ها و جلسات» است.', 403);
        }
        $idea = $this->row($e, $id);
        if (!$idea) {
            return self::err('ایده یافت نشد.', 404);
        }
        if ($idea['status'] !== 'pending') {
            return self::err('درباره این ایده قبلاً تصمیم گرفته شده است.');
        }
        $note = trim((string)($d['note'] ?? ''));
        if ($decision === 'reject') {
            if ($note === '') {
                return self::err('دلیل رد را بنویسید.');
            }
            $stmt = $this->conn->prepare("UPDATE ideas SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?");
            $stmt->bind_param('isi', $uid, $note, $id);
            $stmt->execute();
            $this->notify($e, $id, [(int)$idea['author_user_id']], $uid, "ایده «{$idea['title']}» رد شد", $note);
            return ['success' => true];
        }
        if ($decision !== 'approve') {
            return self::err('تصمیم نامعتبر است.');
        }
        $members = [['user_id' => (int)$idea['author_user_id'], 'role' => 'member']];
        foreach ((array)($d['member_ids'] ?? []) as $m) {
            $members[] = ['user_id' => (int)$m, 'role' => 'member'];
        }
        $p = (new WorkProject($this->conn))->create($e, $uid, true, ['title' => $idea['title'], 'description' => $idea['body'], 'status' => 'planning', 'members' => $members]);
        if (empty($p['success'])) {
            return $p;
        }
        $pid = (int)$p['id'];
        $stmt = $this->conn->prepare("UPDATE ideas SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ?, project_id = ? WHERE id = ?");
        $noteVal = $note !== '' ? $note : null;
        $stmt->bind_param('isii', $uid, $noteVal, $pid, $id);
        $stmt->execute();
        log_activity($this->conn, 'idea_approve', "ایده «{$idea['title']}» تأیید و پروژه شد");
        $this->notify($e, $id, [(int)$idea['author_user_id']], $uid, "ایده «{$idea['title']}» تأیید و پروژه شد", $note !== '' ? $note : 'پروژه آن ساخته شد.');
        return ['success' => true, 'project_id' => $pid];
    }
}
