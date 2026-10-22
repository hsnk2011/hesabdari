<?php
// /api/models/Product.php
require_once __DIR__ . '/BaseModel.php';

class Product extends BaseModel {
    protected $tableName = 'products';
    
    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'p';
        
        $this->select = "
            SELECT p.*, c.name as collectionName, c.type as collectionType,
            cat.name as categoryName,
            (SELECT COUNT(ii.id) FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status IN ('in_stock', 'returned')) as total_stock,
            (SELECT COALESCE(NULLIF(pi.thumb_path, ''), pi.file_path) FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) as primary_image,
            (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) as primary_full_image
        ";  
        $this->from = "FROM `{$this->tableName}` as p";
        $this->join = "LEFT JOIN `product_collections` c ON p.collection_id = c.id LEFT JOIN `product_categories` cat ON p.category_id = cat.id";

        $this->allowedFilters = ['p.name', 'p.description', 'c.name', 'p.design_code', 'p.colorway', 'p.sku', 'cat.name'];
        $this->allowedSorts = ['id', 'name', 'collectionName', 'total_stock', 'price', 'categoryName'];
    }
    
    protected function getOrderByClause($sortBy) {
        if ($sortBy === 'total_stock') return 'total_stock';
        if ($sortBy === 'collectionName') return 'c.name';
        if ($sortBy === 'categoryName') return 'cat.name';
        return "p.`{$sortBy}`";
    }

    public function getPaginated($input) {
        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : DEFAULT_PAGE_SIZE;
        $offset = ($page - 1) * $limit;

        $defaultSort = !empty($this->allowedSorts) ? $this->allowedSorts[0] : 'id';
        $sortBy = in_array($input['sortBy'] ?? $defaultSort, $this->allowedSorts) ? ($input['sortBy'] ?? $defaultSort) : $defaultSort;
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        $searchTerm = $input['searchTerm'] ?? '';
        
        $finalWhere = $this->where;
        $params = [];
        $param_types = '';
        
        if ($this->hasEntityId) {
            $aliasPrefix = $this->alias ? "`{$this->alias}`." : '';
            $finalWhere .= " AND {$aliasPrefix}`entity_id` = ?";
            $params[] = $_SESSION['current_entity_id'];
            $param_types .= 'i';
        }

        $collectionId = $input['collectionId'] ?? '';
        if (!empty($collectionId) && is_numeric($collectionId)) {
            $finalWhere .= " AND p.collection_id = ?";
            $params[] = $collectionId;
            $param_types .= 'i';
        }
        
        // فیلتر موجودی + انبار (با هم خوانده می‌شوند تا «ناموجود» با فیلتر انبار تناقض نداشته باشد)
        $stockFilter = $input['stockFilter'] ?? '';
        $warehouseId = $input['warehouseId'] ?? '';
        $warehouseScopeSql = '';
        $warehouseScopeParams = [];
        $warehouseScopeTypes = '';
        $isOrderWarehouse = false;
        if ($warehouseId === '__no_warehouse__') {
            $warehouseScopeSql = " AND (ii.warehouse_id IS NULL OR ii.warehouse_id = 0)";
        } else if (!empty($warehouseId) && is_numeric($warehouseId)) {
            $warehouseScopeSql = " AND ii.warehouse_id = ?";
            $warehouseScopeParams[] = intval($warehouseId);
            $warehouseScopeTypes = 'i';
            $whTypeStmt = $this->conn->prepare("SELECT warehouse_type FROM warehouses WHERE id = ? AND entity_id = ? LIMIT 1");
            $wid = intval($warehouseId);
            $eid = intval($_SESSION['current_entity_id'] ?? 0);
            $whTypeStmt->bind_param('ii', $wid, $eid);
            $whTypeStmt->execute();
            $whTypeRow = db_stmt_to_assoc_array($whTypeStmt);
            $isOrderWarehouse = !empty($whTypeRow[0]['warehouse_type']) && $whTypeRow[0]['warehouse_type'] === 'order';
        }

        // انبار سفارش معمولاً فرش فروخته‌شدهِ تحویل‌نشده دارد؛ برای فیلتر «موجود/همه» آن‌ها را هم حساب کن.
        $presentStatusesSql = $isOrderWarehouse
            ? "ii.status IN ('in_stock', 'returned', 'sold')"
            : "ii.status IN ('in_stock', 'returned')";
        $availableStatusesSql = "ii.status IN ('in_stock', 'returned')";

        $bindWarehouseParams = function () use (&$params, &$param_types, $warehouseScopeParams, $warehouseScopeTypes) {
            foreach ($warehouseScopeParams as $wp) {
                $params[] = $wp;
            }
            $param_types .= $warehouseScopeTypes;
        };

        if ($stockFilter === 'all' || $stockFilter === '') {
            if ($warehouseScopeSql !== '') {
                $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND {$presentStatusesSql}{$warehouseScopeSql})";
                $bindWarehouseParams();
            }
        } else if ($stockFilter === 'low_stock') {
            if ($warehouseScopeSql !== '') {
                $finalWhere .= " AND p.min_stock IS NOT NULL AND (
                    SELECT COUNT(*) FROM inventory_items ii
                    WHERE ii.product_id = p.id AND {$availableStatusesSql}{$warehouseScopeSql}
                ) <= p.min_stock";
                $bindWarehouseParams();
            } else {
                $finalWhere .= " AND p.min_stock IS NOT NULL AND (
                    SELECT COUNT(*) FROM inventory_items ii
                    WHERE ii.product_id = p.id AND {$availableStatusesSql}
                ) <= p.min_stock";
            }
        } else if ($stockFilter === 'out_of_stock') {
            if ($warehouseScopeSql !== '') {
                // فقط محصولاتی که در این انبار سابقه‌ای دارند ولی موجودِ قابل‌فروش ندارند
                $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id{$warehouseScopeSql})";
                $bindWarehouseParams();
                $finalWhere .= " AND NOT EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND {$availableStatusesSql}{$warehouseScopeSql})";
                $bindWarehouseParams();
            } else {
                $finalWhere .= " AND NOT EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND {$availableStatusesSql})";
            }
        } else if ($stockFilter === 'in_stock') {
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND {$presentStatusesSql}{$warehouseScopeSql})";
            $bindWarehouseParams();
        } else if (in_array($stockFilter, ['sold', 'reserved', 'consignment_out', 'returned', 'returned_to_supplier', 'damaged'], true)) {
            $statusMap = [
                'sold' => 'sold',
                'reserved' => 'reserved',
                'consignment_out' => 'consignment_out',
                'returned' => 'returned',
                'returned_to_supplier' => 'returned_to_supplier',
                'damaged' => 'damaged',
            ];
            $statusValue = $statusMap[$stockFilter];
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status = ?{$warehouseScopeSql})";
            $params[] = $statusValue;
            $param_types .= 's';
            $bindWarehouseParams();
        } else if ($warehouseScopeSql !== '') {
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND {$presentStatusesSql}{$warehouseScopeSql})";
            $bindWarehouseParams();
        }
        
        $filterType = $input['filterType'] ?? '';
        if ($filterType === 'unlinked_items') {
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.purchase_invoice_item_id IS NULL AND ii.status = 'in_stock')";
        } else if ($filterType === 'no_purchase_history') {
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status = 'in_stock')";
            $finalWhere .= " AND NOT EXISTS (SELECT 1 FROM purchase_invoice_items pii WHERE pii.productId = p.id)";
        } 
        // *** START: New filter logic for the new smart alert ***
        else if ($filterType === 'sold_without_purchase') {
            $finalWhere .= " AND EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status = 'sold' AND ii.purchase_invoice_item_id IS NULL)";
        }
        // *** END: New filter logic ***


        $dimSearchNeedle = $this->normalizeDimensionSearch($searchTerm);
        $dimKind = $this->dimensionSearchKind($searchTerm);
        if (!empty($searchTerm) && !empty($this->allowedFilters)) {
            $search_parts = [];
            foreach ($this->allowedFilters as $col) {
                $search_parts[] = "$col LIKE ?";
            }
            $wildcard = "%{$searchTerm}%";
            $normDimSql = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(ii_search.dimensions), '×', 'x'), '*', 'x'), ' ', ''), 'در', 'x')";
            // A full size (2x3) is that size only, not 2x3.5 or 192x302; a size being typed (2x) is a prefix.
            if ($dimKind === 'full') {
                $dimSql = "{$normDimSql} = ?";
                $dimParams = [$dimSearchNeedle];
            } else if ($dimKind === 'partial') {
                $dimSql = "{$normDimSql} LIKE ?";
                $dimParams = [$dimSearchNeedle . '%'];
            } else {
                $dimSql = "ii_search.dimensions LIKE ? OR {$normDimSql} LIKE ?";
                $dimParams = [$wildcard, '%' . ($dimSearchNeedle !== '' ? $dimSearchNeedle : mb_strtolower($searchTerm, 'UTF-8')) . '%'];
            }
            // The matching piece itself must pass the stock/warehouse filter: with «موجود»,
            // a product is not listed only because a sold piece once had that size or code.
            $searchScope = $this->searchPieceScope($stockFilter, $isOrderWarehouse, $warehouseScopeSql, $warehouseScopeParams, $warehouseScopeTypes);
            // شناسه انبار + ابعاد (سایز) — با نرمال‌سازی × / فاصله
            $search_parts[] = "EXISTS (
                SELECT 1 FROM inventory_items ii_search
                WHERE ii_search.product_id = p.id AND ii_search.entity_id = p.entity_id{$searchScope['sql']}
                  AND (
                    ii_search.serial_id LIKE ?
                    OR CONCAT('INV-', LPAD(ii_search.id, 6, '0')) LIKE ?
                    OR {$dimSql}
                  )
            )";
            $finalWhere .= " AND (" . implode(' OR ', $search_parts) . ")";

            foreach ($this->allowedFilters as $_) {
                $params[] = $wildcard;
                $param_types .= 's';
            }
            foreach ($searchScope['params'] as $sp) {
                $params[] = $sp;
            }
            $param_types .= $searchScope['types'];
            $params[] = $wildcard;
            $params[] = $wildcard;
            $param_types .= 'ss';
            foreach ($dimParams as $dp) {
                $params[] = $dp;
                $param_types .= 's';
            }
        }
        
        $orderByClause = $this->getOrderByClause($sortBy);

        $bind_params_safely = function($stmt, $types, &$params) {
            if (!empty($params)) {
                $refs = [];
                foreach ($params as $key => $value) $refs[$key] = &$params[$key];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            }
        };

        $count_sql = "SELECT COUNT(*) as total {$this->from} {$this->join} {$finalWhere} {$this->groupBy}";
        $stmt_count = $this->conn->prepare($count_sql);
        $bind_params_safely($stmt_count, $param_types, $params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        $data_sql = "{$this->select} {$this->from} {$this->join} {$finalWhere} {$this->groupBy} ORDER BY {$orderByClause} {$sortOrder}";

        // فیلتر متراژ / شکل (کناره): ابعاد رشته آزاد است، پس در PHP محاسبه و فیلتر می‌شود
        $minArea = isset($input['minArea']) && $input['minArea'] !== '' ? floatval($input['minArea']) : null;
        $maxArea = isset($input['maxArea']) && $input['maxArea'] !== '' ? floatval($input['maxArea']) : null;
        if ($minArea !== null && !($minArea >= 0)) $minArea = null;
        if ($maxArea !== null && !($maxArea > 0)) $maxArea = null;
        $areaFilterActive = ($minArea !== null && $minArea > 0) || ($maxArea !== null && $maxArea > 0);
        $shapeFilter = trim((string)($input['shapeFilter'] ?? ''));
        $kenarehActive = ($shapeFilter === 'kenareh');
        $kenarehMinRatio = kenareh_min_aspect_ratio();

        $stockFilterOpts = [
            'minArea' => $minArea,
            'maxArea' => $maxArea,
            'areaFilterActive' => $areaFilterActive,
            'kenarehActive' => $kenarehActive,
            'kenarehMinRatio' => $kenarehMinRatio,
            'dimSearch' => $dimKind !== '' ? $dimSearchNeedle : '',
            'dimKind' => $dimKind,
            'rawSearch' => trim((string)$searchTerm),
        ];
        // A text search that is not a size may have matched a piece code (INV-…, serial) or size text.
        $pieceSearch = ($dimKind === '' && trim((string)$searchTerm) !== '') ? trim((string)$searchTerm) : '';

        if ($areaFilterActive || $kenarehActive) {
            // همه ردیف‌های منطبق را می‌گیریم، بعد از اتصال موجودی فیلتر و صفحه‌بندی می‌کنیم
            $stmt_data = $this->conn->prepare($data_sql);
            $bind_params_safely($stmt_data, $param_types, $params);
            $stmt_data->execute();
            $allData = db_stmt_to_assoc_array($stmt_data);
            $allData = $this->attachInventoryDetails($allData);
            $allData = $this->narrowProductStockToFilters($allData, $stockFilterOpts);
            if ($pieceSearch !== '') {
                $allData = $this->narrowStockToMatchingPieces($allData, $pieceSearch);
            }
            $filtered = array_values(array_filter($allData, function ($product) {
                return !empty($product['stock']);
            }));
            $totalRecords = count($filtered);
            if ($limit > 0) {
                $data = array_slice($filtered, $offset, $limit);
            } else {
                $data = $filtered;
            }

            return ['data' => $data, 'totalRecords' => $totalRecords];
        }

        if ($limit > 0) {
            $data_sql .= " LIMIT ? OFFSET ?";
            $data_params = $params;
            $data_params[] = $limit;
            $data_params[] = $offset;
            $data_param_types = $param_types . 'ii';
        } else {
            $data_params = $params;
            $data_param_types = $param_types;
        }
        
        $stmt_data = $this->conn->prepare($data_sql);
        $bind_params_safely($stmt_data, $data_param_types, $data_params);
        $stmt_data->execute();
        
        $data = db_stmt_to_assoc_array($stmt_data);
        
        $data = $this->attachInventoryDetails($data);
        if ($stockFilterOpts['dimSearch'] !== '') {
            $data = $this->narrowProductStockToFilters($data, $stockFilterOpts);
        }
        if ($pieceSearch !== '') {
            $data = $this->narrowStockToMatchingPieces($data, $pieceSearch);
        }

        return ['data' => $data, 'totalRecords' => $totalRecords];
    }

    /**
     * Status/warehouse condition (alias ii_search) a searched piece must meet, mirroring the stock filter.
     * @return array{sql: string, params: array, types: string}
     */
    private function searchPieceScope($stockFilter, $isOrderWarehouse, $warehouseScopeSql, array $warehouseScopeParams, $warehouseScopeTypes) {
        $sql = '';
        $params = [];
        $types = '';
        if ($stockFilter === 'in_stock') {
            $sql = $isOrderWarehouse
                ? " AND ii_search.status IN ('in_stock', 'returned', 'sold')"
                : " AND ii_search.status IN ('in_stock', 'returned')";
        } else if ($stockFilter === 'low_stock') {
            $sql = " AND ii_search.status IN ('in_stock', 'returned')";
        } else if (in_array($stockFilter, ['sold', 'reserved', 'consignment_out', 'returned', 'returned_to_supplier', 'damaged'], true)) {
            $sql = " AND ii_search.status = ?";
            $params[] = $stockFilter;
            $types .= 's';
        }
        if ($warehouseScopeSql !== '') {
            $sql .= str_replace('ii.', 'ii_search.', $warehouseScopeSql);
            foreach ($warehouseScopeParams as $wp) {
                $params[] = $wp;
            }
            $types .= $warehouseScopeTypes;
        }
        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }

    /**
     * For a product found by a piece (INV code, serial or size text) rather than by its own
     * name/code, keep only those pieces in the stock column, with their counts and warehouses.
     */
    private function narrowStockToMatchingPieces(array $products, $term) {
        if (empty($products)) {
            return $products;
        }
        $needle = mb_strtolower($term, 'UTF-8');
        $ids = [];
        foreach ($products as $p) {
            $text = mb_strtolower(implode(' ', [
                $p['name'] ?? '', $p['description'] ?? '', $p['collectionName'] ?? '', $p['design_code'] ?? '',
                $p['colorway'] ?? '', $p['sku'] ?? '', $p['categoryName'] ?? '',
            ]), 'UTF-8');
            if (mb_strpos($text, $needle) === false) {
                $ids[] = (int)$p['id'];
            }
        }
        if (empty($ids)) {
            return $products;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $entity_id = (int)($_SESSION['current_entity_id'] ?? 0);
        $typeCol = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
        $hasWarehouseType = $typeCol && $typeCol->num_rows > 0;
        $warehouseTypeSelect = $hasWarehouseType ? 'w.warehouse_type' : "'physical' AS warehouse_type";
        $orderSoldClause = $hasWarehouseType ? "OR (w.warehouse_type = 'order' AND ii.status = 'sold')" : '';
        $wildcard = "%{$term}%";
        $stmt = $this->conn->prepare("
            SELECT ii.product_id, ii.dimensions, ii.serial_id, ii.status, ii.purchase_invoice_item_id,
                   w.id AS warehouse_id, w.name AS warehouse_name, {$warehouseTypeSelect}
            FROM inventory_items ii
            LEFT JOIN warehouses w ON w.id = ii.warehouse_id
            WHERE ii.entity_id = ? AND ii.product_id IN ($placeholders)
              AND (ii.status IN ('in_stock', 'returned') {$orderSoldClause})
              AND (ii.serial_id LIKE ? OR CONCAT('INV-', LPAD(ii.id, 6, '0')) LIKE ? OR ii.dimensions LIKE ?)
            ORDER BY ii.id
        ");
        $stmt->bind_param('i' . str_repeat('i', count($ids)) . 'sss', $entity_id, ...array_merge($ids, [$wildcard, $wildcard, $wildcard]));
        $stmt->execute();
        $pieces = [];
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $pieces[(int)$row['product_id']][(string)$row['dimensions']][] = $row;
        }

        $narrow = array_flip($ids);
        foreach ($products as &$product) {
            $pid = (int)$product['id'];
            if (!isset($narrow[$pid])) {
                continue;
            }
            $kept = [];
            $warehouses = [];
            foreach ($product['stock'] ?? [] as $s) {
                $matched = $pieces[$pid][(string)($s['dimensions'] ?? '')] ?? [];
                if (empty($matched)) {
                    continue;
                }
                $s['total_quantity'] = count($matched);
                $s['available_quantity'] = 0;
                $s['order_quantity'] = 0;
                $s['without_purchase_record'] = 0;
                $s['serial_ids'] = [];
                $s['available_serial_ids'] = [];
                foreach ($matched as $m) {
                    $available = in_array($m['status'], ['in_stock', 'returned'], true);
                    if ($available) {
                        $s['available_quantity']++;
                    }
                    if (($m['warehouse_type'] ?? '') === 'order') {
                        $s['order_quantity']++;
                    }
                    if ($m['purchase_invoice_item_id'] === null) {
                        $s['without_purchase_record']++;
                    }
                    if ($m['serial_id'] !== null) {
                        $s['serial_ids'][] = $m['serial_id'];
                        if ($m['status'] !== 'sold') {
                            $s['available_serial_ids'][] = $m['serial_id'];
                        }
                    }
                    $wid = $m['warehouse_id'] !== null ? (string)$m['warehouse_id'] : 'none';
                    if (!isset($warehouses[$wid])) {
                        $warehouses[$wid] = [
                            'id' => $m['warehouse_id'],
                            'name' => $m['warehouse_name'] ?: 'بدون انبار',
                            'type' => $m['warehouse_type'] ?? 'physical',
                            'count' => 0,
                        ];
                    }
                    $warehouses[$wid]['count']++;
                }
                $kept[] = $s;
            }
            $product['stock'] = $kept;
            $product['total_stock'] = array_sum(array_column($kept, 'available_quantity'));
            $product['warehouses'] = array_values($warehouses);
        }
        unset($product);
        return $products;
    }

    /** نرمال‌سازی رشته ابعاد برای جستجو: 2×3 / 2 X 3 / 2*3 → 2x3 */
    public function normalizeDimensionSearch($term) {
        $t = mb_strtolower(trim((string)$term), 'UTF-8');
        if ($t === '') {
            return '';
        }
        $t = strtr($t, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.']);
        $t = str_replace(['×', '✕', '✖', '*', 'X', 'х', 'در'], ['x', 'x', 'x', 'x', 'x', 'x', 'x'], $t);
        $t = preg_replace('/\s+/u', '', $t);
        return $t;
    }

    /**
     * آیا عبارت جستجو سایز/ابعاد است (نه نام یا کد محصول):
     * 'full' = سایز کامل (2x3، 1.5×2.25)، 'partial' = سایزِ در حال تایپ (2x)،
     * 'named' = نام سایز بدون عدد از نقشه متراژ (پادری)، '' = سایز نیست.
     * Only digits around an x count: codes such as «px3a» or «box2» are not sizes.
     */
    public function dimensionSearchKind($term) {
        $raw = trim((string)$term);
        if ($raw === '') {
            return '';
        }
        $norm = $this->normalizeDimensionSearch($raw);
        if (preg_match('/^\d+(\.\d+)?x\d+(\.\d+)?$/', $norm)) {
            return 'full';
        }
        if (preg_match('/^\d+(\.\d+)?x$/', $norm)) {
            return 'partial';
        }
        // نام‌های متداول بدون عدد (مثل پادری) از نقشه متراژ
        $mapJson = get_app_setting($this->conn, 'dimension_area_map', '{"پادری":0.4}');
        $map = json_decode((string)$mapJson, true);
        if (is_array($map)) {
            foreach (array_keys($map) as $named) {
                if (mb_strtolower((string)$named, 'UTF-8') === mb_strtolower($raw, 'UTF-8')) {
                    return 'named';
                }
            }
        }
        return '';
    }

    private function dimensionMatchesStockFilters($dims, array $opts) {
        $dims = (string)$dims;
        $dimSearch = (string)($opts['dimSearch'] ?? '');
        $rawSearch = (string)($opts['rawSearch'] ?? '');
        if ($dimSearch !== '') {
            $normDim = $this->normalizeDimensionSearch($dims);
            $kind = (string)($opts['dimKind'] ?? '');
            if ($kind === 'full') {
                $hit = $normDim === $dimSearch;
            } else if ($kind === 'partial') {
                $hit = $normDim !== '' && strpos($normDim, $dimSearch) === 0;
            } else {
                $hit = ($normDim !== '' && strpos($normDim, $dimSearch) !== false)
                    || ($rawSearch !== '' && mb_stripos($dims, $rawSearch, 0, 'UTF-8') !== false);
            }
            if (!$hit) {
                return false;
            }
        }
        if (!empty($opts['areaFilterActive'])) {
            $area = dimension_area($this->conn, $dims);
            if (!($area > 0)) {
                return false;
            }
            $minArea = $opts['minArea'] ?? null;
            $maxArea = $opts['maxArea'] ?? null;
            if ($minArea !== null && $minArea > 0 && $area < $minArea) {
                return false;
            }
            if ($maxArea !== null && $maxArea > 0 && $area > $maxArea) {
                return false;
            }
        }
        if (!empty($opts['kenarehActive'])) {
            $ratio = dimension_aspect_ratio($this->conn, $dims);
            $minRatio = $opts['kenarehMinRatio'] ?? kenareh_min_aspect_ratio();
            if (!($ratio >= $minRatio)) {
                return false;
            }
        }
        return true;
    }

    /**
     * ستون موجودی را فقط به ابعاد منطبق با فیلتر/جستجوی سایز محدود می‌کند
     * و total_stock / انبارها را بر همان اساس بازمحاسبه می‌کند.
     */
    private function narrowProductStockToFilters(array $products, array $opts) {
        $shouldNarrow = !empty($opts['areaFilterActive'])
            || !empty($opts['kenarehActive'])
            || ((string)($opts['dimSearch'] ?? '') !== '');
        if (!$shouldNarrow || empty($products)) {
            return $products;
        }

        $matchedDimsByProduct = [];
        foreach ($products as &$product) {
            $kept = [];
            foreach ($product['stock'] ?? [] as $s) {
                $dims = $s['dimensions'] ?? '';
                if ($this->dimensionMatchesStockFilters($dims, $opts)) {
                    $kept[] = $s;
                    $matchedDimsByProduct[(int)$product['id']][$dims] = true;
                }
            }
            $product['stock'] = $kept;
            $total = 0;
            foreach ($kept as $s) {
                $total += (int)($s['available_quantity'] ?? $s['total_quantity'] ?? 0);
            }
            $product['total_stock'] = $total;
        }
        unset($product);

        // بازسازی شمارش انبارها فقط برای ابعاد باقی‌مانده
        $productIds = array_keys($matchedDimsByProduct);
        if (empty($productIds)) {
            foreach ($products as &$product) {
                $product['warehouses'] = [];
            }
            unset($product);
            return $products;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        $entity_id = (int)($_SESSION['current_entity_id'] ?? 0);
        $typeCol = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
        $hasWarehouseType = $typeCol && $typeCol->num_rows > 0;
        $warehouseTypeSelect = $hasWarehouseType ? 'w.warehouse_type' : "'physical' AS warehouse_type";
        $orderSoldClause = $hasWarehouseType
            ? "OR (w.warehouse_type = 'order' AND ii.status = 'sold')"
            : '';
        $stmt = $this->conn->prepare("
            SELECT ii.product_id, ii.dimensions, w.id AS warehouse_id, w.name AS warehouse_name,
                   {$warehouseTypeSelect}, COUNT(ii.id) AS item_count
            FROM inventory_items ii
            LEFT JOIN warehouses w ON w.id = ii.warehouse_id
            WHERE ii.entity_id = ? AND ii.product_id IN ($placeholders)
              AND (ii.status IN ('in_stock', 'returned') {$orderSoldClause})
            GROUP BY ii.product_id, ii.dimensions, ii.warehouse_id, w.name" .
            ($hasWarehouseType ? ', w.warehouse_type' : '')
        );
        $stmt->bind_param('i' . $types, $entity_id, ...$productIds);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $warehousesByProduct = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            $dims = $row['dimensions'] ?? '';
            if (empty($matchedDimsByProduct[$pid][$dims])) {
                continue;
            }
            $wid = $row['warehouse_id'] !== null ? (string)$row['warehouse_id'] : 'none';
            if (!isset($warehousesByProduct[$pid][$wid])) {
                $warehousesByProduct[$pid][$wid] = [
                    'id' => $row['warehouse_id'],
                    'name' => $row['warehouse_name'] ?: 'بدون انبار',
                    'type' => $row['warehouse_type'] ?? 'physical',
                    'count' => 0,
                ];
            }
            $warehousesByProduct[$pid][$wid]['count'] += (int)$row['item_count'];
        }

        foreach ($products as &$product) {
            $pid = (int)$product['id'];
            $product['warehouses'] = array_values($warehousesByProduct[$pid] ?? []);
        }
        unset($product);

        return $products;
    }

    private function attachInventoryDetails($products) {
        if (empty($products)) return [];

        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $entity_id = $_SESSION['current_entity_id'];
        
        // اطلاعات موجودی (انبار سفارش: فرش‌های سفارش/فروخته‌شدهِ تحویل‌نشده هم در ابعاد می‌آیند)
        $typeCol = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
        $hasWarehouseType = $typeCol && $typeCol->num_rows > 0;
        $orderJoin = $hasWarehouseType
            ? "LEFT JOIN `warehouses` w_stock ON ii.warehouse_id = w_stock.id"
            : '';
        $orderQtySelect = $hasWarehouseType
            ? "SUM(CASE WHEN w_stock.warehouse_type = 'order' THEN 1 ELSE 0 END) as order_quantity"
            : "0 as order_quantity";
        $stockStatusWhere = $hasWarehouseType
            ? "(ii.status IN ('in_stock', 'returned') OR (w_stock.warehouse_type = 'order' AND ii.status = 'sold'))"
            : "ii.status IN ('in_stock', 'returned')";

        $stmt_stock_summary = $this->conn->prepare("
            SELECT 
                ii.product_id, 
                ii.dimensions, 
                COUNT(ii.id) as total_quantity,
                SUM(CASE WHEN ii.status IN ('in_stock', 'returned') THEN 1 ELSE 0 END) as available_quantity,
                {$orderQtySelect},
                COUNT(CASE WHEN ii.purchase_invoice_item_id IS NULL THEN 1 END) as without_purchase_record
            FROM `inventory_items` ii
            {$orderJoin}
            WHERE ii.entity_id = ? AND ii.product_id IN ($placeholders) AND {$stockStatusWhere}
            GROUP BY ii.product_id, ii.dimensions
        ");

        $stmt_stock_summary->bind_param('i' . $types, $entity_id, ...$productIds);
        $stmt_stock_summary->execute();
        $stock_summary = db_stmt_to_assoc_array($stmt_stock_summary);

        $warehouseTypeSelect = $hasWarehouseType ? 'w.warehouse_type' : "'physical' AS warehouse_type";
        $warehouseGroupBy = $hasWarehouseType ? 'ii.product_id, ii.warehouse_id, w.name, w.warehouse_type' : 'ii.product_id, ii.warehouse_id, w.name';
        $orderSoldClause = $hasWarehouseType
            ? "OR (w.warehouse_type = 'order' AND ii.status = 'sold')"
            : '';
        // اطلاعات انبارها برای هر محصول (انبار سفارش: فرش فروخته‌شدهِ تحویل‌نشده هم شمرده می‌شود)
        $stmt_warehouses = $this->conn->prepare("
            SELECT 
                ii.product_id, 
                w.id as warehouse_id,
                w.name as warehouse_name,
                {$warehouseTypeSelect},
                COUNT(ii.id) as item_count
            FROM `inventory_items` ii
            LEFT JOIN `warehouses` w ON ii.warehouse_id = w.id
            WHERE ii.entity_id = ? AND ii.product_id IN ($placeholders)
              AND (
                ii.status IN ('in_stock', 'returned')
                {$orderSoldClause}
              )
            GROUP BY {$warehouseGroupBy}
        ");
        $stmt_warehouses->bind_param('i' . $types, $entity_id, ...$productIds);
        $stmt_warehouses->execute();
        $warehouse_summary = db_stmt_to_assoc_array($stmt_warehouses);

        // شناسه‌های انبار (serial_id) برای هر محصول و ابعاد — شامل سفارش در انبار سفارش
        $serialStatusWhere = $hasWarehouseType
            ? "(ii.status IN ('in_stock', 'returned') OR (w_ser.warehouse_type = 'order' AND ii.status = 'sold'))"
            : "ii.status IN ('in_stock', 'returned')";
        $serialJoin = $hasWarehouseType ? "LEFT JOIN `warehouses` w_ser ON ii.warehouse_id = w_ser.id" : '';
        $stmt_serials = $this->conn->prepare("
            SELECT ii.product_id, ii.dimensions, ii.serial_id, ii.status
            FROM `inventory_items` ii
            {$serialJoin}
            WHERE ii.entity_id = ? AND ii.product_id IN ($placeholders) AND ii.serial_id IS NOT NULL
              AND {$serialStatusWhere}
            ORDER BY ii.product_id, ii.dimensions, ii.id
        ");
        $stmt_serials->bind_param('i' . $types, $entity_id, ...$productIds);
        $stmt_serials->execute();
        $serial_items = db_stmt_to_assoc_array($stmt_serials);

        // شناسه‌های دستی (غیر خودکار) برای جستجو در گزارش کاردکس
        $stmt_manual_serials = $this->conn->prepare("
            SELECT product_id, serial_id
            FROM `inventory_items`
            WHERE entity_id = ? AND product_id IN ($placeholders) AND serial_id IS NOT NULL AND serial_id NOT LIKE 'INV-%'
            ORDER BY product_id, id
        ");
        $stmt_manual_serials->bind_param('i' . $types, $entity_id, ...$productIds);
        $stmt_manual_serials->execute();
        $manual_serial_items = db_stmt_to_assoc_array($stmt_manual_serials);

        foreach ($products as &$product) {
            $product['stock'] = [];
            $product['manual_serial_ids'] = [];
            foreach ($manual_serial_items as $msi) {
                if ($msi['product_id'] == $product['id']) {
                    $product['manual_serial_ids'][] = $msi['serial_id'];
                }
            }
            $product['manual_serial_ids'] = array_values(array_unique($product['manual_serial_ids']));
            foreach ($stock_summary as $stock_item) {
                if ($stock_item['product_id'] == $product['id']) {
                    // اضافه کردن شناسه‌های انبار به هر ابعاد
                    // serial_ids: همه (شامل سفارشِ فروخته‌شده در انبار سفارش) — available_serial_ids: فقط قابل فروش
                    $stock_item['serial_ids'] = [];
                    $stock_item['available_serial_ids'] = [];
                    foreach ($serial_items as $si) {
                        if ($si['product_id'] == $product['id'] && $si['dimensions'] == $stock_item['dimensions']) {
                            $stock_item['serial_ids'][] = $si['serial_id'];
                            if ($si['status'] !== 'sold') {
                                $stock_item['available_serial_ids'][] = $si['serial_id'];
                            }
                        }
                    }
                    $product['stock'][] = $stock_item;
                }
            }
            
            // انبارها
            $product['warehouses'] = [];
            foreach ($warehouse_summary as $wh) {
                if ($wh['product_id'] == $product['id']) {
                    $product['warehouses'][] = [
                        'id' => $wh['warehouse_id'],
                        'name' => $wh['warehouse_name'] ?: 'بدون انبار',
                        'type' => $wh['warehouse_type'] ?? 'physical',
                        'count' => $wh['item_count']
                    ];
                }
            }
        }
        return $products;
    }

    public function save($data, $manageTransaction = true) {
        if (empty(trim($data['name']))) {
            return ['error' => 'نام طرح محصول الزامی است.', 'statusCode' => 400];
        }

        // Inside an open invoice transaction, a second begin/commit/rollback
        // commits or undoes the invoice itself. Caller owns the transaction then.
        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $productId = $data['id'] ?? null;
            $name = trim($data['name']);
            $entity_id = $_SESSION['current_entity_id'];

            // Explicit duplicate name check
            $stmt_check = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE name = ? AND entity_id = ? AND id != ?");
            $check_id = $productId ?: 0;
            $stmt_check->bind_param("sii", $name, $entity_id, $check_id);
            $stmt_check->execute();
            if ($stmt_check->fetch()) {
                $stmt_check->close();
                throw new Exception('محصولی با این نام در این مجموعه تجاری از قبل وجود دارد.', 409);
            }
            $stmt_check->close();
            
            $description = $data['description'] ?? null;
            $design_code = $data['design_code'] ?? null;
            $colorway = $data['colorway'] ?? null;

            $collection_id = null;
            if (isset($data['collection_id']) && is_numeric($data['collection_id']) && $data['collection_id'] > 0) {
                $collection_id = intval($data['collection_id']);
            }

            $price = null;
            if ($collection_id === null) {
                if (isset($data['price']) && is_numeric(str_replace(',', '', $data['price']))) {
                     $price = floatval(str_replace(',', '', $data['price']));
                }
            }
            
            $weight = isset($data['weight']) && is_numeric($data['weight']) ? floatval($data['weight']) : null;
            
            if ($productId) {
                $stmt = $this->conn->prepare("
                    UPDATE `{$this->tableName}` SET 
                        name = ?, description = ?, collection_id = ?,
                        design_code = ?, colorway = ?, price = ?, weight = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ssissddi", 
                    $name, $description, $collection_id,
                    $design_code, $colorway, $price, $weight,
                    $productId
                );
            } else {
                $stmt = $this->conn->prepare("
                    INSERT INTO `{$this->tableName}` 
                    (entity_id, name, description, collection_id, design_code, colorway, price, weight) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ississdd", 
                    $entity_id, $name, $description, $collection_id,
                    $design_code, $colorway, $price, $weight
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            if (!$productId) {
                $productId = $this->conn->insert_id;
            }
            $stmt->close();
            
            if ($manageTransaction) {
                $this->conn->commit();
            }
            
            return ['success' => true, 'id' => $productId];

        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $statusCode = ($e->getCode() == 409) ? 409 : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
    
    public function delete($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }

        // Check usage in sales invoice items
        $stmt_sales = $this->conn->prepare("SELECT 1 FROM sales_invoice_items WHERE productId = ? LIMIT 1");
        $stmt_sales->bind_param("i", $id);
        $stmt_sales->execute();
        $sales_usage = db_stmt_to_assoc_array($stmt_sales);

        // Check usage in purchase invoice items
        $stmt_purchase = $this->conn->prepare("SELECT 1 FROM purchase_invoice_items WHERE productId = ? LIMIT 1");
        $stmt_purchase->bind_param("i", $id);
        $stmt_purchase->execute();
        $purchase_usage = db_stmt_to_assoc_array($stmt_purchase);

        if (!empty($sales_usage) || !empty($purchase_usage)) {
            return ['error' => 'این محصول قابل حذف نیست زیرا در حداقل یک فاکتور خرید یا فروش استفاده شده است.', 'statusCode' => 409];
        }

        // If no usage is found, proceed with the deletion from the base model.
        return parent::delete($id);
    }
    
    public function getFullListWithStock() {
        $entity_id = $_SESSION['current_entity_id'];
        
        $stmt_products = $this->conn->prepare("
            SELECT p.*, c.name as collectionName,
                (SELECT COALESCE(NULLIF(pi.thumb_path, ''), pi.file_path)
                 FROM product_images pi
                 WHERE pi.product_id = p.id
                 ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                 LIMIT 1) AS primary_image,
                (SELECT pi.file_path
                 FROM product_images pi
                 WHERE pi.product_id = p.id
                 ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                 LIMIT 1) AS primary_full_image
            FROM `{$this->tableName}` p 
            LEFT JOIN product_collections c ON p.collection_id = c.id 
            WHERE p.entity_id = ? ORDER BY p.name ASC
        ");
        $stmt_products->bind_param("i", $entity_id);
        $stmt_products->execute();
        $products = db_stmt_to_assoc_array($stmt_products);

        if (empty($products)) {
            return [];
        }
        
        $products = $this->attachInventoryDetails($products);
        
        return $products;
    }

    public function getSerializedItems($productId) {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT ii.id, ii.serial_id, ii.dimensions, ii.status, ii.entry_date, ii.warehouse_id,
                   w.name as warehouse_name
            FROM `inventory_items` ii
            LEFT JOIN `warehouses` w ON ii.warehouse_id = w.id
            WHERE ii.product_id = ? AND ii.entity_id = ? ORDER BY ii.dimensions, ii.entry_date
        ");
        $stmt->bind_param("ii", $productId, $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    public function addSerializedItems($productId, $dimensions, $count, $warehouseId = null) {
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, warehouse_id, status) VALUES (?, ?, ?, ?, 'in_stock')");
            for ($i = 0; $i < $count; $i++) {
                $stmt->bind_param("iisi", $productId, $entity_id, $dimensions, $warehouseId);
                $stmt->execute();
                
                // Generate serial_id based on inserted id
                $newId = $this->conn->insert_id;
                $serialId = 'INV-' . str_pad($newId, 6, '0', STR_PAD_LEFT);
                $stmt_serial = $this->conn->prepare("UPDATE inventory_items SET serial_id = ? WHERE id = ?");
                $stmt_serial->bind_param("si", $serialId, $newId);
                $stmt_serial->execute();
                $stmt_serial->close();
            }
            $stmt->close();
            return ['success' => true];
        } catch (Exception $e) {
            return ['error' => 'خطا در افزودن آیتم به انبار: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function updateSerializedItem($itemId, $dimensions, $serial_id = null, $warehouseId = null) {
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt_check = $this->conn->prepare("SELECT id, product_id, dimensions, serial_id, warehouse_id, sales_invoice_item_id, purchase_invoice_item_id FROM inventory_items WHERE id = ? AND entity_id = ?");
            $stmt_check->bind_param("ii", $itemId, $entity_id);
            $stmt_check->execute();
            $item = db_stmt_to_assoc_array($stmt_check)[0] ?? null;

            if (!$item) {
                return ['error' => 'آیتم مورد نظر یافت نشد.', 'statusCode' => 404];
            }

            // Serial merge touches inventory + invoice items in several writes;
            // keep them atomic so a mid-way failure can't leave half a merge.
            $this->conn->begin_transaction();
            $inTransaction = true;

            // Check if serial_id is being changed and validate uniqueness
            if ($serial_id !== null && $serial_id !== $item['serial_id']) {
                $stmt_unique = $this->conn->prepare("SELECT id FROM inventory_items WHERE serial_id = ? AND entity_id = ? AND id != ?");
                $stmt_unique->bind_param("sii", $serial_id, $entity_id, $itemId);
                $stmt_unique->execute();
                $existing = db_stmt_to_assoc_array($stmt_unique)[0] ?? null;
                
                if ($existing) {
                    return ['error' => 'این شناسه قبلاً برای آیتم دیگری استفاده شده است.', 'statusCode' => 400];
                }
                
                // Update serial_id
                $stmt_serial = $this->conn->prepare("UPDATE inventory_items SET serial_id = ? WHERE id = ? AND entity_id = ?");
                $stmt_serial->bind_param("sii", $serial_id, $itemId, $entity_id);
                $stmt_serial->execute();
                $stmt_serial->close();
            }
            
            // Update warehouse if provided and changed
            $currentWarehouseId = !empty($item['warehouse_id']) ? intval($item['warehouse_id']) : 0;
            if ($warehouseId !== null && $warehouseId != $currentWarehouseId) {
                if ($warehouseId > 0) {
                    $stmt_warehouse = $this->conn->prepare("UPDATE inventory_items SET warehouse_id = ? WHERE id = ? AND entity_id = ?");
                    $stmt_warehouse->bind_param("iii", $warehouseId, $itemId, $entity_id);
                } else {
                    $stmt_warehouse = $this->conn->prepare("UPDATE inventory_items SET warehouse_id = NULL WHERE id = ? AND entity_id = ?");
                    $stmt_warehouse->bind_param("ii", $itemId, $entity_id);
                }
                $stmt_warehouse->execute();
                $stmt_warehouse->close();
            }
            
            // If dimensions are the same, no update needed
            if ($item['dimensions'] === $dimensions) {
                $this->conn->commit();
                return ['success' => true];
            }
            
            $productId = $item['product_id'];
            
            // Update dimensions in inventory_items
            $stmt = $this->conn->prepare("UPDATE inventory_items SET dimensions = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("sii", $dimensions, $itemId, $entity_id);
            $stmt->execute();
            $stmt->close();
            
            // Handle sales invoice item update
            if (!empty($item['sales_invoice_item_id'])) {
                $salesItemId = $item['sales_invoice_item_id'];
                
                // Get sales invoice item details
                $stmt_sii = $this->conn->prepare("SELECT id, invoiceId, productId, quantity, unitPrice, dimensions FROM sales_invoice_items WHERE id = ?");
                $stmt_sii->bind_param("i", $salesItemId);
                $stmt_sii->execute();
                $sii = db_stmt_to_assoc_array($stmt_sii)[0] ?? null;
                
                if ($sii) {
                    // Check if there's already a sales invoice item with the new dimensions (for same invoice & product)
                    $stmt_exist = $this->conn->prepare("SELECT id FROM sales_invoice_items WHERE invoiceId = ? AND productId = ? AND dimensions = ? AND id != ?");
                    $stmt_exist->bind_param("iisi", $sii['invoiceId'], $productId, $dimensions, $salesItemId);
                    $stmt_exist->execute();
                    $existingItem = db_stmt_to_assoc_array($stmt_exist)[0] ?? null;
                    
                    // Count how many inventory items are linked to this sales invoice item
                    $stmt_count = $this->conn->prepare("SELECT COUNT(*) as cnt FROM inventory_items WHERE sales_invoice_item_id = ?");
                    $stmt_count->bind_param("i", $salesItemId);
                    $stmt_count->execute();
                    $countResult = db_stmt_to_assoc_array($stmt_count)[0];
                    $linkedCount = (int)$countResult['cnt'];
                    
                    if ($existingItem) {
                        // There's already an item with this dimension - need to merge
                        $newSalesItemId = $existingItem['id'];
                        
                        // Increment quantity of existing item
                        $stmt_inc = $this->conn->prepare("UPDATE sales_invoice_items SET quantity = quantity + 1 WHERE id = ?");
                        $stmt_inc->bind_param("i", $newSalesItemId);
                        $stmt_inc->execute();
                        $stmt_inc->close();
                        
                        // Update the inventory item to point to the existing sales invoice item
                        $stmt_link = $this->conn->prepare("UPDATE inventory_items SET sales_invoice_item_id = ? WHERE id = ?");
                        $stmt_link->bind_param("ii", $newSalesItemId, $itemId);
                        $stmt_link->execute();
                        $stmt_link->close();
                        
                        // Decrement quantity of original sales invoice item
                        $stmt_dec = $this->conn->prepare("UPDATE sales_invoice_items SET quantity = quantity - 1 WHERE id = ?");
                        $stmt_dec->bind_param("i", $salesItemId);
                        $stmt_dec->execute();
                        $stmt_dec->close();
                        
                        // Delete original if quantity is 0
                        $this->conn->query("DELETE FROM sales_invoice_items WHERE id = " . intval($salesItemId) . " AND quantity <= 0");
                    } else if ($linkedCount == 1) {
                        // Only one inventory item linked and no duplicate - just update the dimensions
                        $stmt_upd = $this->conn->prepare("UPDATE sales_invoice_items SET dimensions = ? WHERE id = ?");
                        $stmt_upd->bind_param("si", $dimensions, $salesItemId);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                    } else {
                        // Multiple inventory items linked and no duplicate - create new item
                        $stmt_ins = $this->conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, 1, ?, ?)");
                        $stmt_ins->bind_param("iids", $sii['invoiceId'], $productId, $sii['unitPrice'], $dimensions);
                        $stmt_ins->execute();
                        $newSalesItemId = $this->conn->insert_id;
                        $stmt_ins->close();
                        
                        // Update the inventory item to point to the new sales invoice item
                        $stmt_link = $this->conn->prepare("UPDATE inventory_items SET sales_invoice_item_id = ? WHERE id = ?");
                        $stmt_link->bind_param("ii", $newSalesItemId, $itemId);
                        $stmt_link->execute();
                        $stmt_link->close();
                        
                        // Decrement quantity of original sales invoice item
                        $stmt_dec = $this->conn->prepare("UPDATE sales_invoice_items SET quantity = quantity - 1 WHERE id = ?");
                        $stmt_dec->bind_param("i", $salesItemId);
                        $stmt_dec->execute();
                        $stmt_dec->close();
                        
                        // Delete original if quantity is 0
                        $this->conn->query("DELETE FROM sales_invoice_items WHERE id = " . intval($salesItemId) . " AND quantity <= 0");
                    }
                }
            }
            
            // Handle purchase invoice item update - same logic as sales invoice
            if (!empty($item['purchase_invoice_item_id'])) {
                $purchaseItemId = $item['purchase_invoice_item_id'];
                
                // Get purchase invoice item details
                $stmt_pii = $this->conn->prepare("SELECT id, invoiceId, productId, quantity, unitPrice, dimensions FROM purchase_invoice_items WHERE id = ?");
                $stmt_pii->bind_param("i", $purchaseItemId);
                $stmt_pii->execute();
                $pii = db_stmt_to_assoc_array($stmt_pii)[0] ?? null;
                
                if ($pii) {
                    // Check if there's already a purchase invoice item with the new dimensions (for same invoice & product)
                    $stmt_exist = $this->conn->prepare("SELECT id FROM purchase_invoice_items WHERE invoiceId = ? AND productId = ? AND dimensions = ? AND id != ?");
                    $stmt_exist->bind_param("iisi", $pii['invoiceId'], $productId, $dimensions, $purchaseItemId);
                    $stmt_exist->execute();
                    $existingItem = db_stmt_to_assoc_array($stmt_exist)[0] ?? null;
                    
                    // Count how many inventory items are linked to this purchase invoice item
                    $stmt_count = $this->conn->prepare("SELECT COUNT(*) as cnt FROM inventory_items WHERE purchase_invoice_item_id = ?");
                    $stmt_count->bind_param("i", $purchaseItemId);
                    $stmt_count->execute();
                    $countResult = db_stmt_to_assoc_array($stmt_count)[0];
                    $linkedCount = (int)$countResult['cnt'];
                    
                    if ($existingItem) {
                        // There's already an item with this dimension - need to merge
                        $newPurchaseItemId = $existingItem['id'];
                        
                        // Increment quantity of existing item
                        $stmt_inc = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = quantity + 1 WHERE id = ?");
                        $stmt_inc->bind_param("i", $newPurchaseItemId);
                        $stmt_inc->execute();
                        $stmt_inc->close();
                        
                        // Update the inventory item to point to the existing purchase invoice item
                        $stmt_link = $this->conn->prepare("UPDATE inventory_items SET purchase_invoice_item_id = ?, is_virtual = 0 WHERE id = ?");
                        $stmt_link->bind_param("ii", $newPurchaseItemId, $itemId);
                        $stmt_link->execute();
                        $stmt_link->close();
                        
                        // Decrement quantity of original purchase invoice item
                        $stmt_dec = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = quantity - 1 WHERE id = ?");
                        $stmt_dec->bind_param("i", $purchaseItemId);
                        $stmt_dec->execute();
                        $stmt_dec->close();
                        
                        // Delete original if quantity is 0
                        $this->conn->query("DELETE FROM purchase_invoice_items WHERE id = " . intval($purchaseItemId) . " AND quantity <= 0");
                    } else if ($linkedCount == 1) {
                        // Only one inventory item linked and no duplicate - just update the dimensions
                        $stmt_upd = $this->conn->prepare("UPDATE purchase_invoice_items SET dimensions = ? WHERE id = ?");
                        $stmt_upd->bind_param("si", $dimensions, $purchaseItemId);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                    } else {
                        // Multiple inventory items linked and no duplicate - create new item
                        $stmt_ins = $this->conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, 1, ?, ?)");
                        $stmt_ins->bind_param("iids", $pii['invoiceId'], $productId, $pii['unitPrice'], $dimensions);
                        $stmt_ins->execute();
                        $newPurchaseItemId = $this->conn->insert_id;
                        $stmt_ins->close();
                        
                        // Update the inventory item to point to the new purchase invoice item
                        $stmt_link = $this->conn->prepare("UPDATE inventory_items SET purchase_invoice_item_id = ?, is_virtual = 0 WHERE id = ?");
                        $stmt_link->bind_param("ii", $newPurchaseItemId, $itemId);
                        $stmt_link->execute();
                        $stmt_link->close();
                        
                        // Decrement quantity of original purchase invoice item
                        $stmt_dec = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = quantity - 1 WHERE id = ?");
                        $stmt_dec->bind_param("i", $purchaseItemId);
                        $stmt_dec->execute();
                        $stmt_dec->close();
                        
                        // Delete original if quantity is 0
                        $this->conn->query("DELETE FROM purchase_invoice_items WHERE id = " . intval($purchaseItemId) . " AND quantity <= 0");
                    }
                }
            }
            
            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            if (!empty($inTransaction)) {
                try { $this->conn->rollback(); } catch (Exception $_) {}
            }
            return ['error' => 'خطا در ویرایش آیتم: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function deleteSerializedItem($itemId) {
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt_check = $this->conn->prepare("SELECT status FROM inventory_items WHERE id = ? AND entity_id = ?");
            $stmt_check->bind_param("ii", $itemId, $entity_id);
            $stmt_check->execute();
            $item = db_stmt_to_assoc_array($stmt_check)[0] ?? null;
            
            if (!$item) {
                return ['error' => 'آیتم مورد نظر یافت نشد.', 'statusCode' => 404];
            }
             if (!in_array($item['status'], ['in_stock', 'returned'])) {
                return ['error' => 'فقط آیتم‌های موجود در انبار قابل حذف هستند.', 'statusCode' => 400];
            }

            // Prevent rewriting inventory history inside a locked closing period.
            $stmt_date = $this->conn->prepare(
                "SELECT DATE(COALESCE(entry_date, CURRENT_TIMESTAMP)) as entry_day
                 FROM inventory_items WHERE id = ? AND entity_id = ?"
            );
            $stmt_date->bind_param("ii", $itemId, $entity_id);
            $stmt_date->execute();
            $dateRow = db_stmt_to_assoc_array($stmt_date);
            if (!empty($dateRow[0]['entry_day'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $dateRow[0]['entry_day']);
            }

            $stmt = $this->conn->prepare("DELETE FROM inventory_items WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ii", $itemId, $entity_id);
            $stmt->execute();
            $stmt->close();
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->conn->errno == 1451) {
                return ['error' => 'این آیتم در یک فاکتور استفاده شده و قابل حذف نیست.', 'statusCode' => 409];
            }
            return ['error' => 'خطا در حذف آیتم: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
}