<?php
// /api/models/StockShareLink.php

/**
 * لینک عمومی موجودی برای همکاران و فروشنده‌ها (stock.php?c=TOKEN).
 *
 * A link is live: every visit lists the stock of that moment. It never
 * expires; it stays valid until revoked. Price mode, partner percentage and
 * scope are stored server-side, so the visitor cannot change them via the URL.
 * The public list never carries cost prices, suppliers, notes or warehouse names.
 */
class StockShareLink {
    const PRICE_MODES = ['sale', 'partner', 'none'];
    const TOKEN_LENGTH = 10;

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Price of one piece for a share link.
     *  - sale:    dimension override, else sale price per meter × area
     *  - partner: own partner price per meter × area, else the sale price
     *             minus the link's partner percentage
     *  - none:    no price
     * Returns null when there is no usable price («تماس بگیرید»).
     */
    public static function piecePrice($mode, $area, $salePerMeter, $partnerPerMeter = null, $override = null, $partnerPct = 0) {
        if ($mode === 'none') {
            return null;
        }
        $area = floatval($area);
        $sale = null;
        if ($override !== null && $override !== '' && floatval($override) > 0) {
            $sale = floatval($override);
        } elseif ($area > 0 && floatval($salePerMeter) > 0) {
            $sale = floatval($salePerMeter) * $area;
        }
        if ($mode === 'partner') {
            if ($area > 0 && $partnerPerMeter !== null && floatval($partnerPerMeter) > 0) {
                return round(floatval($partnerPerMeter) * $area);
            }
            if ($sale === null) {
                return null;
            }
            $pct = max(0, min(100, floatval($partnerPct)));
            return round($sale * (1 - $pct / 100));
        }
        return $sale === null ? null : round($sale);
    }

