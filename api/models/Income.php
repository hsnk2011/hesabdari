<?php
// /api/models/Income.php — درآمد شرکت (برعکس هزینه)
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Income extends BaseModel {
    protected $tableName = 'incomes';

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'i';

        $this->select = "SELECT i.*, a.name as accountName";
        $this->from = "FROM `{$this->tableName}` as i";
        $this->join = "LEFT JOIN accounts a ON i.account_id = a.id";

        $this->allowedFilters = ['i.category', 'i.description', 'i.amount', 'a.name'];
        $this->allowedSorts = ['id', 'date', 'category', 'amount'];
    }

    public function save($data) {
        $entity_id = $_SESSION['current_entity_id'];
        assert_transaction_date_editable($this->conn, $entity_id, $data['date']);
        $id = $data['id'] ?? null;
        if ($id) {
            $oldDateStmt = $this->conn->prepare("SELECT date FROM incomes WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $id, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }

        $this->conn->begin_transaction();
        try {
            $amount = floatval(str_replace(',', '', $data['amount'] ?? 0));
            if ($amount <= 0) {
                throw new Exception('مبلغ درآمد باید بیشتر از صفر باشد.', 400);
            }
            $accountId = intval($data['account_id'] ?? 0);
            $accountModel = new Account($this->conn);
            if ($accountId <= 0 || !$accountModel->getAccountForEntity($accountId, $entity_id)) {
                throw new Exception('حساب دریافت درآمد نامعتبر است یا متعلق به این مجموعه نیست.', 400);
            }

            $personType = trim((string)($data['person_type'] ?? ''));
            $personId = intval($data['person_id'] ?? 0);
            if ($personType === '' || $personId <= 0) {
                $personType = null;
                $personId = null;
            } else {
                $allowed = ['customer', 'supplier', 'partner', 'contact'];
                if (!in_array($personType, $allowed, true)) {
                    throw new Exception('نوع شخص درآمد نامعتبر است.', 400);
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
                    throw new Exception('شخص انتخاب‌شده برای درآمد یافت نشد.', 404);
                }
            }

            if ($id) {
                $oldStmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
                $oldStmt->bind_param("ii", $id, $entity_id);
                $oldStmt->execute();
                $old_res = db_stmt_to_assoc_array($oldStmt);
                $oldIncome = $old_res[0] ?? null;

                if (!$oldIncome) {
                    throw new Exception('درآمد مورد نظر یافت نشد.', 404);
                }

                // Revert previous credit to wallet
                $accountModel->updateBalance($oldIncome['account_id'], -floatval($oldIncome['amount']));

                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET category=?, date=?, amount=?, description=?, account_id=?, person_id=?, person_type=? WHERE id=? AND entity_id=?");
                $stmt->bind_param("ssdsiisis", $data['category'], $data['date'], $amount, $data['description'], $accountId, $personId, $personType, $id, $entity_id);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, category, date, amount, description, account_id, person_id, person_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdsiis", $entity_id, $data['category'], $data['date'], $amount, $data['description'], $accountId, $personId, $personType);
            }

            $stmt->execute();
            $incomeId = $id ?? $this->conn->insert_id;
            $stmt->close();

            // Wallet: income increases the receiving account
            $accountModel->updateBalance($accountId, $amount);

            $this->conn->commit();
            return ['success' => true, 'id' => $incomeId];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در ذخیره درآمد: ' . $e->getMessage(), 'statusCode' => $statusCode];
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
            $income = $res[0] ?? null;

            if (!$income) {
                throw new Exception("درآمد مورد نظر یافت نشد.", 404);
            }

            assert_transaction_date_editable($this->conn, $entity_id, $income['date']);

            $accountModel = new Account($this->conn);
            $accountModel->updateBalance($income['account_id'], -floatval($income['amount']));

            $deleteResult = parent::delete($id);
            if (isset($deleteResult['error'])) {
                throw new Exception($deleteResult['error']);
            }

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در حذف درآمد: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
}
