<?php
// /api/models/Transaction.php
require_once __DIR__ . '/BaseModel.php';

class Transaction extends BaseModel {
    protected $hasEntityId = false; // This model combines data, so manual filtering is needed.

    private function _getPaymentsQuery(&$params, &$param_types, $entityId) {
        $params[] = $entityId;
        $param_types .= 'i';
        return "\n            SELECT \n                p.id, p.date, p.amount, p.description, 'payment' as source_table,\n                a.name as accountName, p.invoiceId, p.invoiceType, p.person_id,\n                p.person_type, p.transaction_type, p.type, p.account_id, p.checkId,\n                NULL as category, c.status as check_status,\n                p.type as payment_type,\n                c.checkNumber, c.dueDate as check_dueDate, c.bankName,\n                CASE\n                    WHEN p.person_type = 'other' AND p.transaction_type = 'receipt' THEN CONCAT('درآمد متفرقه: ', IFNULL(p.description,''))\n                    WHEN p.person_type = 'other' AND p.transaction_type = 'payment' THEN CONCAT('پرداخت متفرقه: ', IFNULL(p.description,''))\n                    WHEN p.person_type = 'other' THEN CONCAT('تراکنش متفرقه: ', IFNULL(p.description,''))\n                    WHEN p.type = 'setoff' AND p.invoiceId IS NOT NULL THEN CONCAT('تهاتر / پرداخت به جای — فاکتور ', IF(p.invoiceType='sales', 'فروش', IF(p.invoiceType='purchase', 'خرید', 'سایر')), ' #', p.invoiceId)
                    WHEN p.type = 'setoff' THEN CONCAT('تهاتر / پرداخت به جای: ', IFNULL(p.description,''))
                    WHEN p.type = 'check_bounce' THEN CONCAT('برگشت/عودت چک #', IFNULL(c.checkNumber,''), ' - ', IFNULL(p.description,''))
                    WHEN p.person_id IS NULL AND p.invoiceId IS NULL THEN CONCAT('انتقال وجه: ', REPLACE(SUBSTRING_INDEX(p.description, '|', -1), '\n', ''))\n                    WHEN p.type = 'check' AND p.invoiceId IS NOT NULL THEN CONCAT('چک دریافتی #', c.checkNumber, ' - سررسید: ', c.dueDate, ' - بانک: ', c.bankName, ' - فاکتور ', IF(p.invoiceType='sales', 'فروش', IF(p.invoiceType='purchase', 'خرید', IF(p.invoiceType='asset_purchase', 'خرید دارایی', IF(p.invoiceType='asset_sale', 'فروش دارایی', 'مرجوعی')))), ' #', p.invoiceId)\n                    WHEN p.type = 'endorse_check' AND p.invoiceId IS NOT NULL THEN CONCAT('خرج چک دریافتی #', c.checkNumber, ' - سررسید: ', c.dueDate, ' - بانک: ', c.bankName, ' - فاکتور ', IF(p.invoiceType='sales', 'فروش', IF(p.invoiceType='purchase', 'خرید', IF(p.invoiceType='asset_purchase', 'خرید دارایی', IF(p.invoiceType='asset_sale', 'فروش دارایی', 'مرجوعی')))), ' #', p.invoiceId)\n                    WHEN p.type = 'pos' AND p.invoiceId IS NOT NULL THEN CONCAT('دستگاه پوز - فاکتور ', IF(p.invoiceType='sales', 'فروش', IF(p.invoiceType='purchase', 'خرید', IF(p.invoiceType='asset_purchase', 'خرید دارایی', IF(p.invoiceType='asset_sale', 'فروش دارایی', 'مرجوعی')))), ' #', p.invoiceId)\n                    WHEN p.invoiceId IS NOT NULL THEN CONCAT('تراکنش نقدی فاکتور ', IF(p.invoiceType='sales', 'فروش', IF(p.invoiceType='purchase', 'خرید', IF(p.invoiceType='asset_purchase', 'خرید دارایی', IF(p.invoiceType='asset_sale', 'فروش دارایی', 'مرجوعی')))), ' #', p.invoiceId)\n                    WHEN p.type = 'endorse_check' AND p.person_type = 'customer' THEN CONCAT('خرج چک دریافتی به مشتری: ', cust.name, ' (چک #', c.checkNumber, ')')\n                    WHEN p.type = 'endorse_check' AND p.person_type = 'supplier' THEN CONCAT('خرج چک دریافتی به تامین کننده: ', s.name, ' (چک #', c.checkNumber, ')')\n                    WHEN p.type = 'endorse_check' AND p.person_type = 'partner' THEN CONCAT('خرج چک دریافتی به شریک: ', pr.name, ' (چک #', c.checkNumber, ')')\n                    WHEN p.type = 'endorse_check' AND p.person_type = 'contact' THEN CONCAT('خرج چک دریافتی به سایر شخص: ', ct.name, ' (چک #', c.checkNumber, ')')\n                    WHEN p.type = 'endorse_check' THEN CONCAT('خرج چک دریافتی #', c.checkNumber)\n                    WHEN p.person_type = 'customer' THEN CONCAT('تراکنش علی الحساب با مشتری: ', cust.name)\n                    WHEN p.person_type = 'supplier' THEN CONCAT('تراکنش علی الحساب با تامین کننده: ', s.name)\n                    WHEN p.person_type = 'partner' THEN CONCAT(IF(p.transaction_type='receipt', 'واریز شریک: ', 'برداشت شریک: '), pr.name)\n                    WHEN p.person_type = 'contact' THEN CONCAT('تراکنش علی الحساب با سایر شخص: ', ct.name)\n                    ELSE 'تراکنش پرداخت نقدی'\n                END as details,\n                IF(p.person_type = 'other', 'payment', IF(p.person_id IS NULL AND p.invoiceId IS NULL, 'transfer', 'payment')) as transaction_class,\n                IF(p.type IN ('check','endorse_check','check_bounce'), 0, IF(p.transaction_type = 'receipt', p.amount, 0)) as credit,\n                IF(p.type IN ('check','endorse_check','check_bounce'), 0, IF(p.transaction_type = 'payment', p.amount, 0)) as debit\n            FROM payments p\n            LEFT JOIN accounts a ON p.account_id = a.id\n            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'\n            LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'\n            LEFT JOIN partners pr ON p.person_id = pr.id AND p.person_type = 'partner'\n            LEFT JOIN contacts ct ON p.person_id = ct.id AND p.person_type = 'contact'\n            LEFT JOIN checks c ON p.checkId = c.id\n            WHERE (p.type = 'cash' OR p.type = 'check' OR p.type = 'endorse_check' OR p.type = 'pos' OR p.type = 'check_bounce' OR p.type = 'setoff') AND p.entity_id = ?\n        ";
    }

