<?php
// /api/models/Check.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Check extends BaseModel {
    protected $tableName = 'checks';
    protected $allowedFilters = ['c.checkNumber', 'c.bankName', 'c.amount', 'p.description'];
    protected $allowedSorts = ['id', 'dueDate', 'amount', 'status', 'type', 'bankName'];

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'c';
        $this->select = "SELECT c.*, p.date, p.description";
        $this->from = "FROM `{$this->tableName}` as c";
        $this->join = "LEFT JOIN `payments` p ON c.id = p.checkId";
    }

    public function save($data) {
        // Validation is now handled in CheckController
        $id = $data['id'] ?? null;
        $this->conn->begin_transaction();
        try {
            $stmt_check = $this->conn->prepare("SELECT status FROM `{$this->tableName}` WHERE id = ?");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $check = db_stmt_to_assoc_array($stmt_check)[0] ?? null;

            if (!$check) {
                throw new Exception("چک مورد نظر یافت نشد.", 404);
            }
            if (!in_array($check['status'], ['in_hand', 'payable'])) {
                throw new Exception("این چک قابل ویرایش نیست زیرا پردازش شده است.", 403);
            }
            
            $stmt_update = $this->conn->prepare("UPDATE `{$this->tableName}` SET checkNumber = ?, dueDate = ?, bankName = ?, amount = ? WHERE id = ?");
            $stmt_update->bind_param("sssdi", $data['checkNumber'], $data['dueDate'], $data['bankName'], $data['amount'], $id);
            $stmt_update->execute();
            $stmt_update->close();

            $stmt_payment = $this->conn->prepare("UPDATE `payments` SET amount = ?, description = ? WHERE checkId = ?");
            $stmt_payment->bind_param("dsi", $data['amount'], $data['description'], $id);
            $stmt_payment->execute();
            $stmt_payment->close();

            $this->conn->commit();
            return ['success' => true, 'id' => $id];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در ویرایش چک: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    public function delete($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $stmt_check = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ?");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $check = db_stmt_to_assoc_array($stmt_check)[0] ?? null;

            if (!$check) {
                throw new Exception("چک مورد نظر یافت نشد.", 404);
            }
            if ($check['status'] === 'endorsed') {
                throw new Exception("این چک قابل حذف نیست زیرا خرج شده (واگذار شده) است.", 400);
            }

            if ($check['status'] === 'cashed' && !empty($check['cashed_in_account_id'])) {
                $accountModel = new Account($this->conn);
                $amountToRevert = ($check['type'] === 'received') ? -$check['amount'] : $check['amount'];
                $accountModel->updateBalance($check['cashed_in_account_id'], $amountToRevert);
            }

            $stmt_payment = $this->conn->prepare("DELETE FROM payments WHERE checkId = ?");
            $stmt_payment->bind_param("i", $id);
            $stmt_payment->execute();
            $stmt_payment->close();

            $delete_result = parent::delete($id);
            if (isset($delete_result['error'])) {
                 throw new Exception($delete_result['error']);
            }

            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در حذف چک: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    public function cashCheck($checkId, $accountId) {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT amount, type, status FROM `{$this->tableName}` WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $checkId);
            $stmt->execute();
            $check = db_stmt_to_assoc_array($stmt)[0] ?? null;

            if (!$check) {
                throw new Exception('چک یافت نشد.', 404);
            }
            if ($check['type'] !== 'received' || $check['status'] !== 'in_hand') {
                throw new Exception('این چک قابل وصول نیست.', 400);
            }

            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $accountId, $checkId);
            $updateStmt->execute();
            $updateStmt->close();

            $accountModel = new Account($this->conn);
            $accountModel->updateBalance($accountId, $check['amount']);
            
            $this->conn->commit();
            log_activity($this->conn, 'CASH_CHECK', "چک به مبلغ {$check['amount']} در حساب {$accountId} وصول شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    public function clearPayableCheck($checkId, $accountId) {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT amount, type, status, checkNumber FROM `{$this->tableName}` WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $checkId);
            $stmt->execute();
            $check = db_stmt_to_assoc_array($stmt)[0] ?? null;

            if (!$check) {
                throw new Exception('چک یافت نشد.', 404);
            }
            if ($check['type'] !== 'payable' || $check['status'] !== 'payable') {
                throw new Exception('این چک قابل پاس شدن نیست.', 400);
            }

            // Step 1: Update the check status to 'cashed' and link to the bank account
            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $accountId, $checkId);
            $updateStmt->execute();
            $updateStmt->close();

            // Step 2: Debit the bank account
            $accountModel = new Account($this->conn);
            $accountModel->updateBalance($accountId, -$check['amount']);
            
            // **FIX**: The logic that created a new conflicting payment record is REMOVED.
            // The reports are now smart enough to interpret this status change correctly.
            
            $this->conn->commit();
            log_activity($this->conn, 'CLEAR_CHECK', "چک پرداختی به مبلغ {$check['amount']} از حساب {$accountId} پاس شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
}