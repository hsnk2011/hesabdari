<?php
/**
 * Smoke test for global search groups.
 * Run: php tests/global_search_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/GlobalSearch.php';

$passed = 0;
$failed = 0;

function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$msg}\n";
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, 'DB connect failed: ' . $conn->connect_error . PHP_EOL);
    exit(1);
}
$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['current_entity_id'] = 1;
$_SESSION['permissions'] = [
    'manage_customers', 'manage_suppliers', 'manage_products',
    'manage_sales_invoices', 'manage_purchase_invoices', 'manage_consignment',
    'manage_quotations', 'manage_checks', 'manage_transactions',
    'manage_accounts', 'manage_return_invoices',
];

$model = new GlobalSearch($conn);

$empty = $model->search('');
expect_true(!empty($empty['success']) && ($empty['groups'] ?? null) === [], 'empty query returns no groups');

$short = $model->search('ا');
expect_true(!empty($short['success']) && ($short['groups'] ?? null) === [], 'single letter does not hit DB');

$numeric = $model->search('1');
expect_true(!empty($numeric['success']) && isset($numeric['groups']), 'numeric query is allowed');
expect_true(is_array($numeric['groups']), 'numeric result groups is array');

$serial = $model->search('INV-001');
expect_true(!empty($serial['success']) && isset($serial['groups']), 'INV-001 serial query is allowed');

$faDigits = $model->search('۱۲');
expect_true(!empty($faDigits['success']), 'persian digits normalize');

$keys = [];
foreach ($numeric['groups'] as $g) {
    $keys[] = $g['key'];
    expect_true(!empty($g['items']), "group {$g['key']} has items");
    foreach ($g['items'] as $item) {
        expect_true(!empty($item['type']) && !empty($item['title']) && !empty($item['tab']), "item in {$g['key']} has type/title/tab");
    }
}

echo "groups for '1': " . (count($numeric['groups']) ? implode(', ', $keys) : '(none)') . "\n";
echo "PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
