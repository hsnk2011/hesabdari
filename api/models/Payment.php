<?php
// /api/models/Payment.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Payment extends BaseModel {
    protected $tableName = 'payments';

    public function save($data) {
        $type = $data['type'] ?? '';
        $transaction_type = $data['transaction_type'] ?? '';
        $entity_id = $_SESSION['current_entity_id'];

        $this->conn->begin_transaction();
        try {
            $id = $data['id'] ?? null;
            $accountModel = new Account($this->conn);
            $oldPayment = null;

            if ($id) {
                $stmt_old = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ?");
                $stmt_old->bind_param("i", $id);
                $stmt_old->execute();
                $result = db_stmt_to_assoc_array($stmt_old);
                $oldPayment = $result[0] ?? null;
                
                if ($oldPayment) {
                    // Revert old transaction's financial impact
                    if ($oldPayment['type'] === 'cash' && $oldPayment['account_id']) {
                        $amountToRevert = ($oldPayment['transaction_type'] === 'receipt') ? -$oldPayment['amount'] : $oldPayment['amount'];
                        $accountModel->updateBalance($oldPayment['account_id'], $amountToRevert);
                    }
                    if ($oldPayment['type'] === 'endorse_check' && $oldPayment['checkId']) {
                         $stmt_revert_endorse = $this->conn->prepare("UPDATE checks SET status = 'in_hand' WHERE id = ?");
                         $stmt_revert_endorse->bind_param("i", $oldPayment['checkId']);
                         $stmt_revert_endorse->execute();
                         $stmt_revert_endorse->close();
                    }
                    if ($oldPayment['invoiceId']) {
                        $table = ($oldPayment['invoiceType'] === 'sales') ? 'sales_invoices' : 'purchase_invoices';
                        $stmt_revert_paid = $this->conn->prepare("UPDATE `{$table}` SET paidAmount = paidAmount - ? WHERE id = ?");
                        $stmt_revert_paid->bind_param("di", $oldPayment['amount'], $oldPayment['invoiceId']);
                        $stmt_revert_paid->execute();
                        $stmt_revert_paid->close();
                    }
                }
            }

            $checkId = $data['checkId'] ?? ($oldPayment['checkId'] ?? null);
            if ($type === 'check' && !empty($data['checkDetails'])) {
                if ($checkId) {
                    $stmt_check = $this->conn->prepare("UPDATE checks SET checkNumber=?, dueDate=?, bankName=?, amount=? WHERE id=?");
                    $stmt_check->bind_param("sssdi", $data['checkDetails']['checkNumber'], $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $data['amount'], $checkId);
                    $stmt_check->execute();
                    $stmt_check->close();
                } else {
                    $checkType = ($transaction_type === 'receipt') ? 'received' : 'payable';
                    $checkStatus = ($checkType === 'received') ? 'in_hand' : 'payable';
                    $stmt_check = $this->conn->prepare("INSERT INTO checks (entity_id, type, status, checkNumber, dueDate, bankName, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_check->bind_param("isssssd", $entity_id, $checkType, $checkStatus, $data['checkDetails']['checkNumber'], $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $data['amount']);
                    $stmt_check->execute();
                    $checkId = $this->conn->insert_id; // Get the new check ID
                    $stmt_check->close();
                }
            }

            $invoiceId = $data['invoiceId'] ?? null;
            $invoiceType = $data['invoiceType'] ?? null;
            $person_id = $data['person_id'] ?? null;
            $person_type = $data['person_type'] ?? null;
            $amount = $data['amount'];
            $date = $data['date'];
            $description = $data['description'] ?? '';
            $account_id = $data['account_id'] ?? null;

            if ($id) {
                $stmt_pay = $this->conn->prepare(
                    "UPDATE payments SET invoiceId=?, invoiceType=?, person_id=?, person_type=?, transaction_type=?, type=?, amount=?, date=?, description=?, checkId=?, account_id=? WHERE id=?"
                );
                $stmt_pay->bind_param("isisssdssiii", $invoiceId, $invoiceType, $person_id, $person_type, $transaction_type, $type, $amount, $date, $description, $checkId, $account_id, $id);
            } else {
                $stmt_pay = $this->conn->prepare(
                    "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt_pay->bind_param("iisisssdssii", $entity_id, $invoiceId, $invoiceType, $person_id, $person_type, $transaction_type, $type, $amount, $date, $description, $checkId, $account_id);
            }
            
            $stmt_pay->execute();
            $paymentId = $id ?? $this->conn->insert_id;
            $stmt_pay->close();

            // Apply new transaction's financial impact
            if ($type === 'cash') {
                $amountToUpdate = ($transaction_type === 'receipt') ? $amount : -$amount;
                $accountModel->updateBalance($account_id, $amountToUpdate);
            }
            if ($type === 'endorse_check' && $checkId) {
                $stmt_endorse = $this->conn->prepare("UPDATE checks SET status = 'endorsed' WHERE id = ?");
                $stmt_endorse->bind_param("i", $checkId);
                $stmt_endorse->execute();
                $stmt_endorse->close();
            }
            if ($invoiceId) {
                 $table = ($invoiceType === 'sales') ? 'sales_invoices' : 'purchase_invoices';
                 $stmt_update_paid = $this->conn->prepare("UPDATE `{$table}` SET paidAmount = paidAmount + ? WHERE id = ?");
                 $stmt_update_paid->bind_param("di", $amount, $invoiceId);
                 $stmt_update_paid->execute();
                 $stmt_update_paid->close();
            }

            $this->conn->commit();
            return ['success' => true, 'id' => $paymentId];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => 'خطا در ذخیره پرداخت: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id)
    {
        if (empty($id)) {
            return ['error' => 'شناسه پرداخت نامعتبر است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = db_stmt_to_assoc_array($stmt);
            $payment = $result[0] ?? null;

            if (!$payment) {
                throw new Exception("پرداخت یافت نشد.");
            }

            $accountModel = new Account($this->conn);
            if ($payment['type'] === 'cash' && $payment['account_id']) {
                $amountToRevert = ($payment['transaction_type'] === 'receipt') ? -$payment['amount'] : $payment['amount'];
                $accountModel->updateBalance($payment['account_id'], $amountToRevert);
            }
            
            if ($payment['type'] === 'endorse_check' && $payment['checkId']) {
                $stmt_revert_endorse = $this->conn->prepare("UPDATE checks SET status = 'in_hand' WHERE id = ?");
                $stmt_revert_endorse->bind_param("i", $payment['checkId']);
                $stmt_revert_endorse->execute();
                $stmt_revert_endorse->close();
            }

            if ($payment['invoiceId']) {
                $table = ($payment['invoiceType'] === 'sales') ? 'sales_invoices' : 'purchase_invoices';
                $stmt_revert_paid = $this->conn->prepare("UPDATE `{$table}` SET paidAmount = paidAmount - ? WHERE id = ?");
                $stmt_revert_paid->bind_param("di", $payment['amount'], $payment['invoiceId']);
                $stmt_revert_paid->execute();
                $stmt_revert_paid->close();
            }
            
            if ($payment['person_type'] === 'partner') {
                 $stmt_pa = $this->conn->prepare("SELECT id FROM accounts WHERE partner_id = ?");
                 $stmt_pa->bind_param("i", $payment['person_id']);
                 $stmt_pa->execute();
                 $result_pa = db_stmt_to_assoc_array($stmt_pa);
                 $partnerAccount = $result_pa[0] ?? null;
                 if ($partnerAccount) {
                     $partnerPersonalAccountId = $partnerAccount['id'];
                     $partnerAmountToRevert = ($payment['transaction_type'] === 'receipt') ? -$payment['amount'] : $payment['amount'];
                     $accountModel->updateBalance($partnerPersonalAccountId, $partnerAmountToRevert);
                 }
            }

            parent::delete($id);

            if ($payment['checkId'] && $payment['type'] === 'check' && is_null($payment['invoiceId'])) {
                 $stmt_del_check = $this->conn->prepare("DELETE FROM checks WHERE id = ?");
                 $stmt_del_check->bind_param("i", $payment['checkId']);
                 $stmt_del_check->execute();
                 $stmt_del_check->close();
            }

            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
             $this->conn->rollback();
             return ['error' => 'خطا در حذف پرداخت: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
}