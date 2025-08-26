<?php
// /api/controllers/InventoryController.php
require_once __DIR__ . '/../models/Warehouse.php';
require_once __DIR__ . '/../models/Product.php';

class InventoryController {
    private $conn;
    private $warehouseModel;
    private $productModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->warehouseModel = new Warehouse($db);
        $this->productModel = new Product($db);
    }

    public function listWarehouses($data) {
        $list = $this->warehouseModel->listActive();
        send_json(['warehouses'=>$list]);
    }

    // Transfer stock between warehouses/bins
    public function transfer($data) {
        $productId = intval($data['product_id'] ?? 0);
        $fromWh = intval($data['from_warehouse_id'] ?? 0);
        $toWh = intval($data['to_warehouse_id'] ?? 0);
        $qty = intval($data['quantity'] ?? 0);
        $toBin = trim($data['to_bin'] ?? '');

        if (!$productId || !$toWh || $qty <= 0) { send_json(['error'=>'اطلاعات انتقال ناقص است'], 400); }

        $this->conn->begin_transaction();
        try {
            if ($fromWh) {
                $stmt = $this->conn->prepare("UPDATE product_stock SET quantity = GREATEST(quantity - ?, 0) WHERE product_id = ? AND warehouse_id = ?");
                $stmt->bind_param("iii", $qty, $productId, $fromWh);
                $stmt->execute(); $stmt->close();
            }
            // Upsert to destination
            $stmt = $this->conn->prepare("SELECT id, quantity FROM product_stock WHERE product_id = ? AND warehouse_id = ? LIMIT 1");
            $stmt->bind_param("ii", $productId, $toWh);
            $stmt->execute();
            $row = db_stmt_get_result($stmt)->fetch_assoc();
            $stmt->close();

            if ($row) {
                $newQ = $row['quantity'] + $qty;
                $stmt = $this->conn->prepare("UPDATE product_stock SET quantity = ?, bin_location = ? WHERE id = ?");
                $stmt->bind_param("isi", $newQ, $toBin, $row['id']);
                $stmt->execute(); $stmt->close();
            } else {
                $stmt = $this->conn->prepare("INSERT INTO product_stock (product_id, warehouse_id, bin_location, quantity) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $productId, $toWh, $toBin, $qty);
                $stmt->execute(); $stmt->close();
            }

            $this->conn->commit();
            send_json(['success'=>true]);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error'=>'خطا در انتقال موجودی: '.$e->getMessage()], 500);
        }
    }
}
