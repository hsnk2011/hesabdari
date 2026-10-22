<?php
// /api/controllers/StocktakingController.php

class StocktakingController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function normalizeWarehouseId($value) {
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }
        $warehouseId = intval($value);
        return $warehouseId > 0 ? $warehouseId : null;
    }

    private function normalizeDimensions($value) {
        return trim((string)($value ?? ''));
    }

    private function itemUniqueKey($productId, $dimensions, $warehouseId) {
        $wh = $warehouseId ?? 0;
        return intval($productId) . '|' . $this->normalizeDimensions($dimensions) . '|' . $wh;
    }

  /**
   * Session-level warehouse wins when set; otherwise per-row warehouse (0/null = بدون انبار).
   */
    private function resolveItemWarehouseId($item, $sessionWarehouseId) {
        if ($sessionWarehouseId !== null) {
            return $sessionWarehouseId;
        }
        return $this->normalizeWarehouseId($item['warehouse_id'] ?? null);
    }

    private function getSummaryByStocktakingId($stocktakingId) {
        $stmt = $this->conn->prepare("
            SELECT
                COUNT(*) as item_count,
                COALESCE(SUM(system_qty), 0) as system_qty_total,
                COALESCE(SUM(counted_qty), 0) as counted_qty_total,
                COALESCE(SUM(difference), 0) as net_difference,
                COALESCE(SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END), 0) as surplus_qty,
                COALESCE(SUM(CASE WHEN difference < 0 THEN ABS(difference) ELSE 0 END), 0) as shortage_qty
            FROM stocktaking_items
            WHERE stocktaking_id = ?
        ");
        $stmt->bind_param('i', $stocktakingId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        return $rows[0] ?? [
            'item_count' => 0,
            'system_qty_total' => 0,
            'counted_qty_total' => 0,
            'net_difference' => 0,
            'surplus_qty' => 0,
            'shortage_qty' => 0,
        ];
    }

    /**
     * Unit cost for stock found or lost in a count: latest purchase price of the
     * same design and size up to the count date. Without it, surplus items were
     * sold with zero COGS (inflated profit) and shortages never reached P&L.
     */
    private function estimateUnitCost($entityId, $productId, $dimensions, $date) {
        $norm = sql_normalize_dimensions_expr('pii.dimensions');
        $stmt = $this->conn->prepare(
            "SELECT pii.unitPrice FROM purchase_invoice_items pii
             JOIN purchase_invoices pi ON pi.id = pii.invoiceId
             WHERE pi.entity_id = ? AND pii.productId = ? AND {$norm} = ? AND pi.date <= ?
             ORDER BY pi.date DESC, pii.id DESC LIMIT 1"
        );
        $dimNorm = normalize_dimensions($dimensions);
        $stmt->bind_param('iiss', $entityId, $productId, $dimNorm, $date);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        return $row ? (float)$row['unitPrice'] : null;
    }

    private function inventoryItemCost($inventoryItemId, $entityId, $productId, $dimensions, $date) {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(pii.unitPrice, ii.cost_price) AS cost FROM inventory_items ii
             LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
             WHERE ii.id = ? AND ii.entity_id = ?"
        );
        $stmt->bind_param('ii', $inventoryItemId, $entityId);
        $stmt->execute();
        $cost = db_stmt_to_assoc_array($stmt)[0]['cost'] ?? null;
        if ($cost === null) {
            $cost = $this->estimateUnitCost($entityId, $productId, $dimensions, $date);
        }
        return (float)($cost ?? 0);
    }

    private function insertInventorySurplus($entityId, $productId, $warehouseId, $dimensions, $note, $costPrice = null) {
        if ($warehouseId === null) {
            $stmt = $this->conn->prepare(
                "INSERT INTO inventory_items (product_id, entity_id, dimensions, status, notes, cost_price) VALUES (?, ?, ?, 'in_stock', ?, ?)"
            );
            $stmt->bind_param('iissd', $productId, $entityId, $dimensions, $note, $costPrice);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO inventory_items (product_id, entity_id, warehouse_id, dimensions, status, notes, cost_price) VALUES (?, ?, ?, ?, 'in_stock', ?, ?)"
            );
            $stmt->bind_param('iiissd', $productId, $entityId, $warehouseId, $dimensions, $note, $costPrice);
        }
        if (!$stmt->execute()) {
            throw new Exception($stmt->error ?: 'ثبت آیتم مازاد انجام نشد.');
        }
        $stmt->close();
    }

    private function findInventoryItemsForShortage($entityId, $productId, $dimensions, $warehouseId, $limit) {
        $dims = $this->normalizeDimensions($dimensions);
        if ($warehouseId === null) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM inventory_items
                 WHERE entity_id = ? AND product_id = ? AND COALESCE(dimensions, '') = ?
                   AND warehouse_id IS NULL
                   AND status IN ('in_stock', 'returned')
                 ORDER BY id ASC LIMIT ?"
            );
            $stmt->bind_param('iisi', $entityId, $productId, $dims, $limit);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id FROM inventory_items
                 WHERE entity_id = ? AND product_id = ? AND COALESCE(dimensions, '') = ?
                   AND warehouse_id = ?
                   AND status IN ('in_stock', 'returned')
                 ORDER BY id ASC LIMIT ?"
            );
            $stmt->bind_param('iisii', $entityId, $productId, $dims, $warehouseId, $limit);
        }
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function insertStocktakingItem($stocktakingId, $productId, $dimensions, $warehouseId, $systemQty, $countedQty, $diff, $itemNotes) {
        $dims = $this->normalizeDimensions($dimensions);
        if ($warehouseId === null) {
            $stmt = $this->conn->prepare(
                "INSERT INTO stocktaking_items (stocktaking_id, product_id, dimensions, warehouse_id, system_qty, counted_qty, difference, notes)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iisiiis', $stocktakingId, $productId, $dims, $systemQty, $countedQty, $diff, $itemNotes);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO stocktaking_items (stocktaking_id, product_id, dimensions, warehouse_id, system_qty, counted_qty, difference, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iisiiiis', $stocktakingId, $productId, $dims, $warehouseId, $systemQty, $countedQty, $diff, $itemNotes);
        }
        if (!$stmt->execute()) {
            throw new Exception($stmt->error ?: 'خطا در ثبت ردیف انبارگردانی.');
        }
        $stmt->close();
    }

    private function fetchSnapshotRows($entityId, $warehouseId = null, $search = '') {
        $params = [$entityId];
        $types = 'i';
        $warehouseClause = '';
        $searchClause = '';

        if ($warehouseId !== null) {
            $warehouseClause = ' AND ii.warehouse_id = ?';
            $params[] = $warehouseId;
            $types .= 'i';
        }

        if ($search !== '') {
            $searchClause = ' AND (p.name LIKE ? OR p.design_code LIKE ? OR p.colorway LIKE ? OR COALESCE(ii.dimensions, "") LIKE ?)';
            $wildcard = '%' . $search . '%';
            $params[] = $wildcard;
            $params[] = $wildcard;
            $params[] = $wildcard;
            $params[] = $wildcard;
            $types .= 'ssss';
        }

        $stmt = $this->conn->prepare("
            SELECT
                p.id as product_id,
                p.name as product_name,
                p.design_code,
                p.colorway,
                COALESCE(ii.dimensions, '') as dimensions,
                COALESCE(ii.warehouse_id, 0) as warehouse_id,
                COALESCE(w.name, 'بدون انبار') as warehouse_name,
                COUNT(ii.id) as system_qty,
                COALESCE(SUM(CASE WHEN ii.purchase_invoice_item_id IS NULL THEN 1 ELSE 0 END), 0) as unlinked_qty,
                /* شناسه انبار = serial_id مثل INV-001266 (نه warehouse_id و نه products.id) */
                COALESCE(GROUP_CONCAT(DISTINCT ii.serial_id ORDER BY ii.id SEPARATOR ', '), '') as serial_ids
            FROM inventory_items ii
            JOIN products p ON ii.product_id = p.id
            LEFT JOIN warehouses w ON ii.warehouse_id = w.id
            WHERE p.entity_id = ?
              AND ii.status IN ('in_stock', 'returned')" . $warehouseClause . $searchClause . "
            GROUP BY p.id, p.name, p.design_code, p.colorway, COALESCE(ii.dimensions, ''), COALESCE(ii.warehouse_id, 0), COALESCE(w.name, 'بدون انبار')
            ORDER BY p.name ASC, warehouse_name ASC, dimensions ASC
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getProducts($data) {
        check_permission('manage_stocktaking');
        $entity_id = $_SESSION['current_entity_id'];

        $stmt = $this->conn->prepare("
            SELECT id, name, design_code, colorway
            FROM products
            WHERE entity_id = ?
            ORDER BY name ASC
        ");
        $stmt->bind_param('i', $entity_id);
        $stmt->execute();

        send_json(['products' => db_stmt_to_assoc_array($stmt)]);
    }

    public function getWarehouses($data) {
        check_permission('manage_stocktaking');
        $entity_id = $_SESSION['current_entity_id'];

        $stmt = $this->conn->prepare("
            SELECT id, name
            FROM warehouses
            WHERE entity_id = ?
            ORDER BY is_default DESC, name ASC
        ");
        $stmt->bind_param('i', $entity_id);
        $stmt->execute();

        send_json(['warehouses' => db_stmt_to_assoc_array($stmt)]);
    }

    public function getList($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT s.*, w.name as warehouse_name, u.username as created_by_name,
                (SELECT COUNT(*) FROM stocktaking_items si WHERE si.stocktaking_id = s.id) as item_count
            FROM stocktaking s
            LEFT JOIN warehouses w ON s.warehouse_id = w.id
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.entity_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);

        foreach ($items as &$item) {
            $summary = $this->getSummaryByStocktakingId(intval($item['id']));
            $item['item_count'] = intval($summary['item_count']);
            $item['system_qty_total'] = intval($summary['system_qty_total']);
            $item['counted_qty_total'] = intval($summary['counted_qty_total']);
            $item['net_difference'] = intval($summary['net_difference']);
            $item['surplus_qty'] = intval($summary['surplus_qty']);
            $item['shortage_qty'] = intval($summary['shortage_qty']);
        }

        send_json(['items' => $items]);
    }

    public function get($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        if (!$id) { send_json(['error' => 'شناسه نامعتبر.'], 400); return; }

        $stmt = $this->conn->prepare("SELECT * FROM stocktaking WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $id, $entity_id);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        if (empty($result)) { send_json(['error' => 'شمارش یافت نشد.'], 404); return; }

        $stm = $this->conn->prepare("
            SELECT si.*, p.name as product_name, w.name as warehouse_name
            FROM stocktaking_items si
            LEFT JOIN products p ON si.product_id = p.id
            LEFT JOIN warehouses w ON si.warehouse_id = w.id
            WHERE si.stocktaking_id = ?
            ORDER BY si.id ASC
        ");
        $stm->bind_param("i", $id);
        $stm->execute();
        $items = db_stmt_to_assoc_array($stm);

        // شناسه انبار زنده از inventory_items.serial_id (مثل INV-001266) برای چاپ برگه شمارش
        foreach ($items as &$item) {
            $item['serial_ids'] = $this->fetchSerialIdsForStockLine(
                $entity_id,
                intval($item['product_id'] ?? 0),
                $item['dimensions'] ?? '',
                $this->normalizeWarehouseId($item['warehouse_id'] ?? null)
            );
        }
        unset($item);

        $result[0]['items'] = $items;
        $result[0]['summary'] = $this->getSummaryByStocktakingId($id);
        send_json($result[0]);
    }

    /**
     * شناسه انبار (serial_id) برای یک ردیف محصول+ابعاد+انبار.
     * این همان ستونی است که در مودال محصول با برچسب «شناسه» نمایش داده می‌شود (مثل INV-001266).
     * هرگز با warehouse_id یا products.id اشتباه نشود.
     */
    private function fetchSerialIdsForStockLine($entityId, $productId, $dimensions, $warehouseId) {
        if ($productId <= 0) {
            return '';
        }
        $dims = $this->normalizeDimensions($dimensions);
        if ($warehouseId === null) {
            $stmt = $this->conn->prepare(
                "SELECT COALESCE(GROUP_CONCAT(DISTINCT serial_id ORDER BY id SEPARATOR ', '), '') AS serial_ids
                 FROM inventory_items
                 WHERE entity_id = ? AND product_id = ? AND COALESCE(dimensions, '') = ?
                   AND warehouse_id IS NULL
                   AND status IN ('in_stock', 'returned')"
            );
            $stmt->bind_param('iis', $entityId, $productId, $dims);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT COALESCE(GROUP_CONCAT(DISTINCT serial_id ORDER BY id SEPARATOR ', '), '') AS serial_ids
                 FROM inventory_items
                 WHERE entity_id = ? AND product_id = ? AND COALESCE(dimensions, '') = ?
                   AND warehouse_id = ?
                   AND status IN ('in_stock', 'returned')"
            );
            $stmt->bind_param('iisi', $entityId, $productId, $dims, $warehouseId);
        }
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0]['serial_ids'] ?? '';
    }

    public function getSnapshot($data) {
        check_permission('manage_stocktaking');

        $entity_id = $_SESSION['current_entity_id'];
        $warehouseId = $this->normalizeWarehouseId($data['warehouse_id'] ?? null);
        $search = trim($data['search'] ?? '');

        $rows = $this->fetchSnapshotRows($entity_id, $warehouseId, $search);

        send_json([
            'items' => $rows,
            'warehouse_id' => $warehouseId,
            'search' => $search,
            'total_rows' => count($rows),
            'total_system_qty' => array_sum(array_map(fn($row) => intval($row['system_qty'] ?? 0), $rows))
        ]);
    }

    public function save($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');
        $date = $data['date'] ?? date('Y-m-d');
        $warehouse_id = $this->normalizeWarehouseId($data['warehouse_id'] ?? null);
        $notes = trim($data['notes'] ?? '');
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $status = in_array(($data['status'] ?? 'draft'), ['draft', 'in_progress'], true) ? $data['status'] : 'draft';

        if (empty($title)) { send_json(['error' => 'عنوان شمارش الزامی است.'], 400); return; }
        if (empty($items)) { send_json(['error' => 'حداقل یک ردیف انبارگردانی ثبت کنید.'], 400); return; }

        try {
            assert_transaction_date_editable($this->conn, $entity_id, $date);
        } catch (Exception $e) {
            send_json(['error' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 403);
            return;
        }

        $seenKeys = [];
        $normalizedItems = [];
        foreach ($items as $item) {
            $productId = intval($item['product_id'] ?? 0);
            if (!$productId) {
                send_json(['error' => 'شناسه محصول در یکی از ردیف‌ها نامعتبر است.'], 400);
                return;
            }
            $dims = $this->normalizeDimensions($item['dimensions'] ?? '');
            $itemWarehouseId = $this->resolveItemWarehouseId($item, $warehouse_id);
            $key = $this->itemUniqueKey($productId, $dims, $itemWarehouseId);
            if (isset($seenKeys[$key])) {
                send_json(['error' => 'ردیف تکراری: همان محصول، ابعاد و انبار بیش از یک بار ثبت شده است.'], 400);
                return;
            }
            $seenKeys[$key] = true;
            $normalizedItems[] = [
                'product_id' => $productId,
                'dimensions' => $dims,
                'warehouse_id' => $itemWarehouseId,
                'system_qty' => max(0, intval($item['system_qty'] ?? 0)),
                'counted_qty' => max(0, intval($item['counted_qty'] ?? 0)),
                'notes' => trim($item['notes'] ?? ''),
            ];
        }

        $this->conn->begin_transaction();
        try {
            if ($id > 0) {
                if ($warehouse_id === null) {
                    $stmt = $this->conn->prepare("UPDATE stocktaking SET title = ?, date = ?, warehouse_id = NULL, notes = ?, status = ? WHERE id = ? AND entity_id = ? AND status IN ('draft','in_progress')");
                    $stmt->bind_param("ssssii", $title, $date, $notes, $status, $id, $entity_id);
                } else {
                    $stmt = $this->conn->prepare("UPDATE stocktaking SET title = ?, date = ?, warehouse_id = ?, notes = ?, status = ? WHERE id = ? AND entity_id = ? AND status IN ('draft','in_progress')");
                    $stmt->bind_param("ssissii", $title, $date, $warehouse_id, $notes, $status, $id, $entity_id);
                }
                $stmt->execute();
                $stmt->close();

                $stmtCheck = $this->conn->prepare("SELECT id FROM stocktaking WHERE id = ? AND entity_id = ? AND status IN ('draft','in_progress') FOR UPDATE");
                $stmtCheck->bind_param('ii', $id, $entity_id);
                $stmtCheck->execute();
                $checkRows = db_stmt_to_assoc_array($stmtCheck);
                if (empty($checkRows)) {
                    throw new Exception("شمارش یافت نشد یا قابل ویرایش نیست.");
                }

                $stmtDelete = $this->conn->prepare("DELETE FROM stocktaking_items WHERE stocktaking_id = ?");
                $stmtDelete->bind_param('i', $id);
                $stmtDelete->execute();
                $stmtDelete->close();
            } else {
                $user_id = intval($_SESSION['user_id'] ?? 0);
                if ($warehouse_id === null) {
                    $stmt = $this->conn->prepare("INSERT INTO stocktaking (entity_id, title, date, warehouse_id, notes, created_by, status) VALUES (?, ?, ?, NULL, ?, ?, ?)");
                    $stmt->bind_param("isssis", $entity_id, $title, $date, $notes, $user_id, $status);
                } else {
                    $stmt = $this->conn->prepare("INSERT INTO stocktaking (entity_id, title, date, warehouse_id, notes, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issisis", $entity_id, $title, $date, $warehouse_id, $notes, $user_id, $status);
                }
                $stmt->execute();
                $id = $this->conn->insert_id;
            }

            foreach ($normalizedItems as $item) {
                $diff = $item['counted_qty'] - $item['system_qty'];
                $this->insertStocktakingItem(
                    $id,
                    $item['product_id'],
                    $item['dimensions'],
                    $item['warehouse_id'],
                    $item['system_qty'],
                    $item['counted_qty'],
                    $diff,
                    $item['notes']
                );
            }

            $this->conn->commit();
            log_activity($this->conn, 'SAVE_STOCKTAKING', "شمارش فیزیکی #{$id} ذخیره شد.");
            send_json(['success' => true, 'id' => $id, 'summary' => $this->getSummaryByStocktakingId($id)]);
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            send_json(['error' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 500);
        }
    }

    public function delete($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);

        $stmt = $this->conn->prepare("DELETE FROM stocktaking WHERE id = ? AND entity_id = ? AND status IN ('draft','cancelled')");
        $stmt->bind_param("ii", $id, $entity_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            log_activity($this->conn, 'DELETE_STOCKTAKING', "شمارش فیزیکی #{$id} حذف شد.");
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'شمارش یافت نشد یا قابل حذف نیست.'], 400);
        }
    }

    public function cancel($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        if (!$id) { send_json(['error' => 'شناسه نامعتبر.'], 400); return; }

        $stmt = $this->conn->prepare("UPDATE stocktaking SET status = 'cancelled' WHERE id = ? AND entity_id = ? AND status IN ('draft','in_progress')");
        $stmt->bind_param('ii', $id, $entity_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            log_activity($this->conn, 'CANCEL_STOCKTAKING', "شمارش فیزیکی #{$id} لغو شد.");
            send_json(['success' => true, 'message' => 'انبارگردانی لغو شد.']);
        } else {
            send_json(['error' => 'شمارش یافت نشد یا قابل لغو نیست.'], 400);
        }
    }

    public function apply($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        if (!$id) { send_json(['error' => 'شناسه نامعتبر.'], 400); return; }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT id, status, warehouse_id, date FROM stocktaking WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $id, $entity_id);
            $stmt->execute();
            $st = db_stmt_to_assoc_array($stmt);
            if (empty($st) || !in_array($st[0]['status'], ['draft', 'in_progress'])) {
                throw new Exception("شمارش یافت نشد یا قبلاً اعمال شده.");
            }

            assert_transaction_date_editable($this->conn, $entity_id, $st[0]['date'] ?? null);

            $sessionWarehouseId = $this->normalizeWarehouseId($st[0]['warehouse_id'] ?? null);

            $stmtItems = $this->conn->prepare("SELECT * FROM stocktaking_items WHERE stocktaking_id = ? AND difference != 0 ORDER BY id ASC");
            $stmtItems->bind_param("i", $id);
            $stmtItems->execute();
            $items = db_stmt_to_assoc_array($stmtItems);

            $adjustments = 0;
            $details = [];
            $totalUnresolved = 0;
            $countDate = $st[0]['date'] ?? date('Y-m-d');
            $surplusValue = 0.0;
            $shrinkageValue = 0.0;
            $uncostedSurplus = 0;
            $surplusNote = "اضافه شده از شمارش فیزیکی #{$id}";
            $damageNote = "کسری شمارش #{$id}";

            foreach ($items as $item) {
                $diff = intval($item['difference']);
                $productId = intval($item['product_id']);
                $dimensions = $this->normalizeDimensions($item['dimensions'] ?? '');
                $itemWarehouseId = $this->resolveItemWarehouseId($item, $sessionWarehouseId);

                if ($diff > 0) {
                    $unitCost = $this->estimateUnitCost($entity_id, $productId, $dimensions, $countDate);
                    if ($unitCost === null) {
                        $uncostedSurplus += $diff;
                    }
                    for ($i = 0; $i < $diff; $i++) {
                        $this->insertInventorySurplus($entity_id, $productId, $itemWarehouseId, $dimensions, $surplusNote, $unitCost);
                    }
                    $surplusValue += (float)$unitCost * $diff;
                    $adjustments += $diff;
                    $details[] = [
                        'product_id' => $productId,
                        'dimensions' => $dimensions,
                        'warehouse_id' => $itemWarehouseId,
                        'difference' => $diff,
                        'type' => 'surplus',
                        'applied' => $diff,
                        'unresolved' => 0,
                    ];
                } elseif ($diff < 0) {
                    $limit = abs($diff);
                    $rowsToDamage = $this->findInventoryItemsForShortage($entity_id, $productId, $dimensions, $itemWarehouseId, $limit);

                    $damagedCount = 0;
                    foreach ($rowsToDamage as $row) {
                        $inventoryItemId = intval($row['id']);
                        $shrinkageValue += $this->inventoryItemCost($inventoryItemId, $entity_id, $productId, $dimensions, $countDate);
                        $stmtDamage = $this->conn->prepare("UPDATE inventory_items SET status = 'damaged', notes = CONCAT(COALESCE(notes,''), ' | ', ?) WHERE id = ? AND entity_id = ?");
                        $stmtDamage->bind_param("sii", $damageNote, $inventoryItemId, $entity_id);
                        if (!$stmtDamage->execute()) {
                            throw new Exception($stmtDamage->error ?: 'ثبت کسری انبار انجام نشد.');
                        }
                        $damagedCount++;
                        $adjustments++;
                    }

                    $unresolved = max(0, $limit - $damagedCount);
                    $totalUnresolved += $unresolved;
                    $details[] = [
                        'product_id' => $productId,
                        'dimensions' => $dimensions,
                        'warehouse_id' => $itemWarehouseId,
                        'difference' => $diff,
                        'type' => 'shortage',
                        'applied' => $damagedCount,
                        'unresolved' => $unresolved,
                    ];
                }
            }

            if ($totalUnresolved > 0) {
                throw new Exception(
                    "اعمال کامل نشد: {$totalUnresolved} قلم کسری در موجودی سیستم برای تسویه پیدا نشد. موجودی تغییر نکرد.",
                    400
                );
            }

            $now = date('Y-m-d H:i:s');
            $stmtComplete = $this->conn->prepare("UPDATE stocktaking SET status = 'completed', completed_at = ?, surplus_value = ?, shrinkage_value = ? WHERE id = ? AND entity_id = ?");
            $stmtComplete->bind_param("sddii", $now, $surplusValue, $shrinkageValue, $id, $entity_id);
            $stmtComplete->execute();

            $summary = $this->getSummaryByStocktakingId($id);
            $this->conn->commit();
            log_activity($this->conn, 'APPLY_STOCKTAKING', "شمارش فیزیکی #{$id} اعمال شد. {$adjustments} تعدیل انبار.");
            send_json([
                'success' => true,
                'adjustments' => $adjustments,
                'details' => $details,
                'summary' => $summary,
                'surplus_value' => $surplusValue,
                'shrinkage_value' => $shrinkageValue,
                'uncosted_surplus' => $uncostedSurplus,
                'message' => "شمارش اعمال شد. تعداد {$adjustments} تعدیل انجام شد." . ($shrinkageValue > 0 ? ' ارزش کسری: ' . number_format($shrinkageValue) . '.' : '') . ($surplusValue > 0 ? ' ارزش اضافی: ' . number_format($surplusValue) . '.' : '') . ($uncostedSurplus > 0 ? " {$uncostedSurplus} قلم اضافی بدون سابقه خرید (بهای صفر) ثبت شد." : '')
            ]);
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            send_json(['error' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 500);
        }
    }
}
