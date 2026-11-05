<?php
/**
 * The returns list without a type filter mixes sales and purchase returns.
 * Each row's items (warehouse ids) and editability must match what the same
 * row gets in its own type's list — purchase returns used to be read as sales.
 * Read-only. Run: php tests/return_list_mixed_types_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/ReturnInvoice.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
$row = $conn->query("SELECT entity_id FROM return_invoices GROUP BY entity_id HAVING COUNT(DISTINCT type) = 2 LIMIT 1")->fetch_assoc();
if (!$row) {
    echo "SKIP: no entity with both sales and purchase returns\n";
    exit(0);
}
$_SESSION = ['current_entity_id' => (int)$row['entity_id']];

$list = function (array $extra) use ($conn) {
    $input = ['tableName' => 'return_invoices', 'currentPage' => 1, 'limit' => 200, 'sortBy' => 'id', 'sortOrder' => 'DESC', 'searchTerm' => ''] + $extra;
    $rows = [];
    foreach ((new ReturnInvoice($conn))->getPaginated($input)['data'] as $r) {
        $rows[(int)$r['id']] = $r;
    }
    return $rows;
};
$summary = fn(array $r) => json_encode([
    'editable' => $r['is_editable'] ?? null,
    'items' => array_map(fn($i) => [$i['id'] ?? null, $i['selected_identifiers'] ?? ($i['identifiers'] ?? null)], $r['items'] ?? []),
]);

$mixed = $list([]);
$sales = $list(['invoiceType' => 'sales']);
$purchase = $list(['invoiceType' => 'purchase']);
expect_true(count($mixed) === count($sales) + count($purchase), 'the mixed list holds every sales and purchase return');
$ids = array_keys($mixed);
$sorted = $ids; rsort($sorted);
expect_true($ids === $sorted, 'the mixed list stays sorted by id (DESC)');
foreach ($mixed as $id => $r) {
    $own = $sales[$id] ?? $purchase[$id] ?? null;
    expect_true($own !== null && $summary($r) === $summary($own), "return #{$id} ({$r['type']}) matches its own-type list");
}

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
