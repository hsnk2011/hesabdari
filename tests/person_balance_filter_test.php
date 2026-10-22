<?php
/**
 * Person lists with a balance filter (positive/negative/settled) must scan every
 * person of the entity, not just the first MAX_PAGE_SIZE rows by sort.
 * Run: php tests/person_balance_filter_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/Customer.php';
require_once $root . '/api/models/Supplier.php';
require_once $root . '/api/models/Contact.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => null];
$token = 'pbf' . substr(bin2hex(random_bytes(4)), 0, 6);
$settledCount = MAX_PAGE_SIZE + 5;

$cases = [
    'customers' => [new Customer($conn), 'nationalId'],
    'suppliers' => [new Supplier($conn), 'economicCode'],
    'contacts' => [new Contact($conn), 'nationalId'],
];

try {
    foreach ($cases as $table => [$model, $codeCol]) {
        // Settled persons sort before the debtor/creditor by name, pushing them past the cap.
        $values = [];
        for ($i = 1; $i <= $settledCount; $i++) {
            $values[] = sprintf("(%d, '%s-a%03d', '', 0)", $entityId, $token, $i);
        }
        $values[] = "({$entityId}, '{$token}-z-pos', '', 5000)";
        $values[] = "({$entityId}, '{$token}-z-neg', '', -7000)";
        $conn->query("INSERT INTO `{$table}` (entity_id, name, `{$codeCol}`, initial_balance) VALUES " . implode(',', $values));

        $base = ['searchTerm' => $token, 'sortBy' => 'name', 'sortOrder' => 'ASC', 'currentPage' => 1, 'limit' => 10];

        $pos = $model->getPaginated($base + ['balanceFilter' => 'positive']);
        $names = array_column($pos['data'], 'name');
        expect_true((int)$pos['totalRecords'] === 1 && $names === ["{$token}-z-pos"], "{$table}: positive filter finds the person sorted past MAX_PAGE_SIZE (" . json_encode($names) . ')');

        $neg = $model->getPaginated($base + ['balanceFilter' => 'negative']);
        $names = array_column($neg['data'], 'name');
        expect_true((int)$neg['totalRecords'] === 1 && $names === ["{$token}-z-neg"], "{$table}: negative filter finds the person sorted past MAX_PAGE_SIZE");

        $set = $model->getPaginated(['currentPage' => 11] + $base + ['balanceFilter' => 'settled']);
        $names = array_column($set['data'], 'name');
        expect_true((int)$set['totalRecords'] === $settledCount && $names === array_map(fn($i) => sprintf('%s-a%03d', $token, $i), range(101, $settledCount)), "{$table}: settled filter counts all {$settledCount} and pages past the cap");

        $desc = $model->getPaginated(['sortOrder' => 'DESC', 'limit' => 3] + $base + ['balanceFilter' => 'settled']);
        $names = array_column($desc['data'], 'name');
        expect_true($names === array_map(fn($i) => sprintf('%s-a%03d', $token, $i), [$settledCount, $settledCount - 1, $settledCount - 2]), "{$table}: sort order kept under the balance filter");

        $other = $model->getPaginated(['searchTerm' => $token . '-z-pos'] + $base + ['balanceFilter' => 'positive']);
        expect_true((int)$other['totalRecords'] === 1, "{$table}: search term still narrows the filtered list");

        $plain = $model->getPaginated($base);
        expect_true((int)$plain['totalRecords'] === $settledCount + 2 && count($plain['data']) === 10, "{$table}: unfiltered list still paginates normally");
    }
} catch (Throwable $e) {
    expect_true(false, 'unexpected exception: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach (array_keys($cases) as $table) {
        $conn->query("DELETE FROM `{$table}` WHERE entity_id = {$entityId} AND name LIKE '{$token}-%'");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
