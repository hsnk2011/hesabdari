<?php
// /api/models/CrmAutomation.php
// Scheduled CRM work: turn accounting events into follow-up tasks (once each)
// and remind people of due tasks by notification + Web Push.
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/CrmTimeline.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class CrmAutomation {
    /** Older accounting events are not turned into tasks (avoids a flood on the first run). */
    const LOOKBACK_DAYS = 30;
    const DIGEST_HOUR = 8;

    /** @return array<string,int> tasks created per rule (all entities). */
    public static function run($db, ?string $today = null): array {
        $today = $today ?: date('Y-m-d');
        $counts = ['promise_kept' => 0, 'promise_broken' => 0, 'check_due' => 0, 'check_bounced' => 0, 'installment_overdue' => 0, 'quotation_expiring' => 0];
        $res = $db->query("SELECT DISTINCT entity_id FROM team_members WHERE is_active = 1 AND user_id IS NOT NULL");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $row) {
            $entityId = (int)$row['entity_id'];
            $team = TeamMember::userIdsForEntity($db, $entityId);
            if (!$team) {
                continue;
            }
            foreach (self::runForEntity($db, $entityId, $team, $today) as $k => $n) {
                $counts[$k] += $n;
            }
        }
        return $counts;
    }

    private static function runForEntity($db, int $e, array $team, string $today): array {
        $c = array_fill_keys(['promise_kept', 'promise_broken', 'check_due', 'check_bounced', 'installment_overdue', 'quotation_expiring'], 0);
        $since = date('Y-m-d', strtotime($today . ' -' . self::LOOKBACK_DAYS . ' days'));
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $in2days = date('Y-m-d', strtotime($today . ' +2 days'));
        $fallback = $team[0];
        $assignee = fn($owner) => ($owner !== null && in_array((int)$owner, $team, true)) ? (int)$owner : $fallback;

        // 1) Payment promises whose date has passed.
        $res = $db->query("SELECT a.id, a.person_id, a.user_id, a.promise_amount, a.promise_date, DATE(a.created_at) AS made_on, cu.name, cu.owner_user_id
            FROM crm_activities a JOIN customers cu ON cu.id = a.person_id
            WHERE a.entity_id = {$e} AND a.person_type = 'customer' AND a.promise_status = 'open' AND a.promise_date < '{$today}'");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $p) {
            // Money from the day the promise was made (or its date, if entered late) up to now; paying a little late still counts.
            $from = min($p['made_on'], $p['promise_date']);
            $got = CrmTimeline::customerReceipts($db, $e, (int)$p['person_id'], $from, $today);
            $kept = $got + 0.01 >= (float)$p['promise_amount'];
            $db->query("UPDATE crm_activities SET promise_status = '" . ($kept ? 'kept' : 'broken') . "' WHERE id = " . (int)$p['id']);
            if ($kept) {
                $c['promise_kept']++;
                continue;
            }
            $who = in_array((int)$p['user_id'], $team, true) ? (int)$p['user_id'] : $assignee($p['owner_user_id']);
            $c['promise_broken'] += self::addTask($db, $e, 'promise_broken', (int)$p['id'], 'collection', $who, (int)$p['person_id'],
                'قول پرداخت ' . $p['name'] . ' عمل نشد', 'مبلغ ' . number_format((float)$p['promise_amount']) . ' تا ' . $p['promise_date'] . ' قول داده شده بود.', $today . ' 10:00:00');
        }

        // 2) Received checks: due tomorrow, or bounced recently. Customer comes from the payment that brought the check.
        $res = $db->query("SELECT ch.id, ch.checkNumber, ch.amount, ch.dueDate, ch.status,
                MIN(cu.id) AS customer_id, MIN(cu.name) AS name, MIN(cu.owner_user_id) AS owner_user_id
            FROM checks ch
            JOIN payments p ON p.checkId = ch.id AND p.type = 'check' AND p.transaction_type = 'receipt' " . CrmTimeline::PAY_CUSTOMER_JOIN . "
            JOIN customers cu ON cu.id = " . CrmTimeline::PAY_CUSTOMER_EXPR . "
            WHERE ch.entity_id = {$e} AND ch.type = 'received'
              AND ((ch.status = 'in_hand' AND ch.dueDate = '{$tomorrow}') OR (ch.status = 'bounced' AND COALESCE(ch.bounced_date, ch.dueDate) >= '{$since}'))
            GROUP BY ch.id");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $ch) {
            $bounced = $ch['status'] === 'bounced';
            $source = $bounced ? 'check_bounced' : 'check_due';
            $title = $bounced ? 'چک برگشتی ' . $ch['name'] : 'چک ' . $ch['name'] . ' فردا سررسید است';
            $c[$source] += self::addTask($db, $e, $source, (int)$ch['id'], 'collection', $assignee($ch['owner_user_id']), (int)$ch['customer_id'],
                $title, 'چک ' . $ch['checkNumber'] . ' به مبلغ ' . number_format((float)$ch['amount']) . ' — سررسید ' . $ch['dueDate'],
                $bounced ? $today . ' 10:00:00' : $today . ' 16:00:00');
        }

        // 3) Overdue installments of sales invoices.
        $res = $db->query("SELECT ins.id, ins.installment_number, ins.amount, ins.paid_amount, ins.due_date, cu.id AS customer_id, cu.name, cu.owner_user_id
            FROM installments ins JOIN sales_invoices si ON si.id = ins.invoice_id JOIN customers cu ON cu.id = si.customerId
            WHERE ins.entity_id = {$e} AND ins.invoice_type = 'sales' AND ins.status IN ('pending', 'overdue', 'partially_paid')
              AND ins.due_date < '{$today}' AND ins.due_date >= '{$since}'");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $i) {
            $left = (float)$i['amount'] - (float)$i['paid_amount'];
            $c['installment_overdue'] += self::addTask($db, $e, 'installment_overdue', (int)$i['id'], 'collection', $assignee($i['owner_user_id']), (int)$i['customer_id'],
                'قسط معوق ' . $i['name'], 'قسط ' . $i['installment_number'] . ' — مانده ' . number_format($left) . ' — سررسید ' . $i['due_date'], $today . ' 10:00:00');
        }

        // 4) Quotations about to expire.
        $res = $db->query("SELECT q.id, q.valid_until, q.totalAmount, cu.id AS customer_id, cu.name, cu.owner_user_id
            FROM quotations q JOIN customers cu ON cu.id = q.customerId
            WHERE q.entity_id = {$e} AND q.status = 'pending' AND q.valid_until BETWEEN '{$today}' AND '{$in2days}'");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $q) {
            $c['quotation_expiring'] += self::addTask($db, $e, 'quotation_expiring', (int)$q['id'], 'sales', $assignee($q['owner_user_id']), (int)$q['customer_id'],
                'پیش‌فاکتور ' . $q['name'] . ' رو به انقضاست', 'پیش‌فاکتور ' . $q['id'] . ' تا ' . $q['valid_until'] . ' اعتبار دارد.', $today . ' 11:00:00');
        }
        return $c;
    }

    /** INSERT IGNORE on (entity_id, source, source_ref_id): each event becomes one task, ever. */
    private static function addTask($db, int $e, string $source, int $refId, string $kind, int $assignee, int $customerId, string $title, string $desc, string $due): int {
        $stmt = $db->prepare("INSERT IGNORE INTO crm_tasks (entity_id, title, description, person_type, person_id, assignee_user_id, due_at, kind, source, source_ref_id)
            VALUES (?, ?, ?, 'customer', ?, ?, ?, ?, ?, ?)");
        $title = mb_substr($title, 0, 255);
        $stmt->bind_param('issiisssi', $e, $title, $desc, $customerId, $assignee, $due, $kind, $source, $refId);
        $stmt->execute();
        return $stmt->affected_rows > 0 ? 1 : 0;
    }

    public static function digestDue(string $now): bool {
        return (int)date('G', strtotime($now)) >= self::DIGEST_HOUR;
    }

    /** Remind assignees of tasks that are due; once a day after 08:00 send each user a summary. Returns reminders sent. */
    public static function sendDueReminders($db, ?string $now = null): int {
        $now = $now ?: date('Y-m-d H:i:s');
        $sent = 0;
        $stmt = $db->prepare("SELECT t.id, t.entity_id, t.title, t.assignee_user_id, " . CrmPerson::nameSelect() . "
            FROM crm_tasks t " . CrmPerson::joinSql('t') . "
            WHERE t.status = 'open' AND t.reminded_at IS NULL AND t.due_at <= ?
            ORDER BY t.due_at LIMIT 200");
        $stmt->bind_param('s', $now);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $t) {
            // Stamp first: a slow push must not make the next tick remind again.
            $db->query("UPDATE crm_tasks SET reminded_at = NOW() WHERE id = " . (int)$t['id'] . " AND reminded_at IS NULL");
            if ($db->affected_rows < 1) {
                continue;
            }
            CrmNotifier::notify($db, (int)$t['entity_id'], (int)$t['assignee_user_id'], 'crm_task_due', 'یادآوری: ' . $t['title'],
                $t['person_name'] ? 'مربوط به ' . $t['person_name'] : 'موعد این کار رسیده است.', './index.php#crm/task/' . (int)$t['id'], 'crm_task', (int)$t['id']);
            $sent++;
        }
        if (self::digestDue($now)) {
            self::sendDigests($db, $now);
        }
        return $sent;
    }

    /** Once a day after 08:00: what each team member has today (CRM, projects, decisions, meetings). */
    private static function sendDigests($db, string $now): void {
        require_once __DIR__ . '/MyWork.php';
        $res = $db->query("SELECT tm.entity_id, tm.user_id FROM team_members tm
            WHERE tm.is_active = 1 AND tm.user_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.type = 'crm_digest' AND n.entity_id = tm.entity_id
                              AND n.user_id = tm.user_id AND DATE(n.created_at) = CURDATE())");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $row) {
            $my = MyWork::forUser($db, (int)$row['entity_id'], (int)$row['user_id']);
            $parts = [];
            if ($my['today']) {
                $parts[] = count($my['today']) . ' کار برای امروز';
            }
            if ($my['overdue']) {
                $parts[] = count($my['overdue']) . ' کار عقب‌افتاده';
            }
            if ($my['awaiting_review']) {
                $parts[] = count($my['awaiting_review']) . ' کار منتظر تأیید شما';
            }
            if ($my['meetings_today']) {
                $parts[] = count($my['meetings_today']) . ' جلسه';
            }
            if (!empty($my['cartable'])) {
                $parts[] = count($my['cartable']) . ' مورد در کارتابل';
            }
            if (!$parts) {
                continue;
            }
            CrmNotifier::notify($db, (int)$row['entity_id'], (int)$row['user_id'], 'crm_digest', 'کارهای امروز شما',
                implode('، ', $parts) . ' دارید.', './index.php#work');
        }
    }
}
