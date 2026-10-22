<?php
// /api/models/RecurringExpense.php
require_once __DIR__ . '/Expense.php';

/**
 * هزینه‌های تکراری (اجاره، قبوض، حقوق ...).
 * mode=auto   → the scheduler books the expense on its due date.
 * mode=remind → the scheduler only creates a reminder notification; the user
 *               books it with «ثبت هزینه» in the recurring list.
 */
class RecurringExpense {
    /**
     * Next occurrence. Months are counted in the Jalali calendar (rent and
     * bills fall on «اول هر ماه»), clamping the day to the month length.
     */
    public static function advanceDate($date, $frequency) {
        if ($frequency === 'weekly') {
            return date('Y-m-d', strtotime($date . ' +1 week'));
        }
        $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$frequency] ?? 1;
        list($jy, $jm, $jd) = array_map('intval', explode('/', gregorian_to_jalali($date)));
        $jm += $months;
        while ($jm > 12) {
            $jm -= 12;
            $jy++;
        }
        $len = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : 29);
        if ($jm === 12) {
            $leapProbe = jalali_to_gregorian($jy, 12, 30);
            if ($leapProbe && gregorian_to_jalali($leapProbe) === sprintf('%04d/12/30', $jy)) {
                $len = 30;
            }
        }
        return jalali_to_gregorian($jy, $jm, min($jd, $len));
    }

    /**
     * Book one occurrence as a real expense (wallet + period lock via Expense).
     * Advances next_date when the booked date is the scheduled one.
     */
    public static function bookOccurrence(mysqli $db, $id, $entityId, $date = null) {
        $stmt = $db->prepare("SELECT * FROM recurring_expenses WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$row) {
            return ['error' => 'هزینه تکراری یافت نشد.', 'statusCode' => 404];
        }
        $bookDate = $date ?: $row['next_date'];
        $expense = new Expense($db);
        $res = $expense->save([
            'category' => $row['category'],
            'date' => $bookDate,
            'amount' => (float)$row['amount'],
            'description' => trim($row['title'] . ($row['description'] ? ' - ' . $row['description'] : '') . ' (هزینه تکراری)'),
            'account_id' => (int)$row['account_id'],
            'person_type' => $row['person_type'] ?? '',
            'person_id' => $row['person_id'] ?? 0,
        ]);
        if (empty($res['success'])) {
            return ['error' => $res['error'] ?? 'ثبت هزینه ناموفق بود.', 'statusCode' => $res['statusCode'] ?? 400];
        }
        if ($bookDate >= $row['next_date']) {
            $next = self::advanceDate($row['next_date'], $row['frequency']);
            $active = ($row['end_date'] && $next > $row['end_date']) ? 0 : 1;
            $u = $db->prepare("UPDATE recurring_expenses SET next_date = ?, last_run_date = ?, is_active = ? WHERE id = ?");
            $u->bind_param('ssii', $next, $bookDate, $active, $id);
            $u->execute();
        }
        return ['success' => true, 'expense_id' => $res['id'], 'message' => 'هزینه ثبت شد.'];
    }

    /** Scheduler job: process every due row of every entity. */
    public static function runDue(mysqli $db) {
        require_once __DIR__ . '/../core/Scheduler.php';
        $today = date('Y-m-d');
        $res = $db->query(
            "SELECT * FROM recurring_expenses
             WHERE is_active = 1 AND next_date <= CURDATE()
               AND (end_date IS NULL OR next_date <= end_date)
             ORDER BY entity_id, next_date LIMIT 200"
        );
        $booked = 0; $reminded = 0; $errors = 0;
        while ($res && ($row = $res->fetch_assoc())) {
            $entityId = (int)$row['entity_id'];
            if ($row['mode'] === 'auto') {
                // Catch up missed occurrences (at most a year of monthly ones).
                $guard = 0;
                $current = $row;
                while ($current && $current['next_date'] <= $today && $guard++ < 12) {
                    $r = Scheduler::withEntity($entityId, function () use ($db, $current, $entityId) {
                        return self::bookOccurrence($db, (int)$current['id'], $entityId);
                    });
                    if (empty($r['success'])) {
                        $errors++;
                        self::notify($db, $entityId, $current, 'ثبت خودکار هزینه تکراری «' . $current['title'] . '» ناموفق بود: ' . ($r['error'] ?? ''));
                        break;
                    }
                    $booked++;
                    $st = $db->prepare("SELECT * FROM recurring_expenses WHERE id = ?");
                    $cid = (int)$current['id'];
                    $st->bind_param('i', $cid);
                    $st->execute();
                    $current = db_stmt_to_assoc_array($st)[0] ?? null;
                    if ($current && (!(int)$current['is_active'])) {
                        break;
                    }
                }
            } else {
                // Reminder once per due date.
                if ($row['last_run_date'] !== $row['next_date']) {
                    self::notify($db, $entityId, $row, 'سررسید هزینه تکراری «' . $row['title'] . '» به مبلغ ' . number_format((float)$row['amount']) . ' — از بخش ابزارها ثبت کنید.');
                    $u = $db->prepare("UPDATE recurring_expenses SET last_run_date = next_date WHERE id = ?");
                    $rid = (int)$row['id'];
                    $u->bind_param('i', $rid);
                    $u->execute();
                    $reminded++;
                }
            }
        }
        return ['booked' => $booked, 'reminded' => $reminded, 'errors' => $errors];
    }

    private static function notify(mysqli $db, $entityId, array $row, $message) {
        $type = 'recurring_expense_due';
        $title = 'هزینه تکراری';
        $refType = 'recurring_expense';
        $refId = (int)$row['id'];
        $due = $row['next_date'];
        $n = $db->prepare("INSERT INTO notifications (entity_id, type, title, message, reference_type, reference_id, due_date, is_read, is_dismissed) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)");
        $n->bind_param('issssis', $entityId, $type, $title, $message, $refType, $refId, $due);
        $n->execute();
    }
}
