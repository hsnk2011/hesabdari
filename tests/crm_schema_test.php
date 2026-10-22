<?php
/**
 * CRM schema: tables, new columns, notifications index and permissions are
 * created by the migration. Run: php tests/crm_schema_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/core/schema.php';
require_once $root . '/api/core/SchemaMigrator.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
$msgs = [];
SchemaMigrator::run($conn, $msgs);

$db = DB_NAME;
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_row()[0] ?? null : null;
$tableExists = fn(string $t) => (int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$t}'") === 1;
$columnExists = fn(string $t, string $c) => (int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'") === 1;

foreach (['team_members', 'push_subscriptions', 'crm_activities', 'crm_tasks', 'crm_leads'] as $t) {
    expect_true($tableExists($t), "table {$t} exists");
}
expect_true($columnExists('notifications', 'user_id'), 'notifications.user_id exists');
expect_true($columnExists('customers', 'owner_user_id'), 'customers.owner_user_id exists');
expect_true((int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_user'") > 0, 'notifications has index idx_user');
expect_true((int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'crm_tasks' AND INDEX_NAME = 'uk_task_source' AND NON_UNIQUE = 0") > 0, 'crm_tasks has unique uk_task_source');

$perms = get_default_permissions();
foreach (['use_crm', 'manage_crm_team'] as $k) {
    expect_true(isset($perms[$k]), "default permission {$k} defined");
    expect_true((int)$one("SELECT COUNT(*) FROM permissions WHERE permission_key = '{$k}'") === 1, "permission {$k} stored");
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
