<?php
// /api/models/Invoice.php
require_once __DIR__ . '/Account.php';

class Invoice {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function getInvoiceConfig($isSales, $isConsignment) {
        if ($isSales) {
            return [
                'select' => "SELECT si.*, c.name as customerName",
                'from' => "`sales_invoices` si LEFT JOIN customers c ON si.customerId = c.id",
                'where' => $isConsignment ? "si.is_consignment = 1" : "(si.is_consignment = 0 OR si.is_consignment IS NULL)",
                'search' => ['si.id', 'c.name', 'si.totalAmount', 'si.paidAmount', '(si.totalAmount - si.discount - si.paidAmount)', 'si.discount'],
                'sort' => ['id', 'date', 'customerName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'],
                'alias' => 'si'
            ];
        } else {
            return [
                'select' => "SELECT pi.*, s.name as supplierName",
                'from' => "`purchase_invoices` pi LEFT JOIN suppliers s ON pi.supplierId = s.id",
                'where' => $isConsignment ? "pi.is_consignment = 1" : "(pi.is_consignment = 0 OR pi.is_consignment IS NULL)",
                'search' => ['pi.id', 's.name', 'pi.totalAmount', 'pi.paidAmount', '(pi.totalAmount - pi.discount - pi.paidAmount)', 'pi.discount'],
                'sort' => ['id', 'date', 'supplierName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'],
                'alias' => 'pi'
            ];
        }
    }

