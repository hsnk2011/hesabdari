<?php
// /api/models/Supplier.php
require_once __DIR__ . '/BaseModel.php';

class Supplier extends BaseModel {
    protected $tableName = 'suppliers';
    protected $allowedFilters = ['name', 'phone', 'economicCode'];
    protected $allowedSorts = ['id', 'name', 'phone', 'economicCode'];

    public function save($data) {
        if (empty($data['name']) || empty($data['economicCode'])) {
            return ['error' => 'نام تأمین‌کننده و کد اقتصادی الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $address = $data['address'] ?? '';
        $phone = $data['phone'] ?? '';
        $economicCode = $data['economicCode'];
        $initial_balance = $data['initial_balance'] ?? 0.00;

        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, address = ?, phone = ?, economicCode = ?, initial_balance = ? WHERE id = ?");
            $stmt->bind_param("ssssdi", $name, $address, $phone, $economicCode, $initial_balance, $id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (name, address, phone, economicCode, initial_balance) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssd", $name, $address, $phone, $economicCode, $initial_balance);
        }

        if ($stmt->execute()) {
            $savedId = $id ?? $this->conn->insert_id;
            $stmt->close();
            return ['success' => true, 'id' => $savedId];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return ['error' => $error, 'statusCode' => 500];
        }
    }
}