<?php
require_once __DIR__ . '/BaseModel.php';

class OnlinePayment extends BaseModel {
    protected $tableName = 'online_payments';
    protected $hasEntityId = true;

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureTable();
    }

    private function ensureTable() {
        // Skip the DDL once the table exists: running CREATE TABLE on every
        // request is wasted work and implicitly commits open transactions.
        static $ensured = false;
        if ($ensured) return;
        $exists = @$this->conn->query("SHOW TABLES LIKE 'online_payments'");
        if ($exists && $exists->num_rows > 0) {
            $ensured = true;
            return;
        }
        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS `online_payments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `entity_id` int(11) NOT NULL,
                `invoice_type` varchar(20) NOT NULL DEFAULT 'sales',
                `invoice_id` int(11) NOT NULL,
                `share_token` varchar(64) NOT NULL,
                `amount` decimal(18,2) NOT NULL,
                `amount_rial` bigint(20) NOT NULL,
                `authority` varchar(64) DEFAULT NULL,
                `ref_id` varchar(64) DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `payment_id` int(11) DEFAULT NULL,
                `customer_mobile` varchar(20) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_authority` (`authority`),
                KEY `idx_invoice` (`invoice_type`, `invoice_id`),
                KEY `idx_token` (`share_token`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ensured = true;
    }

    public function createPending(array $row) {
        $stmt = $this->conn->prepare(
            "INSERT INTO online_payments
                (entity_id, invoice_type, invoice_id, share_token, amount, amount_rial, customer_mobile, description, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $entityId = intval($row['entity_id']);
        $invoiceType = $row['invoice_type'] ?? 'sales';
        $invoiceId = intval($row['invoice_id']);
        $token = (string)$row['share_token'];
        $amount = floatval($row['amount']);
        $amountRial = intval($row['amount_rial']);
        $mobile = $row['customer_mobile'] ?? null;
        $desc = $row['description'] ?? null;
        $stmt->bind_param(
            'isisdiss',
            $entityId,
            $invoiceType,
            $invoiceId,
            $token,
            $amount,
            $amountRial,
            $mobile,
            $desc
        );
        if (!$stmt->execute()) {
            throw new Exception('خطا در ثبت درخواست پرداخت.');
        }
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    public function setAuthority($id, $authority) {
        $stmt = $this->conn->prepare("UPDATE online_payments SET authority = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('si', $authority, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function findByAuthority($authority) {
        $stmt = $this->conn->prepare("SELECT * FROM online_payments WHERE authority = ? LIMIT 1");
        $stmt->bind_param('s', $authority);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    public function markPaid($id, $refId, $paymentId) {
        $id = intval($id);
        $refId = (string)$refId;
        $paymentId = $paymentId !== null && $paymentId !== '' ? intval($paymentId) : null;
        if ($paymentId) {
            $stmt = $this->conn->prepare(
                "UPDATE online_payments SET status = 'paid', ref_id = ?, payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->bind_param('sii', $refId, $paymentId, $id);
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE online_payments SET status = 'paid', ref_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->bind_param('si', $refId, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed($id, $note = null) {
        $stmt = $this->conn->prepare(
            "UPDATE online_payments SET status = 'failed', description = CONCAT(IFNULL(description,''), ?) WHERE id = ? AND status = 'pending'"
        );
        $suffix = $note ? (' | ' . $note) : '';
        $stmt->bind_param('si', $suffix, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function findById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM online_payments WHERE id = ? LIMIT 1");
        $id = (int)$id;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    public function markNeedsReview($id, $note) {
        $stmt = $this->conn->prepare(
            "UPDATE online_payments SET status = 'review', description = CONCAT(IFNULL(description,''), ?) WHERE id = ?"
        );
        $suffix = ' | ' . $note;
        $id = (int)$id;
        $stmt->bind_param('si', $suffix, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Which account receives gateway money for an entity: the entity-specific
     * setting, then the global setting / integration default when that account
     * belongs to the entity, then the entity's first bank (non-cash) account.
     * The global setting alone used to be applied to every entity, so payments
     * for a second entity failed after the customer had already paid.
     */
    public static function resolveDepositAccount(mysqli $db, $entityId, array $settings) {
        $entityId = (int)$entityId;
        $candidates = [];
        require_once __DIR__ . '/EntitySettings.php';
        $es = new EntitySettings($db);
        $candidates[] = (int)$es->getValue($entityId, 'zarinpal_deposit_account_id', 0);
        $candidates[] = (int)($settings['zarinpal_deposit_account_id'] ?? 0);
        $candidates[] = (int)($settings['integration_default_online_account_id'] ?? 0);
        $own = $db->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ? LIMIT 1");
        foreach ($candidates as $cid) {
            if ($cid <= 0) continue;
            $own->bind_param('ii', $cid, $entityId);
            $own->execute();
            if (!empty(db_stmt_to_assoc_array($own, false))) {
                $own->close();
                return $cid;
            }
        }
        $own->close();
        $st = $db->prepare("SELECT id FROM accounts WHERE entity_id = ? AND partner_id IS NULL ORDER BY is_cash ASC, id ASC LIMIT 1");
        $st->bind_param('i', $entityId);
        $st->execute();
        $row = db_stmt_to_assoc_array($st)[0] ?? null;
        if ($row) {
            error_log("Online payment: no deposit account configured for entity {$entityId}; using account #{$row['id']}");
        }
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * Book a verified gateway payment into accounting (idempotent).
     * - Amount up to the invoice remainder settles the invoice.
     * - Any excess (invoice settled meanwhile) is kept as an advance receipt
     *   for the customer instead of being silently dropped.
     * Runs with a temporary entity context; never touches a browser session.
     *
     * @return array ['ok'=>bool, 'message'=>string, 'payment_id'=>?int]
     */
    public static function bookVerified(mysqli $db, array $op, $refId) {
        require_once __DIR__ . '/Payment.php';
        require_once __DIR__ . '/Settings.php';
        $settings = (new Settings($db))->getAllSettings();
        $entityId = (int)$op['entity_id'];
        $invoiceId = (int)$op['invoice_id'];
        $opId = (int)$op['id'];
        $paidAmount = (float)$op['amount'];

        $accountId = self::resolveDepositAccount($db, $entityId, $settings);
        if ($accountId <= 0) {
            (new self($db))->markNeedsReview($opId, 'no deposit account; ref ' . $refId);
            return ['ok' => false, 'message' => 'حساب واریز درگاه برای این مجموعه تنظیم نشده است. پرداخت در زرین‌پال موفق بود (کد پیگیری: ' . $refId . ') و برای بررسی ثبت شد.'];
        }

        $hadSession = isset($_SESSION);
        $prevSession = $hadSession ? $_SESSION : null;
        $_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId]];
        try {
            $db->begin_transaction();
            $lock = $db->prepare("SELECT status FROM online_payments WHERE id = ? FOR UPDATE");
            $lock->bind_param('i', $opId);
            $lock->execute();
            $locked = db_stmt_to_assoc_array($lock)[0] ?? null;
            if (($locked['status'] ?? '') === 'paid') {
                $db->commit();
                return ['ok' => true, 'message' => 'قبلاً ثبت شده است.', 'payment_id' => null];
            }

            $inv = $db->prepare("SELECT customerId, totalAmount, discount, tax_amount, paidAmount, invoice_number FROM sales_invoices WHERE id = ? AND entity_id = ? FOR UPDATE");
            $inv->bind_param('ii', $invoiceId, $entityId);
            $inv->execute();
            $invoice = db_stmt_to_assoc_array($inv)[0] ?? null;
            if (!$invoice) {
                throw new Exception('فاکتور یافت نشد.');
            }
            $remaining = max(0.0, invoice_grand_total($invoice) - (float)$invoice['paidAmount']);
            $toInvoice = min($paidAmount, $remaining);
            $excess = round($paidAmount - $toInvoice, 2);
            $customerId = (int)($invoice['customerId'] ?? 0);
            $paymentModel = new Payment($db);
            $paymentId = null;

            if ($toInvoice >= 0.01) {
                $r = $paymentModel->save([
                    'invoiceId' => $invoiceId, 'invoiceType' => 'sales',
                    'person_id' => $customerId ?: null, 'person_type' => 'customer',
                    'transaction_type' => 'receipt', 'type' => 'cash', 'amount' => $toInvoice,
                    'date' => date('Y-m-d'), 'account_id' => $accountId,
                    'description' => 'پرداخت آنلاین زرین‌پال — پیگیری: ' . $refId,
                ], false);
                $paymentId = (int)($r['id'] ?? 0);
            }
            if ($excess >= 0.01 && $customerId > 0) {
                $r2 = $paymentModel->save([
                    'person_id' => $customerId, 'person_type' => 'customer',
                    'transaction_type' => 'receipt', 'type' => 'cash', 'amount' => $excess,
                    'date' => date('Y-m-d'), 'account_id' => $accountId,
                    'description' => 'مازاد پرداخت آنلاین فاکتور #' . ($invoice['invoice_number'] ?: $invoiceId) . ' (علی‌الحساب) — پیگیری: ' . $refId,
                ], false);
                $paymentId = $paymentId ?: (int)($r2['id'] ?? 0);
            }

            $mark = $db->prepare("UPDATE online_payments SET status = 'paid', ref_id = ?, payment_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $refStr = (string)$refId;
            $mark->bind_param('sii', $refStr, $paymentId, $opId);
            $mark->execute();
            $mark->close();

            $customerName = '-';
            if ($customerId > 0) {
                $cn = $db->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
                $cn->bind_param('i', $customerId);
                $cn->execute();
                $customerName = db_stmt_to_assoc_array($cn)[0]['name'] ?? '-';
            }
            $title = 'پرداخت آنلاین مشتری';
            $message = "مشتری «{$customerName}» مبلغ " . number_format($paidAmount, 0, '', ',') . " بابت فاکتور #" . ($invoice['invoice_number'] ?: $invoiceId)
                . " از طریق زرین‌پال پرداخت کرد. کد پیگیری: {$refId}" . ($excess >= 0.01 ? ' (مازاد ' . number_format($excess) . ' به‌صورت علی‌الحساب ثبت شد)' : '');
            $nType = 'online_payment';
            $refType = 'sales_invoice';
            $n = $db->prepare("INSERT INTO notifications (entity_id, type, title, message, reference_type, reference_id, is_read, is_dismissed) VALUES (?, ?, ?, ?, ?, ?, 0, 0)");
            $n->bind_param('issssi', $entityId, $nType, $title, $message, $refType, $invoiceId);
            $n->execute();
            $n->close();

            $db->commit();
            return ['ok' => true, 'message' => $excess >= 0.01 ? 'پرداخت ثبت شد؛ مبلغ مازاد به‌صورت علی‌الحساب ذخیره شد.' : 'پرداخت ثبت شد.', 'payment_id' => $paymentId];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $_) {}
            (new self($db))->markNeedsReview($opId, 'booking failed: ' . mb_substr($e->getMessage(), 0, 120) . '; ref ' . $refId);
            error_log('Online payment booking failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'پرداخت در زرین‌پال موفق بود (کد پیگیری: ' . $refId . ') اما ثبت در حسابداری انجام نشد و برای بررسی ثبت شد.'];
        } finally {
            $_SESSION = $hadSession ? $prevSession : [];
        }
    }

    /**
     * Scheduler job: verify payments whose callback never arrived (customer
     * closed the browser) and book them; give up on stale ones after 2 days.
     */
    public static function reconcilePending(mysqli $db) {
        require_once __DIR__ . '/Settings.php';
        require_once __DIR__ . '/../helpers/ZarinpalHelper.php';
        $settings = (new Settings($db))->getAllSettings();
        if (($settings['zarinpal_enabled'] ?? '0') !== '1' || trim((string)($settings['zarinpal_merchant_id'] ?? '')) === '') {
            return ['skipped' => 'disabled'];
        }
        $res = $db->query(
            "SELECT * FROM online_payments
             WHERE status = 'pending' AND authority IS NOT NULL AND authority <> ''
               AND created_at < (NOW() - INTERVAL 15 MINUTE)
             ORDER BY id ASC LIMIT 20"
        );
        $booked = 0; $failed = 0;
        while ($res && ($op = $res->fetch_assoc())) {
            $verify = ZarinpalHelper::verifyPayment($settings, (int)$op['amount_rial'], $op['authority']);
            if (!empty($verify['ok'])) {
                $r = self::bookVerified($db, $op, (string)($verify['ref_id'] ?? ''));
                if (!empty($r['ok'])) $booked++;
            } elseif (strtotime($op['created_at']) < time() - 2 * 86400) {
                (new self($db))->markFailed((int)$op['id'], 'expired (not paid)');
                $failed++;
            }
        }
        return ['booked' => $booked, 'expired' => $failed];
    }

    /** Admin list for the reconciliation page. */
    public function listForEntity($entityId, $status = '', $limit = 200) {
        $sql = "SELECT op.*, si.invoice_number, c.name AS customer_name
                FROM online_payments op
                LEFT JOIN sales_invoices si ON si.id = op.invoice_id AND op.invoice_type = 'sales'
                LEFT JOIN customers c ON c.id = si.customerId
                WHERE op.entity_id = ?";
        $types = 'i';
        $params = [(int)$entityId];
        if ($status !== '') {
            $sql .= " AND op.status = ?";
            $types .= 's';
            $params[] = $status;
        }
        $sql .= " ORDER BY op.id DESC LIMIT " . max(1, min(1000, (int)$limit));
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
}
