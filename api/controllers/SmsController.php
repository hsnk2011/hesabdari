<?php
// /api/controllers/SmsController.php

require_once __DIR__ . '/../models/Settings.php';
require_once __DIR__ . '/../models/EntitySettings.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../core/KavenegarSms.php';

class SmsController {
    private $conn;
    private $settingsModel;
    private $entitySettingsModel;
    private $smsLogModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->settingsModel = new Settings($db);
        $this->entitySettingsModel = new EntitySettings($db);
        $this->smsLogModel = new SmsLog($db);
    }

    private function getSmsConfig() {
        $all = $this->settingsModel->getAllSettings();
        $mode = ($all['sms_mode'] ?? 'verify') === 'simple' ? 'simple' : 'verify';
        return [
            'enabled' => ($all['sms_enabled'] ?? '0') === '1',
            'api_key' => $all['sms_api_key'] ?? '',
            'sender' => KavenegarSms::normalizeSenderLine($all['sms_sender'] ?? ''),
            'mode' => $mode,
            'template_sales' => $all['sms_template_sales'] ?? $this->defaultSalesTemplate($all),
            'template_ready' => $all['sms_template_ready'] ?? $this->defaultReadyTemplate($all),
            'verify_template_sales' => $all['sms_verify_template_sales'] ?? 'invoicesales',
            'verify_template_ready' => $all['sms_verify_template_ready'] ?? 'invoiceready',
        ];
    }

    /**
     * Brand/company name for outgoing texts — print_company_name first, then
     * app_title, so messages are never branded with another shop's name.
     */
    private function companyName($all = null) {
        if (!is_array($all)) {
            $all = $this->settingsModel->getAllSettings();
        }
        $name = trim((string)($all['print_company_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($all['app_title'] ?? ''));
        }
        return $name !== '' ? $name : 'فروشگاه';
    }

    private function defaultSalesTemplate($all = null) {
        $brand = $this->companyName($all);
        return "{$brand}\nمشتری گرامی {customer_name}\nفاکتور فروش شماره {invoice_number} مورخ {date} به مبلغ {grand_total} تومان صادر شد.\nخالص دریافتی: {paid} تومان\n{remaining_line}\n{payments_summary}\nمشاهده فاکتور: {invoice_link}";
    }

    private function defaultReadyTemplate($all = null) {
        $brand = $this->companyName($all);
        return "{$brand}\nمشتری گرامی {customer_name}\nسفارش شما با شماره فاکتور {invoice_number} آماده تحویل است.\n{remaining_line}\nتماس: {company_phone}\nمشاهده فاکتور: {invoice_link}";
    }

    private function defaultCheckAlertTemplate() {
        return "شریک گرامی {partner_name}\n{check_count} فقره چک نزدیک به سررسید (نزدیک‌ترین: {days} روز مانده).\nجمع مبلغ: {total_amount} تومان\n{checks_summary}\n{company_name}";
    }

    /** Days from $today (Y-m-d) until $dueDate (Y-m-d); never negative. */
    private function daysUntilDue($dueDate, $today) {
        $dueTs = strtotime(substr((string)$dueDate, 0, 10) . ' 00:00:00');
        $todayTs = strtotime($today . ' 00:00:00');
        if ($dueTs === false || $todayTs === false) {
            return 0;
        }
        return max(0, (int)round(($dueTs - $todayTs) / 86400));
    }

    private function formatDaysRemainingLabel($daysLeft) {
        $n = (int)$daysLeft;
        if ($n === 0) {
            return 'امروز';
        }
        return "{$n} روز مانده";
    }

    /**
     * Called from cron / manual button. Sends once per day max (unless $force).
     */
    /**
     * Scheduler entry: sends the daily check-due alert once per entity.
     * The "already sent today" marker is kept per entity so one entity's run
     * no longer suppresses the others (previously a single global marker plus
     * the triggering user's current entity meant other entities never got alerts).
     */
    public function processPartnerCheckAlertsForAllEntities() {
        $all = $this->settingsModel->getAllSettings();
        if (($all['sms_check_alert_enabled'] ?? '0') !== '1' || ($all['sms_enabled'] ?? '0') !== '1') {
            return ['skipped' => true, 'reason' => 'disabled'];
        }
        $partnerIds = json_decode($all['sms_check_alert_partner_ids'] ?? '[]', true);
        $partnerIds = is_array($partnerIds) ? array_values(array_unique(array_filter(array_map('intval', $partnerIds)))) : [];
        if (empty($partnerIds)) {
            return ['skipped' => true, 'reason' => 'no_partners'];
        }
        $ph = implode(',', array_fill(0, count($partnerIds), '?'));
        $stmt = $this->conn->prepare("SELECT DISTINCT entity_id FROM partners WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($partnerIds)), ...$partnerIds);
        $stmt->execute();
        $entityIds = array_values(array_filter(array_map(function ($r) { return (int)$r['entity_id']; }, db_stmt_to_assoc_array($stmt))));

        $today = date('Y-m-d');
        $results = [];
        foreach ($entityIds as $eid) {
            $key = 'sms_check_alert_last_run_' . $eid;
            if (($all[$key] ?? '') === $today || $this->checkAlertMarkerDate($eid) === $today) {
                continue;
            }
            $res = $this->processPartnerCheckAlertsInternal(false, [$eid]);
            $results[$eid] = $res;
            // Mark done unless the run could not even start (no API key, ...):
            // those reasons are configuration problems, retried next interval.
            $retryReasons = ['no_api_key', 'sms_disabled'];
            if (empty($res['skipped']) || !in_array($res['reason'] ?? '', $retryReasons, true)) {
                $this->markCheckAlertDone($eid, $today);
            }
        }
        return $results;
    }

    /**
     * Scheduler job: SMS reminders to customers
     *  - installments due in N days (once per installment), and
     *  - sales invoices past due with a remaining balance (every X days).
     * Only between 9:00 and 20:00, only when reminders and SMS are enabled.
     * Every send is recorded in reminder_log so nothing is sent twice a day.
     */
    public function processCustomerReminders() {
        $all = $this->settingsModel->getAllSettings();
        if (($all['reminder_sms_enabled'] ?? '0') !== '1' || ($all['sms_enabled'] ?? '0') !== '1') {
            return ['skipped' => 'disabled'];
        }
        $hour = (int)date('G');
        if ($hour < 9 || $hour >= 20) {
            return ['skipped' => 'quiet_hours'];
        }
        $cfg = $this->getSmsConfig();
        if ($cfg['api_key'] === '') {
            return ['skipped' => 'no_api_key'];
        }
        $verifyName = trim((string)($all['sms_verify_template_reminder'] ?? ''));
        if ($cfg['mode'] === 'verify' && $verifyName === '') {
            return ['skipped' => 'verify_template_missing'];
        }
        $client = $this->clientFromConfig($cfg);
        $today = date('Y-m-d');
        $fmt = function ($n) { return number_format((float)$n, 0, '', ','); };
        $branding = [];
        $brand = function ($entityId) use (&$branding, $all) {
            if (!isset($branding[$entityId])) {
                $branding[$entityId] = $this->resolveCompanyBranding($entityId, $all);
            }
            return $branding[$entityId];
        };
        $sent = 0;
        $failed = 0;
        $maxPerRun = 30; // spread large batches over several scheduler ticks

        $logged = function ($kind, $refType, $refId, $sinceDate) {
            $st = $this->conn->prepare("SELECT 1 FROM reminder_log WHERE kind = ? AND ref_type = ? AND ref_id = ? AND sent_on >= ? LIMIT 1");
            $st->bind_param('ssis', $kind, $refType, $refId, $sinceDate);
            $st->execute();
            return !empty(db_stmt_to_assoc_array($st));
        };
        $record = function ($entityId, $kind, $refType, $refId, $receptor, $status) use ($today) {
            $st = $this->conn->prepare("INSERT IGNORE INTO reminder_log (entity_id, kind, ref_type, ref_id, sent_on, receptor, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param('ississs', $entityId, $kind, $refType, $refId, $today, $receptor, $status);
            $st->execute();
        };
        $send = function ($receptor, $message, array $tokens, array $meta) use ($client, $cfg, $verifyName) {
            if ($cfg['mode'] === 'verify') {
                $result = $client->verifyLookup($receptor, $verifyName, $tokens);
            } else {
                $result = $client->send($receptor, $message);
            }
            $ok = !empty($result['success']) || !empty($result['entries']) || !empty($result['messageid'])
                || (!empty($result['return']['status']) && intval($result['return']['status']) === 200);
            if ($ok) {
                $this->logSendSuccess($cfg, $result, $meta + ['receptor' => $receptor, 'message' => $message, 'mode' => $cfg['mode']]);
            }
            return $ok;
        };

        // 1) Installments due soon
        $days = max(0, min(30, (int)($all['reminder_installment_days'] ?? 2)));
        $until = date('Y-m-d', strtotime("+{$days} days"));
        $st = $this->conn->prepare(
            "SELECT i.id, i.entity_id, i.amount, i.paid_amount, i.due_date, i.installment_number, i.invoice_id,
                    si.invoice_number, c.name AS customer_name, c.phone
             FROM installments i
             JOIN sales_invoices si ON i.invoice_type = 'sales' AND si.id = i.invoice_id
             JOIN customers c ON c.id = si.customerId
             WHERE i.status IN ('pending', 'partially_paid', 'overdue') AND i.due_date BETWEEN ? AND ?
             ORDER BY i.due_date ASC LIMIT 200"
        );
        $st->bind_param('ss', $today, $until);
        $st->execute();
        foreach (db_stmt_to_assoc_array($st) as $inst) {
            if ($sent >= $maxPerRun) break;
            $receptor = KavenegarSms::normalizeReceptor((string)$inst['phone']);
            if ($receptor === '' || $logged('installment_due', 'installment', (int)$inst['id'], '2000-01-01')) {
                continue;
            }
            $b = $brand((int)$inst['entity_id']);
            $remaining = max(0, (float)$inst['amount'] - (float)$inst['paid_amount']);
            $dueFa = gregorian_to_jalali($inst['due_date']);
            $msg = "{$b['company_name']}\nمشتری گرامی {$inst['customer_name']}\nقسط شماره {$inst['installment_number']} فاکتور " . ($inst['invoice_number'] ?: $inst['invoice_id'])
                . " به مبلغ {$fmt($remaining)} تومان در تاریخ {$dueFa} سررسید می‌شود.\n" . ($b['company_phone'] ? "تماس: {$b['company_phone']}" : '');
            $ok = $send($receptor, trim($msg), [
                'token' => mb_substr($inst['customer_name'], 0, 100),
                'token2' => $fmt($remaining),
                'token3' => str_replace('/', '-', $dueFa),
            ], ['template_key' => 'installment_reminder', 'invoice_type' => 'sales', 'invoice_id' => (int)$inst['invoice_id']]);
            $record((int)$inst['entity_id'], 'installment_due', 'installment', (int)$inst['id'], $receptor, $ok ? 'sent' : 'failed');
            $ok ? $sent++ : $failed++;
        }

        // 2) Overdue sales invoices
        $every = max(0, min(90, (int)($all['reminder_overdue_every_days'] ?? 7)));
        if ($every > 0) {
            $overdueDays = defined('INVOICE_OVERDUE_DAYS') ? INVOICE_OVERDUE_DAYS : 30;
            $fallbackDate = date('Y-m-d', strtotime("-{$overdueDays} days"));
            $st = $this->conn->prepare(
                "SELECT si.id, si.entity_id, si.invoice_number, si.date, si.due_date,
                        (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) AS remaining,
                        c.name AS customer_name, c.phone
                 FROM sales_invoices si
                 JOIN customers c ON c.id = si.customerId
                 WHERE si.is_consignment = 0
                   AND (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) > 1000
                   AND ((si.due_date IS NOT NULL AND si.due_date < ?) OR (si.due_date IS NULL AND si.date < ?))
                   AND NOT EXISTS (SELECT 1 FROM installments i WHERE i.invoice_type = 'sales' AND i.invoice_id = si.id AND i.status <> 'paid')
                 ORDER BY si.date ASC LIMIT 200"
            );
            $st->bind_param('ss', $today, $fallbackDate);
            $st->execute();
            $since = date('Y-m-d', strtotime('-' . ($every - 1) . ' days'));
            foreach (db_stmt_to_assoc_array($st) as $inv) {
                if ($sent >= $maxPerRun) break;
                $receptor = KavenegarSms::normalizeReceptor((string)$inv['phone']);
                if ($receptor === '' || $logged('invoice_overdue', 'sales_invoice', (int)$inv['id'], $since)) {
                    continue;
                }
                $b = $brand((int)$inv['entity_id']);
                $prevSession = $_SESSION ?? [];
                $_SESSION['current_entity_id'] = (int)$inv['entity_id'];
                $link = $this->ensureInvoiceLink('sales', (int)$inv['id']);
                $_SESSION = $prevSession;
                $msg = "{$b['company_name']}\nمشتری گرامی {$inv['customer_name']}\nمانده فاکتور " . ($inv['invoice_number'] ?: $inv['id'])
                    . " مورخ " . gregorian_to_jalali($inv['date']) . " مبلغ {$fmt($inv['remaining'])} تومان است. لطفاً نسبت به تسویه اقدام فرمایید."
                    . ($link ? "\nمشاهده و پرداخت: {$link}" : '') . ($b['company_phone'] ? "\nتماس: {$b['company_phone']}" : '');
                $ok = $send($receptor, $msg, [
                    'token' => mb_substr($inv['customer_name'], 0, 100),
                    'token2' => $fmt($inv['remaining']),
                    'token3' => (string)($inv['invoice_number'] ?: $inv['id']),
                ], ['template_key' => 'overdue_reminder', 'invoice_type' => 'sales', 'invoice_id' => (int)$inv['id']]);
                $record((int)$inv['entity_id'], 'invoice_overdue', 'sales_invoice', (int)$inv['id'], $receptor, $ok ? 'sent' : 'failed');
                $ok ? $sent++ : $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * "Already sent today" marker per entity. Written directly: saveAllSettings()
     * only stores whitelisted keys and silently dropped this one, so the daily
     * alert was re-sent on every scheduler run (every 15 minutes).
     */
    public function markCheckAlertDone($entityId, $date) {
        $key = 'sms_check_alert_last_run_' . (int)$entityId;
        $stmt = $this->conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('ss', $key, $date);
        $stmt->execute();
        $stmt->close();
    }

    public function checkAlertMarkerDate($entityId) {
        $key = 'sms_check_alert_last_run_' . (int)$entityId;
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0]['setting_value'] ?? null;
    }

    /** True when an automatic check-due SMS already went to this number today. */
    public function checkAlertSentToday($receptor, $date) {
        $digits = preg_replace('/\D/', '', (string)$receptor);
        if (strlen($digits) < 10) {
            return false;
        }
        $tail = substr($digits, -10);
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM sms_logs
             WHERE source = 'check_alert' AND RIGHT(receptor, 10) = ? AND created_at BETWEEN ? AND ?
             LIMIT 1"
        );
        $stmt->bind_param('sss', $tail, $from, $to);
        $stmt->execute();
        return !empty(db_stmt_to_assoc_array($stmt));
    }

    public function processPartnerCheckAlertsInternal($force = false, $entityIdsOverride = null) {
        $all = $this->settingsModel->getAllSettings();
        // Auto/cron requires the feature toggle; manual force may run with current settings.
        if (!$force && ($all['sms_check_alert_enabled'] ?? '0') !== '1') {
            return ['skipped' => true, 'reason' => 'disabled'];
        }
        if (($all['sms_enabled'] ?? '0') !== '1') {
            return ['skipped' => true, 'reason' => 'sms_disabled'];
        }

        $today = date('Y-m-d');

        $cfg = $this->getSmsConfig();
        if ($cfg['api_key'] === '') {
            return ['skipped' => true, 'reason' => 'no_api_key'];
        }

        $days = max(0, min(60, intval($all['sms_check_alert_days'] ?? 3)));
        $partnerIds = json_decode($all['sms_check_alert_partner_ids'] ?? '[]', true);
        if (!is_array($partnerIds) || empty($partnerIds)) {
            return ['skipped' => true, 'reason' => 'no_partners'];
        }
        $partnerIds = array_values(array_unique(array_filter(array_map('intval', $partnerIds))));
        $types = json_decode($all['sms_check_alert_types'] ?? '["received","payable"]', true);
        $statuses = json_decode($all['sms_check_alert_statuses'] ?? '["in_hand","payable"]', true);
        if (!is_array($types) || empty($types)) $types = ['received', 'payable'];
        if (!is_array($statuses) || empty($statuses)) $statuses = ['in_hand', 'payable'];

        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);

        // Explicit list (scheduler) > current entity (manual button) > partners' entities.
        $entityIds = [];
        if (is_array($entityIdsOverride)) {
            $entityIds = array_values(array_filter(array_map('intval', $entityIdsOverride)));
        } elseif ($entityId > 0) {
            $entityIds = [$entityId];
        } else {
            $idPlaceholdersTmp = implode(',', array_fill(0, count($partnerIds), '?'));
            $eStmt = $this->conn->prepare("SELECT DISTINCT entity_id FROM partners WHERE id IN ($idPlaceholdersTmp)");
            $eTypes = str_repeat('i', count($partnerIds));
            $eStmt->bind_param($eTypes, ...$partnerIds);
            $eStmt->execute();
            foreach (db_stmt_to_assoc_array($eStmt) as $row) {
                if (!empty($row['entity_id'])) {
                    $entityIds[] = (int)$row['entity_id'];
                }
            }
            $entityIds = array_values(array_unique($entityIds));
        }
        if (empty($entityIds)) {
            return ['skipped' => true, 'reason' => 'no_partners'];
        }

        $typePlaceholders = implode(',', array_fill(0, count($types), '?'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $entityPlaceholders = implode(',', array_fill(0, count($entityIds), '?'));
        $sql = "SELECT id, type, status, checkNumber, bankName, issuerName, dueDate, amount, entity_id
                FROM checks
                WHERE entity_id IN ($entityPlaceholders)
                  AND dueDate BETWEEN ? AND ?
                  AND type IN ($typePlaceholders)
                  AND status IN ($statusPlaceholders)
                ORDER BY dueDate ASC, id ASC";
        $stmt = $this->conn->prepare($sql);
        $bindTypes = str_repeat('i', count($entityIds)) . 'ss' . str_repeat('s', count($types) + count($statuses));
        $bindParams = array_merge($entityIds, [$today, $endDate], $types, $statuses);
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $allChecks = db_stmt_to_assoc_array($stmt);
        if (empty($allChecks)) {
            return ['success' => true, 'sent' => 0, 'message' => 'در بازه انتخاب‌شده چک نزدیک به سررسید یافت نشد.'];
        }

        $idPlaceholders = implode(',', array_fill(0, count($partnerIds), '?'));
        $pStmt = $this->conn->prepare("SELECT id, name, phone, entity_id FROM partners WHERE id IN ($idPlaceholders) AND entity_id IN ($entityPlaceholders)");
        $pTypes = str_repeat('i', count($partnerIds) + count($entityIds));
        $pParams = array_merge($partnerIds, $entityIds);
        $pStmt->bind_param($pTypes, ...$pParams);
        $pStmt->execute();
        $partners = db_stmt_to_assoc_array($pStmt);

        $partnersWithPhone = 0;
        foreach ($partners as $p) {
            if (trim((string)($p['phone'] ?? '')) !== '' && KavenegarSms::normalizeReceptor($p['phone']) !== '') {
                $partnersWithPhone++;
            }
        }
        if ($partnersWithPhone === 0) {
            return ['skipped' => true, 'reason' => 'no_partner_phone'];
        }

        $template = trim((string)($all['sms_check_alert_template'] ?? '')) ?: $this->defaultCheckAlertTemplate();
        $legacyDefault = "شریک گرامی {partner_name}\n{check_count} فقره چک تا {days} روز آینده سررسید می‌شود.\nجمع مبلغ: {total_amount} تومان\n{checks_summary}\n{company_name}";
        if ($template === $legacyDefault) {
            $template = $this->defaultCheckAlertTemplate();
        }
        $verifyName = $all['sms_check_alert_verify_template'] ?? 'checkdue';
        $client = $this->clientFromConfig($cfg);
        $fmt = function ($n) { return number_format((float)$n, 0, '', ','); };
        $dateFa = function_exists('gregorian_to_jalali') ? gregorian_to_jalali($today) : $today;

        // Branding cache per entity (entity print settings override global)
        $brandingByEntity = [];
        foreach ($entityIds as $eid) {
            $brandingByEntity[$eid] = $this->resolveCompanyBranding($eid, $all);
        }

        $sent = 0;
        $errors = [];
        foreach ($partners as $partner) {
            $phoneRaw = trim((string)($partner['phone'] ?? ''));
            if ($phoneRaw === '') {
                continue;
            }
            $receptor = KavenegarSms::normalizeReceptor($phoneRaw);
            if ($receptor === '') {
                continue;
            }
            // Second guard for automatic runs: one check-due SMS per number per day.
            // The manual «ارسال» button ($force) may still resend on purpose.
            if (!$force && $this->checkAlertSentToday($receptor, $today)) {
                $skippedAlreadySent = ($skippedAlreadySent ?? 0) + 1;
                continue;
            }

            $partnerEntityId = (int)($partner['entity_id'] ?? 0);
            $branding = $brandingByEntity[$partnerEntityId]
                ?? $this->resolveCompanyBranding($partnerEntityId ?: ($entityIds[0] ?? 0), $all);

            // Only checks of the partner's entity
            $partnerChecks = array_values(array_filter($allChecks, function ($c) use ($partnerEntityId) {
                return (int)($c['entity_id'] ?? 0) === $partnerEntityId;
            }));
            if (empty($partnerChecks)) {
                continue;
            }

            $lines = [];
            $total = 0;
            $minDaysLeft = null;
            foreach ($partnerChecks as $c) {
                $total += (float)$c['amount'];
                $daysLeft = $this->daysUntilDue($c['dueDate'], $today);
                if ($minDaysLeft === null || $daysLeft < $minDaysLeft) {
                    $minDaysLeft = $daysLeft;
                }
                $dueFa = function_exists('gregorian_to_jalali') ? gregorian_to_jalali($c['dueDate']) : $c['dueDate'];
                $typeLabel = ($c['type'] === 'payable') ? 'پرداختی' : 'دریافتی';
                $remainLabel = $this->formatDaysRemainingLabel($daysLeft);
                $lines[] = "{$c['checkNumber']} | {$typeLabel} | {$fmt($c['amount'])} | {$dueFa} | {$remainLabel}" . (!empty($c['bankName']) ? " | {$c['bankName']}" : '');
            }
            // Keep SMS reasonably short
            if (count($lines) > 8) {
                $extra = count($lines) - 8;
                $lines = array_slice($lines, 0, 8);
                $lines[] = "... و {$extra} فقره دیگر";
            }

            // {days} = remaining days until nearest check (not the alert window setting)
            $vars = [
                'partner_name' => $partner['name'],
                'days' => (string)($minDaysLeft ?? 0),
                'alert_days' => (string)$days,
                'check_count' => (string)count($partnerChecks),
                'total_amount' => $fmt($total),
                'checks_summary' => implode("\n", $lines),
                'company_name' => $branding['company_name'],
                'company_phone' => $branding['company_phone'],
                'date' => $dateFa,
            ];

            try {
                if ($cfg['mode'] === 'verify') {
                    $result = $client->verifyLookup($receptor, $verifyName, [
                        'token' => mb_substr($vars['partner_name'], 0, 100),
                        'token2' => $vars['check_count'],
                        'token3' => $vars['days'],
                        'token10' => $vars['total_amount'],
                        'token20' => mb_substr(str_replace("\n", ' | ', $vars['checks_summary']), 0, 200),
                    ]);
                    $preview = 'اعلان چک (verify): ' . $verifyName;
                } else {
                    $message = $this->renderTemplate($template, $vars);
                    $result = $client->send($receptor, $message);
                    $preview = $message;
                }

                if (!empty($result['success']) || (!empty($result['return']['status']) && intval($result['return']['status']) === 200) || !empty($result['entries']) || !empty($result['messageid'])) {
                    $sent++;
                    $this->logSendSuccess($cfg, $result, [
                        'source' => 'check_alert',
                        'template_key' => 'check_due',
                        'receptor' => $receptor,
                        'message' => $preview,
                        'mode' => $cfg['mode'],
                        'verify_template' => $cfg['mode'] === 'verify' ? $verifyName : null,
                    ]);
                } else {
                    $errors[] = ($partner['name'] ?? '') . ': ' . ($result['error'] ?? $result['return']['message'] ?? 'خطا');
                }
            } catch (Throwable $e) {
                $errors[] = ($partner['name'] ?? '') . ': ' . $e->getMessage();
            }
        }

        if ($sent > 0) {
            $this->settingsModel->saveAllSettings(['sms_check_alert_last_run' => $today]);
        }
        return [
            'success' => true,
            'sent' => $sent,
            'partners' => count($partners),
            'checks' => count($allChecks),
            'errors' => $errors,
            'message' => $sent > 0
                ? "{$sent} پیامک اعلان چک ارسال شد."
                : 'هیچ پیامکی ارسال نشد.',
        ];
    }

    /**
     * Resolve company branding for SMS from entity print settings first.
     */
    private function resolveCompanyBranding($entityId, array $globalSettings = []) {
        $entityId = intval($entityId);
        if (empty($globalSettings)) {
            $globalSettings = $this->settingsModel->getAllSettings();
        }
        $entityPrint = $entityId > 0
            ? ($this->entitySettingsModel->getEntityPrintSettings($entityId) ?: [])
            : [];

        $entityName = '';
        if ($entityId > 0) {
            $entStmt = $this->conn->prepare('SELECT name FROM business_entities WHERE id = ? LIMIT 1');
            $entStmt->bind_param('i', $entityId);
            $entStmt->execute();
            $entRows = db_stmt_to_assoc_array($entStmt);
            if (!empty($entRows[0]['name'])) {
                $entityName = trim($entRows[0]['name']);
            }
        }

        $companyName = trim((string)($entityPrint['print_company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = $entityName;
        }
        if ($companyName === '') {
            $companyName = trim((string)($globalSettings['print_company_name'] ?? ''));
        }
        if ($companyName === '') {
            $companyName = trim((string)($globalSettings['app_title'] ?? '')) ?: 'فروشگاه';
        }

        $companyPhone = trim((string)($entityPrint['print_company_phone'] ?? ''));
        if ($companyPhone === '') {
            $companyPhone = trim((string)($globalSettings['print_company_phone'] ?? ''));
        }

        return [
            'company_name' => $companyName,
            'company_phone' => $companyPhone,
        ];
    }

    private function checkAlertSkipMessage($reason) {
        $map = [
            'disabled' => 'اعلان خودکار چک غیرفعال است. برای ارسال دستی، تنظیمات را ذخیره کنید یا سوئیچ اعلان را روشن کنید.',
            'sms_disabled' => 'ارسال پیامک در تنظیمات کلی غیرفعال است. ابتدا «فعال‌سازی ارسال پیامک» را روشن و ذخیره کنید.',
            'already_ran_today' => 'اعلان چک امروز قبلاً ارسال شده است.',
            'no_api_key' => 'کلید API کاوه‌نگار تنظیم نشده است.',
            'no_partners' => 'هیچ شریکی برای دریافت اعلان انتخاب نشده است.',
            'no_partner_phone' => 'شرکای انتخاب‌شده شماره تماس معتبر ندارند. در مدیریت شرکا شماره را ثبت کنید.',
        ];
        return $map[$reason] ?? ('ارسال انجام نشد (' . $reason . ').');
    }

    public function runPartnerCheckAlerts($data = null) {
        check_permission('manage_settings');
        $force = !empty($data['force']);
        $result = $this->processPartnerCheckAlertsInternal($force);
        if (!empty($result['skipped'])) {
            send_json([
                'success' => true,
                'skipped' => true,
                'reason' => $result['reason'] ?? '',
                'message' => $this->checkAlertSkipMessage($result['reason'] ?? ''),
            ]);
            return;
        }
        send_json($result);
    }

    private function maskApiKey($key) {
        $key = (string)$key;
        $len = strlen($key);
        if ($len <= 8) return $len ? str_repeat('*', $len) : '';
        return substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($key, -4);
    }

    private function resolveTemplateKey($data) {
        $key = $data['template_key'] ?? 'sales';
        return $key === 'ready' ? 'ready' : 'sales';
    }

    private function pickLocalTemplate($cfg, $templateKey) {
        return $templateKey === 'ready' ? $cfg['template_ready'] : $cfg['template_sales'];
    }

    private function pickVerifyTemplateName($cfg, $templateKey) {
        return $templateKey === 'ready' ? $cfg['verify_template_ready'] : $cfg['verify_template_sales'];
    }

    private function buildVerifyTokens(array $vars, $templateKey = 'sales') {
        return [
            'token' => $vars['customer_name'] ?? '-',
            'token2' => $vars['invoice_number'] ?? '-',
            'token3' => $vars['date'] ?? '-',
            'token10' => $templateKey === 'ready'
                ? ($vars['remaining'] ?? '-')
                : ($vars['grand_total'] ?? '-'),
            'token20' => !empty($vars['invoice_link']) ? $vars['invoice_link'] : '-',
        ];
    }

    private function ensureInvoiceLink($invoiceType, $invoiceId, $ttlHours = null) {
        if ($ttlHours === null) {
            $ttlHours = intval(get_app_setting($this->conn, 'share_link_ttl_hours', defined('DEFAULT_SHARE_LINK_TTL_HOURS') ? DEFAULT_SHARE_LINK_TTL_HOURS : 168));
        }
        $invoiceId = intval($invoiceId);
        if ($invoiceId <= 0 || $invoiceType !== 'sales') {
            return '';
        }

        $stmt = $this->conn->prepare(
            "SELECT token FROM proforma_links
             WHERE invoice_type = ? AND invoice_id = ? AND expires_at > NOW() AND revoked_at IS NULL
               AND CHAR_LENGTH(token) BETWEEN 6 AND 16
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('si', $invoiceType, $invoiceId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (!empty($rows[0]['token'])) {
            return invoice_public_short_url($rows[0]['token']);
        }

        $expires = (new DateTime())->add(new DateInterval('PT' . max(1, intval($ttlHours)) . 'H'))->format('Y-m-d H:i:s');
        $ins = $this->conn->prepare(
            "INSERT INTO proforma_links (invoice_type, invoice_id, token, expires_at) VALUES (?, ?, ?, ?)"
        );

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = generate_short_invoice_code(8);
            $ins->bind_param('siss', $invoiceType, $invoiceId, $token, $expires);
            if ($ins->execute()) {
                return invoice_public_short_url($token);
            }
        }
        return '';
    }

    private function requireUserConfirmation($data) {
        $confirmed = $data['user_confirmed'] ?? false;
        if ($confirmed === true || $confirmed === 1 || $confirmed === '1' || $confirmed === 'true') {
            return true;
        }
        send_json(['error' => 'ارسال پیامک بدون تأیید کاربر مجاز نیست.'], 400);
        return false;
    }

    private function clientFromConfig($cfg = null) {
        $cfg = $cfg ?: $this->getSmsConfig();
        return new KavenegarSms($cfg['api_key'], $cfg['sender']);
    }

    private function extractAccountCredit($infoResult) {
        if (empty($infoResult['success'])) {
            return null;
        }
        $entries = $infoResult['raw']['entries'] ?? null;
        if (!is_array($entries)) {
            return null;
        }
        return $entries['remaincredit'] ?? ($entries['balance'] ?? null);
    }

    private function extractAccountInfo($infoResult) {
        if (empty($infoResult['success'])) {
            return null;
        }
        $e = $infoResult['raw']['entries'] ?? null;
        if (!is_array($e)) {
            return null;
        }
        $expireUnix = isset($e['expiredate']) ? intval($e['expiredate']) : null;
        return [
            'remaincredit' => $e['remaincredit'] ?? null,
            'expiredate' => $expireUnix,
            'expiredate_iso' => $expireUnix ? date('Y-m-d H:i:s', $expireUnix) : null,
            'type' => $e['type'] ?? null,
        ];
    }

    private function logSendSuccess($cfg, $result, array $meta = []) {
        try {
            $entries = is_array($result['entries'] ?? null) ? $result['entries'] : [];
            if (isset($entries['messageid']) && !isset($entries[0])) {
                $entries = [$entries];
            }
            if (empty($entries) && !empty($result['messageid'])) {
                $entries = [['messageid' => $result['messageid'], 'receptor' => $meta['receptor'] ?? '', 'status' => 1, 'cost' => null]];
            }
            $logged = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $status = isset($entry['status']) ? intval($entry['status']) : 1;
                $sentAt = !empty($entry['date']) ? date('Y-m-d H:i:s', intval($entry['date'])) : date('Y-m-d H:i:s');
                $id = $this->smsLogModel->createLog([
                    'messageid' => isset($entry['messageid']) ? (string)$entry['messageid'] : ($result['messageid'] ?? null),
                    'receptor' => $entry['receptor'] ?? ($meta['receptor'] ?? ''),
                    'sender' => $entry['sender'] ?? ($cfg['sender'] ?? null),
                    'message' => $entry['message'] ?? ($meta['message'] ?? null),
                    'mode' => $meta['mode'] ?? $cfg['mode'],
                    'template_key' => $meta['template_key'] ?? null,
                    'verify_template' => $meta['verify_template'] ?? null,
                    'invoice_type' => $meta['invoice_type'] ?? null,
                    'invoice_id' => $meta['invoice_id'] ?? null,
                    'cost' => isset($entry['cost']) ? intval($entry['cost']) : null,
                    'status' => $status,
                    'status_text' => KavenegarSms::statusLabel($status),
                    'source' => $meta['source'] ?? 'app',
                    'sent_at' => $sentAt,
                ]);
                if ($id) {
                    $logged[] = $id;
                }
            }
            return $logged;
        } catch (Throwable $e) {
            error_log('[SmsController::logSendSuccess] ' . $e->getMessage());
            return [];
        }
    }

    public function getSmsSettings($data) {
        $cfg = $this->getSmsConfig();
        $all = $this->settingsModel->getAllSettings();
        $client = $this->clientFromConfig($cfg);
        $account = null;
        $credit = null;
        if ($cfg['api_key'] !== '') {
            $info = $client->accountInfo();
            $account = $this->extractAccountInfo($info);
            $credit = $account['remaincredit'] ?? null;
        }
        send_json([
            'settings' => [
                'sms_enabled' => $cfg['enabled'] ? '1' : '0',
                'sms_mode' => $cfg['mode'],
                'sms_api_key_masked' => $this->maskApiKey($cfg['api_key']),
                'sms_api_key_set' => $cfg['api_key'] !== '',
                'sms_sender' => $cfg['sender'],
                'sms_template_sales' => $cfg['template_sales'],
                'sms_template_ready' => $cfg['template_ready'],
                'sms_verify_template_sales' => $cfg['verify_template_sales'],
                'sms_verify_template_ready' => $cfg['verify_template_ready'],
                'sms_auto_send_sales' => '0',
                'sms_placeholders' => '{customer_name} {invoice_number} {date} {total} {discount} {tax} {grand_total} {paid} {remaining} {remaining_line} {payments_summary} {company_name} {company_phone} {invoice_link}',
                'sms_check_alert_enabled' => $all['sms_check_alert_enabled'] ?? '0',
                'sms_check_alert_days' => $all['sms_check_alert_days'] ?? '3',
                'sms_check_alert_partner_ids' => $all['sms_check_alert_partner_ids'] ?? '[]',
                'sms_check_alert_types' => $all['sms_check_alert_types'] ?? '["received","payable"]',
                'sms_check_alert_statuses' => $all['sms_check_alert_statuses'] ?? '["in_hand","payable"]',
                'sms_check_alert_template' => (function () use ($all) {
                    $tpl = trim((string)($all['sms_check_alert_template'] ?? ''));
                    $legacy = "شریک گرامی {partner_name}\n{check_count} فقره چک تا {days} روز آینده سررسید می‌شود.\nجمع مبلغ: {total_amount} تومان\n{checks_summary}\n{company_name}";
                    if ($tpl === '' || $tpl === $legacy) {
                        return $this->defaultCheckAlertTemplate();
                    }
                    return $tpl;
                })(),
                'sms_check_alert_verify_template' => $all['sms_check_alert_verify_template'] ?? 'checkdue',
                'sms_check_alert_placeholders' => '{partner_name} {days} {alert_days} {check_count} {total_amount} {checks_summary} {company_name} {company_phone} {date}',
                'kavenegar_verify_guide' => (function () use ($all) {
                    $brand = $this->companyName($all);
                    return [
                        'invoicesales' => [
                            'name' => 'invoicesales',
                            'text' => "{$brand}\n\nمشتری گرامی %token\n\nفاکتور شماره %token2 مورخ %token3 به مبلغ\n\n%token10 تومان صادر شد.\n\nلینک فاکتور: %token20",
                            'tokens' => 'token=نام مشتری | token2=شماره فاکتور | token3=تاریخ | token10=مبلغ | token20=لینک',
                        ],
                        'invoiceready' => [
                            'name' => 'invoiceready',
                            'text' => "{$brand}\n\nمشتری گرامی %token\n\nسفارش با فاکتور %token2 آماده تحویل است.\n\nمانده: %token10 تومان\n\nلینک فاکتور: %token20",
                            'tokens' => 'token=نام مشتری | token2=شماره فاکتور | token10=مانده | token20=لینک',
                        ],
                    ];
                })(),
                'status_labels' => KavenegarSms::$statusLabels,
            ],
            'credit' => $credit,
            'account' => $account,
            'configured' => $client->isConfigured(),
        ]);
    }

    public function saveSmsSettings($data) {
        $payload = ['sms_auto_send_sales' => '0'];
        if (array_key_exists('sms_enabled', $data)) {
            $payload['sms_enabled'] = $data['sms_enabled'];
        }
        if (array_key_exists('sms_mode', $data)) {
            $payload['sms_mode'] = ($data['sms_mode'] === 'simple') ? 'simple' : 'verify';
        }
        if (array_key_exists('sms_sender', $data)) {
            $payload['sms_sender'] = KavenegarSms::normalizeSenderLine($data['sms_sender']);
        }
        if (array_key_exists('sms_template_sales', $data)) {
            $payload['sms_template_sales'] = (string)$data['sms_template_sales'];
        }
        if (array_key_exists('sms_template_ready', $data)) {
            $payload['sms_template_ready'] = (string)$data['sms_template_ready'];
        }
        if (array_key_exists('sms_verify_template_sales', $data)) {
            $name = trim((string)$data['sms_verify_template_sales']);
            $payload['sms_verify_template_sales'] = preg_match('/^[A-Za-z0-9_-]+$/', $name) ? $name : 'invoicesales';
        }
        if (array_key_exists('sms_verify_template_ready', $data)) {
            $name = trim((string)$data['sms_verify_template_ready']);
            $payload['sms_verify_template_ready'] = preg_match('/^[A-Za-z0-9_-]+$/', $name) ? $name : 'invoiceready';
        }
        if (!empty($data['sms_api_key']) && strpos($data['sms_api_key'], '*') === false) {
            $payload['sms_api_key'] = trim((string)$data['sms_api_key']);
        }

        if (array_key_exists('sms_check_alert_enabled', $data)) {
            $payload['sms_check_alert_enabled'] = ($data['sms_check_alert_enabled'] === '1' || $data['sms_check_alert_enabled'] === 1 || $data['sms_check_alert_enabled'] === true) ? '1' : '0';
        }
        if (array_key_exists('sms_check_alert_days', $data)) {
            $days = max(0, min(60, intval($data['sms_check_alert_days'])));
            $payload['sms_check_alert_days'] = (string)$days;
        }
        if (array_key_exists('sms_check_alert_partner_ids', $data)) {
            $ids = $data['sms_check_alert_partner_ids'];
            if (is_string($ids)) {
                $decoded = json_decode($ids, true);
                $ids = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($ids)) $ids = [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            $payload['sms_check_alert_partner_ids'] = json_encode($ids);
        }
        if (array_key_exists('sms_check_alert_types', $data)) {
            $types = $data['sms_check_alert_types'];
            if (is_string($types)) {
                $decoded = json_decode($types, true);
                $types = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($types)) $types = [];
            $types = array_values(array_intersect($types, ['received', 'payable']));
            $payload['sms_check_alert_types'] = json_encode($types ?: ['received', 'payable']);
        }
        if (array_key_exists('sms_check_alert_statuses', $data)) {
            $statuses = $data['sms_check_alert_statuses'];
            if (is_string($statuses)) {
                $decoded = json_decode($statuses, true);
                $statuses = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($statuses)) $statuses = [];
            $statuses = array_values(array_intersect($statuses, ['in_hand', 'payable', 'endorsed', 'cashed', 'bounced']));
            $payload['sms_check_alert_statuses'] = json_encode($statuses ?: ['in_hand', 'payable']);
        }
        if (array_key_exists('sms_check_alert_template', $data)) {
            $payload['sms_check_alert_template'] = (string)$data['sms_check_alert_template'];
        }
        if (array_key_exists('sms_check_alert_verify_template', $data)) {
            $name = trim((string)$data['sms_check_alert_verify_template']);
            $payload['sms_check_alert_verify_template'] = preg_match('/^[A-Za-z0-9_-]+$/', $name) ? $name : 'checkdue';
        }

        $ok = $this->settingsModel->saveAllSettings($payload);
        if ($ok === false && count($payload) <= 1) {
            send_json(['error' => 'داده‌ای برای ذخیره ارسال نشده.'], 400);
            return;
        }
        log_activity($this->conn, 'SAVE_SMS_SETTINGS', 'تنظیمات پیامک ذخیره شد.');
        send_json(['success' => true, 'message' => 'تنظیمات پیامک ذخیره شد. هیچ پیامکی بدون تأیید شما ارسال نمی‌شود.']);
    }

    public function testSms($data) {
        if (!$this->requireUserConfirmation($data)) {
            return;
        }
        $cfg = $this->getSmsConfig();
        $receptor = $data['receptor'] ?? '';
        $client = $this->clientFromConfig($cfg);

        if ($cfg['mode'] === 'verify') {
            $template = $cfg['verify_template_sales'];
            $result = $client->verifyLookup($receptor, $template, [
                'token' => 'تست',
                'token2' => '0',
                'token3' => date('Y-m-d'),
                'token10' => '0',
                'token20' => 'test',
            ]);
            $preview = 'تست اعتبارسنجی: ' . $template;
            $verifyName = $template;
        } else {
            $message = trim((string)($data['message'] ?? 'پیامک آزمایشی از سیستم حسابداری'));
            $result = $client->send($receptor, $message);
            $preview = $message;
            $verifyName = null;
        }

        if (!empty($result['success'])) {
            $normalized = KavenegarSms::normalizeReceptor($receptor);
            $this->logSendSuccess($cfg, $result, [
                'receptor' => $normalized ?: $receptor,
                'message' => $preview,
                'mode' => $cfg['mode'],
                'template_key' => 'test',
                'verify_template' => $verifyName,
                'source' => 'test',
            ]);
            log_activity($this->conn, 'SMS_TEST', 'پیامک آزمایشی به ' . $receptor . ' ارسال شد.');
            send_json(['success' => true, 'message' => 'پیامک آزمایشی ارسال شد.', 'messageid' => $result['messageid'] ?? null]);
            return;
        }
        send_json(['error' => $result['error'] ?? 'ارسال ناموفق بود.'], 400);
    }

    public function sendInvoiceSms($data) {
        if (!$this->requireUserConfirmation($data)) {
            return;
        }

        $cfg = $this->getSmsConfig();
        if (!$cfg['enabled']) {
            send_json(['error' => 'ارسال پیامک در تنظیمات غیرفعال است.'], 400);
            return;
        }

        $type = $data['invoice_type'] ?? 'sales';
        if ($type !== 'sales') {
            send_json(['error' => 'ارسال پیامک فقط برای فاکتور فروش (مشتری) فعال است.'], 400);
            return;
        }
        check_permission('manage_sales_invoices');

        $invoiceId = intval($data['invoice_id'] ?? 0);
        $receptor = $data['receptor'] ?? '';
        $customMessage = isset($data['message']) ? trim((string)$data['message']) : null;
        $templateKey = $this->resolveTemplateKey($data);
        $includeLink = !empty($data['include_invoice_link']);

        $ctx = $this->buildInvoiceContext($invoiceId, $data, $includeLink, $templateKey);
        if (!empty($ctx['error'])) {
            send_json(['error' => $ctx['error']], $ctx['statusCode'] ?? 400);
            return;
        }

        if ($receptor === '' && !empty($ctx['phones'])) {
            $receptor = $ctx['phones'][0];
        }
        $normalized = KavenegarSms::normalizeReceptor($receptor);
        if ($normalized === '' && !empty($ctx['phones'])) {
            foreach ($ctx['phones'] as $candidate) {
                $n = KavenegarSms::normalizeReceptor($candidate);
                if ($n !== '') {
                    $receptor = $candidate;
                    $normalized = $n;
                    break;
                }
            }
        }
        if ($normalized === '') {
            send_json([
                'error' => 'شماره موبایل معتبر برای مشتری یافت نشد. شماره باید مثل 09123456789 باشد. مقدار فعلی: ' . mb_substr((string)$receptor, 0, 40),
            ], 400);
            return;
        }

        $client = $this->clientFromConfig($cfg);
        $verifyName = null;
        if ($cfg['mode'] === 'verify') {
            $verifyName = $this->pickVerifyTemplateName($cfg, $templateKey);
            $tokens = $this->buildVerifyTokens($ctx['vars'], $templateKey);
            $result = $client->verifyLookup($receptor, $verifyName, $tokens);
            $preview = $this->renderTemplate($this->pickLocalTemplate($cfg, $templateKey), $ctx['vars']);
        } else {
            $template = $this->pickLocalTemplate($cfg, $templateKey);
            $message = ($customMessage !== null && $customMessage !== '')
                ? $customMessage
                : $this->renderTemplate($template, $ctx['vars']);
            $result = $client->send($receptor, $message);
            $preview = $message;
        }

        if (!empty($result['success'])) {
            $this->logSendSuccess($cfg, $result, [
                'receptor' => $normalized,
                'message' => $preview,
                'mode' => $cfg['mode'],
                'template_key' => $templateKey,
                'verify_template' => $verifyName,
                'invoice_type' => 'sales',
                'invoice_id' => $invoiceId,
                'source' => 'invoice',
            ]);
            log_activity(
                $this->conn,
                'SMS_INVOICE',
                "پیامک ({$templateKey}/{$cfg['mode']}) فاکتور sales#{$invoiceId} به {$normalized} ارسال شد."
            );
            send_json([
                'success' => true,
                'message' => 'پیامک با موفقیت ارسال شد.',
                'messageid' => $result['messageid'] ?? null,
                'preview' => $preview,
                'receptor' => $normalized,
                'invoice_link' => $ctx['vars']['invoice_link'] ?? '',
                'mode' => $cfg['mode'],
            ]);
            return;
        }
        send_json(['error' => $result['error'] ?? 'ارسال پیامک ناموفق بود.'], 400);
    }

    /**
     * Free-text SMS from the CRM to a customer/lead/supplier/contact. Also
     * recorded as a CRM activity so the team sees who sent what.
     */
    public function sendCrmSms($data) {
        if (!$this->requireUserConfirmation($data)) {
            return;
        }
        $cfg = $this->getSmsConfig();
        if (!$cfg['enabled']) {
            send_json(['error' => 'ارسال پیامک در تنظیمات غیرفعال است.'], 400);
            return;
        }
        require_once __DIR__ . '/../models/CrmPerson.php';
        require_once __DIR__ . '/../models/CrmActivity.php';
        $entityId = (int)$_SESSION['current_entity_id'];
        $type = (string)($data['person_type'] ?? '');
        $personId = (int)($data['person_id'] ?? 0);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 600) {
            send_json(['error' => 'متن پیامک خالی یا بیش از حد طولانی است.'], 400);
            return;
        }
        if (!CrmPerson::exists($this->conn, $entityId, $type, $personId)) {
            send_json(['error' => 'شخص انتخاب‌شده یافت نشد.'], 404);
            return;
        }
        $stmt = $this->conn->prepare("SELECT phone FROM `" . CrmPerson::TABLES[$type] . "` WHERE id = ?");
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $phone = (string)(db_stmt_to_assoc_array($stmt)[0]['phone'] ?? '');
        // Several numbers may be stored ("0912... / 0935..."); text the first mobile only.
        $normalized = explode(',', KavenegarSms::normalizeReceptor($phone))[0];
        if ($normalized === '') {
            send_json(['error' => 'شماره موبایل معتبر برای این شخص ثبت نشده است.'], 400);
            return;
        }
        $result = $this->clientFromConfig($cfg)->send($normalized, $message);
        if (empty($result['success'])) {
            send_json(['error' => $result['error'] ?? 'ارسال پیامک ناموفق بود.'], 400);
            return;
        }
        $this->logSendSuccess($cfg, $result, ['receptor' => $normalized, 'message' => $message, 'mode' => 'simple', 'source' => 'crm']);
        (new CrmActivity($this->conn))->create($entityId, (int)($_SESSION['user_id'] ?? 0), [
            'person_type' => $type, 'person_id' => $personId, 'kind' => 'note', 'outcome' => 'sms', 'body' => $message,
        ]);
        log_activity($this->conn, 'SMS_CRM', "پیامک CRM به {$normalized} ({$type} #{$personId}) ارسال شد.");
        send_json(['success' => true, 'message' => 'پیامک ارسال شد.', 'receptor' => $normalized]);
    }

    public function previewInvoiceSms($data) {
        $cfg = $this->getSmsConfig();
        $type = $data['invoice_type'] ?? 'sales';
        if ($type !== 'sales') {
            send_json(['error' => 'پیش‌نمایش پیامک فقط برای فاکتور فروش است.'], 400);
            return;
        }
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $templateKey = $this->resolveTemplateKey($data);
        $includeLink = array_key_exists('include_invoice_link', $data)
            ? !empty($data['include_invoice_link'])
            : true;
        $ctx = $this->buildInvoiceContext($invoiceId, $data, $includeLink, $templateKey);
        if (!empty($ctx['error'])) {
            send_json(['error' => $ctx['error']], $ctx['statusCode'] ?? 400);
            return;
        }
        $template = $this->pickLocalTemplate($cfg, $templateKey);
        $message = $this->renderTemplate($template, $ctx['vars']);
        $phones = [];
        foreach ($ctx['phones'] as $p) {
            $n = KavenegarSms::normalizeOnePhone($p);
            $phones[] = $n !== '' ? $n : $p;
        }
        send_json([
            'success' => true,
            'message' => $message,
            'phones' => $phones,
            'vars' => $ctx['vars'],
            'tokens' => $this->buildVerifyTokens($ctx['vars'], $templateKey),
            'template_key' => $templateKey,
            'verify_template' => $this->pickVerifyTemplateName($cfg, $templateKey),
            'invoice_link' => $ctx['vars']['invoice_link'] ?? '',
            'sms_enabled' => $cfg['enabled'],
            'configured' => $cfg['api_key'] !== '',
            'mode' => $cfg['mode'],
        ]);
    }

    /**
     * Local SMS history (+ optional live status refresh for pending).
     */
    public function getSmsLogs($data) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            send_json(['error' => 'نشست نامعتبر است.'], 401);
            return;
        }
        $limit = intval($data['limit'] ?? 50);
        $offset = intval($data['offset'] ?? 0);
        $refresh = !empty($data['refresh_status']);

        if ($refresh) {
            $this->refreshPendingStatuses($entityId);
        }

        $logs = $this->smsLogModel->listLogs($entityId, $limit, $offset);
        $total = $this->smsLogModel->countLogs($entityId);
        send_json([
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'status_labels' => KavenegarSms::$statusLabels,
        ]);
    }

    /**
     * Refresh delivery status for specific message IDs (or all pending).
     */
    public function refreshSmsStatus($data) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            send_json(['error' => 'نشست نامعتبر است.'], 401);
            return;
        }
        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            send_json(['success' => true, 'updated' => [], 'count' => 0, 'message' => 'کلید API پیامک تنظیم نشده است.']);
            return;
        }

        $messageIds = $data['messageids'] ?? $data['messageid'] ?? null;
        if ($messageIds) {
            $ids = is_array($messageIds) ? $messageIds : preg_split('/\s*,\s*/', (string)$messageIds);
        } else {
            $pending = $this->smsLogModel->getPendingMessageIds($entityId, 100);
            $ids = array_column($pending, 'messageid');
        }
        $ids = array_values(array_filter(array_map(function ($id) {
            return preg_replace('/\D+/', '', (string)$id);
        }, $ids)));

        if (empty($ids)) {
            send_json(['success' => true, 'updated' => [], 'message' => 'پیامکی برای به‌روزرسانی وضعیت یافت نشد.']);
            return;
        }

        $result = $client->status($ids);
        if (empty($result['success'])) {
            send_json(['error' => $result['error'] ?? 'دریافت وضعیت ناموفق بود.'], 400);
            return;
        }

        $updated = [];
        foreach (KavenegarSms::normalizeEntries($result['entries']) as $entry) {
            if (empty($entry['messageid'])) {
                continue;
            }
            $this->smsLogModel->updateStatusByMessageId(
                $entry['messageid'],
                $entry['status'],
                $entry['status_label'],
                $entry['cost'] ?? null
            );
            $updated[] = $entry;
        }

        send_json([
            'success' => true,
            'updated' => $updated,
            'count' => count($updated),
            'message' => count($updated) . ' وضعیت به‌روزرسانی شد.',
        ]);
    }

    /**
     * Latest / ranged outbox from Kavenegar panel.
     */
    public function getKavenegarOutbox($data) {
        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            send_json(['success' => true, 'configured' => false, 'messages' => [], 'count' => 0, 'hint' => 'کلید API پیامک تنظیم نشده است.']);
            return;
        }

        $pageSize = intval($data['pagesize'] ?? $data['limit'] ?? 50);
        $sender = array_key_exists('sender', $data) ? $data['sender'] : null;

        if (!empty($data['startdate']) && !empty($data['enddate'])) {
            $start = $this->toUnix($data['startdate']);
            $end = $this->toUnix($data['enddate']);
            if (!$start || !$end) {
                send_json(['error' => 'بازه تاریخ نامعتبر است.'], 400);
                return;
            }
            // Kavenegar selectoutbox: max 1 day span
            if (($end - $start) > 86400) {
                $start = $end - 86400;
            }
            $result = $client->selectOutbox($start, $end, $sender);
            $source = 'selectoutbox';
        } else {
            $result = $client->latestOutbox($pageSize, $sender);
            $source = 'latestoutbox';
            // Fallback: today's selectoutbox when latestoutbox is blocked (407)
            if (empty($result['success']) && (intval($result['status'] ?? 0) === 407 || !empty($result['access_denied']))) {
                $end = time();
                $start = $end - 86400;
                $result = $client->selectOutbox($start, $end, $sender);
                if (empty($result['success']) && $cfg['sender'] !== '') {
                    // retry without sender filter
                    $result = $client->selectOutbox($start, $end, '');
                }
                $source = 'selectoutbox';
            }
        }

        if (empty($result['success'])) {
            // Soft-fail so UI can show hint without Api.call toast
            $hint = $result['error'] ?? 'دریافت صندوق خروجی ناموفق بود.';
            if (!empty($result['access_denied'])) {
                $lineHint = $cfg['sender'] !== '' ? " (خط {$cfg['sender']})" : '';
                $hint = "با وجود خط اختصاصی{$lineHint}، وب‌سرویس کاوه‌نگار اجازه خواندن صندوق خروجی را نداد. در پنل کاوه‌نگار → توسعه‌دهندگان/API مطمئن شوید همین کلید به خط متصل است و متدهای SMS فعال‌اند. پیامک‌های همین سیستم را از «تاریخچه سیستم» ببینید.";
            }
            send_json([
                'success' => true,
                'configured' => true,
                'source' => $source,
                'messages' => [],
                'count' => 0,
                'hint' => $hint,
                'access_denied' => !empty($result['access_denied']),
            ]);
            return;
        }

        $messages = KavenegarSms::normalizeEntries($result['entries']);
        send_json([
            'success' => true,
            'configured' => true,
            'source' => $source,
            'messages' => $messages,
            'count' => count($messages),
            'status_labels' => KavenegarSms::$statusLabels,
        ]);
    }

    /**
     * Count outbox (optionally by delivery status) for last N days.
     * Falls back to local sms_logs when Kavenegar denies panel access (verify-only plans).
     */
    public function getKavenegarOutboxStats($data) {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        $days = max(1, min(90, intval($data['days'] ?? 7)));
        $local = $entityId ? $this->smsLogModel->countStats($entityId, $days) : ['total' => 0, 'delivered' => 0, 'undelivered' => 0];

        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            send_json([
                'success' => true,
                'configured' => false,
                'source' => 'local',
                'days' => $days,
                'total' => $local['total'],
                'delivered' => $local['delivered'],
                'undelivered' => $local['undelivered'],
                'hint' => 'آمار از تاریخچه سیستم (کلید API تنظیم نشده).',
            ]);
            return;
        }

        $end = time();
        $start = $end - ($days * 86400);
        $sender = array_key_exists('sender', $data) ? $data['sender'] : null;

        $totalRes = $client->countOutbox($start, $end, null, $sender);
        $deliveredRes = $client->countOutbox($start, $end, 10, $sender);
        $failedRes = $client->countOutbox($start, $end, 11, $sender);

        $pick = function ($res) {
            if (empty($res['success'])) return null;
            $e = $res['entries'] ?? [];
            if (is_array($e) && isset($e[0]['sumcount'])) return intval($e[0]['sumcount']);
            if (is_array($e) && isset($e['sumcount'])) return intval($e['sumcount']);
            return null;
        };

        $denied = !empty($totalRes['access_denied']) || !empty($deliveredRes['access_denied']) || !empty($failedRes['access_denied']);
        $total = $pick($totalRes);
        $delivered = $pick($deliveredRes);
        $undelivered = $pick($failedRes);

        if ($denied || ($total === null && $delivered === null && $undelivered === null)) {
            send_json([
                'success' => true,
                'configured' => true,
                'source' => 'local',
                'days' => $days,
                'total' => $local['total'],
                'delivered' => $local['delivered'],
                'undelivered' => $local['undelivered'],
                'hint' => $denied
                    ? 'پنل کاوه‌نگار به آمار صندوق خروجی دسترسی نداد؛ آمار از تاریخچه همین سیستم نمایش داده می‌شود.'
                    : 'آمار از تاریخچه سیستم.',
            ]);
            return;
        }

        send_json([
            'success' => true,
            'configured' => true,
            'source' => 'kavenegar',
            'days' => $days,
            'startdate' => $start,
            'enddate' => $end,
            'total' => $total,
            'delivered' => $delivered,
            'undelivered' => $undelivered,
        ]);
    }

    /**
     * Inbox (received SMS) — requires dedicated line number.
     */
    public function getKavenegarInbox($data) {
        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            send_json(['success' => true, 'configured' => false, 'messages' => [], 'hint' => 'کلید API پیامک تنظیم نشده است.']);
            return;
        }

        $line = $data['linenumber'] ?? $data['sender'] ?? $cfg['sender'];
        if ($line === '' || $line === null) {
            send_json([
                'success' => true,
                'configured' => true,
                'messages' => [],
                'hint' => 'برای دریافت پیامک‌های ورودی، شماره خط (Sender) را در تنظیمات وارد کنید.',
            ]);
            return;
        }
        $isRead = array_key_exists('isread', $data) ? intval($data['isread']) : 2;
        $result = $client->receive($line, $isRead);
        if (empty($result['success'])) {
            $hint = $result['error'] ?? 'دریافت صندوق ورودی ناموفق بود.';
            if (!empty($result['access_denied'])) {
                $hint = "خط {$line} تنظیم شده، اما API اجازه خواندن صندوق ورودی را نداد. در پنل کاوه‌نگار اتصال کلید API به این خط و فعال بودن دریافت پیامک را بررسی کنید.";
            }
            send_json([
                'success' => true,
                'configured' => true,
                'messages' => [],
                'linenumber' => $line,
                'hint' => $hint,
                'access_denied' => !empty($result['access_denied']),
            ]);
            return;
        }

        send_json([
            'success' => true,
            'configured' => true,
            'messages' => KavenegarSms::normalizeEntries($result['entries']),
            'linenumber' => $line,
        ]);
    }

    /**
     * Select full details for message ID(s).
     */
    public function selectSms($data) {
        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            send_json(['error' => 'کلید API پیامک تنظیم نشده است.'], 400);
            return;
        }
        $ids = $data['messageids'] ?? $data['messageid'] ?? '';
        if ($ids === '' || $ids === null) {
            send_json(['error' => 'شناسه پیامک الزامی است.'], 400);
            return;
        }
        $result = $client->select($ids);
        if (empty($result['success'])) {
            send_json(['error' => $result['error'] ?? 'دریافت جزئیات ناموفق بود.'], 400);
            return;
        }
        $messages = KavenegarSms::normalizeEntries($result['entries']);
        foreach ($messages as $entry) {
            if (!empty($entry['messageid']) && isset($entry['status'])) {
                $this->smsLogModel->updateStatusByMessageId(
                    $entry['messageid'],
                    $entry['status'],
                    $entry['status_label'],
                    $entry['cost'] ?? null
                );
            }
        }
        send_json(['success' => true, 'messages' => $messages]);
    }

    private function refreshPendingStatuses($entityId) {
        $cfg = $this->getSmsConfig();
        $client = $this->clientFromConfig($cfg);
        if (!$client->isConfigured()) {
            return;
        }
        $pending = $this->smsLogModel->getPendingMessageIds($entityId, 100);
        $ids = array_column($pending, 'messageid');
        if (empty($ids)) {
            return;
        }
        $result = $client->status($ids);
        if (empty($result['success'])) {
            return;
        }
        foreach (KavenegarSms::normalizeEntries($result['entries']) as $entry) {
            if (empty($entry['messageid'])) continue;
            $this->smsLogModel->updateStatusByMessageId(
                $entry['messageid'],
                $entry['status'],
                $entry['status_label'],
                $entry['cost'] ?? null
            );
        }
    }

    private function toUnix($value) {
        if (is_numeric($value)) {
            $n = intval($value);
            // milliseconds → seconds
            if ($n > 9999999999) {
                $n = intval($n / 1000);
            }
            return $n;
        }
        $ts = strtotime((string)$value);
        return $ts ?: null;
    }

    private function buildInvoiceContext($invoiceId, $data, $includeLink = false, $templateKey = 'sales') {
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return ['error' => 'نشست نامعتبر است.', 'statusCode' => 401];
        }

        if (!empty($data['snapshot']) && is_array($data['snapshot']) && !$invoiceId) {
            $inv = $data['snapshot'];
        } else {
            if (!$invoiceId) {
                return ['error' => 'شناسه فاکتور الزامی است.', 'statusCode' => 400];
            }
            $stmt = $this->conn->prepare('SELECT * FROM sales_invoices WHERE id = ? AND entity_id = ?');
            $stmt->bind_param('ii', $invoiceId, $entityId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $inv = $rows[0] ?? null;
            if (!$inv) {
                return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
            }
        }

        $personName = $data['customer_name'] ?? '';
        $personPhone = $data['customer_phone'] ?? '';
        $personId = $inv['customerId'] ?? null;

        if ($personId) {
            $pstmt = $this->conn->prepare('SELECT name, phone FROM customers WHERE id = ? AND entity_id = ?');
            $pid = intval($personId);
            $pstmt->bind_param('ii', $pid, $entityId);
            $pstmt->execute();
            $prows = db_stmt_to_assoc_array($pstmt);
            if (!empty($prows[0])) {
                if ($personName === '' || $personName === '-') $personName = $prows[0]['name'];
                if ($personPhone === '' || $personPhone === '-') $personPhone = $prows[0]['phone'];
            }
        }

        $settings = $this->settingsModel->getAllSettings();
        $entityPrint = $this->entitySettingsModel->getEntityPrintSettings($entityId);
        $merged = array_merge($settings, $entityPrint);

        $entityName = '';
        $entStmt = $this->conn->prepare('SELECT name FROM business_entities WHERE id = ? LIMIT 1');
        $entStmt->bind_param('i', $entityId);
        $entStmt->execute();
        $entRows = db_stmt_to_assoc_array($entStmt);
        if (!empty($entRows[0]['name'])) {
            $entityName = trim($entRows[0]['name']);
        }

        $companyName = trim((string)($entityPrint['print_company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = $entityName;
        }
        if ($companyName === '') {
            $companyName = trim((string)($settings['print_company_name'] ?? ''));
        }
        if ($companyName === '') {
            $companyName = trim((string)($merged['app_title'] ?? '')) ?: 'فروشگاه';
        }

        $total = floatval($inv['totalAmount'] ?? $data['totalAmount'] ?? 0);
        $discount = floatval($inv['discount'] ?? $data['discount'] ?? 0);
        $tax = floatval($inv['tax_amount'] ?? $data['tax_amount'] ?? 0);
        $paid = floatval($inv['paidAmount'] ?? $data['paidAmount'] ?? 0);
        $grand = ($total - $discount) + $tax;
        $remaining = $grand - $paid;
        $invoiceNumber = $inv['invoice_number'] ?? $inv['id'] ?? $invoiceId;
        $date = $inv['date'] ?? ($data['date'] ?? '');
        $dateFa = ($date && function_exists('gregorian_to_jalali')) ? gregorian_to_jalali($date) : $date;

        $fmt = function ($n) {
            return number_format((float)$n, 0, '', ',');
        };

        // خلاصه پرداخت‌ها با جهت (دریافت / پرداخت به مشتری)
        $paymentsSummary = '';
        $payLines = [];
        if ($invoiceId) {
            $pstmt = $this->conn->prepare(
                "SELECT date, amount, transaction_type, type, description
                 FROM payments
                 WHERE entity_id = ? AND invoiceType = 'sales' AND invoiceId = ?
                 ORDER BY date ASC, id ASC"
            );
            $pstmt->bind_param('ii', $entityId, $invoiceId);
            $pstmt->execute();
            foreach (db_stmt_to_assoc_array($pstmt) as $pay) {
                $txn = $pay['transaction_type'] ?? 'receipt';
                $dir = ($txn === 'payment') ? 'پرداخت به شما' : 'دریافت';
                // setoff/tehatar is internal — SMS lists it like a normal cash receipt
                $typeMap = ['cash' => 'نقد', 'setoff' => 'نقد', 'check' => 'چک', 'endorse_check' => 'خرج چک'];
                $rawType = $pay['type'] ?? '';
                $typeFa = $typeMap[$rawType] ?? (($rawType === 'setoff') ? 'نقد' : ($rawType ?: ''));
                $dFa = (!empty($pay['date']) && function_exists('gregorian_to_jalali'))
                    ? gregorian_to_jalali($pay['date'])
                    : ($pay['date'] ?? '');
                $line = $dir . ($typeFa ? " ({$typeFa})" : '') . ' ' . $fmt($pay['amount']) . ' تومان';
                if ($dFa) $line .= " — {$dFa}";
                $payLines[] = $line;
            }
        }
        if (!empty($payLines)) {
            $paymentsSummary = "جزئیات:\n" . implode("\n", $payLines);
        }

        if ($remaining > 0.01) {
            $remainingLine = 'مانده قابل پرداخت: ' . $fmt($remaining) . ' تومان';
        } elseif ($remaining < -0.01) {
            $remainingLine = 'بستانکار شما (مازاد): ' . $fmt(abs($remaining)) . ' تومان';
        } else {
            $remainingLine = 'وضعیت: تسویه کامل';
        }

        $template = $this->pickLocalTemplate($this->getSmsConfig(), $templateKey);
        $needsLink = $includeLink || strpos($template, '{invoice_link}') !== false;

        $invoiceLink = '';
        if ($needsLink && $invoiceId) {
            $invoiceLink = $this->ensureInvoiceLink('sales', $invoiceId);
        }

        $vars = [
            'customer_name' => $personName ?: '-',
            'invoice_number' => (string)$invoiceNumber,
            'date' => $dateFa,
            'total' => $fmt($total),
            'discount' => $fmt($discount),
            'tax' => $fmt($tax),
            'grand_total' => $fmt($grand),
            'paid' => $fmt($paid),
            'remaining' => $fmt($remaining),
            'remaining_line' => $remainingLine,
            'payments_summary' => $paymentsSummary,
            'company_name' => $companyName,
            'company_phone' => $merged['print_company_phone'] ?? '',
            'invoice_link' => $invoiceLink,
        ];

        $phones = [];
        foreach (preg_split('/\s*\/\s*|\s*،\s*|\s*,\s*|\n+/u', (string)$personPhone) as $p) {
            $p = trim($p);
            if ($p !== '' && $p !== '-') $phones[] = $p;
        }

        return ['vars' => $vars, 'phones' => $phones, 'invoice' => $inv];
    }

    private function renderTemplate($template, $vars) {
        $out = (string)$template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        $out = preg_replace('/^.*\{invoice_link\}.*$/mu', '', $out);
        // حذف خطوط خالی اضافه‌مانده از placeholderهای اختیاری مثل payments_summary
        $out = preg_replace("/\n[ \t]*\n[ \t]*\n+/u", "\n\n", $out);
        $out = preg_replace("/\n{3,}/u", "\n\n", $out);
        return trim($out);
    }
}
