<?php
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Product.php';

class Invoice extends BaseModel {
    protected $tableName = 'sales_invoices';
    protected $alias = 'si';
    protected $select = "SELECT si.*, c.name as customerName, (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) as remainingAmount";
    protected $from = "FROM `sales_invoices` as si";
    protected $join = "LEFT JOIN customers c ON si.customerId = c.id";
    protected $where = "WHERE (si.is_consignment = 0 OR si.is_consignment IS NULL)";
    protected $allowedFilters = ['si.id', 'c.name', 'si.totalAmount', 'si.source', 'si.invoice_number', 'si.description'];
    protected $allowedSorts = ['id', 'date', 'customerName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
    protected $hasEntityId = true;

    public function __construct($db, $type = 'sales', $is_consignment = false) {
        parent::__construct($db);
        $this->configure($type, $is_consignment);
    }

    public function configure($type, $is_consignment) {
        if ($type === 'sales') {
            $this->tableName = 'sales_invoices';
            $this->alias = 'si';
            $this->select = "SELECT si.*, c.name as customerName, (si.totalAmount - si.discount + COALESCE(si.tax_amount, 0) - si.paidAmount) as remainingAmount";
            $this->from = "FROM `sales_invoices` as si";
            $this->join = "LEFT JOIN customers c ON si.customerId = c.id";
            $this->where = $is_consignment ? "WHERE si.is_consignment = 1" : "WHERE (si.is_consignment = 0 OR si.is_consignment IS NULL)";
            $this->allowedFilters = ['si.id', 'c.name', 'si.totalAmount', 'si.source', 'si.invoice_number', 'si.description'];
            $this->allowedSorts = ['id', 'date', 'customerName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
            $this->serialSearchExistsSql = "
                SELECT 1 FROM sales_invoice_items sii_serial
                INNER JOIN inventory_items ii_serial ON (
                    ii_serial.sales_invoice_item_id = sii_serial.id
                    OR ii_serial.returned_from_sales_item_id = sii_serial.id
                )
                WHERE sii_serial.invoiceId = si.id
                  AND (ii_serial.serial_id LIKE ? OR CONCAT('INV-', LPAD(ii_serial.id, 6, '0')) LIKE ?)
            ";
            $this->invoiceItemSearchExistsSql = "
                SELECT 1 FROM sales_invoice_items sii_item
                LEFT JOIN products p_item ON sii_item.productId = p_item.id
                WHERE sii_item.invoiceId = si.id
                  AND (p_item.name LIKE ? OR sii_item.dimensions LIKE ?)
            ";
        } else {
            $this->tableName = 'purchase_invoices';
            $this->alias = 'pi';
            $this->ensurePurchaseWarehouseColumn();
            $this->ensureWarehouseTypeColumn();
            $this->select = "SELECT pi.*, s.name as supplierName, w.name as warehouseName, w.warehouse_type as warehouseType,
                IF(w.warehouse_type = 'order', 1, 0) as is_order_warehouse,
                (pi.totalAmount - pi.discount + COALESCE(pi.tax_amount, 0) - pi.paidAmount) as remainingAmount";
            $this->from = "FROM `purchase_invoices` as pi";
            $this->join = "LEFT JOIN suppliers s ON pi.supplierId = s.id LEFT JOIN warehouses w ON pi.warehouse_id = w.id";
            $this->where = $is_consignment ? "WHERE pi.is_consignment = 1" : "WHERE (pi.is_consignment = 0 OR pi.is_consignment IS NULL)";
            $this->allowedFilters = ['pi.id', 's.name', 'pi.totalAmount', 'pi.invoice_number', 'pi.description', 'w.name'];
            $this->allowedSorts = ['id', 'date', 'supplierName', 'totalAmount', 'discount', 'paidAmount', 'remainingAmount'];
            $this->serialSearchExistsSql = "
                SELECT 1 FROM purchase_invoice_items pii_serial
                INNER JOIN inventory_items ii_serial ON ii_serial.purchase_invoice_item_id = pii_serial.id
                WHERE pii_serial.invoiceId = pi.id
                  AND (ii_serial.serial_id LIKE ? OR CONCAT('INV-', LPAD(ii_serial.id, 6, '0')) LIKE ?)
            ";
            $this->invoiceItemSearchExistsSql = "
                SELECT 1 FROM purchase_invoice_items pii_item
                LEFT JOIN products p_item ON pii_item.productId = p_item.id
                WHERE pii_item.invoiceId = pi.id
                  AND (p_item.name LIKE ? OR pii_item.dimensions LIKE ?)
            ";
        }
        $this->from = "{$this->from}";
        if ($type === 'sales') {
            $this->ensureReturnedFromSalesColumn();
        }
    }

    protected function getOrderByClause($sortBy) {
        if ($sortBy === 'remainingAmount') return '(`totalAmount` - `discount` + COALESCE(`tax_amount`, 0) - `paidAmount`)';
        if ($sortBy === 'customerName') return 'c.name';
        if ($sortBy === 'supplierName') return 's.name';
        return "`{$this->alias}`.`$sortBy`";
    }

    public function getPaginated($input) {
        $this->configure(
            strpos($input['tableName'], 'sales') !== false ? 'sales' : 'purchase',
            strpos($input['tableName'], 'consignment') !== false
        );

        $filterType = $input['filterType'] ?? '';
        $overdueRemaining = null;
        if ($filterType === 'overdue_unsettled' && strpos($input['tableName'], 'sales') !== false) {
            // Same source as the dashboard alert: open against the customer's statement balance
            require_once __DIR__ . '/ReportModel.php';
            $overdueDays = defined('INVOICE_OVERDUE_DAYS') ? INVOICE_OVERDUE_DAYS : 30;
            $overdueDate = date('Y-m-d', strtotime("-{$overdueDays} days"));
            $report = new ReportModel($this->conn);
            $overdueRemaining = array_column($report->getOverdueUnsettledSalesInvoices($_SESSION['current_entity_id'], $overdueDate), 'remaining', 'id');
            $this->where .= empty($overdueRemaining)
                ? " AND 0"
                : " AND si.id IN (" . implode(',', array_map('intval', array_keys($overdueRemaining))) . ")";
        }
        if ($filterType === 'orphan_items' && strpos($input['tableName'], 'sales') !== false) {
            $this->where .= " AND EXISTS (SELECT 1 FROM sales_invoice_items sii_check LEFT JOIN products p_check ON sii_check.productId = p_check.id WHERE sii_check.invoiceId = si.id AND sii_check.is_service = 0 AND p_check.id IS NULL)";
        }
        if ($filterType === 'missing_serials' && strpos($input['tableName'], 'purchase') !== false) {
            $this->where .= " AND EXISTS (SELECT 1 FROM purchase_invoice_items pii WHERE pii.invoiceId = pi.id AND pii.is_service = 0 AND (SELECT COUNT(*) FROM inventory_items ii WHERE ii.purchase_invoice_item_id = pii.id) < pii.quantity)";
        }
        if ($filterType === 'sales_without_serials' && strpos($input['tableName'], 'sales') !== false) {
            $this->where .= " AND EXISTS (SELECT 1 FROM sales_invoice_items sii_ns WHERE sii_ns.invoiceId = si.id AND sii_ns.is_service = 0 AND NOT EXISTS (SELECT 1 FROM inventory_items ii WHERE ii.sales_invoice_item_id = sii_ns.id OR ii.returned_from_sales_item_id = sii_ns.id) AND NOT EXISTS (SELECT 1 FROM return_invoice_items rii_ns WHERE rii_ns.original_invoice_item_id = sii_ns.id))";
        }
        if ($filterType === 'negative_total') {
            $isSales = strpos($input['tableName'], 'sales') !== false;
            $alias = $isSales ? 'si' : 'pi';
            $itemsTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
            $itemAlias = $isSales ? 'sii_pricing' : 'pii_pricing';
            $invoiceIdCol = $isSales ? 'invoiceId' : 'invoiceId';
            $this->where .= " AND (
                ({$alias}.totalAmount - {$alias}.discount) <= 0
                OR EXISTS (
                    SELECT 1 FROM {$itemsTable} {$itemAlias}
                    WHERE {$itemAlias}.{$invoiceIdCol} = {$alias}.id
                      AND {$itemAlias}.is_service = 0
                      AND {$itemAlias}.unitPrice <= 0
                )
            )";
        }
        if ($filterType === 'online_store' && strpos($input['tableName'], 'sales') !== false) {
            $this->where .= " AND si.source = 'online_store'";
        }
        if ($filterType === 'order_warehouse' && strpos($input['tableName'], 'purchase') !== false) {
            $this->where .= " AND w.warehouse_type = 'order'";
        }

        // Delivery status filter
        $deliveryStatusFilter = $input['deliveryStatusFilter'] ?? '';
        if (in_array($deliveryStatusFilter, ['preparing', 'ready', 'delivered'])) {
            $alias = strpos($input['tableName'], 'sales') !== false ? 'si' : 'pi';
            $this->where .= " AND {$alias}.delivery_status = '" . $this->conn->real_escape_string($deliveryStatusFilter) . "'";
        }

        // Purchase warehouse type filter (order = سفارش به کارخانه)
        $warehouseTypeFilter = $input['warehouseTypeFilter'] ?? '';
        if ($warehouseTypeFilter === 'order' && strpos($input['tableName'], 'purchase') !== false) {
            $this->where .= " AND w.warehouse_type = 'order'";
        } elseif ($warehouseTypeFilter === 'physical' && strpos($input['tableName'], 'purchase') !== false) {
            $this->where .= " AND (pi.warehouse_id IS NULL OR pi.warehouse_id = 0 OR w.warehouse_type IS NULL OR w.warehouse_type <> 'order')";
        }

        // Exact invoice ID filter (for dashboard navigation)
        $exactInvoiceId = $input['exactInvoiceId'] ?? null;
        if ($exactInvoiceId !== null && is_numeric($exactInvoiceId)) {
            $alias = strpos($input['tableName'], 'sales') !== false ? 'si' : 'pi';
            $this->where .= " AND {$alias}.id = " . intval($exactInvoiceId);
        }

        $paginatedResult = parent::getPaginated($input);
        if (!empty($paginatedResult['data'])) {
            $is_sales = strpos($input['tableName'], 'sales') !== false;
            $paginatedResult['data'] = $this->applyFifoToInvoices($paginatedResult['data'], $is_sales);
            if ($overdueRemaining !== null) {
                foreach ($paginatedResult['data'] as &$row) {
                    $row['remainingAmount'] = $overdueRemaining[(int)$row['id']] ?? $row['remainingAmount'];
                }
                unset($row);
            }
            $paginatedResult['data'] = $this->fetchInvoiceDetails($paginatedResult['data'], $is_sales ? 'sales' : 'purchase');
        }
        return $paginatedResult;
    }

    private function applyFifoToInvoices($invoices, $isSales) {
        if (empty($invoices)) return [];
        $personType = $isSales ? 'customer' : 'supplier';
        $personIdColumn = $isSales ? 'customerId' : 'supplierId';
        $personIds = array_unique(array_column($invoices, $personIdColumn));
        if (empty($personIds)) return $invoices;
        $ids_placeholder = implode(',', array_fill(0, count($personIds), '?'));
        $types = str_repeat('i', count($personIds));
        $credit_sql_logic = $isSales ? "SUM(IF(transaction_type = 'receipt', amount, -amount))" : "SUM(IF(transaction_type = 'payment', amount, -amount))";
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
        $inv_stmt = $this->conn->prepare("SELECT id, {$personIdColumn}, date, (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) as remaining FROM `{$invoiceTable}` WHERE entity_id = ? AND {$personIdColumn} IN ({$ids_placeholder}) AND (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) > " . MINIMUM_AMOUNT . " ORDER BY date ASC, id ASC");
        $inv_stmt->bind_param("i" . $types, $entity_id, ...$personIds);
        $inv_stmt->execute();
        $unsettled_res = db_stmt_to_assoc_array($inv_stmt);
        foreach ($unsettled_res as $row) {
            $unsettledInvoicesByPerson[$row[$personIdColumn]][] = $row;
        }
        $appliedCredits = [];
        foreach ($unsettledInvoicesByPerson as $personId => $allUnsettledInvoices) {
            $availableCredit = $creditsByPerson[$personId] ?? 0;
            if ($availableCredit <= 0) continue;
            foreach ($allUnsettledInvoices as $unsettled) {
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

    private function checkIdentifiersReturned($item_ids, $invoice_type) {
        if (empty($item_ids)) return [];
        $col_name = $invoice_type === 'sales' ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
        $item_id_placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $item_id_types = str_repeat('i', count($item_ids));
        $stmt = $this->conn->prepare("SELECT {$col_name}, COUNT(*) as returned_count FROM inventory_items WHERE {$col_name} IN ({$item_id_placeholders}) AND status = 'returned' GROUP BY {$col_name}");
        $stmt->bind_param($item_id_types, ...$item_ids);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        return array_column($result, $col_name);
    }

    /**
     * Keep original sales invoice serials visible after return / resale.
     */
    private function attachReturnedSerialsFromReturnInvoices($item_ids, array &$returned_ids_by_item, array &$returned_labels_by_item) {
        if (empty($item_ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        $hasStoredIds = false;
        $colCheck = $this->conn->query("SHOW COLUMNS FROM return_invoice_items LIKE 'inventory_item_ids'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $hasStoredIds = true;
        }
        $storedSelect = $hasStoredIds ? ', rii.inventory_item_ids' : '';
        $stmt = $this->conn->prepare(
            "SELECT rii.original_invoice_item_id, rii.productId, rii.dimensions, rii.quantity{$storedSelect}
             FROM return_invoice_items rii
             INNER JOIN return_invoices ri ON ri.id = rii.return_invoice_id AND ri.type = 'sales_return'
             WHERE rii.original_invoice_item_id IN ({$placeholders})"
        );
        $stmt->bind_param($types, ...$item_ids);
        $stmt->execute();
        $returnRows = db_stmt_to_assoc_array($stmt);

        foreach ($returnRows as $row) {
            $origItemId = intval($row['original_invoice_item_id']);
            $inventoryIds = [];
            if ($hasStoredIds && !empty($row['inventory_item_ids'])) {
                $inventoryIds = array_values(array_filter(array_map('intval', explode(',', $row['inventory_item_ids']))));
            }
            if (empty($inventoryIds)) {
                $productId = intval($row['productId']);
                $dimensions = $row['dimensions'] ?? '';
                $qty = intval($row['quantity']);
                $stmtInv = $this->conn->prepare(
                    "SELECT id, serial_id, status, sales_invoice_item_id, returned_from_sales_item_id
                     FROM inventory_items
                     WHERE (sales_invoice_item_id = ? OR returned_from_sales_item_id = ?)
                        OR (product_id = ? AND dimensions = ? AND sales_invoice_item_id IS NOT NULL AND sales_invoice_item_id <> ?)
                     ORDER BY id ASC"
                );
                $stmtInv->bind_param('iiisi', $origItemId, $origItemId, $productId, $dimensions, $origItemId);
                $stmtInv->execute();
                $candidates = db_stmt_to_assoc_array($stmtInv);
                $direct = [];
                $resold = [];
                foreach ($candidates as $inv) {
                    $invId = intval($inv['id']);
                    $currentSale = intval($inv['sales_invoice_item_id'] ?? 0);
                    $originSale = intval($inv['returned_from_sales_item_id'] ?? 0);
                    if ($currentSale === $origItemId || $originSale === $origItemId) {
                        $direct[] = $inv;
                    } else {
                        $resold[] = $inv;
                    }
                    unset($invId);
                }
                $picked = $direct;
                if (count($picked) < $qty && count($resold) === ($qty - count($picked))) {
                    $picked = array_merge($picked, $resold);
                }
                foreach ($picked as $inv) {
                    $inventoryIds[] = intval($inv['id']);
                    $label = $inv['serial_id'] ?: ('INV-' . str_pad($inv['id'], 6, '0', STR_PAD_LEFT));
                    if (!in_array(intval($inv['id']), $returned_ids_by_item[$origItemId] ?? [], true)) {
                        $returned_ids_by_item[$origItemId][] = intval($inv['id']);
                        $returned_labels_by_item[$origItemId][] = $label;
                    }
                    if (intval($inv['returned_from_sales_item_id'] ?? 0) === 0 && intval($inv['sales_invoice_item_id'] ?? 0) !== $origItemId) {
                        $fixId = intval($inv['id']);
                        $stmtFix = $this->conn->prepare("UPDATE inventory_items SET returned_from_sales_item_id = ? WHERE id = ? AND returned_from_sales_item_id IS NULL");
                        $stmtFix->bind_param('ii', $origItemId, $fixId);
                        $stmtFix->execute();
                        $stmtFix->close();
                    }
                }
                continue;
            }

            if (empty($inventoryIds)) {
                continue;
            }
            $idList = implode(',', $inventoryIds);
            $stmtLabels = $this->conn->prepare("SELECT id, serial_id FROM inventory_items WHERE id IN ({$idList})");
            $stmtLabels->execute();
            foreach (db_stmt_to_assoc_array($stmtLabels) as $inv) {
                $label = $inv['serial_id'] ?: ('INV-' . str_pad($inv['id'], 6, '0', STR_PAD_LEFT));
                if (!in_array(intval($inv['id']), $returned_ids_by_item[$origItemId] ?? [], true)) {
                    $returned_ids_by_item[$origItemId][] = intval($inv['id']);
                    $returned_labels_by_item[$origItemId][] = $label;
                }
            }
        }
    }

    public function fetchInvoiceDetails($invoices, $invoice_type) {
        if (empty($invoices)) return [];
        $item_table = $invoice_type . '_invoice_items';
        $invoice_ids = array_column($invoices, 'id');
        $prepare_in_clause = function(array $ids) {
            if (empty($ids)) return ['', [], ''];
            $placeholder = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            return [$placeholder, $ids, $types];
        };
        list($ids_placeholder, $id_params, $types) = $prepare_in_clause($invoice_ids);
        if (empty($ids_placeholder)) return $invoices;

        $items_by_invoice = [];
        $items_sql = "SELECT ii.*, p.name as productName,
            (SELECT COALESCE(NULLIF(pi.thumb_path, ''), pi.file_path)
             FROM product_images pi
             WHERE pi.product_id = ii.productId
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
             LIMIT 1) AS primary_image,
            (SELECT pi.file_path
             FROM product_images pi
             WHERE pi.product_id = ii.productId
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
             LIMIT 1) AS primary_full_image
            FROM `{$item_table}` ii
            LEFT JOIN products p ON ii.productId = p.id
            WHERE ii.invoiceId IN ($ids_placeholder)";
        $stmt_items = $this->conn->prepare($items_sql);
        $stmt_items->bind_param($types, ...$id_params);
        $stmt_items->execute();
        $items_res = db_stmt_to_assoc_array($stmt_items);

        $item_ids = array_values(array_map('intval', array_column(array_filter($items_res, function($it) { return empty($it['is_service']); }), 'id')));
        $serialized_ids_by_item = [];
        $returned_ids_by_item = [];
        $serial_labels_by_item = [];
        $returned_labels_by_item = [];
        if (!empty($item_ids)) {
            $item_id_placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $col_name = $invoice_type === 'sales' ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
            $this->ensureReturnedFromSalesColumn();
            $returnOriginSelect = $invoice_type === 'sales' ? ', returned_from_sales_item_id' : '';
            $returnOriginWhere = $invoice_type === 'sales'
                ? " OR returned_from_sales_item_id IN ({$item_id_placeholders})"
                : '';
            $bindIds = $invoice_type === 'sales' ? array_merge($item_ids, $item_ids) : $item_ids;
            $bindTypes = str_repeat('i', count($bindIds));
            $stmt_serials = $this->conn->prepare("SELECT id, serial_id, {$col_name}, status{$returnOriginSelect} FROM inventory_items WHERE {$col_name} IN ({$item_id_placeholders}){$returnOriginWhere}");
            $stmt_serials->bind_param($bindTypes, ...$bindIds);
            $stmt_serials->execute();
            $serials_res = db_stmt_to_assoc_array($stmt_serials);
            foreach ($serials_res as $serial) {
                $label = $serial['serial_id'] ?: ('INV-' . str_pad($serial['id'], 6, '0', STR_PAD_LEFT));
                $currentItemId = intval($serial[$col_name] ?? 0);
                if ($currentItemId && in_array($currentItemId, $item_ids, true)) {
                    if ($serial['status'] === 'returned') {
                        $returned_ids_by_item[$currentItemId][] = $serial['id'];
                        $returned_labels_by_item[$currentItemId][] = $label;
                    } else {
                        $serialized_ids_by_item[$currentItemId][] = $serial['id'];
                        $serial_labels_by_item[$currentItemId][] = $label;
                    }
                }
                if ($invoice_type === 'sales') {
                    $originItemId = intval($serial['returned_from_sales_item_id'] ?? 0);
                    if ($originItemId && $originItemId !== $currentItemId && in_array($originItemId, $item_ids, true)) {
                        $returned_ids_by_item[$originItemId][] = $serial['id'];
                        $returned_labels_by_item[$originItemId][] = $label;
                    }
                }
            }
            $returned_item_ids = $this->checkIdentifiersReturned($item_ids, $invoice_type);
            if ($invoice_type === 'sales' && !empty($returned_ids_by_item)) {
                $returned_item_ids = array_unique(array_merge($returned_item_ids, array_keys($returned_ids_by_item)));
            }
            if ($invoice_type === 'sales') {
                $this->attachReturnedSerialsFromReturnInvoices(
                    $item_ids,
                    $returned_ids_by_item,
                    $returned_labels_by_item
                );
                if (!empty($returned_ids_by_item)) {
                    $returned_item_ids = array_unique(array_merge($returned_item_ids ?? [], array_keys($returned_ids_by_item)));
                }
            }
        }

        foreach ($items_res as $item) {
            $item['serialized_ids'] = $serialized_ids_by_item[$item['id']] ?? [];
            $item['returned_ids'] = $returned_ids_by_item[$item['id']] ?? [];
            $item['serial_labels'] = $serial_labels_by_item[$item['id']] ?? [];
            $item['returned_labels'] = $returned_labels_by_item[$item['id']] ?? [];
            $item['is_returned'] = in_array($item['id'], $returned_item_ids ?? []);
            $item = normalize_invoice_item_display($item);
            $items_by_invoice[$item['invoiceId']][] = $item;
        }

        $payments_by_invoice = [];
        $all_check_ids = [];
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        $stmt_payments = $this->conn->prepare("SELECT * FROM payments WHERE entity_id = ? AND invoiceType = ? AND invoiceId IN ($ids_placeholder)");
        $stmt_payments->bind_param('is' . $types, $entity_id, $invoice_type, ...$id_params);
        $stmt_payments->execute();
        $payments_res = db_stmt_to_assoc_array($stmt_payments);
        foreach ($payments_res as $payment) {
            $payments_by_invoice[$payment['invoiceId']][] = $payment;
            if (!empty($payment['checkId'])) $all_check_ids[] = intval($payment['checkId']);
        }

        $checks_by_id = [];
        if (!empty($all_check_ids)) {
            $unique_check_ids = array_unique($all_check_ids);
            list($check_ids_placeholder, $check_id_params, $check_types) = $prepare_in_clause($unique_check_ids);
            if (!empty($check_ids_placeholder)) {
                $stmt_checks = $this->conn->prepare("SELECT * FROM checks WHERE entity_id = ? AND id IN ($check_ids_placeholder)");
                $stmt_checks->bind_param('i' . $check_types, $entity_id, ...$check_id_params);
                $stmt_checks->execute();
                $checks_res = db_stmt_to_assoc_array($stmt_checks);
                foreach ($checks_res as $check) $checks_by_id[$check['id']] = $check;
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
            if (!empty($processed_payments)) {
                require_once __DIR__ . '/Payment.php';
                (new Payment($this->conn))->enrichSetoffMeta($processed_payments);
            }
            $invoice['payments'] = $processed_payments;
            if (!isset($invoice['remainingAmount'])) {
                $invoice['remainingAmount'] = ($invoice['totalAmount'] ?? 0) - ($invoice['discount'] ?? 0) + ($invoice['tax_amount'] ?? 0) - ($invoice['paidAmount'] ?? 0);
            }
            $processed_invoices[] = $invoice;
        }
        return $processed_invoices;
    }
    /**
     * همگام‌سازی پرداخت‌های فاکتور:
     * - existing_payment_id: اتصال علی‌الحساب (بدون تغییر بانک) یا نگه‌داشتن
     * - ردیف جدید: ایجاد تراکنش
     * - حذف از فرم: اگر is_allocated → detach، وگرنه delete
     */
    /**
     * Reconcile the payments sent with an invoice form against the stored ones.
     *
     * - Unchanged native payments are left untouched, so their ids stay stable
     *   (bank-reconciliation matches, installment links and check history refer
     *   to payment ids; delete+recreate used to break those links).
     * - Payments whose check already moved money or changed hands (cashed,
     *   endorsed, bounced) and bounce reversal rows are frozen: they can only be
     *   changed from the checks section.
     * - Advance payments attached from «علی‌الحساب» are detached, not deleted.
     */
    public function syncInvoicePayments($invoiceId, $invoiceType, $personId, $personType, array $payments, $defaultTxnType) {
        require_once __DIR__ . '/Payment.php';
        $paymentModel = new Payment($this->conn);
        $paymentModel->ensureAllocatedColumn();
        $paymentModel->ensureSetoffColumn();
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);

        $keepIds = [];
        foreach ($payments as $p) {
            if (!empty($p['existing_payment_id'])) {
                $keepIds[] = intval($p['existing_payment_id']);
            }
        }
        $keepIds = array_values(array_unique(array_filter($keepIds)));

        $stmt_sel = $this->conn->prepare(
            "SELECT p.*, COALESCE(p.is_allocated, 0) AS is_allocated, c.status AS check_status,
                    c.checkNumber AS c_checkNumber, c.dueDate AS c_dueDate, c.bankName AS c_bankName, c.issuerName AS c_issuerName
             FROM payments p
             LEFT JOIN checks c ON c.id = p.checkId
             WHERE p.invoiceType = ? AND p.invoiceId = ? AND p.entity_id = ?"
        );
        $stmt_sel->bind_param('sii', $invoiceType, $invoiceId, $entityId);
        $stmt_sel->execute();
        $oldPays = db_stmt_to_assoc_array($stmt_sel);
        $oldById = [];
        foreach ($oldPays as $op) {
            $oldById[(int)$op['id']] = $op;
        }

        $isFrozen = function (array $op) use ($invoiceType) {
            if (($op['type'] ?? '') === 'check_bounce') {
                return true;
            }
            // Setoff on purchase is created from the sales side — edit/remove only there.
            if (($op['type'] ?? '') === 'setoff' && $invoiceType === 'purchase') {
                return true;
            }
            if (($op['type'] ?? '') === 'check' && !empty($op['checkId'])) {
                return !in_array($op['check_status'], Payment::CHECK_OPEN_STATUSES, true);
            }
            if (($op['type'] ?? '') === 'endorse_check' && !empty($op['checkId'])) {
                return ($op['check_status'] ?? '') !== 'endorsed';
            }
            return false;
        };

        $incomingCheckIds = [];
        foreach ($payments as $p) {
            if (!empty($p['checkId'])) {
                $incomingCheckIds[] = intval($p['checkId']);
            }
        }

        $paymentModel->enrichSetoffMeta($oldPays);

        foreach ($oldPays as $op) {
            $opid = intval($op['id']);
            if (in_array($opid, $keepIds, true)) {
                continue;
            }
            if ($isFrozen($op)) {
                if (($op['type'] ?? '') === 'check_bounce') {
                    continue; // reversal rows are not part of the form
                }
                if (($op['type'] ?? '') === 'setoff') {
                    throw new Exception('ردیف تهاتر فقط از فاکتور فروش قابل حذف است.', 400);
                }
                throw new Exception('تراکنش چکی شماره ' . ($op['c_checkNumber'] ?: $opid) . ' در وضعیت «' . Payment::checkStatusLabel($op['check_status']) . '» است و از روی فاکتور قابل حذف نیست. ابتدا از بخش چک‌ها وضعیت آن را برگردانید.', 400);
            }
            if (intval($op['is_allocated']) === 1) {
                $det = $paymentModel->detachFromInvoice($opid, false);
                if (!empty($det['error'])) {
                    throw new Exception($det['error'], $det['statusCode'] ?? 500);
                }
            } else {
                $delResult = $paymentModel->delete($opid, true, false);
                if (!empty($delResult['error'])) {
                    throw new Exception($delResult['error'], $delResult['statusCode'] ?? 500);
                }
                if (($op['type'] ?? '') === 'check' && !empty($op['checkId']) && !in_array(intval($op['checkId']), $incomingCheckIds, true)) {
                    // Only an un-processed check may disappear with its payment.
                    $delChk = $this->conn->prepare("DELETE FROM checks WHERE id = ? AND entity_id = ? AND status IN ('in_hand','payable')");
                    $delChk->bind_param('ii', $op['checkId'], $entityId);
                    $delChk->execute();
                    $delChk->close();
                }
            }
        }

        foreach ($payments as $p) {
            $amount = parse_money($p['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $existingId = !empty($p['existing_payment_id']) ? intval($p['existing_payment_id']) : 0;
            $txnType = $p['transaction_type'] ?? $defaultTxnType;
            if (!in_array($txnType, ['receipt', 'payment'], true)) {
                $txnType = $defaultTxnType;
            }
            $pType = $p['type'] ?? 'cash';
            $pDate = $p['date'] ?? date('Y-m-d');

            if ($existingId > 0) {
                $exist = $oldById[$existingId] ?? null;
                if (!$exist) {
                    $chk = $this->conn->prepare("SELECT id, invoiceId, invoiceType, COALESCE(is_allocated,0) AS is_allocated FROM payments WHERE id = ? AND entity_id = ?");
                    $chk->bind_param('ii', $existingId, $entityId);
                    $chk->execute();
                    $exist = db_stmt_to_assoc_array($chk)[0] ?? null;
                }
                if (!$exist) {
                    throw new Exception("تراکنش #{$existingId} یافت نشد.", 404);
                }
                $onThisInvoice = intval($exist['invoiceId']) === intval($invoiceId)
                    && ($exist['invoiceType'] ?? '') === $invoiceType;

                if ($onThisInvoice && (intval($exist['is_allocated']) === 1 || $isFrozen($exist))) {
                    // Attached advance or processed check: kept exactly as stored.
                    continue;
                }
                if ($onThisInvoice) {
                    $cd = is_array($p['checkDetails'] ?? null) ? $p['checkDetails'] : [];
                    $descMatch = ($pType === 'setoff')
                        || trim((string)($exist['description'] ?? '')) === trim((string)($p['description'] ?? ''));
                    $unchanged = ($exist['type'] === $pType)
                        && abs((float)$exist['amount'] - $amount) < 0.005
                        && (string)$exist['date'] === (string)$pDate
                        && (string)$exist['transaction_type'] === $txnType
                        && (int)($exist['account_id'] ?? 0) === (int)($p['account_id'] ?? 0)
                        && $descMatch
                        && ($pType !== 'endorse_check' || (int)$exist['checkId'] === (int)($p['checkId'] ?? 0))
                        && ($pType !== 'check' || (
                            trim((string)($exist['c_checkNumber'] ?? '')) === trim((string)($cd['checkNumber'] ?? $exist['c_checkNumber'] ?? ''))
                            && (string)($exist['c_dueDate'] ?? '') === (string)($cd['dueDate'] ?? $exist['c_dueDate'] ?? '')
                            && (string)($exist['c_bankName'] ?? '') === (string)($cd['bankName'] ?? $exist['c_bankName'] ?? '')
                            && trim((string)($exist['c_issuerName'] ?? '')) === trim((string)($cd['issuerName'] ?? $exist['c_issuerName'] ?? ''))
                        ))
                        && ($pType !== 'setoff' || (
                            (int)($exist['setoff_person_id'] ?? 0) === (int)($p['setoff_person_id'] ?? 0)
                            && (string)($exist['setoff_person_type'] ?? '') === (string)($p['setoff_person_type'] ?? '')
                            && (int)($exist['setoff_invoice_id'] ?? 0) === (int)($p['setoff_invoice_id'] ?? 0)
                        ));
                    if ($unchanged) {
                        continue;
                    }
                    // Native payment edited: rebuild it (keeping its check row).
                    $delResult = $paymentModel->delete($existingId, true, false);
                    if (!empty($delResult['error'])) {
                        throw new Exception($delResult['error'], $delResult['statusCode'] ?? 500);
                    }
                    if ($pType === 'check' && empty($p['checkId']) && !empty($exist['checkId'])) {
                        $p['checkId'] = (int)$exist['checkId'];
                    }
                } elseif (empty($exist['invoiceId'])) {
                    $attach = $paymentModel->attachToInvoice($existingId, $invoiceId, $invoiceType, false);
                    if (!empty($attach['error'])) {
                        throw new Exception($attach['error'], $attach['statusCode'] ?? 500);
                    }
                    continue;
                } else {
                    throw new Exception("تراکنش #{$existingId} به فاکتور دیگری متصل است.", 400);
                }
            }

            if ($pType === 'check_bounce') {
                continue;
            }

            if ($pType === 'setoff') {
                if ($invoiceType !== 'sales') {
                    throw new Exception('تهاتر فقط از فاکتور فروش قابل ثبت است.', 400);
                }
                if ($txnType !== 'receipt') {
                    throw new Exception('تهاتر روی فاکتور فروش فقط به‌صورت دریافت ثبت می‌شود.', 400);
                }
                $setoffResult = $paymentModel->saveSetoff([
                    'sales_invoice_id' => $invoiceId,
                    'customer_id' => $personId,
                    'amount' => $amount,
                    'date' => $pDate,
                    'description' => $p['description'] ?? '',
                    'setoff_person_type' => $p['setoff_person_type'] ?? '',
                    'setoff_person_id' => $p['setoff_person_id'] ?? 0,
                    'setoff_invoice_id' => $p['setoff_invoice_id'] ?? null,
                ], false);
                if (!empty($setoffResult['error'])) {
                    throw new Exception($setoffResult['error'], $setoffResult['statusCode'] ?? 500);
                }
                continue;
            }

            $payload = [
                'invoiceId' => $invoiceId,
                'invoiceType' => $invoiceType,
                'person_id' => $personId,
                'person_type' => $personType,
                'transaction_type' => $txnType,
                'type' => $pType,
                'amount' => $amount,
                'date' => $pDate,
                'description' => $p['description'] ?? '',
                'account_id' => $p['account_id'] ?? null,
                'checkDetails' => $p['checkDetails'] ?? null,
                'checkId' => $p['checkId'] ?? null,
            ];
            if (!in_array($payload['type'], ['cash', 'check', 'endorse_check'], true)) {
                throw new Exception('نوع پرداخت نامعتبر است.', 400);
            }
            $saveResult = $paymentModel->save($payload, false);
            if (!empty($saveResult['error'])) {
                throw new Exception($saveResult['error'], $saveResult['statusCode'] ?? 500);
            }
        }
    }

    /**
     * Invoice number for a save. The number people see is the invoice's own
     * number (its id, shown in the lists) unless a number is typed in the form;
     * a separate per-entity sequence made the form/print show 1, 2, 3 while the
     * list showed 465. A typed number must not clash with another invoice's
     * typed number in the entity or with another invoice's own number.
     */
    private function resolveInvoiceNumber($type, $entityId, $invoiceId, $requested) {
        $table = $type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        $requested = trim((string)$requested);
        $selfId = (int)$invoiceId;
        if ($requested === '' || ($selfId > 0 && $requested === (string)$selfId)) {
            return null; // shown as the invoice's own number
        }
        $dup = $this->conn->prepare("SELECT id FROM `{$table}` WHERE entity_id = ? AND invoice_number = ? AND id <> ? LIMIT 1");
        $dup->bind_param('isi', $entityId, $requested, $selfId);
        $dup->execute();
        if (!empty(db_stmt_to_assoc_array($dup))) {
            throw new Exception("شماره فاکتور «{$requested}» قبلاً در این مجموعه استفاده شده است.", 409);
        }
        if (ctype_digit($requested)) {
            $other = (int)$requested;
            $own = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND id <> ? AND (invoice_number IS NULL OR invoice_number = '') LIMIT 1");
            $own->bind_param('ii', $other, $selfId);
            $own->execute();
            if (!empty(db_stmt_to_assoc_array($own))) {
                throw new Exception("شماره «{$requested}» شماره فاکتور دیگری است؛ شماره دیگری بنویسید یا خالی بگذارید.", 409);
            }
        }
        return $requested;
    }

    /**
     * سقف اعتبار مشتری: after the invoice is written (same transaction), compare
     * the customer's balance with their credit limit. Policy from settings:
     * off | warn (save + warning) | block (reject). Consignment sales are not
     * receivables and are skipped.
     */
    private function enforceCreditLimit($customerId, $invoiceId) {
        $policy = get_app_setting($this->conn, 'credit_limit_policy', 'warn');
        if ($policy === 'off' || (int)$customerId <= 0) {
            return;
        }
        $st = $this->conn->prepare("SELECT is_consignment FROM sales_invoices WHERE id = ?");
        $st->bind_param('i', $invoiceId);
        $st->execute();
        if ((int)(db_stmt_to_assoc_array($st)[0]['is_consignment'] ?? 0) === 1) {
            return;
        }
        require_once __DIR__ . '/Customer.php';
        $info = (new Customer($this->conn))->getNetBalance($customerId);
        if (!$info || $info['credit_limit'] === null || $info['credit_limit'] <= 0) {
            return;
        }
        if ($info['net_balance'] > $info['credit_limit'] + 0.5) {
            $msg = 'بدهی مشتری «' . $info['name'] . '» (' . number_format($info['net_balance']) . ') از سقف اعتبار او (' . number_format($info['credit_limit']) . ') بیشتر می‌شود.';
            if ($policy === 'block') {
                throw new Exception($msg . ' ثبت فاکتور نسیه بیش از سقف اعتبار در تنظیمات مسدود است.', 400);
            }
            $this->stockWarnings[] = $msg;
        }
    }

    /**
     * Customer/supplier and products referenced by an invoice must belong to
     * the current entity (ids come from the client).
     */
    private function assertEntityReferences($entity_id, $partyTable, $partyId, $items) {
        if ($partyId > 0) {
            $st = $this->conn->prepare("SELECT 1 FROM `{$partyTable}` WHERE id = ? AND entity_id = ?");
            $st->bind_param('ii', $partyId, $entity_id);
            $st->execute();
            if (empty(db_stmt_to_assoc_array($st))) {
                send_json(['error' => 'طرف حساب انتخاب‌شده متعلق به این مجموعه نیست.'], 400);
            }
        }
        $productIds = [];
        foreach ((array)$items as $it) {
            $pid = intval($it['productId'] ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        if (!empty($productIds)) {
            $ids = implode(',', array_map('intval', array_keys($productIds)));
            $eid = intval($entity_id);
            $res = $this->conn->query("SELECT COUNT(*) AS n FROM products WHERE id IN ({$ids}) AND entity_id = {$eid}");
            $n = intval($res->fetch_assoc()['n'] ?? 0);
            if ($n !== count($productIds)) {
                send_json(['error' => 'برخی کالاهای فاکتور متعلق به این مجموعه نیستند.'], 400);
            }
        }
    }

    public function saveSalesInvoice($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $customerId = intval($data['customerId'] ?? 0);
        $date = $data['date'] ?? '';
        $discount = floatval(str_replace(',', '', $data['discount'] ?? 0));
        $items = $data['items'] ?? [];
        $payments = $data['payments'] ?? [];
        assert_transaction_date_editable($this->conn, $entity_id, $date);
        $invoiceIdCheck = intval($data['id'] ?? 0);
        if ($invoiceIdCheck > 0) {
            $oldDateStmt = $this->conn->prepare("SELECT date FROM sales_invoices WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $invoiceIdCheck, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (empty($oldDateRow)) {
                send_json(['error' => 'فاکتور یافت نشد.'], 404);
            }
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }
        $this->assertEntityReferences($entity_id, 'customers', $customerId, $items);
        foreach ($payments as $p) {
            if (!empty($p['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $p['date']);
            }
        }
        $this->conn->begin_transaction();
        try {
            $serverTotalAmount = 0;
            foreach ($items as $it) {
                $rawQty = floatval($it['quantity'] ?? 0);
                $qty = intval($it['quantity'] ?? 0);
                if ($rawQty != $qty || $qty < 0) {
                    throw new Exception('تعداد اقلام باید عدد صحیح غیرمنفی باشد.', 400);
                }
                $up = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));
                $serverTotalAmount += round($qty * $up, 2);
            }
            $invoiceId = intval($data['id'] ?? 0);
            $due_date = !empty($data['due_date']) ? $data['due_date'] : null;
            $payment_terms = $data['payment_terms'] ?? null;
            $tax_rate = floatval($data['tax_rate'] ?? 0);
            $tax_amount = floatval(str_replace(',', '', $data['tax_amount'] ?? 0));
            $invoice_number = $this->resolveInvoiceNumber('sales', $entity_id, intval($data['id'] ?? 0), $data['invoice_number'] ?? '');
            $salesperson_id = !empty($data['salesperson_id']) ? intval($data['salesperson_id']) : null;
            if ($salesperson_id) {
                $spChk = $this->conn->prepare("SELECT id FROM salespeople WHERE id = ? AND entity_id = ?");
                $spChk->bind_param('ii', $salesperson_id, $entity_id);
                $spChk->execute();
                if (empty(db_stmt_to_assoc_array($spChk))) {
                    throw new Exception('فروشنده انتخاب‌شده نامعتبر است.', 400);
                }
            }

            // Server-side validation & recomputation: discount and tax must derive from items,
            // not be trusted from the client (mirrors Quotation::save validation).
            if ($discount < 0) {
                throw new Exception('تخفیف نمی‌تواند منفی باشد.', 400);
            }
            if ($discount > $serverTotalAmount) {
                throw new Exception('تخفیف نمی‌تواند بیشتر از جمع کل باشد.', 400);
            }
            $taxableBase = $serverTotalAmount - $discount;
            // round() with no precision matches the client's Math.round(taxableBase * taxRate / 100)
            $tax_amount = ($tax_rate > 0 && $taxableBase > 0) ? round($taxableBase * $tax_rate / 100) : 0.00;

            if ($invoiceId > 0) {
                // Update Invoice Header
                $stmt_upd = $this->conn->prepare("UPDATE sales_invoices SET customerId = ?, date = ?, totalAmount = ?, discount = ?, description = ?, due_date = ?, payment_terms = ?, tax_rate = ?, tax_amount = ?, invoice_number = ?, salesperson_id = ? WHERE id = ? AND entity_id = ?");
                $desc = $data['description'] ?? '';
                $stmt_upd->bind_param("isdssssddsiii", $customerId, $date, $serverTotalAmount, $discount, $desc, $due_date, $payment_terms, $tax_rate, $tax_amount, $invoice_number, $salesperson_id, $invoiceId, $entity_id);
                $stmt_upd->execute();
                $stmt_upd->close();

                // *** DIFF STRATEGY FOR ITEMS ***
                // 1. Get existing items
                $stmt_existing = $this->conn->prepare("SELECT id, productId, dimensions, quantity FROM sales_invoice_items WHERE invoiceId = ?");
                $stmt_existing->bind_param("i", $invoiceId);
                $stmt_existing->execute();
                $existing_items = db_stmt_to_assoc_array($stmt_existing);
                $existing_items_map = [];
                foreach ($existing_items as $ei) $existing_items_map[$ei['id']] = $ei;

                // 2. Identify items to Keep/Update and New items
                $incoming_item_ids = [];
                $items_to_insert = [];
                $items_to_update = [];

                foreach ($items as $it) {
                    $it_id = intval($it['id'] ?? 0);
                    if ($it_id > 0 && isset($existing_items_map[$it_id])) {
                        $incoming_item_ids[] = $it_id;
                        $items_to_update[] = $it;
                    } else {
                        $items_to_insert[] = $it;
                    }
                }

                // 3. Identify items to DELETE
                $ids_to_delete = array_diff(array_keys($existing_items_map), $incoming_item_ids);
                
                if (!empty($ids_to_delete)) {
                    // Check for returns before deleting
                    $ph_del = implode(',', array_fill(0, count($ids_to_delete), '?'));
                    $types_del = str_repeat('i', count($ids_to_delete));
                    
                    $stmt_check_return = $this->conn->prepare("SELECT id FROM return_invoice_items WHERE original_invoice_item_id IN ($ph_del)");
                    $stmt_check_return->bind_param($types_del, ...$ids_to_delete);
                    $stmt_check_return->execute();
                    $returned_rows = db_stmt_to_assoc_array($stmt_check_return);
                    
                    if (!empty($returned_rows)) {
                        throw new Exception("برخی از اقلام حذف شده دارای سابقه مرجوعی هستند و قابل حذف نمی‌باشند.");
                    }

                    // Release stock (virtual placeholders are removed, real items return to stock)
                    $this->releaseSalesItemStock($ids_to_delete);
                    
                    // Delete items
                    $stmt_del = $this->conn->prepare("DELETE FROM sales_invoice_items WHERE id IN ($ph_del)");
                    $stmt_del->bind_param($types_del, ...$ids_to_delete);
                    $stmt_del->execute();
                }

                // 4. Process UPDATES
                foreach ($items_to_update as $it) {
                    $itemId = intval($it['id']);
                    $oldItem = $existing_items_map[$itemId];
                    $newQty = intval($it['quantity']);
                    $newDim = $it['dimensions'] ?? '';
                    $newPrice = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));
                    $newProductId = intval($it['productId'] ?? $oldItem['productId']);
                    
                    // Check if this is a service item
                    $isService = intval($it['is_service'] ?? 0);
                    if ($isService) {
                        // Service item: just update description, quantity, price - no inventory
                        $itemDesc = trim($it['item_description'] ?? '');
                        $stmt_u = $this->conn->prepare("UPDATE sales_invoice_items SET quantity = ?, unitPrice = ?, item_description = ?, is_service = 1, productId = NULL, dimensions = NULL WHERE id = ?");
                        $stmt_u->bind_param("idsi", $newQty, $newPrice, $itemDesc, $itemId);
                        $stmt_u->execute();
                        continue;
                    }

                    // Update item record (including productId)
                    $stmt_u = $this->conn->prepare("UPDATE sales_invoice_items SET productId = ?, quantity = ?, unitPrice = ?, dimensions = ? WHERE id = ?");
                    $stmt_u->bind_param("iidsi", $newProductId, $newQty, $newPrice, $newDim, $itemId);
                    $stmt_u->execute();

                    // Handle Inventory Logic
                    $selectedSerialIds = $it['selected_serial_ids'] ?? [];
                    $productChanged = ($newProductId !== intval($oldItem['productId']));
                    $dimChanged = ($newDim !== ($oldItem['dimensions'] ?? ''));
                    $serialsChanged = !empty($selectedSerialIds);

                    if ($productChanged || $dimChanged || $serialsChanged) {
                        // Product, dimension, or serial selection changed: Revert non-returned stock and reassign
                        $this->releaseSalesItemStock([$itemId]);
                        $this->assignStockToSalesItem($entity_id, $newProductId, $newDim, $newQty, $itemId, 0, $selectedSerialIds);
                    } elseif ($newQty > intval($oldItem['quantity'])) {
                        // Quantity increased (no serial/dim/product change): Assign MORE stock
                        $diff = $newQty - intval($oldItem['quantity']);
                        $this->assignStockToSalesItem($entity_id, $newProductId, $newDim, $diff, $itemId);
                    } elseif ($newQty < intval($oldItem['quantity'])) {
                        // Quantity decreased: Release latest non-returned items
                        $diff = intval($oldItem['quantity']) - $newQty;
                        $this->releaseSalesItemStock([$itemId], $diff);
                    }
                }

                // 5. Process INSERTS
                foreach ($items_to_insert as $it) {
                    $this->insertNewSalesItem($entity_id, $invoiceId, $it);
                }

                // Handle Payments — sync (attach / create / detach) instead of blind recreate
                // (actual sync runs after insert/update below)

            } else {
                // INSERT NEW INVOICE
                $is_consignment = isset($data['is_consignment']) ? intval($data['is_consignment']) : 0;
                $stmt_ins = $this->conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, description, is_consignment, due_date, payment_terms, tax_rate, tax_amount, invoice_number, salesperson_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
                $desc = $data['description'] ?? '';
                $stmt_ins->bind_param("iissdsissddsi", $entity_id, $customerId, $date, $serverTotalAmount, $discount, $desc, $is_consignment, $due_date, $payment_terms, $tax_rate, $tax_amount, $invoice_number, $salesperson_id);
                $stmt_ins->execute();
                $invoiceId = $this->conn->insert_id;
                $stmt_ins->close();

                foreach ($items as $it) {
                    $this->insertNewSalesItem($entity_id, $invoiceId, $it, $is_consignment);
                }
            }
            
            $this->syncInvoicePayments($invoiceId, 'sales', $customerId, 'customer', $payments, 'receipt');

            $conversion = ['converted_count' => 0, 'invoices' => []];
            $saleMetaStmt = $this->conn->prepare("SELECT is_consignment FROM sales_invoices WHERE id = ? AND entity_id = ?");
            $saleMetaStmt->bind_param('ii', $invoiceId, $entity_id);
            $saleMetaStmt->execute();
            $saleMeta = db_stmt_to_assoc_array($saleMetaStmt);
            $isConsignmentSale = intval($saleMeta[0]['is_consignment'] ?? 0);
            if ($isConsignmentSale === 0) {
                $conversion = $this->convertSoldConsignmentPurchaseItems($entity_id, intval($invoiceId), false);
            }

            $this->enforceCreditLimit($customerId, $invoiceId);

            $this->conn->commit();
            log_activity($this->conn, 'SAVE_SALES_INVOICE', "فاکتور فروش #{$invoiceId} ذخیره شد.");

            // Notify online store of inventory changes (best-effort)
            try {
                $pids = [];
                foreach ($items as $it) {
                    if (empty($it['is_service']) && !empty($it['productId'])) {
                        $pids[] = intval($it['productId']);
                    }
                }
                if (!empty($pids) && file_exists(__DIR__ . '/../core/IntegrationService.php')) {
                    require_once __DIR__ . '/../core/IntegrationService.php';
                    (new IntegrationService($this->conn))->enqueueStockSync($entity_id, $pids);
                }
            } catch (Throwable $ie) {
                error_log('Stock sync enqueue after sales invoice failed: ' . $ie->getMessage());
            }

            $result = ['success' => true, 'id' => $invoiceId, 'consignment_conversion' => $conversion];
            if (!empty($this->stockWarnings)) {
                $result['warnings'] = $this->stockWarnings;
            }
            return $result;
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            return ['error' => 'خطا در ذخیره فاکتور فروش: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    private function insertNewSalesItem($entity_id, $invoiceId, $it, $is_consignment = 0) {
        $isService = intval($it['is_service'] ?? 0);
        $quantity = intval($it['quantity'] ?? 0);
        $unitPrice = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));

        if ($isService) {
            // Service/non-product item: no inventory operations
            $itemDesc = trim($it['item_description'] ?? '');
            if (empty($itemDesc) || $quantity <= 0) return;
            $stmt_item = $this->conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice, is_service, item_description) VALUES (?, NULL, NULL, ?, ?, 1, ?)");
            $stmt_item->bind_param("iids", $invoiceId, $quantity, $unitPrice, $itemDesc);
            $stmt_item->execute();
            $stmt_item->close();
            return;
        }

        $productId = intval($it['productId'] ?? 0);
        $dimensions = $it['dimensions'] ?? '';
        if ($productId <= 0 || empty($dimensions) || $quantity <= 0) return;

        // One invoice line per product+dimensions (uk_invoice_product_dims).
        // A new row with the same specs is treated as extra quantity on the existing line.
        $stmt_dup = $this->conn->prepare("SELECT id, quantity FROM sales_invoice_items WHERE invoiceId = ? AND productId = ? AND dimensions = ? AND IFNULL(is_service, 0) = 0 LIMIT 1");
        $stmt_dup->bind_param("iis", $invoiceId, $productId, $dimensions);
        $stmt_dup->execute();
        $existingLine = db_stmt_to_assoc_array($stmt_dup)[0] ?? null;
        if ($existingLine) {
            $salesItemId = intval($existingLine['id']);
            $mergedQty = intval($existingLine['quantity']) + $quantity;
            $stmt_u = $this->conn->prepare("UPDATE sales_invoice_items SET quantity = ?, unitPrice = ? WHERE id = ?");
            $stmt_u->bind_param("idi", $mergedQty, $unitPrice, $salesItemId);
            $stmt_u->execute();
            $stmt_u->close();
            $selectedSerialIds = $it['selected_serial_ids'] ?? [];
            $this->assignStockToSalesItem($entity_id, $productId, $dimensions, $quantity, $salesItemId, $is_consignment, $selectedSerialIds);
            return;
        }

        $stmt_item = $this->conn->prepare("INSERT INTO sales_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, ?, ?)");
        $stmt_item->bind_param("iisid", $invoiceId, $productId, $dimensions, $quantity, $unitPrice);
        if (!$stmt_item->execute()) {
            $errno = (int)$stmt_item->errno;
            $err = $stmt_item->error;
            $stmt_item->close();
            if ($errno === 1062) {
                throw new Exception('این طرح با همین ابعاد قبلاً در فاکتور ثبت شده است. تعداد را در همان ردیف افزایش دهید.', 400);
            }
            throw new Exception('خطا در ثبت قلم فاکتور: ' . $err, 500);
        }
        $salesItemId = $this->conn->insert_id;
        $stmt_item->close();

        $selectedSerialIds = $it['selected_serial_ids'] ?? [];
        $this->assignStockToSalesItem($entity_id, $productId, $dimensions, $quantity, $salesItemId, $is_consignment, $selectedSerialIds);
    }

    private function ensureReturnedFromSalesColumn() {
        static $ready = false;
        if ($ready) {
            return;
        }
        $ready = true;
        $col = $this->conn->query("SHOW COLUMNS FROM inventory_items LIKE 'returned_from_sales_item_id'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `inventory_items` ADD COLUMN `returned_from_sales_item_id` INT(11) DEFAULT NULL AFTER `sales_invoice_item_id`");
            $this->conn->query("ALTER TABLE `inventory_items` ADD INDEX `idx_returned_from_sales` (`returned_from_sales_item_id`)");
        }
    }

    /** Warnings collected while saving (e.g. sold beyond stock); returned to the client. */
    private $stockWarnings = [];

    /**
     * Release stock held by sales lines.
     * Virtual placeholders (created when a sale exceeded stock) are deleted —
     * putting them «in stock» would invent inventory that was never bought.
     * Real items go back to stock. With $limit, releases that many from one line,
     * virtual ones first.
     */
    private function releaseSalesItemStock(array $salesItemIds, $limit = null) {
        $salesItemIds = array_values(array_filter(array_map('intval', $salesItemIds)));
        if (empty($salesItemIds)) {
            return;
        }
        $held = "status IN ('sold', 'consignment_out')";
        if ($limit === null) {
            $ph = implode(',', array_fill(0, count($salesItemIds), '?'));
            $types = str_repeat('i', count($salesItemIds));
            $del = $this->conn->prepare("DELETE FROM inventory_items WHERE sales_invoice_item_id IN ($ph) AND is_virtual = 1 AND purchase_invoice_item_id IS NULL AND {$held}");
            $del->bind_param($types, ...$salesItemIds);
            $del->execute();
            $del->close();
            $rev = $this->conn->prepare("UPDATE inventory_items SET status = 'in_stock', sales_invoice_item_id = NULL WHERE sales_invoice_item_id IN ($ph) AND {$held}");
            $rev->bind_param($types, ...$salesItemIds);
            $rev->execute();
            $rev->close();
            return;
        }
        $limit = (int)$limit;
        if ($limit <= 0) {
            return;
        }
        $sel = $this->conn->prepare("SELECT id, is_virtual, purchase_invoice_item_id FROM inventory_items WHERE sales_invoice_item_id = ? AND {$held} ORDER BY is_virtual DESC, id DESC LIMIT ? FOR UPDATE");
        $sel->bind_param('ii', $salesItemIds[0], $limit);
        $sel->execute();
        $rows = db_stmt_to_assoc_array($sel);
        foreach ($rows as $r) {
            $iid = (int)$r['id'];
            if ((int)$r['is_virtual'] === 1 && empty($r['purchase_invoice_item_id'])) {
                $q = $this->conn->prepare("DELETE FROM inventory_items WHERE id = ?");
            } else {
                $q = $this->conn->prepare("UPDATE inventory_items SET status = 'in_stock', sales_invoice_item_id = NULL WHERE id = ?");
            }
            $q->bind_param('i', $iid);
            $q->execute();
            $q->close();
        }
    }

    private function assignStockToSalesItem($entity_id, $productId, $dimensions, $quantity, $salesItemId, $is_consignment = 0, $selectedSerialIds = []) {
        $targetStatus = $is_consignment ? 'consignment_out' : 'sold';
        $taken = 0;
        // Sellable stock: in_stock + customer returns (returned). Exclude returned_to_supplier.
        $sellableStatuses = "status IN ('in_stock', 'returned')";
        $this->ensureReturnedFromSalesColumn();
        // Keep the original sales-item link when reselling a customer return.
        $preserveReturnLink = "returned_from_sales_item_id = IF(status = 'returned', COALESCE(returned_from_sales_item_id, sales_invoice_item_id), returned_from_sales_item_id)";

        // If user selected specific serial IDs, assign those first
        if (!empty($selectedSerialIds)) {
            foreach ($selectedSerialIds as $serialId) {
                if ($taken >= $quantity) break;
                $stmt_sel = $this->conn->prepare("UPDATE inventory_items SET {$preserveReturnLink}, status = ?, sales_invoice_item_id = ? WHERE entity_id = ? AND product_id = ? AND dimensions = ? AND serial_id = ? AND {$sellableStatuses} LIMIT 1");
                $stmt_sel->bind_param("siiiss", $targetStatus, $salesItemId, $entity_id, $productId, $dimensions, $serialId);
                $stmt_sel->execute();
                $affected = $stmt_sel->affected_rows;
                $stmt_sel->close();
                if ($affected > 0) {
                    $taken++;
                } else {
                    throw new Exception("شناسه انبار «{$serialId}» در موجودی قابل فروش یافت نشد.", 400);
                }
            }
        }

        // FIFO for the rest. Rows are locked so two concurrent sales can never
        // grab the same physical item (the second would silently steal it).
        if ($taken < $quantity) {
            $remaining = $quantity - $taken;
            $stmt_fifo = $this->conn->prepare("SELECT id FROM inventory_items WHERE entity_id = ? AND product_id = ? AND dimensions = ? AND {$sellableStatuses} ORDER BY entry_date ASC, id ASC LIMIT ? FOR UPDATE");
            $stmt_fifo->bind_param("iisi", $entity_id, $productId, $dimensions, $remaining);
            $stmt_fifo->execute();
            $fifo_items = db_stmt_to_assoc_array($stmt_fifo, false);
            $stmt_fifo->close();

            $fifo_taken = count($fifo_items);
            if ($fifo_taken > 0) {
                $ids = array_column($fifo_items, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $stmt_upd_stock = $this->conn->prepare("UPDATE inventory_items SET {$preserveReturnLink}, status = ?, sales_invoice_item_id = ? WHERE id IN ($ph) AND {$sellableStatuses}");
                $stmt_upd_stock->bind_param("si" . $types, $targetStatus, $salesItemId, ...$ids);
                $stmt_upd_stock->execute();
                $updated = $stmt_upd_stock->affected_rows;
                $stmt_upd_stock->close();
                if ($updated !== $fifo_taken) {
                    throw new Exception('موجودی این کالا هم‌زمان توسط کاربر دیگری تغییر کرد. لطفاً دوباره ذخیره کنید.', 409);
                }
            }
            $taken += $fifo_taken;
        }

        if ($taken < $quantity) {
            $remain = $quantity - $taken;
            $policy = get_app_setting($this->conn, 'negative_stock_policy', 'allow');
            $label = $this->productLabelForMessage($productId, $dimensions);
            if ($policy === 'block') {
                throw new Exception("موجودی کافی نیست: از «{$label}» فقط {$taken} عدد قابل فروش موجود است (درخواست: {$quantity}). فروش بیش از موجودی در تنظیمات غیرفعال است.", 400);
            }
            if ($policy === 'warn') {
                $this->stockWarnings[] = "«{$label}»: {$remain} عدد بیش از موجودی فروخته شد (کالای مجازی ثبت شد).";
            }
            error_log("WARNING: Creating {$remain} virtual inventory items for product {$productId}, dimensions '{$dimensions}' in entity {$entity_id}. Stock is negative.");

            // Virtual placeholders keep the sale traceable until a purchase is linked.
            $stmt_create = $this->conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, sales_invoice_item_id, is_virtual) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt_serial = $this->conn->prepare("UPDATE inventory_items SET serial_id = ? WHERE id = ?");
            for ($i = 0; $i < $remain; $i++) {
                $stmt_create->bind_param("iissi", $productId, $entity_id, $dimensions, $targetStatus, $salesItemId);
                $stmt_create->execute();
                $newId = $this->conn->insert_id;
                $serialId = 'INV-' . str_pad($newId, 6, '0', STR_PAD_LEFT);
                $stmt_serial->bind_param("si", $serialId, $newId);
                $stmt_serial->execute();
            }
            $stmt_serial->close();
            $stmt_create->close();
        }
    }

    private function productLabelForMessage($productId, $dimensions) {
        $st = $this->conn->prepare("SELECT name FROM products WHERE id = ? LIMIT 1");
        $st->bind_param('i', $productId);
        $st->execute();
        $name = db_stmt_to_assoc_array($st)[0]['name'] ?? ('کالا #' . $productId);
        return trim($name . ' ' . $dimensions);
    }

    public function savePurchaseInvoice($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $supplierId = intval($data['supplierId'] ?? 0);
        $date = $data['date'] ?? '';
        $discount = floatval(str_replace(',', '', $data['discount'] ?? 0));
        $items = $data['items'] ?? [];
        $payments = $data['payments'] ?? [];
        assert_transaction_date_editable($this->conn, $entity_id, $date);
        $invoiceIdCheck = intval($data['id'] ?? 0);
        if ($invoiceIdCheck > 0) {
            $oldDateStmt = $this->conn->prepare("SELECT date FROM purchase_invoices WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $invoiceIdCheck, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (empty($oldDateRow)) {
                send_json(['error' => 'فاکتور یافت نشد.'], 404);
            }
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }
        $this->assertEntityReferences($entity_id, 'suppliers', $supplierId, $items);
        foreach ($payments as $p) {
            if (!empty($p['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $p['date']);
            }
        }
        $this->conn->begin_transaction();
        try {
            $createdProducts = [];
            $serverTotalAmount = 0;
            foreach ($items as $it) {
                $rawQty = floatval($it['quantity'] ?? 0);
                $qty = intval($it['quantity'] ?? 0);
                if ($rawQty != $qty || $qty < 0) {
                    throw new Exception('تعداد اقلام باید عدد صحیح غیرمنفی باشد.', 400);
                }
                $up = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));
                $serverTotalAmount += round($qty * $up, 2);
            }
            $invoiceId = intval($data['id'] ?? 0);
            
            $due_date = !empty($data['due_date']) ? $data['due_date'] : null;
            $payment_terms = $data['payment_terms'] ?? null;
            $tax_rate = floatval($data['tax_rate'] ?? 0);
            $tax_amount = floatval(str_replace(',', '', $data['tax_amount'] ?? 0));
            $invoice_number = $this->resolveInvoiceNumber('purchase', $entity_id, intval($data['id'] ?? 0), $data['invoice_number'] ?? '');
            $warehouseId = $this->resolvePurchaseWarehouseId($entity_id, $data['warehouse_id'] ?? null);
            $this->ensurePurchaseWarehouseColumn();
            $previousWarehouseId = null;
            if ($invoiceId > 0) {
                $prevStmt = $this->conn->prepare("SELECT warehouse_id FROM purchase_invoices WHERE id = ? AND entity_id = ?");
                $prevStmt->bind_param('ii', $invoiceId, $entity_id);
                $prevStmt->execute();
                $prevRow = db_stmt_to_assoc_array($prevStmt);
                $previousWarehouseId = !empty($prevRow[0]['warehouse_id']) ? intval($prevRow[0]['warehouse_id']) : null;
            }

            // Server-side validation & recomputation: discount and tax must derive from items,
            // not be trusted from the client (mirrors Quotation::save validation).
            if ($discount < 0) {
                throw new Exception('تخفیف نمی‌تواند منفی باشد.', 400);
            }
            if ($discount > $serverTotalAmount) {
                throw new Exception('تخفیف نمی‌تواند بیشتر از جمع کل باشد.', 400);
            }
            $taxableBase = $serverTotalAmount - $discount;
            // round() with no precision matches the client's Math.round(taxableBase * taxRate / 100)
            $tax_amount = ($tax_rate > 0 && $taxableBase > 0) ? round($taxableBase * $tax_rate / 100) : 0.00;

            $desc = (string)($data['description'] ?? '');
            $desc = $this->withFactoryOrderPurchaseNote($desc, $warehouseId, $entity_id);

            if ($invoiceId > 0) {
                // Update Header
                $stmt_upd = $this->conn->prepare("UPDATE purchase_invoices SET supplierId = ?, date = ?, totalAmount = ?, discount = ?, description = ?, due_date = ?, payment_terms = ?, tax_rate = ?, tax_amount = ?, invoice_number = ?, warehouse_id = ? WHERE id = ? AND entity_id = ?");
                $stmt_upd->bind_param("isddsssddsiii", $supplierId, $date, $serverTotalAmount, $discount, $desc, $due_date, $payment_terms, $tax_rate, $tax_amount, $invoice_number, $warehouseId, $invoiceId, $entity_id);
                $stmt_upd->execute();
                $stmt_upd->close();

                // *** DIFF STRATEGY FOR ITEMS ***
                // 1. Get existing items
                $stmt_existing = $this->conn->prepare("SELECT id, productId, dimensions, quantity FROM purchase_invoice_items WHERE invoiceId = ?");
                $stmt_existing->bind_param("i", $invoiceId);
                $stmt_existing->execute();
                $existing_items = db_stmt_to_assoc_array($stmt_existing);
                $existing_items_map = [];
                foreach ($existing_items as $ei) $existing_items_map[$ei['id']] = $ei;

                // 2. Identify items to Keep/Update and New items
                $incoming_item_ids = [];
                $items_to_insert = [];
                $items_to_update = [];

                foreach ($items as $it) {
                    $it_id = intval($it['id'] ?? 0);
                    if ($it_id > 0 && isset($existing_items_map[$it_id])) {
                        $incoming_item_ids[] = $it_id;
                        $items_to_update[] = $it;
                    } else {
                        $items_to_insert[] = $it;
                    }
                }

                // 3. Identify items to DELETE
                $ids_to_delete = array_diff(array_keys($existing_items_map), $incoming_item_ids);
                
                if (!empty($ids_to_delete)) {
                    $ph_del = implode(',', array_fill(0, count($ids_to_delete), '?'));
                    $types_del = str_repeat('i', count($ids_to_delete));
                    
                    // Check if any inventory items from these purchase items are sold/returned
                    $stmt_check_used = $this->conn->prepare("SELECT id FROM inventory_items WHERE purchase_invoice_item_id IN ($ph_del) AND status != 'in_stock'");
                    $stmt_check_used->bind_param($types_del, ...$ids_to_delete);
                    $stmt_check_used->execute();
                    $used_rows = db_stmt_to_assoc_array($stmt_check_used);
                    
                    if (!empty($used_rows)) {
                        // If forceEdit is NOT true, we should warn. But for now, let's throw error to be safe.
                        // Or we can implement the confirmation logic here if needed.
                        // For simplicity and safety:
                        throw new Exception("برخی از اقلام حذف شده فروخته شده‌اند و قابل حذف نیستند.");
                    }

                    // Delete inventory items (all should be in_stock)
                    $stmt_del_inv = $this->conn->prepare("DELETE FROM inventory_items WHERE purchase_invoice_item_id IN ($ph_del)");
                    $stmt_del_inv->bind_param($types_del, ...$ids_to_delete);
                    $stmt_del_inv->execute();
                    
                    // Delete items
                    $stmt_del = $this->conn->prepare("DELETE FROM purchase_invoice_items WHERE id IN ($ph_del)");
                    $stmt_del->bind_param($types_del, ...$ids_to_delete);
                    $stmt_del->execute();
                }

                // 4. Process UPDATES
                require_once __DIR__ . '/Product.php';
                foreach ($items_to_update as $it) {
                    $itemId = intval($it['id']);
                    $oldItem = $existing_items_map[$itemId];
                    $newQty = intval($it['quantity']);
                    $newDim = $it['dimensions'] ?? '';
                    $newPrice = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));
                    
                    // Check if this is a service item
                    $isService = intval($it['is_service'] ?? 0);
                    if ($isService) {
                        // Service item: just update description, quantity, price - no inventory
                        $itemDesc = trim($it['item_description'] ?? '');
                        $stmt_u = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = ?, unitPrice = ?, item_description = ?, is_service = 1, productId = NULL, dimensions = NULL WHERE id = ?");
                        $stmt_u->bind_param("idsi", $newQty, $newPrice, $itemDesc, $itemId);
                        $stmt_u->execute();
                        continue;
                    }

                    $newProductId = intval($it['productId'] ?? $oldItem['productId']);
                    
                    // Update item record (including productId)
                    $stmt_u = $this->conn->prepare("UPDATE purchase_invoice_items SET productId = ?, quantity = ?, unitPrice = ?, dimensions = ? WHERE id = ?");
                    $stmt_u->bind_param("iidsi", $newProductId, $newQty, $newPrice, $newDim, $itemId);
                    $stmt_u->execute();

                    // Handle Inventory Logic
                    $productChanged = ($newProductId !== intval($oldItem['productId']));
                    if ($productChanged || $newDim !== ($oldItem['dimensions'] ?? '')) {
                        // Product or dimension changed: Check if old stock is used
                        $stmt_check = $this->conn->prepare("SELECT id FROM inventory_items WHERE purchase_invoice_item_id = ? AND status != 'in_stock'");
                        $stmt_check->bind_param("i", $itemId);
                        $stmt_check->execute();
                        if (!empty(db_stmt_to_assoc_array($stmt_check))) {
                            throw new Exception($productChanged ? "محصول کالایی که فروخته شده است قابل تغییر نیست." : "ابعاد کالایی که فروخته شده است قابل تغییر نیست.");
                        }
                        // Delete old stock (all in_stock)
                        $this->conn->query("DELETE FROM inventory_items WHERE purchase_invoice_item_id = $itemId");
                        // Create new stock
                        $customSerialIds = $it['custom_serial_ids'] ?? [];
                        $this->createStockForPurchaseItem($entity_id, $newProductId, $newDim, $newQty, $itemId, $customSerialIds, $warehouseId);
                    } elseif ($newQty > $oldItem['quantity']) {
                        // Quantity increased: Add MORE stock
                        $diff = $newQty - $oldItem['quantity'];
                        $customSerialIds = $it['custom_serial_ids'] ?? [];
                        $this->createStockForPurchaseItem($entity_id, $newProductId, $newDim, $diff, $itemId, $customSerialIds, $warehouseId);
                    } elseif ($newQty < $oldItem['quantity']) {
                        // Quantity decreased: Remove SOME stock (only in_stock)
                        $diff = $oldItem['quantity'] - $newQty;
                        // Check if we have enough in_stock to remove
                        $stmt_count = $this->conn->prepare("SELECT COUNT(*) as cnt FROM inventory_items WHERE purchase_invoice_item_id = ? AND status = 'in_stock'");
                        $stmt_count->bind_param("i", $itemId);
                        $stmt_count->execute();
                        $cnt = db_stmt_to_assoc_array($stmt_count)[0]['cnt'];
                        
                        if ($cnt < $diff) {
                            throw new Exception("تعداد جدید کمتر از تعداد فروخته شده است. امکان کاهش موجودی وجود ندارد.");
                        }
                        
                        $stmt_rem = $this->conn->prepare("DELETE FROM inventory_items WHERE purchase_invoice_item_id = ? AND status = 'in_stock' LIMIT ?");
                        $stmt_rem->bind_param("ii", $itemId, $diff);
                        $stmt_rem->execute();
                    }
                }

                // 5. Process INSERTS
                foreach ($items_to_insert as $it) {
                    $created = $this->insertNewPurchaseItem($entity_id, $invoiceId, $it, $warehouseId);
                    if (is_array($created) && !empty($created['product_id'])) {
                        $createdProducts[] = $created;
                    }
                }

                // Payments synced after insert/update below

            } else {
                // INSERT NEW INVOICE
                $stmt_ins = $this->conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, description, due_date, payment_terms, tax_rate, tax_amount, invoice_number, warehouse_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_ins->bind_param("iisddsssddsi", $entity_id, $supplierId, $date, $serverTotalAmount, $discount, $desc, $due_date, $payment_terms, $tax_rate, $tax_amount, $invoice_number, $warehouseId);
                $stmt_ins->execute();
                $invoiceId = $this->conn->insert_id;
                $stmt_ins->close();

                require_once __DIR__ . '/Product.php';
                foreach ($items as $it) {
                    $created = $this->insertNewPurchaseItem($entity_id, $invoiceId, $it, $warehouseId);
                    if (is_array($created) && !empty($created['product_id'])) {
                        $createdProducts[] = $created;
                    }
                }
            }

            // حتی اگر انبار فاکتور عوض نشده باشد، فرش‌های بدون انبار (NULL/0) همین فاکتور
            // به انبار فعلی منتقل می‌شوند تا ویرایش‌های قبلیِ ناقص جبران شود.
            if ($warehouseId) {
                $this->applyPurchaseWarehouseToStock($entity_id, $invoiceId, $warehouseId, $previousWarehouseId);
            }
            
            $this->syncInvoicePayments($invoiceId, 'purchase', $supplierId, 'supplier', $payments, 'payment');

            $this->conn->commit();
            log_activity($this->conn, 'SAVE_PURCHASE_INVOICE', "فاکتور خرید #{$invoiceId} ذخیره شد.");

            try {
                $pids = [];
                foreach ($items as $it) {
                    if (empty($it['is_service']) && !empty($it['productId'])) {
                        $pids[] = intval($it['productId']);
                    }
                }
                foreach ($createdProducts as $cp) {
                    if (!empty($cp['product_id'])) {
                        $pids[] = intval($cp['product_id']);
                    }
                }
                $pids = array_values(array_unique(array_filter($pids)));
                if (!empty($pids) && file_exists(__DIR__ . '/../core/IntegrationService.php')) {
                    require_once __DIR__ . '/../core/IntegrationService.php';
                    (new IntegrationService($this->conn))->enqueueStockSync($entity_id, $pids);
                }
            } catch (Throwable $e) {
                // ignore
            }

            return ['success' => true, 'id' => $invoiceId, 'created_products' => $createdProducts];
        } catch (Throwable $e) {
            $this->conn->rollback();
            $code = ($e instanceof Exception && $e->getCode() >= 400 && $e->getCode() < 500) ? $e->getCode() : 500;
            return ['error' => 'خطا در ذخیره فاکتور خرید: ' . $e->getMessage(), 'statusCode' => $code];
        }
    }

    /**
     * Draft a new sales invoice from the still-in-stock lines of a purchase invoice.
     * The purchase invoice itself is left unchanged.
     */
    public function buildSalesDraftFromPurchase($entity_id, $purchaseId) {
        $entity_id = intval($entity_id);
        $purchaseId = intval($purchaseId);
        if ($purchaseId <= 0) {
            throw new Exception('فاکتور خرید نامعتبر است.', 400);
        }

        $stmt = $this->conn->prepare("SELECT id, description, invoice_number FROM purchase_invoices WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $purchaseId, $entity_id);
        $stmt->execute();
        $header = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$header) {
            throw new Exception('فاکتور خرید یافت نشد.', 404);
        }

        $stmt_items = $this->conn->prepare("SELECT pii.id, pii.productId, pii.dimensions, pii.quantity, pii.unitPrice, pii.is_service, pii.item_description, p.name AS productName FROM purchase_invoice_items pii LEFT JOIN products p ON p.id = pii.productId WHERE pii.invoiceId = ? ORDER BY pii.id");
        $stmt_items->bind_param('i', $purchaseId);
        $stmt_items->execute();
        $lines = db_stmt_to_assoc_array($stmt_items);

        $stockByItem = [];
        $stmt_stock = $this->conn->prepare("SELECT ii.purchase_invoice_item_id, ii.serial_id FROM inventory_items ii INNER JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id WHERE ii.entity_id = ? AND ii.status = 'in_stock' AND pii.invoiceId = ?");
        $stmt_stock->bind_param('ii', $entity_id, $purchaseId);
        $stmt_stock->execute();
        foreach (db_stmt_to_assoc_array($stmt_stock) as $row) {
            $lineId = intval($row['purchase_invoice_item_id']);
            $label = trim((string)($row['serial_id'] ?? ''));
            if ($label === '') {
                continue;
            }
            $stockByItem[$lineId][] = $label;
        }

        $items = [];
        $skipped = 0;
        foreach ($lines as $line) {
            if (intval($line['is_service'] ?? 0) === 1) {
                $serviceDesc = trim((string)($line['item_description'] ?? ''));
                $serviceQty = intval($line['quantity'] ?? 0);
                if ($serviceDesc === '' || $serviceQty <= 0) {
                    continue;
                }
                $items[] = [
                    'is_service' => 1,
                    'item_description' => $serviceDesc,
                    'quantity' => $serviceQty,
                    'unitPrice' => floatval($line['unitPrice'] ?? 0),
                ];
                continue;
            }

            $lineId = intval($line['id']);
            $labels = $stockByItem[$lineId] ?? [];
            $available = count($labels);
            $originalQty = intval($line['quantity'] ?? 0);
            if ($available <= 0) {
                $skipped += max(1, $originalQty);
                continue;
            }
            if ($originalQty > $available) {
                $skipped += ($originalQty - $available);
            }
            $items[] = [
                'productId' => intval($line['productId']),
                'productName' => $line['productName'] ?? '',
                'dimensions' => $line['dimensions'],
                'quantity' => $available,
                'unitPrice' => floatval($line['unitPrice'] ?? 0),
                'serial_labels' => $labels,
                'is_service' => 0,
            ];
        }

        if (empty($items)) {
            throw new Exception('کالای موجود در انبار برای تبدیل این فاکتور خرید نمانده است.', 400);
        }

        $note = 'تبدیل از فاکتور خرید #' . $purchaseId;
        $invNo = trim((string)($header['invoice_number'] ?? ''));
        if ($invNo !== '') {
            $note .= ' (شماره ' . $invNo . ')';
        }
        $prev = trim((string)($header['description'] ?? ''));
        if ($prev !== '') {
            $note .= ' — ' . $prev;
        }

        return [
            'success' => true,
            'skipped' => $skipped,
            'invoice' => [
                'id' => '',
                'date' => date('Y-m-d'),
                'description' => $note,
                'discount' => 0,
                'customerId' => '',
                'payment_terms' => 'cash',
                'tax_rate' => 0,
                'tax_amount' => 0,
                'invoice_number' => '',
                'items' => $items,
                'payments' => [],
            ],
        ];
    }

    private function ensurePurchaseWarehouseColumn() {
        $col = $this->conn->query("SHOW COLUMNS FROM purchase_invoices LIKE 'warehouse_id'");
        if ($col && $col->num_rows === 0) {
            $this->conn->query("ALTER TABLE `purchase_invoices` ADD COLUMN `warehouse_id` int(11) DEFAULT NULL AFTER `invoice_number`");
            $this->conn->query("ALTER TABLE `purchase_invoices` ADD KEY `idx_purchase_warehouse` (`warehouse_id`)");
        }
    }

    private function ensureWarehouseTypeColumn() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $tbl = $this->conn->query("SHOW TABLES LIKE 'warehouses'");
            if (!($tbl && $tbl->num_rows)) {
                return;
            }
            $col = $this->conn->query("SHOW COLUMNS FROM warehouses LIKE 'warehouse_type'");
            if ($col && $col->num_rows === 0) {
                $this->conn->query("ALTER TABLE `warehouses` ADD COLUMN `warehouse_type` varchar(20) NOT NULL DEFAULT 'physical' AFTER `show_on_dashboard`");
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    private function resolvePurchaseWarehouseId($entityId, $raw) {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        $warehouseId = intval($raw);
        if ($warehouseId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT id FROM warehouses WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $warehouseId, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt);
        if (empty($row)) {
            throw new Exception('انبار انتخاب‌شده معتبر نیست.', 400);
        }
        return $warehouseId;
    }

    /**
     * When a purchase is registered against an order (factory) warehouse, keep a lasting note
     * in the invoice description so history remains after the warehouse is later normalized.
     */
    private function withFactoryOrderPurchaseNote($description, $warehouseId, $entityId) {
        $note = 'ثبت به‌عنوان سفارش به کارخانه';
        $desc = trim((string)$description);
        if ($warehouseId === null || intval($warehouseId) <= 0) {
            return $desc;
        }
        $this->ensureWarehouseTypeColumn();
        $stmt = $this->conn->prepare("SELECT warehouse_type FROM warehouses WHERE id = ? AND entity_id = ? LIMIT 1");
        $wid = intval($warehouseId);
        $stmt->bind_param('ii', $wid, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt);
        if (empty($row) || ($row[0]['warehouse_type'] ?? '') !== 'order') {
            return $desc;
        }
        if ($desc !== '' && mb_strpos($desc, $note) !== false) {
            return $desc;
        }
        return $desc === '' ? $note : ($desc . "\n" . $note);
    }

    private function applyPurchaseWarehouseToStock($entityId, $invoiceId, $warehouseId, $previousWarehouseId = null) {
        // فقط فرش هنوزموجود که هنوز در انبار قبلی همین فاکتور است جابه‌جا می‌شود.
        // فروخته‌شده، تحویل‌شده، یا دستی به انبار دیگر منتقل‌شده دست نمی‌خورد.
        // warehouse_id=0 همان «بدون انبار» است (mysqli bind null با نوع i → 0).
        if ($previousWarehouseId) {
            $stmt = $this->conn->prepare("
                UPDATE inventory_items ii
                INNER JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                SET ii.warehouse_id = ?
                WHERE pii.invoiceId = ? AND ii.entity_id = ? AND ii.status = 'in_stock'
                  AND (
                    ii.warehouse_id = ?
                    OR ii.warehouse_id IS NULL
                    OR ii.warehouse_id = 0
                  )
            ");
            $stmt->bind_param('iiii', $warehouseId, $invoiceId, $entityId, $previousWarehouseId);
        } else {
            $stmt = $this->conn->prepare("
                UPDATE inventory_items ii
                INNER JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                SET ii.warehouse_id = ?
                WHERE pii.invoiceId = ? AND ii.entity_id = ? AND ii.status = 'in_stock'
                  AND (ii.warehouse_id IS NULL OR ii.warehouse_id = 0)
            ");
            $stmt->bind_param('iii', $warehouseId, $invoiceId, $entityId);
        }
        $stmt->execute();
        $stmt->close();
    }

    private function insertNewPurchaseItem($entity_id, $invoiceId, $it, $warehouseId = null) {
        $isService = intval($it['is_service'] ?? 0);
        $quantity = intval($it['quantity'] ?? 0);
        $unitPrice = floatval(str_replace(',', '', $it['unitPrice'] ?? 0));

        if ($isService) {
            // Service/non-product item: no inventory operations
            $itemDesc = trim($it['item_description'] ?? '');
            if (empty($itemDesc) || $quantity <= 0) return null;
            $stmt_item = $this->conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice, is_service, item_description) VALUES (?, NULL, NULL, ?, ?, 1, ?)");
            $stmt_item->bind_param("iids", $invoiceId, $quantity, $unitPrice, $itemDesc);
            $stmt_item->execute();
            $stmt_item->close();
            return null;
        }

        $newName = trim($it['newProductName'] ?? '');
        $productId = intval($it['productId'] ?? 0);
        $attachImages = false;
        if (!$productId && $newName) {
            $productModel = new Product($this->conn);
            $res = $productModel->save(['name' => $newName], false);
            if (isset($res['success'])) {
                $productId = intval($res['id']);
                $attachImages = true;
            } elseif ((int)($res['statusCode'] ?? 0) === 409) {
                $stmt_ex = $this->conn->prepare("SELECT id FROM products WHERE name = ? AND entity_id = ? LIMIT 1");
                $stmt_ex->bind_param('si', $newName, $entity_id);
                $stmt_ex->execute();
                $existingProduct = db_stmt_to_assoc_array($stmt_ex)[0] ?? null;
                $productId = intval($existingProduct['id'] ?? 0);
                if ($productId <= 0) {
                    throw new Exception($res['error'] ?? 'محصولی با این نام وجود دارد.', 409);
                }
                $attachImages = true;
            } else {
                throw new Exception($res['error'] ?? 'خطا در ساخت محصول جدید', (int)($res['statusCode'] ?? 400) ?: 400);
            }
        }
        $dimensions = trim((string)($it['dimensions'] ?? ''));
        $invalidDim = ($dimensions === '' || $dimensions === 'custom' || $dimensions === 'ابتدا طرح را انتخاب کنید' || $dimensions === 'انتخاب کنید...');
        if ($newName && ($productId <= 0 || $invalidDim || $quantity <= 0)) {
            throw new Exception('قلم «' . $newName . '» ذخیره نشد. نام، ابعاد و تعداد را کامل کنید.', 400);
        }
        if ($productId <= 0 || $invalidDim || $quantity <= 0) return null;

        $customSerialIds = $it['custom_serial_ids'] ?? [];

        // One invoice line per product+dimensions (uk_invoice_product_dims).
        // A new row with the same specs is treated as extra quantity on the existing line.
        $stmt_dup = $this->conn->prepare("SELECT id, quantity FROM purchase_invoice_items WHERE invoiceId = ? AND productId = ? AND dimensions = ? AND IFNULL(is_service, 0) = 0 LIMIT 1");
        $stmt_dup->bind_param("iis", $invoiceId, $productId, $dimensions);
        $stmt_dup->execute();
        $existingLine = db_stmt_to_assoc_array($stmt_dup)[0] ?? null;
        if ($existingLine) {
            $purchaseItemId = intval($existingLine['id']);
            $mergedQty = intval($existingLine['quantity']) + $quantity;
            $stmt_u = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = ? WHERE id = ?");
            $stmt_u->bind_param("ii", $mergedQty, $purchaseItemId);
            $stmt_u->execute();
            $stmt_u->close();
            $this->createStockForPurchaseItem($entity_id, $productId, $dimensions, $quantity, $purchaseItemId, $customSerialIds, $warehouseId);
            if (!$attachImages) {
                return null;
            }
            return [
                'product_id' => $productId,
                'client_key' => $it['client_key'] ?? null,
                'name' => $newName,
            ];
        }
        
        $stmt_item = $this->conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice) VALUES (?, ?, ?, ?, ?)");
        $stmt_item->bind_param("iisid", $invoiceId, $productId, $dimensions, $quantity, $unitPrice);
        $stmt_item->execute();
        $purchaseItemId = $this->conn->insert_id;
        $stmt_item->close();
        
        $this->createStockForPurchaseItem($entity_id, $productId, $dimensions, $quantity, $purchaseItemId, $customSerialIds, $warehouseId);

        if (!$attachImages) {
            return null;
        }
        return [
            'product_id' => $productId,
            'client_key' => $it['client_key'] ?? null,
            'name' => $newName,
        ];
    }

    private function createStockForPurchaseItem($entity_id, $productId, $dimensions, $quantity, $purchaseItemId, $customSerialIds = [], $warehouseId = null) {
        $alreadyOnThisLine = [];
        $stmt_exist = $this->conn->prepare("SELECT serial_id FROM inventory_items WHERE purchase_invoice_item_id = ? AND serial_id IS NOT NULL AND serial_id != ''");
        $stmt_exist->bind_param("i", $purchaseItemId);
        $stmt_exist->execute();
        // db_stmt_to_assoc_array closes the statement by default — do not close again.
        foreach (db_stmt_to_assoc_array($stmt_exist) as $row) {
            $alreadyOnThisLine[mb_strtolower(trim((string)$row['serial_id']))] = true;
        }

        $freshSerials = [];
        foreach ((array)$customSerialIds as $sid) {
            $sid = trim((string)$sid);
            if ($sid === '') continue;
            if (isset($alreadyOnThisLine[mb_strtolower($sid)])) continue;
            $freshSerials[] = $sid;
        }

        $resolvedWarehouseId = ($warehouseId !== null && intval($warehouseId) > 0) ? intval($warehouseId) : null;
        if ($resolvedWarehouseId === null) {
            $stmt_add = $this->conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, warehouse_id) VALUES (?, ?, ?, 'in_stock', ?, NULL)");
        } else {
            $stmt_add = $this->conn->prepare("INSERT INTO inventory_items (product_id, entity_id, dimensions, status, purchase_invoice_item_id, warehouse_id) VALUES (?, ?, ?, 'in_stock', ?, ?)");
        }
        for ($i = 0; $i < $quantity; $i++) {
            if ($resolvedWarehouseId === null) {
                $stmt_add->bind_param("iisi", $productId, $entity_id, $dimensions, $purchaseItemId);
            } else {
                $stmt_add->bind_param("iisii", $productId, $entity_id, $dimensions, $purchaseItemId, $resolvedWarehouseId);
            }
            $stmt_add->execute();
            
            $newId = $this->conn->insert_id;
            if (isset($freshSerials[$i]) && trim((string)$freshSerials[$i]) !== '') {
                $serialId = trim((string)$freshSerials[$i]);
                $stmt_unique = $this->conn->prepare("SELECT id FROM inventory_items WHERE serial_id = ? AND entity_id = ? AND id != ?");
                $stmt_unique->bind_param("sii", $serialId, $entity_id, $newId);
                $stmt_unique->execute();
                $conflict = db_stmt_to_assoc_array($stmt_unique);
                if (!empty($conflict)) {
                    throw new Exception("شناسه انبار «{$serialId}» قبلاً برای آیتم دیگری استفاده شده است.", 400);
                }
            } else {
                $serialId = 'INV-' . str_pad($newId, 6, '0', STR_PAD_LEFT);
            }
            $stmt_serial = $this->conn->prepare("UPDATE inventory_items SET serial_id = ? WHERE id = ?");
            $stmt_serial->bind_param("si", $serialId, $newId);
            $stmt_serial->execute();
            $stmt_serial->close();
        }
        $stmt_add->close();
    }

    public function deleteSalesInvoice($id) {
        $id = intval($id);
        if ($id <= 0) return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        $entity_id = $_SESSION['current_entity_id'];
        $dateStmt = $this->conn->prepare("SELECT date FROM sales_invoices WHERE id = ? AND entity_id = ?");
        $dateStmt->bind_param('ii', $id, $entity_id);
        $dateStmt->execute();
        $dateRow = db_stmt_to_assoc_array($dateStmt);
        if (empty($dateRow)) {
            return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
        }
        if (!empty($dateRow[0]['date'])) {
            assert_transaction_date_editable($this->conn, $entity_id, $dateRow[0]['date']);
        }
        $this->conn->begin_transaction();
        try {
            $stmt_items = $this->conn->prepare("SELECT id FROM sales_invoice_items WHERE invoiceId = ?");
            $stmt_items->bind_param("i", $id);
            $stmt_items->execute();
            $items = db_stmt_to_assoc_array($stmt_items);
            $item_ids = array_column($items, 'id');
            if (!empty($item_ids)) {
                $ph = implode(',', array_fill(0, count($item_ids), '?'));
                $types = str_repeat('i', count($item_ids));

                // Block deletion if any items have associated returns
                $stmt_ret = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM return_invoice_items WHERE original_invoice_item_id IN ($ph)");
                $stmt_ret->bind_param($types, ...$item_ids);
                $stmt_ret->execute();
                $retRes = db_stmt_to_assoc_array($stmt_ret);
                if (!empty($retRes) && intval($retRes[0]['cnt']) > 0) {
                    throw new Exception('حذف فاکتور فروش ممکن نیست؛ برای برخی اقلام مرجوعی ثبت شده است.', 409);
                }

                // Also block if inventory is in returned status linked to these items
                $stmt_returned = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM inventory_items WHERE sales_invoice_item_id IN ($ph) AND status = 'returned'");
                $stmt_returned->bind_param($types, ...$item_ids);
                $stmt_returned->execute();
                $retInv = db_stmt_to_assoc_array($stmt_returned);
                if (!empty($retInv) && intval($retInv[0]['cnt']) > 0) {
                    throw new Exception('حذف فاکتور فروش ممکن نیست؛ برخی شناسه‌ها مرجوع شده‌اند.', 409);
                }

                // Virtual placeholders are deleted and real items return to stock.
                // (Previously the revert ran first and unlinked the virtual rows, so
                // the delete matched nothing and they became phantom in-stock items.)
                $this->releaseSalesItemStock($item_ids);
            }
            $stmt_del_items = $this->conn->prepare("DELETE FROM sales_invoice_items WHERE invoiceId = ?");
            $stmt_del_items->bind_param("i", $id);
            $stmt_del_items->execute();
            $stmt_del_items->close();
            $stmt_pay = $this->conn->prepare("SELECT id FROM payments WHERE invoiceType = 'sales' AND invoiceId = ?");
            $stmt_pay->bind_param("i", $id);
            $stmt_pay->execute();
            $payments = db_stmt_to_assoc_array($stmt_pay, false);
            $stmt_pay->close();
            if (!empty($payments)) {
                require_once __DIR__ . '/Payment.php';
                $paymentModel = new Payment($this->conn);
                foreach ($payments as $p) {
                    $delResult = $paymentModel->delete(intval($p['id']), false, false);
                    if (!empty($delResult['error'])) {
                        throw new Exception($delResult['error'], $delResult['statusCode'] ?? 500);
                    }
                }
            }
            // Installment plans belong to the invoice; their payments were removed above.
            $stmt_inst = $this->conn->prepare("DELETE FROM installments WHERE invoice_type = 'sales' AND invoice_id = ? AND entity_id = ?");
            $stmt_inst->bind_param("ii", $id, $entity_id);
            $stmt_inst->execute();
            $stmt_inst->close();
            $stmt_del = $this->conn->prepare("DELETE FROM sales_invoices WHERE id = ? AND entity_id = ?");
            $stmt_del->bind_param("ii", $id, $entity_id);
            $stmt_del->execute();
            $stmt_del->close();
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_SALES_INVOICE', "فاکتور فروش #{$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            return ['error' => 'خطا در حذف فاکتور فروش: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }
    public function deletePurchaseInvoice($id) {
        $id = intval($id);
        if ($id <= 0) return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        $entity_id = $_SESSION['current_entity_id'];
        $dateStmt = $this->conn->prepare("SELECT date FROM purchase_invoices WHERE id = ? AND entity_id = ?");
        $dateStmt->bind_param('ii', $id, $entity_id);
        $dateStmt->execute();
        $dateRow = db_stmt_to_assoc_array($dateStmt);
        if (empty($dateRow)) {
            return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
        }
        if (!empty($dateRow[0]['date'])) {
            assert_transaction_date_editable($this->conn, $entity_id, $dateRow[0]['date']);
        }
        $this->conn->begin_transaction();
        try {
            $stmt_items = $this->conn->prepare("SELECT id FROM purchase_invoice_items WHERE invoiceId = ?");
            $stmt_items->bind_param("i", $id);
            $stmt_items->execute();
            $items = db_stmt_to_assoc_array($stmt_items);
            $item_ids = array_column($items, 'id');
            if (!empty($item_ids)) {
                $ph = implode(',', array_fill(0, count($item_ids), '?'));
                $types = str_repeat('i', count($item_ids));
                // Block deletion if any identifiers are consumed
                $stmt_check_used = $this->conn->prepare("SELECT COUNT(*) AS used_count FROM inventory_items WHERE purchase_invoice_item_id IN ($ph) AND status <> 'in_stock'");
                $stmt_check_used->bind_param($types, ...$item_ids);
                $stmt_check_used->execute();
                $res_used = db_stmt_to_assoc_array($stmt_check_used);
                if (!empty($res_used) && intval($res_used[0]['used_count']) > 0) {
                    throw new Exception('حذف فاکتور خرید ممکن نیست؛ برخی شناسه‌ها فروخته یا مرجوع شده‌اند.', 409);
                }
                // Safe delete only free stock
                $stmt_del_stock = $this->conn->prepare("DELETE FROM inventory_items WHERE purchase_invoice_item_id IN ($ph) AND status = 'in_stock'");
                $stmt_del_stock->bind_param($types, ...$item_ids);
                $stmt_del_stock->execute();
                $stmt_del_stock->close();
            }
            $stmt_del_items = $this->conn->prepare("DELETE FROM purchase_invoice_items WHERE invoiceId = ?");
            $stmt_del_items->bind_param("i", $id);
            $stmt_del_items->execute();
            $stmt_del_items->close();
            $stmt_pay = $this->conn->prepare("SELECT id FROM payments WHERE invoiceType = 'purchase' AND invoiceId = ?");
            $stmt_pay->bind_param("i", $id);
            $stmt_pay->execute();
            $payments = db_stmt_to_assoc_array($stmt_pay, false);
            $stmt_pay->close();
            if (!empty($payments)) {
                require_once __DIR__ . '/Payment.php';
                $paymentModel = new Payment($this->conn);
                foreach ($payments as $p) {
                    $delResult = $paymentModel->delete(intval($p['id']), false, false);
                    if (!empty($delResult['error'])) {
                        throw new Exception($delResult['error'], $delResult['statusCode'] ?? 500);
                    }
                }
            }
            // Installment plans belong to the invoice; their payments were removed above.
            $stmt_inst = $this->conn->prepare("DELETE FROM installments WHERE invoice_type = 'purchase' AND invoice_id = ? AND entity_id = ?");
            $stmt_inst->bind_param("ii", $id, $entity_id);
            $stmt_inst->execute();
            $stmt_inst->close();
            $stmt_del = $this->conn->prepare("DELETE FROM purchase_invoices WHERE id = ? AND entity_id = ?");
            $stmt_del->bind_param("ii", $id, $entity_id);
            $stmt_del->execute();
            $stmt_del->close();
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_PURCHASE_INVOICE', "فاکتور خرید #{$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? intval($e->getCode()) : 500;
            return ['error' => 'خطا در حذف فاکتور خرید: ' . $e->getMessage(), 'statusCode' => $code];
        }
    }
    public function updateConsignmentStatus($action, $data) {
        $type = $data['type'] ?? 'sales';
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) return ['error' => 'شناسه مجموعه تجاری نامعتبر است.', 'statusCode' => 401];
        $table = ($type === 'sales') ? 'sales_invoices' : 'purchase_invoices';
        $flag = ($action === 'mark_as_consignment') ? 1 : 0;

        $dateStmt = $this->conn->prepare("SELECT date FROM `{$table}` WHERE id = ? AND entity_id = ?");
        $dateStmt->bind_param('ii', $id, $entity_id);
        $dateStmt->execute();
        $dateRow = db_stmt_to_assoc_array($dateStmt);
        if (empty($dateRow)) {
            return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
        }
        assert_transaction_date_editable($this->conn, $entity_id, $dateRow[0]['date']);
        
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("UPDATE `{$table}` SET is_consignment = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("iii", $flag, $id, $entity_id);
            $stmt->execute();
            // affected_rows is 0 both when the flag didn't change (no-op) and when the
            // row doesn't exist; info's "Rows matched" distinguishes the two.
            if (preg_match('/Rows matched: (\d+)/', (string)$this->conn->info, $m)) {
                if (intval($m[1]) === 0) {
                    throw new Exception('فاکتور یافت نشد یا قابل بروزرسانی نیست.', 404);
                }
            }
            $stmt->close();

            // Update Inventory Status for Sales Invoices
            if ($type === 'sales') {
                $fromStatus = ($flag === 1) ? 'sold' : 'consignment_out';
                $toStatus = ($flag === 1) ? 'consignment_out' : 'sold';
                
                // Find all items linked to this invoice
                $stmt_items = $this->conn->prepare("SELECT id FROM sales_invoice_items WHERE invoiceId = ?");
                $stmt_items->bind_param("i", $id);
                $stmt_items->execute();
                $items = db_stmt_to_assoc_array($stmt_items);
                $item_ids = array_column($items, 'id');
                
                if (!empty($item_ids)) {
                    $ph = implode(',', array_fill(0, count($item_ids), '?'));
                    $types = str_repeat('i', count($item_ids));
                    
                    $stmt_inv = $this->conn->prepare("UPDATE inventory_items SET status = ? WHERE sales_invoice_item_id IN ($ph) AND status = ? AND entity_id = ?");
                    $params = array_merge([$toStatus], $item_ids, [$fromStatus, $entity_id]);
                    $stmt_inv->bind_param("s" . $types . "si", ...$params);
                    $stmt_inv->execute();
                    $stmt_inv->close();
                }
            }

            $this->conn->commit();
            $act = ($flag === 1) ? 'MARK_AS_CONSIGNMENT' : 'RETURN_FROM_CONSIGNMENT';
            log_activity($this->conn, $act, "فاکتور {$type} #{$id} وضعیت امانی بروزرسانی شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = (int)$e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            return ['error' => 'خطا در بروزرسانی وضعیت امانی: ' . $e->getMessage(), 'statusCode' => $statusCode];
        }
    }

    /**
     * Gets consignment invoice items for partial conversion modal.
     * @param array $data Contains 'type' ('sales' or 'purchase') and 'id'.
     */
    public function getConsignmentInvoiceItems($data) {
        $type = $data['type'] ?? 'sales';
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];

        try {
            if ($type === 'sales') {
                // Get sales invoice info
                $stmt = $this->conn->prepare("
                    SELECT si.*, c.name as personName 
                    FROM sales_invoices si 
                    LEFT JOIN customers c ON si.customerId = c.id 
                    WHERE si.id = ? AND si.entity_id = ?
                ");
                $stmt->bind_param("ii", $id, $_SESSION['current_entity_id']);
                $stmt->execute();
                $invoice = db_stmt_to_assoc_array($stmt);
                if (empty($invoice)) return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
                $invoice = $invoice[0];

                // Get items with product names (services are not transferable to/from consignment)
                $stmt_items = $this->conn->prepare("
                    SELECT sii.*, p.name as productName 
                    FROM sales_invoice_items sii 
                    LEFT JOIN products p ON sii.productId = p.id 
                    WHERE sii.invoiceId = ? AND sii.is_service = 0
                ");
                $stmt_items->bind_param("i", $id);
                $stmt_items->execute();
                $items = array_map('normalize_invoice_item_display', db_stmt_to_assoc_array($stmt_items));
            } else {
                // Get purchase invoice info
                $stmt = $this->conn->prepare("
                    SELECT pi.*, s.name as personName 
                    FROM purchase_invoices pi 
                    LEFT JOIN suppliers s ON pi.supplierId = s.id 
                    WHERE pi.id = ? AND pi.entity_id = ?
                ");
                $stmt->bind_param("ii", $id, $_SESSION['current_entity_id']);
                $stmt->execute();
                $invoice = db_stmt_to_assoc_array($stmt);
                if (empty($invoice)) return ['error' => 'فاکتور یافت نشد.', 'statusCode' => 404];
                $invoice = $invoice[0];

                // Get items with product names (services are not transferable to/from consignment)
                $stmt_items = $this->conn->prepare("
                    SELECT pii.*, p.name as productName 
                    FROM purchase_invoice_items pii 
                    LEFT JOIN products p ON pii.productId = p.id 
                    WHERE pii.invoiceId = ? AND pii.is_service = 0
                ");
                $stmt_items->bind_param("i", $id);
                $stmt_items->execute();
                $items = array_map('normalize_invoice_item_display', db_stmt_to_assoc_array($stmt_items));
            }

            return [
                'success' => true,
                'invoice' => $invoice,
                'items' => $items
            ];
        } catch (Exception $e) {
            return ['error' => 'خطا در دریافت اطلاعات فاکتور: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    /**
     * Handles partial return from consignment - converts selected items to regular invoice.
     * @param array $data Contains 'type', 'id', and 'selectedItems' (array of item IDs).
     */
    public function partialReturnFromConsignment($data) {
        $type = $data['type'] ?? 'sales';
        $consignmentInvoiceId = intval($data['id'] ?? 0);
        $selectedItemIds = $data['selectedItems'] ?? [];

        if ($consignmentInvoiceId <= 0) return ['error' => 'شناسه فاکتور نامعتبر است.', 'statusCode' => 400];
        if (empty($selectedItemIds) || !is_array($selectedItemIds)) {
            return ['error' => 'لطفاً حداقل یک قلم کالا انتخاب کنید.', 'statusCode' => 400];
        }

        // Sanitize item IDs
        $selectedItemIds = array_values(array_unique(array_filter(array_map('intval', $selectedItemIds), function($id) { return $id > 0; })));
        if (empty($selectedItemIds)) return ['error' => 'شناسه‌های اقلام نامعتبر است.', 'statusCode' => 400];

        $this->conn->begin_transaction();
        try {
            $entity_id = $_SESSION['current_entity_id'] ?? null;
            if (!$entity_id) throw new Exception('شناسه مجموعه تجاری نامعتبر است.');

            if ($type === 'sales') {
                $result = $this->partialConvertSalesConsignment($entity_id, $consignmentInvoiceId, $selectedItemIds);
            } else {
                $result = $this->partialConvertPurchaseConsignment($entity_id, $consignmentInvoiceId, $selectedItemIds);
            }

            $this->conn->commit();
            return $result;
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? intval($e->getCode()) : 500;
            return ['error' => 'خطا در تبدیل فاکتور امانی: ' . $e->getMessage(), 'statusCode' => $code];
        }
    }

    /**
     * Split discount/tax/paid proportionally for partial consignment conversion.
     */
    private function splitConsignmentAmounts($consignmentInvoice, $selectedItemsTotal) {
        $totalInvoiceAmount = floatval($consignmentInvoice['totalAmount']);
        $totalDiscount = floatval($consignmentInvoice['discount'] ?? 0);
        $totalTax = floatval($consignmentInvoice['tax_amount'] ?? 0);
        $originalPaid = floatval($consignmentInvoice['paidAmount'] ?? 0);
        $share = ($totalInvoiceAmount > 0) ? ($selectedItemsTotal / $totalInvoiceAmount) : 0;

        $proportionalDiscount = ($totalDiscount > 0) ? round($share * $totalDiscount) : 0;
        $remainingDiscount = $totalDiscount - $proportionalDiscount;
        $proportionalTax = ($totalTax > 0) ? round($share * $totalTax) : 0;
        $remainingTax = $totalTax - $proportionalTax;

        $remainingTotal = $totalInvoiceAmount - $selectedItemsTotal;
        $newNet = $selectedItemsTotal - $proportionalDiscount + $proportionalTax;
        $remnantNet = $remainingTotal - $remainingDiscount + $remainingTax;

        $movedPaid = ($originalPaid > 0) ? round($share * $originalPaid) : 0;
        $remainingPaid = $originalPaid - $movedPaid;
        if ($remainingPaid > max(0, $remnantNet)) {
            $excess = $remainingPaid - max(0, $remnantNet);
            $remainingPaid -= $excess;
            $movedPaid += $excess;
        }
        if ($movedPaid > max(0, $newNet)) {
            $excess = $movedPaid - max(0, $newNet);
            $movedPaid -= $excess;
            $remainingPaid += $excess;
        }

        return [
            'totalInvoiceAmount' => $totalInvoiceAmount,
            'selectedItemsTotal' => $selectedItemsTotal,
            'remainingTotal' => $remainingTotal,
            'proportionalDiscount' => $proportionalDiscount,
            'remainingDiscount' => $remainingDiscount,
            'proportionalTax' => $proportionalTax,
            'remainingTax' => $remainingTax,
            'taxRate' => floatval($consignmentInvoice['tax_rate'] ?? 0),
            'movedPaid' => $movedPaid,
            'remainingPaid' => $remainingPaid,
        ];
    }

    /**
     * Converts selected items from sales consignment invoice to regular sales invoice.
     */
    private function partialConvertSalesConsignment($entity_id, $consignmentInvoiceId, $selectedItemIds) {
        $stmt = $this->conn->prepare("SELECT * FROM sales_invoices WHERE id = ? AND entity_id = ? AND is_consignment = 1");
        $stmt->bind_param("ii", $consignmentInvoiceId, $entity_id);
        $stmt->execute();
        $consignmentInvoice = db_stmt_to_assoc_array($stmt);
        if (empty($consignmentInvoice)) throw new Exception('فاکتور امانی یافت نشد.', 404);
        $consignmentInvoice = $consignmentInvoice[0];
        assert_transaction_date_editable($this->conn, $entity_id, $consignmentInvoice['date']);

        $stmt_items = $this->conn->prepare("SELECT * FROM sales_invoice_items WHERE invoiceId = ?");
        $stmt_items->bind_param("i", $consignmentInvoiceId);
        $stmt_items->execute();
        $allItems = db_stmt_to_assoc_array($stmt_items);
        $allItemIds = array_map('intval', array_column($allItems, 'id'));

        foreach ($selectedItemIds as $itemId) {
            if (!in_array($itemId, $allItemIds, true)) {
                throw new Exception("قلم با شناسه {$itemId} به این فاکتور تعلق ندارد.", 400);
            }
        }

        $isFullConversion = count($selectedItemIds) === count($allItems);

        $selectedItemsTotal = 0;
        foreach ($allItems as $item) {
            if (in_array(intval($item['id']), $selectedItemIds, true)) {
                $selectedItemsTotal += floatval($item['quantity']) * floatval($item['unitPrice']);
            }
        }
        $split = $this->splitConsignmentAmounts($consignmentInvoice, $selectedItemsTotal);

        if ($isFullConversion) {
            $stmt_update = $this->conn->prepare("UPDATE sales_invoices SET is_consignment = 0 WHERE id = ? AND entity_id = ?");
            $stmt_update->bind_param("ii", $consignmentInvoiceId, $entity_id);
            $stmt_update->execute();

            $ph = implode(',', array_fill(0, count($selectedItemIds), '?'));
            $types = str_repeat('i', count($selectedItemIds));
            $stmt_inv = $this->conn->prepare("UPDATE inventory_items SET status = 'sold' WHERE sales_invoice_item_id IN ($ph) AND status = 'consignment_out' AND entity_id = ?");
            $params = array_merge($selectedItemIds, [$entity_id]);
            $stmt_inv->bind_param($types . 'i', ...$params);
            $stmt_inv->execute();

            log_activity($this->conn, 'FULL_RETURN_FROM_CONSIGNMENT', "فاکتور فروش امانی #{$consignmentInvoiceId} به طور کامل به فاکتور فروش تبدیل شد.");
            return ['success' => true, 'message' => 'فاکتور امانی به طور کامل به فاکتور فروش تبدیل شد.', 'newInvoiceId' => $consignmentInvoiceId];
        }

        $stmt_new = $this->conn->prepare("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, paidAmount, description, is_consignment, due_date, payment_terms, tax_rate, tax_amount, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
        $desc = ($consignmentInvoice['description'] ?? '') . " (منتقل شده از فاکتور امانی #{$consignmentInvoiceId})";
        $c_due = $consignmentInvoice['due_date'] ?? null;
        $c_pt  = $consignmentInvoice['payment_terms'] ?? null;
        $c_in  = null; // avoid duplicate invoice_number
        $movedPaid = $split['movedPaid'];
        $stmt_new->bind_param("iisdddsssdds", $entity_id, $consignmentInvoice['customerId'], $consignmentInvoice['date'], $split['selectedItemsTotal'], $split['proportionalDiscount'], $movedPaid, $desc, $c_due, $c_pt, $split['taxRate'], $split['proportionalTax'], $c_in);
        $stmt_new->execute();
        $newInvoiceId = $this->conn->insert_id;

        $ph = implode(',', array_fill(0, count($selectedItemIds), '?'));
        $types = str_repeat('i', count($selectedItemIds));
        $stmt_move = $this->conn->prepare("UPDATE sales_invoice_items SET invoiceId = ? WHERE id IN ($ph)");
        $params = array_merge([$newInvoiceId], $selectedItemIds);
        $stmt_move->bind_param("i" . $types, ...$params);
        $stmt_move->execute();

        $stmt_inv = $this->conn->prepare("UPDATE inventory_items SET status = 'sold' WHERE sales_invoice_item_id IN ($ph) AND status = 'consignment_out' AND entity_id = ?");
        $invParams = array_merge($selectedItemIds, [$entity_id]);
        $stmt_inv->bind_param($types . 'i', ...$invParams);
        $stmt_inv->execute();

        $stmt_update = $this->conn->prepare("UPDATE sales_invoices SET totalAmount = ?, discount = ?, tax_amount = ?, paidAmount = ? WHERE id = ? AND entity_id = ?");
        $stmt_update->bind_param("ddddii", $split['remainingTotal'], $split['remainingDiscount'], $split['remainingTax'], $split['remainingPaid'], $consignmentInvoiceId, $entity_id);
        $stmt_update->execute();

        $stmt_check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM sales_invoice_items WHERE invoiceId = ?");
        $stmt_check->bind_param("i", $consignmentInvoiceId);
        $stmt_check->execute();
        $remaining = db_stmt_to_assoc_array($stmt_check);
        if (intval($remaining[0]['cnt']) === 0) {
            $stmt_del = $this->conn->prepare("DELETE FROM sales_invoices WHERE id = ? AND entity_id = ?");
            $stmt_del->bind_param("ii", $consignmentInvoiceId, $entity_id);
            $stmt_del->execute();
        }

        log_activity($this->conn, 'PARTIAL_RETURN_FROM_CONSIGNMENT', "اقلام منتخب از فاکتور امانی فروش #{$consignmentInvoiceId} به فاکتور فروش #{$newInvoiceId} منتقل شد.");
        return ['success' => true, 'message' => 'اقلام انتخابی با موفقیت به فاکتور فروش جدید منتقل شد.', 'newInvoiceId' => $newInvoiceId];
    }

    /**
     * Converts selected items from purchase consignment invoice to regular purchase invoice.
     */
    private function partialConvertPurchaseConsignment($entity_id, $consignmentInvoiceId, $selectedItemIds) {
        $stmt = $this->conn->prepare("SELECT * FROM purchase_invoices WHERE id = ? AND entity_id = ? AND is_consignment = 1");
        $stmt->bind_param("ii", $consignmentInvoiceId, $entity_id);
        $stmt->execute();
        $consignmentInvoice = db_stmt_to_assoc_array($stmt);
        if (empty($consignmentInvoice)) throw new Exception('فاکتور امانی یافت نشد.', 404);
        $consignmentInvoice = $consignmentInvoice[0];
        assert_transaction_date_editable($this->conn, $entity_id, $consignmentInvoice['date']);

        $stmt_items = $this->conn->prepare("SELECT * FROM purchase_invoice_items WHERE invoiceId = ?");
        $stmt_items->bind_param("i", $consignmentInvoiceId);
        $stmt_items->execute();
        $allItems = db_stmt_to_assoc_array($stmt_items);
        $allItemIds = array_map('intval', array_column($allItems, 'id'));

        foreach ($selectedItemIds as $itemId) {
            if (!in_array($itemId, $allItemIds, true)) {
                throw new Exception("قلم با شناسه {$itemId} به این فاکتور تعلق ندارد.", 400);
            }
        }

        $isFullConversion = count($selectedItemIds) === count($allItems);

        $selectedItemsTotal = 0;
        foreach ($allItems as $item) {
            if (in_array(intval($item['id']), $selectedItemIds, true)) {
                $selectedItemsTotal += floatval($item['quantity']) * floatval($item['unitPrice']);
            }
        }
        $split = $this->splitConsignmentAmounts($consignmentInvoice, $selectedItemsTotal);

        if ($isFullConversion) {
            $stmt_update = $this->conn->prepare("UPDATE purchase_invoices SET is_consignment = 0 WHERE id = ? AND entity_id = ?");
            $stmt_update->bind_param("ii", $consignmentInvoiceId, $entity_id);
            $stmt_update->execute();

            log_activity($this->conn, 'FULL_RETURN_FROM_CONSIGNMENT', "فاکتور خرید امانی #{$consignmentInvoiceId} به طور کامل به فاکتور خرید تبدیل شد.");
            return ['success' => true, 'message' => 'فاکتور امانی به طور کامل به فاکتور خرید تبدیل شد.', 'newInvoiceId' => $consignmentInvoiceId];
        }

        $stmt_new = $this->conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, description, is_consignment, due_date, payment_terms, tax_rate, tax_amount, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
        $desc = ($consignmentInvoice['description'] ?? '') . " (منتقل شده از فاکتور امانی #{$consignmentInvoiceId})";
        $c_due = $consignmentInvoice['due_date'] ?? null;
        $c_pt  = $consignmentInvoice['payment_terms'] ?? null;
        $c_in  = null;
        $movedPaid = $split['movedPaid'];
        $stmt_new->bind_param("iisdddsssdds", $entity_id, $consignmentInvoice['supplierId'], $consignmentInvoice['date'], $split['selectedItemsTotal'], $split['proportionalDiscount'], $movedPaid, $desc, $c_due, $c_pt, $split['taxRate'], $split['proportionalTax'], $c_in);
        $stmt_new->execute();
        $newInvoiceId = $this->conn->insert_id;

        $ph = implode(',', array_fill(0, count($selectedItemIds), '?'));
        $types = str_repeat('i', count($selectedItemIds));
        $stmt_move = $this->conn->prepare("UPDATE purchase_invoice_items SET invoiceId = ? WHERE id IN ($ph)");
        $params = array_merge([$newInvoiceId], $selectedItemIds);
        $stmt_move->bind_param("i" . $types, ...$params);
        $stmt_move->execute();

        $stmt_update = $this->conn->prepare("UPDATE purchase_invoices SET totalAmount = ?, discount = ?, tax_amount = ?, paidAmount = ? WHERE id = ? AND entity_id = ?");
        $stmt_update->bind_param("ddddii", $split['remainingTotal'], $split['remainingDiscount'], $split['remainingTax'], $split['remainingPaid'], $consignmentInvoiceId, $entity_id);
        $stmt_update->execute();

        $stmt_check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM purchase_invoice_items WHERE invoiceId = ?");
        $stmt_check->bind_param("i", $consignmentInvoiceId);
        $stmt_check->execute();
        $remaining = db_stmt_to_assoc_array($stmt_check);
        if (intval($remaining[0]['cnt']) === 0) {
            $stmt_del = $this->conn->prepare("DELETE FROM purchase_invoices WHERE id = ? AND entity_id = ?");
            $stmt_del->bind_param("ii", $consignmentInvoiceId, $entity_id);
            $stmt_del->execute();
        }

        log_activity($this->conn, 'PARTIAL_RETURN_FROM_CONSIGNMENT', "اقلام منتخب از فاکتور امانی خرید #{$consignmentInvoiceId} به فاکتور خرید #{$newInvoiceId} منتقل شد.");
        return ['success' => true, 'message' => 'اقلام انتخابی با موفقیت به فاکتور خرید جدید منتقل شد.', 'newInvoiceId' => $newInvoiceId];
    }

    /**
     * Sold inventory still sitting on a consignment purchase invoice.
     * Optional $salesInvoiceId limits rows to one regular sales invoice.
     */
    public function findSoldConsignmentPurchaseItems($entity_id, $salesInvoiceId = null) {
        $rows = $this->querySoldConsignmentPurchaseRows(intval($entity_id), $salesInvoiceId ? intval($salesInvoiceId) : null);
        $grouped = [];
        foreach ($rows as $row) {
            $cid = intval($row['consignment_invoice_id']);
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'consignment_invoice_id' => $cid,
                    'consignment_date' => $row['consignment_date'],
                    'supplier_id' => intval($row['supplierId']),
                    'supplier_name' => $row['supplier_name'],
                    'remaining_in_stock' => intval($row['remaining_in_stock'] ?? 0),
                    'items' => [],
                ];
            }
            $grouped[$cid]['items'][] = [
                'purchase_item_id' => intval($row['purchase_item_id']),
                'product_name' => $row['product_name'],
                'dimensions' => $row['dimensions'],
                'line_qty' => intval($row['line_qty']),
                'unit_price' => floatval($row['unitPrice']),
                'inventory_id' => intval($row['inventory_id']),
                'serial_id' => $row['serial_id'],
                'status' => $row['status'],
                'sales_invoice_id' => intval($row['sales_invoice_id']),
                'sales_date' => $row['sales_date'],
                'customer_name' => $row['customer_name'],
            ];
        }
        return [
            'success' => true,
            'count' => count($rows),
            'invoices' => array_values($grouped),
            'items' => $rows,
        ];
    }

    /**
     * Move sold consignment-purchase lines onto regular purchase invoices.
     * Remaining unsold lines stay on the consignment invoice.
     * When $salesInvoiceId is set, only stock sold on that invoice is converted.
     */
    public function convertSoldConsignmentPurchaseItems($entity_id, $salesInvoiceId = null, $manageTransaction = true) {
        $entity_id = intval($entity_id);
        $salesInvoiceId = $salesInvoiceId ? intval($salesInvoiceId) : null;
        $rows = $this->querySoldConsignmentPurchaseRows($entity_id, $salesInvoiceId);
        $empty = ['success' => true, 'converted_count' => 0, 'invoices' => [], 'message' => ''];
        if (empty($rows)) {
            return $empty;
        }

        $groups = [];
        foreach ($rows as $row) {
            $cid = intval($row['consignment_invoice_id']);
            $piiId = intval($row['purchase_item_id']);
            if (!isset($groups[$cid])) {
                $groups[$cid] = [
                    'header' => $row,
                    'sales_dates' => [],
                    'sales_ids' => [],
                    'lines' => [],
                ];
            }
            if (!empty($row['sales_date'])) {
                $groups[$cid]['sales_dates'][] = $row['sales_date'];
            }
            if (!empty($row['sales_invoice_id'])) {
                $groups[$cid]['sales_ids'][] = intval($row['sales_invoice_id']);
            }
            if (!isset($groups[$cid]['lines'][$piiId])) {
                $groups[$cid]['lines'][$piiId] = [
                    'pii_id' => $piiId,
                    'line_qty' => intval($row['line_qty']),
                    'unitPrice' => floatval($row['unitPrice']),
                    'productId' => intval($row['productId']),
                    'dimensions' => $row['dimensions'],
                    'is_service' => intval($row['is_service'] ?? 0),
                    'item_description' => $row['item_description'],
                    'sold_qty' => 0,
                    'inventory_ids' => [],
                ];
            }
            $groups[$cid]['lines'][$piiId]['sold_qty']++;
            $groups[$cid]['lines'][$piiId]['inventory_ids'][] = intval($row['inventory_id']);
        }

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $created = [];
            $convertedCount = 0;
            foreach ($groups as $cid => $group) {
                $salesIds = array_values(array_unique($group['sales_ids']));
                $salesDate = !empty($group['sales_dates']) ? max($group['sales_dates']) : date('Y-m-d');
                $result = $this->convertSoldLinesOnConsignmentPurchase(
                    $entity_id,
                    $cid,
                    array_values($group['lines']),
                    $salesDate,
                    $salesIds
                );
                if ($result) {
                    $created[] = $result;
                    $convertedCount += intval($result['item_count']);
                }
            }
            if ($manageTransaction) {
                $this->conn->commit();
            }
            $message = $convertedCount > 0
                ? "{$convertedCount} قلم فروخته‌شده از فاکتور امانی به فاکتور خرید تبدیل شد."
                : '';
            return [
                'success' => true,
                'converted_count' => $convertedCount,
                'invoices' => $created,
                'message' => $message,
            ];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            throw $e;
        }
    }

    private function querySoldConsignmentPurchaseRows($entity_id, $salesInvoiceId = null) {
        $sql = "
            SELECT
                pi.id AS consignment_invoice_id,
                pi.date AS consignment_date,
                pi.supplierId,
                pi.totalAmount,
                pi.discount,
                pi.tax_amount,
                pi.tax_rate,
                pi.paidAmount,
                pi.description,
                pi.due_date,
                pi.payment_terms,
                s.name AS supplier_name,
                pii.id AS purchase_item_id,
                pii.productId,
                pii.dimensions,
                pii.quantity AS line_qty,
                pii.unitPrice,
                pii.is_service,
                pii.item_description,
                ii.id AS inventory_id,
                COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_id,
                ii.status,
                si.id AS sales_invoice_id,
                si.date AS sales_date,
                c.name AS customer_name,
                p.name AS product_name,
                (
                    SELECT COUNT(*)
                    FROM purchase_invoice_items pii_r
                    JOIN inventory_items ii_r ON ii_r.purchase_invoice_item_id = pii_r.id
                    WHERE pii_r.invoiceId = pi.id AND ii_r.status = 'in_stock'
                ) AS remaining_in_stock
            FROM inventory_items ii
            JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
            JOIN purchase_invoices pi ON pi.id = pii.invoiceId
            LEFT JOIN suppliers s ON s.id = pi.supplierId
            LEFT JOIN products p ON p.id = COALESCE(ii.product_id, pii.productId)
            LEFT JOIN sales_invoice_items sii ON sii.id = ii.sales_invoice_item_id
            LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
            LEFT JOIN customers c ON c.id = si.customerId
            WHERE pi.entity_id = ?
              AND COALESCE(pi.is_consignment, 0) = 1
              AND ii.status IN ('sold', 'returned')
              AND ii.sales_invoice_item_id IS NOT NULL
              AND ii.sales_invoice_item_id <> 0
              AND si.id IS NOT NULL
              AND COALESCE(si.is_consignment, 0) = 0
        ";
        if ($salesInvoiceId) {
            $sql .= " AND si.id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ii', $entity_id, $salesInvoiceId);
        } else {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $entity_id);
        }
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function resolveOpenPurchaseDate($entity_id, $desiredDate) {
        $desiredDate = $desiredDate ?: date('Y-m-d');
        try {
            assert_transaction_date_editable($this->conn, $entity_id, $desiredDate);
            return [$desiredDate, ''];
        } catch (Exception $e) {
            $today = date('Y-m-d');
            assert_transaction_date_editable($this->conn, $entity_id, $today);
            return [$today, 'تاریخ سند برابر تاریخ اصلاح است چون دوره فروش در دوره بسته‌شده قرار داشت.'];
        }
    }

    /**
     * @param array $lines Each: pii_id, line_qty, unitPrice, productId, dimensions, sold_qty, inventory_ids
     */
    private function convertSoldLinesOnConsignmentPurchase($entity_id, $consignmentInvoiceId, array $lines, $salesDate, array $salesIds) {
        $stmt = $this->conn->prepare("SELECT * FROM purchase_invoices WHERE id = ? AND entity_id = ? AND is_consignment = 1");
        $stmt->bind_param('ii', $consignmentInvoiceId, $entity_id);
        $stmt->execute();
        $consignmentInvoice = db_stmt_to_assoc_array($stmt);
        if (empty($consignmentInvoice)) {
            return null;
        }
        $consignmentInvoice = $consignmentInvoice[0];

        $stmt_items = $this->conn->prepare("SELECT * FROM purchase_invoice_items WHERE invoiceId = ?");
        $stmt_items->bind_param('i', $consignmentInvoiceId);
        $stmt_items->execute();
        $allItems = db_stmt_to_assoc_array($stmt_items);
        $allById = [];
        foreach ($allItems as $item) {
            $allById[intval($item['id'])] = $item;
        }

        $fullMoveIds = [];
        $splits = [];
        $selectedItemsTotal = 0;
        $itemCount = 0;
        foreach ($lines as $line) {
            $piiId = intval($line['pii_id']);
            if (!isset($allById[$piiId])) {
                continue;
            }
            $currentQty = intval($allById[$piiId]['quantity']);
            $soldQty = min(intval($line['sold_qty']), $currentQty);
            if ($soldQty <= 0) {
                continue;
            }
            $unitPrice = floatval($allById[$piiId]['unitPrice']);
            $selectedItemsTotal += $soldQty * $unitPrice;
            $itemCount += $soldQty;
            $line['sold_qty'] = $soldQty;
            $line['unitPrice'] = $unitPrice;
            $line['productId'] = intval($allById[$piiId]['productId']);
            $line['dimensions'] = $allById[$piiId]['dimensions'];
            $line['is_service'] = intval($allById[$piiId]['is_service'] ?? 0);
            $line['item_description'] = $allById[$piiId]['item_description'];
            $line['inventory_ids'] = array_slice(array_map('intval', $line['inventory_ids'] ?? []), 0, $soldQty);
            if ($soldQty >= $currentQty) {
                $fullMoveIds[] = $piiId;
            } else {
                $splits[] = $line;
            }
        }
        if ($itemCount <= 0) {
            return null;
        }

        $split = $this->splitConsignmentAmounts($consignmentInvoice, $selectedItemsTotal);
        [$newDate, $dateNote] = $this->resolveOpenPurchaseDate($entity_id, $salesDate);
        $salesRef = !empty($salesIds) ? ('، فاکتور فروش #' . implode('، #', $salesIds)) : '';
        $desc = trim((string)($consignmentInvoice['description'] ?? ''));
        $desc = ($desc !== '' ? $desc . ' — ' : '') . "تبدیل خودکار اقلام فروخته‌شده از فاکتور امانی #{$consignmentInvoiceId}{$salesRef}";
        if ($dateNote !== '') {
            $desc .= ' — ' . $dateNote;
        }

        $stmt_new = $this->conn->prepare("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, paidAmount, description, is_consignment, due_date, payment_terms, tax_rate, tax_amount, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
        $c_due = $consignmentInvoice['due_date'] ?? null;
        $c_pt = $consignmentInvoice['payment_terms'] ?? null;
        $c_in = null;
        $movedPaid = $split['movedPaid'];
        $stmt_new->bind_param(
            'iisdddsssdds',
            $entity_id,
            $consignmentInvoice['supplierId'],
            $newDate,
            $split['selectedItemsTotal'],
            $split['proportionalDiscount'],
            $movedPaid,
            $desc,
            $c_due,
            $c_pt,
            $split['taxRate'],
            $split['proportionalTax'],
            $c_in
        );
        $stmt_new->execute();
        $newInvoiceId = intval($this->conn->insert_id);
        $stmt_new->close();

        if (!empty($fullMoveIds)) {
            $ph = implode(',', array_fill(0, count($fullMoveIds), '?'));
            $types = str_repeat('i', count($fullMoveIds));
            $stmt_move = $this->conn->prepare("UPDATE purchase_invoice_items SET invoiceId = ? WHERE id IN ($ph)");
            $params = array_merge([$newInvoiceId], $fullMoveIds);
            $stmt_move->bind_param('i' . $types, ...$params);
            $stmt_move->execute();
            $stmt_move->close();
        }

        foreach ($splits as $line) {
            $newQty = intval($line['sold_qty']);
            $stmt_ins = $this->conn->prepare("INSERT INTO purchase_invoice_items (invoiceId, productId, dimensions, quantity, unitPrice, is_service, item_description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->bind_param(
                'iisidis',
                $newInvoiceId,
                $line['productId'],
                $line['dimensions'],
                $newQty,
                $line['unitPrice'],
                $line['is_service'],
                $line['item_description']
            );
            $stmt_ins->execute();
            $newPiiId = intval($this->conn->insert_id);
            $stmt_ins->close();

            $invIds = $line['inventory_ids'];
            if (!empty($invIds)) {
                $ph = implode(',', array_fill(0, count($invIds), '?'));
                $types = str_repeat('i', count($invIds));
                $stmt_inv = $this->conn->prepare("UPDATE inventory_items SET purchase_invoice_item_id = ?, is_virtual = 0 WHERE id IN ($ph) AND entity_id = ?");
                $params = array_merge([$newPiiId], $invIds, [$entity_id]);
                $stmt_inv->bind_param('i' . $types . 'i', ...$params);
                $stmt_inv->execute();
                $stmt_inv->close();
            }

            $remainQty = intval($line['line_qty']) - $newQty;
            $stmt_qty = $this->conn->prepare("UPDATE purchase_invoice_items SET quantity = ? WHERE id = ? AND invoiceId = ?");
            $stmt_qty->bind_param('iii', $remainQty, $line['pii_id'], $consignmentInvoiceId);
            $stmt_qty->execute();
            $stmt_qty->close();
        }

        $stmt_update = $this->conn->prepare("UPDATE purchase_invoices SET totalAmount = ?, discount = ?, tax_amount = ?, paidAmount = ? WHERE id = ? AND entity_id = ?");
        $stmt_update->bind_param('ddddii', $split['remainingTotal'], $split['remainingDiscount'], $split['remainingTax'], $split['remainingPaid'], $consignmentInvoiceId, $entity_id);
        $stmt_update->execute();
        $stmt_update->close();

        $stmt_check = $this->conn->prepare("SELECT COUNT(*) as cnt FROM purchase_invoice_items WHERE invoiceId = ?");
        $stmt_check->bind_param('i', $consignmentInvoiceId);
        $stmt_check->execute();
        $remaining = db_stmt_to_assoc_array($stmt_check);
        if (intval($remaining[0]['cnt'] ?? 0) === 0) {
            $stmt_del = $this->conn->prepare("DELETE FROM purchase_invoices WHERE id = ? AND entity_id = ?");
            $stmt_del->bind_param('ii', $consignmentInvoiceId, $entity_id);
            $stmt_del->execute();
            $stmt_del->close();
        }

        log_activity(
            $this->conn,
            'AUTO_CONVERT_SOLD_CONSIGNMENT',
            "{$itemCount} قلم فروخته‌شده از فاکتور امانی خرید #{$consignmentInvoiceId} به فاکتور خرید #{$newInvoiceId} منتقل شد."
        );

        return [
            'consignment_invoice_id' => intval($consignmentInvoiceId),
            'new_purchase_id' => $newInvoiceId,
            'item_count' => $itemCount,
            'date' => $newDate,
        ];
    }

    /**
     * Last meter prices for a product (or similar products by name/collection).
     * Prefer same customer/supplier (+ matching dimensions), then fall back to latest by date.
     *
     * @return array{prices: array<int, array>, source: string}
     */
    public function getRecentProductPrices($type, $productId, $personId = 0, $dimensions = '', $excludeInvoiceId = 0, $limit = 3, $productName = '', $allowSimilar = true) {
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);
        $productId = intval($productId);
        $personId = intval($personId);
        $excludeInvoiceId = intval($excludeInvoiceId);
        $limit = max(1, min(10, intval($limit)));
        $dimensions = trim((string)$dimensions);
        $productName = trim((string)$productName);
        $allowSimilar = (bool)$allowSimilar;
        if ($dimensions === 'custom') {
            $dimensions = '';
        }

        if ($entityId <= 0 || !in_array($type, ['sales', 'purchase'], true)) {
            return ['prices' => [], 'source' => 'none'];
        }

        $related = $this->resolveRelatedProductsForPricing($entityId, $productId, $productName, $allowSimilar);
        $productIds = $related['ids'];
        if (empty($productIds)) {
            return ['prices' => [], 'source' => 'none'];
        }

        $isSales = ($type === 'sales');
        $invTable = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $personCol = $isSales ? 'customerId' : 'supplierId';
        $personJoin = $isSales
            ? "LEFT JOIN customers per ON per.id = inv.customerId"
            : "LEFT JOIN suppliers per ON per.id = inv.supplierId";

        $hasDims = ($dimensions !== '');
        $excludeSql = $excludeInvoiceId > 0 ? 'AND inv.id <> ?' : '';
        $idPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
        $idTypes = str_repeat('i', count($productIds));

        // product_rank: 0 exact product, 1 same collection/family, 2 weaker name match
        $exactId = $productId > 0 ? $productId : 0;
        $productRankExpr = $exactId > 0
            ? "CASE WHEN it.productId = ? THEN 0 ELSE 1 END"
            : '1';

        // priority: person+dims, person, dims, then date — plus product_rank
        if ($personId > 0 && $hasDims) {
            $priorityExpr = "CASE
                WHEN inv.`{$personCol}` = ? AND it.dimensions = ? THEN 1
                WHEN inv.`{$personCol}` = ? THEN 2
                WHEN it.dimensions = ? THEN 3
                ELSE 4
            END";
            $prioTypes = 'isis';
            $prioParams = [$personId, $dimensions, $personId, $dimensions];
        } elseif ($personId > 0) {
            $priorityExpr = "CASE WHEN inv.`{$personCol}` = ? THEN 1 ELSE 2 END";
            $prioTypes = 'i';
            $prioParams = [$personId];
        } elseif ($hasDims) {
            $priorityExpr = "CASE WHEN it.dimensions = ? THEN 1 ELSE 2 END";
            $prioTypes = 's';
            $prioParams = [$dimensions];
        } else {
            $priorityExpr = '1';
            $prioTypes = '';
            $prioParams = [];
        }

        $types = '';
        $params = [];
        if ($exactId > 0) {
            $types .= 'i';
            $params[] = $exactId;
        }
        $types .= $prioTypes;
        $params = array_merge($params, $prioParams);
        $types .= $idTypes . 'i';
        $params = array_merge($params, $productIds, [$entityId]);
        if ($excludeInvoiceId > 0) {
            $types .= 'i';
            $params[] = $excludeInvoiceId;
        }

        $sql = "
            SELECT
                it.unitPrice,
                it.dimensions,
                it.productId AS product_id,
                pr.name AS product_name,
                inv.id AS invoice_id,
                inv.date AS invoice_date,
                inv.`{$personCol}` AS person_id,
                per.name AS person_name,
                ({$productRankExpr}) AS product_rank,
                ({$priorityExpr}) AS priority
            FROM `{$itemTable}` it
            INNER JOIN `{$invTable}` inv ON inv.id = it.invoiceId
            INNER JOIN products pr ON pr.id = it.productId
            {$personJoin}
            WHERE it.productId IN ({$idPlaceholders})
              AND inv.entity_id = ?
              AND COALESCE(it.is_service, 0) = 0
              AND it.unitPrice > 0
              AND IFNULL(it.dimensions, '') <> ''
              {$excludeSql}
            ORDER BY product_rank ASC, priority ASC, inv.date DESC, inv.id DESC, it.id DESC
            LIMIT 60
        ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['prices' => [], 'source' => 'none'];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        $out = [];
        $seen = [];
        $hadPersonMatch = false;
        $hadSimilarProduct = false;
        $currentArea = $hasDims ? dimension_area($this->conn, $dimensions) : 0;

        foreach ($rows as $row) {
            $unitPrice = round(floatval($row['unitPrice'] ?? 0));
            if ($unitPrice <= 0) {
                continue;
            }
            $dims = trim((string)($row['dimensions'] ?? ''));
            if ($dims === '') {
                continue;
            }
            $area = dimension_area($this->conn, $dims);
            if (!($area > 0)) {
                continue;
            }
            $meterPrice = (int)round($unitPrice / $area);
            if ($meterPrice <= 0) {
                continue;
            }

            $key = (string)$meterPrice;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $fromPerson = ($personId > 0 && intval($row['person_id'] ?? 0) === $personId);
            if ($fromPerson) {
                $hadPersonMatch = true;
            }
            $rowProductId = intval($row['product_id'] ?? 0);
            $isExactProduct = ($exactId > 0 && $rowProductId === $exactId);
            if (!$isExactProduct) {
                $hadSimilarProduct = true;
            }

            $unitForCurrent = ($currentArea > 0)
                ? (int)round($meterPrice * $currentArea)
                : (int)$unitPrice;

            $match = 'global';
            if ($fromPerson && $hasDims && mb_strtolower($dims) === mb_strtolower($dimensions)) {
                $match = 'person_exact';
            } elseif ($fromPerson) {
                $match = 'person_similar';
            } elseif ($hasDims && mb_strtolower($dims) === mb_strtolower($dimensions)) {
                $match = 'global_exact';
            }
            if (!$isExactProduct) {
                $match = ($match === 'global' || $match === 'global_exact')
                    ? 'similar_product'
                    : ($match . '_similar_product');
            }

            $out[] = [
                'meter_price' => $meterPrice,
                'unit_price' => $unitForCurrent,
                'source_unit_price' => (int)$unitPrice,
                'dimensions' => $dims,
                'product_id' => $rowProductId,
                'product_name' => (string)($row['product_name'] ?? ''),
                'is_exact_product' => $isExactProduct,
                'invoice_id' => intval($row['invoice_id'] ?? 0),
                'date' => (string)($row['invoice_date'] ?? ''),
                'person_id' => intval($row['person_id'] ?? 0),
                'person_name' => (string)($row['person_name'] ?? ''),
                'from_person' => $fromPerson,
                'match' => $match,
                'priority' => intval($row['priority'] ?? 99),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        if (empty($out)) {
            return ['prices' => [], 'source' => 'none'];
        }
        $source = $hadPersonMatch ? 'person' : ($hadSimilarProduct ? 'similar' : 'global');
        return ['prices' => $out, 'source' => $source, 'related_count' => count($productIds)];
    }

    /**
     * Find product IDs that should share price history: exact id, same collection, similar name.
     * @return array{ids:int[], seed_name:string}
     */
    private function resolveRelatedProductsForPricing($entityId, $productId, $productName = '', $allowSimilar = true) {
        $seedName = trim((string)$productName);
        $collectionId = 0;
        $ids = [];

        if ($productId > 0) {
            $ids[$productId] = true;
            $stmt = $this->conn->prepare("SELECT name, collection_id FROM products WHERE id = ? AND entity_id = ? LIMIT 1");
            $stmt->bind_param('ii', $productId, $entityId);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
            if ($row) {
                if ($seedName === '') {
                    $seedName = (string)($row['name'] ?? '');
                }
                $collectionId = intval($row['collection_id'] ?? 0);
            }
        }

        if (!$allowSimilar) {
            return ['ids' => array_values(array_map('intval', array_keys($ids))), 'seed_name' => $seedName];
        }

        if ($collectionId > 0) {
            $stmt = $this->conn->prepare("SELECT id FROM products WHERE entity_id = ? AND collection_id = ? LIMIT 80");
            $stmt->bind_param('ii', $entityId, $collectionId);
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $r) {
                $ids[intval($r['id'])] = true;
            }
        }

        $normalized = $this->normalizeProductNameForPricing($seedName);
        $tokens = $this->extractProductNameTokensForPricing($normalized);
        if ($normalized !== '' || !empty($tokens)) {
            $candidates = [];
            // Prefix / contains on cleaned name family (strip codes)
            if (mb_strlen($normalized) >= 4) {
                $like = '%' . $this->escapeLike($normalized) . '%';
                $stmt = $this->conn->prepare("SELECT id, name FROM products WHERE entity_id = ? AND name LIKE ? LIMIT 80");
                $stmt->bind_param('is', $entityId, $like);
                $stmt->execute();
                $candidates = array_merge($candidates, db_stmt_to_assoc_array($stmt));
            }
            // Token AND match (e.g. گلیم + مراکشی) — use up to 2 strongest tokens
            $strong = array_slice($tokens, 0, 2);
            if (count($strong) >= 1) {
                $where = ['entity_id = ?'];
                $types = 'i';
                $params = [$entityId];
                foreach ($strong as $tok) {
                    $where[] = 'name LIKE ?';
                    $types .= 's';
                    $params[] = '%' . $this->escapeLike($tok) . '%';
                }
                $sql = 'SELECT id, name FROM products WHERE ' . implode(' AND ', $where) . ' LIMIT 80';
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $candidates = array_merge($candidates, db_stmt_to_assoc_array($stmt));
            }

            foreach ($candidates as $c) {
                $cid = intval($c['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                // Keep candidates that share the normalized family or enough tokens
                $cNorm = $this->normalizeProductNameForPricing((string)($c['name'] ?? ''));
                if ($normalized !== '' && $cNorm !== '') {
                    if (mb_strpos($cNorm, $normalized) !== false || mb_strpos($normalized, $cNorm) !== false) {
                        $ids[$cid] = true;
                        continue;
                    }
                    // Shared significant tokens: primary (longest) token is enough, else need 2
                    $cTokens = $this->extractProductNameTokensForPricing($cNorm);
                    $shared = array_values(array_intersect($tokens, $cTokens));
                    $primary = $tokens[0] ?? '';
                    if (($primary !== '' && in_array($primary, $shared, true)) || count($shared) >= 2) {
                        $ids[$cid] = true;
                    }
                } elseif (!empty($tokens)) {
                    $cTokens = $this->extractProductNameTokensForPricing($cNorm);
                    $shared = array_values(array_intersect($tokens, $cTokens));
                    $primary = $tokens[0] ?? '';
                    if (($primary !== '' && in_array($primary, $shared, true)) || count($shared) >= 2) {
                        $ids[$cid] = true;
                    }
                }
            }
        }

        return ['ids' => array_values(array_map('intval', array_keys($ids))), 'seed_name' => $seedName];
    }

    private function normalizeProductNameForPricing($name) {
        $s = trim(mb_strtolower((string)$name));
        if ($s === '') {
            return '';
        }
        // Drop design/product codes: "کد 26", "کد26", "- k004", "#6732"
        $s = preg_replace('/\bکد\s*[:\-_]?\s*[\w\d]+/u', ' ', $s);
        $s = preg_replace('/\bk\s*\d+\b/iu', ' ', $s);
        $s = preg_replace('/#\s*\d+/u', ' ', $s);
        $s = preg_replace('/\b\d{2,}\b/u', ' ', $s);
        $s = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $s);
        $s = preg_replace('/\s*[-–—_|\/]+\s*/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function extractProductNameTokensForPricing($normalizedName) {
        $stop = [
            'کالکشن' => true, 'مجموعه' => true, 'فرش' => true,
            'دستباف' => true, 'دستبافت' => true, 'ماشینی' => true, 'و' => true,
            'از' => true, 'با' => true, 'در' => true, 'به' => true, 'برای' => true,
            'نوع' => true, 'مدل' => true, 'سایز' => true, 'ابعاد' => true,
            'کرم' => true, 'ای' => true, 'نو' => true,
        ];
        $parts = preg_split('/\s+/u', trim((string)$normalizedName)) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset($stop[$p])) {
                continue;
            }
            if (preg_match('/^\d+$/u', $p)) {
                continue;
            }
            $tokens[$p] = mb_strlen($p);
        }
        // Longer tokens first (more specific)
        arsort($tokens);
        return array_keys($tokens);
    }

    private function escapeLike($value) {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);
    }
}