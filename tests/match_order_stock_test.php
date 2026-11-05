<?php
/**
 * Factory-order ↔ physical stock matching and nearest-size search (match_order_stock).
 * Run: php tests/match_order_stock_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Product.php';
require_once $root . '/api/controllers/InventoryController.php';

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

$entityId = 1;
$token = 'mo' . substr(bin2hex(random_bytes(5)), 0, 8);
$_SESSION = [
    'current_entity_id' => $entityId,
    'permissions' => ['manage_products'],
];

function call_match(InventoryController $c, array $data): array {
    global $entityId;
    return $c->buildOrderStockMatch($entityId, $data);
}

/** Only this test's rows: entity 1 may already hold real stock of the same sizes. */
function mine(array $rows, string $token): array {
    return array_values(array_filter($rows, fn($r) => str_starts_with((string)$r['serial_id'], $token)));
}

$conn->begin_transaction();
try {
    $col = $conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical'");
    }
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-order', 0, 1, 1, 'order')");
    $orderWh = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-phys', 0, 1, 0, 'physical')");
    $physWh = (int)$conn->insert_id;

    $conn->query("INSERT INTO products (name, design_code, colorway, entity_id, is_active) VALUES ('{$token}-A', '{$token}D', 'red', {$entityId}, 1)");
    $prodA = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, design_code, colorway, entity_id, is_active) VALUES ('{$token}-A2', '{$token}D', 'blue', {$entityId}, 1)");
    $prodA2 = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, design_code, colorway, entity_id, is_active) VALUES ('{$token}-B', '{$token}X', 'cream', {$entityId}, 1)");
    $prodB = (int)$conn->insert_id;

    $add = function (int $productId, string $dims, string $status, ?int $wh, string $serial) use ($conn, $entityId) {
        $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, warehouse_id, serial_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissis', $productId, $entityId, $dims, $status, $wh, $serial);
        $stmt->execute();
        return (int)$conn->insert_id;
    };

    // Order: product A, 2x3
    $add($prodA, '1.37x2.71', 'in_stock', $orderWh, "{$token}-O1");
    // Order: product B, 250x350 (cm)
    $add($prodB, '187x321', 'in_stock', $orderWh, "{$token}-O2");

    // Stock
    $add($prodA, '2.71x1.37', 'in_stock', $physWh, "{$token}-S1");        // same product, rotated → 0 cm
    $add($prodA2, '142x271', 'in_stock', $physWh, "{$token}-S2");   // same design, 5 cm
    $add($prodB, '1.45x2.71', 'returned', null, "{$token}-S3");        // size only, 8 cm, no warehouse
    $add($prodA, '1.37x3.01', 'in_stock', $physWh, "{$token}-S4");      // same product, 30 cm → out of ±10
    $add($prodA, '1.37x2.71', 'sold', $physWh, "{$token}-S5");            // sold → never a match
    $add($prodA, '1.37x2.71', 'in_stock', $orderWh, "{$token}-S6");       // in order warehouse → it is an order, not stock

    $c = new InventoryController($conn);

    $res = call_match($c, ['tolerance_cm' => 10]);
    $orders = mine($res['orders'] ?? [], $token);
    $o1 = array_values(array_filter($orders, fn($o) => $o['serial_id'] === "{$token}-O1"))[0] ?? null;
    expect_true($o1 !== null, 'Order 1.37x2.71 is listed');
    $m = mine($o1['matches'] ?? [], $token);
    $serials = array_column($m, 'serial_id');
    expect_true($serials === ["{$token}-S1", "{$token}-S2", "{$token}-S3"], 'Matches sorted product > design > size: ' . implode(',', $serials));
    expect_true(($m[0]['tier'] ?? '') === 'product' && (int)$m[0]['diff_cm'] === 0, 'Rotated 2.71x1.37 of same product is an exact match');
    expect_true(($m[1]['tier'] ?? '') === 'design' && (int)$m[1]['diff_cm'] === 5, 'Same design code other colour: 5 cm');
    expect_true(($m[2]['tier'] ?? '') === 'size' && (int)$m[2]['diff_cm'] === 8, 'Other design, size only: 8 cm');
    expect_true(!in_array("{$token}-S5", $serials, true) && !in_array("{$token}-S6", $serials, true), 'Sold and order-warehouse rugs are not stock matches');
    expect_true(!in_array("{$token}-S4", $serials, true), '30 cm off is outside ±10');

    $res = call_match($c, ['tolerance_cm' => 50]);
    $o1 = array_values(array_filter(mine($res['orders'] ?? [], $token), fn($o) => $o['serial_id'] === "{$token}-O1"))[0] ?? null;
    expect_true(in_array("{$token}-S4", array_column($o1['matches'] ?? [], 'serial_id'), true), 'Wider tolerance includes 30 cm match');

    // Nearest-size search: ۲×۳ (Persian digits) → O1 (0) before O2 (50 cm), stock nearest includes S1 first
    $res = call_match($c, ['search' => '۱٫۳۷×۲٫۷۱', 'tolerance_cm' => 10]);
    expect_true(($res['search_kind'] ?? '') === 'size', 'Persian size is detected as a size search');
    $orders = mine($res['orders'] ?? [], $token);
    // S6 sits in the order warehouse, so it is an order too (2x3, tie broken by serial)
    expect_true(array_column($orders, 'serial_id') === ["{$token}-O1", "{$token}-S6", "{$token}-O2"], 'Orders sorted by nearest size: ' . implode(',', array_column($orders, 'serial_id')));
    expect_true((int)(end($orders)['distance_cm'] ?? -1) === 50, '187x321 is 50 cm from 1.37x2.71');
    $near = mine($res['stock_nearest'] ?? [], $token);
    expect_true(($near[0]['serial_id'] ?? '') === "{$token}-S1", 'Stock nearest starts with the exact rug');
    expect_true(in_array("{$token}-S4", array_column($near, 'serial_id'), true), 'Stock nearest shows up to 50 cm');

    // Text search
    $res = call_match($c, ['search' => "{$token}-B"]);
    $orders = mine($res['orders'] ?? [], $token);
    expect_true(array_column($orders, 'serial_id') === ["{$token}-O2"] && ($res['search_kind'] ?? '') === 'text', 'Text search by product name');
    expect_true(empty($res['stock_nearest']), 'Text search has no stock-nearest list');

    // Partial size
    $res = call_match($c, ['search' => '187x']);
    $orders = mine($res['orders'] ?? [], $token);
    expect_true(array_column($orders, 'serial_id') === ["{$token}-O2"], 'Partial size 187x is a prefix match');
} catch (Throwable $e) {
    echo '[FAIL] exception: ' . $e->getMessage() . ' @' . $e->getLine() . PHP_EOL;
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
