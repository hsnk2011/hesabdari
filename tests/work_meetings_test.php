<?php
/**
 * میز کار meetings: visibility, invitations, decisions, decision → project
 * task, files. Run: php tests/work_meetings_test.php
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
require_once $root . '/api/models/WorkMeeting.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wm' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $pid = 0; $mids = [];
$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c', 'd'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    // Project: A manager, B member (C attends meetings but is not a project member)
    $pid = (int)(new WorkProject($conn))->create($e, $u['a'], true, ['title' => "{$token} پروژه", 'members' => [['user_id' => $u['b']]]])['id'];
    $wm = new WorkMeeting($conn);
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $r = $wm->create($e, $u['a'], false, ['title' => "{$token} جلسه هفتگی", 'date' => $tomorrow, 'time' => '10:30', 'location' => 'دفتر', 'agenda' => 'بودجه', 'project_id' => $pid, 'attendee_ids' => [$u['b'], $u['c']]]);
    expect_true(!empty($r['success']), 'meeting created' . (!empty($r['error']) ? ' — ' . $r['error'] : ''));
    $mid = (int)($r['id'] ?? 0); $mids[] = $mid;
    expect_true(($one("SELECT starts_at FROM meetings WHERE id = {$mid}")['starts_at'] ?? '') === "{$tomorrow} 10:30:00", 'start time stored');
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE type = 'work_meeting' AND title LIKE 'دعوت به جلسه:%' AND user_id IN ({$u['b']}, {$u['c']}) AND link LIKE '%#work/meeting/{$mid}'")['n'] === 2, 'B and C invited with a link');
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE type = 'work_meeting' AND user_id = {$u['a']}")['n'] === 0, 'creator is not invited');
    expect_true($wm->visible($e, $u['c'], false, $mid) !== null, 'attendee sees the meeting');
    expect_true($wm->visible($e, $u['d'], false, $mid) === null, 'non-attendee does not');
    expect_true($wm->visible($e, $u['d'], true, $mid) !== null, 'manage_projects sees every meeting');
    expect_true(in_array($mid, array_column($wm->listFor($e, $u['b'], false, 'upcoming'), 'id')), 'it is in B\'s upcoming list');
    expect_true(($wm->update($e, $u['b'], false, $mid, ['title' => 'x', 'date' => $tomorrow])['statusCode'] ?? 0) === 403, 'only the creator edits');
    expect_true(!empty($wm->create($e, $u['a'], false, ['title' => "{$token} بد", 'date' => 'فردا'])['error']), 'invalid date refused');
    $foreignPid = $conn->query("INSERT INTO projects (entity_id, title) VALUES ({$e}, '{$token} غریبه')") ? (int)$conn->insert_id : 0;
    expect_true(!empty($wm->create($e, $u['b'], false, ['title' => "{$token} ب", 'date' => $tomorrow, 'project_id' => $foreignPid])['error']), 'cannot attach a project you cannot see');
    $conn->query("DELETE FROM projects WHERE id = {$foreignPid}");

    // Decisions
    $a1 = $wm->addAction($e, $u['c'], false, $mid, ['text' => 'تهیه نمونه رنگ', 'assignee_user_id' => $u['b'], 'due_date' => '2026-11-10']);
    expect_true(!empty($a1['success']), 'attendee records a decision');
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['b']} AND type = 'work_meeting' AND link LIKE '%/action/" . (int)$a1['id'] . "'")['n'] === 1, 'assignee notified about the decision');
    expect_true(($wm->addAction($e, $u['d'], false, $mid, ['text' => 'نه'])['statusCode'] ?? 0) === 404, 'outsider cannot add a decision');
    $conv = $wm->actionToTask($e, $u['a'], false, $mid, (int)$a1['id']);
    $task = $one("SELECT * FROM project_tasks WHERE id = " . (int)($conv['task_id'] ?? 0));
    expect_true(!empty($conv['success']) && $conv['assigned'] === true && $task && (int)$task['project_id'] === $pid && $task['title'] === 'تهیه نمونه رنگ' && $task['due_date'] === '2026-11-10' && (int)$task['assignee_user_id'] === $u['b'], 'decision becomes a project task with text, due date and assignee');
    expect_true(!empty($wm->actionToTask($e, $u['a'], false, $mid, (int)$a1['id'])['error']), 'a decision converts only once');
    $a2 = $wm->addAction($e, $u['a'], false, $mid, ['text' => 'تماس با چاپخانه', 'assignee_user_id' => $u['c']]);
    $conv2 = $wm->actionToTask($e, $u['a'], false, $mid, (int)$a2['id']);
    expect_true(($conv2['assigned'] ?? null) === false && $one("SELECT assignee_user_id a FROM project_tasks WHERE id = " . (int)$conv2['task_id'])['a'] === null, 'assignee who is not a project member leaves the task unassigned');
    expect_true(($wm->actionToTask($e, $u['c'], false, $mid, (int)$a2['id'])['statusCode'] ?? 0) === 403, 'only the creator converts decisions');
    $conn->query("UPDATE project_tasks SET status = 'done' WHERE id = " . (int)$conv['task_id']);
    $detail = $wm->detail($e, $u['b'], false, $mid);
    $act = array_values(array_filter($detail['actions'], fn($x) => (int)$x['id'] === (int)$a1['id']))[0] ?? null;
    expect_true($act && $act['is_done'] === true, 'decision counts done when its task is done');
    expect_true(!in_array((int)$a1['id'], array_column($wm->listFor($e, $u['b'], false, 'actions'), 'id')), 'done decision leaves «مصوبه‌های من»');
    $a3 = $wm->addAction($e, $u['a'], false, $mid, ['text' => 'ارسال صورت‌جلسه', 'assignee_user_id' => $u['b']]);
    expect_true(in_array((int)$a3['id'], array_column($wm->listFor($e, $u['b'], false, 'actions'), 'id')), 'open decision appears in «مصوبه‌های من»');
    expect_true(!empty($wm->setActionDone($e, $u['b'], false, $mid, (int)$a3['id'], true)['success']), 'assignee ticks own decision');
    expect_true(($wm->setActionDone($e, $u['c'], false, $mid, (int)$a3['id'], false)['statusCode'] ?? 0) === 403, 'another attendee cannot tick it');

    $m2 = $wm->create($e, $u['b'], false, ['title' => "{$token} بی‌پروژه", 'date' => $tomorrow, 'time' => '09:00', 'attendee_ids' => []]);
    $mids[] = (int)$m2['id'];
    $x = $wm->addAction($e, $u['b'], false, (int)$m2['id'], ['text' => 'کاری']);
    expect_true(!empty($wm->actionToTask($e, $u['b'], false, (int)$m2['id'], (int)$x['id'])['error']), 'meeting without a project cannot convert');

    // Files + delete
    $f = $wm->addFiles($e, $u['b'], false, $mid, [['name' => 'board.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => 0]], false);
    $path = WorkFiles::path(WorkFiles::get($conn, $e, (int)$f['ids'][0]));
    expect_true(!empty($f['success']) && is_file($path), 'attendee adds a file');
    $wm->delete($e, $u['a'], false, $mid);
    expect_true($one("SELECT id FROM meetings WHERE id = {$mid}") === null && !is_file($path) && (int)$one("SELECT COUNT(*) n FROM meeting_actions WHERE meeting_id = {$mid}")['n'] === 0, 'delete removes meeting, decisions and files');
    expect_true($one("SELECT id FROM project_tasks WHERE id = " . (int)$conv['task_id']) !== null, 'tasks made from decisions stay in the project');
} finally {
    foreach ($mids as $mid) {
        WorkFiles::deleteRows($conn, WorkFiles::listFor($conn, $e, 'meeting', $mid));
        $conn->query("DELETE FROM meeting_actions WHERE meeting_id = {$mid}");
        $conn->query("DELETE FROM meeting_attendees WHERE meeting_id = {$mid}");
        $conn->query("DELETE FROM meetings WHERE id = {$mid}");
    }
    if ($pid) {
        $conn->query("DELETE FROM project_tasks WHERE project_id = {$pid}");
        $conn->query("DELETE FROM project_members WHERE project_id = {$pid}");
        $conn->query("DELETE FROM projects WHERE id = {$pid}");
    }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
    @unlink($png);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
