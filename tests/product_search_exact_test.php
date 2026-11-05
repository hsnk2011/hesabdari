<?php
/**
 * Product list search for a piece code or a full size shows exactly that piece / size:
 * - INV-code or serial search narrows the stock column to the matching pieces only;
 * - a full size (2x3) matches that size only, not 2x3.5 or 192x302;
 * - with the «موجود» filter a product is not listed only because a sold piece had that size.
 * Run: php tests/product_search_exact_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Product.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId]];
$token = 'px' . substr(bin2hex(random_bytes(4)), 0, 8);
$products = []; $items = [];

$addProduct = function (string $name) use ($conn, $entityId, &$products): int {
    $conn->query("INSERT INTO products (entity_id, name, unit) VALUES ({$entityId}, '{$name}', 'عدد')");
    return $products[] = (int)$conn->insert_id;
};
$addItem = function (int $pid, string $dim, string $status, ?string $serial = null) use ($conn, $entityId, &$items): int {
    $s = $serial === null ? 'NULL' : "'{$serial}'";
    $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, serial_id) VALUES ({$pid}, {$entityId}, '{$dim}', '{$status}', {$s})");
    return $items[] = (int)$conn->insert_id;
};
$search = function (string $term, string $stockFilter = 'in_stock') use ($conn): array {
    return (new Product($conn))->getPaginated([
        'currentPage' => 1, 'limit' => 0, 'sortBy' => 'name', 'sortOrder' => 'ASC',
        'searchTerm' => $term, 'stockFilter' => $stockFilter, 'minArea' => '', 'maxArea' => '', 'shapeFilter' => '',
    ])['data'];
};
$row = function (array $data, int $pid): ?array {
    foreach ($data as $p) { if ((int)$p['id'] === $pid) return $p; }
    return null;
};
$dims = fn(?array $r) => array_column($r['stock'] ?? [], 'dimensions');

try {
    // A product with several sizes, each one piece in stock (two pieces of 300x250).
    $multi = $addProduct("{$token} مراکشی");
    $addItem($multi, '237x163', 'in_stock', "{$token}-a");
    $codeItem = $addItem($multi, '300x250', 'in_stock', "{$token}-b");
    $addItem($multi, '300x250', 'in_stock', "{$token}-c");
    $addItem($multi, '2x3', 'in_stock');
    $addItem($multi, '2x3.5', 'in_stock');
    $addItem($multi, '192x302', 'in_stock');
    $addItem($multi, '1.5x2.25', 'in_stock');
    // A product that only ever sold a 2x3 but still has another size in stock.
    $soldOnly = $addProduct("{$token} فروخته");
    $addItem($soldOnly, '2x3', 'sold');
    $addItem($soldOnly, '1x1.5', 'in_stock');

    $invCode = 'INV-' . str_pad((string)$codeItem, 6, '0', STR_PAD_LEFT);
    $r = $row($search($invCode), $multi);
    expect_true($r !== null, 'INV code search finds the product');
    expect_true($dims($r) === ['300x250'], 'INV code search shows only that piece\'s size: ' . json_encode($dims($r)));
    expect_true((int)($r['stock'][0]['available_quantity'] ?? 0) === 1 && (int)($r['total_stock'] ?? 0) === 1, 'INV code search counts only the searched piece');

    $r = $row($search("{$token}-a"), $multi);
    expect_true($dims($r) === ['237x163'], 'serial search shows only that piece\'s size: ' . json_encode($dims($r)));

    $r = $row($search('2x3'), $multi);
    expect_true($dims($r) === ['2x3'], 'size search 2x3 shows 2x3 only (not 2x3.5 / 192x302): ' . json_encode($dims($r)));
    $r = $row($search('2 × 3'), $multi);
    expect_true($dims($r) === ['2x3'], 'size search «2 × 3» is the same as 2x3');
    $r = $row($search('1.5x2'), $multi);
    expect_true($r === null, '1.5x2 does not match a product that only has 1.5x2.25');

    expect_true($row($search('2x3'), $soldOnly) === null, 'with «موجود», a product whose 2x3 was sold is not listed for 2x3');
    expect_true($row($search('2x3', 'sold'), $soldOnly) !== null, 'with «فروخته‌شده», the sold 2x3 is still found');

    $r = $row($search('2x'), $multi);
    expect_true($r !== null && in_array('2x3', $dims($r), true) && in_array('2x3.5', $dims($r), true), 'an incomplete size (2x) still matches as you type');

    $r = $row($search($token), $multi);
    expect_true(count($dims($r)) === 6, 'name search keeps every size of the product');
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    if ($items) { $conn->query("DELETE FROM inventory_items WHERE id IN (" . implode(',', $items) . ")"); }
    if ($products) { $conn->query("DELETE FROM products WHERE id IN (" . implode(',', $products) . ")"); }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
