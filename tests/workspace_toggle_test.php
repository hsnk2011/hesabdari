<?php
/**
 * «میز کار» on/off switch (settings.workspace_enabled): permissions, setting
 * whitelist, expense project options and background jobs.
 * Run: php tests/workspace_toggle_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/User.php';
require_once $root . '/api/models/Settings.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$token = 'wst' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$saved = $one("SELECT setting_value FROM settings WHERE setting_key = 'workspace_enabled'");
$userId = 0; $roleId = 0;

try {
    $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}', 'x')");
    $userId = (int)$conn->insert_id;
    $conn->query("INSERT INTO roles (role_name, description, is_system) VALUES ('{$token}', 't', 0)");
    $roleId = (int)$conn->insert_id;
    $conn->query("INSERT INTO role_permissions (role_id, permission_id) SELECT {$roleId}, id FROM permissions WHERE permission_key IN ('use_crm', 'use_messenger', 'manage_customers')");
    $conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ({$userId}, {$roleId})");
    $users = new User($conn);
    $settings = new Settings($conn);

    $conn->query("DELETE FROM settings WHERE setting_key = 'workspace_enabled'");
    expect_true(workspace_enabled($conn), 'workspace is on when the setting was never saved');
    $perms = $users->getUserAccessData($userId)['permissions'];
    expect_true(in_array('use_crm', $perms, true) && in_array('use_messenger', $perms, true), 'workspace permissions are granted while it is on');

    $settings->saveAllSettings(['workspace_enabled' => '0']);
    expect_true(!workspace_enabled($conn), 'saving 0 switches it off (key is whitelisted)');
    $perms = $users->getUserAccessData($userId)['permissions'];
    expect_true(!array_intersect($perms, WORKSPACE_PERMISSIONS), 'no workspace permission reaches the session while it is off');
    expect_true(in_array('manage_customers', $perms, true), 'other permissions of the role stay');
    expect_true(array_keys($perms) === range(0, count($perms) - 1), 'permission list stays a JSON array');
    expect_true((int)($one("SELECT COUNT(*) c FROM role_permissions WHERE role_id = {$roleId}")['c'] ?? 0) === 3, 'role assignments are not touched');

    $settings->saveAllSettings(['workspace_enabled' => 'yes']);
    expect_true(($one("SELECT setting_value v FROM settings WHERE setting_key = 'workspace_enabled'")['v'] ?? '') === '0', 'non-boolean input is stored as 0');

    $settings->saveAllSettings(['workspace_enabled' => '1']);
    expect_true(workspace_enabled($conn), 'saving 1 switches it back on');
    expect_true(in_array('use_crm', $users->getUserAccessData($userId)['permissions'], true), 'permissions come back when it is on again');

    // Every background job of «میز کار» checks the switch first.
    $src = file_get_contents($root . '/api/core/Scheduler.php');
    foreach (['crm_automation', 'work_task_reminders', 'work_meeting_reminders', 'crm_push'] as $job) {
        expect_true((bool)preg_match("/'{$job}' => \[\d+, function \(\\\$db\) \{\s*if \(!workspace_enabled\(\\\$db\)\) return/", $src), "scheduler job {$job} is skipped while it is off");
    }
    $work = file_get_contents($root . '/api/controllers/WorkController.php');
    expect_true((bool)preg_match('/function projectOptions\(\$data\) \{\s*.*\n\s*if \(!workspace_enabled/', $work), 'expense project options are empty while it is off');
} finally {
    if ($roleId) { $conn->query("DELETE FROM roles WHERE id = {$roleId}"); }
    if ($userId) { $conn->query("DELETE FROM users WHERE id = {$userId}"); }
    $conn->query("DELETE FROM settings WHERE setting_key = 'workspace_enabled'");
    if ($saved !== null) {
        $v = $conn->real_escape_string((string)$saved['setting_value']);
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('workspace_enabled', '{$v}')");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
