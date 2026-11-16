<?php
/**
 * «انبار و محصولات» per-item view (viewMode = items): one row per rug (inventory item),
 * with the same filters as the per-product view: stock status, warehouse, collection,
 * search (شناسه انبار / name / size), area, plus sorting and pages.
 * Run: php tests/product_items_view_test.php (one transaction, always rolled back)
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
$ins = function (string $sql) use ($conn): int { $conn->query($sql); return (int)$conn->insert_id; };
$token = 'iv' . substr(bin2hex(random_bytes(4)), 0, 8);
$model = new Product($conn); // schema checks run before the transaction

$conn->begin_transaction();
try {
    $e = $ins("INSERT INTO business_entities (name) VALUES ('{$token}-entity')");
    $_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e]];
    $col = $ins("INSERT INTO product_collections (name, type, entity_id) VALUES ('{$token}-col', 'handmade', {$e})");
    $wh = $ins("INSERT INTO warehouses (entity_id, name, is_active, warehouse_type) VALUES ({$e}, '{$token}-wh', 1, 'physical')");
    $pA = $ins("INSERT INTO products (entity_id, name, collection_id) VALUES ({$e}, '{$token} الف', {$col})");
    $pB = $ins("INSERT INTO products (entity_id, name) VALUES ({$e}, '{$token} ب')");
    $item = function (int $pid, string $dim, string $status, string $serial, ?int $w) use ($ins, $e): int {
        $wv = $w === null ? 'NULL' : $w;
        return $ins("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, serial_id, warehouse_id, entry_date) VALUES ({$pid}, {$e}, '{$dim}', '{$status}', '{$serial}', {$wv}, '2026-01-01 10:00:00')");
    };
    $a1 = $item($pA, '2x3', 'in_stock', "{$token}-a1", $wh);
    $a2 = $item($pA, '3x4', 'sold', "{$token}-a2", null);
    $a3 = $item($pA, '1x2', 'returned', '', null);
    $b1 = $item($pB, '2x3', 'in_stock', "{$token}-b1", $wh);
    $b2 = $item($pB, '1.5x2', 'consignment_out', "{$token}-b2", null);

    $list = function (array $extra = []) use ($model): array {
        return $model->getPaginated($extra + [
            'viewMode' => 'items', 'currentPage' => 1, 'limit' => 0, 'sortBy' => 'name', 'sortOrder' => 'ASC',
            'searchTerm' => '', 'stockFilter' => 'in_stock', 'warehouseId' => '', 'collectionId' => '',
            'minArea' => '', 'maxArea' => '', 'shapeFilter' => '',
        ]);
    };
    $ids = fn(array $r) => array_map(fn($x) => (int)$x['id'], $r['data'] ?? []);
    $sameSet = function (array $a, array $b): bool { sort($a); sort($b); return $a === $b; };

    $r = $list();
    expect_true($sameSet($ids($r), [$a1, $a3, $b1]) && (int)$r['totalRecords'] === 3, 'in stock: one row per rug that is here (in stock or returned)');
    $row = array_values(array_filter($r['data'], fn($x) => (int)$x['id'] === $a1))[0] ?? [];
    expect_true(($row['serial_id'] ?? '') === "{$token}-a1" && ($row['product_name'] ?? '') === "{$token} الف"
        && ($row['dimensions'] ?? '') === '2x3' && ($row['warehouse_name'] ?? '') === "{$token}-wh" && ($row['collectionName'] ?? '') === "{$token}-col",
        'a row carries the شناسه انبار, product, size, warehouse and collection');
    $row3 = array_values(array_filter($r['data'], fn($x) => (int)$x['id'] === $a3))[0] ?? [];
    expect_true(($row3['serial_id'] ?? '') === 'INV-' . str_pad((string)$a3, 6, '0', STR_PAD_LEFT), 'a rug with an empty serial shows its INV- code');

    expect_true($sameSet($ids($list(['stockFilter' => 'all'])), [$a1, $a2, $a3, $b1, $b2]), 'all statuses: every rug');
    expect_true($ids($list(['stockFilter' => 'sold'])) === [$a2], 'sold: only the sold rug');
    expect_true($sameSet($ids($list(['stockFilter' => 'out_of_stock'])), [$a2, $b2]), 'not in stock: rugs that are not here');
    expect_true($sameSet($ids($list(['warehouseId' => (string)$wh])), [$a1, $b1]), 'warehouse filter');
    expect_true($sameSet($ids($list(['warehouseId' => '__no_warehouse__', 'stockFilter' => 'all'])), [$a2, $a3, $b2]), 'no-warehouse filter');
    expect_true($sameSet($ids($list(['collectionId' => (string)$col])), [$a1, $a3]), 'collection filter');
    expect_true($ids($list(['searchTerm' => "{$token}-b1"])) === [$b1], 'search by شناسه انبار finds that rug only');
    expect_true($sameSet($ids($list(['searchTerm' => '2x3'])), [$a1, $b1]), 'search by full size finds that size only');
    expect_true($sameSet($ids($list(['searchTerm' => "{$token} الف"])), [$a1, $a3]), 'search by product name lists its rugs');
    expect_true($ids($list(['maxArea' => '5'])) === [$a3], 'area filter (up to 5 m²)');

    $p2 = $list(['limit' => 2, 'currentPage' => 2, 'sortBy' => 'serial_id', 'sortOrder' => 'ASC']);
    expect_true(count($p2['data']) === 1 && (int)$p2['totalRecords'] === 3, 'pages: page 2 of 2 rows per page holds the third rug');
    $desc = $ids($list(['sortBy' => 'serial_id', 'sortOrder' => 'DESC']));
    expect_true($desc === [$b1, $a1, $a3], 'sort by شناسه انبار, descending');

    $products = $model->getPaginated(['currentPage' => 1, 'limit' => 0, 'sortBy' => 'name', 'sortOrder' => 'ASC', 'searchTerm' => '', 'stockFilter' => 'in_stock']);
    expect_true($sameSet(array_map(fn($x) => (int)$x['id'], $products['data']), [$pA, $pB]), 'without viewMode the list is still one row per product');
} catch (Throwable $ex) {
    $failed++;
    echo "[FAIL] exception: " . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine() . "\n";
} finally {
    $conn->rollback();
}
$left = (int)$conn->query("SELECT COUNT(*) FROM business_entities WHERE name = '{$token}-entity'")->fetch_row()[0];
expect_true($left === 0, 'everything was rolled back');
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
