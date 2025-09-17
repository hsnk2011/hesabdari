<?php
// /api/controllers/CheckController.php

require_once __DIR__ . '/../models/Check.php';
class CheckController {
    private $conn;
    private $checkModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->checkModel = new Check($db);
    }

    public function cash($data) {
        $checkId = $data['checkId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        if (empty($checkId) || empty($accountId)) {
            send_json(['error' => 'شناسه چک و حساب مقصد الزامی است.'], 400);
            return;
        }
        $result = $this->checkModel->cashCheck($checkId, $accountId);
        
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function clearPayable($data) {
        $checkId = $data['checkId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        if (empty($checkId) || empty($accountId)) {
            send_json(['error' => 'شناسه چک و حساب مبدا الزامی است.'], 400);
            return;
        }
        $result = $this->checkModel->clearPayableCheck($checkId, $accountId);
        
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function delete($data) {
        $id = $data['id'] ?? null;
        if (empty($id) || !is_numeric($id)) {
            send_json(['error' => 'شناسه چک نامعتبر است.'], 400);
            return;
        }
        $result = $this->checkModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_CHECK', "چک با شناسه {$id} و تراکنش مالی مرتبط با آن حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles saving (updating) a check's details.
     * @param array $data The check data from the client.
     */
    public function save($data) {
        // Server-side validation
        if (empty($data['id'])) {
            send_json(['error' => 'شناسه چک برای ویرایش الزامی است.'], 400);
            return;
        }
        if (empty($data['checkNumber']) || empty($data['dueDate']) || empty($data['bankName'])) {
            send_json(['error' => 'شماره چک، تاریخ سررسید و نام بانک الزامی است.'], 400);
            return;
        }
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            send_json(['error' => 'مبلغ چک باید یک عدد مثبت باشد.'], 400);
            return;
        }
        
        $result = $this->checkModel->save($data);
        if (isset($result['success'])) {
            log_activity($this->conn, 'SAVE_CHECK', "چک «{$data['checkNumber']}» با شناسه {$result['id']} ویرایش شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}