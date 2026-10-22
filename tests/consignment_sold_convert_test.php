<?php
/**
 * Consignment sold-item conversion: sold qty leaves consignment, remainder stays.
 * Run: php tests/consignment_sold_convert_test.php
 * Uses a DB transaction that is always rolled back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Invoice.php';

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
$token = 't' . substr(bin2hex(random_bytes(6)), 0, 10);
$today = date('Y-m-d');

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cus', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id) VALUES ('{$token}-prd', {$entityId})");
    $productId = (int)$conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, is_consignment) VALUES (?, ?, ?, 200000, 0, 0, 1)");
    $stmt->bind_param('iis', $entityId, $supplierId, $today);
    $stmt->execute();
    $consignmentId = (int)$conn->insert_id;
    $stmt->close();

    $dims = '1x2';
    $qty = 2;
    $price = 100000;
    $stmt = $conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisid', $consignmentId, $productId, $dims, $qty, $price);
    $stmt->execute();
    $piiId = (int)$conn->insert_id;
    $stmt->close();

    $invIds = [];
    for ($i = 0; $i < 2; $i++) {
        $serial = $token . '-INV' . $i;
        $stmt = $conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, serial_id) VALUES (?, ?, ?, 'in_stock', ?, ?)");
        $stmt->bind_param('iisis', $productId, $entityId, $dims, $piiId, $serial);
        $stmt->execute();
        $invIds[] = (int)$conn->insert_id;
        $stmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, is_consignment) VALUES (?, ?, ?, 150000, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $customerId, $today);
    $stmt->execute();
    $salesId = (int)$conn->insert_id;
    $stmt->close();

    $saleQty = 1;
    $salePrice = 150000;
    $stmt = $conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisid', $salesId, $productId, $dims, $saleQty, $salePrice);
    $stmt->execute();
    $siiId = (int)$conn->insert_id;
    $stmt->close();

    $soldInvId = $invIds[0];
    $stmt = $conn->prepare("UPDATE inventory_items SET status = 'sold', sales_invoice_item_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $siiId, $soldInvId);
    $stmt->execute();
    $stmt->close();

    $invoice = new Invoice($conn, 'purchase', true);
    $found = $invoice->findSoldConsignmentPurchaseItems($entityId, $salesId);
    expect_true(($found['count'] ?? 0) === 1, 'Find reports the sold consignment serial');

    $converted = $invoice->convertSoldConsignmentPurchaseItems($entityId, $salesId, false);
    expect_true(($converted['converted_count'] ?? 0) === 1, 'Convert moves exactly one sold item');
    $newPurchaseId = (int)($converted['invoices'][0]['new_purchase_id'] ?? 0);
    expect_true($newPurchaseId > 0, 'A regular purchase invoice is created');

    $remainQty = (int)$conn->query("SELECT quantity FROM purchase_invoice_items WHERE id = {$piiId}")->fetch_assoc()['quantity'];
    expect_true($remainQty === 1, 'Consignment line quantity reduced to remaining stock');

    $consignmentFlag = (int)$conn->query("SELECT is_consignment FROM purchase_invoices WHERE id = {$consignmentId}")->fetch_assoc()['is_consignment'];
    expect_true($consignmentFlag === 1, 'Original invoice stays consignment');

    $newFlag = (int)$conn->query("SELECT is_consignment FROM purchase_invoices WHERE id = {$newPurchaseId}")->fetch_assoc()['is_consignment'];
    expect_true($newFlag === 0, 'New invoice is a regular purchase');

    $newQty = (int)$conn->query("SELECT quantity FROM purchase_invoice_items WHERE invoiceId = {$newPurchaseId}")->fetch_assoc()['quantity'];
    expect_true($newQty === 1, 'New purchase has the sold quantity');

    $soldPii = (int)$conn->query("SELECT purchase_invoice_item_id FROM inventory_items WHERE id = {$soldInvId}")->fetch_assoc()['purchase_invoice_item_id'];
    expect_true($soldPii !== $piiId, 'Sold serial now belongs to the new purchase line');

    $stockPii = (int)$conn->query("SELECT purchase_invoice_item_id FROM inventory_items WHERE id = {$invIds[1]}")->fetch_assoc()['purchase_invoice_item_id'];
    expect_true($stockPii === $piiId, 'Unsold serial stays on the consignment line');

    $foundAfter = $invoice->findSoldConsignmentPurchaseItems($entityId, $salesId);
    expect_true(($foundAfter['count'] ?? 0) === 0, 'Sold consignment list is empty after convert');
} catch (Throwable $e) {
    echo "[FAIL] exception: " . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