    /** Normalizes the scope stored in `filters` (empty list = everything). */
    public static function normalizeFilters($raw) {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $ints = function ($v) {
            return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), function ($n) { return $n > 0; })));
        };
        $sizes = [];
        foreach ((is_array($raw['sizes'] ?? null) ? $raw['sizes'] : []) as $s) {
            $s = trim((string)$s);
            if ($s !== '' && mb_strlen($s) <= 50) {
                $sizes[] = $s;
            }
        }
        return [
            'warehouse_ids' => $ints($raw['warehouse_ids'] ?? []),
            'collection_ids' => $ints($raw['collection_ids'] ?? []),
            // محصولات تکی (بدون کلکسیون): only meaningful when collection_ids narrows the list.
            'include_singles' => !empty($raw['include_singles']),
            'sizes' => array_values(array_unique($sizes)),
        ];
    }

    public static function generateToken() {
        return generate_short_invoice_code(self::TOKEN_LENGTH);
    }

    public static function publicUrl($token) {
        return rtrim(app_public_base_url(), '/') . '/stock.php?c=' . rawurlencode($token);
    }

    // ---------- management (current entity) ----------

    public function listForEntity($entityId) {
        $stmt = $this->conn->prepare(
            "SELECT id, token, title, price_mode, partner_discount_pct, filters, show_serials, show_counts,
                    created_at, updated_at, revoked_at, last_viewed_at, view_count
             FROM stock_share_links WHERE entity_id = ? ORDER BY revoked_at IS NOT NULL, id DESC"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        foreach ($rows as &$row) {
            $row['filters'] = self::normalizeFilters($row['filters']);
            $row['url'] = self::publicUrl($row['token']);
        }
        unset($row);
        return $rows;
    }

    public function find($id, $entityId) {
        $stmt = $this->conn->prepare("SELECT * FROM stock_share_links WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    /**
     * Creates a link or updates one (same token, so the shared URL keeps working).
     * @return array ['success'=>bool, 'id'=>int, 'error'=>string]
     */
    public function save(array $data, $entityId, $userId = null) {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'نام لینک را وارد کنید (مثلاً نام همکار).'];
        }
        $title = mb_substr($title, 0, 255);
        $mode = in_array($data['price_mode'] ?? '', self::PRICE_MODES, true) ? $data['price_mode'] : 'sale';
        $pct = floatval(str_replace(',', '.', (string)($data['partner_discount_pct'] ?? 0)));
        if ($pct < 0 || $pct > 100) {
            return ['success' => false, 'error' => 'درصد همکاری باید بین ۰ تا ۱۰۰ باشد.'];
        }
        $filters = json_encode(self::normalizeFilters($data['filters'] ?? []), JSON_UNESCAPED_UNICODE);
        $showSerials = !empty($data['show_serials']) ? 1 : 0;
        $showCounts = !empty($data['show_counts']) ? 1 : 0;
        $id = intval($data['id'] ?? 0);

        if ($id > 0) {
            $existing = $this->find($id, $entityId);
            if (!$existing || $existing['revoked_at'] !== null) {
                return ['success' => false, 'error' => 'لینک یافت نشد یا باطل شده است.'];
            }
            $stmt = $this->conn->prepare(
                "UPDATE stock_share_links SET title = ?, price_mode = ?, partner_discount_pct = ?, filters = ?,
                        show_serials = ?, show_counts = ?, updated_at = NOW()
                 WHERE id = ? AND entity_id = ?"
            );
            $stmt->bind_param('ssdsiiii', $title, $mode, $pct, $filters, $showSerials, $showCounts, $id, $entityId);
            $stmt->execute();
            return ['success' => true, 'id' => $id];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO stock_share_links
                (entity_id, token, title, price_mode, partner_discount_pct, filters, show_serials, show_counts, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = self::generateToken();
            $stmt->bind_param('isssdsiii', $entityId, $token, $title, $mode, $pct, $filters, $showSerials, $showCounts, $userId);
            try {
                if ($stmt->execute()) {
                    return ['success' => true, 'id' => (int)$stmt->insert_id];
                }
            } catch (mysqli_sql_exception $e) {
                // duplicate token: try another one
            }
        }
        return ['success' => false, 'error' => 'ساخت لینک ناموفق بود؛ دوباره تلاش کنید.'];
    }

    public function revoke($id, $entityId) {
        $stmt = $this->conn->prepare("UPDATE stock_share_links SET revoked_at = NOW() WHERE id = ? AND entity_id = ? AND revoked_at IS NULL");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    /** Choices for the link form: warehouses, collections, sizes in stock. */
    public function scopeOptions($entityId) {
        $wh = $this->conn->prepare("SELECT id, name FROM warehouses WHERE entity_id = ? AND is_active = 1 AND warehouse_type <> 'order' ORDER BY is_default DESC, name");
        $wh->bind_param('i', $entityId);
        $wh->execute();
        $warehouses = db_stmt_to_assoc_array($wh);
        $col = $this->conn->prepare("SELECT id, name FROM product_collections WHERE entity_id = ? ORDER BY name");
        $col->bind_param('i', $entityId);
        $col->execute();
        $collections = db_stmt_to_assoc_array($col);
        $sz = $this->conn->prepare(
            "SELECT DISTINCT ii.dimensions FROM inventory_items ii
             WHERE ii.entity_id = ? AND ii.status IN ('in_stock', 'returned') AND ii.is_virtual = 0"
        );
        $sz->bind_param('i', $entityId);
        $sz->execute();
        $sizes = array_values(array_filter(array_column(db_stmt_to_assoc_array($sz), 'dimensions'), 'strlen'));
        $sizes = $this->sortSizes($sizes);
        return [
            'warehouses' => $warehouses,
            'collections' => $collections,
            'sizes' => $sizes,
        ];
    }

    private function sortSizes(array $sizes) {
        $conn = $this->conn;
        usort($sizes, function ($a, $b) use ($conn) {
            $da = dimension_area_m2($conn, $a);
            $db = dimension_area_m2($conn, $b);
            if ($da != $db) {
                return $da <=> $db;
            }
            return strcmp($a, $b);
        });
        return $sizes;
    }

    // ---------- public page ----------

    /** Active (not revoked) link by token, or null. */
    public function findActiveByToken($token) {
        $token = trim((string)$token);
        if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $token)) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT * FROM stock_share_links WHERE token = ? AND revoked_at IS NULL LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    public function recordView($id) {
        $stmt = $this->conn->prepare("UPDATE stock_share_links SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }

    /**
     * The live stock list for a link: products (with images) and, per size,
     * count, price and serial IDs. Counts/serials are left out when the link
     * hides them.
     */
    public function buildPublicList(array $link) {
        $entityId = (int)$link['entity_id'];
        $mode = in_array($link['price_mode'], self::PRICE_MODES, true) ? $link['price_mode'] : 'sale';
        $pct = floatval($link['partner_discount_pct']);
        $showSerials = (int)$link['show_serials'] === 1;
        $showCounts = (int)$link['show_counts'] === 1;
        $f = self::normalizeFilters($link['filters']);

        $where = ["ii.entity_id = ?", "ii.status IN ('in_stock', 'returned')", "ii.is_virtual = 0",
                  "(w.id IS NULL OR w.warehouse_type <> 'order')", "p.is_active = 1"];
        $types = 'i';
        $params = [$entityId];
        if ($f['warehouse_ids']) {
            $where[] = 'ii.warehouse_id IN (' . implode(',', array_fill(0, count($f['warehouse_ids']), '?')) . ')';
            $types .= str_repeat('i', count($f['warehouse_ids']));
            $params = array_merge($params, $f['warehouse_ids']);
        }
        if ($f['collection_ids'] || $f['include_singles']) {
            $or = [];
            if ($f['collection_ids']) {
                $or[] = 'p.collection_id IN (' . implode(',', array_fill(0, count($f['collection_ids']), '?')) . ')';
                $types .= str_repeat('i', count($f['collection_ids']));
                $params = array_merge($params, $f['collection_ids']);
            }
            if ($f['include_singles']) {
                $or[] = 'p.collection_id IS NULL';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($f['sizes']) {
            $where[] = 'ii.dimensions IN (' . implode(',', array_fill(0, count($f['sizes']), '?')) . ')';
            $types .= str_repeat('s', count($f['sizes']));
            $params = array_merge($params, $f['sizes']);
        }

        $sql = "SELECT ii.product_id, ii.dimensions, ii.serial_id,
                       p.name, p.design_code, p.colorway, p.collection_id, p.price, p.partner_price,
                       c.name AS collection_name, c.type AS collection_type,
                       c.price_per_meter, c.partner_price_per_meter
                FROM inventory_items ii
                JOIN products p ON p.id = ii.product_id
                LEFT JOIN product_collections c ON c.id = p.collection_id
                LEFT JOIN warehouses w ON w.id = ii.warehouse_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.name IS NULL, c.name, p.name, ii.serial_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $overrides = $this->dimensionOverrides(array_unique(array_filter(array_column($rows, 'collection_id'))));
        $areaCache = [];
        $products = [];
        $totalPieces = 0;
        foreach ($rows as $r) {
            $pid = (int)$r['product_id'];
            $dim = (string)$r['dimensions'];
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'id' => $pid,
                    'name' => $r['name'],
                    'design_code' => $r['design_code'],
                    'colorway' => $r['colorway'],
                    'collection' => $r['collection_name'],
                    'collection_type' => $r['collection_type'],
                    'images' => [],
                    'sizes' => [],
                ];
            }
            if (!isset($products[$pid]['sizes'][$dim])) {
                if (!isset($areaCache[$dim])) {
                    $areaCache[$dim] = dimension_area_m2($this->conn, $dim);
                }
                $inCollection = !empty($r['collection_id']);
                $price = self::piecePrice(
                    $mode,
                    $areaCache[$dim],
                    $inCollection ? $r['price_per_meter'] : $r['price'],
                    $inCollection ? $r['partner_price_per_meter'] : $r['partner_price'],
                    $inCollection ? ($overrides[(int)$r['collection_id']][$dim] ?? null) : null,
                    $pct
                );
                $products[$pid]['sizes'][$dim] = [
                    'dimension' => $dim,
                    'area' => round($areaCache[$dim], 3),
                    'price' => $price,
                    'count' => 0,
                    'serials' => [],
                ];
            }
            $products[$pid]['sizes'][$dim]['count']++;
            if ($showSerials && $r['serial_id'] !== null && $r['serial_id'] !== '') {
                $products[$pid]['sizes'][$dim]['serials'][] = $r['serial_id'];
            }
            $totalPieces++;
        }

        $images = $this->productImages(array_keys($products));
        $allSizes = [];
        foreach ($products as $pid => &$p) {
            $p['images'] = $images[$pid] ?? [];
            $sizes = array_values($p['sizes']);
            usort($sizes, function ($a, $b) {
                return $a['area'] == $b['area'] ? strcmp($a['dimension'], $b['dimension']) : ($a['area'] <=> $b['area']);
            });
            foreach ($sizes as &$s) {
                $allSizes[$s['dimension']] = $s['area'];
                if (!$showCounts) {
                    unset($s['count']);
                }
                if (!$showSerials) {
                    unset($s['serials']);
                }
            }
            unset($s);
            $p['sizes'] = $sizes;
        }
        unset($p);
        asort($allSizes);

        return [
            'title' => $link['title'],
            'price_mode' => $mode,
            'show_counts' => $showCounts,
            'show_serials' => $showSerials,
            'total_pieces' => $showCounts ? $totalPieces : null,
            'sizes' => array_keys($allSizes),
            'collections' => array_values(array_unique(array_filter(array_column($products, 'collection')))),
            'products' => array_values($products),
        ];
    }

    /**
     * Header of the public page: company name, phone and logo from the print
     * settings (entity settings win over global ones, as on the public invoice).
     */
    public function publicCompany($entityId) {
        $keys = ['print_company_name', 'print_company_phone', 'print_logo_url', 'print_show_logo', 'app_title'];
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $merged = [];
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($ph)");
        $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $merged[$row['setting_key']] = trim((string)$row['setting_value']);
        }
        $entityName = '';
        $entityCompany = '';
        try {
            $es = $this->conn->prepare("SELECT setting_key, setting_value FROM entity_settings WHERE entity_id = ? AND setting_key IN ($ph)");
            $es->bind_param('i' . str_repeat('s', count($keys)), $entityId, ...$keys);
            $es->execute();
            foreach (db_stmt_to_assoc_array($es) as $row) {
                if (trim((string)$row['setting_value']) !== '') {
                    $merged[$row['setting_key']] = trim((string)$row['setting_value']);
                    if ($row['setting_key'] === 'print_company_name') {
                        $entityCompany = trim((string)$row['setting_value']);
                    }
                }
            }
            $en = $this->conn->prepare('SELECT name FROM business_entities WHERE id = ? LIMIT 1');
            $en->bind_param('i', $entityId);
            $en->execute();
            $entityName = trim((string)(db_stmt_to_assoc_array($en)[0]['name'] ?? ''));
        } catch (mysqli_sql_exception $e) {
            // older schema without entity_settings
        }
        // Same order as the public invoice: entity company name, entity name, global name, app title.
        $name = $entityCompany;
        if ($name === '') $name = $entityName;
        if ($name === '') $name = $merged['print_company_name'] ?? '';
        if ($name === '') $name = $merged['app_title'] ?? '';
        $logo = (($merged['print_show_logo'] ?? '0') === '1') ? ($merged['print_logo_url'] ?? '') : '';
        if ($logo !== '' && !preg_match('#^(https?:)?//#i', $logo) && strpos($logo, 'data:') !== 0) {
            $logo = rtrim(app_public_base_url(), '/') . '/' . ltrim($logo, '/');
        }
        return [
            'name' => $name,
            'phone' => $merged['print_company_phone'] ?? '',
            'logo_url' => $logo,
        ];
    }

    /** [collection_id][dimension] => price_override */
    private function dimensionOverrides(array $collectionIds) {
        $collectionIds = array_values(array_map('intval', $collectionIds));
        if (!$collectionIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($collectionIds), '?'));
        $stmt = $this->conn->prepare("SELECT collection_id, dimension, price_override FROM collection_dimensions WHERE collection_id IN ($ph) AND price_override IS NOT NULL");
        $stmt->bind_param(str_repeat('i', count($collectionIds)), ...$collectionIds);
        $stmt->execute();
        $out = [];
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $out[(int)$row['collection_id']][$row['dimension']] = $row['price_override'];
        }
        return $out;
    }

    /** [product_id] => [['thumb'=>..., 'full'=>...], ...], primary first. */
    private function productImages(array $productIds) {
        if (!$productIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT product_id, file_path, thumb_path FROM product_images
             WHERE product_id IN ($ph) ORDER BY product_id, is_primary DESC, sort_order ASC, id ASC"
        );
        $stmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $stmt->execute();
        $out = [];
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $full = (string)$row['file_path'];
            $out[(int)$row['product_id']][] = [
                'thumb' => ($row['thumb_path'] ?? '') !== '' ? $row['thumb_path'] : $full,
                'full' => $full,
            ];
        }
        return $out;
    }
}
