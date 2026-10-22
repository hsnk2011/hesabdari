<?php
/**
 * Messenger conversations: one direct chat per pair, groups with admins,
 * leaving, system messages, list with unread. Run: php tests/chat_conversations_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkChat.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'chat-test'];
$token = 'cc' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $cids = [];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c', 'd'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $chat = new WorkChat($conn);

    $d1 = $chat->openDirect($e, $u['a'], $u['b']);
    $d2 = $chat->openDirect($e, $u['b'], $u['a']);
    $cids[] = (int)($d1['id'] ?? 0);
    expect_true(!empty($d1['success']) && (int)$d1['id'] === (int)$d2['id'], 'one direct conversation per pair (either side opens the same one)');
    expect_true(($chat->openDirect($e, $u['a'], $u['a'])['error'] ?? '') === 'با خودتان نمی‌توانید گفتگو کنید.', 'no direct chat with yourself');
    expect_true(!empty($chat->openDirect($e, $u['a'], 999999999)['error']), 'only team members');
    expect_true(WorkChat::directKey(9, 3) === '3:9', 'directKey sorts ids');

    $g = $chat->createGroup($e, $u['a'], "{$token} گروه فروش", 'هماهنگی فروش', [$u['b'], $u['c']]);
    $gid = (int)($g['id'] ?? 0); $cids[] = $gid;
    expect_true(!empty($g['success']) && (int)$one("SELECT COUNT(*) n FROM chat_members WHERE conversation_id = {$gid}")['n'] === 3, 'group created with creator + 2 members');
    expect_true((int)$one("SELECT is_admin FROM chat_members WHERE conversation_id = {$gid} AND user_id = {$u['a']}")['is_admin'] === 1, 'creator is group admin');
    expect_true((int)$one("SELECT COUNT(*) n FROM chat_messages WHERE conversation_id = {$gid} AND kind = 'system'")['n'] >= 1, 'system message posted');
    expect_true(!empty($chat->createGroup($e, $u['a'], '  ', null, [])['error']), 'group needs a title');

    expect_true(($chat->addMembers($e, $u['b'], $gid, [$u['d']])['statusCode'] ?? 0) === 403, 'non-admin cannot add members');
    expect_true(!empty($chat->addMembers($e, $u['a'], $gid, [$u['d']])['success']) && $chat->membership($e, $u['d'], $gid) !== null, 'admin adds D');
    expect_true(($chat->removeMember($e, $u['a'], $gid, $u['a'])['error'] ?? '') === 'برای خروج از گروه از گزینه «ترک گروه» استفاده کنید.', 'admin cannot remove self (use leave)');
    expect_true(!empty($chat->removeMember($e, $u['a'], $gid, $u['d'])['success']) && $chat->membership($e, $u['d'], $gid) === null, 'admin removes D; D loses access');
    expect_true(($chat->setAdmin($e, $u['a'], $gid, $u['a'], false)['error'] ?? '') === 'گروه باید حداقل یک مدیر داشته باشد.', 'last admin cannot be demoted');
    expect_true(($chat->membership($e, $u['c'], 999999999)) === null, 'unknown conversation: no membership');

    // Leaving: last admin hands over to the oldest member
    $conn->query("UPDATE chat_members SET joined_at = '2020-01-01 00:00:00' WHERE conversation_id = {$gid} AND user_id = {$u['b']}");
    $chat->leave($e, $u['a'], $gid);
    expect_true($chat->membership($e, $u['a'], $gid) === null && (int)$one("SELECT is_admin FROM chat_members WHERE conversation_id = {$gid} AND user_id = {$u['b']}")['is_admin'] === 1, 'when the last admin leaves, the oldest member becomes admin');

    // List with unread
    $conn->query("INSERT INTO chat_messages (conversation_id, sender_id, body) VALUES ({$d1['id']}, {$u['b']}, 'سلام')");
    $list = $chat->listFor($e, $u['a']);
    $row = array_values(array_filter($list, fn($x) => (int)$x['id'] === (int)$d1['id']))[0] ?? null;
    expect_true($row && $row['title'] === "{$token} b" && (int)$row['unread'] === 1 && $row['preview'] === 'سلام', 'list: direct titled by the other person, preview and unread');
    expect_true(!in_array($gid, array_map(fn($x) => (int)$x['id'], $list), true), 'a group I left is not in my list');
    $chat->setMuted($e, $u['a'], (int)$d1['id'], true);
    expect_true((int)$one("SELECT muted FROM chat_members WHERE conversation_id = {$d1['id']} AND user_id = {$u['a']}")['muted'] === 1, 'mute saved');

    // Deactivated team member loses access
    $tm = $one("SELECT id FROM team_members WHERE user_id = {$u['c']}");
    $team->deactivate($e, (int)$tm['id']);
    expect_true($chat->membership($e, $u['c'], $gid) === null, 'a deactivated team member loses access');

    // Everyone leaves → group deleted
    $chat->leave($e, $u['b'], $gid);
    $conn->query("UPDATE team_members SET is_active = 1 WHERE user_id = {$u['c']}");
    $chat->leave($e, $u['c'], $gid);
    expect_true($one("SELECT id FROM chat_conversations WHERE id = {$gid}") === null && (int)$one("SELECT COUNT(*) n FROM chat_messages WHERE conversation_id = {$gid}")['n'] === 0, 'empty group is deleted with its messages');
} finally {
    foreach ($cids as $cid) {
        $conn->query("DELETE FROM chat_messages WHERE conversation_id = {$cid}");
        $conn->query("DELETE FROM chat_members WHERE conversation_id = {$cid}");
        $conn->query("DELETE FROM chat_conversations WHERE id = {$cid}");
    }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
