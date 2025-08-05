<?php
// /api/models/Customer.php
require_once __DIR__ . '/BaseModel.php';

class Customer extends BaseModel {
    protected $tableName = 'customers';
    protected $allowedFilters = ['name', 'phone', 'nationalId', 'address'];
    protected $allowedSorts = ['id', 'name', 'phone', 'nationalId'];

    public function save($data) {
        if (empty($data['name']) || empty($data['nationalId'])) {
            return ['error' => 'نام مشتری و کد ملی الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $address = $data['address'] ?? '';
        $phone = $data['phone'] ?? '';
        $nationalId = $data['nationalId'];
        $initial_balance = $data['initial_balance'] ?? 0.00;

        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, address = ?, phone = ?, nationalId = ?, initial_balance = ? WHERE id = ?");
            $stmt->bind_param("ssssdi", $name, $address, $phone, $nationalId, $initial_balance, $id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (name, address, phone, nationalId, initial_balance) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssd", $name, $address, $phone, $nationalId, $initial_balance);
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