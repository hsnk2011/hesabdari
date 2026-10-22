<?php
/**
 * میز کار tasks: who may move them, complete → review → approve / send back,
 * prerequisites (cycles, blocking), checklist, order.
 * Run: php tests/work_tasks_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkProject.php';
require_once $root . '/api/models/WorkTask.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wt' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $pid = 0;

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $pid = (int)(new WorkProject($conn))->create($e, $u['a'], true, ['title' => "{$token} پروژه", 'members' => [['user_id' => $u['b']], ['user_id' => $u['c']]]])['id'];
    $wt = new WorkTask($conn);
    $status = fn(int $id) => $one("SELECT * FROM project_tasks WHERE id = {$id}");
    $notes = fn(int $user, string $like) => (int)($one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$user} AND type = 'work_task' AND title LIKE '" . $conn->real_escape_string($like) . "'")['n'] ?? 0);

    // Create
    $t1 = $wt->create($e, $u['b'], false, $pid, ['title' => "{$token} طراحی", 'assignee_user_id' => $u['b'], 'due_date' => '2026-11-01']);
    expect_true(!empty($t1['success']) && ($status((int)$t1['id'])['title'] ?? '') === "{$token} طراحی", 'member creates a task; returned id is the task');
    expect_true(!empty($wt->create($e, $u['b'], false, $pid, ['title' => 'x', 'assignee_user_id' => 999999999])['error']), 'assignee must be a project member');
    expect_true(!empty($wt->create($e, $u['a'], false, $pid, ['title' => '   '])['error']), 'empty title refused');
    $t2 = $wt->create($e, $u['a'], false, $pid, ['title' => "{$token} چاپ", 'assignee_user_id' => $u['c']]);
    expect_true($notes($u['c'], 'کار جدید%') === 1, 'assignee C is notified about the new task');

    // Who may move
    expect_true(($wt->setStatus($e, $u['b'], false, (int)$t2['id'], 'in_progress')['statusCode'] ?? 0) === 403, "B cannot move C's task");
    expect_true(!empty($wt->setStatus($e, $u['b'], false, (int)$t1['id'], 'done')['error']), 'done cannot be set directly');
    expect_true(!empty($wt->setStatus($e, $u['b'], false, (int)$t1['id'], 'in_progress')['success']), 'B starts own task');

    // Complete → review → send back → complete → approve
    $c1 = $wt->complete($e, $u['b'], false, (int)$t1['id'], 'طرح اول آماده است');
    $row = $status((int)$t1['id']);
    expect_true(($c1['status'] ?? '') === 'review' && $row['status'] === 'review' && (int)$row['completed_by'] === $u['b'] && $row['completion_note'] === 'طرح اول آماده است', 'member completion waits for review with the note');
    expect_true($notes($u['a'], 'منتظر تأیید شما:%') === 1, 'manager A gets «منتظر تأیید شما»');
    expect_true(($wt->approve($e, $u['b'], false, (int)$t1['id'], null)['statusCode'] ?? 0) === 403, 'a member cannot approve');
    $wt->sendBack($e, $u['a'], false, (int)$t1['id'], 'رنگ‌ها را اصلاح کن');
    $row = $status((int)$t1['id']);
    expect_true($row['status'] === 'in_progress' && $row['review_note'] === 'رنگ‌ها را اصلاح کن' && $row['completion_note'] === null, 'send back returns it to in progress with the reason');
    expect_true($notes($u['b'], '%برای ادامه برگشت') === 1, 'B is told it came back');
    $wt->complete($e, $u['b'], false, (int)$t1['id'], null);
    $ap = $wt->approve($e, $u['a'], false, (int)$t1['id'], 'عالی');
    $row = $status((int)$t1['id']);
    expect_true(!empty($ap['success']) && $row['status'] === 'done' && (int)$row['reviewed_by'] === $u['a'] && (int)$row['completed_by'] === $u['b'] && $row['completed_at'] !== null, 'approve finishes it; doer stays B, approver A');
    expect_true(!empty($wt->approve($e, $u['a'], false, (int)$t1['id'], null)['error']), 'approving twice is refused');

    // Manager's own completion is direct
    $t3 = $wt->create($e, $u['a'], false, $pid, ['title' => "{$token} مدیر", 'assignee_user_id' => $u['a']]);
    expect_true(($wt->complete($e, $u['a'], false, (int)$t3['id'], null)['status'] ?? '') === 'done', "manager's own completion is done directly");
    expect_true(!empty($wt->reopen($e, $u['a'], false, (int)$t3['id'])['success']) && $status((int)$t3['id'])['status'] === 'in_progress', 'manager reopens a done task');

    // Prerequisites
    $ta = $wt->create($e, $u['a'], false, $pid, ['title' => "{$token} A"]);
    $tb = $wt->create($e, $u['a'], false, $pid, ['title' => "{$token} B", 'depends_on' => [(int)$ta['id']]]);
    $start = $wt->setStatus($e, $u['a'], false, (int)$tb['id'], 'in_progress');
    expect_true(($start['error'] ?? '') === "اول این پیش‌نیازها باید انجام شود: «{$token} A»", 'open prerequisite blocks start with its name');
    expect_true(!empty($wt->complete($e, $u['a'], false, (int)$tb['id'], null)['error']), 'open prerequisite blocks completion');
    expect_true(!empty($wt->setDeps($e, $u['a'], false, (int)$ta['id'], [(int)$tb['id']])['error']), 'a cycle A→B→A is refused');
    expect_true(WorkTask::createsCycle(1, [2], [['task_id' => 2, 'depends_on_id' => 1]]) === true, 'createsCycle: 1→2, 2→1 is a cycle');
    expect_true(WorkTask::createsCycle(1, [2], [['task_id' => 2, 'depends_on_id' => 3]]) === false, 'createsCycle: 1→2→3 is not');
    expect_true(WorkTask::createsCycle(1, [1], []) === true, 'createsCycle: self dependency is a cycle');
    $wt->delete($e, $u['a'], false, (int)$ta['id']);
    expect_true(!empty($wt->setStatus($e, $u['a'], false, (int)$tb['id'], 'in_progress')['success']), 'deleting the prerequisite unblocks the task');

    // Checklist
    $ck = $wt->addCheck($e, $u['c'], false, (int)$t2['id'], 'فایل چاپ');
    $tog = $wt->toggleCheck($e, $u['c'], false, (int)$ck['id'], true);
    $crow = $one("SELECT done, done_by FROM project_task_checks WHERE id = " . (int)$ck['id']);
    expect_true(!empty($tog['success']) && (int)$crow['done'] === 1 && (int)$crow['done_by'] === $u['c'], 'checklist tick records who');
    for ($i = 1; $i < 50; $i++) { $conn->query("INSERT INTO project_task_checks (task_id, text) VALUES (" . (int)$t2['id'] . ", 'c{$i}')"); }
    expect_true(!empty($wt->addCheck($e, $u['c'], false, (int)$t2['id'], 'پنجاه و یکم')['error']), 'the 51st checklist item is refused');

    // Order + listing
    $ids = [(int)$t3['id'], (int)$t2['id'], (int)$tb['id']];
    $wt->reorder($e, $u['b'], false, $pid, $ids);
    expect_true((int)$status((int)$t2['id'])['sort_order'] === 2, 'reorder saves positions');
    $list = $wt->listForProject($e, $pid, $u['a']);
    $t2row = array_values(array_filter($list['tasks'], fn($t) => (int)$t['id'] === (int)$t2['id']))[0] ?? null;
    expect_true($t2row && $t2row['checks_total'] === 50 && $t2row['checks_done'] === 1 && $t2row['assignee_name'] === "{$token} c", 'list carries checklist counts and assignee name');
    expect_true(count($list['done_log']) >= 1, 'done log lists finished tasks');
    expect_true(($wt->setStatus($e, $u['a'], false, (int)$t2['id'], 'in_progress')['success'] ?? false) === true, 'manager may move any task');
} finally {
    if ($pid) {
        $conn->query("DELETE c FROM project_task_checks c JOIN project_tasks t ON t.id = c.task_id WHERE t.project_id = {$pid}");
        $conn->query("DELETE d FROM project_task_deps d JOIN project_tasks t ON t.id = d.task_id WHERE t.project_id = {$pid}");
        $conn->query("DELETE FROM project_tasks WHERE project_id = {$pid}");
        $conn->query("DELETE FROM project_members WHERE project_id = {$pid}");
        $conn->query("DELETE FROM projects WHERE id = {$pid}");
    }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
