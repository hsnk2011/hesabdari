<?php
/**
 * Ideas: anonymous author hidden, votes, comments, edit rules, decision
 * (approve → project, reject needs note). Run: php tests/work_ideas_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkIdea.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'idea-test'];
$token = 'wi' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $iids = [];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $wi = new WorkIdea($conn);
    $deciders = fn() => [$u['c']];
    $wi->decidersResolver = $deciders;

    $r = $wi->create($e, $u['a'], ['title' => 'ویترین چرخشی', 'body' => 'هر هفته یک فرش ویژه در ویترین', 'is_anonymous' => 1]);
    expect_true(!empty($r['success']), 'idea created');
    $id = (int)$r['id']; $iids[] = $id;
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['c']} AND link LIKE '%#work/idea/{$id}'")['n'] === 1, 'deciders notified');
    expect_true(!empty($wi->create($e, $u['a'], ['title' => '', 'body' => 'x'])['error']), 'title required');

    $forB = array_values(array_filter($wi->listFor($e, $u['b'], false), fn($x) => (int)$x['id'] === $id))[0] ?? null;
    expect_true($forB && $forB['author_user_id'] === null && $forB['author_name'] === null && $forB['is_anonymous'] === true, 'anonymous: author hidden from others in the list');
    $dB = $wi->detail($e, $u['b'], false, $id);
    expect_true($dB['idea']['author_user_id'] === null && $dB['idea']['author_name'] === null, 'anonymous: hidden in detail too');
    $dA = $wi->detail($e, $u['a'], false, $id);
    expect_true((int)$dA['idea']['author_user_id'] === $u['a'] && $dA['idea']['is_mine'] === true, 'the author still sees it is theirs');

    // Votes
    $wi->vote($e, $u['b'], $id, true);
    $wi->vote($e, $u['c'], $id, false);
    $d = $wi->detail($e, $u['b'], false, $id);
    expect_true($d['idea']['agree'] === 1 && $d['idea']['disagree'] === 1 && $d['idea']['my_vote'] === 'agree', 'vote counts and my vote');
    $wi->vote($e, $u['b'], $id, true);
    expect_true($wi->detail($e, $u['b'], false, $id)['idea']['agree'] === 0, 'same vote again removes it');

    // Comments
    expect_true(!empty($wi->comment($e, $u['b'], $id, 'خیلی خوبه')['success']) && count($wi->detail($e, $u['a'], false, $id)['comments']) === 1, 'comment added');
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['a']} AND link LIKE '%#work/idea/{$id}'")['n'] >= 1, 'author notified about the comment');

    $wi->comment($e, $u['a'], $id, 'توضیح بیشتر از نویسنده');
    $cB = array_values(array_filter($wi->detail($e, $u['b'], false, $id)['comments'], fn($x) => $x['body'] === 'توضیح بیشتر از نویسنده'))[0] ?? null;
    expect_true($cB && $cB['author_name'] === 'نویسنده ایده' && !isset($cB['author_user_id']), "anonymous author's own comment does not reveal them");
    $nB = $one("SELECT message FROM notifications WHERE user_id = {$u['b']} AND link LIKE '%#work/idea/{$id}' ORDER BY id DESC LIMIT 1");
    expect_true($nB && strpos($nB['message'], "{$token} a") === false, 'nor does its notification');

    // Edit rules + decide
    expect_true(($wi->update($e, $u['b'], $id, ['title' => 'x', 'body' => 'y'])['statusCode'] ?? 0) === 403, 'only the author edits');
    expect_true(($wi->decide($e, $u['b'], false, $id, 'approve', [])['statusCode'] ?? 0) === 403, 'only deciders decide');
    expect_true(!empty($wi->decide($e, $u['c'], true, $id, 'reject', [])['error']), 'reject needs a note');
    $ok = $wi->decide($e, $u['c'], true, $id, 'approve', ['note' => 'اجرا شود', 'member_ids' => [$u['b']]]);
    $row = $one("SELECT status, project_id FROM ideas WHERE id = {$id}");
    expect_true(!empty($ok['success']) && $row['status'] === 'approved' && $row['project_id'] !== null, 'approve creates a project and links it' . (!empty($ok['error']) ? ' — ' . $ok['error'] : ''));
    $pid = (int)$row['project_id'];
    $members = array_map('intval', array_column($conn->query("SELECT user_id FROM project_members WHERE project_id = {$pid}")->fetch_all(MYSQLI_ASSOC), 'user_id'));
    expect_true(in_array($u['a'], $members, true) && in_array($u['b'], $members, true) && in_array($u['c'], $members, true), 'author, chosen member and decider are project members');
    expect_true(($one("SELECT title FROM projects WHERE id = {$pid}")['title'] ?? '') === 'ویترین چرخشی', 'project titled after the idea');
    expect_true(!empty($wi->update($e, $u['a'], $id, ['title' => 'x', 'body' => 'y'])['error']), 'decided idea cannot be edited');
    expect_true(!empty($wi->decide($e, $u['c'], true, $id, 'approve', [])['error']), 'cannot approve twice');

    $r2 = $wi->create($e, $u['b'], ['title' => "{$token} دیگر", 'body' => 'متن']);
    $iids[] = (int)$r2['id'];
    $wi->decide($e, $u['c'], true, (int)$r2['id'], 'reject', ['note' => 'بودجه نداریم']);
    expect_true($one("SELECT status FROM ideas WHERE id = " . (int)$r2['id'])['status'] === 'rejected', 'rejected with note');
    expect_true(!empty($wi->delete($e, $u['b'], (int)$r2['id'])['error']), 'decided idea cannot be deleted by author');
} finally {
    foreach ($iids as $id) {
        $p = $one("SELECT project_id FROM ideas WHERE id = {$id}");
        if (!empty($p['project_id'])) { $conn->query("DELETE FROM project_members WHERE project_id = " . (int)$p['project_id']); $conn->query("DELETE FROM projects WHERE id = " . (int)$p['project_id']); }
        $conn->query("DELETE FROM idea_votes WHERE idea_id = {$id}"); $conn->query("DELETE FROM idea_comments WHERE idea_id = {$id}"); $conn->query("DELETE FROM ideas WHERE id = {$id}");
    }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
