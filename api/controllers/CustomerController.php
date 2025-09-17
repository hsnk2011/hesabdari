<?php
// /api/controllers/CustomerController.php

require_once __DIR__ . '/../models/Customer.php';

class CustomerController {
    private $conn;
    private $customerModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->customerModel = new Customer($db);
    }

    public function save($data) {
        // Server-side validation
        if (empty(trim($data['name']))) {
            send_json(['error' => 'نام مشتری الزامی است.'], 400);
            return;
        }
        if (empty(trim($data['nationalId']))) {
            send_json(['error' => 'کد ملی مشتری الزامی است.'], 400);
            return;
        }
        
        $result = $this->customerModel->save($data);
        if (isset($result['success'])) {
            $is_new = empty($data['id']);
            $log_description = "مشتری «{$data['name']}» با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, 'SAVE_CUSTOMER', $log_description);
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

        $result = $this->customerModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_CUSTOMER', "مشتری با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}