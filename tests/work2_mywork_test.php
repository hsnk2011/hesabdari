<?php
/**
 * «کارهای من» with the cartable: requests waiting for my approval, payment
 * or (letters) my action; digest mentions them. Run: php tests/work2_mywork_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkRequest.php';
require_once $root . '/api/models/MyWork.php';
require_once $root . '/api/models/CrmAutomation.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'mywork2-test'];
$token = 'mw' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $rids = []; $roleIds = [];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    // B approves, C pays (roles with exactly those permissions)
    foreach (['b' => ['approve_payments'], 'c' => ['pay_payments', 'manage_transactions']] as $s => $keys) {
        $conn->query("INSERT INTO roles (role_name, description, is_system) VALUES ('{$token}{$s}', 't', 0)");
        $rid = (int)$conn->insert_id; $roleIds[] = $rid;
        foreach ($keys as $k) { $conn->query("INSERT INTO role_permissions (role_id, permission_id) SELECT {$rid}, id FROM permissions WHERE permission_key = '{$k}'"); }
        $conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ({$u[$s]}, {$rid})");
    }
    $wr = new WorkRequest($conn);
    expect_true($wr->userPerms($u['b']) === ['approve' => true, 'pay' => false, 'all' => false], 'userPerms reads the role (approver)');
    expect_true($wr->userPerms($u['c'])['pay'] === true, 'userPerms: payer needs pay_payments and manage_transactions');

    $p = $wr->create($e, $u['a'], ['kind' => 'payment', 'title' => "{$token} خرید", 'category' => 'PURCHASE', 'amount' => 1000]);
    $rids[] = (int)$p['id'];
    $l = $wr->create($e, $u['a'], ['kind' => 'letter', 'title' => "{$token} نامه", 'assignee_user_id' => $u['c']]);
    $rids[] = (int)$l['id'];
    $titles = fn(int $user) => array_column(MyWork::forUser($conn, $e, $user)['cartable'], 'title');
    expect_true(in_array("درخواست پرداخت #{$p['number']}: {$token} خرید", $titles($u['b']), true), 'approver sees the pending payment');
    expect_true(!in_array("درخواست پرداخت #{$p['number']}: {$token} خرید", $titles($u['c']), true), 'payer does not see it yet');
    expect_true(in_array("نامه #{$l['number']}: {$token} نامه", $titles($u['c']), true), 'letter holder sees the letter');
    $wr->act($e, $u['b'], $wr->userPerms($u['b']), (int)$p['id'], 'approve', []);
    expect_true(in_array("درخواست پرداخت #{$p['number']}: {$token} خرید", $titles($u['c']), true), 'after approval the payer sees it');
    expect_true(!in_array("درخواست پرداخت #{$p['number']}: {$token} خرید", $titles($u['b']), true), 'and the approver no longer does');
    $item = array_values(array_filter(MyWork::forUser($conn, $e, $u['c'])['cartable'], fn($x) => (int)$x['id'] === (int)$p['id']))[0] ?? null;
    expect_true($item && strpos($item['link'], "#work/request/{$p['id']}") !== false, 'cartable item links to the request');

    $conn->query("DELETE FROM notifications WHERE user_id = {$u['c']} AND type = 'crm_digest'");
    CrmAutomation::sendDueReminders($conn, date('Y-m-d') . ' 08:30:00');
    $dg = $one("SELECT message FROM notifications WHERE user_id = {$u['c']} AND type = 'crm_digest' ORDER BY id DESC LIMIT 1");
    expect_true($dg && strpos($dg['message'], 'کارتابل') !== false, 'digest mentions the cartable');
} finally {
    foreach ($rids as $id) { $conn->query("DELETE FROM work_request_events WHERE request_id = {$id}"); $conn->query("DELETE FROM work_requests WHERE id = {$id}"); }
    foreach ($roleIds as $rid) { $conn->query("DELETE FROM role_permissions WHERE role_id = {$rid}"); $conn->query("DELETE FROM user_roles WHERE role_id = {$rid}"); $conn->query("DELETE FROM roles WHERE id = {$rid}"); }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
