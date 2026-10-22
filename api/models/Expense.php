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
        $entity_id = $_SESSION['current_entity_id'];
        assert_transaction_date_editable($this->conn, $entity_id, $data['date']);
        $id = $data['id'] ?? null;
        if ($id) {
            $oldDateStmt = $this->conn->prepare("SELECT date FROM expenses WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $id, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }

        $this->conn->begin_transaction();
        try {
            // Client sends locale-formatted amounts ("1,000,000"); floatval alone would truncate.
            $amount = floatval(str_replace(',', '', $data['amount'] ?? 0));
            if ($amount <= 0) {
                throw new Exception('مبلغ هزینه باید بیشتر از صفر باشد.', 400);
            }
            $accountId = intval($data['account_id'] ?? 0);
            $accountModel = new Account($this->conn);
            if ($accountId <= 0 || !$accountModel->getAccountForEntity($accountId, $entity_id)) {
                throw new Exception('حساب پرداخت هزینه نامعتبر است یا متعلق به این مجموعه نیست.', 400);
            }

            $personType = trim((string)($data['person_type'] ?? ''));
            $personId = intval($data['person_id'] ?? 0);
            if ($personType === '' || $personId <= 0) {
                $personType = null;
                $personId = null;
            } else {
                $allowed = ['customer', 'supplier', 'partner', 'contact'];
                if (!in_array($personType, $allowed, true)) {
                    throw new Exception('نوع شخص هزینه نامعتبر است.', 400);
                }
                $map = [
                    'customer' => 'customers',
                    'supplier' => 'suppliers',
                    'partner' => 'partners',
                    'contact' => 'contacts',
                ];
                $table = $map[$personType];
                $own = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ? LIMIT 1");
                $own->bind_param('ii', $personId, $entity_id);
                $own->execute();
                if (empty(db_stmt_to_assoc_array($own))) {
                    throw new Exception('شخص انتخاب‌شده برای هزینه یافت نشد.', 404);
                }
            }

            if ($id) {
                // Find the old expense to revert its financial effect (scoped to current entity)
                $oldExpenseStmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
                $oldExpenseStmt->bind_param("ii", $id, $entity_id);
                $oldExpenseStmt->execute();
                $old_res = db_stmt_to_assoc_array($oldExpenseStmt);
                $oldExpense = $old_res[0] ?? null;

                if (!$oldExpense) {
                    // Unknown id: deducting below would create a balance change
                    // with no expense record behind it.
                    throw new Exception('هزینه مورد نظر یافت نشد.', 404);
                }

                // Add the old amount back to the old account (wallet)
                $accountModel->updateBalance($oldExpense['account_id'], $oldExpense['amount'], $entity_id);

                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET category=?, date=?, amount=?, description=?, account_id=?, person_id=?, person_type=? WHERE id=? AND entity_id=?");
                $stmt->bind_param("ssdsiisis", $data['category'], $data['date'], $amount, $data['description'], $accountId, $personId, $personType, $id, $entity_id);
            } else {
                $entity_id = $_SESSION['current_entity_id'];
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, category, date, amount, description, account_id, person_id, person_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdsiis", $entity_id, $data['category'], $data['date'], $amount, $data['description'], $accountId, $personId, $personType);
            }
            
            $stmt->execute();
            $expenseId = $id ?? $this->conn->insert_id;
            $stmt->close();

            // میز کار: optional project (budget). Only when the form sends the field, so
            // other callers (recurring expenses, imports) never clear an existing link.
            if (array_key_exists('project_id', $data)) {
                $projectId = (int)($data['project_id'] ?? 0) ?: null;
                if ($projectId !== null) {
                    $own = $this->conn->prepare("SELECT id FROM projects WHERE id = ? AND entity_id = ? LIMIT 1");
                    $own->bind_param('ii', $projectId, $entity_id);
                    $own->execute();
                    if (empty(db_stmt_to_assoc_array($own))) {
                        $projectId = null;
                    }
                }
                $link = $this->conn->prepare("UPDATE `{$this->tableName}` SET project_id = ? WHERE id = ? AND entity_id = ?");
                $link->bind_param('iii', $projectId, $expenseId, $entity_id);
                $link->execute();
            }

            // Wallet: expense always reduces the paying account (including personal)
            $accountModel->updateBalance($accountId, -$amount, $entity_id);

            $this->conn->commit();
            return ['success' => true, 'id' => $expenseId];

        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            return ['error' => 'خطا در ذخیره هزینه: ' . $e->getMessage(), 'statusCode' => ($code >= 400 && $code < 600) ? $code : 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        if (!$id) {
             return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        $this->conn->begin_transaction();
        try {
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("SELECT amount, account_id, date FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ii", $id, $entity_id);
            $stmt->execute();
            $res = db_stmt_to_assoc_array($stmt);
            $expense = $res[0] ?? null;

            if (!$expense) {
                throw new Exception("هزینه مورد نظر یافت نشد.", 404);
            }

            assert_transaction_date_editable($this->conn, $entity_id, $expense['date']);

            // Add the amount back to the account (wallet)
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