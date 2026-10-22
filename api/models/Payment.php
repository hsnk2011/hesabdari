<?php
// /api/models/Payment.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Payment extends BaseModel {
    protected $tableName = 'payments';

    private function resolveInvoiceTable($invoiceType) {
        $map = [
            'sales' => 'sales_invoices',
            'purchase' => 'purchase_invoices',
            'return' => 'return_invoices',
            'asset_purchase' => 'asset_documents',
            'asset_sale' => 'asset_documents',
        ];
        if (!isset($map[$invoiceType])) {
            throw new Exception('نوع فاکتور نامعتبر است.', 400);
        }
        return $map[$invoiceType];
    }

    private function ensureAccountOwnership($accountId, $entityId) {
        if (!$accountId) return;
        $stmt = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $accountId, $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        if (empty($result)) {
            throw new Exception('حساب انتخاب شده در این مجموعه یافت نشد.', 403);
        }
    }

    private function ensurePersonOwnership($personId, $personType, $entityId) {
        if (!$personId || !$personType) return;
        $map = [
            'customer' => 'customers',
            'supplier' => 'suppliers',
            'partner' => 'partners',
            'contact' => 'contacts'
        ];
        if (!isset($map[$personType])) {
            throw new Exception('نوع شخص نامعتبر است.', 400);
        }
        $table = $map[$personType];
        $stmt = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $personId, $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        if (empty($result)) {
            throw new Exception('شخص انتخاب شده در این مجموعه یافت نشد.', 404);
        }
    }

    private function ensureInvoiceOwnership($invoiceId, $invoiceType, $entityId) {
        if (!$invoiceId) return;
        $table = $this->resolveInvoiceTable($invoiceType);
        $stmt = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $invoiceId, $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        if (empty($result)) {
            throw new Exception('فاکتور انتخاب شده یافت نشد یا متعلق به این مجموعه نیست.', 404);
        }
    }

    private function ensureCheckOwnership($checkId, $entityId) {
        if (!$checkId) return;
        $stmt = $this->conn->prepare("SELECT id FROM checks WHERE id = ? AND entity_id = ?");
        $stmt->bind_param("ii", $checkId, $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        if (empty($result)) {
            throw new Exception('چک انتخاب شده یافت نشد یا متعلق به این مجموعه نیست.', 404);
        }
    }

    /** Statuses in which a check has not yet moved money or changed hands. */
    const CHECK_OPEN_STATUSES = ['in_hand', 'payable'];

    public static function checkStatusLabel($status) {
        $map = [
            'in_hand' => 'نزد ما', 'payable' => 'پرداختنی', 'endorsed' => 'خرج‌شده (واگذار شده)',
            'cashed' => 'وصول/پاس‌شده', 'bounced' => 'برگشتی',
        ];
        return $map[$status] ?? $status;
    }

    /** True for one leg of an account-to-account transfer (paired rows). */
    public static function isTransferLeg(array $payment) {
        return empty($payment['person_id']) && empty($payment['invoiceId'])
            && strpos((string)($payment['description'] ?? ''), 'TRANSFER:') === 0;
    }

    /** True for one leg of a setoff / تهاتر pair (customer ↔ supplier/contact). */
    public static function isSetoffLeg(array $payment) {
        return ($payment['type'] ?? '') === 'setoff' && !empty($payment['setoff_group_id']);
    }

    public function ensureSetoffColumn() {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        $col = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'setoff_group_id'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `payments` ADD COLUMN `setoff_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL");
            @$this->conn->query("ALTER TABLE `payments` ADD INDEX `idx_setoff_group` (`setoff_group_id`)");
        }
        $t = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'type'");
        if ($t && ($row = $t->fetch_assoc()) && strpos((string)($row['Type'] ?? ''), 'setoff') === false) {
            $this->conn->query("ALTER TABLE `payments` MODIFY COLUMN `type` enum('cash','check','endorse_check','check_bounce','setoff') COLLATE utf8mb4_unicode_ci NOT NULL");
        }
    }

    /** Batch id for multi-check ras-giri / endorse transfers. */
    public function ensureTransferGroupColumn() {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        $col = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'transfer_group_id'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `payments` ADD COLUMN `transfer_group_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL");
            @$this->conn->query("ALTER TABLE `payments` ADD INDEX `idx_transfer_group` (`transfer_group_id`)");
        }
    }

    public static function newTransferGroupId() {
        return sprintf(
            '%s-%s',
            bin2hex(random_bytes(8)),
            substr(str_replace('.', '', uniqid('', true)), -8)
        );
    }

    /**
     * Attach counterparty fields onto setoff payment rows (for invoice UI / print).
     */
    public function enrichSetoffMeta(array &$payments) {
        $this->ensureSetoffColumn();
        $groups = [];
        foreach ($payments as $p) {
            if (self::isSetoffLeg($p)) {
                $groups[(string)$p['setoff_group_id']] = true;
            }
        }
        if (empty($groups)) {
            return;
        }
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $gids = array_keys($groups);
        $ph = implode(',', array_fill(0, count($gids), '?'));
        $types = str_repeat('s', count($gids));
        $sql = "SELECT p.id, p.setoff_group_id, p.person_id, p.person_type, p.invoiceId, p.invoiceType,
                       p.transaction_type, p.amount,
                       COALESCE(s.name, ct.name, cust.name, pr.name) AS person_name
                FROM payments p
                LEFT JOIN suppliers s ON p.person_type = 'supplier' AND p.person_id = s.id
                LEFT JOIN contacts ct ON p.person_type = 'contact' AND p.person_id = ct.id
                LEFT JOIN customers cust ON p.person_type = 'customer' AND p.person_id = cust.id
                LEFT JOIN partners pr ON p.person_type = 'partner' AND p.person_id = pr.id
                WHERE p.entity_id = ? AND p.type = 'setoff' AND p.setoff_group_id IN ($ph)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i' . $types, $entityId, ...$gids);
        $stmt->execute();
        $byGroup = [];
        foreach (db_stmt_to_assoc_array($stmt) as $leg) {
            $byGroup[(string)$leg['setoff_group_id']][] = $leg;
        }
        foreach ($payments as &$p) {
            if (!self::isSetoffLeg($p)) {
                continue;
            }
            $gid = (string)$p['setoff_group_id'];
            $legs = $byGroup[$gid] ?? [];
            $other = null;
            foreach ($legs as $leg) {
                if ((int)$leg['id'] !== (int)$p['id']) {
                    $other = $leg;
                    break;
                }
            }
            if ($other) {
                $p['setoff_person_id'] = (int)$other['person_id'];
                $p['setoff_person_type'] = $other['person_type'];
                $p['setoff_person_name'] = $other['person_name'] ?? '';
                $p['setoff_invoice_id'] = $other['invoiceId'] ? (int)$other['invoiceId'] : null;
                $p['setoff_invoice_type'] = $other['invoiceType'] ?: null;
            }
        }
        unset($p);
    }

    /**
     * Keep an installment's paid amount in step with the payment booked for it.
     */
    public function adjustInstallmentPaid($installmentId, $delta, $entityId) {
        $installmentId = (int)$installmentId;
        if ($installmentId <= 0 || abs($delta) < 0.005) {
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE installments
             SET status = CASE
                    WHEN GREATEST(0, COALESCE(paid_amount, 0) + ?) >= amount - 0.005 THEN 'paid'
                    WHEN GREATEST(0, COALESCE(paid_amount, 0) + ?) > 0.005 THEN 'partially_paid'
                    WHEN due_date < CURDATE() THEN 'overdue'
                    ELSE 'pending' END,
                 paid_date = IF(GREATEST(0, COALESCE(paid_amount, 0) + ?) > 0.005, paid_date, NULL),
                 paid_amount = GREATEST(0, COALESCE(paid_amount, 0) + ?)
             WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('ddddii', $delta, $delta, $delta, $delta, $installmentId, $entityId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * اثر پرداخت روی paidAmount فاکتور (با پشتیبانی دوطرفه).
     * فروش: دریافت = + | پرداخت به مشتری = −
     * خرید: پرداخت = + | دریافت از تأمین‌کننده = −
     */
    public function invoicePaidDelta($invoiceType, $transactionType, $amount) {
        $amount = floatval($amount);
        if ($invoiceType === 'sales' || $invoiceType === 'sales_return' || $invoiceType === 'asset_sale') {
            return ($transactionType === 'receipt') ? $amount : -$amount;
        }
        if ($invoiceType === 'purchase' || $invoiceType === 'purchase_return' || $invoiceType === 'asset_purchase') {
            return ($transactionType === 'payment') ? $amount : -$amount;
        }
        return $amount;
    }

    public function ensureAllocatedColumn() {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        $col = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'is_allocated'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `payments` ADD COLUMN `is_allocated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `invoiceType`");
        }
    }

    /**
     * ۱۰ تراکنش آخر علی‌الحساب (بدون فاکتور) برای مشتری/تأمین‌کننده.
     */
    public function getAdvancePayments($personId, $personType, $limit = 10) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'نشست نامعتبر است.', 'statusCode' => 401];
        }
        $personId = intval($personId);
        $limit = max(1, min(50, intval($limit)));
        if ($personId <= 0 || !in_array($personType, ['customer', 'supplier'], true)) {
            return ['error' => 'شخص نامعتبر است.', 'statusCode' => 400];
        }
        $this->ensurePersonOwnership($personId, $personType, $entityId);
        $this->ensureAllocatedColumn();

        $sql = "SELECT p.id, p.date, p.amount, p.transaction_type, p.type, p.description, p.account_id,
                       a.name AS account_name
                FROM payments p
                LEFT JOIN accounts a ON a.id = p.account_id
                WHERE p.entity_id = ? AND p.person_type = ? AND p.person_id = ?
                  AND p.invoiceId IS NULL
                  AND p.type <> 'setoff'
                ORDER BY p.date DESC, p.id DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('isii', $entityId, $personType, $personId, $limit);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return ['success' => true, 'data' => $rows];
    }

    /**
     * اتصال پرداخت علی‌الحساب موجود به فاکتور — بدون تغییر موجودی بانک.
     */
    public function attachToInvoice($paymentId, $invoiceId, $invoiceType, $manageTransaction = true) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'نشست نامعتبر است.', 'statusCode' => 401];
        }
        $paymentId = intval($paymentId);
        $invoiceId = intval($invoiceId);
        if ($paymentId <= 0 || $invoiceId <= 0 || !in_array($invoiceType, ['sales', 'purchase'], true)) {
            return ['error' => 'پارامتر نامعتبر است.', 'statusCode' => 400];
        }

        $this->ensureAllocatedColumn();
        $this->ensureInvoiceOwnership($invoiceId, $invoiceType, $entityId);

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $stmt = $this->conn->prepare("SELECT * FROM payments WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $paymentId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $payment = $rows[0] ?? null;
            if (!$payment) {
                throw new Exception('تراکنش یافت نشد.', 404);
            }
            if (($payment['type'] ?? '') === 'setoff' || !empty($payment['setoff_group_id'])) {
                throw new Exception('ردیف تهاتر قابل اتصال به‌عنوان علی‌الحساب نیست.', 400);
            }
            if (!empty($payment['invoiceId'])) {
                if (intval($payment['invoiceId']) === $invoiceId && ($payment['invoiceType'] ?? '') === $invoiceType) {
                    if ($manageTransaction) {
                        $this->conn->commit();
                    }
                    return ['success' => true, 'id' => $paymentId, 'already_attached' => true];
                }
                throw new Exception('این تراکنش قبلاً به فاکتور دیگری متصل است.', 400);
            }

            $personCol = $invoiceType === 'sales' ? 'customerId' : 'supplierId';
            $personType = $invoiceType === 'sales' ? 'customer' : 'supplier';
            $invTable = $this->resolveInvoiceTable($invoiceType);
            $invStmt = $this->conn->prepare("SELECT `{$personCol}` AS person_id FROM `{$invTable}` WHERE id = ? AND entity_id = ?");
            $invStmt->bind_param('ii', $invoiceId, $entityId);
            $invStmt->execute();
            $invRows = db_stmt_to_assoc_array($invStmt);
            if (empty($invRows[0]['person_id'])) {
                throw new Exception('فاکتور یافت نشد.', 404);
            }
            if (intval($payment['person_id']) !== intval($invRows[0]['person_id']) || ($payment['person_type'] ?? '') !== $personType) {
                throw new Exception('تراکنش متعلق به شخص این فاکتور نیست.', 400);
            }

            $upd = $this->conn->prepare("UPDATE payments SET invoiceId = ?, invoiceType = ?, is_allocated = 1 WHERE id = ? AND entity_id = ?");
            $upd->bind_param('isii', $invoiceId, $invoiceType, $paymentId, $entityId);
            if (!$upd->execute()) {
                throw new Exception('خطا در اتصال تراکنش.');
            }

            $delta = $this->invoicePaidDelta($invoiceType, $payment['transaction_type'], $payment['amount']);
            $paidUpd = $this->conn->prepare("UPDATE `{$invTable}` SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?");
            $paidUpd->bind_param('dii', $delta, $invoiceId, $entityId);
            $paidUpd->execute();

            if ($manageTransaction) {
                $this->conn->commit();
            }
            return ['success' => true, 'id' => $paymentId, 'paid_delta' => $delta];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * جدا کردن پرداخت تخصیص‌یافته از فاکتور — بدون تغییر موجودی بانک (برمی‌گردد به علی‌الحساب).
     */
    public function detachFromInvoice($paymentId, $manageTransaction = true) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'نشست نامعتبر است.', 'statusCode' => 401];
        }
        $paymentId = intval($paymentId);
        $this->ensureAllocatedColumn();

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $stmt = $this->conn->prepare("SELECT * FROM payments WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $paymentId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $payment = $rows[0] ?? null;
            if (!$payment) {
                throw new Exception('تراکنش یافت نشد.', 404);
            }
            if (empty($payment['invoiceId'])) {
                if ($manageTransaction) {
                    $this->conn->commit();
                }
                return ['success' => true, 'id' => $paymentId, 'already_detached' => true];
            }

            $invoiceId = intval($payment['invoiceId']);
            $invoiceType = $payment['invoiceType'];
            $invTable = $this->resolveInvoiceTable($invoiceType);
            $delta = $this->invoicePaidDelta($invoiceType, $payment['transaction_type'], $payment['amount']);

            $upd = $this->conn->prepare("UPDATE payments SET invoiceId = NULL, invoiceType = NULL, is_allocated = 0 WHERE id = ? AND entity_id = ?");
            $upd->bind_param('ii', $paymentId, $entityId);
            $upd->execute();

            $paidUpd = $this->conn->prepare("UPDATE `{$invTable}` SET paidAmount = paidAmount - ? WHERE id = ? AND entity_id = ?");
            $paidUpd->bind_param('dii', $delta, $invoiceId, $entityId);
            $paidUpd->execute();

            if ($manageTransaction) {
                $this->conn->commit();
            }
            return ['success' => true, 'id' => $paymentId];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    public function save($data, $manageTransaction = true) {
        $type = $data['type'] ?? '';
        $transaction_type = $data['transaction_type'] ?? '';
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            throw new Exception('شناسه مجموعه تجاری نامعتبر است.', 401);
        }

        $id = isset($data['id']) && $data['id'] !== '' ? intval($data['id']) : null;
        $invoiceId = isset($data['invoiceId']) && $data['invoiceId'] !== '' ? intval($data['invoiceId']) : null;
        $invoiceType = $data['invoiceType'] ?? null;
        $person_id = isset($data['person_id']) && $data['person_id'] !== '' ? intval($data['person_id']) : null;
        $person_type = $data['person_type'] ?? null;
        // Client sends locale-formatted amounts ("1,000,000"); floatval alone would truncate.
        $amount = floatval(str_replace(',', '', (string)($data['amount'] ?? 0)));
        $date = $data['date'];
        $description = $data['description'] ?? '';
        $account_id = isset($data['account_id']) && $data['account_id'] !== '' && $data['account_id'] !== null ? intval($data['account_id']) : null;
        $checkId = isset($data['checkId']) && $data['checkId'] !== '' && $data['checkId'] !== null ? intval($data['checkId']) : null;

        // Validate amount
        if ($amount <= 0) {
            throw new Exception('مبلغ باید بیشتر از صفر باشد.', 400);
        }
        
        // Validate required fields based on type
        if ($type === 'setoff') {
            throw new Exception('تهاتر باید از مسیر اختصاصی (فاکتور فروش) ثبت شود.', 400);
        }
        if ($type === 'cash' && !$account_id) {
            throw new Exception('برای تراکنش نقدی، حساب الزامی است.', 400);
        }
        if ($type === 'check' && empty($data['checkDetails'])) {
            throw new Exception('برای تراکنش چکی، اطلاعات چک الزامی است.', 400);
        }
        if ($type === 'endorse_check' && empty($checkId)) {
            throw new Exception('برای خرج چک دریافتی، انتخاب چک الزامی است.', 400);
        }

        $isOtherParty = ($person_type === 'other');
        if ($isOtherParty) {
            $person_id = null;
            if ($invoiceId) {
                throw new Exception('تراکنش متفرقه به فاکتور وصل نمی‌شود.', 400);
            }
            if ($type !== 'cash') {
                throw new Exception('تراکنش متفرقه فقط به‌صورت نقدی (واریز/برداشت حساب) قابل ثبت است.', 400);
            }
            if (trim((string)$description) === '') {
                throw new Exception('برای تراکنش متفرقه، نوشتن شرح الزامی است (مثلاً سود بانکی یا نام شخص).', 400);
            }
        }
        if (!$invoiceId && !$person_id && !$isOtherParty) {
            throw new Exception('یا فاکتور یا شخص باید مشخص شود.', 400);
        }

        if ($invoiceId) {
            if (!$invoiceType) {
                throw new Exception('نوع فاکتور برای این تراکنش مشخص نشده است.', 400);
            }
            $this->ensureInvoiceOwnership($invoiceId, $invoiceType, $entity_id);
        }
        if ($person_id && $person_type) {
            $this->ensurePersonOwnership($person_id, $person_type, $entity_id);
        }
        if ($account_id) {
            $this->ensureAccountOwnership($account_id, $entity_id);
        }
        if ($checkId) {
            $this->ensureCheckOwnership($checkId, $entity_id);
        }

        assert_transaction_date_editable($this->conn, $entity_id, $date);
        if ($id) {
            $oldDateStmt = $this->conn->prepare("SELECT date FROM payments WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $id, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $accountModel = new Account($this->conn);
            $oldPayment = null;

            if ($id) {
                $stmt_old = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
                $stmt_old->bind_param("ii", $id, $entity_id);
                $stmt_old->execute();
                $result = db_stmt_to_assoc_array($stmt_old);
                $oldPayment = $result[0] ?? null;

                if ($oldPayment && ($oldPayment['type'] ?? '') === 'check_bounce') {
                    throw new Exception('این ردیف، سند برگشت چک است و فقط از بخش چک‌ها (لغو برگشت) قابل تغییر است.', 400);
                }
                if ($oldPayment && self::isTransferLeg($oldPayment)) {
                    throw new Exception('این تراکنش یک انتقال وجه بین حساب‌هاست؛ برای اصلاح، انتقال را حذف و دوباره ثبت کنید.', 400);
                }
                if ($oldPayment && self::isSetoffLeg($oldPayment)) {
                    throw new Exception('این تراکنش تهاتر است؛ برای اصلاح، آن را حذف و دوباره از فاکتور فروش ثبت کنید.', 400);
                }

                if ($oldPayment) {
                    // Preserve invoice link when editing from check/transaction UI
                    // (standalone modal does not send invoiceId / invoiceType).
                    if ($invoiceId === null && !empty($oldPayment['invoiceId'])) {
                        $invoiceId = (int)$oldPayment['invoiceId'];
                        if (empty($invoiceType)) {
                            $invoiceType = $oldPayment['invoiceType'] ?? null;
                        }
                    }

                    // Revert old transaction's financial impact (wallet on the cash account only)
                    if ($oldPayment['type'] === 'cash' && $oldPayment['account_id']) {
                        $amountToRevert = ($oldPayment['transaction_type'] === 'receipt') ? -$oldPayment['amount'] : $oldPayment['amount'];
                        $accountModel->updateBalance($oldPayment['account_id'], $amountToRevert, $entity_id);
                    }
                    if ($oldPayment['type'] === 'endorse_check' && $oldPayment['checkId']) {
                         // FIX: Only revert if check is still 'endorsed', not if subsequently cashed
                         $stmt_chk = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ?");
                         $stmt_chk->bind_param("ii", $oldPayment['checkId'], $entity_id);
                         $stmt_chk->execute();
                         $chk_result = db_stmt_to_assoc_array($stmt_chk);
                         $chkStatus = $chk_result[0]['status'] ?? null;
                         
                         if ($chkStatus === 'endorsed') {
                             $stmt_revert_endorse = $this->conn->prepare("UPDATE checks SET status = 'in_hand' WHERE id = ? AND entity_id = ?");
                             $stmt_revert_endorse->bind_param("ii", $oldPayment['checkId'], $entity_id);
                             $stmt_revert_endorse->execute();
                             $stmt_revert_endorse->close();
                         }
                    }
                    if ($oldPayment['invoiceId']) {
                        $invoiceTable = $this->resolveInvoiceTable($oldPayment['invoiceType']);
                        $oldDelta = $this->invoicePaidDelta(
                            $oldPayment['invoiceType'],
                            $oldPayment['transaction_type'],
                            $oldPayment['amount']
                        );
                        $stmt_revert_paid = $this->conn->prepare("UPDATE `{$invoiceTable}` SET paidAmount = paidAmount - ? WHERE id = ? AND entity_id = ?");
                        $stmt_revert_paid->bind_param("dii", $oldDelta, $oldPayment['invoiceId'], $entity_id);
                        $stmt_revert_paid->execute();
                        $stmt_revert_paid->close();
                    }
                    // Capital partnerDelta no longer touches wallet current_balance.
                    // (Kept block removed — صورتحساب اشخاص rebuilds claim from payments.)
                    
                    // FIX: If old payment was check type and new type is NOT check, delete orphan check
                    if ($oldPayment['type'] === 'check' && $oldPayment['checkId'] && $type !== 'check') {
                        $stmt_check_status = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ?");
                        $stmt_check_status->bind_param("ii", $oldPayment['checkId'], $entity_id);
                        $stmt_check_status->execute();
                        $check_result = db_stmt_to_assoc_array($stmt_check_status);
                        $oldCheckStatus = $check_result[0]['status'] ?? null;

                        // A cashed/endorsed check has already moved real money into
                        // an account via its lifecycle; converting the payment to
                        // cash here would recognize the amount a second time.
                        if ($oldCheckStatus && in_array($oldCheckStatus, ['cashed', 'endorsed'], true)) {
                            throw new Exception('این چک وصول یا خرج شده است و قابل تبدیل به نوع تراکنش دیگر نیست. ابتدا وضعیت چک را اصلاح کنید.', 400);
                        }

                        // Only delete check if it's still in hand / payable
                        if ($oldCheckStatus && in_array($oldCheckStatus, ['in_hand', 'payable'])) {
                            $stmt_del_check = $this->conn->prepare("DELETE FROM checks WHERE id = ? AND entity_id = ?");
                            $stmt_del_check->bind_param("ii", $oldPayment['checkId'], $entity_id);
                            $stmt_del_check->execute();
                            $stmt_del_check->close();
                        }
                        // Reset checkId since we're changing type
                        $checkId = null;
                    }
                }
            }

            // Determine checkId - only keep old checkId if we're still using check type
            // For endorse_check, checkId should be preserved as it references an existing check
            if ($type === 'check') {
                // For 'check' type, use the provided checkId or keep the old one
                $checkId = $checkId ?: ($oldPayment['checkId'] ?? null);
            } elseif ($type !== 'endorse_check') {
                // For other types (cash, etc), clear the checkId
                $checkId = null;
            }
            // For 'endorse_check', $checkId is already set from line 93, so don't modify it
            
            if ($type === 'check' && !empty($data['checkDetails'])) {
                $checkNumber = trim($data['checkDetails']['checkNumber']);
                
                if ($checkId) {
                    // A check that was already cashed/endorsed/bounced has moved money or
                    // changed hands; its number/amount/due date are part of that history.
                    $stCur = $this->conn->prepare("SELECT status, type, amount, checkNumber, dueDate FROM checks WHERE id = ? AND entity_id = ? FOR UPDATE");
                    $stCur->bind_param('ii', $checkId, $entity_id);
                    $stCur->execute();
                    $curCheck = db_stmt_to_assoc_array($stCur)[0] ?? null;
                    if ($curCheck && !in_array($curCheck['status'], self::CHECK_OPEN_STATUSES, true)) {
                        $changed = abs((float)$curCheck['amount'] - $amount) > 0.005
                            || trim((string)$curCheck['checkNumber']) !== $checkNumber
                            || (string)$curCheck['dueDate'] !== (string)($data['checkDetails']['dueDate'] ?? $curCheck['dueDate']);
                        if ($changed) {
                            throw new Exception('این چک در وضعیت «' . self::checkStatusLabel($curCheck['status']) . '» است؛ مبلغ، شماره یا سررسید آن قابل تغییر نیست.', 400);
                        }
                    }
                    // Direction follows the payment (receipt → received check, payment → payable).
                    if ($curCheck && in_array($curCheck['status'], self::CHECK_OPEN_STATUSES, true)) {
                        $wantType = ($transaction_type === 'receipt') ? 'received' : 'payable';
                        if ($curCheck['type'] !== $wantType) {
                            $wantStatus = ($wantType === 'received') ? 'in_hand' : 'payable';
                            $stDir = $this->conn->prepare("UPDATE checks SET type = ?, status = ? WHERE id = ? AND entity_id = ?");
                            $stDir->bind_param('ssii', $wantType, $wantStatus, $checkId, $entity_id);
                            $stDir->execute();
                            $stDir->close();
                        }
                    }
                    // If editing a payment with a check, update the check details as well
                    // Check for duplicate check number (excluding current check)
                    $stmt_dup = $this->conn->prepare("SELECT id FROM checks WHERE checkNumber = ? AND entity_id = ? AND id != ?");
                    $stmt_dup->bind_param("sii", $checkNumber, $entity_id, $checkId);
                    $stmt_dup->execute();
                    $dup_result = db_stmt_to_assoc_array($stmt_dup);
                    if (!empty($dup_result)) {
                        throw new Exception('شماره چک تکراری است. این شماره چک قبلاً ثبت شده است.', 400);
                    }
                    
                    $issuerName = trim($data['checkDetails']['issuerName'] ?? '') ?: null;
                    // Never wipe invoice linkage on check edit; only set when known.
                    if ($invoiceId !== null && $invoiceType) {
                        $stmt_check = $this->conn->prepare("UPDATE checks SET checkNumber=?, dueDate=?, bankName=?, issuerName=?, amount=?, invoiceId=?, invoiceType=? WHERE id=? AND entity_id = ?");
                        $stmt_check->bind_param("ssssdisii", $checkNumber, $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $issuerName, $amount, $invoiceId, $invoiceType, $checkId, $entity_id);
                    } else {
                        $stmt_check = $this->conn->prepare("UPDATE checks SET checkNumber=?, dueDate=?, bankName=?, issuerName=?, amount=? WHERE id=? AND entity_id = ?");
                        $stmt_check->bind_param("ssssdii", $checkNumber, $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $issuerName, $amount, $checkId, $entity_id);
                    }
                    $stmt_check->execute();
                    $stmt_check->close();
                } else {
                    // Creating a new check - check for duplicate check number
                    $stmt_dup = $this->conn->prepare("SELECT id, invoiceId, invoiceType FROM checks WHERE checkNumber = ? AND entity_id = ?");
                    $stmt_dup->bind_param("si", $checkNumber, $entity_id);
                    $stmt_dup->execute();
                    $dup_result = db_stmt_to_assoc_array($stmt_dup);
                    $issuerName = trim($data['checkDetails']['issuerName'] ?? '') ?: null;
                    if (!empty($dup_result)) {
                        $existingCheck = $dup_result[0];
                        // Reuse only when both payments belong to the SAME invoice.
                        // null == null must not count: two standalone (no-invoice)
                        // payments would otherwise take over each other's check.
                        if ($invoiceId !== null && $invoiceType !== null
                            && $existingCheck['invoiceId'] == $invoiceId
                            && $existingCheck['invoiceType'] === $invoiceType) {
                            $checkId = $existingCheck['id'];
                            // Update check details
                            $stmt_check = $this->conn->prepare("UPDATE checks SET dueDate=?, bankName=?, issuerName=?, amount=? WHERE id=? AND entity_id = ?");
                            $stmt_check->bind_param("sssdii", $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $issuerName, $amount, $checkId, $entity_id);
                            $stmt_check->execute();
                            $stmt_check->close();
                        } else {
                            throw new Exception('شماره چک تکراری است. این شماره چک قبلاً ثبت شده است.', 400);
                        }
                    } else {
                        $checkType = ($transaction_type === 'receipt') ? 'received' : 'payable';
                        $checkStatus = ($checkType === 'received') ? 'in_hand' : 'payable';
                        $stmt_check = $this->conn->prepare("INSERT INTO checks (entity_id, type, status, checkNumber, dueDate, bankName, issuerName, amount, invoiceId, invoiceType) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_check->bind_param("issssssdis", $entity_id, $checkType, $checkStatus, $checkNumber, $data['checkDetails']['dueDate'], $data['checkDetails']['bankName'], $issuerName, $amount, $invoiceId, $invoiceType);
                        $stmt_check->execute();
                        $checkId = $this->conn->insert_id; // Get the new check ID
                        $stmt_check->close();
                    }
                }
            }

            if ($type === 'endorse_check' && $checkId) {
                // The previous endorsement (when editing) was already reverted above,
                // so the check must now be an un-spent received check.
                $stEnd = $this->conn->prepare("SELECT type, status, amount, checkNumber FROM checks WHERE id = ? AND entity_id = ? FOR UPDATE");
                $stEnd->bind_param('ii', $checkId, $entity_id);
                $stEnd->execute();
                $endCheck = db_stmt_to_assoc_array($stEnd)[0] ?? null;
                if (!$endCheck) {
                    throw new Exception('چک انتخاب‌شده یافت نشد.', 404);
                }
                if ($endCheck['type'] !== 'received') {
                    throw new Exception('فقط چک‌های دریافتی را می‌توان خرج (واگذار) کرد.', 400);
                }
                if ($endCheck['status'] !== 'in_hand') {
                    throw new Exception('چک شماره ' . $endCheck['checkNumber'] . ' در وضعیت «' . self::checkStatusLabel($endCheck['status']) . '» است و قابل خرج نیست.', 400);
                }
                if ($transaction_type !== 'payment') {
                    throw new Exception('خرج چک فقط به‌صورت «پرداخت» به طرف حساب ثبت می‌شود.', 400);
                }
                if (abs((float)$endCheck['amount'] - $amount) > 0.5) {
                    throw new Exception('مبلغ خرج چک باید برابر مبلغ چک (' . number_format((float)$endCheck['amount']) . ') باشد.', 400);
                }
            }

            // For nullable integer fields, we need to handle NULL properly
            // mysqli bind_param with 'i' type converts null to 0, which violates FK constraints
            // Solution: Build query dynamically or use NULLIF in values
            
            if ($id) {
                $query = "UPDATE payments SET 
                    invoiceId = " . ($invoiceId !== null ? intval($invoiceId) : "NULL") . ",
                    invoiceType = " . ($invoiceType !== null ? "'" . $this->conn->real_escape_string($invoiceType) . "'" : "NULL") . ",
                    person_id = " . ($person_id !== null ? intval($person_id) : "NULL") . ",
                    person_type = " . ($person_type !== null ? "'" . $this->conn->real_escape_string($person_type) . "'" : "NULL") . ",
                    transaction_type = '" . $this->conn->real_escape_string($transaction_type) . "',
                    type = '" . $this->conn->real_escape_string($type) . "',
                    amount = " . floatval($amount) . ",
                    date = '" . $this->conn->real_escape_string($date) . "',
                    description = " . ($description !== '' ? "'" . $this->conn->real_escape_string($description) . "'" : "NULL") . ",
                    checkId = " . ($checkId !== null ? intval($checkId) : "NULL") . ",
                    account_id = " . ($account_id !== null ? intval($account_id) : "NULL") . "
                    WHERE id = " . intval($id) . " AND entity_id = " . intval($entity_id);
                if (!$this->conn->query($query)) {
                    throw new Exception('خطا در بروزرسانی پرداخت: ' . $this->conn->error);
                }
                $paymentId = $id;
            } else {
                $query = "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) VALUES (
                    " . intval($entity_id) . ",
                    " . ($invoiceId !== null ? intval($invoiceId) : "NULL") . ",
                    " . ($invoiceType !== null ? "'" . $this->conn->real_escape_string($invoiceType) . "'" : "NULL") . ",
                    " . ($person_id !== null ? intval($person_id) : "NULL") . ",
                    " . ($person_type !== null ? "'" . $this->conn->real_escape_string($person_type) . "'" : "NULL") . ",
                    '" . $this->conn->real_escape_string($transaction_type) . "',
                    '" . $this->conn->real_escape_string($type) . "',
                    " . floatval($amount) . ",
                    '" . $this->conn->real_escape_string($date) . "',
                    " . ($description !== '' ? "'" . $this->conn->real_escape_string($description) . "'" : "NULL") . ",
                    " . ($checkId !== null ? intval($checkId) : "NULL") . ",
                    " . ($account_id !== null ? intval($account_id) : "NULL") . "
                )";
                
                if (!$this->conn->query($query)) {
                    throw new Exception('خطا در ذخیره پرداخت: ' . $this->conn->error);
                }
                $paymentId = $this->conn->insert_id;
            }

            // Apply new transaction's financial impact on the selected account (WALLET):
            // receipt +, payment − — including partner personal accounts in بانک‌ها.
            if ($type === 'cash') {
                $amountToUpdate = ($transaction_type === 'receipt') ? $amount : -$amount;
                
                if ($transaction_type === 'payment') {
                    $acct = $accountModel->getAccountForEntity($account_id, $entity_id);
                    if ($acct && ($acct['current_balance'] - $amount) < 0) {
                        error_log("Warning: Payment ID {$paymentId} created negative balance in account {$account_id}");
                    }
                }
                
                $accountModel->updateBalance($account_id, $amountToUpdate, $entity_id);
            }
            // Capital claim vs company (واریز/برداشت شریک روی حساب بانکی شرکت) is shown
            // only on صورتحساب اشخاص — it must NOT change the personal account wallet balance.
            // When cash is booked directly on the personal account, the wallet update above
            // already handled it.
            if ($type === 'endorse_check' && $checkId) {
                $stmt_endorse = $this->conn->prepare("UPDATE checks SET status = 'endorsed' WHERE id = ? AND entity_id = ?");
                $stmt_endorse->bind_param("ii", $checkId, $entity_id);
                $stmt_endorse->execute();
                $stmt_endorse->close();
            }
              if ($invoiceId) {
                  $invoiceTable = $this->resolveInvoiceTable($invoiceType);
                  $paidDelta = $this->invoicePaidDelta($invoiceType, $transaction_type, $amount);
                  $stmt_update_paid = $this->conn->prepare("UPDATE `{$invoiceTable}` SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?");
                 $stmt_update_paid->bind_param("dii", $paidDelta, $invoiceId, $entity_id);
                 $stmt_update_paid->execute();
                 $stmt_update_paid->close();
            }

            // Installment payments: keep the installment's paid amount in step.
            $installmentId = !empty($data['installment_id']) ? (int)$data['installment_id'] : (int)($oldPayment['installment_id'] ?? 0);
            if ($installmentId > 0) {
                $sameInst = $oldPayment && (int)($oldPayment['installment_id'] ?? 0) === $installmentId;
                if (!$sameInst) {
                    $setInst = $this->conn->prepare("UPDATE payments SET installment_id = ? WHERE id = ? AND entity_id = ?");
                    $setInst->bind_param('iii', $installmentId, $paymentId, $entity_id);
                    $setInst->execute();
                    $setInst->close();
                }
                $oldAmt = $sameInst ? (float)$oldPayment['amount'] : 0.0;
                $this->adjustInstallmentPaid($installmentId, $amount - $oldAmt, $entity_id);
            }

            if ($manageTransaction) {
                $this->conn->commit();
            }

            // Notify Medusa when a receipt is booked against an online-store invoice
            // (covers bank-import matches, manual receipts, and integration register_payment).
            // Setoff is never real cash — exclude it.
            if (
                !$id
                && !empty($paymentId)
                && $invoiceId
                && $invoiceType === 'sales'
                && $transaction_type === 'receipt'
                && $type !== 'setoff'
            ) {
                try {
                    require_once __DIR__ . '/../core/IntegrationService.php';
                    (new IntegrationService($this->conn))->notifyOnlineStorePayment(
                        $entity_id,
                        $invoiceId,
                        $paymentId,
                        $amount
                    );
                } catch (Throwable $ignore) {
                    // never block payment save on integration failure
                }
            }

            return ['success' => true, 'id' => $paymentId];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => 'خطا در ذخیره پرداخت: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * تهاتر / پرداخت به جای: مشتری فاکتور فروش را با واریز مستقیم به تأمین‌کننده/شخص تسویه می‌کند.
     * دو پایه لینک‌شده، بدون حرکت موجودی بانک.
     *
     * @param array $data sales_invoice_id, amount, date, description,
     *                    customer_id, setoff_person_type (supplier|contact),
     *                    setoff_person_id, setoff_invoice_id? (purchase)
     */
    public function saveSetoff(array $data, $manageTransaction = true) {
        $this->ensureSetoffColumn();
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $amount = floatval(str_replace(',', '', (string)($data['amount'] ?? 0)));
        $date = $data['date'] ?? '';
        $description = trim((string)($data['description'] ?? ''));
        $customerId = (int)($data['customer_id'] ?? $data['person_id'] ?? 0);
        $salesInvoiceId = (int)($data['sales_invoice_id'] ?? $data['invoiceId'] ?? 0);
        $cpType = $data['setoff_person_type'] ?? '';
        $cpId = (int)($data['setoff_person_id'] ?? 0);
        $purchaseInvoiceId = !empty($data['setoff_invoice_id']) ? (int)$data['setoff_invoice_id'] : null;

        if ($amount <= 0) {
            return ['error' => 'مبلغ تهاتر باید بیشتر از صفر باشد.', 'statusCode' => 400];
        }
        if (!validate_date_format($date)) {
            return ['error' => 'فرمت تاریخ نامعتبر است.', 'statusCode' => 400];
        }
        if ($salesInvoiceId <= 0 || $customerId <= 0) {
            return ['error' => 'فاکتور فروش و مشتری برای تهاتر الزامی است.', 'statusCode' => 400];
        }
        if (!in_array($cpType, ['supplier', 'contact'], true) || $cpId <= 0) {
            return ['error' => 'مقصد تهاتر باید تأمین‌کننده یا سایر اشخاص باشد.', 'statusCode' => 400];
        }
        if ($cpType === 'supplier' && $cpId === $customerId) {
            // unlikely same id space but guard cross-type anyway — no-op
        }

        try {
            $this->ensureInvoiceOwnership($salesInvoiceId, 'sales', $entity_id);
            $this->ensurePersonOwnership($customerId, 'customer', $entity_id);
            $this->ensurePersonOwnership($cpId, $cpType, $entity_id);
            if ($purchaseInvoiceId) {
                if ($cpType !== 'supplier') {
                    return ['error' => 'اتصال به فاکتور خرید فقط برای تأمین‌کننده مجاز است.', 'statusCode' => 400];
                }
                $this->ensureInvoiceOwnership($purchaseInvoiceId, 'purchase', $entity_id);
                $stPi = $this->conn->prepare(
                    "SELECT supplierId, (totalAmount - discount + COALESCE(tax_amount,0) - paidAmount) AS remaining
                     FROM purchase_invoices WHERE id = ? AND entity_id = ?"
                );
                $stPi->bind_param('ii', $purchaseInvoiceId, $entity_id);
                $stPi->execute();
                $pi = db_stmt_to_assoc_array($stPi)[0] ?? null;
                if (!$pi || (int)$pi['supplierId'] !== $cpId) {
                    return ['error' => 'فاکتور خرید متعلق به تأمین‌کننده انتخاب‌شده نیست.', 'statusCode' => 400];
                }
                if ((float)$pi['remaining'] + 0.5 < $amount) {
                    return ['error' => 'مبلغ تهاتر از مانده فاکتور خرید بیشتر است.', 'statusCode' => 400];
                }
            }
            assert_transaction_date_editable($this->conn, $entity_id, $date);

            $stSi = $this->conn->prepare(
                "SELECT customerId FROM sales_invoices WHERE id = ? AND entity_id = ?"
            );
            $stSi->bind_param('ii', $salesInvoiceId, $entity_id);
            $stSi->execute();
            $si = db_stmt_to_assoc_array($stSi)[0] ?? null;
            if (!$si || (int)$si['customerId'] !== $customerId) {
                return ['error' => 'فاکتور فروش متعلق به این مشتری نیست.', 'statusCode' => 400];
            }

            $cpLabel = $cpType === 'supplier' ? 'تأمین‌کننده' : 'سایر شخص';
            $nameStmtMap = [
                'supplier' => "SELECT name FROM suppliers WHERE id = ? AND entity_id = ?",
                'contact' => "SELECT name FROM contacts WHERE id = ? AND entity_id = ?",
            ];
            $ns = $this->conn->prepare($nameStmtMap[$cpType]);
            $ns->bind_param('ii', $cpId, $entity_id);
            $ns->execute();
            $cpName = db_stmt_to_assoc_array($ns)[0]['name'] ?? ('#' . $cpId);

            $custStmt = $this->conn->prepare("SELECT name FROM customers WHERE id = ? AND entity_id = ?");
            $custStmt->bind_param('ii', $customerId, $entity_id);
            $custStmt->execute();
            $custName = db_stmt_to_assoc_array($custStmt)[0]['name'] ?? ('#' . $customerId);

            $baseDesc = $description !== '' ? $description : '';
            $salesDesc = 'تهاتر — واریز مستقیم مشتری به ' . $cpLabel . ' «' . $cpName . '»'
                . ($purchaseInvoiceId ? (' بابت فاکتور خرید #' . $purchaseInvoiceId) : '')
                . ($baseDesc !== '' ? (' — ' . $baseDesc) : '');
            $cpDesc = 'تهاتر — دریافت مستقیم از مشتری «' . $custName . '» بابت فاکتور فروش #' . $salesInvoiceId
                . ($baseDesc !== '' ? (' — ' . $baseDesc) : '');

            $groupId = sprintf(
                '%s-%s',
                bin2hex(random_bytes(8)),
                substr(str_replace('.', '', uniqid('', true)), -8)
            );

            if ($manageTransaction) {
                $this->conn->begin_transaction();
            }
            try {
                $salesPaymentId = $this->insertSetoffLeg([
                    'entity_id' => $entity_id,
                    'invoiceId' => $salesInvoiceId,
                    'invoiceType' => 'sales',
                    'person_id' => $customerId,
                    'person_type' => 'customer',
                    'transaction_type' => 'receipt',
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $salesDesc,
                    'setoff_group_id' => $groupId,
                ]);
                $cpPaymentId = $this->insertSetoffLeg([
                    'entity_id' => $entity_id,
                    'invoiceId' => $purchaseInvoiceId,
                    'invoiceType' => $purchaseInvoiceId ? 'purchase' : null,
                    'person_id' => $cpId,
                    'person_type' => $cpType,
                    'transaction_type' => 'payment',
                    'amount' => $amount,
                    'date' => $date,
                    'description' => $cpDesc,
                    'setoff_group_id' => $groupId,
                ]);

                // paidAmount: sales receipt +, purchase payment +
                $stUp = $this->conn->prepare(
                    "UPDATE sales_invoices SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?"
                );
                $stUp->bind_param('dii', $amount, $salesInvoiceId, $entity_id);
                $stUp->execute();
                $stUp->close();

                if ($purchaseInvoiceId) {
                    $stUp2 = $this->conn->prepare(
                        "UPDATE purchase_invoices SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?"
                    );
                    $stUp2->bind_param('dii', $amount, $purchaseInvoiceId, $entity_id);
                    $stUp2->execute();
                    $stUp2->close();
                }

                if ($manageTransaction) {
                    $this->conn->commit();
                }
                return [
                    'success' => true,
                    'id' => $salesPaymentId,
                    'setoff_group_id' => $groupId,
                    'counterparty_payment_id' => $cpPaymentId,
                ];
            } catch (Exception $e) {
                if ($manageTransaction) {
                    $this->conn->rollback();
                }
                throw $e;
            }
        } catch (Exception $e) {
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => 'خطا در ثبت تهاتر: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    private function insertSetoffLeg(array $leg) {
        $invoiceId = $leg['invoiceId'] !== null ? (int)$leg['invoiceId'] : null;
        $invoiceType = $leg['invoiceType'];
        $query = "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id, setoff_group_id) VALUES (
            " . (int)$leg['entity_id'] . ",
            " . ($invoiceId !== null ? $invoiceId : "NULL") . ",
            " . ($invoiceType !== null ? "'" . $this->conn->real_escape_string($invoiceType) . "'" : "NULL") . ",
            " . (int)$leg['person_id'] . ",
            '" . $this->conn->real_escape_string($leg['person_type']) . "',
            '" . $this->conn->real_escape_string($leg['transaction_type']) . "',
            'setoff',
            " . floatval($leg['amount']) . ",
            '" . $this->conn->real_escape_string($leg['date']) . "',
            '" . $this->conn->real_escape_string($leg['description']) . "',
            NULL,
            NULL,
            '" . $this->conn->real_escape_string($leg['setoff_group_id']) . "'
        )";
        if (!$this->conn->query($query)) {
            throw new Exception('خطا در ذخیره پایه تهاتر: ' . $this->conn->error);
        }
        return (int)$this->conn->insert_id;
    }

    /**
     * Delete both legs of a setoff group and revert invoice paidAmount.
     */
    public function deleteSetoffGroup($groupId, $manageTransaction = true) {
        $this->ensureSetoffColumn();
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }
        $groupId = (string)$groupId;
        if ($groupId === '') {
            return ['error' => 'گروه تهاتر نامعتبر است.', 'statusCode' => 400];
        }

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM payments WHERE entity_id = ? AND type = 'setoff' AND setoff_group_id = ? FOR UPDATE"
            );
            $stmt->bind_param('is', $entity_id, $groupId);
            $stmt->execute();
            $legs = db_stmt_to_assoc_array($stmt);
            if (empty($legs)) {
                if ($manageTransaction) {
                    $this->conn->commit();
                }
                return ['success' => true, 'already_deleted' => true];
            }

            foreach ($legs as $payment) {
                assert_transaction_date_editable($this->conn, $entity_id, $payment['date']);
                if (!empty($payment['invoiceId']) && !empty($payment['invoiceType'])) {
                    $invoiceTable = $this->resolveInvoiceTable($payment['invoiceType']);
                    $paidDelta = $this->invoicePaidDelta(
                        $payment['invoiceType'],
                        $payment['transaction_type'],
                        $payment['amount']
                    );
                    $stmt_revert = $this->conn->prepare(
                        "UPDATE `{$invoiceTable}` SET paidAmount = paidAmount - ? WHERE id = ? AND entity_id = ?"
                    );
                    $stmt_revert->bind_param('dii', $paidDelta, $payment['invoiceId'], $entity_id);
                    $stmt_revert->execute();
                    $stmt_revert->close();
                }
                $delId = (int)$payment['id'];
                $del = $this->conn->prepare("DELETE FROM payments WHERE id = ? AND entity_id = ?");
                $del->bind_param('ii', $delId, $entity_id);
                $del->execute();
                $del->close();
            }

            if ($manageTransaction) {
                $this->conn->commit();
            }
            return ['success' => true];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => 'خطا در حذف تهاتر: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * Open purchase invoices for a supplier (for setoff destination picker).
     */
    public function getOpenPurchaseInvoices($supplierId, $limit = 30) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'نشست نامعتبر است.', 'statusCode' => 401];
        }
        $supplierId = (int)$supplierId;
        $limit = max(1, min(100, (int)$limit));
        if ($supplierId <= 0) {
            return ['error' => 'تأمین‌کننده نامعتبر است.', 'statusCode' => 400];
        }
        $this->ensurePersonOwnership($supplierId, 'supplier', $entityId);
        $sql = "SELECT id, date, invoice_number,
                       (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) AS remaining,
                       (totalAmount - discount + COALESCE(tax_amount, 0)) AS net_total
                FROM purchase_invoices
                WHERE entity_id = ? AND supplierId = ?
                  AND (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) > 0.5
                  AND is_consignment = 0
                ORDER BY date DESC, id DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iii', $entityId, $supplierId, $limit);
        $stmt->execute();
        return ['success' => true, 'data' => db_stmt_to_assoc_array($stmt)];
    }

    /**
     * @param bool $allowSystemRows internal callers (check lifecycle, transfers)
     *        may delete bounce reversals / transfer legs; the UI may not.
     */
    public function delete($id, $preserveCheck = false, $manageTransaction = true, $allowSystemRows = false)
    {
        if (empty($id)) {
            return ['error' => 'شناسه پرداخت نامعتبر است.', 'statusCode' => 400];
        }

        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $this->ensureSetoffColumn();

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ii", $id, $entity_id);
            $stmt->execute();
            $result = db_stmt_to_assoc_array($stmt);
            $payment = $result[0] ?? null;

            if (!$payment) {
                // Idempotent: pair-delete may have already removed this leg.
                if ($manageTransaction) {
                    $this->conn->commit();
                }
                return ['success' => true, 'already_deleted' => true];
            }

            assert_transaction_date_editable($this->conn, $entity_id, $payment['date']);

            if (self::isSetoffLeg($payment)) {
                // Always delete both legs together (even for internal callers).
                $groupId = $payment['setoff_group_id'];
                // Nested transaction: caller may already be in one.
                $res = $this->deleteSetoffGroup($groupId, false);
                if (!empty($res['error'])) {
                    throw new Exception($res['error'], $res['statusCode'] ?? 500);
                }
                if ($manageTransaction) {
                    $this->conn->commit();
                }
                return $res;
            }

            if (!$allowSystemRows) {
                $revStmt = $this->conn->prepare("SELECT 1 FROM payments WHERE reversal_of_payment_id = ? AND entity_id = ? LIMIT 1");
                $revStmt->bind_param('ii', $id, $entity_id);
                $revStmt->execute();
                if (!empty(db_stmt_to_assoc_array($revStmt))) {
                    throw new Exception('برای این تراکنش سند برگشت/عودت چک ثبت شده است؛ ابتدا از بخش چک‌ها آن را لغو کنید.', 400);
                }
                if ($payment['type'] === 'check_bounce') {
                    throw new Exception('این ردیف سند برگشت چک است؛ برای حذف، از بخش چک‌ها «لغو برگشت» را بزنید.', 400);
                }
                if (self::isTransferLeg($payment)) {
                    throw new Exception('این تراکنش یک طرف از انتقال وجه است؛ از «حذف انتقال» استفاده کنید تا هر دو طرف با هم حذف شوند.', 400);
                }
            }

            // A check that was cashed/endorsed/bounced already moved money or changed
            // hands. Deleting its payment (and the check) would leave the bank
            // balance or the other party's records without a source document.
            if ($payment['type'] === 'check' && !empty($payment['checkId']) && !$preserveCheck) {
                $stCs = $this->conn->prepare("SELECT status, checkNumber FROM checks WHERE id = ? AND entity_id = ?");
                $stCs->bind_param('ii', $payment['checkId'], $entity_id);
                $stCs->execute();
                $cs = db_stmt_to_assoc_array($stCs)[0] ?? null;
                if ($cs && !in_array($cs['status'], self::CHECK_OPEN_STATUSES, true)) {
                    throw new Exception('چک شماره ' . $cs['checkNumber'] . ' در وضعیت «' . self::checkStatusLabel($cs['status']) . '» است. ابتدا از بخش چک‌ها وضعیت آن را برگردانید (لغو وصول / عودت / لغو برگشت).', 400);
                }
            }

            if (!empty($payment['installment_id'])) {
                $this->adjustInstallmentPaid($payment['installment_id'], -(float)$payment['amount'], $entity_id);
            }

            $accountModel = new Account($this->conn);
            if ($payment['type'] === 'cash' && $payment['account_id']) {
                $amountToRevert = ($payment['transaction_type'] === 'receipt') ? -$payment['amount'] : $payment['amount'];
                $accountModel->updateBalance($payment['account_id'], $amountToRevert, $entity_id);
            }
            
            if ($payment['type'] === 'endorse_check' && $payment['checkId']) {
                // FIX: Only revert check status if it's still 'endorsed' 
                // Don't revert if it was subsequently cashed or used elsewhere
                $stmt_check_status = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ?");
                $stmt_check_status->bind_param("ii", $payment['checkId'], $entity_id);
                $stmt_check_status->execute();
                $check_result = db_stmt_to_assoc_array($stmt_check_status);
                $currentCheckStatus = $check_result[0]['status'] ?? null;
                
                // Only revert to in_hand if current status is 'endorsed'
                if ($currentCheckStatus === 'endorsed') {
                    $stmt_revert_endorse = $this->conn->prepare("UPDATE checks SET status = 'in_hand' WHERE id = ? AND entity_id = ?");
                    $stmt_revert_endorse->bind_param("ii", $payment['checkId'], $entity_id);
                    $stmt_revert_endorse->execute();
                    $stmt_revert_endorse->close();
                }
            }

            if ($payment['invoiceId']) {
                $invoiceTable = $this->resolveInvoiceTable($payment['invoiceType']);
                $paidDelta = $this->invoicePaidDelta(
                    $payment['invoiceType'],
                    $payment['transaction_type'],
                    $payment['amount']
                );
                $stmt_revert_paid = $this->conn->prepare("UPDATE `{$invoiceTable}` SET paidAmount = paidAmount - ? WHERE id = ? AND entity_id = ?");
                $stmt_revert_paid->bind_param("dii", $paidDelta, $payment['invoiceId'], $entity_id);
                $stmt_revert_paid->execute();
                $stmt_revert_paid->close();
            }
            
            // Capital claim is statement-only; no partnerDelta wallet revert on delete.
            
            parent::delete($id);

            if (!$preserveCheck && $payment['checkId'] && $payment['type'] === 'check') {
                 $stmt_del_check = $this->conn->prepare("DELETE FROM checks WHERE id = ? AND entity_id = ?");
                 $stmt_del_check->bind_param("ii", $payment['checkId'], $entity_id);
                 $stmt_del_check->execute();
                 $stmt_del_check->close();
            }

            if ($manageTransaction) {
                $this->conn->commit();
            }
            return ['success' => true];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            if (!$manageTransaction) {
                throw $e;
            }
            return ['error' => 'خطا در حذف پرداخت: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
}