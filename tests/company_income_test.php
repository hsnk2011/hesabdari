<?php
/**
 * Company incomes: save/delete wallet effect, P&L, contact balance, cash flow.
 * Run: php tests/company_income_test.php
 * Always rolls back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Account.php';
require_once $root . '/api/models/Income.php';
require_once $root . '/api/models/Expense.php';
require_once $root . '/api/models/Contact.php';
require_once $root . '/api/models/ReportModel.php';
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
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

$tableOk = $conn->query("SHOW TABLES LIKE 'incomes'");
if (!$tableOk || $tableOk->num_rows === 0) {
    echo "Creating incomes table via schema snippet...\n";
    $conn->query("CREATE TABLE IF NOT EXISTS `incomes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
        `date` date NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `account_id` int(11) NOT NULL,
        `person_id` int(11) DEFAULT NULL,
        `person_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `entity_id` int(11) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        KEY `account_id` (`account_id`),
        KEY `date` (`date`),
        KEY `idx_entity_date` (`entity_id`, `date`),
        KEY `idx_income_person` (`entity_id`, `person_type`, `person_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$ent = $conn->query("SELECT id FROM business_entities ORDER BY id ASC LIMIT 1");
$entityId = (int)(($ent && ($r = $ent->fetch_assoc())) ? $r['id'] : 0);
expect_true($entityId > 0, 'entity exists');

$acc = $conn->query("SELECT id, current_balance FROM accounts WHERE entity_id = {$entityId} AND partner_id IS NULL ORDER BY id ASC LIMIT 1");
$accRow = $acc ? $acc->fetch_assoc() : null;
expect_true(!empty($accRow), 'company account exists');
$accountId = (int)$accRow['id'];
$balBefore = (float)$accRow['current_balance'];

$_SESSION['user_id'] = 1;
$_SESSION['current_entity_id'] = $entityId;
$_SESSION['allowed_entities'] = [$entityId];
$_SESSION['permissions'] = ['manage_transactions', 'view_reports', 'manage_contacts'];

$conn->begin_transaction();
try {
    $date = date('Y-m-d');
    $amount = 125000.0;

    $incomeModel = new Income($conn);
    $saved = $incomeModel->save([
        'category' => 'سود سپرده بانکی',
        'date' => $date,
        'amount' => $amount,
        'description' => 'test company income',
        'account_id' => $accountId,
    ]);
    expect_true(!empty($saved['success']) && !empty($saved['id']), 'income saves');
    $incomeId = (int)$saved['id'];

    $balStmt = $conn->prepare("SELECT current_balance FROM accounts WHERE id = ?");
    $balStmt->bind_param('i', $accountId);
    $balStmt->execute();
    $balAfter = (float)(db_stmt_to_assoc_array($balStmt)[0]['current_balance'] ?? 0);
    expect_true(abs(($balAfter - $balBefore) - $amount) < 0.01, 'account balance increased by income');

    $reports = new ReportModel($conn);
    $pl = $reports->getProfitLossReport([
        'startDate' => $date,
        'endDate' => $date,
        'entityId' => $entityId,
        'calculationMethod' => 'standard',
        'skipPartnerDetails' => true,
    ]);
    expect_true((float)($pl['totalCompanyIncome'] ?? 0) >= $amount - 0.01, 'P&L includes company income');

    $cf = $reports->getCashFlowReport(['startDate' => $date, 'endDate' => $date]);
    expect_true((float)($cf['incomes'] ?? 0) >= $amount - 0.01, 'cash flow includes incomes');

    $tx = new Transaction($conn);
    $paged = $tx->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'date',
        'sortOrder' => 'DESC',
        'typeFilter' => 'income',
        'searchTerm' => 'test company income',
    ]);
    $found = false;
    foreach ($paged['data'] as $row) {
        if ((int)$row['id'] === $incomeId && ($row['source_table'] ?? '') === 'income') {
            $found = true;
            expect_true((float)$row['credit'] > 0 && (float)$row['debit'] == 0, 'income shows as credit');
            break;
        }
    }
    expect_true($found, 'income appears in transaction filter');

    // Contact-linked income affects contact net_balance (+income)
    $contactModel = new Contact($conn);
    $cSave = $contactModel->save([
        'name' => 'تست درآمد ' . uniqid(),
        'phone' => '09000000000',
        'initial_balance' => 0,
    ]);
    expect_true(!empty($cSave['success']) && !empty($cSave['id']), 'contact created');
    $contactId = (int)$cSave['id'];

    $linked = $incomeModel->save([
        'category' => 'سایر درآمدها',
        'date' => $date,
        'amount' => 50000,
        'description' => 'linked income',
        'account_id' => $accountId,
        'person_type' => 'contact',
        'person_id' => $contactId,
    ]);
    expect_true(!empty($linked['success']), 'linked income saves');

    $clist = $contactModel->getPaginated(['currentPage' => 1, 'limit' => 100, 'searchTerm' => '']);
    $cBal = null;
    foreach ($clist['data'] as $c) {
        if ((int)$c['id'] === $contactId) {
            $cBal = (float)$c['net_balance'];
            break;
        }
    }
    expect_true($cBal !== null && abs($cBal - 50000) < 0.01, 'contact balance +income');

    $deleted = $incomeModel->delete($incomeId);
    expect_true(!empty($deleted['success']), 'income deletes');
    $balStmt2 = $conn->prepare("SELECT current_balance FROM accounts WHERE id = ?");
    $balStmt2->bind_param('i', $accountId);
    $balStmt2->execute();
    $balAfterDelete = (float)(db_stmt_to_assoc_array($balStmt2)[0]['current_balance'] ?? 0);
    // After delete of first income, still have linked +50000
    expect_true(abs(($balAfterDelete - $balBefore) - 50000) < 0.01, 'delete reverts wallet (linked income remains)');

    // Expense still works (regression)
    $exp = new Expense($conn);
    $eSave = $exp->save([
        'category' => 'متفرقه',
        'date' => $date,
        'amount' => 1000,
        'description' => 'regression expense',
        'account_id' => $accountId,
    ]);
    expect_true(!empty($eSave['success']), 'expense still saves');

} catch (Throwable $e) {
    echo "[FAIL] exception: " . $e->getMessage() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
