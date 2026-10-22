<?php
/**
 * میز کار projects: who may create, visibility, members and managers,
 * progress, delete (with files). Run: php tests/work_projects_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkProject.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wp' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $pids = [];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.png';
file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c', 'd'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s], 'is_active' => $s === 'd' ? 0 : 1]);
    }
    $wp = new WorkProject($conn);

    $denied = $wp->create($e, $u['b'], false, ['title' => "{$token} بی‌اجازه"]);
    expect_true(($denied['statusCode'] ?? 0) === 403, 'without manage_projects a project cannot be created');
    expect_true(!empty($wp->create($e, $u['a'], true, ['title' => '  '])['error']), 'an empty title is refused');
    $r = $wp->create($e, $u['a'], true, ['title' => "{$token} پروژه", 'description' => 'شرح', 'status' => 'active', 'priority' => 'high', 'due_date' => '2026-12-01', 'budget' => '5,000,000', 'members' => [['user_id' => $u['b'], 'role' => 'member']]]);
    expect_true(!empty($r['success']), 'project created' . (!empty($r['error']) ? ' — ' . $r['error'] : ''));
    $pid = (int)($r['id'] ?? 0); $pids[] = $pid;
    expect_true(($one("SELECT title FROM projects WHERE id = {$pid}")['title'] ?? '') === "{$token} پروژه", 'returned id is the project row');
    expect_true(($one("SELECT role FROM project_members WHERE project_id = {$pid} AND user_id = {$u['a']}")['role'] ?? '') === 'manager', 'creator is a manager member');
    expect_true(($one("SELECT role FROM project_members WHERE project_id = {$pid} AND user_id = {$u['b']}")['role'] ?? '') === 'member', 'B added as member');
    expect_true((int)($one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['b']} AND type = 'work_project' AND link LIKE '%#work/project/{$pid}/members'")['n'] ?? 0) === 1, 'B notified with a link to members');
    expect_true(abs((float)$one("SELECT budget FROM projects WHERE id = {$pid}")['budget'] - 5000000) < 0.01, 'budget parsed from formatted money');

    $acc = $wp->access($e, $u['b'], false, $pid);
    expect_true($acc && $acc['isMember'] && !$acc['canManage'], 'B sees it as a member who cannot manage');
    expect_true($wp->access($e, $u['c'], false, $pid) === null, 'outsider C cannot see it');
    expect_true(($wp->access($e, $u['c'], true, $pid)['canManage'] ?? false) === true, 'manage_projects sees and manages every project');
    expect_true(in_array($pid, array_column($wp->listFor($e, $u['b'], false, 'mine'), 'id')), "it is in B's «mine» list");
    expect_true(!in_array($pid, array_column($wp->listFor($e, $u['c'], false, 'all'), 'id')), "it is not in C's list");
    expect_true(!empty($wp->update($e, $u['b'], false, $pid, ['title' => 'x'])['error']), 'a plain member cannot edit the project');

    expect_true(!empty($wp->addMember($e, $u['a'], false, $pid, $u['d'], 'member')['error']), 'a deactivated team member cannot be added');
    $onlyMgr = $wp->removeMember($e, $u['a'], false, $pid, $u['a']);
    expect_true(($onlyMgr['error'] ?? '') === 'آخرین مدیر پروژه را نمی‌توان برداشت؛ ابتدا مدیر دیگری تعیین کنید.', 'the last manager cannot be removed');
    expect_true(!empty($wp->setMemberRole($e, $u['a'], false, $pid, $u['a'], 'member')['error']), 'the last manager cannot be demoted');

    $conn->query("INSERT INTO project_tasks (project_id, title, status, assignee_user_id) VALUES ({$pid}, '{$token} t1', 'todo', {$u['b']}), ({$pid}, '{$token} t2', 'done', {$u['b']})");
    $p = array_values(array_filter($wp->listFor($e, $u['a'], false, 'active'), fn($x) => (int)$x['id'] === $pid))[0] ?? null;
    expect_true($p && $p['progress']['percent'] === 50 && $p['progress']['total'] === 2, 'progress 1 of 2 done = 50%');
    expect_true($wp->removeMember($e, $u['a'], false, $pid, $u['b'])['success'] ?? false, 'B removed');
    expect_true($one("SELECT assignee_user_id a FROM project_tasks WHERE title = '{$token} t1'")['a'] === null, "B's open task is unassigned");
    expect_true((int)$one("SELECT assignee_user_id a FROM project_tasks WHERE title = '{$token} t2'")['a'] === $u['b'], "B's done task keeps its doer");
    expect_true($wp->access($e, $u['b'], false, $pid) === null, 'removed member no longer sees the project');

    require_once $root . '/api/models/WorkFiles.php';
    $f = WorkFiles::store($conn, $e, 'project', $pid, $u['a'], [['name' => 'x.png', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => 0]], [], false);
    $path = WorkFiles::path(WorkFiles::get($conn, $e, (int)$f['ids'][0]));
    expect_true(!empty($wp->delete($e, $u['b'], false, $pid)['error']), 'non-member cannot delete');
    $del = $wp->delete($e, $u['a'], false, $pid);
    expect_true(!empty($del['success']) && $one("SELECT id FROM projects WHERE id = {$pid}") === null, 'manager deletes the project');
    expect_true((int)$one("SELECT COUNT(*) n FROM project_tasks WHERE project_id = {$pid}")['n'] === 0 && !is_file($path), 'its tasks and files are gone (file removed from disk)');
} finally {
    foreach ($pids as $pid) {
        $conn->query("DELETE FROM project_tasks WHERE project_id = {$pid}");
        $conn->query("DELETE FROM project_members WHERE project_id = {$pid}");
        $conn->query("DELETE FROM projects WHERE id = {$pid}");
    }
    $conn->query("DELETE FROM projects WHERE title = 'x' AND created_by = " . (int)($u['a'] ?? 0));
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
    @unlink($tmp);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
