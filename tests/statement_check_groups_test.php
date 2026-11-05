<?php
/**
 * Person statements show checks moved together as one row: an endorsement
 * group («راس‌گیری و انتقال چک», payments.transfer_group_id) or a batch of
 * checks received from the same person on the same day with the same
 * description. The row says how many checks and their total; the checks
 * themselves are listed only with «نمایش جزئیات». Totals and running balance
 * must not change. Read-only. Run: php tests/statement_check_groups_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/ReportModel.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$sum = function (array $tx, string $col) { return array_sum(array_map(fn($t) => (float)($t[$col] ?? 0), $tx)); };

function statement($conn, $type, $id, $entity, $details) {
    $_SESSION = ['current_entity_id' => (int)$entity, 'allowed_entities' => [(int)$entity]];
    $m = new ReportModel($conn);
    return $m->getPersonStatement([
        'personType' => $type, 'personId' => $id, 'entityId' => $entity,
        'startDate' => '2020-01-01', 'endDate' => '2030-01-01',
        'showInvoiceDetails' => $details ? '1' : '0', 'showPreviousBalance' => '0',
    ]);
}

// ---- 1) a batch of received checks with no group id (mz7) ----
$batch = $one("SELECT person_id, entity_id, date, COUNT(*) n, SUM(amount) s FROM payments
               WHERE type = 'check' AND transaction_type = 'receipt' AND transfer_group_id IS NULL AND person_type = 'customer' AND checkId IS NOT NULL
               GROUP BY person_id, entity_id, date HAVING n >= 10 ORDER BY n DESC LIMIT 1");
if (!$batch) {
    echo "SKIP: no batch of received checks in this database\n";
} else {
    $plain = statement($conn, 'customer', $batch['person_id'], $batch['entity_id'], false);
    $detail = statement($conn, 'customer', $batch['person_id'], $batch['entity_id'], true);
    $groups = array_values(array_filter($plain['transactions'], fn($t) => ($t['refType'] ?? '') === 'check_group'));
    $n = (int)$batch['n'];
    expect_true(count($groups) >= 1, 'the batch becomes a group row');
    $g = $groups[0] ?? [];
    $count = (int)($g['checkCount'] ?? 0);
    expect_true($count >= 2 && $count <= $n, "the group row counts its checks ({$count})");
    expect_true(str_contains($g['description'] ?? '', 'فقره چک'), 'the description says «… فقره چک»: ' . ($g['description'] ?? ''));
    $groupTotal = array_sum(array_map(fn($x) => (float)$x['credit'] + (float)$x['debit'], $groups));
    expect_true(abs($groupTotal - (float)$batch['s']) < 0.01 || $groupTotal <= (float)$batch['s'] + 0.01, 'group amounts come from the batch');
    expect_true(empty($g['checkGroup']), 'without «جزئیات» the checks are not listed');
    $dg = array_values(array_filter($detail['transactions'], fn($t) => ($t['refType'] ?? '') === 'check_group'));
    expect_true(!empty($dg[0]['checkGroup']) && count($dg[0]['checkGroup']) === (int)$dg[0]['checkCount'], 'with «جزئیات» every check of the group is listed');
    $line = $dg[0]['checkGroup'][0] ?? [];
    expect_true(isset($line['checkNumber'], $line['amount'], $line['dueDate']), 'a listed check has number, amount and due date');
    expect_true(abs(array_sum(array_column($dg[0]['checkGroup'] ?? [], 'amount')) - ((float)$dg[0]['credit'] + (float)$dg[0]['debit'])) < 0.01, 'the listed checks add up to the row');
    // Same money either way.
    expect_true(abs($sum($plain['transactions'], 'credit') - $sum($detail['transactions'], 'credit')) < 0.01
             && abs($sum($plain['transactions'], 'debit') - $sum($detail['transactions'], 'debit')) < 0.01, 'totals are the same with and without details');
    $checkRows = $one("SELECT COUNT(*) n FROM payments WHERE person_type='customer' AND person_id={$batch['person_id']} AND entity_id={$batch['entity_id']} AND type IN ('check','endorse_check')");
    expect_true(count($plain['transactions']) < (int)$checkRows['n'] + 200, 'statement has fewer rows than before grouping');
    $leftover = array_filter($plain['transactions'], fn($t) => !empty($t['_checkGroupKey']) || !empty($t['_checkLine']));
    expect_true(!$leftover, 'no internal grouping fields leak into the result');
}

// ---- 2) an endorsement group (راس‌گیری و انتقال گروهی) to a supplier ----
$eg = $one("SELECT transfer_group_id g, person_type pt, person_id pid, entity_id e, COUNT(*) n, SUM(amount) s FROM payments
            WHERE transfer_group_id IS NOT NULL AND type = 'endorse_check' AND person_id IS NOT NULL
            GROUP BY transfer_group_id, person_type, person_id, entity_id ORDER BY n DESC LIMIT 1");
if (!$eg) {
    echo "SKIP: no endorsement group in this database\n";
} else {
    $st = statement($conn, $eg['pt'], $eg['pid'], $eg['e'], true);
    $rows = array_values(array_filter($st['transactions'], fn($t) => ($t['refType'] ?? '') === 'check_group' && (int)($t['checkCount'] ?? 0) === (int)$eg['n']));
    expect_true(count($rows) === 1, "the endorsement group of {$eg['n']} checks is one row");
    $r = $rows[0] ?? [];
    expect_true(abs(((float)($r['debit'] ?? 0) + (float)($r['credit'] ?? 0)) - (float)$eg['s']) < 0.01, 'its amount is the group total');
    expect_true(count($r['checkGroup'] ?? []) === (int)$eg['n'], 'with «جزئیات» all its checks are listed');
    expect_true(!preg_match('/\d{6,}/u', $r['description'] ?? ''), 'the group description has no single check number in it: ' . ($r['description'] ?? ''));
}

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
