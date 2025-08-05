<?php
// /api/models/Expense.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Expense extends BaseModel {
    protected $tableName = 'expenses';
    protected $allowedFilters = ['category', 'description', 'amount', 'a.name']; // Use real column name with alias
    protected $allowedSorts = ['id', 'date', 'category', 'amount'];

    public function getPaginated($input) {
        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;

        $sortBy = in_array($input['sortBy'] ?? 'id', $this->allowedSorts) ? $input['sortBy'] : 'id';
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        
        $searchTerm = $input['searchTerm'] ?? '';

        // Custom select and from for this model to include account name
        $select = "SELECT e.*, a.name as accountName";
        $from = "FROM `{$this->tableName}` e LEFT JOIN accounts a ON e.account_id = a.id";
        $where = "WHERE 1";
        
        $params = [];
        $param_types = '';

        if (!empty($searchTerm) && !empty($this->allowedFilters)) {
            $search_parts = [];
            foreach ($this->allowedFilters as $col) {
                $search_parts[] = "$col LIKE ?";
            }
            $where .= " AND (" . implode(' OR ', $search_parts) . ")";
            
            $wildcard = "%{$searchTerm}%";
            foreach ($this->allowedFilters as $_) {
                $params[] = $wildcard;
                $param_types .= 's';
            }
        }
        
        $bind_params_safely = function($stmt, $types, &$params) {
            if (!empty($params)) {
                $refs = [];
                foreach ($params as $key => $value) $refs[$key] = &$params[$key];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            }
        };

        // Count total records
        $count_sql = "SELECT COUNT(*) as total $from $where";
        $stmt_count = $this->conn->prepare($count_sql);
        $bind_params_safely($stmt_count, $param_types, $params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        // Fetch paginated data
        $data_sql = "$select $from $where ORDER BY e.`$sortBy` $sortOrder LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $param_types .= 'ii';
        
        $stmt_data = $this->conn->prepare($data_sql);
        $bind_params_safely($stmt_data, $param_types, $params);
        $stmt_data->execute();
        
        $stmt_data->store_result();
        $meta = $stmt_data->result_metadata();
        $fields = []; $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt_data, 'bind_result'], $fields);
        
        $data = [];
        while ($stmt_data->fetch()) {
            $c = [];
            foreach($row as $key => $val) $c[$key] = $val;
            $data[] = $c;
        }
        $stmt_data->close();
        
        return ['data' => $data, 'totalRecords' => $totalRecords];
    }

    public function save($data) {
        if (empty($data['category']) || !isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0 || empty($data['date']) || empty($data['account_id'])) {
            return ['error' => 'لطفاً تمام فیلدهای الزامی هزینه (دسته‌بندی، مبلغ، تاریخ و حساب پرداخت) را به درستی وارد کنید.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);

        try {
            $id = $data['id'] ?? null;
            $amount = $data['amount'];
            $accountId = $data['account_id'];

            if ($id) {
                $oldExpenseStmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
                $oldExpenseStmt->bind_param("i", $id);
                $oldExpenseStmt->execute();
                $oldExpenseStmt->store_result();
                $oldExpense = null;
                if ($oldExpenseStmt->num_rows > 0) {
                    $old_amount = 0; $old_account_id = 0;
                    $oldExpenseStmt->bind_result($old_amount, $old_account_id);
                    $oldExpenseStmt->fetch();
                    $oldExpense = ['amount' => $old_amount, 'account_id' => $old_account_id];
                }
                $oldExpenseStmt->close();

                if ($oldExpense) {
                    $accountModel->updateBalance($oldExpense['account_id'], $oldExpense['amount']);
                }
                
                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET category=?, date=?, amount=?, description=?, account_id=? WHERE id=?");
                $stmt->bind_param("ssdsii", $data['category'], $data['date'], $amount, $data['description'], $accountId, $id);
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (category, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsi", $data['category'], $data['date'], $amount, $data['description'], $accountId);
            }
            
            $stmt->execute();
            $expenseId = $id ?? $this->conn->insert_id;
            $stmt->close();

            $accountModel->updateBalance($accountId, -$amount);

            $typeText = "هزینه: " . $data['category'];
            $neg_amount = -$amount;
            if ($id) {
                $stmt_trans = $this->conn->prepare("UPDATE transactions SET amount=?, date=?, type=?, description=? WHERE relatedObjectType='expense' AND relatedObjectId=?");
                $stmt_trans->bind_param("dsssi", $neg_amount, $data['date'], $typeText, $data['description'], $id);
            } else {
                $stmt_trans = $this->conn->prepare("INSERT INTO transactions (relatedObjectType, relatedObjectId, amount, date, type, description) VALUES ('expense', ?, ?, ?, ?, ?)");
                $stmt_trans->bind_param("idsss", $expenseId, $neg_amount, $data['date'], $typeText, $data['description']);
            }
            $stmt_trans->execute();
            $stmt_trans->close();

            $this->conn->commit();
            return ['success' => true, 'id' => $expenseId];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        $this->conn->begin_transaction();
        $accountModel = new Account($this->conn);
        try {
            $stmt = $this->conn->prepare("SELECT amount, account_id FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->store_result();
            $expense = null;
            if ($stmt->num_rows > 0) {
                $amount = 0; $account_id = 0;
                $stmt->bind_result($amount, $account_id);
                $stmt->fetch();
                $expense = ['amount' => $amount, 'account_id' => $account_id];
            }
            $stmt->close();

            if ($expense) {
                $accountModel->updateBalance($expense['account_id'], $expense['amount']);
            }

            $stmt_trans = $this->conn->prepare("DELETE FROM transactions WHERE relatedObjectType='expense' AND relatedObjectId=?");
            $stmt_trans->bind_param("i", $id);
            $stmt_trans->execute();
            $stmt_trans->close();
            
            parent::delete($id);
            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}