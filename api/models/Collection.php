<?php
// /api/models/Collection.php
require_once __DIR__ . '/BaseModel.php';

class Collection extends BaseModel {
    protected $tableName = 'product_collections';

    public function getAttributeTypes() {
        $stmt = $this->conn->prepare("SELECT * FROM `product_attribute_types` ORDER BY name ASC");
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function saveAttributeType($data) {
        $id = $data['id'] ?? null;
        $name = trim($data['name'] ?? '');
        $applicable_to = $data['applicable_to'] ?? 'both';

        if (empty($name)) {
            return ['error' => 'نام مشخصه الزامی است.', 'statusCode' => 400];
        }

        if (!empty($id)) {
            $stmt = $this->conn->prepare("UPDATE `product_attribute_types` SET name = ?, applicable_to = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $applicable_to, $id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `product_attribute_types` (name, applicable_to) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $applicable_to);
        }

        if ($stmt->execute()) {
            $savedId = $id ?? $this->conn->insert_id;
            $stmt->close();
            return ['success' => true, 'id' => $savedId];
        } else {
            $error = $stmt->error;
            $stmt->close();
            if ($this->conn->errno == 1062) {
                return ['error' => 'این نوع مشخصه از قبل وجود دارد.', 'statusCode' => 409];
            }
            return ['error' => $error, 'statusCode' => 500];
        }
    }

    public function deleteAttributeType($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }
        
        // product_attribute_types has no entity_id column, so a direct DELETE is
        // used instead of BaseModel::delete (which scopes by entity_id).
        try {
            $stmt = $this->conn->prepare("DELETE FROM `product_attribute_types` WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                return ['error' => 'نوع ویژگی یافت نشد.', 'statusCode' => 404];
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['error' => 'خطا در حذف نوع ویژگی: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    public function getAllCollectionsWithDetails() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt_collections = $this->conn->prepare("SELECT * FROM `product_collections` WHERE entity_id = ? ORDER BY name ASC");
        $stmt_collections->bind_param("i", $entity_id);
        $stmt_collections->execute();
        $collections = db_stmt_to_assoc_array($stmt_collections);

        if (empty($collections)) {
            return [];
        }

        $collectionIds = array_column($collections, 'id');
        $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
        $types = str_repeat('i', count($collectionIds));

        // Fetch attributes
        $stmt_attrs = $this->conn->prepare("
            SELECT ca.collection_id, ca.attribute_type_id, ca.value, at.name as attribute_name
            FROM `product_collection_attributes` ca
            JOIN `product_attribute_types` at ON ca.attribute_type_id = at.id
            WHERE ca.collection_id IN ($placeholders)
        ");
        $stmt_attrs->bind_param($types, ...$collectionIds);
        $stmt_attrs->execute();
        $attributes = db_stmt_to_assoc_array($stmt_attrs);

        // Fetch dimensions
        $stmt_dims = $this->conn->prepare("SELECT * FROM `collection_dimensions` WHERE collection_id IN ($placeholders)");
        $stmt_dims->bind_param($types, ...$collectionIds);
        $stmt_dims->execute();
        $dimensions = db_stmt_to_assoc_array($stmt_dims);

        foreach ($collections as &$collection) {
            $collection['attributes'] = [];
            foreach ($attributes as $attribute) {
                if ($attribute['collection_id'] == $collection['id']) {
                    $collection['attributes'][] = [
                        'attribute_type_id' => $attribute['attribute_type_id'],
                        'name' => $attribute['attribute_name'],
                        'value' => $attribute['value']
                    ];
                }
            }
            $collection['dimensions'] = [];
            foreach ($dimensions as $dimension) {
                if($dimension['collection_id'] == $collection['id']) {
                    $collection['dimensions'][] = $dimension;
                }
            }
        }
        
        return $collections;
    }
    
    public function getAllCollectionsSimple() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("SELECT id, name, type FROM `product_collections` WHERE entity_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public function saveCollection($data) {
        $id = $data['id'] ?? null;
        $name = trim($data['name'] ?? '');
        $type = $data['type'] ?? 'machine';
        $price = floatval(str_replace(',', '', $data['price_per_meter'] ?? 0));
        $attributes = $data['attributes'] ?? [];
        $dimensions = $data['dimensions'] ?? []; // Get dimensions from data
        $entity_id = $_SESSION['current_entity_id'];

        if (empty($name)) {
            return ['error' => 'نام کلکسیون الزامی است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $collectionId = 0;
            if (!empty($id)) {
                $stmt = $this->conn->prepare("UPDATE `product_collections` SET name = ?, type = ?, price_per_meter = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param("ssdii", $name, $type, $price, $id, $entity_id);
                $stmt->execute();
                $collectionId = $id;
            } else {
                $stmt = $this->conn->prepare("INSERT INTO `product_collections` (name, type, price_per_meter, entity_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssdi", $name, $type, $price, $entity_id);
                $stmt->execute();
                $collectionId = $this->conn->insert_id;
            }
            $stmt->close();

            // Sync attributes
            $stmt_delete_attrs = $this->conn->prepare("DELETE FROM `product_collection_attributes` WHERE collection_id = ?");
            $stmt_delete_attrs->bind_param("i", $collectionId);
            $stmt_delete_attrs->execute();
            $stmt_delete_attrs->close();

            if (!empty($attributes)) {
                $stmt_insert_attrs = $this->conn->prepare("INSERT INTO `product_collection_attributes` (collection_id, attribute_type_id, value) VALUES (?, ?, ?)");
                foreach ($attributes as $attr) {
                    if (!empty($attr['value'])) {
                        $stmt_insert_attrs->bind_param("iis", $collectionId, $attr['id'], $attr['value']);
                        $stmt_insert_attrs->execute();
                    }
                }
                $stmt_insert_attrs->close();
            }

            // Sync dimensions
            $stmt_delete_dims = $this->conn->prepare("DELETE FROM `collection_dimensions` WHERE collection_id = ?");
            $stmt_delete_dims->bind_param("i", $collectionId);
            $stmt_delete_dims->execute();
            $stmt_delete_dims->close();

            if (!empty($dimensions)) {
                // Binding a NULL as type "d" would coerce to 0, so use a dedicated
                // statement that inserts a literal NULL when no override is provided.
                $stmt_insert_dims = $this->conn->prepare("INSERT INTO `collection_dimensions` (collection_id, dimension, price_override) VALUES (?, ?, ?)");
                $stmt_insert_dims_null = $this->conn->prepare("INSERT INTO `collection_dimensions` (collection_id, dimension, price_override) VALUES (?, ?, NULL)");
                foreach ($dimensions as $dim) {
                    if (!empty($dim['dimension'])) {
                        if (isset($dim['price_override']) && $dim['price_override'] !== '' && $dim['price_override'] !== null) {
                            $price_override = $dim['price_override'];
                            $stmt_insert_dims->bind_param("isd", $collectionId, $dim['dimension'], $price_override);
                            $stmt_insert_dims->execute();
                        } else {
                            $stmt_insert_dims_null->bind_param("is", $collectionId, $dim['dimension']);
                            $stmt_insert_dims_null->execute();
                        }
                    }
                }
                $stmt_insert_dims->close();
                $stmt_insert_dims_null->close();
            }

            $this->conn->commit();
            return ['success' => true, 'id' => $collectionId];

        } catch (Exception $e) {
            $this->conn->rollback();
            if ($this->conn->errno == 1062) {
                return ['error' => 'کلکسیونی با این نام از قبل در این مجموعه تجاری وجود دارد.', 'statusCode' => 409];
            }
            return ['error' => 'خطا در ذخیره کلکسیون: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    public function deleteCollection($id) {
        return parent::delete($id);
    }

    /**
     * Get products belonging to a collection
     */
    public function getCollectionProducts($collectionId) {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT id, name, design_code, colorway 
            FROM products 
            WHERE collection_id = ? AND entity_id = ? 
            ORDER BY name ASC
        ");
        $stmt->bind_param("ii", $collectionId, $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    /**
     * Get all products (for selection list)
     */
    public function getAllProductsForSelection() {
        $entity_id = $_SESSION['current_entity_id'];
        $stmt = $this->conn->prepare("
            SELECT p.id, p.name, p.design_code, p.colorway, p.collection_id, c.name as collection_name
            FROM products p
            LEFT JOIN product_collections c ON p.collection_id = c.id
            WHERE p.entity_id = ? 
            ORDER BY p.name ASC
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    /**
     * Add products to a collection
     */
    public function addProductsToCollection($collectionId, $productIds) {
        $entity_id = $_SESSION['current_entity_id'];
        
        if (empty($productIds) || !is_array($productIds)) {
            return ['error' => 'لیست محصولات نامعتبر است.', 'statusCode' => 400];
        }

        // Verify collection belongs to this entity
        $stmt_check = $this->conn->prepare("SELECT id FROM product_collections WHERE id = ? AND entity_id = ?");
        $stmt_check->bind_param("ii", $collectionId, $entity_id);
        $stmt_check->execute();
        if (!$stmt_check->fetch()) {
            $stmt_check->close();
            return ['error' => 'کلکسیون یافت نشد.', 'statusCode' => 404];
        }
        $stmt_check->close();

        // Update products to belong to this collection
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        
        $sql = "UPDATE products SET collection_id = ?, price = NULL WHERE id IN ($placeholders) AND entity_id = ?";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return ['error' => 'خطا در آماده‌سازی کوئری: ' . $this->conn->error, 'statusCode' => 500];
        }
        
        // Build params array properly
        $params = [];
        $params[] = $collectionId;
        foreach ($productIds as $pid) {
            $params[] = $pid;
        }
        $params[] = $entity_id;
        
        $stmt->bind_param('i' . $types . 'i', ...$params);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            return ['success' => true, 'affectedRows' => $affected];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return ['error' => 'خطا در افزودن محصولات: ' . $error, 'statusCode' => 500];
        }
    }

    /**
     * Remove products from a collection (set collection_id to NULL)
     */
    public function removeProductsFromCollection($productIds) {
        $entity_id = $_SESSION['current_entity_id'];
        
        if (empty($productIds) || !is_array($productIds)) {
            return ['error' => 'لیست محصولات نامعتبر است.', 'statusCode' => 400];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        
        $stmt = $this->conn->prepare("
            UPDATE products 
            SET collection_id = NULL 
            WHERE id IN ($placeholders) AND entity_id = ?
        ");
        
        $params = array_merge($productIds, [$entity_id]);
        $stmt->bind_param($types . 'i', ...$params);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            return ['success' => true, 'affectedRows' => $affected];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return ['error' => 'خطا در حذف محصولات از کلکسیون: ' . $error, 'statusCode' => 500];
        }
    }
}