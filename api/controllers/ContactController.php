<?php
// /api/controllers/ContactController.php

require_once __DIR__ . '/../models/Contact.php';

class ContactController {
    private $conn;
    private $contactModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->contactModel = new Contact($db);
    }

    public function save($data) {
        try {
            if (empty(trim($data['name'] ?? ''))) {
                send_json(['error' => 'نام شخص الزامی است.'], 400);
                return;
            }

            $result = $this->contactModel->save($data);
            if (isset($result['success'])) {
                $is_new = empty($data['id']);
                $log_description = "سایر شخص «{$data['name']}» با شناسه {$result['id']} " . ($is_new ? 'ایجاد شد.' : 'ویرایش شد.');
                log_activity($this->conn, 'SAVE_CONTACT', $log_description);
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره شخص: ' . $e->getMessage()], 500);
        }
    }

    public function delete($data) {
        try {
            $id = $data['id'] ?? null;
            if (empty($id) || !is_numeric($id)) {
                send_json(['error' => 'شناسه نامعتبر است.'], 400);
                return;
            }

            $result = $this->contactModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_CONTACT', "سایر شخص با شناسه {$id} حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف شخص: ' . $e->getMessage()], 500);
        }
    }
}