    private function _getExpensesQuery(&$params, &$param_types, $entityId) {
        $params[] = $entityId;
        $param_types .= 'i';
        return "\n            SELECT \n                e.id, e.date, e.amount, e.description, 'expense' as source_table,\n                a.name as accountName, NULL as invoiceId, NULL as invoiceType, e.person_id,\n                e.person_type, NULL as transaction_type, NULL as type, e.account_id,\n                NULL as checkId, e.category, NULL as check_status,\n                NULL as payment_type,\n                NULL as checkNumber, NULL as check_dueDate, NULL as bankName,\n                CONCAT('هزینه: ', e.category,\n                    IF(e.person_type = 'customer' AND cust.name IS NOT NULL, CONCAT(' — مشتری: ', cust.name), ''),\n                    IF(e.person_type = 'supplier' AND s.name IS NOT NULL, CONCAT(' — تامین‌کننده: ', s.name), ''),\n                    IF(e.person_type = 'partner' AND pr.name IS NOT NULL, CONCAT(' — شریک: ', pr.name), ''),\n                    IF(e.person_type = 'contact' AND ct.name IS NOT NULL, CONCAT(' — سایر شخص: ', ct.name), '')\n                ) as details,\n                'expense' as transaction_class,\n                0 as credit,\n                e.amount as debit\n            FROM expenses e\n            LEFT JOIN accounts a ON e.account_id = a.id\n            LEFT JOIN customers cust ON e.person_id = cust.id AND e.person_type = 'customer'\n            LEFT JOIN suppliers s ON e.person_id = s.id AND e.person_type = 'supplier'\n            LEFT JOIN partners pr ON e.person_id = pr.id AND e.person_type = 'partner'\n            LEFT JOIN contacts ct ON e.person_id = ct.id AND e.person_type = 'contact'\n            WHERE e.entity_id = ?\n        ";
    }

