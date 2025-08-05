<?php
// /api/models/Account.php
require_once __DIR__ . '/BaseModel.php';

class Account extends BaseModel {
    protected $tableName = 'accounts';
    protected $allowedFilters = ['name', 'bank_name', 'account_number'];
    protected $allowedSorts = ['id', 'name', 'bank_name', 'current_balance'];

    /**
     * Saves an account's data (creates or updates).
     * @param array $data The account data.
     * @return array Result of the operation.
     */
    public function save($data) {
        if (empty($data['name'])) {
            return ['error' => 'نام حساب الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        $name = $data['name'];
        $bank_name = $data['bank_name'] ?? null;
        $account_number = $data['account_number'] ?? null;
        $card_number = $data['card_number'] ?? null;
        $is_cash = isset($data['is_cash']) ? (int)$data['is_cash'] : 0;
        
        // Prevent editing initial balance directly via save. Balance is updated via transactions.
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, bank_name = ?, account_number = ?, card_number = ?, is_cash = ? WHERE id = ?");
            $stmt->bind_param("ssssii", $name, $bank_name, $account_number, $card_number, $is_cash, $id);
        } else {
            $initial_balance = $data['current_balance'] ?? 0.00;
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (name, bank_name, account_number, card_number, current_balance, is_cash) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssdi", $name, $bank_name, $account_number, $card_number, $initial_balance, $is_cash);
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

    /**
     * Updates an account's balance by a given amount (positive for deposit, negative for withdrawal).
     * @param int $accountId The ID of the account.
     * @param float $amount The amount to add or subtract.
     * @return bool True on success, false on failure.
     */
    public function updateBalance($accountId, $amount) {
        if (empty($accountId) || !is_numeric($amount)) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET current_balance = current_balance + ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $accountId);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Fetches a complete list of all accounts.
     * @return array List of accounts.
     */
    public function getAll() {
    $result = $this->conn->query("SELECT * FROM `{$this->tableName}` ORDER BY is_cash DESC, name ASC");
    return $result->fetch_all(MYSQLI_ASSOC);
}
}