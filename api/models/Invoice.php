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
            $this->select = "SELECT si.*, c.name as customerName, (si.totalAmount - si.discount - si.paidAmount) as remainingAmount";
            $this->from = "FROM `sales_invoices` as si";
            $this->join = "LEFT JOIN customers c ON si.customerId = c.id";
            $this->where = $is_consignment ? "WHERE si.is_consignment = 1" : "WHERE (si.is_consignment = 0 OR si.is_consignment IS NULL)";
            $this->allowedFilters = ['si.id', 'c.name', 'si.totalAmount'];
            $this->allowedSorts = ['id', 'date', 'customerName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
        } else {
            $this->tableName = 'purchase_invoices';
            $this->alias = 'pi';
            $this->select = "SELECT pi.*, s.name as supplierName, (pi.totalAmount - pi.discount - pi.paidAmount) as remainingAmount";
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
            $is_sales = strpos($input['tableName'], 'sales') !== false;
            $paginatedResult['data'] = $this->applyFifoToInvoices($paginatedResult['data'], $is_sales);
            $paginatedResult['data'] = $this->fetchInvoiceDetails($paginatedResult['data'], $is_sales ? 'sales' : 'purchase');
        }

        return $paginatedResult;
    }

    private function applyFifoToInvoices($invoices, $isSales) {
        if (empty($invoices)) {
            return [];
        }

        $personType = $isSales ? 'customer' : 'supplier';
        $personIdColumn = $isSales ? 'customerId' : 'supplierId';
        
        $personIds = array_unique(array_column($invoices, $personIdColumn));
        if (empty($personIds)) {
            return $invoices;
        }

        $ids_placeholder = implode(',', array_fill(0, count($personIds), '?'));
        $types = str_repeat('i', count($personIds));
        
        $credit_sql_logic = $isSales 
            ? "SUM(IF(transaction_type = 'receipt', amount, -amount))" 
            : "SUM(IF(transaction_type = 'payment', amount, -amount))";

        $entity_id = $_SESSION['current_entity_id'];
        $credit_stmt = $this->conn->prepare("SELECT person_id, {$credit_sql_logic} as available_credit FROM payments WHERE entity_id = ? AND person_type = ? AND invoiceId IS NULL AND person_id IN ({$ids_placeholder}) GROUP BY person_id");
        $credit_stmt->bind_param("is" . $types, $entity_id, $personType, ...$personIds);
        $credit_stmt->execute();
        $creditsByPerson = [];
        foreach (db_stmt_to_assoc_array($credit_stmt) as $row) {
            $creditsByPerson[$row['person_id']] = (float)$row['available_credit'];
        }
        
        $unsettledInvoicesByPerson = [];
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $inv_stmt = $this->conn->prepare("SELECT id, {$personIdColumn}, date, (totalAmount - discount - paidAmount) as remaining FROM `{$invoiceTable}` WHERE entity_id = ? AND {$personIdColumn} IN ({$ids_placeholder}) AND (totalAmount - discount - paidAmount) > 0.01 ORDER BY date ASC, id ASC");
        $inv_stmt->bind_param("i" . $types, $entity_id, ...$personIds);
        $inv_stmt->execute();
        $unsettled_res = db_stmt_to_assoc_array($inv_stmt);
        foreach ($unsettled_res as $row) {
            $unsettledInvoicesByPerson[$row[$personIdColumn]][] = $row;
        }

        $appliedCredits = [];
        foreach ($unsettledInvoicesByPerson as $personId => $unsettledInvoices) {
            $availableCredit = $creditsByPerson[$personId] ?? 0;
            if ($availableCredit <= 0) continue;

            foreach ($unsettledInvoices as $unsettled) {
                if ($availableCredit <= 0) break;
                $amountToApply = min($availableCredit, (float)$unsettled['remaining']);
                $appliedCredits[$unsettled['id']] = $amountToApply;
                $availableCredit -= $amountToApply;
            }
        }

        foreach ($invoices as &$invoice) {
            if (isset($appliedCredits[$invoice['id']])) {
                $invoice['remainingAmount'] -= $appliedCredits[$invoice['id']];
            }
        }
        unset($invoice);
        
        return $invoices;
    }
    
    private function saveInvoiceLogic($data, $type) {
        $isSales = $type === 'sales';
        $requiredField = $isSales ? 'customerId' : 'supplierId';
        if (empty($data[$requiredField]) || empty($data['date']) || !isset($data['items'])) {
            return ['error' => 'اطلاعات فاکتور (شخص، تاریخ و اقلام) ناقص است.', 'statusCode' => 400];
        }
        $entity_id = $_SESSION['current_entity_id'];

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
                $stmt = $this->conn->prepare("INSERT INTO `{$table}` (entity_id, {$personColumn}, date, totalAmount, discount, paidAmount, description, is_consignment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $is_consignment = $data['is_consignment'] ?? 0;
                $stmt->bind_param("iisdddsi", $entity_id, $data[$requiredField], $data['date'], $data['totalAmount'], $data['discount'], $data['paidAmount'], $data['description'], $is_consignment);
            } else {
                $stmt = $this->conn->prepare("UPDATE `{$table}` SET {$personColumn}=?, date=?, totalAmount=?, discount=?, paidAmount=?, description=? WHERE id=?");
                $stmt->bind_param("isdddsi", $data[$requiredField], $data['date'], $data['totalAmount'], $data['discount'], $data['paidAmount'], $data['description'], $invoiceId);
            }
            $stmt->execute();
            if ($is_new) {
                $invoiceId = $this->conn->insert_id;
            }
            $stmt->close();
            
            if ($is_new) {
                $this->processNewInvoiceItems($invoiceId, $type, $data['items']);
            } else {
                $this->processUpdatedInvoiceItems($invoiceId, $type, $data['items'], $oldInvoice['items'] ?? []);
            }
            
            $this->processPayments($invoiceId, $type, $data['payments'], $oldInvoice['payments'] ?? [], $data[$requiredField]);

            $this->conn->commit();
            $logAction = $isSales ? 'SAVE_SALES_INVOICE' : 'SAVE_PURCHASE_INVOICE';
            $logMessage = "فاکتور " . ($isSales ? "فروش" : "خرید") . " به شناسه {$invoiceId} " . ($is_new ? "ایجاد شد." : "ویرایش شد.");
            log_activity($this->conn, $logAction, $logMessage);
            return ['success' => true, 'id' => $invoiceId];

        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    private function processNewInvoiceItems($invoiceId, $type, $newItems) {
        $isSales = $type === 'sales';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';

        foreach ($newItems as $itemData) {
            $productId = $itemData['productId'] ?? 0;
            $itemData['dimensions'] = trim($itemData['dimensions']);

            if (!$isSales && isset($itemData['newProductName']) && !empty(trim($itemData['newProductName']))) {
                $entity_id = $_SESSION['current_entity_id'];
                $stmt_new = $this->conn->prepare("INSERT INTO products (entity_id, name) VALUES (?, ?)");
                $stmt_new->bind_param("is", $entity_id, $itemData['newProductName']);
                $stmt_new->execute();
                $productId = $this->conn->insert_id;
                $stmt_new->close();
            }
            if (!$productId) continue;
            
            $stockAdjust = $isSales ? -$itemData['quantity'] : $itemData['quantity'];
            $this->updateStock($productId, $itemData['dimensions'], $stockAdjust, $isSales);

            $stmt_item = $this->conn->prepare("INSERT INTO `{$itemTable}` (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, ?, ?, ?)");
            $stmt_item->bind_param("iiids", $invoiceId, $productId, $itemData['quantity'], $itemData['unitPrice'], $itemData['dimensions']);
            $stmt_item->execute();
            $stmt_item->close();
        }
    }

    private function processUpdatedInvoiceItems($invoiceId, $type, $newItems, $oldItems) {
        $isSales = $type === 'sales';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        
        $oldItemsMap = [];
        foreach ($oldItems as $item) {
            $key = $item['productId'] . '::' . trim($item['dimensions']);
            $oldItemsMap[$key] = $item;
        }

        $newItemsMap = [];
        foreach ($newItems as $itemData) {
            $productId = $itemData['productId'] ?? 0;
            if (!$productId) continue; 
            
            $itemData['dimensions'] = trim($itemData['dimensions']);
            $key = $productId . '::' . $itemData['dimensions'];
            $newItemsMap[$key] = $itemData;

            $oldQty = $oldItemsMap[$key]['quantity'] ?? 0;
            $newQty = $itemData['quantity'];
            $qtyDiff = $newQty - $oldQty;

            if ($qtyDiff !== 0) {
                $stockAdjust = $isSales ? -$qtyDiff : $qtyDiff;
                $this->updateStock($productId, $itemData['dimensions'], $stockAdjust, $isSales && $qtyDiff > 0);
            }

            if (isset($oldItemsMap[$key])) {
                $stmt_item = $this->conn->prepare("UPDATE `{$itemTable}` SET quantity = ?, unitPrice = ? WHERE id = ?");
                $stmt_item->bind_param("idi", $newQty, $itemData['unitPrice'], $oldItemsMap[$key]['id']);
                $stmt_item->execute();
                $stmt_item->close();
            } else {
                $stmt_item = $this->conn->prepare("INSERT INTO `{$itemTable}` (invoiceId, productId, quantity, unitPrice, dimensions) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->bind_param("iiids", $invoiceId, $productId, $newQty, $itemData['unitPrice'], $itemData['dimensions']);
                $stmt_item->execute();
                $stmt_item->close();
            }
        }

        foreach ($oldItemsMap as $key => $oldItem) {
            if (!isset($newItemsMap[$key])) {
                $stockAdjust = $isSales ? $oldItem['quantity'] : -$oldItem['quantity'];
                $this->updateStock($oldItem['productId'], $oldItem['dimensions'], $stockAdjust, false);
                
                $stmt_del = $this->conn->prepare("DELETE FROM `{$itemTable}` WHERE id = ?");
                $stmt_del->bind_param("i", $oldItem['id']);
                $stmt_del->execute();
                $stmt_del->close();
            }
        }
    }
    
    private function processPayments($invoiceId, $type, $newPayments, $oldPayments, $personId) {
        $isSales = $type === 'sales';
        $accountModel = new Account($this->conn);
        $personType = $isSales ? 'customer' : 'supplier';

        if (!empty($oldPayments)) {
            foreach ($oldPayments as $p) {
                if ($p['type'] === 'cash' && $p['account_id']) {
                    $amountToRevert = $isSales ? -$p['amount'] : $p['amount'];
                    $accountModel->updateBalance($p['account_id'], $amountToRevert);
                } elseif ($p['type'] === 'endorse_check' && $p['checkId']) {
                    $stmt_revert_check = $this->conn->prepare("UPDATE checks SET status = 'in_hand', endorsedToInvoiceId = NULL WHERE id = ?");
                    $stmt_revert_check->bind_param("i", $p['checkId']);
                    $stmt_revert_check->execute();
                    $stmt_revert_check->close();
                }
            }
            $stmt_del_pays = $this->conn->prepare("DELETE FROM payments WHERE invoiceId = ? AND invoiceType = ?");
            $stmt_del_pays->bind_param("is", $invoiceId, $type);
            $stmt_del_pays->execute();
            $stmt_del_pays->close();
            
            $stmt_del_checks = $this->conn->prepare("DELETE FROM checks WHERE invoiceId = ? AND invoiceType = ? AND type = ?");
            $checkType = $isSales ? 'received' : 'payable';
            $stmt_del_checks->bind_param("iss", $invoiceId, $type, $checkType);
            $stmt_del_checks->execute();
            $stmt_del_checks->close();
        }

        $this->savePaymentsAndTransactions($type, $invoiceId, $newPayments, $personId, $personType);
    }
    
    public function saveSalesInvoice($data) {
        return $this->saveInvoiceLogic($data, 'sales');
    }

    public function savePurchaseInvoice($data) {
        return $this->saveInvoiceLogic($data, 'purchase');
    }
    
    private function updateStock($productId, $dimensions, $quantityChange, $useLock = false) {
        if ($quantityChange == 0) return;

        // **FIX START**: Rewritten logic to handle NULL in UNIQUE KEY.
        // First, check if the record exists. warehouse_id is assumed NULL.
        $stmt_select = $this->conn->prepare("SELECT id, quantity FROM product_stock WHERE product_id = ? AND dimensions = ? AND warehouse_id IS NULL");
        $stmt_select->bind_param("is", $productId, $dimensions);
        $stmt_select->execute();
        $result = db_stmt_to_assoc_array($stmt_select);
        $existing_stock = $result[0] ?? null;

        if ($useLock) {
            $currentStock = $existing_stock ? $existing_stock['quantity'] : 0;
            if ($currentStock < abs($quantityChange)) {
                 throw new Exception("موجودی محصول با شناسه {$productId} و ابعاد {$dimensions} کافی نیست. موجودی فعلی: {$currentStock} عدد.", 400);
            }
        }
        
        if ($existing_stock) {
            // Record exists, UPDATE it.
            $stmt_update = $this->conn->prepare("UPDATE product_stock SET quantity = quantity + ? WHERE id = ?");
            $stmt_update->bind_param("ii", $quantityChange, $existing_stock['id']);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            // Record does not exist, INSERT it.
            $stmt_insert = $this->conn->prepare("INSERT INTO product_stock (product_id, dimensions, quantity) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("isi", $productId, $dimensions, $quantityChange);
            $stmt_insert->execute();
            $stmt_insert->close();
        }
        // **FIX END**
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
        $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';

        $fullInvoice = $this->fetchInvoiceDetails([['id' => $id]], $type)[0] ?? null;

        if (!$fullInvoice) {
            throw new Exception("فاکتور مورد نظر یافت نشد.");
        }

        if (isset($fullInvoice['payments'])) {
            foreach($fullInvoice['payments'] as $payment) {
                if ($payment['type'] === 'cash' && $payment['account_id']) {
                    $amountToRevert = $isSales ? -$payment['amount'] : $payment['amount'];
                    $accountModel->updateBalance($payment['account_id'], $amountToRevert);
                } else if ($payment['type'] === 'endorse_check' && $payment['checkId']) {
                    $stmt_revert_check = $this->conn->prepare("UPDATE checks SET status = 'in_hand', endorsedToInvoiceId = NULL WHERE id = ?");
                    $stmt_revert_check->bind_param("i", $payment['checkId']);
                    $stmt_revert_check->execute();
                    $stmt_revert_check->close();
                }
            }
        }
        
        if(isset($fullInvoice['items'])) {
            foreach ($fullInvoice['items'] as $item) {
                $stockAdjust = $isSales ? $item['quantity'] : -$item['quantity'];
                $this->updateStock($item['productId'], $item['dimensions'], $stockAdjust);
            }
        }
        
        $stmt_del_pays = $this->conn->prepare("DELETE FROM payments WHERE invoiceId = ? AND invoiceType = ?");
        $stmt_del_pays->bind_param("is", $id, $type);
        $stmt_del_pays->execute();
        $stmt_del_pays->close();
        
        $stmt_del_checks = $this->conn->prepare("DELETE FROM checks WHERE invoiceId = ? AND invoiceType = ?");
        $stmt_del_checks->bind_param("is", $id, $type);
        $stmt_del_checks->execute();
        $stmt_del_checks->close();

        $stmt_del_inv = $this->conn->prepare("DELETE FROM `{$invoiceTable}` WHERE id = ?");
        $stmt_del_inv->bind_param("i", $id);
        $stmt_del_inv->execute();
        $stmt_del_inv->close();
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

    private function savePaymentsAndTransactions($invoiceType, $invoiceId, $payments, $personId, $personType) {
        $accountModel = new Account($this->conn);
        $isSales = $invoiceType === 'sales';
        $transactionType = $isSales ? 'receipt' : 'payment';
        $entity_id = $_SESSION['current_entity_id'];

        foreach ($payments as $payment) {
            $checkId = null;
            $accountId = $payment['account_id'] ?? null;

            if ($payment['type'] === 'check' && isset($payment['checkDetails'])) {
                $checkType = $isSales ? 'received' : 'payable';
                $checkStatus = $isSales ? 'in_hand' : 'payable';
                $stmt_check = $this->conn->prepare("INSERT INTO checks (entity_id, type, invoiceId, invoiceType, checkNumber, dueDate, bankName, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_check->bind_param("isissssds", $entity_id, $checkType, $invoiceId, $invoiceType, $payment['checkDetails']['checkNumber'], $payment['checkDetails']['dueDate'], $payment['checkDetails']['bankName'], $payment['amount'], $checkStatus);
                $stmt_check->execute();
                $checkId = $this->conn->insert_id;
                $stmt_check->close();
            } else if ($payment['type'] === 'endorse_check') {
                $checkId = intval($payment['checkId']);
                $stmt_endorse = $this->conn->prepare("UPDATE checks SET status = 'endorsed', endorsedToInvoiceId = ? WHERE id = ?");
                $stmt_endorse->bind_param("ii", $invoiceId, $checkId);
                $stmt_endorse->execute();
                $stmt_endorse->close();
            }

            if($payment['type'] === 'cash') {
                if (empty($accountId)) {
                    throw new Exception("برای پرداخت نقدی، انتخاب حساب الزامی است.", 400);
                }
                $adjustedAmount = $isSales ? $payment['amount'] : -$payment['amount'];
                $accountModel->updateBalance($accountId, $adjustedAmount);
            }

            $stmt_pay = $this->conn->prepare(
                "INSERT INTO payments (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt_pay->bind_param("iisisssdssii", $entity_id, $invoiceId, $invoiceType, $personId, $personType, $transactionType, $payment['type'], $payment['amount'], $payment['date'], $payment['description'], $checkId, $accountId);
            $stmt_pay->execute();
            $stmt_pay->close();
        }
    }
    
    public function fetchInvoiceDetails($invoices, $invoice_type) {
        if (empty($invoices)) return [];
    
        $item_table = $invoice_type . '_invoice_items';
        $invoice_ids = array_column($invoices, 'id');
        
        $prepare_in_clause = function(array $ids, string &$types) {
            if (empty($ids)) return ['', []];
            $placeholder = implode(',', array_fill(0, count($ids), '?'));
            $types .= str_repeat('i', count($ids));
            return [$placeholder, $ids];
        };
        
        $params = [];
        $types = '';
        list($ids_placeholder, $id_params) = $prepare_in_clause($invoice_ids, $types);
        $params = array_merge($params, $id_params);
        if (empty($ids_placeholder)) return $invoices;

        $items_by_invoice = [];
        $items_sql = "
            SELECT ii.*, p.name as productName 
            FROM `{$item_table}` ii 
            LEFT JOIN products p ON ii.productId = p.id 
            WHERE ii.invoiceId IN ($ids_placeholder)
        ";
        $stmt_items = $this->conn->prepare($items_sql);
        $stmt_items->bind_param($types, ...$params);
        $stmt_items->execute();
        $items_res = db_stmt_to_assoc_array($stmt_items);
        foreach ($items_res as $item) {
            $items_by_invoice[$item['invoiceId']][] = $item;
        }
    
        $payments_by_invoice = [];
        $all_check_ids = [];
        $stmt_payments = $this->conn->prepare("SELECT * FROM payments WHERE invoiceType = ? AND invoiceId IN ($ids_placeholder)");
        $stmt_payments->bind_param('s' . $types, $invoice_type, ...$params);
        $stmt_payments->execute();
        $payments_res = db_stmt_to_assoc_array($stmt_payments);
        foreach ($payments_res as $payment) {
            $payments_by_invoice[$payment['invoiceId']][] = $payment;
            if (!empty($payment['checkId'])) {
                $all_check_ids[] = intval($payment['checkId']);
            }
        }
    
        $checks_by_id = [];
        if (!empty($all_check_ids)) {
            $unique_check_ids = array_unique($all_check_ids);
            $check_params = [];
            $check_types = '';
            list($check_ids_placeholder, $check_id_params) = $prepare_in_clause($unique_check_ids, $check_types);
            $check_params = array_merge($check_params, $check_id_params);

            if (!empty($check_ids_placeholder)) {
                $stmt_checks = $this->conn->prepare("SELECT * FROM checks WHERE id IN ($check_ids_placeholder)");
                $stmt_checks->bind_param($check_types, ...$check_params);
                $stmt_checks->execute();
                $checks_res = db_stmt_to_assoc_array($stmt_checks);
                foreach ($checks_res as $check) {
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
            
            if (!isset($invoice['remainingAmount'])) {
                 $invoice['remainingAmount'] = ($invoice['totalAmount'] ?? 0) - ($invoice['discount'] ?? 0) - ($invoice['paidAmount'] ?? 0);
            }
           
            $processed_invoices[] = $invoice;
        }
    
        return $processed_invoices;
    }
}