    private function _getIncomesQuery(&$params, &$param_types, $entityId) {
        $params[] = $entityId;
        $param_types .= 'i';
        return "\n            SELECT \n                i.id, i.date, i.amount, i.description, 'income' as source_table,\n                a.name as accountName, NULL as invoiceId, NULL as invoiceType, i.person_id,\n                i.person_type, NULL as transaction_type, NULL as type, i.account_id,\n                NULL as checkId, i.category, NULL as check_status,\n                NULL as payment_type,\n                NULL as checkNumber, NULL as check_dueDate, NULL as bankName,\n                CONCAT('درآمد: ', i.category,\n                    IF(i.person_type = 'customer' AND cust.name IS NOT NULL, CONCAT(' — مشتری: ', cust.name), ''),\n                    IF(i.person_type = 'supplier' AND s.name IS NOT NULL, CONCAT(' — تامین‌کننده: ', s.name), ''),\n                    IF(i.person_type = 'partner' AND pr.name IS NOT NULL, CONCAT(' — شریک: ', pr.name), ''),\n                    IF(i.person_type = 'contact' AND ct.name IS NOT NULL, CONCAT(' — سایر شخص: ', ct.name), '')\n                ) as details,\n                'income' as transaction_class,\n                i.amount as credit,\n                0 as debit\n            FROM incomes i\n            LEFT JOIN accounts a ON i.account_id = a.id\n            LEFT JOIN customers cust ON i.person_id = cust.id AND i.person_type = 'customer'\n            LEFT JOIN suppliers s ON i.person_id = s.id AND i.person_type = 'supplier'\n            LEFT JOIN partners pr ON i.person_id = pr.id AND i.person_type = 'partner'\n            LEFT JOIN contacts ct ON i.person_id = ct.id AND i.person_type = 'contact'\n            WHERE i.entity_id = ?\n        ";
    }

    private function _getCashedChecksQuery(&$params, &$param_types, $entityId) {
        $params[] = $entityId;
        $param_types .= 'i';
        // FIX: Only join with original payment (check/payable_check), not endorse_check payments
        return "
            SELECT
                c.id, COALESCE(c.cashed_date, c.dueDate) as date, c.amount, p.description, 'check' as source_table,
                a.name as accountName, c.invoiceId, c.invoiceType, p.person_id, 
                p.person_type, p.transaction_type, 
                'check' as type,
                c.cashed_in_account_id as account_id, 
                c.id as checkId, NULL as category, c.status as check_status,
                'check' as payment_type,
                c.checkNumber, c.dueDate as check_dueDate, c.bankName,
                CONCAT(IF(c.type='received', 'وصول چک #', 'پاس شدن چک #'), c.checkNumber) as details,
                'check' as transaction_class,
                IF(c.type='received', c.amount, 0) as credit,
                IF(c.type='payable', c.amount, 0) as debit
            FROM checks c
            JOIN accounts a ON c.cashed_in_account_id = a.id
            LEFT JOIN payments p ON c.id = p.checkId AND p.type IN ('check', 'payable_check')
            WHERE c.entity_id = ? AND c.status = 'cashed'
        ";
    }

    private function _getUncashedChecksQuery(&$params, &$param_types, $entityId) {
        $params[] = $entityId;
        $param_types .= 'i';
        // FIX: Only join with original payment (check/payable_check), not endorse_check payments
        // Also exclude endorsed checks - they should only appear in payment transactions as 'endorse_check'
        return "
            SELECT
                c.id, c.dueDate as date, c.amount, p.description, 'check' as source_table,
                NULL as accountName, c.invoiceId, c.invoiceType, p.person_id,
                p.person_type, p.transaction_type, c.type, NULL as account_id,
                c.id as checkId, NULL as category, c.status as check_status,
                'check' as payment_type,
                c.checkNumber, c.dueDate as check_dueDate, c.bankName,
                CONCAT(
                    IF(c.type='received', 'چک دریافتی #: ', 'چک پرداختی #: '),
                    c.checkNumber, ' - مبلغ: ', FORMAT(c.amount, 0),
                    IF(p.person_type = 'customer', CONCAT(' از ', cust.name), ''),
                    IF(p.person_type = 'supplier', CONCAT(' به ', s.name), ''),
                    ' (منتظر اقدام)'
                ) as details,
                'check' as transaction_class,
                0 as credit,
                0 as debit
            FROM checks c
            LEFT JOIN payments p ON c.id = p.checkId AND p.type IN ('check', 'payable_check')
            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'
            LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'
            WHERE c.entity_id = ? AND c.status IN ('in_hand', 'payable')
        ";
    }

