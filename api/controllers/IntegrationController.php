<?php
// /api/controllers/IntegrationController.php

require_once __DIR__ . '/../core/IntegrationAuth.php';
require_once __DIR__ . '/../core/IntegrationService.php';
require_once __DIR__ . '/../models/Invoice.php';
require_once __DIR__ . '/../models/Payment.php';

class IntegrationController {
    private $conn;
    private $svc;

    public function __construct($db) {
        $this->conn = $db;
        $this->svc = new IntegrationService($db);
    }

    private function entityId($data = []) {
        // A token is bound to one entity: requests authenticated with it always
        // act on that entity; a different entity_id in the payload is refused.
        if (is_integration_auth()) {
            $tokenEntity = (int)($_SESSION['current_entity_id'] ?? 0);
            if (!empty($data['entity_id']) && (int)$data['entity_id'] !== $tokenEntity) {
                send_json(['error' => 'این توکن به مجموعه دیگری تعلق دارد.'], 403);
            }
            return $tokenEntity;
        }
        if (!empty($data['entity_id'])) {
            $requested = intval($data['entity_id']);
            $allowed = array_map('intval', $_SESSION['allowed_entities'] ?? []);
            if (!in_array($requested, $allowed, true)) {
                send_json(['error' => 'شما به این مجموعه تجاری دسترسی ندارید.'], 403);
            }
            return $requested;
        }
        $fromSettings = $this->svc->getIntegrationSetting('default_entity_id', '');
        if ($fromSettings !== '' && $fromSettings !== null) {
            return intval($fromSettings);
        }
        if (defined('DEFAULT_ENTITY_ID')) {
            return intval(DEFAULT_ENTITY_ID);
        }
        // Last resort: the seeded primary entity (id=1 — see setup.php).
        return intval($_SESSION['current_entity_id'] ?? 1);
    }

    public function ping($data) {
        send_json([
            'success' => true,
            'service' => 'hesabdari',
            'entity_id' => $this->entityId($data),
            'time' => date('c'),
            'version' => defined('INTEGRATION_API_VERSION') ? INTEGRATION_API_VERSION : 'integration-1.0',
        ]);
    }

