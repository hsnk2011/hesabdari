<?php
/**
 * Cartable: payment requests (approve / reject / pay → one real expense,
 * advance → staff record) and letters (refer / close), visibility, numbers.
 * Run: php tests/work_requests_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkProject.php';
require_once $root . '/api/models/WorkRequest.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'office-test'];
$token = 'wq' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $rids = []; $pid = 0; $accountId = 0; $personnelId = 0;
$plain = ['approve' => false, 'pay' => false, 'all' => false];
$approver = ['approve' => true, 'pay' => false, 'all' => false];
$payer = ['approve' => false, 'pay' => true, 'all' => false];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $wr = new WorkRequest($conn);
    $pid = (int)(new WorkProject($conn))->create($e, $u['b'], true, ['title' => "{$token} پروژه"])['id'];
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$e}, 0, 0)");
    $accountId = (int)$conn->insert_id;
    $conn->query("INSERT INTO hr_personnel (entity_id, full_name, user_id) VALUES ({$e}, '{$token} پرسنل', {$u['a']})");
    $personnelId = (int)$conn->insert_id;

    // Create
    $r1 = $wr->create($e, $u['a'], ['kind' => 'payment', 'title' => 'خرید لامپ', 'category' => 'PURCHASE', 'amount' => '1,200,000', 'payee' => 'فروشگاه نور', 'project_id' => $pid]);
    expect_true(!empty($r1['success']), 'payment request created' . (!empty($r1['error']) ? ' — ' . $r1['error'] : ''));
    $id1 = (int)$r1['id']; $rids[] = $id1;
    $r2 = $wr->create($e, $u['a'], ['kind' => 'payment', 'title' => 'مساعده', 'category' => 'ADVANCE', 'amount' => 500000, 'personnel_id' => $personnelId]);
    $id2 = (int)$r2['id']; $rids[] = $id2;
    $n1 = (int)$one("SELECT number FROM work_requests WHERE id = {$id1}")['number'];
    expect_true((int)$one("SELECT number FROM work_requests WHERE id = {$id2}")['number'] === $n1 + 1, 'numbers are sequential');
    expect_true(!empty($wr->create($e, $u['a'], ['kind' => 'payment', 'title' => 'x', 'category' => 'OTHER', 'amount' => 0])['error']), 'payment needs an amount');
    expect_true(!empty($wr->create($e, $u['a'], ['kind' => 'letter', 'title' => 'x'])['error']), 'letter needs a holder');

    // Visibility
    expect_true(($wr->detail($e, $u['c'], $plain, $id1)['statusCode'] ?? 0) === 404, 'an unrelated user cannot open it');
    expect_true(!empty($wr->detail($e, $u['c'], $approver, $id1)['success']), 'an approver sees every payment');
    expect_true(($wr->detail($e, $u['c'], $payer, $id1)['statusCode'] ?? 0) === 404, 'a payer does not see pending payments');
    expect_true(in_array($id1, array_column($wr->listFor($e, $u['c'], $approver, 'mine_action', null), 'id')), 'pending payment waits for the approver');
    expect_true(WorkRequest::allowedActions(['kind' => 'payment', 'status' => 'pending', 'created_by' => $u['a'], 'assignee_user_id' => null], $u['a'], $plain) === ['cancel', 'comment'], 'creator of a pending payment: cancel, comment');

    // Flow
    expect_true(($wr->act($e, $u['a'], $plain, $id1, 'approve', [])['statusCode'] ?? 0) === 403, 'creator cannot approve');
    expect_true(!empty($wr->act($e, $u['c'], $approver, $id1, 'reject', [])['error']), 'reject needs a reason');
    expect_true(!empty($wr->act($e, $u['c'], $approver, $id1, 'approve', ['note' => 'اوکی'])['success']) && $one("SELECT status FROM work_requests WHERE id = {$id1}")['status'] === 'approved', 'approved');
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['a']} AND link LIKE '%#work/request/{$id1}'")['n'] >= 1, 'creator notified');
    expect_true(!empty($wr->detail($e, $u['c'], $payer, $id1)['success']), 'a payer sees the approved payment');

    // Pay fails → stays approved
    $bad = $wr->act($e, $u['c'], $payer, $id1, 'pay', ['account_id' => 999999999, 'expense_category' => 'متفرقه', 'date' => date('Y-m-d')]);
    $row = $one("SELECT status, expense_id FROM work_requests WHERE id = {$id1}");
    expect_true(!empty($bad['error']) && $row['status'] === 'approved' && $row['expense_id'] === null, 'failed booking leaves the request approved');

    // Pay → one expense
    $before = (int)$one("SELECT COUNT(*) n FROM expenses WHERE description LIKE '%خرید لامپ%' AND entity_id = {$e}")['n'];
    $pay = $wr->act($e, $u['c'], $payer, $id1, 'pay', ['account_id' => $accountId, 'expense_category' => 'متفرقه', 'date' => date('Y-m-d')]);
    $row = $one("SELECT status, expense_id FROM work_requests WHERE id = {$id1}");
    $exp = $row['expense_id'] ? $one("SELECT * FROM expenses WHERE id = {$row['expense_id']}") : null;
    expect_true(!empty($pay['success']) && $row['status'] === 'paid' && $exp && abs((float)$exp['amount'] - 1200000) < 0.01 && (int)$exp['project_id'] === $pid, 'pay books one expense with amount and project' . (!empty($pay['error']) ? ' — ' . $pay['error'] : ''));
    expect_true(!empty($wr->act($e, $u['c'], $payer, $id1, 'pay', ['account_id' => $accountId, 'expense_category' => 'متفرقه', 'date' => date('Y-m-d')])['error']), 'second pay refused');
    expect_true((int)$one("SELECT COUNT(*) n FROM expenses WHERE description LIKE '%خرید لامپ%' AND entity_id = {$e}")['n'] === $before + 1, 'exactly one expense exists');

    // Advance → staff record
    $wr->act($e, $u['c'], $approver, $id2, 'approve', []);
    $wr->act($e, $u['c'], $payer, $id2, 'pay', ['account_id' => $accountId, 'expense_category' => 'متفرقه', 'date' => date('Y-m-d')]);
    $rec = $one("SELECT * FROM hr_records WHERE request_id = {$id2}");
    expect_true($rec && $rec['kind'] === 'advance' && abs((float)$rec['amount'] - 500000) < 0.01 && (int)$rec['personnel_id'] === $personnelId, 'paid advance lands in the staff file');

    // Cancel / reopen
    $r3 = $wr->create($e, $u['a'], ['kind' => 'payment', 'title' => 'کرایه', 'category' => 'TRANSPORT', 'amount' => 90000]);
    $id3 = (int)$r3['id']; $rids[] = $id3;
    $wr->act($e, $u['a'], $plain, $id3, 'cancel', []);
    expect_true($one("SELECT status FROM work_requests WHERE id = {$id3}")['status'] === 'cancelled', 'creator cancels');
    $wr->act($e, $u['a'], $plain, $id3, 'reopen', []);
    expect_true($one("SELECT status FROM work_requests WHERE id = {$id3}")['status'] === 'pending', 'creator resubmits');

    // Letter
    $l = $wr->create($e, $u['a'], ['kind' => 'letter', 'title' => 'پیگیری بیمه', 'body' => 'مدارک بیمه', 'assignee_user_id' => $u['b']]);
    $lid = (int)$l['id']; $rids[] = $lid;
    expect_true((int)$one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$u['b']} AND link LIKE '%#work/request/{$lid}'")['n'] === 1, 'holder notified');
    expect_true(in_array($lid, array_column($wr->listFor($e, $u['b'], $plain, 'mine_action', null), 'id')), 'letter waits for its holder');
    expect_true(($wr->act($e, $u['c'], $plain, $lid, 'refer', ['to_user_id' => $u['a'], 'note' => 'x'])['statusCode'] ?? 0) === 404, 'outsider cannot act on the letter');
    $wr->act($e, $u['b'], $plain, $lid, 'refer', ['to_user_id' => $u['c'], 'note' => 'لطفاً پیگیری کن']);
    expect_true((int)$one("SELECT assignee_user_id a FROM work_requests WHERE id = {$lid}")['a'] === $u['c'], 'referral changes the holder');
    expect_true(!empty($wr->detail($e, $u['b'], $plain, $lid)['success']), 'a previous holder still sees it');
    expect_true(!empty($wr->act($e, $u['c'], $plain, $lid, 'done', [])['error']), 'closing needs a result');
    $wr->act($e, $u['c'], $plain, $lid, 'done', ['note' => 'بیمه تمدید شد']);
    $lrow = $one("SELECT status, result FROM work_requests WHERE id = {$lid}");
    expect_true($lrow['status'] === 'done' && $lrow['result'] === 'بیمه تمدید شد', 'closed with result');
    $events = $wr->detail($e, $u['a'], $plain, $lid)['events'];
    expect_true(count($events) === 3 && $events[1]['action'] === 'refer', 'history: create, refer, done');
} finally {
    foreach ($rids as $id) {
        $ex = $one("SELECT expense_id FROM work_requests WHERE id = {$id}");
        if (!empty($ex['expense_id'])) { $conn->query("DELETE FROM expenses WHERE id = " . (int)$ex['expense_id']); }
        $conn->query("DELETE FROM work_request_events WHERE request_id = {$id}");
        $conn->query("DELETE FROM hr_records WHERE request_id = {$id}");
        $conn->query("DELETE FROM work_requests WHERE id = {$id}");
    }
    if ($personnelId) { $conn->query("DELETE FROM hr_personnel WHERE id = {$personnelId}"); }
    if ($accountId) { $conn->query("DELETE FROM accounts WHERE id = {$accountId}"); }
    if ($pid) { $conn->query("DELETE FROM project_members WHERE project_id = {$pid}"); $conn->query("DELETE FROM projects WHERE id = {$pid}"); }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
