<?php
// /api/controllers/LookupController.php

/**
 * Small entity-scoped master data with simple CRUD:
 *   salespeople (فروشندگان)، customer_groups (گروه مشتریان)،
 *   recurring_expenses (هزینه‌های تکراری)، budgets (بودجه هزینه).
 * Plus their reports: commission per salesperson, budget vs actual.
 */
class LookupController {
    private $conn;

    /** kind => [table, read permissions (any), write permission, fields] */
    private $kinds = [
        'salespeople' => [
            'table' => 'salespeople',
            'read' => ['manage_customers', 'manage_sales_invoices', 'view_reports'],
            'write' => 'manage_customers',
            'order' => 'is_active DESC, name ASC',
        ],
        'customer_groups' => [
            'table' => 'customer_groups',
            'read' => ['manage_customers', 'manage_sales_invoices'],
            'write' => 'manage_customers',
            'order' => 'name ASC',
        ],
        'recurring_expenses' => [
            'table' => 'recurring_expenses',
            'read' => ['manage_transactions'],
            'write' => 'manage_transactions',
            'order' => 'is_active DESC, next_date ASC',
        ],
        'budgets' => [
            'table' => 'budgets',
            'read' => ['manage_transactions', 'view_reports'],
            'write' => 'manage_transactions',
            'order' => 'period_start DESC, category ASC',
        ],
    ];

    public function __construct($db) {
        $this->conn = $db;
    }

    private function entityId() {
        return (int)($_SESSION['current_entity_id'] ?? 0);
    }

    private function kind($data) {
        $kind = $data['kind'] ?? '';
        if (!isset($this->kinds[$kind])) {
            send_json(['error' => 'نوع اطلاعات نامعتبر است.'], 400);
        }
        return $kind;
    }

