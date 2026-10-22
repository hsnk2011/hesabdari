<?php
// /api/models/Account.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/ReportModel.php'; // Dependency for the new logic

/**
 * Account Model
 * Manages bank accounts and cash accounts
 */
class Account extends BaseModel {
    protected $tableName = 'accounts';
    protected $allowedFilters = ['name', 'bank_name', 'account_number'];
    protected $allowedSorts = ['id', 'name', 'bank_name', 'current_balance'];

    /**
     * Save or update an account
     * @param array $data Account data including name, bank_name, etc.
     * @return array Result with success or error
     */
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
        $include_in_cash_position = isset($data['include_in_cash_position']) ? (int)$data['include_in_cash_position'] : 1;
        
        if ($id) {
            $entity_id = $_SESSION['current_entity_id'] ?? null;
            if (!$entity_id) {
                return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
            }
            // For editing, we don't change balances directly here. Balances are changed via transactions.
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, bank_name = ?, account_number = ?, card_number = ?, is_cash = ?, include_in_cash_position = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ssssiiii", $name, $bank_name, $account_number, $card_number, $is_cash, $include_in_cash_position, $id, $entity_id);
        } else {
            $initial_balance = $data['initial_balance'] ?? 0.00;
            $entity_id = $_SESSION['current_entity_id'];
            // When creating, both initial and current balance are the same.
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, bank_name, account_number, card_number, initial_balance, current_balance, is_cash, include_in_cash_position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssddii", $entity_id, $name, $bank_name, $account_number, $card_number, $initial_balance, $initial_balance, $is_cash, $include_in_cash_position);
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

        // payments/checks reference accounts with ON DELETE SET NULL, so deleting an
        // account with history would silently orphan every transaction on it.
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $usage = [];
        $queries = [
            ["SELECT COUNT(*) AS c FROM payments WHERE account_id = ? AND entity_id = ?", 'تراکنش'],
            ["SELECT COUNT(*) AS c FROM expenses WHERE account_id = ? AND entity_id = ?", 'هزینه'],
            ["SELECT COUNT(*) AS c FROM incomes WHERE account_id = ? AND entity_id = ?", 'درآمد'],
            ["SELECT COUNT(*) AS c FROM checks WHERE cashed_in_account_id = ? AND entity_id = ?", 'چک وصول/پاس‌شده'],
            ["SELECT COUNT(*) AS c FROM recurring_expenses WHERE account_id = ? AND entity_id = ? AND is_active = 1", 'هزینه تکراری فعال'],
        ];
        foreach ($queries as $def) {
            try {
                $st = $this->conn->prepare($def[0]);
                $st->bind_param('ii', $id, $entityId);
                $st->execute();
                $n = (int)(db_stmt_to_assoc_array($st)[0]['c'] ?? 0);
                if ($n > 0) {
                    $usage[] = "{$n} {$def[1]}";
                }
            } catch (Throwable $e) { /* table may not exist yet */ }
        }
        if (!empty($usage)) {
            return [
                'error' => 'این حساب دارای سابقه است (' . implode('، ', $usage) . ') و قابل حذف نیست. در صورت عدم استفاده، نام آن را تغییر دهید یا از «شامل در موجودی نقد» خارج کنید.',
                'statusCode' => 409,
            ];
        }

        return parent::delete($id);
    }

    /**
     * Update account balance by adding amount (can be positive or negative)
     * @param int $accountId Account ID
     * @param float $amount Amount to add (negative to subtract)
     * @return bool Success status
     */
    public function updateBalance($accountId, $amount, $entityId = null) {
        if (empty($accountId) || !is_numeric($amount)) {
            return false;
        }
        if ($entityId === null) {
            $entityId = $_SESSION['current_entity_id'] ?? null;
        }
        if ($entityId === null) {
            throw new RuntimeException('Entity context is required to update account balances.');
        }
        $amount = round((float)$amount, 2);
        if (abs($amount) < 0.005) {
            return true;
        }
        $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET current_balance = current_balance + ? WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("dii", $amount, $accountId, $entityId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            // Silently skipping here used to save the document while the wallet
            // stayed unchanged, so balances drifted from their ledgers. Abort the
            // surrounding transaction instead.
            throw new Exception('حساب بانکی/صندوق انتخاب‌شده یافت نشد یا متعلق به این مجموعه نیست؛ موجودی به‌روز نشد.', 400);
        }
        return true;
    }

