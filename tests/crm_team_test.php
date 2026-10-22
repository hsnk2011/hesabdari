<?php
/**
 * CRM team: members, per-user notifications and push-subscription upkeep.
 * Run: php tests/crm_team_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/core/CrmNotifier.php';
require_once $root . '/api/controllers/NotificationController.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } // log_activity only writes inside a session; returned ids must not be the log row's
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => 1, 'username' => 'crm-test'];
$token = 'crmt' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$userIds = []; $partnerId = 0;

try {
    foreach (['a', 'b'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $userIds[$s] = (int)$conn->insert_id;
    }
    $conn->query("INSERT INTO partners (name, share, entity_id) VALUES ('{$token}-p', 0.1, {$entityId})");
    $partnerId = (int)$conn->insert_id;
    $team = new TeamMember($conn);

    $r1 = $team->save($entityId, ['full_name' => "{$token} شریک", 'mobile' => '09120000000', 'kind' => 'partner', 'user_id' => $userIds['a'], 'partner_id' => $partnerId]);
    expect_true(!empty($r1['success']) && $r1['id'] > 0, 'partner member saved' . (!empty($r1['error']) ? ' — ' . $r1['error'] : ''));
    expect_true(($one("SELECT full_name FROM team_members WHERE id = " . (int)($r1['id'] ?? 0))['full_name'] ?? '') === "{$token} شریک", 'save returns the id of the member row');
    $dup = $team->save($entityId, ['full_name' => "{$token} تکراری", 'kind' => 'employee', 'user_id' => $userIds['a']]);
    expect_true(!empty($dup['error']), 'same user cannot be two members of one entity');
    $noName = $team->save($entityId, ['full_name' => '  ', 'kind' => 'employee']);
    expect_true(!empty($noName['error']), 'full_name is required');
    $r2 = $team->save($entityId, ['full_name' => "{$token} کارمند", 'kind' => 'employee']);
    expect_true(!empty($r2['success']), 'employee without a system user can be saved');
    $ids = TeamMember::userIdsForEntity($conn, $entityId);
    expect_true(in_array($userIds['a'], $ids, true), 'userIdsForEntity lists the member with a user');
    $listed = array_values(array_filter($team->list($entityId), fn($m) => (int)$m['id'] === (int)$r1['id']))[0] ?? null;
    expect_true($listed && $listed['username'] === "{$token}a", 'list joins the username');
    $team->deactivate($entityId, (int)$r1['id']);
    expect_true(!in_array($userIds['a'], TeamMember::userIdsForEntity($conn, $entityId), true), 'deactivated member is no longer a team user');
    $team->save($entityId, ['id' => $r1['id'], 'full_name' => "{$token} شریک", 'kind' => 'partner', 'user_id' => $userIds['a'], 'partner_id' => $partnerId, 'is_active' => 1]);

    // Notifications: per user and whole team
    $conn->query("INSERT INTO push_subscriptions (entity_id, user_id, endpoint, p256dh, auth) VALUES ({$entityId}, {$userIds['a']}, 'http://127.0.0.1:9/{$token}', 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'BTBZMqHH6r4Tts7J_aSIgg')");
    $subId = (int)$conn->insert_id;
    CrmNotifier::notify($conn, $entityId, $userIds['a'], 'crm_test', "{$token} برای الف", 'متن', './index.php#crm');
    $n = $one("SELECT user_id FROM notifications WHERE title = '{$token} برای الف'");
    expect_true($n && (int)$n['user_id'] === $userIds['a'], 'notify stores the notification for that user');
    expect_true((int)$one("SELECT fail_count FROM push_subscriptions WHERE id = {$subId}")['fail_count'] === 1, 'unreachable push endpoint counts a failure without throwing');
    CrmNotifier::notify($conn, $entityId, null, 'crm_test', "{$token} برای همه", 'متن', './index.php#crm');

    $visibleB = array_column(NotificationController::fetchVisible($conn, $entityId, $userIds['b'], 500), 'title');
    expect_true(!in_array("{$token} برای الف", $visibleB, true), "user B does not see user A's notification");
    expect_true(in_array("{$token} برای همه", $visibleB, true), 'user B sees a whole-team notification');
    $visibleA = array_column(NotificationController::fetchVisible($conn, $entityId, $userIds['a'], 500), 'title');
    expect_true(in_array("{$token} برای الف", $visibleA, true), 'user A sees their own notification');

    CrmNotifier::handleResult($conn, $subId, ['status' => 201, 'gone' => false]);
    $s = $one("SELECT fail_count, last_success_at FROM push_subscriptions WHERE id = {$subId}");
    expect_true((int)$s['fail_count'] === 0 && $s['last_success_at'] !== null, 'a delivered push resets fail_count and stamps last_success_at');
    CrmNotifier::handleResult($conn, $subId, ['status' => 410, 'gone' => true]);
    expect_true($one("SELECT id FROM push_subscriptions WHERE id = {$subId}") === null, 'a gone (410) subscription is deleted');

    $vapid = CrmNotifier::vapid($conn);
    expect_true(strlen(WebPush::b64urlDecode($vapid['public'])) === 65 && $vapid['subject'] !== '', 'VAPID keys are created once and readable');
    expect_true(CrmNotifier::vapid($conn)['public'] === $vapid['public'], 'VAPID keys are stable between calls');
} finally {
    $conn->query("DELETE FROM notifications WHERE title LIKE '{$token}%'");
    $conn->query("DELETE FROM push_subscriptions WHERE endpoint LIKE '%{$token}%'");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    if ($partnerId) { $conn->query("DELETE FROM partners WHERE id = {$partnerId}"); }
    foreach ($userIds as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
