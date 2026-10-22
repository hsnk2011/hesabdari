<?php
/**
 * Product list size filter/search must narrow the stock column to matching dims.
 * Run: php tests/product_size_filter_stock_test.php
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
require_once $root . '/api/models/Product.php';

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
$token = 'sz' . substr(bin2hex(random_bytes(4)), 0, 8);

$created = ['products' => [], 'inventory' => []];

try {
    $conn->query("INSERT INTO products (entity_id, name, unit) VALUES ({$entityId}, '{$token}-قالی', 'عدد')");
    $productId = (int)$conn->insert_id;
    $created['products'][] = $productId;

    foreach (['2x3' => 2, '3x4' => 1, '1x1.5' => 3] as $dim => $qty) {
        for ($i = 0; $i < $qty; $i++) {
            $sid = $token . '-' . str_replace('.', '', $dim) . '-' . $i;
            $conn->query("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, serial_id)
                          VALUES ({$productId}, {$entityId}, '{$dim}', 'in_stock', '{$sid}')");
            $created['inventory'][] = (int)$conn->insert_id;
        }
    }

    $model = new Product($conn);

    $res = $model->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'name',
        'sortOrder' => 'ASC',
        'searchTerm' => '',
        'stockFilter' => 'in_stock',
        'minArea' => 5,
        'maxArea' => 7,
        'shapeFilter' => '',
    ]);
    $row = null;
    foreach ($res['data'] as $p) {
        if ((int)$p['id'] === $productId) { $row = $p; break; }
    }
    expect_true($row !== null, 'area filter finds product with 2x3');
    $dims = array_column($row['stock'] ?? [], 'dimensions');
    expect_true($dims === ['2x3'], 'area filter stock shows only 2x3, not 3x4/1x1.5: ' . json_encode($dims));
    expect_true((int)$row['total_stock'] === 2, 'total_stock matches filtered qty');

    $res2 = $model->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'name',
        'sortOrder' => 'ASC',
        'searchTerm' => '2×3',
        'stockFilter' => 'in_stock',
        'minArea' => '',
        'maxArea' => '',
        'shapeFilter' => '',
    ]);
    $row2 = null;
    foreach ($res2['data'] as $p) {
        if ((int)$p['id'] === $productId) { $row2 = $p; break; }
    }
    expect_true($row2 !== null, 'dimension search 2×3 finds product');
    $dims2 = array_column($row2['stock'] ?? [], 'dimensions');
    expect_true($dims2 === ['2x3'], 'dimension search narrows stock to 2x3: ' . json_encode($dims2));

    $res3 = $model->getPaginated([
        'currentPage' => 1,
        'limit' => 50,
        'sortBy' => 'name',
        'sortOrder' => 'ASC',
        'searchTerm' => $token,
        'stockFilter' => 'in_stock',
        'minArea' => '',
        'maxArea' => '',
        'shapeFilter' => '',
    ]);
    $row3 = null;
    foreach ($res3['data'] as $p) {
        if ((int)$p['id'] === $productId) { $row3 = $p; break; }
    }
    expect_true($row3 !== null, 'name search still works');
    expect_true(count($row3['stock'] ?? []) === 3, 'name search keeps all stock dimensions');

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    if (!empty($created['inventory'])) {
        $ids = implode(',', $created['inventory']);
        $conn->query("DELETE FROM inventory_items WHERE id IN ({$ids})");
    }
    foreach ($created['products'] as $id) {
        $conn->query("DELETE FROM products WHERE id = {$id}");
    }
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
