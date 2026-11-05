<?php
/**
 * Cashed/passed check rows in تراکنشات must use cashed_date, not dueDate.
 * Run: php tests/cashed_check_transaction_date_test.php
 * Uses a DB transaction that is always rolled back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Transaction.php';

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
$token = 't' . substr(bin2hex(random_bytes(6)), 0, 10);
$dueDate = '2024-01-15';
$cashedDate = '2026-09-07';

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 0, 0)");
    $accountId = (int)$conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO checks (entity_id, type, status, checkNumber, dueDate, bankName, amount, cashed_in_account_id, cashed_date) VALUES (?, 'received', 'cashed', ?, ?, 'TestBank', 100000, ?, ?)");
    $checkNo = $token . '-r';
    $stmt->bind_param('issis', $entityId, $checkNo, $dueDate, $accountId, $cashedDate);
    $stmt->execute();
    $receivedId = (int)$conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO checks (entity_id, type, status, checkNumber, dueDate, bankName, amount, cashed_in_account_id, cashed_date) VALUES (?, 'payable', 'cashed', ?, ?, 'TestBank', 50000, ?, ?)");
    $checkNoP = $token . '-p';
    $stmt->bind_param('issis', $entityId, $checkNoP, $dueDate, $accountId, $cashedDate);
    $stmt->execute();
    $payableId = (int)$conn->insert_id;
    $stmt->close();

    $model = new Transaction($conn);
    $result = $model->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'date',
        'sortOrder' => 'DESC',
        'searchTerm' => $token,
        'typeFilter' => 'payment',
        'includeUncashedChecks' => false,
    ]);

    $byId = [];
    foreach ($result['data'] as $row) {
        if (($row['source_table'] ?? '') === 'check') {
            $byId[(int)$row['id']] = $row;
        }
    }

    expect_true(isset($byId[$receivedId]), 'received cashed check appears in transactions');
    expect_true(isset($byId[$payableId]), 'payable cashed check appears in transactions');
    expect_true(($byId[$receivedId]['date'] ?? '') === $cashedDate, 'وصول uses cashed_date not dueDate');
    expect_true(($byId[$payableId]['date'] ?? '') === $cashedDate, 'پاس uses cashed_date not dueDate');
    expect_true(($byId[$receivedId]['date'] ?? '') !== $dueDate, 'وصول date is not dueDate');
    expect_true(($byId[$payableId]['date'] ?? '') !== $dueDate, 'پاس date is not dueDate');
} catch (Throwable $e) {
    expect_true(false, 'exception: ' . $e->getMessage());
} finally {
    $conn->rollback();
    $conn->close();
}

echo "PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
