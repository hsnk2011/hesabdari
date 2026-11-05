<?php
// /api/models/PeriodClosing.php

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/ReportModel.php';
require_once __DIR__ . '/Partner.php';
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/ClosingSnapshotBuilder.php';

class PeriodClosing {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getStatus() {
        $entityId = $_SESSION['current_entity_id'];
        $lastClosed = get_entity_last_closed_date($this->conn, $entityId);

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM account_closings WHERE entity_id = ? AND status = 'completed'"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $countRow = db_stmt_to_assoc_array($stmt);

        return [
            'lastClosedDate' => $lastClosed,
            'suggestedStartDate' => $this->getSuggestedPeriodStart($entityId),
            'closingCount' => (int)($countRow[0]['cnt'] ?? 0),
            'isLocked' => !empty($lastClosed),
        ];
    }

    public function getList() {
        $entityId = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare(
            "SELECT id, title, period_start_date, period_end_date, close_type, calculation_method,
                    status, net_profit, gross_profit, profit_posted, notes, closed_at, closed_by,
                    reopened_at, reopened_by
             FROM account_closings
             WHERE entity_id = ?
             ORDER BY period_end_date DESC, id DESC"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getDetail($id) {
        $entityId = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare(
            "SELECT * FROM account_closings WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows)) {
            return ['error' => 'بستن حساب یافت نشد.', 'statusCode' => 404];
        }

        $closing = $rows[0];
        $closing['snapshot'] = json_decode($closing['snapshot_json'] ?? '{}', true) ?: [];
        $closing['partnerAllocation'] = json_decode($closing['partner_allocation_json'] ?? '[]', true) ?: [];
        $closing['newPartnersConfig'] = json_decode($closing['new_partners_config_json'] ?? '[]', true) ?: [];
        unset($closing['snapshot_json'], $closing['partner_allocation_json'], $closing['new_partners_config_json']);

        $histStmt = $this->conn->prepare(
            "SELECT * FROM partner_share_history WHERE closing_id = ? ORDER BY id ASC"
        );
        $histStmt->bind_param('i', $id);
        $histStmt->execute();
        $closing['shareHistory'] = db_stmt_to_assoc_array($histStmt);

        return $closing;
    }

    public function getShareHistory() {
        $entityId = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare(
            "SELECT h.*, p.name as current_partner_name
             FROM partner_share_history h
             LEFT JOIN partners p ON h.partner_id = p.id
             WHERE h.entity_id = ?
             ORDER BY h.effective_from DESC, h.id DESC"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function preview($data) {
        $entityId = $_SESSION['current_entity_id'];
        $validation = $this->validateCloseRequest($data, $entityId);
        if (isset($validation['error'])) {
            return $validation;
        }

        $periodStart = $validation['periodStart'];
        $periodEnd = $validation['periodEnd'];
        $calculationMethod = $this->normalizeCalculationMethod($data['calculationMethod'] ?? 'standard');
        if (is_array($calculationMethod)) {
            return $calculationMethod;
        }
        $closeType = $data['closeType'] ?? 'interim';
        // سهم شرکا فقط در بستن «تغییر ساختار شرکا» قابل تغییر است؛ در سایر انواع نادیده گرفته می‌شود.
        $newPartnersConfig = ($closeType === 'partner_restructure') ? ($data['newPartnersConfig'] ?? null) : null;

        $reportModel = new ReportModel($this->conn);
        $plData = $reportModel->getProfitLossReport([
            'startDate' => $periodStart,
            'endDate' => $periodEnd,
            'entityId' => $entityId,
            'calculationMethod' => $calculationMethod,
        ]);

        $currentPartners = $this->getPartnersAtDate($entityId, $periodEnd);
        $netProfit = (float)($plData['netOperatingProfit'] ?? 0);
        $grossProfit = (float)($plData['grossProfit'] ?? 0);

        $periodAllocation = $this->buildPartnerAllocation($currentPartners, $grossProfit, $netProfit);
        $resolved = $this->resolveNextPartnersConfig($currentPartners, $newPartnersConfig);
        $nextPartners = $resolved['partners'];
        $warnings = $this->buildPreviewWarnings($entityId, $periodEnd, $nextPartners, $resolved['dropped'] ?? []);

        $wantPostProfit = !empty($data['postProfitToPartners']);
        if ($wantPostProfit && $netProfit != 0) {
            $warnings = array_merge(
                $warnings,
                $this->buildProfitPostingWarnings($entityId, $periodAllocation)
            );
        }

        $snapshotBuilder = new ClosingSnapshotBuilder($this->conn);
        $closingSnapshot = $snapshotBuilder->build(
            $entityId,
            $periodStart,
            $periodEnd,
            $calculationMethod,
            ['currentPartners' => $currentPartners]
        );
        $warnings = array_merge($warnings, $snapshotBuilder->buildValidationWarnings($closingSnapshot));

        return [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'closeType' => $closeType,
            'calculationMethod' => $calculationMethod,
            'profitLoss' => $plData,
            'closingSnapshot' => $closingSnapshot,
            'currentPartners' => $currentPartners,
            'periodPartnerAllocation' => $periodAllocation,
            'nextPartnersConfig' => $nextPartners,
            'capitalSummary' => $closingSnapshot['capital'] ?? ($plData['capitalSummary'] ?? []),
            'cashPosition' => $plData['cashPosition'] ?? [],
            'customerReceivables' => $closingSnapshot['customers'] ?? [],
            'supplierDebts' => $closingSnapshot['suppliers'] ?? [],
            'warnings' => $warnings,
            'canExecute' => empty(array_filter($warnings, fn($w) => ($w['level'] ?? '') === 'error')),
        ];
    }

    public function execute($data) {
        $entityId = $_SESSION['current_entity_id'];

        $backupCheck = $this->validateClosingBackup($data['backupFilename'] ?? '');
        if (isset($backupCheck['error'])) {
            return $backupCheck;
        }

        $allowedCloseTypes = ['annual', 'interim', 'partner_restructure'];
        $closeType = $data['closeType'] ?? 'interim';
        if (!in_array($closeType, $allowedCloseTypes, true)) {
            return ['error' => 'نوع بستن حساب نامعتبر است.', 'statusCode' => 400];
        }

        $calculationMethod = $this->normalizeCalculationMethod($data['calculationMethod'] ?? 'standard');
        if (is_array($calculationMethod)) {
            return $calculationMethod;
        }
        $data['calculationMethod'] = $calculationMethod;

        // Open the transaction BEFORE previewing: under REPEATABLE READ every
        // read preview makes shares one consistent snapshot with the locked
        // re-check and writes below, so the stored snapshot can't diverge
        // from the data the close actually validated.
        $this->conn->begin_transaction();

        // preview() already runs validateCloseRequest — avoid duplicate work here.
        $preview = $this->preview($data);
        if (isset($preview['error'])) {
            $this->conn->rollback();
            return $preview;
        }
        if (empty($preview['canExecute'])) {
            $this->conn->rollback();
            return ['error' => 'امکان بستن حساب به دلیل خطاهای پیش‌نمایش وجود ندارد.', 'statusCode' => 400];
        }

        $periodStart = $preview['periodStart'];
        $periodEnd = $preview['periodEnd'];
        $title = trim($data['title'] ?? '') ?: $this->defaultTitle($closeType, $periodEnd);
        $notes = trim($data['notes'] ?? '');
        $postProfit = !empty($data['postProfitToPartners']);
        $nextPartners = $preview['nextPartnersConfig'];
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $snapshot = $preview['closingSnapshot'] ?? [
            'profitLoss' => $preview['profitLoss'],
            'capitalSummary' => $preview['capitalSummary'],
            'customerReceivables' => $preview['customerReceivables'],
            'supplierDebts' => $preview['supplierDebts'],
        ];
        $snapshot['closedAt'] = date('Y-m-d H:i:s');
        $snapshot['backupFilename'] = $backupCheck['filename'] ?? ($data['backupFilename'] ?? null);
        $snapshot['periodPartnerAllocation'] = $preview['periodPartnerAllocation'] ?? [];

        try {
            // Lock existing closings then re-check overlap (prevents concurrent overlapping closes).
            $lockStmt = $this->conn->prepare(
                "SELECT id FROM account_closings WHERE entity_id = ? AND status = 'completed' FOR UPDATE"
            );
            $lockStmt->bind_param('i', $entityId);
            $lockStmt->execute();
            db_stmt_to_assoc_array($lockStmt);

            $overlapStmt = $this->conn->prepare(
                "SELECT id FROM account_closings
                 WHERE entity_id = ? AND status = 'completed'
                   AND period_start_date <= ? AND period_end_date >= ? LIMIT 1"
            );
            $overlapStmt->bind_param('iss', $entityId, $periodEnd, $periodStart);
            $overlapStmt->execute();
            $overlap = db_stmt_to_assoc_array($overlapStmt);
            if (!empty($overlap)) {
                throw new Exception('این بازه با یک بستن حساب قبلی همپوشانی دارد.', 409);
            }

            $netProfit = (float)($preview['profitLoss']['netOperatingProfit'] ?? 0);
            $grossProfit = (float)($preview['profitLoss']['grossProfit'] ?? 0);
            // Start as 0; set to 1 only after at least one CLOSING payment is inserted.
            $profitPostedInt = 0;
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            $allocationJson = json_encode($preview['periodPartnerAllocation'], JSON_UNESCAPED_UNICODE);
            $newPartnersJson = json_encode($nextPartners, JSON_UNESCAPED_UNICODE);

            if ($userId === null) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO account_closings
                    (entity_id, title, period_start_date, period_end_date, close_type, calculation_method,
                     status, net_profit, gross_profit, snapshot_json, partner_allocation_json,
                     new_partners_config_json, profit_posted, notes, closed_by)
                     VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, NULL)"
                );
                $stmt->bind_param(
                    'isssssddsssis',
                    $entityId,
                    $title,
                    $periodStart,
                    $periodEnd,
                    $closeType,
                    $calculationMethod,
                    $netProfit,
                    $grossProfit,
                    $snapshotJson,
                    $allocationJson,
                    $newPartnersJson,
                    $profitPostedInt,
                    $notes
                );
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO account_closings
                    (entity_id, title, period_start_date, period_end_date, close_type, calculation_method,
                     status, net_profit, gross_profit, snapshot_json, partner_allocation_json,
                     new_partners_config_json, profit_posted, notes, closed_by)
                     VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    'isssssddsssisi',
                    $entityId,
                    $title,
                    $periodStart,
                    $periodEnd,
                    $closeType,
                    $calculationMethod,
                    $netProfit,
                    $grossProfit,
                    $snapshotJson,
                    $allocationJson,
                    $newPartnersJson,
                    $profitPostedInt,
                    $notes,
                    $userId
                );
            }
            if (!$stmt->execute()) {
                throw new Exception('خطا در ثبت بستن حساب: ' . $stmt->error);
            }
            $closingId = $this->conn->insert_id;
            $stmt->close();

