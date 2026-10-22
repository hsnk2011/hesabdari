<?php
/**
 * تراکنشات: date range filter (dateFrom / dateTo) on Transaction::getPaginated.
 * Run: php tests/transactions_date_filter_test.php
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

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$entityId}, 0, 0)");
    $accountId = (int)$conn->insert_id;

    $dates = ['d10' => '2026-01-10', 'd15' => '2026-01-15', 'd20' => '2026-01-20'];
    $ids = [];
    foreach ($dates as $key => $date) {
        $stmt = $conn->prepare("INSERT INTO expenses (entity_id, category, date, amount, description, account_id) VALUES (?, 'test', ?, 1000, ?, ?)");
        $desc = $token . '-' . $key;
        $stmt->bind_param('issi', $entityId, $date, $desc, $accountId);
        $stmt->execute();
        $ids[$key] = (int)$conn->insert_id;
        $stmt->close();
    }

    $model = new Transaction($conn);
    $ask = function (array $extra) use ($model, $token): array {
        $res = $model->getPaginated(array_merge([
            'currentPage' => 1,
            'limit' => 50,
            'sortBy' => 'date',
            'sortOrder' => 'ASC',
            'searchTerm' => $token,
        ], $extra));
        $ids = array_map(fn($r) => (int)$r['id'], $res['data']);
        sort($ids);
        return ['ids' => $ids, 'total' => (int)$res['totalRecords']];
    };
    $want = fn(string ...$keys) => (function () use ($ids, $keys) {
        $out = array_map(fn($k) => $ids[$k], $keys);
        sort($out);
        return $out;
    })();

    $r = $ask([]);
    expect_true($r['ids'] === $want('d10', 'd15', 'd20'), 'no date filter returns all rows');

    $r = $ask(['dateFrom' => '2026-01-10', 'dateTo' => '2026-01-20']);
    expect_true($r['ids'] === $want('d10', 'd15', 'd20'), 'range is inclusive of both boundary dates');

    $r = $ask(['dateFrom' => '2026-01-11', 'dateTo' => '2026-01-19']);
    expect_true($r['ids'] === $want('d15'), 'narrow range returns only the middle row');
    expect_true($r['total'] === 1, 'totalRecords reflects the date filter');

    $r = $ask(['dateFrom' => '2026-01-15']);
    expect_true($r['ids'] === $want('d15', 'd20'), 'dateFrom only is open-ended upward');

    $r = $ask(['dateTo' => '2026-01-15']);
    expect_true($r['ids'] === $want('d10', 'd15'), 'dateTo only is open-ended downward');

    $r = $ask(['dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28']);
    expect_true($r['ids'] === [], 'range with no rows returns nothing');

    $r = $ask(['dateFrom' => 'garbage', 'dateTo' => '2026-13-45']);
    expect_true($r['ids'] === $want('d10', 'd15', 'd20'), 'invalid dates are ignored, not an error');

    $r = $ask(['dateFrom' => '2026-01-12', 'accountFilter' => $accountId, 'typeFilter' => 'expense']);
    expect_true($r['ids'] === $want('d15', 'd20'), 'date filter combines with account and type filters');
} catch (Throwable $e) {
    expect_true(false, 'exception: ' . $e->getMessage());
} finally {
    $conn->rollback();
    $conn->close();
}

echo "PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
