<?php
/**
 * میز کار (stage 1) schema: project/meeting tables, new columns, permissions
 * and the protected storage folder. Run: php tests/workspace_schema_test.php
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
$one = fn(string $sql) => ($r = $conn->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$table = fn(string $t) => (int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$t}'") === 1;
$col = fn(string $t, string $c) => (int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'") === 1;

foreach (['projects', 'project_members', 'project_tasks', 'project_task_checks', 'project_task_deps', 'project_comments', 'meetings', 'meeting_attendees', 'meeting_actions', 'work_files'] as $t) {
    expect_true($table($t), "table {$t} exists");
}
foreach ([['expenses', 'project_id'], ['notifications', 'link'], ['project_tasks', 'reminded_on'], ['meetings', 'reminded_at']] as [$t, $c]) {
    expect_true($col($t, $c), "{$t}.{$c} exists");
}
expect_true((int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'meeting_actions' AND COLUMN_NAME = 'task_id' AND NON_UNIQUE = 0") > 0, 'meeting_actions.task_id is unique');
$perms = get_default_permissions();
foreach (['use_projects', 'manage_projects'] as $k) {
    expect_true(isset($perms[$k]), "default permission {$k}");
    expect_true((int)$one("SELECT COUNT(*) FROM permissions WHERE permission_key = '{$k}'") === 1, "permission {$k} stored");
}
$ht = @file_get_contents($root . '/storage/.htaccess') ?: '';
expect_true(strpos($ht, 'Require all denied') !== false, 'storage/.htaccess denies all requests');
$nginx = @file_get_contents($root . '/docs/deploy/nginx-hesabdari-hardening.conf') ?: '';
expect_true((bool)preg_match('#location ~ \^/hesabdari/\([^)]*\bstorage\b#', $nginx), 'nginx hardening denies /hesabdari/storage/');
expect_true(version_compare(APP_VERSION, '2.4.0', '>='), 'APP_VERSION is at least 2.4.0');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
