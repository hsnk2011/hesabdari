<?php
// /api/models/Account.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/ReportModel.php'; // Dependency for the new logic

class Account extends BaseModel {
    protected $tableName = 'accounts';
    protected $allowedFilters = ['name', 'bank_name', 'account_number'];
    protected $allowedSorts = ['id', 'name', 'bank_name', 'current_balance'];

    public function save($data) {
        if (empty($data['name'])) {
            return ['error' => 'نام حساب الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        
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
            $entity_id = $_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, bank_name, account_number, card_number, current_balance, is_cash) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssdi", $entity_id, $name, $bank_name, $account_number, $card_number, $initial_balance, $is_cash);
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
        // Filter accounts by the current active entity
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE entity_id = ? ORDER BY is_cash DESC, name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    public function getLedger($accountId) {
        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ?");
        $account_stmt->bind_param("i", $accountId);
        $account_stmt->execute();
        $account_res = db_stmt_to_assoc_array($account_stmt);
        $account = $account_res[0] ?? null;

        if (!$account) {
            return ['error' => 'حساب یافت نشد.', 'statusCode' => 404];
        }

        $all_transactions = [];
        
        // **FIX START**: Unify logic for partner and regular accounts
        if ($account['partner_id']) {
            // For partner accounts, use the comprehensive logic from ReportModel to ensure consistency
            $reportModel = new ReportModel($this->conn);
            $statementData = $reportModel->getPersonStatement([
                'personType' => 'partner',
                'personId' => $account['partner_id'],
                'startDate' => '1970-01-01', // Use a wide date range to get all transactions
                'endDate' => '2999-12-31'
            ]);
            $report_transactions = $statementData['transactions'];

            // Convert the report format to the ledger format
            foreach ($report_transactions as $tx) {
                if ($tx['date'] === 'مانده اولیه') continue; // Skip opening balance row from report
                
                $isDebit = ($tx['debit'] > 0);
                $all_transactions[] = [
                    'date' => $tx['date'],
                    'amount' => $isDebit ? $tx['debit'] : $tx['credit'],
                    'source' => $isDebit ? 'payment_out' : 'payment_in', // Generic source for ledger rendering
                    'type' => $tx['desc'] // The rich description from the report becomes the type
                ];
            }

        } else {
            // Use the standard, simpler logic for regular bank/cash accounts
            $base_payment_sql = "
                FROM payments p
                LEFT JOIN customers c ON p.person_id = c.id AND p.person_type = 'customer'
                LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'
                LEFT JOIN partners pr ON p.person_id = pr.id AND p.person_type = 'partner'
                WHERE p.account_id = ? AND p.type = 'cash'
            ";
            $receipt_sql = "
                SELECT p.date, p.amount, 'payment_in' as source, 
                CASE
                    WHEN p.invoiceId IS NOT NULL THEN CONCAT('دریافت بابت فاکتور فروش #', p.invoiceId)
                    WHEN p.person_type = 'customer' THEN CONCAT('دریافت علی الحساب از مشتری: ', c.name)
                    WHEN p.person_type = 'partner' THEN CONCAT('واریز از طرف شریک: ', pr.name)
                    ELSE p.description
                END as type
                {$base_payment_sql} AND p.transaction_type = 'receipt'";
            $stmt_receipt = $this->conn->prepare($receipt_sql);
            $stmt_receipt->bind_param("i", $accountId);
            $stmt_receipt->execute();
            $all_transactions = array_merge($all_transactions, db_stmt_to_assoc_array($stmt_receipt));
            
            $payment_sql = "
                SELECT p.date, p.amount, 'payment_out' as source, 
                CASE
                    WHEN p.invoiceId IS NOT NULL THEN CONCAT('پرداخت بابت فاکتور خرید #', p.invoiceId)
                    WHEN p.person_type = 'supplier' THEN CONCAT('پرداخت علی الحساب به تامین کننده: ', s.name)
                    WHEN p.person_type = 'partner' THEN CONCAT('برداشت توسط شریک: ', pr.name)
                    ELSE p.description
                END as type
                {$base_payment_sql} AND p.transaction_type = 'payment'";
            $stmt_payment = $this->conn->prepare($payment_sql);
            $stmt_payment->bind_param("i", $accountId);
            $stmt_payment->execute();
            $all_transactions = array_merge($all_transactions, db_stmt_to_assoc_array($stmt_payment));
            
            $stmt_expense = $this->conn->prepare("SELECT date, amount, 'expense' as source, CONCAT('هزینه: ', category) as type, description FROM expenses WHERE account_id = ?");
            $stmt_expense->bind_param("i", $accountId);
            $stmt_expense->execute();
            $all_transactions = array_merge($all_transactions, db_stmt_to_assoc_array($stmt_expense));
            
            $stmt_check = $this->conn->prepare("SELECT c.dueDate as date, c.amount, IF(c.type='received', 'check_in', 'check_out') as source, IF(c.type='received', 'وصول چک', 'پاس شدن چک') as type, CONCAT('چک شماره: ', c.checkNumber) as description FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed'");
            $stmt_check->bind_param("i", $accountId);
            $stmt_check->execute();
            $all_transactions = array_merge($all_transactions, db_stmt_to_assoc_array($stmt_check));
        }
        // **FIX END**

        $totalChange = 0;
        foreach ($all_transactions as $tx) {
            $amount = (float)$tx['amount'];
            $source = $tx['source'];
            if (str_ends_with($source, '_out') || $source === 'expense') {
                $totalChange -= $amount;
            } else {
                $totalChange += $amount;
            }
        }
        
        $account['initial_balance_calculated'] = (float)$account['current_balance'] - $totalChange;
        
        return ['account' => $account, 'transactions' => $all_transactions];
    }
}