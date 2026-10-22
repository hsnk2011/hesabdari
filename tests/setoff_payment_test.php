<?php
/**
 * تهاتر / پرداخت به جای (setoff):
 * مشتری فاکتور فروش را با واریز مستقیم به تأمین‌کننده تسویه می‌کند — بدون حرکت بانک.
 * Run: php tests/setoff_payment_test.php
 * Uses a DB transaction that is always rolled back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Payment.php';
require_once $root . '/api/models/Account.php';

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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$entityId = 1;
$_SESSION['current_entity_id'] = $entityId;
$token = 'sof' . substr(bin2hex(random_bytes(4)), 0, 8);
$date = '2031-06-15';
$amount = 1500000.0;

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 0, 5000000)");
    $accountId = (int)$conn->insert_id;
    $balBefore = 5000000.0;

    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cust', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;

    $conn->query("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, tax_amount, paidAmount, is_consignment)
                  VALUES ({$entityId}, {$customerId}, '{$date}', 2000000, 0, 0, 0, 0)");
    $salesId = (int)$conn->insert_id;

    $conn->query("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, tax_amount, paidAmount, is_consignment)
                  VALUES ({$entityId}, {$supplierId}, '{$date}', 3000000, 0, 0, 0, 0)");
    $purchaseId = (int)$conn->insert_id;

    $payment = new Payment($conn);
    $payment->ensureSetoffColumn();

    $blocked = false;
    try {
        $payment->save([
            'type' => 'setoff',
            'transaction_type' => 'receipt',
            'amount' => $amount,
            'date' => $date,
            'person_id' => $customerId,
            'person_type' => 'customer',
            'invoiceId' => $salesId,
            'invoiceType' => 'sales',
        ], false);
    } catch (Exception $e) {
        $blocked = strpos($e->getMessage(), 'تهاتر') !== false;
    }
    expect_true($blocked, 'normal save() rejects type=setoff');

    $res = $payment->saveSetoff([
        'sales_invoice_id' => $salesId,
        'customer_id' => $customerId,
        'amount' => $amount,
        'date' => $date,
        'description' => 'test setoff ' . $token,
        'setoff_person_type' => 'supplier',
        'setoff_person_id' => $supplierId,
        'setoff_invoice_id' => $purchaseId,
    ], false);
    expect_true(!empty($res['success']) && !empty($res['setoff_group_id']), 'saveSetoff creates pair');

    $groupId = $res['setoff_group_id'] ?? '';
    $st = $conn->prepare("SELECT * FROM payments WHERE entity_id = ? AND setoff_group_id = ? ORDER BY id");
    $st->bind_param('is', $entityId, $groupId);
    $st->execute();
    $legs = db_stmt_to_assoc_array($st);
    expect_true(count($legs) === 2, 'exactly two setoff legs');

    $salesLeg = null;
    $purchaseLeg = null;
    foreach ($legs as $leg) {
        expect_true($leg['type'] === 'setoff', 'leg type is setoff');
        expect_true($leg['account_id'] === null || $leg['account_id'] === '', 'leg has null account_id');
        if ($leg['invoiceType'] === 'sales') $salesLeg = $leg;
        if ($leg['invoiceType'] === 'purchase') $purchaseLeg = $leg;
    }
    expect_true($salesLeg && $salesLeg['transaction_type'] === 'receipt' && (int)$salesLeg['person_id'] === $customerId, 'sales leg is customer receipt');
    expect_true($purchaseLeg && $purchaseLeg['transaction_type'] === 'payment' && (int)$purchaseLeg['person_id'] === $supplierId, 'purchase leg is supplier payment');

    $q = function (string $sql) use ($conn): array {
        $r = $conn->query($sql);
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    };

    $si = $q("SELECT paidAmount FROM sales_invoices WHERE id = {$salesId}")[0];
    $pi = $q("SELECT paidAmount FROM purchase_invoices WHERE id = {$purchaseId}")[0];
    expect_true(abs((float)$si['paidAmount'] - $amount) < 0.01, 'sales paidAmount updated');
    expect_true(abs((float)$pi['paidAmount'] - $amount) < 0.01, 'purchase paidAmount updated');

    $acc = $q("SELECT current_balance FROM accounts WHERE id = {$accountId}")[0];
    expect_true(abs((float)$acc['current_balance'] - $balBefore) < 0.01, 'bank balance unchanged');

    // Cash flow must not count setoff as cash
    $cf = $q(
        "SELECT COALESCE(SUM(amount),0) AS t FROM payments
         WHERE entity_id = {$entityId} AND setoff_group_id = '" . $conn->real_escape_string($groupId) . "'
           AND type = 'cash'"
    )[0];
    expect_true((float)$cf['t'] === 0.0, 'no cash-type rows in setoff group');

    $del = $payment->delete((int)$salesLeg['id'], false, false);
    expect_true(!empty($del['success']), 'delete one leg removes group');

    $left = $q(
        "SELECT COUNT(*) AS c FROM payments WHERE entity_id = {$entityId} AND setoff_group_id = '" . $conn->real_escape_string($groupId) . "'"
    )[0];
    expect_true((int)$left['c'] === 0, 'both legs deleted');

    $si2 = $q("SELECT paidAmount FROM sales_invoices WHERE id = {$salesId}")[0];
    $pi2 = $q("SELECT paidAmount FROM purchase_invoices WHERE id = {$purchaseId}")[0];
    expect_true(abs((float)$si2['paidAmount']) < 0.01, 'sales paidAmount reverted');
    expect_true(abs((float)$pi2['paidAmount']) < 0.01, 'purchase paidAmount reverted');

    // Contact destination without purchase invoice
    $conn->query("INSERT INTO contacts (name, entity_id) VALUES ('{$token}-ct', {$entityId})");
    $contactId = (int)$conn->insert_id;
    $res2 = $payment->saveSetoff([
        'sales_invoice_id' => $salesId,
        'customer_id' => $customerId,
        'amount' => 100000,
        'date' => $date,
        'description' => 'to contact',
        'setoff_person_type' => 'contact',
        'setoff_person_id' => $contactId,
    ], false);
    expect_true(!empty($res2['success']), 'setoff to contact without purchase invoice');
    $g2 = $res2['setoff_group_id'];
    $legs2 = $q(
        "SELECT * FROM payments WHERE setoff_group_id = '" . $conn->real_escape_string($g2) . "'"
    );
    $cpLeg = null;
    foreach ($legs2 as $l) {
        if ($l['person_type'] === 'contact') $cpLeg = $l;
    }
    expect_true($cpLeg && ($cpLeg['invoiceId'] === null || $cpLeg['invoiceId'] === ''), 'contact leg has no invoice');

    $acc2 = $q("SELECT current_balance FROM accounts WHERE id = {$accountId}")[0];
    expect_true(abs((float)$acc2['current_balance'] - $balBefore) < 0.01, 'bank still unchanged after contact setoff');

} catch (Throwable $e) {
    echo "[FAIL] exception: " . $e->getMessage() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
