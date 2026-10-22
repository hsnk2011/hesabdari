<?php
// /api/models/CrmTimeline.php
// Read side of the CRM: everything that happened with one customer, and the
// list of customers who owe us with what was said to them last.
require_once __DIR__ . '/Customer.php';
require_once __DIR__ . '/CrmTask.php';

class CrmTimeline {
    /** Customer a payment belongs to: through its sales invoice, or a standalone customer payment. */
    const PAY_CUSTOMER_JOIN = " LEFT JOIN sales_invoices psi ON p.invoiceType = 'sales' AND psi.id = p.invoiceId ";
    const PAY_CUSTOMER_EXPR = " COALESCE(psi.customerId, IF(p.person_type = 'customer' AND p.invoiceId IS NULL, p.person_id, NULL)) ";

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Net money received from the customer between two dates (receipts minus
     * refunds, reversals and bounced checks), the same signing the balances use.
     */
    public static function customerReceipts($db, int $entityId, int $customerId, string $fromDate, string $toDate): float {
        $stmt = $db->prepare("SELECT COALESCE(SUM(IF(p.transaction_type = 'receipt', p.amount, -p.amount)), 0) AS net
            FROM payments p " . self::PAY_CUSTOMER_JOIN . "
            WHERE p.entity_id = ? AND p.date BETWEEN ? AND ? AND " . self::PAY_CUSTOMER_EXPR . " = ?");
        $stmt->bind_param('issi', $entityId, $fromDate, $toDate, $customerId);
        $stmt->execute();
        return (float)(db_stmt_to_assoc_array($stmt)[0]['net'] ?? 0);
    }

    public function forCustomer(int $entityId, int $customerId, int $page = 1, int $limit = 30): array {
        $customer = (new Customer($this->conn))->getNetBalance($customerId);
        if ($customer === null) {
            return ['error' => 'مشتری یافت نشد.', 'statusCode' => 404];
        }
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        $e = $entityId;
        $c = $customerId;
        $today = date('Y-m-d');

        // Every branch yields: type, id, date, title, amount, detail, user_name.
        $sql = "
            SELECT 'sales_invoice' AS type, si.id, CONCAT(si.date, ' 00:00:00') AS date,
                   CONCAT('فاکتور فروش ', COALESCE(NULLIF(si.invoice_number, ''), si.id)) AS title,
                   (si.totalAmount - COALESCE(si.discount, 0) + COALESCE(si.tax_amount, 0)) AS amount,
                   si.description AS detail, NULL AS user_name
            FROM sales_invoices si WHERE si.entity_id = {$e} AND si.customerId = {$c}
            UNION ALL
            SELECT 'return_invoice', ri.id, CONCAT(ri.date, ' 00:00:00'), CONCAT('مرجوعی فروش ', ri.id),
                   (ri.totalAmount - COALESCE(ri.discount, 0)), ri.description, NULL
            FROM return_invoices ri WHERE ri.entity_id = {$e} AND ri.type = 'sales_return' AND ri.person_type = 'customer' AND ri.person_id = {$c}
            UNION ALL
            SELECT 'payment', p.id, CONCAT(p.date, ' 00:00:01'),
                   CONCAT(IF(p.transaction_type = 'receipt', 'دریافت ', 'پرداخت/برگشت '),
                          CASE p.type WHEN 'cash' THEN 'نقدی' WHEN 'check' THEN CONCAT('چک ', COALESCE(ch.checkNumber, ''), IF(ch.status = 'bounced', ' (برگشتی)', IF(ch.status = 'cashed', ' (وصول‌شده)', ''))) WHEN 'endorse_check' THEN 'خرج چک' WHEN 'check_bounce' THEN 'برگشت چک' ELSE 'تهاتر' END),
                   IF(p.transaction_type = 'receipt', p.amount, -p.amount),
                   IF(p.type = 'check' AND ch.dueDate IS NOT NULL, CONCAT('سررسید ', ch.dueDate), p.description), NULL
            FROM payments p " . self::PAY_CUSTOMER_JOIN . "
            LEFT JOIN checks ch ON ch.id = p.checkId
            WHERE p.entity_id = {$e} AND " . self::PAY_CUSTOMER_EXPR . " = {$c}
            UNION ALL
            SELECT 'installment', ins.id, CONCAT(ins.due_date, ' 00:00:02'),
                   CONCAT('قسط ', ins.installment_number, IF(ins.status = 'paid', ' (پرداخت‌شده)', IF(ins.status IN ('overdue', 'partially_paid'), ' (معوق)', ''))),
                   (ins.amount - COALESCE(ins.paid_amount, 0)), ins.description, NULL
            FROM installments ins JOIN sales_invoices isi ON isi.id = ins.invoice_id
            WHERE ins.entity_id = {$e} AND ins.invoice_type = 'sales' AND isi.customerId = {$c} AND ins.due_date <= '{$today}'
            UNION ALL
            SELECT 'quotation', q.id, CONCAT(q.date, ' 00:00:00'), CONCAT('پیش‌فاکتور ', q.id,
                   CASE q.status WHEN 'converted' THEN ' (تبدیل‌شده)' WHEN 'cancelled' THEN ' (لغو)' WHEN 'expired' THEN ' (منقضی)' ELSE '' END),
                   (q.totalAmount - COALESCE(q.discount, 0)), q.description, NULL
            FROM quotations q WHERE q.entity_id = {$e} AND q.customerId = {$c}
            UNION ALL
            SELECT 'sms', sl.id, COALESCE(sl.sent_at, sl.created_at), 'پیامک فاکتور', NULL, sl.message, sl.username
            FROM sms_logs sl JOIN sales_invoices ssi ON sl.invoice_type = 'sales' AND ssi.id = sl.invoice_id
            WHERE sl.entity_id = {$e} AND ssi.customerId = {$c}
            UNION ALL
            SELECT 'activity', a.id, a.created_at, CONCAT(a.kind, '|', COALESCE(a.outcome, '')), a.promise_amount,
                   CONCAT_WS(' — ', a.body, IF(a.promise_date IS NOT NULL, CONCAT('قول تا ', a.promise_date, IF(a.promise_status = 'kept', ' (عمل شد)', IF(a.promise_status = 'broken', ' (عمل نشد)', ''))), NULL)),
                   atm.full_name
            FROM crm_activities a LEFT JOIN team_members atm ON atm.entity_id = a.entity_id AND atm.user_id = a.user_id
            WHERE a.entity_id = {$e} AND a.person_type = 'customer' AND a.person_id = {$c}
            UNION ALL
            SELECT 'task', t.id, t.done_at, CONCAT('انجام شد: ', t.title), NULL, t.description, ttm.full_name
            FROM crm_tasks t LEFT JOIN team_members ttm ON ttm.entity_id = t.entity_id AND ttm.user_id = t.done_by
            WHERE t.entity_id = {$e} AND t.person_type = 'customer' AND t.person_id = {$c} AND t.status = 'done'
            ORDER BY date DESC, type, id DESC
            LIMIT " . ($limit + 1) . " OFFSET {$offset}";
        $res = $this->conn->query($sql);
        $items = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);

        $header = null;
        if ($page <= 1) {
            $stmt = $this->conn->prepare("SELECT c.name, c.phone, c.owner_user_id, tm.full_name AS owner_name FROM customers c
                LEFT JOIN team_members tm ON tm.entity_id = c.entity_id AND tm.user_id = c.owner_user_id WHERE c.id = ? AND c.entity_id = ?");
            $stmt->bind_param('ii', $c, $e);
            $stmt->execute();
            $header = db_stmt_to_assoc_array($stmt)[0];
            $header['net_balance'] = $customer['net_balance'];
            $header['credit_limit'] = $customer['credit_limit'];
            $header['last_activity'] = $this->one("SELECT a.created_at, a.kind, a.outcome, tm.full_name AS user_name FROM crm_activities a
                LEFT JOIN team_members tm ON tm.entity_id = a.entity_id AND tm.user_id = a.user_id
                WHERE a.entity_id = {$e} AND a.person_type = 'customer' AND a.person_id = {$c} ORDER BY a.created_at DESC, a.id DESC LIMIT 1");
            $header['open_promise'] = $this->one("SELECT id, promise_amount, promise_date, promise_status FROM crm_activities
                WHERE entity_id = {$e} AND person_type = 'customer' AND person_id = {$c} AND promise_status IN ('open', 'broken')
                ORDER BY promise_status = 'open' DESC, promise_date DESC LIMIT 1");
            $header['open_tasks'] = array_values(array_filter(
                (new CrmTask($this->conn))->listForPerson($e, 'customer', $c),
                fn($t) => $t['status'] === 'open'
            ));
        }
        return ['success' => true, 'header' => $header, 'items' => $items, 'has_more' => $hasMore];
    }

    /**
     * Customers who owe us, with the follow-up context; most urgent first:
     * broken/overdue promise, bounced check, overdue installments, then amount.
     */
    public function debtors(int $entityId): array {
        $rows = array_values(array_filter(
            (new Customer($this->conn))->allWithBalance(),
            fn($r) => (float)($r['net_balance'] ?? 0) > 0.01
        ));
        if (!$rows) {
            return [];
        }
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $e = $entityId;
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+3 days'));

        $byCustomer = function (string $sql) {
            $out = [];
            $res = $this->conn->query($sql);
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $out[(int)$r['cid']] = $r;
            }
            return $out;
        };
        $last = $byCustomer("SELECT a.person_id AS cid, a.created_at AS last_activity_at, a.outcome AS last_activity_outcome, tm.full_name AS last_activity_user
            FROM crm_activities a LEFT JOIN team_members tm ON tm.entity_id = a.entity_id AND tm.user_id = a.user_id
            WHERE a.id IN (SELECT MAX(id) FROM crm_activities WHERE entity_id = {$e} AND person_type = 'customer' AND person_id IN ({$ids}) GROUP BY person_id)");
        $promise = $byCustomer("SELECT a.person_id AS cid, a.promise_amount, a.promise_date, a.promise_status
            FROM crm_activities a
            WHERE a.id IN (SELECT MAX(id) FROM crm_activities WHERE entity_id = {$e} AND person_type = 'customer' AND person_id IN ({$ids}) AND promise_status IN ('open', 'broken') GROUP BY person_id)");
        $inst = $byCustomer("SELECT si.customerId AS cid, SUM(ins.amount - COALESCE(ins.paid_amount, 0)) AS overdue_installments
            FROM installments ins JOIN sales_invoices si ON si.id = ins.invoice_id
            WHERE ins.entity_id = {$e} AND ins.invoice_type = 'sales' AND ins.status IN ('pending', 'overdue', 'partially_paid') AND ins.due_date < '{$today}' AND si.customerId IN ({$ids})
            GROUP BY si.customerId");
        $checks = $byCustomer("SELECT " . self::PAY_CUSTOMER_EXPR . " AS cid,
                SUM(ch.status = 'bounced') AS bounced_checks,
                SUM(IF(ch.status = 'in_hand' AND ch.dueDate <= '{$soon}', ch.amount, 0)) AS checks_due_soon
            FROM payments p " . self::PAY_CUSTOMER_JOIN . " JOIN checks ch ON ch.id = p.checkId AND ch.type = 'received'
            WHERE p.entity_id = {$e} AND p.type = 'check' AND " . self::PAY_CUSTOMER_EXPR . " IN ({$ids})
            GROUP BY cid");
        $owners = TeamMember::namesByUser($this->conn, $e);
        $ownerIds = [];
        $res = $this->conn->query("SELECT id, owner_user_id FROM customers WHERE id IN ({$ids})");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
            $ownerIds[(int)$r['id']] = $r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null;
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $p = $promise[$id] ?? [];
            $row = [
                'id' => $id,
                'name' => $r['name'],
                'phone' => $r['phone'],
                'net_balance' => (float)$r['net_balance'],
                'owner_user_id' => $ownerIds[$id] ?? null,
                'owner_name' => isset($ownerIds[$id]) ? ($owners[$ownerIds[$id]] ?? null) : null,
                'last_activity_at' => $last[$id]['last_activity_at'] ?? null,
                'last_activity_outcome' => $last[$id]['last_activity_outcome'] ?? null,
                'last_activity_user' => $last[$id]['last_activity_user'] ?? null,
                'promise_amount' => isset($p['promise_amount']) ? (float)$p['promise_amount'] : null,
                'promise_date' => $p['promise_date'] ?? null,
                'promise_status' => $p['promise_status'] ?? null,
                'overdue_installments' => (float)($inst[$id]['overdue_installments'] ?? 0),
                'bounced_checks' => (int)($checks[$id]['bounced_checks'] ?? 0),
                'checks_due_soon' => (float)($checks[$id]['checks_due_soon'] ?? 0),
            ];
            $row['promise_late'] = $row['promise_status'] === 'broken' || ($row['promise_status'] === 'open' && $row['promise_date'] < $today);
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            foreach (['promise_late', 'bounced_checks', 'overdue_installments'] as $k) {
                $cmp = ($b[$k] > 0) <=> ($a[$k] > 0);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return $b['net_balance'] <=> $a['net_balance'];
        });
        return $out;
    }

    private function one(string $sql): ?array {
        $res = $this->conn->query($sql);
        return $res ? ($res->fetch_assoc() ?: null) : null;
    }
}
