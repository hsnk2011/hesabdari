<?php
// /api/models/Check.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Check extends BaseModel {
    protected $tableName = 'checks';
    protected $allowedFilters = ['c.checkNumber', 'c.bankName', 'c.issuerName', 'c.amount', 'p.description'];
    protected $allowedSorts = ['id', 'dueDate', 'amount', 'status', 'type', 'bankName', 'issuerName', 'cashed_date'];

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'c';
        // Join only the original payment (check), not endorse_check.
        // relatedPersonName = the customer/supplier/partner/contact on that payment.
        $hasContacts = $this->tableExists('contacts');
        $personNameExpr = $hasContacts
            ? "COALESCE(cust.name, sup.name, pa.name, ct.name)"
            : "COALESCE(cust.name, sup.name, pa.name)";
        $this->select = "SELECT c.*, p.date, p.description, p.person_type, p.person_id, {$personNameExpr} AS relatedPersonName";
        $this->from = "FROM `{$this->tableName}` as c";
        $this->join = "LEFT JOIN `payments` p ON c.id = p.checkId AND p.type = 'check'
            LEFT JOIN customers cust ON p.person_type = 'customer' AND p.person_id = cust.id
            LEFT JOIN suppliers sup ON p.person_type = 'supplier' AND p.person_id = sup.id
            LEFT JOIN partners pa ON p.person_type = 'partner' AND p.person_id = pa.id";
        if ($hasContacts) {
            $this->join .= "\n            LEFT JOIN contacts ct ON p.person_type = 'contact' AND p.person_id = ct.id";
        }
        $this->allowedFilters = ['c.checkNumber', 'c.bankName', 'c.issuerName', 'c.amount', 'p.description', 'cust.name', 'sup.name', 'pa.name'];
        if ($hasContacts) {
            $this->allowedFilters[] = 'ct.name';
        }
    }

    private function tableExists($tableName) {
        static $cache = [];
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }
        $safe = $this->conn->real_escape_string($tableName);
        $result = $this->conn->query("SHOW TABLES LIKE '{$safe}'");
        $cache[$tableName] = ($result && $result->num_rows > 0);
        return $cache[$tableName];
    }

    // Override getPaginated to separate checks by type, status, due date, bank, and invoice link.
    public function getPaginated($input) {
        $this->where = 'WHERE 1';

        $filterCheckType = $input['filterCheckType'] ?? '';
        if (in_array($filterCheckType, ['received', 'payable'], true)) {
            $this->where .= " AND c.type = '" . $this->conn->real_escape_string($filterCheckType) . "'";
        }

        $filterStatus = $input['filterStatus'] ?? '';
        $allowedStatuses = ['in_hand', 'payable', 'endorsed', 'cashed', 'bounced'];
        if ($filterStatus === 'open') {
            $this->where .= " AND c.status IN ('in_hand', 'payable')";
        } elseif (!empty($filterStatus)) {
            $statusList = is_array($filterStatus) ? $filterStatus : [$filterStatus];
            $statusList = array_values(array_unique(array_filter($statusList, function ($status) use ($allowedStatuses) {
                return in_array($status, $allowedStatuses, true);
            })));
            if (!empty($statusList)) {
                $quotedStatuses = array_map(function ($status) {
                    return "'" . $this->conn->real_escape_string($status) . "'";
                }, $statusList);
                $this->where .= " AND c.status IN (" . implode(',', $quotedStatuses) . ")";
            }
        }

        $today = date('Y-m-d');
        $filterDue = $input['filterDue'] ?? '';
        // Dashboard/smart-alert compatibility: past-due open checks.
        if (($input['filterType'] ?? '') === 'past_due' && $filterDue === '') {
            $filterDue = 'past_due';
            if ($filterStatus === '') {
                $this->where .= " AND c.status IN ('in_hand', 'payable')";
            }
        }

        if ($filterDue === 'past_due') {
            $this->where .= " AND c.dueDate < '{$today}'";
            if ($filterStatus === '') {
                $this->where .= " AND c.status IN ('in_hand', 'payable')";
            }
        } elseif ($filterDue === 'today') {
            $this->where .= " AND c.dueDate = '{$today}'";
        } elseif ($filterDue === 'next_7') {
            $days = defined('CHECK_REMINDER_DAYS') ? CHECK_REMINDER_DAYS : 7;
            $until = date('Y-m-d', strtotime("+{$days} days"));
            $this->where .= " AND c.dueDate >= '{$today}' AND c.dueDate <= '{$until}'";
        } elseif ($filterDue === 'next_30') {
            $days = defined('CHECK_UPCOMING_DAYS') ? CHECK_UPCOMING_DAYS : 30;
            $until = date('Y-m-d', strtotime("+{$days} days"));
            $this->where .= " AND c.dueDate >= '{$today}' AND c.dueDate <= '{$until}'";
        } elseif ($filterDue === 'not_due') {
            $this->where .= " AND c.dueDate >= '{$today}'";
        } elseif ($filterDue === 'this_month') {
            [$monthStart, $monthEnd] = $this->currentJalaliMonthBounds($today);
            $this->where .= " AND c.dueDate >= '{$monthStart}' AND c.dueDate <= '{$monthEnd}'";
        }

        $dueFrom = $this->normalizeDate($input['filterDueFrom'] ?? '');
        $dueTo = $this->normalizeDate($input['filterDueTo'] ?? '');
        if ($dueFrom !== '') {
            $this->where .= " AND c.dueDate >= '{$dueFrom}'";
        }
        if ($dueTo !== '') {
            $this->where .= " AND c.dueDate <= '{$dueTo}'";
        }

        $filterRelated = $input['filterRelated'] ?? '';
        if ($filterRelated === 'invoice') {
            $this->where .= " AND c.invoiceId IS NOT NULL AND c.invoiceId <> 0";
        } elseif ($filterRelated === 'standalone') {
            $this->where .= " AND (c.invoiceId IS NULL OR c.invoiceId = 0)";
        }

        return parent::getPaginated($input);
    }

    private function normalizeDate($value) {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /** Inclusive Gregorian bounds of the Jalali month containing $today. */
    private function currentJalaliMonthBounds($today) {
        $jalali = gregorian_to_jalali($today);
        $parts = explode('/', $jalali);
        $jy = intval($parts[0] ?? 0);
        $jm = intval($parts[1] ?? 0);

        $start = $today;
        for ($i = 0; $i < 40; $i++) {
            $prev = date('Y-m-d', strtotime($start . ' -1 day'));
            $prevParts = explode('/', gregorian_to_jalali($prev));
            if (intval($prevParts[0] ?? 0) !== $jy || intval($prevParts[1] ?? 0) !== $jm) {
                break;
            }
            $start = $prev;
        }

        $end = $today;
        for ($i = 0; $i < 40; $i++) {
            $next = date('Y-m-d', strtotime($end . ' +1 day'));
            $nextParts = explode('/', gregorian_to_jalali($next));
            if (intval($nextParts[0] ?? 0) !== $jy || intval($nextParts[1] ?? 0) !== $jm) {
                break;
            }
            $end = $next;
        }

        return [$start, $end];
    }

    /**
     * Override getOrderByClause to add secondary sort by dueDate when sorting by status
     */
    protected function getOrderByClause($sortBy) {
        if ($sortBy === 'status') {
            return "`{$sortBy}`, `dueDate`";
        }
        return "`{$sortBy}`";
    }

    public function save($data) {
        // Validation is now handled in CheckController
        $id = $data['id'] ?? null;
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }
        
        $this->conn->begin_transaction();
        try {
            // Security: Include entity_id check to prevent cross-entity access
            $stmt_check = $this->conn->prepare("SELECT status, entity_id FROM `{$this->tableName}` WHERE id = ? AND entity_id = ?");
            $stmt_check->bind_param("ii", $id, $entity_id);
            $stmt_check->execute();
            $check = db_stmt_to_assoc_array($stmt_check)[0] ?? null;

            if (!$check) {
                throw new Exception("چک مورد نظر یافت نشد یا به این مجموعه تعلق ندارد.", 404);
            }
            if (!in_array($check['status'], ['in_hand', 'payable'])) {
                throw new Exception("این چک قابل ویرایش نیست زیرا پردازش شده است.", 403);
            }

            $payDateStmt = $this->conn->prepare(
                "SELECT date FROM payments WHERE checkId = ? AND entity_id = ? AND type = 'check' LIMIT 1"
            );
            $payDateStmt->bind_param('ii', $id, $entity_id);
            $payDateStmt->execute();
            $payDateRow = db_stmt_to_assoc_array($payDateStmt);
            if (!empty($payDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $payDateRow[0]['date']);
            }

            $issuerName = trim($data['issuerName'] ?? '') ?: null;
            
            // Security: Include entity_id in UPDATE to ensure ownership
            $stmt_update = $this->conn->prepare("UPDATE `{$this->tableName}` SET checkNumber = ?, dueDate = ?, bankName = ?, issuerName = ?, amount = ? WHERE id = ? AND entity_id = ?");
            $stmt_update->bind_param("ssssdii", $data['checkNumber'], $data['dueDate'], $data['bankName'], $issuerName, $data['amount'], $id, $entity_id);
            $stmt_update->execute();
            $stmt_update->close();

            // FIX: Get old payment amount to calculate difference for invoice paidAmount update
            if (!empty($data['checkNumber'])) {
                $dupStmt = $this->conn->prepare("SELECT id FROM checks WHERE checkNumber = ? AND entity_id = ? AND id != ? LIMIT 1");
                $dupNo = trim((string)$data['checkNumber']);
                $dupStmt->bind_param('sii', $dupNo, $entity_id, $id);
                $dupStmt->execute();
                if (!empty(db_stmt_to_assoc_array($dupStmt))) {
                    throw new Exception('شماره چک تکراری است. این شماره چک قبلاً ثبت شده است.', 400);
                }
            }

            $stmt_old_pay = $this->conn->prepare("SELECT amount, invoiceId, invoiceType, transaction_type FROM payments WHERE checkId = ? AND entity_id = ? AND type = 'check'");
            $stmt_old_pay->bind_param("ii", $id, $entity_id);
            $stmt_old_pay->execute();
            $old_payment = db_stmt_to_assoc_array($stmt_old_pay)[0] ?? null;
            
            $oldAmount = $old_payment['amount'] ?? 0;
            $newAmount = floatval($data['amount']);
            $amountDiff = $newAmount - $oldAmount;

            $description = (string)($data['description'] ?? '');
            $stmt_payment = $this->conn->prepare("UPDATE `payments` SET amount = ?, description = ? WHERE checkId = ? AND entity_id = ? AND type = 'check'");
            $stmt_payment->bind_param("dsii", $newAmount, $description, $id, $entity_id);
            $stmt_payment->execute();
            $stmt_payment->close();

            // Update invoice paidAmount if the check amount changed — signed by the
            // payment's direction (a refund check lowers paidAmount instead).
            if ($old_payment && $old_payment['invoiceId'] && abs($amountDiff) > 0.001) {
                $invoiceTable = $this->invoiceTableFor($old_payment['invoiceType']);
                if ($invoiceTable) {
                    $paymentModel = $this->paymentModel();
                    $signedDiff = $paymentModel->invoicePaidDelta($old_payment['invoiceType'], $old_payment['transaction_type'], $newAmount)
                        - $paymentModel->invoicePaidDelta($old_payment['invoiceType'], $old_payment['transaction_type'], $oldAmount);
                    $stmt_inv = $this->conn->prepare("UPDATE `{$invoiceTable}` SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?");
                    $stmt_inv->bind_param("dii", $signedDiff, $old_payment['invoiceId'], $entity_id);
                    $stmt_inv->execute();
                    $stmt_inv->close();
                }
            }

            $this->conn->commit();
            return ['success' => true, 'id' => $id];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => 'خطا در ویرایش چک: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    private function invoiceTableFor($invoiceType) {
        switch ($invoiceType) {
            case 'sales': return 'sales_invoices';
            case 'purchase': return 'purchase_invoices';
            case 'return': return 'return_invoices';
            case 'asset_purchase':
            case 'asset_sale': return 'asset_documents';
            default: return null;
        }
    }

    private function paymentModel() {
        require_once __DIR__ . '/Payment.php';
        static $model = null;
        if ($model === null || !($model instanceof Payment)) {
            $model = new Payment($this->conn);
        }
        return $model;
    }

    /** Append an entry to the check's lifecycle log (shown in «سابقه چک»). */
    private function logEvent($entityId, $checkId, $eventType, $date, $amount = null, $accountId = null, $paymentId = null, $note = null) {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $stmt = $this->conn->prepare(
            "INSERT INTO check_events (entity_id, check_id, event_type, event_date, amount, account_id, payment_id, note, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iissdiisi', $entityId, $checkId, $eventType, $date, $amount, $accountId, $paymentId, $note, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /** Apply a signed paidAmount delta to the invoice a payment row belongs to. */
    private function applyInvoicePaidDelta(array $payment, $delta, $entityId) {
        if (empty($payment['invoiceId']) || abs($delta) < 0.005) {
            return;
        }
        $table = $this->invoiceTableFor($payment['invoiceType'] ?? '');
        if (!$table) {
            return;
        }
        $stmt = $this->conn->prepare("UPDATE `{$table}` SET paidAmount = paidAmount + ? WHERE id = ? AND entity_id = ?");
        $invoiceId = (int)$payment['invoiceId'];
        $stmt->bind_param('dii', $delta, $invoiceId, $entityId);
        $stmt->execute();
        $stmt->close();
    }

    private function lockCheck($checkId, $entityId) {
        $stmt = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ? AND entity_id = ? FOR UPDATE");
        $stmt->bind_param('ii', $checkId, $entityId);
        $stmt->execute();
        $check = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$check) {
            throw new Exception('چک یافت نشد یا به این مجموعه تعلق ندارد.', 404);
        }
        return $check;
    }

    private function checkPayments($checkId, $entityId, $type = null) {
        $sql = "SELECT * FROM payments WHERE checkId = ? AND entity_id = ?" . ($type ? " AND type = ?" : "") . " ORDER BY id ASC";
        $stmt = $this->conn->prepare($sql);
        if ($type) {
            $stmt->bind_param('iis', $checkId, $entityId, $type);
        } else {
            $stmt->bind_param('ii', $checkId, $entityId);
        }
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    /**
     * Book a reversal row that cancels $orig's effect on the counterparty's
     * balance and on the invoice (type 'check_bounce', opposite direction).
     * No bank account is touched: the original check row never moved cash.
     */
    private function insertReversal(array $orig, $date, $description, $entityId) {
        $txn = ($orig['transaction_type'] === 'receipt') ? 'payment' : 'receipt';
        $type = 'check_bounce';
        $stmt = $this->conn->prepare(
            "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, reversal_of_payment_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $invoiceId = !empty($orig['invoiceId']) ? (int)$orig['invoiceId'] : null;
        $invoiceType = $invoiceId ? $orig['invoiceType'] : null;
        $personId = !empty($orig['person_id']) ? (int)$orig['person_id'] : null;
        $personType = $orig['person_type'] ?? null;
        $amount = (float)$orig['amount'];
        $checkId = (int)$orig['checkId'];
        $origId = (int)$orig['id'];
        $stmt->bind_param('iisisssdssii', $entityId, $invoiceId, $invoiceType, $personId, $personType, $txn, $type, $amount, $date, $description, $checkId, $origId);
        if (!$stmt->execute()) {
            throw new Exception('ثبت سند برگشت ناموفق بود: ' . $stmt->error);
        }
        $newId = (int)$this->conn->insert_id;
        $stmt->close();
        $origDelta = $this->paymentModel()->invoicePaidDelta($orig['invoiceType'] ?? '', $orig['transaction_type'], $amount);
        $this->applyInvoicePaidDelta($orig, -$origDelta, $entityId);
        return $newId;
    }

    /** Remove reversal rows (undo bounce/return) and restore the invoice effect. */
    private function deleteReversals(array $reversals, $entityId) {
        foreach ($reversals as $rev) {
            assert_transaction_date_editable($this->conn, $entityId, $rev['date']);
            $orig = null;
            if (!empty($rev['reversal_of_payment_id'])) {
                $st = $this->conn->prepare("SELECT * FROM payments WHERE id = ? AND entity_id = ?");
                $origId = (int)$rev['reversal_of_payment_id'];
                $st->bind_param('ii', $origId, $entityId);
                $st->execute();
                $orig = db_stmt_to_assoc_array($st)[0] ?? null;
            }
            $ref = $orig ?: $rev;
            $origDelta = $this->paymentModel()->invoicePaidDelta($ref['invoiceType'] ?? '', $orig ? $orig['transaction_type'] : ($rev['transaction_type'] === 'receipt' ? 'payment' : 'receipt'), (float)$rev['amount']);
            $this->applyInvoicePaidDelta($rev, $origDelta, $entityId);
            $del = $this->conn->prepare("DELETE FROM payments WHERE id = ? AND entity_id = ? AND type = 'check_bounce'");
            $revId = (int)$rev['id'];
            $del->bind_param('ii', $revId, $entityId);
            $del->execute();
            $del->close();
        }
    }

    public function delete($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        $entity_id = (int)($_SESSION['current_entity_id'] ?? 0);

        $this->conn->begin_transaction();
        try {
            $check = $this->lockCheck($id, $entity_id);
            if ($check['status'] === 'endorsed') {
                throw new Exception("این چک قابل حذف نیست زیرا خرج شده (واگذار شده) است. ابتدا آن را «عودت» کنید.", 400);
            }

            $payments = $this->checkPayments($id, $entity_id);
            foreach ($payments as $pay) {
                assert_transaction_date_editable($this->conn, $entity_id, $pay['date']);
            }
            if (!empty($check['cashed_date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $check['cashed_date']);
            }

            if ($check['status'] === 'cashed' && !empty($check['cashed_in_account_id'])) {
                $accountModel = new Account($this->conn);
                $amountToRevert = ($check['type'] === 'received') ? -$check['amount'] : $check['amount'];
                $accountModel->updateBalance($check['cashed_in_account_id'], $amountToRevert, $entity_id);
            }

            // Undo every linked row's effect on its invoice, signed by direction
            // (bounce reversals cancel their originals, so the net is exact).
            $paymentModel = $this->paymentModel();
            foreach ($payments as $pay) {
                if (empty($pay['invoiceId'])) {
                    continue;
                }
                $delta = $paymentModel->invoicePaidDelta($pay['invoiceType'], $pay['transaction_type'], (float)$pay['amount']);
                if ($pay['type'] === 'check_bounce' && !empty($pay['reversal_of_payment_id'])) {
                    // A reversal applied the negative of its original's delta.
                    foreach ($payments as $o) {
                        if ((int)$o['id'] === (int)$pay['reversal_of_payment_id']) {
                            $delta = -$paymentModel->invoicePaidDelta($o['invoiceType'], $o['transaction_type'], (float)$o['amount']);
                            break;
                        }
                    }
                }
                $this->applyInvoicePaidDelta($pay, -$delta, $entity_id);
            }

            $stmt_payment = $this->conn->prepare("DELETE FROM payments WHERE checkId = ? AND entity_id = ?");
            $stmt_payment->bind_param("ii", $id, $entity_id);
            $stmt_payment->execute();
            $stmt_payment->close();

            $stmtEv = $this->conn->prepare("DELETE FROM check_events WHERE check_id = ? AND entity_id = ?");
            $stmtEv->bind_param('ii', $id, $entity_id);
            $stmtEv->execute();
            $stmtEv->close();

            $delete_result = parent::delete($id);
            if (isset($delete_result['error'])) {
                 throw new Exception($delete_result['error']);
            }

            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return ['error' => 'خطا در حذف چک: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * برگشت چک: the bank refused the check.
     *  - received, in hand   → customer owes again (original receipt reversed)
     *  - received, endorsed  → the person we gave it to is owed again AND the
     *                          original issuer owes us again; the check is back with us
     *  - payable, unpaid     → we owe the supplier again
     * Cashed checks must first be un-cashed («لغو وصول»).
     */
    public function bounceCheck($checkId, $date, $reason = '') {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $date = $date ?: date('Y-m-d');
        $this->conn->begin_transaction();
        try {
            assert_transaction_date_editable($this->conn, $entityId, $date);
            $check = $this->lockCheck($checkId, $entityId);
            $allowed = ($check['type'] === 'received') ? ['in_hand', 'endorsed'] : ['payable'];
            if ($check['status'] === 'cashed') {
                throw new Exception('این چک وصول/پاس شده است. ابتدا «لغو وصول» را انجام دهید، سپس برگشت بزنید.', 400);
            }
            if (!in_array($check['status'], $allowed, true)) {
                throw new Exception('چک در وضعیت «' . $this->statusLabelFor($check) . '» قابل برگشت نیست.', 400);
            }
            $reason = trim((string)$reason);
            $label = 'برگشت چک شماره ' . $check['checkNumber'] . ($reason !== '' ? ' - ' . $reason : '');

            $created = [];
            if ($check['status'] === 'endorsed') {
                foreach ($this->activeOriginals($checkId, $entityId, 'endorse_check') as $endorse) {
                    $created[] = $this->insertReversal($endorse, $date, $label . ' (از طرف دریافت‌کننده)', $entityId);
                }
            }
            foreach ($this->activeOriginals($checkId, $entityId, 'check') as $orig) {
                $created[] = $this->insertReversal($orig, $date, $label, $entityId);
            }

            $upd = $this->conn->prepare("UPDATE checks SET status = 'bounced', bounced_date = ?, bounce_reason = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('ssii', $date, $reason, $checkId, $entityId);
            $upd->execute();
            $upd->close();

            $this->logEvent($entityId, (int)$checkId, 'bounced', $date, (float)$check['amount'], null, $created[0] ?? null,
                ($check['status'] === 'endorsed' ? 'برگشت پس از خرج' : null) . ($reason !== '' ? ' ' . $reason : ''));
            $this->conn->commit();
            log_activity($this->conn, 'BOUNCE_CHECK', "چک شماره {$check['checkNumber']} برگشت خورد.");
            return ['success' => true, 'reversals' => $created];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => $this->errorStatus($e)];
        }
    }

    /** لغو برگشت: undo bounceCheck and restore the previous status. */
    public function undoBounce($checkId) {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $this->conn->begin_transaction();
        try {
            $check = $this->lockCheck($checkId, $entityId);
            if ($check['status'] !== 'bounced') {
                throw new Exception('این چک در وضعیت برگشتی نیست.', 400);
            }
            $reversals = $this->checkPayments($checkId, $entityId, 'check_bounce');
            $wasEndorsed = false;
            $bounceRevs = [];
            foreach ($reversals as $rev) {
                // Only the reversals created by the bounce (same date), not earlier «عودت» rows.
                if ((string)$rev['date'] !== (string)$check['bounced_date']) {
                    continue;
                }
                $bounceRevs[] = $rev;
                if (!empty($rev['reversal_of_payment_id'])) {
                    $st = $this->conn->prepare("SELECT type FROM payments WHERE id = ? AND entity_id = ?");
                    $oid = (int)$rev['reversal_of_payment_id'];
                    $st->bind_param('ii', $oid, $entityId);
                    $st->execute();
                    $o = db_stmt_to_assoc_array($st)[0] ?? null;
                    if ($o && $o['type'] === 'endorse_check') {
                        $wasEndorsed = true;
                    }
                }
            }
            $this->deleteReversals($bounceRevs, $entityId);
            $newStatus = $wasEndorsed ? 'endorsed' : (($check['type'] === 'received') ? 'in_hand' : 'payable');
            $upd = $this->conn->prepare("UPDATE checks SET status = ?, bounced_date = NULL, bounce_reason = NULL WHERE id = ? AND entity_id = ?");
            $upd->bind_param('sii', $newStatus, $checkId, $entityId);
            $upd->execute();
            $upd->close();
            $this->logEvent($entityId, (int)$checkId, 'bounce_undone', date('Y-m-d'), (float)$check['amount']);
            $this->conn->commit();
            log_activity($this->conn, 'UNDO_BOUNCE_CHECK', "برگشت چک شماره {$check['checkNumber']} لغو شد.");
            return ['success' => true, 'status' => $newStatus];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => $this->errorStatus($e)];
        }
    }

    /**
     * عودت چک خرج‌شده: the person we endorsed the check to gave it back
     * (not via the bank). Their settlement is reversed and the check returns
     * to «نزد ما» so it can be cashed or endorsed again.
     */
    public function returnEndorsedCheck($checkId, $date, $note = '') {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $date = $date ?: date('Y-m-d');
        $this->conn->begin_transaction();
        try {
            assert_transaction_date_editable($this->conn, $entityId, $date);
            $check = $this->lockCheck($checkId, $entityId);
            if ($check['status'] !== 'endorsed') {
                throw new Exception('فقط چک خرج‌شده (واگذار شده) قابل عودت است.', 400);
            }
            $label = 'عودت چک شماره ' . $check['checkNumber'] . (trim((string)$note) !== '' ? ' - ' . trim($note) : '');
            $created = [];
            foreach ($this->activeOriginals($checkId, $entityId, 'endorse_check') as $endorse) {
                $created[] = $this->insertReversal($endorse, $date, $label, $entityId);
            }
            $upd = $this->conn->prepare("UPDATE checks SET status = 'in_hand' WHERE id = ? AND entity_id = ?");
            $upd->bind_param('ii', $checkId, $entityId);
            $upd->execute();
            $upd->close();
            $this->logEvent($entityId, (int)$checkId, 'endorse_returned', $date, (float)$check['amount'], null, $created[0] ?? null, trim((string)$note) ?: null);
            $this->conn->commit();
            log_activity($this->conn, 'RETURN_ENDORSED_CHECK', "چک شماره {$check['checkNumber']} از طرف دریافت‌کننده عودت داده شد.");
            return ['success' => true, 'reversals' => $created];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => $this->errorStatus($e)];
        }
    }

    /**
     * لغو وصول / لغو پاس: reverse a cash/clear (e.g. recorded on the wrong
     * account or the bank reversed the credit). The account balance is
     * restored and the check returns to its open status.
     */
    public function undoCash($checkId) {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $this->conn->begin_transaction();
        try {
            $check = $this->lockCheck($checkId, $entityId);
            if ($check['status'] !== 'cashed') {
                throw new Exception('این چک وصول/پاس نشده است.', 400);
            }
            if (!empty($check['cashed_date'])) {
                assert_transaction_date_editable($this->conn, $entityId, $check['cashed_date']);
            }
            if (!empty($check['cashed_in_account_id'])) {
                $accountModel = new Account($this->conn);
                $delta = ($check['type'] === 'received') ? -(float)$check['amount'] : (float)$check['amount'];
                $accountModel->updateBalance($check['cashed_in_account_id'], $delta, $entityId);
            }
            $newStatus = ($check['type'] === 'received') ? 'in_hand' : 'payable';
            $upd = $this->conn->prepare("UPDATE checks SET status = ?, cashed_in_account_id = NULL, cashed_date = NULL WHERE id = ? AND entity_id = ?");
            $upd->bind_param('sii', $newStatus, $checkId, $entityId);
            $upd->execute();
            $upd->close();
            $this->logEvent($entityId, (int)$checkId, 'cash_undone', date('Y-m-d'), (float)$check['amount'], $check['cashed_in_account_id'] ? (int)$check['cashed_in_account_id'] : null);
            $this->conn->commit();
            log_activity($this->conn, 'UNDO_CASH_CHECK', "وصول/پاس چک شماره {$check['checkNumber']} لغو شد.");
            return ['success' => true, 'status' => $newStatus];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => $this->errorStatus($e)];
        }
    }

    /** Original rows of a given type that have not been reversed yet. */
    private function activeOriginals($checkId, $entityId, $type) {
        $stmt = $this->conn->prepare(
            "SELECT p.* FROM payments p
             WHERE p.checkId = ? AND p.entity_id = ? AND p.type = ?
               AND NOT EXISTS (SELECT 1 FROM payments r WHERE r.reversal_of_payment_id = p.id AND r.type = 'check_bounce')
             ORDER BY p.id ASC"
        );
        $stmt->bind_param('iis', $checkId, $entityId, $type);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function statusLabelFor(array $check) {
        if ($check['status'] === 'cashed') {
            return $check['type'] === 'payable' ? 'پاس شده' : 'وصول شده';
        }
        $map = ['in_hand' => 'نزد ما', 'payable' => 'پرداختنی', 'endorsed' => 'خرج‌شده', 'bounced' => 'برگشتی'];
        return $map[$check['status']] ?? $check['status'];
    }

    private function errorStatus(Exception $e) {
        $code = (int)$e->getCode();
        return ($code >= 400 && $code < 600) ? $code : 500;
    }

    public function cashCheck($checkId, $accountId, $cashedDate = null) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $cashedDate = $cashedDate ?: date('Y-m-d');
        
        $this->conn->begin_transaction();
        try {
            assert_transaction_date_editable($this->conn, $entity_id, $cashedDate);

            // Security: Include entity_id check with FOR UPDATE for race condition prevention
            $stmt = $this->conn->prepare("SELECT amount, type, status FROM `{$this->tableName}` WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $checkId, $entity_id);
            $stmt->execute();
            $check = db_stmt_to_assoc_array($stmt)[0] ?? null;

            if (!$check) {
                throw new Exception('چک یافت نشد یا به این مجموعه تعلق ندارد.', 404);
            }
            if ($check['type'] !== 'received' || $check['status'] !== 'in_hand') {
                throw new Exception('این چک قابل وصول نیست.', 400);
            }
            
            // Verify account belongs to same entity
            $stmt_acc = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
            $stmt_acc->bind_param("ii", $accountId, $entity_id);
            $stmt_acc->execute();
            $acc = db_stmt_to_assoc_array($stmt_acc)[0] ?? null;
            if (!$acc) {
                throw new Exception('حساب انتخاب شده معتبر نیست.', 400);
            }

            $payDateStmt = $this->conn->prepare(
                "SELECT date FROM payments WHERE checkId = ? AND entity_id = ? AND type = 'check' LIMIT 1"
            );
            $payDateStmt->bind_param('ii', $checkId, $entity_id);
            $payDateStmt->execute();
            $payDateRow = db_stmt_to_assoc_array($payDateStmt);
            if (!empty($payDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $payDateRow[0]['date']);
            }

            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ?, cashed_date = ? WHERE id = ? AND entity_id = ?");
            $updateStmt->bind_param("isii", $accountId, $cashedDate, $checkId, $entity_id);
            $updateStmt->execute();
            $updateStmt->close();

            $accountModel = new Account($this->conn);
            // Wallet: cashing a received check into any account (incl. personal) increases it
            $accountModel->updateBalance($accountId, $check['amount'], $entity_id);
            $this->logEvent((int)$entity_id, (int)$checkId, 'cashed', $cashedDate, (float)$check['amount'], (int)$accountId);

            // Partner capital claim is statement-only (no extra personal balance write).
            
            $this->conn->commit();
            log_activity($this->conn, 'CASH_CHECK', "چک به مبلغ {$check['amount']} در حساب {$accountId} وصول شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    public function clearPayableCheck($checkId, $accountId, $cashedDate = null) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        $cashedDate = $cashedDate ?: date('Y-m-d');
        $this->conn->begin_transaction();
        try {
            assert_transaction_date_editable($this->conn, $entity_id, $cashedDate);
            // Security: Include entity_id check with FOR UPDATE for race condition prevention
            $stmt = $this->conn->prepare("SELECT amount, type, status, checkNumber FROM `{$this->tableName}` WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $checkId, $entity_id);
            $stmt->execute();
            $check = db_stmt_to_assoc_array($stmt)[0] ?? null;

            if (!$check) {
                throw new Exception('چک یافت نشد یا به این مجموعه تعلق ندارد.', 404);
            }
            if ($check['type'] !== 'payable' || $check['status'] !== 'payable') {
                throw new Exception('این چک قابل پاس شدن نیست.', 400);
            }

            // Verify account belongs to same entity
            $stmt_acc = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
            $stmt_acc->bind_param("ii", $accountId, $entity_id);
            $stmt_acc->execute();
            $acc = db_stmt_to_assoc_array($stmt_acc)[0] ?? null;
            if (!$acc) {
                throw new Exception('حساب انتخاب شده معتبر نیست.', 400);
            }

            // Step 1: Update the check status to 'cashed' and link to the bank account
            $updateStmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'cashed', cashed_in_account_id = ?, cashed_date = ? WHERE id = ? AND entity_id = ?");
            $updateStmt->bind_param("isii", $accountId, $cashedDate, $checkId, $entity_id);
            $updateStmt->execute();
            $updateStmt->close();

            // Step 2: Wallet debit on the paying account
            $accountModel = new Account($this->conn);
            $accountModel->updateBalance($accountId, -$check['amount'], $entity_id);
            $this->logEvent((int)$entity_id, (int)$checkId, 'cleared', $cashedDate, (float)$check['amount'], (int)$accountId);

            // Partner capital claim is statement-only.
            
            $this->conn->commit();
            log_activity($this->conn, 'CLEAR_CHECK', "چک پرداختی به مبلغ {$check['amount']} از حساب {$accountId} پاس شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * Discount and transfer multiple checks to a person
     * راس‌گیری و انتقال چند چک به یک شخص
     */
    public function discountAndTransferChecks($checks, $person_id, $person_type, $transaction_date, $description, $discount_rate, $base_date) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        // Map person type to table
        $personTableMap = [
            'customer' => 'customers',
            'supplier' => 'suppliers',
            'partner' => 'partners',
            'contact' => 'contacts'
        ];
        
        if (!isset($personTableMap[$person_type])) {
            return ['error' => 'نوع شخص نامعتبر است.', 'statusCode' => 400];
        }

        require_once __DIR__ . '/Payment.php';
        $paymentModel = new Payment($this->conn);
        $paymentModel->ensureTransferGroupColumn();
        $transferGroupId = Payment::newTransferGroupId();

        $this->conn->begin_transaction();
        try {
            assert_transaction_date_editable($this->conn, $entity_id, $transaction_date);

            // Verify person exists and belongs to entity
            $personTable = $personTableMap[$person_type];
            $stmt_person = $this->conn->prepare("SELECT id, name FROM `{$personTable}` WHERE id = ? AND entity_id = ?");
            $stmt_person->bind_param("ii", $person_id, $entity_id);
            $stmt_person->execute();
            $person = db_stmt_to_assoc_array($stmt_person)[0] ?? null;
            
            if (!$person) {
                throw new Exception('شخص انتخاب شده یافت نشد یا به این مجموعه تعلق ندارد.', 404);
            }

            $totalOriginalAmount = 0;
            $totalDiscountAmount = 0;
            $processedChecks = [];
            
            // Process each check
            foreach ($checks as $checkData) {
                $checkId = intval($checkData['checkId']);
                $discountAmount = max(0, floatval($checkData['discountAmount'] ?? 0));
                
                // Verify check exists, is received, and in_hand
                $stmt_check = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE id = ? AND entity_id = ? FOR UPDATE");
                $stmt_check->bind_param("ii", $checkId, $entity_id);
                $stmt_check->execute();
                $check = db_stmt_to_assoc_array($stmt_check)[0] ?? null;
                
                if (!$check) {
                    throw new Exception("چک با شناسه {$checkId} یافت نشد.", 404);
                }
                if ($check['type'] !== 'received') {
                    throw new Exception("چک {$check['checkNumber']} از نوع دریافتی نیست.", 400);
                }
                if ($check['status'] !== 'in_hand') {
                    throw new Exception("چک {$check['checkNumber']} در وضعیت 'نزد ما' نیست و قابل انتقال نیست.", 400);
                }
                // The face value comes from the check itself, never from the client.
                $originalAmount = (float)$check['amount'];
                if ($discountAmount > $originalAmount) {
                    throw new Exception("مبلغ تنزیل چک {$check['checkNumber']} از مبلغ آن بیشتر است.", 400);
                }

                $origPay = $this->conn->prepare(
                    "SELECT date FROM payments WHERE checkId = ? AND entity_id = ? AND type = 'check' LIMIT 1"
                );
                $origPay->bind_param('ii', $checkId, $entity_id);
                $origPay->execute();
                $origPayRow = db_stmt_to_assoc_array($origPay);
                if (!empty($origPayRow[0]['date'])) {
                    assert_transaction_date_editable($this->conn, $entity_id, $origPayRow[0]['date']);
                }
                // Update check status to endorsed
                $stmt_update = $this->conn->prepare("UPDATE `{$this->tableName}` SET status = 'endorsed' WHERE id = ? AND entity_id = ?");
                $stmt_update->bind_param("ii", $checkId, $entity_id);
                $stmt_update->execute();
                $stmt_update->close();
                
                // Create endorse_check payment record for the transfer
                $desc = $description ?: "راس‌گیری و انتقال چک {$check['checkNumber']} به {$person['name']}";
                if ($discountAmount > 0) {
                    $desc .= " (تنزیل: " . number_format($discountAmount) . " تومان)";
                }
                
                $stmt_payment = $this->conn->prepare(
                    "INSERT INTO payments (entity_id, person_id, person_type, transaction_type, type, amount, date, description, checkId, transfer_group_id) 
                     VALUES (?, ?, ?, 'payment', 'endorse_check', ?, ?, ?, ?, ?)"
                );
                $stmt_payment->bind_param("iisdssis", $entity_id, $person_id, $person_type, $originalAmount, $transaction_date, $desc, $checkId, $transferGroupId);
                $stmt_payment->execute();
                $paymentId = (int)$this->conn->insert_id;
                $stmt_payment->close();
                
                $totalOriginalAmount += $originalAmount;
                $totalDiscountAmount += $discountAmount;
                
                $processedChecks[] = [
                    'id' => $checkId,
                    'paymentId' => $paymentId,
                    'checkNumber' => $check['checkNumber'],
                    'bankName' => $check['bankName'],
                    'issuerName' => $check['issuerName'] ?? null,
                    'dueDate' => $check['dueDate'],
                    'amount' => $originalAmount,
                    'originalAmount' => $originalAmount,
                    'discountAmount' => $discountAmount
                ];
            }
            
            // Endorsing to a partner is recorded via the endorse_check payment row
            // and affects صورتحساب اشخاص (capital) only — not personal wallet balance.

            // The discount is a real financial cost: the checks are endorsed at their
            // full face value but the business effectively loses the discount amount.
            // Record it as an expense so it is reflected in reports and account balances.
            if ($totalDiscountAmount > 0) {
                // Prefer the configured cash account; fall back to the first
                // cash account for backward compatibility.
                $cashAccount = null;
                $configuredCashId = intval(get_app_setting($this->conn, 'default_cash_account_id', 0));
                if ($configuredCashId > 0) {
                    $stmt_cfg = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ? LIMIT 1");
                    $stmt_cfg->bind_param("ii", $configuredCashId, $entity_id);
                    $stmt_cfg->execute();
                    $cashAccount = db_stmt_to_assoc_array($stmt_cfg)[0] ?? null;
                }
                if (!$cashAccount) {
                    $stmt_cash = $this->conn->prepare("SELECT id FROM accounts WHERE entity_id = ? AND is_cash = 1 ORDER BY id ASC LIMIT 1");
                    $stmt_cash->bind_param("i", $entity_id);
                    $stmt_cash->execute();
                    $cashAccount = db_stmt_to_assoc_array($stmt_cash)[0] ?? null;
                }

                if ($cashAccount) {
                    $expenseCategory = 'هزینه تنزیل چک';
                    $expenseDesc = "هزینه تنزیل چک‌های انتقالی به {$person['name']}";
                    $stmt_exp = $this->conn->prepare(
                        "INSERT INTO expenses (entity_id, category, date, amount, description, account_id) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt_exp->bind_param("issdsi", $entity_id, $expenseCategory, $transaction_date, $totalDiscountAmount, $expenseDesc, $cashAccount['id']);
                    $stmt_exp->execute();
                    $stmt_exp->close();

                    $accountModel = new Account($this->conn);
                    $accountModel->updateBalance($cashAccount['id'], -$totalDiscountAmount, $entity_id);
                }
            }

            $this->conn->commit();
            return [
                'success' => true, 
                'processedCount' => count($processedChecks),
                'totalOriginalAmount' => $totalOriginalAmount,
                'totalDiscountAmount' => $totalDiscountAmount,
                'transfer_group_id' => $transferGroupId,
                'checks' => $processedChecks,
                'transferredTo' => [
                    'person_id' => $person_id,
                    'person_type' => $person_type,
                    'name' => $person['name']
                ]
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * Checks transferred together in one ras-giri / endorse batch.
     * Accepts transfer_group_id or any checkId that belongs to a group.
     */
    public function getTransferGroup($transferGroupId = null, $checkId = null) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        require_once __DIR__ . '/Payment.php';
        (new Payment($this->conn))->ensureTransferGroupColumn();

        $groupId = $transferGroupId ? trim((string)$transferGroupId) : '';
        if ($groupId === '' && $checkId) {
            $cid = (int)$checkId;
            $stmt = $this->conn->prepare(
                "SELECT transfer_group_id FROM payments
                 WHERE entity_id = ? AND checkId = ? AND type = 'endorse_check'
                   AND transfer_group_id IS NOT NULL AND transfer_group_id != ''
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->bind_param('ii', $entity_id, $cid);
            $stmt->execute();
            $groupId = (string)(db_stmt_to_assoc_array($stmt)[0]['transfer_group_id'] ?? '');
        }
        if ($groupId === '') {
            return ['error' => 'گروه انتقال برای این چک یافت نشد.', 'statusCode' => 404];
        }

        $stmt = $this->conn->prepare(
            "SELECT p.id AS payment_id, p.date AS transfer_date, p.description, p.person_id, p.person_type,
                    p.amount AS transfer_amount, p.transfer_group_id,
                    c.id, c.checkNumber, c.bankName, c.issuerName, c.dueDate, c.amount, c.status, c.type
             FROM payments p
             INNER JOIN checks c ON c.id = p.checkId AND c.entity_id = p.entity_id
             WHERE p.entity_id = ? AND p.type = 'endorse_check' AND p.transfer_group_id = ?
             ORDER BY c.dueDate ASC, c.id ASC"
        );
        $stmt->bind_param('is', $entity_id, $groupId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows)) {
            return ['error' => 'چک‌های این گروه انتقال یافت نشد.', 'statusCode' => 404];
        }

        $personTableMap = [
            'customer' => 'customers',
            'supplier' => 'suppliers',
            'partner' => 'partners',
            'contact' => 'contacts'
        ];
        $personTypeLabels = [
            'customer' => 'مشتری',
            'supplier' => 'تأمین‌کننده',
            'partner' => 'شریک',
            'contact' => 'سایر شخص'
        ];
        $statusLabels = [
            'in_hand' => 'نزد ما',
            'endorsed' => 'واگذار شده',
            'cashed' => 'وصول/پاس شده',
            'payable' => 'پرداختنی',
            'bounced' => 'برگشتی'
        ];

        $first = $rows[0];
        $personName = null;
        $personPhone = null;
        $pt = $first['person_type'] ?? null;
        $pid = (int)($first['person_id'] ?? 0);
        if ($pt && $pid && isset($personTableMap[$pt])) {
            $table = $personTableMap[$pt];
            $hasPhone = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'phone'");
            $phoneOk = $hasPhone && $hasPhone->num_rows > 0;
            $ps = $this->conn->prepare($phoneOk
                ? "SELECT name, phone FROM `{$table}` WHERE id = ? AND entity_id = ?"
                : "SELECT name FROM `{$table}` WHERE id = ? AND entity_id = ?"
            );
            $ps->bind_param('ii', $pid, $entity_id);
            $ps->execute();
            $prow = db_stmt_to_assoc_array($ps)[0] ?? null;
            $personName = $prow['name'] ?? null;
            $personPhone = $phoneOk ? ($prow['phone'] ?? null) : null;
        }

        $checks = [];
        $totalAmount = 0;
        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $totalAmount += $amount;
            $checks[] = [
                'id' => (int)$row['id'],
                'payment_id' => (int)$row['payment_id'],
                'checkNumber' => $row['checkNumber'],
                'bankName' => $row['bankName'],
                'issuerName' => $row['issuerName'] ?? null,
                'dueDate' => $row['dueDate'],
                'amount' => $amount,
                'status' => $row['status'],
                'status_label' => $statusLabels[$row['status']] ?? $row['status'],
                'type' => $row['type'],
            ];
        }

        return [
            'success' => true,
            'transfer_group_id' => $groupId,
            'transfer_date' => $first['transfer_date'],
            'description' => $first['description'],
            'transferredTo' => [
                'person_id' => $pid ?: null,
                'person_type' => $pt,
                'type_label' => $personTypeLabels[$pt] ?? $pt,
                'name' => $personName,
                'phone' => $personPhone,
            ],
            'checks' => $checks,
            'checkCount' => count($checks),
            'totalAmount' => $totalAmount,
        ];
    }

    /**
     * Full lifecycle / history report for one or more checks.
     */
    public function getCheckHistory($checkIds) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }

        require_once __DIR__ . '/Payment.php';
        (new Payment($this->conn))->ensureTransferGroupColumn();

        if (!is_array($checkIds)) {
            $checkIds = [$checkIds];
        }
        $checkIds = array_values(array_filter(array_map('intval', $checkIds)));
        if (empty($checkIds)) {
            return ['error' => 'شناسه چک نامعتبر است.', 'statusCode' => 400];
        }

        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        $types = str_repeat('i', count($checkIds) + 1);
        $params = array_merge([$entity_id], $checkIds);

        $stmt = $this->conn->prepare("SELECT * FROM checks WHERE entity_id = ? AND id IN ($placeholders) ORDER BY dueDate ASC, id ASC");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $checks = db_stmt_to_assoc_array($stmt);

        $results = [];
        foreach ($checks as $check) {
            $results[] = $this->buildSingleCheckHistory($check, $entity_id);
        }

        return ['success' => true, 'checks' => $results];
    }

    private function buildSingleCheckHistory(array $check, int $entityId) {
        $checkId = (int)$check['id'];
        $timeline = [];
        $personTableMap = [
            'customer' => 'customers',
            'supplier' => 'suppliers',
            'partner' => 'partners',
            'contact' => 'contacts'
        ];

        $resolvePerson = function ($personType, $personId) use ($personTableMap, $entityId) {
            if (!$personType || !$personId || !isset($personTableMap[$personType])) {
                return ['name' => null, 'phone' => null];
            }
            $table = $personTableMap[$personType];
            // partners/contacts now have optional phone
            $hasPhone = in_array($table, ['customers', 'suppliers', 'partners', 'contacts'], true);
            $sql = $hasPhone
                ? "SELECT name, phone FROM `{$table}` WHERE id = ? AND entity_id = ?"
                : "SELECT name FROM `{$table}` WHERE id = ? AND entity_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ii', $personId, $entityId);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
            return [
                'name' => $row['name'] ?? null,
                'phone' => $hasPhone ? ($row['phone'] ?? null) : null
            ];
        };

        $personTypeLabels = [
            'customer' => 'مشتری',
            'supplier' => 'تأمین‌کننده',
            'partner' => 'شریک',
            'contact' => 'سایر شخص'
        ];
        $invoiceTypeLabels = [
            'sales' => 'فاکتور فروش',
            'purchase' => 'فاکتور خرید',
            'return' => 'فاکتور مرجوعی',
            'asset_purchase' => 'سند خرید دارایی',
            'asset_sale' => 'سند فروش دارایی',
        ];
        $statusLabels = [
            'in_hand' => 'نزد ما',
            'endorsed' => 'واگذار شده',
            'cashed' => $check['type'] === 'payable' ? 'پاس شده' : 'وصول شده',
            'payable' => 'پرداختنی',
            'bounced' => 'برگشتی'
        ];

        // All payments linked to this check
        $stmtPays = $this->conn->prepare("SELECT * FROM payments WHERE checkId = ? AND entity_id = ? ORDER BY date ASC, id ASC");
        $stmtPays->bind_param('ii', $checkId, $entityId);
        $stmtPays->execute();
        $payments = db_stmt_to_assoc_array($stmtPays);

        $originPayment = null;
        $currentHolder = null;
        $originPerson = null;

        foreach ($payments as $pay) {
            $personInfo = $resolvePerson($pay['person_type'], $pay['person_id']);
            $personName = $personInfo['name'];
            $personPhone = $personInfo['phone'];
            $event = [
                'date' => $pay['date'],
                'type' => $pay['type'],
                'transaction_type' => $pay['transaction_type'],
                'amount' => (float)$pay['amount'],
                'description' => $pay['description'],
                'person_type' => $pay['person_type'],
                'person_id' => $pay['person_id'] ? (int)$pay['person_id'] : null,
                'person_name' => $personName,
                'person_phone' => $personPhone,
                'invoiceId' => $pay['invoiceId'] ? (int)$pay['invoiceId'] : null,
                'invoiceType' => $pay['invoiceType'],
                'payment_id' => (int)$pay['id']
            ];

            if ($pay['type'] === 'check') {
                $originPayment = $pay;
                $originPerson = [
                    'person_type' => $pay['person_type'],
                    'person_id' => $pay['person_id'] ? (int)$pay['person_id'] : null,
                    'name' => $personName,
                    'phone' => $personPhone,
                    'type_label' => $personTypeLabels[$pay['person_type']] ?? $pay['person_type']
                ];
                $event['title'] = $check['type'] === 'received'
                    ? 'دریافت چک' . ($personName ? " از {$personName}" : '')
                    : 'صدور/پرداخت چک' . ($personName ? " به {$personName}" : '');
                if ($check['type'] === 'received') {
                    $currentHolder = ['kind' => 'entity', 'label' => 'نزد مجموعه'];
                } elseif ($personName) {
                    $currentHolder = [
                        'kind' => 'person',
                        'person_type' => $pay['person_type'],
                        'person_id' => (int)$pay['person_id'],
                        'label' => $personName,
                        'phone' => $personPhone
                    ];
                }
            } elseif ($pay['type'] === 'endorse_check') {
                $event['title'] = 'انتقال/خرج چک' . ($personName ? " به {$personName}" : '');
                if ($personName) {
                    $currentHolder = [
                        'kind' => 'person',
                        'person_type' => $pay['person_type'],
                        'person_id' => (int)$pay['person_id'],
                        'label' => $personName,
                        'phone' => $personPhone
                    ];
                }
            } elseif ($pay['type'] === 'check_bounce') {
                $event['title'] = 'ابطال تسویه' . ($personName ? " با {$personName}" : '') . ' (برگشت/عودت چک)';
            } else {
                $event['title'] = 'تراکنش مرتبط';
            }

            if ($pay['invoiceId']) {
                $invLabel = $invoiceTypeLabels[$pay['invoiceType']] ?? 'فاکتور';
                $event['invoice_label'] = "{$invLabel} #{$pay['invoiceId']}";
            }

            $timeline[] = $event;
        }

        // Origin from check.invoiceId if payment didn't have it
        $originInvoice = null;
        $invoiceId = $check['invoiceId'] ?: ($originPayment['invoiceId'] ?? null);
        $invoiceType = $check['invoiceType'] ?: ($originPayment['invoiceType'] ?? null);
        if ($invoiceId && $invoiceType) {
            $originInvoice = [
                'id' => (int)$invoiceId,
                'type' => $invoiceType,
                'label' => ($invoiceTypeLabels[$invoiceType] ?? 'فاکتور') . " #{$invoiceId}"
            ];
        }

        // Lifecycle log (cash/clear, undo, bounce, return). When present it is the
        // authoritative source for cash events, so undone cashes stay visible.
        $events = [];
        $evStmt = $this->conn->prepare("SELECT e.*, a.name AS account_name FROM check_events e LEFT JOIN accounts a ON a.id = e.account_id WHERE e.check_id = ? AND e.entity_id = ? ORDER BY e.id ASC");
        if ($evStmt) {
            $evStmt->bind_param('ii', $checkId, $entityId);
            $evStmt->execute();
            $events = db_stmt_to_assoc_array($evStmt);
        }
        $eventTitles = [
            'cashed' => 'وصول چک', 'cleared' => 'پاس شدن چک', 'cash_undone' => 'لغو وصول/پاس',
            'bounced' => 'برگشت چک', 'bounce_undone' => 'لغو برگشت', 'endorse_returned' => 'عودت چک خرج‌شده',
        ];
        $hasCashEvents = false;
        foreach ($events as $ev) {
            if (in_array($ev['event_type'], ['cashed', 'cleared'], true)) {
                $hasCashEvents = true;
            }
            $timeline[] = [
                'date' => $ev['event_date'],
                'type' => $ev['event_type'],
                'title' => $eventTitles[$ev['event_type']] ?? $ev['event_type'],
                'amount' => $ev['amount'] !== null ? (float)$ev['amount'] : null,
                'description' => trim(($ev['account_name'] ? 'حساب: ' . $ev['account_name'] . ' ' : '') . ($ev['note'] ?? '')) ?: null,
                'account_id' => $ev['account_id'] ? (int)$ev['account_id'] : null,
                'account_name' => $ev['account_name'] ?? null,
            ];
        }
        if (in_array($check['status'], ['in_hand', 'bounced'], true) && $check['type'] === 'received') {
            $currentHolder = ['kind' => 'entity', 'label' => $check['status'] === 'bounced' ? 'نزد مجموعه (برگشتی)' : 'نزد مجموعه'];
        }

        // Cash / clear event
        if ($check['status'] === 'cashed' && !empty($check['cashed_in_account_id'])) {
            $stmtAcc = $this->conn->prepare("SELECT id, name FROM accounts WHERE id = ? AND entity_id = ?");
            $cashedAccId = (int)$check['cashed_in_account_id'];
            $stmtAcc->bind_param('ii', $cashedAccId, $entityId);
            $stmtAcc->execute();
            $acc = db_stmt_to_assoc_array($stmtAcc)[0] ?? null;
            $cashDate = $check['cashed_date'] ?: $check['dueDate'];
            if (!$hasCashEvents) $timeline[] = [
                'date' => $cashDate,
                'type' => 'cashed',
                'title' => $check['type'] === 'received' ? 'وصول چک' : 'پاس شدن چک',
                'amount' => (float)$check['amount'],
                'description' => $acc ? ('حساب: ' . $acc['name']) : null,
                'account_id' => $cashedAccId,
                'account_name' => $acc['name'] ?? null
            ];
            $currentHolder = [
                'kind' => 'account',
                'account_id' => $cashedAccId,
                'label' => $acc['name'] ?? ('حساب #' . $cashedAccId)
            ];
        }

        usort($timeline, function ($a, $b) {
            $cmp = strcmp($a['date'] ?? '', $b['date'] ?? '');
            return $cmp !== 0 ? $cmp : (($a['payment_id'] ?? 0) - ($b['payment_id'] ?? 0));
        });

        $transferGroup = null;
        $transferGroupId = null;
        $endorsePaymentId = null;
        foreach ($payments as $pay) {
            if (($pay['type'] ?? '') !== 'endorse_check') {
                continue;
            }
            $endorsePaymentId = (int)$pay['id'];
            if (!empty($pay['transfer_group_id'])) {
                $transferGroupId = (string)$pay['transfer_group_id'];
            }
        }
        if ($transferGroupId) {
            $groupInfo = $this->getTransferGroup($transferGroupId, null);
            if (!empty($groupInfo['success'])) {
                $transferGroup = [
                    'transfer_group_id' => $groupInfo['transfer_group_id'],
                    'transfer_date' => $groupInfo['transfer_date'],
                    'transferredTo' => $groupInfo['transferredTo'],
                    'checkCount' => $groupInfo['checkCount'],
                    'totalAmount' => $groupInfo['totalAmount'],
                    'checks' => $groupInfo['checks'],
                ];
            }
        }

        return [
            'id' => $checkId,
            'type' => $check['type'],
            'type_label' => $check['type'] === 'received' ? 'دریافتی' : 'پرداختی',
            'status' => $check['status'],
            'status_label' => $statusLabels[$check['status']] ?? $check['status'],
            'checkNumber' => $check['checkNumber'],
            'bankName' => $check['bankName'],
            'issuerName' => $check['issuerName'] ?? null,
            'dueDate' => $check['dueDate'],
            'cashed_date' => $check['cashed_date'] ?? null,
            'bounced_date' => $check['bounced_date'] ?? null,
            'bounce_reason' => $check['bounce_reason'] ?? null,
            'amount' => (float)$check['amount'],
            'origin_person' => $originPerson,
            'origin_invoice' => $originInvoice,
            'current_holder' => $currentHolder,
            'endorse_payment_id' => $endorsePaymentId,
            'transfer_group_id' => $transferGroupId,
            'transfer_group' => $transferGroup,
            'timeline' => $timeline
        ];
    }

    /**
     * Fetch checks by IDs for printing (entity-scoped).
     */
    public function getChecksByIds($checkIds) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'نشست کاربر نامعتبر است.', 'statusCode' => 401];
        }
        if (!is_array($checkIds)) {
            $checkIds = [$checkIds];
        }
        $checkIds = array_values(array_filter(array_map('intval', $checkIds)));
        if (empty($checkIds)) {
            return ['error' => 'شناسه چک نامعتبر است.', 'statusCode' => 400];
        }

        $history = $this->getCheckHistory($checkIds);
        if (isset($history['error'])) {
            return $history;
        }
        return ['success' => true, 'checks' => $history['checks']];
    }
}
?>
