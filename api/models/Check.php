<?php
// /api/models/Check.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Check extends BaseModel {
    protected $tableName = 'checks';
    protected $allowedFilters = ['checkNumber', 'bankName', 'amount'];
    protected $allowedSorts = ['id', 'dueDate', 'amount', 'status', 'type', 'bankName'];

    public function cashCheck($checkId, $accountId) {
        if (empty($checkId) || empty($accountId)) {
            return ['error' => 'شناسه چک و حساب مقصد الزامی است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $stmt = $this->conn->prepare("SELECT amount, type, status FROM `{$this->tableName}` WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $checkId);
            $stmt->execute();
            $stmt->store_result();
            $check = null;
            if ($stmt->num_rows > 0) {
                $amount = null; $type = null; $status = null;
                $stmt->bind_result($amount, $type, $status);
                $stmt->fetch();
                $check = ['amount' => $amount, 'type' => $type, 'status' => $status];
            }
            $stmt->close();

            if (!$check) {
                throw new Exception('چک یافت نشد.', 404);
            }
            if ($check['type'] !== 'received' || $check['status'] !== 'in_hand') {
                throw new Exception('این چک قابل وصول نیست. (فقط چک‌های دریافتی نزد ما قابل وصول هستند)', 400);
            }

            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $accountId, $checkId);
            $updateStmt->execute();
            $updateStmt->close();

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

    /**
     * Marks a payable check as cleared and deducts from an account.
     */
    public function clearPayableCheck($checkId, $accountId) {
        if (empty($checkId) || empty($accountId)) {
            return ['error' => 'شناسه چک و حساب مبدا الزامی است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $stmt = $this->conn->prepare("SELECT amount, type, status FROM `{$this->tableName}` WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $checkId);
            $stmt->execute();
            $stmt->store_result();
            $check = null;
            if ($stmt->num_rows > 0) {
                $amount = null; $type = null; $status = null;
                $stmt->bind_result($amount, $type, $status);
                $stmt->fetch();
                $check = ['amount' => $amount, 'type' => $type, 'status' => $status];
            }
            $stmt->close();

            if (!$check) {
                throw new Exception('چک یافت نشد.', 404);
            }
            if ($check['type'] !== 'payable' || $check['status'] !== 'payable') {
                throw new Exception('این چک قابل پاس شدن نیست. (فقط چک‌های پرداختنی در جریان قابل پاس شدن هستند)', 400);
            }

            // Using 'cashed' status for cleared checks as well. Update account it was paid from.
            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $accountId, $checkId);
            $updateStmt->execute();
            $updateStmt->close();

            // Deduct the amount from the specified account
            $accountModel->updateBalance($accountId, -$check['amount']);
            
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