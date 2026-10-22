<?php
// /api/controllers/ProductController.php

require_once __DIR__ . '/../models/Product.php';

class ProductController {
    private $conn;
    private $productModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->productModel = new Product($db);
    }

    private function uploadErrorMessage($fieldName = 'image') {
        if (!isset($_FILES[$fieldName])) {
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax = $this->iniSizeToBytes(ini_get('post_max_size'));
            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                return 'حجم فایل تصویر بیشتر از حد مجاز سرور است. تصویر کوچک‌تری انتخاب کنید یا کیفیت را کاهش دهید.';
            }
            return 'فایل تصویر الزامی است.';
        }

        switch ((int)($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE)) {
            case UPLOAD_ERR_OK:
                return '';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'حجم فایل تصویر بیشتر از حد مجاز است.';
            case UPLOAD_ERR_PARTIAL:
                return 'آپلود فایل تصویر کامل انجام نشد. لطفاً دوباره تلاش کنید.';
            case UPLOAD_ERR_NO_FILE:
                return 'فایل تصویر الزامی است.';
            default:
                return 'خطا در دریافت فایل تصویر.';
        }
    }

    private function iniSizeToBytes($value) {
        $value = trim((string)$value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        switch ($unit) {
            case 'g': return (int)($num * 1024 * 1024 * 1024);
            case 'm': return (int)($num * 1024 * 1024);
            case 'k': return (int)($num * 1024);
            default: return (int)$num;
        }
    }

    /** Product must belong to the current entity (images, stock rows, labels). */
    private function requireOwnedProduct($productId) {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $st = $this->conn->prepare("SELECT id FROM products WHERE id = ? AND entity_id = ?");
        $pid = (int)$productId;
        $st->bind_param('ii', $pid, $entityId);
        $st->execute();
        if (empty(db_stmt_to_assoc_array($st))) {
            send_json(['error' => 'محصول یافت نشد یا متعلق به این مجموعه نیست.'], 404);
        }
    }

    /** Image row whose product belongs to the current entity (or 404). */
    private function requireOwnedImage($imageId) {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $st = $this->conn->prepare("SELECT pi.* FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ? AND p.entity_id = ?");
        $iid = (int)$imageId;
        $st->bind_param('ii', $iid, $entityId);
        $st->execute();
        $row = db_stmt_to_assoc_array($st)[0] ?? null;
        if (!$row) {
            send_json(['error' => 'تصویر یافت نشد.'], 404);
        }
        return $row;
    }

    public function save($data) {
        check_permission('manage_products');
        // Server-side validation
        if (empty(trim($data['name']))) {
            send_json(['error' => 'نام طرح محصول الزامی است.'], 400);
            return;
        }
        $result = $this->productModel->save($data);
        if (isset($result['success'])) {
            $is_new = empty($data['id']);
            $log_description = "محصول «{$data['name']}» با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, 'SAVE_PRODUCT', $log_description);
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function delete($data) {
        check_permission('manage_products');
        $id = $data['id'] ?? null;
        if (empty($id) || !is_numeric($id)) {
            send_json(['error' => 'شناسه نامعتبر است.'], 400);
            return;
        }
        
        // Capture name before hard delete (for webhook / activity log)
        $productName = '';
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);
        try {
            $stmtName = $this->conn->prepare('SELECT name, entity_id FROM products WHERE id = ? LIMIT 1');
            $pid = intval($id);
            $stmtName->bind_param('i', $pid);
            $stmtName->execute();
            $row = db_stmt_to_assoc_array($stmtName)[0] ?? null;
            if ($row) {
                $productName = (string)($row['name'] ?? '');
                if (!$entityId) {
                    $entityId = intval($row['entity_id'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // non-fatal
        }

        $result = $this->productModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_PRODUCT', "محصول با شناسه {$id} حذف شد.");
            // Tell linked storefront(s): mark unavailable, keep site images
            try {
                require_once __DIR__ . '/../core/IntegrationService.php';
                $svc = new IntegrationService($this->conn);
                if ($entityId > 0) {
                    $svc->enqueueProductRemoved($entityId, intval($id), $productName);
                    $svc->processOutbox(5);
                }
            } catch (Throwable $ie) {
                error_log('product.removed enqueue failed: ' . $ie->getMessage());
            }
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
    
    public function getSerializedItems($data) {
        check_permission('manage_products');
        $productId = intval($data['productId'] ?? 0);
        if (!$productId) {
            send_json(['error' => 'شناسه محصول الزامی است.'], 400);
            return;
        }
        $this->requireOwnedProduct($productId);
        $items = $this->productModel->getSerializedItems($productId);
        send_json($items);
    }

    public function addSerializedItem($data) {
        check_permission('manage_products');
        $productId = intval($data['productId'] ?? 0);
        $dimensions = trim($data['dimensions'] ?? '');
        $count = intval($data['count'] ?? 0);
        $warehouseId = !empty($data['warehouse_id']) ? intval($data['warehouse_id']) : null;

        if (!$productId || empty($dimensions) || $count <= 0) {
            send_json(['error' => 'اطلاعات وارد شده برای افزودن آیتم ناقص یا نامعتبر است.'], 400);
            return;
        }

        $this->requireOwnedProduct($productId);
        $result = $this->productModel->addSerializedItems($productId, $dimensions, $count, $warehouseId);
        if (isset($result['success'])) {
            log_activity($this->conn, 'ADD_INVENTORY_ITEM', "تعداد {$count} آیتم با ابعاد {$dimensions} برای محصول {$productId} به انبار اضافه شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function updateSerializedItem($data) {
        check_permission('manage_products');
        $itemId = intval($data['itemId'] ?? 0);
        $dimensions = trim($data['dimensions'] ?? '');
        $serial_id = isset($data['serial_id']) ? trim($data['serial_id']) : null;
        $warehouseId = isset($data['warehouse_id']) ? (intval($data['warehouse_id']) ?: null) : null;

        if (!$itemId || empty($dimensions)) {
            send_json(['error' => 'شناسه آیتم و ابعاد جدید الزامی است.'], 400);
            return;
        }

        $result = $this->productModel->updateSerializedItem($itemId, $dimensions, $serial_id, $warehouseId);
        if (isset($result['success'])) {
            log_activity($this->conn, 'UPDATE_INVENTORY_ITEM', "آیتم انبار با شناسه {$itemId} ویرایش شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * انتقال آیتم‌ها بین انبارها
     */
    public function transferItems($data) {
        check_permission('manage_products');
        $toWarehouseId = intval($data['to_warehouse_id'] ?? 0);
        $itemIds = array_values(array_filter(array_map('intval', (array)($data['item_ids'] ?? []))));
        $productIds = array_values(array_filter(array_map('intval', (array)($data['product_ids'] ?? []))));
        $fromWarehouseRaw = $data['from_warehouse_id'] ?? null;
        $moveAll = !empty($data['move_all']);

        if (!$toWarehouseId || (empty($itemIds) && empty($productIds) && !$moveAll)) {
            send_json(['error' => 'فرش‌ها و انبار مقصد را انتخاب کنید.'], 400);
            return;
        }
        if ($moveAll && ($fromWarehouseRaw === null || $fromWarehouseRaw === '' || $fromWarehouseRaw === 'all')) {
            send_json(['error' => 'برای انتقال همه، انبار مبدأ را مشخص کنید.'], 400);
            return;
        }

        $entity_id = $_SESSION['current_entity_id'];

        $stmt_check = $this->conn->prepare("SELECT id, name FROM warehouses WHERE id = ? AND entity_id = ? AND is_active = 1");
        $stmt_check->bind_param("ii", $toWarehouseId, $entity_id);
        $stmt_check->execute();
        $dest = db_stmt_to_assoc_array($stmt_check);
        if (empty($dest)) {
            send_json(['error' => 'انبار مقصد یافت نشد یا غیرفعال است.'], 404);
            return;
        }

        $where = ["ii.entity_id = ?", "ii.status IN ('in_stock', 'returned')", "(ii.warehouse_id IS NULL OR ii.warehouse_id <> ?)"];
        $params = [$entity_id, $toWarehouseId];
        $types = 'ii';

        if ($fromWarehouseRaw === '__no_warehouse__' || $fromWarehouseRaw === 'none') {
            $where[] = '(ii.warehouse_id IS NULL OR ii.warehouse_id = 0)';
        } elseif ($fromWarehouseRaw !== null && $fromWarehouseRaw !== '' && $fromWarehouseRaw !== 'all') {
            $fromWarehouseId = intval($fromWarehouseRaw);
            if ($fromWarehouseId > 0) {
                $where[] = 'ii.warehouse_id = ?';
                $params[] = $fromWarehouseId;
                $types .= 'i';
            }
        }

        if (!$moveAll) {
            $scope = [];
            if (!empty($itemIds)) {
                $scope[] = 'ii.id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
                foreach ($itemIds as $id) {
                    $params[] = $id;
                    $types .= 'i';
                }
            }
            if (!empty($productIds)) {
                $scope[] = 'ii.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')';
                foreach ($productIds as $id) {
                    $params[] = $id;
                    $types .= 'i';
                }
            }
            if (empty($scope)) {
                send_json(['error' => 'فرشی برای انتقال انتخاب نشده است.'], 400);
                return;
            }
            $where[] = '(' . implode(' OR ', $scope) . ')';
        }

        $findSql = 'SELECT ii.id, ii.product_id FROM inventory_items ii WHERE ' . implode(' AND ', $where);
        $find = $this->conn->prepare($findSql);
        $find->bind_param($types, ...$params);
        $find->execute();
        $matches = db_stmt_to_assoc_array($find);
        $matchIds = [];
        $syncProductIds = $productIds;
        foreach ($matches as $row) {
            $matchIds[] = intval($row['id']);
            $syncProductIds[] = intval($row['product_id']);
        }

        $transferred = 0;
        if (!empty($matchIds)) {
            $ph = implode(',', array_fill(0, count($matchIds), '?'));
            $upd = $this->conn->prepare("UPDATE inventory_items SET warehouse_id = ? WHERE entity_id = ? AND id IN ($ph)");
            $updParams = array_merge([$toWarehouseId, $entity_id], $matchIds);
            $upd->bind_param('ii' . str_repeat('i', count($matchIds)), ...$updParams);
            $upd->execute();
            $transferred = $upd->affected_rows;
            $upd->close();
        }

        if ($transferred > 0) {
            try {
                require_once __DIR__ . '/../core/IntegrationService.php';
                $svc = new IntegrationService($this->conn);
                $pids = array_values(array_unique(array_filter($syncProductIds)));
                if (!empty($pids)) {
                    $svc->enqueueStockSync($entity_id, $pids);
                }
            } catch (Throwable $ie) {
                error_log('Stock sync enqueue failed: ' . $ie->getMessage());
            }
        }

        $destName = $dest[0]['name'] ?? $toWarehouseId;
        log_activity($this->conn, 'TRANSFER_INVENTORY', "تعداد {$transferred} فرش به انبار «{$destName}» منتقل شد.");
        send_json(['success' => true, 'transferred' => $transferred]);
    }

    public function deleteSerializedItem($data) {
        check_permission('manage_products');
        $itemId = intval($data['itemId'] ?? 0);
        if (!$itemId) {
            send_json(['error' => 'شناسه آیتم نامعتبر است.'], 400);
            return;
        }

        $result = $this->productModel->deleteSerializedItem($itemId);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_INVENTORY_ITEM', "آیتم انبار با شناسه {$itemId} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
    
    private function generateLabelDataForId($serializedId) {
        $labelEntity = (int)($_SESSION['current_entity_id'] ?? 0);
        $stmt_item = $this->conn->prepare("SELECT product_id, dimensions, serial_id FROM inventory_items WHERE id = ? AND entity_id = ?");
        $stmt_item->bind_param("ii", $serializedId, $labelEntity);
        $stmt_item->execute();
        $item_res = db_stmt_to_assoc_array($stmt_item);
        $item = $item_res[0] ?? null;

        if (!$item) {
            return null;
        }

        $productId = $item['product_id'];
        $dimensions = $item['dimensions'];
        $serial_id = $item['serial_id'] ?: ('INV-' . str_pad((string)$serializedId, 6, '0', STR_PAD_LEFT));

        $stmt_prod = $this->conn->prepare("
            SELECT p.id, p.name, p.design_code, p.colorway, p.collection_id, 
                   c.name as collection_name, c.price_per_meter
            FROM products p
            LEFT JOIN product_collections c ON p.collection_id = c.id
            WHERE p.id = ?
        ");
        $stmt_prod->bind_param("i", $productId);
        $stmt_prod->execute();
        $p_res = db_stmt_to_assoc_array($stmt_prod);
        $p = $p_res[0] ?? null;

        if (!$p) return null;

        $attributes = [];
        if ($p['collection_id']) {
            $stmt_attrs = $this->conn->prepare("
                SELECT at.name, pca.value
                FROM product_collection_attributes pca
                JOIN product_attribute_types at ON pca.attribute_type_id = at.id
                WHERE pca.collection_id = ?
            ");
            $stmt_attrs->bind_param("i", $p['collection_id']);
            $stmt_attrs->execute();
            foreach (db_stmt_to_assoc_array($stmt_attrs) as $attr) {
                $attributes[$attr['name']] = $attr['value'];
            }
        }
        
        $official_price = 0;
        if ($dimensions && (strpos($dimensions, 'x') !== false || strpos($dimensions, '*') !== false)) {
             $cmThreshold = floatval(get_app_setting($this->conn, 'dimension_cm_threshold', 15));
             if ($cmThreshold <= 0) $cmThreshold = 15;
             list($w, $h) = preg_split('/[x*]/', $dimensions);
             $width_m = floatval($w);
             $height_m = floatval($h);
             // Convert each side independently (see PriceList).
             if ($width_m > $cmThreshold) $width_m /= 100;
             if ($height_m > $cmThreshold) $height_m /= 100;
             if ($p['price_per_meter'] > 0) {
                 $official_price = ($width_m * $height_m) * floatval($p['price_per_meter']);
             }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        
        // محاسبه مسیر پایه برنامه
        // SCRIPT_NAME برای API: /hesabdari/api/index.php
        // باید به /hesabdari برسیم
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $base_path = '';
        
        // اگر از api/ فراخوانی شده، دو سطح بالا برو
        if (strpos($script_name, '/api/') !== false) {
            $base_path = dirname(dirname($script_name));
        } else {
            $base_path = dirname($script_name);
        }
        
        // اطمینان از عدم وجود / در انتها و مدیریت ریشه
        $base_path = rtrim($base_path, '/\\');
        if ($base_path === '' || $base_path === '.') {
            $base_path = '';
        }
        
        $scan_url = $protocol . $host . $base_path . '/scan.php?id=' . $serializedId;
        
        return [
            'serialized_id' => $serial_id,
            'product_name' => $p['name'] ?? '',
            'dimensions' => $dimensions,
            'collection_name' => $p['collection_name'] ?? '',
            'design_code' => $p['design_code'] ?? '',
            'colorway' => $p['colorway'] ?? '',
            'attributes' => $attributes,
            'scan_url' => $scan_url,
            'official_price' => $official_price
        ];
    }

    public function labelData($data) {
        check_permission('manage_products');
        $serializedId = intval($data['id'] ?? 0);
        if (!$serializedId) { 
            send_json(['error'=>'شناسه آیتم نامعتبر است'], 400); 
            return;
        }
        $labelData = $this->generateLabelDataForId($serializedId);
        if ($labelData) {
            send_json(['label' => $labelData]);
        } else {
            send_json(['error'=>'آیتم یافت نشد'], 404);
        }
    }
    
    public function getBatchLabelData($data) {
        check_permission('manage_products');
        $ids = $data['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            send_json(['error' => 'هیچ شناسه‌ای برای چاپ انتخاب نشده است.'], 400);
            return;
        }

        $labels = [];
        foreach($ids as $id) {
            $labelData = $this->generateLabelDataForId(intval($id));
            if ($labelData) {
                $labels[] = $labelData;
            }
        }
        send_json(['labels' => $labels]);
    }

    /**
     * دریافت لیبل‌های تمام آیتم‌های انبار برای محصولات انتخاب‌شده
     */
    public function getLabelsForProducts($data) {
        check_permission('manage_products');
        $entityId = $_SESSION['current_entity_id'];
        $productIds = $data['productIds'] ?? [];
        $allProducts = $data['allProducts'] ?? false;

        if (!$allProducts && (empty($productIds) || !is_array($productIds))) {
            send_json(['error' => 'هیچ محصولی انتخاب نشده است.'], 400);
            return;
        }

        // دریافت شناسه آیتم‌های انبار
        if ($allProducts) {
            $stmt = $this->conn->prepare("
                SELECT ii.id FROM inventory_items ii
                JOIN products p ON ii.product_id = p.id
                WHERE p.entity_id = ? AND ii.status = 'in_stock'
                ORDER BY p.name, ii.id
            ");
            $stmt->bind_param("i", $entityId);
        } else {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $types = 'i' . str_repeat('i', count($productIds));
            $stmt = $this->conn->prepare("
                SELECT ii.id FROM inventory_items ii
                JOIN products p ON ii.product_id = p.id
                WHERE p.entity_id = ? AND ii.product_id IN ($placeholders) AND ii.status = 'in_stock'
                ORDER BY p.name, ii.id
            ");
            $params = array_merge([$entityId], array_map('intval', $productIds));
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $itemIds = array_column(db_stmt_to_assoc_array($stmt), 'id');

        if (empty($itemIds)) {
            send_json(['error' => 'هیچ آیتم موجود در انبار برای محصولات انتخاب‌شده یافت نشد.'], 400);
            return;
        }

        $labels = [];
        foreach ($itemIds as $id) {
            $labelData = $this->generateLabelDataForId(intval($id));
            if ($labelData) {
                $labels[] = $labelData;
            }
        }
        send_json(['labels' => $labels, 'totalItems' => count($labels)]);
    }

    /**
     * آپلود تصویر محصول
     */
    public function uploadImage($data) {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('manage_products', $perms) && !in_array('manage_purchase_invoices', $perms)) {
            send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
            return;
        }
        
        $productId = intval($data['product_id'] ?? 0);
        if (!$productId) {
            send_json(['error' => 'شناسه محصول الزامی است.'], 400);
            return;
        }
        
        $this->requireOwnedProduct($productId);
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            send_json(['error' => 'خطا در آپلود فایل.'], 400);
            return;
        }
        
        $file = $_FILES['image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        
        if (!in_array($file['type'], $allowed_types)) {
            send_json(['error' => 'فرمت تصویر مجاز نیست. فقط JPEG, PNG, WebP و GIF مجاز است.'], 400);
            return;
        }
        
        $upload_dir = __DIR__ . '/../../uploads/products/' . $productId;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $thumb_dir = $upload_dir . '/thumbs';
        if (!is_dir($thumb_dir)) {
            mkdir($thumb_dir, 0755, true);
        }
        
        // همیشه به فرمت JPEG ذخیره می‌کنیم برای بهینه‌سازی حجم
        $filename = uniqid() . '_' . time() . '.jpg';
        $filepath = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/products/' . $productId . '/' . $filename;
        $thumb_filename = $filename;
        $thumb_filepath = $thumb_dir . '/' . $thumb_filename;
        $relative_thumb = 'uploads/products/' . $productId . '/thumbs/' . $thumb_filename;
        
        // فشرده‌سازی همه تصاویر به حداکثر 1 مگابایت
        $compressed = $this->compressImage($file['tmp_name'], $filepath, $file['type'], $file['size']);
        if (!$compressed) {
            send_json(['error' => 'خطا در پردازش تصویر.'], 500);
            return;
        }

        $thumbOk = $this->createThumbnailFromFile($filepath, $thumb_filepath, 300, 72);
        if (!$thumbOk) {
            // اگر بند انگشتی ساخته نشد، لیست از فایل اصلی استفاده می‌کند
            $relative_thumb = null;
        }
        
        // بررسی آیا اولین تصویر است
        $stmt_count = $this->conn->prepare("SELECT COUNT(*) as cnt FROM product_images WHERE product_id = ?");
        $stmt_count->bind_param("i", $productId);
        $stmt_count->execute();
        $result = db_stmt_to_assoc_array($stmt_count);
        $is_primary = ($result[0]['cnt'] == 0) ? 1 : 0;
        
        // ذخیره در دیتابیس
        $stmt = $this->conn->prepare("INSERT INTO product_images (product_id, file_name, file_path, thumb_path, is_primary) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $productId, $filename, $relative_path, $relative_thumb, $is_primary);
        
        if ($stmt->execute()) {
            $imageId = $this->conn->insert_id;
            log_activity($this->conn, 'UPLOAD_PRODUCT_IMAGE', "تصویر برای محصول شماره {$productId} آپلود شد.");
            send_json([
                'success' => true, 
                'id' => $imageId,
                'file_path' => $relative_path,
                'thumb_path' => $relative_thumb,
                'is_primary' => $is_primary
            ]);
        } else {
            @unlink($filepath);
            @unlink($thumb_filepath);
            send_json(['error' => 'خطا در ذخیره اطلاعات تصویر.'], 500);
        }
    }

    /**
     * فشرده‌سازی تصویر با هدف رسیدن به حداکثر 1 مگابایت
     */
    private function compressImage($source, $destination, $mime_type, $original_size) {
        // ایجاد تصویر از فایل آپلود شده
        switch ($mime_type) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($source);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($source);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        // تعیین حداکثر ابعاد بر اساس حجم فایل اصلی
        $target_size = 1 * 1024 * 1024; // هدف: 1 مگابایت
        
        if ($original_size > 10 * 1024 * 1024) {
            // بالای 10 مگ: ابعاد 1200 پیکسل
            $max_dimension = 1200;
            $quality = 70;
        } else if ($original_size > 5 * 1024 * 1024) {
            // 5 تا 10 مگ: ابعاد 1400 پیکسل
            $max_dimension = 1400;
            $quality = 75;
        } else if ($original_size > 2 * 1024 * 1024) {
            // 2 تا 5 مگ: ابعاد 1600 پیکسل
            $max_dimension = 1600;
            $quality = 80;
        } else if ($original_size > 1 * 1024 * 1024) {
            // 1 تا 2 مگ: ابعاد 1800 پیکسل
            $max_dimension = 1800;
            $quality = 85;
        } else {
            // زیر 1 مگ: ابعاد اصلی با کیفیت 85
            $max_dimension = max($width, $height);
            $quality = 85;
        }
        
        // تغییر اندازه اگر بزرگتر از حد مجاز است
        if ($width > $max_dimension || $height > $max_dimension) {
            if ($width > $height) {
                $new_width = $max_dimension;
                $new_height = intval($height * ($max_dimension / $width));
            } else {
                $new_height = $max_dimension;
                $new_width = intval($width * ($max_dimension / $height));
            }
            
            $resized = imagecreatetruecolor($new_width, $new_height);
            
            // پس‌زمینه سفید برای تصاویر PNG با شفافیت
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }
        
        // ذخیره به فرمت JPEG برای بهترین فشرده‌سازی
        $result = imagejpeg($image, $destination, $quality);
        
        // اگر هنوز بزرگتر از 1 مگ است، با کیفیت کمتر امتحان کن
        if ($result && filesize($destination) > $target_size) {
            $quality = 60;
            $result = imagejpeg($image, $destination, $quality);
        }
        
        // آخرین تلاش با کیفیت خیلی پایین
        if ($result && filesize($destination) > $target_size) {
            $quality = 50;
            $result = imagejpeg($image, $destination, $quality);
        }
        
        imagedestroy($image);
        return $result;
    }

    /**
     * ساخت بند انگشتی کم‌حجم برای لیست محصولات (حداکثر ضلع ~300px)
     */
    private function createThumbnailFromFile($sourcePath, $destination, $maxDimension = 300, $quality = 72) {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
            return false;
        }
        if (!is_file($sourcePath)) {
            return false;
        }
        $image = @imagecreatefromjpeg($sourcePath);
        if (!$image) {
            // ممکن است هنوز png/webp قدیمی باشد
            $info = @getimagesize($sourcePath);
            if (!$info) return false;
            switch ($info['mime'] ?? '') {
                case 'image/png': $image = @imagecreatefrompng($sourcePath); break;
                case 'image/webp': $image = @imagecreatefromwebp($sourcePath); break;
                case 'image/gif': $image = @imagecreatefromgif($sourcePath); break;
                default: return false;
            }
        }
        if (!$image) return false;

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return false;
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            if ($width > $height) {
                $new_width = $maxDimension;
                $new_height = max(1, intval($height * ($maxDimension / $width)));
            } else {
                $new_height = $maxDimension;
                $new_width = max(1, intval($width * ($maxDimension / $height)));
            }
            $resized = imagecreatetruecolor($new_width, $new_height);
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $result = imagejpeg($image, $destination, $quality);
        imagedestroy($image);
        return (bool)$result;
    }

    /**
     * اطمینان از وجود بند انگشتی برای یک ردیف تصویر (تصاویر قدیمی)
     */
    private function ensureImageThumbnail(array &$image) {
        $root = __DIR__ . '/../../';
        $thumbRel = $image['thumb_path'] ?? null;
        if (!empty($thumbRel) && is_file($root . $thumbRel)) {
            return;
        }

        $fileRel = $image['file_path'] ?? '';
        if ($fileRel === '' || !is_file($root . $fileRel)) {
            return;
        }

        $productId = intval($image['product_id'] ?? 0);
        $base = basename($fileRel);
        // همیشه jpg thumb
        if (!preg_match('/\.jpe?g$/i', $base)) {
            $base = preg_replace('/\.[^.]+$/', '', $base) . '.jpg';
        }
        $thumbRel = 'uploads/products/' . $productId . '/thumbs/' . $base;
        $thumbAbs = $root . $thumbRel;

        if ($this->createThumbnailFromFile($root . $fileRel, $thumbAbs, 300, 72)) {
            $imageId = intval($image['id']);
            $stmt = $this->conn->prepare("UPDATE product_images SET thumb_path = ? WHERE id = ?");
            $stmt->bind_param("si", $thumbRel, $imageId);
            $stmt->execute();
            $image['thumb_path'] = $thumbRel;
        }
    }

    /**
     * حذف تصویر محصول
     */
    public function deleteImage($data) {
        check_permission('manage_products');
        
        $imageId = intval($data['image_id'] ?? 0);
        if (!$imageId) {
            send_json(['error' => 'شناسه تصویر الزامی است.'], 400);
            return;
        }
        
        $image = $this->requireOwnedImage($imageId);
        
        if (!$image) {
            send_json(['error' => 'تصویر یافت نشد.'], 404);
            return;
        }
        
        $root = __DIR__ . '/../../';
        // حذف فایل فیزیکی
        $filepath = $root . $image['file_path'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        if (!empty($image['thumb_path'])) {
            $thumbpath = $root . $image['thumb_path'];
            if (file_exists($thumbpath)) {
                unlink($thumbpath);
            }
        }
        
        // حذف از دیتابیس
        $stmt_del = $this->conn->prepare("DELETE FROM product_images WHERE id = ?");
        $stmt_del->bind_param("i", $imageId);
        
        if ($stmt_del->execute()) {
            // اگر تصویر اصلی بود، تصویر بعدی را اصلی کن
            if ($image['is_primary']) {
                $stmt_next = $this->conn->prepare("UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order ASC LIMIT 1");
                $stmt_next->bind_param("i", $image['product_id']);
                $stmt_next->execute();
            }
            
            log_activity($this->conn, 'DELETE_PRODUCT_IMAGE', "تصویر شماره {$imageId} حذف شد.");
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'خطا در حذف تصویر.'], 500);
        }
    }

    /**
     * تنظیم تصویر اصلی
     */
    public function setPrimaryImage($data) {
        check_permission('manage_products');
        
        $imageId = intval($data['image_id'] ?? 0);
        $productId = intval($data['product_id'] ?? 0);
        
        if (!$imageId || !$productId) {
            send_json(['error' => 'اطلاعات ناقص است.'], 400);
            return;
        }
                $this->requireOwnedProduct($productId);

        $this->conn->begin_transaction();
        try {
            // حذف وضعیت اصلی از همه تصاویر
            $stmt1 = $this->conn->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?");
            $stmt1->bind_param("i", $productId);
            $stmt1->execute();
            
            // تنظیم تصویر جدید به عنوان اصلی
            $stmt2 = $this->conn->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?");
            $stmt2->bind_param("ii", $imageId, $productId);
            $stmt2->execute();
            
            $this->conn->commit();
            send_json(['success' => true]);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * دریافت تصاویر محصول
     */
    public function getImages($data) {
        check_permission('manage_products');
        
        $productId = intval($data['product_id'] ?? 0);
        if (!$productId) {
            send_json(['error' => 'شناسه محصول الزامی است.'], 400);
            return;
        }
        
        $this->requireOwnedProduct($productId);
        $stmt = $this->conn->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $images = db_stmt_to_assoc_array($stmt);

        foreach ($images as &$image) {
            $this->ensureImageThumbnail($image);
            // برای UI مدیریت: نمایش بند انگشتی
            $image['display_path'] = !empty($image['thumb_path']) ? $image['thumb_path'] : $image['file_path'];
        }
        unset($image);
        
        send_json(['images' => $images]);
    }

    /**
     * ساخت بند انگشتی برای تصاویر قدیمی که thumb ندارند (صفحه فعلی لیست محصولات)
     */
    public function ensureProductListThumbs($data) {
        check_permission('manage_products');
        $productIds = $data['product_ids'] ?? [];
        if (!is_array($productIds) || empty($productIds)) {
            send_json(['success' => true, 'updated' => 0]);
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids)) {
            send_json(['success' => true, 'updated' => 0]);
            return;
        }
        $placeholders = implode(',', $ids);
        $stmt = $this->conn->prepare("
            SELECT * FROM product_images
            WHERE product_id IN ($placeholders)
              AND is_primary = 1
              AND (thumb_path IS NULL OR thumb_path = '')
        ");
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        $updated = 0;
        foreach ($rows as $row) {
            $this->ensureImageThumbnail($row);
            if (!empty($row['thumb_path'])) $updated++;
        }
        send_json(['success' => true, 'updated' => $updated]);
    }

    /**
     * دریافت هشدارهای موجودی
     */
    public function getStockAlerts($data) {
        check_permission('manage_products');
        
        $entity_id = $_SESSION['current_entity_id'];
        
        // محصولات با موجودی کمتر از حداقل
        $stmt = $this->conn->prepare("
            SELECT p.id, p.name, p.min_stock, p.reorder_point,
                   (SELECT COUNT(*) FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status IN ('in_stock', 'returned')) as current_stock
            FROM products p
            WHERE p.entity_id = ? AND p.min_stock IS NOT NULL AND p.is_active = 1
            HAVING current_stock <= p.min_stock OR (p.reorder_point IS NOT NULL AND current_stock <= p.reorder_point)
            ORDER BY (current_stock - COALESCE(p.min_stock, 0)) ASC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $low_stock = db_stmt_to_assoc_array($stmt);
        
        // محصولات با موجودی صفر
        $stmt_zero = $this->conn->prepare("
            SELECT p.id, p.name
            FROM products p
            WHERE p.entity_id = ? AND p.is_active = 1
            AND NOT EXISTS (
                SELECT 1 FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status IN ('in_stock', 'returned')
            )
            ORDER BY p.name ASC
        ");
        $stmt_zero->bind_param("i", $entity_id);
        $stmt_zero->execute();
        $out_of_stock = db_stmt_to_assoc_array($stmt_zero);
        
        send_json([
            'low_stock' => $low_stock,
            'out_of_stock' => $out_of_stock,
            'total_alerts' => count($low_stock) + count($out_of_stock)
        ]);
    }

    /**
     * پردازش موقت تصویر (قبل از ذخیره محصول) — حذف پس‌زمینه و بازگشت مسیر موقت
     */
    public function processImage($data) {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('manage_products', $perms) && !in_array('manage_purchase_invoices', $perms)) {
            send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
            return;
        }

        require_once __DIR__ . '/../helpers/ImageProcessHelper.php';

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            send_json(['error' => $this->uploadErrorMessage('image')], 400);
            return;
        }

        $file = $_FILES['image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file['type'], $allowed)) {
            send_json(['error' => 'فرمت تصویر مجاز نیست.'], 400);
            return;
        }

        $removeBg = !empty($_POST['remove_bg']) || !empty($data['remove_bg']);
        $model = preg_replace('/[^a-z0-9\-]/i', '', $_POST['model'] ?? $data['model'] ?? '') ?: null;
        $background = trim((string)($_POST['background'] ?? $data['background'] ?? 'transparent'));
        if ($background !== 'transparent' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $background)) {
            $background = '#ffffff';
        }

        $tmpDir = ImageProcessHelper::ensureTmpDir();
        $base = 'proc_' . uniqid() . '_' . time();

        if ($removeBg) {
            if (!RembgHelper::isHealthy()) {
                send_json(['error' => 'سرویس حذف پس‌زمینه در دسترس نیست.'], 503);
                return;
            }
            $png = RembgHelper::removeBackground($file['tmp_name'], $model ?: null);
            if ($png === false) {
                send_json(['error' => 'حذف پس‌زمینه ناموفق بود.'], 500);
                return;
            }

            if ($background === 'transparent') {
                $rel = 'uploads/tmp/' . $base . '.png';
                $abs = __DIR__ . '/../../' . $rel;
                if (file_put_contents($abs, $png) === false) {
                    send_json(['error' => 'خطا در ذخیره فایل موقت.'], 500);
                    return;
                }
                send_json([
                    'success' => true,
                    'file_path' => $rel,
                    'display_path' => $rel,
                    'mime' => 'image/png',
                ]);
                return;
            }

            $rel = 'uploads/tmp/' . $base . '.jpg';
            $abs = __DIR__ . '/../../' . $rel;
            if (!ImageProcessHelper::flattenPngToJpeg($png, $abs, $background, 90)) {
                send_json(['error' => 'خطا در تبدیل تصویر.'], 500);
                return;
            }
            send_json([
                'success' => true,
                'file_path' => $rel,
                'display_path' => $rel,
                'mime' => 'image/jpeg',
            ]);
            return;
        }

        // بدون rembg: فقط ذخیره موقت فشرده برای پیش‌نمایش/ادامه ویرایش
        $rel = 'uploads/tmp/' . $base . '.jpg';
        $abs = __DIR__ . '/../../' . $rel;
        $ok = $this->compressImage($file['tmp_name'], $abs, $file['type'], $file['size']);
        if (!$ok) {
            send_json(['error' => 'خطا در پردازش تصویر.'], 500);
            return;
        }
        send_json([
            'success' => true,
            'file_path' => $rel,
            'display_path' => $rel,
            'mime' => 'image/jpeg',
        ]);
    }

    /**
     * وضعیت سرویس حذف پس‌زمینه (rembg)
     */
    public function rembgStatus($data) {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('manage_products', $perms) && !in_array('manage_purchase_invoices', $perms)) {
            send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
            return;
        }
        require_once __DIR__ . '/../helpers/RembgHelper.php';
        send_json([
            'available' => RembgHelper::isHealthy(),
            'url' => RembgHelper::baseUrl(),
        ]);
    }

    /**
     * حذف پس‌زمینه تصویر محصول با rembg و جایگزینی فایل JPEG با پس‌زمینه سفید
     */
    public function removeImageBackground($data) {
        check_permission('manage_products');
        require_once __DIR__ . '/../helpers/RembgHelper.php';
        require_once __DIR__ . '/../helpers/ImageProcessHelper.php';

        $imageId = intval($data['image_id'] ?? 0);
        if (!$imageId) {
            send_json(['error' => 'شناسه تصویر الزامی است.'], 400);
            return;
        }

        if (!RembgHelper::isHealthy()) {
            send_json(['error' => 'سرویس حذف پس‌زمینه در دسترس نیست. مطمئن شوید rembg روی سرور در حال اجرا است.'], 503);
            return;
        }

        $image = $this->requireOwnedImage($imageId);
        if (!$image) {
            send_json(['error' => 'تصویر یافت نشد.'], 404);
            return;
        }

        $root = __DIR__ . '/../../';
        $absPath = $root . $image['file_path'];
        if (!is_file($absPath)) {
            send_json(['error' => 'فایل تصویر روی دیسک یافت نشد.'], 404);
            return;
        }

        $model = !empty($data['model']) ? preg_replace('/[^a-z0-9\-]/i', '', $data['model']) : null;
        $background = trim((string)($data['background'] ?? '#ffffff'));
        if ($background !== 'transparent' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $background)) {
            $background = '#ffffff';
        }

        $png = RembgHelper::removeBackground($absPath, $model ?: null);
        if ($png === false) {
            send_json(['error' => 'حذف پس‌زمینه ناموفق بود. لطفاً دوباره تلاش کنید.'], 500);
            return;
        }

        $productId = intval($image['product_id']);
        $upload_dir = $root . 'uploads/products/' . $productId;
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $thumb_dir = $upload_dir . '/thumbs';
        if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

        if ($background === 'transparent') {
            $filename = uniqid('bg_') . '_' . time() . '.png';
            $filepath = $upload_dir . '/' . $filename;
            $relative_path = 'uploads/products/' . $productId . '/' . $filename;
            if (file_put_contents($filepath, $png) === false) {
                send_json(['error' => 'خطا در ذخیره تصویر جدید.'], 500);
                return;
            }
            // thumb as jpeg for list
            $thumb_filename = preg_replace('/\.png$/i', '.jpg', $filename);
            $relative_thumb = 'uploads/products/' . $productId . '/thumbs/' . $thumb_filename;
            $thumb_filepath = $thumb_dir . '/' . $thumb_filename;
            ImageProcessHelper::flattenPngToJpeg($png, $thumb_filepath, '#ffffff', 72);
        } else {
            $filename = uniqid('bg_') . '_' . time() . '.jpg';
            $filepath = $upload_dir . '/' . $filename;
            $relative_path = 'uploads/products/' . $productId . '/' . $filename;
            $tmp = tempnam(sys_get_temp_dir(), 'rembg_');
            if ($tmp === false || !ImageProcessHelper::flattenPngToJpeg($png, $tmp, $background, 88)) {
                if ($tmp) @unlink($tmp);
                send_json(['error' => 'خطا در تبدیل تصویر پردازش‌شده.'], 500);
                return;
            }
            if (!@rename($tmp, $filepath) && !(@copy($tmp, $filepath) && @unlink($tmp))) {
                @unlink($tmp);
                send_json(['error' => 'خطا در ذخیره تصویر جدید.'], 500);
                return;
            }
            $relative_thumb = 'uploads/products/' . $productId . '/thumbs/' . $filename;
            $thumb_filepath = $thumb_dir . '/' . $filename;
            $this->createThumbnailFromFile($filepath, $thumb_filepath, 300, 72);
        }

        if (empty($relative_thumb) || !is_file($root . $relative_thumb)) {
            $relative_thumb = null;
        }

        $oldFile = $root . $image['file_path'];
        $oldThumb = !empty($image['thumb_path']) ? $root . $image['thumb_path'] : null;

        $stmt_up = $this->conn->prepare("UPDATE product_images SET file_name = ?, file_path = ?, thumb_path = ? WHERE id = ?");
        $stmt_up->bind_param("sssi", $filename, $relative_path, $relative_thumb, $imageId);
        if (!$stmt_up->execute()) {
            @unlink($filepath);
            if ($relative_thumb) @unlink($root . $relative_thumb);
            send_json(['error' => 'خطا در به‌روزرسانی دیتابیس.'], 500);
            return;
        }

        if (is_file($oldFile) && realpath($oldFile) !== realpath($filepath)) {
            @unlink($oldFile);
        }
        if ($oldThumb && is_file($oldThumb) && (!$relative_thumb || realpath($oldThumb) !== realpath($root . $relative_thumb))) {
            @unlink($oldThumb);
        }

        log_activity($this->conn, 'REMOVE_PRODUCT_IMAGE_BG', "پس‌زمینه تصویر #{$imageId} محصول #{$productId} حذف شد.");

        send_json([
            'success' => true,
            'id' => $imageId,
            'file_path' => $relative_path,
            'thumb_path' => $relative_thumb,
            'display_path' => $relative_thumb ?: $relative_path,
        ]);
    }

    /**
     * جایگزینی فایل یک تصویر موجود (ویرایش)
     */
    public function replaceImage($data) {
        check_permission('manage_products');

        $imageId = intval($_POST['image_id'] ?? $data['image_id'] ?? 0);
        if (!$imageId) {
            send_json(['error' => 'شناسه تصویر الزامی است.'], 400);
            return;
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            send_json(['error' => 'خطا در آپلود فایل.'], 400);
            return;
        }

        $image = $this->requireOwnedImage($imageId);
        if (!$image) {
            send_json(['error' => 'تصویر یافت نشد.'], 404);
            return;
        }

        $file = $_FILES['image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file['type'], $allowed_types)) {
            send_json(['error' => 'فرمت تصویر مجاز نیست. فقط JPEG, PNG, WebP و GIF مجاز است.'], 400);
            return;
        }

        $productId = intval($image['product_id']);
        $root = __DIR__ . '/../../';
        $upload_dir = $root . 'uploads/products/' . $productId;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $thumb_dir = $upload_dir . '/thumbs';
        if (!is_dir($thumb_dir)) {
            mkdir($thumb_dir, 0755, true);
        }

        $filename = uniqid('rep_') . '_' . time() . '.jpg';
        $filepath = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/products/' . $productId . '/' . $filename;
        $relative_thumb = 'uploads/products/' . $productId . '/thumbs/' . $filename;
        $thumb_filepath = $thumb_dir . '/' . $filename;

        $compressed = $this->compressImage($file['tmp_name'], $filepath, $file['type'], $file['size']);
        if (!$compressed) {
            send_json(['error' => 'خطا در پردازش تصویر.'], 500);
            return;
        }

        $thumbOk = $this->createThumbnailFromFile($filepath, $thumb_filepath, 300, 72);
        if (!$thumbOk) {
            $relative_thumb = null;
        }

        $oldFile = $root . $image['file_path'];
        $oldThumb = !empty($image['thumb_path']) ? $root . $image['thumb_path'] : null;

        $stmt_up = $this->conn->prepare("UPDATE product_images SET file_name = ?, file_path = ?, thumb_path = ? WHERE id = ?");
        $stmt_up->bind_param("sssi", $filename, $relative_path, $relative_thumb, $imageId);
        if (!$stmt_up->execute()) {
            @unlink($filepath);
            if ($relative_thumb) @unlink($thumb_filepath);
            send_json(['error' => 'خطا در به‌روزرسانی دیتابیس.'], 500);
            return;
        }

        if (is_file($oldFile) && realpath($oldFile) !== realpath($filepath)) {
            @unlink($oldFile);
        }
        if ($oldThumb && is_file($oldThumb) && (!$relative_thumb || realpath($oldThumb) !== realpath($thumb_filepath))) {
            @unlink($oldThumb);
        }

        log_activity($this->conn, 'REPLACE_PRODUCT_IMAGE', "تصویر #{$imageId} محصول #{$productId} جایگزین شد.");

        send_json([
            'success' => true,
            'id' => $imageId,
            'file_path' => $relative_path,
            'thumb_path' => $relative_thumb,
            'display_path' => $relative_thumb ?: $relative_path,
        ]);
    }
}