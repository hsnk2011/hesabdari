<?php
/**
 * Miscellaneous (person_type=other) cash receipts/payments:
 * bank interest, fee refunds, non-customer counterparties.
 * Run: php tests/other_party_payment_test.php
 * Uses a DB transaction that is always rolled back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Payment.php';
require_once $root . '/api/models/Transaction.php';
require_once $root . '/api/models/ReportModel.php';

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
$token = 'misc' . substr(bin2hex(random_bytes(5)), 0, 8);
$date = '2031-03-17';
$incomeAmount = 250000.0;
$outgoingAmount = 40000.0;

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 0, 0)");
    $accountId = (int)$conn->insert_id;

    $payment = new Payment($conn);

    $noPersonFailed = false;
    try {
        $payment->save([
            'type' => 'cash',
            'transaction_type' => 'receipt',
            'amount' => 1000,
            'date' => $date,
            'description' => $token,
            'account_id' => $accountId,
        ], false);
    } catch (Exception $e) {
        $noPersonFailed = strpos($e->getMessage(), 'شخص') !== false || strpos($e->getMessage(), 'فاکتور') !== false;
    }
    expect_true($noPersonFailed, 'cash receipt without person still rejected');

    $emptyDescFailed = false;
    try {
        $payment->save([
            'type' => 'cash',
            'transaction_type' => 'receipt',
            'amount' => $incomeAmount,
            'date' => $date,
            'description' => '   ',
            'account_id' => $accountId,
            'person_type' => 'other',
        ], false);
    } catch (Exception $e) {
        $emptyDescFailed = strpos($e->getMessage(), 'شرح') !== false;
    }
    expect_true($emptyDescFailed, 'other receipt without description is rejected');

    $checkFailed = false;
    try {
        $payment->save([
            'type' => 'check',
            'transaction_type' => 'receipt',
            'amount' => $incomeAmount,
            'date' => $date,
            'description' => 'سود بانکی ' . $token,
            'person_type' => 'other',
            'checkDetails' => [
                'checkNumber' => $token,
                'dueDate' => $date,
                'bankName' => 'Test',
                'issuerName' => 'Bank',
            ],
        ], false);
    } catch (Exception $e) {
        $checkFailed = strpos($e->getMessage(), 'نقدی') !== false;
    }
    expect_true($checkFailed, 'other receipt cannot be a check');

    $saved = $payment->save([
        'type' => 'cash',
        'transaction_type' => 'receipt',
        'amount' => $incomeAmount,
        'date' => $date,
        'description' => 'سود بانکی ' . $token,
        'account_id' => $accountId,
        'person_type' => 'other',
    ], false);
    expect_true(!empty($saved['success']) && !empty($saved['id']), 'other income receipt saves');
    $incomeId = (int)($saved['id'] ?? 0);

    $row = $conn->query("SELECT person_id, person_type, invoiceId, type FROM payments WHERE id = {$incomeId}")->fetch_assoc();
    expect_true(($row['person_type'] ?? '') === 'other', 'stored person_type is other');
    expect_true($row['person_id'] === null || $row['person_id'] === '', 'person_id stays empty');
    expect_true($row['invoiceId'] === null || $row['invoiceId'] === '', 'no invoice link');

    $bal = (float)$conn->query("SELECT current_balance FROM accounts WHERE id = {$accountId}")->fetch_assoc()['current_balance'];
    expect_true(abs($bal - $incomeAmount) < 0.01, 'account balance increased by receipt');

    $outSaved = $payment->save([
        'type' => 'cash',
        'transaction_type' => 'payment',
        'amount' => $outgoingAmount,
        'date' => $date,
        'description' => 'پرداخت متفرقه ' . $token,
        'account_id' => $accountId,
        'person_type' => 'other',
    ], false);
    expect_true(!empty($outSaved['success']), 'other outgoing payment saves');

    $bal2 = (float)$conn->query("SELECT current_balance FROM accounts WHERE id = {$accountId}")->fetch_assoc()['current_balance'];
    expect_true(abs($bal2 - ($incomeAmount - $outgoingAmount)) < 0.01, 'account balance decreased by other payment');

    $txModel = new Transaction($conn);
    $txList = $txModel->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'date',
        'sortOrder' => 'DESC',
        'searchTerm' => $token,
        'typeFilter' => 'payment',
        'includeUncashedChecks' => false,
    ]);
    $incomeRow = null;
    $outRow = null;
    foreach ($txList['data'] as $tx) {
        if ((int)($tx['id'] ?? 0) === $incomeId) {
            $incomeRow = $tx;
        }
        if ((int)($tx['id'] ?? 0) === (int)($outSaved['id'] ?? 0)) {
            $outRow = $tx;
        }
    }
    expect_true($incomeRow !== null, 'other income appears in تراکنشات');
    expect_true($incomeRow && strpos((string)$incomeRow['details'], 'درآمد متفرقه') !== false, 'label is درآمد متفرقه not انتقال وجه');
    expect_true($incomeRow && strpos((string)$incomeRow['details'], 'انتقال وجه') === false, 'not labeled as transfer');
    expect_true($incomeRow && ($incomeRow['transaction_class'] ?? '') === 'payment', 'transaction_class is payment (editable)');
    expect_true($incomeRow && abs((float)$incomeRow['credit'] - $incomeAmount) < 0.01, 'shown as credit');
    expect_true($outRow && strpos((string)$outRow['details'], 'پرداخت متفرقه') !== false, 'outgoing labeled پرداخت متفرقه');

    $reports = new ReportModel($conn);
    $pl = $reports->getProfitLossReport([
        'startDate' => $date,
        'endDate' => $date,
        'entityId' => $entityId,
        'calculationMethod' => 'cogs',
        'skipPartnerDetails' => true,
    ]);
    expect_true(abs((float)($pl['totalOtherIncome'] ?? 0) - $incomeAmount) < 0.01, 'P&L totalOtherIncome equals test receipt');
    expect_true(abs((float)($pl['totalOtherOutgoings'] ?? 0) - $outgoingAmount) < 0.01, 'P&L totalOtherOutgoings equals test payment');
} catch (Throwable $e) {
    echo "[FAIL] uncaught: " . $e->getMessage() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
