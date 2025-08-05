<?php
// /api/models/BaseModel.php

class BaseModel {
    protected $conn;
    protected $tableName;
    protected $allowedFilters = [];
    protected $allowedSorts = [];

    public function __construct($db) {
        $this->conn = $db;
        // Set mysqli to throw exceptions on error
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
            // Error code 1451 is for foreign key constraint failure
            if ($e->getCode() == 1451) {
                return ['error' => 'این مورد قابل حذف نیست زیرا در بخش دیگری (مانند فاکتورها) استفاده شده است.', 'statusCode' => 409];
            }
            // For other database errors
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    // متد getPaginated بدون تغییر باقی می‌ماند
    public function getPaginated($input) {
        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;

        $defaultSort = !empty($this->allowedSorts) ? $this->allowedSorts[0] : 'id';
        $sortBy = in_array($input['sortBy'] ?? $defaultSort, $this->allowedSorts) ? $input['sortBy'] : $defaultSort;
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        
        $searchTerm = $input['searchTerm'] ?? '';

        $select = "SELECT *";
        $from = "FROM `{$this->tableName}`";
        $where = "WHERE 1";
        
        $params = [];
        $param_types = '';

        if (!empty($searchTerm) && !empty($this->allowedFilters)) {
            $search_parts = [];
            foreach ($this->allowedFilters as $col) {
                $search_parts[] = "`$col` LIKE ?";
            }
            $where .= " AND (" . implode(' OR ', $search_parts) . ")";
            
            $wildcard = "%{$searchTerm}%";
            foreach ($this->allowedFilters as $_) {
                $params[] = $wildcard;
                $param_types .= 's';
            }
        }
        
        $count_sql = "SELECT COUNT(*) as total $from $where";
        $stmt_count = $this->conn->prepare($count_sql);
        if ($stmt_count === false) throw new Exception("Prepare failed (count): {$this->conn->error}");
        if (!empty($params)) $stmt_count->bind_param($param_types, ...$params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        $data_sql = "$select $from $where ORDER BY `$sortBy` $sortOrder LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $param_types .= 'ii';
        
        $stmt_data = $this->conn->prepare($data_sql);
        if ($stmt_data === false) throw new Exception("Prepare failed (data): {$this->conn->error}");
        $stmt_data->bind_param($param_types, ...$params);
        $stmt_data->execute();
        $stmt_data->store_result();
        
        $meta = $stmt_data->result_metadata();
        $fields = [];
        $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt_data, 'bind_result'], $fields);
        
        $data = [];
        while ($stmt_data->fetch()) {
            $c = [];
            foreach($row as $key => $val) {
                $c[$key] = $val;
            }
            $data[] = $c;
        }
        
        $stmt_data->close();
        
        return ['data' => $data, 'totalRecords' => $totalRecords];
    }
}