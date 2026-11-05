<?php
/**
 * Public stock share links (لینک موجودی برای همکار): pricing rule, scope
 * filters, what the public list exposes, revoke/update, and the partner
 * price on the price list.
 * Run: php tests/stock_share_link_test.php
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
require_once $root . '/api/models/PriceList.php';
require_once $root . '/api/models/StockShareLink.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}

// ---------- pure pricing rule ----------
expect_true(StockShareLink::piecePrice('sale', 6, 1000000) === 6000000.0, 'sale: per meter × area');
expect_true(StockShareLink::piecePrice('sale', 6, 1000000, null, 5500000) === 5500000.0, 'sale: dimension override wins');
expect_true(StockShareLink::piecePrice('sale', 6, 0) === null, 'sale: no price → null («تماس بگیرید»)');
expect_true(StockShareLink::piecePrice('sale', 0, 1000000) === null, 'sale: unknown area → null');
expect_true(StockShareLink::piecePrice('partner', 6, 1000000, null, null, 10) === 5400000.0, 'partner: sale minus link percentage');
expect_true(StockShareLink::piecePrice('partner', 6, 1000000, 800000, null, 10) === 4800000.0, 'partner: own partner price per meter wins over percentage');
expect_true(StockShareLink::piecePrice('partner', 6, 1000000, null, 5000000, 20) === 4000000.0, 'partner: percentage applies to the override');
expect_true(StockShareLink::piecePrice('partner', 6, 0, null, null, 10) === null, 'partner: no sale and no partner price → null');
expect_true(StockShareLink::piecePrice('partner', 6, 1000000, null, null, 150) === 0.0, 'partner: percentage is capped at 100');
expect_true(StockShareLink::piecePrice('none', 6, 1000000, 800000) === null, 'none: never a price');

// ---------- filter normalization ----------
$nf = StockShareLink::normalizeFilters('{"warehouse_ids":["3","3","x",-1],"collection_ids":[5],"include_singles":1,"sizes":[" 2x3 ","","2x3"]}');
expect_true($nf['warehouse_ids'] === [3], 'filters: warehouse ids are positive unique ints');
expect_true($nf['collection_ids'] === [5] && $nf['include_singles'] === true, 'filters: collections + singles');
expect_true($nf['sizes'] === ['2x3'], 'filters: sizes trimmed and unique');
expect_true(StockShareLink::normalizeFilters(null) === ['warehouse_ids' => [], 'collection_ids' => [], 'include_singles' => false, 'sizes' => []], 'filters: empty = everything');

// ---------- database ----------
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);

$entityId = (int)($conn->query('SELECT id FROM business_entities ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
if ($entityId <= 0) {
    echo "No business entity; skipping DB checks.\n";
    exit($failed > 0 ? 1 : 0);
}
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId]];
$tag = 'ss' . substr(bin2hex(random_bytes(4)), 0, 8);
$created = ['links' => [], 'inventory' => [], 'images' => [], 'products' => [], 'collections' => [], 'warehouses' => []];

$q = function (string $sql) use ($conn) {
    if (!$conn->query($sql)) {
        throw new RuntimeException($conn->error . ' in ' . $sql);
    }
    return (int)$conn->insert_id;
};

try {
    $whA = $q("INSERT INTO warehouses (entity_id, name, is_active, warehouse_type) VALUES ({$entityId}, '{$tag}-A', 1, 'physical')");
    $whB = $q("INSERT INTO warehouses (entity_id, name, is_active, warehouse_type) VALUES ({$entityId}, '{$tag}-B', 1, 'physical')");
    $whOrder = $q("INSERT INTO warehouses (entity_id, name, is_active, warehouse_type) VALUES ({$entityId}, '{$tag}-O', 1, 'order')");
    $created['warehouses'] = [$whA, $whB, $whOrder];

    $colId = $q("INSERT INTO product_collections (name, type, price_per_meter, entity_id) VALUES ('{$tag}-col', 'machine', 1000000, {$entityId})");
    $created['collections'][] = $colId;
    $conn->query("INSERT INTO collection_dimensions (collection_id, dimension, price_override) VALUES ({$colId}, '1x1.5', 1200000)");

    $pCol = $q("INSERT INTO products (entity_id, name, unit, collection_id, design_code) VALUES ({$entityId}, '{$tag}-افشان', 'عدد', {$colId}, 'D-77')");
    $pSingle = $q("INSERT INTO products (entity_id, name, unit, price) VALUES ({$entityId}, '{$tag}-تکی', 'عدد', 2000000)");
    $pNoPrice = $q("INSERT INTO products (entity_id, name, unit) VALUES ({$entityId}, '{$tag}-بی‌قیمت', 'عدد')");
    $pInactive = $q("INSERT INTO products (entity_id, name, unit, price, is_active) VALUES ({$entityId}, '{$tag}-غیرفعال', 'عدد', 1000000, 0)");
    $created['products'] = [$pCol, $pSingle, $pNoPrice, $pInactive];

    $created['images'][] = $q("INSERT INTO product_images (product_id, file_name, file_path, thumb_path, is_primary, sort_order) VALUES ({$pCol}, 'b.jpg', 'uploads/products/{$pCol}/b.jpg', '', 0, 1)");
    $created['images'][] = $q("INSERT INTO product_images (product_id, file_name, file_path, thumb_path, is_primary, sort_order) VALUES ({$pCol}, 'a.jpg', 'uploads/products/{$pCol}/a.jpg', 'uploads/products/{$pCol}/thumbs/a.jpg', 1, 2)");

    $n = 0;
    $piece = function (int $pid, string $dim, int $wh, string $status = 'in_stock', int $virtual = 0) use ($q, $entityId, $tag, &$n, &$created) {
        $n++;
        $created['inventory'][] = $q("INSERT INTO inventory_items (product_id, entity_id, warehouse_id, dimensions, status, serial_id, cost_price, notes, is_virtual)
            VALUES ({$pid}, {$entityId}, {$wh}, '{$dim}', '{$status}', '{$tag}-{$n}', 777777, 'internal-note-{$tag}', {$virtual})");
    };
    $piece($pCol, '2x3', $whA);
    $piece($pCol, '2x3', $whA);
    $piece($pCol, '2x3', $whB);
    $piece($pCol, '1x1.5', $whA, 'returned');
    $piece($pCol, '2x3', $whA, 'sold');
    $piece($pCol, '2x3', $whA, 'returned_to_supplier');
    $piece($pCol, '2x3', $whA, 'consignment_out');
    $piece($pCol, '2x3', $whOrder);
    $piece($pCol, '2x3', $whA, 'in_stock', 1);
    $piece($pSingle, '1x2', $whB);
    $piece($pNoPrice, '2x3', $whA);
    $piece($pInactive, '2x3', $whA);

    $model = new StockShareLink($conn);
    $productsOf = function (array $list) use ($tag) {
        $out = [];
        foreach ($list['products'] as $p) {
            if (strpos($p['name'], $tag) === 0) {
                $out[$p['name']] = $p;
            }
        }
        return $out;
    };
    $sizeOf = function (array $p, string $dim) {
        foreach ($p['sizes'] as $s) {
            if ($s['dimension'] === $dim) return $s;
        }
        return null;
    };

    // Save validation
    $bad = $model->save(['title' => '  '], $entityId);
    expect_true(!$bad['success'], 'save: title is required');
    $bad = $model->save(['title' => 'x', 'price_mode' => 'partner', 'partner_discount_pct' => 120], $entityId);
    expect_true(!$bad['success'], 'save: percentage above 100 rejected');

    // Partner link, whole stock
    $res = $model->save([
        'title' => "{$tag} همکار", 'price_mode' => 'partner', 'partner_discount_pct' => 10,
        'show_serials' => 1, 'show_counts' => 1, 'filters' => [],
    ], $entityId, null);
    expect_true($res['success'], 'save: partner link created');
    $linkId = $res['id'];
    $created['links'][] = $linkId;
    $link = $model->find($linkId, $entityId);
    expect_true(strlen($link['token']) === StockShareLink::TOKEN_LENGTH, 'token has the expected length');
    expect_true($model->findActiveByToken($link['token'])['id'] == $linkId, 'active link found by token');
    expect_true($model->findActiveByToken("bad'token") === null, 'malformed token rejected');
    expect_true($model->find($linkId, $entityId + 100000) === null, 'link is scoped to its entity');

    $list = $model->buildPublicList($link);
    $ps = $productsOf($list);
    expect_true(isset($ps["{$tag}-افشان"], $ps["{$tag}-تکی"], $ps["{$tag}-بی‌قیمت"]), 'list has the in-stock products');
    expect_true(!isset($ps["{$tag}-غیرفعال"]), 'inactive products are left out');
    $afshan = $ps["{$tag}-افشان"];
    $s23 = $sizeOf($afshan, '2x3');
    expect_true($s23 !== null && $s23['count'] === 3, '2x3 counts in-stock pieces only (not sold/returned to supplier/consignment/order warehouse/virtual): ' . json_encode($s23['count'] ?? null));
    expect_true($s23['price'] === 5400000.0, 'partner price = (1,000,000 × 6) − 10%');
    expect_true(count($s23['serials']) === 3, 'serial IDs listed per size');
    $s115 = $sizeOf($afshan, '1x1.5');
    expect_true($s115 !== null && $s115['count'] === 1, 'returned pieces count as stock');
    expect_true($s115['price'] === 1080000.0, 'dimension override minus partner percentage');
    expect_true($afshan['sizes'][0]['dimension'] === '1x1.5', 'sizes sorted by area');
    expect_true($afshan['images'][0]['thumb'] === "uploads/products/{$pCol}/thumbs/a.jpg" && $afshan['images'][0]['full'] === "uploads/products/{$pCol}/a.jpg", 'primary image first, thumb preferred');
    expect_true($afshan['images'][1]['thumb'] === "uploads/products/{$pCol}/b.jpg", 'image without thumb falls back to the full file');
    expect_true($afshan['collection'] === "{$tag}-col" && $afshan['design_code'] === 'D-77', 'collection and design code shown');
    expect_true($sizeOf($ps["{$tag}-تکی"], '1x2')['price'] === 3600000.0, 'single product: product price × area − 10%');
    expect_true($sizeOf($ps["{$tag}-بی‌قیمت"], '2x3')['price'] === null, 'product without price → null');

    $json = json_encode($list, JSON_UNESCAPED_UNICODE);
    expect_true(strpos($json, '777777') === false, 'cost price never in the public list');
    expect_true(strpos($json, "internal-note-{$tag}") === false, 'piece notes never in the public list');
    expect_true(strpos($json, "{$tag}-A") === false && strpos($json, "{$tag}-O") === false, 'warehouse names never in the public list');

    // Own partner price per meter wins
    $conn->query("UPDATE product_collections SET partner_price_per_meter = 800000 WHERE id = {$colId}");
    $ps = $productsOf($model->buildPublicList($link));
    expect_true($sizeOf($ps["{$tag}-افشان"], '2x3')['price'] === 4800000.0, 'collection partner price per meter used when set');

    // Update keeps the token; scope + hidden counts/serials
    $res = $model->save([
        'id' => $linkId, 'title' => "{$tag} همکار ۲", 'price_mode' => 'sale', 'partner_discount_pct' => 0,
        'show_serials' => 0, 'show_counts' => 0,
        'filters' => ['warehouse_ids' => [$whA], 'collection_ids' => [$colId], 'sizes' => ['2x3']],
    ], $entityId);
    expect_true($res['success'] && $res['id'] === $linkId, 'update succeeds');
    $link2 = $model->find($linkId, $entityId);
    expect_true($link2['token'] === $link['token'], 'update keeps the shared URL');
    $list2 = $model->buildPublicList($link2);
    $ps2 = $productsOf($list2);
    expect_true(array_keys($ps2) === ["{$tag}-افشان"], 'collection filter drops singles and other products');
    $only = $ps2["{$tag}-افشان"]['sizes'];
    expect_true(count($only) === 1 && $only[0]['dimension'] === '2x3', 'size filter keeps only 2x3');
    expect_true(!array_key_exists('count', $only[0]) && !array_key_exists('serials', $only[0]), 'hidden counts/serials are not sent at all');
    expect_true($list2['total_pieces'] === null, 'total hidden when counts are hidden');
    expect_true($only[0]['price'] === 6000000.0, 'sale mode ignores partner prices');

    $res = $model->save(['id' => $linkId, 'title' => 'y', 'price_mode' => 'sale', 'filters' => ['collection_ids' => [$colId], 'include_singles' => 1, 'warehouse_ids' => [$whB]], 'show_counts' => 1], $entityId);
    $ps3 = $productsOf($model->buildPublicList($model->find($linkId, $entityId)));
    expect_true(isset($ps3["{$tag}-تکی"]) && $sizeOf($ps3["{$tag}-افشان"], '2x3')['count'] === 1, 'singles + warehouse B filter');

    $res = $model->save(['id' => $linkId, 'title' => 'z', 'price_mode' => 'none', 'filters' => []], $entityId);
    $ps4 = $productsOf($model->buildPublicList($model->find($linkId, $entityId)));
    expect_true($sizeOf($ps4["{$tag}-افشان"], '2x3')['price'] === null, 'none mode sends no price');

    // Scope options
    $opts = $model->scopeOptions($entityId);
    $whNames = array_column($opts['warehouses'], 'name');
    expect_true(in_array("{$tag}-A", $whNames, true) && !in_array("{$tag}-O", $whNames, true), 'options list physical warehouses only');
    expect_true(in_array('1x1.5', $opts['sizes'], true), 'options list sizes in stock');

    // Revoke
    expect_true($model->revoke($linkId, $entityId + 100000) === false, 'revoke is scoped to the entity');
    expect_true($model->revoke($linkId, $entityId) === true, 'revoke works');
    expect_true($model->findActiveByToken($link['token']) === null, 'revoked link no longer opens');
    expect_true($model->revoke($linkId, $entityId) === false, 'second revoke is a no-op');
    expect_true(!$model->save(['id' => $linkId, 'title' => 'again'], $entityId)['success'], 'revoked link cannot be edited');

    $model->recordView($linkId);
    $v = $model->find($linkId, $entityId);
    expect_true((int)$v['view_count'] === 1 && $v['last_viewed_at'] !== null, 'views are counted');

    // Price list: partner price column
    $pl = new PriceList($conn);
    $r = $pl->setPrice($colId, 'collection', '950,000', 'partner');
    $val = $conn->query("SELECT partner_price_per_meter, price_per_meter FROM product_collections WHERE id = {$colId}")->fetch_assoc();
    expect_true($r['success'] && (float)$val['partner_price_per_meter'] === 950000.0 && (float)$val['price_per_meter'] === 1000000.0, 'setPrice(partner) sets only the partner price');
    $pl->setPrice($colId, 'collection', '', 'partner');
    $val = $conn->query("SELECT partner_price_per_meter FROM product_collections WHERE id = {$colId}")->fetch_assoc();
    expect_true($val['partner_price_per_meter'] === null, 'empty partner price clears it (percentage applies)');
    $pl->setPrice($pSingle, 'product', '2500000', 'partner');
    $val = $conn->query("SELECT partner_price, price FROM products WHERE id = {$pSingle}")->fetch_assoc();
    expect_true((float)$val['partner_price'] === 2500000.0 && (float)$val['price'] === 2000000.0, 'product partner price stored separately');
    $full = $pl->getFullPriceList();
    $col = array_values(array_filter($full['collections'], function ($c) use ($colId) { return (int)$c['id'] === $colId; }))[0] ?? null;
    expect_true($col !== null && array_key_exists('partner_price_per_meter', $col), 'price list returns partner price per meter');

    expect_true(dimension_area_m2($conn, '200x300') == 6.0 && dimension_area_m2($conn, '2×3') == 6.0, 'dimension_area_m2: cm and × handled');
} catch (Throwable $e) {
    expect_true(false, 'unexpected error: ' . $e->getMessage());
} finally {
    foreach ($created['links'] as $id) $conn->query("DELETE FROM stock_share_links WHERE id = {$id}");
    if ($created['inventory']) $conn->query('DELETE FROM inventory_items WHERE id IN (' . implode(',', $created['inventory']) . ')');
    if ($created['images']) $conn->query('DELETE FROM product_images WHERE id IN (' . implode(',', $created['images']) . ')');
    if ($created['products']) $conn->query('DELETE FROM products WHERE id IN (' . implode(',', $created['products']) . ')');
    foreach ($created['collections'] as $id) {
        $conn->query("DELETE FROM collection_dimensions WHERE collection_id = {$id}");
        $conn->query("DELETE FROM product_collections WHERE id = {$id}");
    }
    if ($created['warehouses']) $conn->query('DELETE FROM warehouses WHERE id IN (' . implode(',', $created['warehouses']) . ')');
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
