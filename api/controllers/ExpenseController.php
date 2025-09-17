<?php
// /api/controllers/ExpenseController.php

require_once __DIR__ . '/../models/Expense.php';

class ExpenseController {
    private $conn;
    private $expenseModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->expenseModel = new Expense($db);
    }

    public function save($data) {
        // Server-side validation
        if (empty($data['category'])) {
            send_json(['error' => 'دسته‌بندی هزینه الزامی است.'], 400);
            return;
        }
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            send_json(['error' => 'مبلغ هزینه باید یک عدد مثبت باشد.'], 400);
            return;
        }
        if (empty($data['date'])) {
            send_json(['error' => 'تاریخ هزینه الزامی است.'], 400);
            return;
        }
        if (empty($data['account_id'])) {
            send_json(['error' => 'حساب پرداخت هزینه الزامی است.'], 400);
            return;
        }
        
        $result = $this->expenseModel->save($data);
        if (isset($result['success'])) {
            $is_new = empty($data['id']);
            $log_description = "هزینه «{$data['category']}» به مبلغ {$data['amount']} با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, 'SAVE_EXPENSE', $log_description);
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function delete($data) {
        $id = $data['id'] ?? null;
        if (empty($id) || !is_numeric($id)) {
            send_json(['error' => 'شناسه نامعتبر است.'], 400);
            return;
        }
        
        $result = $this->expenseModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_EXPENSE', "هزینه با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}