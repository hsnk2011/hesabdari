<?php
/**
 * میز کار stage 2–3 schema: messenger, cartable, HR, ideas tables, new
 * permissions. Run: php tests/workspace2_schema_test.php
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
$unique = fn(string $t, string $c) => (int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}' AND NON_UNIQUE = 0") > 0;

foreach (['chat_conversations', 'chat_members', 'chat_messages', 'chat_reactions', 'work_requests', 'work_request_events', 'hr_personnel', 'hr_records', 'ideas', 'idea_comments', 'idea_votes'] as $t) {
    expect_true($table($t), "table {$t} exists");
}
expect_true((int)$one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'work_files' AND COLUMN_NAME = 'is_voice'") === 1, 'work_files.is_voice exists');
expect_true($unique('chat_conversations', 'direct_key'), 'direct_key is unique (per entity)');
expect_true($unique('work_requests', 'number'), 'request number is unique (per entity)');
expect_true($unique('hr_records', 'request_id'), 'one hr record per request');
expect_true($unique('ideas', 'project_id'), 'one project per idea');
$perms = get_default_permissions();
foreach (['use_messenger', 'use_cartable', 'approve_payments', 'pay_payments', 'manage_hr', 'use_ideas'] as $k) {
    expect_true(isset($perms[$k]) && (int)$one("SELECT COUNT(*) FROM permissions WHERE permission_key = '{$k}'") === 1, "permission {$k}");
}
expect_true(version_compare(APP_VERSION, '2.5.0', '>='), 'APP_VERSION is at least 2.5.0');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
