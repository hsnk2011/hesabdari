<?php
// /api/controllers/QuotationController.php

require_once __DIR__ . '/../models/Quotation.php';

/**
 * The QuotationController handles all requests related to quotations (draft invoices).
 * Quotations do NOT affect inventory or financial records until converted to actual invoices.
 */
class QuotationController {
    private $conn;
    private $quotationModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->quotationModel = new Quotation($db);
    }

    /**
     * Get a single quotation by ID
     * @param array $data Contains 'id'
     */
    public function getQuotation($data) {
        try {
            $id = $data['id'] ?? null;
            if (!$id) {
                send_json(['error' => 'شناسه پیش فاکتور الزامی است.'], 400);
                return;
            }
            
            $result = $this->quotationModel->getById($id);
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            } else {
                send_json($result);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Save (create or update) a quotation
     * @param array $data The quotation data
     */
    public function saveQuotation($data) {
        try {
            // Validate basic data
            if (empty($data['customerId'])) {
                send_json(['error' => 'انتخاب مشتری الزامی است.'], 400);
                return;
            }
            if (empty($data['date'])) {
                send_json(['error' => 'تاریخ الزامی است.'], 400);
                return;
            }
            if (!validate_date_format($data['date'])) {
                send_json(['error' => 'فرمت تاریخ نامعتبر است.'], 400);
                return;
            }
            if (!empty($data['valid_until']) && !validate_date_format($data['valid_until'])) {
                send_json(['error' => 'فرمت تاریخ اعتبار نامعتبر است.'], 400);
                return;
            }
            if (empty($data['items']) || !is_array($data['items'])) {
                send_json(['error' => 'حداقل یک آیتم باید اضافه شود.'], 400);
                return;
            }
            
            // Validate items
            foreach ($data['items'] as $item) {
                if (empty($item['productId']) || !is_numeric($item['productId'])) {
                    send_json(['error' => 'شناسه محصول نامعتبر است.'], 400);
                    return;
                }
                if (empty($item['quantity']) || intval($item['quantity']) <= 0) {
                    send_json(['error' => 'تعداد باید بزرگتر از صفر باشد.'], 400);
                    return;
                }
                if (!isset($item['unitPrice']) || floatval($item['unitPrice']) < 0) {
                    send_json(['error' => 'قیمت واحد نامعتبر است.'], 400);
                    return;
                }
            }
            
            $result = $this->quotationModel->save($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'quotation', isset($data['id']) ? 'ویرایش پیش فاکتور شماره ' . $result['id'] : 'ایجاد پیش فاکتور شماره ' . $result['id']);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a quotation
     * @param array $data Contains 'id'
     */
    public function deleteQuotation($data) {
        try {
            $id = $data['id'] ?? null;
            if (!$id) {
                send_json(['error' => 'شناسه پیش فاکتور الزامی است.'], 400);
                return;
            }
            
            $result = $this->quotationModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'quotation', 'حذف پیش فاکتور شماره ' . $id);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update quotation status (cancel, etc.)
     * @param array $data Contains 'id' and 'status'
     */
    public function updateQuotationStatus($data) {
        try {
            $id = $data['id'] ?? null;
            $status = $data['status'] ?? null;
            
            if (!$id) {
                send_json(['error' => 'شناسه پیش فاکتور الزامی است.'], 400);
                return;
            }
            if (!$status) {
                send_json(['error' => 'وضعیت جدید الزامی است.'], 400);
                return;
            }
            
            $result = $this->quotationModel->updateStatus($id, $status);
            if (isset($result['success'])) {
                $statusLabels = [
                    'pending' => 'در انتظار',
                    'cancelled' => 'لغو شده',
                    'expired' => 'منقضی شده'
                ];
                log_activity($this->conn, 'quotation', 'تغییر وضعیت پیش فاکتور شماره ' . $id . ' به ' . ($statusLabels[$status] ?? $status));
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Convert quotation to sales invoice
     * This creates a new invoice but does NOT affect inventory until the invoice is saved with items
     * @param array $data Contains 'id'
     */
    public function convertToInvoice($data) {
        try {
            $id = $data['id'] ?? null;
            if (!$id) {
                send_json(['error' => 'شناسه پیش فاکتور الزامی است.'], 400);
                return;
            }
            
            $result = $this->quotationModel->convertToInvoice($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'quotation', 'تبدیل پیش فاکتور شماره ' . $id . ' به فاکتور فروش شماره ' . $result['invoiceId']);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور: ' . $e->getMessage()], 500);
        }
    }
}
