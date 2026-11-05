<?php
/**
 * Ras-giri / multi-check transfer group persistence + payment lookup by checkId.
 * Run: php tests/check_transfer_group_test.php
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
require_once $root . '/api/models/Check.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);

$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId]];
$token = 'tg' . substr(bin2hex(random_bytes(5)), 0, 8);
$date = '2032-03-15';

$one = function (string $sql) use ($conn) {
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : null;
};

$created = [
    'customers' => [],
    'suppliers' => [],
    'accounts' => [],
    'checks' => [],
    'payments' => [],
    'expenses' => [],
];

try {
    (new Payment($conn))->ensureTransferGroupColumn();

    $conn->query("INSERT INTO customers (name, nationalId, entity_id) VALUES ('{$token}-cust', '{$token}', {$entityId})");
    $customerId = (int)$conn->insert_id;
    $created['customers'][] = $customerId;

    $conn->query("INSERT INTO suppliers (name, economicCode, entity_id) VALUES ('{$token}-sup', '{$token}', {$entityId})");
    $supplierId = (int)$conn->insert_id;
    $created['suppliers'][] = $supplierId;

    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-cash', 1, {$entityId}, 10000000, 10000000)");
    $cashId = (int)$conn->insert_id;
    $created['accounts'][] = $cashId;

    $checkIds = [];
    foreach ([1, 2, 3] as $i) {
        $num = $token . '-' . $i;
        $due = '2032-0' . (4 + $i) . '-01';
        $amt = 1000000 * $i;
        $conn->query("INSERT INTO checks (entity_id, type, status, checkNumber, bankName, issuerName, dueDate, amount)
                      VALUES ({$entityId}, 'received', 'in_hand', '{$num}', 'ملی', 'صادرکننده', '{$due}', {$amt})");
        $cid = (int)$conn->insert_id;
        $checkIds[] = $cid;
        $created['checks'][] = $cid;
        $conn->query("INSERT INTO payments (entity_id, person_id, person_type, transaction_type, type, amount, date, description, checkId)
                      VALUES ({$entityId}, {$customerId}, 'customer', 'receipt', 'check', {$amt}, '{$date}', 'دریافت {$num}', {$cid})");
        $created['payments'][] = (int)$conn->insert_id;
    }

    $checkModel = new Check($conn);
    $payload = array_map(function ($cid) use ($one) {
        $row = $one("SELECT amount FROM checks WHERE id = {$cid}");
        return [
            'checkId' => $cid,
            'originalAmount' => (float)$row['amount'],
            'discountAmount' => 10000,
            'discountedAmount' => (float)$row['amount'] - 10000,
        ];
    }, $checkIds);

    $res = $checkModel->discountAndTransferChecks(
        $payload,
        $supplierId,
        'supplier',
        $date,
        'تست گروه انتقال ' . $token,
        0,
        $date
    );
    expect_true(!empty($res['success']), 'discountAndTransferChecks succeeds');
    expect_true(!empty($res['transfer_group_id']), 'returns transfer_group_id');
    $groupId = (string)($res['transfer_group_id'] ?? '');

    $endorsed = $one("SELECT COUNT(*) AS c FROM checks WHERE id IN (" . implode(',', $checkIds) . ") AND status = 'endorsed'");
    expect_true((int)$endorsed['c'] === 3, 'all three checks endorsed');

    $groupPays = $one("SELECT COUNT(*) AS c, COUNT(DISTINCT transfer_group_id) AS g
                       FROM payments WHERE type = 'endorse_check' AND checkId IN (" . implode(',', $checkIds) . ")");
    expect_true((int)$groupPays['c'] === 3, 'three endorse payments created');
    expect_true((int)$groupPays['g'] === 1, 'same transfer_group_id on all endorse rows');

    foreach ($conn->query("SELECT id FROM payments WHERE type = 'endorse_check' AND checkId IN (" . implode(',', $checkIds) . ")") as $row) {
        $created['payments'][] = (int)$row['id'];
    }
    $exp = $one("SELECT id FROM expenses WHERE entity_id = {$entityId} AND description LIKE '%{$token}%' ORDER BY id DESC LIMIT 1");
    if ($exp) {
        $created['expenses'][] = (int)$exp['id'];
    }

    $group = $checkModel->getTransferGroup($groupId, null);
    expect_true(!empty($group['success']) && (int)$group['checkCount'] === 3, 'getTransferGroup by id returns 3 checks');

    // Simulate removing one check from the conceptual list (return endorsed)
    $returned = $checkModel->returnEndorsedCheck($checkIds[0], $date, 'عودت تست');
    expect_true(!empty($returned['success']), 'return one endorsed check');
    $groupAfter = $checkModel->getTransferGroup(null, $checkIds[1]);
    expect_true(!empty($groupAfter['success']) && (int)$groupAfter['checkCount'] === 3, 'group still lists all original checks after one return');
    $statuses = array_column($groupAfter['checks'], 'status', 'id');
    expect_true(($statuses[$checkIds[0]] ?? '') === 'in_hand', 'returned check shows in_hand in group');
    expect_true(($statuses[$checkIds[1]] ?? '') === 'endorsed', 'sibling still endorsed');

    $history = $checkModel->getCheckHistory($checkIds[1]);
    expect_true(!empty($history['success']), 'history loads');
    $h = $history['checks'][0] ?? [];
    expect_true(!empty($h['transfer_group']) && (int)$h['transfer_group']['checkCount'] === 3, 'history embeds full transfer group');
    expect_true(!empty($h['endorse_payment_id']), 'history exposes endorse_payment_id for edit');

    // Prefer endorse_check when looking up payment by checkId for an endorsed check
    $cid2 = $checkIds[1];
    $st = $conn->prepare("SELECT * FROM payments WHERE checkId = ? AND entity_id = ? AND type = 'endorse_check' ORDER BY id DESC LIMIT 1");
    $st->bind_param('ii', $cid2, $entityId);
    $st->execute();
    $endorseRow = db_stmt_to_assoc_array($st)[0] ?? null;
    expect_true(($endorseRow['type'] ?? '') === 'endorse_check', 'endorse payment lookup for endorsed check');

    // ---- Edit the group afterwards: remove one, add a new one + the returned one, rewrite description ----
    $conn->query("INSERT INTO checks (entity_id, type, status, checkNumber, bankName, issuerName, dueDate, amount)
                  VALUES ({$entityId}, 'received', 'in_hand', '{$token}-4', 'ملی', 'صادرکننده', '2032-09-01', 4000000)");
    $check4 = (int)$conn->insert_id;
    $created['checks'][] = $check4;
    $conn->query("INSERT INTO payments (entity_id, person_id, person_type, transaction_type, type, amount, date, description, checkId)
                  VALUES ({$entityId}, {$customerId}, 'customer', 'receipt', 'check', 4000000, '{$date}', 'دریافت {$token}-4', {$check4})");

    expect_true(($groupAfter['common_description'] ?? null) === 'تست گروه انتقال ' . $token, 'common description strips discount suffix');
    $removable = array_column($groupAfter['checks'], 'removable', 'id');
    expect_true($removable[$checkIds[0]] === false && $removable[$checkIds[1]] === true, 'returned check is not removable, endorsed sibling is');

    $bad = $checkModel->updateTransferGroup($groupId, null, [], [$checkIds[0]]);
    expect_true(!empty($bad['error']), 'cannot remove a returned check');

    $edit = $checkModel->updateTransferGroup($groupId, 'توضیح جدید ' . $token, [$check4, $checkIds[0]], [$checkIds[2]]);
    expect_true(!empty($edit['success']), 'updateTransferGroup succeeds');
    expect_true((int)($edit['remainingCount'] ?? 0) === 3, 'three active checks after edit');

    $st3 = $one("SELECT status FROM checks WHERE id = {$checkIds[2]}");
    expect_true($st3['status'] === 'in_hand', 'removed check is back in hand');
    $st4 = $one("SELECT status FROM checks WHERE id = {$check4}");
    expect_true($st4['status'] === 'endorsed', 'added check is endorsed');
    $st0 = $one("SELECT status FROM checks WHERE id = {$checkIds[0]}");
    expect_true($st0['status'] === 'endorsed', 'returned check re-added to group');

    $edited = $checkModel->getTransferGroup($groupId, null);
    $ids = array_column($edited['checks'], 'id');
    sort($ids);
    $expectIds = [$checkIds[0], $checkIds[1], $check4];
    sort($expectIds);
    expect_true($ids === $expectIds, 'group lists re-added, kept and added checks once each');
    expect_true(($edited['common_description'] ?? '') === 'توضیح جدید ' . $token, 'description updated on all rows');
    $sib = $one("SELECT description FROM payments WHERE type = 'endorse_check' AND checkId = {$checkIds[1]} AND transfer_group_id = '{$groupId}'");
    expect_true(mb_strpos($sib['description'], '(تنزیل: 10,000 تومان)') !== false, 'per-row discount suffix kept');
    $p4 = $one("SELECT person_id, person_type, date FROM payments WHERE type = 'endorse_check' AND checkId = {$check4}");
    expect_true((int)$p4['person_id'] === $supplierId && $p4['person_type'] === 'supplier' && $p4['date'] === $date, 'added row uses group person and date');

    $auto = $checkModel->updateTransferGroup($groupId, '', [], []);
    $r4 = $one("SELECT description FROM payments WHERE type = 'endorse_check' AND checkId = {$check4}");
    expect_true(!empty($auto['success']) && mb_strpos($r4['description'], "راس‌گیری و انتقال چک {$token}-4 به") === 0, 'empty description restores per-check default');
    $again = $checkModel->getTransferGroup($groupId, null);
    expect_true(($again['common_description'] ?? null) === '', 'auto descriptions report empty common description');

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    foreach ($created['expenses'] as $id) {
        $conn->query("DELETE FROM expenses WHERE id = {$id}");
    }
    // bounce reversals / endorse rows first
    if (!empty($created['checks'])) {
        $ids = implode(',', $created['checks']);
        $conn->query("DELETE FROM payments WHERE checkId IN ({$ids})");
        $conn->query("DELETE FROM check_events WHERE check_id IN ({$ids})");
        $conn->query("DELETE FROM checks WHERE id IN ({$ids})");
    }
    foreach ($created['accounts'] as $id) {
        $conn->query("DELETE FROM accounts WHERE id = {$id}");
    }
    foreach ($created['suppliers'] as $id) {
        $conn->query("DELETE FROM suppliers WHERE id = {$id}");
    }
    foreach ($created['customers'] as $id) {
        $conn->query("DELETE FROM customers WHERE id = {$id}");
    }
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
