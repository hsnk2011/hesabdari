<?php
/**
 * Check-due SMS must go out at most once per day: the per-entity marker is
 * really stored, and a number that already got today's alert is skipped.
 * Sends nothing. Run: php tests/sms_check_alert_guard_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/controllers/SmsController.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$_SESSION = ['current_entity_id' => 1, 'allowed_entities' => [1], 'user_id' => null];
$sms = new SmsController($conn);
$today = date('Y-m-d');
$fakeEntity = 987654;
$number = '0912' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$logIds = [];

try {
    // marker is persisted (it used to be dropped by the settings whitelist)
    $sms->markCheckAlertDone($fakeEntity, $today);
    expect_true($sms->checkAlertMarkerDate($fakeEntity) === $today, 'per-entity "sent today" marker is stored');

    // log-based guard
    expect_true($sms->checkAlertSentToday($number, $today) === false, 'number without a log entry is not blocked');
    $st = $conn->prepare("INSERT INTO sms_logs (receptor, source, status, message, created_at) VALUES (?, 'check_alert', 1, 'test', NOW())");
    $st->bind_param('s', $number);
    $st->execute();
    $logIds[] = $conn->insert_id;
    expect_true($sms->checkAlertSentToday($number, $today) === true, 'number that got an alert today is blocked');
    expect_true($sms->checkAlertSentToday('+98' . substr($number, 1), $today) === true, 'same number in +98 form is blocked too');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    expect_true($sms->checkAlertSentToday($number, $yesterday) === false, 'yesterday is a different day');

    $other = $conn->prepare("INSERT INTO sms_logs (receptor, source, status, message, created_at) VALUES (?, 'invoice', 1, 'test', NOW())");
    $num2 = '0935' . substr($number, 4);
    $other->bind_param('s', $num2);
    $other->execute();
    $logIds[] = $conn->insert_id;
    expect_true($sms->checkAlertSentToday($num2, $today) === false, 'other SMS types do not block the check alert');
} catch (Throwable $e) {
    expect_true(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $conn->query("DELETE FROM settings WHERE setting_key = 'sms_check_alert_last_run_{$fakeEntity}'");
    foreach ($logIds as $id) { $conn->query('DELETE FROM sms_logs WHERE id = ' . (int)$id); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
