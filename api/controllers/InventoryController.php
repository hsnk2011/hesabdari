<?php
// /api/controllers/InventoryController.php
// require_once __DIR__ . '/../models/Warehouse.php'; // Temporarily disabled as the file is missing
require_once __DIR__ . '/../models/Product.php';

class InventoryController {
    private $conn;
    // private $warehouseModel; // Temporarily disabled
    private $productModel;

    public function __construct($db) {
        $this->conn = $db;
        // $this->warehouseModel = new Warehouse($db); // Temporarily disabled
        $this->productModel = new Product($db);
    }

    public function listWarehouses($data) {
        // This feature is disabled because the Warehouse.php model is missing.
        send_json(['warehouses'=>[]]);
    }

    // Transfer stock between warehouses/bins
    public function transfer($data) {
        $productId = intval($data['product_id'] ?? 0);
        $fromWh = intval($data['from_warehouse_id'] ?? 0);
        $toWh = intval($data['to_warehouse_id'] ?? 0);
        $qty = intval($data['quantity'] ?? 0);
        $toBin = trim($data['to_bin'] ?? '');

        if (!$productId || !$toWh || $qty <= 0) {
            send_json(['error'=>'اطلاعات انتقال ناقص است'], 400);
            return;
        }

        $this->conn->begin_transaction();
        try {
            if ($fromWh) {
                $stmtUpdateFrom = $this->conn->prepare("UPDATE product_stock SET quantity = GREATEST(quantity - ?, 0) WHERE product_id = ? AND warehouse_id = ?");
                $stmtUpdateFrom->bind_param("iii", $qty, $productId, $fromWh);
                $stmtUpdateFrom->execute();
                $stmtUpdateFrom->close();
            }
            
            $stmtSelectTo = $this->conn->prepare("SELECT id, quantity FROM product_stock WHERE product_id = ? AND warehouse_id = ? LIMIT 1");
            $stmtSelectTo->bind_param("ii", $productId, $toWh);
            $stmtSelectTo->execute();
            $result = db_stmt_to_assoc_array($stmtSelectTo);
            $row = $result[0] ?? null;

            if ($row) {
                $newQ = $row['quantity'] + $qty;
                $stmtUpdateTo = $this->conn->prepare("UPDATE product_stock SET quantity = ?, bin_location = ? WHERE id = ?");
                $stmtUpdateTo->bind_param("isi", $newQ, $toBin, $row['id']);
                $stmtUpdateTo->execute();
                $stmtUpdateTo->close();
            } else {
                $stmtInsertTo = $this->conn->prepare("INSERT INTO product_stock (product_id, warehouse_id, bin_location, quantity) VALUES (?, ?, ?, ?)");
                $stmtInsertTo->bind_param("iisi", $productId, $toWh, $toBin, $qty);
                $stmtInsertTo->execute();
                $stmtInsertTo->close();
            }

            $this->conn->commit();
            send_json(['success'=>true]);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error'=>'خطا در انتقال موجودی: '.$e->getMessage()], 500);
        }
    }
}