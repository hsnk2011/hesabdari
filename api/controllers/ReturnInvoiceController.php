<?php
// /api/controllers/ReturnInvoiceController.php

require_once __DIR__ . '/../models/ReturnInvoice.php';

/**
 * The ReturnInvoiceController handles all requests related to return invoices
 */
class ReturnInvoiceController {
    private $conn;
    private $returnInvoiceModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->returnInvoiceModel = new ReturnInvoice($db);
    }

    private function validateReturnInvoiceData($data) {
        if (empty($data['person_id']) || !is_numeric($data['person_id'])) {
            return 'شناسه شخص (مشتری/تامین‌کننده) نامعتبر است.';
        }
        if (empty($data['date'])) {
            return 'تاریخ فاکتور مرجوعی الزامی است.';
        }
        // Validate date format
        if (!validate_date_format($data['date'])) {
            return 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY-MM-DD';
        }
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return 'فاکتور مرجوعی باید حداقل یک قلم کالا داشته باشد.';
        }
        return null; // No error
    }

    /**
     * Handles saving a sales return invoice
     * @param array $data The return invoice data from the client
     */
    public function saveSalesReturn($data) {
        try {
            $error = $this->validateReturnInvoiceData($data);
            if ($error) {
                send_json(['error' => $error], 400);
                return;
            }
            $result = $this->returnInvoiceModel->saveSalesReturn($data);
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره فاکتور مرجوعی فروش: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles deleting a sales return invoice
     * @param array $data Contains the 'id' of the return invoice to delete
     */
    public function deleteSalesReturn($data) {
        try {
            $id = $data['id'] ?? null;
            $result = $this->returnInvoiceModel->deleteSalesReturn($id);
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف فاکتور مرجوعی فروش: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles saving a purchase return invoice
     * @param array $data The return invoice data from the client
     */
    public function savePurchaseReturn($data) {
        try {
            $error = $this->validateReturnInvoiceData($data);
            if ($error) {
                send_json(['error' => $error], 400);
                return;
            }
            $result = $this->returnInvoiceModel->savePurchaseReturn($data);
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره فاکتور مرجوعی خرید: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles deleting a purchase return invoice
     * @param array $data Contains the 'id' of the return invoice to delete
     */
    public function deletePurchaseReturn($data) {
        try {
            $id = $data['id'] ?? null;
            $result = $this->returnInvoiceModel->deletePurchaseReturn($id);
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف فاکتور مرجوعی خرید: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get available identifiers for return (sold or in_stock items)
     * @param array $data Contains productId, dimensions, status, invoiceId (optional)
     */
    public function getAvailableIdentifiers($data) {
        try {
            $productId = $data['productId'] ?? null;
            $dimensions = $data['dimensions'] ?? null;
            $status = $data['status'] ?? 'sold'; // 'sold' for sales return, 'in_stock' for purchase return
            $invoiceId = isset($data['invoiceId']) && $data['invoiceId'] ? intval($data['invoiceId']) : null;
            $entityId = $_SESSION['current_entity_id'] ?? null;

            if (!$entityId) {
                send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 401);
                return;
            }

            if (!$productId || !$dimensions) {
                send_json(['error' => 'شناسه محصول و ابعاد الزامی است.'], 400);
                return;
            }

            $isSalesReturn = ($status === 'sold');
            
            // Build query based on whether we're filtering by invoice
            if ($invoiceId) {
                // Filter by specific invoice
                if ($isSalesReturn) {
                    $stmt = $this->conn->prepare("
                        SELECT ii.id, ii.entry_date, ii.status, si.id as invoice_id
                        FROM inventory_items ii
                        JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
                        JOIN sales_invoices si ON sii.invoiceId = si.id
                        WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ? AND si.id = ?
                        ORDER BY ii.entry_date ASC, ii.id ASC
                    ");
                    $stmt->bind_param("iissi", $entityId, $productId, $dimensions, $status, $invoiceId);
                } else {
                    $stmt = $this->conn->prepare("
                        SELECT ii.id, ii.entry_date, ii.status, pi.id as invoice_id
                        FROM inventory_items ii
                        JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
                        JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                        WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ? AND pi.id = ?
                        ORDER BY ii.entry_date ASC, ii.id ASC
                    ");
                    $stmt->bind_param("iissi", $entityId, $productId, $dimensions, $status, $invoiceId);
                }
            } else {
                // Get all items with invoice info for display
                if ($isSalesReturn) {
                    $stmt = $this->conn->prepare("
                        SELECT ii.id, ii.entry_date, ii.status, si.id as invoice_id
                        FROM inventory_items ii
                        LEFT JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
                        LEFT JOIN sales_invoices si ON sii.invoiceId = si.id
                        WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ?
                        ORDER BY ii.entry_date ASC, ii.id ASC
                    ");
                } else {
                    $stmt = $this->conn->prepare("
                        SELECT ii.id, ii.entry_date, ii.status, pi.id as invoice_id
                        FROM inventory_items ii
                        LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
                        LEFT JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                        WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ?
                        ORDER BY ii.entry_date ASC, ii.id ASC
                    ");
                }
                $stmt->bind_param("iiss", $entityId, $productId, $dimensions, $status);
            }
            
            $stmt->execute();
            $items = db_stmt_to_assoc_array($stmt);

            // Get original invoice info to calculate proportional discount
            $originalInvoiceInfo = null;
            if (!empty($items)) {
                $originalInvoiceInfo = $this->getOriginalInvoiceInfo($items[0]['id'], $status, $entityId);
            }

            send_json([
                'items' => $items,
                'originalInvoice' => $originalInvoiceInfo
            ]);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت شناسه‌ها: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get original invoice information for calculating proportional discount
     */
    private function getOriginalInvoiceInfo($inventoryItemId, $status, $entityId) {
        try {
            $isSalesReturn = ($status === 'sold');
            
            if ($isSalesReturn) {
                // Get sales invoice info
                $stmt = $this->conn->prepare("
                    SELECT si.id, si.totalAmount, si.discount, sii.id as item_id, sii.quantity, sii.unitPrice
                    FROM inventory_items ii
                    JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
                    JOIN sales_invoices si ON sii.invoiceId = si.id
                    WHERE ii.id = ? AND si.entity_id = ?
                ");
            } else {
                // Get purchase invoice info
                $stmt = $this->conn->prepare("
                    SELECT pi.id, pi.totalAmount, pi.discount, pii.id as item_id, pii.quantity, pii.unitPrice
                    FROM inventory_items ii
                    JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
                    JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                    WHERE ii.id = ? AND pi.entity_id = ?
                ");
            }
            
            $stmt->bind_param('ii', $inventoryItemId, $entityId);
            $stmt->execute();
            $result = db_stmt_to_assoc_array($stmt);
            
            if (empty($result)) {
                return null;
            }
            
            $invoice = $result[0];
            
            // Calculate proportional discount per unit for this item
            $totalAmount = floatval($invoice['totalAmount']);
            $totalDiscount = floatval($invoice['discount']);
            $itemTotal = floatval($invoice['quantity']) * floatval($invoice['unitPrice']);
            
            // Calculate item's share of discount (proportional to its value)
            $discountPercentage = ($totalAmount > 0) ? ($totalDiscount / $totalAmount) : 0;
            $itemDiscount = $itemTotal * $discountPercentage;
            $discountPerUnit = ($invoice['quantity'] > 0) ? ($itemDiscount / floatval($invoice['quantity'])) : 0;
            
            return [
                'invoice_id' => $invoice['id'],
                'invoice_total' => $totalAmount,
                'invoice_discount' => $totalDiscount,
                'discount_percentage' => $discountPercentage,
                'discount_per_unit' => $discountPerUnit,
                'item_unit_price' => floatval($invoice['unitPrice'])
            ];
        } catch (Exception $e) {
            error_log("Error getting original invoice info: " . $e->getMessage());
            return null;
        }
    }
}