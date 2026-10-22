<?php
// /api/models/PriceList.php
require_once __DIR__ . '/BaseModel.php';

class PriceList extends BaseModel {
    protected $tableName = 'product_collections';

    public function __construct($db) {
        parent::__construct($db);
        $this->ensureUpdatedAtColumns();
    }

    /**
     * Ensure updated_at columns exist in required tables
     */
    private function ensureUpdatedAtColumns() {
        // Check and add updated_at to product_collections
        $result = $this->conn->query("SHOW COLUMNS FROM `product_collections` LIKE 'updated_at'");
        if ($result->num_rows === 0) {
            $this->conn->query("ALTER TABLE `product_collections` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        
        // Check and add updated_at to products
        $result = $this->conn->query("SHOW COLUMNS FROM `products` LIKE 'updated_at'");
        if ($result->num_rows === 0) {
            $this->conn->query("ALTER TABLE `products` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }

    /**
     * Calculates the price for a given dimension based on price per meter.
     * @param float $price_per_meter The base price per square meter.
     * @param string $dimension The dimension string (e.g., "2x3" or "پادری").
     * @return float The calculated price.
     */
    private function _calculatePriceForDimension($price_per_meter, $dimension) {
        return $this->dimensionArea($dimension) * floatval($price_per_meter);
    }

    /**
     * Area (m²) for a dimension string. Named dimensions (e.g. «پادری») are
     * looked up in the `dimension_area_map` setting (JSON); numeric "WxH"
     * values are multiplied after centimeter normalization, where any side
     * larger than the `dimension_cm_threshold` setting is treated as cm.
     */
    private function dimensionArea($dimension) {
        $mapJson = get_app_setting($this->conn, 'dimension_area_map', '{"پادری":0.4}');
        $map = json_decode((string)$mapJson, true);
        if (!is_array($map)) {
            $map = ['پادری' => 0.4];
        }
        if (isset($map[$dimension])) {
            return floatval($map[$dimension]);
        }
        $threshold = floatval(get_app_setting($this->conn, 'dimension_cm_threshold', 15));
        if ($threshold <= 0) {
            $threshold = 15;
        }
        $parts = explode('x', str_replace('*', 'x', $dimension));
        if (count($parts) === 2) {
            $width = floatval($parts[0]);
            $height = floatval($parts[1]);
            if ($width > 0 && $height > 0) {
                // Convert each side independently: a value above the threshold
                // can only be centimeters, so "2x200" becomes 2m x 2m instead
                // of the whole pair being scaled together.
                if ($width > $threshold) $width /= 100;
                if ($height > $threshold) $height /= 100;
                return $width * $height;
            }
        }
        return 0;
    }

    /**
     * Gathers and calculates prices for all collections.
     * @param int $entity_id The ID of the business entity.
     * @return array An array containing collections with their prices and a list of unique dimensions.
     */
    private function getCalculatedPriceListForCollections($entity_id) {
        // Fetch all collections for the entity
        $stmt_collections = $this->conn->prepare("SELECT id, name, type, price_per_meter, updated_at FROM `{$this->tableName}` WHERE entity_id = ? ORDER BY name ASC");
        $stmt_collections->bind_param("i", $entity_id);
        $stmt_collections->execute();
        $collections = db_stmt_to_assoc_array($stmt_collections);

        if (empty($collections)) {
            return ['collections' => [], 'dimensions' => [], 'last_updated' => null];
        }

        // Get latest update time
        $lastUpdated = null;
        foreach ($collections as $col) {
            if ($col['updated_at'] && (!$lastUpdated || $col['updated_at'] > $lastUpdated)) {
                $lastUpdated = $col['updated_at'];
            }
        }

        // Fetch all defined dimensions for these collections
        $collectionIds = array_column($collections, 'id');
        $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
        $types = str_repeat('i', count($collectionIds));

        $stmt_dims = $this->conn->prepare("SELECT * FROM `collection_dimensions` WHERE collection_id IN ($placeholders)");
        $stmt_dims->bind_param($types, ...$collectionIds);
        $stmt_dims->execute();
        $all_dimensions_data = db_stmt_to_assoc_array($stmt_dims);

        // Get the master list of unique dimensions to use as table headers
        $standard_dimensions = array_unique(array_column($all_dimensions_data, 'dimension'));
        sort($standard_dimensions);

        $results = [];
        foreach ($collections as $collection) {
            $calculated_prices = [];
            $collection_dims = array_filter($all_dimensions_data, function ($dim) use ($collection) {
                return $dim['collection_id'] == $collection['id'];
            });

            foreach ($standard_dimensions as $std_dim) {
                $calculated_prices[$std_dim] = null;
            }

            foreach ($collection_dims as $dim_data) {
                $dim = $dim_data['dimension'];
                $price_override = $dim_data['price_override'];

                if ($price_override !== null) {
                    $calculated_prices[$dim] = floatval($price_override);
                } else {
                    $calculated_prices[$dim] = $this->_calculatePriceForDimension($collection['price_per_meter'], $dim);
                }
            }
            $collection['calculated_prices'] = $calculated_prices;
            // Check if price is zero or null
            $collection['has_no_price'] = empty($collection['price_per_meter']) || floatval($collection['price_per_meter']) == 0;
            $results[] = $collection;
        }

        return ['collections' => $results, 'dimensions' => $standard_dimensions, 'last_updated' => $lastUpdated];
    }

    /**
     * Gathers and calculates prices for single products (not in a collection) that have stock.
     * @param int $entity_id The ID of the business entity.
     * @return array An array containing products with their prices and a list of unique dimensions.
     */
    private function getCalculatedPriceListForSingleProducts($entity_id) {
        $stmt_products = $this->conn->prepare("
            SELECT p.id, p.name, p.price as price_per_meter, p.updated_at, ii.dimensions
            FROM products p
            JOIN inventory_items ii ON p.id = ii.product_id
            WHERE p.entity_id = ?
              AND p.collection_id IS NULL
              AND ii.status IN ('in_stock', 'returned')
            GROUP BY p.id, p.name, p.price, p.updated_at, ii.dimensions
            ORDER BY p.name, ii.dimensions
        ");
        $stmt_products->bind_param("i", $entity_id);
        $stmt_products->execute();
        $product_dims = db_stmt_to_assoc_array($stmt_products);

        if (empty($product_dims)) {
            return ['products' => [], 'dimensions' => [], 'last_updated' => null];
        }

        $products_by_id = [];
        $all_dimensions = [];
        $lastUpdated = null;

        foreach ($product_dims as $pd) {
            if (!isset($products_by_id[$pd['id']])) {
                $products_by_id[$pd['id']] = [
                    'id' => $pd['id'],
                    'name' => $pd['name'],
                    'price_per_meter' => $pd['price_per_meter'],
                    'updated_at' => $pd['updated_at'],
                    'dimensions' => [],
                    'has_no_price' => empty($pd['price_per_meter']) || floatval($pd['price_per_meter']) == 0
                ];
            }
            $products_by_id[$pd['id']]['dimensions'][] = $pd['dimensions'];
            $all_dimensions[] = $pd['dimensions'];
            
            if ($pd['updated_at'] && (!$lastUpdated || $pd['updated_at'] > $lastUpdated)) {
                $lastUpdated = $pd['updated_at'];
            }
        }

        $unique_dimensions = array_unique($all_dimensions);
        sort($unique_dimensions);

        $results = [];
        foreach ($products_by_id as $product) {
            $calculated_prices = [];
            foreach ($unique_dimensions as $dim) {
                if (in_array($dim, $product['dimensions'])) {
                    $calculated_prices[$dim] = $this->_calculatePriceForDimension($product['price_per_meter'], $dim);
                } else {
                    $calculated_prices[$dim] = null;
                }
            }
            $product['calculated_prices'] = $calculated_prices;
            unset($product['dimensions']);
            $results[] = $product;
        }

        return ['products' => $results, 'dimensions' => $unique_dimensions, 'last_updated' => $lastUpdated];
    }


    public function getFullPriceList() {
        $entity_id = $_SESSION['current_entity_id'];
        
        $collections_data = $this->getCalculatedPriceListForCollections($entity_id);
        $single_products_data = $this->getCalculatedPriceListForSingleProducts($entity_id);
        
        // Calculate overall last updated
        $lastUpdated = null;
        if ($collections_data['last_updated']) {
            $lastUpdated = $collections_data['last_updated'];
        }
        if ($single_products_data['last_updated'] && (!$lastUpdated || $single_products_data['last_updated'] > $lastUpdated)) {
            $lastUpdated = $single_products_data['last_updated'];
        }

        return [
            'collections' => $collections_data['collections'],
            'collection_dimensions' => $collections_data['dimensions'],
            'products' => $single_products_data['products'],
            'product_dimensions' => $single_products_data['dimensions'],
            'last_updated' => $lastUpdated
        ];
    }

    /**
     * Update prices by percentage for collections or products
     * @param array $ids Array of IDs to update
     * @param string $type 'collection' or 'product'
     * @param float $percentage Percentage to increase (can be negative for decrease)
     * @return array Result with success status
     */
    public function updatePricesByPercentage($ids, $type, $percentage) {
        $entity_id = $_SESSION['current_entity_id'];
        
        if (empty($ids) || !is_array($ids)) {
            return ['success' => false, 'error' => 'شناسه‌های نامعتبر'];
        }
        
        $percentage = floatval($percentage);
        if ($percentage == 0) {
            return ['success' => false, 'error' => 'درصد افزایش نمی‌تواند صفر باشد'];
        }
        
        $multiplier = 1 + ($percentage / 100);
        $count = count($ids);
        $placeholders = implode(',', array_fill(0, $count, '?'));
        
        if ($type === 'collection') {
            // Update collection prices
            $sql = "UPDATE `product_collections` 
                    SET price_per_meter = ROUND(price_per_meter * ?, 0),
                        updated_at = NOW()
                    WHERE id IN ($placeholders) AND entity_id = ? AND price_per_meter IS NOT NULL AND price_per_meter > 0";
            
            $stmt = $this->conn->prepare($sql);
            $types = "d" . str_repeat('i', $count) . "i";
            $params = array_merge([$multiplier], $ids, [$entity_id]);
            $stmt->bind_param($types, ...$params);
            
        } else {
            // Update product prices
            $sql = "UPDATE `products` 
                    SET price = ROUND(price * ?, 0),
                        updated_at = NOW()
                    WHERE id IN ($placeholders) AND entity_id = ? AND price IS NOT NULL AND price > 0";
            
            $stmt = $this->conn->prepare($sql);
            $types = "d" . str_repeat('i', $count) . "i";
            $params = array_merge([$multiplier], $ids, [$entity_id]);
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'خطا در بروزرسانی قیمت‌ها'];
        }
        
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        return [
            'success' => true, 
            'affected' => $affected,
            'message' => "قیمت $affected مورد با موفقیت بروزرسانی شد"
        ];
    }

    /**
     * Set specific price for collection or product
     * @param int $id ID of collection or product
     * @param string $type 'collection' or 'product'
     * @param float $price New price per meter
     * @return array Result with success status
     */
    public function setPrice($id, $type, $price) {
        $entity_id = $_SESSION['current_entity_id'];
        
        $price = floatval(str_replace(',', '', $price));
        if ($price < 0) {
            return ['success' => false, 'error' => 'قیمت نمی‌تواند منفی باشد'];
        }
        
        if ($type === 'collection') {
            $stmt = $this->conn->prepare("UPDATE `product_collections` SET price_per_meter = ?, updated_at = NOW() WHERE id = ? AND entity_id = ?");
        } else {
            $stmt = $this->conn->prepare("UPDATE `products` SET price = ?, updated_at = NOW() WHERE id = ? AND entity_id = ?");
        }
        
        $stmt->bind_param("dii", $price, $id, $entity_id);
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'خطا در بروزرسانی قیمت'];
        }
        
        $stmt->close();
        
        return ['success' => true, 'message' => 'قیمت با موفقیت بروزرسانی شد'];
    }
}