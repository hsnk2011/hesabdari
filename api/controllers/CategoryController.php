<?php
// /api/controllers/CategoryController.php

require_once __DIR__ . '/../models/Category.php';

class CategoryController {
    private $conn;
    private $categoryModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->categoryModel = new Category($db);
    }

    /**
     * دریافت ساختار درختی دسته‌بندی‌ها
     */
    public function getTree($data) {
        check_permission('manage_products');
        $tree = $this->categoryModel->getTree();
        send_json(['categories' => $tree]);
    }

    /**
     * دریافت لیست مسطح دسته‌بندی‌ها
     */
    public function getList($data) {
        check_permission('manage_products');
        $list = $this->categoryModel->getFlatList();
        send_json(['categories' => $list]);
    }

    /**
     * ذخیره دسته‌بندی
     */
    public function save($data) {
        check_permission('manage_products');
        
        if (empty(trim($data['name'] ?? ''))) {
            send_json(['error' => 'نام دسته‌بندی الزامی است.'], 400);
            return;
        }
        
        $result = $this->categoryModel->save($data);
        if (isset($result['success'])) {
            $is_new = empty($data['id']);
            $log_description = "دسته‌بندی «{$data['name']}» " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, 'SAVE_CATEGORY', $log_description);
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * حذف دسته‌بندی
     */
    public function delete($data) {
        check_permission('manage_products');
        
        $id = $data['id'] ?? null;
        if (empty($id) || !is_numeric($id)) {
            send_json(['error' => 'شناسه نامعتبر است.'], 400);
            return;
        }
        
        $result = $this->categoryModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_CATEGORY', "دسته‌بندی با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * دریافت مسیر دسته‌بندی
     */
    public function getPath($data) {
        check_permission('manage_products');
        
        $id = $data['id'] ?? null;
        if (empty($id)) {
            send_json(['error' => 'شناسه الزامی است.'], 400);
            return;
        }
        
        $path = $this->categoryModel->getPath($id);
        send_json(['path' => $path]);
    }
}