    public function createApiToken($data) {
        if (is_integration_auth()) {
            send_json(['error' => 'ساخت توکن فقط از پنل کاربری مجاز است.'], 403);
            return;
        }
        $name = trim($data['name'] ?? 'medusa');
        $entityId = $this->entityId($data);
        $auth = new IntegrationAuth($this->conn);
        $result = $auth->createToken($name, $entityId, $data['scopes'] ?? ['*']);
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            return;
        }
        send_json($result);
    }

    public function listApiTokens($data) {
        if (is_integration_auth()) {
            send_json(['error' => 'دسترسی غیرمجاز'], 403);
            return;
        }
        $auth = new IntegrationAuth($this->conn);
        send_json(['success' => true, 'tokens' => $auth->listTokens()]);
    }

    public function revokeApiToken($data) {
        if (is_integration_auth()) {
            send_json(['error' => 'دسترسی غیرمجاز'], 403);
            return;
        }
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            send_json(['error' => 'شناسه نامعتبر'], 400);
            return;
        }
        $auth = new IntegrationAuth($this->conn);
        $auth->revokeToken($id);
        send_json(['success' => true]);
    }

    public function saveSettings($data) {
        if (array_key_exists('medusa_webhook_url', $data)) {
            $this->svc->setIntegrationSetting('medusa_webhook_url', trim((string)$data['medusa_webhook_url']));
        }
        if (!empty($data['webhook_secret'])) {
            $this->svc->setIntegrationSetting('webhook_secret', trim((string)$data['webhook_secret']));
        }
        if (array_key_exists('default_online_account_id', $data)) {
            $raw = $data['default_online_account_id'];
            $this->svc->setIntegrationSetting(
                'default_online_account_id',
                $raw === '' || $raw === null ? '' : strval(intval($raw))
            );
        }
        if (array_key_exists('default_entity_id', $data) && $data['default_entity_id'] !== '') {
            $this->svc->setIntegrationSetting('default_entity_id', strval(intval($data['default_entity_id'])));
        }
        if (array_key_exists('sso_allowed_user_ids', $data)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,،]+/u', (string)$data['sso_allowed_user_ids'])))));
            $this->svc->setIntegrationSetting('sso_allowed_user_ids', implode(',', $ids));
        }
        send_json(['success' => true]);
    }

    public function getSettings($data) {
        $entityDefault = defined('DEFAULT_ENTITY_ID') ? intval(DEFAULT_ENTITY_ID) : 1;
        send_json([
            'success' => true,
            'settings' => [
                'medusa_webhook_url' => $this->svc->getIntegrationSetting('medusa_webhook_url', ''),
                'default_online_account_id' => $this->svc->getIntegrationSetting('default_online_account_id', ''),
                'default_entity_id' => $this->svc->getIntegrationSetting('default_entity_id', strval($entityDefault)),
                'has_webhook_secret' => $this->svc->getIntegrationSetting('webhook_secret', '') !== '',
                'sso_allowed_user_ids' => $this->svc->getIntegrationSetting('sso_allowed_user_ids', ''),
            ],
        ]);
    }

    public function upsertCustomer($data) {
        $entityId = $this->entityId($data);
        $_SESSION['current_entity_id'] = $entityId;

        $phone = $this->svc->normalizePhone($data['phone'] ?? '');
        $name = trim($data['name'] ?? '');
        $remoteId = trim($data['remote_id'] ?? '');
        $address = trim($data['address'] ?? '');

        if ($name === '') {
            $name = $phone !== '' ? ('مشتری آنلاین ' . $phone) : 'مشتری آنلاین';
        }

        $customerId = null;
        if ($remoteId !== '') {
            $link = $this->svc->findLinkByRemote('customer', $remoteId);
            if ($link) {
                $customerId = intval($link['local_id']);
            }
        }

        if (!$customerId && $phone !== '') {
            $stmt = $this->conn->prepare(
                "SELECT id FROM customers WHERE entity_id = ? AND phone = ? LIMIT 1"
            );
            $stmt->bind_param('is', $entityId, $phone);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                $customerId = intval($row['id']);
            }
        }

        if ($customerId) {
            $stmt = $this->conn->prepare(
                "UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ? AND entity_id = ?"
            );
            $stmt->bind_param('sssii', $name, $phone, $address, $customerId, $entityId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO customers (entity_id, name, phone, address, nationalId, initial_balance)
                 VALUES (?, ?, ?, ?, '', 0)"
            );
            $stmt->bind_param('isss', $entityId, $name, $phone, $address);
            if (!$stmt->execute()) {
                // duplicate name fallback
                $uniqueName = $name . ' (' . ($phone ?: substr(uniqid(), -4)) . ')';
                $stmt->close();
                $stmt = $this->conn->prepare(
                    "INSERT INTO customers (entity_id, name, phone, address, nationalId, initial_balance)
                     VALUES (?, ?, ?, ?, '', 0)"
                );
                $stmt->bind_param('isss', $entityId, $uniqueName, $phone, $address);
                if (!$stmt->execute()) {
                    send_json(['error' => 'خطا در ایجاد مشتری: ' . $stmt->error], 500);
                    return;
                }
            }
            $customerId = $this->conn->insert_id;
            $stmt->close();
        }

        if ($remoteId !== '') {
            $this->svc->upsertLink('customer', $customerId, $remoteId, $entityId, [
                'phone' => $phone,
                'name' => $name,
            ]);
        }

        send_json(['success' => true, 'id' => $customerId, 'phone' => $phone]);
    }

    public function catalogExport($data) {
        $entityId = $this->entityId($data);
        $updatedSince = $data['updated_since'] ?? null;

        $sql = "SELECT p.*, c.name AS category_name, col.name AS collection_name, col.type AS collection_type
                FROM products p
                LEFT JOIN product_categories c ON c.id = p.category_id
                LEFT JOIN product_collections col ON col.id = p.collection_id
                WHERE p.entity_id = ?";
        if ($updatedSince) {
            $sql .= " AND p.updated_at >= ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('is', $entityId, $updatedSince);
        } else {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $entityId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $products = [];
        while ($row = $res->fetch_assoc()) {
            $pid = intval($row['id']);
            $mode = $row['fulfillment_mode'] ?: 'stocked';
            // Auto-suggest mto for machine collections with zero stock
            if ($mode === 'stocked' && ($row['collection_type'] ?? '') === 'machine') {
                $cntStmt = $this->conn->prepare(
                    "SELECT COUNT(*) AS c FROM inventory_items WHERE product_id = ? AND entity_id = ? AND status = 'in_stock'"
                );
                $cntStmt->bind_param('ii', $pid, $entityId);
                $cntStmt->execute();
                $cntRes = $cntStmt->get_result()->fetch_assoc();
                $cntStmt->close();
                if (intval($cntRes['c'] ?? 0) === 0 && !empty($data['auto_mto_machine'])) {
                    $mode = 'mto';
                }
            }

            $dims = [];
            $seenDims = [];
            if (!empty($row['collection_id'])) {
                $dStmt = $this->conn->prepare(
                    "SELECT dimension, price_override FROM collection_dimensions WHERE collection_id = ?"
                );
                $cid = intval($row['collection_id']);
                $dStmt->bind_param('i', $cid);
                $dStmt->execute();
                $dRes = $dStmt->get_result();
                while ($d = $dRes->fetch_assoc()) {
                    $raw = trim((string)($d['dimension'] ?? ''));
                    $norm = $this->svc->normalizeDimensions($raw) ?: $raw;
                    if ($norm === '' || isset($seenDims[$norm])) continue;
                    $seenDims[$norm] = true;
                    $dims[] = [
                        'dimension' => $norm,
                        'label' => $raw !== '' ? $raw : $norm,
                        'price_override' => $d['price_override'],
                        'source' => 'collection',
                    ];
                }
                $dStmt->close();
            }

            $stock = $this->svc->buildStockSnapshot($entityId, [$pid]);
            // ابعاد واقعی انبار (مثل 207x107) را هم اضافه کن — مخصوص دستباف/بدون کالکشن
            foreach ($stock as $s) {
                $raw = trim((string)($s['dimensions'] ?? ''));
                $norm = $this->svc->normalizeDimensions($raw) ?: $raw;
                if ($norm === '' || isset($seenDims[$norm])) {
                    // اگر قبلاً از کالکشن آمده، فقط موجودی را روی همان بگذار
                    if ($norm !== '') {
                        foreach ($dims as &$dd) {
                            if (($dd['dimension'] ?? '') === $norm) {
                                $dd['available_qty'] = intval($s['available_qty'] ?? 0);
                            }
                        }
                        unset($dd);
                    }
                    continue;
                }
                $seenDims[$norm] = true;
                $dims[] = [
                    'dimension' => $norm,
                    'label' => $raw !== '' ? $raw : $norm,
                    'price_override' => null,
                    'available_qty' => intval($s['available_qty'] ?? 0),
                    'source' => 'inventory',
                ];
            }

            $images = $this->svc->productImages($pid);

            $products[] = [
                'id' => $pid,
                'name' => $row['name'],
                'description' => $row['description'],
                'sku' => $row['sku'],
                'price' => floatval($row['price']),
                'is_active' => intval($row['is_active']) === 1,
                'fulfillment_mode' => $mode,
                'category_name' => $row['category_name'],
                'collection_name' => $row['collection_name'],
                'collection_type' => $row['collection_type'],
                'design_code' => $row['design_code'],
                'colorway' => $row['colorway'],
                'dimensions' => $dims,
                'stock' => $stock,
                'images' => $images,
                'primary_image' => $images[0]['url'] ?? null,
                'image_count' => count($images),
                'updated_at' => $row['updated_at'],
            ];
        }
        $stmt->close();

        send_json(['success' => true, 'products' => $products, 'entity_id' => $entityId]);
    }

    /**
     * دریافت تصاویر یک محصول (آدرس مطلق) برای انتقال به فروشگاه.
     */
    public function getProductImages($data) {
        $entityId = $this->entityId($data);
        $productId = intval($data['product_id'] ?? 0);
        if ($productId <= 0) {
            send_json(['error' => 'شناسه محصول الزامی است.'], 400);
            return;
        }
        // اطمینان از تعلق محصول به این کسب‌وکار
        $chk = $this->conn->prepare("SELECT id FROM products WHERE id = ? AND entity_id = ? LIMIT 1");
        $chk->bind_param('ii', $productId, $entityId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) {
            send_json(['error' => 'محصول یافت نشد.'], 404);
            return;
        }
        $images = $this->svc->productImages($productId);
        send_json([
            'success' => true,
            'product_id' => $productId,
            'images' => $images,
            'primary_image' => $images[0]['url'] ?? null,
        ]);
    }

    /**
     * دریافت تصاویر از فروشگاه (Medusa) و ذخیره روی محصول حسابداری.
     * بدنه: product_id, images: [{url, is_primary?}], replace? (پیش‌فرض false)
     */
    public function receiveProductImages($data) {
        $entityId = $this->entityId($data);
        $productId = intval($data['product_id'] ?? 0);
        $images = $data['images'] ?? [];
        $replace = !empty($data['replace']);
        if ($productId <= 0 || !is_array($images) || count($images) === 0) {
            send_json(['error' => 'product_id و images الزامی هستند.'], 400);
            return;
        }
        $chk = $this->conn->prepare("SELECT id FROM products WHERE id = ? AND entity_id = ? LIMIT 1");
        $chk->bind_param('ii', $productId, $entityId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) {
            send_json(['error' => 'محصول یافت نشد.'], 404);
            return;
        }

        $upload_dir = __DIR__ . '/../../uploads/products/' . $productId;
        $thumb_dir = $upload_dir . '/thumbs';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
        if (!is_dir($thumb_dir)) @mkdir($thumb_dir, 0755, true);

        if ($replace) {
            $del = $this->conn->prepare("SELECT file_path, thumb_path FROM product_images WHERE product_id = ?");
            $del->bind_param('i', $productId);
            $del->execute();
            $oldRows = db_stmt_to_assoc_array($del);
            $del->close();
            $root = __DIR__ . '/../../';
            foreach ($oldRows as $o) {
                if (!empty($o['file_path'])) @unlink($root . $o['file_path']);
                if (!empty($o['thumb_path'])) @unlink($root . $o['thumb_path']);
            }
            $dstmt = $this->conn->prepare("DELETE FROM product_images WHERE product_id = ?");
            $dstmt->bind_param('i', $productId);
            $dstmt->execute();
            $dstmt->close();
        }

        // آیا از قبل تصویر اصلی وجود دارد؟
        $cnt = $this->conn->prepare("SELECT COUNT(*) AS c FROM product_images WHERE product_id = ?");
        $cnt->bind_param('i', $productId);
        $cnt->execute();
        $hasPrimary = intval(($cnt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
        $cnt->close();

        $saved = [];
        $errors = [];
        foreach ($images as $idx => $img) {
            $url = is_array($img) ? ($img['url'] ?? '') : (string)$img;
            $wantPrimary = is_array($img) ? !empty($img['is_primary']) : false;
            if (!$url) { continue; }
            $stored = $this->storeImageFromUrl($url, $productId, $upload_dir, $thumb_dir);
            if (!$stored) { $errors[] = $url; continue; }

            $isPrimary = 0;
            if (($wantPrimary || (!$hasPrimary && count($saved) === 0))) {
                $isPrimary = 1;
                $hasPrimary = true;
                // پاک‌کردن پرچم اصلی قبلی
                $up = $this->conn->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?");
                $up->bind_param('i', $productId);
                $up->execute();
                $up->close();
            }

            $ins = $this->conn->prepare("INSERT INTO product_images (product_id, file_name, file_path, thumb_path, is_primary) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param('isssi', $productId, $stored['file_name'], $stored['file_path'], $stored['thumb_path'], $isPrimary);
            if ($ins->execute()) {
                $saved[] = [
                    'id' => $this->conn->insert_id,
                    'file_path' => $stored['file_path'],
                    'is_primary' => $isPrimary,
                ];
            } else {
                $errors[] = $url;
            }
            $ins->close();
        }

        if (function_exists('log_activity')) {
            log_activity($this->conn, 'INTEGRATION_RECEIVE_IMAGES', "دریافت " . count($saved) . " تصویر از فروشگاه برای محصول {$productId}.");
        }

        send_json([
            'success' => true,
            'product_id' => $productId,
            'saved' => count($saved),
            'failed' => count($errors),
            'images' => $this->svc->productImages($productId),
        ]);
    }

    /**
     * دانلود یک تصویر از URL، فشرده‌سازی به JPEG و ساخت بند انگشتی.
     * خروجی: ['file_name','file_path','thumb_path'] یا false.
     */
    private function storeImageFromUrl($url, $productId, $upload_dir, $thumb_dir) {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        $bytes = @file_get_contents($url);
        if ($bytes === false || strlen($bytes) === 0) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $bytes = curl_exec($ch);
            curl_close($ch);
        }
        if (!$bytes) return false;

        $src = @imagecreatefromstring($bytes);
        if (!$src) return false;

        $filename = uniqid() . '_' . time() . '.jpg';
        $filepath = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/products/' . $productId . '/' . $filename;
        $thumb_filepath = $thumb_dir . '/' . $filename;
        $relative_thumb = 'uploads/products/' . $productId . '/thumbs/' . $filename;

        // ذخیره تصویر اصلی (حداکثر بعد 1600px)
        $w = imagesx($src); $h = imagesy($src);
        $maxDim = 1600;
        if ($w > $maxDim || $h > $maxDim) {
            if ($w >= $h) { $nw = $maxDim; $nh = max(1, intval($h * ($maxDim / $w))); }
            else { $nh = $maxDim; $nw = max(1, intval($w * ($maxDim / $h))); }
            $dst = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        } else {
            $dst = $src;
        }
        $ok = imagejpeg($dst, $filepath, 85);
        if ($dst !== $src) imagedestroy($dst);
        imagedestroy($src);
        if (!$ok) return false;

        // بند انگشتی
        $thumbOk = false;
        $timg = @imagecreatefromjpeg($filepath);
        if ($timg) {
            $tw = imagesx($timg); $th = imagesy($timg);
            $tmax = 300;
            if ($tw > $tmax || $th > $tmax) {
                if ($tw >= $th) { $tnw = $tmax; $tnh = max(1, intval($th * ($tmax / $tw))); }
                else { $tnh = $tmax; $tnw = max(1, intval($tw * ($tmax / $th))); }
                $tdst = imagecreatetruecolor($tnw, $tnh);
                $white = imagecolorallocate($tdst, 255, 255, 255);
                imagefill($tdst, 0, 0, $white);
                imagecopyresampled($tdst, $timg, 0, 0, 0, 0, $tnw, $tnh, $tw, $th);
                $thumbOk = imagejpeg($tdst, $thumb_filepath, 72);
                imagedestroy($tdst);
            } else {
                $thumbOk = imagejpeg($timg, $thumb_filepath, 72);
            }
            imagedestroy($timg);
        }

        return [
            'file_name' => $filename,
            'file_path' => $relative_path,
            'thumb_path' => $thumbOk ? $relative_thumb : null,
        ];
    }

    public function updateProductMode($data) {
        $entityId = $this->entityId($data);
        $productId = intval($data['product_id'] ?? 0);
        $mode = $data['fulfillment_mode'] ?? '';
        if ($productId <= 0 || !in_array($mode, ['stocked', 'mto'], true)) {
            send_json(['error' => 'پارامتر نامعتبر'], 400);
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE products SET fulfillment_mode = ? WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('sii', $mode, $productId, $entityId);
        $stmt->execute();
        $stmt->close();
        $this->svc->enqueueStockSync($entityId, [$productId]);
        send_json(['success' => true]);
    }

    public function getStockSnapshot($data) {
        $entityId = $this->entityId($data);
        $productIds = $data['product_ids'] ?? [];
        if (!is_array($productIds)) $productIds = [];
        send_json([
            'success' => true,
            'entity_id' => $entityId,
            'items' => $this->svc->buildStockSnapshot($entityId, $productIds),
        ]);
    }

    public function createSalesInvoice($data) {
        $entityId = $this->entityId($data);
        $_SESSION['current_entity_id'] = $entityId;

        $remoteOrderId = trim($data['remote_order_id'] ?? '');
        if ($remoteOrderId === '') {
            send_json(['error' => 'remote_order_id الزامی است'], 400);
            return;
        }

        $existing = $this->svc->findLinkByRemote('order', $remoteOrderId);
        if ($existing) {
            send_json([
                'success' => true,
                'id' => intval($existing['local_id']),
                'idempotent' => true,
                'invoice_id' => intval($existing['local_id']),
            ]);
            return;
        }

        $customerId = intval($data['customer_id'] ?? 0);
        if ($customerId <= 0 && !empty($data['customer'])) {
            // inline upsert
            $this->upsertCustomerSilent($data['customer'], $entityId, $customerId);
        }
        if ($customerId <= 0) {
            send_json(['error' => 'مشتری مشخص نشده است'], 400);
            return;
        }

        $itemsIn = $data['items'] ?? [];
        if (!is_array($itemsIn) || empty($itemsIn)) {
            send_json(['error' => 'اقلام فاکتور خالی است'], 400);
            return;
        }

        $invoiceItems = [];
        $mtoProductIds = [];
        $stockedProductIds = [];

        foreach ($itemsIn as $it) {
            $productId = intval($it['product_id'] ?? 0);
            $qty = intval($it['quantity'] ?? 0);
            $unitPrice = isset($it['unit_price_accounting'])
                ? floatval($it['unit_price_accounting'])
                : $this->svc->rialToAccounting($it['unit_price_rial'] ?? 0);
            $dimensions = trim($it['dimensions'] ?? '');
            $title = trim($it['title'] ?? 'قلم فروش آنلاین');
            $mode = $it['fulfillment_mode'] ?? null;

            if ($productId > 0 && $mode === null) {
                $pStmt = $this->conn->prepare("SELECT fulfillment_mode FROM products WHERE id = ? LIMIT 1");
                $pStmt->bind_param('i', $productId);
                $pStmt->execute();
                $pRow = $pStmt->get_result()->fetch_assoc();
                $pStmt->close();
                $mode = $pRow['fulfillment_mode'] ?? 'stocked';
            }
            if ($mode === null) $mode = 'mto';

            if ($mode === 'mto' || $productId <= 0) {
                $desc = $title;
                if ($productId > 0) {
                    $desc .= " [محصول #{$productId}]";
                    $mtoProductIds[] = $productId;
                }
                if ($dimensions !== '') {
                    $desc .= " — ابعاد {$dimensions}";
                }
                $desc .= ' — سفارش آنلاین، خرید به سفارش';
                $invoiceItems[] = [
                    'is_service' => 1,
                    'item_description' => $desc,
                    'quantity' => max(1, $qty),
                    'unitPrice' => $unitPrice,
                    '_meta_product_id' => $productId,
                    '_fulfillment_mode' => 'mto',
                    '_dimensions' => $this->svc->normalizeDimensions($dimensions),
                    '_title' => $title,
                ];
            } else {
                if ($dimensions === '') {
                    $dimensions = trim($it['variant_title'] ?? 'استاندارد');
                }
                $dimensions = $this->svc->normalizeDimensions($dimensions) ?: $dimensions;
                $invoiceItems[] = [
                    'productId' => $productId,
                    'dimensions' => $dimensions,
                    'quantity' => max(1, $qty),
                    'unitPrice' => $unitPrice,
                    'is_service' => 0,
                    '_fulfillment_mode' => 'stocked',
                ];
                $stockedProductIds[] = $productId;
            }
        }

        $discount = isset($data['discount_accounting'])
            ? floatval($data['discount_accounting'])
            : $this->svc->rialToAccounting($data['discount_rial'] ?? 0);
        $taxAmount = isset($data['tax_accounting'])
            ? floatval($data['tax_accounting'])
            : $this->svc->rialToAccounting($data['tax_rial'] ?? 0);

        $displayId = $data['display_id'] ?? $remoteOrderId;
        $desc = trim($data['description'] ?? '');
        if ($desc === '') {
            $desc = "سفارش آنلاین #{$displayId} ({$remoteOrderId})";
        }
        $paymentMode = $data['payment_mode'] ?? 'full';
        $desc .= " | پرداخت: {$paymentMode}";
        if (!empty($data['zarinpal_ref_id'])) {
            $desc .= " | زرین‌پال: {$data['zarinpal_ref_id']}";
        }

        $date = $data['date'] ?? date('Y-m-d');
        $payments = [];
        $paidAccounting = isset($data['paid_amount_accounting'])
            ? floatval($data['paid_amount_accounting'])
            : $this->svc->rialToAccounting($data['paid_amount_rial'] ?? 0);

        // An online payment is only booked with the gateway tracking code; a
        // "paid" amount without it is not trusted (forged/unverified payments).
        if ($paidAccounting > 0 && trim((string)($data['zarinpal_ref_id'] ?? '')) === '') {
            $desc .= ' | مبلغ پرداختی بدون کد پیگیری درگاه — دریافت ثبت نشد، بررسی شود';
            $paidAccounting = 0;
        }
        if ($paidAccounting < 0) {
            $paidAccounting = 0;
        }
        // Flag (do not drop) payments larger than the goods total: shipping is
        // not an invoice line, so a small difference is expected.
        $itemsTotal = 0;
        foreach ($invoiceItems as $ii) {
            $itemsTotal += floatval($ii['unitPrice'] ?? 0) * max(1, intval($ii['quantity'] ?? 1));
        }
        $invoiceTotal = max(0, $itemsTotal - $discount + $taxAmount);
        if ($invoiceTotal > 0 && $paidAccounting > $invoiceTotal * 1.5) {
            $desc .= ' | هشدار: مبلغ پرداختی بیش از ۱.۵ برابر جمع فاکتور است، بررسی شود';
        }

        $accountId = intval($data['account_id'] ?? 0);
        if ($accountId <= 0) {
            $accountId = intval($this->svc->getIntegrationSetting('default_online_account_id', 0));
        }
        if ($accountId <= 0) {
            $accRes = $this->conn->query(
                "SELECT id FROM accounts WHERE entity_id = {$entityId} ORDER BY id ASC LIMIT 1"
            );
            if ($accRes && ($acc = $accRes->fetch_assoc())) {
                $accountId = intval($acc['id']);
            }
        }

        if ($paidAccounting > 0 && $accountId > 0) {
            $payments[] = [
                'type' => 'cash',
                'amount' => $paidAccounting,
                'date' => $date,
                'description' => 'پرداخت آنلاین سفارش #' . $displayId,
                'account_id' => $accountId,
            ];
        }

        $invoiceModel = new Invoice($this->conn, 'sales', false);
        $payload = [
            'customerId' => $customerId,
            'date' => $date,
            'description' => $desc,
            'discount' => $discount,
            'tax_rate' => floatval($data['tax_rate'] ?? 0),
            'tax_amount' => $taxAmount,
            'items' => $invoiceItems,
            'payments' => $payments,
            'is_consignment' => 0,
        ];

        $result = $invoiceModel->saveSalesInvoice($payload);
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            return;
        }

        $invoiceId = intval($result['id']);

        // Mark source
        $src = 'online_store';
        $srcEntity = (int)($_SESSION['current_entity_id'] ?? 0);
        $srcStmt = $this->conn->prepare("UPDATE sales_invoices SET source = ? WHERE id = ? AND entity_id = ?");
        if ($srcStmt) {
            $srcStmt->bind_param('sii', $src, $invoiceId, $srcEntity);
            $srcStmt->execute();
            $srcStmt->close();
        }

        $this->svc->upsertLink('order', $invoiceId, $remoteOrderId, $entityId, [
            'display_id' => $displayId,
            'payment_mode' => $paymentMode,
            'zarinpal_ref_id' => $data['zarinpal_ref_id'] ?? null,
        ]);

        foreach ($mtoProductIds as $pid) {
            $this->svc->createNotification(
                $entityId,
                'mto_procurement',
                'خرید به سفارش — فروشگاه آنلاین',
                "فاکتور #{$invoiceId} / سفارش {$displayId}: محصول #{$pid} نیاز به خرید و تأمین دارد.",
                'sales_invoice',
                $invoiceId
            );
        }

        // Structured MTO queue rows
        $mtoJobs = [];
        foreach ($invoiceItems as $ii) {
            if (($ii['_fulfillment_mode'] ?? '') !== 'mto') continue;
            $mtoJobs[] = [
                'remote_order_id' => $remoteOrderId,
                'display_id' => $displayId,
                'invoice_id' => $invoiceId,
                'product_id' => $ii['_meta_product_id'] ?? 0,
                'title' => $ii['_title'] ?? ($ii['item_description'] ?? 'قلم MTO'),
                'dimensions' => $ii['_dimensions'] ?? '',
                'quantity' => $ii['quantity'] ?? 1,
                'status' => 'open',
            ];
        }
        if (!empty($mtoJobs)) {
            $this->svc->upsertMtoJobs($entityId, $mtoJobs);
        }

        // Reserve / confirm serials for stocked lines
        $serialResult = null;
        if (!empty($stockedProductIds)) {
            try {
                $serialResult = $this->svc->reserveSerialsForInvoice($entityId, $invoiceId);
            } catch (Throwable $e) {
                $serialResult = ['error' => $e->getMessage()];
            }
        }

        $allProducts = array_merge($mtoProductIds, $stockedProductIds);
        if (!empty($allProducts)) {
            $this->svc->enqueueStockSync($entityId, $allProducts);
        }

        send_json([
            'success' => true,
            'id' => $invoiceId,
            'invoice_id' => $invoiceId,
            'mto_items' => count($mtoProductIds),
            'stocked_items' => count($stockedProductIds),
            'serials' => $serialResult,
        ]);
    }

    private function upsertCustomerSilent($customerData, $entityId, &$customerId) {
        $phone = $this->svc->normalizePhone($customerData['phone'] ?? '');
        $name = trim($customerData['name'] ?? '');
        $remoteId = trim($customerData['remote_id'] ?? '');
        $address = trim($customerData['address'] ?? '');
        if ($name === '') {
            $name = $phone !== '' ? ('مشتری آنلاین ' . $phone) : 'مشتری آنلاین';
        }

        if ($remoteId !== '') {
            $link = $this->svc->findLinkByRemote('customer', $remoteId);
            if ($link) {
                $customerId = intval($link['local_id']);
            }
        }
        if (!$customerId && $phone !== '') {
            $stmt = $this->conn->prepare("SELECT id FROM customers WHERE entity_id = ? AND phone = ? LIMIT 1");
            $stmt->bind_param('is', $entityId, $phone);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $customerId = intval($row['id']);
        }
        if ($customerId) {
            $stmt = $this->conn->prepare(
                "UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ? AND entity_id = ?"
            );
            $stmt->bind_param('sssii', $name, $phone, $address, $customerId, $entityId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO customers (entity_id, name, phone, address, nationalId, initial_balance) VALUES (?, ?, ?, ?, '', 0)"
            );
            $stmt->bind_param('isss', $entityId, $name, $phone, $address);
            if (!$stmt->execute()) {
                $uniqueName = $name . ' (' . ($phone ?: substr(uniqid(), -4)) . ')';
                $stmt->close();
                $stmt = $this->conn->prepare(
                    "INSERT INTO customers (entity_id, name, phone, address, nationalId, initial_balance) VALUES (?, ?, ?, ?, '', 0)"
                );
                $stmt->bind_param('isss', $entityId, $uniqueName, $phone, $address);
                $stmt->execute();
            }
            $customerId = $this->conn->insert_id;
            $stmt->close();
        }
        if ($remoteId !== '') {
            $this->svc->upsertLink('customer', $customerId, $remoteId, $entityId);
        }
    }

    public function registerPayment($data) {
        $entityId = $this->entityId($data);
        $_SESSION['current_entity_id'] = $entityId;

        $remotePaymentId = trim($data['remote_payment_id'] ?? '');
        if ($remotePaymentId !== '') {
            $existing = $this->svc->findLinkByRemote('payment', $remotePaymentId);
            if ($existing) {
                send_json(['success' => true, 'id' => intval($existing['local_id']), 'idempotent' => true]);
                return;
            }
        }

        $invoiceId = intval($data['invoice_id'] ?? 0);
        if ($invoiceId <= 0 && !empty($data['remote_order_id'])) {
            $link = $this->svc->findLinkByRemote('order', $data['remote_order_id']);
            if ($link) $invoiceId = intval($link['local_id']);
        }
        if ($invoiceId <= 0) {
            send_json(['error' => 'فاکتور یافت نشد'], 404);
            return;
        }

        $invStmt = $this->conn->prepare("SELECT customerId FROM sales_invoices WHERE id = ? AND entity_id = ?");
        $invStmt->bind_param('ii', $invoiceId, $entityId);
        $invStmt->execute();
        $inv = $invStmt->get_result()->fetch_assoc();
        $invStmt->close();
        if (!$inv) {
            send_json(['error' => 'فاکتور متعلق به این مجموعه نیست'], 404);
            return;
        }

        $amount = isset($data['amount_accounting'])
            ? floatval($data['amount_accounting'])
            : $this->svc->rialToAccounting($data['amount_rial'] ?? 0);
        if ($amount <= 0) {
            send_json(['error' => 'مبلغ نامعتبر'], 400);
            return;
        }

        $accountId = intval($data['account_id'] ?? 0);
        if ($accountId <= 0) {
            $accountId = intval($this->svc->getIntegrationSetting('default_online_account_id', 0));
        }
        if ($accountId <= 0) {
            $accRes = $this->conn->query("SELECT id FROM accounts WHERE entity_id = {$entityId} ORDER BY id ASC LIMIT 1");
            $acc = $accRes ? $accRes->fetch_assoc() : null;
            $accountId = intval($acc['id'] ?? 0);
        }
        if ($accountId <= 0) {
            send_json(['error' => 'حساب بانکی پیش‌فرض تنظیم نشده است'], 400);
            return;
        }

        $paymentModel = new Payment($this->conn);
        $result = $paymentModel->save([
            'invoiceId' => $invoiceId,
            'invoiceType' => 'sales',
            'person_id' => intval($inv['customerId']),
            'person_type' => 'customer',
            'transaction_type' => 'receipt',
            'type' => 'cash',
            'amount' => $amount,
            'date' => $data['date'] ?? date('Y-m-d'),
            'description' => $data['description'] ?? 'پرداخت آنلاین',
            'account_id' => $accountId,
        ], false);

        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            return;
        }

        if ($remotePaymentId !== '') {
            $this->svc->upsertLink('payment', $result['id'], $remotePaymentId, $entityId);
        }

        $custName = '-';
        $cStmt = $this->conn->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
        $cid = intval($inv['customerId']);
        $cStmt->bind_param('i', $cid);
        $cStmt->execute();
        $cRow = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();
        if (!empty($cRow['name'])) {
            $custName = $cRow['name'];
        }
        $amountLabel = number_format($amount, 0, '', ',');
        $refNote = $remotePaymentId !== '' ? " (مرجع فروشگاه: {$remotePaymentId})" : '';
        $this->svc->createNotification(
            $entityId,
            'online_payment',
            'پرداخت آنلاین مشتری',
            "مشتری «{$custName}» مبلغ {$amountLabel} تومان بابت فاکتور #{$invoiceId} به‌صورت آنلاین پرداخت کرد.{$refNote}",
            'sales_invoice',
            $invoiceId
        );

        send_json(['success' => true, 'id' => $result['id'], 'invoice_id' => $invoiceId]);
    }

    public function updateDeliveryStatus($data) {
        $entityId = $this->entityId($data);
        $invoiceId = intval($data['invoice_id'] ?? 0);
        if ($invoiceId <= 0 && !empty($data['remote_order_id'])) {
            $link = $this->svc->findLinkByRemote('order', $data['remote_order_id']);
            if ($link) $invoiceId = intval($link['local_id']);
        }
        $status = $data['delivery_status'] ?? '';
        if (!in_array($status, ['preparing', 'ready', 'delivered'], true) || $invoiceId <= 0) {
            send_json(['error' => 'پارامتر نامعتبر'], 400);
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE sales_invoices SET delivery_status = ? WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('sii', $status, $invoiceId, $entityId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 0) {
            send_json(['error' => 'به‌روزرسانی ناموفق'], 500);
            return;
        }
        send_json(['success' => true, 'invoice_id' => $invoiceId, 'delivery_status' => $status]);
    }

    public function getOrderLink($data) {
        $remoteOrderId = trim($data['remote_order_id'] ?? '');
        if ($remoteOrderId === '') {
            send_json(['error' => 'remote_order_id الزامی است'], 400);
            return;
        }
        $link = $this->svc->findLinkByRemote('order', $remoteOrderId);
        if (!$link) {
            send_json(['success' => true, 'linked' => false]);
            return;
        }
        $invoiceId = intval($link['local_id']);
        $stmt = $this->conn->prepare(
            "SELECT id, invoice_number, totalAmount, discount, tax_amount, paidAmount, delivery_status, source, date, description
             FROM sales_invoices WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $inv = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        send_json([
            'success' => true,
            'linked' => true,
            'invoice' => $inv,
            'meta' => json_decode($link['meta_json'] ?? '{}', true),
        ]);
    }

    public function onlineOrdersSummary($data) {
        $entityId = $this->entityId($data);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(totalAmount - discount + COALESCE(tax_amount,0)),0) AS total_sales,
                    COALESCE(SUM(paidAmount),0) AS total_paid,
                    COALESCE(SUM(totalAmount - discount + COALESCE(tax_amount,0) - paidAmount),0) AS total_due
             FROM sales_invoices WHERE entity_id = ? AND source = 'online_store'"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $outboxPending = 0;
        $ores = $this->conn->query("SELECT COUNT(*) AS c FROM integration_outbox WHERE status IN ('pending','failed')");
        if ($ores) {
            $outboxPending = intval($ores->fetch_assoc()['c'] ?? 0);
        }

        send_json([
            'success' => true,
            'summary' => $summary,
            'outbox_pending' => $outboxPending,
        ]);
    }

    public function listOutbox($data) {
        $limit = min(100, max(1, intval($data['limit'] ?? 50)));
        $res = $this->conn->query(
            "SELECT id, event_type, idempotency_key, status, attempts, last_error, created_at, sent_at
             FROM integration_outbox ORDER BY id DESC LIMIT {$limit}"
        );
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }
        send_json(['success' => true, 'items' => $rows]);
    }

    public function processOutbox($data) {
        $result = $this->svc->processOutbox(intval($data['limit'] ?? 20));
        send_json(['success' => true] + $result);
    }

    public function createSsoToken($data) {
        // One-time deep-link login from the shop admin. Only users explicitly
        // listed in the integration settings (and with access to the token's
        // entity) can be logged in this way; previously any user_id — including
        // full administrators — could be impersonated with the shop's API token.
        $allowed = array_values(array_filter(array_map('intval', explode(',', (string)$this->svc->getIntegrationSetting('sso_allowed_user_ids', '')))));
        if (empty($allowed)) {
            send_json(['error' => 'ورود یک‌مرحله‌ای (SSO) غیرفعال است. کاربران مجاز را در تنظیمات اتصال فروشگاه مشخص کنید.'], 403);
            return;
        }
        $userId = intval($data['user_id'] ?? 0);
        if ($userId <= 0 && count($allowed) === 1) {
            $userId = $allowed[0];
        }
        if ($userId <= 0 || !in_array($userId, $allowed, true)) {
            send_json(['error' => 'این کاربر برای ورود از فروشگاه مجاز نیست.'], 403);
            return;
        }
        $entityId = $this->entityId($data);
        $acc = $this->conn->prepare("SELECT 1 FROM user_entity_access WHERE user_id = ? AND entity_id = ? LIMIT 1");
        $acc->bind_param('ii', $userId, $entityId);
        $acc->execute();
        if (empty(db_stmt_to_assoc_array($acc))) {
            send_json(['error' => 'این کاربر به مجموعه این توکن دسترسی ندارد.'], 403);
            return;
        }

        $plain = bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        $ssoTtl = defined('SSO_TOKEN_TTL_SECONDS') ? SSO_TOKEN_TTL_SECONDS : 120;
        $expires = date('Y-m-d H:i:s', time() + $ssoTtl);
        $stmt = $this->conn->prepare(
            "INSERT INTO integration_sso_tokens (token_hash, user_id, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('sis', $hash, $userId, $expires);
        $stmt->execute();
        $stmt->close();

        send_json([
            'success' => true,
            'token' => $plain,
            'expires_at' => $expires,
            'login_path' => 'sso.php?token=' . urlencode($plain),
        ]);
    }

    public function upsertLink($data) {
        $entityType = trim($data['entity_type'] ?? '');
        $localId = trim($data['local_id'] ?? '');
        $remoteId = trim($data['remote_id'] ?? '');
        if ($entityType === '' || $localId === '' || $remoteId === '') {
            send_json(['error' => 'پارامتر ناقص'], 400);
            return;
        }
        $id = $this->svc->upsertLink(
            $entityType,
            $localId,
            $remoteId,
            $this->entityId($data),
            $data['meta'] ?? null
        );
        send_json(['success' => true, 'id' => $id]);
    }

    /**
     * Cancel / credit-note an online order invoice.
     * Stocked lines → sales return; MTO/service lines → mark cancelled + notification.
     */
    public function creditNote($data) {
        $entityId = $this->entityId($data);
        $_SESSION['current_entity_id'] = $entityId;

        $remoteOrderId = trim($data['remote_order_id'] ?? '');
        if ($remoteOrderId === '') {
            send_json(['error' => 'remote_order_id الزامی است'], 400);
            return;
        }

        $existingCredit = $this->svc->findLinkByRemote('credit_note', $remoteOrderId);
        if ($existingCredit) {
            send_json([
                'success' => true,
                'idempotent' => true,
                'return_id' => intval($existingCredit['local_id']),
            ]);
            return;
        }

        $orderLink = $this->svc->findLinkByRemote('order', $remoteOrderId);
        if (!$orderLink) {
            send_json(['error' => 'فاکتور سفارش یافت نشد'], 404);
            return;
        }
        $invoiceId = intval($orderLink['local_id']);

        $invStmt = $this->conn->prepare(
            "SELECT id, customerId, description FROM sales_invoices WHERE id = ? AND entity_id = ? LIMIT 1"
        );
        $invStmt->bind_param('ii', $invoiceId, $entityId);
        $invStmt->execute();
        $inv = $invStmt->get_result()->fetch_assoc();
        $invStmt->close();
        if (!$inv) {
            send_json(['error' => 'فاکتور متعلق به این مجموعه نیست'], 404);
            return;
        }

        $itemsStmt = $this->conn->prepare(
            "SELECT id, productId, dimensions, quantity, unitPrice, is_service, item_description
             FROM sales_invoice_items WHERE invoiceId = ?"
        );
        $itemsStmt->bind_param('i', $invoiceId);
        $itemsStmt->execute();
        $itemRes = $itemsStmt->get_result();
        $stockedItems = [];
        $mtoCount = 0;
        $productIds = [];
        while ($row = $itemRes->fetch_assoc()) {
            if (intval($row['is_service']) === 1 || empty($row['productId'])) {
                $mtoCount++;
                continue;
            }
            $stockedItems[] = [
                'productId' => intval($row['productId']),
                'dimensions' => $row['dimensions'],
                'quantity' => intval($row['quantity']),
                'unitPrice' => floatval($row['unitPrice']),
                'original_invoice_item_id' => intval($row['id']),
            ];
            $productIds[] = intval($row['productId']);
        }
        $itemsStmt->close();

        $reason = trim($data['reason'] ?? 'لغو سفارش آنلاین');
        $returnId = 0;

        if (!empty($stockedItems)) {
            require_once __DIR__ . '/../models/ReturnInvoice.php';
            $returnModel = new ReturnInvoice($this->conn);
            $result = $returnModel->saveSalesReturn([
                'person_id' => intval($inv['customerId']),
                'date' => $data['date'] ?? date('Y-m-d'),
                'description' => "مرجوعی آنلاین — {$reason} — سفارش {$remoteOrderId}",
                'discount' => 0,
                'original_invoice_id' => $invoiceId,
                'items' => $stockedItems,
                'payments' => [],
            ]);
            if (!empty($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            $returnId = intval($result['id']);
        }

        $cancelTag = '[CANCELLED_ONLINE]';
        $newDesc = $inv['description'];
        if (strpos($newDesc, $cancelTag) === false) {
            $newDesc = $cancelTag . ' ' . $reason . ' | ' . $newDesc;
            $updEntity = (int)($_SESSION['current_entity_id'] ?? 0);
            $upd = $this->conn->prepare("UPDATE sales_invoices SET description = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('sii', $newDesc, $invoiceId, $updEntity);
            $upd->execute();
            $upd->close();
        }

        if ($mtoCount > 0) {
            $this->svc->createNotification(
                $entityId,
                'online_cancel',
                'لغو سفارش آنلاین',
                "سفارش {$remoteOrderId} / فاکتور #{$invoiceId} لغو شد. اقلام MTO: {$mtoCount}. {$reason}",
                'sales_invoice',
                $invoiceId
            );
            $cancelMto = $this->conn->prepare(
                "UPDATE mto_procurement SET status = 'cancelled'
                 WHERE entity_id = ? AND remote_order_id = ? AND status NOT IN ('fulfilled','cancelled')"
            );
            if ($cancelMto) {
                $cancelMto->bind_param('is', $entityId, $remoteOrderId);
                $cancelMto->execute();
                $cancelMto->close();
            }
        }

        $linkLocal = $returnId > 0 ? $returnId : $invoiceId;
        $this->svc->upsertLink('credit_note', $linkLocal, $remoteOrderId, $entityId, [
            'invoice_id' => $invoiceId,
            'return_id' => $returnId ?: null,
            'mto_items' => $mtoCount,
            'reason' => $reason,
        ]);

        if (!empty($productIds)) {
            $this->svc->enqueueStockSync($entityId, $productIds);
        }

        send_json([
            'success' => true,
            'invoice_id' => $invoiceId,
            'return_id' => $returnId ?: null,
            'mto_cancelled' => $mtoCount,
        ]);
    }

    public function upsertMtoJobs($data) {
        $entityId = $this->entityId($data);
        $jobs = $data['jobs'] ?? [];
        if (!is_array($jobs)) {
            send_json(['error' => 'jobs نامعتبر'], 400);
            return;
        }
        $result = $this->svc->upsertMtoJobs($entityId, $jobs);
        send_json(['success' => true] + $result);
    }

    public function listMtoJobs($data) {
        $entityId = $this->entityId($data);
        $status = trim($data['status'] ?? '');
        $limit = min(200, max(1, intval($data['limit'] ?? 50)));
        $sql = "SELECT * FROM mto_procurement WHERE entity_id = ?";
        $types = 'i';
        $params = [$entityId];
        if ($status !== '') {
            $sql .= " AND status = ?";
            $types .= 's';
            $params[] = $status;
        }
        $sql .= " ORDER BY id DESC LIMIT {$limit}";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        $stmt->close();
        send_json(['success' => true, 'items' => $items]);
    }

    public function updateMtoStatus($data) {
        $entityId = $this->entityId($data);
        $id = intval($data['id'] ?? 0);
        $status = trim($data['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['open', 'ordered', 'received', 'fulfilled', 'cancelled'], true)) {
            send_json(['error' => 'پارامتر نامعتبر'], 400);
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE mto_procurement SET status = ? WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('sii', $status, $id, $entityId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            send_json(['error' => 'رکورد یافت نشد'], 404);
            return;
        }

        // Mirror status to Medusa
        $rowStmt = $this->conn->prepare(
            "SELECT remote_order_id, title FROM mto_procurement WHERE id = ? LIMIT 1"
        );
        $rowStmt->bind_param('i', $id);
        $rowStmt->execute();
        $row = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();
        if ($row) {
            $this->svc->enqueueOutbox('mto.status', [
                'mto_id' => $id,
                'remote_order_id' => $row['remote_order_id'],
                'title' => $row['title'],
                'status' => $status,
            ], 'mto.status:' . $id . ':' . $status);
        }

        send_json(['success' => true, 'id' => $id, 'status' => $status]);
    }

    public function reserveSerials($data) {
        $entityId = $this->entityId($data);
        $invoiceId = intval($data['invoice_id'] ?? 0);
        if ($invoiceId <= 0 && !empty($data['remote_order_id'])) {
            $link = $this->svc->findLinkByRemote('order', $data['remote_order_id']);
            if ($link) $invoiceId = intval($link['local_id']);
        }
        if ($invoiceId <= 0) {
            send_json(['error' => 'فاکتور یافت نشد'], 404);
            return;
        }
        $result = $this->svc->reserveSerialsForInvoice($entityId, $invoiceId);
        send_json(['success' => true] + $result);
    }

    public function alertStaleMto($data) {
        $entityId = $this->entityId($data);
        $count = intval($data['count'] ?? 0);
        $items = $data['items'] ?? [];
        $msg = "{$count} سفارش MTO قدیمی‌تر از ۵ روز باز مانده است.";
        if (is_array($items) && !empty($items)) {
            $sample = array_slice($items, 0, 5);
            $bits = [];
            foreach ($sample as $it) {
                $bits[] = ($it['display_id'] ?? $it['order_id'] ?? '?') . ':' . ($it['title'] ?? '');
            }
            $msg .= ' نمونه: ' . implode(' | ', $bits);
        }
        $this->svc->createNotification(
            $entityId,
            'mto_stale',
            'هشدار MTO معوق',
            $msg,
            null,
            null
        );
        send_json(['success' => true, 'notified' => true, 'count' => $count]);
    }

    public function reconcile($data) {
        $entityId = $this->entityId($data);
        $days = min(30, max(1, intval($data['days'] ?? 7)));

        $stmt = $this->conn->prepare(
            "SELECT id, invoice_number, totalAmount, paidAmount, discount, tax_amount, date
             FROM sales_invoices
             WHERE entity_id = ? AND source = 'online_store'
               AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY id DESC LIMIT 200"
        );
        $stmt->bind_param('ii', $entityId, $days);
        $stmt->execute();
        $res = $stmt->get_result();
        $invoices = [];
        $unlinked = 0;
        $due = 0;
        while ($row = $res->fetch_assoc()) {
            $link = $this->svc->findLinkByLocal('order', (string)$row['id'], $entityId);
            $total = floatval($row['totalAmount']) - floatval($row['discount']) + floatval($row['tax_amount']);
            $paid = floatval($row['paidAmount']);
            $item = [
                'invoice_id' => intval($row['id']),
                'invoice_number' => $row['invoice_number'],
                'date' => $row['date'],
                'total' => $total,
                'paid' => $paid,
                'due' => max(0, $total - $paid),
                'remote_order_id' => $link['remote_id'] ?? null,
            ];
            if (!$link) $unlinked++;
            if ($item['due'] > 0.01) $due++;
            $invoices[] = $item;
        }
        $stmt->close();

        $mtoOpen = 0;
        $mtoRes = $this->conn->query(
            "SELECT COUNT(*) AS c FROM mto_procurement WHERE entity_id = {$entityId} AND status IN ('open','ordered')"
        );
        if ($mtoRes) $mtoOpen = intval($mtoRes->fetch_assoc()['c'] ?? 0);

        send_json([
            'success' => true,
            'days' => $days,
            'invoice_count' => count($invoices),
            'unlinked_count' => $unlinked,
            'due_count' => $due,
            'mto_open' => $mtoOpen,
            'invoices' => $invoices,
        ]);
    }
}
