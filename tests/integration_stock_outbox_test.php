<?php
/**
 * Stock updates to the online shops: one queue row per product set, queued
 * again on every change, stock read at send time (never an old count).
 * Run: php tests/integration_stock_outbox_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/core/IntegrationService.php';

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
$tbl = $conn->query("SHOW TABLES LIKE 'integration_outbox'");
if (!$tbl || $tbl->num_rows === 0) {
    echo "integration_outbox missing (run api/upgrade_integration.php) — skipped\n";
    exit(0);
}

$entityId = 1;
$token = 'so' . substr(bin2hex(random_bytes(5)), 0, 8);
$svc = new IntegrationService($conn);

function outbox_rows(mysqli $conn, int $entityId, int $productId): array {
    $key = 'stock_sync_' . $entityId . '_' . md5(json_encode([$productId]));
    $stmt = $conn->prepare("SELECT * FROM integration_outbox WHERE idempotency_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}', {$entityId}, 1)");
    $pid = (int)$conn->insert_id;
    $add = function (string $dims, string $serial) use ($conn, $pid, $entityId) {
        $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, serial_id) VALUES (?, ?, ?, 'in_stock', ?)");
        $stmt->bind_param('iiss', $pid, $entityId, $dims, $serial);
        $stmt->execute();
        return (int)$conn->insert_id;
    };
    $add('200x300', "{$token}-1");
    $sold = $add('150x225', "{$token}-2");

    $svc->enqueueStockSync($entityId, [$pid]);
    $rows = outbox_rows($conn, $entityId, $pid);
    expect_true(count($rows) === 1, 'first change queues one row');
    $payload = json_decode($rows[0]['payload_json'], true);
    expect_true(($payload['product_ids'] ?? null) === [$pid], 'row carries product ids');
    expect_true(!isset($payload['items']), 'row does not freeze a stock count');

    // The row was delivered, then the 150x225 carpet is sold in the shop.
    $conn->query("UPDATE integration_outbox SET status = 'sent', attempts = 3, sent_at = NOW() WHERE id = " . (int)$rows[0]['id']);
    $conn->query("UPDATE inventory_items SET status = 'sold' WHERE id = {$sold}");
    $svc->enqueueStockSync($entityId, [$pid, $pid]);

    $rows = outbox_rows($conn, $entityId, $pid);
    expect_true(count($rows) === 1, 'second change in the same minute reuses the row');
    expect_true($rows[0]['status'] === 'pending' && (int)$rows[0]['attempts'] === 0, 'sent row is queued again');

    $snapshot = $svc->buildStockSnapshot($entityId, [$pid]);
    $dims = array_column($snapshot, 'available_qty', 'dimensions');
    expect_true(($dims['200x300'] ?? null) === 1, 'snapshot at send time has the remaining size');
    expect_true(!isset($dims['150x225']), 'sold-out size is absent (the shop zeroes it)');
} finally {
    $conn->rollback();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
