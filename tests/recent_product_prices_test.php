<?php
/**
 * Extend recent_product_prices_test with similar-name matching for purchase.
 * Run: php tests/recent_product_prices_test.php
 */
// Keep original tests and append similar-name cases by including previous file body...
// Actually replace/extend the existing test file.
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
$token = 'rp' . substr(bin2hex(random_bytes(5)), 0, 8);
$today = date('Y-m-d');
$older = date('Y-m-d', strtotime('-10 days'));
$_SESSION = ['current_entity_id' => $entityId];

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-c1', '{$token}1', {$entityId})");
    $customerA = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-c2', '{$token}2', {$entityId})");
    $customerB = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$entityId}, 1)");
    $productId = (int)$conn->insert_id;

    // Older global sale: customer B, dims 2x3, price 1000000
    $stmt = $conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES (?, ?, ?, 1000000, 0, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $customerB, $older);
    $stmt->execute();
    $invOld = (int)$conn->insert_id;
    $stmt->close();
    $dims23 = '2x3';
    $priceOld = 1000000.0;
    $stmt = $conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param('iisd', $invOld, $productId, $dims23, $priceOld);
    $stmt->execute();
    $stmt->close();

    // Recent person A similar dims 3x4 price 1500000
    $stmt = $conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES (?, ?, ?, 1500000, 0, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $customerA, $today);
    $stmt->execute();
    $invSimilar = (int)$conn->insert_id;
    $stmt->close();
    $dims34 = '3x4';
    $priceSimilar = 1500000.0;
    $stmt = $conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param('iisd', $invSimilar, $productId, $dims34, $priceSimilar);
    $stmt->execute();
    $stmt->close();

    // Recent person A exact dims 2x3 price 1200000
    $stmt = $conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES (?, ?, ?, 1200000, 0, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $customerA, $today);
    $stmt->execute();
    $invExact = (int)$conn->insert_id;
    $stmt->close();
    $priceExact = 1200000.0;
    $stmt = $conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param('iisd', $invExact, $productId, $dims23, $priceExact);
    $stmt->execute();
    $stmt->close();

    $model = new Invoice($conn, 'sales');
    $res = $model->getRecentProductPrices('sales', $productId, $customerA, '2x3', 0, 3);
    expect_true(($res['source'] ?? '') === 'person', 'Source is person when customer history exists');
    expect_true(count($res['prices']) >= 2, 'At least 2 price suggestions returned');
    expect_true((int)$res['prices'][0]['meter_price'] === 200000, 'Exact person+dims meter price ranks first');
    expect_true(($res['prices'][0]['match'] ?? '') === 'person_exact', 'First match is person_exact');
    expect_true((int)$res['prices'][0]['unit_price'] === 1200000, 'Unit price scaled for current 2x3 dims');
    expect_true((int)$res['prices'][1]['meter_price'] === 125000, 'Person similar dims meter price ranks second');
    expect_true((int)$res['prices'][1]['unit_price'] === 750000, 'Similar meter price converted to current 2x3 unit');

    $resGlobal = $model->getRecentProductPrices('sales', $productId, 0, '2x3', 0, 3);
    expect_true(($resGlobal['source'] ?? '') === 'global', 'Without personId source is global');
    expect_true((int)$resGlobal['prices'][0]['meter_price'] === 200000, 'Global with dims prefers matching dims newest meter');

    $resOther = $model->getRecentProductPrices('sales', $productId, $customerB, '2x3', 0, 3);
    expect_true((int)$resOther['prices'][0]['meter_price'] === 166667, 'Other customer gets own exact meter price first');

    // --- Similar product name matching (purchase + new product name) ---
    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}s', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('گلیم بوهمی پشمی - کد 10', {$entityId}, 1)");
    $gilimA = (int)$conn->insert_id;
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('گلیم بوهمی پشمی - کد 32', {$entityId}, 1)");
    $gilimB = (int)$conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, tax_rate, tax_amount) VALUES (?, ?, ?, 6000000, 0, 0, 0, 0)");
    $stmt->bind_param('iis', $entityId, $supplierId, $today);
    $stmt->execute();
    $purId = (int)$conn->insert_id;
    $stmt->close();
    $purPrice = 6000000.0; // 2x3 => 1,000,000 /m
    $stmt = $conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param('iisd', $purId, $gilimA, $dims23, $purPrice);
    $stmt->execute();
    $stmt->close();

    $purchaseModel = new Invoice($conn, 'purchase');
    $byOtherCode = $purchaseModel->getRecentProductPrices('purchase', $gilimB, $supplierId, '2x3', 0, 3);
    expect_true(count($byOtherCode['prices']) >= 1, 'Purchase finds price via similar product code');
    expect_true((int)$byOtherCode['prices'][0]['meter_price'] === 1000000, 'Similar gilim meter price is 1,000,000');
    expect_true(empty($byOtherCode['prices'][0]['is_exact_product']), 'Matched product is marked non-exact');

    $byNewName = $purchaseModel->getRecentProductPrices('purchase', 0, $supplierId, '2x3', 0, 3, 'گلیم بوهمی پشمی - کد 99');
    expect_true(count($byNewName['prices']) >= 1, 'New product name finds similar purchase history');
    expect_true((int)$byNewName['prices'][0]['meter_price'] === 1000000, 'New-name suggestion meter price correct');
} catch (Throwable $e) {
    echo '[FAIL] exception: ' . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
