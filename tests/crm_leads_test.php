<?php
/**
 * CRM leads: stages, lost reason, conversion to a customer (history moves).
 * Run: php tests/crm_leads_test.php
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
require_once $root . '/api/models/CrmLead.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } // log_activity only writes inside a session; returned ids must not be the log row's
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => 1, 'username' => 'crm-test'];
$token = 'crml' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$userId = 0; $customerIds = [];

try {
    $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}', 'x')");
    $userId = (int)$conn->insert_id;
    (new TeamMember($conn))->save($entityId, ['full_name' => "{$token} الف", 'kind' => 'partner', 'user_id' => $userId]);
    $leads = new CrmLead($conn);

    $r = $leads->save($entityId, ['name' => "{$token} سرنخ", 'phone' => '09121112233', 'source' => 'اینستاگرام', 'interest' => 'فرش ۹ متری', 'owner_user_id' => $userId]);
    expect_true(!empty($r['success']), 'lead saved' . (!empty($r['error']) ? ' — ' . $r['error'] : ''));
    $leadId = (int)($r['id'] ?? 0);
    expect_true(($one("SELECT name FROM crm_leads WHERE id = {$leadId}")['name'] ?? '') === "{$token} سرنخ", 'save returns the id of the lead row');
    expect_true($one("SELECT stage FROM crm_leads WHERE id = {$leadId}")['stage'] === 'new', 'new lead starts in stage new');
    expect_true(!empty($leads->save($entityId, ['name' => ' '])['error']), 'lead name is required');

    $act = (new CrmActivity($conn))->create($entityId, $userId, [
        'person_type' => 'lead', 'person_id' => $leadId, 'kind' => 'call_out', 'outcome' => 'interested', 'body' => "{$token} تماس اول",
        'next_task' => ['title' => "{$token} ارسال پیش‌فاکتور", 'due_at' => date('Y-m-d', strtotime('+1 day')) . ' 10:00', 'assignee_user_id' => $userId],
    ]);
    expect_true(!empty($act['success']) && $one("SELECT stage FROM crm_leads WHERE id = {$leadId}")['stage'] === 'contacted', 'first call moves the lead to contacted');
    expect_true($one("SELECT kind FROM crm_tasks WHERE title = '{$token} ارسال پیش‌فاکتور'")['kind'] === 'sales', 'follow-up on a lead is a sales task');

    $board = $leads->listByStage($entityId);
    $card = array_values(array_filter($board['contacted'], fn($l) => (int)$l['id'] === $leadId))[0] ?? null;
    expect_true($card && $card['last_activity_outcome'] === 'interested' && $card['owner_name'] === "{$token} الف", 'board card shows last activity and owner');

    expect_true(!empty($leads->setStage($entityId, $leadId, 'lost')['error']), 'lost needs a reason');
    expect_true(!empty($leads->setStage($entityId, $leadId, 'weird')['error']), 'unknown stage refused');
    expect_true(!empty($leads->setStage($entityId, $leadId, 'quoted')['success']) && $one("SELECT stage FROM crm_leads WHERE id = {$leadId}")['stage'] === 'quoted', 'stage can move to quoted');

    // Failing conversion (duplicate customer name) leaves the lead untouched
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token} تکراری', '', {$entityId})");
    $customerIds[] = (int)$conn->insert_id;
    $bad = $leads->convert($entityId, $leadId, ['name' => "{$token} تکراری", 'phone' => '09121112233']);
    $after = $one("SELECT stage, converted_customer_id FROM crm_leads WHERE id = {$leadId}");
    expect_true(!empty($bad['error']) && $after['stage'] === 'quoted' && $after['converted_customer_id'] === null, 'failed conversion leaves the lead as it was');

    // Successful conversion
    $ok = $leads->convert($entityId, $leadId, ['name' => "{$token} مشتری", 'phone' => '09121112233']);
    expect_true(!empty($ok['success']) && $ok['customer_id'] > 0, 'conversion creates a customer' . (!empty($ok['error']) ? ' — ' . $ok['error'] : ''));
    $cid = (int)($ok['customer_id'] ?? 0);
    $customerIds[] = $cid;
    $after = $one("SELECT stage, converted_customer_id FROM crm_leads WHERE id = {$leadId}");
    expect_true($after['stage'] === 'won' && (int)$after['converted_customer_id'] === $cid, 'lead is won and points at the customer');
    expect_true((int)$one("SELECT COUNT(*) n FROM crm_activities WHERE person_type = 'customer' AND person_id = {$cid}")['n'] === 1, 'activity moved to the customer');
    expect_true((int)$one("SELECT COUNT(*) n FROM crm_tasks WHERE person_type = 'customer' AND person_id = {$cid}")['n'] === 1, 'task moved to the customer');
    expect_true((int)$one("SELECT owner_user_id o FROM customers WHERE id = {$cid}")['o'] === $userId, 'customer inherits the lead owner');
    expect_true(!empty($leads->convert($entityId, $leadId, ['name' => "{$token} دوباره"])['error']), 'a converted lead cannot be converted again');
} finally {
    $conn->query("DELETE FROM notifications WHERE reference_type = 'crm_task' AND reference_id IN (SELECT id FROM crm_tasks WHERE title LIKE '{$token}%')");
    $conn->query("DELETE FROM crm_tasks WHERE title LIKE '{$token}%'");
    $conn->query("DELETE FROM crm_activities WHERE body LIKE '{$token}%'");
    $conn->query("DELETE FROM crm_leads WHERE name LIKE '{$token}%'");
    foreach ($customerIds as $id) { if ($id) { $conn->query("DELETE FROM customers WHERE id = {$id}"); } }
    $conn->query("DELETE FROM customers WHERE name LIKE '{$token}%'");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    if ($userId) { $conn->query("DELETE FROM users WHERE id = {$userId}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
