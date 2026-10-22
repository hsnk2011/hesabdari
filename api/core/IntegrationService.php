<?php
// /api/core/IntegrationService.php

class IntegrationService {
    /** @var mysqli */
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function upsertLink($entityType, $localId, $remoteId, $businessEntityId, $meta = null, $remoteSystem = 'medusa') {
        $metaJson = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
        $localId = (string)$localId;
        $remoteId = (string)$remoteId;

        $existing = $this->findLinkByRemote($entityType, $remoteId, $remoteSystem);
        if ($existing) {
            $stmt = $this->conn->prepare(
                "UPDATE integration_links SET local_id = ?, business_entity_id = ?, meta_json = ? WHERE id = ?"
            );
            $stmt->bind_param('sisi', $localId, $businessEntityId, $metaJson, $existing['id']);
            $stmt->execute();
            $stmt->close();
            return intval($existing['id']);
        }

        $existingLocal = $this->findLinkByLocal($entityType, $localId, $businessEntityId, $remoteSystem);
        if ($existingLocal) {
            $stmt = $this->conn->prepare(
                "UPDATE integration_links SET remote_id = ?, meta_json = ? WHERE id = ?"
            );
            $stmt->bind_param('ssi', $remoteId, $metaJson, $existingLocal['id']);
            $stmt->execute();
            $stmt->close();
            return intval($existingLocal['id']);
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO integration_links (entity_type, local_id, remote_id, remote_system, business_entity_id, meta_json)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssis', $entityType, $localId, $remoteId, $remoteSystem, $businessEntityId, $metaJson);
        $stmt->execute();
        $id = $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    public function findLinkByRemote($entityType, $remoteId, $remoteSystem = 'medusa') {
        $stmt = $this->conn->prepare(
            "SELECT * FROM integration_links WHERE entity_type = ? AND remote_system = ? AND remote_id = ? LIMIT 1"
        );
        $stmt->bind_param('sss', $entityType, $remoteSystem, $remoteId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    public function findLinkByLocal($entityType, $localId, $businessEntityId, $remoteSystem = 'medusa') {
        $localId = (string)$localId;
        $stmt = $this->conn->prepare(
            "SELECT * FROM integration_links WHERE entity_type = ? AND remote_system = ? AND business_entity_id = ? AND local_id = ? LIMIT 1"
        );
        $stmt->bind_param('ssis', $entityType, $remoteSystem, $businessEntityId, $localId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    public function enqueueOutbox($eventType, $payload, $idempotencyKey) {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->conn->prepare(
            "INSERT INTO integration_outbox (event_type, payload_json, idempotency_key, status, next_attempt_at)
             VALUES (?, ?, ?, 'pending', NOW())
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->bind_param('sss', $eventType, $payloadJson, $idempotencyKey);
        $stmt->execute();
        $id = $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    public function enqueueStockSync($entityId, $productIds = []) {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $snapshot = $this->buildStockSnapshot($entityId, $productIds);
        $key = 'stock_sync_' . $entityId . '_' . md5(json_encode($productIds) . date('YmdHi'));
        return $this->enqueueOutbox('stock.sync', [
            'entity_id' => intval($entityId),
            'items' => $snapshot,
            'generated_at' => date('c'),
        ], $key);
    }

    /**
     * Notify storefront that a hesabdari product was hard-deleted.
     * Site must mark the linked product unavailable and MUST NOT delete images.
     */
    public function enqueueProductRemoved($entityId, $productId, $productName = '') {
        $productId = intval($productId);
        $entityId = intval($entityId);
        if ($productId <= 0) {
            return 0;
        }
        $key = 'product_removed_' . $entityId . '_' . $productId . '_' . time();
        return $this->enqueueOutbox('product.removed', [
            'entity_id' => $entityId,
            'product_id' => $productId,
            'name' => (string)$productName,
            'removed_at' => date('c'),
        ], $key);
    }

    public function buildStockSnapshot($entityId, $productIds = []) {
        $items = [];
        if (!empty($productIds)) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $types = str_repeat('i', count($productIds) + 1);
            $sql = "SELECT p.id AS product_id, p.sku, p.fulfillment_mode, ii.dimensions,
                           COUNT(ii.id) AS available_qty
                    FROM products p
                    LEFT JOIN inventory_items ii
                      ON ii.product_id = p.id AND ii.entity_id = p.entity_id AND ii.status = 'in_stock'
                    WHERE p.entity_id = ? AND p.id IN ($ph)
                    GROUP BY p.id, p.sku, p.fulfillment_mode, ii.dimensions";
            $stmt = $this->conn->prepare($sql);
            $params = array_merge([$entityId], $productIds);
            $stmt->bind_param($types, ...$params);
        } else {
            $sql = "SELECT p.id AS product_id, p.sku, p.fulfillment_mode, ii.dimensions,
                           COUNT(ii.id) AS available_qty
                    FROM products p
                    LEFT JOIN inventory_items ii
                      ON ii.product_id = p.id AND ii.entity_id = p.entity_id AND ii.status = 'in_stock'
                    WHERE p.entity_id = ? AND p.is_active = 1
                    GROUP BY p.id, p.sku, p.fulfillment_mode, ii.dimensions";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $entityId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'product_id' => intval($row['product_id']),
                'sku' => $row['sku'],
                'fulfillment_mode' => $row['fulfillment_mode'] ?: 'stocked',
                'dimensions' => $this->normalizeDimensions($row['dimensions']) ?: $row['dimensions'],
                'available_qty' => intval($row['available_qty']),
            ];
        }
        $stmt->close();
        return $items;
    }

    /**
     * تصاویر یک محصول با آدرس مطلق (URL کامل) برای انتقال به فروشگاه.
     * تصویر اصلی (is_primary) اول لیست قرار می‌گیرد.
     */
    public function productImages($productId, $baseUrl = null) {
        $productId = intval($productId);
        if ($productId <= 0) return [];
        if ($baseUrl === null && function_exists('app_public_base_url')) {
            $baseUrl = app_public_base_url();
        }
        $baseUrl = rtrim((string)$baseUrl, '/');

        $stmt = $this->conn->prepare(
            "SELECT id, file_name, file_path, thumb_path, is_primary, sort_order
             FROM product_images
             WHERE product_id = ?
             ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $res = $stmt->get_result();
        $images = [];
        while ($row = $res->fetch_assoc()) {
            $rel = ltrim((string)($row['file_path'] ?? ''), '/');
            $thumbRel = ltrim((string)($row['thumb_path'] ?? ''), '/');
            if ($rel === '') continue;
            $images[] = [
                'id' => intval($row['id']),
                'file_name' => $row['file_name'],
                'url' => $baseUrl !== '' ? ($baseUrl . '/' . $rel) : $rel,
                'thumb_url' => $thumbRel !== '' ? ($baseUrl . '/' . $thumbRel) : ($baseUrl !== '' ? ($baseUrl . '/' . $rel) : $rel),
                'is_primary' => intval($row['is_primary']) === 1,
                'sort_order' => intval($row['sort_order'] ?? 0),
            ];
        }
        $stmt->close();
        return $images;
    }

    public function normalizePhone($phone) {
        if (!$phone) return '';
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (strpos($digits, '9') === 0 && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }
        return $digits;
    }

    /**
     * Normalize dimension labels for matching across Medusa / hesabdari.
     * 192×150, 192 * 150 → 192x150
     */
    public function normalizeDimensions($input) {
        if ($input === null || $input === '') return '';
        $s = trim((string)$input);
        $s = preg_replace('/[×✕*X]/iu', 'x', $s);
        $s = preg_replace('/\s+/', '', $s);
        return mb_strtolower($s, 'UTF-8');
    }

    public function upsertMtoJobs($entityId, $jobs) {
        if (!is_array($jobs) || empty($jobs)) return ['upserted' => 0];
        $count = 0;
        foreach ($jobs as $job) {
            $remoteOrderId = trim($job['remote_order_id'] ?? '');
            $title = trim($job['title'] ?? 'قلم MTO');
            if ($remoteOrderId === '' || $title === '') continue;

            $displayId = trim($job['display_id'] ?? '') ?: null;
            $invoiceId = intval($job['invoice_id'] ?? 0);
            $productId = intval($job['product_id'] ?? 0);
            $dimensions = $this->normalizeDimensions($job['dimensions'] ?? '') ?: null;
            $quantity = max(1, intval($job['quantity'] ?? 1));
            $status = $job['status'] ?? 'open';
            if (!in_array($status, ['open', 'ordered', 'received', 'fulfilled', 'cancelled'], true)) {
                $status = 'open';
            }
            $metaJson = isset($job['meta']) ? json_encode($job['meta'], JSON_UNESCAPED_UNICODE) : null;

            // Deduplicate by remote_order_id + title + dimensions
            $dimKey = $dimensions ?? '';
            $find = $this->conn->prepare(
                "SELECT id FROM mto_procurement
                 WHERE entity_id = ? AND remote_order_id = ? AND title = ?
                   AND COALESCE(dimensions,'') = ?
                 LIMIT 1"
            );
            $find->bind_param('isss', $entityId, $remoteOrderId, $title, $dimKey);
            $find->execute();
            $existing = $find->get_result()->fetch_assoc();
            $find->close();

            if ($existing) {
                $upd = $this->conn->prepare(
                    "UPDATE mto_procurement
                     SET display_id = ?, invoice_id = NULLIF(?, 0), product_id = NULLIF(?, 0),
                         quantity = ?, status = ?, meta_json = ?, dimensions = ?
                     WHERE id = ?"
                );
                $id = intval($existing['id']);
                $upd->bind_param('siiisssi', $displayId, $invoiceId, $productId, $quantity, $status, $metaJson, $dimensions, $id);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $this->conn->prepare(
                    "INSERT INTO mto_procurement
                     (entity_id, remote_order_id, display_id, invoice_id, product_id, title, dimensions, quantity, status, meta_json)
                     VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?)"
                );
                $ins->bind_param(
                    'issiississ',
                    $entityId,
                    $remoteOrderId,
                    $displayId,
                    $invoiceId,
                    $productId,
                    $title,
                    $dimensions,
                    $quantity,
                    $status,
                    $metaJson
                );
                $ins->execute();
                $ins->close();
            }
            $count++;
        }
        return ['upserted' => $count];
    }

    /**
     * Ensure stocked invoice lines have inventory serials assigned; return snapshot.
     */
    public function reserveSerialsForInvoice($entityId, $invoiceId) {
        $stmt = $this->conn->prepare(
            "SELECT sii.id, sii.productId, sii.dimensions, sii.quantity, sii.is_service
             FROM sales_invoice_items sii
             INNER JOIN sales_invoices si ON si.id = sii.invoiceId
             WHERE sii.invoiceId = ? AND si.entity_id = ?"
        );
        $stmt->bind_param('ii', $invoiceId, $entityId);
        $stmt->execute();
        $res = $stmt->get_result();
        $reserved = [];
        $missing = [];

        while ($row = $res->fetch_assoc()) {
            if (intval($row['is_service']) === 1 || empty($row['productId'])) {
                continue;
            }
            $itemId = intval($row['id']);
            $productId = intval($row['productId']);
            $dimensions = $this->normalizeDimensions($row['dimensions']) ?: trim($row['dimensions'] ?? '');
            $qty = intval($row['quantity']);

            $cntStmt = $this->conn->prepare(
                "SELECT COUNT(*) AS c FROM inventory_items WHERE sales_invoice_item_id = ?"
            );
            $cntStmt->bind_param('i', $itemId);
            $cntStmt->execute();
            $have = intval($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $cntStmt->close();

            $need = max(0, $qty - $have);
            if ($need > 0) {
                // Compare normalized dimensions in PHP: stored values may use
                // ×, *, X etc., which never equal the normalized form in SQL.
                $sel = $this->conn->prepare(
                    "SELECT id, dimensions FROM inventory_items
                     WHERE entity_id = ? AND product_id = ? AND status IN ('in_stock', 'returned')
                     ORDER BY entry_date ASC, id ASC"
                );
                $sel->bind_param('ii', $entityId, $productId);
                $sel->execute();
                $ids = [];
                $r2 = $sel->get_result();
                while ($s = $r2->fetch_assoc()) {
                    if (count($ids) >= $need) break;
                    if ($this->normalizeDimensions($s['dimensions'] ?? '') === $dimensions) {
                        $ids[] = intval($s['id']);
                    }
                }
                $sel->close();

                if (!empty($ids)) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $types = str_repeat('i', count($ids));
                    $upd = $this->conn->prepare(
                        "UPDATE inventory_items
                         SET returned_from_sales_item_id = IF(status = 'returned', COALESCE(returned_from_sales_item_id, sales_invoice_item_id), returned_from_sales_item_id),
                             status = 'sold',
                             sales_invoice_item_id = ?
                         WHERE id IN ($ph)"
                    );
                    $upd->bind_param('i' . $types, $itemId, ...$ids);
                    $upd->execute();
                    $upd->close();
                    $have += count($ids);
                }
            }

            $serStmt = $this->conn->prepare(
                "SELECT id, serial_id, status FROM inventory_items WHERE sales_invoice_item_id = ?"
            );
            $serStmt->bind_param('i', $itemId);
            $serStmt->execute();
            $serials = [];
            $sr = $serStmt->get_result();
            while ($s = $sr->fetch_assoc()) {
                $serials[] = [
                    'id' => intval($s['id']),
                    'serial_id' => $s['serial_id'] ?: ('INV-' . str_pad($s['id'], 6, '0', STR_PAD_LEFT)),
                    'status' => $s['status'],
                ];
            }
            $serStmt->close();

            $entry = [
                'invoice_item_id' => $itemId,
                'product_id' => $productId,
                'dimensions' => $dimensions,
                'quantity' => $qty,
                'serials' => $serials,
            ];
            $reserved[] = $entry;
            if (count($serials) < $qty) {
                $missing[] = $entry;
            }
        }
        $stmt->close();

        if (!empty($reserved)) {
            $this->enqueueStockSync($entityId, array_column($reserved, 'product_id'));
        }

        return [
            'invoice_id' => $invoiceId,
            'reserved' => $reserved,
            'missing_count' => count($missing),
            'missing' => $missing,
        ];
    }

    /**
     * After a receipt payment on an online_store invoice, notify Medusa via outbox.
     */
    public function notifyOnlineStorePayment($entityId, $invoiceId, $paymentId, $amountAccounting) {
        $invStmt = $this->conn->prepare(
            "SELECT id, source, paidAmount, totalAmount, discount, tax_amount
             FROM sales_invoices WHERE id = ? AND entity_id = ? LIMIT 1"
        );
        $invStmt->bind_param('ii', $invoiceId, $entityId);
        $invStmt->execute();
        $inv = $invStmt->get_result()->fetch_assoc();
        $invStmt->close();
        if (!$inv || ($inv['source'] ?? '') !== 'online_store') {
            return null;
        }

        $link = $this->findLinkByLocal('order', (string)$invoiceId, $entityId);
        $remoteOrderId = $link['remote_id'] ?? null;
        $key = 'payment.matched:' . $paymentId;
        return $this->enqueueOutbox('payment.matched', [
            'entity_id' => intval($entityId),
            'invoice_id' => intval($invoiceId),
            'payment_id' => intval($paymentId),
            'remote_order_id' => $remoteOrderId,
            'amount_accounting' => floatval($amountAccounting),
            'paid_amount' => floatval($inv['paidAmount'] ?? 0),
            'total_amount' => floatval($inv['totalAmount'] ?? 0) - floatval($inv['discount'] ?? 0) + floatval($inv['tax_amount'] ?? 0),
            'generated_at' => date('c'),
        ], $key);
    }

    /**
     * Medusa amounts are IRR (rial). Hesabdari typically stores toman.
     */
    public function rialToAccounting($amountRial) {
        // Priority: installation setting → config constant → historical default 10.
        $divisor = floatval(get_app_setting($this->conn, 'currency_rial_divisor', 0));
        if ($divisor <= 0 && defined('INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR')) {
            $divisor = floatval(INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR);
        }
        if ($divisor <= 0) $divisor = 10.0;
        return round(floatval($amountRial) / $divisor, 2);
    }

    public function createNotification($entityId, $type, $title, $message, $referenceType = null, $referenceId = null) {
        $refType = $referenceType !== null ? (string)$referenceType : null;
        $refId = $referenceId !== null ? intval($referenceId) : null;
        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (entity_id, type, title, message, reference_type, reference_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        // bind with variables (mysqli requires references)
        $stmt->bind_param('issssi', $entityId, $type, $title, $message, $refType, $refId);
        $stmt->execute();
        $id = $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    public function getIntegrationSetting($key, $default = null) {
        // DB overrides config defines (so settings UI can change values without editing config.php)
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $fullKey = 'integration_' . $key;
        $stmt->bind_param('s', $fullKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row !== null && array_key_exists('setting_value', $row) && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return $row['setting_value'];
        }

        if ($key === 'medusa_webhook_url' && defined('MEDUSA_WEBHOOK_URL') && MEDUSA_WEBHOOK_URL) {
            return MEDUSA_WEBHOOK_URL;
        }
        if ($key === 'webhook_secret' && defined('HESABDARI_INTEGRATION_SECRET') && HESABDARI_INTEGRATION_SECRET) {
            return HESABDARI_INTEGRATION_SECRET;
        }
        return $default;
    }

    public function setIntegrationSetting($key, $value) {
        $fullKey = 'integration_' . $key;
        $stmt = $this->conn->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->bind_param('ss', $fullKey, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Deliver pending outbox events to Medusa webhook.
     */
    public function processOutbox($limit = 20) {
        $webhookUrl = $this->getIntegrationSetting('medusa_webhook_url', '');
        $secret = $this->getIntegrationSetting('webhook_secret', '');
        if ($webhookUrl === '') {
            return ['processed' => 0, 'skipped' => 'no_webhook_url'];
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM integration_outbox
             WHERE status IN ('pending','failed')
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
               AND attempts < 8
             ORDER BY id ASC LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $sent = 0;
        foreach ($rows as $row) {
            $body = json_encode([
                'event' => $row['event_type'],
                'idempotency_key' => $row['idempotency_key'],
                'payload' => json_decode($row['payload_json'], true),
            ], JSON_UNESCAPED_UNICODE);

            if ($secret === '') {
                // Never sign with a guessable fallback secret — defer until configured.
                $attempts = intval($row['attempts']) + 1;
                $err = 'webhook_secret is not configured';
                $upd = $this->conn->prepare(
                    "UPDATE integration_outbox SET status = 'failed', attempts = ?, last_error = ?,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id = ?"
                );
                $upd->bind_param('isi', $attempts, $err, $row['id']);
                $upd->execute();
                $upd->close();
                continue;
            }

            $signature = hash_hmac('sha256', $body, $secret);
            // Replay-safe signature over "<timestamp>.<body>" (shop backends from
            // the 2026-10 audit verify it; older ones use the legacy headers).
            $timestamp = (string)time();
            $signatureV2 = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            $headers = [
                'Content-Type: application/json',
                'X-Hesabdari-Signature: ' . $signature,
                'X-Hesabdari-Timestamp: ' . $timestamp,
                'X-Hesabdari-Signature-V2: ' . $signatureV2,
                'X-Idempotency-Key: ' . $row['idempotency_key'],
            ];
            // Sending the shared secret itself defeats the signature. Kept only
            // for shop backends that are not updated yet; set
            // INTEGRATION_WEBHOOK_LEGACY_SECRET_HEADER=false in api/config.php
            // once all connected shops run the updated backend.
            if (!defined('INTEGRATION_WEBHOOK_LEGACY_SECRET_HEADER') || INTEGRATION_WEBHOOK_LEGACY_SECRET_HEADER) {
                $headers[] = 'X-Hesabdari-Secret: ' . $secret;
            }
            $ok = $this->httpPost($webhookUrl, $body, $headers);

            $attempts = intval($row['attempts']) + 1;
            if ($ok['success']) {
                $upd = $this->conn->prepare(
                    "UPDATE integration_outbox SET status = 'sent', attempts = ?, sent_at = NOW(), last_error = NULL WHERE id = ?"
                );
                $upd->bind_param('ii', $attempts, $row['id']);
                $upd->execute();
                $upd->close();
                $sent++;
            } else {
                $delayMin = min(60, pow(2, $attempts));
                $err = substr($ok['error'] ?? 'unknown', 0, 500);
                $upd = $this->conn->prepare(
                    "UPDATE integration_outbox SET status = 'failed', attempts = ?, last_error = ?,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?"
                );
                $upd->bind_param('isii', $attempts, $err, $delayMin, $row['id']);
                $upd->execute();
                $upd->close();
            }
        }

        return ['processed' => count($rows), 'sent' => $sent];
    }

    private function httpPost($url, $body, $headers = []) {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = intval($m[1]);
            }
            if ($resp === false || $code >= 400) {
                return ['success' => false, 'error' => "HTTP {$code}"];
            }
            return ['success' => true];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            return ['success' => false, 'error' => $err ?: "HTTP {$code}"];
        }
        return ['success' => true];
    }
}
