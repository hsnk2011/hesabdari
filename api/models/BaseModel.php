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
    protected $hasEntityId = true; // Assume tables have entity_id by default
    /** @var string SQL inside EXISTS(...): use two ? placeholders for serial_id and INV-padded id LIKE */
    protected $serialSearchExistsSql = '';
    /** @var string SQL inside EXISTS(...): use two ? placeholders for product name and dimensions LIKE */
    protected $invoiceItemSearchExistsSql = '';

    public function __construct($db) {
        $this->conn = $db;
        // Only set default alias if child class hasn't defined one
        if (empty($this->alias)) {
            $this->alias = $this->tableName;
        }
        // Only set default from if child class hasn't defined one
        if (empty($this->from)) {
            $this->from = "FROM `{$this->tableName}` as `{$this->alias}`";
        }
        
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // Integrity checks moved to scheduled cron job for performance
        // See: api/core/cron_trigger.php
        // $this->ensureSharedDataIntegrity();
    }

    /**
     * Run data integrity fixes - should be called from cron job, not on every request
     * This fixes orphaned records and incorrect status values
     */
    public static function runIntegrityFixes($conn) {
        try {
            $conn->query("
                UPDATE inventory_items ii
                INNER JOIN products p ON p.id = ii.product_id
                SET ii.entity_id = p.entity_id
                WHERE (ii.entity_id IS NULL OR ii.entity_id = 0)
                  AND p.entity_id IS NOT NULL
            ");

            $conn->query("
                UPDATE payments pm
                INNER JOIN return_invoices ri ON ri.id = pm.invoiceId AND pm.invoiceType = 'return'
                SET pm.entity_id = ri.entity_id
                WHERE pm.entity_id IS NULL OR pm.entity_id = 0
            ");

            // Fix status for items returned to supplier (historical fix)
            $conn->query("
                UPDATE inventory_items 
                SET status = 'returned_to_supplier' 
                WHERE status = 'returned' 
                  AND purchase_invoice_item_id IS NOT NULL 
                  AND sales_invoice_item_id IS NULL
            ");
            
            return true;
        } catch (mysqli_sql_exception $e) {
            error_log("Integrity fix error: " . $e->getMessage());
            return false;
        }
    }

    protected function ensureSharedDataIntegrity() {
        // Deprecated: This method is now a no-op
        // Integrity checks are run via cron job to avoid performance impact
        // Call BaseModel::runIntegrityFixes($conn) from cron instead
        return;
    }

    public function delete($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }

        try {
            $entityClause = '';
            $types = 'i';
            $params = [$id];
            if ($this->hasEntityId) {
                $entityClause = ' AND entity_id = ?';
                $types .= 'i';
                $params[] = $_SESSION['current_entity_id'];
            }
            $stmt = $this->conn->prepare("DELETE FROM `{$this->tableName}` WHERE id = ?{$entityClause}");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) {
                return ['error' => 'رکورد یافت نشد یا به این مجموعه تعلق ندارد.', 'statusCode' => 404];
            }
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
        $requestedLimit = isset($input['limit']) ? intval($input['limit']) : DEFAULT_PAGE_SIZE;
        // Enforce maximum limit to prevent DoS attacks
        $maxLimit = defined('MAX_PAGE_SIZE') ? MAX_PAGE_SIZE : 500;
        // Handle -1 as "show all" by using a very large number
        if ($requestedLimit == -1) {
            // "Show all" is still bounded so one request cannot pull an entire table.
            $limit = defined('MAX_SHOW_ALL_ROWS') ? MAX_SHOW_ALL_ROWS : 5000;
        } else {
            $limit = min(max(1, $requestedLimit), $maxLimit);
        }
        $offset = ($page - 1) * $limit;

        return $this->queryList($input, $limit, $offset);
    }

    /**
     * Every row matching the list's entity/search/sort, without the MAX_PAGE_SIZE cap.
     * For lists filtered in PHP after fetching (e.g. person balance filters).
     */
    protected function getAllMatching($input) {
        return $this->queryList($input, null, 0)['data'];
    }

    /** Runs the list query; $limit null = no LIMIT. */
    protected function queryList($input, $limit, $offset) {
        $defaultSort = !empty($this->allowedSorts) ? $this->allowedSorts[0] : 'id';
        $sortBy = in_array($input['sortBy'] ?? $defaultSort, $this->allowedSorts) ? ($input['sortBy'] ?? $defaultSort) : $defaultSort;
        $sortOrderRaw = strtoupper((string)($input['sortOrder'] ?? 'ASC'));
        $sortOrder = in_array($sortOrderRaw, ['ASC', 'DESC'], true) ? $sortOrderRaw : 'ASC';
        $searchTerm = $input['searchTerm'] ?? '';
        
        $finalWhere = $this->where;
        $params = [];
        $param_types = '';
        
        if ($this->hasEntityId) {
            $aliasPrefix = $this->alias ? "`{$this->alias}`." : '';
            $finalWhere .= " AND {$aliasPrefix}`entity_id` = ?";
            $params[] = $_SESSION['current_entity_id'];
            $param_types .= 'i';
        }

        if (!empty($searchTerm) && !empty($this->allowedFilters)) {
            $search_parts = [];
            foreach ($this->allowedFilters as $col) {
                $search_parts[] = "$col LIKE ?";
            }
            if (!empty($this->serialSearchExistsSql)) {
                $search_parts[] = "EXISTS ({$this->serialSearchExistsSql})";
            }
            if (!empty($this->invoiceItemSearchExistsSql)) {
                $search_parts[] = "EXISTS ({$this->invoiceItemSearchExistsSql})";
            }
            $finalWhere .= " AND (" . implode(' OR ', $search_parts) . ")";
            
            $wildcard = "%{$searchTerm}%";
            foreach ($this->allowedFilters as $_) {
                $params[] = $wildcard;
                $param_types .= 's';
            }
            if (!empty($this->serialSearchExistsSql)) {
                $params[] = $wildcard;
                $params[] = $wildcard;
                $param_types .= 'ss';
            }
            if (!empty($this->invoiceItemSearchExistsSql)) {
                $params[] = $wildcard;
                $params[] = $wildcard;
                $param_types .= 'ss';
            }
        }
        
        $orderByClause = $this->getOrderByClause($sortBy);

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

        $data_sql = "{$this->select} {$this->from} {$this->join} {$finalWhere} {$this->groupBy} ORDER BY {$orderByClause} {$sortOrder}";
        $data_params = $params;
        $data_param_types = $param_types;
        if ($limit !== null) {
            $data_sql .= " LIMIT ? OFFSET ?";
            $data_params[] = $limit;
            $data_params[] = $offset;
            $data_param_types .= 'ii';
        }
        
        $stmt_data = $this->conn->prepare($data_sql);
        $bind_params_safely($stmt_data, $data_param_types, $data_params);
        $stmt_data->execute();
        
        $data = db_stmt_to_assoc_array($stmt_data);
        
        return ['data' => $data, 'totalRecords' => $totalRecords];
    }
    
    protected function getOrderByClause($sortBy) {
        return "`{$sortBy}`";
    }
}