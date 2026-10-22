<?php
/**
 * Contacts + optional expense person + other-party regression helpers.
 * Run: php tests/contacts_and_misc_income_test.php
 * Always rolls back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Contact.php';
require_once $root . '/api/models/Payment.php';
require_once $root . '/api/models/Expense.php';
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
$token = 'ctc' . substr(bin2hex(random_bytes(5)), 0, 8);
$date = '2024-04-11';

$conn->begin_transaction();
try {
    $tbl = $conn->query("SHOW TABLES LIKE 'contacts'");
    expect_true($tbl && $tbl->num_rows > 0, 'contacts table exists');

    $col = $conn->query("SHOW COLUMNS FROM expenses LIKE 'person_id'");
    expect_true($col && $col->num_rows > 0, 'expenses.person_id exists');

    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 1000000, 1000000)");
    $accountId = (int)$conn->insert_id;

    $contact = new Contact($conn);
    $saved = $contact->save([
        'name' => 'پیمانکار ' . $token,
        'phone' => '09120000000',
        'notes' => 'test',
        'nationalId' => '',
        'initial_balance' => 500000,
    ]);
    expect_true(!empty($saved['success']) && !empty($saved['id']), 'contact CRUD create');
    $contactId = (int)$saved['id'];

    $payment = new Payment($conn);
    $payOut = $payment->save([
        'type' => 'cash',
        'transaction_type' => 'payment',
        'amount' => 200000,
        'date' => $date,
        'description' => 'پرداخت به پیمانکار ' . $token,
        'account_id' => $accountId,
        'person_id' => $contactId,
        'person_type' => 'contact',
    ], false);
    expect_true(!empty($payOut['success']), 'payment to contact saves');

    $list = $contact->getPaginated(['currentPage' => 1, 'limit' => 50, 'searchTerm' => $token]);
    $row = null;
    foreach ($list['data'] as $c) {
        if ((int)$c['id'] === $contactId) {
            $row = $c;
            break;
        }
    }
    expect_true($row !== null, 'contact appears in paginated list');
    // initial 500000 - payment 200000 = 300000 بستانکار
    expect_true($row && abs((float)$row['net_balance'] - 300000) < 0.01, 'contact balance after payment');

    $expense = new Expense($conn);
    $exp = $expense->save([
        'category' => 'متفرقه',
        'date' => $date,
        'amount' => 100000,
        'description' => 'هزینه وصل‌شده ' . $token,
        'account_id' => $accountId,
        'person_type' => 'contact',
        'person_id' => $contactId,
    ]);
    expect_true(!empty($exp['success']), 'expense with contact saves');

    $list2 = $contact->getPaginated(['currentPage' => 1, 'limit' => 50, 'searchTerm' => $token]);
    $row2 = null;
    foreach ($list2['data'] as $c) {
        if ((int)$c['id'] === $contactId) {
            $row2 = $c;
            break;
        }
    }
    // 500000 - 200000 - 100000 expense = 200000
    expect_true($row2 && abs((float)$row2['net_balance'] - 200000) < 0.01, 'contact balance includes linked expense');

    $reports = new ReportModel($conn);
    $stmt = $reports->getPersonStatement([
        'personType' => 'contact',
        'personId' => $contactId,
        'startDate' => '2024-01-01',
        'endDate' => '2024-12-31',
        'entityId' => $entityId,
    ]);
    expect_true(empty($stmt['error']), 'person statement for contact works');
    $hasExpense = false;
    $hasPayment = false;
    $hasInitial = false;
    foreach ($stmt['transactions'] ?? [] as $tx) {
        if (($tx['refType'] ?? '') === 'expense') $hasExpense = true;
        if (($tx['refType'] ?? '') === 'payment') $hasPayment = true;
        if (!empty($tx['isPreviousBalance']) || in_array(($tx['date'] ?? ''), ['مانده اولیه', 'مانده از قبل'], true)) $hasInitial = true;
    }
    expect_true($hasInitial, 'statement shows initial balance');
    expect_true($hasPayment, 'statement shows payment');
    expect_true($hasExpense, 'statement shows linked expense');

    $expPlain = $expense->save([
        'category' => 'متفرقه',
        'date' => $date,
        'amount' => 50000,
        'description' => 'بدون شخص ' . $token,
        'account_id' => $accountId,
    ]);
    expect_true(!empty($expPlain['success']), 'expense without person still works');
} catch (Throwable $e) {
    echo '[FAIL] uncaught: ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