    public function getAccountForEntity($accountId, $entityId) {
        $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $accountId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    public function getAll() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE entity_id = ? ORDER BY is_cash DESC, name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
    
    
    public function getLedger($accountId) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $account_stmt = $this->conn->prepare("SELECT * FROM accounts WHERE id = ? AND entity_id = ?");
        $account_stmt->bind_param("ii", $accountId, $entity_id);
        $account_stmt->execute();
        $account_res = db_stmt_to_assoc_array($account_stmt);
        $account = $account_res[0] ?? null;

        if (!$account) {
            return ['error' => 'حساب یافت نشد.', 'statusCode' => 404];
        }

        // *** START REFACTORED SECTION ***
        // Banks / حساب view is always a WALLET for every account (including partner
        // personal accounts): money in +, money out −. Capital claim vs the company
        // lives only in getPersonStatement (صورتحساب اشخاص), not here.
        $all_transactions = [];
        $entityId = $account['entity_id'];

        $stmt = $this->conn->prepare("\n                -- Cash Receipts\n                SELECT p.date, p.amount, 'payment_in' as source, \n                    CASE\n                        WHEN p.invoiceType = 'asset_sale' THEN CONCAT('دریافت بابت سند فروش دارایی #', p.invoiceId)
                        WHEN p.invoiceType = 'asset_purchase' THEN CONCAT('دریافت (برگشت وجه) سند خرید دارایی #', p.invoiceId)
                        WHEN p.invoiceType = 'return' THEN CONCAT('دریافت از فاکتور مرجوعی خرید #', p.invoiceId)\n                        WHEN p.invoiceType = 'purchase' THEN CONCAT('دریافت (برگشت وجه) از فاکتور خرید #', p.invoiceId)\n                        WHEN p.person_id IS NULL AND p.description LIKE 'TRANSFER:%' THEN SUBSTRING_INDEX(p.description, '|', -1)\n                        WHEN p.invoiceId IS NOT NULL THEN CONCAT('دریافت بابت فاکتور فروش #', p.invoiceId)\n                        WHEN p.person_type = 'customer' THEN CONCAT('دریافت علی الحساب از مشتری: ', c.name)\n                        WHEN p.person_type = 'partner' THEN CONCAT('واریز از طرف شریک: ', pr.name)\n                        WHEN p.person_type = 'contact' THEN CONCAT('دریافت علی الحساب از سایر شخص: ', ct.name)\n                        WHEN p.person_type = 'other' THEN CONCAT('درآمد متفرقه: ', IFNULL(p.description,''))\n                         ELSE p.description\n                    END as type,\n                    IF(p.invoiceType = 'sales', IFNULL((SELECT si.is_consignment FROM sales_invoices si WHERE si.id = p.invoiceId), 0), IF(p.invoiceType = 'purchase', IFNULL((SELECT pi.is_consignment FROM purchase_invoices pi WHERE pi.id = p.invoiceId), 0), 0)) as is_consignment\n                FROM payments p\n                LEFT JOIN customers c ON p.person_id = c.id AND p.person_type = 'customer'\n                LEFT JOIN partners pr ON p.person_id = pr.id AND p.person_type = 'partner'\n                LEFT JOIN contacts ct ON p.person_id = ct.id AND p.person_type = 'contact'\n                WHERE p.account_id = ? AND p.type = 'cash' AND p.transaction_type = 'receipt' AND p.entity_id = ?\n
                UNION ALL\n
                -- Cash Payments\n                SELECT p.date, p.amount, 'payment_out' as source,\n                    CASE\n                        WHEN p.invoiceType = 'asset_purchase' THEN CONCAT('پرداخت بابت سند خرید دارایی #', p.invoiceId)
                        WHEN p.invoiceType = 'asset_sale' THEN CONCAT('پرداخت (برگشت وجه) سند فروش دارایی #', p.invoiceId)
                        WHEN p.invoiceType = 'return' THEN CONCAT('پرداخت بابت فاکتور مرجوعی فروش #', p.invoiceId)\n                        WHEN p.invoiceType = 'sales' THEN CONCAT('پرداخت (برگشت وجه) بابت فاکتور فروش #', p.invoiceId)\n                        WHEN p.person_id IS NULL AND p.description LIKE 'TRANSFER:%' THEN SUBSTRING_INDEX(p.description, '|', -1)\n                        WHEN p.invoiceId IS NOT NULL THEN CONCAT('پرداخت بابت فاکتور خرید #', p.invoiceId)\n                        WHEN p.person_type = 'supplier' THEN CONCAT('پرداخت علی الحساب به تامین کننده: ', s.name)\n                        WHEN p.person_type = 'partner' THEN CONCAT('برداشت توسط شریک: ', pr.name)\n                        WHEN p.person_type = 'contact' THEN CONCAT('پرداخت علی الحساب به سایر شخص: ', ct.name)\n                        WHEN p.person_type = 'other' THEN CONCAT('پرداخت متفرقه: ', IFNULL(p.description,''))\n                         ELSE p.description\n                    END as type,\n                    IF(p.invoiceType = 'sales', IFNULL((SELECT si.is_consignment FROM sales_invoices si WHERE si.id = p.invoiceId), 0), IF(p.invoiceType = 'purchase', IFNULL((SELECT pi.is_consignment FROM purchase_invoices pi WHERE pi.id = p.invoiceId), 0), 0)) as is_consignment\n                FROM payments p\n                LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'\n                LEFT JOIN partners pr ON p.person_id = pr.id AND p.person_type = 'partner'\n                LEFT JOIN contacts ct ON p.person_id = ct.id AND p.person_type = 'contact'\n                WHERE p.account_id = ? AND p.type = 'cash' AND p.transaction_type = 'payment' AND p.entity_id = ?\n
                UNION ALL\n
                -- Expenses\n                SELECT date, amount, 'expense' as source, CONCAT('هزینه: ', category) as type, 0 as is_consignment\n                FROM expenses WHERE account_id = ? AND entity_id = ?\n
                UNION ALL\n
                -- Incomes\n                SELECT date, amount, 'income_in' as source, CONCAT('درآمد: ', category) as type, 0 as is_consignment\n                FROM incomes WHERE account_id = ? AND entity_id = ?\n
                UNION ALL\n
                -- Cashed Checks
                SELECT COALESCE(c.cashed_date, c.dueDate) as date, c.amount, IF(c.type='received', 'check_in', 'check_out') as source, 
                    CONCAT(IF(c.type='received', 'وصول چک: ', 'پاس شدن چک: '), c.checkNumber) as type, 0 as is_consignment
                FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed' AND c.entity_id = ?
            ");
        $stmt->bind_param("iiiiiiiiii", $accountId, $entityId, $accountId, $entityId, $accountId, $entityId, $accountId, $entityId, $accountId, $entityId);
        $stmt->execute();
        $all_transactions = db_stmt_to_assoc_array($stmt);
        
        // The `initial_balance` is now a reliable, stored value.
        // We no longer need to calculate a derived opening balance.
        return ['account' => $account, 'transactions' => $all_transactions];
        // *** END REFACTORED SECTION ***
    }

    public function transferMoney($fromAccountId, $toAccountId, $amount, $date, $description = '') {
        $entity_id = $_SESSION['current_entity_id'];

        assert_transaction_date_editable($this->conn, $entity_id, $date);

        // Verify both accounts exist and belong to current entity
        $fromAccount = $this->getAccountForEntity($fromAccountId, $entity_id);
        if (!$fromAccount) {
            return ['error' => 'حساب مبدا یافت نشد یا به این مجموعه تعلق ندارد.', 'statusCode' => 404];
        }
        $toAccount = $this->getAccountForEntity($toAccountId, $entity_id);
        if (!$toAccount) {
            return ['error' => 'حساب مقصد یافت نشد یا به این مجموعه تعلق ندارد.', 'statusCode' => 404];
        }

        // *** CRITICAL FIX: Re-enable balance check ***
        if ($fromAccount['current_balance'] < $amount) {
            return ['error' => 'موجودی حساب مبدا کافی نیست. موجودی فعلی: ' . number_format($fromAccount['current_balance']) . ' تومان', 'statusCode' => 400];
        }
        // *** END FIX ***
        // *** END REMOVAL ***

        /**
         * Transfer money between two accounts
         * @param int $fromAccountId Source account ID
         * @param int $toAccountId Destination account ID
         * @param float $amount Amount to transfer
         * @param string|null $description Optional transfer description
         * @return array Result with success or error
         */
        // Start transaction
        $this->conn->begin_transaction();
        try {
            // Lock the source row and re-check balance inside the transaction:
            // the pre-transaction check races with concurrent transfers and
            // could let two of them overdraw the same account.
            $lockStmt = $this->conn->prepare("SELECT current_balance FROM accounts WHERE id = ? AND entity_id = ? FOR UPDATE");
            $lockStmt->bind_param("ii", $fromAccountId, $entity_id);
            $lockStmt->execute();
            $lockedRow = db_stmt_to_assoc_array($lockStmt)[0] ?? null;
            if (!$lockedRow || floatval($lockedRow['current_balance']) < $amount) {
                throw new Exception('موجودی حساب مبدا کافی نیست.', 400);
            }

            // Create description with account IDs for reliable pair matching
            // Format: "TRANSFER:FROM_ACC_ID:TO_ACC_ID:TIMESTAMP|user description"
            // Marker pairs the two legs. Microsecond precision plus a random suffix keeps
            // it unique even for identical transfers submitted within the same second.
            $transferMarker = "TRANSFER:{$fromAccountId}:{$toAccountId}:" . ((int)(microtime(true) * 1000000) + random_int(0, 999));
            $descFrom = $transferMarker . "|" . ($description ? $description : "انتقال وجه به حساب {$toAccount['name']}");
            $descTo = $transferMarker . "|" . ($description ? $description : "انتقال وجه از حساب {$fromAccount['name']}");
            
            // Insert payment OUT from source account (برداشت)
            // Explicitly define null variables for clarity in bind_param
            $invoiceId = null;
            $invoiceType = null;
            $person_id = null;
            $person_type = null;
            $checkId = null;
            $transactionTypePayment = 'payment';
            $paymentTypeCash = 'cash';
            
            $stmt = $this->conn->prepare(
                "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisisssdssii", $entity_id, $invoiceId, $invoiceType, $person_id, $person_type, $transactionTypePayment, $paymentTypeCash, $amount, $date, $descFrom, $checkId, $fromAccountId);
            if (!$stmt->execute()) {
                throw new Exception('خطا در ثبت برداشت از حساب مبدا: ' . $stmt->error);
            }
            $fromPaymentId = intval($this->conn->insert_id);
            $stmt->close();
            
            // Insert payment IN to destination account (واریز)
            $transactionTypeReceipt = 'receipt';
            // Use the same null variables
            $stmt = $this->conn->prepare(
                "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisisssdssii", $entity_id, $invoiceId, $invoiceType, $person_id, $person_type, $transactionTypeReceipt, $paymentTypeCash, $amount, $date, $descTo, $checkId, $toAccountId);
            if (!$stmt->execute()) {
                throw new Exception('خطا در ثبت واریز به حساب مقصد: ' . $stmt->error);
            }
            $toPaymentId = intval($this->conn->insert_id);
            $stmt->close();
            
            // Wallet for every account (incl. personal): source −, destination +
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET current_balance = current_balance - ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $fromAccountId);
            if (!$stmt->execute()) {
                throw new Exception('خطا در به‌روزرسانی موجودی حساب مبدا');
            }
            $stmt->close();
            
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET current_balance = current_balance + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $toAccountId);
            if (!$stmt->execute()) {
                throw new Exception('خطا در به‌روزرسانی موجودی حساب مقصد');
            }
            $stmt->close();
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'انتقال وجه با موفقیت انجام شد.',
                'from_payment_id' => $fromPaymentId,
                'to_payment_id' => $toPaymentId,
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    // *** START: New function to delete a transfer ***
    public function deleteTransfer($paymentId) {
        $currentEntityId = $_SESSION['current_entity_id'] ?? null;
        if (!$currentEntityId) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }
        $this->conn->begin_transaction();
        try {
            // 1. Get the payment record to be deleted
            $stmt_find = $this->conn->prepare("SELECT * FROM payments WHERE id = ? AND entity_id = ?");
            $stmt_find->bind_param("ii", $paymentId, $currentEntityId);
            $stmt_find->execute();
            $payment = db_stmt_to_assoc_array($stmt_find)[0] ?? null;

            if (!$payment) {
                throw new Exception("تراکنش یافت نشد.", 404);
            }

            if ((int)$payment['entity_id'] !== (int)$currentEntityId) {
                throw new Exception('شما به این تراکنش دسترسی ندارید.', 403);
            }

            assert_transaction_date_editable($this->conn, $currentEntityId, $payment['date']);
            
            // Check if it's a transfer (no person_id and no invoiceId)
            if ($payment['person_id'] !== null || $payment['invoiceId'] !== null) {
                throw new Exception("این تراکنش یک انتقال وجه نیست و نمی‌توان آن را از این طریق حذف کرد.", 400);
            }

            // 2. Extract transfer marker from description for precise pair matching
            $transferMarker = null;
            if (preg_match('/^(TRANSFER:\d+:\d+:\d+)\|/', $payment['description'], $matches)) {
                $transferMarker = $matches[1];
            }

            // Without a reliable TRANSFER marker we cannot safely identify the paired
            // leg. Guessing by amount/date could delete an unrelated payment, so refuse.
            if (!$transferMarker) {
                throw new Exception('این تراکنش فاقد شناسه انتقال معتبر است و برای جلوگیری از حذف اشتباه، امکان حذف خودکار آن وجود ندارد.', 400);
            }

            $pair_transaction_type = ($payment['transaction_type'] === 'payment') ? 'receipt' : 'payment';

            // Use precise marker-based matching
            $stmt_pair = $this->conn->prepare("
                SELECT * FROM payments 
                WHERE description LIKE CONCAT(?, '%')
                AND transaction_type = ? 
                AND entity_id = ? 
                AND id != ?
                LIMIT 1
            ");
            $markerPattern = $transferMarker;
            $stmt_pair->bind_param("ssii", $markerPattern, $pair_transaction_type, $currentEntityId, $paymentId);
            $stmt_pair->execute();
            $pair_payment = db_stmt_to_assoc_array($stmt_pair)[0] ?? null;

            if (!$pair_payment) {
                throw new Exception('طرف دوم این انتقال وجه یافت نشد و امکان حذف امن آن وجود ندارد.', 400);
            }

            assert_transaction_date_editable($this->conn, $currentEntityId, $pair_payment['date']);

            // 3–4. Revert wallet deltas (inverse of from− / to+)
            $amountToRevert1 = ($payment['transaction_type'] === 'receipt') ? -$payment['amount'] : $payment['amount'];
            $this->updateBalance($payment['account_id'], $amountToRevert1, $currentEntityId);

            $amountToRevert2 = ($pair_payment['transaction_type'] === 'receipt') ? -$pair_payment['amount'] : $pair_payment['amount'];
            $this->updateBalance($pair_payment['account_id'], $amountToRevert2, $currentEntityId);

            // 5. Delete both payment records
            $stmt_delete = $this->conn->prepare("DELETE FROM payments WHERE id = ? OR id = ?");
            $stmt_delete->bind_param("ii", $payment['id'], $pair_payment['id']);
            $stmt_delete->execute();
            $stmt_delete->close();

            $this->conn->commit();
            return ['success' => true, 'message' => 'تراکنش انتقال وجه (هر دو طرف) با موفقیت حذف شد.'];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => $e->getCode() > 0 ? $e->getCode() : 500];
        }
    }
    // *** END: New function ***
}
