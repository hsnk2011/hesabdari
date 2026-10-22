<?php
/**
 * Messenger messages: send with file/reply/ref, read access, sync cursor
 * (edits, deletes, reactions), edit/delete rules, reactions, unread, pin,
 * forward with file copy. Run: php tests/chat_messages_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkProject.php';
require_once $root . '/api/models/WorkChat.php';
require_once $root . '/api/models/WorkTask.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'chat-test'];
$token = 'cm' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $cids = []; $pid = 0;
$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$file = fn() => ['name' => 'عکس.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => 0];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $chat = new WorkChat($conn);
    $gid = (int)$chat->createGroup($e, $u['a'], "{$token} گروه", null, [$u['b']])['id']; $cids[] = $gid;
    $did = (int)$chat->openDirect($e, $u['b'], $u['c'])['id']; $cids[] = $did;

    // Send
    $m1 = $chat->send($e, $u['a'], $gid, ['body' => 'سلام به همه'], [$file()], false);
    expect_true(!empty($m1['success']) && ($m1['message']['body'] ?? '') === 'سلام به همه' && count($m1['message']['attachments']) === 1, 'send text + file');
    $mid1 = (int)$m1['message']['id'];
    expect_true(!empty($chat->send($e, $u['a'], $gid, ['body' => '   '], [], false)['error']), 'empty message refused');
    expect_true(!empty($chat->send($e, $u['a'], $gid, ['body' => str_repeat('x', 4001)], [], false)['error']), 'message longer than 4000 refused');
    expect_true(($chat->send($e, $u['c'], $gid, ['body' => 'نفوذ'], [], false)['statusCode'] ?? 0) === 404, 'non-member cannot send');
    expect_true(($chat->messages($e, $u['c'], $gid, [])['statusCode'] ?? 0) === 404, 'non-member cannot read');
    $fileRow = WorkFiles::listFor($conn, $e, 'chat', $mid1)[0];
    expect_true($chat->canSeeFile($e, $u['b'], $fileRow) && !$chat->canSeeFile($e, $u['c'], $fileRow), 'only members may download a chat file');

    $r = $chat->send($e, $u['b'], $gid, ['body' => 'سلام!', 'reply_to_id' => $mid1], [], false);
    expect_true(($r['message']['reply_to']['snippet'] ?? '') === 'سلام به همه', 'reply carries the quoted snippet');

    // Ref: only things the sender can see
    $pid = (int)(new WorkProject($conn))->create($e, $u['a'], true, ['title' => "{$token} پروژه"])['id'];
    expect_true(!empty($chat->send($e, $u['b'], $gid, ['ref_type' => 'project', 'ref_id' => $pid], [], false)['error']), 'cannot share a project you cannot see');
    $refMsg = $chat->send($e, $u['a'], $gid, ['ref_type' => 'project', 'ref_id' => $pid], [], false);
    expect_true(($refMsg['message']['ref_title'] ?? '') === "{$token} پروژه", 'project shared with its title');

    // Unread + read
    $list = $chat->listFor($e, $u['b']);
    $row = array_values(array_filter($list, fn($x) => (int)$x['id'] === $gid))[0];
    expect_true((int)$row['unread'] === 1, "B has 1 unread: A's message after B's own reply (sending marks earlier ones read)");
    $tot = $chat->unreadTotal($e, $u['b']);
    expect_true($tot['total'] === 1 && $tot['chats'] === 1, 'unread total 1 in 1 chat');
    $chat->setMuted($e, $u['b'], $gid, true);
    expect_true($chat->unreadTotal($e, $u['b'])['total'] === 0, 'muted chat is not counted in the total');
    $chat->setMuted($e, $u['b'], $gid, false);
    $chat->markRead($e, $u['b'], $gid);
    expect_true($chat->unreadTotal($e, $u['b'])['total'] === 0, 'opening the chat marks it read');
    $tid = (int)(new WorkTask($conn))->create($e, $u['a'], true, $pid, ['title' => "{$token} کار"])['id'];
    $tm = $chat->send($e, $u['a'], $gid, ['ref_type' => 'project_task', 'ref_id' => $tid], [], false);
    expect_true(($tm['message']['ref_project_id'] ?? 0) === $pid, 'a shared project task carries its project id for the link');
    $chat->markRead($e, $u['b'], $gid);

    // Sync cursor: edits, deletes, reactions
    $page = $chat->messages($e, $u['b'], $gid, []);
    $cursor = $page['cursor'];
    usleep(20000);
    expect_true(!empty($chat->edit($e, $u['a'], $mid1, 'سلام به همه (ویرایش)')['success']), 'sender edits own message');
    expect_true(($chat->edit($e, $u['b'], $mid1, 'تحریف')['statusCode'] ?? 0) === 403, "nobody edits someone else's message");
    expect_true(!empty($chat->react($e, $u['b'], $mid1, '👍')['success']), 'reaction added');
    expect_true(!empty($chat->react($e, $u['b'], $mid1, '🍕')['error']), 'unknown emoji refused');
    $since = $chat->messages($e, $u['b'], $gid, ['since' => $cursor]);
    $changed = array_values(array_filter($since['messages'], fn($x) => (int)$x['id'] === $mid1))[0] ?? null;
    expect_true($changed && $changed['edited_at'] !== null && ($changed['reactions'][0]['emoji'] ?? '') === '👍' && $changed['reactions'][0]['mine'] === true, 'since-cursor returns the edit and the reaction');
    $chat->react($e, $u['b'], $mid1, '👍');
    expect_true((int)$one("SELECT COUNT(*) n FROM chat_reactions WHERE message_id = {$mid1}")['n'] === 0, 'same reaction again removes it');

    // Delete rules
    $path = WorkFiles::path($fileRow);
    $bMsg = (int)$r['message']['id'];
    expect_true(($chat->delete($e, $u['b'], $mid1)['statusCode'] ?? 0) === 403, "a member cannot delete someone else's message");
    $cursor2 = $chat->messages($e, $u['b'], $gid, [])['cursor'];
    usleep(20000);
    expect_true(!empty($chat->delete($e, $u['a'], $mid1)['success']) && !is_file($path), 'sender deletes own message; its file leaves the disk');
    expect_true(!empty($chat->delete($e, $u['a'], $bMsg)['success']), "group admin deletes a member's message");
    $since2 = $chat->messages($e, $u['b'], $gid, ['since' => $cursor2]);
    $del = array_values(array_filter($since2['messages'], fn($x) => (int)$x['id'] === $mid1))[0] ?? null;
    expect_true($del && $del['deleted'] === true && $del['body'] === null && $del['attachments'] === [], 'since-cursor returns the deletion without content');
    expect_true(!empty($chat->edit($e, $u['a'], $mid1, 'x')['error']), 'a deleted message cannot be edited');

    // Pin
    $m2 = (int)$chat->send($e, $u['b'], $gid, ['body' => 'نکته مهم'], [], false)['message']['id'];
    expect_true(($chat->pin($e, $u['b'], $gid, $m2)['statusCode'] ?? 0) === 403, 'in a group only admins pin');
    $chat->pin($e, $u['a'], $gid, $m2);
    expect_true((int)($chat->messages($e, $u['b'], $gid, [])['pinned']['id'] ?? 0) === $m2, 'pinned message returned with the page');

    // Forward with file copy
    $fm = $chat->send($e, $u['b'], $gid, ['body' => 'فاکتور'], [$file()], false);
    $fw = $chat->forward($e, $u['b'], (int)$fm['message']['id'], $did);
    $copy = $fw['message'] ?? null;
    $origFile = WorkFiles::listFor($conn, $e, 'chat', (int)$fm['message']['id'])[0];
    $copyFile = $copy ? (WorkFiles::listFor($conn, $e, 'chat', (int)$copy['id'])[0] ?? null) : null;
    expect_true($copy && $copy['forwarded_from'] === "{$token} b" && $copyFile && $copyFile['stored_name'] !== $origFile['stored_name'] && is_file(WorkFiles::path($copyFile)), 'forward copies text and file (separate copy) and records the sender');
    expect_true(($chat->forward($e, $u['c'], (int)$fm['message']['id'], $did)['statusCode'] ?? 0) === 404, 'cannot forward a message you cannot see');

    // Search and paging
    $found = $chat->messages($e, $u['b'], $gid, ['search' => 'نکته']);
    expect_true(count($found['messages']) === 1 && (int)$found['messages'][0]['id'] === $m2, 'search finds the message');
    $before = $chat->messages($e, $u['b'], $gid, ['before_id' => $m2]);
    expect_true(!in_array($m2, array_map(fn($x) => (int)$x['id'], $before['messages']), true), 'before_id pages older messages');
} finally {
    foreach ($cids as $cid) {
        $ids = array_column($conn->query("SELECT id FROM chat_messages WHERE conversation_id = {$cid}")->fetch_all(MYSQLI_ASSOC), 'id');
        foreach ($ids as $mid) { WorkFiles::deleteRows($conn, WorkFiles::listFor($conn, $e, 'chat', (int)$mid)); $conn->query("DELETE FROM chat_reactions WHERE message_id = " . (int)$mid); }
        $conn->query("DELETE FROM chat_messages WHERE conversation_id = {$cid}");
        $conn->query("DELETE FROM chat_members WHERE conversation_id = {$cid}");
        $conn->query("DELETE FROM chat_conversations WHERE id = {$cid}");
    }
    if ($pid) { $conn->query("DELETE FROM project_members WHERE project_id = {$pid}"); $conn->query("DELETE FROM projects WHERE id = {$pid}"); }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
    @unlink($png);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
