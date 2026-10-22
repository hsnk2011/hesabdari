<?php
/**
 * CRM data follows the accounting: merging two customers moves their CRM
 * history, and finishing a task clears its reminders from the bell.
 * Run: php tests/crm_merge_test.php
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
require_once $root . '/api/models/PersonLink.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => null];
$token = 'crmm' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$userId = 0; $src = 0; $dst = 0;

try {
    $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}', 'x')");
    $userId = (int)$conn->insert_id;
    (new TeamMember($conn))->save($entityId, ['full_name' => "{$token} الف", 'kind' => 'partner', 'user_id' => $userId]);
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-src', '', {$entityId})");
    $src = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-dst', '', {$entityId})");
    $dst = (int)$conn->insert_id;

    $act = (new CrmActivity($conn))->create($entityId, $userId, ['person_type' => 'customer', 'person_id' => $src, 'kind' => 'call_out', 'outcome' => 'talked', 'body' => "{$token} تماس"]);
    $tasks = new CrmTask($conn);
    $task = $tasks->create($entityId, $userId, ['title' => "{$token} کار", 'due_at' => date('Y-m-d') . ' 09:00', 'assignee_user_id' => $userId, 'person_type' => 'customer', 'person_id' => $src]);

    $res = (new PersonLink($conn))->merge('customer', $src, 'customer', $dst);
    expect_true(empty($res['error']), 'customers merged' . (!empty($res['error']) ? ' — ' . $res['error'] : ''));
    expect_true((int)$one("SELECT person_id p FROM crm_activities WHERE id = " . (int)$act['id'])['p'] === $dst, 'activity moved to the surviving customer');
    expect_true((int)$one("SELECT person_id p FROM crm_tasks WHERE id = " . (int)$task['id'])['p'] === $dst, 'task moved to the surviving customer');

    // Bell: reminders of a finished task disappear
    $conn->query("INSERT INTO notifications (entity_id, user_id, type, title, reference_type, reference_id) VALUES ({$entityId}, {$userId}, 'crm_task_due', '{$token} یادآوری', 'crm_task', " . (int)$task['id'] . ")");
    $tasks->setStatus($entityId, (int)$task['id'], 'done', $userId);
    expect_true((int)$one("SELECT is_dismissed d FROM notifications WHERE title = '{$token} یادآوری'")['d'] === 1, "finishing a task dismisses its reminder");
} finally {
    $conn->query("DELETE FROM notifications WHERE title LIKE '{$token}%' OR (reference_type = 'crm_task' AND reference_id IN (SELECT id FROM crm_tasks WHERE title LIKE '{$token}%'))");
    $conn->query("DELETE FROM crm_tasks WHERE title LIKE '{$token}%'");
    $conn->query("DELETE FROM crm_activities WHERE body LIKE '{$token}%'");
    foreach ([$src, $dst] as $id) { if ($id) { $conn->query("DELETE FROM customers WHERE id = {$id}"); } }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    if ($userId) { $conn->query("DELETE FROM users WHERE id = {$userId}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
