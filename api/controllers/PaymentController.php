<?php
// /api/controllers/PaymentController.php
require_once __DIR__ . '/../models/Payment.php';

class PaymentController {
    private $conn;
    private $paymentModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->paymentModel = new Payment($db);
    }

    public function savePayment($data) {
        // Permission is dynamically checked in the router (api/index.php)
        try {
            // Server-side validation
            if (empty($data['type']) || !in_array($data['type'], ['cash', 'check', 'endorse_check'], true)) {
                send_json(['error' => 'نوع پرداخت نامعتبر است.' . (($data['type'] ?? '') === 'setoff' ? ' تهاتر را از فاکتور فروش ثبت کنید.' : '')], 400);
                return;
            }
            if (!empty($data['invoiceId']) && empty($data['invoiceType'])) {
                send_json(['error' => 'نوع فاکتور برای این تراکنش مشخص نشده است.'], 400);
                return;
            }
            if (empty($data['transaction_type']) || !in_array($data['transaction_type'], ['receipt', 'payment'])) {
                send_json(['error' => 'نوع تراکنش (دریافت/پرداخت) نامعتبر است.'], 400);
                return;
            }
            if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
                send_json(['error' => 'مبلغ باید یک عدد مثبت باشد.'], 400);
                return;
            }
            if (empty($data['date'])) {
                send_json(['error' => 'تاریخ تراکنش الزامی است.'], 400);
                return;
            }
            // Validate date format
            if (!validate_date_format($data['date'])) {
                send_json(['error' => 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
                return;
            }
            // Validate description length
            if (!empty($data['description'])) {
                $descValidation = validate_description($data['description'], 500);
                if (!$descValidation['valid']) {
                    send_json(['error' => $descValidation['error']], 400);
                    return;
                }
                $data['description'] = $descValidation['sanitized'];
            }
            if ($data['type'] === 'cash' && (empty($data['account_id']) || !is_numeric($data['account_id']))) {
                send_json(['error' => 'برای پرداخت نقدی، انتخاب حساب الزامی است.'], 400);
                return;
            }
            if ($data['type'] === 'check' && empty($data['checkDetails'])) {
                send_json(['error' => 'جزئیات چک برای این نوع پرداخت الزامی است.'], 400);
                return;
            }
             if ($data['type'] === 'endorse_check' && (empty($data['checkId']) || !is_numeric($data['checkId']))) {
                send_json(['error' => 'انتخاب چک برای خرج کردن الزامی است.'], 400);
                return;
            }
            
            $result = $this->paymentModel->save($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'SAVE_PAYMENT', "تراکنش با شناسه {$result['id']} ثبت شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            send_json(['error' => 'خطای سرور هنگام ذخیره پرداخت: ' . $e->getMessage()], $statusCode);
        }
    }

    public function getPersonAdvancePayments($data) {
        try {
            $personId = isset($data['person_id']) ? intval($data['person_id']) : 0;
            $personType = $data['person_type'] ?? '';
            $limit = isset($data['limit']) ? intval($data['limit']) : 10;
            $result = $this->paymentModel->getAdvancePayments($personId, $personType, $limit);
            if (!empty($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            send_json(['error' => 'خطا در دریافت علی‌الحساب: ' . $e->getMessage()], $statusCode);
        }
    }

    public function attachAdvancePayments($data) {
        try {
            $invoiceId = isset($data['invoice_id']) ? intval($data['invoice_id']) : 0;
            $invoiceType = $data['invoice_type'] ?? '';
            $paymentIds = $data['payment_ids'] ?? [];
            if (!is_array($paymentIds) || empty($paymentIds)) {
                send_json(['error' => 'هیچ تراکنشی انتخاب نشده است.'], 400);
                return;
            }
            if ($invoiceId <= 0 || !in_array($invoiceType, ['sales', 'purchase'], true)) {
                send_json(['error' => 'فاکتور نامعتبر است.'], 400);
                return;
            }

            $attached = [];
            $this->conn->begin_transaction();
            try {
                foreach ($paymentIds as $pid) {
                    $res = $this->paymentModel->attachToInvoice(intval($pid), $invoiceId, $invoiceType, false);
                    if (!empty($res['error'])) {
                        throw new Exception($res['error'], $res['statusCode'] ?? 400);
                    }
                    $attached[] = $res;
                }
                $this->conn->commit();
            } catch (Exception $e) {
                $this->conn->rollback();
                throw $e;
            }

            log_activity(
                $this->conn,
                'ATTACH_ADVANCE_PAYMENT',
                'اتصال ' . count($attached) . " تراکنش علی‌الحساب به فاکتور {$invoiceType} #{$invoiceId}"
            );
            send_json(['success' => true, 'attached' => $attached]);
        } catch (Exception $e) {
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            send_json(['error' => 'خطا در اتصال علی‌الحساب: ' . $e->getMessage()], $statusCode);
        }
    }

    public function deletePayment($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            if (empty($id) || !is_numeric($id)) {
                send_json(['error' => 'شناسه پرداخت نامعتبر است.'], 400);
                return;
            }
            $result = $this->paymentModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_PAYMENT', "پرداخت با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف پرداخت: ' . $e->getMessage()], 500);
        }
    }

    public function getOpenPurchaseInvoices($data) {
        try {
            $supplierId = isset($data['supplier_id']) ? intval($data['supplier_id']) : 0;
            $limit = isset($data['limit']) ? intval($data['limit']) : 30;
            $result = $this->paymentModel->getOpenPurchaseInvoices($supplierId, $limit);
            if (!empty($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            send_json(['error' => 'خطا در دریافت فاکتورهای خرید باز: ' . $e->getMessage()], $statusCode);
        }
    }
}
