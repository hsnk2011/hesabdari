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
        // NEW: Server-side validation
        if (empty($data['type']) || !in_array($data['type'], ['cash', 'check', 'endorse_check'])) {
            send_json(['error' => 'نوع پرداخت نامعتبر است.'], 400);
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
        if ($data['type'] === 'cash' && empty($data['account_id'])) {
            send_json(['error' => 'برای پرداخت نقدی، انتخاب حساب الزامی است.'], 400);
            return;
        }
        // END NEW
        
        $result = $this->paymentModel->save($data);
        if (isset($result['success'])) {
            log_activity($this->conn, 'SAVE_PAYMENT', "تراکنش با شناسه {$result['id']} ثبت شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function deletePayment($data) {
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
    }
}