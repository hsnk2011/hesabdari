<?php
/**
 * Check lifecycle (bounce / return / undo cash), payment guards, virtual stock
 * release and quotation → invoice stock allocation.
 * Run: php tests/check_lifecycle_and_stock_test.php
 * Model methods open their own transactions, so this test creates uniquely
 * named rows and deletes them in the finally block.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Account.php';
require_once $root . '/api/models/Payment.php';
require_once $root . '/api/models/Check.php';
require_once $root . '/api/models/Invoice.php';
require_once $root . '/api/models/Quotation.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);

$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId]];
$token = 'lc' . substr(bin2hex(random_bytes(5)), 0, 8);
$date = '2031-05-10';

$one = function (string $sql) use ($conn) {
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
};
$created = ['customers' => [], 'suppliers' => [], 'accounts' => [], 'products' => []];

try {
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cust', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id; $created['customers'][] = $customerId;
    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id; $created['suppliers'][] = $supplierId;
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-bank', 0, {$entityId}, 0, 0)");
    $accountId = (int)$conn->insert_id; $created['accounts'][] = $accountId;

    $invoice = new Invoice($conn, 'sales');
    $payment = new Payment($conn);
    $check = new Check($conn);

    // --- Sales invoice paid by a received check ---
    $res = $invoice->saveSalesInvoice([
        'customerId' => $customerId, 'date' => $date, 'discount' => 0,
        'items' => [['is_service' => 1, 'item_description' => 'خدمات ' . $token, 'quantity' => 1, 'unitPrice' => 1000000]],
        'payments' => [[
            'type' => 'check', 'transaction_type' => 'receipt', 'amount' => 1000000, 'date' => $date,
            'checkDetails' => ['checkNumber' => $token . '-1', 'dueDate' => '2031-06-01', 'bankName' => 'ملی'],
        ]],
    ]);
    expect_true(!empty($res['success']), 'sales invoice with check saved');
    $salesId = (int)($res['id'] ?? 0);
    $paid = fn($table, $id) => (float)$one("SELECT paidAmount FROM {$table} WHERE id = {$id}")['paidAmount'];
    expect_true(abs($paid('sales_invoices', $salesId) - 1000000) < 0.01, 'invoice paid by check');
    $chk = $one("SELECT * FROM checks WHERE checkNumber = '{$token}-1' AND entity_id = {$entityId}");
    $checkId = (int)$chk['id'];
    $origPay = $one("SELECT * FROM payments WHERE checkId = {$checkId} AND type = 'check'");

    // Saving the same invoice unchanged keeps the payment id stable
    $res2 = $invoice->saveSalesInvoice([
        'id' => $salesId, 'customerId' => $customerId, 'date' => $date, 'discount' => 0,
        'items' => [['id' => (int)$one("SELECT id FROM sales_invoice_items WHERE invoiceId = {$salesId}")['id'], 'is_service' => 1, 'item_description' => 'خدمات ' . $token, 'quantity' => 1, 'unitPrice' => 1000000]],
        'payments' => [[
            'existing_payment_id' => (int)$origPay['id'], 'type' => 'check', 'transaction_type' => 'receipt', 'amount' => 1000000, 'date' => $date,
            'checkId' => $checkId,
            'checkDetails' => ['checkNumber' => $token . '-1', 'dueDate' => '2031-06-01', 'bankName' => 'ملی'],
        ]],
    ]);
    expect_true(!empty($res2['success']) && (int)$one("SELECT id FROM payments WHERE checkId = {$checkId} AND type = 'check'")['id'] === (int)$origPay['id'], 'unchanged payment keeps its id on invoice re-save');

    // --- Bounce in-hand check ---
    $b = $check->bounceCheck($checkId, '2031-06-02', 'کسری موجودی');
    expect_true(!empty($b['success']), 'bounce in-hand check');
    expect_true(abs($paid('sales_invoices', $salesId)) < 0.01, 'bounce re-opens the invoice balance');
    expect_true($one("SELECT status FROM checks WHERE id = {$checkId}")['status'] === 'bounced', 'check marked bounced');
    $delRes = $payment->delete((int)$origPay['id']);
    expect_true(!empty($delRes['error']), 'cannot delete payment that has a bounce reversal');
    $u = $check->undoBounce($checkId);
    expect_true(!empty($u['success']) && abs($paid('sales_invoices', $salesId) - 1000000) < 0.01, 'undo bounce restores invoice payment');

    // --- Endorse to supplier against a purchase invoice ---
    $pinv = new Invoice($conn, 'purchase');
    $pr = $pinv->savePurchaseInvoice([
        'supplierId' => $supplierId, 'date' => $date, 'discount' => 0,
        'items' => [['is_service' => 1, 'item_description' => 'خدمات خرید ' . $token, 'quantity' => 1, 'unitPrice' => 1000000]],
        'payments' => [['type' => 'endorse_check', 'transaction_type' => 'payment', 'amount' => 1000000, 'date' => $date, 'checkId' => $checkId]],
    ]);
    expect_true(!empty($pr['success']), 'purchase invoice paid by endorsing the check');
    $purchaseId = (int)($pr['id'] ?? 0);
    expect_true($one("SELECT status FROM checks WHERE id = {$checkId}")['status'] === 'endorsed', 'check endorsed');

    $dupFailed = false;
    try {
        $payment->save(['type' => 'endorse_check', 'transaction_type' => 'payment', 'amount' => 1000000, 'date' => $date,
            'person_id' => $supplierId, 'person_type' => 'supplier', 'checkId' => $checkId], false);
    } catch (Exception $e) { $dupFailed = true; }
    expect_true($dupFailed, 'an endorsed check cannot be endorsed twice');

    $amountMismatch = false;
    $r = $check->returnEndorsedCheck($checkId, '2031-06-03', 'عودت');
    expect_true(!empty($r['success']) && abs($paid('purchase_invoices', $purchaseId)) < 0.01, 'return of endorsed check re-opens purchase balance');
    expect_true($one("SELECT status FROM checks WHERE id = {$checkId}")['status'] === 'in_hand', 'returned check back in hand');
    try {
        $payment->save(['type' => 'endorse_check', 'transaction_type' => 'payment', 'amount' => 999, 'date' => $date,
            'person_id' => $supplierId, 'person_type' => 'supplier', 'checkId' => $checkId], false);
    } catch (Exception $e) { $amountMismatch = true; }
    expect_true($amountMismatch, 'endorse amount must equal check amount');

    // Endorse again (standalone) then bounce while endorsed: both sides re-opened
    $payment->save(['type' => 'endorse_check', 'transaction_type' => 'payment', 'amount' => 1000000, 'date' => $date,
        'person_id' => $supplierId, 'person_type' => 'supplier', 'checkId' => $checkId]);
    $b2 = $check->bounceCheck($checkId, '2031-06-05');
    expect_true(!empty($b2['success']), 'bounce an endorsed check');
    $revCount = (int)$one("SELECT COUNT(*) c FROM payments WHERE checkId = {$checkId} AND type = 'check_bounce'")['c'];
    expect_true($revCount === 3, 'reversals: purchase return + endorse + original receipt');
    expect_true(abs($paid('sales_invoices', $salesId)) < 0.01, 'customer owes again after endorsed bounce');
    $u2 = $check->undoBounce($checkId);
    expect_true(!empty($u2['success']) && $one("SELECT status FROM checks WHERE id = {$checkId}")['status'] === 'endorsed', 'undo bounce of endorsed check restores endorsed');
    $remainingRevs = (int)$one("SELECT COUNT(*) c FROM payments WHERE checkId = {$checkId} AND type = 'check_bounce'")['c'];
    expect_true($remainingRevs === 1, 'only the earlier return reversal remains');

    // --- Cash / undo cash on a standalone received check ---
    $p2 = $payment->save(['type' => 'check', 'transaction_type' => 'receipt', 'amount' => 500000, 'date' => $date,
        'person_id' => $customerId, 'person_type' => 'customer',
        'checkDetails' => ['checkNumber' => $token . '-2', 'dueDate' => '2031-07-01', 'bankName' => 'ملت']]);
    $check2 = (int)$one("SELECT id FROM checks WHERE checkNumber = '{$token}-2'")['id'];
    $cc = $check->cashCheck($check2, $accountId, '2031-07-01');
    expect_true(!empty($cc['success']), 'cash received check');
    $bal = fn() => (float)$one("SELECT current_balance FROM accounts WHERE id = {$accountId}")['current_balance'];
    expect_true(abs($bal() - 500000) < 0.01, 'account credited');
    $delCashed = $payment->delete((int)$p2['id']);
    expect_true(!empty($delCashed['error']), 'cannot delete payment of a cashed check');
    $uc = $check->undoCash($check2);
    expect_true(!empty($uc['success']) && abs($bal()) < 0.01, 'undo cash restores account balance');
    $events = (int)$one("SELECT COUNT(*) c FROM check_events WHERE check_id = {$check2}")['c'];
    expect_true($events === 2, 'cash + undo events logged');
    $delOk = $payment->delete((int)$p2['id']);
    expect_true(!empty($delOk['success']), 'payment deletable after undo cash');

    // --- Virtual stock is removed (not turned into stock) on edit/delete ---
    $conn->query("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$entityId}, 1)");
    $productId = (int)$conn->insert_id; $created['products'][] = $productId;
    $vs = $invoice->saveSalesInvoice([
        'customerId' => $customerId, 'date' => $date, 'discount' => 0,
        'items' => [['productId' => $productId, 'dimensions' => '2x3', 'quantity' => 2, 'unitPrice' => 100]],
        'payments' => [],
    ]);
    $vsId = (int)$vs['id'];
    $lineId = (int)$one("SELECT id FROM sales_invoice_items WHERE invoiceId = {$vsId}")['id'];
    expect_true((int)$one("SELECT COUNT(*) c FROM inventory_items WHERE product_id = {$productId} AND is_virtual = 1")['c'] === 2, 'oversell creates flagged virtual items');
    $invoice->saveSalesInvoice([
        'id' => $vsId, 'customerId' => $customerId, 'date' => $date, 'discount' => 0,
        'items' => [['id' => $lineId, 'productId' => $productId, 'dimensions' => '2x3', 'quantity' => 1, 'unitPrice' => 100]],
        'payments' => [],
    ]);
    expect_true((int)$one("SELECT COUNT(*) c FROM inventory_items WHERE product_id = {$productId} AND status = 'in_stock'")['c'] === 0, 'reducing quantity does not create phantom stock');
    $invoice->deleteSalesInvoice($vsId);
    expect_true((int)$one("SELECT COUNT(*) c FROM inventory_items WHERE product_id = {$productId}")['c'] === 0, 'deleting the invoice removes virtual items');

    // --- Quotation conversion allocates stock ---
    $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status) VALUES ({$productId}, {$entityId}, '2x3', 'in_stock')");
    $stockItem = (int)$conn->insert_id;
    $conn->query("INSERT INTO quotations (customerId, date, totalAmount, discount, status, entity_id) VALUES ({$customerId}, '{$date}', 100, 0, 'pending', {$entityId})");
    $qId = (int)$conn->insert_id;
    $conn->query("INSERT INTO quotation_items (quotationId, productId, quantity, unitPrice, dimensions) VALUES ({$qId}, {$productId}, 1, 100, '2x3')");
    $q = new Quotation($conn);
    $qc = $q->convertToInvoice($qId);
    expect_true(!empty($qc['success']), 'quotation converted');
    expect_true($one("SELECT status FROM inventory_items WHERE id = {$stockItem}")['status'] === 'sold', 'conversion allocates the in-stock item');
    $qAgain = $q->convertToInvoice($qId);
    expect_true(!empty($qAgain['error']), 'second conversion rejected');
    if (!empty($qc['invoiceId'])) {
        $invoice->deleteSalesInvoice((int)$qc['invoiceId']);
    }
    $conn->query("DELETE FROM quotations WHERE id = {$qId}");

    // --- Cleanup of the check scenario ---
    $check->returnEndorsedCheck($checkId, '2031-06-06');
    $pinv->deletePurchaseInvoice($purchaseId);
    $conn->query("DELETE FROM payments WHERE checkId = {$checkId}");
    $conn->query("DELETE FROM check_events WHERE check_id IN ({$checkId}, {$check2})");
    $conn->query("DELETE FROM checks WHERE id IN ({$checkId}, {$check2})");
    $conn->query("DELETE FROM payments WHERE person_type IN ('customer','supplier') AND person_id IN ({$customerId}, {$supplierId})");
    $conn->query("DELETE FROM sales_invoice_items WHERE invoiceId = {$salesId}");
    $conn->query("DELETE FROM payments WHERE invoiceType = 'sales' AND invoiceId = {$salesId}");
    $conn->query("DELETE FROM sales_invoices WHERE id = {$salesId}");
} catch (Throwable $e) {
    $failed++;
    echo "[FAIL] exception: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($created['products'] as $id) {
        $conn->query("DELETE FROM inventory_items WHERE product_id = {$id}");
        $conn->query("DELETE FROM products WHERE id = {$id}");
    }
    foreach ($created['customers'] as $id) {
        $conn->query("DELETE FROM payments WHERE person_type = 'customer' AND person_id = {$id}");
        $conn->query("DELETE FROM sales_invoices WHERE customerId = {$id}");
        $conn->query("DELETE FROM customers WHERE id = {$id}");
    }
    foreach ($created['suppliers'] as $id) {
        $conn->query("DELETE FROM payments WHERE person_type = 'supplier' AND person_id = {$id}");
        $conn->query("DELETE FROM purchase_invoices WHERE supplierId = {$id}");
        $conn->query("DELETE FROM suppliers WHERE id = {$id}");
    }
    foreach ($created['accounts'] as $id) {
        $conn->query("DELETE FROM accounts WHERE id = {$id}");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
