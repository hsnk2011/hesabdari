<?php
// /api/controllers/ProductController.php
require_once __DIR__ . '/../models/Product.php';

class ProductController {
    private $conn;
    private $productModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->productModel = new Product($db);
    }

    // Update attributes for a product
    public function updateAttributes($data) {
        $id = intval($data['id'] ?? 0);
        if (!$id) { send_json(['error'=>'شناسه محصول نامعتبر است'], 400); }

        $fields = ['collection','design_code','colorway','material','shaneh','density','pile_height_mm','length_cm','width_cm','parent_product_id'];
        $set = []; $params = []; $types = '';
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "$f = ?";
                $val = $data[$f];
                if (in_array($f, ['shaneh','density','length_cm','width_cm','parent_product_id'])) { $types .= 'i'; $params[] = intval($val); }
                elseif ($f === 'pile_height_mm') { $types .= 'd'; $params[] = floatval($val); }
                else { $types .= 's'; $params[] = $val; }
            }
        }
        if (empty($set)) { send_json(['error'=>'هیچ فیلدی برای به‌روزرسانی ارسال نشده'], 400); }
        $sql = "UPDATE products SET ".implode(',', $set)." WHERE id = ?";
        $types .= 'i'; $params[] = $id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $stmt->close();
            send_json(['success'=>true]);
        } else {
            $err = $stmt->error; $stmt->close();
            send_json(['error'=>"DB error: $err"], 500);
        }
    }

    // List variants for a parent product
    public function listVariants($data) {
        $parent = intval($data['parent_product_id'] ?? 0);
        if (!$parent) { send_json(['variants'=>[]]); }
        $stmt = $this->conn->prepare("SELECT * FROM products WHERE parent_product_id = ? ORDER BY width_cm, length_cm");
        $stmt->bind_param("i", $parent);
        $stmt->execute();
        $res = db_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        send_json(['variants'=>$res]);
    }

    // Provide printable label data (client will render barcode/QR)
    public function labelData($data) {
        $id = intval($data['id'] ?? 0);
        if (!$id) { send_json(['error'=>'شناسه محصول نامعتبر است'], 400); }
        $stmt = $this->conn->prepare("SELECT id, name, design_code, colorway, collection, shaneh, density, length_cm, width_cm FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $p = db_stmt_get_result($stmt)->fetch_assoc();
        $stmt->close();
        if (!$p) { send_json(['error'=>'محصول یافت نشد'], 404); }
        // Generate a SKU-like code
        $size = (($p['length_cm'] && $p['width_cm']) ? ($p['length_cm'].'x'.$p['width_cm'].'cm') : '');
        $sku = strtoupper(trim(($p['collection'] ?? '').'-'.($p['design_code'] ?? '').'-'.($p['colorway'] ?? '').'-'.($p['shaneh'] ?? '').'-'.$size), '-');
        send_json(['label'=>[
            'id'=>$p['id'],
            'name'=>$p['name'],
            'sku'=>$sku,
            'size'=>$size,
            'collection'=>$p['collection'],
            'design_code'=>$p['design_code'],
            'colorway'=>$p['colorway'],
            'shaneh'=>$p['shaneh'],
            'density'=>$p['density']
        ]]);
    }
}
