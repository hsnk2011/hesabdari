<?php
// /api/controllers/SupplierController.php

require_once __DIR__ . '/../models/Supplier.php';

class SupplierController {
    private $conn;
    private $supplierModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->supplierModel = new Supplier($db);
    }

    public function save($data) {
        // Server-side validation
        if (empty(trim($data['name']))) {
            send_json(['error' => 'نام تأمین‌کننده الزامی است.'], 400);
            return;
        }
        if (empty(trim($data['economicCode']))) {
            send_json(['error' => 'کد اقتصادی تأمین‌کننده الزامی است.'], 400);
            return;
        }
        
        $result = $this->supplierModel->save($data);
        if (isset($result['success'])) {
            $is_new = empty($data['id']);
            $log_description = "تأمین‌کننده «{$data['name']}» با شناسه {$result['id']} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, 'SAVE_SUPPLIER', $log_description);
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

        $result = $this->supplierModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_SUPPLIER', "تأمین‌کننده با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}