    private function requireAny(array $perms) {
        $mine = $_SESSION['permissions'] ?? [];
        foreach ($perms as $p) {
            if (in_array($p, $mine, true)) {
                check_permission($p); // also enforces entity access
                return;
            }
        }
        send_json(['error' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'], 403);
    }

    public function listItems($data) {
        $kind = $this->kind($data);
        $cfg = $this->kinds[$kind];
        $this->requireAny($cfg['read']);
        $entityId = $this->entityId();
        $sql = "SELECT * FROM `{$cfg['table']}` WHERE entity_id = ? ORDER BY {$cfg['order']}";
        if ($kind === 'recurring_expenses') {
            $sql = "SELECT r.*, a.name AS account_name FROM recurring_expenses r LEFT JOIN accounts a ON a.id = r.account_id
                    WHERE r.entity_id = ? ORDER BY r.is_active DESC, r.next_date ASC";
        } elseif ($kind === 'customer_groups') {
            $sql = "SELECT g.*, (SELECT COUNT(*) FROM customers c WHERE c.group_id = g.id AND c.entity_id = g.entity_id) AS customers_count
                    FROM customer_groups g WHERE g.entity_id = ? ORDER BY g.name ASC";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        send_json(['success' => true, 'items' => db_stmt_to_assoc_array($stmt)]);
    }

    public function saveItem($data) {
        $kind = $this->kind($data);
        $cfg = $this->kinds[$kind];
        check_permission($cfg['write']);
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $entityId = $this->entityId();
        $id = (int)($item['id'] ?? 0);

        try {
            switch ($kind) {
                case 'salespeople':
                    $values = [
                        'name' => $this->requireText($item, 'name', 'نام فروشنده'),
                        'phone' => trim((string)($item['phone'] ?? '')) ?: null,
                        'commission_percent' => $this->percent($item['commission_percent'] ?? 0, 'درصد پورسانت'),
                        'is_active' => !empty($item['is_active']) || !array_key_exists('is_active', $item) ? 1 : 0,
                    ];
                    break;
                case 'customer_groups':
                    $values = [
                        'name' => $this->requireText($item, 'name', 'نام گروه'),
                        'discount_percent' => $this->percent($item['discount_percent'] ?? 0, 'درصد تخفیف'),
                    ];
                    break;
                case 'recurring_expenses':
                    $accountId = (int)($item['account_id'] ?? 0);
                    $own = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
                    $own->bind_param('ii', $accountId, $entityId);
                    $own->execute();
                    if (empty(db_stmt_to_assoc_array($own))) {
                        throw new Exception('حساب پرداخت نامعتبر است.', 400);
                    }
                    $next = normalize_input_date($item['next_date'] ?? '');
                    if (!$next) {
                        throw new Exception('تاریخ نوبت بعدی نامعتبر است.', 400);
                    }
                    $end = trim((string)($item['end_date'] ?? '')) !== '' ? normalize_input_date($item['end_date']) : null;
                    $amount = parse_money($item['amount'] ?? 0);
                    if ($amount <= 0) {
                        throw new Exception('مبلغ باید بیشتر از صفر باشد.', 400);
                    }
                    $freq = in_array($item['frequency'] ?? '', ['weekly', 'monthly', 'quarterly', 'yearly'], true) ? $item['frequency'] : 'monthly';
                    $values = [
                        'title' => $this->requireText($item, 'title', 'عنوان'),
                        'category' => $this->requireText($item, 'category', 'دسته‌بندی'),
                        'amount' => $amount,
                        'account_id' => $accountId,
                        'description' => trim((string)($item['description'] ?? '')) ?: null,
                        'frequency' => $freq,
                        'next_date' => $next,
                        'end_date' => $end,
                        'mode' => ($item['mode'] ?? '') === 'auto' ? 'auto' : 'remind',
                        'is_active' => !empty($item['is_active']) || !array_key_exists('is_active', $item) ? 1 : 0,
                    ];
                    break;
                case 'budgets':
                    $start = normalize_input_date($item['period_start'] ?? '');
                    $endB = normalize_input_date($item['period_end'] ?? '');
                    if (!$start || !$endB || $endB < $start) {
                        throw new Exception('بازه زمانی بودجه نامعتبر است.', 400);
                    }
                    $amount = parse_money($item['amount'] ?? 0);
                    if ($amount <= 0) {
                        throw new Exception('مبلغ بودجه باید بیشتر از صفر باشد.', 400);
                    }
                    $values = [
                        'category' => $this->requireText($item, 'category', 'دسته‌بندی هزینه'),
                        'period_label' => trim((string)($item['period_label'] ?? '')) ?: null,
                        'period_start' => $start,
                        'period_end' => $endB,
                        'amount' => $amount,
                    ];
                    break;
            }
        } catch (Exception $e) {
            send_json(['error' => $e->getMessage()], 400);
            return;
        }

        $cols = array_keys($values);
        $types = '';
        foreach ($values as $v) {
            $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        }
        if ($id > 0) {
            $set = implode(', ', array_map(function ($c) { return "`{$c}` = ?"; }, $cols));
            $stmt = $this->conn->prepare("UPDATE `{$cfg['table']}` SET {$set} WHERE id = ? AND entity_id = ?");
            $params = array_merge(array_values($values), [$id, $entityId]);
            $stmt->bind_param($types . 'ii', ...$params);
            $stmt->execute();
        } else {
            $colSql = '`entity_id`, `' . implode('`, `', $cols) . '`';
            $ph = implode(', ', array_fill(0, count($cols) + 1, '?'));
            $stmt = $this->conn->prepare("INSERT INTO `{$cfg['table']}` ({$colSql}) VALUES ({$ph})");
            $params = array_merge([$entityId], array_values($values));
            $stmt->bind_param('i' . $types, ...$params);
            $stmt->execute();
            $id = (int)$this->conn->insert_id;
        }
        log_activity($this->conn, 'SAVE_' . strtoupper($kind), "ذخیره {$kind} #{$id}");
        send_json(['success' => true, 'id' => $id]);
    }

    public function deleteItem($data) {
        $kind = $this->kind($data);
        $cfg = $this->kinds[$kind];
        check_permission($cfg['write']);
        $id = (int)($data['id'] ?? 0);
        $entityId = $this->entityId();
        if ($kind === 'customer_groups') {
            $u = $this->conn->prepare("UPDATE customers SET group_id = NULL WHERE group_id = ? AND entity_id = ?");
            $u->bind_param('ii', $id, $entityId);
            $u->execute();
        } elseif ($kind === 'salespeople') {
            $u = $this->conn->prepare("SELECT COUNT(*) c FROM sales_invoices WHERE salesperson_id = ? AND entity_id = ?");
            $u->bind_param('ii', $id, $entityId);
            $u->execute();
            if ((int)(db_stmt_to_assoc_array($u)[0]['c'] ?? 0) > 0) {
                send_json(['error' => 'این فروشنده در فاکتورها ثبت شده است؛ به‌جای حذف، آن را غیرفعال کنید.'], 409);
                return;
            }
        }
        $stmt = $this->conn->prepare("DELETE FROM `{$cfg['table']}` WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) {
            send_json(['error' => 'مورد یافت نشد.'], 404);
            return;
        }
        log_activity($this->conn, 'DELETE_' . strtoupper($kind), "حذف {$kind} #{$id}");
        send_json(['success' => true]);
    }

    /** Book one occurrence of a recurring expense now (or on a given date). */
    public function runRecurringExpense($data) {
        check_permission('manage_transactions');
        require_once __DIR__ . '/../models/RecurringExpense.php';
        $res = RecurringExpense::bookOccurrence($this->conn, (int)($data['id'] ?? 0), $this->entityId(), normalize_input_date($data['date'] ?? '') ?: null);
        if (!empty($res['success'])) {
            send_json($res);
        } else {
            send_json(['error' => $res['error'] ?? 'خطا'], $res['statusCode'] ?? 400);
        }
    }

    /** بودجه در برابر عملکرد: budgets overlapping the range vs actual expenses. */
    public function budgetReport($data) {
        $this->requireAny(['manage_transactions', 'view_reports']);
        $entityId = $this->entityId();
        $start = normalize_input_date($data['startDate'] ?? '') ?: date('Y-m-01');
        $end = normalize_input_date($data['endDate'] ?? '') ?: date('Y-m-t');
        $stmt = $this->conn->prepare(
            "SELECT b.*,
                    (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
                      WHERE e.entity_id = b.entity_id AND e.category = b.category
                        AND e.date BETWEEN b.period_start AND b.period_end) AS actual
             FROM budgets b
             WHERE b.entity_id = ? AND b.period_end >= ? AND b.period_start <= ?
             ORDER BY b.period_start ASC, b.category ASC"
        );
        $stmt->bind_param('iss', $entityId, $start, $end);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        $totBudget = 0; $totActual = 0;
        foreach ($rows as &$r) {
            $r['amount'] = (float)$r['amount'];
            $r['actual'] = (float)$r['actual'];
            $r['remaining'] = $r['amount'] - $r['actual'];
            $r['used_percent'] = $r['amount'] > 0 ? round($r['actual'] / $r['amount'] * 100, 1) : 0;
            $totBudget += $r['amount'];
            $totActual += $r['actual'];
        }
        unset($r);
        // Categories that had spending in range but no budget at all.
        $un = $this->conn->prepare(
            "SELECT e.category, SUM(e.amount) AS actual FROM expenses e
             WHERE e.entity_id = ? AND e.date BETWEEN ? AND ?
               AND NOT EXISTS (SELECT 1 FROM budgets b WHERE b.entity_id = e.entity_id AND b.category = e.category
                               AND e.date BETWEEN b.period_start AND b.period_end)
             GROUP BY e.category ORDER BY actual DESC"
        );
        $un->bind_param('iss', $entityId, $start, $end);
        $un->execute();
        send_json(['success' => true, 'rows' => $rows, 'unbudgeted' => db_stmt_to_assoc_array($un),
            'totals' => ['budget' => $totBudget, 'actual' => $totActual, 'remaining' => $totBudget - $totActual]]);
    }

    /**
     * گزارش فروشندگان: net sales (after discount and customer returns), amount
     * collected on those invoices and commission (percent of net sales).
     */
    public function salespersonReport($data) {
        $this->requireAny(['view_reports', 'manage_customers']);
        $entityId = $this->entityId();
        $start = normalize_input_date($data['startDate'] ?? '') ?: date('Y-m-01');
        $end = normalize_input_date($data['endDate'] ?? '') ?: date('Y-m-t');
        $stmt = $this->conn->prepare(
            "SELECT sp.id, sp.name, sp.commission_percent,
                    COUNT(si.id) AS invoice_count,
                    COALESCE(SUM(si.totalAmount - si.discount), 0) AS gross_sales,
                    COALESCE(SUM(LEAST(si.paidAmount, si.totalAmount - si.discount + COALESCE(si.tax_amount,0))), 0) AS collected,
                    COALESCE(SUM((SELECT COALESCE(SUM(rii.quantity * rii.unitPrice), 0)
                                  FROM return_invoice_items rii
                                  JOIN return_invoices ri ON ri.id = rii.return_invoice_id AND ri.type = 'sales_return'
                                  JOIN sales_invoice_items sii ON sii.id = rii.original_invoice_item_id
                                  WHERE sii.invoiceId = si.id)), 0) AS returns_amount
             FROM salespeople sp
             LEFT JOIN sales_invoices si ON si.salesperson_id = sp.id AND si.entity_id = sp.entity_id
                  AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
             WHERE sp.entity_id = ?
             GROUP BY sp.id, sp.name, sp.commission_percent
             ORDER BY gross_sales DESC"
        );
        $stmt->bind_param('ssi', $start, $end, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        foreach ($rows as &$r) {
            $r['net_sales'] = (float)$r['gross_sales'] - (float)$r['returns_amount'];
            $r['commission'] = round($r['net_sales'] * (float)$r['commission_percent'] / 100);
        }
        unset($r);
        $nos = $this->conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(totalAmount - discount), 0) s FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0 AND salesperson_id IS NULL AND date BETWEEN ? AND ?");
        $nos->bind_param('iss', $entityId, $start, $end);
        $nos->execute();
        send_json(['success' => true, 'rows' => $rows, 'unassigned' => db_stmt_to_assoc_array($nos)[0] ?? ['c' => 0, 's' => 0]]);
    }

    private function requireText(array $item, $key, $label) {
        $v = trim((string)($item[$key] ?? ''));
        if ($v === '') {
            throw new Exception("«{$label}» الزامی است.", 400);
        }
        return mb_substr($v, 0, 150);
    }

    private function percent($v, $label) {
        $n = (float)parse_money($v);
        if ($n < 0 || $n > 100) {
            throw new Exception("{$label} باید بین ۰ تا ۱۰۰ باشد.", 400);
        }
        return $n;
    }
}
