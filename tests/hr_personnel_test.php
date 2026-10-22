<?php
/**
 * Staff files: personnel, records (money only for fine/advance/bonus),
 * summary, delete rules, import from the team. Run: php tests/hr_personnel_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/HrPersonnel.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'hr-test'];
$token = 'hr' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $pids = []; $maxBefore = PHP_INT_MAX;

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $hr = new HrPersonnel($conn);
    $r = $hr->save($e, $u['a'], ['full_name' => "{$token} انباردار", 'job_title' => 'انباردار', 'phone' => '0912', 'hired_at' => '2025-03-01']);
    expect_true(!empty($r['success']) && ($one("SELECT full_name FROM hr_personnel WHERE id = " . (int)$r['id'])['full_name'] ?? '') === "{$token} انباردار", 'person saved (id is the row)');
    $pid = (int)$r['id']; $pids[] = $pid;
    expect_true(!empty($hr->save($e, $u['a'], ['full_name' => ' '])['error']), 'name required');
    expect_true(!empty($hr->save($e, $u['a'], ['full_name' => 'x', 'user_id' => 999999999])['error']), 'linked account must exist');

    expect_true(!empty($hr->addRecord($e, $u['a'], $pid, ['kind' => 'warning', 'date' => '2026-10-01', 'note' => ''])['error']), 'note required');
    $w = $hr->addRecord($e, $u['a'], $pid, ['kind' => 'warning', 'date' => '2026-10-01', 'note' => 'تأخیر', 'amount' => 50000]);
    expect_true(!empty($w['success']) && $one("SELECT amount FROM hr_records WHERE id = " . (int)$w['id'])['amount'] === null, 'amount is ignored for non-money kinds');
    $f = $hr->addRecord($e, $u['a'], $pid, ['kind' => 'fine', 'date' => '2026-10-02', 'note' => 'خسارت', 'amount' => '200,000']);
    $hr->addRecord($e, $u['a'], $pid, ['kind' => 'fine', 'date' => '2026-10-05', 'note' => 'تأخیر مکرر', 'amount' => 100000]);
    expect_true(!empty($hr->addRecord($e, $u['a'], $pid, ['kind' => 'bonus', 'date' => '2026-10-02', 'note' => 'x', 'amount' => 0])['error']), 'money kinds need an amount');
    expect_true(!empty($hr->addRecord($e, $u['a'], $pid, ['kind' => 'weird', 'date' => '2026-10-02', 'note' => 'x'])['error']), 'unknown kind refused');

    $d = $hr->detail($e, $pid);
    expect_true($d['summary']['fine']['count'] === 2 && abs($d['summary']['fine']['total'] - 300000) < 0.01 && $d['summary']['warning']['count'] === 1, 'summary counts and totals');
    expect_true(count($d['records']) === 3 && $d['records'][0]['date'] >= $d['records'][2]['date'], 'records newest first');
    expect_true(($hr->deleteRecord($e, $u['b'], (int)$f['id'])['statusCode'] ?? 0) === 403, 'only the creator deletes a record');
    $conn->query("INSERT INTO hr_records (personnel_id, kind, date, amount, note, request_id, created_by) VALUES ({$pid}, 'advance', '2026-10-03', 100, 'از درخواست', 999999999, {$u['a']})");
    $reqRec = (int)$conn->insert_id;
    expect_true(!empty($hr->deleteRecord($e, $u['a'], $reqRec)['error']), 'a record from a payment request cannot be deleted here');
    expect_true(!empty($hr->deleteRecord($e, $u['a'], (int)$f['id'])['success']), 'creator deletes');
    expect_true($hr->detail($e + 999, $pid)['statusCode'] === 404, 'another entity cannot open the file');

    // Import team
    $maxBefore = (int)$one("SELECT COALESCE(MAX(id), 0) m FROM hr_personnel")['m'];
    $n1 = $hr->importTeam($e, $u['a']);
    $n2 = $hr->importTeam($e, $u['a']);
    $mine = (int)$one("SELECT COUNT(*) n FROM hr_personnel WHERE entity_id = {$e} AND user_id IN ({$u['a']}, {$u['b']})")['n'];
    expect_true($n1 >= 2 && $n2 === 0 && $mine === 2, 'import creates one file per team member, once');
    $list = $hr->list($e, $token, false);
    expect_true(count($list) >= 1, 'search by name');
    $hr->save($e, $u['a'], ['id' => $pid, 'full_name' => "{$token} انباردار", 'is_active' => 0]);
    expect_true(!in_array($pid, array_column($hr->list($e, $token, false), 'id')), 'inactive hidden unless asked');
    expect_true(in_array($pid, array_column($hr->list($e, $token, true), 'id')), 'inactive listed when asked');
} finally {
    $ids = array_column($conn->query("SELECT id FROM hr_personnel WHERE full_name LIKE '{$token}%' OR user_id IN (" . (implode(',', $u) ?: '0') . ") OR id > {$maxBefore}")->fetch_all(MYSQLI_ASSOC), 'id');
    foreach ($ids as $id) { $conn->query("DELETE FROM hr_records WHERE personnel_id = " . (int)$id); $conn->query("DELETE FROM hr_personnel WHERE id = " . (int)$id); }
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
