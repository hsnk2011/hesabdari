<?php
/**
 * Cost-of-goods method: purchase header discounts lower each piece's cost, and service
 * lines on purchase invoices are a cost of the period. Both profit methods then agree.
 * Run: php tests/cogs_discount_service_test.php (one transaction, always rolled back)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/ReportModel.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.01; }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
$ins = function (string $sql) use ($conn): int { $conn->query($sql); return (int)$conn->insert_id; };
$token = 'cd' . substr(bin2hex(random_bytes(4)), 0, 8);

$conn->begin_transaction();
try {
    $e = $ins("INSERT INTO business_entities (name) VALUES ('{$token}-entity')");
    $_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e]];
    $sup = $ins("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$e})");
    $cust = $ins("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cust', '{$token}', {$e})");
    $prd = $ins("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$e}, 1)");

    // Two pieces at 100 + a service line of 30 = 230, with a 10% header discount (23).
    $pi = $ins("INSERT INTO purchase_invoices (supplierId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$sup}, '2030-01-05', 230, 23, 0, 0, {$e})");
    $pii = $ins("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions, is_service) VALUES ({$pi}, {$prd}, 2, 100, '2x3', 0)");
    $ins("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions, is_service, item_description) VALUES ({$pi}, {$prd}, 1, 30, '', 1, 'حمل')");
    // One piece sold for 150.
    $si = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2030-02-01', 150, 0, 0, 0, {$e})");
    $sii = $ins("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$si}, {$prd}, 1, 150, '2x3')");
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, entity_id) VALUES ({$prd}, '2x3', 'sold', {$pii}, {$sii}, {$e})");
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, entity_id) VALUES ({$prd}, '2x3', 'in_stock', {$pii}, {$e})");

    $rep = new ReportModel($conn);
    $args = ['startDate' => '2030-01-01', 'endDate' => '2030-12-31', 'entityId' => $e, 'skipPartnerDetails' => true];
    $cogs = $rep->getProfitLossReport($args + ['calculationMethod' => 'cogs']);
    $inv = $rep->getProfitLossReport($args + ['calculationMethod' => 'inventory']);

    // Sold piece costs 100 × 0.9 = 90; the service costs 30 × 0.9 = 27.
    expect_true(near($cogs['totalCogs'], 117), 'cogs = discounted piece 90 + discounted service 27 (' . $cogs['totalCogs'] . ')');
    expect_true(near($cogs['purchaseServices'] ?? 0, 27), 'purchase services are reported separately (' . ($cogs['purchaseServices'] ?? 'none') . ')');
    expect_true(near($cogs['netOperatingProfit'], 33), 'cogs profit = 150 − 117 = 33 (' . $cogs['netOperatingProfit'] . ')');
    expect_true(near($rep->getInventoryValueAtDate('2030-12-31', $e), 90), 'the piece in stock is valued at its discounted cost 90');
    expect_true(near($inv['netOperatingProfit'], 33), 'inventory method gives the same 33 (' . $inv['netOperatingProfit'] . ')');

    // ---- stock value at a past date for a piece that was sold, returned and sold again ----
    $pi2 = $ins("INSERT INTO purchase_invoices (supplierId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$sup}, '2031-01-05', 300, 0, 0, 0, {$e})");
    $pii2 = $ins("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions, is_service) VALUES ({$pi2}, {$prd}, 2, 150, '3x4', 0)");
    $saleA = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2031-03-01', 200, 0, 0, 0, {$e})");
    $siiA = $ins("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$saleA}, {$prd}, 2, 100, '3x4')");
    $ret = $ins("INSERT INTO return_invoices (type, original_invoice_id, original_invoice_type, person_id, person_type, date, totalAmount, discount, paidAmount, entity_id)
                 VALUES ('sales_return', {$saleA}, 'sales', {$cust}, 'customer', '2031-05-01', 200, 0, 0, {$e})");
    $ins("INSERT INTO return_invoice_items (return_invoice_id, productId, quantity, unitPrice, dimensions, original_invoice_item_id) VALUES ({$ret}, {$prd}, 2, 100, '3x4', {$siiA})");
    $saleB = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2031-06-01', 120, 0, 0, 0, {$e})");
    $siiB = $ins("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$saleB}, {$prd}, 1, 120, '3x4')");
    // Piece R: sold (A), returned, sold again (B). Piece Q: sold (A), returned, still with us.
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, returned_from_sales_item_id, entity_id) VALUES ({$prd}, '3x4', 'sold', {$pii2}, {$siiB}, {$siiA}, {$e})");
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, entity_id) VALUES ({$prd}, '3x4', 'returned', {$pii2}, {$siiA}, {$e})");
    $base = 90; // the discounted piece from the first invoice is still in stock throughout
    expect_true(near($rep->getInventoryValueAtDate('2031-02-01', $e) - $base, 300), 'before the first sale both pieces are in stock');
    expect_true(near($rep->getInventoryValueAtDate('2031-04-01', $e) - $base, 0), 'between the sale and the customer return neither piece is in stock (' . ($rep->getInventoryValueAtDate('2031-04-01', $e) - $base) . ')');
    expect_true(near($rep->getInventoryValueAtDate('2031-05-15', $e) - $base, 300), 'after the return both pieces are back in stock');
    expect_true(near($rep->getInventoryValueAtDate('2031-07-01', $e) - $base, 150), 'after the second sale only the kept piece is in stock');
} catch (Throwable $ex) {
    $failed++;
    echo "[FAIL] exception: " . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine() . "\n";
} finally {
    $conn->rollback();
}
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
