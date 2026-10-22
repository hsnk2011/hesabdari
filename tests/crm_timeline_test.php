<?php
/**
 * CRM customer timeline, net receipts and the debtors list.
 * Run: php tests/crm_timeline_test.php
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
require_once $root . '/api/models/CrmTimeline.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => null];
$token = 'crmz' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$userId = 0; $customerId = 0; $invoiceId = 0;

try {
    $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}', 'x')");
    $userId = (int)$conn->insert_id;
    (new TeamMember($conn))->save($entityId, ['full_name' => "{$token} الف", 'kind' => 'partner', 'user_id' => $userId]);
    $conn->query("INSERT INTO customers (name, nationalId, phone, entity_id, owner_user_id) VALUES ('{$token}-cus', '', '09121234567', {$entityId}, {$userId})");
    $customerId = (int)$conn->insert_id;

    $conn->query("INSERT INTO sales_invoices (customerId, date, totalAmount, entity_id, invoice_number) VALUES ({$customerId}, '2025-03-01', 10000000, {$entityId}, '{$token}')");
    $invoiceId = (int)$conn->insert_id;
    $conn->query("INSERT INTO payments (invoiceId, invoiceType, transaction_type, type, amount, date, entity_id) VALUES ({$invoiceId}, 'sales', 'receipt', 'cash', 3000000, '2025-03-05', {$entityId})");
    // a standalone receipt that was reversed: nets to zero
    $conn->query("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, entity_id, description) VALUES ({$customerId}, 'customer', 'receipt', 'cash', 1000, '2025-03-06', {$entityId}, '{$token}')");
    $rev = (int)$conn->insert_id;
    $conn->query("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, entity_id, reversal_of_payment_id, description) VALUES ({$customerId}, 'customer', 'payment', 'cash', 1000, '2025-03-07', {$entityId}, {$rev}, '{$token}')");
    $conn->query("INSERT INTO sms_logs (entity_id, receptor, message, invoice_type, invoice_id, source, sent_at) VALUES ({$entityId}, '09121234567', '{$token} پیامک فاکتور', 'sales', {$invoiceId}, 'invoice', '2025-03-02 10:00:00')");
    $act = (new CrmActivity($conn))->create($entityId, $userId, ['person_type' => 'customer', 'person_id' => $customerId, 'kind' => 'call_out', 'outcome' => 'promised', 'body' => "{$token} تماس", 'promise_amount' => 2000000, 'promise_date' => '2025-04-01']);
    $task = (new CrmTask($conn))->create($entityId, $userId, ['title' => "{$token} کار", 'due_at' => '2025-03-10 09:00', 'assignee_user_id' => $userId, 'person_type' => 'customer', 'person_id' => $customerId]);

    expect_true(abs(CrmTimeline::customerReceipts($conn, $entityId, $customerId, '2025-03-01', '2025-03-31') - 3000000) < 0.01, 'net receipts: invoice receipt counted, reversed standalone receipt nets to zero');
    expect_true(abs(CrmTimeline::customerReceipts($conn, $entityId, $customerId, '2025-03-06', '2025-03-31')) < 0.01, 'receipts outside the date range are ignored');

    $tl = new CrmTimeline($conn);
    $page = $tl->forCustomer($entityId, $customerId, 1, 50);
    $types = array_column($page['items'], 'type');
    foreach (['sales_invoice', 'payment', 'sms', 'activity'] as $t) {
        expect_true(in_array($t, $types, true), "timeline contains {$t}");
    }
    $dates = array_column($page['items'], 'date');
    $sorted = $dates; rsort($sorted);
    expect_true($dates === $sorted, 'timeline is newest first');
    $h = $page['header'];
    expect_true($h['name'] === "{$token}-cus" && (int)$h['owner_user_id'] === $userId && $h['owner_name'] === "{$token} الف", 'header has name and owner');
    expect_true($h['net_balance'] > 0, 'header shows the customer owes us');
    expect_true($h['open_promise'] && abs((float)$h['open_promise']['promise_amount'] - 2000000) < 0.01, 'header shows the open promise');
    expect_true(count($h['open_tasks']) === 1 && $h['open_tasks'][0]['title'] === "{$token} کار", 'header lists open tasks');
    $small = $tl->forCustomer($entityId, $customerId, 1, 2);
    expect_true(count($small['items']) === 2 && $small['has_more'] === true, 'paging: limit 2 returns 2 items and has_more');
    $p2 = $tl->forCustomer($entityId, $customerId, 2, 2);
    expect_true(count($p2['items']) >= 1 && $p2['items'][0] !== $small['items'][0], 'second page continues the list');
    expect_true(($tl->forCustomer($entityId, 999999999, 1, 10)['error'] ?? '') !== '', 'unknown customer gives an error');

    $debtors = $tl->debtors($entityId);
    $row = array_values(array_filter($debtors, fn($d) => (int)$d['id'] === $customerId))[0] ?? null;
    expect_true($row !== null, 'customer with a positive balance is in debtors');
    expect_true($row && abs((float)$row['promise_amount'] - 2000000) < 0.01 && $row['last_activity_user'] === "{$token} الف", 'debtor row has the promise and who called last');
} finally {
    if ($customerId) {
        $conn->query("DELETE FROM notifications WHERE reference_type = 'crm_task' AND reference_id IN (SELECT id FROM crm_tasks WHERE person_type = 'customer' AND person_id = {$customerId})");
        $conn->query("DELETE FROM crm_tasks WHERE person_type = 'customer' AND person_id = {$customerId}");
        $conn->query("DELETE FROM crm_activities WHERE person_type = 'customer' AND person_id = {$customerId}");
        $conn->query("DELETE FROM payments WHERE person_type = 'customer' AND person_id = {$customerId}");
    }
    if ($invoiceId) {
        $conn->query("DELETE FROM payments WHERE invoiceType = 'sales' AND invoiceId = {$invoiceId}");
        $conn->query("DELETE FROM sms_logs WHERE invoice_type = 'sales' AND invoice_id = {$invoiceId}");
        $conn->query("DELETE FROM sales_invoices WHERE id = {$invoiceId}");
    }
    if ($customerId) { $conn->query("DELETE FROM customers WHERE id = {$customerId}"); }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    if ($userId) { $conn->query("DELETE FROM users WHERE id = {$userId}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
