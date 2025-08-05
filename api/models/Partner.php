<?php
// /api/models/Partner.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Partner extends BaseModel {
    protected $tableName = 'partners';

    public function save($data) {
        if (empty($data['name']) || !isset($data['share']) || !is_numeric($data['share']) || $data['share'] < 0 || $data['share'] > 1) {
            return ['error' => 'نام شریک و سهم (عددی بین 0 و 1) الزامی است.', 'statusCode' => 400];
        }
        $id = $data['id'] ?? null;
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, share = ? WHERE id = ?");
            $stmt->bind_param("sdi", $data['name'], $data['share'], $id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (name, share) VALUES (?, ?)");
            $stmt->bind_param("sd", $data['name'], $data['share']);
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

    public function saveTransaction($data) {
        if (empty($data['partnerId']) || empty($data['type']) || !isset($data['amount']) || $data['amount'] <= 0 || empty($data['date']) || empty($data['account_id'])) {
            return ['error' => 'اطلاعات تراکنش شریک (شامل حساب بانکی) ناقص است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $partnerId = intval($data['partnerId']);
            $accountId = intval($data['account_id']);
            $amount = $data['amount'];
            $type = $data['type'];
            $id = $data['id'] ?? null;

            if ($id) { // Update existing transaction
                $oldTxStmt = $this->conn->prepare("SELECT amount, type, account_id FROM partner_transactions WHERE id = ?");
                $oldTxStmt->bind_param("i", $id);
                $oldTxStmt->execute();
                $oldTxStmt->store_result();
                $oldAmount = 0; $oldType = ''; $oldAccountId = 0;
                $oldTxStmt->bind_result($oldAmount, $oldType, $oldAccountId);
                $oldTxStmt->fetch();
                $oldTxStmt->close();

                // Revert old transaction
                $reversalAmount = ($oldType === 'DEPOSIT') ? -$oldAmount : $oldAmount;
                $accountModel->updateBalance($oldAccountId, $reversalAmount);

                $stmt = $this->conn->prepare("UPDATE partner_transactions SET partnerId=?, type=?, date=?, amount=?, description=?, account_id=? WHERE id=?");
                $stmt->bind_param("issdsii", $partnerId, $type, $data['date'], $amount, $data['description'], $accountId, $id);
                $stmt->execute();
                $stmt->close();
            } else { // Insert new transaction
                $stmt = $this->conn->prepare("INSERT INTO partner_transactions (partnerId, type, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdsi", $partnerId, $type, $data['date'], $amount, $data['description'], $accountId);
                $stmt->execute();
                $id = $this->conn->insert_id;
                $stmt->close();
            }
            
            $adjustedAmount = ($type === 'DEPOSIT') ? $amount : -$amount;
            $accountModel->updateBalance($accountId, $adjustedAmount);
            
            $partnerName = ($this->conn->query("SELECT name FROM partners WHERE id = $partnerId")->fetch_assoc()['name'] ?? 'نامشخص');
            $trans_type_text = ($type === 'DEPOSIT' ? 'واریز شریک: ' : 'برداشت شریک: ') . $partnerName;
            
            if (isset($data['id'])) {
                $this->conn->query("DELETE FROM transactions WHERE relatedObjectType = 'partner' AND relatedObjectId = ".intval($id));
            }

            $stmt_trans = $this->conn->prepare("INSERT INTO transactions (relatedObjectType, relatedObjectId, amount, date, type, description) VALUES ('partner', ?, ?, ?, ?, ?)");
            $stmt_trans->bind_param("idsss", $id, $adjustedAmount, $data['date'], $trans_type_text, $data['description']);
            $stmt_trans->execute();
            $stmt_trans->close();

            $this->conn->commit();
            return ['success' => true, 'id' => $id, 'partnerName' => $partnerName];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    public function deleteTransaction($id) {
        $id = intval($id);
        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $stmt = $this->conn->prepare("SELECT amount, type, account_id FROM partner_transactions WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->store_result();
            
            $tx = null;
            if ($stmt->num_rows > 0) {
                $amount = 0; $type = ''; $account_id = 0;
                $stmt->bind_result($amount, $type, $account_id);
                $stmt->fetch();
                $tx = ['amount' => $amount, 'type' => $type, 'account_id' => $account_id];
            }
            $stmt->close();

            if ($tx) {
                $reversalAmount = ($tx['type'] === 'DEPOSIT') ? -$tx['amount'] : $tx['amount'];
                $accountModel->updateBalance($tx['account_id'], $reversalAmount);
            }
            
            $stmt1 = $this->conn->prepare("DELETE FROM transactions WHERE relatedObjectType = 'partner' AND relatedObjectId = ?");
            $stmt1->bind_param("i", $id);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $this->conn->prepare("DELETE FROM partner_transactions WHERE id = ?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $stmt2->close();
            
            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        try {
            return parent::delete($id);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1451) {
                return ['error' => 'این شریک قابل حذف نیست. ابتدا باید تمام تراکنش‌های مالی ثبت‌شده برای او را حذف کنید.', 'statusCode' => 409];
            }
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}