    public function getPaginated($type, $input) {
        $isSales = $type === 'sales';
        $config = $this->getInvoiceConfig($isSales, $input['is_consignment'] ?? false);

        $page = isset($input['currentPage']) ? max(1, intval($input['currentPage'])) : 1;
        $limit = isset($input['limit']) ? intval($input['limit']) : 15;
        $offset = ($page - 1) * $limit;
        $sortBy = in_array($input['sortBy'] ?? 'id', $config['sort']) ? $input['sortBy'] : 'id';
        $sortOrder = in_array(strtoupper($input['sortOrder'] ?? 'ASC'), ['ASC', 'DESC']) ? strtoupper($input['sortOrder']) : 'ASC';
        
        $orderByClause = "`{$config['alias']}`.`$sortBy`";
        if ($sortBy === 'remainingAmount') $orderByClause = '(`totalAmount` - `discount` - `paidAmount`)';
        elseif ($sortBy === 'customerName') $orderByClause = 'c.name';
        elseif ($sortBy === 'supplierName') $orderByClause = 's.name';

        $searchTerm = $input['searchTerm'] ?? '';
        
        $search_params = [];
        $search_param_types = '';
        $where_clauses = [$config['where']];

        if (!empty($searchTerm) && !empty($config['search'])) {
            $search_parts = [];
            foreach ($config['search'] as $col) $search_parts[] = "$col LIKE ?";
            $where_clauses[] = "(" . implode(' OR ', $search_parts) . ")";
            $wildcard = "%{$searchTerm}%";
            foreach ($config['search'] as $_) {
                $search_params[] = $wildcard;
                $search_param_types .= 's';
            }
        }
        $where_sql = " WHERE " . implode(' AND ', $where_clauses);
        
        $bind_params_safely = function($stmt, $types, &$params) {
            if (!empty($params)) {
                $refs = [];
                foreach ($params as $key => $value) {
                    $refs[$key] = &$params[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            }
        };

        $count_sql = "SELECT COUNT(*) as total FROM {$config['from']} {$where_sql}";
        $stmt_count = $this->conn->prepare($count_sql);
        $bind_params_safely($stmt_count, $search_param_types, $search_params);
        $stmt_count->execute();
        $stmt_count->store_result();
        $totalRecords = 0;
        $stmt_count->bind_result($totalRecords);
        $stmt_count->fetch();
        $stmt_count->close();
        
        $data_sql = "{$config['select']} FROM {$config['from']} {$where_sql} ORDER BY {$orderByClause} {$sortOrder} LIMIT ? OFFSET ?";
        
        $data_params = $search_params;
        $data_params[] = $limit;
        $data_params[] = $offset;
        $data_param_types = $search_param_types . 'ii';

        $stmt_data = $this->conn->prepare($data_sql);
        $bind_params_safely($stmt_data, $data_param_types, $data_params);
        
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

        if (!empty($data)) {
            $data = $this->fetchInvoiceDetails($data, $type);
        }

        return ['data' => $data, 'totalRecords' => $totalRecords];
    }
    
    public function saveSalesInvoice($data) {
        if (empty($data['customerId']) || empty($data['date']) || !isset($data['items'])) {
            return ['error' => 'اطلاعات فاکتور فروش ناقص است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $is_new = empty($data['id']);
            $invoiceId = $data['id'] ?? null;
            $is_consignment_flag = 0;

            if (!$is_new) {
                $stmt_check = $this->conn->prepare("SELECT is_consignment FROM sales_invoices WHERE id=?");
                $stmt_check->bind_param("i", $invoiceId);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $stmt_check->bind_result($is_consignment_flag);
                    $stmt_check->fetch();
                }
                $stmt_check->close();
                
                $this->deleteSalesInvoiceLogic($invoiceId);
            }
            
            $stmt = $this->conn->prepare("INSERT INTO sales_invoices (id, customerId, date, totalAmount, discount, paidAmount, description, is_consignment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $discount = $data['discount'] ?? 0;
            $stmt->bind_param("iisdddsi", $invoiceId, $data['customerId'], $data['date'], $data['totalAmount'], $discount, $data['paidAmount'], $data['description'], $is_consignment_flag);
            $stmt->execute();
            
            if ($is_new) {
                $invoiceId = $this->conn->insert_id;
            }
            $stmt->close();

            foreach ($data['items'] as $item) {
                $stmt_item = $this->conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->bind_param("iiids", $invoiceId, $item['productId'], $item['quantity'], $item['unitPrice'], $item['dimensions']);
                $stmt_item->execute();
                $stmt_item->close();

                $this->conn->query("UPDATE product_stock SET quantity = quantity - " . intval($item['quantity']) . " WHERE product_id = " . intval($item['productId']) . " AND dimensions = '" . $this->conn->real_escape_string($item['dimensions']) . "'");
            }

            $this->savePaymentsAndTransactions('sales', $invoiceId, $data['payments']);
            
            $this->conn->commit();
            log_activity($this->conn, 'SAVE_SALES_INVOICE', "فاکتور فروش به شناسه {$invoiceId} " . ($is_new ? "ایجاد شد." : "ویرایش شد."));
            return ['success' => true, 'id' => $invoiceId];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => "Error in save_sales_invoice: " . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function savePurchaseInvoice($data) {
        if (empty($data['supplierId']) || empty($data['date']) || !isset($data['items'])) {
            return ['error' => 'اطلاعات فاکتور خرید ناقص است.', 'statusCode' => 400];
        }

        $this->conn->begin_transaction();
        try {
            $is_new = empty($data['id']);
            $invoiceId = $data['id'] ?? null;
            $is_consignment_flag = 0;

            if (!$is_new) {
                $stmt_check = $this->conn->prepare("SELECT is_consignment FROM purchase_invoices WHERE id=?");
                $stmt_check->bind_param("i", $invoiceId);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $stmt_check->bind_result($is_consignment_flag);
                    $stmt_check->fetch();
                }
                $stmt_check->close();
                
                $this->deletePurchaseInvoiceLogic($invoiceId);
            }

            $stmt = $this->conn->prepare("INSERT INTO purchase_invoices (id, supplierId, date, totalAmount, discount, paidAmount, description, is_consignment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $discount = $data['discount'] ?? 0;
            $stmt->bind_param("iisdddsi", $invoiceId, $data['supplierId'], $data['date'], $data['totalAmount'], $discount, $data['paidAmount'], $data['description'], $is_consignment_flag);
            $stmt->execute();
            
            if ($is_new) {
                $invoiceId = $this->conn->insert_id;
            }
            $stmt->close();
            
            foreach ($data['items'] as $item) {
                $productId = 0;
                if (isset($item['newProductName']) && !empty(trim($item['newProductName']))) {
                    $stmt_new = $this->conn->prepare("INSERT INTO products (name) VALUES (?)");
                    $stmt_new->bind_param("s", $item['newProductName']);
                    $stmt_new->execute();
                    $productId = $this->conn->insert_id;
                    $stmt_new->close();
                } else {
                    $productId = intval($item['productId']);
                }
                if (!$productId) continue;

                $stmt_item = $this->conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->bind_param("iiids", $invoiceId, $productId, $item['quantity'], $item['unitPrice'], $item['dimensions']);
                $stmt_item->execute();
                $stmt_item->close();

                $stock_exists_res = $this->conn->query("SELECT id FROM product_stock WHERE product_id = {$productId} AND dimensions = '" . $this->conn->real_escape_string($item['dimensions']) . "'");
                if ($stock_exists_res->num_rows > 0) {
                    $this->conn->query("UPDATE product_stock SET quantity = quantity + " . intval($item['quantity']) . " WHERE product_id = {$productId} AND dimensions = '" . $this->conn->real_escape_string($item['dimensions']) . "'");
                } else {
                    $this->conn->query("INSERT INTO product_stock (product_id, dimensions, quantity) VALUES ({$productId}, '" . $this->conn->real_escape_string($item['dimensions']) . "', " . intval($item['quantity']) . ")");
                }
            }

            $this->savePaymentsAndTransactions('purchase', $invoiceId, $data['payments']);

            $this->conn->commit();
            log_activity($this->conn, 'SAVE_PURCHASE_INVOICE', "فاکتور خرید به شناسه {$invoiceId} " . ($is_new ? "ایجاد شد." : "ویرایش شد."));
            return ['success' => true, 'id' => $invoiceId];

        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => "Error in save_purchase_invoice: " . $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    public function deleteSalesInvoice($id) {
        $this->conn->begin_transaction();
        try {
            $this->deleteSalesInvoiceLogic($id);
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
            $this->deletePurchaseInvoiceLogic($id);
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_PURCHASE_INVOICE', "فاکتور خرید با شناسه {$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
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

    private function deleteSalesInvoiceLogic($id) {
        $id = intval($id);
        $accountModel = new Account($this->conn);
        
        $payments_res = $this->conn->query("SELECT amount, account_id FROM payments WHERE invoiceType = 'sales' AND invoiceId = $id AND type = 'cash' AND account_id IS NOT NULL");
        if ($payments_res) {
            while ($payment = $payments_res->fetch_assoc()) {
                $accountModel->updateBalance($payment['account_id'], -$payment['amount']);
            }
        }
        
        $items_res = $this->conn->query("SELECT productId, quantity, dimensions FROM sales_invoice_items WHERE invoiceId = $id");
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $this->conn->query("UPDATE product_stock SET quantity = quantity + " . intval($item['quantity']) . " WHERE product_id = " . intval($item['productId']) . " AND dimensions = '" . $this->conn->real_escape_string($item['dimensions']) . "'");
            }
        }
        $this->conn->query("DELETE FROM transactions WHERE relatedObjectType = 'sales_payment' AND relatedObjectId = $id");
        $this->conn->query("DELETE FROM checks WHERE invoiceType = 'sales' AND invoiceId = $id");
        $this->conn->query("DELETE FROM payments WHERE invoiceType = 'sales' AND invoiceId = $id");
        $this->conn->query("DELETE FROM sales_invoice_items WHERE invoiceId = $id");
        $this->conn->query("DELETE FROM sales_invoices WHERE id = $id");
    }

    private function deletePurchaseInvoiceLogic($id) {
        $id = intval($id);
        $accountModel = new Account($this->conn);

        $payments_res = $this->conn->query("SELECT amount, account_id FROM payments WHERE invoiceType = 'purchase' AND invoiceId = $id AND type = 'cash' AND account_id IS NOT NULL");
        if ($payments_res) {
            while ($payment = $payments_res->fetch_assoc()) {
                $accountModel->updateBalance($payment['account_id'], $payment['amount']);
            }
        }
        
        $items_res = $this->conn->query("SELECT productId, quantity, dimensions FROM purchase_invoice_items WHERE invoiceId = $id");
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $this->conn->query("UPDATE product_stock SET quantity = quantity - " . intval($item['quantity']) . " WHERE product_id = " . intval($item['productId']) . " AND dimensions = '" . $this->conn->real_escape_string($item['dimensions']) . "'");
            }
        }
        $payments_res = $this->conn->query("SELECT checkId FROM payments WHERE invoiceType = 'purchase' AND invoiceId = $id AND type = 'endorse_check' AND checkId IS NOT NULL");
        if ($payments_res) {
            while ($payment = $payments_res->fetch_assoc()) {
                $this->conn->query("UPDATE checks SET status = 'in_hand', endorsedToInvoiceId = NULL WHERE id = " . intval($payment['checkId']));
            }
        }
        $this->conn->query("DELETE FROM transactions WHERE relatedObjectType = 'purchase_payment' AND relatedObjectId = $id");
        $this->conn->query("DELETE FROM checks WHERE invoiceType = 'purchase' AND invoiceId = $id AND type = 'payable'");
        $this->conn->query("DELETE FROM payments WHERE invoiceType = 'purchase' AND invoiceId = $id");
        $this->conn->query("DELETE FROM purchase_invoice_items WHERE invoiceId = $id");
        $this->conn->query("DELETE FROM purchase_invoices WHERE id = $id");
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

            if($payment['type'] === 'cash' && $accountId) {
                $adjustedAmount = $isSales ? $payment['amount'] : -$payment['amount'];
                $accountModel->updateBalance($accountId, $adjustedAmount);
            }

            $stmt_pay = $this->conn->prepare("INSERT INTO payments (invoiceId, invoiceType, type, amount, date, description, checkId, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_pay->bind_param("issdssii", $invoiceId, $invoiceType, $payment['type'], $payment['amount'], $payment['date'], $payment['description'], $checkId, $accountId);
            $stmt_pay->execute();
            $stmt_pay->close();

            $trans_amount = $isSales ? $payment['amount'] : -$payment['amount'];
            $trans_type_text = "پرداخت فاکتور " . ($isSales ? "فروش" : "خرید") . " #{$invoiceId}";
            $relatedObjectType = $isSales ? 'sales_payment' : 'purchase_payment';
            
            $stmt_trans = $this->conn->prepare("INSERT INTO transactions (relatedObjectType, relatedObjectId, amount, date, type, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_trans->bind_param("sidsss", $relatedObjectType, $invoiceId, $trans_amount, $payment['date'], $trans_type_text, $payment['description']);
            $stmt_trans->execute();
            $stmt_trans->close();
        }
    }
    
    public function fetchInvoiceDetails($invoices, $invoice_type) {
        if (empty($invoices)) return [];
    
        $item_table = $invoice_type . '_invoice_items';
        $invoice_ids = array_column($invoices, 'id');
        $invoice_ids_str = implode(',', array_map('intval', $invoice_ids));
    
        $items_by_invoice = [];
        $items_res = $this->conn->query("SELECT * FROM `$item_table` WHERE invoiceId IN ($invoice_ids_str)");
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $items_by_invoice[$item['invoiceId']][] = $item;
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
            $invoice['items'] = $items_by_invoice[$invId] ?? [];
            
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