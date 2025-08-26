<?php
// /api/models/Product.php
require_once __DIR__ . '/BaseModel.php';

class Product extends BaseModel {
    protected $tableName = 'products';
    protected $allowedFilters = ['name', 'description'];
    protected $allowedSorts = ['id', 'name'];

    /**
     * Overrides the base getPaginated method to attach stock information to each product.
     *
     * @param array $input The user input for pagination, sorting, and search.
     * @return array An array containing the paginated product data with stock info.
     */
    public function getPaginated($input) {
        // First, get the paginated product data from the parent method.
        $paginatedResult = parent::getPaginated($input);
        $products = $paginatedResult['data'];

        if (empty($products)) {
            return $paginatedResult; // Return early if no products were found.
        }

        // Get the IDs of the fetched products.
        $productIds = array_column($products, 'id');
        $ids_str = implode(',', $productIds);

        // Fetch all stock information for these specific products in a single query.
        $all_stock = $this->conn->query("SELECT * FROM `product_stock` WHERE product_id IN ($ids_str)")->fetch_all(MYSQLI_ASSOC);

        // Map the stock items to their respective products.
        foreach ($products as &$product) {
            $product['stock'] = [];
            foreach ($all_stock as $stock_item) {
                if ($stock_item['product_id'] == $product['id']) {
                    $product['stock'][] = $stock_item;
                }
            }
        }
        
        // Update the data in the result and return it.
        $paginatedResult['data'] = $products;
        return $paginatedResult;
    }

    // ... (متدهای save و getFullListWithStock بدون تغییر باقی می‌مانند) ...
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
                $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
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
        $products = $this->conn->query("SELECT * FROM `{$this->tableName}` ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
        $all_stock = $this->conn->query("SELECT * FROM `product_stock`")->fetch_all(MYSQLI_ASSOC);

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
    
    /**
     * Searches for products by name and attaches their stock information.
     * Used for AJAX-based Select2 dropdowns.
     *
     * @param string $term The search term.
     * @return array A list of products matching the term, with stock info.
     */
    public function searchByNameWithStock($term) {
        $searchTerm = "%{$term}%";
        $stmt = $this->conn->prepare("SELECT id, name, description FROM `{$this->tableName}` WHERE name LIKE ? LIMIT 30");
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        $fields = []; $row = [];
        while ($field = $meta->fetch_field()) { $fields[] = &$row[$field->name]; }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        $products = [];
        while ($stmt->fetch()) {
            $c = [];
            foreach($row as $key => $val) { $c[$key] = $val; }
            $products[] = $c;
        }
        $stmt->close();

        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');
        $ids_str = implode(',', $productIds);
        $all_stock = $this->conn->query("SELECT * FROM `product_stock` WHERE product_id IN ($ids_str)")->fetch_all(MYSQLI_ASSOC);

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