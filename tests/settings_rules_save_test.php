<?php
/**
 * «قوانین و رفتار سیستم» settings are really stored (they were dropped by the
 * whitelist before 2.5.1) and are validated; activity_log keeps a year of history.
 * Run: php tests/settings_rules_save_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Settings.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } // log_activity only writes inside a session
$keys = ['negative_stock_policy', 'credit_limit_policy', 'cron_mode', 'reminder_sms_enabled',
    'reminder_installment_days', 'reminder_overdue_every_days', 'sms_verify_template_reminder'];
$in = "'" . implode("','", $keys) . "'";
$saved = [];
$res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ({$in})");
while ($row = $res->fetch_assoc()) { $saved[$row['setting_key']] = $row['setting_value']; }
$val = function (string $k) use ($conn): ?string {
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '" . $conn->real_escape_string($k) . "'")->fetch_assoc();
    return $r['setting_value'] ?? null;
};
$token = 'srst' . substr(bin2hex(random_bytes(4)), 0, 6);

try {
    $settings = new Settings($conn);
    $settings->saveAllSettings([
        'negative_stock_policy' => 'block',
        'credit_limit_policy' => 'off',
        'cron_mode' => 'external',
        'reminder_sms_enabled' => '1',
        'reminder_installment_days' => '5',
        'reminder_overdue_every_days' => '14',
        'sms_verify_template_reminder' => 'remind',
    ]);
    expect_true($val('negative_stock_policy') === 'block', 'negative_stock_policy is stored');
    expect_true($val('credit_limit_policy') === 'off', 'credit_limit_policy is stored');
    expect_true($val('cron_mode') === 'external', 'cron_mode is stored');
    expect_true($val('reminder_sms_enabled') === '1', 'reminder_sms_enabled is stored');
    expect_true($val('reminder_installment_days') === '5', 'reminder_installment_days is stored');
    expect_true($val('reminder_overdue_every_days') === '14', 'reminder_overdue_every_days is stored');
    expect_true($val('sms_verify_template_reminder') === 'remind', 'sms_verify_template_reminder is stored');

    $settings->saveAllSettings([
        'negative_stock_policy' => 'whatever',
        'credit_limit_policy' => '',
        'cron_mode' => 'x',
        'reminder_sms_enabled' => 'on',
        'reminder_installment_days' => '99',
        'reminder_overdue_every_days' => '-3',
    ]);
    expect_true($val('negative_stock_policy') === 'allow', 'unknown stock policy falls back to allow');
    expect_true($val('credit_limit_policy') === 'warn', 'unknown credit policy falls back to warn');
    expect_true($val('cron_mode') === 'request', 'unknown cron mode falls back to request');
    expect_true($val('reminder_sms_enabled') === '0', 'non-boolean reminder switch is stored as 0');
    expect_true($val('reminder_installment_days') === '30', 'installment days are capped at 30');
    expect_true($val('reminder_overdue_every_days') === '0', 'overdue repeat days are at least 0');

    // activity_log: a year of history instead of the newest 500 rows.
    $conn->query("INSERT INTO activity_log (user_id, timestamp, username, action_type, description) VALUES
        (NULL, NOW() - INTERVAL 400 DAY, '{$token}', 'TEST', 'old'),
        (NULL, NOW() - INTERVAL 200 DAY, '{$token}', 'TEST', 'recent')");
    $_SESSION = ['user_id' => 1, 'username' => $token];
    log_activity($conn, 'TEST', 'trigger prune');
    $left = array_column($conn->query("SELECT description FROM activity_log WHERE username = '{$token}'")->fetch_all(MYSQLI_ASSOC), 'description');
    expect_true(!in_array('old', $left, true), 'entries older than a year are pruned');
    expect_true(in_array('recent', $left, true), 'entries within a year are kept');
} finally {
    $conn->query("DELETE FROM activity_log WHERE username = '{$token}'");
    $conn->query("DELETE FROM settings WHERE setting_key IN ({$in})");
    foreach ($saved as $k => $v) {
        $st = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $st->bind_param('ss', $k, $v);
        $st->execute();
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
