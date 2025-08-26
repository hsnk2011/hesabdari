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

        // منطق ویرایش شریک (بدون تغییر)
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, share = ? WHERE id = ?");
            $stmt->bind_param("sdi", $data['name'], $data['share'], $id);
            if ($stmt->execute()) {
                $stmt->close();
                return ['success' => true, 'id' => $id];
            } else {
                $error = $stmt->error;
                $stmt->close();
                return ['error' => $error, 'statusCode' => 500];
            }
        } 
        // منطق ایجاد شریک جدید (اصلاح شده)
        else {
            $this->conn->begin_transaction();
            try {
                // 1. ابتدا شریک را در جدول partners ایجاد کن
                $stmt_partner = $this->conn->prepare("INSERT INTO `partners` (name, share) VALUES (?, ?)");
                $stmt_partner->bind_param("sd", $data['name'], $data['share']);
                $stmt_partner->execute();
                $savedId = $this->conn->insert_id;
                $stmt_partner->close();

                if (!$savedId || $savedId == 0) {
                    throw new Exception("ایجاد شریک ناموفق بود، شناسه جدیدی دریافت نشد.");
                }

                // 2. سپس حساب شخصی را در جدول accounts برای او ایجاد کن
                $accountName = "حساب شخصی: " . $data['name'];
                $isCash = 0; 
                $initialBalance = 0.00;

                $stmt_account = $this->conn->prepare(
                    "INSERT INTO `accounts` (name, current_balance, is_cash, partner_id) VALUES (?, ?, ?, ?)"
                );
                $stmt_account->bind_param("sdii", $accountName, $initialBalance, $isCash, $savedId);
                $stmt_account->execute();
                $stmt_account->close();
                
                $this->conn->commit();
                return ['success' => true, 'id' => $savedId];

            } catch (Exception $e) {
                $this->conn->rollback();
                return ['error' => $e->getMessage(), 'statusCode' => 500];
            }
        }
    }

    public function saveTransaction($data) {
        if (empty($data['partnerId']) || empty($data['type']) || !isset($data['amount']) || $data['amount'] <= 0 || empty($data['date']) || empty($data['account_id'])) {
            return ['error' => 'اطلاعات تراکنش شریک (شامل حساب بانکی شرکت) ناقص است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $partnerId = intval($data['partnerId']);
            $companyAccountId = intval($data['account_id']);
            $amount = $data['amount'];
            $type = $data['type'];
            $id = $data['id'] ?? null;
            
            $stmt_pa = $this->conn->prepare("SELECT id FROM accounts WHERE partner_id = ?");
            $stmt_pa->bind_param("i", $partnerId);
            $stmt_pa->execute();
            $stmt_pa->store_result();
            if ($stmt_pa->num_rows === 0) {
                 throw new Exception("حساب شخصی برای این شریک یافت نشد.");
            }
            $partnerPersonalAccountId = null;
            $stmt_pa->bind_result($partnerPersonalAccountId);
            $stmt_pa->fetch();
            $stmt_pa->close();

            if ($id) { // ویرایش تراکنش موجود
                $oldTxStmt = $this->conn->prepare("SELECT amount, type, account_id FROM partner_transactions WHERE id = ?");
                $oldTxStmt->bind_param("i", $id);
                $oldTxStmt->execute();
                $oldTxStmt->store_result();
                $oldAmount = 0; $oldType = ''; $oldCompanyAccountId = 0;
                $oldTxStmt->bind_result($oldAmount, $oldType, $oldCompanyAccountId);
                $oldTxStmt->fetch();
                $oldTxStmt->close();

                // Revert old transaction based on CORRECTED logic
                if ($oldType === 'DEPOSIT') {
                    $accountModel->updateBalance($partnerPersonalAccountId, -$oldAmount); // Revert deposit from partner's account
                    $accountModel->updateBalance($oldCompanyAccountId, -$oldAmount);
                } else { // WITHDRAWAL
                    $accountModel->updateBalance($partnerPersonalAccountId, $oldAmount); // Revert withdrawal from partner's account
                    $accountModel->updateBalance($oldCompanyAccountId, $oldAmount);
                }
                
                $stmt = $this->conn->prepare("UPDATE partner_transactions SET partnerId=?, type=?, date=?, amount=?, description=?, account_id=? WHERE id=?");
                $stmt->bind_param("issdsii", $partnerId, $type, $data['date'], $amount, $data['description'], $companyAccountId, $id);

            } else { // درج تراکنش جدید
                $stmt = $this->conn->prepare("INSERT INTO partner_transactions (partnerId, type, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdsi", $partnerId, $type, $data['date'], $amount, $data['description'], $companyAccountId);
            }
            $stmt->execute();
            $id = $id ?? $this->conn->insert_id;
            $stmt->close();
            
            // *** FIX: Corrected the debit/credit logic for partner's personal account ***
            if ($type === 'DEPOSIT') {
                // Partner's personal account is CREDITED (balance increases)
                $accountModel->updateBalance($partnerPersonalAccountId, $amount); 
                // Company's account is CREDITED (balance increases)
                $accountModel->updateBalance($companyAccountId, $amount);          
            } else { // WITHDRAWAL
                // Partner's personal account is DEBITED (balance decreases)
                $accountModel->updateBalance($partnerPersonalAccountId, -$amount);    
                // Company's account is DEBITED (balance decreases)
                $accountModel->updateBalance($companyAccountId, -$amount);           
            }

            $this->conn->commit();

            $res = $this->conn->query("SELECT name FROM partners WHERE id = $partnerId");
            $partnerName = $res ? $res->fetch_assoc()['name'] : 'نامشخص';
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
            $stmt = $this->conn->prepare("SELECT partnerId, amount, type, account_id FROM partner_transactions WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->store_result();
            
            $tx = null;
            if ($stmt->num_rows > 0) {
                $partnerId = 0; $amount = 0; $type = ''; $company_account_id = 0;
                $stmt->bind_result($partnerId, $amount, $type, $company_account_id);
                $stmt->fetch();
                $tx = ['partnerId' => $partnerId, 'amount' => $amount, 'type' => $type, 'company_account_id' => $company_account_id];
            }
            $stmt->close();

            if ($tx) {
                $stmt_pa = $this->conn->prepare("SELECT id FROM accounts WHERE partner_id = ?");
                $stmt_pa->bind_param("i", $tx['partnerId']);
                $stmt_pa->execute();
                $stmt_pa->store_result();
                $partnerPersonalAccountId = null;
                $stmt_pa->bind_result($partnerPersonalAccountId);
                $stmt_pa->fetch();
                $stmt_pa->close();
                
                // *** FIX: Correctly reverse the transaction based on the new logic ***
                if ($tx['type'] === 'DEPOSIT') {
                    // To reverse a deposit, DEBIT the partner's account (decrease balance)
                    $accountModel->updateBalance($partnerPersonalAccountId, -$tx['amount']);
                    // DEBIT the company's account
                    $accountModel->updateBalance($tx['company_account_id'], -$tx['amount']);
                } else { // WITHDRAWAL
                    // To reverse a withdrawal, CREDIT the partner's account (increase balance)
                    $accountModel->updateBalance($partnerPersonalAccountId, $tx['amount']);
                    // CREDIT the company's account
                    $accountModel->updateBalance($tx['company_account_id'], $tx['amount']);
                }
            }
            
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