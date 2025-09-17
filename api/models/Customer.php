<?php
// /api/models/Customer.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/traits/PersonBalanceTrait.php';

class Customer extends BaseModel {
    use PersonBalanceTrait; // Use the refactored trait

    protected $tableName = 'customers';
    protected $allowedFilters = ['name', 'phone', 'nationalId', 'address'];
    protected $allowedSorts = ['id', 'name', 'phone', 'nationalId'];

    public function getPaginated($input) {
        $paginatedResult = parent::getPaginated($input);
        
        if (empty($paginatedResult['data'])) {
            return $paginatedResult;
        }
        
        // Use the trait method to attach balance data
        $customersWithBalance = $this->attachBalanceDataToPersons($paginatedResult['data'], 'customer');
        
        $paginatedResult['data'] = $customersWithBalance;
        return $paginatedResult;
    }

    public function save($data) {
        // Validation is now handled in CustomerController
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
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, address, phone, nationalId, initial_balance) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssd", $entity_id, $name, $address, $phone, $nationalId, $initial_balance);
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