            $this->closePreviousSharePeriods($entityId, $periodEnd, $closingId);
            $this->applyPartnerChanges($entityId, $periodEnd, $closingId, $preview['currentPartners'], $nextPartners);

            if ($postProfit && $netProfit != 0) {
                $postedCount = $this->postProfitToPartnerAccounts(
                    $entityId,
                    $periodEnd,
                    $preview['periodPartnerAllocation'],
                    $closingId
                );
                if ($postedCount > 0) {
                    $flagStmt = $this->conn->prepare(
                        "UPDATE account_closings SET profit_posted = 1 WHERE id = ? AND entity_id = ?"
                    );
                    $flagStmt->bind_param('ii', $closingId, $entityId);
                    $flagStmt->execute();
                    $flagStmt->close();
                }
            }

            $this->conn->commit();

            return [
                'success' => true,
                'id' => $closingId,
                'message' => 'حساب با موفقیت بسته شد.',
                'periodEnd' => $periodEnd,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            return ['error' => $e->getMessage(), 'statusCode' => ($code >= 400 && $code < 600) ? $code : 500];
        }
    }

    public function reopen($data) {
        $entityId = $_SESSION['current_entity_id'];
        $id = (int)($data['id'] ?? 0);
        if (!$id) {
            return ['error' => 'شناسه بستن حساب الزامی است.', 'statusCode' => 400];
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM account_closings WHERE id = ? AND entity_id = ? AND status = 'completed'"
        );
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows)) {
            return ['error' => 'بستن حساب فعال یافت نشد.', 'statusCode' => 404];
        }
        $closing = $rows[0];

        $latestStmt = $this->conn->prepare(
            "SELECT id FROM account_closings WHERE entity_id = ? AND status = 'completed' ORDER BY period_end_date DESC, id DESC LIMIT 1"
        );
        $latestStmt->bind_param('i', $entityId);
        $latestStmt->execute();
        $latest = db_stmt_to_assoc_array($latestStmt);
        if (empty($latest) || (int)$latest[0]['id'] !== $id) {
            return ['error' => 'فقط آخرین بستن حساب قابل بازگشایی است.', 'statusCode' => 409];
        }

        $this->conn->begin_transaction();
        try {
            if (!empty($closing['profit_posted'])) {
                $this->reverseProfitPostings($entityId, $id);
            }

            $this->revertPartnerShareChanges($entityId, $id, $closing['period_end_date']);

            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            if ($userId === null) {
                $upd = $this->conn->prepare(
                    "UPDATE account_closings SET status = 'reopened', reopened_at = NOW(), reopened_by = NULL WHERE id = ?"
                );
                $upd->bind_param('i', $id);
            } else {
                $upd = $this->conn->prepare(
                    "UPDATE account_closings SET status = 'reopened', reopened_at = NOW(), reopened_by = ? WHERE id = ?"
                );
                $upd->bind_param('ii', $userId, $id);
            }
            $upd->execute();
            $upd->close();

            $this->conn->commit();
            return ['success' => true, 'message' => 'بستن حساب بازگشایی شد و دوره قابل ویرایش است.'];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function getPartnersAtDate($entityId, $asOfDate) {
        $histCheck = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM partner_share_history WHERE entity_id = ?"
        );
        $histCheck->bind_param('i', $entityId);
        $histCheck->execute();
        $histCount = db_stmt_to_assoc_array($histCheck);

        if (empty($histCount[0]['cnt'])) {
            $stmt = $this->conn->prepare("SELECT id, name, share FROM partners WHERE entity_id = ? ORDER BY id");
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            return db_stmt_to_assoc_array($stmt);
        }

        $this->repairInvalidShareHistoryDates();

        // MAX(id) avoids ambiguity when multiple rows share the same effective_from.
        $stmt = $this->conn->prepare(
            "SELECT h.partner_id AS id, h.partner_name AS name, h.share
             FROM partner_share_history h
             INNER JOIN (
                 SELECT partner_id, MAX(id) AS max_id
                 FROM partner_share_history
                 WHERE entity_id = ?
                   AND effective_from <= ?
                   AND (effective_to IS NULL OR effective_to >= ?)
                   AND action <> 'removed'
                   AND share > 0
                 GROUP BY partner_id
             ) latest ON h.id = latest.max_id
             WHERE h.entity_id = ?
             ORDER BY h.partner_id"
        );
        $stmt->bind_param('issi', $entityId, $asOfDate, $asOfDate, $entityId);
        $stmt->execute();
        $fromHistory = db_stmt_to_assoc_array($stmt);

        if (!empty($fromHistory)) {
            return $fromHistory;
        }

        // History exists but no row covers this date — do not fall back to live partners
        // (that would silently mis-allocate profit for out-of-range dates).
        return [];
    }

    /**
     * تاریخ‌های نامعتبر effective_to (مثلاً 0000-00-00) را به NULL تبدیل می‌کند.
     * MySQL strict mode اجازه مقایسه با '0000-00-00' را نمی‌دهد؛ موقتاً sql_mode شل می‌شود.
     */
    private function repairInvalidShareHistoryDates() {
        static $repaired = false;
        if ($repaired) {
            return;
        }
        $repaired = true;

        $tableCheck = $this->conn->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'partner_share_history' LIMIT 1"
        );
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return;
        }

        $this->runWithRelaxedDateSqlMode(function () {
            $this->conn->query(
                "UPDATE partner_share_history SET effective_to = NULL
                 WHERE effective_to IS NOT NULL
                   AND (effective_to = '0000-00-00' OR effective_to < '1980-01-01')"
            );
        });
    }

    private function runWithRelaxedDateSqlMode(callable $callback) {
        $this->conn->query('SET @hesabdari_old_sql_mode = @@SESSION.sql_mode');
        $this->conn->query(
            "SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(
                @@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', ''))"
        );
        $this->conn->query("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ALLOW_INVALID_DATES')");
        try {
            $callback();
        } finally {
            $this->conn->query('SET SESSION sql_mode = @hesabdari_old_sql_mode');
        }
    }

    private function getSuggestedPeriodStart($entityId) {
        $lastClosed = get_entity_last_closed_date($this->conn, $entityId);
        if ($lastClosed) {
            return date('Y-m-d', strtotime($lastClosed . ' +1 day'));
        }

        $dates = [];
        foreach (['sales_invoices', 'purchase_invoices', 'payments', 'expenses'] as $table) {
            $stmt = $this->conn->prepare("SELECT MIN(date) as min_date FROM `{$table}` WHERE entity_id = ?");
            $stmt->bind_param('i', $entityId);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt);
            if (!empty($row[0]['min_date'])) {
                $dates[] = $row[0]['min_date'];
            }
        }
        if (empty($dates)) {
            return date('Y-01-01');
        }
        sort($dates);
        return $dates[0];
    }

    private function validateClosingBackup($filename) {
        $filename = basename((string)$filename);
        if (!preg_match('/^full_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/', $filename)) {
            return ['error' => 'پشتیبان کامل معتبر یافت نشد. قبل از بستن حساب، دکمه «ایجاد پشتیبان کامل» را بزنید.', 'statusCode' => 400];
        }
        $path = realpath(__DIR__ . '/../../backups/' . $filename);
        $backupDir = realpath(__DIR__ . '/../../backups');
        if (!$path || !$backupDir || strpos($path, $backupDir) !== 0 || !is_file($path)) {
            return ['error' => 'فایل پشتیبان یافت نشد. لطفاً دوباره پشتیبان کامل ایجاد کنید.', 'statusCode' => 400];
        }
        return ['filename' => $filename];
    }

    private function validateCloseRequest($data, $entityId) {
        $periodEnd = $data['periodEndDate'] ?? $data['periodEnd'] ?? null;
        $periodStart = $data['periodStartDate'] ?? $data['periodStart'] ?? null;

        if (empty($periodEnd)) {
            return ['error' => 'تاریخ پایان دوره الزامی است.', 'statusCode' => 400];
        }

        if (!validate_date_format($periodEnd)) {
            return ['error' => 'فرمت تاریخ پایان نامعتبر است (YYYY-MM-DD).', 'statusCode' => 400];
        }

        if (empty($periodStart)) {
            $periodStart = $this->getSuggestedPeriodStart($entityId);
        }

        if (!validate_date_format($periodStart)) {
            return ['error' => 'فرمت تاریخ شروع نامعتبر است (YYYY-MM-DD).', 'statusCode' => 400];
        }

        if ($periodStart > $periodEnd) {
            return ['error' => 'تاریخ شروع دوره نمی‌تواند بعد از تاریخ پایان باشد.', 'statusCode' => 400];
        }

        $today = date('Y-m-d');
        if ($periodEnd > $today) {
            return ['error' => 'تاریخ پایان دوره نمی‌تواند در آینده باشد.', 'statusCode' => 400];
        }

        $lastClosed = get_entity_last_closed_date($this->conn, $entityId);
        if ($lastClosed && $periodEnd <= $lastClosed) {
            return ['error' => "تاریخ پایان باید بعد از آخرین بستن حساب ({$lastClosed}) باشد.", 'statusCode' => 400];
        }
        if ($lastClosed) {
            $expectedStart = date('Y-m-d', strtotime($lastClosed . ' +1 day'));
            if ($periodStart !== $expectedStart) {
                return [
                    'error' => "تاریخ شروع باید دقیقاً روز بعد از آخرین بستن ({$expectedStart}) باشد تا شکاف دوره‌ای ایجاد نشود.",
                    'statusCode' => 400,
                ];
            }
        }

        $overlapStmt = $this->conn->prepare(
            "SELECT id FROM account_closings
             WHERE entity_id = ? AND status = 'completed'
               AND period_start_date <= ? AND period_end_date >= ? LIMIT 1"
        );
        $overlapStmt->bind_param('iss', $entityId, $periodEnd, $periodStart);
        $overlapStmt->execute();
        $overlap = db_stmt_to_assoc_array($overlapStmt);
        if (!empty($overlap)) {
            return ['error' => 'این بازه با یک بستن حساب قبلی همپوشانی دارد.', 'statusCode' => 409];
        }

        return ['periodStart' => $periodStart, 'periodEnd' => $periodEnd];
    }

    private function normalizeCalculationMethod($method) {
        $allowed = ['standard', 'cogs', 'inventory'];
        $method = is_string($method) ? trim($method) : '';
        if ($method === '') {
            return 'standard';
        }
        if (!in_array($method, $allowed, true)) {
            return ['error' => 'روش محاسبه سود نامعتبر است.', 'statusCode' => 400];
        }
        return $method;
    }

    private function buildProfitPostingWarnings($entityId, array $allocations) {
        $warnings = [];
        $missing = [];
        $accountStmt = $this->conn->prepare(
            "SELECT id FROM accounts WHERE entity_id = ? AND partner_id = ? LIMIT 1"
        );
        foreach ($allocations as $alloc) {
            $amount = (float)($alloc['netProfitShare'] ?? 0);
            if ($amount == 0 || empty($alloc['id'])) {
                continue;
            }
            $partnerId = (int)$alloc['id'];
            $accountStmt->bind_param('ii', $entityId, $partnerId);
            $accountStmt->execute();
            $accRows = db_stmt_to_assoc_array($accountStmt, false);
            if (empty($accRows)) {
                $missing[] = $alloc['name'] ?? ("شناسه {$partnerId}");
            }
        }
        $accountStmt->close();
        if (!empty($missing)) {
            $warnings[] = [
                'level' => 'error',
                'message' => 'برای ثبت سود، این شرکا حساب شخصی ندارند: ' . implode('، ', $missing),
            ];
        }
        return $warnings;
    }

    private function buildPartnerAllocation($partners, $grossProfit, $netProfit) {
        $totalShare = 0;
        foreach ($partners as $p) {
            $totalShare += floatval($p['share']);
        }

        $grossTarget = (int)round((float)$grossProfit);
        $netTarget = (int)round((float)$netProfit);
        $results = [];
        $allocatedGross = 0;
        $allocatedNet = 0;
        $count = count($partners);
        $i = 0;
        foreach ($partners as $p) {
            $sharePercent = $totalShare > 0 ? floatval($p['share']) / $totalShare : 0;
            $isLast = ($i === $count - 1);
            if ($isLast && $count > 0) {
                $grossShare = $grossTarget - $allocatedGross;
                $netShare = $netTarget - $allocatedNet;
            } else {
                $grossShare = (int)round($grossTarget * $sharePercent);
                $netShare = (int)round($netTarget * $sharePercent);
                $allocatedGross += $grossShare;
                $allocatedNet += $netShare;
            }
            $results[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'share' => (float)$p['share'],
                'sharePercent' => round($sharePercent * 100, 2),
                'grossProfitShare' => $grossShare,
                'netProfitShare' => $netShare,
            ];
            $i++;
        }
        return $results;
    }

    private function resolveNextPartnersConfig($currentPartners, $newPartnersConfig) {
        if (empty($newPartnersConfig) || !is_array($newPartnersConfig)) {
            return [
                'partners' => array_map(function ($p) {
                    return [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'share' => (float)$p['share'],
                        'action' => 'unchanged',
                    ];
                }, $currentPartners),
                'dropped' => [],
            ];
        }

        $resolved = [];
        $droppedInvalid = [];
        foreach ($newPartnersConfig as $item) {
            if (!is_array($item)) {
                continue;
            }
            $share = isset($item['share']) ? (float)$item['share'] : 0;
            $name = trim($item['name'] ?? '');
            $id = isset($item['id']) && $item['id'] !== '' ? (int)$item['id'] : null;
            $action = $item['action'] ?? ($id ? 'modified' : 'added');

            if ($action === 'removed') {
                $resolved[] = [
                    'id' => $id,
                    'name' => $name,
                    'share' => 0,
                    'action' => 'removed',
                ];
                continue;
            }

            if ($share < 0 || $share > 1) {
                $droppedInvalid[] = $name ?: ($id ? "شناسه {$id}" : 'بدون نام');
                continue;
            }
            if ($share == 0 && $action !== 'removed') {
                $droppedInvalid[] = $name ?: ($id ? "شناسه {$id}" : 'بدون نام');
                continue;
            }
            if (empty($name) && !$id) {
                $droppedInvalid[] = 'شریک جدید بدون نام';
                continue;
            }

            $resolved[] = [
                'id' => $id,
                'name' => $name,
                'share' => $share,
                'action' => $action,
            ];
        }
        return ['partners' => $resolved, 'dropped' => $droppedInvalid];
    }

    private function buildPreviewWarnings($entityId, $periodEnd, $nextPartners, array $dropped = []) {
        $warnings = [];

        if (!empty($dropped)) {
            $warnings[] = [
                'level' => 'error',
                'message' => 'برخی سهم‌های شرکا نامعتبر بودند و اعمال نشدند: ' . implode('، ', $dropped),
            ];
        }

        $totalShare = 0;
        $activeCount = 0;
        foreach ($nextPartners as $p) {
            if (($p['action'] ?? '') === 'removed' || (float)($p['share'] ?? 0) <= 0) {
                continue;
            }
            $totalShare += (float)$p['share'];
            $activeCount++;
        }

        if ($activeCount === 0) {
            $warnings[] = ['level' => 'error', 'message' => 'حداقل یک شریک فعال برای دوره بعد لازم است.'];
        } elseif (abs($totalShare - 1) > 0.0001) {
            $warnings[] = [
                'level' => 'error',
                'message' => 'جمع سهم شرکای دوره بعد باید ۱۰۰٪ باشد (الان ' . round($totalShare * 100, 2) . '٪).',
            ];
        }

        foreach ($nextPartners as $p) {
            if (($p['action'] ?? '') === 'added' && empty($p['id']) && empty(trim($p['name'] ?? ''))) {
                $warnings[] = ['level' => 'error', 'message' => 'نام شریک جدید الزامی است.'];
            }
        }

        return $warnings;
    }

    private function defaultTitle($closeType, $periodEnd) {
        $labels = [
            'annual' => 'بستن سالانه',
            'interim' => 'بستن میان‌دوره‌ای',
            'partner_restructure' => 'بستن و تغییر ساختار شرکا',
        ];
        return ($labels[$closeType] ?? 'بستن حساب') . ' تا ' . $periodEnd;
    }

    private function closePreviousSharePeriods($entityId, $periodEnd, $closingId) {
        $stmt = $this->conn->prepare(
            "UPDATE partner_share_history
             SET effective_to = ?
             WHERE entity_id = ? AND effective_to IS NULL AND effective_from <= ?"
        );
        $stmt->bind_param('sis', $periodEnd, $entityId, $periodEnd);
        $stmt->execute();
        $stmt->close();
    }

    private function applyPartnerChanges($entityId, $periodEnd, $closingId, $currentPartners, $nextPartners) {
        $periodStart = null;
        $closingRow = $this->conn->prepare("SELECT period_start_date FROM account_closings WHERE id = ?");
        $closingRow->bind_param('i', $closingId);
        $closingRow->execute();
        $cRow = db_stmt_to_assoc_array($closingRow);
        $periodStart = $cRow[0]['period_start_date'] ?? $periodEnd;

        $nextDay = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
        $partnerModel = new Partner($this->conn);
        $currentById = [];
        foreach ($currentPartners as $p) {
            $currentById[$p['id']] = $p;
            $this->insertShareHistory(
                $entityId,
                $p['id'],
                $p['name'],
                (float)$p['share'],
                $periodStart,
                $closingId,
                'unchanged',
                $periodEnd
            );
        }

        foreach ($nextPartners as $config) {
            $action = $config['action'] ?? 'unchanged';
            $share = (float)($config['share'] ?? 0);
            $name = trim($config['name'] ?? '');
            $partnerId = $config['id'] ?? null;

            if ($action === 'removed') {
                if ($partnerId) {
                    $upd = $this->conn->prepare("UPDATE partners SET share = 0 WHERE id = ? AND entity_id = ?");
                    $upd->bind_param('ii', $partnerId, $entityId);
                    $upd->execute();
                    $this->insertShareHistory($entityId, $partnerId, $name ?: ($currentById[$partnerId]['name'] ?? ''), 0, $nextDay, $closingId, 'removed');
                }
                continue;
            }

            if ($action === 'added' || (!$partnerId && $share > 0)) {
                // manageTransaction=false: stay inside execute()'s outer transaction
                $result = $partnerModel->save(['name' => $name, 'share' => $share], false);
                if (empty($result['success'])) {
                    throw new Exception($result['error'] ?? 'خطا در ایجاد شریک جدید هنگام بستن حساب.');
                }
                $partnerId = $result['id'];
                $this->insertShareHistory($entityId, $partnerId, $name, $share, $nextDay, $closingId, 'added');
                continue;
            }

            if ($partnerId) {
                $oldShare = (float)($currentById[$partnerId]['share'] ?? 0);
                $oldName = $currentById[$partnerId]['name'] ?? $name;
                $safeName = $name !== '' ? $name : $oldName;
                $histAction = abs($oldShare - $share) < 0.0001 ? 'unchanged' : 'modified';

                if ($safeName !== $oldName) {
                    $histAction = 'modified';
                }

                $upd = $this->conn->prepare("UPDATE partners SET name = ?, share = ? WHERE id = ? AND entity_id = ?");
                $upd->bind_param('sdii', $safeName, $share, $partnerId, $entityId);
                $upd->execute();

                $accName = "حساب شخصی: " . $safeName;
                $accUpd = $this->conn->prepare(
                    "UPDATE accounts SET name = ? WHERE partner_id = ? AND entity_id = ?"
                );
                $accUpd->bind_param('sii', $accName, $partnerId, $entityId);
                $accUpd->execute();

                $this->insertShareHistory($entityId, $partnerId, $safeName, $share, $nextDay, $closingId, $histAction);
            }
        }
    }

    private function insertShareHistory($entityId, $partnerId, $name, $share, $effectiveFrom, $closingId, $action, $effectiveTo = null) {
        if ($effectiveTo === null) {
            $stmt = $this->conn->prepare(
                "INSERT INTO partner_share_history
                (entity_id, partner_id, partner_name, share, effective_from, effective_to, closing_id, action)
                VALUES (?, ?, ?, ?, ?, NULL, ?, ?)"
            );
            $stmt->bind_param('iisdsis', $entityId, $partnerId, $name, $share, $effectiveFrom, $closingId, $action);
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO partner_share_history
                (entity_id, partner_id, partner_name, share, effective_from, effective_to, closing_id, action)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iisdssis', $entityId, $partnerId, $name, $share, $effectiveFrom, $effectiveTo, $closingId, $action);
        }
        $stmt->execute();
        $stmt->close();
    }

    private function postProfitToPartnerAccounts($entityId, $periodEnd, $allocations, $closingId) {
        // Post on the first day of the next period so the entry is outside the locked range
        // and capital reports can exclude CLOSING:* descriptions separately.
        $postDate = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
        $accountStmt = $this->conn->prepare(
            "SELECT id FROM accounts WHERE entity_id = ? AND partner_id = ? LIMIT 1"
        );
        $insertStmt = $this->conn->prepare(
            "INSERT INTO payments (entity_id, type, transaction_type, person_type, person_id, account_id, amount, date, description)
             VALUES (?, 'cash', ?, 'partner', ?, ?, ?, ?, ?)"
        );
        $accountModel = new Account($this->conn);
        $postedCount = 0;

        foreach ($allocations as $alloc) {
            $amount = (float)($alloc['netProfitShare'] ?? 0);
            if ($amount == 0 || empty($alloc['id'])) {
                continue;
            }

            $partnerId = (int)$alloc['id'];
            $accountStmt->bind_param('ii', $entityId, $partnerId);
            $accountStmt->execute();
            $accRows = db_stmt_to_assoc_array($accountStmt, false);
            if (empty($accRows)) {
                throw new Exception(
                    'حساب شخصی برای شریک «' . ($alloc['name'] ?? $partnerId) . '» یافت نشد؛ ثبت سود متوقف شد.',
                    400
                );
            }
            $accountId = (int)$accRows[0]['id'];

            // Capital claim for صورتحساب: receipt = profit (+), payment = loss (−).
            // Wallet current_balance is NOT changed — closing is not a bank cash move.
            $transactionType = $amount > 0 ? 'receipt' : 'payment';
            $absAmount = abs($amount);
            $description = "CLOSING:{$closingId}|سهم سود بستن حساب تا {$periodEnd}";

            $insertStmt->bind_param(
                'isiidss',
                $entityId,
                $transactionType,
                $partnerId,
                $accountId,
                $absAmount,
                $postDate,
                $description
            );
            if (!$insertStmt->execute()) {
                throw new Exception('خطا در ثبت سهم سود شریک: ' . $insertStmt->error);
            }

            $postedCount++;
        }
        if ($accountStmt) {
            $accountStmt->close();
        }
        if ($insertStmt) {
            $insertStmt->close();
        }
        return $postedCount;
    }

    private function reverseProfitPostings($entityId, $closingId) {
        $pattern = "CLOSING:{$closingId}|%";
        $stmt = $this->conn->prepare(
            "SELECT * FROM payments WHERE entity_id = ? AND description LIKE ?"
        );
        $stmt->bind_param('is', $entityId, $pattern);
        $stmt->execute();
        $payments = db_stmt_to_assoc_array($stmt);

        $accountModel = new Account($this->conn);
        $delStmt = $this->conn->prepare("DELETE FROM payments WHERE id = ? AND entity_id = ?");

        foreach ($payments as $payment) {
            // Wallet was never changed by CLOSING posts; only delete the payment rows.
            $paymentId = (int)$payment['id'];
            $delStmt->bind_param('ii', $paymentId, $entityId);
            $delStmt->execute();
        }
    }

    private function revertPartnerShareChanges($entityId, $closingId, $periodEnd) {
        // Capture partners added by this closing before deleting their history rows.
        $addedStmt = $this->conn->prepare(
            "SELECT partner_id, partner_name FROM partner_share_history
             WHERE closing_id = ? AND action = 'added'"
        );
        $addedStmt->bind_param('i', $closingId);
        $addedStmt->execute();
        $addedPartners = db_stmt_to_assoc_array($addedStmt);

        // Period snapshot rows written at close carry the pre-close name AND share.
        // Critical for the FIRST closing (no prior open history to reopen).
        $priorStmt = $this->conn->prepare(
            "SELECT partner_id, partner_name, share, action FROM partner_share_history
             WHERE closing_id = ?
               AND effective_to IS NOT NULL
               AND action IN ('unchanged', 'modified')"
        );
        $priorStmt->bind_param('i', $closingId);
        $priorStmt->execute();
        $priorNames = [];
        $priorShares = [];
        foreach (db_stmt_to_assoc_array($priorStmt) as $row) {
            $pid = (int)($row['partner_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            // Prefer the original period snapshot ('unchanged') over any other closed row.
            if (!isset($priorShares[$pid]) || ($row['action'] ?? '') === 'unchanged') {
                $priorNames[$pid] = $row['partner_name'];
                $priorShares[$pid] = (float)$row['share'];
            }
        }

        // Also restore partners that were zeroed via action=removed on next-day rows
        // (their pre-close share lives only in the period snapshot above).

        $del = $this->conn->prepare("DELETE FROM partner_share_history WHERE closing_id = ?");
        $del->bind_param('i', $closingId);
        $del->execute();

        $reopen = $this->conn->prepare(
            "UPDATE partner_share_history SET effective_to = NULL
             WHERE entity_id = ? AND effective_to = ?"
        );
        $reopen->bind_param('is', $entityId, $periodEnd);
        $reopen->execute();

        $stmt = $this->conn->prepare(
            "SELECT partner_id, partner_name, share FROM partner_share_history
             WHERE entity_id = ? AND effective_to IS NULL
             ORDER BY effective_from DESC"
        );
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $activeShares = db_stmt_to_assoc_array($stmt);

        $seen = [];
        foreach ($activeShares as $row) {
            $pid = (int)$row['partner_id'];
            if (!$pid || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $restoreName = $priorNames[$pid] ?? $row['partner_name'];
            $restoreShare = $priorShares[$pid] ?? (float)$row['share'];
            $upd = $this->conn->prepare(
                "UPDATE partners SET share = ?, name = ? WHERE id = ? AND entity_id = ?"
            );
            $upd->bind_param('dsii', $restoreShare, $restoreName, $pid, $entityId);
            $upd->execute();

            $accName = "حساب شخصی: " . $restoreName;
            $accUpd = $this->conn->prepare(
                "UPDATE accounts SET name = ? WHERE partner_id = ? AND entity_id = ?"
            );
            $accUpd->bind_param('sii', $accName, $pid, $entityId);
            $accUpd->execute();
        }

        // First closing / removed partners: no open history row remains — restore from snapshot.
        foreach ($priorShares as $pid => $share) {
            if (isset($seen[$pid])) {
                continue;
            }
            $restoreName = $priorNames[$pid] ?? '';
            $upd = $this->conn->prepare(
                "UPDATE partners SET share = ?, name = ? WHERE id = ? AND entity_id = ?"
            );
            $upd->bind_param('dsii', $share, $restoreName, $pid, $entityId);
            $upd->execute();
            if ($restoreName !== '') {
                $accName = "حساب شخصی: " . $restoreName;
                $accUpd = $this->conn->prepare(
                    "UPDATE accounts SET name = ? WHERE partner_id = ? AND entity_id = ?"
                );
                $accUpd->bind_param('sii', $accName, $pid, $entityId);
                $accUpd->execute();
            }
            $seen[$pid] = true;
        }

        // Remove partners that were created solely by this closing (no remaining payments).
        foreach ($addedPartners as $added) {
            $pid = (int)($added['partner_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            $payCheck = $this->conn->prepare(
                "SELECT id FROM payments WHERE person_type = 'partner' AND person_id = ? AND entity_id = ? LIMIT 1"
            );
            $payCheck->bind_param('ii', $pid, $entityId);
            $payCheck->execute();
            $hasPay = db_stmt_to_assoc_array($payCheck);
            if (!empty($hasPay)) {
                // Keep the partner record but zero the share.
                $zero = $this->conn->prepare("UPDATE partners SET share = 0 WHERE id = ? AND entity_id = ?");
                $zero->bind_param('ii', $pid, $entityId);
                $zero->execute();
                continue;
            }
            $delPartner = $this->conn->prepare("DELETE FROM partners WHERE id = ? AND entity_id = ?");
            $delPartner->bind_param('ii', $pid, $entityId);
            $delPartner->execute();
        }
    }
}
