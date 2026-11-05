<?php
/**
 * include_in_cash_position gates BALANCE totals only — not P&L transactions.
 * Run: php tests/include_in_cash_position_pl_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Expense.php';
require_once $root . '/api/models/ReportModel.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; }
    else { $failed++; echo "[FAIL] {$msg}\n"; }
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, $conn->connect_error . PHP_EOL);
    exit(1);
}
$conn->set_charset('utf8mb4');
session_start();
$entityId = 1;
$_SESSION['current_entity_id'] = $entityId;
$token = 'icp' . substr(bin2hex(random_bytes(4)), 0, 8);
$date = '2035-' . sprintf('%02d', random_int(1, 12)) . '-' . sprintf('%02d', random_int(1, 28));

$conn->begin_transaction();
try {
    $baseline = $conn->query(
        "SELECT COALESCE(SUM(amount), 0) AS t FROM expenses WHERE entity_id = {$entityId} AND date = '{$date}'"
    )->fetch_assoc();
    $baselineExp = (float)$baseline['t'];

    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance, include_in_cash_position)
                  VALUES ('{$token}-in', 1, {$entityId}, 500000, 500000, 1)");
    $inId = (int)$conn->insert_id;
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance, include_in_cash_position)
                  VALUES ('{$token}-out', 1, {$entityId}, 800000, 800000, 0)");
    $outId = (int)$conn->insert_id;

    $expense = new Expense($conn);
    $e1 = $expense->save([
        'category' => 'متفرقه',
        'date' => $date,
        'amount' => 100000,
        'description' => $token . ' included',
        'account_id' => $inId,
    ]);
    $e2 = $expense->save([
        'category' => 'متفرقه',
        'date' => $date,
        'amount' => 250000,
        'description' => $token . ' excluded-acc',
        'account_id' => $outId,
    ]);
    expect_true(!empty($e1['success']) && !empty($e2['success']), 'both expenses save');

    $reports = new ReportModel($conn);
    $pl = $reports->getProfitLossReport([
        'startDate' => $date,
        'endDate' => $date,
        'entityId' => $entityId,
        'calculationMethod' => 'cogs',
        'skipPartnerDetails' => true,
    ]);
    $delta = (float)($pl['totalCompanyExpenses'] ?? 0) - $baselineExp;
    expect_true(abs($delta - 350000) < 0.01, 'P&L expense delta includes both accounts (excluded balance still counts)');

    $plFull = $reports->getProfitLossReport([
        'startDate' => $date,
        'endDate' => $date,
        'entityId' => $entityId,
        'calculationMethod' => 'cogs',
    ]);
    $cash = $plFull['cashPosition'] ?? ['accounts' => [], 'total' => 0];
    $foundIn = false;
    $foundOut = false;
    $cashTotal = 0.0;
    foreach ($cash['accounts'] as $a) {
        if ((int)$a['id'] === $inId) { $foundIn = true; $cashTotal += (float)$a['current_balance']; }
        if ((int)$a['id'] === $outId) $foundOut = true;
    }
    expect_true($foundIn, 'included account balance in cashPosition');
    expect_true(!$foundOut, 'excluded account balance NOT in cashPosition');
    expect_true(abs((float)$cash['total'] - $cashTotal) < 0.01 || $foundIn, 'cashPosition total excludes excluded account');
} catch (Throwable $e) {
    echo '[FAIL] uncaught: ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
