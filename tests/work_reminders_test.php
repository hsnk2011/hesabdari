<?php
/**
 * میز کار reminders and «کارهای من»: daily due reminders (once per day, after
 * 08:00), 30-minute meeting reminders, the merged My Work list, digest.
 * Run: php tests/work_reminders_test.php
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
require_once $root . '/api/models/CrmTask.php';
require_once $root . '/api/models/WorkReminders.php';
require_once $root . '/api/models/MyWork.php';
require_once $root . '/api/models/CrmAutomation.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wr' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$count = fn(string $sql) => (int)($one($sql)['n'] ?? 0);
$u = []; $pid = 0; $mids = []; $customerId = 0;
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $pid = (int)(new WorkProject($conn))->create($e, $u['a'], true, ['title' => "{$token} پروژه", 'status' => 'active', 'members' => [['user_id' => $u['b']]]])['id'];
    $wt = new WorkTask($conn);
    $tDue = (int)$wt->create($e, $u['a'], false, $pid, ['title' => "{$token} فردا", 'assignee_user_id' => $u['b'], 'due_date' => $tomorrow])['id'];
    $tLate = (int)$wt->create($e, $u['a'], false, $pid, ['title' => "{$token} دیروز", 'assignee_user_id' => $u['b'], 'due_date' => date('Y-m-d', strtotime('-1 day'))])['id'];
    $tReview = (int)$wt->create($e, $u['a'], false, $pid, ['title' => "{$token} بازبینی", 'assignee_user_id' => $u['b'], 'due_date' => $today])['id'];
    $wt->complete($e, $u['b'], false, $tReview, null); // → review (B is a plain member)
    $remind = fn(int $tid) => $count("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['b']} AND title LIKE 'یادآوری کار:%' AND link LIKE '%/task/{$tid}'");

    expect_true(WorkReminders::dailyTasks($conn, "{$today} 07:30:00") === 0 && $remind($tDue) === 0, 'nothing before 08:00');
    WorkReminders::dailyTasks($conn, "{$today} 08:05:00");
    expect_true($remind($tDue) === 1 && $remind($tLate) === 1, 'due-tomorrow and overdue tasks reminded at 08:00');
    expect_true($count("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['b']} AND title = 'یادآوری کار: فردا موعد انجام است'") === 1, 'tomorrow wording');
    expect_true($remind($tReview) === 0, 'a task waiting for review is not reminded');
    WorkReminders::dailyTasks($conn, "{$today} 09:00:00");
    expect_true($remind($tDue) === 1, 'a task is reminded once per day');

    // Meeting reminder 30 minutes before
    $wm = new WorkMeeting($conn);
    $soon = date('Y-m-d H:i', strtotime('+20 minutes'));
    $later = date('Y-m-d H:i', strtotime('+2 hours'));
    $m1 = (int)$wm->create($e, $u['a'], false, ['title' => "{$token} نزدیک", 'date' => substr($soon, 0, 10), 'time' => substr($soon, 11, 5), 'attendee_ids' => [$u['b']]])['id'];
    $m2 = (int)$wm->create($e, $u['a'], false, ['title' => "{$token} دور", 'date' => substr($later, 0, 10), 'time' => substr($later, 11, 5), 'attendee_ids' => [$u['b']]])['id'];
    $mids = [$m1, $m2];
    WorkReminders::meetings($conn);
    WorkReminders::meetings($conn);
    $mr = fn(int $mid, int $user) => $count("SELECT COUNT(*) n FROM notifications WHERE user_id = {$user} AND title LIKE 'جلسه «%' AND link LIKE '%#work/meeting/{$mid}'");
    expect_true($mr($m1, $u['b']) === 1 && $mr($m1, $u['a']) === 1, 'meeting in 20 minutes: attendee and creator reminded once');
    expect_true($mr($m2, $u['b']) === 0, 'meeting in 2 hours: not yet');

    // My Work
    $act = $wm->addAction($e, $u['a'], false, $m1, ['text' => "{$token} مصوبه", 'assignee_user_id' => $u['b'], 'due_date' => $today]);
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-c', '', {$e})");
    $customerId = (int)$conn->insert_id;
    (new CrmTask($conn))->create($e, $u['a'], ['title' => "{$token} پیگیری", 'due_at' => "{$tomorrow} 10:00", 'assignee_user_id' => $u['b'], 'person_type' => 'customer', 'person_id' => $customerId]);
    $my = MyWork::forUser($conn, $e, $u['b']);
    $find = fn(string $bucket, string $title) => array_values(array_filter($my[$bucket], fn($x) => $x['title'] === $title))[0] ?? null;
    expect_true(($find('overdue', "{$token} دیروز")['source'] ?? '') === 'project', 'overdue project task in overdue');
    expect_true(($find('today', "{$token} مصوبه")['source'] ?? '') === 'meeting', "today's decision in today");
    expect_true(($find('upcoming', "{$token} فردا")['source'] ?? '') === 'project' && ($find('upcoming', "{$token} پیگیری")['source'] ?? '') === 'crm', 'tomorrow: project task and CRM follow-up');
    expect_true(strpos($find('upcoming', "{$token} فردا")['link'] ?? '', "#work/project/{$pid}/task/{$tDue}") !== false, 'items carry their link');
    expect_true($find('today', "{$token} بازبینی") === null && $find('overdue', "{$token} بازبینی") === null, "B's task in review is not in B's to-do list");
    $myA = MyWork::forUser($conn, $e, $u['a']);
    expect_true(in_array("{$token} بازبینی", array_column($myA['awaiting_review'], 'title'), true), 'manager A sees it under «منتظر تأیید شما»');
    expect_true(in_array($m1, array_map('intval', array_column($myA['meetings_today'], 'id')), true) || substr($soon, 0, 10) !== $today, "today's meeting listed for its creator");

    // Digest counts project tasks too
    $conn->query("DELETE FROM notifications WHERE user_id = {$u['b']} AND type = 'crm_digest'");
    CrmAutomation::sendDueReminders($conn, "{$today} 08:30:00");
    $dg = $one("SELECT message FROM notifications WHERE user_id = {$u['b']} AND type = 'crm_digest' ORDER BY id DESC LIMIT 1");
    expect_true($dg && strpos($dg['message'], 'عقب‌افتاده') !== false, 'morning digest includes the overdue project task');
} finally {
    foreach ($mids as $mid) {
        $conn->query("DELETE FROM meeting_actions WHERE meeting_id = {$mid}");
        $conn->query("DELETE FROM meeting_attendees WHERE meeting_id = {$mid}");
        $conn->query("DELETE FROM meetings WHERE id = {$mid}");
    }
    if ($pid) {
        $conn->query("DELETE FROM project_tasks WHERE project_id = {$pid}");
        $conn->query("DELETE FROM project_members WHERE project_id = {$pid}");
        $conn->query("DELETE FROM projects WHERE id = {$pid}");
    }
    if ($customerId) { $conn->query("DELETE FROM crm_tasks WHERE person_type = 'customer' AND person_id = {$customerId}"); $conn->query("DELETE FROM customers WHERE id = {$customerId}"); }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
