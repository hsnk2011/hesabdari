<?php
/**
 * Deliver from order warehouse: update dims/serial, rescale prices, split qty>1 lines.
 * Run: php tests/deliver_order_warehouse_test.php
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
$token = 'dl' . substr(bin2hex(random_bytes(5)), 0, 8);
$today = date('Y-m-d');
$_SESSION = [
    'current_entity_id' => $entityId,
    'permissions' => ['manage_products'],
];

$conn->begin_transaction();
try {
    $col = $conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical'");
    }

    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cus', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$entityId}, 1)");
    $productId = (int)$conn->insert_id;
    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-order', 0, 1, 1, 'order')");
    $orderWhId = (int)$conn->insert_id;

    // Purchase: 2 rugs on one line at 2x3, unitPrice 600000 (100000 / m² × 6)
    $stmt = $conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount, warehouse_id, description) VALUES (?, ?, ?, 1200000, 0, 0, 0, 0, ?, 'ثبت به‌عنوان سفارش به کارخانه')");
    $stmt->bind_param('iisi', $entityId, $supplierId, $today, $orderWhId);
    $stmt->execute();
    $purchaseId = (int)$conn->insert_id;
    $stmt->close();

    $conn->query("INSERT INTO warehouses (entity_id, name, is_default, is_active, show_on_dashboard, warehouse_type) VALUES ({$entityId}, '{$token}-phys', 1, 1, 1, 'physical')");
    $physWhId = (int)$conn->insert_id;

    $dims = '2x3';
    $unitPurchase = 600000.0;
    $stmt = $conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 2, ?)");
    $stmt->bind_param('iisd', $purchaseId, $productId, $dims, $unitPurchase);
    $stmt->execute();
    $piiId = (int)$conn->insert_id;
    $stmt->close();

    // Sales: 2 rugs on one line, unitPrice 900000
    $stmt = $conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES (?, ?, ?, 1800000, 0, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $customerId, $today);
    $stmt->execute();
    $salesId = (int)$conn->insert_id;
    $stmt->close();

    $unitSales = 900000.0;
    $stmt = $conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 2, ?)");
    $stmt->bind_param('iisd', $salesId, $productId, $dims, $unitSales);
    $stmt->execute();
    $siiId = (int)$conn->insert_id;
    $stmt->close();

    $serial1 = $token . '-A';
    $serial2 = $token . '-B';
    $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, warehouse_id, serial_id) VALUES (?, ?, ?, 'sold', ?, ?, ?, ?)");
    $stmt->bind_param('iisiiis', $productId, $entityId, $dims, $piiId, $siiId, $orderWhId, $serial1);
    $stmt->execute();
    $inv1 = (int)$conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, warehouse_id, serial_id) VALUES (?, ?, ?, 'sold', ?, ?, ?, ?)");
    $stmt->bind_param('iisiiis', $productId, $entityId, $dims, $piiId, $siiId, $orderWhId, $serial2);
    $stmt->execute();
    $inv2 = (int)$conn->insert_id;
    $stmt->close();

    expect_true(abs(dimension_area($conn, '2x3') - 6.0) < 0.001, 'dimension_area(2x3)=6');
    expect_true(abs(rescale_unit_price_by_area($conn, '2x3', '2x3.5', 600000) - 700000) < 0.01, 'rescale purchase 2x3→2x3.5');

    // Capture send_json by buffering — call private logic via reflection on controller methods
    $controller = new InventoryController($conn);
    $deliver = new ReflectionMethod(InventoryController::class, 'deliverOrderItems');

    // deliverOrderItems calls send_json and exits flow — instead invoke private helpers + simulate deliver body
    $apply = new ReflectionMethod(InventoryController::class, 'applyDeliverDimensionToInvoiceLine');
    $apply->setAccessible(true);
    $recalc = new ReflectionMethod(InventoryController::class, 'recalculateInvoiceHeaderTotals');
    $recalc->setAccessible(true);

    $newDims = '2x3.5';
    $newSerial = $token . '-A2';

    $salesChange = $apply->invoke($controller, 'sales', $siiId, $inv1, $productId, $dims, $newDims, $entityId);
    $purchaseChange = $apply->invoke($controller, 'purchase', $piiId, $inv1, $productId, $dims, $newDims, $entityId);
    expect_true(!empty($salesChange['changed']), 'Sales line change reported');
    expect_true(!empty($purchaseChange['changed']), 'Purchase line change reported');

    $append = new ReflectionMethod(InventoryController::class, 'appendDeliverChangeNote');
    $append->setAccessible(true);
    $append->invoke($controller, 'sales', $salesId, $entityId, $newSerial, $salesChange);
    $append->invoke($controller, 'purchase', $purchaseId, $entityId, $newSerial, $purchaseChange);

    $upd = $conn->prepare("UPDATE inventory_items SET dimensions = ?, serial_id = ?, warehouse_id = NULL WHERE id = ?");
    $upd->bind_param('ssi', $newDims, $newSerial, $inv1);
    $upd->execute();
    $upd->close();

    $recalc->invoke($controller, 'sales', $salesId, $entityId);
    $recalc->invoke($controller, 'purchase', $purchaseId, $entityId);

    $invRow = $conn->query("SELECT dimensions, serial_id, warehouse_id, sales_invoice_item_id, purchase_invoice_item_id FROM inventory_items WHERE id = {$inv1}")->fetch_assoc();
    expect_true(($invRow['dimensions'] ?? '') === $newDims, 'Inventory dimensions updated');
    expect_true(($invRow['serial_id'] ?? '') === $newSerial, 'Inventory serial updated');
    expect_true($invRow['warehouse_id'] === null || $invRow['warehouse_id'] === '', 'Left order warehouse');

    $newSii = (int)$invRow['sales_invoice_item_id'];
    $newPii = (int)$invRow['purchase_invoice_item_id'];
    expect_true($newSii !== $siiId, 'Sales line split to new id');
    expect_true($newPii !== $piiId, 'Purchase line split to new id');

    $siiNew = $conn->query("SELECT quantity, dimensions, unitPrice FROM sales_invoice_items WHERE id = {$newSii}")->fetch_assoc();
    expect_true((int)$siiNew['quantity'] === 1, 'New sales line qty=1');
    expect_true(($siiNew['dimensions'] ?? '') === $newDims, 'New sales line dimensions');
    expect_true(abs(floatval($siiNew['unitPrice']) - 1050000) < 0.01, 'Sales price rescaled 900k×(7/6)');

    $piiNew = $conn->query("SELECT quantity, dimensions, unitPrice FROM purchase_invoice_items WHERE id = {$newPii}")->fetch_assoc();
    expect_true((int)$piiNew['quantity'] === 1, 'New purchase line qty=1');
    expect_true(abs(floatval($piiNew['unitPrice']) - 700000) < 0.01, 'Purchase price rescaled 600k×(7/6)');

    $siiOld = $conn->query("SELECT quantity, dimensions, unitPrice FROM sales_invoice_items WHERE id = {$siiId}")->fetch_assoc();
    expect_true((int)$siiOld['quantity'] === 1, 'Original sales line qty reduced to 1');
    expect_true(($siiOld['dimensions'] ?? '') === $dims, 'Original sales line keeps old dims');
    expect_true(abs(floatval($siiOld['unitPrice']) - 900000) < 0.01, 'Original sales price unchanged');

    $salesHdr = $conn->query("SELECT totalAmount FROM sales_invoices WHERE id = {$salesId}")->fetch_assoc();
    // remaining line 900000 + new 1050000 = 1950000
    expect_true(abs(floatval($salesHdr['totalAmount']) - 1950000) < 0.01, 'Sales invoice total recalculated');

    $purchaseHdr = $conn->query("SELECT totalAmount FROM purchase_invoices WHERE id = {$purchaseId}")->fetch_assoc();
    // remaining 600000 + new 700000 = 1300000
    expect_true(abs(floatval($purchaseHdr['totalAmount']) - 1300000) < 0.01, 'Purchase invoice total recalculated');

    $inv2Row = $conn->query("SELECT sales_invoice_item_id, purchase_invoice_item_id, dimensions FROM inventory_items WHERE id = {$inv2}")->fetch_assoc();
    expect_true((int)$inv2Row['sales_invoice_item_id'] === $siiId, 'Second rug stays on original sales line');
    expect_true(($inv2Row['dimensions'] ?? '') === $dims, 'Second rug dims unchanged');

    $salesDesc = (string)($conn->query("SELECT description FROM sales_invoices WHERE id = {$salesId}")->fetch_assoc()['description'] ?? '');
    expect_true(str_contains($salesDesc, 'تحویل انبار سفارش'), 'Sales invoice description has deliver note');
    expect_true(str_contains($salesDesc, $newDims), 'Sales note includes new dimensions');
    $purchaseDesc = (string)($conn->query("SELECT description FROM purchase_invoices WHERE id = {$purchaseId}")->fetch_assoc()['description'] ?? '');
    expect_true(str_contains($purchaseDesc, 'تحویل انبار سفارش'), 'Purchase invoice description has deliver note');

    // After first rug leaves order warehouse, second still there → purchase stays order warehouse
    $normalize = new ReflectionMethod(InventoryController::class, 'normalizePurchaseInvoiceAfterOrderDeliver');
    $normalize->setAccessible(true);
    $normalize->invoke($controller, $purchaseId, $entityId, null);
    $piWh = $conn->query("SELECT warehouse_id FROM purchase_invoices WHERE id = {$purchaseId}")->fetch_assoc();
    expect_true((int)($piWh['warehouse_id'] ?? 0) === $orderWhId, 'Purchase keeps order warehouse while stock remains');

    // Move second rug out of order warehouse, then normalize → becomes normal + keeps factory note
    $conn->query("UPDATE inventory_items SET warehouse_id = NULL WHERE id = {$inv2}");
    $normalize->invoke($controller, $purchaseId, $entityId, $physWhId);
    $piAfter = $conn->query("SELECT warehouse_id, description FROM purchase_invoices WHERE id = {$purchaseId}")->fetch_assoc();
    expect_true((int)($piAfter['warehouse_id'] ?? 0) === $physWhId, 'Purchase converted to physical warehouse after full deliver');
    expect_true(str_contains((string)($piAfter['description'] ?? ''), 'ثبت به‌عنوان سفارش به کارخانه'), 'Factory-order registration note preserved');

    // withFactoryOrderPurchaseNote on save path
    require_once $root . '/api/models/Invoice.php';
    $invoiceModel = new Invoice($conn);
    $noteFn = new ReflectionMethod(Invoice::class, 'withFactoryOrderPurchaseNote');
    $noteFn->setAccessible(true);
    $withNote = $noteFn->invoke($invoiceModel, 'پرداخت نقدی', $orderWhId, $entityId);
    expect_true(str_contains($withNote, 'ثبت به‌عنوان سفارش به کارخانه'), 'Save path adds factory-order note');
    $withNote2 = $noteFn->invoke($invoiceModel, $withNote, $orderWhId, $entityId);
    expect_true(substr_count($withNote2, 'ثبت به‌عنوان سفارش به کارخانه') === 1, 'Factory-order note is not duplicated');
    $noNote = $noteFn->invoke($invoiceModel, 'عادی', $physWhId, $entityId);
    expect_true(!str_contains($noNote, 'ثبت به‌عنوان سفارش به کارخانه'), 'Physical warehouse does not add factory note');
} catch (Throwable $e) {
    echo '[FAIL] exception: ' . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
