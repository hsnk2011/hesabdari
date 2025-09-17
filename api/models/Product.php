<?php
// /api/models/Product.php
require_once __DIR__ . '/BaseModel.php';

class Product extends BaseModel {
    protected $tableName = 'products';
    protected $allowedFilters = ['p.name', 'p.description']; // Aliased for new query
    protected $allowedSorts = ['id', 'name', 'total_stock'];

    public function __construct($db) {
        parent::__construct($db);
        $this->alias = 'p'; // Set alias for the products table
    }

    public function getPaginated($input) {
        // --- CUSTOM PAGINATION LOGIC FOR PRODUCTS TO SORT BY STOCK ---

        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;

        // Default sort: by stock quantity descending, then by name ascending
        $sortBy = $input['sortBy'] ?? 'total_stock';
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'DESC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'DESC';
        $searchTerm = $input['searchTerm'] ?? '';
        
        // Build the base query with a JOIN to get total stock for sorting
        $this->select = "SELECT p.*, COALESCE(s.total_quantity, 0) as total_stock";
        $this->from = "FROM `{$this->tableName}` as p";
        $this->join = "LEFT JOIN (SELECT product_id, SUM(quantity) as total_quantity FROM product_stock GROUP BY product_id) s ON p.id = s.product_id";
        
        $finalWhere = "WHERE p.entity_id = ?";
        $params = [$_SESSION['current_entity_id']];
        $param_types = 'i';

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

        // Custom Order By Logic
        $orderByClause = "ORDER BY total_stock DESC, p.name ASC"; // Default sort
        if (in_array($sortBy, $this->allowedSorts)) {
            if ($sortBy === 'total_stock') {
                 $orderByClause = "ORDER BY total_stock {$sortOrder}, p.name ASC";
            } else {
                 $orderByClause = "ORDER BY p.{$sortBy} {$sortOrder}";
            }
        }
        
        // Get total records count with the same filters
        $count_sql = "SELECT COUNT(p.id) as total {$this->from} {$this->join} {$finalWhere}";
        $stmt_count = $this->conn->prepare($count_sql);
        if (!empty($params)) {
            $stmt_count->bind_param($param_types, ...$params);
        }
        $stmt_count->execute();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();

        // Get paginated data
        $data_sql = "{$this->select} {$this->from} {$this->join} {$finalWhere} {$orderByClause} LIMIT ? OFFSET ?";
        $data_params = $params;
        $data_params[] = $limit;
        $data_params[] = $offset;
        $data_param_types = $param_types . 'ii';
        
        $stmt_data = $this->conn->prepare($data_sql);
        if (!empty($data_params)) {
             $refs = [];
             foreach ($data_params as $key => $value) $refs[$key] = &$data_params[$key];
             call_user_func_array([$stmt_data, 'bind_param'], array_merge([$data_param_types], $refs));
        }
        $stmt_data->execute();
        $products = db_stmt_to_assoc_array($stmt_data);
        
        $paginatedResult = ['data' => $products, 'totalRecords' => $totalRecords];

        // --- ATTACH DETAILED STOCK INFORMATION (as before) ---
        if (empty($products)) {
            return $paginatedResult;
        }

        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $stmt_stock_details = $this->conn->prepare("SELECT * FROM `product_stock` WHERE product_id IN ($placeholders)");
        $stmt_stock_details->bind_param($types, ...$productIds);
        $stmt_stock_details->execute();
        $all_stock = db_stmt_to_assoc_array($stmt_stock_details);

        foreach ($products as &$product) {
            $product['stock'] = [];
            foreach ($all_stock as $stock_item) {
                if ($stock_item['product_id'] == $product['id']) {
                    $product['stock'][] = $stock_item;
                }
            }
        }
        
        $paginatedResult['data'] = $products;
        return $paginatedResult;
    }

    public function save($data) {
        if (empty(trim($data['name']))) {
            return ['error' => 'نام طرح محصول الزامی است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();

        try {
            $productId = $data['id'] ?? null;
            $name = $data['name'];
            $description = $data['description'] ?? '';

            if ($productId) {
                $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $description, $productId);
            } else {
                $entity_id = $_SESSION['current_entity_id'];
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, description) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $entity_id, $name, $description);
            }
            
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            
            if (!$productId) {
                $productId = $this->conn->insert_id;
            }
            $stmt->close();

            $stmt_delete_stock = $this->conn->prepare("DELETE FROM product_stock WHERE product_id = ?");
            $stmt_delete_stock->bind_param("i", $productId);
            if (!$stmt_delete_stock->execute()) {
                throw new Exception($stmt_delete_stock->error);
            }
            $stmt_delete_stock->close();

            if (isset($data['stock']) && is_array($data['stock'])) {
                $stmt_stock = $this->conn->prepare("INSERT INTO product_stock (product_id, dimensions, quantity) VALUES (?, ?, ?)");
                
                foreach ($data['stock'] as $stock_item) {
                    if (!empty($stock_item['dimensions']) && isset($stock_item['quantity'])) {
                        $dimensions = $stock_item['dimensions'];
                        $quantity = intval($stock_item['quantity']);
                        $stmt_stock->bind_param("isi", $productId, $dimensions, $quantity);
                        if (!$stmt_stock->execute()) {
                            throw new Exception($stmt_stock->error);
                        }
                    }
                }
                $stmt_stock->close();
            }

            $this->conn->commit();
            
            return ['success' => true, 'id' => $productId];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function getFullListWithStock() {
        $entity_id = $_SESSION['current_entity_id'];
        
        $stmt_products = $this->conn->prepare("SELECT * FROM `{$this->tableName}` WHERE entity_id = ? ORDER BY name ASC");
        $stmt_products->bind_param("i", $entity_id);
        $stmt_products->execute();
        $products = db_stmt_to_assoc_array($stmt_products);

        if (empty($products)) {
            return [];
        }
        
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $stmt_stock_details = $this->conn->prepare("SELECT * FROM `product_stock` WHERE product_id IN ($placeholders)");
        $stmt_stock_details->bind_param($types, ...$productIds);
        $stmt_stock_details->execute();
        $all_stock = db_stmt_to_assoc_array($stmt_stock_details);

        foreach ($products as &$product) {
            $product['stock'] = [];
            foreach ($all_stock as $stock_item) {
                if ($stock_item['product_id'] == $product['id']) {
                    $product['stock'][] = $stock_item;
                }
            }
        }
        
        return $products;
    }
}