<?php
// /api/models/BankImport.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/Expense.php';
require_once __DIR__ . '/Account.php';

class BankImport extends BaseModel {
    protected $tableName = 'bank_imports';

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureBankFormatSupportsMelli();
        $this->ensureBankFormatSupportsBlubank();
        $this->ensureMatchHistoryTable();
        $this->ensureMatchHistoryCounterpartyColumn();
        $this->ensureMatchedSourceSupportsIncome();
    }

    /** Expand matched_source ENUM to include income (existing DBs). */
    private function ensureMatchedSourceSupportsIncome() {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach (['bank_import_items', 'bank_match_history'] as $table) {
            try {
                $res = $this->conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'matched_source'");
                if (!$res) continue;
                $col = $res->fetch_assoc();
                if (!$col || empty($col['Type'])) continue;
                $type = strtolower((string)$col['Type']);
                if (strpos($type, "'income'") !== false) continue;
                $nullable = (strtoupper((string)($col['Null'] ?? '')) === 'YES') ? 'DEFAULT NULL' : 'NOT NULL';
                $this->conn->query(
                    "ALTER TABLE `{$table}`
                     MODIFY COLUMN `matched_source`
                     ENUM('payment','expense','income','check')
                     COLLATE utf8mb4_unicode_ci {$nullable}"
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    private function ensureMatchHistoryCounterpartyColumn() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM `bank_match_history` LIKE 'counterparty'");
            if ($res && $res->num_rows > 0) return;
            $this->conn->query(
                "ALTER TABLE `bank_match_history`
                 ADD COLUMN `counterparty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 AFTER `document_number`"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    /** Create bank_match_history on existing DBs (preserves matches after import delete / re-import). */
    private function ensureMatchHistoryTable() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'bank_match_history'");
            if ($res && $res->num_rows > 0) return;
            $this->conn->query("
                CREATE TABLE IF NOT EXISTS `bank_match_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `entity_id` int(11) NOT NULL,
                    `account_id` int(11) NOT NULL,
                    `item_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `transaction_date` date NOT NULL,
                    `amount` decimal(15,2) NOT NULL,
                    `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `document_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `counterparty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `matched_source` enum('payment','expense','income','check') COLLATE utf8mb4_unicode_ci NOT NULL,
                    `matched_ref_id` int(11) NOT NULL,
                    `ledger_label` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `matched_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_bank_match_fp` (`account_id`, `item_fingerprint`),
                    KEY `idx_bank_match_entity` (`entity_id`, `account_id`),
                    KEY `idx_bank_match_ledger` (`matched_source`, `matched_ref_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            // ignore — feature degrades gracefully
        }
    }

    /** Expand bank_format ENUM to include melli and blubank (existing DBs). */
    private function ensureBankFormatSupportsMelli() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM `bank_imports` LIKE 'bank_format'");
            if (!$res) return;
            $col = $res->fetch_assoc();
            if (!$col || empty($col['Type'])) return;
            $type = strtolower((string)$col['Type']);
            if (strpos($type, "'melli'") !== false) return;
            $this->conn->query(
                "ALTER TABLE `bank_imports`
                 MODIFY COLUMN `bank_format`
                 ENUM('mellat','dey','melli','blubank','generic')
                 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generic'"
            );
        } catch (Throwable $e) {
            // ignore — import will surface a clear DB error if alter fails
        }
    }

    private function ensureBankFormatSupportsBlubank() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM `bank_imports` LIKE 'bank_format'");
            if (!$res) return;
            $col = $res->fetch_assoc();
            if (!$col || empty($col['Type'])) return;
            $type = strtolower((string)$col['Type']);
            if (strpos($type, "'blubank'") !== false) return;
            $this->conn->query(
                "ALTER TABLE `bank_imports`
                 MODIFY COLUMN `bank_format`
                 ENUM('mellat','dey','melli','blubank','generic')
                 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generic'"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    public function getImportForEntity($importId, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT bi.*, a.name AS account_name, a.current_balance AS account_balance
            FROM bank_imports bi
            LEFT JOIN accounts a ON bi.account_id = a.id
            WHERE bi.id = ? AND bi.entity_id = ?
        ");
        $stmt->bind_param('ii', $importId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    public function listImports($entityId) {
        $stmt = $this->conn->prepare("
            SELECT bi.*, a.name AS account_name,
                (SELECT COUNT(*) FROM bank_import_items bii WHERE bii.import_id = bi.id AND bii.match_status = 'unmatched') AS unmatched_count,
                (SELECT COUNT(*) FROM bank_import_items bii WHERE bii.import_id = bi.id AND bii.match_status = 'ignored') AS ignored_count
            FROM bank_imports bi
            LEFT JOIN accounts a ON bi.account_id = a.id
            WHERE bi.entity_id = ?
            ORDER BY bi.import_date DESC, bi.id DESC
        ");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getItems($importId, $statusFilter = null) {
        if ($statusFilter && in_array($statusFilter, ['unmatched', 'matched', 'ignored'], true)) {
            $stmt = $this->conn->prepare("SELECT * FROM bank_import_items WHERE import_id = ? AND match_status = ? ORDER BY transaction_date DESC, id DESC");
            $stmt->bind_param('is', $importId, $statusFilter);
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM bank_import_items WHERE import_id = ? ORDER BY transaction_date DESC, id DESC");
            $stmt->bind_param('i', $importId);
        }
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function refreshMatchedCount($importId) {
        $stmt = $this->conn->prepare("
            UPDATE bank_imports bi
            SET matched_rows = (
                SELECT COUNT(*) FROM bank_import_items
                WHERE import_id = bi.id AND match_status = 'matched'
            ),
            status = CASE
                WHEN (SELECT COUNT(*) FROM bank_import_items WHERE import_id = bi.id AND match_status = 'unmatched') = 0
                    THEN 'completed'
                ELSE 'in_progress'
            END
            WHERE bi.id = ?
        ");
        $stmt->bind_param('i', $importId);
        $stmt->execute();
    }

    public function getItemOwned($itemId, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT bii.*, bi.account_id, bi.entity_id, bi.id AS import_header_id
            FROM bank_import_items bii
            INNER JOIN bank_imports bi ON bi.id = bii.import_id
            WHERE bii.id = ? AND bi.entity_id = ?
        ");
        $stmt->bind_param('ii', $itemId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows[0] ?? null;
    }

    /**
     * Ledger candidates for an account (payments, expenses, incomes, cashed checks).
     * Includes person names for smarter matching.
     */
    public function getLedgerCandidates($accountId, $entityId, $dateFrom = null, $dateTo = null) {
        $sql = "
            SELECT p.id AS ref_id, 'payment' AS source, p.date, p.amount,
                CASE WHEN p.transaction_type = 'receipt' THEN 1 ELSE -1 END AS direction,
                p.description AS description,
                p.transaction_type AS txn_type,
                p.person_type AS person_type,
                p.person_id AS person_id,
                p.invoiceId AS invoice_id,
                COALESCE(cu.name, su.name, pa.name, ct.name, '') AS person_name,
                CASE
                    WHEN p.person_type = 'other' AND p.transaction_type = 'receipt' THEN CONCAT('درآمد متفرقه', IF(IFNULL(p.description,'') <> '', CONCAT(': ', p.description), ''))
                    WHEN p.person_type = 'other' AND p.transaction_type = 'payment' THEN CONCAT('پرداخت متفرقه', IF(IFNULL(p.description,'') <> '', CONCAT(': ', p.description), ''))
                    WHEN p.description LIKE 'TRANSFER:%' THEN CONCAT('انتقال: ', IFNULL(p.description,''))
                    WHEN p.transaction_type = 'receipt' THEN CONCAT(
                        'دریافت',
                        IF(COALESCE(cu.name, su.name, pa.name, ct.name, '') <> '', CONCAT(' از ', COALESCE(cu.name, su.name, pa.name, ct.name)), ''),
                        IF(IFNULL(p.description,'') <> '', CONCAT(' - ', p.description), '')
                    )
                    ELSE CONCAT(
                        'پرداخت',
                        IF(COALESCE(cu.name, su.name, pa.name, ct.name, '') <> '', CONCAT(' به ', COALESCE(cu.name, su.name, pa.name, ct.name)), ''),
                        IF(IFNULL(p.description,'') <> '', CONCAT(' - ', p.description), '')
                    )
                END AS label,
                '' AS check_number
            FROM payments p
            LEFT JOIN customers cu ON p.person_type = 'customer' AND p.person_id = cu.id
            LEFT JOIN suppliers su ON p.person_type = 'supplier' AND p.person_id = su.id
            LEFT JOIN partners pa ON p.person_type = 'partner' AND p.person_id = pa.id
            LEFT JOIN contacts ct ON p.person_type = 'contact' AND p.person_id = ct.id
            WHERE p.account_id = ? AND p.entity_id = ? AND p.type = 'cash'

            UNION ALL

            SELECT e.id AS ref_id, 'expense' AS source, e.date, e.amount, -1 AS direction,
                e.description AS description,
                'expense' AS txn_type,
                NULL AS person_type,
                NULL AS person_id,
                NULL AS invoice_id,
                '' AS person_name,
                CONCAT('هزینه: ', e.category, IF(e.description IS NULL OR e.description = '', '', CONCAT(' - ', e.description))) AS label,
                '' AS check_number
            FROM expenses e
            WHERE e.account_id = ? AND e.entity_id = ?

            UNION ALL

            SELECT i.id AS ref_id, 'income' AS source, i.date, i.amount, 1 AS direction,
                i.description AS description,
                'income' AS txn_type,
                NULL AS person_type,
                NULL AS person_id,
                NULL AS invoice_id,
                '' AS person_name,
                CONCAT('درآمد: ', i.category, IF(i.description IS NULL OR i.description = '', '', CONCAT(' - ', i.description))) AS label,
                '' AS check_number
            FROM incomes i
            WHERE i.account_id = ? AND i.entity_id = ?

            UNION ALL

            SELECT c.id AS ref_id, 'check' AS source, COALESCE(c.cashed_date, c.dueDate) AS date, c.amount,
                IF(c.type = 'received', 1, -1) AS direction,
                CONCAT('چک ', c.checkNumber, ' ', IFNULL(c.bankName,'')) AS description,
                c.type AS txn_type,
                NULL AS person_type,
                NULL AS person_id,
                NULL AS invoice_id,
                IFNULL(c.bankName, '') AS person_name,
                CONCAT(IF(c.type='received', 'وصول چک: ', 'پاس چک: '), c.checkNumber, IF(IFNULL(c.bankName,'')<>'', CONCAT(' (', c.bankName, ')'), '')) AS label,
                c.checkNumber AS check_number
            FROM checks c
            WHERE c.cashed_in_account_id = ? AND c.status = 'cashed' AND c.entity_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iiiiiiii', $accountId, $entityId, $accountId, $entityId, $accountId, $entityId, $accountId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        if ($dateFrom && $dateTo) {
            $rows = array_values(array_filter($rows, function ($r) use ($dateFrom, $dateTo) {
                $d = $r['date'] ?? null;
                if (!$d) return false;
                return $d >= $dateFrom && $d <= $dateTo;
            }));
        }
        return $rows;
    }

    public function getAlreadyMatchedKeys($importId) {
        $stmt = $this->conn->prepare("
            SELECT matched_source, matched_ref_id
            FROM bank_import_items
            WHERE import_id = ? AND match_status = 'matched'
              AND matched_source IS NOT NULL AND matched_ref_id IS NOT NULL
        ");
        $stmt->bind_param('i', $importId);
        $stmt->execute();
        $used = [];
        foreach (db_stmt_to_assoc_array($stmt) as $r) {
            $used[$r['matched_source'] . ':' . $r['matched_ref_id']] = true;
        }
        return $used;
    }

    /** Keys already matched on this account across all imports (avoid double-link). */
    public function getMatchedKeysForAccount($accountId, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT bii.matched_source, bii.matched_ref_id
            FROM bank_import_items bii
            INNER JOIN bank_imports bi ON bi.id = bii.import_id
            WHERE bi.account_id = ? AND bi.entity_id = ?
              AND bii.match_status = 'matched'
              AND bii.matched_source IS NOT NULL AND bii.matched_ref_id IS NOT NULL
        ");
        $stmt->bind_param('ii', $accountId, $entityId);
        $stmt->execute();
        $used = [];
        foreach (db_stmt_to_assoc_array($stmt) as $r) {
            $used[$r['matched_source'] . ':' . $r['matched_ref_id']] = true;
        }
        return $used;
    }

    private function normalizeFaText($s) {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        $s = str_replace(["\xE2\x80\x8C", '‌'], '', $s);
        $map = [
            'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'آ' => 'ا',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function extractDigits($s) {
        return preg_replace('/\D+/', '', $this->normalizeFaText($s));
    }

    private function nameSimilarity($a, $b) {
        $a = $this->normalizeFaText($a);
        $b = $this->normalizeFaText($b);
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 100;
        if (mb_strpos($a, $b, 0, 'UTF-8') !== false || mb_strpos($b, $a, 0, 'UTF-8') !== false) {
            return 90;
        }
        $ta = preg_split('/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $tb = preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY);
        if (!$ta || !$tb) return 0;
        $hits = 0;
        foreach ($ta as $t) {
            if (mb_strlen($t, 'UTF-8') < 2) continue;
            foreach ($tb as $u) {
                if ($t === $u || mb_strpos($u, $t, 0, 'UTF-8') !== false || mb_strpos($t, $u, 0, 'UTF-8') !== false) {
                    $hits++;
                    break;
                }
            }
        }
        $den = max(count($ta), count($tb));
        return $den > 0 ? (int)round(($hits / $den) * 100) : 0;
    }

    /**
     * Detect if bank abs amount equals book +/- fee.
     */
    private function analyzeAmountMatch($bankAbs, $bookAbs) {
        $bankAbs = abs(floatval($bankAbs));
        $bookAbs = abs(floatval($bookAbs));
        $diff = abs($bankAbs - $bookAbs);

        if ($diff <= 0.5) {
            return ['ok' => true, 'fee' => 0.0, 'mode' => 'exact'];
        }
        if ($diff <= 5 && $diff / max($bookAbs, 1) <= 0.002) {
            return ['ok' => true, 'fee' => $diff, 'mode' => 'near'];
        }

        // Common bank fees (in the accounting unit) — configurable via the
        // bank_import_common_fees setting; the list below preserves the
        // historical defaults for Iranian banks.
        $defaultFees = '180,360,500,720,900,1000,1200,1500,1800,2000,2400,3000,3600,4000,5000,6000,7200,8000,9000,10000,12000,15000,18000,20000,25000,30000,36000,40000,50000,60000,72000,100000';
        $feesSetting = (string)get_app_setting($this->conn, 'bank_import_common_fees', $defaultFees);
        $commonFees = array_values(array_filter(array_map('intval', preg_split('/[,،]/', $feesSetting)), function($v) { return $v > 0; }));
        if (empty($commonFees)) {
            $commonFees = array_map('intval', explode(',', $defaultFees));
        }

        $feeCapRatio = floatval(get_app_setting($this->conn, 'bank_import_fee_cap_ratio', 0.03));
        if ($feeCapRatio <= 0) {
            $feeCapRatio = 0.03;
        }

        if ($bankAbs > $bookAbs) {
            $fee = $bankAbs - $bookAbs;
            $feeRound = (int)round($fee);
            $maxPctFee = max(2000, min(150000, $bookAbs * $feeCapRatio));
            if (in_array($feeRound, $commonFees, true)) {
                return ['ok' => true, 'fee' => $fee, 'mode' => 'fee'];
            }
            if ($fee <= $maxPctFee && ($fee / max($bookAbs, 1)) <= $feeCapRatio) {
                return ['ok' => true, 'fee' => $fee, 'mode' => 'fee_pct'];
            }
        }

        if ($bookAbs > $bankAbs) {
            $fee = $bookAbs - $bankAbs;
            if ($fee <= 50 || ($fee <= 2000 && $fee / max($bookAbs, 1) <= 0.005)) {
                return ['ok' => true, 'fee' => $fee, 'mode' => 'near_rev'];
            }
        }

        return ['ok' => false, 'fee' => $diff, 'mode' => 'none'];
    }

    public function scoreMatchDetailed($bankItem, $candidate) {
        $bankAmount = floatval($bankItem['amount']);
        $bookAbs = abs(floatval($candidate['amount']));
        $dir = intval($candidate['direction']);
        $reasons = [];

        if (($bankAmount > 0 && $dir < 0) || ($bankAmount < 0 && $dir > 0)) {
            return ['score' => 0, 'reasons' => ['جهت تراکنش ناسازگار'], 'amount_mode' => 'none', 'date_diff' => null, 'fee' => 0, 'person_name' => ''];
        }

        $amt = $this->analyzeAmountMatch(abs($bankAmount), $bookAbs);
        if (!$amt['ok']) {
            return ['score' => 0, 'reasons' => ['مبلغ ناسازگار'], 'amount_mode' => 'none', 'date_diff' => null, 'fee' => 0, 'person_name' => ''];
        }

        $score = 0;
        if ($amt['mode'] === 'exact') {
            $score += 45;
            $reasons[] = 'مبلغ دقیق';
        } elseif ($amt['mode'] === 'near' || $amt['mode'] === 'near_rev') {
            $score += 38;
            $reasons[] = 'مبلغ تقریباً برابر';
        } elseif ($amt['mode'] === 'fee') {
            $score += 34;
            $reasons[] = 'مبلغ + کارمزد بانکی (' . number_format($amt['fee'], 0, '', ',') . ')';
        } else {
            $score += 28;
            $reasons[] = 'مبلغ با اختلاف کارمزد محتمل (' . number_format($amt['fee'], 0, '', ',') . ')';
        }

        $bankDate = $bankItem['transaction_date'] ?? $bankItem['date'] ?? null;
        $candDate = $candidate['date'] ?? null;
        $dateDiff = null;
        if ($bankDate && $candDate) {
            $dateDiff = (int)round(abs((strtotime($bankDate) - strtotime($candDate)) / 86400));
            if ($dateDiff === 0) { $score += 32; $reasons[] = 'تاریخ یکسان'; }
            elseif ($dateDiff === 1) { $score += 26; $reasons[] = 'اختلاف ۱ روز'; }
            elseif ($dateDiff === 2) { $score += 20; $reasons[] = 'اختلاف ۲ روز'; }
            elseif ($dateDiff === 3) { $score += 14; $reasons[] = 'اختلاف ۳ روز'; }
            elseif ($dateDiff <= 5) { $score += 8; $reasons[] = "اختلاف {$dateDiff} روز"; }
            elseif ($dateDiff <= 7) { $score += 3; $reasons[] = "اختلاف {$dateDiff} روز"; }
            else {
                return ['score' => 0, 'reasons' => ['خارج از بازه تاریخ'], 'amount_mode' => $amt['mode'], 'date_diff' => $dateDiff, 'fee' => $amt['fee'], 'person_name' => ''];
            }
        } else {
            $score += 5;
        }

        $bankBlob = $this->normalizeFaText(implode(' ', [
            $bankItem['description'] ?? '',
            $bankItem['counterparty'] ?? '',
            $bankItem['note'] ?? '',
            $bankItem['tracking_number'] ?? '',
            $bankItem['reference_number'] ?? '',
            $bankItem['document_number'] ?? '',
            $bankItem['deposit_id'] ?? '',
            $bankItem['branch'] ?? '',
        ]));
        $candBlob = $this->normalizeFaText(implode(' ', [
            $candidate['description'] ?? '',
            $candidate['label'] ?? '',
            $candidate['person_name'] ?? '',
            $candidate['check_number'] ?? '',
        ]));

        $personName = trim((string)($candidate['person_name'] ?? ''));
        $counterparty = trim((string)($bankItem['counterparty'] ?? ''));
        if ($personName !== '') {
            $bestName = max(
                $this->nameSimilarity($personName, $counterparty),
                $this->nameSimilarity($personName, $bankItem['description'] ?? '')
            );
            if ($bestName >= 90) { $score += 22; $reasons[] = 'نام شخص منطبق: ' . $personName; }
            elseif ($bestName >= 60) { $score += 14; $reasons[] = 'شباهت نام شخص'; }
            elseif ($bestName >= 40) { $score += 7; $reasons[] = 'شباهت جزئی نام'; }
        } elseif ($counterparty !== '' && $candBlob !== '') {
            $sim = $this->nameSimilarity($counterparty, $candBlob);
            if ($sim >= 70) { $score += 10; $reasons[] = 'شباهت طرف حساب'; }
        }

        foreach (['tracking_number', 'reference_number', 'document_number', 'deposit_id'] as $rk) {
            $ref = trim((string)($bankItem[$rk] ?? ''));
            if ($ref === '' || $ref === '0') continue;
            $refN = $this->normalizeFaText($ref);
            $refDigits = $this->extractDigits($ref);
            if ($refN !== '' && mb_strpos($candBlob, $refN, 0, 'UTF-8') !== false) {
                $score += 16;
                $reasons[] = 'شماره مرجع مشترک';
                break;
            }
            if (strlen($refDigits) >= 6 && strpos($this->extractDigits($candBlob), $refDigits) !== false) {
                $score += 14;
                $reasons[] = 'رقم مرجع مشترک';
                break;
            }
        }

        $checkNo = trim((string)($candidate['check_number'] ?? ''));
        if ($checkNo !== '') {
            $cn = $this->normalizeFaText($checkNo);
            $cnDigits = $this->extractDigits($checkNo);
            $bankDigits = $this->extractDigits($bankBlob);
            $tail = strlen($cnDigits) >= 6 ? substr($cnDigits, -6) : $cnDigits;
            if (
                ($cn !== '' && mb_strpos($bankBlob, $cn, 0, 'UTF-8') !== false)
                || (strlen($cnDigits) >= 4 && strpos($bankDigits, $cnDigits) !== false)
                || (strlen($tail) >= 4 && strpos($bankDigits, $tail) !== false)
            ) {
                $score += 24;
                $reasons[] = 'شماره چک: ' . $checkNo;
            }
        }

        $stop = ['از', 'به', 'در', 'با', 'برای', 'و', 'یا', 'که', 'این', 'آن', 'شماره', 'حساب', 'کارت', 'بانک', 'طرف', 'مقابل', 'نام', 'کامل', 'حواله', 'انتقال', 'تراکنش'];
        $tokens = preg_split('/\s+/u', $bankBlob, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hits = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t, 'UTF-8') < 3) continue;
            if (in_array($t, $stop, true)) continue;
            if (preg_match('/^\d+$/', $t) && strlen($t) < 5) continue;
            if (mb_strpos($candBlob, $t, 0, 'UTF-8') !== false) {
                $hits++;
            }
            if ($hits >= 4) break;
        }
        if ($hits >= 3) { $score += 12; $reasons[] = 'واژه‌های مشترک شرح'; }
        elseif ($hits === 2) { $score += 7; $reasons[] = 'دو واژه مشترک'; }
        elseif ($hits === 1) { $score += 3; }

        if (($candidate['source'] ?? '') === 'check' && (mb_strpos($bankBlob, 'چک', 0, 'UTF-8') !== false || mb_strpos($bankBlob, 'چك', 0, 'UTF-8') !== false)) {
            $score += 6;
            $reasons[] = 'شرح بانکی مربوط به چک';
        }
        if (($candidate['source'] ?? '') === 'expense' && preg_match('/کارمزد|كارمزد|هزینه/u', $bankBlob)) {
            $score += 5;
            $reasons[] = 'احتمال کارمزد/هزینه';
        }
        if (($candidate['source'] ?? '') === 'income' && preg_match('/سود|درآمد|برگشت کارمزد|اجاره/u', $bankBlob)) {
            $score += 5;
            $reasons[] = 'احتمال درآمد شرکت';
        }

        $score = min(100, max(0, $score));
        return [
            'score' => $score,
            'reasons' => $reasons,
            'amount_mode' => $amt['mode'],
            'fee' => $amt['fee'],
            'date_diff' => $dateDiff,
            'person_name' => $personName,
        ];
    }

    public function scoreMatch($bankItem, $candidate) {
        return intval($this->scoreMatchDetailed($bankItem, $candidate)['score'] ?? 0);
    }

    public function suggestForItem($bankItem, $candidates, $usedKeys, $dateWindowDays = 2) {
        $suggestions = [];
        $bankDate = $bankItem['transaction_date'] ?? $bankItem['date'] ?? null;
        $window = max(0, min(30, intval($dateWindowDays)));

        foreach ($candidates as $cand) {
            $key = $cand['source'] . ':' . $cand['ref_id'];
            if (isset($usedKeys[$key])) continue;
            if ($bankDate && !empty($cand['date'])) {
                $diff = abs((strtotime($bankDate) - strtotime($cand['date'])) / 86400);
                if ($diff > $window) continue;
            }
            $detail = $this->scoreMatchDetailed($bankItem, $cand);
            if (($detail['score'] ?? 0) < 45) continue;
            $bookAbs = abs(floatval($cand['amount']));
            $bankAbs = abs(floatval($bankItem['amount'] ?? 0));
            $feeMode = in_array(($detail['amount_mode'] ?? ''), ['fee', 'fee_pct'], true) && $bankAbs > $bookAbs + 0.5;
            $feeAmt = $feeMode ? ($bankAbs - $bookAbs) : floatval($detail['fee'] ?? 0);
            $needsFeeExpense = $feeMode && $this->isFeeExpenseEligible(
                $cand['source'],
                $bankItem,
                $cand['txn_type'] ?? null,
                $cand['person_id'] ?? null,
                $cand['invoice_id'] ?? null,
                (string)($cand['description'] ?? '')
            );
            $suggestions[] = [
                'source' => $cand['source'],
                'ref_id' => intval($cand['ref_id']),
                'date' => $cand['date'],
                'amount' => floatval($cand['amount']),
                'direction' => intval($cand['direction']),
                'label' => $cand['label'],
                'person_name' => $cand['person_name'] ?? '',
                'check_number' => $cand['check_number'] ?? '',
                'score' => intval($detail['score']),
                'reasons' => $detail['reasons'] ?? [],
                'amount_mode' => $detail['amount_mode'] ?? 'none',
                'fee' => $feeAmt,
                'date_diff' => $detail['date_diff'],
                'needs_fee_expense' => $needsFeeExpense,
                'needs_amount_adjust' => false, // legacy flag — مبلغ اصلی دیگر تغییر نمی‌کند
                'book_abs_amount' => $bookAbs,
                'bank_abs_amount' => $bankAbs,
            ];
        }
        usort($suggestions, function ($a, $b) {
            if ($b['score'] !== $a['score']) return $b['score'] <=> $a['score'];
            $da = $a['date_diff'] ?? 99;
            $db = $b['date_diff'] ?? 99;
            return $da <=> $db;
        });
        return array_slice($suggestions, 0, 10);
    }

    /**
     * Build auto-match proposals (does NOT apply). One ledger ref used at most once.
     */
    public function buildAutoMatchProposals($items, $candidates, $usedKeys, $dateWindowDays = 2, $entityId = null) {
        $pairs = [];
        foreach ($items as $item) {
            if (($item['match_status'] ?? 'unmatched') !== 'unmatched') continue;
            $suggestions = $this->suggestForItem($item, $candidates, $usedKeys, $dateWindowDays);
            if (empty($suggestions)) continue;
            $top = $suggestions[0];
            $second = $suggestions[1] ?? null;
            $accept = false;
            if ($top['score'] >= 72 && (!$second || ($top['score'] - $second['score']) >= 8)) {
                $accept = true;
            } elseif ($top['score'] >= 85 && (!$second || ($top['score'] - $second['score']) >= 4)) {
                $accept = true;
            } elseif ($top['score'] >= 90) {
                $accept = true;
            }
            if (!$accept) continue;
            $pairs[] = [
                'item' => $item,
                'suggestion' => $top,
            ];
        }

        usort($pairs, function ($a, $b) {
            return $b['suggestion']['score'] <=> $a['suggestion']['score'];
        });

        $reserved = $usedKeys;
        $proposals = [];
        foreach ($pairs as $pair) {
            $item = $pair['item'];
            $s = $pair['suggestion'];
            $key = $s['source'] . ':' . $s['ref_id'];
            if (isset($reserved[$key])) continue;
            $reserved[$key] = true;
            $needsFeeExpense = !empty($s['needs_fee_expense']);
            $proposal = [
                'item_id' => intval($item['id']),
                'transaction_date' => $item['transaction_date'],
                'bank_amount' => floatval($item['amount']),
                'bank_description' => $item['description'] ?? '',
                'bank_counterparty' => $item['counterparty'] ?? '',
                'source' => $s['source'],
                'ref_id' => intval($s['ref_id']),
                'ledger_date' => $s['date'],
                'ledger_amount' => floatval($s['amount']) * intval($s['direction']),
                'label' => $s['label'],
                'score' => intval($s['score']),
                'reasons' => $s['reasons'] ?? [],
                'amount_mode' => $s['amount_mode'] ?? '',
                'fee' => $s['fee'] ?? 0,
                'date_diff' => $s['date_diff'],
                'needs_fee_expense' => $needsFeeExpense,
                'needs_amount_adjust' => false,
                'book_abs_amount' => abs(floatval($s['amount'])),
                'bank_abs_amount' => abs(floatval($item['amount'])),
            ];
            if ($entityId) {
                $proposal['change_preview'] = $this->buildChangePreviewBundle(
                    $s['source'],
                    intval($s['ref_id']),
                    $item,
                    intval($entityId),
                    $needsFeeExpense ? floatval($s['fee'] ?? 0) : 0
                );
            }
            $proposals[] = $proposal;
        }
        return $proposals;
    }

    public function buildItemFingerprint($item) {
        $parts = [
            (string)($item['transaction_date'] ?? $item['date'] ?? ''),
            number_format(floatval($item['amount'] ?? 0), 2, '.', ''),
            trim((string)($item['tracking_number'] ?? '')),
            trim((string)($item['reference_number'] ?? '')),
            trim((string)($item['document_number'] ?? '')),
            trim((string)($item['deposit_id'] ?? '')),
            substr($this->normalizeFaText($item['counterparty'] ?? ''), 0, 60),
        ];
        return hash('sha256', implode('|', $parts));
    }

    public function recordMatchHistory($entityId, $accountId, $item, $source, $refId, $ledgerLabel = '') {
        $entityId = intval($entityId);
        $accountId = intval($accountId);
        $refId = intval($refId);
        if ($entityId <= 0 || $accountId <= 0 || $refId <= 0) return;
        if (!in_array($source, ['payment', 'expense', 'income', 'check'], true)) return;

        $fp = $this->buildItemFingerprint($item);
        $stmt = $this->conn->prepare("
            INSERT INTO bank_match_history
            (entity_id, account_id, item_fingerprint, transaction_date, amount,
             tracking_number, reference_number, document_number, counterparty,
             matched_source, matched_ref_id, ledger_label)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                matched_source = VALUES(matched_source),
                matched_ref_id = VALUES(matched_ref_id),
                ledger_label = VALUES(ledger_label),
                transaction_date = VALUES(transaction_date),
                amount = VALUES(amount),
                tracking_number = VALUES(tracking_number),
                reference_number = VALUES(reference_number),
                document_number = VALUES(document_number),
                counterparty = VALUES(counterparty),
                matched_at = CURRENT_TIMESTAMP
        ");
        $txDate = (string)($item['transaction_date'] ?? $item['date'] ?? date('Y-m-d'));
        $amount = floatval($item['amount'] ?? 0);
        $track = trim((string)($item['tracking_number'] ?? ''));
        $ref = trim((string)($item['reference_number'] ?? ''));
        $doc = trim((string)($item['document_number'] ?? ''));
        $counterparty = trim((string)($item['counterparty'] ?? ''));
        $label = trim((string)$ledgerLabel);
        $stmt->bind_param(
            'iissdsssssis',
            $entityId,
            $accountId,
            $fp,
            $txDate,
            $amount,
            $track,
            $ref,
            $doc,
            $counterparty,
            $source,
            $refId,
            $label
        );
        $stmt->execute();
    }

    public function getMatchHistoryForAccount($accountId, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT * FROM bank_match_history
            WHERE account_id = ? AND entity_id = ?
            ORDER BY matched_at DESC
        ");
        $stmt->bind_param('ii', $accountId, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function findHistoryRecordForItem($item, array $history) {
        $fp = $this->buildItemFingerprint($item);
        foreach ($history as $h) {
            if (($h['item_fingerprint'] ?? '') === $fp) {
                return $h;
            }
        }
        $itemDate = (string)($item['transaction_date'] ?? '');
        $itemAmt = floatval($item['amount'] ?? 0);
        $itemTrack = trim((string)($item['tracking_number'] ?? ''));
        $itemRef = trim((string)($item['reference_number'] ?? ''));
        $itemCp = substr($this->normalizeFaText($item['counterparty'] ?? ''), 0, 60);
        foreach ($history as $h) {
            if (abs(floatval($h['amount']) - $itemAmt) > 0.01) continue;
            if (($h['transaction_date'] ?? '') !== $itemDate) continue;
            if ($itemTrack !== '' && $itemTrack === trim((string)($h['tracking_number'] ?? ''))) {
                return $h;
            }
            if ($itemRef !== '' && $itemRef === trim((string)($h['reference_number'] ?? ''))) {
                return $h;
            }
        }
        if ($itemCp !== '') {
            foreach ($history as $h) {
                if (abs(floatval($h['amount']) - $itemAmt) > 0.01) continue;
                if (($h['transaction_date'] ?? '') !== $itemDate) continue;
                $hCp = substr($this->normalizeFaText($h['counterparty'] ?? ''), 0, 60);
                if ($hCp !== '' && $hCp === $itemCp) {
                    return $h;
                }
            }
        }
        $dayAmountMatches = [];
        foreach ($history as $h) {
            if (abs(floatval($h['amount']) - $itemAmt) > 0.01) continue;
            if (($h['transaction_date'] ?? '') !== $itemDate) continue;
            $dayAmountMatches[] = $h;
        }
        if (count($dayAmountMatches) === 1) {
            return $dayAmountMatches[0];
        }
        return null;
    }

    /**
     * Persist matches from all imports of this account (for re-import recall).
     */
    public function backfillMatchHistoryForAccount($accountId, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT bii.*, bi.account_id, bi.entity_id
            FROM bank_import_items bii
            INNER JOIN bank_imports bi ON bi.id = bii.import_id
            WHERE bi.account_id = ? AND bi.entity_id = ?
              AND bii.match_status = 'matched'
              AND bii.matched_source IS NOT NULL AND bii.matched_ref_id IS NOT NULL
        ");
        $stmt->bind_param('ii', $accountId, $entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $item) {
            $source = (string)$item['matched_source'];
            $refId = intval($item['matched_ref_id']);
            $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
            $label = empty($loaded['error'])
                ? $this->buildLedgerLabelFromLoaded($source, $loaded)
                : '';
            $this->recordMatchHistory($entityId, intval($accountId), $item, $source, $refId, $label);
        }
    }

    /**
     * When the same statement is imported again, archive matches and remove older overlapping imports.
     * @return int number of retired imports
     */
    public function retireOverlappingImports($accountId, $entityId, $periodStart, $periodEnd, $keepImportId) {
        $accountId = intval($accountId);
        $entityId = intval($entityId);
        $keepImportId = intval($keepImportId);
        if ($accountId <= 0 || $entityId <= 0 || $keepImportId <= 0) return 0;

        $sql = "
            SELECT id FROM bank_imports
            WHERE account_id = ? AND entity_id = ? AND id != ?
        ";
        $types = 'iii';
        $params = [$accountId, $entityId, $keepImportId];

        if ($periodStart && $periodEnd) {
            $sql .= " AND period_start IS NOT NULL AND period_end IS NOT NULL
                      AND period_start <= ? AND period_end >= ?";
            $types .= 'ss';
            $params[] = $periodEnd;
            $params[] = $periodStart;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $oldIds = array_column(db_stmt_to_assoc_array($stmt), 'id');
        $retired = 0;
        foreach ($oldIds as $oldId) {
            $oldId = intval($oldId);
            if ($oldId <= 0) continue;
            $this->archiveImportMatchesToHistory($oldId, $entityId);
            $del = $this->conn->prepare("DELETE FROM bank_imports WHERE id = ? AND entity_id = ?");
            $del->bind_param('ii', $oldId, $entityId);
            $del->execute();
            if ($del->affected_rows > 0) {
                $retired++;
            }
        }
        return $retired;
    }

    private function ledgerRefBelongsToAccount($source, $refId, $entityId, $accountId) {
        $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
        if (!empty($loaded['error'])) return false;
        $row = $loaded['row'] ?? [];
        if ($source === 'payment' || $source === 'expense' || $source === 'income') {
            return intval($row['account_id'] ?? 0) === intval($accountId);
        }
        if ($source === 'check') {
            return intval($row['cashed_in_account_id'] ?? 0) === intval($accountId);
        }
        return false;
    }

    private function buildLedgerLabelFromLoaded($source, $loaded) {
        $row = $loaded['row'] ?? [];
        if ($source === 'payment') {
            $txn = ($row['transaction_type'] ?? '') === 'receipt' ? 'دریافت' : 'پرداخت';
            return $txn . ' #' . intval($row['id'] ?? 0);
        }
        if ($source === 'expense') {
            return 'هزینه: ' . trim((string)($row['category'] ?? '')) . ' #' . intval($row['id'] ?? 0);
        }
        if ($source === 'income') {
            return 'درآمد: ' . trim((string)($row['category'] ?? '')) . ' #' . intval($row['id'] ?? 0);
        }
        if ($source === 'check') {
            return 'چک ' . trim((string)($row['checkNumber'] ?? ''));
        }
        return '';
    }

    /**
     * Resolve a prior match from history (after re-import or when import was deleted).
     * @return array|null
     */
    public function resolvePriorMatchForItem($item, $accountId, $entityId, $usedKeys) {
        $history = $this->getMatchHistoryForAccount(intval($accountId), intval($entityId));
        if (empty($history)) return null;

        $hist = $this->findHistoryRecordForItem($item, $history);
        if (!$hist) return null;

        $source = (string)($hist['matched_source'] ?? '');
        $refId = intval($hist['matched_ref_id'] ?? 0);
        if (!$refId || !in_array($source, ['payment', 'expense', 'income', 'check'], true)) return null;

        $key = $source . ':' . $refId;
        if (isset($usedKeys[$key])) return null;

        if (!$this->ledgerRefBelongsToAccount($source, $refId, $entityId, $accountId)) {
            return null;
        }

        $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
        if (!empty($loaded['error'])) return null;

        $bookAbs = abs(floatval($loaded['amount']));
        $bankAbs = abs(floatval($item['amount'] ?? 0));
        $amt = $this->analyzeAmountMatch($bankAbs, $bookAbs);
        if (!$amt['ok']) return null;

        $direction = ($source === 'expense') ? -1 : 1;
        if ($source === 'payment') {
            $direction = (($loaded['row']['transaction_type'] ?? '') === 'receipt') ? 1 : -1;
        } elseif ($source === 'income') {
            $direction = 1;
        } elseif ($source === 'check') {
            $direction = (($loaded['row']['type'] ?? '') === 'received') ? 1 : -1;
        }

        $feeMode = in_array(($amt['mode'] ?? ''), ['fee', 'fee_pct'], true) && $bankAbs > $bookAbs + 0.5;
        $needsFeeExpense = $feeMode && $this->isFeeExpenseEligible(
            $source,
            $item,
            $loaded['row']['transaction_type'] ?? $loaded['row']['type'] ?? null,
            $loaded['row']['person_id'] ?? null,
            $loaded['row']['invoiceId'] ?? null,
            (string)($loaded['row']['description'] ?? '')
        );

        return [
            'source' => $source,
            'ref_id' => $refId,
            'date' => $loaded['row']['date'] ?? $loaded['row']['dueDate'] ?? null,
            'amount' => floatval($loaded['amount']),
            'direction' => $direction,
            'label' => trim((string)($hist['ledger_label'] ?? '')) ?: $this->buildLedgerLabelFromLoaded($source, $loaded),
            'score' => 98,
            'reasons' => ['تطبیق قبلی (ورود مجدد صورتحساب)'],
            'amount_mode' => $amt['mode'],
            'fee' => $feeMode ? ($bankAbs - $bookAbs) : floatval($amt['fee'] ?? 0),
            'date_diff' => 0,
            'needs_fee_expense' => $needsFeeExpense,
            'needs_amount_adjust' => false,
            'book_abs_amount' => $bookAbs,
            'bank_abs_amount' => $bankAbs,
            'prior_match' => true,
        ];
    }

    public function buildPriorMatchProposals($items, $accountId, $entityId, $usedKeys) {
        $proposals = [];
        $reserved = $usedKeys;
        foreach ($items as $item) {
            if (($item['match_status'] ?? 'unmatched') !== 'unmatched') continue;
            $prior = $this->resolvePriorMatchForItem($item, $accountId, $entityId, $reserved);
            if (!$prior) continue;

            $key = $prior['source'] . ':' . $prior['ref_id'];
            $reserved[$key] = true;

            $needsFee = !empty($prior['needs_fee_expense']);
            $proposal = [
                'item_id' => intval($item['id']),
                'transaction_date' => $item['transaction_date'],
                'bank_amount' => floatval($item['amount']),
                'bank_description' => $item['description'] ?? '',
                'bank_counterparty' => $item['counterparty'] ?? '',
                'source' => $prior['source'],
                'ref_id' => intval($prior['ref_id']),
                'ledger_date' => $prior['date'],
                'ledger_amount' => floatval($prior['amount']) * intval($prior['direction']),
                'label' => $prior['label'],
                'score' => intval($prior['score']),
                'reasons' => $prior['reasons'],
                'amount_mode' => $prior['amount_mode'] ?? '',
                'fee' => $prior['fee'] ?? 0,
                'date_diff' => $prior['date_diff'],
                'needs_fee_expense' => $needsFee,
                'needs_amount_adjust' => false,
                'book_abs_amount' => $prior['book_abs_amount'],
                'bank_abs_amount' => $prior['bank_abs_amount'],
                'prior_match' => true,
            ];
            $proposal['change_preview'] = $this->buildChangePreviewBundle(
                $prior['source'],
                intval($prior['ref_id']),
                $item,
                intval($entityId),
                $needsFee ? floatval($prior['fee'] ?? 0) : 0
            );
            $proposals[] = $proposal;
        }
        return $proposals;
    }

    public function archiveImportMatchesToHistory($importId, $entityId) {
        $import = $this->getImportForEntity(intval($importId), intval($entityId));
        if (!$import) return;
        $accountId = intval($import['account_id']);
        foreach ($this->getItems(intval($importId), 'matched') as $item) {
            $source = $item['matched_source'] ?? '';
            $refId = intval($item['matched_ref_id'] ?? 0);
            if (!$refId || !in_array($source, ['payment', 'expense', 'income', 'check'], true)) continue;
            $label = '';
            $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
            if (empty($loaded['error'])) {
                $label = $this->buildLedgerLabelFromLoaded($source, $loaded);
            }
            $this->recordMatchHistory($entityId, $accountId, $item, $source, $refId, $label);
        }
    }

    public function computeAccountBalanceAtDate($accountId, $entityId, $asOfDate) {
        if (!$asOfDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$asOfDate)) {
            return null;
        }
        $stmt = $this->conn->prepare("
            SELECT
                (a.initial_balance +
                    COALESCE((SELECT SUM(IF(p.transaction_type='receipt', p.amount, -p.amount))
                        FROM payments p WHERE p.account_id = a.id AND p.type='cash' AND p.entity_id = ?
                          AND p.date <= ?), 0) -
                    COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.account_id = a.id AND e.entity_id = ?
                          AND e.date <= ?), 0) +
                    COALESCE((SELECT SUM(i.amount) FROM incomes i WHERE i.account_id = a.id AND i.entity_id = ?
                          AND i.date <= ?), 0) +
                    COALESCE((SELECT SUM(IF(c.type='received', c.amount, -c.amount))
                        FROM checks c WHERE c.cashed_in_account_id = a.id AND c.status='cashed' AND c.entity_id = ?
                          AND COALESCE(c.cashed_date, c.dueDate) <= ?), 0)
                ) AS balance
            FROM accounts a
            WHERE a.id = ? AND a.entity_id = ?
        ");
        $stmt->bind_param(
            'isisisisii',
            $entityId, $asOfDate,
            $entityId, $asOfDate,
            $entityId, $asOfDate,
            $entityId, $asOfDate,
            $accountId, $entityId
        );
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows[0])) return null;
        return round(floatval($rows[0]['balance']), 2);
    }

    public function buildReconciliationSummary($import, $entityId) {
        $accountId = intval($import['account_id'] ?? 0);
        $periodStart = $import['period_start'] ?? null;
        $periodEnd = $import['period_end'] ?? null;

        $bookCurrent = floatval($import['account_balance'] ?? 0);
        $bookAtStart = $periodStart ? $this->computeAccountBalanceAtDate($accountId, $entityId, $periodStart) : null;
        $bookAtEnd = $periodEnd ? $this->computeAccountBalanceAtDate($accountId, $entityId, $periodEnd) : null;

        $stmtOpen = (isset($import['opening_balance']) && $import['opening_balance'] !== null && $import['opening_balance'] !== '')
            ? floatval($import['opening_balance']) : null;
        $stmtClose = (isset($import['closing_balance']) && $import['closing_balance'] !== null && $import['closing_balance'] !== '')
            ? floatval($import['closing_balance']) : null;

        $openingDiff = ($stmtOpen !== null && $bookAtStart !== null) ? round($bookAtStart - $stmtOpen, 2) : null;
        $closingDiff = ($stmtClose !== null && $bookAtEnd !== null) ? round($bookAtEnd - $stmtClose, 2) : null;

        return [
            'account_balance_current' => $bookCurrent,
            'book_balance_period_start' => $bookAtStart,
            'book_balance_period_end' => $bookAtEnd,
            'statement_opening_balance' => $stmtOpen,
            'statement_closing_balance' => $stmtClose,
            'opening_diff' => $openingDiff,
            'closing_diff' => $closingDiff,
            'current_vs_statement_closing_diff' => ($stmtClose !== null) ? round($bookCurrent - $stmtClose, 2) : null,
        ];
    }

    public function applyMatch($itemId, $source, $refId, $importId, $recordHistory = true) {
        $paymentId = ($source === 'payment') ? $refId : null;
        $stmt = $this->conn->prepare("
            UPDATE bank_import_items
            SET match_status = 'matched', matched_source = ?, matched_ref_id = ?, matched_payment_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param('siii', $source, $refId, $paymentId, $itemId);
        $stmt->execute();
        $this->refreshMatchedCount($importId);

        if ($recordHistory) {
            $stmt2 = $this->conn->prepare("
                SELECT bii.*, bi.account_id, bi.entity_id
                FROM bank_import_items bii
                INNER JOIN bank_imports bi ON bi.id = bii.import_id
                WHERE bii.id = ?
            ");
            $stmt2->bind_param('i', $itemId);
            $stmt2->execute();
            $rows = db_stmt_to_assoc_array($stmt2);
            $item = $rows[0] ?? null;
            if ($item) {
                $loaded = $this->loadLedgerForEnrich($source, $refId, intval($item['entity_id']));
                $label = empty($loaded['error'])
                    ? $this->buildLedgerLabelFromLoaded($source, $loaded)
                    : '';
                $this->recordMatchHistory(
                    intval($item['entity_id']),
                    intval($item['account_id']),
                    $item,
                    $source,
                    $refId,
                    $label
                );
            }
        }
    }

    /** Compact bank-line details for appending to ledger description. */
    public function buildBankDetailSnippet($bankItem) {
        $parts = [];
        $date = trim((string)($bankItem['transaction_date'] ?? ''));
        if ($date !== '') {
            $parts[] = 'تاریخ: ' . (function_exists('gregorian_to_jalali') ? gregorian_to_jalali($date) : $date);
        }
        $time = trim((string)($bankItem['transaction_time'] ?? ''));
        if ($time !== '') {
            $parts[] = 'ساعت: ' . $time;
        }
        $amt = floatval($bankItem['amount'] ?? 0);
        if (abs($amt) > 0.0001) {
            $parts[] = 'مبلغ بانک: ' . number_format(abs($amt), 0, '.', ',') . ($amt >= 0 ? ' (واریز)' : ' (برداشت)');
        }
        $desc = trim((string)($bankItem['description'] ?? ''));
        if ($desc !== '') $parts[] = 'شرح: ' . $desc;
        $cp = trim((string)($bankItem['counterparty'] ?? ''));
        if ($cp !== '') $parts[] = 'طرف: ' . $cp;
        $track = trim((string)($bankItem['tracking_number'] ?? ''));
        if ($track !== '') $parts[] = 'پیگیری: ' . $track;
        $ref = trim((string)($bankItem['reference_number'] ?? ''));
        if ($ref !== '' && $ref !== $track) $parts[] = 'مرجع: ' . $ref;
        $doc = trim((string)($bankItem['document_number'] ?? ''));
        if ($doc !== '') $parts[] = 'سند: ' . $doc;
        $branch = trim((string)($bankItem['branch'] ?? ''));
        if ($branch !== '') $parts[] = 'شعبه: ' . $branch;
        $note = trim((string)($bankItem['note'] ?? ''));
        if ($note !== '') $parts[] = 'یادداشت: ' . $note;
        return implode(' | ', $parts);
    }

    public function composeEnrichedDescription($oldDesc, $bankItem, $amountAdjusted = false) {
        $oldDesc = trim((string)$oldDesc);
        $marker = '[جزئیات بانک]';
        if (mb_strpos($oldDesc, $marker, 0, 'UTF-8') !== false) {
            return $oldDesc;
        }
        $snippet = $this->buildBankDetailSnippet($bankItem);
        if ($snippet === '') {
            return $oldDesc;
        }
        $extra = $marker . ' ' . $snippet;
        return $oldDesc === '' ? $extra : ($oldDesc . "\n" . $extra);
    }

    /**
     * Fee expense is only for bank withdrawals where bank abs > book abs.
     * For transfers, only the source leg (payment) may create the fee — never the destination receipt.
     */
    public function isFeeExpenseEligible($source, $bankItem, $txnType = null, $personId = null, $invoiceId = null, $description = '') {
        if (floatval($bankItem['amount'] ?? 0) >= -0.0001) {
            return false; // کارمزد فقط روی برداشت بانکی
        }
        if ($source === 'payment') {
            $isTransfer = $this->isTransferLikeRow($personId, $invoiceId, $description);
            if ($isTransfer && ($txnType === 'receipt')) {
                return false; // مقصد انتقال — کارمزد فقط روی مبدأ
            }
        }
        return true;
    }

    private function isTransferLikeRow($personId, $invoiceId, $description = '') {
        if (is_string($description) && strpos($description, 'TRANSFER:') === 0) {
            return true;
        }
        return empty($personId) && empty($invoiceId);
    }

    private function resolvePersonDisplayName($personType, $personId, $entityId) {
        $personId = intval($personId);
        $entityId = intval($entityId);
        if ($personId <= 0 || $entityId <= 0) return '';
        $table = null;
        if ($personType === 'customer') $table = 'customers';
        elseif ($personType === 'supplier') $table = 'suppliers';
        elseif ($personType === 'partner') $table = 'partners';
        elseif ($personType === 'contact') $table = 'contacts';
        if (!$table) return '';
        $stmt = $this->conn->prepare("SELECT name FROM `{$table}` WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $personId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return trim((string)($rows[0]['name'] ?? ''));
    }

    private function resolveTransferAccountNames($description, $entityId) {
        if (!preg_match('/^TRANSFER:(\d+):(\d+):/', (string)$description, $m)) {
            return ['from' => '', 'to' => ''];
        }
        $fromId = intval($m[1]);
        $toId = intval($m[2]);
        $names = ['from' => '', 'to' => ''];
        $stmt = $this->conn->prepare("SELECT id, name FROM accounts WHERE entity_id = ? AND id IN (?, ?)");
        $stmt->bind_param('iii', $entityId, $fromId, $toId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $a) {
            if (intval($a['id']) === $fromId) $names['from'] = $a['name'];
            if (intval($a['id']) === $toId) $names['to'] = $a['name'];
        }
        return $names;
    }

    /** Clear fee expense description for humans. */
    public function buildFeeExpenseDescription($source, $row, $bookAbs, $bankItem, $entityId) {
        $bookFmt = number_format(abs(floatval($bookAbs)), 0, '.', ',');
        $track = trim((string)($bankItem['tracking_number'] ?? ''));
        $personId = $row['person_id'] ?? null;
        $invoiceId = $row['invoiceId'] ?? null;
        $desc = (string)($row['description'] ?? '');
        $txnType = $row['transaction_type'] ?? null;
        $isTransfer = ($source === 'payment') && $this->isTransferLikeRow($personId, $invoiceId, $desc);

        if ($isTransfer) {
            $acc = $this->resolveTransferAccountNames($desc, $entityId);
            $text = "کارمزد انتقال مربوط به تراکنش {$bookFmt} تومان";
            if ($acc['from'] !== '' || $acc['to'] !== '') {
                $text .= ' از ' . ($acc['from'] !== '' ? $acc['from'] : 'حساب مبدأ');
                $text .= ' به ' . ($acc['to'] !== '' ? $acc['to'] : 'حساب مقصد');
            }
        } elseif ($source === 'payment' && ($txnType === 'payment' || $txnType === null)) {
            $name = $this->resolvePersonDisplayName($row['person_type'] ?? '', $personId, $entityId);
            if ($name === '') {
                $name = trim((string)($bankItem['counterparty'] ?? ''));
            }
            $text = "کارمزد انتقال مربوط به تراکنش {$bookFmt} تومان";
            if ($name !== '') {
                $text .= " به {$name}";
            }
        } elseif ($source === 'payment' && $txnType === 'receipt') {
            $name = $this->resolvePersonDisplayName($row['person_type'] ?? '', $personId, $entityId);
            if ($name === '') {
                $name = trim((string)($bankItem['counterparty'] ?? ''));
            }
            $text = "کارمزد بانکی مربوط به تراکنش {$bookFmt} تومان";
            if ($name !== '') {
                $text .= " از {$name}";
            }
        } elseif ($source === 'expense') {
            $cat = trim((string)($row['category'] ?? ''));
            $text = "کارمزد بانکی مربوط به هزینه {$bookFmt} تومان";
            if ($cat !== '') {
                $text .= " ({$cat})";
            }
        } elseif ($source === 'check') {
            $checkNo = trim((string)($row['checkNumber'] ?? ''));
            $text = "کارمزد بانکی مربوط به چک" . ($checkNo !== '' ? " {$checkNo}" : '') . " مبلغ {$bookFmt} تومان";
        } else {
            $text = "کارمزد بانکی مربوط به تراکنش {$bookFmt} تومان";
        }

        if ($track !== '') {
            $text .= " | پیگیری:{$track}";
        }
        return $text;
    }

    /**
     * Build plan for a separate fee expense (does not mutate ledger amount).
     * @return array|null null when no fee should be created
     */
    public function buildFeeExpensePlan($source, $refId, $bankItem, $entityId, $feeAmount = null) {
        $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
        if (!empty($loaded['error'])) return null;

        $bookAbs = abs(floatval($loaded['amount']));
        $bankAbs = abs(floatval($bankItem['amount'] ?? 0));
        $fee = $feeAmount !== null ? abs(floatval($feeAmount)) : ($bankAbs - $bookAbs);
        if ($fee < 0.5 || $bankAbs <= $bookAbs + 0.5) {
            return null;
        }

        $row = $loaded['row'] ?? [];
        $txnType = $row['transaction_type'] ?? null;
        if ($source === 'expense') $txnType = 'expense';
        if ($source === 'income') $txnType = 'income';
        if ($source === 'check') $txnType = $row['type'] ?? null;
        $personId = $row['person_id'] ?? null;
        $invoiceId = $row['invoiceId'] ?? null;
        $desc = (string)($row['description'] ?? '');

        if (!$this->isFeeExpenseEligible($source, $bankItem, $txnType, $personId, $invoiceId, $desc)) {
            return null;
        }

        $accountId = 0;
        if ($source === 'payment' || $source === 'expense' || $source === 'income') {
            $accountId = intval($row['account_id'] ?? 0);
        } elseif ($source === 'check') {
            $accountId = intval($row['cashed_in_account_id'] ?? 0);
        }
        if ($accountId <= 0) {
            $accountId = intval($bankItem['account_id'] ?? 0);
        }
        if ($accountId <= 0) return null;

        $date = $bankItem['transaction_date'] ?? ($row['date'] ?? date('Y-m-d'));
        $description = $this->buildFeeExpenseDescription($source, $row, $bookAbs, $bankItem, $entityId);

        return [
            'amount' => $fee,
            'account_id' => $accountId,
            'date' => $date,
            'category' => 'بانک / کارمزد',
            'description' => $description,
            'ledger_source' => $source,
            'ledger_ref_id' => intval($refId),
        ];
    }

    /** Create the fee expense from a plan. */
    public function createBankFeeExpense($plan, $entityId) {
        if (empty($plan) || empty($plan['amount']) || empty($plan['account_id'])) {
            return ['error' => 'برنامه ثبت کارمزد نامعتبر است.'];
        }
        try {
            $expense = new Expense($this->conn);
            $result = $expense->save([
                'category' => $plan['category'] ?? 'بانک / کارمزد',
                'date' => $plan['date'],
                'amount' => floatval($plan['amount']),
                'description' => $plan['description'] ?? 'کارمزد بانکی',
                'account_id' => intval($plan['account_id']),
            ]);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
        if (!empty($result['error'])) return $result;
        return [
            'success' => true,
            'expense_id' => intval($result['id'] ?? 0),
            'amount' => floatval($plan['amount']),
        ];
    }

    /**
     * Load ledger row fields needed for preview/enrich.
     * @return array{error?:string,source?:string,amount?:float,description?:string,row?:array}
     */
    public function loadLedgerForEnrich($source, $refId, $entityId) {
        $source = (string)$source;
        $refId = intval($refId);
        $entityId = intval($entityId);
        if ($refId <= 0 || !in_array($source, ['payment', 'expense', 'income', 'check'], true)) {
            return ['error' => 'منبع یا شناسه نامعتبر است.'];
        }
        if ($source === 'payment') {
            $stmt = $this->conn->prepare("SELECT * FROM payments WHERE id = ? AND entity_id = ? LIMIT 1");
            $stmt->bind_param('ii', $refId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $row = $rows[0] ?? null;
            if (!$row) return ['error' => 'پرداخت/دریافت یافت نشد.'];
            return [
                'source' => 'payment',
                'amount' => floatval($row['amount']),
                'description' => (string)($row['description'] ?? ''),
                'row' => $row,
            ];
        }
        if ($source === 'expense') {
            $stmt = $this->conn->prepare("SELECT * FROM expenses WHERE id = ? AND entity_id = ? LIMIT 1");
            $stmt->bind_param('ii', $refId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $row = $rows[0] ?? null;
            if (!$row) return ['error' => 'هزینه یافت نشد.'];
            return [
                'source' => 'expense',
                'amount' => floatval($row['amount']),
                'description' => (string)($row['description'] ?? ''),
                'row' => $row,
            ];
        }
        if ($source === 'income') {
            $stmt = $this->conn->prepare("SELECT * FROM incomes WHERE id = ? AND entity_id = ? LIMIT 1");
            $stmt->bind_param('ii', $refId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $row = $rows[0] ?? null;
            if (!$row) return ['error' => 'درآمد یافت نشد.'];
            return [
                'source' => 'income',
                'amount' => floatval($row['amount']),
                'description' => (string)($row['description'] ?? ''),
                'row' => $row,
            ];
        }
        $stmt = $this->conn->prepare("SELECT * FROM checks WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $refId, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        $row = $rows[0] ?? null;
        if (!$row) return ['error' => 'چک یافت نشد.'];
        return [
            'source' => 'check',
            'amount' => floatval($row['amount']),
            'description' => '',
            'row' => $row,
        ];
    }

    /** Preview before/after for description enrichment only (مبلغ اصلی تغییر نمی‌کند). */
    public function previewLedgerEnrichment($source, $refId, $bankItem, $entityId, $adjustAmount = false) {
        $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
        if (!empty($loaded['error'])) return $loaded;

        $beforeAmt = floatval($loaded['amount']);
        $beforeDesc = (string)($loaded['description'] ?? '');
        $supportsDesc = in_array($source, ['payment', 'expense', 'income'], true);
        $afterDesc = $supportsDesc
            ? $this->composeEnrichedDescription($beforeDesc, $bankItem, false)
            : $beforeDesc;

        return [
            'success' => true,
            'supports_description' => $supportsDesc,
            'before' => [
                'amount' => $beforeAmt,
                'description' => $beforeDesc,
            ],
            'after' => [
                'amount' => $beforeAmt,
                'description' => $afterDesc,
            ],
            'will_change_amount' => false,
            'will_change_description' => $supportsDesc && $afterDesc !== $beforeDesc,
        ];
    }

    /** Bundle used by UI: before/after description + optional fee expense. */
    public function buildChangePreviewBundle($source, $refId, $bankItem, $entityId, $feeHint = 0) {
        $match = $this->previewLedgerEnrichment($source, $refId, $bankItem, $entityId, false);
        if (!empty($match['error'])) {
            return ['error' => $match['error']];
        }
        $feePlan = $this->buildFeeExpensePlan($source, $refId, $bankItem, $entityId, $feeHint > 0 ? $feeHint : null);
        return [
            'supports_description' => !empty($match['supports_description']),
            'before' => $match['before'],
            'after_match' => $match['after'],
            'after_adjust' => $match['after'], // legacy alias — مبلغ دیگر تغییر نمی‌کند
            'will_change_description' => !empty($match['will_change_description']),
            'fee_expense' => $feePlan,
        ];
    }

    /** Attach change_preview to suggestion list for a bank item. */
    public function attachChangePreviewsToSuggestions($suggestions, $bankItem, $entityId) {
        if (!is_array($suggestions) || empty($suggestions)) return $suggestions;
        foreach ($suggestions as &$s) {
            $s['change_preview'] = $this->buildChangePreviewBundle(
                $s['source'] ?? '',
                intval($s['ref_id'] ?? 0),
                $bankItem,
                intval($entityId),
                !empty($s['needs_fee_expense']) ? floatval($s['fee'] ?? 0) : 0
            );
            // Re-sync eligibility from plan (authoritative)
            if (!empty($s['change_preview']['fee_expense'])) {
                $s['needs_fee_expense'] = true;
                $s['fee'] = floatval($s['change_preview']['fee_expense']['amount']);
            } else {
                $s['needs_fee_expense'] = false;
            }
            $s['needs_amount_adjust'] = false;
        }
        unset($s);
        return $suggestions;
    }

    /**
     * Update a transfer-like cash payment (no person/invoice) without Payment::save validation.
     * Keeps TRANSFER pair amounts in sync when amount changes.
     */
    private function enrichTransferLikePayment($pay, $newAmount, $newDesc, $amountChanged, $entityId) {
        $paymentId = intval($pay['id']);
        $oldAmount = floatval($pay['amount']);
        $accountId = intval($pay['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['error' => 'حساب تراکنش انتقال نامعتبر است.'];
        }
        if (function_exists('assert_transaction_date_editable')) {
            assert_transaction_date_editable($this->conn, $entityId, $pay['date']);
        }

        $pair = null;
        $transferMarker = null;
        if (preg_match('/^(TRANSFER:\d+:\d+:\d+)/', (string)($pay['description'] ?? ''), $m)) {
            $transferMarker = $m[1];
        }
        $pairType = (($pay['transaction_type'] ?? '') === 'payment') ? 'receipt' : 'payment';
        if ($transferMarker) {
            $stmt = $this->conn->prepare("
                SELECT * FROM payments
                WHERE description LIKE CONCAT(?, '%')
                  AND transaction_type = ?
                  AND entity_id = ?
                  AND id != ?
                LIMIT 1
            ");
            $stmt->bind_param('ssii', $transferMarker, $pairType, $entityId, $paymentId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $pair = $rows[0] ?? null;
        }
        if (!$pair && $amountChanged) {
            $stmt = $this->conn->prepare("
                SELECT * FROM payments
                WHERE amount = ?
                  AND date = ?
                  AND transaction_type = ?
                  AND person_id IS NULL
                  AND invoiceId IS NULL
                  AND entity_id = ?
                  AND id != ?
                ORDER BY ABS(id - ?) ASC
                LIMIT 1
            ");
            $stmt->bind_param('dssiii', $pay['amount'], $pay['date'], $pairType, $entityId, $paymentId, $paymentId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $pair = $rows[0] ?? null;
        }

        $this->conn->begin_transaction();
        try {
            $upd = $this->conn->prepare("UPDATE payments SET amount = ?, description = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('dsii', $newAmount, $newDesc, $paymentId, $entityId);
            if (!$upd->execute()) {
                throw new Exception('خطا در به‌روزرسانی تراکنش انتقال.');
            }

            $accountModel = new Account($this->conn);
            if ($amountChanged) {
                $delta = $newAmount - $oldAmount;
                // receipt increases balance; payment decreases it
                $balanceDelta = (($pay['transaction_type'] ?? '') === 'receipt') ? $delta : -$delta;
                $accountModel->updateBalance($accountId, $balanceDelta, $entityId);

                if ($pair) {
                    $pairId = intval($pair['id']);
                    $pairAccountId = intval($pair['account_id'] ?? 0);
                    $pairUpd = $this->conn->prepare("UPDATE payments SET amount = ? WHERE id = ? AND entity_id = ?");
                    $pairUpd->bind_param('dii', $newAmount, $pairId, $entityId);
                    if (!$pairUpd->execute()) {
                        throw new Exception('خطا در به‌روزرسانی جفت انتقال.');
                    }
                    if ($pairAccountId > 0) {
                        $pairDelta = (($pair['transaction_type'] ?? '') === 'receipt') ? $delta : -$delta;
                        $accountModel->updateBalance($pairAccountId, $pairDelta, $entityId);
                    }
                }
            }

            $this->conn->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Enrich ledger from bank line: append bank details to description (payment/expense).
     * Never changes the original amount — bank fees are registered as a separate expense.
     */
    public function enrichLedgerFromBank($source, $refId, $bankItem, $entityId, $adjustAmount = false) {
        $source = (string)$source;
        $refId = intval($refId);
        $entityId = intval($entityId);
        $loaded = $this->loadLedgerForEnrich($source, $refId, $entityId);
        if (!empty($loaded['error'])) return $loaded;

        $oldAmount = floatval($loaded['amount']);
        $oldDesc = (string)($loaded['description'] ?? '');
        // مبلغ اصلی عمداً تغییر نمی‌کند (حتی اگر کلاینت قدیمی adjust بفرستد)
        $newAmount = $oldAmount;
        $amountChanged = false;
        $newDesc = in_array($source, ['payment', 'expense', 'income'], true)
            ? $this->composeEnrichedDescription($oldDesc, $bankItem, false)
            : $oldDesc;
        $descChanged = $newDesc !== $oldDesc;

        if (!$descChanged) {
            return [
                'success' => true,
                'unchanged' => true,
                'before' => ['amount' => $oldAmount, 'description' => $oldDesc],
                'after' => ['amount' => $newAmount, 'description' => $newDesc],
            ];
        }

        if ($source === 'payment') {
            $pay = $loaded['row'];
            $hasPerson = !empty($pay['person_id']);
            $hasInvoice = !empty($pay['invoiceId']);
            $isTransferLike = !$hasPerson && !$hasInvoice;

            try {
                if ($isTransferLike) {
                    $result = $this->enrichTransferLikePayment($pay, $newAmount, $newDesc, false, $entityId);
                    if (!empty($result['error'])) return $result;
                } else {
                    $payment = new Payment($this->conn);
                    $result = $payment->save([
                        'id' => intval($pay['id']),
                        'type' => $pay['type'],
                        'transaction_type' => $pay['transaction_type'],
                        'amount' => $newAmount,
                        'date' => $pay['date'],
                        'description' => $newDesc,
                        'account_id' => $pay['account_id'],
                        'person_id' => $pay['person_id'],
                        'person_type' => $pay['person_type'],
                        'invoiceId' => $pay['invoiceId'],
                        'invoiceType' => $pay['invoiceType'],
                        'checkId' => $pay['checkId'],
                    ]);
                    if (!empty($result['error'])) return $result;
                }
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
            return [
                'success' => true,
                'amount_changed' => false,
                'description_changed' => true,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'before' => ['amount' => $oldAmount, 'description' => $oldDesc],
                'after' => ['amount' => $newAmount, 'description' => $newDesc],
            ];
        }

        if ($source === 'expense') {
            $exp = $loaded['row'];
            try {
                $expense = new Expense($this->conn);
                $result = $expense->save([
                    'id' => intval($exp['id']),
                    'category' => $exp['category'],
                    'date' => $exp['date'],
                    'amount' => $newAmount,
                    'description' => $newDesc,
                    'account_id' => $exp['account_id'],
                ]);
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
            if (!empty($result['error'])) return $result;
            return [
                'success' => true,
                'amount_changed' => false,
                'description_changed' => true,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'before' => ['amount' => $oldAmount, 'description' => $oldDesc],
                'after' => ['amount' => $newAmount, 'description' => $newDesc],
            ];
        }

        if ($source === 'income') {
            $inc = $loaded['row'];
            try {
                require_once __DIR__ . '/Income.php';
                $income = new Income($this->conn);
                $result = $income->save([
                    'id' => intval($inc['id']),
                    'category' => $inc['category'],
                    'date' => $inc['date'],
                    'amount' => $newAmount,
                    'description' => $newDesc,
                    'account_id' => $inc['account_id'],
                    'person_id' => $inc['person_id'] ?? null,
                    'person_type' => $inc['person_type'] ?? null,
                ]);
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
            if (!empty($result['error'])) return $result;
            return [
                'success' => true,
                'amount_changed' => false,
                'description_changed' => true,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'before' => ['amount' => $oldAmount, 'description' => $oldDesc],
                'after' => ['amount' => $newAmount, 'description' => $newDesc],
            ];
        }

        // check — no description field; nothing to enrich
        return [
            'success' => true,
            'unchanged' => true,
            'before' => ['amount' => $oldAmount, 'description' => ''],
            'after' => ['amount' => $newAmount, 'description' => ''],
        ];
    }

    public function clearMatch($itemId, $importId, $toStatus = 'unmatched') {
        $stmt = $this->conn->prepare("
            UPDATE bank_import_items
            SET match_status = ?, matched_source = NULL, matched_ref_id = NULL, matched_payment_id = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('si', $toStatus, $itemId);
        $stmt->execute();
        $this->refreshMatchedCount($importId);
    }

    /**
     * Create book entry from unmatched bank line and match it.
     * create_type: expense | income | receipt | payment | transfer | split
     */
    public function createFromItem($item, $payload) {
        $entityId = intval($item['entity_id']);
        $accountId = intval($item['account_id']);
        $amount = abs(floatval($item['amount']));
        $date = $item['transaction_date'];
        $desc = trim($payload['description'] ?? ($item['description'] ?? ''));
        $createType = $payload['create_type'] ?? 'expense';

        if ($amount <= 0) {
            return ['error' => 'مبلغ نامعتبر است.', 'statusCode' => 400];
        }

        assert_transaction_date_editable($this->conn, $entityId, $date);

        if ($createType === 'transfer') {
            return $this->createTransferFromItem($item, $payload, $entityId, $accountId, $amount, $date, $desc);
        }

        if ($createType === 'split') {
            return $this->createSplitFromItem($item, $payload, $entityId, $accountId, $amount, $date, $desc);
        }

        if ($createType === 'expense') {
            if (floatval($item['amount']) >= 0) {
                return ['error' => 'برای واریز بانکی نمی‌توان هزینه ثبت کرد.', 'statusCode' => 400];
            }
            $personType = trim((string)($payload['person_type'] ?? ''));
            $personId = intval($payload['person_id'] ?? 0);
            if ($personType === '' || $personId <= 0) {
                $personType = null;
                $personId = null;
            }
            try {
                $expense = new Expense($this->conn);
                $result = $expense->save([
                    'category' => $payload['category'] ?? 'بانک / کارمزد',
                    'date' => $date,
                    'amount' => $amount,
                    'description' => $desc ?: 'ثبت از صورتحساب بانکی',
                    'account_id' => $accountId,
                    'person_id' => $personId,
                    'person_type' => $personType,
                ]);
            } catch (Exception $e) {
                return ['error' => $e->getMessage(), 'statusCode' => 400];
            }
            if (!empty($result['error'])) return $result;
            $expenseId = intval($result['id']);
            $this->enrichLedgerFromBank('expense', $expenseId, $item, $entityId, false);
            $this->applyMatch(intval($item['id']), 'expense', $expenseId, intval($item['import_header_id']));
            return ['success' => true, 'source' => 'expense', 'ref_id' => $expenseId];
        }

        if ($createType === 'income') {
            if (floatval($item['amount']) <= 0) {
                return ['error' => 'برای برداشت بانکی نمی‌توان درآمد ثبت کرد.', 'statusCode' => 400];
            }
            $personType = trim((string)($payload['person_type'] ?? ''));
            $personId = intval($payload['person_id'] ?? 0);
            if ($personType === '' || $personId <= 0) {
                $personType = null;
                $personId = null;
            }
            try {
                require_once __DIR__ . '/Income.php';
                $income = new Income($this->conn);
                $result = $income->save([
                    'category' => $payload['category'] ?? 'سایر درآمدها',
                    'date' => $date,
                    'amount' => $amount,
                    'description' => $desc ?: 'ثبت از صورتحساب بانکی',
                    'account_id' => $accountId,
                    'person_id' => $personId,
                    'person_type' => $personType,
                ]);
            } catch (Exception $e) {
                return ['error' => $e->getMessage(), 'statusCode' => 400];
            }
            if (!empty($result['error'])) return $result;
            $incomeId = intval($result['id']);
            $this->enrichLedgerFromBank('income', $incomeId, $item, $entityId, false);
            $this->applyMatch(intval($item['id']), 'income', $incomeId, intval($item['import_header_id']));
            return ['success' => true, 'source' => 'income', 'ref_id' => $incomeId];
        }

        if (!in_array($createType, ['receipt', 'payment'], true)) {
            return ['error' => 'نوع ثبت نامعتبر است.', 'statusCode' => 400];
        }

        $personId = isset($payload['person_id']) ? intval($payload['person_id']) : 0;
        $personType = $payload['person_type'] ?? null;
        $isOtherParty = ($personType === 'other');
        if ($isOtherParty) {
            $personId = 0;
            if (trim($desc) === '') {
                return ['error' => 'برای تراکنش متفرقه، نوشتن شرح الزامی است.', 'statusCode' => 400];
            }
        } elseif (!$personId || !$personType) {
            return ['error' => 'برای دریافت/پرداخت، انتخاب شخص الزامی است.', 'statusCode' => 400];
        }

        $expectedSign = ($createType === 'receipt') ? 1 : -1;
        if (($item['amount'] > 0 && $expectedSign < 0) || ($item['amount'] < 0 && $expectedSign > 0)) {
            return ['error' => 'نوع تراکنش با علامت مبلغ بانکی سازگار نیست.', 'statusCode' => 400];
        }

        try {
            $payment = new Payment($this->conn);
            $result = $payment->save([
                'type' => 'cash',
                'transaction_type' => $createType,
                'amount' => $amount,
                'date' => $date,
                'description' => $desc ?: 'ثبت از صورتحساب بانکی',
                'account_id' => $accountId,
                'person_id' => $isOtherParty ? null : $personId,
                'person_type' => $personType,
            ]);
        } catch (Exception $e) {
            return ['error' => $e->getMessage(), 'statusCode' => 400];
        }
        if (!empty($result['error'])) return $result;
        $newId = intval($result['id'] ?? 0);
        if (!$newId) {
            $newId = intval($this->conn->insert_id);
        }
        $this->enrichLedgerFromBank('payment', $newId, $item, $entityId, false);
        $this->applyMatch(intval($item['id']), 'payment', $newId, intval($item['import_header_id']));
        return ['success' => true, 'source' => 'payment', 'ref_id' => $newId];
    }

    private function createTransferFromItem($item, $payload, $entityId, $accountId, $amount, $date, $desc) {
        $otherAccountId = intval($payload['other_account_id'] ?? 0);
        if ($otherAccountId <= 0) {
            return ['error' => 'حساب طرف انتقال را انتخاب کنید.', 'statusCode' => 400];
        }
        if ($otherAccountId === $accountId) {
            return ['error' => 'حساب مبدا و مقصد نمی‌توانند یکسان باشند.', 'statusCode' => 400];
        }

        $bankAmount = floatval($item['amount']);
        if ($bankAmount < 0) {
            $fromId = $accountId;
            $toId = $otherAccountId;
        } else {
            $fromId = $otherAccountId;
            $toId = $accountId;
        }

        $transferDesc = $desc !== '' ? $desc : trim((string)($item['description'] ?? ''));
        if ($transferDesc === '') {
            $transferDesc = 'انتقال ثبت‌شده از صورتحساب بانکی';
        }

        $accountModel = new Account($this->conn);
        $result = $accountModel->transferMoney($fromId, $toId, $amount, $date, $transferDesc);
        if (!empty($result['error'])) {
            return $result;
        }

        $matchPaymentId = ($bankAmount < 0)
            ? intval($result['from_payment_id'] ?? 0)
            : intval($result['to_payment_id'] ?? 0);
        if ($matchPaymentId <= 0) {
            return ['error' => 'انتقال ثبت شد ولی شناسه تراکنش برای تطبیق یافت نشد.', 'statusCode' => 500];
        }

        $this->enrichLedgerFromBank('payment', $matchPaymentId, $item, $entityId, false);
        $this->applyMatch(intval($item['id']), 'payment', $matchPaymentId, intval($item['import_header_id']));

        return [
            'success' => true,
            'source' => 'payment',
            'ref_id' => $matchPaymentId,
            'transfer' => true,
            'from_payment_id' => intval($result['from_payment_id'] ?? 0),
            'to_payment_id' => intval($result['to_payment_id'] ?? 0),
            'message' => 'انتقال دوطرفه ثبت و ردیف بانک تطبیق شد.',
        ];
    }

    private function createSplitFromItem($item, $payload, $entityId, $accountId, $amount, $date, $desc) {
        $splits = $payload['splits'] ?? [];
        if (!is_array($splits) || count($splits) < 2) {
            return ['error' => 'حداقل ۲ سهم برای تقسیم لازم است.', 'statusCode' => 400];
        }

        $bankAmount = floatval($item['amount']);
        $txnType = $bankAmount >= 0 ? 'receipt' : 'payment';

        $normalized = [];
        $total = 0.0;
        foreach ($splits as $idx => $sp) {
            if (!is_array($sp)) continue;
            $spAmount = abs(floatval($sp['amount'] ?? 0));
            $personId = intval($sp['person_id'] ?? 0);
            $personType = $sp['person_type'] ?? '';
            if ($spAmount <= 0) {
                return ['error' => 'مبلغ هر سهم باید بزرگ‌تر از صفر باشد.', 'statusCode' => 400];
            }
            if (!$personId || !in_array($personType, ['customer', 'supplier', 'partner', 'contact'], true)) {
                return ['error' => 'برای هر سهم، نوع و انتخاب شخص الزامی است.', 'statusCode' => 400];
            }
            $normalized[] = [
                'person_id' => $personId,
                'person_type' => $personType,
                'amount' => $spAmount,
            ];
            $total += $spAmount;
        }

        if (count($normalized) < 2) {
            return ['error' => 'حداقل ۲ سهم معتبر برای تقسیم لازم است.', 'statusCode' => 400];
        }
        if (abs($total - $amount) > 0.01) {
            return [
                'error' => 'جمع سهم‌ها (' . number_format($total, 0, '', ',') . ') با مبلغ ردیف بانک (' . number_format($amount, 0, '', ',') . ') برابر نیست.',
                'statusCode' => 400,
            ];
        }

        $baseDesc = $desc !== '' ? $desc : 'ثبت تقسیم‌شده از صورتحساب بانکی';
        $createdIds = [];
        $payment = new Payment($this->conn);

        try {
            foreach ($normalized as $i => $sp) {
                $personName = $this->resolvePersonDisplayName($sp['person_type'], $sp['person_id'], $entityId);
                $splitDesc = $baseDesc;
                if ($personName !== '') {
                    $splitDesc .= ' — سهم ' . $personName;
                } elseif (count($normalized) > 1) {
                    $splitDesc .= ' — سهم ' . ($i + 1);
                }

                $result = $payment->save([
                    'type' => 'cash',
                    'transaction_type' => $txnType,
                    'amount' => $sp['amount'],
                    'date' => $date,
                    'description' => $splitDesc,
                    'account_id' => $accountId,
                    'person_id' => $sp['person_id'],
                    'person_type' => $sp['person_type'],
                ]);
                if (!empty($result['error'])) {
                    throw new Exception($result['error']);
                }
                $newId = intval($result['id'] ?? 0);
                if (!$newId) {
                    $newId = intval($this->conn->insert_id);
                }
                if ($newId <= 0) {
                    throw new Exception('خطا در ثبت سهم تقسیم‌شده.');
                }
                $this->enrichLedgerFromBank('payment', $newId, $item, $entityId, false);
                $createdIds[] = $newId;
            }

            $this->applyMatch(intval($item['id']), 'payment', $createdIds[0], intval($item['import_header_id']));
        } catch (Exception $e) {
            foreach ($createdIds as $pid) {
                $payment->delete($pid, true, true);
            }
            return ['error' => $e->getMessage(), 'statusCode' => 400];
        }

        return [
            'success' => true,
            'source' => 'payment',
            'ref_id' => $createdIds[0],
            'split' => true,
            'split_count' => count($createdIds),
            'ref_ids' => $createdIds,
            'message' => count($createdIds) . ' تراکنش تقسیم‌شده ثبت و ردیف بانک تطبیق شد.',
        ];
    }
}
