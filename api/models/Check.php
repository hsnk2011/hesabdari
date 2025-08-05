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
            // --- COMPATIBILITY FIX: Replaced get_result() with bind_result() ---
            $stmt = $this->conn->prepare("SELECT amount, type, status FROM `{$this->tableName}` WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $checkId);
            $stmt->execute();
            $stmt->store_result();

            $check = null;
            if ($stmt->num_rows > 0) {
                $amount = null;
                $type = null;
                $status = null;
                $stmt->bind_result($amount, $type, $status);
                $stmt->fetch();
                $check = ['amount' => $amount, 'type' => $type, 'status' => $status];
            }
            $stmt->close();
            // --- END FIX ---

            if (!$check) {
                $this->conn->rollback();
                return ['error' => 'چک یافت نشد.', 'statusCode' => 404];
            }
            if ($check['type'] !== 'received' || $check['status'] !== 'in_hand') {
                $this->conn->rollback();
                return ['error' => 'این چک قابل وصول نیست. (فقط چک‌های دریافتی نزد ما قابل وصول هستند)', 'statusCode' => 400];
            }

            // Update check status
            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $accountId, $checkId);
            $updateStmt->execute();
            $updateStmt->close();

            // Update account balance
            $accountModel->updateBalance($accountId, $check['amount']);
            
            $this->conn->commit();
            log_activity($this->conn, 'CASH_CHECK', "چک به مبلغ {$check['amount']} در حساب {$accountId} وصول شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}