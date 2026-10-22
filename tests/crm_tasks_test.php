<?php
/**
 * CRM activities, payment promises and tasks.
 * Run: php tests/crm_tasks_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/CrmActivity.php';
require_once $root . '/api/models/CrmTask.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } // log_activity only writes inside a session; returned ids must not be the log row's
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => 1, 'username' => 'crm-test'];
$token = 'crmk' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$userIds = []; $customerId = 0; $otherEntityCustomer = 0; $otherEntity = 0;

try {
    foreach (['a', 'b'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $userIds[$s] = (int)$conn->insert_id;
    }
    $team = new TeamMember($conn);
    $team->save($entityId, ['full_name' => "{$token} الف", 'kind' => 'partner', 'user_id' => $userIds['a']]);
    $team->save($entityId, ['full_name' => "{$token} ب", 'kind' => 'employee', 'user_id' => $userIds['b']]);
    $noLogin = $team->save($entityId, ['full_name' => "{$token} بی‌کاربر", 'kind' => 'employee']);
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cus', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $otherEntity = (int)($one("SELECT id FROM business_entities WHERE id <> {$entityId} ORDER BY id LIMIT 1")['id'] ?? 0);
    if ($otherEntity) {
        $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-other', '{$token}o', {$otherEntity})");
        $otherEntityCustomer = (int)$conn->insert_id;
    }

    $acts = new CrmActivity($conn);
    $tasks = new CrmTask($conn);
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // 1) Call with a payment promise and a follow-up for user B
    $r = $acts->create($entityId, $userIds['a'], [
        'person_type' => 'customer', 'person_id' => $customerId, 'kind' => 'call_out', 'outcome' => 'promised',
        'body' => "{$token} قول داد", 'promise_amount' => '5,000,000', 'promise_date' => $tomorrow,
        'next_task' => ['title' => "{$token} پیگیری وصول", 'due_at' => $tomorrow . ' 10:00:00', 'assignee_user_id' => $userIds['b']],
    ]);
    expect_true(!empty($r['success']), 'activity with promise saved' . (!empty($r['error']) ? ' — ' . $r['error'] : ''));
    $act = $one("SELECT * FROM crm_activities WHERE id = " . (int)($r['id'] ?? 0));
    expect_true($act && $act['promise_status'] === 'open' && abs((float)$act['promise_amount'] - 5000000) < 0.01 && $act['promise_date'] === $tomorrow, 'promise stored as open, 5,000,000, due tomorrow');
    $t = $one("SELECT * FROM crm_tasks WHERE title = '{$token} پیگیری وصول'");
    expect_true($t && (int)$t['assignee_user_id'] === $userIds['b'] && $t['kind'] === 'collection' && $t['status'] === 'open' && $t['person_type'] === 'customer' && (int)$t['person_id'] === $customerId, 'follow-up task created for B as a collection task on the customer');
    $assignNote = $one("SELECT user_id FROM notifications WHERE reference_type = 'crm_task' AND reference_id = " . (int)($t['id'] ?? 0));
    expect_true($assignNote && (int)$assignNote['user_id'] === $userIds['b'], 'B is notified about the task A gave them');

    $list = $acts->listForPerson($entityId, 'customer', $customerId);
    expect_true(count($list) === 1 && $list[0]['user_name'] === "{$token} الف", 'activity list shows who logged it');

    // 2) Validation
    $bad = $tasks->create($entityId, $userIds['a'], ['title' => "{$token} x", 'due_at' => $tomorrow . ' 09:00', 'assignee_user_id' => 999999999]);
    expect_true(($bad['error'] ?? '') === 'این عضو تیم کاربر سیستم ندارد و نمی‌توان کار به او سپرد.', 'task for a non-team / no-login member is refused with the exact message');
    $noTitle = $tasks->create($entityId, $userIds['a'], ['title' => '', 'due_at' => $tomorrow . ' 09:00', 'assignee_user_id' => $userIds['a']]);
    expect_true(!empty($noTitle['error']), 'task title is required');
    if ($otherEntityCustomer) {
        $x = $acts->create($entityId, $userIds['a'], ['person_type' => 'customer', 'person_id' => $otherEntityCustomer, 'kind' => 'note', 'body' => 'x']);
        expect_true(!empty($x['error']), "activity on another business's customer is refused");
    }
    $badPromise = $acts->create($entityId, $userIds['a'], ['person_type' => 'customer', 'person_id' => $customerId, 'kind' => 'call_out', 'promise_amount' => 1000]);
    expect_true(!empty($badPromise['error']), 'promise amount without a date is refused');

    // 3) Buckets for user A
    $mk = fn($title, $due) => $tasks->create($entityId, $userIds['a'], ['title' => "{$token} {$title}", 'due_at' => $due, 'assignee_user_id' => $userIds['a']]);
    $mk('عقب', date('Y-m-d', strtotime('-2 day')) . ' 09:00:00');
    $mk('امروز', date('Y-m-d') . ' 23:00:00');
    $future = $mk('بعدا', date('Y-m-d', strtotime('+3 day')) . ' 09:00:00');
    $mine = $tasks->listForUser($entityId, $userIds['a']);
    $titles = fn($bucket) => array_column($mine[$bucket], 'title');
    expect_true(in_array("{$token} عقب", $titles('overdue'), true), 'overdue task in overdue bucket');
    expect_true(in_array("{$token} امروز", $titles('today'), true), "today's task in today bucket");
    expect_true(in_array("{$token} بعدا", $titles('upcoming'), true), 'future task in upcoming bucket');
    expect_true(!in_array("{$token} پیگیری وصول", array_merge($titles('overdue'), $titles('today'), $titles('upcoming')), true), "B's task is not in A's list");

    // 4) Done / reassign
    $done = $tasks->setStatus($entityId, (int)$future['id'], 'done', $userIds['a']);
    $row = $one("SELECT status, done_at, done_by FROM crm_tasks WHERE id = " . (int)$future['id']);
    expect_true(!empty($done['success']) && $row['status'] === 'done' && $row['done_at'] !== null && (int)$row['done_by'] === $userIds['a'], 'done sets status, done_at and done_by');
    $badStatus = $tasks->setStatus($entityId, (int)$future['id'], 'weird', $userIds['a']);
    expect_true(!empty($badStatus['error']), 'unknown status is refused');
    $upd = $tasks->update($entityId, (int)$t['id'], ['title' => "{$token} پیگیری وصول", 'due_at' => $tomorrow . ' 11:00', 'assignee_user_id' => $userIds['a']], $userIds['b']);
    expect_true(!empty($upd['success']) && (int)$one("SELECT assignee_user_id a FROM crm_tasks WHERE id = " . (int)$t['id'])['a'] === $userIds['a'], 'task can be reassigned');
    $team_rows = $tasks->listTeam($entityId, ['status' => 'open']);
    $teamRow = array_values(array_filter($team_rows, fn($x) => (int)$x['id'] === (int)$t['id']))[0] ?? null;
    expect_true($teamRow && $teamRow['person_name'] === "{$token}-cus" && $teamRow['assignee_name'] === "{$token} الف", 'team list carries person and assignee names');
} finally {
    $conn->query("DELETE FROM notifications WHERE reference_type = 'crm_task' AND reference_id IN (SELECT id FROM crm_tasks WHERE title LIKE '{$token}%')");
    $conn->query("DELETE FROM crm_tasks WHERE title LIKE '{$token}%'");
    if ($customerId) { $conn->query("DELETE FROM crm_activities WHERE person_type = 'customer' AND person_id = {$customerId}"); $conn->query("DELETE FROM customers WHERE id = {$customerId}"); }
    if ($otherEntityCustomer) { $conn->query("DELETE FROM customers WHERE id = {$otherEntityCustomer}"); }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($userIds as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
