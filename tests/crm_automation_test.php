<?php
/**
 * CRM automation: broken promises, bounced checks, overdue installments,
 * expiring quotations → tasks (once), due reminders and the morning digest.
 * Run: php tests/crm_automation_test.php
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
require_once $root . '/api/models/CrmAutomation.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => null];
$token = 'crma' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$count = fn(string $sql) => (int)($one($sql)['n'] ?? 0);
$users = []; $cust = []; $checkIds = []; $invoiceIds = []; $quoteId = 0;
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

try {
    foreach (['a', 'b'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $users[$s] = (int)$conn->insert_id;
    }
    // Make sure test users come first among team users is not assumed: B owns the second customer.
    $team = new TeamMember($conn);
    $team->save($entityId, ['full_name' => "{$token} الف", 'kind' => 'partner', 'user_id' => $users['a']]);
    $team->save($entityId, ['full_name' => "{$token} ب", 'kind' => 'employee', 'user_id' => $users['b']]);
    foreach (['p1', 'p2', 'chk', 'ins', 'q'] as $k) {
        $owner = $k === 'chk' || $k === 'ins' || $k === 'q' ? $users['b'] : 'NULL';
        $conn->query("INSERT INTO customers (name, nationalId, entity_id, owner_user_id) VALUES ('{$token}-{$k}', '', {$entityId}, {$owner})");
        $cust[$k] = (int)$conn->insert_id;
    }
    $acts = new CrmActivity($conn);
    // p1: promise 1,000 due yesterday, nothing received → broken
    $a1 = $acts->create($entityId, $users['a'], ['person_type' => 'customer', 'person_id' => $cust['p1'], 'kind' => 'call_out', 'outcome' => 'promised', 'body' => "{$token} p1", 'promise_amount' => 1000, 'promise_date' => $yesterday]);
    // p2: promise 1,000 due yesterday, 1,000 received → kept
    $a2 = $acts->create($entityId, $users['a'], ['person_type' => 'customer', 'person_id' => $cust['p2'], 'kind' => 'call_out', 'outcome' => 'promised', 'body' => "{$token} p2", 'promise_amount' => 1000, 'promise_date' => $yesterday]);
    $conn->query("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, entity_id, description) VALUES ({$cust['p2']}, 'customer', 'receipt', 'cash', 1000, '{$yesterday}', {$entityId}, '{$token}')");
    // chk: a received check that bounced yesterday (owner B)
    $conn->query("INSERT INTO checks (type, status, checkNumber, dueDate, bankName, amount, bounced_date, entity_id) VALUES ('received', 'bounced', '{$token}', '{$yesterday}', 'ملت', 5000, '{$yesterday}', {$entityId})");
    $checkIds[] = (int)$conn->insert_id;
    $conn->query("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, entity_id, checkId, description) VALUES ({$cust['chk']}, 'customer', 'receipt', 'check', 5000, '{$yesterday}', {$entityId}, {$checkIds[0]}, '{$token}')");
    // ins: overdue installment of a sales invoice (owner B)
    $conn->query("INSERT INTO sales_invoices (customerId, date, totalAmount, entity_id, invoice_number) VALUES ({$cust['ins']}, '{$yesterday}', 9000, {$entityId}, '{$token}')");
    $invoiceIds[] = (int)$conn->insert_id;
    $conn->query("INSERT INTO installments (invoice_id, invoice_type, installment_number, amount, due_date, status, entity_id) VALUES ({$invoiceIds[0]}, 'sales', 1, 3000, '{$yesterday}', 'overdue', {$entityId})");
    $insId = (int)$conn->insert_id;
    // q: quotation expiring tomorrow (owner B)
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $conn->query("INSERT INTO quotations (customerId, date, valid_until, totalAmount, status, entity_id, description) VALUES ({$cust['q']}, '{$today}', '{$tomorrow}', 7000, 'pending', {$entityId}, '{$token}')");
    $quoteId = (int)$conn->insert_id;

    $r = CrmAutomation::run($conn, $today);
    expect_true(is_array($r), 'run returns counts');
    $p1 = $one("SELECT promise_status FROM crm_activities WHERE id = " . (int)$a1['id']);
    $p2 = $one("SELECT promise_status FROM crm_activities WHERE id = " . (int)$a2['id']);
    expect_true($p1['promise_status'] === 'broken', 'unpaid promise becomes broken');
    expect_true($p2['promise_status'] === 'kept', 'paid promise becomes kept');
    $t1 = $one("SELECT * FROM crm_tasks WHERE source = 'promise_broken' AND source_ref_id = " . (int)$a1['id']);
    expect_true($t1 && (int)$t1['assignee_user_id'] === $users['a'] && $t1['kind'] === 'collection' && (int)$t1['person_id'] === $cust['p1'], 'broken promise → collection task for whoever took the promise');
    expect_true($count("SELECT COUNT(*) n FROM crm_tasks WHERE source = 'promise_broken' AND source_ref_id = " . (int)$a2['id']) === 0, 'kept promise makes no task');
    $tc = $one("SELECT * FROM crm_tasks WHERE source = 'check_bounced' AND source_ref_id = {$checkIds[0]}");
    expect_true($tc && (int)$tc['assignee_user_id'] === $users['b'] && (int)$tc['person_id'] === $cust['chk'], "bounced check → task for the customer's owner");
    $ti = $one("SELECT * FROM crm_tasks WHERE source = 'installment_overdue' AND source_ref_id = {$insId}");
    expect_true($ti && (int)$ti['assignee_user_id'] === $users['b'], 'overdue installment → task for the owner');
    $tq = $one("SELECT * FROM crm_tasks WHERE source = 'quotation_expiring' AND source_ref_id = {$quoteId}");
    expect_true($tq && $tq['kind'] === 'sales', 'expiring quotation → sales task');

    CrmAutomation::run($conn, $today);
    $mine = "SELECT COUNT(*) n FROM crm_tasks WHERE entity_id = {$entityId} AND person_id IN (" . implode(',', $cust) . ") AND person_type = 'customer'";
    expect_true($count($mine) === 4, 'running again creates no duplicates (4 tasks total)');

    // Reminders
    $conn->query("UPDATE crm_tasks SET reminded_at = NOW() WHERE reminded_at IS NULL AND id NOT IN ({$t1['id']})");
    $sent = CrmAutomation::sendDueReminders($conn, date('Y-m-d H:i:s', strtotime('+1 day')));
    expect_true($sent >= 1 && $one("SELECT reminded_at FROM crm_tasks WHERE id = {$t1['id']}")['reminded_at'] !== null, 'due task is reminded and stamped');
    expect_true($count("SELECT COUNT(*) n FROM notifications WHERE type = 'crm_task_due' AND reference_id = {$t1['id']} AND user_id = {$users['a']}") === 1, 'reminder notification goes to the assignee');
    expect_true(CrmAutomation::sendDueReminders($conn, date('Y-m-d H:i:s', strtotime('+1 day'))) === 0 || $count("SELECT COUNT(*) n FROM notifications WHERE type = 'crm_task_due' AND reference_id = {$t1['id']}") === 1, 'a task is reminded only once');

    // Digest: after 08:00, once per day per user with open tasks
    $morning = $today . ' 08:30:00';
    CrmAutomation::sendDueReminders($conn, $morning);
    CrmAutomation::sendDueReminders($conn, $today . ' 09:30:00');
    expect_true($count("SELECT COUNT(*) n FROM notifications WHERE type = 'crm_digest' AND user_id = {$users['a']} AND DATE(created_at) = CURDATE()") === 1, 'morning digest sent once to A (' . $count("SELECT COUNT(*) n FROM notifications WHERE type = 'crm_digest' AND user_id = {$users['a']}") . ' total, now ' . date('H:i') . ')');
    $early = CrmAutomation::digestDue($today . ' 07:59:00');
    expect_true($early === false && CrmAutomation::digestDue($morning) === true, 'digest waits for 08:00');
} finally {
    $ids = implode(',', array_filter($cust)) ?: '0';
    $conn->query("DELETE FROM notifications WHERE (reference_type = 'crm_task' AND reference_id IN (SELECT id FROM crm_tasks WHERE person_type = 'customer' AND person_id IN ({$ids}))) OR (type = 'crm_digest' AND user_id IN (" . (implode(',', $users) ?: '0') . "))");
    $conn->query("DELETE FROM crm_tasks WHERE person_type = 'customer' AND person_id IN ({$ids})");
    $conn->query("DELETE FROM crm_activities WHERE person_type = 'customer' AND person_id IN ({$ids})");
    $conn->query("DELETE FROM payments WHERE description = '{$token}'");
    foreach ($checkIds as $id) { $conn->query("DELETE FROM checks WHERE id = {$id}"); }
    foreach ($invoiceIds as $id) { $conn->query("DELETE FROM installments WHERE invoice_id = {$id}"); $conn->query("DELETE FROM sales_invoices WHERE id = {$id}"); }
    if ($quoteId) { $conn->query("DELETE FROM quotations WHERE id = {$quoteId}"); }
    $conn->query("DELETE FROM customers WHERE id IN ({$ids})");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($users as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
