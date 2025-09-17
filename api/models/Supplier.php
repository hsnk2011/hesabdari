<?php
// /api/models/Supplier.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/traits/PersonBalanceTrait.php';

class Supplier extends BaseModel {
    use PersonBalanceTrait; // Use the refactored trait

    protected $tableName = 'suppliers';
    protected $allowedFilters = ['name', 'phone', 'economicCode'];
    protected $allowedSorts = ['id', 'name', 'phone', 'economicCode'];

    public function getPaginated($input) {
        $paginatedResult = parent::getPaginated($input);
        
        if (empty($paginatedResult['data'])) {
            return $paginatedResult;
        }

        // Use the trait method to attach balance data
        $suppliersWithBalance = $this->attachBalanceDataToPersons($paginatedResult['data'], 'supplier');

        $paginatedResult['data'] = $suppliersWithBalance;
        return $paginatedResult;
    }

    public function save($data) {
        // Validation is now handled in SupplierController
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
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, address, phone, economicCode, initial_balance) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssd", $entity_id, $name, $address, $phone, $economicCode, $initial_balance);
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