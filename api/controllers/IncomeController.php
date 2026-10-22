<?php
// /api/controllers/IncomeController.php

require_once __DIR__ . '/../models/Income.php';

class IncomeController {
    private $conn;
    private $incomeModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->incomeModel = new Income($db);
    }

    public function save($data) {
        try {
            if (empty(trim($data['category'] ?? ''))) {
                send_json(['error' => 'دسته‌بندی درآمد الزامی است.'], 400);
                return;
            }
            if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
                send_json(['error' => 'مبلغ درآمد باید یک عدد مثبت باشد.'], 400);
                return;
            }
            if (empty($data['date'])) {
                send_json(['error' => 'تاریخ درآمد الزامی است.'], 400);
                return;
            }
            if (!validate_date_format($data['date'])) {
                send_json(['error' => 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
                return;
            }
            if (!empty($data['description'])) {
                $descValidation = validate_description($data['description'], 500);
                if (!$descValidation['valid']) {
                    send_json(['error' => $descValidation['error']], 400);
                    return;
                }
                $data['description'] = $descValidation['sanitized'];
            }
            if (empty($data['account_id']) || !is_numeric($data['account_id'])) {
                send_json(['error' => 'حساب دریافت درآمد الزامی است.'], 400);
                return;
            }

            $result = $this->incomeModel->save($data);
            if (isset($result['success'])) {
                $is_new = empty($data['id']);
                $log_description = "درآمد «{$data['category']}» به مبلغ {$data['amount']} با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
                log_activity($this->conn, 'SAVE_INCOME', $log_description);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره درآمد: ' . $e->getMessage()], 500);
        }
    }

    public function delete($data) {
        try {
            $id = $data['id'] ?? null;
            if (empty($id) || !is_numeric($id)) {
                send_json(['error' => 'شناسه نامعتبر است.'], 400);
                return;
            }

            $result = $this->incomeModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_INCOME', "درآمد با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف درآمد: ' . $e->getMessage()], 500);
        }
    }
}
