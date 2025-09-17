<?php
// /api/models/Expense.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Expense extends BaseModel {
    protected $tableName = 'expenses';

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'e'; // Set table alias
        
        $this->select = "SELECT e.*, a.name as accountName";
        $this->from = "FROM `{$this->tableName}` as e";
        $this->join = "LEFT JOIN accounts a ON e.account_id = a.id";
        
        $this->allowedFilters = ['e.category', 'e.description', 'e.amount', 'a.name'];
        $this->allowedSorts = ['id', 'date', 'category', 'amount'];
    }

    public function save($data) {
        // Validation is now handled in ExpenseController
        $this->conn->begin_transaction();
        try {
            $id = $data['id'] ?? null;
            $amount = $data['amount'];
            $accountId = $data['account_id'];
            $accountModel = new Account($this->conn);

            if ($id) {
                // Find the old expense to revert its financial effect
                $oldExpenseStmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
                $oldExpenseStmt->bind_param("i", $id);
                $oldExpenseStmt->execute();
                $old_res = db_stmt_to_assoc_array($oldExpenseStmt);
                $oldExpense = $old_res[0] ?? null;

                if ($oldExpense) {
                    // Add the old amount back to the old account
                    $accountModel->updateBalance($oldExpense['account_id'], $oldExpense['amount']);
                }
                
                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET category=?, date=?, amount=?, description=?, account_id=? WHERE id=?");
                $stmt->bind_param("ssdsii", $data['category'], $data['date'], $amount, $data['description'], $accountId, $id);
            } else {
                $entity_id = $_SESSION['current_entity_id'];
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, category, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssdsi", $entity_id, $data['category'], $data['date'], $amount, $data['description'], $accountId);
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
            return ['error' => 'خطا در ذخیره هزینه: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        if (!$id) {
             return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = db_stmt_to_assoc_array($stmt);
            $expense = $res[0] ?? null;

            if (!$expense) {
                throw new Exception("هزینه مورد نظر یافت نشد.", 404);
            }

            // Add the amount back to the account
            $accountModel = new Account($this->conn);
            $accountModel->updateBalance($expense['account_id'], $expense['amount']);
            
            // Use the parent's delete method to remove the record
            $deleteResult = parent::delete($id);
            if(isset($deleteResult['error'])){
                throw new Exception($deleteResult['error']);
            }

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در حذف هزینه: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
}