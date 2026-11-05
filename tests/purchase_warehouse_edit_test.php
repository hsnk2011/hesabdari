<?php
/**
 * Editing a purchase invoice warehouse must move in_stock items
 * even when warehouse_id was stored as 0 (mysqli null→int) or NULL.
 * Run: php tests/purchase_warehouse_edit_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Invoice.php';
require_once $root . '/api/models/Product.php';

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
$token = 'pw' . substr(bin2hex(random_bytes(5)), 0, 8);
$today = date('Y-m-d');
$_SESSION = ['current_entity_id' => $entityId];

$conn->begin_transaction();
try {
    $col = $conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical' AFTER `show_on_dashboard`");
    }
    $col = $conn->query("SHOW COLUMNS FROM purchase_invoices LIKE 'warehouse_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `purchase_invoices` ADD COLUMN `warehouse_id` int(11) DEFAULT NULL AFTER `invoice_number`");
    }

    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$entityId}, 1)");
    $productId = (int)$conn->insert_id;

    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-order', 0, 1, 1, 'order')");
    $orderWhId = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-phys', 0, 1, 0, 'physical')");
    $physWhId = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-empty-order', 0, 1, 1, 'order')");
    $emptyOrderWh = (int)$conn->insert_id;

    // Invoice A: header NULL warehouse; stock warehouse_id = 0 (legacy mysqli null→0)
    $stmt = $conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, warehouse_id) VALUES (?, ?, ?, 100000, 0, 0, NULL)");
    $stmt->bind_param('iis', $entityId, $supplierId, $today);
    $stmt->execute();
    $invoiceId = (int)$conn->insert_id;
    $stmt->close();

    $dims = '2x3';
    $stmt = $conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, 100000)");
    $stmt->bind_param('iis', $invoiceId, $productId, $dims);
    $stmt->execute();
    $piiId = (int)$conn->insert_id;
    $stmt->close();

    $serial = $token . '-S0';
    $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, warehouse_id, serial_id) VALUES (?, ?, ?, 'in_stock', ?, 0, ?)");
    $stmt->bind_param('iisis', $productId, $entityId, $dims, $piiId, $serial);
    $stmt->execute();
    $invIdZero = (int)$conn->insert_id;
    $stmt->close();

    // Invoice B: header + stock on physical warehouse → edit moves to order
    $stmt = $conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, warehouse_id) VALUES (?, ?, ?, 50000, 0, 0, ?)");
    $stmt->bind_param('iisi', $entityId, $supplierId, $today, $physWhId);
    $stmt->execute();
    $invoiceId2 = (int)$conn->insert_id;
    $stmt->close();

    $dims2 = '3x4';
    $stmt = $conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, 50000)");
    $stmt->bind_param('iis', $invoiceId2, $productId, $dims2);
    $stmt->execute();
    $piiId2 = (int)$conn->insert_id;
    $stmt->close();

    $serial2 = $token . '-S1';
    $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, warehouse_id, serial_id) VALUES (?, ?, ?, 'in_stock', ?, ?, ?)");
    $stmt->bind_param('iisiss', $productId, $entityId, $dims2, $piiId2, $physWhId, $serial2);
    $stmt->execute();
    $invIdPhys = (int)$conn->insert_id;
    $stmt->close();

    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-empty-prd', {$entityId}, 1)");
    $emptyProductId = (int)$conn->insert_id;

    $invoice = new Invoice($conn, 'purchase', false);
    $method = new ReflectionMethod(Invoice::class, 'applyPurchaseWarehouseToStock');
    $method->setAccessible(true);

    $method->invoke($invoice, $entityId, $invoiceId, $orderWhId, null);
    $row = $conn->query("SELECT warehouse_id FROM inventory_items WHERE id = {$invIdZero}")->fetch_assoc();
    expect_true((int)($row['warehouse_id'] ?? -1) === $orderWhId, 'Stock with warehouse_id=0 moves to order warehouse when invoice had no warehouse');

    $method->invoke($invoice, $entityId, $invoiceId2, $orderWhId, $physWhId);
    $row2 = $conn->query("SELECT warehouse_id FROM inventory_items WHERE id = {$invIdPhys}")->fetch_assoc();
    expect_true((int)($row2['warehouse_id'] ?? -1) === $orderWhId, 'Stock on previous physical warehouse moves to order warehouse');

    $productModel = new Product($conn);
    $listed = $productModel->getPaginated([
        'stockFilter' => 'in_stock',
        'warehouseId' => (string)$orderWhId,
        'limit' => 50,
        'currentPage' => 1,
        'searchTerm' => $token . '-prd',
        'sortOrder' => 'ASC',
    ]);
    $ids = array_map('intval', array_column($listed['data'] ?? [], 'id'));
    expect_true(in_array($productId, $ids, true), 'Product appears when filtering by order warehouse + in_stock');

    $oos = $productModel->getPaginated([
        'stockFilter' => 'out_of_stock',
        'warehouseId' => (string)$emptyOrderWh,
        'limit' => 200,
        'currentPage' => 1,
        'searchTerm' => $token . '-empty-prd',
        'sortOrder' => 'ASC',
    ]);
    $oosIds = array_map('intval', array_column($oos['data'] ?? [], 'id'));
    // بدون سابقه در انبار، «ناموجود» نباید همه محصولات را نشان دهد
    expect_true(!in_array($emptyProductId, $oosIds, true), 'Out-of-stock + empty order warehouse does not list unrelated products');

    // محصول با فقط فرش فروخته‌شده در انبار سفارش: با فیلتر موجود دیده شود
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-sold-prd', {$entityId}, 1)");
    $soldProductId = (int)$conn->insert_id;
    $serialSold = $token . '-SX';
    $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, warehouse_id, serial_id) VALUES (?, ?, '2x3', 'sold', ?, ?)");
    $stmt->bind_param('iiis', $soldProductId, $entityId, $emptyOrderWh, $serialSold);
    $stmt->execute();
    $stmt->close();

    $listedSold = $productModel->getPaginated([
        'stockFilter' => 'in_stock',
        'warehouseId' => (string)$emptyOrderWh,
        'limit' => 50,
        'currentPage' => 1,
        'searchTerm' => $token . '-sold-prd',
        'sortOrder' => 'ASC',
    ]);
    $soldIds = array_map('intval', array_column($listedSold['data'] ?? [], 'id'));
    expect_true(in_array($soldProductId, $soldIds, true), 'Sold item in order warehouse appears under in_stock+order-warehouse filter');

    $oosSold = $productModel->getPaginated([
        'stockFilter' => 'out_of_stock',
        'warehouseId' => (string)$emptyOrderWh,
        'limit' => 50,
        'currentPage' => 1,
        'searchTerm' => $token . '-sold-prd',
        'sortOrder' => 'ASC',
    ]);
    $oosSoldIds = array_map('intval', array_column($oosSold['data'] ?? [], 'id'));
    expect_true(in_array($soldProductId, $oosSoldIds, true), 'Sold-only presence in order warehouse counts as out_of_stock for that warehouse');
} catch (Throwable $e) {
    echo '[FAIL] exception: ' . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
