<?php
/**
 * Order warehouse edge cases:
 *  1) A sold-but-undelivered rug in the order warehouse must not count as sellable stock
 *     (otherwise editing its sales invoice shows one extra).
 *  2) Sale removed/cancelled before factory delivery → "deliver" moves the rug into a
 *     physical warehouse (default or chosen) as stock, touching only the purchase invoice.
 * deliverOrderItems() commits its own transaction, so this test cleans up explicitly.
 * Run: php tests/order_warehouse_cancelled_sale_test.php
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

// send_json() exits the script, so each deliverOrderItems() call runs in a child process
// (this file with --deliver=<json>); the parent does setup, DB assertions and cleanup.
function call_deliver(array $data): void {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg('--deliver=' . base64_encode(json_encode($data)));
    echo '    deliver -> ' . trim((string)shell_exec($cmd . ' 2>&1')) . PHP_EOL;
}

$entityId = 1;
if (isset($argv[1]) && str_starts_with($argv[1], '--deliver=')) {
    $childConn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    $childConn->set_charset('utf8mb4');
    @session_start();
    $_SESSION['current_entity_id'] = $entityId;
    $_SESSION['user_id'] = 1;
    $_SESSION['allowed_entities'] = [$entityId];
    $_SESSION['permissions'] = ['manage_products'];
    (new InventoryController($childConn))->deliverOrderItems(json_decode(base64_decode(substr($argv[1], 10)), true));
    exit(0);
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, 'DB connect failed: ' . $conn->connect_error . PHP_EOL);
    exit(1);
}
$conn->set_charset('utf8mb4');

$token = 'ow' . substr(bin2hex(random_bytes(5)), 0, 8);
$today = date('Y-m-d');
$_SESSION = ['current_entity_id' => $entityId];

$ids = ['supplier' => 0, 'customer' => 0, 'product' => 0, 'wh' => [], 'purchase' => 0, 'sales' => 0];
try {
    $col = $conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical'");
    }

    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $ids['supplier'] = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cus', '{$token}', {$entityId})");
    $ids['customer'] = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$entityId}, 1)");
    $productId = $ids['product'] = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-order', 0, 1, 1, 'order')");
    $orderWhId = $ids['wh'][] = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-office', 0, 1, 0, 'physical')");
    $officeWhId = $ids['wh'][] = (int)$conn->insert_id;

    $conn->query("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES ({$entityId}, {$ids['supplier']}, '{$today}', 1200000, 0, 0, 0, 0)");
    $purchaseId = $ids['purchase'] = (int)$conn->insert_id;
    $conn->query("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES ({$purchaseId}, {$productId}, '2x3', 2, 600000)");
    $piiId = (int)$conn->insert_id;

    $conn->query("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES ({$entityId}, {$ids['customer']}, '{$today}', 900000, 0, 0, 0, 0)");
    $salesId = $ids['sales'] = (int)$conn->insert_id;
    $conn->query("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES ({$salesId}, {$productId}, '2x3', 1, 900000)");
    $siiId = (int)$conn->insert_id;

    // Rug A: sold to customer, waiting for factory. Rug B: its sale was deleted (back to in_stock, still in order warehouse).
    $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, warehouse_id, serial_id) VALUES ({$productId}, {$entityId}, '2x3', 'sold', {$piiId}, {$siiId}, {$orderWhId}, '{$token}-A')");
    $invA = (int)$conn->insert_id;
    $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, warehouse_id, serial_id) VALUES ({$productId}, {$entityId}, '2x3', 'in_stock', {$piiId}, NULL, {$orderWhId}, '{$token}-B')");
    $invB = (int)$conn->insert_id;

    // --- 1) stock summary ---
    $attach = new ReflectionMethod(Product::class, 'attachInventoryDetails');
    $attach->setAccessible(true);
    $products = $attach->invoke(new Product($conn), [['id' => $productId]]);
    $stock = $products[0]['stock'][0] ?? [];
    expect_true((int)($stock['total_quantity'] ?? -1) === 2, 'total_quantity still shows both rugs in order warehouse');
    expect_true((int)($stock['available_quantity'] ?? -1) === 1, 'available_quantity excludes the sold rug');
    expect_true(($stock['available_serial_ids'] ?? []) === ["{$token}-B"], 'available_serial_ids excludes the sold rug');

    // --- 2a) deliver rug without customer to an explicit physical warehouse, with new size ---
    call_deliver(['items' => [['id' => $invB, 'dimensions' => '2x3.5', 'serial_id' => "{$token}-B", 'target_warehouse_id' => $officeWhId]]]);
    $rowB = $conn->query("SELECT status, warehouse_id, dimensions, sales_invoice_item_id FROM inventory_items WHERE id = {$invB}")->fetch_assoc();
    expect_true((int)$rowB['warehouse_id'] === $officeWhId, 'Customer-less rug moved into the physical warehouse');
    expect_true($rowB['status'] === 'in_stock', 'Customer-less rug stays sellable stock');
    expect_true($rowB['dimensions'] === '2x3.5', 'Customer-less rug got delivered size');
    $salesTotal = (float)$conn->query("SELECT SUM(quantity * unitPrice) AS t FROM sales_invoice_items WHERE invoiceId = {$salesId}")->fetch_assoc()['t'];
    expect_true(abs($salesTotal - 900000) < 0.01, 'Unrelated sales invoice untouched');
    $pLine = $conn->query("SELECT unitPrice FROM purchase_invoice_items pii JOIN inventory_items ii ON ii.purchase_invoice_item_id = pii.id WHERE ii.id = {$invB}")->fetch_assoc();
    expect_true(abs((float)$pLine['unitPrice'] - 700000) < 0.01, 'Purchase line rescaled for customer-less rug');

    // --- 2b) returned rug (keeps old sales link) with no target → default/first physical warehouse; sales line not rescaled ---
    $conn->query("UPDATE inventory_items SET status = 'returned' WHERE id = {$invA}");
    $salesLineBefore = $conn->query("SELECT unitPrice, dimensions FROM sales_invoice_items WHERE id = {$siiId}")->fetch_assoc();
    call_deliver(['items' => [['id' => $invA, 'dimensions' => '2x4', 'serial_id' => "{$token}-A"]]]);
    $rowA = $conn->query("SELECT ii.status, ii.warehouse_id, w.warehouse_type FROM inventory_items ii LEFT JOIN warehouses w ON w.id = ii.warehouse_id WHERE ii.id = {$invA}")->fetch_assoc();
    expect_true(!empty($rowA['warehouse_id']) && $rowA['warehouse_type'] !== 'order', 'Returned rug placed in a physical warehouse');
    expect_true($rowA['status'] === 'returned', 'Returned rug keeps returned status');
    $salesLineAfter = $conn->query("SELECT unitPrice, dimensions FROM sales_invoice_items WHERE id = {$siiId}")->fetch_assoc();
    expect_true($salesLineAfter == $salesLineBefore, 'Old sales line of returned rug not modified');

    // --- 2c) order warehouse is rejected as target ---
    $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, warehouse_id, serial_id) VALUES ({$productId}, {$entityId}, '2x3', 'in_stock', {$piiId}, {$orderWhId}, '{$token}-C')");
    $invC = (int)$conn->insert_id;
    call_deliver(['items' => [['id' => $invC, 'dimensions' => '2x3', 'target_warehouse_id' => $orderWhId]]]);
    $rowC = $conn->query("SELECT warehouse_id FROM inventory_items WHERE id = {$invC}")->fetch_assoc();
    expect_true((int)$rowC['warehouse_id'] === $orderWhId, 'Order warehouse rejected as delivery target');
} catch (Throwable $e) {
    echo '[FAIL] exception: ' . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    if ($ids['product']) {
        $conn->query("DELETE FROM inventory_items WHERE product_id = {$ids['product']}");
    }
    if ($ids['sales']) {
        $conn->query("DELETE FROM sales_invoice_items WHERE invoiceId = {$ids['sales']}");
        $conn->query("DELETE FROM sales_invoices WHERE id = {$ids['sales']}");
    }
    if ($ids['purchase']) {
        $conn->query("DELETE FROM purchase_invoice_items WHERE invoiceId = {$ids['purchase']}");
        $conn->query("DELETE FROM purchase_invoices WHERE id = {$ids['purchase']}");
    }
    foreach ($ids['wh'] as $wid) {
        $conn->query("DELETE FROM warehouses WHERE id = {$wid}");
    }
    if ($ids['product']) $conn->query("DELETE FROM products WHERE id = {$ids['product']}");
    if ($ids['customer']) $conn->query("DELETE FROM customers WHERE id = {$ids['customer']}");
    if ($ids['supplier']) $conn->query("DELETE FROM suppliers WHERE id = {$ids['supplier']}");
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
