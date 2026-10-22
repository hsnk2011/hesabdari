<?php
// /api/controllers/NotificationController.php

class NotificationController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /** Scheduler entry for customer SMS reminders (installments / overdue invoices). */
    public static function runScheduledReminders(mysqli $db) {
        require_once __DIR__ . '/SmsController.php';
        return (new SmsController($db))->processCustomerReminders();
    }

    /**
     * Notifications the user may see: whole-team rows (user_id NULL) plus the
     * ones addressed to this user (CRM tasks, reminders).
     */
    public static function fetchVisible($db, int $entityId, ?int $userId, int $limit): array {
        $uid = (int)($userId ?? 0);
        $stmt = $db->prepare("
            SELECT * FROM notifications
            WHERE entity_id = ? AND is_dismissed = 0 AND (user_id IS NULL OR user_id = ?)
            ORDER BY due_date ASC, created_at DESC
            LIMIT " . max(1, $limit));
        $stmt->bind_param("ii", $entityId, $uid);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function getReminders($data) {
        $entity_id = $_SESSION['current_entity_id'];

        $listLimit = defined('NOTIFICATION_LIST_LIMIT') ? NOTIFICATION_LIST_LIMIT : 50;
        $notifications = self::fetchVisible($this->conn, (int)$entity_id, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, (int)$listLimit);

        // تبدیل تاریخ‌های میلادی موجود در متن پیام و فیلدها به شمسی
        foreach ($notifications as &$n) {
            // تبدیل الگوی YYYY-MM-DD در متن پیام
            $n['message'] = preg_replace_callback('/(\d{4})-(\d{2})-(\d{2})/', function($m) {
                return gregorian_to_jalali($m[0]);
            }, (string)($n['message'] ?? ''));
            // تبدیل فیلد due_date برای نمایش
            if (!empty($n['due_date'])) {
                $n['due_date_shamsi'] = gregorian_to_jalali($n['due_date']);
            }
        }
        unset($n);

        send_json(['items' => $notifications]);
    }

    public function dismiss($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);

        // A user may dismiss whole-team rows and their own, never another user's.
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE notifications SET is_dismissed = 1 WHERE id = ? AND entity_id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt->bind_param("iii", $id, $entity_id, $uid);
            $stmt->execute();
        } else {
            // Dismiss all
            $stmt = $this->conn->prepare("UPDATE notifications SET is_dismissed = 1 WHERE entity_id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt->bind_param("ii", $entity_id, $uid);
            $stmt->execute();
        }

        send_json(['success' => true]);
    }

    /**
     * Generate notifications for due checks, overdue invoices, installments
     */
    public function generate($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $today = date('Y-m-d');
        $reminderDays = defined('CHECK_REMINDER_DAYS') ? CHECK_REMINDER_DAYS : 7;
        $weekFromNow = date('Y-m-d', strtotime("+{$reminderDays} days"));
        $generated = 0;

        // 1. Due checks (received) coming up within the reminder window
        $stmt = $this->conn->prepare("
            SELECT c.id, c.checkNumber, c.amount, c.dueDate, c.bankName 
            FROM checks c 
            WHERE c.entity_id = ? AND c.type = 'received' AND c.status = 'in_hand' 
            AND c.dueDate BETWEEN ? AND ?
            AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.entity_id = ? AND n.reference_type = 'check' AND n.reference_id = c.id)
        ");
        $stmt->bind_param("issi", $entity_id, $today, $weekFromNow, $entity_id);
        $stmt->execute();
        $dueChecks = db_stmt_to_assoc_array($stmt);

        $stmtIns = $this->conn->prepare("INSERT INTO notifications (entity_id, type, title, message, reference_type, reference_id, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($dueChecks as $chk) {
            $type = 'check_due';
            $title = "سررسید چک #{$chk['checkNumber']}";
            $shamsiDate = gregorian_to_jalali($chk['dueDate']);
            $message = "چک به مبلغ " . number_format($chk['amount']) . " ریال در تاریخ {$shamsiDate} سررسید می‌شود.";
            $refType = 'check';
            $stmtIns->bind_param("issssis", $entity_id, $type, $title, $message, $refType, $chk['id'], $chk['dueDate']);
            $stmtIns->execute();
            $generated++;
        }

        // 2. Payable checks due in 7 days
        $stmt2 = $this->conn->prepare("
            SELECT c.id, c.checkNumber, c.amount, c.dueDate 
            FROM checks c 
            WHERE c.entity_id = ? AND c.type = 'payable' AND c.status = 'payable' 
            AND c.dueDate BETWEEN ? AND ?
            AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.entity_id = ? AND n.reference_type = 'payable_check' AND n.reference_id = c.id)
        ");
        $stmt2->bind_param("issi", $entity_id, $today, $weekFromNow, $entity_id);
        $stmt2->execute();
        $payableChecks = db_stmt_to_assoc_array($stmt2);
        
        foreach ($payableChecks as $chk) {
            $type = 'payable_check_due';
            $title = "سررسید چک پرداختنی #{$chk['checkNumber']}";
            $shamsiDate = gregorian_to_jalali($chk['dueDate']);
            $message = "چک پرداختنی به مبلغ " . number_format($chk['amount']) . " ریال در تاریخ {$shamsiDate} سررسید می‌شود.";
            $refType = 'payable_check';
            $stmtIns->bind_param("issssis", $entity_id, $type, $title, $message, $refType, $chk['id'], $chk['dueDate']);
            $stmtIns->execute();
            $generated++;
        }

        // 3. Overdue installments
        $stmt3 = $this->conn->prepare("
            SELECT i.id, i.invoice_id, i.invoice_type, i.installment_number, i.amount, i.due_date 
            FROM installments i 
            WHERE i.entity_id = ? AND i.status IN ('pending','overdue') AND i.due_date <= ?
            AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.entity_id = ? AND n.reference_type = 'installment' AND n.reference_id = i.id)
        ");
        $stmt3->bind_param("isi", $entity_id, $today, $entity_id);
        $stmt3->execute();
        $overdueInstallments = db_stmt_to_assoc_array($stmt3);

        foreach ($overdueInstallments as $inst) {
            $type = 'installment_overdue';
            $title = "قسط معوق #{$inst['installment_number']}";
            $shamsiDate = gregorian_to_jalali($inst['due_date']);
            $typeLabel = $inst['invoice_type'] === 'sales' ? 'فروش' : 'خرید';
            $message = "قسط شماره {$inst['installment_number']} فاکتور {$typeLabel} #{$inst['invoice_id']} به مبلغ " . number_format($inst['amount']) . " - سررسید: {$shamsiDate}";
            $refType = 'installment';
            $stmtIns->bind_param("issssis", $entity_id, $type, $title, $message, $refType, $inst['id'], $inst['due_date']);
            $stmtIns->execute();
            $generated++;
        }

        // 4. Overdue invoices (sales with due_date)
        $stmt4 = $this->conn->prepare("
            SELECT si.id, si.due_date, c.name as customerName, (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) as remaining
            FROM sales_invoices si
            LEFT JOIN customers c ON si.customerId = c.id
            WHERE si.entity_id = ? AND si.due_date IS NOT NULL AND si.due_date < ?
            AND (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) > " . MINIMUM_AMOUNT . "
            AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.entity_id = ? AND n.reference_type = 'overdue_invoice' AND n.reference_id = si.id)
        ");
        $stmt4->bind_param("isi", $entity_id, $today, $entity_id);
        $stmt4->execute();
        $overdueInvoices = db_stmt_to_assoc_array($stmt4);

        foreach ($overdueInvoices as $inv) {
            $type = 'invoice_overdue';
            $title = "فاکتور فروش سررسید گذشته #{$inv['id']}";
            $shamsiDate = gregorian_to_jalali($inv['due_date']);
            $message = "فاکتور مشتری {$inv['customerName']} با مانده " . number_format($inv['remaining']) . " ریال - سررسید: {$shamsiDate}";
            $refType = 'overdue_invoice';
            $stmtIns->bind_param("issssis", $entity_id, $type, $title, $message, $refType, $inv['id'], $inv['due_date']);
            $stmtIns->execute();
            $generated++;
        }

        send_json(['success' => true, 'generated' => $generated, 'message' => "{$generated} اعلان جدید ایجاد شد."]);
    }
}
