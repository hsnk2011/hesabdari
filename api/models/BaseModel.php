<?php
// /api/models/BaseModel.php

class BaseModel {
    protected $conn;
    protected $tableName;

    // Properties for building complex paginated queries, can be overridden by child classes
    protected $select = 'SELECT *';
    protected $from = '';
    protected $join = '';
    protected $where = 'WHERE 1';
    protected $groupBy = '';
    protected $allowedFilters = [];
    protected $allowedSorts = [];
    protected $alias = ''; // Table alias for sorting

    public function __construct($db) {
        $this->conn = $db;
        $this->alias = $this->tableName; // Default alias is the table name itself
        $this->from = "FROM `{$this->tableName}` as {$this->alias}";
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    public function delete($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }

        try {
            $stmt = $this->conn->prepare("DELETE FROM `{$this->tableName}` WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            return ['success' => true];

        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1451) {
                return ['error' => 'این مورد قابل حذف نیست زیرا در بخش دیگری (مانند فاکتورها) استفاده شده است.', 'statusCode' => 409];
            }
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function getPaginated($input) {
        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;

        $defaultSort = !empty($this->allowedSorts) ? $this->allowedSorts[0] : 'id';
        $sortBy = in_array($input['sortBy'] ?? $defaultSort, $this->allowedSorts) ? ($input['sortBy'] ?? $defaultSort) : $defaultSort;
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        $searchTerm = $input['searchTerm'] ?? '';
        
        $finalWhere = $this->where;
        $params = [];
        $param_types = '';

        if (!empty($searchTerm) && !empty($this->allowedFilters)) {
            $search_parts = [];
            foreach ($this->allowedFilters as $col) {
                $search_parts[] = "$col LIKE ?";
            }
            $finalWhere .= " AND (" . implode(' OR ', $search_parts) . ")";
            
            $wildcard = "%{$searchTerm}%";
            foreach ($this->allowedFilters as $_) {
                $params[] = $wildcard;
                $param_types .= 's';
            }
        }
        
        $orderByClause = $this->getOrderByClause($sortBy);

        // Safely bind parameters
        $bind_params_safely = function($stmt, $types, &$params) {
            if (!empty($params)) {
                $refs = [];
                foreach ($params as $key => $value) $refs[$key] = &$params[$key];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            }
        };

        $count_sql = "SELECT COUNT(*) as total {$this->from} {$this->join} {$finalWhere} {$this->groupBy}";
        $stmt_count = $this->conn->prepare($count_sql);
        $bind_params_safely($stmt_count, $param_types, $params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        $data_sql = "{$this->select} {$this->from} {$this->join} {$finalWhere} {$this->groupBy} ORDER BY {$orderByClause} {$sortOrder} LIMIT ? OFFSET ?";
        $data_params = $params;
        $data_params[] = $limit;
        $data_params[] = $offset;
        $data_param_types = $param_types . 'ii';
        
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
    
    // Can be overridden by child classes for complex sorting
    protected function getOrderByClause($sortBy) {
        return "`{$sortBy}`";
    }
}