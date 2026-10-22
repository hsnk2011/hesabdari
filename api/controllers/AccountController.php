<?php
// /api/controllers/AccountController.php

require_once __DIR__ . '/../models/Account.php';

class AccountController {
    private $conn;
    private $accountModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->accountModel = new Account($db);
    }

    public function getPaginated($data) {
        // Permission check is now handled by the router
        try {
            $result = $this->accountModel->getPaginated($data);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت لیست حساب‌ها: ' . $e->getMessage()], 500);
        }
    }

    public function getFullList() {
        // Permission check is now handled by the router
        try {
            $result = $this->accountModel->getAll();
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت لیست کامل حساب‌ها: ' . $e->getMessage()], 500);
        }
    }

    public function save($data) {
        // Permission check is handled by the router
        try {
            if (empty(trim($data['name']))) {
                send_json(['error' => 'نام حساب الزامی است.'], 400);
                return;
            }

            $result = $this->accountModel->save($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'SAVE_ACCOUNT', "حساب «{$data['name']}» ذخیره شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره حساب: ' . $e->getMessage()], 500);
        }
    }

    public function delete($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            if (empty($id) || !is_numeric($id)) {
                send_json(['error' => 'شناسه حساب نامعتبر است.'], 400);
                return;
            }

            $result = $this->accountModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_ACCOUNT', "حساب با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف حساب: ' . $e->getMessage()], 500);
        }
    }

    public function getAccountTransactions($data) {
        // Permission check is handled by the router
        try {
            $accountId = $data['accountId'] ?? null;
            if (empty($accountId) || !is_numeric($accountId)) {
                send_json(['error' => 'شناسه حساب الزامی است.'], 400);
                return;
            }

            $result = $this->accountModel->getLedger($accountId);

            if (isset($result['error'])) {
                send_json($result, $result['statusCode'] ?? 404);
            } else {
                send_json($result);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت گردش حساب: ' . $e->getMessage()], 500);
        }
    }

    public function transferMoney($data) {
        // Permission check is handled by the router
        try {
            $fromAccountId = $data['fromAccountId'] ?? null;
            $toAccountId = $data['toAccountId'] ?? null;
            $amount = $data['amount'] ?? null;
            $date = $data['date'] ?? null;
            $description = $data['description'] ?? '';

            // Validation
            if (empty($fromAccountId) || !is_numeric($fromAccountId)) {
                send_json(['error' => 'حساب مبدا الزامی است.'], 400);
                return;
            }
            if (empty($toAccountId) || !is_numeric($toAccountId)) {
                send_json(['error' => 'حساب مقصد الزامی است.'], 400);
                return;
            }
            if ($fromAccountId == $toAccountId) {
                send_json(['error' => 'حساب مبدا و مقصد نمی‌توانند یکسان باشند.'], 400);
                return;
            }
            if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
                send_json(['error' => 'مبلغ باید عددی مثبت باشد.'], 400);
                return;
            }
            if (empty($date)) {
                send_json(['error' => 'تاریخ الزامی است.'], 400);
                return;
            }
            if (!validate_date_format($date)) {
                send_json(['error' => 'فرمت تاریخ نامعتبر است.'], 400);
                return;
            }

            $result = $this->accountModel->transferMoney($fromAccountId, $toAccountId, $amount, $date, $description);
            
            if (isset($result['success'])) {
                log_activity($this->conn, 'TRANSFER_MONEY', "انتقال وجه به مبلغ {$amount} تومان از حساب {$fromAccountId} به حساب {$toAccountId}");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام انتقال وجه: ' . $e->getMessage()], 500);
        }
    }

    // *** START: New function to delete a transfer ***
    public function deleteTransfer($data) {
        // Permission check is handled by the router
        try {
            $paymentId = $data['paymentId'] ?? null;
            if (empty($paymentId) || !is_numeric($paymentId)) {
                send_json(['error' => 'شناسه تراکنش نامعتبر است.'], 400);
                return;
            }

            $result = $this->accountModel->deleteTransfer($paymentId);

            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_TRANSFER', "تراکنش انتقال وجه (شناسه پرداخت {$paymentId}) حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف انتقال وجه: ' . $e->getMessage()], 500);
        }
    }
    // *** END: New function ***
}
