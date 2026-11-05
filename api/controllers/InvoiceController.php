<?php
// /api/controllers/InvoiceController.php

require_once __DIR__ . '/../models/Invoice.php';
require_once __DIR__ . '/../models/InvoiceHistory.php';

/**
 * The InvoiceController handles all requests related to sales and purchase invoices.
 */
class InvoiceController {
    private $conn;
    private $invoiceModel;

    private $history;

    public function __construct($db) {
        $this->conn = $db;
        $this->invoiceModel = new Invoice($db);
    }

    private function history() {
        if (!$this->history) {
            $this->history = new InvoiceHistory($this->conn);
        }
        return $this->history;
    }

    /** Snapshot before a mutation; never lets history problems break the main flow. */
    private function snapshotSafe($type, $id) {
        try {
            return intval($id) > 0 ? $this->history()->snapshot($type, $id) : null;
        } catch (Throwable $e) {
            error_log('Invoice history snapshot failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Records an event comparing $before with the invoice's current state. */
    private function recordChange($type, $id, $action, $before, $note = null) {
        $after = $action === 'deleted' ? null : $this->snapshotSafe($type, $id);
        if ($action === 'updated' && $before === null) {
            $action = 'created';
        }
        $this->history()->record($type, $id, $action, $before, $after, $note);
    }

    private function validateInvoiceData($data, $type) {
        $personField = ($type === 'sales') ? 'customerId' : 'supplierId';
        if (empty($data[$personField]) || !is_numeric($data[$personField])) {
            return 'شناسه شخص (مشتری/تامین‌کننده) نامعتبر است.';
        }
        if (empty($data['date'])) {
            return 'تاریخ فاکتور الزامی است.';
        }
        // Validate date format
        if (!validate_date_format($data['date'])) {
            return 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY-MM-DD';
        }
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return 'فاکتور باید حداقل یک قلم کالا داشته باشد.';
        }
        return null; // No error
    }

    /**
     * Handles saving a sales invoice.
     * @param array $data The invoice data from the client.
     */
    public function saveSalesInvoice($data) {
        // Permission check is handled by the router
        try {
            $error = $this->validateInvoiceData($data, 'sales');
            if ($error) {
                send_json(['error' => $error], 400);
                return;
            }
            $before = $this->snapshotSafe('sales', $data['id'] ?? 0);
            $result = $this->invoiceModel->saveSalesInvoice($data);
            if (isset($result['success'])) {
                $this->recordChange('sales', $result['id'], 'updated', $before);
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره فاکتور فروش: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles deleting a sales invoice.
     * @param array $data Contains the 'id' of the invoice to delete.
     */
    public function deleteSalesInvoice($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            $before = $this->snapshotSafe('sales', $id);
            $result = $this->invoiceModel->deleteSalesInvoice($id);
            if (isset($result['success']) && $before !== null) {
                $this->recordChange('sales', $id, 'deleted', $before);
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف فاکتور فروش: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles saving a purchase invoice.
     * @param array $data The invoice data from the client.
     */
    public function savePurchaseInvoice($data) {
        // Permission check is handled by the router
        try {
            $error = $this->validateInvoiceData($data, 'purchase');
            if ($error) {
                send_json(['error' => $error], 400);
                return;
            }
            $before = $this->snapshotSafe('purchase', $data['id'] ?? 0);
            $result = $this->invoiceModel->savePurchaseInvoice($data);
            if (isset($result['success'])) {
                $this->recordChange('purchase', $result['id'], 'updated', $before);
            }
            if (isset($result['success']) || isset($result['requiresConfirmation'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره فاکتور خرید: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles deleting a purchase invoice.
     * @param array $data Contains the 'id' of the invoice to delete.
     */
    public function deletePurchaseInvoice($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            $before = $this->snapshotSafe('purchase', $id);
            $result = $this->invoiceModel->deletePurchaseInvoice($id);
            if (isset($result['success']) && $before !== null) {
                $this->recordChange('purchase', $id, 'deleted', $before);
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف فاکتور خرید: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles marking an invoice as consignment.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function markAsConsignment($data) {
        // Permission check is handled by the router
        try {
            $hType = ($data['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
            $before = $this->snapshotSafe($hType, $data['id'] ?? 0);
            $result = $this->invoiceModel->updateConsignmentStatus('mark_as_consignment', $data);
            if (isset($result['success'])) {
                $this->recordChange($hType, $data['id'], 'consignment_marked', $before);
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Handles returning an invoice from consignment.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function returnFromConsignment($data) {
        // Permission check is handled by the router
        try {
            $hType = ($data['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
            $before = $this->snapshotSafe($hType, $data['id'] ?? 0);
            $result = $this->invoiceModel->updateConsignmentStatus('return_from_consignment', $data);
            if (isset($result['success'])) {
                $this->recordChange($hType, $data['id'], 'consignment_returned', $before);
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Gets consignment invoice items for partial conversion modal.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function getConsignmentInvoiceItems($data) {
        try {
            $result = $this->invoiceModel->getConsignmentInvoiceItems($data);
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles partial return from consignment - converts selected items to regular invoice.
     * @param array $data Contains 'type', 'id', and 'selectedItems' (array of item IDs and quantities).
     */
    public function partialReturnFromConsignment($data) {
        try {
            $hType = ($data['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
            $before = $this->snapshotSafe($hType, $data['id'] ?? 0);
            $result = $this->invoiceModel->partialReturnFromConsignment($data);
            if (isset($result['success'])) {
                $oldId = intval($data['id']);
                $newId = intval($result['newInvoiceId'] ?? 0);
                if ($newId > 0 && $newId !== $oldId) {
                    $this->recordChange($hType, $oldId, 'consignment_partial', $before, "اقلام انتخابی به فاکتور #{$newId} منتقل شد");
                    $this->recordChange($hType, $newId, 'created', null, "ایجاد از تبدیل بخشی فاکتور امانی #{$oldId}");
                } else {
                    $this->recordChange($hType, $oldId, 'consignment_returned', $before);
                }
            }
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Lists consignment-purchase items that already have a regular sales invoice.
     */
    public function findSoldConsignmentPurchases($data) {
        try {
            $entity_id = $_SESSION['current_entity_id'] ?? null;
            if (!$entity_id) {
                send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 401);
                return;
            }
            $salesInvoiceId = isset($data['salesInvoiceId']) ? intval($data['salesInvoiceId']) : null;
            $result = $this->invoiceModel->findSoldConsignmentPurchaseItems($entity_id, $salesInvoiceId);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطا در بررسی اقلام امانی فروخته‌شده: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Converts sold consignment-purchase items into regular purchase invoices.
     */
    public function convertSoldConsignmentPurchases($data) {
        try {
            $entity_id = $_SESSION['current_entity_id'] ?? null;
            if (!$entity_id) {
                send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 401);
                return;
            }
            $salesInvoiceId = isset($data['salesInvoiceId']) ? intval($data['salesInvoiceId']) : null;
            $result = $this->invoiceModel->convertSoldConsignmentPurchaseItems($entity_id, $salesInvoiceId, true);
            send_json($result);
        } catch (Exception $e) {
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? intval($e->getCode()) : 500;
            send_json(['error' => 'خطا در تبدیل اقلام امانی فروخته‌شده: ' . $e->getMessage()], $code);
        }
    }

    /**
     * Updates the delivery status of an invoice.
     * @param array $data Contains 'type' ('sales' or 'purchase'), 'id', and 'delivery_status'.
     */
    /**
     * Recent meter prices for a product / similar products (name or collection).
     */
    public function getProductRecentPrices($data) {
        try {
            $type = ($data['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
            check_permission($type === 'sales' ? 'manage_sales_invoices' : 'manage_purchase_invoices');

            $enabled = get_app_setting($this->conn, 'invoice_price_history_enabled', '1');
            if (strval($enabled) === '0') {
                send_json(['success' => true, 'prices' => [], 'source' => 'disabled', 'related_count' => 0]);
                return;
            }

            $productId = intval($data['productId'] ?? $data['product_id'] ?? 0);
            $productName = trim((string)($data['productName'] ?? $data['product_name'] ?? ''));
            if ($productId <= 0 && $productName === '') {
                send_json(['error' => 'محصول یا نام محصول الزامی است.'], 400);
                return;
            }
            $personPrefer = strval(get_app_setting($this->conn, 'invoice_price_history_person_prefer', '1')) !== '0';
            $personId = $personPrefer ? intval($data['personId'] ?? $data['person_id'] ?? 0) : 0;
            $dimensions = trim((string)($data['dimensions'] ?? ''));
            $excludeInvoiceId = intval($data['excludeInvoiceId'] ?? $data['exclude_invoice_id'] ?? 0);

            $allowSimilarSetting = strval(get_app_setting($this->conn, 'invoice_price_history_similar', '1')) !== '0';
            $allowSimilarReq = !isset($data['allowSimilar']) || !in_array(strval($data['allowSimilar']), ['0', 'false', 'no'], true);
            $allowSimilar = $allowSimilarSetting && $allowSimilarReq;

            $limitSetting = intval(get_app_setting($this->conn, 'invoice_price_history_limit', 3));
            $limitReq = intval($data['limit'] ?? 0);
            $limit = $limitReq > 0 ? $limitReq : $limitSetting;
            $limit = max(1, min(5, $limit));

            $result = $this->invoiceModel->getRecentProductPrices(
                $type,
                $productId,
                $personId,
                $dimensions,
                $excludeInvoiceId,
                $limit,
                $productName,
                $allowSimilar
            );
            send_json([
                'success' => true,
                'prices' => $result['prices'],
                'source' => $result['source'],
                'related_count' => $result['related_count'] ?? 0,
            ]);
        } catch (Exception $e) {
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? intval($e->getCode()) : 500;
            send_json(['error' => 'خطا در دریافت قیمت‌های قبلی: ' . $e->getMessage()], $code);
        }
    }

    public function cloneInvoice($data) {
        try {
            $type = $data['type'] ?? 'sales';
            $id = intval($data['id'] ?? 0);
            if (!in_array($type, ['sales', 'purchase']) || $id <= 0) {
                send_json(['error' => 'اطلاعات نامعتبر است.'], 400);
                return;
            }
            check_permission($type === 'sales' ? 'manage_sales_invoices' : 'manage_purchase_invoices');
            $entity_id = $_SESSION['current_entity_id'];
            $table = $type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
            $items_table = $type === 'sales' ? 'sales_invoice_items' : 'purchase_invoice_items';

            $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ii", $id, $entity_id);
            $stmt->execute();
            $invoice = db_stmt_to_assoc_array($stmt);
            if (empty($invoice)) {
                send_json(['error' => 'فاکتور یافت نشد.'], 404);
                return;
            }
            $invoice = $invoice[0];

            $stmt_items = $this->conn->prepare("SELECT * FROM `{$items_table}` WHERE invoiceId = ?");
            $stmt_items->bind_param("i", $id);
            $stmt_items->execute();
            $items = db_stmt_to_assoc_array($stmt_items);

            $clonedItems = [];
            foreach ($items as $item) {
                $clonedItems[] = [
                    'productId' => $item['productId'],
                    'quantity' => $item['quantity'],
                    'unitPrice' => $item['unitPrice'],
                    'dimensions' => $item['dimensions'],
                    'is_service' => $item['is_service'] ?? 0,
                    'item_description' => $item['item_description'] ?? '',
                ];
            }

            $clonedInvoice = [
                'date' => date('Y-m-d'),
                'discount' => $invoice['discount'],
                'description' => $invoice['description'] ? '(کپی) ' . $invoice['description'] : '(کپی)',
                'items' => $clonedItems,
                'payments' => [],
                // Carry consignment flag: a plain clone of a consignment invoice
                // would deduct real stock a second time.
                'is_consignment' => intval($invoice['is_consignment'] ?? 0),
                'tax_rate' => $invoice['tax_rate'] ?? 0,
                'tax_amount' => $invoice['tax_amount'] ?? 0,
                'due_date' => null,
                'payment_terms' => $invoice['payment_terms'] ?? null,
            ];

            if ($type === 'sales') {
                $clonedInvoice['customerId'] = $invoice['customerId'];
            } else {
                $clonedInvoice['supplierId'] = $invoice['supplierId'];
            }

            $result = $type === 'sales'
                ? $this->invoiceModel->saveSalesInvoice($clonedInvoice)
                : $this->invoiceModel->savePurchaseInvoice($clonedInvoice);

            if (isset($result['success'])) {
                log_activity($this->conn, 'CLONE_INVOICE', "فاکتور {$type} #{$id} کپی شد → #{$result['id']}");
                $this->recordChange($type, $result['id'], 'created', null, "کپی از فاکتور #{$id}");
                send_json(['success' => true, 'id' => $result['id'], 'message' => 'فاکتور با موفقیت کپی شد.']);
            } else {
                send_json(['error' => $result['error'] ?? 'خطا در کپی فاکتور.'], 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطا: ' . $e->getMessage()], 500);
        }
    }

    /**
     * تاریخچه تغییرات یک فاکتور + اقلام متصل به فاکتورهای فروش/خرید دیگر.
     */
    public function getInvoiceHistory($data) {
        try {
            $type = ($data['type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
            $id = intval($data['id'] ?? 0);
            if ($id <= 0) {
                send_json(['error' => 'شناسه فاکتور نامعتبر است.'], 400);
                return;
            }
            check_permission($type === 'sales' ? 'manage_sales_invoices' : 'manage_purchase_invoices');

            $history = $this->history();
            $current = $history->snapshot($type, $id);
            $events = $history->getHistory($type, $id);
            if ($current === null && empty($events)) {
                send_json(['error' => 'فاکتور یافت نشد.'], 404);
                return;
            }
            $linked = $current !== null ? $history->getLinkedItems($type, $id) : ['links' => [], 'returns' => []];
            send_json([
                'success' => true,
                'type' => $type,
                'id' => $id,
                'exists' => $current !== null,
                'history' => $events,
                'linked' => $linked,
            ]);
        } catch (Exception $e) {
            $code = (is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 500) ? intval($e->getCode()) : 500;
            send_json(['error' => 'خطا در دریافت تاریخچه فاکتور: ' . $e->getMessage()], $code);
        }
    }

    public function preparePurchaseToSales($data) {
        try {
            check_permission('manage_purchase_invoices');
            check_permission('manage_sales_invoices');
            $id = intval($data['id'] ?? 0);
            if ($id <= 0) {
                send_json(['error' => 'فاکتور خرید نامعتبر است.'], 400);
                return;
            }
            $result = $this->invoiceModel->buildSalesDraftFromPurchase($_SESSION['current_entity_id'], $id);
            send_json($result);
        } catch (Exception $e) {
            $code = (is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 500) ? intval($e->getCode()) : 500;
            send_json(['error' => $e->getMessage()], $code);
        }
    }

    public function updateDeliveryStatus($data) {
        try {
            $type = $data['type'] ?? '';
            $id = intval($data['id'] ?? 0);
            $status = $data['delivery_status'] ?? '';

            if (!in_array($type, ['sales', 'purchase'])) {
                send_json(['error' => 'نوع فاکتور نامعتبر است.'], 400);
                return;
            }
            if ($id <= 0) {
                send_json(['error' => 'شناسه فاکتور نامعتبر است.'], 400);
                return;
            }
            if (!in_array($status, ['preparing', 'ready', 'delivered'])) {
                send_json(['error' => 'وضعیت تحویل نامعتبر است.'], 400);
                return;
            }

            $table = ($type === 'sales') ? 'sales_invoices' : 'purchase_invoices';
            $entity_id = $_SESSION['current_entity_id'];

            // Verify existence first: affected_rows is 0 both for a missing row
            // and an unchanged value, so it cannot distinguish 404 from a no-op.
            $stmtChk = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ?");
            $stmtChk->bind_param("ii", $id, $entity_id);
            $stmtChk->execute();
            if (empty(db_stmt_to_assoc_array($stmtChk))) {
                send_json(['error' => 'فاکتور یافت نشد.'], 404);
                return;
            }

            $before = $this->snapshotSafe($type, $id);
            $stmt = $this->conn->prepare("UPDATE `{$table}` SET delivery_status = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("sii", $status, $id, $entity_id);
            $stmt->execute();
            $this->recordChange($type, $id, 'delivery_status', $before);

            {
                $statusLabels = [
                    'preparing' => 'در حال آماده‌سازی',
                    'ready' => 'آماده تحویل',
                    'delivered' => 'تحویل شده'
                ];

                // Auto-update linked sales invoices when a purchase invoice becomes "delivered"
                $autoUpdated = [];
                if ($type === 'purchase' && $status === 'delivered') {
                    $autoUpdated = $this->autoUpdateLinkedSalesInvoices($id, $entity_id);
                }

                $msg = 'وضعیت تحویل به "' . $statusLabels[$status] . '" تغییر کرد.';
                if (!empty($autoUpdated)) {
                    $ids = implode('، ', $autoUpdated);
                    $msg .= ' همچنین فاکتور(های) فروش شماره ' . $ids . ' به وضعیت «آماده تحویل» تغییر کرد.';
                }
                send_json(['success' => true, 'message' => $msg, 'autoUpdatedSalesInvoices' => $autoUpdated]);
            }
            $stmt->close();
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام بروزرسانی وضعیت تحویل: ' . $e->getMessage()], 500);
        }
    }

    /**
     * When a purchase invoice is marked as "delivered", check all "preparing" sales invoices.
     * If ALL non-service items of a sales invoice now have sufficient delivered purchase quantity,
     * auto-update that sales invoice to "ready".
     */
    private function autoUpdateLinkedSalesInvoices($purchaseInvoiceId, $entityId) {
        $updatedIds = [];

        // Get product IDs from the delivered purchase invoice
        $pStmt = $this->conn->prepare("
            SELECT DISTINCT productId FROM purchase_invoice_items 
            WHERE invoiceId = ? AND is_service = 0 AND productId IS NOT NULL
        ");
        $pStmt->bind_param("i", $purchaseInvoiceId);
        $pStmt->execute();
        $purchaseProducts = db_stmt_to_assoc_array($pStmt);

        if (empty($purchaseProducts)) return $updatedIds;

        $productIds = array_column($purchaseProducts, 'productId');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        // Find all "preparing" sales invoices that contain at least one of these products
        $sStmt = $this->conn->prepare("
            SELECT DISTINCT si.id 
            FROM sales_invoices si
            JOIN sales_invoice_items sii ON sii.invoiceId = si.id
            WHERE si.entity_id = ? 
              AND si.delivery_status = 'preparing'
              AND (si.is_consignment = 0 OR si.is_consignment IS NULL)
              AND sii.is_service = 0
              AND sii.productId IN ({$placeholders})
        ");
        $sStmt->bind_param("i" . $types, $entityId, ...$productIds);
        $sStmt->execute();
        $candidateInvoices = db_stmt_to_assoc_array($sStmt);

        if (empty($candidateInvoices)) return $updatedIds;

        // For each candidate, check if ALL its non-service items are fully covered
        foreach ($candidateInvoices as $candidate) {
            $salesInvoiceId = $candidate['id'];

            // Get all non-service items of this sales invoice
            $itemStmt = $this->conn->prepare("
                SELECT productId, dimensions, SUM(quantity) as needed_qty
                FROM sales_invoice_items
                WHERE invoiceId = ? AND is_service = 0 AND productId IS NOT NULL
                GROUP BY productId, dimensions
            ");
            $itemStmt->bind_param("i", $salesInvoiceId);
            $itemStmt->execute();
            $salesItems = db_stmt_to_assoc_array($itemStmt);

            if (empty($salesItems)) continue;

            $allCovered = true;
            foreach ($salesItems as $sItem) {
                // Count how many of this product+dimension exist in delivered purchase invoices
                $qStmt = $this->conn->prepare("
                    SELECT COALESCE(SUM(pii.quantity), 0) as available_qty
                    FROM purchase_invoice_items pii
                    JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                    WHERE pi.entity_id = ?
                      AND pi.delivery_status = 'delivered'
                      AND (pi.is_consignment = 0 OR pi.is_consignment IS NULL)
                      AND pii.productId = ?
                      AND pii.is_service = 0
                      AND (pii.dimensions <=> ?)
                ");
                // '' and NULL must match each other, otherwise a purchase row with
                // NULL dimensions never covers a sales item stored as ''.
                $qStmt->bind_param("iis", $entityId, $sItem['productId'], $sItem['dimensions']);
                $qStmt->execute();
                $row = db_stmt_to_assoc_array($qStmt);

                $availableQty = (int)($row[0]['available_qty'] ?? 0);
                if ($availableQty < (int)$sItem['needed_qty']) {
                    $allCovered = false;
                    break;
                }
            }

            if ($allCovered) {
                $upStmt = $this->conn->prepare("UPDATE sales_invoices SET delivery_status = 'ready' WHERE id = ? AND entity_id = ?");
                $upStmt->bind_param("ii", $salesInvoiceId, $entityId);
                $upStmt->execute();
                if ($upStmt->affected_rows > 0) {
                    $updatedIds[] = $salesInvoiceId;
                }
                $upStmt->close();
            }
        }

        return $updatedIds;
    }
}
