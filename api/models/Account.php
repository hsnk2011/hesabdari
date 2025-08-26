<?php
// /api/models/Account.php
require_once __DIR__ . '/BaseModel.php';

class Account extends BaseModel {
    protected $tableName = 'accounts';
    protected $allowedFilters = ['name', 'bank_name', 'account_number'];
    protected $allowedSorts = ['id', 'name', 'bank_name', 'current_balance'];

    public function save($data) {
        if (empty($data['name'])) {
            return ['error' => 'نام حساب الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        
        // اگر در حالت ویرایش هستیم، چک کن که حساب شریک نباشد
        if ($id) {
            $stmt_check = $this->conn->prepare("SELECT partner_id FROM `{$this->tableName}` WHERE id = ?");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $partner_id = null;
                $stmt_check->bind_result($partner_id);
                $stmt_check->fetch();
                if ($partner_id !== null) {
                    $stmt_check->close();
                    return ['error' => 'حساب‌های شخصی شرکا قابل ویرایش نیستند.', 'statusCode' => 403];
                }
            }
            $stmt_check->close();
        }

        $name = $data['name'];
        $bank_name = $data['bank_name'] ?? null;
        $account_number = $data['account_number'] ?? null;
        $card_number = $data['card_number'] ?? null;
        $is_cash = isset($data['is_cash']) ? (int)$data['is_cash'] : 0;
        
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
    
    public function delete($id) {
        $id = intval($id);

        // قبل از حذف، چک کن که حساب متعلق به شریک نباشد
        $stmt_check = $this->conn->prepare("SELECT partner_id FROM `{$this->tableName}` WHERE id = ?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $partner_id = null;
            $stmt_check->bind_result($partner_id);
            $stmt_check->fetch();
            if ($partner_id !== null) {
                $stmt_check->close();
                return ['error' => 'حساب‌های شخصی شرکا قابل حذف نیستند.', 'statusCode' => 403];
            }
        }
        $stmt_check->close();

        // اگر حساب شریک نبود، ادامه بده به منطق حذف والد
        return parent::delete($id);
    }

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

    public function getAll() {
        $result = $this->conn->query("SELECT * FROM `{$this->tableName}` ORDER BY is_cash DESC, name ASC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}