    /** Returns a valid YYYY-MM-DD string, or null when empty/invalid. */
    private function _normalizeDate($value) {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : null;
    }

    public function getPaginated($input) {
        $page = max(1, intval($input['currentPage'] ?? 1));
        $limit = intval($input['limit'] ?? 15);
        $offset = ($page - 1) * $limit;
        
        $allowedSorts = ['date', 'credit', 'debit'];
        $sortBy = in_array($input['sortBy'] ?? 'date', $allowedSorts) ? $input['sortBy'] : 'date';
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'DESC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'DESC';
        
        $searchTerm = $input['searchTerm'] ?? '';
        $typeFilter = $input['typeFilter'] ?? '';
        $accountFilter = intval($input['accountFilter'] ?? 0);
        $includeUncashedChecks = filter_var($input['includeUncashedChecks'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $entityId = $_SESSION['current_entity_id'];

        $union_params = [];
        $union_param_types = '';
        $queries = [];

        if (empty($typeFilter) || $typeFilter === 'payment') {
            $queries[] = $this->_getPaymentsQuery($union_params, $union_param_types, $entityId);
            $queries[] = $this->_getCashedChecksQuery($union_params, $union_param_types, $entityId);
            if ($includeUncashedChecks) {
                $queries[] = $this->_getUncashedChecksQuery($union_params, $union_param_types, $entityId);
            }
        }
        if (empty($typeFilter) || $typeFilter === 'expense') {
            $queries[] = $this->_getExpensesQuery($union_params, $union_param_types, $entityId);
        }
        if (empty($typeFilter) || $typeFilter === 'income') {
            $queries[] = $this->_getIncomesQuery($union_params, $union_param_types, $entityId);
        }

        if (empty($queries)) {
            return ['data' => [], 'totalRecords' => 0];
        }

        $fullQuery = implode(" UNION ALL ", $queries);

        $whereClause = ' WHERE 1';
        $search_params = [];
        $search_param_types = '';
        if ($accountFilter > 0) {
            $whereClause .= " AND account_id = ?";
            $search_params[] = $accountFilter;
            $search_param_types .= 'i';
        }
        $dateFrom = $this->_normalizeDate($input['dateFrom'] ?? '');
        $dateTo = $this->_normalizeDate($input['dateTo'] ?? '');
        if ($dateFrom !== null) {
            $whereClause .= " AND DATE(date) >= ?";
            $search_params[] = $dateFrom;
            $search_param_types .= 's';
        }
        if ($dateTo !== null) {
            $whereClause .= " AND DATE(date) <= ?";
            $search_params[] = $dateTo;
            $search_param_types .= 's';
        }
        if (!empty($searchTerm)) {
            $whereClause .= " AND (details LIKE ? OR description LIKE ? OR accountName LIKE ? OR amount LIKE ?)";
            $wildcard = "%{$searchTerm}%";
            $search_params = array_merge($search_params, [$wildcard, $wildcard, $wildcard, $wildcard]);
            $search_param_types .= 'ssss';
        }
        
        $final_params = array_merge($union_params, $search_params);
        $final_param_types = $union_param_types . $search_param_types;

        $countQuery = "SELECT COUNT(*) FROM ({$fullQuery}) as union_table {$whereClause}";
        $stmt_count = $this->conn->prepare($countQuery);
        if(!empty($final_params)) $stmt_count->bind_param($final_param_types, ...$final_params);
        $stmt_count->execute();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        $orderBy = "ORDER BY {$sortBy} {$sortOrder}, id {$sortOrder}";
        $limitClause = "LIMIT ? OFFSET ?";
        
        $dataQuery = "SELECT * FROM ({$fullQuery}) as union_table {$whereClause} {$orderBy} {$limitClause}";
        
        $data_params = $final_params;
        $data_params[] = $limit;
        $data_params[] = $offset;
        $data_param_types = $final_param_types . 'ii';

        $stmt_data = $this->conn->prepare($dataQuery);
        if(!empty($data_params)) {
             $refs = [];
             foreach ($data_params as $key => $value) $refs[$key] = &$data_params[$key];
             call_user_func_array([$stmt_data, 'bind_param'], array_merge([$data_param_types], $refs));
        }
        $stmt_data->execute();
        $data = db_stmt_to_assoc_array($stmt_data);

        return ['data' => $data, 'totalRecords' => $totalRecords];
    }
}
