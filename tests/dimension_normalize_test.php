<?php
/**
 * Canonical dimensions: «60X90», «۶۰ × ۹۰», «60*90», «60 در 90» are all stored as «60x90»;
 * named sizes such as «پادری» stay as they are. Covers the helper, stock entry and a sales line.
 * Run: php tests/dimension_normalize_test.php
 * saveSalesInvoice commits its own transaction, so every row this test makes is deleted at the end.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Product.php';
require_once $root . '/api/models/Invoice.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}

// 1) helper
$cases = [
    '60x90' => '60x90', '60X90' => '60x90', ' 60 × 90 ' => '60x90', '60*90' => '60x90', '60✕90' => '60x90',
    '۶۰×۹۰' => '60x90', '٦٠x٩٠' => '60x90', '60 در 90' => '60x90', '۲٫۵ در ۳' => '2.5x3', '200X300' => '200x300',
    'پادری' => 'پادری', ' پادری ' => 'پادری', '' => '',
];
foreach ($cases as $in => $out) {
    expect_true(normalize_dimensions($in) === $out, "normalize_dimensions(" . json_encode($in, JSON_UNESCAPED_UNICODE) . ") = {$out}");
}
expect_true(normalize_dimensions(null) === '', 'normalize_dimensions(null) = ""');

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) { fwrite(STDERR, 'DB connect failed' . PHP_EOL); exit(1); }
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$entityId = 1;
$_SESSION['current_entity_id'] = $entityId;
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';
$one = function (string $sql) use ($conn) { $r = $conn->query($sql); return $r ? $r->fetch_assoc() : null; };

$tok = 'dimtest-' . substr(bin2hex(random_bytes(4)), 0, 8);
$productId = 0; $customerId = 0; $invoiceIds = [];
try {
    $conn->query("INSERT INTO products (name, entity_id) VALUES ('{$tok}', {$entityId})");
    $productId = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$tok}', '', {$entityId})");
    $customerId = (int)$conn->insert_id;

    // 2) stock entry with Persian digits and «×»
    $res = (new Product($conn))->addSerializedItems($productId, '۶۰ × ۹۰', 2);
    expect_true(!empty($res['success']), 'addSerializedItems succeeded');
    $dims = $one("SELECT GROUP_CONCAT(DISTINCT BINARY dimensions) d, COUNT(*) n FROM inventory_items WHERE product_id = {$productId}");
    expect_true($dims['d'] === '60x90' && (int)$dims['n'] === 2, 'stock stored as 60x90 (2 pieces)');

    // 3) sales line typed as «60X90» is stored canonical and takes a piece from that stock
    $inv = new Invoice($conn, 'sales');
    $r = $inv->saveSalesInvoice(['customerId' => $customerId, 'date' => '2031-08-01', 'discount' => 0, 'payments' => [],
        'items' => [['productId' => $productId, 'dimensions' => '60X90', 'quantity' => 1, 'unitPrice' => 1000]]]);
    expect_true(!empty($r['success']), 'sales invoice with 60X90 saved');
    if (!empty($r['id'])) $invoiceIds[] = (int)$r['id'];
    $line = $one("SELECT id, BINARY dimensions d FROM sales_invoice_items WHERE invoiceId = " . (int)($r['id'] ?? 0));
    expect_true(($line['d'] ?? null) === '60x90', 'sales line stored as 60x90');
    $linked = $one("SELECT COUNT(*) n FROM inventory_items WHERE product_id = {$productId} AND sales_invoice_item_id = " . (int)($line['id'] ?? 0));
    expect_true((int)$linked['n'] === 1, 'the sales line took one 60x90 piece from stock');
    $left = $one("SELECT COUNT(*) n FROM inventory_items WHERE product_id = {$productId} AND status = 'in_stock'");
    expect_true((int)$left['n'] === 1, 'one 60x90 piece left in stock');
} catch (Throwable $e) {
    $failed++;
    echo "[FAIL] exception: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($invoiceIds) {
        $in = implode(',', $invoiceIds);
        $conn->query("UPDATE inventory_items SET sales_invoice_item_id = NULL WHERE sales_invoice_item_id IN (SELECT id FROM sales_invoice_items WHERE invoiceId IN ({$in}))");
        $conn->query("DELETE FROM payments WHERE invoiceType = 'sales' AND invoiceId IN ({$in})");
        $conn->query("DELETE FROM sales_invoice_items WHERE invoiceId IN ({$in})");
        try { $conn->query("DELETE FROM invoice_history WHERE invoice_type = 'sales' AND invoice_id IN ({$in})"); } catch (Throwable $e) {}
        $conn->query("DELETE FROM sales_invoices WHERE id IN ({$in})");
    }
    if ($productId) {
        $conn->query("DELETE FROM inventory_items WHERE product_id = {$productId}");
        try { $conn->query("DELETE FROM integration_outbox WHERE payload_json LIKE '%\"product_id\":{$productId}%'"); } catch (Throwable $e) {}
        $conn->query("DELETE FROM products WHERE id = {$productId}");
    }
    if ($customerId) $conn->query("DELETE FROM customers WHERE id = {$customerId}");
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
