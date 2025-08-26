<?php
// /api/models/Expense.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Expense extends BaseModel {
    protected $tableName = 'expenses';

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'e'; // Set table alias
        
        // Configure the select and join clauses for the paginated query in BaseModel
        $this->select = "SELECT e.*, a.name as accountName";
        $this->from = "FROM `{$this->tableName}` as e";
        $this->join = "LEFT JOIN accounts a ON e.account_id = a.id";
        
        // Define allowed filters and sorts using aliases
        $this->allowedFilters = ['e.category', 'e.description', 'e.amount', 'a.name'];
        $this->allowedSorts = ['id', 'date', 'category', 'amount'];
    }

    // The getPaginated method is now inherited from BaseModel and works with the configuration above.

    public function save($data) {
        if (empty($data['category']) || !isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0 || empty($data['date']) || empty($data['account_id'])) {
            return ['error' => 'لطفاً تمام فیلدهای الزامی هزینه (دسته‌بندی، مبلغ، تاریخ و حساب پرداخت) را به درستی وارد کنید.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);

        try {
            $id = $data['id'] ?? null;
            $amount = $data['amount'];
            $accountId = $data['account_id'];
            $oldExpense = null;

            if ($id) {
                $oldExpenseStmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
                $oldExpenseStmt->bind_param("i", $id);
                $oldExpenseStmt->execute();
                $oldExpenseStmt->store_result();
                if ($oldExpenseStmt->num_rows > 0) {
                    $old_amount = 0; $old_account_id = 0;
                    $oldExpenseStmt->bind_result($old_amount, $old_account_id);
                    $oldExpenseStmt->fetch();
                    $oldExpense = ['amount' => $old_amount, 'account_id' => $old_account_id];
                }
                $oldExpenseStmt->close();

                // Revert the old transaction amount from the old account
                if ($oldExpense) {
                    $accountModel->updateBalance($oldExpense['account_id'], $oldExpense['amount']);
                }
                
                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET category=?, date=?, amount=?, description=?, account_id=? WHERE id=?");
                $stmt->bind_param("ssdsii", $data['category'], $data['date'], $amount, $data['description'], $accountId, $id);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (category, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsi", $data['category'], $data['date'], $amount, $data['description'], $accountId);
            }
            
            $stmt->execute();
            $expenseId = $id ?? $this->conn->insert_id;
            $stmt->close();

            // Apply the new transaction amount to the new/current account
            $accountModel->updateBalance($accountId, -$amount);

            $this->conn->commit();
            return ['success' => true, 'id' => $expenseId];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        if (!$id) {
             return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $stmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->store_result();
            $expense = null;
            if ($stmt->num_rows > 0) {
                $amount = 0; $account_id = 0;
                $stmt->bind_result($amount, $account_id);
                $stmt->fetch();
                $expense = ['amount' => $amount, 'account_id' => $account_id];
            }
            $stmt->close();

            if ($expense) {
                // Add the amount back to the account
                $accountModel->updateBalance($expense['account_id'], $expense['amount']);
            }
            
            // Use the parent's delete method to remove the record
            parent::delete($id);

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}