<?php
// /api/models/Invoice.php
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/BaseModel.php';

class Invoice extends BaseModel {
    protected $tableName = 'sales_invoices';

    public function __construct($db, $type = 'sales', $is_consignment = false) {
        parent::__construct($db);
        $this->configure($type, $is_consignment);
    }
    
    public function configure($type, $is_consignment) {
        if ($type === 'sales') {
            $this->tableName = 'sales_invoices';
            $this->alias = 'si';
            $this->select = "SELECT si.*, c.name as customerName";
            $this->from = "FROM `sales_invoices` as si";
            $this->join = "LEFT JOIN customers c ON si.customerId = c.id";
            $this->where = $is_consignment ? "WHERE si.is_consignment = 1" : "WHERE (si.is_consignment = 0 OR si.is_consignment IS NULL)";
            $this->allowedFilters = ['si.id', 'c.name', 'si.totalAmount'];
            $this->allowedSorts = ['id', 'date', 'customerName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
        } else {
            $this->tableName = 'purchase_invoices';
            $this->alias = 'pi';
            $this->select = "SELECT pi.*, s.name as supplierName";
            $this->from = "FROM `purchase_invoices` as pi";
            $this->join = "LEFT JOIN suppliers s ON pi.supplierId = s.id";
            $this->where = $is_consignment ? "WHERE pi.is_consignment = 1" : "WHERE (pi.is_consignment = 0 OR pi.is_consignment IS NULL)";
            $this->allowedFilters = ['pi.id', 's.name', 'pi.totalAmount'];
            $this->allowedSorts = ['id', 'date', 'supplierName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
        }
    }
    
    protected function getOrderByClause($sortBy) {
        if ($sortBy === 'remainingAmount') return '(`totalAmount` - `discount` - `paidAmount`)';
        if ($sortBy === 'customerName') return 'c.name';
        if ($sortBy === 'supplierName') return 's.name';
        return "`{$this->alias}`.`$sortBy`";
    }
    
    public function getPaginated($input) {
        $this->configure(
            strpos($input['tableName'], 'sales') !== false ? 'sales' : 'purchase',
            strpos($input['tableName'], 'consignment') !== false
        );
        $paginatedResult = parent::getPaginated($input);
        
        if (!empty($paginatedResult['data'])) {
            $paginatedResult['data'] = $this->fetchInvoiceDetails($paginatedResult['data'], strpos($input['tableName'], 'sales') !== false ? 'sales' : 'purchase');
        }

        return $paginatedResult;
    }

    private function saveInvoiceLogic($data, $type) {
        $isSales = $type === 'sales';
        $requiredField = $isSales ? 'customerId' : 'supplierId';
        if (empty($data[$requiredField]) || empty($data['date']) || !isset($data['items'])) {
            return ['error' => 'اطلاعات فاکتور ناقص است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $invoiceId = $data['id'] ?? null;
            $is_new = empty($invoiceId);

            $oldInvoice = null;
            if (!$is_new) {
                $oldInvoices = $this->fetchInvoiceDetails([['id' => $invoiceId]], $type);
                $oldInvoice = $oldInvoices[0] ?? null;
            }

            $table = $isSales ? 'sales_invoices' : 'purchase_invoices';
            $personColumn = $isSales ? 'customerId' : 'supplierId';
            
            if ($is_new) {
                $stmt = $this->conn->prepare("INSERT INTO `{$table}` ({$personColumn}, date, totalAmount, discount, paidAmount, description, is_consignment) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $is_consignment = $oldInvoice['is_consignment'] ?? 0;
                $stmt->bind_param("isdddsi", $data[$requiredField], $data['date'], $data['totalAmount'], $data['discount'], $data['paidAmount'], $data['description'], $is_consignment);
            } else {
                $stmt = $this->conn->prepare("UPDATE `{$table}` SET {$personColumn}=?, date=?, totalAmount=?, discount=?, paidAmount=?, description=? WHERE id=?");
                $stmt->bind_param("isdddsi", $data[$requiredField], $data['date'], $data['totalAmount'], $data['discount'], $data['paidAmount'], $data['description'], $invoiceId);
            }
            $stmt->execute();
            if ($is_new) {
                $invoiceId = $this->conn->insert_id;
            }
            $stmt->close();
            
            $this->adjustStockAndPaymentsForUpdate($invoiceId, $type, $data, $oldInvoice);

            $this->conn->commit();
            $logAction = $isSales ? 'SAVE_SALES_INVOICE' : 'SAVE_PURCHASE_INVOICE';
            $logMessage = "فاکتور " . ($isSales ? "فروش" : "خرید") . " به شناسه {$invoiceId} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, $logAction, $logMessage);
            return ['success' => true, 'id' => $invoiceId];

        } catch (Exception $e) {
            $this->conn->rollback();
            // Return specific status code if available
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
    
    private function adjustStockAndPaymentsForUpdate($invoiceId, $type, $newData, $oldData) {
        $isSales = $type === 'sales';
        $accountModel = new Account($this->conn);
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';

        $oldItemsMap = [];
        if ($oldData && isset($oldData['items'])) {
            foreach ($oldData['items'] as $item) {
                $key = $item['productId'] . '-' . $this->conn->real_escape_string($item['dimensions']);
                $oldItemsMap[$key] = $item;
            }
        }
        
        $newItemsMap = [];
        foreach ($newData['items'] as $itemData) {
            $productId = $itemData['productId'] ?? 0;
            if (!$isSales && isset($itemData['newProductName']) && !empty(trim($itemData['newProductName']))) {
                $stmt_new = $this->conn->prepare("INSERT INTO products (name) VALUES (?)");
                $stmt_new->bind_param("s", $itemData['newProductName']);
                $stmt_new->execute();
                $productId = $this->conn->insert_id;
                $stmt_new->close();
            }
            if (!$productId) continue;
            
            $key = $productId . '-' . $this->conn->real_escape_string($itemData['dimensions']);
            $newItemsMap[$key] = $itemData;

            $oldQty = $oldItemsMap[$key]['quantity'] ?? 0;
            $newQty = $itemData['quantity'];
            $qtyDiff = $newQty - $oldQty;

            // *** FIX: Server-side stock validation for sales invoices ***
            if ($isSales && $qtyDiff > 0) { // If we are selling more items than before
                $currentStock = 0;
                $escaped_dims = $this->conn->real_escape_string($itemData['dimensions']);
                $stock_res = $this->conn->query("SELECT quantity FROM product_stock WHERE product_id = {$productId} AND dimensions = '{$escaped_dims}'");
                if ($stock_res && $stock_res->num_rows > 0) {
                    $currentStock = $stock_res->fetch_assoc()['quantity'];
                }

                if ($qtyDiff > $currentStock) {
                    throw new Exception("موجودی محصول با شناسه {$productId} و ابعاد {$itemData['dimensions']} کافی نیست. موجودی فعلی: {$currentStock} عدد.", 400);
                }
            }

            if ($qtyDiff != 0) {
                $stockAdjust = $isSales ? -$qtyDiff : $qtyDiff;
                $this->updateStock($productId, $itemData['dimensions'], $stockAdjust);
            }
            
            // Perform manual UPSERT
            if (isset($oldItemsMap[$key])) { // This item exists, so UPDATE it
                $stmt_item = $this->conn->prepare("UPDATE `{$itemTable}` SET quantity = ?, unitPrice = ? WHERE id = ?");
                $stmt_item->bind_param("idi", $itemData['quantity'], $itemData['unitPrice'], $oldItemsMap[$key]['id']);
            } else { // This is a new item, INSERT it
                $stmt_item = $this->conn->prepare("INSERT INTO `{$itemTable}` (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->bind_param("iiids", $invoiceId, $productId, $itemData['quantity'], $itemData['unitPrice'], $itemData['dimensions']);
            }
            $stmt_item->execute();
            $stmt_item->close();
        }
        
        foreach ($oldItemsMap as $key => $oldItem) {
            if (!isset($newItemsMap[$key])) {
                $stockAdjust = $isSales ? $oldItem['quantity'] : -$oldItem['quantity'];
                $this->updateStock($oldItem['productId'], $oldItem['dimensions'], $stockAdjust);
                $this->conn->query("DELETE FROM `{$itemTable}` WHERE id = " . intval($oldItem['id']));
            }
        }

        if ($oldData && isset($oldData['payments'])) {
            foreach($oldData['payments'] as $p) {
                if ($p['type'] === 'cash' && $p['account_id']) {
                    $accountModel->updateBalance($p['account_id'], $isSales ? -$p['amount'] : $p['amount']);
                } else if ($p['type'] === 'endorse_check' && $p['checkId']) {
                    $this->conn->query("UPDATE checks SET status = 'in_hand', endorsedToInvoiceId = NULL WHERE id = " . intval($p['checkId']));
                }
            }
        }
        
        $this->conn->query("DELETE FROM payments WHERE invoiceId = {$invoiceId} AND invoiceType = '{$type}'");
        $this->conn->query("DELETE FROM checks WHERE invoiceId = {$invoiceId} AND invoiceType = '{$type}' AND type = '" . ($isSales ? 'received' : 'payable') . "'");
        
        $this->savePaymentsAndTransactions($type, $invoiceId, $newData['payments']);
    }
    
    public function saveSalesInvoice($data) {
        return $this->saveInvoiceLogic($data, 'sales');
    }

    public function savePurchaseInvoice($data) {
        return $this->saveInvoiceLogic($data, 'purchase');
    }

    private function updateStock($productId, $dimensions, $quantityChange) {
        if ($quantityChange == 0) return;
        $escaped_dims = $this->conn->real_escape_string($dimensions);
        $stock_exists_res = $this->conn->query("SELECT id FROM product_stock WHERE product_id = {$productId} AND dimensions = '{$escaped_dims}'");
        if ($stock_exists_res->num_rows > 0) {
            $this->conn->query("UPDATE product_stock SET quantity = quantity + ({$quantityChange}) WHERE product_id = {$productId} AND dimensions = '{$escaped_dims}'");
        } else {
            $this->conn->query("INSERT INTO product_stock (product_id, dimensions, quantity) VALUES ({$productId}, '{$escaped_dims}', {$quantityChange})");
        }
    }

    public function deleteSalesInvoice($id) {
        $this->conn->begin_transaction();
        try {
            $this->deleteInvoiceLogic($id, 'sales');
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_SALES_INVOICE', "فاکتور فروش با شناسه {$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function deletePurchaseInvoice($id) {
        $this->conn->begin_transaction();
        try {
            $this->deleteInvoiceLogic($id, 'purchase');
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_PURCHASE_INVOICE', "فاکتور خرید با شناسه {$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    private function deleteInvoiceLogic($id, $type) {
        $id = intval($id);
        $isSales = $type === 'sales';
        $accountModel = new Account($this->conn);
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';

        $payments_res = $this->conn->query("SELECT amount, account_id FROM payments WHERE invoiceType = '{$type}' AND invoiceId = $id AND type = 'cash' AND account_id IS NOT NULL");
        if ($payments_res) {
            while ($payment = $payments_res->fetch_assoc()) {
                $amountToRevert = $isSales ? -$payment['amount'] : $payment['amount'];
                $accountModel->updateBalance($payment['account_id'], $amountToRevert);
            }
        }
        
        $items_res = $this->conn->query("SELECT productId, quantity, dimensions FROM `{$itemTable}` WHERE invoiceId = $id");
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $stockAdjust = $isSales ? $item['quantity'] : -$item['quantity'];
                $this->updateStock($item['productId'], $item['dimensions'], $stockAdjust);
            }
        }

        if (!$isSales) {
            $payments_res = $this->conn->query("SELECT checkId FROM payments WHERE invoiceType = 'purchase' AND invoiceId = $id AND type = 'endorse_check' AND checkId IS NOT NULL");
            if ($payments_res) {
                while ($payment = $payments_res->fetch_assoc()) {
                    $this->conn->query("UPDATE checks SET status = 'in_hand', endorsedToInvoiceId = NULL WHERE id = " . intval($payment['checkId']));
                }
            }
        }

        $this->conn->query("DELETE FROM checks WHERE invoiceType = '{$type}' AND invoiceId = $id");
        $this->conn->query("DELETE FROM payments WHERE invoiceType = '{$type}' AND invoiceId = $id");
        $this->conn->query("DELETE FROM `{$itemTable}` WHERE invoiceId = $id");
        $this->conn->query("DELETE FROM `{$invoiceTable}` WHERE id = $id");
    }

    public function updateConsignmentStatus($action, $data) {
        $table = $data['type'] === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        $id = intval($data['id']);
        $newValue = $action === 'mark_as_consignment' ? 1 : 0;
        $logAction = $action === 'mark_as_consignment' ? 'MARK_CONSIGNMENT' : 'RETURN_CONSIGNMENT';
        $logMessage = $action === 'mark_as_consignment' ? "به امانی منتقل شد." : "از امانی بازگردانده شد.";

        $stmt = $this->conn->prepare("UPDATE `$table` SET is_consignment = ? WHERE id = ?");
        $stmt->bind_param("ii", $newValue, $id);
        
        if($stmt->execute()){
            $stmt->close();
            log_activity($this->conn, $logAction, "فاکتور {$data['type']} با شناسه {$id} {$logMessage}");
            return ['success' => true];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return ['error' => $error, 'statusCode' => 500];
        }
    }

    private function savePaymentsAndTransactions($invoiceType, $invoiceId, $payments) {
        $accountModel = new Account($this->conn);
        foreach ($payments as $payment) {
            $checkId = null;
            $isSales = $invoiceType === 'sales';
            $accountId = $payment['account_id'] ?? null;

            if ($payment['type'] === 'check' && isset($payment['checkDetails'])) {
                $checkType = $isSales ? 'received' : 'payable';
                $checkStatus = $isSales ? 'in_hand' : 'payable';
                $stmt_check = $this->conn->prepare("INSERT INTO checks (type, invoiceId, invoiceType, checkNumber, dueDate, bankName, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_check->bind_param("sissssds", $checkType, $invoiceId, $invoiceType, $payment['checkDetails']['checkNumber'], $payment['checkDetails']['dueDate'], $payment['checkDetails']['bankName'], $payment['amount'], $checkStatus);
                $stmt_check->execute();
                $checkId = $this->conn->insert_id;
                $stmt_check->close();
            } else if ($payment['type'] === 'endorse_check') {
                $checkId = intval($payment['checkId']);
                $this->conn->query("UPDATE checks SET status = 'endorsed', endorsedToInvoiceId = {$invoiceId} WHERE id = {$checkId}");
            }

            // *** FIX: Server-side validation for cash payment account ***
            if($payment['type'] === 'cash') {
                if (empty($accountId)) {
                    throw new Exception("برای پرداخت نقدی، انتخاب حساب الزامی است.", 400);
                }
                $adjustedAmount = $isSales ? $payment['amount'] : -$payment['amount'];
                $accountModel->updateBalance($accountId, $adjustedAmount);
            }

            $stmt_pay = $this->conn->prepare("INSERT INTO payments (invoiceId, invoiceType, type, amount, date, description, checkId, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_pay->bind_param("issdssii", $invoiceId, $invoiceType, $payment['type'], $payment['amount'], $payment['date'], $payment['description'], $checkId, $accountId);
            $stmt_pay->execute();
            $stmt_pay->close();
        }
    }
    
    public function fetchInvoiceDetails($invoices, $invoice_type) {
        if (empty($invoices)) return [];
    
        $item_table = $invoice_type . '_invoice_items';
        $invoice_ids = array_column($invoices, 'id');
        $invoice_ids_str = implode(',', array_map('intval', $invoice_ids));
    
        $items_by_invoice = [];
        $product_ids_in_items = [];
        $items_sql = "
            SELECT ii.*, p.name as productName 
            FROM `{$item_table}` ii 
            LEFT JOIN products p ON ii.productId = p.id 
            WHERE ii.invoiceId IN ($invoice_ids_str)
        ";
        $items_res = $this->conn->query($items_sql);
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $items_by_invoice[$item['invoiceId']][] = $item;
                $product_ids_in_items[] = $item['productId'];
            }
        }
        
        // *** FIX: Fetch stock for all products found in the items ***
        $stock_by_product = [];
        if (!empty($product_ids_in_items)) {
            $unique_product_ids = array_unique($product_ids_in_items);
            $pids_str = implode(',', $unique_product_ids);
            $stock_res = $this->conn->query("SELECT * FROM product_stock WHERE product_id IN ({$pids_str})");
            if ($stock_res) {
                while ($stock = $stock_res->fetch_assoc()) {
                    $stock_by_product[$stock['product_id']][] = $stock;
                }
            }
        }
    
        $payments_by_invoice = [];
        $all_check_ids = [];
        $payments_res = $this->conn->query("SELECT * FROM payments WHERE invoiceType = '{$invoice_type}' AND invoiceId IN ($invoice_ids_str)");
        if ($payments_res) {
            while ($payment = $payments_res->fetch_assoc()) {
                $payments_by_invoice[$payment['invoiceId']][] = $payment;
                if (!empty($payment['checkId'])) {
                    $all_check_ids[] = intval($payment['checkId']);
                }
            }
        }
    
        $checks_by_id = [];
        if (!empty($all_check_ids)) {
            $unique_check_ids = array_unique($all_check_ids);
            $check_ids_str = implode(',', $unique_check_ids);
            $checks_res = $this->conn->query("SELECT * FROM checks WHERE id IN ($check_ids_str)");
            if ($checks_res) {
                while ($check = $checks_res->fetch_assoc()) {
                    $checks_by_id[$check['id']] = $check;
                }
            }
        }
    
        $processed_invoices = [];
        foreach ($invoices as $invoice) {
            $invId = intval($invoice['id']);
            
            $invoice_items = $items_by_invoice[$invId] ?? [];
            $processed_items = [];
            foreach ($invoice_items as $item) {
                // Attach stock info to each item
                $item['stock'] = $stock_by_product[$item['productId']] ?? [];
                $processed_items[] = $item;
            }
            $invoice['items'] = $processed_items;
            
            $invoice_payments = $payments_by_invoice[$invId] ?? [];
            $processed_payments = [];
            foreach ($invoice_payments as $payment) {
                if (!empty($payment['checkId']) && isset($checks_by_id[$payment['checkId']])) {
                    $payment['checkDetails'] = $checks_by_id[$payment['checkId']];
                }
                $processed_payments[] = $payment;
            }
            $invoice['payments'] = $processed_payments;
            
            $invoice['remainingAmount'] = ($invoice['totalAmount'] ?? 0) - ($invoice['discount'] ?? 0) - ($invoice['paidAmount'] ?? 0);
            
            $processed_invoices[] = $invoice;
        }
    
        return $processed_invoices;
    }
}