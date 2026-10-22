<?php
/**
 * دارایی‌ها: purchase/sale documents with payments, moving-average gain,
 * oversell guard, person balances, statement rows, purchase-invoice conversion,
 * tgju price parser. Run: php tests/assets_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/Invoice.php';
require_once $root . '/api/models/Asset.php';
require_once $root . '/api/models/ReportModel.php';
require_once $root . '/api/core/AssetPriceService.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => null];
$token = 'ast' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$model = new Asset($conn);
$docIds = []; $assetIds = [];

try {
    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cus', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 0, 0)");
    $accountId = (int)$conn->insert_id;
    $balance = fn() => (float)$one("SELECT current_balance b FROM accounts WHERE id = {$accountId}")['b'];
    $bookBase = Asset::bookValue($conn, $entityId, '2031-12-31');
    $gainBase = Asset::realizedGain($conn, $entityId, '2031-01-01', '2031-12-31');

    // 1) purchase: 1000 units @100, cash 60k + new check 40k
    $p1 = $model->saveDocument([
        'doc_type' => 'purchase', 'date' => '2031-01-10', 'person_type' => 'supplier', 'person_id' => $supplierId,
        'items' => [['new_asset' => ['name' => "{$token} دلار", 'category' => 'ارز', 'unit' => 'دلار', 'price_key' => 'price_dollar_rl'], 'quantity' => '1,000', 'unit_price' => 100]],
        'payments' => [
            ['type' => 'cash', 'amount' => 60000, 'date' => '2031-01-10', 'account_id' => $accountId],
            ['type' => 'check', 'amount' => 40000, 'date' => '2031-01-10', 'checkDetails' => ['checkNumber' => $token . '1', 'dueDate' => '2031-03-01', 'bankName' => 'ملت', 'issuerName' => 'ما']],
        ],
    ]);
    expect_true(!empty($p1['success']), 'purchase document saved' . (!empty($p1['error']) ? ' — ' . $p1['error'] : ''));
    $docIds[] = (int)($p1['id'] ?? 0);
    $doc1 = $one("SELECT * FROM asset_documents WHERE id = " . (int)$p1['id']);
    expect_true(abs((float)$doc1['total_amount'] - 100000) < 0.01 && abs((float)$doc1['paidAmount'] - 100000) < 0.01, 'document total and paidAmount = 100,000');
    expect_true(abs($balance() + 60000) < 0.01, 'cash payment reduced the account by 60,000');
    $chk = $one("SELECT invoiceType, invoiceId, type, status FROM checks WHERE checkNumber = '{$token}1'");
    expect_true($chk && $chk['invoiceType'] === 'asset_purchase' && (int)$chk['invoiceId'] === (int)$p1['id'] && $chk['type'] === 'payable', 'issued check linked to the asset document');
    $assetId = (int)$one("SELECT id FROM assets WHERE name = '{$token} دلار'")['id'];
    $assetIds[] = $assetId;

    // 2) credit purchase 500 @130 from the supplier
    $p2 = $model->saveDocument(['doc_type' => 'purchase', 'date' => '2031-01-20', 'person_type' => 'supplier', 'person_id' => $supplierId,
        'items' => [['asset_id' => $assetId, 'quantity' => 500, 'unit_price' => 130]], 'payments' => []]);
    $docIds[] = (int)($p2['id'] ?? 0);
    $net = Asset::personNet($conn, $entityId, 'supplier', [$supplierId]);
    expect_true(abs(($net[$supplierId] ?? 0) + 65000) < 0.01, 'credit purchase: we owe the supplier 65,000 (' . ($net[$supplierId] ?? 'none') . ')');
    $debts = (new ReportModel($conn))->getSupplierDebts('2031-12-31', $entityId);
    $row = array_values(array_filter($debts['suppliers'], fn($s) => (int)$s['id'] === $supplierId))[0] ?? null;
    expect_true($row && abs((float)$row['debt'] - 65000) < 0.01, 'supplier debt report includes the asset document');

    // 3) sale 600 @150 to the customer, received in cash
    $s1 = $model->saveDocument(['doc_type' => 'sale', 'date' => '2031-02-01', 'person_type' => 'customer', 'person_id' => $customerId,
        'items' => [['asset_id' => $assetId, 'quantity' => 600, 'unit_price' => 150]],
        'payments' => [['type' => 'cash', 'amount' => 90000, 'date' => '2031-02-01', 'account_id' => $accountId]]]);
    expect_true(!empty($s1['success']), 'sale document saved' . (!empty($s1['error']) ? ' — ' . $s1['error'] : ''));
    $docIds[] = (int)($s1['id'] ?? 0);
    $sale = $one("SELECT cost_amount, gain_amount FROM asset_document_items WHERE document_id = " . (int)$s1['id']);
    // avg cost = 165,000 / 1,500 = 110 → cost 66,000, gain 24,000
    expect_true(abs((float)$sale['cost_amount'] - 66000) < 0.01 && abs((float)$sale['gain_amount'] - 24000) < 0.01, 'moving-average cost 66,000 and gain 24,000');
    expect_true(abs(Asset::realizedGain($conn, $entityId, '2031-01-01', '2031-12-31') - $gainBase - 24000) < 0.01, 'realized gain in P&L period = 24,000');
    expect_true(abs($balance() - 30000) < 0.01, 'receipt added 90,000 to the account');
    expect_true(abs(Asset::bookValue($conn, $entityId, '2031-12-31') - $bookBase - 99000) < 0.01, 'book value after sale = 99,000');
    $listed = array_values(array_filter($model->listAssets(), fn($a) => (int)$a['id'] === $assetId))[0] ?? null;
    expect_true($listed && abs($listed['quantity'] - 900) < 0.0001 && abs($listed['avg_cost'] - 110) < 0.01, 'register shows 900 held at avg 110');

    // 4) oversell refused
    $bad = $model->saveDocument(['doc_type' => 'sale', 'date' => '2031-02-05', 'person_type' => 'customer', 'person_id' => $customerId,
        'items' => [['asset_id' => $assetId, 'quantity' => 1000, 'unit_price' => 150]], 'payments' => []]);
    expect_true(!empty($bad['error']) && (int)$one("SELECT COUNT(*) n FROM asset_documents WHERE date = '2031-02-05' AND person_id = {$customerId}")['n'] === 0, 'selling more than held is refused');

    // 5) deleting the first purchase would make the sale exceed holdings → refused, nothing changes
    $del = $model->deleteDocument((int)$p1['id']);
    expect_true(!empty($del['error']) && $one("SELECT id FROM asset_documents WHERE id = " . (int)$p1['id']), 'deleting a purchase needed by a later sale is refused');

    // 6) without counterparty the payments must cover the total
    $noParty = $model->saveDocument(['doc_type' => 'purchase', 'date' => '2031-02-10',
        'items' => [['asset_id' => $assetId, 'quantity' => 1, 'unit_price' => 100]], 'payments' => []]);
    expect_true(!empty($noParty['error']), 'credit document without a counterparty is refused');

    // 6b) opening balance: no counterparty, no payment, account untouched
    $balOpen = $balance();
    $op = $model->saveDocument(['doc_type' => 'purchase', 'date' => '2031-01-01', 'is_opening' => 1,
        'items' => [['new_asset' => ['name' => "{$token} میز", 'category' => 'اثاثیه'], 'quantity' => 1, 'unit_price' => 40000]]]);
    $docIds[] = (int)($op['id'] ?? 0);
    $assetIds[] = (int)($one("SELECT id FROM assets WHERE name = '{$token} میز'")['id'] ?? 0);
    expect_true(!empty($op['success']) && abs($balance() - $balOpen) < 0.01, 'opening-balance document needs no payment and moves no money');
    $opBad = $model->saveDocument(['doc_type' => 'purchase', 'date' => '2031-01-01', 'is_opening' => 1,
        'items' => [['asset_id' => $assetId, 'quantity' => 1, 'unit_price' => 1]],
        'payments' => [['type' => 'cash', 'amount' => 1, 'date' => '2031-01-01', 'account_id' => $accountId]]]);
    expect_true(!empty($opBad['error']), 'opening-balance document with a payment is refused');

    // 7) statement rows for the customer
    $rows = Asset::statementRows($conn, $entityId, 'customer', $customerId, '2031-01-01', '2031-12-31');
    $sum = array_sum(array_column($rows, 'signed'));
    expect_true(count($rows) === 2 && abs($sum) < 0.01, 'customer statement: sale document and receipt net to zero');

    // 8) delete the sale: payment removed, account restored, gain gone
    $ds = $model->deleteDocument((int)$s1['id']);
    expect_true(!empty($ds['success']) && abs($balance() + 60000) < 0.01, 'sale deleted and its receipt reversed' . (!empty($ds['error']) ? ' — ' . $ds['error'] : ''));
    expect_true(abs(Asset::realizedGain($conn, $entityId, '2031-01-01', '2031-12-31') - $gainBase) < 0.01, 'no realized gain after deleting the sale');

    // 9) purchase invoice → asset document
    $conn->query("INSERT INTO products (name, entity_id) VALUES ('{$token} سکه', {$entityId})");
    $productId = (int)$conn->insert_id;
    $inv = new Invoice($conn, 'purchase');
    $pi = $inv->savePurchaseInvoice(['supplierId' => $supplierId, 'date' => '2031-03-01',
        'items' => [['productId' => $productId, 'dimensions' => '1x1', 'quantity' => 1, 'unitPrice' => 50000]],
        'payments' => [['type' => 'cash', 'amount' => 50000, 'date' => '2031-03-01', 'account_id' => $accountId, 'transaction_type' => 'payment']]]);
    expect_true(!empty($pi['success']), 'fixture purchase invoice created' . (!empty($pi['error']) ? ' — ' . $pi['error'] : ''));
    $piId = (int)($pi['id'] ?? 0);
    $itemId = (int)$one("SELECT id FROM purchase_invoice_items WHERE invoiceId = {$piId}")['id'];
    $balBefore = $balance();
    $conv = $model->convertPurchaseInvoice($piId, [['item_id' => $itemId, 'quantity' => 2]]);
    expect_true(!empty($conv['success']), 'purchase invoice converted' . (!empty($conv['error']) ? ' — ' . $conv['error'] : ''));
    $newDoc = (int)($conv['id'] ?? 0);
    $docIds[] = $newDoc;
    expect_true(!$one("SELECT id FROM purchase_invoices WHERE id = {$piId}") && !$one("SELECT id FROM products WHERE id = {$productId}"), 'invoice and its product removed');
    $pay = $one("SELECT invoiceType, invoiceId FROM payments WHERE invoiceId = {$newDoc} AND invoiceType = 'asset_purchase'");
    expect_true((bool)$pay && abs($balance() - $balBefore) < 0.01, 'payment re-pointed without touching the account');
    $coin = $one("SELECT a.id, a.price_key, a.category, i.quantity, i.amount FROM asset_document_items i JOIN assets a ON a.id = i.asset_id WHERE i.document_id = {$newDoc}");
    $assetIds[] = (int)$coin['id'];
    expect_true($coin['price_key'] === 'sekee' && $coin['category'] === 'طلا و سکه' && abs((float)$coin['quantity'] - 2) < 0.0001 && abs((float)$coin['amount'] - 50000) < 0.01, 'coin asset guessed (sekee), quantity 2, amount kept');

    // 10) tgju parser
    $html = '<tr data-market-row="price_dollar_rl" data-title="<div class=\'x\'>tip</div>" data-price="1,234,500"><th>دلار</th><td class="nf">1,234,500</td></tr>'
          . '<tr data-market-row="sekee"><th>سکه</th><td class="">2,665,650,000</td></tr>';
    $parsed = AssetPriceService::parsePage($html, ['price_dollar_rl', 'sekee', 'geram18']);
    expect_true(($parsed['price_dollar_rl'] ?? 0) == 1234500 && ($parsed['sekee'] ?? 0) == 2665650000 && !isset($parsed['geram18']), 'tgju rows parsed (attribute and cell fallback)');
} catch (Throwable $e) {
    expect_true(false, 'unexpected exception: ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    foreach (array_reverse(array_filter($docIds)) as $d) {
        $pids = $conn->query("SELECT id, checkId FROM payments WHERE invoiceType IN ('asset_purchase','asset_sale') AND invoiceId = {$d}");
        while ($pids && ($p = $pids->fetch_assoc())) {
            $conn->query("DELETE FROM payments WHERE id = " . (int)$p['id']);
            if (!empty($p['checkId'])) { $conn->query("DELETE FROM check_events WHERE check_id = " . (int)$p['checkId']); $conn->query("DELETE FROM checks WHERE id = " . (int)$p['checkId']); }
        }
        $conn->query("DELETE FROM asset_documents WHERE id = {$d}");
    }
    foreach ($assetIds as $a) { if ($a) $conn->query("DELETE FROM assets WHERE id = {$a}"); }
    if (!empty($accountId)) { $conn->query("DELETE FROM payments WHERE account_id = {$accountId}"); $conn->query("DELETE FROM accounts WHERE id = {$accountId}"); }
    if (!empty($supplierId)) { $conn->query("DELETE FROM purchase_invoices WHERE supplierId = {$supplierId}"); $conn->query("DELETE FROM suppliers WHERE id = {$supplierId}"); }
    if (!empty($customerId)) $conn->query("DELETE FROM customers WHERE id = {$customerId}");
    $conn->query("DELETE FROM products WHERE name LIKE '{$token}%'");
    $conn->query("DELETE FROM activity_log WHERE description LIKE '%دارایی #%' AND description LIKE '%" . date('Y') . "%' AND 1 = 0");
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
