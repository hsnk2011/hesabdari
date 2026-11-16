<?php
/**
 * Account closing end to end on a small throw-away entity:
 * as-of inventory, the balance check, profit posting, the period lock and reopen.
 * Run: php tests/period_closing_db_test.php
 *
 * Everything runs inside ONE outer transaction that is always rolled back.
 * The connection wrapper below turns the app's own begin/commit/rollback into
 * no-ops while "frozen", so execute()/reopen() cannot commit anything.
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
require_once $root . '/api/models/ReturnInvoice.php';
require_once $root . '/api/models/ReportModel.php';
require_once $root . '/api/models/PeriodClosing.php';
require_once $root . '/api/models/Partner.php';

class RollbackOnlyMysqli extends mysqli {
    public bool $frozen = false;
    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        return $this->frozen ? true : parent::begin_transaction($flags, $name);
    }
    public function commit(int $flags = 0, ?string $name = null): bool {
        return $this->frozen ? true : parent::commit($flags, $name);
    }
    public function rollback(int $flags = 0, ?string $name = null): bool {
        return $this->frozen ? true : parent::rollback($flags, $name);
    }
    public function realRollback(): void {
        $this->frozen = false;
        parent::rollback();
    }
}

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}
function near($a, $b): bool {
    return abs((float)$a - (float)$b) < 0.01;
}

$conn = new RollbackOnlyMysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');

$one = function (string $sql) use ($conn) {
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
};
$ins = function (string $sql) use ($conn): int {
    $conn->query($sql);
    return (int)$conn->insert_id;
};

$token = 'pc' . substr(bin2hex(random_bytes(5)), 0, 8);
$periodStart = '2026-01-01';
$periodEnd = '2026-03-31';
$backupFile = $root . '/backups/full_' . date('Y-m-d_H-i-s') . '.sql.gz';
$madeBackup = false;

// Constructors may run one-time schema checks; do that before the transaction opens.
new ReturnInvoice($conn);
(new Payment($conn))->ensureSetoffColumn();

$conn->begin_transaction();
$conn->frozen = true;

try {
    // ---------- fixture: capital 1000, buy 3 x 200, sell one for 500 ----------
    $e = $ins("INSERT INTO business_entities (name) VALUES ('{$token}-entity')");
    $_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e]];

    $p1 = $ins("INSERT INTO partners (name, share, entity_id) VALUES ('{$token}-p1', 0.6, {$e})");
    $p2 = $ins("INSERT INTO partners (name, share, entity_id) VALUES ('{$token}-p2', 0.4, {$e})");
    $a1 = $ins("INSERT INTO accounts (name, is_cash, partner_id, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc-p1', 0, {$p1}, {$e}, 0, 0)");
    $a2 = $ins("INSERT INTO accounts (name, is_cash, partner_id, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc-p2', 0, {$p2}, {$e}, 0, 0)");
    $bank = $ins("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance, include_in_cash_position) VALUES ('{$token}-bank', 0, {$e}, 0, 900, 1)");
    $cust = $ins("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cust', '{$token}', {$e})");
    $sup = $ins("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$e})");
    $prd = $ins("INSERT INTO products (name, entity_id, is_active) VALUES ('{$token}-prd', {$e}, 1)");

    // Partner 1 brings 1000 into the bank.
    $ins("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
          VALUES ({$p1}, 'partner', 'receipt', 'cash', 1000, '2026-01-02', {$bank}, {$e})");

    // Purchase of three pieces, paid in cash.
    $pi = $ins("INSERT INTO purchase_invoices (supplierId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$sup}, '2026-01-05', 600, 0, 600, 0, {$e})");
    $pii = $ins("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$pi}, {$prd}, 3, 200, '2x3')");
    $ins("INSERT INTO payments (invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
          VALUES ({$pi}, 'purchase', {$sup}, 'supplier', 'payment', 'cash', 600, '2026-01-05', {$bank}, {$e})");

    // Sale of piece 1 for 500, paid in cash.
    $si = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2026-02-01', 500, 0, 500, 0, {$e})");
    $sii = $ins("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$si}, {$prd}, 1, 500, '2x3')");
    $ins("INSERT INTO payments (invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
          VALUES ({$si}, 'sales', {$cust}, 'customer', 'receipt', 'cash', 500, '2026-02-01', {$bank}, {$e})");

    $i1 = $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, entity_id) VALUES ({$prd}, '2x3', 'sold', {$pii}, {$sii}, {$e})");
    $i2 = $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, entity_id) VALUES ({$prd}, '2x3', 'returned_to_supplier', {$pii}, {$e})");
    $i3 = $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, entity_id) VALUES ({$prd}, '2x3', 'returned_to_supplier', {$pii}, {$e})");

    // Piece 3 goes back to the supplier INSIDE the period (not refunded: supplier owes us 200).
    $retIn = $ins("INSERT INTO return_invoices (type, original_invoice_id, original_invoice_type, person_id, person_type, date, totalAmount, discount, paidAmount, entity_id)
                   VALUES ('purchase_return', {$pi}, 'purchase', {$sup}, 'supplier', '2026-02-10', 200, 0, 0, {$e})");
    $ins("INSERT INTO return_invoice_items (return_invoice_id, productId, quantity, unitPrice, dimensions, original_invoice_item_id, inventory_item_ids) VALUES ({$retIn}, {$prd}, 1, 200, '2x3', {$pii}, '{$i3}')");
    // Piece 2 goes back AFTER the period end: on the closing date it was still in stock.
    $retAfter = $ins("INSERT INTO return_invoices (type, original_invoice_id, original_invoice_type, person_id, person_type, date, totalAmount, discount, paidAmount, entity_id)
                      VALUES ('purchase_return', {$pi}, 'purchase', {$sup}, 'supplier', '2026-04-10', 200, 0, 0, {$e})");
    $ins("INSERT INTO return_invoice_items (return_invoice_id, productId, quantity, unitPrice, dimensions, original_invoice_item_id, inventory_item_ids) VALUES ({$retAfter}, {$prd}, 1, 200, '2x3', {$pii}, '{$i2}')");

    $report = new ReportModel($conn);
    $closing = new PeriodClosing($conn);
    $payload = ['periodStartDate' => $periodStart, 'periodEndDate' => $periodEnd, 'closeType' => 'annual'];

    // ---------- as-of inventory ----------
    expect_true(near($report->getInventoryValueAtDate($periodEnd, $e), 200), 'stock returned to the supplier after the date still counts on that date');
    expect_true(near($report->getInventoryValueAtDate('2026-04-30', $e), 0), 'after its return date that piece is no longer in stock');
    expect_true(near($report->getInventoryValueAtDate('2026-01-31', $e), 600), 'before any sale or return all three pieces are in stock');
    // Old data: before v2.6.0 the return list saved wrong rug ids on purchase returns
    // (it looked them up as if they were sales returns). The value must not depend on them.
    $conn->query("UPDATE return_invoice_items SET inventory_item_ids = '{$i1}' WHERE return_invoice_id = {$retAfter}");
    expect_true(near($report->getInventoryValueAtDate($periodEnd, $e), 200), 'a wrong stored rug id on a purchase return does not change the stock value');
    $conn->query("UPDATE return_invoice_items SET inventory_item_ids = NULL WHERE return_invoice_id = {$retAfter}");
    expect_true(near($report->getInventoryValueAtDate($periodEnd, $e), 200), 'a purchase return without stored rug ids still counts');
    $conn->query("UPDATE return_invoice_items SET inventory_item_ids = '{$i2}' WHERE return_invoice_id = {$retAfter}");

    // ---------- calculation method ----------
    $prev = $closing->preview($payload);
    expect_true(($prev['calculationMethod'] ?? '') === 'cogs', 'closing defaults to the cost-of-goods method');
    $std = $closing->preview($payload + ['calculationMethod' => 'standard']);
    expect_true(isset($std['error']), 'closing rejects the sales-minus-purchases method');
    expect_true(near($prev['profitLoss']['netOperatingProfit'] ?? 0, 300), 'period profit is 300');

    // ---------- balance check is a real check ----------
    $bs = $prev['closingSnapshot']['balanceSheet'] ?? [];
    expect_true(near($bs['assets']['total'] ?? 0, 1100) && near($bs['liabilities']['total'] ?? 0, -200), 'net assets are 1300 (bank 900 + stock 200 + supplier owes 200)');
    expect_true(near($bs['difference'] ?? 99, 0) && !empty($bs['isBalanced']), 'consistent books show no difference');

    $orphan = $ins("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
                    VALUES (2000000000, 'customer', 'receipt', 'cash', 77, '2026-03-01', {$bank}, {$e})");
    $bad = $closing->preview($payload);
    $badBs = $bad['closingSnapshot']['balanceSheet'] ?? [];
    expect_true(near($badBs['difference'] ?? 0, 77) && empty($badBs['isBalanced']), 'money in the bank with no source shows up as a difference of 77');
    expect_true(
        (bool)array_filter($bad['warnings'] ?? [], fn($w) => str_contains($w['message'] ?? '', 'اختلاف ترازنامه')),
        'the difference is reported as a warning'
    );
    $conn->query("DELETE FROM payments WHERE id = {$orphan}");

    // Prepayments: paid before the closing date on an invoice dated after it.
    // On the closing date the customer has a credit of 50 and we have a credit of 30 with the supplier.
    $siLater = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2026-04-05', 100, 0, 50, 0, {$e})");
    $preIn = $ins("INSERT INTO payments (invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
                   VALUES ({$siLater}, 'sales', {$cust}, 'customer', 'receipt', 'cash', 50, '2026-03-20', {$bank}, {$e})");
    $piLater = $ins("INSERT INTO purchase_invoices (supplierId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$sup}, '2026-04-02', 80, 0, 30, 0, {$e})");
    $preOut = $ins("INSERT INTO payments (invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
                    VALUES ({$piLater}, 'purchase', {$sup}, 'supplier', 'payment', 'cash', 30, '2026-03-25', {$bank}, {$e})");
    $custAt = array_column($report->getCustomerReceivables($periodEnd, $e)['customers'], 'receivable', 'id');
    $supAt = array_column($report->getSupplierDebts($periodEnd, $e)['suppliers'], 'debt', 'id');
    expect_true(near($custAt[$cust] ?? 0, -50), 'a customer prepayment on a later invoice is a credit of 50 on the closing date');
    expect_true(near($supAt[$sup] ?? 0, -230), 'a supplier prepayment on a later invoice adds to what the supplier owes us (200 + 30)');
    $pre = $closing->preview($payload);
    expect_true(near($pre['closingSnapshot']['balanceSheet']['difference'] ?? 99, 0), 'prepayments on later invoices keep the balance check at 0');
    $conn->query("DELETE FROM payments WHERE id IN ({$preIn}, {$preOut})");
    $conn->query("DELETE FROM sales_invoices WHERE id = {$siLater}");
    $conn->query("DELETE FROM purchase_invoices WHERE id = {$piLater}");

    // ---------- first closing may not start after the first document ----------
    $late = $closing->preview(['periodStartDate' => '2026-01-03'] + $payload);
    expect_true(isset($late['error']) && str_contains($late['error'], 'اولین سند'), 'first closing refuses a start after the first document (2026-01-02)');

    // ---------- execute refuses with the real reason ----------
    $madeBackup = (bool)file_put_contents($backupFile, 'test');
    $orphan2 = $ins("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
                     VALUES (2000000000, 'customer', 'receipt', 'cash', 5000, '2026-03-01', {$bank}, {$e})");
    $refused = $closing->execute($payload + ['backupFilename' => basename($backupFile)]);
    expect_true(isset($refused['error']) && str_contains($refused['error'], 'اختلاف ترازنامه'), 'a refused closing says why (balance difference)');
    $conn->query("DELETE FROM payments WHERE id = {$orphan2}");

    // ---------- an old backup file is not accepted ----------
    $oldBackup = $root . '/backups/full_2020-01-01_00-00-00.sql.gz';
    $madeOld = !is_file($oldBackup) && file_put_contents($oldBackup, 'old') !== false;
    if ($madeOld) {
        touch($oldBackup, time() - 2 * 3600);
        $old = $closing->execute($payload + ['backupFilename' => basename($oldBackup)]);
        expect_true(isset($old['error']) && str_contains($old['error'], 'قدیمی'), 'a backup older than an hour is refused');
        @unlink($oldBackup);
    }

    // ---------- execute with profit posting ----------
    $res = $closing->execute($payload + ['postProfitToPartners' => true, 'backupFilename' => basename($backupFile)]);
    expect_true(!empty($res['success']), 'closing executes' . (isset($res['error']) ? ' — ' . $res['error'] : ''));
    $cid = (int)($res['id'] ?? 0);

    $posts = [];
    $r = $conn->query("SELECT * FROM payments WHERE entity_id = {$e} AND description LIKE 'CLOSING:{$cid}|%' ORDER BY person_id");
    while ($row = $r->fetch_assoc()) { $posts[] = $row; }
    expect_true(count($posts) === 2 && near($posts[0]['amount'], 180) && near($posts[1]['amount'], 120), 'profit 300 is posted 180 / 120');
    expect_true(count($posts) === 2 && $posts[0]['date'] === $periodEnd && $posts[1]['date'] === $periodEnd, 'profit rows are dated on the closing date');
    expect_true(count($posts) === 2 && $posts[0]['account_id'] === null && $posts[1]['account_id'] === null, 'profit rows are not booked on a bank/personal account');

    $accModel = new Account($conn);
    $ledgerOk = true;
    foreach ([$a1, $a2] as $accId) {
        $led = $accModel->getLedger($accId);
        $run = (float)$led['account']['initial_balance'];
        foreach ($led['transactions'] as $tx) {
            $run += (str_ends_with($tx['source'], '_out') || $tx['source'] === 'expense') ? -(float)$tx['amount'] : (float)$tx['amount'];
        }
        $ledgerOk = $ledgerOk && near($run, $led['account']['current_balance']);
    }
    expect_true($ledgerOk, 'partner personal accounts: ledger still equals the stored balance');

    $next = $report->getProfitLossReport(['startDate' => '2026-04-01', 'endDate' => '2026-04-30', 'entityId' => $e, 'calculationMethod' => 'cogs']);
    $opening = [];
    foreach ($next['partners'] ?? [] as $pr) { $opening[(int)$pr['id']] = (float)($pr['capital']['opening'] ?? 0); }
    expect_true(near($opening[$p1] ?? 0, 1180) && near($opening[$p2] ?? 0, 120), 'next period opens with capital + posted profit (1180 / 120)');

    // After a closing, a new partner with a share would never get profit: refused.
    $pm = new Partner($conn);
    $newWithShare = $pm->save(['name' => "{$token}-p4", 'share' => 0.1], false);
    expect_true(!empty($newWithShare['error']) && (int)($newWithShare['statusCode'] ?? 0) === 409, 'after a closing, a new partner with a share is refused');
    $newNoShare = $pm->save(['name' => "{$token}-p5", 'share' => 0], false);
    expect_true(!empty($newNoShare['success']), 'after a closing, a new partner with share 0 is allowed');
    if (!empty($newNoShare['id'])) {
        $conn->query("DELETE FROM accounts WHERE partner_id = " . (int)$newNoShare['id']);
        $conn->query("DELETE FROM partners WHERE id = " . (int)$newNoShare['id']);
    }

    // After a closing the shares come from the share history. A partner who is not in it
    // (no share, e.g. left in a restructuring) still has capital in the business.
    $p3 = $ins("INSERT INTO partners (name, share, entity_id) VALUES ('{$token}-p3', 0, {$e})");
    $p3pay = $ins("INSERT INTO payments (person_id, person_type, transaction_type, type, amount, date, account_id, entity_id)
                   VALUES ({$p3}, 'partner', 'receipt', 'cash', 40, '2026-04-15', {$bank}, {$e})");
    $z = $closing->preview(['periodStartDate' => '2026-04-01', 'periodEndDate' => '2026-04-30', 'closeType' => 'annual']);
    expect_true(near($z['closingSnapshot']['balanceSheet']['difference'] ?? 99, 0), 'capital of a partner with no share still counts in the balance check (' . ($z['closingSnapshot']['balanceSheet']['difference'] ?? ($z['error'] ?? '?')) . ')');
    $conn->query("DELETE FROM payments WHERE id = {$p3pay}");
    $conn->query("DELETE FROM partners WHERE id = {$p3}");

    // ---------- posted rows and the closed period are protected ----------
    if ($posts) {
        $del = (new Payment($conn))->delete((int)$posts[0]['id']);
        expect_true(!empty($del['error']), 'a posted profit row cannot be deleted from the payments screen');
        $editBlocked = false;
        try {
            (new Payment($conn))->save([
                'id' => (int)$posts[0]['id'], 'type' => 'cash', 'transaction_type' => 'receipt', 'amount' => 1,
                'date' => '2026-06-01', 'person_id' => $p1, 'person_type' => 'partner', 'account_id' => $a1,
            ], false);
        } catch (Throwable $ex) { $editBlocked = true; }
        expect_true($editBlocked, 'a posted profit row cannot be edited');
    }
    $delRet = (new ReturnInvoice($conn))->deletePurchaseReturn($retIn);
    expect_true(!empty($delRet['error']) && (int)($delRet['statusCode'] ?? 0) === 403, 'a return invoice inside the closed period cannot be deleted');
    expect_true($one("SELECT status FROM inventory_items WHERE id = {$i3}")['status'] === 'returned_to_supplier', 'its stock stays returned');

    // ---------- reopen ----------
    $ro = $closing->reopen(['id' => $cid]);
    expect_true(!empty($ro['success']), 'closing can be reopened');
    expect_true((int)$one("SELECT COUNT(*) c FROM payments WHERE entity_id = {$e} AND description LIKE 'CLOSING:%'")['c'] === 0, 'reopen removes the posted profit rows');
    expect_true((int)$one("SELECT COUNT(*) c FROM partner_share_history WHERE entity_id = {$e}")['c'] === 0, 'reopen clears the share history of the first closing');
    $delRet2 = (new ReturnInvoice($conn))->deletePurchaseReturn($retAfter);
    expect_true(!empty($delRet2['success']), 'after reopen, return invoices can be deleted again');

    // ---------- rugs with no purchase price are flagged before closing ----------
    // An order booked with price 0: one piece sold in the period, one still in stock.
    $piZero = $ins("INSERT INTO purchase_invoices (supplierId, date, totalAmount, discount, paidAmount, is_consignment, entity_id, delivery_status) VALUES ({$sup}, '2026-02-15', 0, 0, 0, 0, {$e}, 'preparing')");
    $piiZero = $ins("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$piZero}, {$prd}, 2, 0, '1x2')");
    $siZero = $ins("INSERT INTO sales_invoices (customerId, date, totalAmount, discount, paidAmount, is_consignment, entity_id) VALUES ({$cust}, '2026-03-10', 400, 0, 0, 0, {$e})");
    $siiZero = $ins("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES ({$siZero}, {$prd}, 1, 400, '1x2')");
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, sales_invoice_item_id, entity_id) VALUES ({$prd}, '1x2', 'sold', {$piiZero}, {$siiZero}, {$e})");
    $ins("INSERT INTO inventory_items (product_id, dimensions, status, purchase_invoice_item_id, entity_id) VALUES ({$prd}, '1x2', 'in_stock', {$piiZero}, {$e})");
    $zq = $closing->preview($payload);
    $dq = $zq['profitLoss']['dataQuality'] ?? [];
    expect_true((int)($dq['zeroCostSoldItems'] ?? 0) === 1 && near($dq['zeroCostSaleValue'] ?? 0, 400), 'data quality counts the rug sold with no purchase price');
    expect_true((int)($dq['zeroCostStockItems'] ?? 0) === 1, 'data quality counts the zero-price rug still in stock');
    expect_true(in_array($piZero, array_map('intval', $dq['zeroCostPurchaseInvoices'] ?? []), true), 'data quality names the purchase invoice to price');
    $msgs = implode(' | ', array_column($zq['warnings'] ?? [], 'message'));
    expect_true(str_contains($msgs, 'بهای صفر') && str_contains($msgs, '#' . $piZero), 'closing preview warns about rugs sold with no purchase price and names the invoice');
} catch (Throwable $ex) {
    $failed++;
    echo "[FAIL] exception: " . $ex->getMessage() . " @ " . basename($ex->getFile()) . ':' . $ex->getLine() . "\n";
} finally {
    $conn->realRollback();
    if ($madeBackup) {
        @unlink($backupFile);
    }
}

expect_true((int)$one("SELECT COUNT(*) c FROM business_entities WHERE name = '{$token}-entity'")['c'] === 0, 'everything was rolled back');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
