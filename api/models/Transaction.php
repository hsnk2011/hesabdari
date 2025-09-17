<?php
// /api/models/Transaction.php
require_once __DIR__ . '/BaseModel.php';

class Transaction extends BaseModel {
    protected $hasEntityId = false; // This model combines data, so manual filtering is needed.

    public function getPaginated($input) {
        $page = max(1, intval($input['currentPage'] ?? 1));
        $limit = intval($input['limit'] ?? 15);
        $offset = ($page - 1) * $limit;
        
        $allowedSorts = ['date', 'credit', 'debit'];
        $sortBy = in_array($input['sortBy'] ?? 'date', $allowedSorts) ? $input['sortBy'] : 'date';
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'DESC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'DESC';
        
        $searchTerm = $input['searchTerm'] ?? '';
        $typeFilter = $input['typeFilter'] ?? '';
        $entityId = $_SESSION['current_entity_id'];

        $params = [];
        $param_types = '';
        
        $baseWherePayments = 'WHERE (p.type = \'cash\' OR p.type = \'endorse_check\') AND p.entity_id = ?';
        $params[] = $entityId;
        $param_types .= 'i';

        $paymentsQuery = "
            SELECT 
                p.id, p.date, p.amount, p.description, 'payment' as source_table,
                a.name as accountName, p.invoiceId, p.invoiceType, p.person_id,
                p.person_type, p.transaction_type, p.type, p.account_id, p.checkId,
                NULL as category, c.status as check_status,
                CASE
                    WHEN p.invoiceId IS NOT NULL THEN CONCAT('تراکنش نقدی فاکتور ', IF(p.invoiceType='sales', 'فروش', 'خرید'), ' #', p.invoiceId)
                    WHEN p.person_type = 'customer' THEN CONCAT('تراکنش علی الحساب با مشتری: ', cust.name)
                    WHEN p.person_type = 'supplier' THEN CONCAT('تراکنش علی الحساب با تامین کننده: ', s.name)
                    WHEN p.person_type = 'partner' THEN CONCAT(IF(p.transaction_type='receipt', 'واریز شریک: ', 'برداشت شریک: '), pr.name)
                    ELSE 'تراکنش پرداخت نقدی'
                END as details,
                IF(p.transaction_type = 'receipt', p.amount, 0) as credit,
                IF(p.transaction_type = 'payment', p.amount, 0) as debit
            FROM payments p
            LEFT JOIN accounts a ON p.account_id = a.id
            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'
            LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'
            LEFT JOIN partners pr ON p.person_id = pr.id AND p.person_type = 'partner'
            LEFT JOIN checks c ON p.checkId = c.id
            {$baseWherePayments}
        ";

        $baseWhereExpenses = 'WHERE e.entity_id = ?';
        $expensesQuery = "
            SELECT 
                e.id, e.date, e.amount, e.description, 'expense' as source_table,
                a.name as accountName, NULL as invoiceId, NULL as invoiceType, NULL as person_id,
                NULL as person_type, NULL as transaction_type, NULL as type, e.account_id,
                NULL as checkId, e.category, NULL as check_status,
                CONCAT('هزینه: ', e.category) as details,
                0 as credit,
                e.amount as debit
            FROM expenses e
            LEFT JOIN accounts a ON e.account_id = a.id
            {$baseWhereExpenses}
        ";

        $baseWhereChecks = 'WHERE c.entity_id = ?';
        $cashedChecksQuery = "
            SELECT
                c.id, c.dueDate as date, c.amount, p.description, 'check' as source_table,
                a.name as accountName, c.invoiceId, c.invoiceType, p.person_id, 
                p.person_type, p.transaction_type, 
                'check' as type,
                c.cashed_in_account_id as account_id, 
                c.id as checkId, NULL as category, c.status as check_status,
                CONCAT(IF(c.type='received', 'وصول چک #', 'پاس شدن چک #'), c.checkNumber) as details,
                IF(c.type='received', c.amount, 0) as credit,
                IF(c.type='payable', c.amount, 0) as debit
            FROM checks c
            JOIN accounts a ON c.cashed_in_account_id = a.id
            LEFT JOIN payments p ON c.id = p.checkId
            {$baseWhereChecks} AND c.status = 'cashed'
        ";
        
        $unCashedChecksQuery = "
            SELECT
                c.id, c.dueDate as date, c.amount, p.description, 'check' as source_table,
                NULL as accountName, c.invoiceId, c.invoiceType, p.person_id,
                p.person_type, p.transaction_type, c.type, NULL as account_id,
                c.id as checkId, NULL as category, c.status as check_status,
                CONCAT(
                    IF(c.type='received', 'چک دریافتی #: ', 'چک پرداختی #: '),
                    c.checkNumber, ' - مبلغ: ', FORMAT(c.amount, 0),
                    IF(p.person_type = 'customer', CONCAT(' از ', cust.name), ''),
                    IF(p.person_type = 'supplier', CONCAT(' به ', s.name), ''),
                    ' (منتظر اقدام)'
                ) as details,
                0 as credit,
                0 as debit
            FROM checks c
            LEFT JOIN payments p ON c.id = p.checkId
            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'
            LEFT JOIN suppliers s ON p.person_id = s.id AND p.person_type = 'supplier'
            {$baseWhereChecks} AND c.status IN ('in_hand', 'payable')
        ";

        $queries = [];
        $union_params = [];
        $union_param_types = '';

        if (empty($typeFilter) || $typeFilter === 'payment') {
            $queries[] = $paymentsQuery;
            $queries[] = $cashedChecksQuery;
            $queries[] = $unCashedChecksQuery;
            $union_params = array_merge($union_params, [$entityId, $entityId, $entityId]);
            $union_param_types .= 'iii';
        }
        if (empty($typeFilter) || $typeFilter === 'expense') {
            $queries[] = $expensesQuery;
            $union_params = array_merge($union_params, [$entityId]);
            $union_param_types .= 'i';
        }

        if (empty($queries)) {
            return ['data' => [], 'totalRecords' => 0];
        }

        $fullQuery = implode(" UNION ALL ", $queries);

        $whereClause = ' WHERE 1';
        $search_params = [];
        $search_param_types = '';
        if (!empty($searchTerm)) {
            $whereClause .= " AND (details LIKE ? OR description LIKE ? OR accountName LIKE ? OR amount LIKE ?)";
            $wildcard = "%{$searchTerm}%";
            $search_params = [$wildcard, $wildcard, $wildcard, $wildcard];
            $search_param_types = 'ssss';
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