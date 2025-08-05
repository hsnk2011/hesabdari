<?php
// /api/models/PartnerTransaction.php
require_once __DIR__ . '/BaseModel.php';

class PartnerTransaction extends BaseModel {
    protected $tableName = 'partner_transactions';
    protected $allowedSorts = ['id', 'date', 'partnerName', 'amount'];

    public function getPaginated($input) {
        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;

        $sortBy = in_array($input['sortBy'] ?? 'id', $this->allowedSorts) ? $input['sortBy'] : 'id';
        if ($sortBy === 'partnerName') $orderByClause = 'p.name';
        else $orderByClause = 'pt.' . $sortBy;
        
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        $searchTerm = $input['searchTerm'] ?? '';

        $select = "SELECT pt.*, p.name as partnerName, a.name as accountName";
        $from = "FROM `{$this->tableName}` pt 
                 JOIN partners p ON pt.partnerId = p.id 
                 LEFT JOIN accounts a ON pt.account_id = a.id";
        $where = "WHERE 1";
        
        $search_params = [];
        $search_param_types = '';
        $allowedFilters = ['p.name', 'pt.type', 'pt.amount', 'a.name'];

        if (!empty($searchTerm) && !empty($allowedFilters)) {
            $search_parts = [];
            foreach ($allowedFilters as $col) $search_parts[] = "$col LIKE ?";
            $where .= " AND (" . implode(' OR ', $search_parts) . ")";
            $wildcard = "%{$searchTerm}%";
            foreach ($allowedFilters as $_) {
                $search_params[] = $wildcard;
                $search_param_types .= 's';
            }
        }
        
        $bind_params_safely = function($stmt, $types, &$params) {
            if (!empty($params)) {
                $refs = [];
                foreach ($params as $key => $value) $refs[$key] = &$params[$key];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            }
        };

        // Count
        $count_sql = "SELECT COUNT(*) as total $from $where";
        $stmt_count = $this->conn->prepare($count_sql);
        $bind_params_safely($stmt_count, $search_param_types, $search_params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        // Data
        $data_sql = "$select $from $where ORDER BY {$orderByClause} {$sortOrder} LIMIT ? OFFSET ?";
        $data_params = $search_params;
        $data_params[] = $limit;
        $data_params[] = $offset;
        $data_param_types = $search_param_types . 'ii';
        
        $stmt_data = $this->conn->prepare($data_sql);
        $bind_params_safely($stmt_data, $data_param_types, $data_params);
        $stmt_data->execute();
        
        $stmt_data->store_result();
        $meta = $stmt_data->result_metadata();
        $fields = []; $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt_data, 'bind_result'], $fields);
        
        $data = [];
        while ($stmt_data->fetch()) {
            $c = [];
            foreach($row as $key => $val) $c[$key] = $val;
            $data[] = $c;
        }
        $stmt_data->close();
        
        return ['data' => $data, 'totalRecords' => $totalRecords];
    }
}