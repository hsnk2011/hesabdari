<?php
// /api/models/WorkChat.php
// Internal messenger (rules from mz7 src/core/chat + api/chat): one direct
// conversation per pair, groups with admins; only members read or write.
// No supervision of private chats (see design §غیرهدف).
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/WorkFiles.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class WorkChat {
    const MESSAGE_MAX = 4000;
    const TITLE_MAX = 100;
    const DESC_MAX = 500;
    const PAGE = 50;
    const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '✅'];

    protected $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    protected static function err(string $msg, int $code = 400): array {
        return ['error' => $msg, 'statusCode' => $code];
    }

    protected static function notFound(): array {
        return self::err('گفتگو یافت نشد.', 404);
    }

    public static function directKey(int $a, int $b): string {
        return min($a, $b) . ':' . max($a, $b);
    }

    protected function team(int $e): array {
        return TeamMember::userIdsForEntity($this->conn, $e);
    }

    protected function name(int $e, int $uid): string {
        return TeamMember::namesByUser($this->conn, $e)[$uid] ?? 'همکار';
    }

    /** Conversation row + my membership, or null (not a member, other entity, or not an active team user). */
    public function membership(int $e, int $uid, int $cid): ?array {
        if (!in_array($uid, $this->team($e), true)) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT c.*, m.is_admin, m.last_read_message_id, m.muted, m.pinned_at, m.joined_at
            FROM chat_conversations c JOIN chat_members m ON m.conversation_id = c.id AND m.user_id = ?
            WHERE c.id = ? AND c.entity_id = ?");
        $stmt->bind_param('iii', $uid, $cid, $e);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    protected function memberIds(int $cid): array {
        $res = $this->conn->query("SELECT user_id FROM chat_members WHERE conversation_id = {$cid} ORDER BY joined_at, user_id");
        return array_map('intval', array_column($res ? $res->fetch_all(MYSQLI_ASSOC) : [], 'user_id'));
    }

    public function postSystem(int $cid, int $uid, string $body): void {
        $stmt = $this->conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, kind, body) VALUES (?, ?, 'system', ?)");
        $stmt->bind_param('iis', $cid, $uid, $body);
        $stmt->execute();
        $this->conn->query("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = {$cid}");
    }

    // ---------- conversations ----------

    public function openDirect(int $e, int $uid, int $otherUid): array {
        if ($otherUid === $uid) {
            return self::err('با خودتان نمی‌توانید گفتگو کنید.');
        }
        $team = $this->team($e);
        if (!in_array($uid, $team, true) || !in_array($otherUid, $team, true)) {
            return self::err('کاربر یافت نشد.');
        }
        $key = self::directKey($uid, $otherUid);
        $stmt = $this->conn->prepare("SELECT id FROM chat_conversations WHERE entity_id = ? AND direct_key = ?");
        $stmt->bind_param('is', $e, $key);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if ($row) {
            $cid = (int)$row['id'];
        } else {
            $stmt = $this->conn->prepare("INSERT IGNORE INTO chat_conversations (entity_id, type, direct_key, created_by) VALUES (?, 'direct', ?, ?)");
            $stmt->bind_param('isi', $e, $key, $uid);
            $stmt->execute();
            $cid = (int)$this->conn->insert_id;
            if ($cid === 0) { // created at the same moment by the other side
                $stmt = $this->conn->prepare("SELECT id FROM chat_conversations WHERE entity_id = ? AND direct_key = ?");
                $stmt->bind_param('is', $e, $key);
                $stmt->execute();
                $cid = (int)db_stmt_to_assoc_array($stmt)[0]['id'];
            }
        }
        foreach ([$uid, $otherUid] as $m) {
            $this->conn->query("INSERT IGNORE INTO chat_members (conversation_id, user_id) VALUES ({$cid}, {$m})");
        }
        return ['success' => true, 'id' => $cid];
    }

    private function cleanGroup(?string $title, ?string $desc): array {
        $title = trim((string)$title);
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            return self::err('نام گروه را بنویسید (حداکثر ۱۰۰ نویسه).');
        }
        $desc = trim((string)$desc);
        if (mb_strlen($desc) > self::DESC_MAX) {
            return self::err('توضیح گروه حداکثر ۵۰۰ نویسه است.');
        }
        return ['title' => $title, 'description' => $desc !== '' ? $desc : null];
    }

    public function createGroup(int $e, int $uid, ?string $title, ?string $desc, array $memberIds): array {
        $v = $this->cleanGroup($title, $desc);
        if (isset($v['error'])) {
            return $v;
        }
        $team = $this->team($e);
        if (!in_array($uid, $team, true)) {
            return self::err('حساب شما عضو تیم نیست.', 403);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($x) => $x !== $uid)));
        foreach ($ids as $m) {
            if (!in_array($m, $team, true)) {
                return self::err('یک یا چند عضو انتخاب‌شده نامعتبر است.');
            }
        }
        $stmt = $this->conn->prepare("INSERT INTO chat_conversations (entity_id, type, title, description, created_by) VALUES (?, 'group', ?, ?, ?)");
        $stmt->bind_param('issi', $e, $v['title'], $v['description'], $uid);
        $stmt->execute();
        $cid = (int)$this->conn->insert_id;
        $this->conn->query("INSERT INTO chat_members (conversation_id, user_id, is_admin) VALUES ({$cid}, {$uid}, 1)");
        foreach ($ids as $m) {
            $this->conn->query("INSERT IGNORE INTO chat_members (conversation_id, user_id) VALUES ({$cid}, {$m})");
        }
        $this->postSystem($cid, $uid, $this->name($e, $uid) . ' گروه را ساخت');
        log_activity($this->conn, 'chat_group_create', "گروه گفتگو: {$v['title']}");
        return ['success' => true, 'id' => $cid];
    }

    private function adminCtx(int $e, int $uid, int $cid) {
        $me = $this->membership($e, $uid, $cid);
        if (!$me) {
            return self::notFound();
        }
        if ($me['type'] !== 'group') {
            return self::err('این گفتگو گروه نیست.');
        }
        if (!(int)$me['is_admin']) {
            return self::err('فقط مدیر گروه می‌تواند این کار را انجام دهد.', 403);
        }
        return $me;
    }

    public function updateGroup(int $e, int $uid, int $cid, array $d): array {
        $me = $this->adminCtx($e, $uid, $cid);
        if (isset($me['error'])) {
            return $me;
        }
        $v = $this->cleanGroup($d['title'] ?? $me['title'], array_key_exists('description', $d) ? $d['description'] : $me['description']);
        if (isset($v['error'])) {
            return $v;
        }
        $stmt = $this->conn->prepare("UPDATE chat_conversations SET title = ?, description = ? WHERE id = ?");
        $stmt->bind_param('ssi', $v['title'], $v['description'], $cid);
        $stmt->execute();
        if ($v['title'] !== $me['title']) {
            $this->postSystem($cid, $uid, $this->name($e, $uid) . " نام گروه را به «{$v['title']}» تغییر داد");
        }
        return ['success' => true];
    }

    public function addMembers(int $e, int $uid, int $cid, array $ids): array {
        $me = $this->adminCtx($e, $uid, $cid);
        if (isset($me['error'])) {
            return $me;
        }
        $team = $this->team($e);
        $current = $this->memberIds($cid);
        $added = [];
        foreach (array_unique(array_map('intval', $ids)) as $m) {
            if (!in_array($m, $team, true)) {
                return self::err('یک یا چند عضو انتخاب‌شده نامعتبر است.');
            }
            if (!in_array($m, $current, true)) {
                $this->conn->query("INSERT IGNORE INTO chat_members (conversation_id, user_id) VALUES ({$cid}, {$m})");
                $added[] = $this->name($e, $m);
            }
        }
        if ($added) {
            $this->postSystem($cid, $uid, $this->name($e, $uid) . ' ' . implode('، ', $added) . ' را اضافه کرد');
        }
        return ['success' => true];
    }

    public function removeMember(int $e, int $uid, int $cid, int $userId): array {
        $me = $this->adminCtx($e, $uid, $cid);
        if (isset($me['error'])) {
            return $me;
        }
        if ($userId === $uid) {
            return self::err('برای خروج از گروه از گزینه «ترک گروه» استفاده کنید.');
        }
        if (!in_array($userId, $this->memberIds($cid), true)) {
            return self::err('عضو یافت نشد.', 404);
        }
        $this->conn->query("DELETE FROM chat_members WHERE conversation_id = {$cid} AND user_id = {$userId}");
        $this->postSystem($cid, $uid, $this->name($e, $uid) . ' ' . $this->name($e, $userId) . ' را از گروه حذف کرد');
        return ['success' => true];
    }

    public function setAdmin(int $e, int $uid, int $cid, int $userId, bool $admin): array {
        $me = $this->adminCtx($e, $uid, $cid);
        if (isset($me['error'])) {
            return $me;
        }
        $row = $this->conn->query("SELECT is_admin FROM chat_members WHERE conversation_id = {$cid} AND user_id = {$userId}")->fetch_assoc();
        if (!$row) {
            return self::err('عضو یافت نشد.', 404);
        }
        $admins = (int)$this->conn->query("SELECT COUNT(*) n FROM chat_members WHERE conversation_id = {$cid} AND is_admin = 1")->fetch_assoc()['n'];
        if (!$admin && (int)$row['is_admin'] && $admins <= 1) {
            return self::err('گروه باید حداقل یک مدیر داشته باشد.');
        }
        $this->conn->query("UPDATE chat_members SET is_admin = " . ($admin ? 1 : 0) . " WHERE conversation_id = {$cid} AND user_id = {$userId}");
        return ['success' => true];
    }

    /** Leave a group; the last admin hands over to the oldest member; an empty group is deleted. */
    public function leave(int $e, int $uid, int $cid): array {
        $me = $this->membership($e, $uid, $cid);
        if (!$me) {
            // A deactivated member may still be listed; let them go.
            $row = $this->conn->query("SELECT c.type FROM chat_conversations c JOIN chat_members m ON m.conversation_id = c.id AND m.user_id = {$uid} WHERE c.id = {$cid} AND c.entity_id = {$e}")->fetch_assoc();
            if (!$row) {
                return self::notFound();
            }
            $me = ['type' => $row['type'], 'is_admin' => 0];
        }
        if ($me['type'] !== 'group') {
            return self::err('از گفتگوی خصوصی نمی‌توان خارج شد.');
        }
        $this->conn->query("DELETE FROM chat_members WHERE conversation_id = {$cid} AND user_id = {$uid}");
        $rest = $this->memberIds($cid);
        if (!$rest) {
            $this->deleteConversation($e, $cid);
            return ['success' => true, 'deleted' => true];
        }
        $admins = (int)$this->conn->query("SELECT COUNT(*) n FROM chat_members WHERE conversation_id = {$cid} AND is_admin = 1")->fetch_assoc()['n'];
        if ($admins === 0) {
            $this->conn->query("UPDATE chat_members SET is_admin = 1 WHERE conversation_id = {$cid} AND user_id = {$rest[0]}");
        }
        $this->postSystem($cid, $uid, $this->name($e, $uid) . ' گروه را ترک کرد');
        return ['success' => true];
    }

    protected function deleteConversation(int $e, int $cid): void {
        $res = $this->conn->query("SELECT id FROM chat_messages WHERE conversation_id = {$cid}");
        $ids = array_map('intval', array_column($res ? $res->fetch_all(MYSQLI_ASSOC) : [], 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            $files = $this->conn->query("SELECT * FROM work_files WHERE entity_id = {$e} AND owner_type = 'chat' AND owner_id IN ({$in})")->fetch_all(MYSQLI_ASSOC);
            WorkFiles::deleteRows($this->conn, $files);
            $this->conn->query("DELETE FROM chat_reactions WHERE message_id IN ({$in})");
        }
        $this->conn->query("DELETE FROM chat_messages WHERE conversation_id = {$cid}");
        $this->conn->query("DELETE FROM chat_members WHERE conversation_id = {$cid}");
        $this->conn->query("DELETE FROM chat_conversations WHERE id = {$cid}");
    }

    public function setMuted(int $e, int $uid, int $cid, bool $muted): array {
        if (!$this->membership($e, $uid, $cid)) {
            return self::notFound();
        }
        $this->conn->query("UPDATE chat_members SET muted = " . ($muted ? 1 : 0) . " WHERE conversation_id = {$cid} AND user_id = {$uid}");
        return ['success' => true];
    }

    public function setPinned(int $e, int $uid, int $cid, bool $pinned): array {
        if (!$this->membership($e, $uid, $cid)) {
            return self::notFound();
        }
        $this->conn->query("UPDATE chat_members SET pinned_at = " . ($pinned ? 'NOW()' : 'NULL') . " WHERE conversation_id = {$cid} AND user_id = {$uid}");
        return ['success' => true];
    }

    // ---------- messages ----------

    /** Serialize message rows (attachments, reactions, reply snippet, names) for one viewer. */
    public function serializeMany(int $e, int $viewer, array $rows): array {
        if (!$rows) {
            return [];
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $files = [];
        $res = $this->conn->query("SELECT id, owner_id, file_name, size, mime, kind, is_voice FROM work_files WHERE entity_id = {$e} AND owner_type = 'chat' AND owner_id IN ({$ids}) ORDER BY id");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $f) {
            $files[(int)$f['owner_id']][] = ['id' => (int)$f['id'], 'file_name' => $f['file_name'], 'size' => (int)$f['size'], 'mime' => $f['mime'], 'kind' => $f['kind'], 'is_voice' => (int)$f['is_voice'] === 1];
        }
        $reactions = [];
        $res = $this->conn->query("SELECT message_id, user_id, emoji FROM chat_reactions WHERE message_id IN ({$ids}) ORDER BY created_at");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
            $mid = (int)$r['message_id'];
            $g = $reactions[$mid][$r['emoji']] ?? ['emoji' => $r['emoji'], 'users' => [], 'mine' => false];
            $g['users'][] = $names[(int)$r['user_id']] ?? '';
            if ((int)$r['user_id'] === $viewer) {
                $g['mine'] = true;
            }
            $reactions[$mid][$r['emoji']] = $g;
        }
        // A shared project task opens inside its project: give the client the project id.
        $taskProject = [];
        $taskIds = array_filter(array_map(fn($r) => $r['ref_type'] === 'project_task' ? (int)$r['ref_id'] : 0, $rows));
        if ($taskIds) {
            $res = $this->conn->query("SELECT id, project_id FROM project_tasks WHERE id IN (" . implode(',', $taskIds) . ")");
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
                $taskProject[(int)$t['id']] = (int)$t['project_id'];
            }
        }
        $replyIds = array_filter(array_map(fn($r) => (int)$r['reply_to_id'], $rows));
        $replies = [];
        if ($replyIds) {
            $res = $this->conn->query("SELECT m.id, m.sender_id, m.body, m.deleted_at, m.ref_title,
                    (SELECT IF(f.is_voice = 1, 'voice', f.kind) FROM work_files f WHERE f.owner_type = 'chat' AND f.owner_id = m.id LIMIT 1) AS file_kind
                FROM chat_messages m WHERE m.id IN (" . implode(',', $replyIds) . ")");
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $replies[(int)$r['id']] = ['id' => (int)$r['id'], 'sender_name' => $names[(int)$r['sender_id']] ?? '', 'snippet' => mb_substr(self::preview($r), 0, 120)];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['id'];
            $deleted = $r['deleted_at'] !== null;
            $out[] = [
                'id' => $mid,
                'conversation_id' => (int)$r['conversation_id'],
                'kind' => $r['kind'],
                'body' => $deleted ? null : $r['body'],
                'deleted' => $deleted,
                'is_mine' => (int)$r['sender_id'] === $viewer,
                'sender_id' => (int)$r['sender_id'],
                'sender_name' => $names[(int)$r['sender_id']] ?? 'همکار سابق',
                'forwarded_from' => $deleted ? null : $r['forwarded_from'],
                'ref_type' => $deleted ? null : $r['ref_type'],
                'ref_id' => $deleted ? null : ($r['ref_id'] !== null ? (int)$r['ref_id'] : null),
                'ref_title' => $deleted ? null : $r['ref_title'],
                'ref_project_id' => !$deleted && $r['ref_type'] === 'project_task' ? ($taskProject[(int)$r['ref_id']] ?? null) : null,
                'edited_at' => $r['edited_at'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
                'attachments' => $deleted ? [] : ($files[$mid] ?? []),
                'reactions' => $deleted ? [] : array_values($reactions[$mid] ?? []),
                'reply_to' => $r['reply_to_id'] ? ($replies[(int)$r['reply_to_id']] ?? ['id' => (int)$r['reply_to_id'], 'sender_name' => '', 'snippet' => 'پیام حذف شد']) : null,
            ];
        }
        return $out;
    }

    protected function messageRow(int $mid): ?array {
        $res = $this->conn->query("SELECT * FROM chat_messages WHERE id = {$mid}");
        return $res ? ($res->fetch_assoc() ?: null) : null;
    }

    /** Title of a customer / project / task / meeting the user may see, or null. */
    protected function refTitle(int $e, int $uid, string $type, int $id): ?string {
        require_once __DIR__ . '/WorkProject.php';
        require_once __DIR__ . '/CrmPerson.php';
        $seesAll = false; // sharing never widens access: plain visibility rules apply
        if ($type === 'customer') {
            if (!CrmPerson::exists($this->conn, $e, 'customer', $id)) {
                return null;
            }
            $r = $this->conn->query("SELECT name FROM customers WHERE id = {$id}")->fetch_assoc();
            return $r['name'] ?? null;
        }
        if ($type === 'project') {
            $acc = (new WorkProject($this->conn))->access($e, $uid, $seesAll, $id);
            return $acc ? $acc['project']['title'] : null;
        }
        if ($type === 'project_task') {
            $t = $this->conn->query("SELECT t.title, t.project_id FROM project_tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = {$id} AND p.entity_id = {$e}")->fetch_assoc();
            if (!$t || !(new WorkProject($this->conn))->access($e, $uid, $seesAll, (int)$t['project_id'])) {
                return null;
            }
            return $t['title'];
        }
        if ($type === 'meeting') {
            require_once __DIR__ . '/WorkMeeting.php';
            $m = (new WorkMeeting($this->conn))->visible($e, $uid, $seesAll, $id);
            return $m ? $m['title'] : null;
        }
        return null;
    }

    public function send(int $e, int $uid, int $cid, array $d, array $files = [], bool $fromUpload = true): array {
        $me = $this->membership($e, $uid, $cid);
        if (!$me) {
            return self::notFound();
        }
        $body = trim((string)($d['body'] ?? ''));
        if (mb_strlen($body) > self::MESSAGE_MAX) {
            return self::err('پیام حداکثر ۴۰۰۰ نویسه است.');
        }
        $refType = $d['ref_type'] ?? null;
        $refId = (int)($d['ref_id'] ?? 0);
        $refTitle = null;
        if ($refType) {
            if (!in_array($refType, ['customer', 'project', 'project_task', 'meeting'], true) || !($refTitle = $this->refTitle($e, $uid, $refType, $refId))) {
                return self::err('مورد انتخاب‌شده یافت نشد یا به آن دسترسی ندارید.');
            }
        } else {
            $refType = null;
            $refId = null;
        }
        $files = array_values(array_filter($files, fn($f) => (int)($f['error'] ?? 0) !== UPLOAD_ERR_NO_FILE));
        if ($body === '' && !$refType && !$files) {
            return self::err('پیام خالی است.');
        }
        $replyTo = (int)($d['reply_to_id'] ?? 0) ?: null;
        if ($replyTo !== null) {
            $rr = $this->messageRow($replyTo);
            if (!$rr || (int)$rr['conversation_id'] !== $cid) {
                $replyTo = null;
            }
        }
        $this->conn->begin_transaction();
        $stmt = $this->conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, body, reply_to_id, ref_type, ref_id, ref_title) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $bodyVal = $body !== '' ? $body : null;
        $stmt->bind_param('iisisis', $cid, $uid, $bodyVal, $replyTo, $refType, $refId, $refTitle);
        $stmt->execute();
        $mid = (int)$this->conn->insert_id;
        if ($files) {
            $stored = WorkFiles::store($this->conn, $e, 'chat', $mid, $uid, $files, [], $fromUpload);
            if (!empty($stored['error'])) {
                $this->conn->rollback();
                return self::err($stored['error']);
            }
            if (!empty($d['voice'])) {
                $this->conn->query("UPDATE work_files SET is_voice = 1, kind = 'audio' WHERE id IN (" . implode(',', $stored['ids']) . ")");
            }
        }
        $this->conn->query("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = {$cid}");
        // Sending also means I have read everything so far.
        $this->conn->query("UPDATE chat_members SET last_read_message_id = {$mid} WHERE conversation_id = {$cid} AND user_id = {$uid}");
        $this->conn->commit();

        $row = $this->messageRow($mid);
        $msg = $this->serializeMany($e, $uid, [$row])[0];
        $this->pushNew($e, $uid, $me, $row, $msg);
        return ['success' => true, 'message' => $msg];
    }

    /** Push to unmuted members (mentioned people even when muted); no bell rows for chat. */
    protected function pushNew(int $e, int $uid, array $conv, array $row, array $msg): void {
        $team = $this->team($e);
        $res = $this->conn->query("SELECT user_id, muted FROM chat_members WHERE conversation_id = {$conv['id']} AND user_id <> {$uid}");
        preg_match_all('/@\[[^\]]{1,150}\]\(user:(\d+)\)/u', (string)$row['body'], $mm);
        $mentioned = array_map('intval', $mm[1] ?? []);
        $to = [];
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $m) {
            $id = (int)$m['user_id'];
            if (in_array($id, $team, true) && (!(int)$m['muted'] || in_array($id, $mentioned, true))) {
                $to[] = $id;
            }
        }
        if (!$to) {
            return;
        }
        $who = $this->name($e, $uid);
        $kind = $msg['attachments'] ? ($msg['attachments'][0]['is_voice'] ? 'voice' : $msg['attachments'][0]['kind']) : null;
        $preview = self::preview($row + ['file_kind' => $kind]);
        CrmNotifier::push($this->conn, $to, [
            'title' => $conv['type'] === 'group' ? "{$conv['title']} — {$who}" : $who,
            'body' => $preview,
            'url' => "./index.php#work/chat/{$conv['id']}",
            'tag' => "chat-{$conv['id']}",
        ]);
    }

    /** q: since (updated_at cursor) | before_id | around_id | search; default: the last page. */
    public function messages(int $e, int $uid, int $cid, array $q): array {
        $me = $this->membership($e, $uid, $cid);
        if (!$me) {
            return self::notFound();
        }
        $page = self::PAGE;
        $hasMore = false;
        if (!empty($q['since'])) {
            $stmt = $this->conn->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? AND updated_at > ? ORDER BY id LIMIT 300");
            $since = (string)$q['since'];
            $stmt->bind_param('is', $cid, $since);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
        } elseif (!empty($q['search'])) {
            $stmt = $this->conn->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? AND deleted_at IS NULL AND kind = 'text' AND body LIKE ? ORDER BY id DESC LIMIT {$page}");
            $like = '%' . trim((string)$q['search']) . '%';
            $stmt->bind_param('is', $cid, $like);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
        } elseif (!empty($q['around_id'])) {
            $a = (int)$q['around_id'];
            $older = $this->conn->query("SELECT * FROM chat_messages WHERE conversation_id = {$cid} AND id < {$a} ORDER BY id DESC LIMIT 25")->fetch_all(MYSQLI_ASSOC);
            $newer = $this->conn->query("SELECT * FROM chat_messages WHERE conversation_id = {$cid} AND id >= {$a} ORDER BY id LIMIT 25")->fetch_all(MYSQLI_ASSOC);
            $rows = array_merge(array_reverse($older), $newer);
            $hasMore = count($older) === 25;
        } else {
            $before = !empty($q['before_id']) ? ' AND id < ' . (int)$q['before_id'] : '';
            $rows = $this->conn->query("SELECT * FROM chat_messages WHERE conversation_id = {$cid}{$before} ORDER BY id DESC LIMIT " . ($page + 1))->fetch_all(MYSQLI_ASSOC);
            $hasMore = count($rows) > $page;
            $rows = array_reverse(array_slice($rows, 0, $page));
        }
        $cursor = $this->conn->query("SELECT COALESCE(MAX(updated_at), '1970-01-01 00:00:00.000') c FROM chat_messages WHERE conversation_id = {$cid}")->fetch_assoc()['c'];
        $pinned = null;
        if ($me['pinned_message_id']) {
            $p = $this->messageRow((int)$me['pinned_message_id']);
            if ($p && $p['deleted_at'] === null) {
                $pinned = $this->serializeMany($e, $uid, [$p])[0];
            }
        }
        $members = $this->conn->query("SELECT m.user_id, m.is_admin, tm.full_name FROM chat_members m LEFT JOIN team_members tm ON tm.entity_id = {$e} AND tm.user_id = m.user_id WHERE m.conversation_id = {$cid} ORDER BY m.is_admin DESC, m.joined_at")->fetch_all(MYSQLI_ASSOC);
        return [
            'success' => true,
            'conversation' => ['id' => $cid, 'type' => $me['type'], 'title' => $me['title'], 'description' => $me['description'], 'is_admin' => (int)$me['is_admin'] === 1, 'muted' => (int)$me['muted'] === 1, 'pinned' => $me['pinned_at'] !== null],
            'members' => array_map(fn($m) => ['user_id' => (int)$m['user_id'], 'full_name' => $m['full_name'], 'is_admin' => (int)$m['is_admin'] === 1], $members),
            'messages' => $this->serializeMany($e, $uid, $rows),
            'cursor' => $cursor,
            'pinned' => $pinned,
            'has_more' => $hasMore,
        ];
    }

    /** Message + membership of its conversation, or an error. */
    protected function ownable(int $e, int $uid, int $mid) {
        $msg = $this->messageRow($mid);
        $me = $msg ? $this->membership($e, $uid, (int)$msg['conversation_id']) : null;
        if (!$msg || !$me) {
            return self::err('پیام یافت نشد.', 404);
        }
        if ($msg['kind'] !== 'text' || $msg['deleted_at'] !== null) {
            return self::err('این پیام قابل تغییر نیست.');
        }
        return ['msg' => $msg, 'me' => $me];
    }

    public function edit(int $e, int $uid, int $mid, string $body): array {
        $ctx = $this->ownable($e, $uid, $mid);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        if ((int)$ctx['msg']['sender_id'] !== $uid) {
            return self::err('فقط پیام خودتان را می‌توانید ویرایش کنید.', 403);
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MESSAGE_MAX) {
            return self::err('متن پیام باید بین ۱ تا ۴۰۰۰ نویسه باشد.');
        }
        $stmt = $this->conn->prepare("UPDATE chat_messages SET body = ?, edited_at = NOW(), updated_at = NOW(3) WHERE id = ?");
        $stmt->bind_param('si', $body, $mid);
        $stmt->execute();
        return ['success' => true, 'message' => $this->serializeMany($e, $uid, [$this->messageRow($mid)])[0]];
    }

    /** Delete for everyone (own message, or any message by a group admin): content and files are gone. */
    public function delete(int $e, int $uid, int $mid): array {
        $ctx = $this->ownable($e, $uid, $mid);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $groupAdmin = $ctx['me']['type'] === 'group' && (int)$ctx['me']['is_admin'] === 1;
        if ((int)$ctx['msg']['sender_id'] !== $uid && !$groupAdmin) {
            return self::err('اجازه حذف این پیام را ندارید.', 403);
        }
        WorkFiles::deleteRows($this->conn, WorkFiles::listFor($this->conn, $e, 'chat', $mid));
        $this->conn->query("DELETE FROM chat_reactions WHERE message_id = {$mid}");
        $this->conn->query("UPDATE chat_messages SET body = NULL, forwarded_from = NULL, ref_type = NULL, ref_id = NULL, ref_title = NULL,
            deleted_at = NOW(), updated_at = NOW(3) WHERE id = {$mid}");
        $this->conn->query("UPDATE chat_conversations SET pinned_message_id = NULL WHERE id = {$ctx['msg']['conversation_id']} AND pinned_message_id = {$mid}");
        if ((int)$ctx['msg']['sender_id'] !== $uid) {
            log_activity($this->conn, 'chat_delete_other', "حذف پیام #{$mid} توسط مدیر گروه");
        }
        return ['success' => true];
    }

    /** One reaction per person; the same emoji again removes it. */
    public function react(int $e, int $uid, int $mid, string $emoji): array {
        if (!in_array($emoji, self::REACTIONS, true)) {
            return self::err('واکنش نامعتبر است.');
        }
        $ctx = $this->ownable($e, $uid, $mid);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $cur = $this->conn->query("SELECT emoji FROM chat_reactions WHERE message_id = {$mid} AND user_id = {$uid}")->fetch_assoc();
        if ($cur && $cur['emoji'] === $emoji) {
            $this->conn->query("DELETE FROM chat_reactions WHERE message_id = {$mid} AND user_id = {$uid}");
        } else {
            $stmt = $this->conn->prepare("REPLACE INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $mid, $uid, $emoji);
            $stmt->execute();
        }
        $this->conn->query("UPDATE chat_messages SET updated_at = NOW(3) WHERE id = {$mid}");
        return ['success' => true];
    }

    public function pin(int $e, int $uid, int $cid, ?int $mid): array {
        $me = $this->membership($e, $uid, $cid);
        if (!$me) {
            return self::notFound();
        }
        if ($me['type'] === 'group' && !(int)$me['is_admin']) {
            return self::err('در گروه فقط مدیر می‌تواند پیام سنجاق کند.', 403);
        }
        if ($mid) {
            $m = $this->messageRow($mid);
            if (!$m || (int)$m['conversation_id'] !== $cid || $m['deleted_at'] !== null) {
                return self::err('پیام یافت نشد.', 404);
            }
        }
        $val = $mid ? (int)$mid : 'NULL';
        $this->conn->query("UPDATE chat_conversations SET pinned_message_id = {$val} WHERE id = {$cid}");
        return ['success' => true];
    }

    public function markRead(int $e, int $uid, int $cid): array {
        if (!$this->membership($e, $uid, $cid)) {
            return self::notFound();
        }
        $this->conn->query("UPDATE chat_members SET last_read_message_id = GREATEST(last_read_message_id, (SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE conversation_id = {$cid}))
            WHERE conversation_id = {$cid} AND user_id = {$uid}");
        return ['success' => true];
    }

    /** Copy a message (text, ref, files — physically copied) into another conversation of mine. */
    public function forward(int $e, int $uid, int $mid, int $toCid): array {
        $msg = $this->messageRow($mid);
        if (!$msg || !$this->membership($e, $uid, (int)$msg['conversation_id']) || $msg['deleted_at'] !== null || $msg['kind'] !== 'text') {
            return self::err('پیام یافت نشد.', 404);
        }
        $to = $this->membership($e, $uid, $toCid);
        if (!$to) {
            return self::notFound();
        }
        $from = $msg['forwarded_from'] ?: $this->name($e, (int)$msg['sender_id']);
        $stmt = $this->conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, body, forwarded_from, ref_type, ref_id, ref_title) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisssis', $toCid, $uid, $msg['body'], $from, $msg['ref_type'], $msg['ref_id'], $msg['ref_title']);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id;
        foreach (WorkFiles::listFor($this->conn, $e, 'chat', $mid) as $f) {
            $src = WorkFiles::path($f);
            $stored = dirname($f['stored_name']) . '/' . bin2hex(random_bytes(16)) . '.' . pathinfo($f['stored_name'], PATHINFO_EXTENSION);
            $dest = WorkFiles::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored);
            if (!is_file($src) || !@copy($src, $dest)) {
                continue;
            }
            $stmt = $this->conn->prepare("INSERT INTO work_files (entity_id, owner_type, owner_id, title, file_name, stored_name, size, mime, kind, uploaded_by, is_voice) VALUES (?, 'chat', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisssissii', $e, $newId, $f['title'], $f['file_name'], $stored, $f['size'], $f['mime'], $f['kind'], $uid, $f['is_voice']);
            $stmt->execute();
        }
        $this->conn->query("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = {$toCid}");
        $this->conn->query("UPDATE chat_members SET last_read_message_id = {$newId} WHERE conversation_id = {$toCid} AND user_id = {$uid}");
        $row = $this->messageRow($newId);
        $out = $this->serializeMany($e, $uid, [$row])[0];
        $this->pushNew($e, $uid, $to, $row, $out);
        return ['success' => true, 'message' => $out];
    }

    /** Unread messages over my unmuted conversations. */
    public function unreadTotal(int $e, int $uid): array {
        if (!in_array($uid, $this->team($e), true)) {
            return ['total' => 0, 'chats' => 0];
        }
        $res = $this->conn->query("SELECT m.conversation_id, COUNT(*) n FROM chat_messages x
            JOIN chat_members m ON m.conversation_id = x.conversation_id AND m.user_id = {$uid} AND m.muted = 0
            JOIN chat_conversations c ON c.id = x.conversation_id AND c.entity_id = {$e}
            WHERE x.sender_id <> {$uid} AND x.kind = 'text' AND x.deleted_at IS NULL AND x.id > m.last_read_message_id
            GROUP BY m.conversation_id");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        return ['total' => array_sum(array_map(fn($r) => (int)$r['n'], $rows)), 'chats' => count($rows)];
    }

    /** May this user download a chat file? (member of the message's conversation) */
    public function canSeeFile(int $e, int $uid, array $file): bool {
        if ($file['owner_type'] !== 'chat') {
            return false;
        }
        $m = $this->messageRow((int)$file['owner_id']);
        return $m && $m['deleted_at'] === null && $this->membership($e, $uid, (int)$m['conversation_id']) !== null;
    }

    /** Short text for lists and pushes. */
    public static function preview(?array $m): string {
        if (!$m) {
            return '';
        }
        if (!empty($m['deleted_at'])) {
            return 'پیام حذف شد';
        }
        $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/@\[([^\]]+)\]\(user:\d+\)/u', '@$1', (string)$m['body'])));
        if ($text !== '') {
            return mb_strlen($text) > 90 ? mb_substr($text, 0, 89) . '…' : $text;
        }
        if (!empty($m['ref_title'])) {
            return '🔗 ' . $m['ref_title'];
        }
        if (!empty($m['file_kind'])) {
            return ['voice' => '🎤 پیام صوتی', 'image' => '🖼 تصویر', 'video' => '🎬 ویدئو', 'audio' => '🎵 فایل صوتی'][$m['file_kind']] ?? '📎 فایل';
        }
        return '';
    }

    /** My conversations: pinned first, then by last message; with unread counts and previews. */
    public function listFor(int $e, int $uid): array {
        if (!in_array($uid, $this->team($e), true)) {
            return [];
        }
        $res = $this->conn->query("SELECT c.id, c.type, c.title, c.description, c.last_message_at, m.muted, m.pinned_at, m.is_admin, m.last_read_message_id,
                (SELECT COUNT(*) FROM chat_messages x WHERE x.conversation_id = c.id AND x.sender_id <> {$uid} AND x.kind = 'text'
                    AND x.deleted_at IS NULL AND x.id > m.last_read_message_id) AS unread,
                (SELECT MAX(id) FROM chat_messages y WHERE y.conversation_id = c.id) AS last_id
            FROM chat_conversations c JOIN chat_members m ON m.conversation_id = c.id AND m.user_id = {$uid}
            WHERE c.entity_id = {$e}
            ORDER BY m.pinned_at IS NULL, m.pinned_at DESC, c.last_message_at DESC LIMIT 300");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if (!$rows) {
            return [];
        }
        $names = TeamMember::namesByUser($this->conn, $e);
        $active = $this->team($e);
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $others = [];
        $r2 = $this->conn->query("SELECT conversation_id, user_id FROM chat_members WHERE conversation_id IN ({$ids}) AND user_id <> {$uid}");
        foreach ($r2 ? $r2->fetch_all(MYSQLI_ASSOC) : [] as $m) {
            $others[(int)$m['conversation_id']][] = (int)$m['user_id'];
        }
        $lastIds = implode(',', array_filter(array_map(fn($r) => (int)$r['last_id'], $rows))) ?: '0';
        $last = [];
        $r3 = $this->conn->query("SELECT m.id, m.conversation_id, m.body, m.deleted_at, m.ref_title, m.kind, m.sender_id,
                (SELECT IF(f.is_voice = 1, 'voice', f.kind) FROM work_files f WHERE f.owner_type = 'chat' AND f.owner_id = m.id LIMIT 1) AS file_kind
            FROM chat_messages m WHERE m.id IN ({$lastIds})");
        foreach ($r3 ? $r3->fetch_all(MYSQLI_ASSOC) : [] as $m) {
            $last[(int)$m['conversation_id']] = $m;
        }
        $out = [];
        foreach ($rows as $r) {
            $cid = (int)$r['id'];
            $other = $r['type'] === 'direct' ? ($others[$cid][0] ?? 0) : 0;
            // A direct chat with someone no longer in the team stays readable but quiet.
            $lm = $last[$cid] ?? null;
            $out[] = [
                'id' => $cid,
                'type' => $r['type'],
                'title' => $r['type'] === 'direct' ? ($names[$other] ?? 'همکار سابق') : $r['title'],
                'description' => $r['description'],
                'other_user_id' => $other ?: null,
                'other_active' => $other ? in_array($other, $active, true) : true,
                'members' => count($others[$cid] ?? []) + 1,
                'is_admin' => (int)$r['is_admin'] === 1,
                'muted' => (int)$r['muted'] === 1,
                'pinned' => $r['pinned_at'] !== null,
                'unread' => (int)$r['unread'],
                'last_message_at' => $r['last_message_at'],
                'preview' => self::preview($lm),
                'preview_mine' => $lm && (int)$lm['sender_id'] === $uid && $lm['kind'] === 'text',
            ];
        }
        return $out;
    }
}
