<?php
/**
 * Lightweight regression tests for account-closing logic (no full DB required).
 * Run: php tests/period_closing_logic_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/Partner.php';
require_once $root . '/api/models/PeriodClosing.php';
require_once $root . '/api/models/ClosingSnapshotBuilder.php';

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

function invoke_private(object $obj, string $method, array $args = []) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

// --- Partner::save accepts manageTransaction flag ---
$partnerSave = new ReflectionMethod(Partner::class, 'save');
expect_true($partnerSave->getNumberOfParameters() >= 2, 'Partner::save has manageTransaction parameter');
$params = $partnerSave->getParameters();
expect_true(($params[1]->getName() ?? '') === 'manageTransaction', 'Second param is manageTransaction');
expect_true($params[1]->isDefaultValueAvailable() && $params[1]->getDefaultValue() === true, 'manageTransaction defaults to true');

// --- resolveNextPartnersConfig ---
$closing = new PeriodClosing(null);
$current = [
    ['id' => 1, 'name' => 'علی', 'share' => 0.6],
    ['id' => 2, 'name' => 'رضا', 'share' => 0.4],
];

$resolved = invoke_private($closing, 'resolveNextPartnersConfig', [$current, null]);
expect_true(count($resolved['partners']) === 2 && empty($resolved['dropped']), 'Null config keeps current partners unchanged');

$resolved = invoke_private($closing, 'resolveNextPartnersConfig', [$current, [
    ['id' => 1, 'name' => 'علی', 'share' => 1.5, 'action' => 'modified'],
    ['id' => 2, 'name' => 'رضا', 'share' => 0.4, 'action' => 'modified'],
    ['id' => null, 'name' => '', 'share' => 0.2, 'action' => 'added'],
]]);
expect_true(count($resolved['dropped']) === 2, 'Invalid shares and blank new partner are dropped');
expect_true(count($resolved['partners']) === 1 && (float)$resolved['partners'][0]['share'] === 0.4, 'Only valid partner kept');

$resolved = invoke_private($closing, 'resolveNextPartnersConfig', [$current, [
    ['id' => 1, 'name' => 'علی', 'share' => 0.5, 'action' => 'modified'],
    ['id' => 2, 'name' => 'رضا', 'share' => 0.5, 'action' => 'modified'],
]]);
expect_true(empty($resolved['dropped']) && count($resolved['partners']) === 2, 'Valid 50/50 config accepted');

// --- buildPreviewWarnings: share must total 100% (error, not warning) ---
$warnings = invoke_private($closing, 'buildPreviewWarnings', [1, '2025-12-31', [
    ['id' => 1, 'name' => 'علی', 'share' => 0.5, 'action' => 'modified'],
], []]);
$errors = array_filter($warnings, fn($w) => ($w['level'] ?? '') === 'error');
expect_true(count($errors) >= 1, 'Share total != 100% yields error');
expect_true(
    (bool)array_filter($errors, fn($w) => str_contains($w['message'], '۱۰۰٪') || str_contains($w['message'], '100')),
    'Error message mentions 100% requirement'
);

$warnings = invoke_private($closing, 'buildPreviewWarnings', [1, '2025-12-31', [
    ['id' => 1, 'name' => 'علی', 'share' => 0.5, 'action' => 'unchanged'],
    ['id' => 2, 'name' => 'رضا', 'share' => 0.5, 'action' => 'unchanged'],
], []]);
$errors = array_filter($warnings, fn($w) => ($w['level'] ?? '') === 'error');
expect_true(empty($errors), 'Valid 100% share has no errors');

$warnings = invoke_private($closing, 'buildPreviewWarnings', [1, '2025-12-31', [
    ['id' => 1, 'name' => 'علی', 'share' => 1, 'action' => 'unchanged'],
], ['سهم نامعتبر']]);
expect_true(
    (bool)array_filter($warnings, fn($w) => ($w['level'] ?? '') === 'error' && str_contains($w['message'], 'نامعتبر')),
    'Dropped invalid shares produce error warning'
);

// --- Opening entry: negative bank becomes liability ---
$builder = new ClosingSnapshotBuilder(null);
$opening = invoke_private($builder, 'buildOpeningEntry', [
    '2026-01-01',
    ['customers' => []],
    ['suppliers' => []],
    ['allAccounts' => [
        ['id' => 10, 'name' => 'بانک', 'bankName' => 'ملی', 'balance' => -5000, 'inCashPosition' => 1],
        ['id' => 11, 'name' => 'صندوق', 'bankName' => null, 'balance' => 2000, 'inCashPosition' => 1],
    ]],
    ['received' => [], 'payable' => []],
    ['ownValue' => 0, 'ownQuantity' => 0],
    ['list' => []],
    1000.0,
    1000.0,
]);
$negBank = array_values(array_filter($opening['lines'], fn($l) => ($l['refId'] ?? null) === 10));
$cashLine = array_values(array_filter($opening['lines'], fn($l) => ($l['refId'] ?? null) === 11));
expect_true(!empty($negBank) && $negBank[0]['side'] === 'liability' && $negBank[0]['amount'] === 5000.0, 'Negative bank is liability with abs amount');
expect_true(!empty($cashLine) && $cashLine[0]['side'] === 'asset', 'Positive cash remains asset');

// --- Balance-sheet tolerance uses 1 rial ---
$snap = [
    'liquidity' => ['discrepancies' => []],
    'balanceSheet' => ['difference' => 50],
    'partners' => ['list' => [['id' => 1]]],
];
$bw = $builder->buildValidationWarnings($snap);
expect_true(
    (bool)array_filter($bw, fn($w) => ($w['level'] ?? '') === 'warning' && str_contains($w['message'], 'اختلاف ترازنامه') && str_contains($w['message'], 'تومان')),
    'Difference of 50 triggers balance WARNING in Tomans'
);
$snapBig = [
    'liquidity' => ['discrepancies' => []],
    'balanceSheet' => ['difference' => 5000],
    'partners' => ['list' => [['id' => 1]]],
];
$bwBig = $builder->buildValidationWarnings($snapBig);
expect_true(
    (bool)array_filter($bwBig, fn($w) => ($w['level'] ?? '') === 'error' && str_contains($w['message'], 'اختلاف ترازنامه')),
    'Difference >= 1000 rials triggers balance ERROR'
);
$snapOk = [
    'liquidity' => ['discrepancies' => []],
    'balanceSheet' => ['difference' => 0.5],
    'partners' => ['list' => [['id' => 1]]],
];
$bwOk = $builder->buildValidationWarnings($snapOk);
expect_true(
    !array_filter($bwOk, fn($w) => str_contains($w['message'], 'اختلاف ترازنامه')),
    'Sub-rial difference does not warn'
);

// --- Allocation rounding residual absorbed by last partner ---
$alloc = invoke_private($closing, 'buildPartnerAllocation', [
    [
        ['id' => 1, 'name' => 'A', 'share' => 1 / 3],
        ['id' => 2, 'name' => 'B', 'share' => 1 / 3],
        ['id' => 3, 'name' => 'C', 'share' => 1 / 3],
    ],
    100,
    100,
]);
$sumNet = array_sum(array_column($alloc, 'netProfitShare'));
$sumGross = array_sum(array_column($alloc, 'grossProfitShare'));
expect_true($sumNet === 100, 'Net profit shares sum exactly to 100');
expect_true($sumGross === 100, 'Gross profit shares sum exactly to 100');

// --- Date format validation required before comparisons ---
$badDate = invoke_private($closing, 'validateCloseRequest', [
    ['periodEndDate' => '1405/03/15', 'periodStartDate' => '2025-01-01'],
    1,
]);
expect_true(isset($badDate['error']) && str_contains($badDate['error'], 'تاریخ پایان'), 'Rejects non-Gregorian end date format');

$badBackup = invoke_private($closing, 'validateClosingBackup', ['auto_backup.sql']);
expect_true(isset($badBackup['error']), 'Non-full backup filename rejected');
$badBackup2 = invoke_private($closing, 'validateClosingBackup', ['full_2026-01-01_12-00-00.sql']);
expect_true(isset($badBackup2['error']), 'Uncompressed full backup rejected');
$badPath = invoke_private($closing, 'validateClosingBackup', ['full_2026-01-01_12-00-00.sql.gz']);
expect_true(isset($badPath['error']), 'Missing backup file on disk rejected');

// --- Future period end rejected ---
$futureClose = invoke_private($closing, 'validateCloseRequest', [
    ['periodEndDate' => '2099-12-31', 'periodStartDate' => '2099-01-01'],
    1,
]);
expect_true(
    isset($futureClose['error']) && str_contains($futureClose['error'], 'آینده'),
    'Rejects future period end date'
);

// --- calculationMethod whitelist ---
$badMethod = invoke_private($closing, 'normalizeCalculationMethod', ['weird_method']);
expect_true(is_array($badMethod) && isset($badMethod['error']), 'Rejects unknown calculationMethod');
$okMethod = invoke_private($closing, 'normalizeCalculationMethod', ['cogs']);
expect_true($okMethod === 'cogs', 'Accepts cogs calculationMethod');
$defaultMethod = invoke_private($closing, 'normalizeCalculationMethod', ['']);
expect_true($defaultMethod === 'standard', 'Empty calculationMethod defaults to standard');

// --- Schema: completed-only unique key (prevents close→reopen→close→reopen collision) ---
$setupSrc = file_get_contents($root . '/api/core/schema.php');
expect_true(
    str_contains($setupSrc, 'completed_end_key')
        && str_contains($setupSrc, "IF(`status` = 'completed', `period_end_date`, NULL)"),
    'Schema defines completed_end_key generated column'
);
expect_true(
    str_contains($setupSrc, 'UNIQUE KEY `uq_closing_entity_completed_end` (`entity_id`, `completed_end_key`)'),
    'Unique key is on (entity_id, completed_end_key) not status'
);
expect_true(
    !preg_match('/UNIQUE KEY `uq_closing_entity_completed_end` \(`entity_id`, `period_end_date`, `status`\)/', $setupSrc),
    'Legacy unique on (entity_id, period_end_date, status) removed from schema'
);

// --- Period lock gaps wired in source ---
$accountSrc = file_get_contents($root . '/api/models/Account.php');
expect_true(
    substr_count($accountSrc, 'assert_transaction_date_editable') >= 2
        && str_contains($accountSrc, 'function deleteTransfer'),
    'deleteTransfer asserts period lock'
);
$stockSrc = file_get_contents($root . '/api/controllers/StocktakingController.php');
expect_true(
    substr_count($stockSrc, 'assert_transaction_date_editable') >= 2,
    'Stocktaking save/apply assert period lock'
);

// --- UI profit post date label ---
$tplSrc = file_get_contents($root . '/templates/section_account_closing.php');
expect_true(
    str_contains($tplSrc, 'روز بعد از تاریخ بستن')
        && !str_contains($tplSrc, 'تراکنش نقدی در تاریخ بستن'),
    'UI label matches next-day profit posting'
);

// --- Helpers: closed-period comparison ---
expect_true(function_exists('assert_transaction_date_editable'), 'assert_transaction_date_editable exists');
expect_true(function_exists('get_entity_last_closed_date'), 'get_entity_last_closed_date exists');

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
