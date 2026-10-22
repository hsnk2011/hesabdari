<?php
// /api/controllers/InventoryController.php
require_once __DIR__ . '/../models/Product.php';

class InventoryController {
    private $conn;
    private $productModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->productModel = new Product($db);
    }

    /**
     * دریافت لیست انبارها
     */
    private function ensureDashboardColumn() {
        $col = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'show_on_dashboard'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `warehouses` ADD COLUMN `show_on_dashboard` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`");
        }
        $typeCol = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
        if ($typeCol && $typeCol->num_rows === 0) {
            $this->conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical' AFTER `show_on_dashboard`");
        }
    }

    public function getWarehouses($data) {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('manage_products', $perms, true) && !in_array('manage_purchase_invoices', $perms, true)) {
            send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
            return;
        }
        $this->ensureDashboardColumn();
        $entity_id = $_SESSION['current_entity_id'];
        
        $stmt = $this->conn->prepare("
            SELECT w.*, 
                   (SELECT COUNT(*) FROM inventory_items ii
                    WHERE ii.warehouse_id = w.id
                      AND (
                        ii.status IN ('in_stock', 'returned')
                        OR (w.warehouse_type = 'order' AND ii.status = 'sold')
                      )
                   ) as item_count
            FROM warehouses w 
            WHERE w.entity_id = ? 
            ORDER BY w.is_default DESC, w.name ASC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $warehouses = db_stmt_to_assoc_array($stmt);

        $unassigned = 0;
        $uStmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM inventory_items WHERE entity_id = ? AND (warehouse_id IS NULL OR warehouse_id = 0) AND status IN ('in_stock', 'returned')");
        $uStmt->bind_param('i', $entity_id);
        $uStmt->execute();
        $uRow = db_stmt_to_assoc_array($uStmt);
        if (!empty($uRow)) {
            $unassigned = intval($uRow[0]['c'] ?? 0);
        }
        
        send_json(['warehouses' => $warehouses, 'unassigned_count' => $unassigned]);
    }

    public function listStock($data) {
        check_permission('manage_products');
        $entity_id = $_SESSION['current_entity_id'];
        $search = trim($data['search'] ?? '');
        $warehouse = $data['warehouse_id'] ?? '';
        $defaultLimit = defined('INVENTORY_DEFAULT_LIMIT') ? INVENTORY_DEFAULT_LIMIT : 200;
        $maxLimit = defined('INVENTORY_MAX_LIMIT') ? INVENTORY_MAX_LIMIT : 500;
        $limit = intval($data['limit'] ?? $defaultLimit);
        if ($limit < 1 || $limit > $maxLimit) {
            $limit = $defaultLimit;
        }

        $where = ["ii.entity_id = ?"];
        $params = [$entity_id];
        $types = 'i';

        $includeSold = false;
        if ($warehouse !== '' && $warehouse !== 'all' && $warehouse !== '__no_warehouse__') {
            $wid = intval($warehouse);
            $typeStmt = $this->conn->prepare("SELECT warehouse_type FROM warehouses WHERE id = ? AND entity_id = ? LIMIT 1");
            $typeStmt->bind_param('ii', $wid, $entity_id);
            $typeStmt->execute();
            $typeRow = db_stmt_to_assoc_array($typeStmt);
            $includeSold = !empty($typeRow[0]['warehouse_type']) && $typeRow[0]['warehouse_type'] === 'order';
        }
        if ($includeSold) {
            $where[] = "ii.status IN ('in_stock', 'returned', 'sold')";
        } else {
            $where[] = "ii.status IN ('in_stock', 'returned')";
        }

        if ($warehouse === '__no_warehouse__') {
            $where[] = '(ii.warehouse_id IS NULL OR ii.warehouse_id = 0)';
        } elseif ($warehouse !== '' && $warehouse !== 'all') {
            $where[] = 'ii.warehouse_id = ?';
            $params[] = intval($warehouse);
            $types .= 'i';
        }

        if ($search !== '') {
            // A full size (2x3, ۲×۳) is that size only, not 2x3.5 or 192x302; a size being typed (2x) is a prefix.
            $dimKind = $this->productModel->dimensionSearchKind($search);
            $normDimSql = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(ii.dimensions), '×', 'x'), '*', 'x'), ' ', ''), 'در', 'x')";
            if ($dimKind === 'full' || $dimKind === 'partial') {
                $needle = $this->productModel->normalizeDimensionSearch($search);
                $where[] = $dimKind === 'full' ? "{$normDimSql} = ?" : "{$normDimSql} LIKE ?";
                $params[] = $dimKind === 'full' ? $needle : $needle . '%';
                $types .= 's';
            } else {
                $where[] = '(ii.serial_id LIKE ? OR CONCAT(\'INV-\', LPAD(ii.id, 6, \'0\')) LIKE ? OR p.name LIKE ? OR ii.dimensions LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $types .= 'ssss';
            }
        }

        $sql = "SELECT ii.id, ii.serial_id, ii.dimensions, ii.status, ii.warehouse_id, ii.product_id,
                       p.name AS product_name, w.name AS warehouse_name,
                       COALESCE(NULLIF(pi.thumb_path, ''), pi.file_path) AS product_thumb,
                       pi.file_path AS product_image_full
                FROM inventory_items ii
                INNER JOIN products p ON p.id = ii.product_id
                LEFT JOIN warehouses w ON w.id = ii.warehouse_id
                LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.name ASC, ii.serial_id ASC";
        // فیلتر متراژ / شکل (کناره): روی رشته آزاد ابعاد در PHP اعمال می‌شود
        $minArea = isset($data['minArea']) && $data['minArea'] !== '' ? floatval($data['minArea']) : null;
        $maxArea = isset($data['maxArea']) && $data['maxArea'] !== '' ? floatval($data['maxArea']) : null;
        if ($minArea !== null && !($minArea >= 0)) $minArea = null;
        if ($maxArea !== null && !($maxArea > 0)) $maxArea = null;
        $areaFilterActive = ($minArea !== null && $minArea > 0) || ($maxArea !== null && $maxArea > 0);
        $shapeFilter = trim((string)($data['shapeFilter'] ?? ''));
        $kenarehActive = ($shapeFilter === 'kenareh');
        $kenarehMinRatio = kenareh_min_aspect_ratio();
        $phpFilterActive = $areaFilterActive || $kenarehActive;
        if ($phpFilterActive) {
            $sql .= " LIMIT 5000";
        } else {
            $sql .= " LIMIT {$limit}";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);
        foreach ($items as &$item) {
            $sid = trim((string)($item['serial_id'] ?? ''));
            if ($sid === '') {
                $item['serial_id'] = 'INV-' . str_pad((string)$item['id'], 6, '0', STR_PAD_LEFT);
            }
        }
        unset($item);
        if ($phpFilterActive) {
            $items = array_values(array_filter($items, function ($item) use ($minArea, $maxArea, $areaFilterActive, $kenarehActive, $kenarehMinRatio) {
                $dims = $item['dimensions'] ?? '';
                if ($areaFilterActive) {
                    $area = dimension_area($this->conn, $dims);
                    if (!($area > 0)) return false;
                    if ($minArea !== null && $minArea > 0 && $area < $minArea) return false;
                    if ($maxArea !== null && $maxArea > 0 && $area > $maxArea) return false;
                }
                if ($kenarehActive) {
                    $ratio = dimension_aspect_ratio($this->conn, $dims);
                    if (!($ratio >= $kenarehMinRatio)) return false;
                }
                return true;
            }));
            $items = array_slice($items, 0, $limit);
        }

        $sum = $this->conn->prepare("
            SELECT
                SUM(CASE WHEN status IN ('in_stock','returned') AND (warehouse_id IS NULL OR warehouse_id = 0) THEN 1 ELSE 0 END) AS unassigned,
                SUM(CASE WHEN status IN ('in_stock','returned') THEN 1 ELSE 0 END) AS movable
            FROM inventory_items
            WHERE entity_id = ?
        ");
        $sum->bind_param('i', $entity_id);
        $sum->execute();
        $summary = db_stmt_to_assoc_array($sum);

        send_json([
            'items' => $items,
            'summary' => $summary[0] ?? ['unassigned' => 0, 'movable' => 0],
        ]);
    }

    /**
     * ذخیره انبار
     */
    public function saveWarehouse($data) {
        check_permission('manage_products');
        
        if (empty(trim($data['name'] ?? ''))) {
            send_json(['error' => 'نام انبار الزامی است.'], 400);
            return;
        }
        
        $this->ensureDashboardColumn();
        $entity_id = $_SESSION['current_entity_id'];
        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $address = trim($data['address'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $is_default = intval($data['is_default'] ?? 0);
        $is_active = intval($data['is_active'] ?? 1);
        $show_on_dashboard = intval($data['show_on_dashboard'] ?? 0) ? 1 : 0;
        $warehouse_type = (($data['warehouse_type'] ?? '') === 'order') ? 'order' : 'physical';
        if (!empty($id) && !array_key_exists('warehouse_type', $data)) {
            $keep = $this->conn->prepare("SELECT warehouse_type FROM warehouses WHERE id = ? AND entity_id = ?");
            $keepId = intval($id);
            $keep->bind_param('ii', $keepId, $entity_id);
            $keep->execute();
            $keepRow = db_stmt_to_assoc_array($keep);
            if (!empty($keepRow[0]['warehouse_type'])) {
                $warehouse_type = $keepRow[0]['warehouse_type'] === 'order' ? 'order' : 'physical';
            }
        }
        if ($warehouse_type === 'order') {
            $show_on_dashboard = 1;
        }
        
        // بررسی تکراری نبودن نام
        $check_id = $id ?: 0;
        $stmt_check = $this->conn->prepare("SELECT id FROM warehouses WHERE name = ? AND entity_id = ? AND id != ?");
        $stmt_check->bind_param("sii", $name, $entity_id, $check_id);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            $stmt_check->close();
            send_json(['error' => 'انبار با این نام قبلاً وجود دارد.'], 409);
            return;
        }
        $stmt_check->close();
        
        $this->conn->begin_transaction();
        try {
            // اگر این انبار پیش‌فرض است، بقیه را از حالت پیش‌فرض خارج کن
            if ($is_default) {
                $stmt_reset = $this->conn->prepare("UPDATE warehouses SET is_default = 0 WHERE entity_id = ?");
                $stmt_reset->bind_param("i", $entity_id);
                $stmt_reset->execute();
                $stmt_reset->close();
            }
            
            if ($id) {
                $stmt = $this->conn->prepare("UPDATE warehouses SET name = ?, address = ?, phone = ?, is_default = ?, is_active = ?, show_on_dashboard = ?, warehouse_type = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param("sssiiisii", $name, $address, $phone, $is_default, $is_active, $show_on_dashboard, $warehouse_type, $id, $entity_id);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO warehouses (entity_id, name, address, phone, is_default, is_active, show_on_dashboard, warehouse_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssiiis", $entity_id, $name, $address, $phone, $is_default, $is_active, $show_on_dashboard, $warehouse_type);
            }
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            if (!$id) {
                $id = $this->conn->insert_id;
            }
            $stmt->close();
            
            $this->conn->commit();
            
            $is_new = empty($data['id']);
            log_activity($this->conn, 'SAVE_WAREHOUSE', "انبار «{$name}» " . ($is_new ? "ایجاد شد." : "ویرایش شد."));
            
            send_json(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => $e->getMessage()], 500);
        }
    }

    public function listConvertibleOrders($data) {
        check_permission('manage_products');
        $this->ensureDashboardColumn();
        $entity_id = $_SESSION['current_entity_id'];
        $search = trim($data['search'] ?? '');
        $where = [
            'ii.entity_id = ?',
            "ii.status = 'sold'",
            'ii.sales_invoice_item_id IS NOT NULL',
            "(si.delivery_status IS NULL OR si.delivery_status <> 'delivered')",
            "(ii.warehouse_id IS NULL OR w.warehouse_type IS NULL OR w.warehouse_type <> 'order')",
        ];
        $params = [$entity_id];
        $types = 'i';
        if ($search !== '') {
            $where[] = "(ii.serial_id LIKE ? OR CONCAT('INV-', LPAD(ii.id, 6, '0')) LIKE ? OR p.name LIKE ? OR c.name LIKE ? OR si.invoice_number LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
            $types .= 'sssss';
        }
        $sql = "
            SELECT ii.id, ii.dimensions, ii.warehouse_id,
                   COALESCE(NULLIF(ii.serial_id, ''), CONCAT('INV-', LPAD(ii.id, 6, '0'))) AS serial_id,
                   p.name AS product_name, p.design_code, p.colorway,
                   si.id AS sales_invoice_id, si.invoice_number, si.date AS sales_date, si.delivery_status,
                   c.name AS customer_name, w.name AS warehouse_name
            FROM inventory_items ii
            INNER JOIN products p ON p.id = ii.product_id
            INNER JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
            INNER JOIN sales_invoices si ON si.id = sii.invoiceId
            LEFT JOIN customers c ON c.id = si.customerId
            LEFT JOIN warehouses w ON w.id = ii.warehouse_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY si.date DESC, p.name ASC
            LIMIT 300
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        send_json(['items' => db_stmt_to_assoc_array($stmt)]);
    }

    public function assignSoldToOrderWarehouse($data) {
        check_permission('manage_products');
        $this->ensureDashboardColumn();
        $entity_id = $_SESSION['current_entity_id'];
        $warehouseId = intval($data['warehouse_id'] ?? 0);
        $itemIds = array_values(array_filter(array_map('intval', (array)($data['item_ids'] ?? []))));
        if ($warehouseId <= 0 || empty($itemIds)) {
            send_json(['error' => 'انبار سفارش و فرش‌ها را انتخاب کنید.'], 400);
            return;
        }
        $check = $this->conn->prepare("SELECT id, name FROM warehouses WHERE id = ? AND entity_id = ? AND warehouse_type = 'order' AND is_active = 1");
        $check->bind_param('ii', $warehouseId, $entity_id);
        $check->execute();
        $dest = db_stmt_to_assoc_array($check);
        if (empty($dest)) {
            send_json(['error' => 'انبار مقصد باید از نوع سفارش و فعال باشد.'], 400);
            return;
        }
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = "UPDATE inventory_items ii
                INNER JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
                INNER JOIN sales_invoices si ON si.id = sii.invoiceId
                SET ii.warehouse_id = ?
                WHERE ii.entity_id = ? AND ii.status = 'sold' AND ii.id IN ($ph)
                  AND (si.delivery_status IS NULL OR si.delivery_status <> 'delivered')";
        $upd = $this->conn->prepare($sql);
        $params = array_merge([$warehouseId, $entity_id], $itemIds);
        $upd->bind_param('ii' . str_repeat('i', count($itemIds)), ...$params);
        $upd->execute();
        $moved = $upd->affected_rows;
        $upd->close();
        log_activity($this->conn, 'ASSIGN_ORDER_WAREHOUSE', "{$moved} فرش فروخته‌شده به انبار سفارش «{$dest[0]['name']}» منتقل شد.");
        send_json(['success' => true, 'moved' => $moved]);
    }

    /**
     * تحویل فرش انبار سفارش. سایز واقعی و شناسه می‌تواند عوض شود.
     * ابعاد/قیمت (نسبت متراژ) در فاکتور خرید و فروش به‌روز می‌شود؛ جمع فاکتور بازمحاسبه می‌گردد.
     */
    public function deliverOrderItems($data) {
        check_permission('manage_products');
        $this->ensureDashboardColumn();
        $entity_id = $_SESSION['current_entity_id'];
        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            send_json(['error' => 'فرشی برای تحویل انتخاب نشده است.'], 400);
            return;
        }

        $defaultTargetWarehouseId = intval($data['target_warehouse_id'] ?? 0);

        $delivered = 0;
        $this->conn->begin_transaction();
        try {
            foreach ($items as $row) {
                $itemId = intval($row['id'] ?? 0);
                $newDimensions = trim((string)($row['dimensions'] ?? ''));
                $newSerial = array_key_exists('serial_id', $row) ? trim((string)$row['serial_id']) : null;
                if ($itemId <= 0) {
                    continue;
                }
                if ($newDimensions === '') {
                    throw new Exception('ابعاد تحویل را وارد کنید.');
                }

                $stmt = $this->conn->prepare("
                    SELECT ii.id, ii.dimensions, ii.status, ii.serial_id,
                           ii.sales_invoice_item_id, ii.purchase_invoice_item_id, ii.product_id,
                           w.warehouse_type
                    FROM inventory_items ii
                    INNER JOIN warehouses w ON w.id = ii.warehouse_id
                    WHERE ii.id = ? AND ii.entity_id = ? AND w.warehouse_type = 'order'
                    LIMIT 1
                ");
                $stmt->bind_param('ii', $itemId, $entity_id);
                $stmt->execute();
                $found = db_stmt_to_assoc_array($stmt);
                if (empty($found)) {
                    throw new Exception('فرش در انبار سفارش یافت نشد.');
                }
                $item = $found[0];
                if (!in_array($item['status'], ['sold', 'in_stock', 'returned'], true)) {
                    throw new Exception('این فرش قابل تحویل از انبار سفارش نیست.');
                }

                // فروخته‌شده → تحویل به مشتری (خروج از انبار).
                // بدون مشتری (فروش لغو/حذف شده یا مرجوع شده) → ورود به انبار فیزیکی مقصد/پیش‌فرض.
                $isSold = $item['status'] === 'sold';
                $targetWarehouseId = null;
                if (!$isSold) {
                    $targetWarehouseId = $this->resolveDeliverTargetWarehouse(
                        $entity_id,
                        intval($row['target_warehouse_id'] ?? 0) ?: $defaultTargetWarehouseId
                    );
                }

                $oldDimensions = (string)($item['dimensions'] ?? '');
                $dimensions = $newDimensions;
                $productId = intval($item['product_id']);

                if ($newSerial !== null) {
                    if ($newSerial === '') {
                        throw new Exception('شناسه انبار نمی‌تواند خالی باشد.');
                    }
                    $currentSerial = trim((string)($item['serial_id'] ?? ''));
                    if ($newSerial !== $currentSerial) {
                        $uniq = $this->conn->prepare("SELECT id FROM inventory_items WHERE serial_id = ? AND entity_id = ? AND id != ? LIMIT 1");
                        $uniq->bind_param('sii', $newSerial, $entity_id, $itemId);
                        $uniq->execute();
                        if (!empty(db_stmt_to_assoc_array($uniq))) {
                            throw new Exception("شناسه انبار «{$newSerial}» قبلاً استفاده شده است.");
                        }
                    }
                }

                $salesInvoiceIds = [];
                $purchaseInvoiceIds = [];
                $serialLabel = $newSerial !== null ? $newSerial : trim((string)($item['serial_id'] ?? ''));
                if ($serialLabel === '') {
                    $serialLabel = 'INV-' . str_pad((string)$itemId, 6, '0', STR_PAD_LEFT);
                }

                // فاکتور فروش فقط وقتی فرش هنوز فروخته‌شده است اصلاح می‌شود (نه برای مرجوعی)
                $salesItemId = $isSold ? intval($item['sales_invoice_item_id'] ?? 0) : 0;
                if ($salesItemId > 0) {
                    $salesChange = $this->applyDeliverDimensionToInvoiceLine(
                        'sales',
                        $salesItemId,
                        $itemId,
                        $productId,
                        $oldDimensions,
                        $dimensions,
                        $entity_id
                    );
                    if (!empty($salesChange['invoice_id'])) {
                        $salesInvoiceIds[] = intval($salesChange['invoice_id']);
                        $this->appendDeliverChangeNote('sales', intval($salesChange['invoice_id']), $entity_id, $serialLabel, $salesChange);
                    }
                }

                $purchaseItemId = intval($item['purchase_invoice_item_id'] ?? 0);
                if ($purchaseItemId > 0) {
                    $purchaseChange = $this->applyDeliverDimensionToInvoiceLine(
                        'purchase',
                        $purchaseItemId,
                        $itemId,
                        $productId,
                        $oldDimensions,
                        $dimensions,
                        $entity_id
                    );
                    if (!empty($purchaseChange['invoice_id'])) {
                        $purchaseInvoiceIds[] = intval($purchaseChange['invoice_id']);
                        $this->appendDeliverChangeNote('purchase', intval($purchaseChange['invoice_id']), $entity_id, $serialLabel, $purchaseChange);
                    }
                }

                if ($newSerial !== null) {
                    $upd = $this->conn->prepare("UPDATE inventory_items SET dimensions = ?, serial_id = ?, warehouse_id = ? WHERE id = ? AND entity_id = ?");
                    $upd->bind_param('ssiii', $dimensions, $newSerial, $targetWarehouseId, $itemId, $entity_id);
                } else {
                    $upd = $this->conn->prepare("UPDATE inventory_items SET dimensions = ?, warehouse_id = ? WHERE id = ? AND entity_id = ?");
                    $upd->bind_param('siii', $dimensions, $targetWarehouseId, $itemId, $entity_id);
                }
                $upd->execute();
                $upd->close();

                foreach (array_unique(array_filter($salesInvoiceIds)) as $invId) {
                    $this->recalculateInvoiceHeaderTotals('sales', intval($invId), $entity_id);
                }
                foreach (array_unique(array_filter($purchaseInvoiceIds)) as $invId) {
                    $this->recalculateInvoiceHeaderTotals('purchase', intval($invId), $entity_id);
                    $this->normalizePurchaseInvoiceAfterOrderDeliver(intval($invId), $entity_id, $targetWarehouseId);
                }
                // Even if dimensions did not change (no invoice_id collected above), still try normalize via purchase line
                if (empty($purchaseInvoiceIds) && $purchaseItemId > 0) {
                    $piLookup = $this->conn->prepare("SELECT invoiceId FROM purchase_invoice_items WHERE id = ? LIMIT 1");
                    $piLookup->bind_param('i', $purchaseItemId);
                    $piLookup->execute();
                    $piRow = db_stmt_to_assoc_array($piLookup);
                    if (!empty($piRow[0]['invoiceId'])) {
                        $this->normalizePurchaseInvoiceAfterOrderDeliver(intval($piRow[0]['invoiceId']), $entity_id, $targetWarehouseId);
                    }
                }

                $delivered++;
            }
            $this->conn->commit();
            log_activity($this->conn, 'DELIVER_ORDER_WAREHOUSE', "تحویل {$delivered} فرش از انبار سفارش ثبت شد.");
            send_json(['success' => true, 'delivered' => $delivered]);
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = ($e->getCode() >= 400 && $e->getCode() < 500) ? $e->getCode() : 400;
            send_json(['error' => $e->getMessage()], $code);
        }
    }

    /**
     * انبار مقصد برای فرش سفارشیِ بدون مشتری: انبار انتخاب‌شده، وگرنه انبار پیش‌فرض (فقط فیزیکی و فعال).
     */
    private function resolveDeliverTargetWarehouse($entityId, $requestedId) {
        if ($requestedId > 0) {
            $stmt = $this->conn->prepare("SELECT id FROM warehouses WHERE id = ? AND entity_id = ? AND is_active = 1 AND warehouse_type <> 'order' LIMIT 1");
            $stmt->bind_param('ii', $requestedId, $entityId);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt);
            if (empty($row)) {
                throw new Exception('انبار مقصد باید یک انبار فیزیکی فعال باشد.');
            }
            return intval($row[0]['id']);
        }
        $stmt = $this->conn->prepare("SELECT id FROM warehouses WHERE entity_id = ? AND is_active = 1 AND warehouse_type <> 'order' ORDER BY is_default DESC, id ASC LIMIT 1");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt);
        if (empty($row)) {
            throw new Exception('این فرش مشتری ندارد و باید به یک انبار فیزیکی تحویل شود؛ ابتدا یک انبار فیزیکی تعریف کنید.');
        }
        return intval($row[0]['id']);
    }

    /**
     * Update or split an invoice line for deliver size change.
     * @return array{invoice_id:int,changed:bool,old_dimensions:?string,new_dimensions:?string,old_price:?float,new_price:?float}
     */
    private function applyDeliverDimensionToInvoiceLine($type, $lineId, $inventoryItemId, $productId, $oldDimensions, $newDimensions, $entityId) {
        $empty = ['invoice_id' => 0, 'changed' => false, 'old_dimensions' => null, 'new_dimensions' => null, 'old_price' => null, 'new_price' => null];
        $isSales = ($type === 'sales');
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $linkColumn = $isSales ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';

        $stmt = $this->conn->prepare("SELECT id, invoiceId, productId, quantity, unitPrice, dimensions FROM `{$itemTable}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $lineId);
        $stmt->execute();
        $line = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$line) {
            return $empty;
        }
        $invoiceId = intval($line['invoiceId']);

        $hdr = $this->conn->prepare("SELECT id, date FROM `{$invoiceTable}` WHERE id = ? AND entity_id = ? LIMIT 1");
        $hdr->bind_param('ii', $invoiceId, $entityId);
        $hdr->execute();
        $hdrRow = db_stmt_to_assoc_array($hdr);
        if (empty($hdrRow)) {
            throw new Exception('فاکتور مرتبط یافت نشد.');
        }
        if (!empty($hdrRow[0]['date'])) {
            assert_transaction_date_editable($this->conn, $entityId, $hdrRow[0]['date']);
        }

        $oldLineDims = (string)($line['dimensions'] ?? $oldDimensions);
        $oldPrice = floatval($line['unitPrice']);
        $newPrice = rescale_unit_price_by_area($this->conn, $oldLineDims, $newDimensions, $oldPrice);
        $dimsChanged = ($oldLineDims !== $newDimensions);
        $priceChanged = (abs($oldPrice - $newPrice) > 0.0001);
        $result = [
            'invoice_id' => $invoiceId,
            'changed' => ($dimsChanged || $priceChanged),
            'old_dimensions' => $oldLineDims,
            'new_dimensions' => $newDimensions,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ];

        if (!$dimsChanged && !$priceChanged) {
            return $result;
        }

        $cntStmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM inventory_items WHERE `{$linkColumn}` = ?");
        $cntStmt->bind_param('i', $lineId);
        $cntStmt->execute();
        $linkedCount = intval((db_stmt_to_assoc_array($cntStmt)[0]['cnt'] ?? 0));
        $qty = intval($line['quantity']);

        if ($qty === 1 && $linkedCount <= 1) {
            $upd = $this->conn->prepare("UPDATE `{$itemTable}` SET dimensions = ?, unitPrice = ? WHERE id = ?");
            $upd->bind_param('sdi', $newDimensions, $newPrice, $lineId);
            $upd->execute();
            $upd->close();
            return $result;
        }

        $ins = $this->conn->prepare("INSERT INTO `{$itemTable}` (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, 1, ?, ?)");
        $ins->bind_param('iids', $invoiceId, $productId, $newPrice, $newDimensions);
        $ins->execute();
        $newLineId = intval($this->conn->insert_id);
        $ins->close();

        $link = $this->conn->prepare("UPDATE inventory_items SET `{$linkColumn}` = ?" . ($isSales ? "" : ", is_virtual = 0") . " WHERE id = ? AND entity_id = ?");
        $link->bind_param('iii', $newLineId, $inventoryItemId, $entityId);
        $link->execute();
        $link->close();

        $dec = $this->conn->prepare("UPDATE `{$itemTable}` SET quantity = quantity - 1 WHERE id = ?");
        $dec->bind_param('i', $lineId);
        $dec->execute();
        $dec->close();
        $this->conn->query("DELETE FROM `{$itemTable}` WHERE id = " . intval($lineId) . " AND quantity <= 0");

        return $result;
    }

    private function appendDeliverChangeNote($type, $invoiceId, $entityId, $serialLabel, $change) {
        if ($invoiceId <= 0 || empty($change['changed'])) {
            return;
        }
        $isSales = ($type === 'sales');
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $oldDim = (string)($change['old_dimensions'] ?? '');
        $newDim = (string)($change['new_dimensions'] ?? '');
        $oldPrice = floatval($change['old_price'] ?? 0);
        $newPrice = floatval($change['new_price'] ?? 0);
        $fmt = function ($n) {
            return number_format((float)$n, 0, '.', ',');
        };
        $note = "تحویل انبار سفارش — {$serialLabel}: ابعاد {$oldDim} ← {$newDim}؛ قیمت {$fmt($oldPrice)} ← {$fmt($newPrice)}";

        $stmt = $this->conn->prepare("SELECT description FROM `{$invoiceTable}` WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $invoiceId, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if ($row === null) {
            return;
        }
        $existing = trim((string)($row['description'] ?? ''));
        if ($existing !== '' && mb_strpos($existing, $note) !== false) {
            return;
        }
        $newDesc = $existing === '' ? $note : ($existing . "\n" . $note);
        $upd = $this->conn->prepare("UPDATE `{$invoiceTable}` SET description = ? WHERE id = ? AND entity_id = ?");
        $upd->bind_param('sii', $newDesc, $invoiceId, $entityId);
        $upd->execute();
        $upd->close();
    }

    /**
     * After the last factory-order stock of a purchase leaves the order warehouse,
     * convert that purchase invoice back to a normal (non-order) warehouse state,
     * while keeping a lasting note that it was originally a factory order.
     */
    private function normalizePurchaseInvoiceAfterOrderDeliver($purchaseInvoiceId, $entityId, $fallbackPhysicalWarehouseId = null) {
        if ($purchaseInvoiceId <= 0) {
            return;
        }
        $this->ensureDashboardColumn();

        $hdr = $this->conn->prepare("
            SELECT pi.id, pi.description, pi.warehouse_id, w.warehouse_type
            FROM purchase_invoices pi
            LEFT JOIN warehouses w ON w.id = pi.warehouse_id
            WHERE pi.id = ? AND pi.entity_id = ?
            LIMIT 1
        ");
        $hdr->bind_param('ii', $purchaseInvoiceId, $entityId);
        $hdr->execute();
        $invoice = db_stmt_to_assoc_array($hdr)[0] ?? null;
        if (!$invoice) {
            return;
        }
        if (($invoice['warehouse_type'] ?? '') !== 'order') {
            return;
        }

        $remain = $this->conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM inventory_items ii
            INNER JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
            INNER JOIN warehouses w ON w.id = ii.warehouse_id AND w.warehouse_type = 'order'
            WHERE pii.invoiceId = ? AND ii.entity_id = ?
        ");
        $remain->bind_param('ii', $purchaseInvoiceId, $entityId);
        $remain->execute();
        $remaining = intval((db_stmt_to_assoc_array($remain)[0]['cnt'] ?? 0));
        if ($remaining > 0) {
            return;
        }

        $note = 'ثبت به‌عنوان سفارش به کارخانه';
        $desc = trim((string)($invoice['description'] ?? ''));
        if ($desc === '' || mb_strpos($desc, $note) === false) {
            $desc = $desc === '' ? $note : ($desc . "\n" . $note);
        }

        $newWarehouseId = null;
        $fallbackId = intval($fallbackPhysicalWarehouseId ?? 0);
        if ($fallbackId > 0) {
            $chk = $this->conn->prepare("SELECT id FROM warehouses WHERE id = ? AND entity_id = ? AND warehouse_type <> 'order' LIMIT 1");
            $chk->bind_param('ii', $fallbackId, $entityId);
            $chk->execute();
            if (!empty(db_stmt_to_assoc_array($chk))) {
                $newWarehouseId = $fallbackId;
            }
        }

        if ($newWarehouseId === null) {
            $upd = $this->conn->prepare("UPDATE purchase_invoices SET warehouse_id = NULL, description = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('sii', $desc, $purchaseInvoiceId, $entityId);
        } else {
            $upd = $this->conn->prepare("UPDATE purchase_invoices SET warehouse_id = ?, description = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('isii', $newWarehouseId, $desc, $purchaseInvoiceId, $entityId);
        }
        $upd->execute();
        $upd->close();
    }

    private function recalculateInvoiceHeaderTotals($type, $invoiceId, $entityId) {
        if ($invoiceId <= 0) {
            return;
        }
        $isSales = ($type === 'sales');
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';

        $sumStmt = $this->conn->prepare("SELECT COALESCE(SUM(quantity * unitPrice), 0) AS total FROM `{$itemTable}` WHERE invoiceId = ?");
        $sumStmt->bind_param('i', $invoiceId);
        $sumStmt->execute();
        $totalAmount = round(floatval((db_stmt_to_assoc_array($sumStmt)[0]['total'] ?? 0)), 2);

        $hdr = $this->conn->prepare("SELECT discount, tax_rate FROM `{$invoiceTable}` WHERE id = ? AND entity_id = ? LIMIT 1");
        $hdr->bind_param('ii', $invoiceId, $entityId);
        $hdr->execute();
        $row = db_stmt_to_assoc_array($hdr)[0] ?? null;
        if (!$row) {
            return;
        }
        $discount = floatval($row['discount'] ?? 0);
        if ($discount < 0) {
            $discount = 0;
        }
        if ($discount > $totalAmount) {
            $discount = $totalAmount;
        }
        $taxRate = floatval($row['tax_rate'] ?? 0);
        $taxableBase = $totalAmount - $discount;
        $taxAmount = ($taxRate > 0 && $taxableBase > 0) ? round($taxableBase * $taxRate / 100) : 0.0;

        $upd = $this->conn->prepare("UPDATE `{$invoiceTable}` SET totalAmount = ?, discount = ?, tax_amount = ? WHERE id = ? AND entity_id = ?");
        $upd->bind_param('dddii', $totalAmount, $discount, $taxAmount, $invoiceId, $entityId);
        $upd->execute();
        $upd->close();
    }

    /**
     * حذف انبار
     */
    public function deleteWarehouse($data) {
        check_permission('manage_products');
        
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            send_json(['error' => 'شناسه نامعتبر است.'], 400);
            return;
        }
        
        $entity_id = $_SESSION['current_entity_id'];
        
        // بررسی موجودی در انبار
        $stmt_check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM inventory_items WHERE warehouse_id = ? AND status IN ('in_stock', 'returned')");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $result = db_stmt_to_assoc_array($stmt_check);
        
        if ($result[0]['cnt'] > 0) {
            send_json(['error' => 'این انبار دارای موجودی است و قابل حذف نیست. ابتدا کالاها را منتقل کنید.'], 409);
            return;
        }
        
        $stmt = $this->conn->prepare("DELETE FROM warehouses WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $id, $entity_id);
        
        if ($stmt->execute()) {
            log_activity($this->conn, 'DELETE_WAREHOUSE', "انبار با شناسه {$id} حذف شد.");
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'خطا در حذف انبار.'], 500);
        }
    }

    /**
     * انتقال موجودی بین انبارها
     */
    public function transferStock($data) {
        check_permission('manage_products');
        
        $itemIds = $data['item_ids'] ?? [];
        $toWarehouseId = intval($data['to_warehouse_id'] ?? 0);
        
        if (empty($itemIds) || !$toWarehouseId) {
            send_json(['error' => 'اطلاعات انتقال ناقص است.'], 400);
            return;
        }
        
        $entity_id = $_SESSION['current_entity_id'];
        
        // بررسی وجود انبار مقصد
        $stmt_check = $this->conn->prepare("SELECT id FROM warehouses WHERE id = ? AND entity_id = ?");
        $stmt_check->bind_param("ii", $toWarehouseId, $entity_id);
        $stmt_check->execute();
        if (!$stmt_check->fetch()) {
            $stmt_check->close();
            send_json(['error' => 'انبار مقصد یافت نشد.'], 404);
            return;
        }
        $stmt_check->close();
        
        $this->conn->begin_transaction();
        try {
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $types = str_repeat('i', count($itemIds));
            
            $stmt = $this->conn->prepare("
                UPDATE inventory_items 
                SET warehouse_id = ? 
                WHERE id IN ($placeholders) AND entity_id = ? AND status IN ('in_stock', 'returned')
            ");
            
            $params = array_merge([$toWarehouseId], $itemIds, [$entity_id]);
            $stmt->bind_param('i' . $types . 'i', ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            $this->conn->commit();

            // Notify Medusa of stock changes (product ids of transferred items)
            try {
                require_once __DIR__ . '/../core/IntegrationService.php';
                $svc = new IntegrationService($this->conn);
                $ph2 = implode(',', array_fill(0, count($itemIds), '?'));
                $types2 = str_repeat('i', count($itemIds));
                $pStmt = $this->conn->prepare("SELECT DISTINCT product_id FROM inventory_items WHERE id IN ($ph2)");
                $pStmt->bind_param($types2, ...$itemIds);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                $pids = [];
                while ($pr = $pRes->fetch_assoc()) {
                    $pids[] = intval($pr['product_id']);
                }
                $pStmt->close();
                if (!empty($pids)) {
                    $svc->enqueueStockSync($entity_id, $pids);
                }
            } catch (Throwable $ie) {
                error_log('Stock sync enqueue failed: ' . $ie->getMessage());
            }
            
            log_activity($this->conn, 'TRANSFER_STOCK', "تعداد {$affected} آیتم به انبار شماره {$toWarehouseId} منتقل شد.");
            
            send_json(['success' => true, 'transferred_count' => $affected]);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در انتقال موجودی: ' . $e->getMessage()], 500);
        }
    }
}