<?php
// /api/models/ReturnInvoice.php - COMPLETELY REWRITTEN VERSION
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

/**
 * ReturnInvoice Model
 * 
 * Manages sales and purchase return invoices with complete data integrity:
 * - Tracks returned inventory items with proper status management
 * - Links return items to original invoice items for audit trail
 * - Handles payments, checks, and account balance updates
 * - Supports entity isolation and multi-tenant operations
 * - Validates data integrity before save/delete operations
 */
class ReturnInvoice extends BaseModel {
    protected $tableName = 'return_invoices';
    protected $allowedFilters = ['ri.id', 'ri.date', 'ri.description', 'c.name', 's.name', 'p.name'];
    protected $allowedSorts = ['id', 'date', 'totalAmount', 'paidAmount', 'remainingAmount', 'type'];

    public function __construct($conn) {
        parent::__construct($conn);
        $this->alias = 'ri';
        $this->select = "SELECT ri.*, 
            CASE 
                WHEN ri.person_type = 'customer' THEN c.name
                WHEN ri.person_type = 'supplier' THEN s.name
                WHEN ri.person_type = 'partner' THEN p.name
            END as personName,
            (ri.totalAmount - ri.discount - ri.paidAmount) as remainingAmount,
            CASE 
                WHEN ri.type = 'sales_return' THEN 'sales'
                WHEN ri.type = 'purchase_return' THEN 'purchase'
            END as type";
        $this->from = "FROM `{$this->tableName}` as ri";
        $this->join = "LEFT JOIN customers c ON ri.person_id = c.id AND ri.person_type = 'customer'
                      LEFT JOIN suppliers s ON ri.person_id = s.id AND ri.person_type = 'supplier'
                      LEFT JOIN partners p ON ri.person_id = p.id AND ri.person_type = 'partner'";
        $this->allowedFilters = ['ri.id', 'ri.date', 'ri.description', 'c.name', 's.name', 'p.name'];
            $this->serialSearchExistsSql = "
            SELECT 1 FROM return_invoice_items rii_serial
            INNER JOIN inventory_items ii_serial ON (
                (ri.type = 'sales_return' AND (
                    ii_serial.sales_invoice_item_id = rii_serial.original_invoice_item_id
                    OR ii_serial.returned_from_sales_item_id = rii_serial.original_invoice_item_id
                    OR FIND_IN_SET(ii_serial.id, rii_serial.inventory_item_ids)
                ))
                OR (ri.type = 'purchase_return' AND (
                    ii_serial.purchase_invoice_item_id = rii_serial.original_invoice_item_id
                    OR FIND_IN_SET(ii_serial.id, rii_serial.inventory_item_ids)
                ))
            )
            WHERE rii_serial.return_invoice_id = ri.id
              AND (ii_serial.serial_id LIKE ? OR CONCAT('INV-', LPAD(ii_serial.id, 6, '0')) LIKE ?)
        ";
        $this->ensureReturnTablesExist();
    }

    /**
     * Override getPaginated to support invoiceType filter
     */
    public function getPaginated($input) {
        $typeParam = '';
        // Handle invoiceType filter
        if (!empty($input['invoiceType'])) {
            $typeParam = $input['invoiceType'] === 'sales' ? 'sales_return' : 'purchase_return';
            $this->where .= " AND ri.type = '{$typeParam}'";
        } elseif (!empty($input['tableName'])) {
            if ($input['tableName'] === 'sales_returns') {
                $typeParam = 'sales_return';
                $this->where .= " AND ri.type = 'sales_return'";
            } elseif ($input['tableName'] === 'purchase_returns') {
                $typeParam = 'purchase_return';
                $this->where .= " AND ri.type = 'purchase_return'";
            }
        }
        
        $paginatedResult = parent::getPaginated($input);
        
        if (!empty($paginatedResult['data'])) {
            $typeToFetch = !empty($typeParam) ? $typeParam : 'sales_return';
            $paginatedResult['data'] = $this->fetchReturnInvoiceDetails($paginatedResult['data'], $typeToFetch);
            $paginatedResult['data'] = $this->applyPersonSettlementToReturns($paginatedResult['data']);
        }
        
        return $paginatedResult;
    }

    /**
     * اگر مانده فاکتورهای اصلی همان شخص، بدهی/طلب مرجوعی را پوشش دهد،
     * مانده نمایشی مرجوعی کم می‌شود تا وضعیت «تسویه شده» با صورتحساب شخص هم‌خوان باشد.
     * (فقط نمایش — بدون تغییر دیتابیس)
     */
    private function applyPersonSettlementToReturns(array $invoices) {
        if (empty($invoices)) {
            return [];
        }
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return $invoices;
        }

        $byKind = ['sales_return' => [], 'purchase_return' => []];
        foreach ($invoices as $inv) {
            $rawType = $inv['type'] ?? '';
            // select alias maps sales_return → 'sales', purchase_return → 'purchase'
            if ($rawType === 'sales' || $rawType === 'sales_return') {
                $byKind['sales_return'][] = $inv;
            } elseif ($rawType === 'purchase' || $rawType === 'purchase_return') {
                $byKind['purchase_return'][] = $inv;
            }
        }

        $offsets = [];

        foreach (['sales_return' => true, 'purchase_return' => false] as $retType => $isSales) {
            $group = $byKind[$retType];
            if (empty($group)) {
                continue;
            }
            $personIds = array_values(array_unique(array_filter(array_map('intval', array_column($group, 'person_id')))));
            if (empty($personIds)) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($personIds), '?'));
            $types = str_repeat('i', count($personIds));
            $personCol = $isSales ? 'customerId' : 'supplierId';
            $invoiceTable = $isSales ? 'sales_invoices' : 'purchase_invoices';

            // مانده باز فاکتورهای اصلی همان اشخاص (پوشش‌دهنده مرجوعی در صورتحساب)
            $sql = "SELECT {$personCol} AS person_id,
                           SUM(totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) AS open_remaining
                    FROM `{$invoiceTable}`
                    WHERE entity_id = ? AND {$personCol} IN ({$ph})
                      AND (totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount) > " . MINIMUM_AMOUNT . "
                      AND is_consignment = 0
                    GROUP BY {$personCol}";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i' . $types, $entityId, ...$personIds);
            $stmt->execute();
            $poolByPerson = [];
            foreach (db_stmt_to_assoc_array($stmt) as $row) {
                $poolByPerson[intval($row['person_id'])] = max(0, floatval($row['open_remaining']));
            }

            // اعتبار علی‌الحساب در جهت تسویه مرجوعی:
            // فروش: پرداخت به مشتری (payment) · خرید: دریافت از تأمین‌کننده (receipt)
            $advType = $isSales ? 'payment' : 'receipt';
            $personType = $isSales ? 'customer' : 'supplier';
            $advSql = "SELECT person_id, SUM(amount) AS amt
                       FROM payments
                       WHERE entity_id = ? AND person_type = ? AND invoiceId IS NULL
                         AND transaction_type = ? AND person_id IN ({$ph})
                       GROUP BY person_id";
            $advStmt = $this->conn->prepare($advSql);
            $advStmt->bind_param('iss' . $types, $entityId, $personType, $advType, ...$personIds);
            $advStmt->execute();
            foreach (db_stmt_to_assoc_array($advStmt) as $row) {
                $pid = intval($row['person_id']);
                $poolByPerson[$pid] = ($poolByPerson[$pid] ?? 0) + max(0, floatval($row['amt']));
            }

            // FIFO روی مرجوعی‌های باز همان شخص
            $openReturns = [];
            foreach ($group as $inv) {
                $rem = floatval($inv['remainingAmount'] ?? 0);
                if ($rem > MINIMUM_AMOUNT) {
                    $openReturns[] = $inv;
                }
            }
            usort($openReturns, function ($a, $b) {
                $da = strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
                return $da !== 0 ? $da : (intval($a['id']) - intval($b['id']));
            });

            foreach ($openReturns as $inv) {
                $pid = intval($inv['person_id']);
                $pool = $poolByPerson[$pid] ?? 0;
                if ($pool <= MINIMUM_AMOUNT) {
                    continue;
                }
                $rem = floatval($inv['remainingAmount'] ?? 0);
                $apply = min($pool, $rem);
                if ($apply > MINIMUM_AMOUNT) {
                    $offsets[intval($inv['id'])] = $apply;
                    $poolByPerson[$pid] = $pool - $apply;
                }
            }
        }

        foreach ($invoices as &$invoice) {
            $id = intval($invoice['id']);
            if (isset($offsets[$id])) {
                $invoice['remainingAmount'] = floatval($invoice['remainingAmount'] ?? 0) - $offsets[$id];
                if ($invoice['remainingAmount'] < 0) {
                    $invoice['remainingAmount'] = 0;
                }
                $invoice['settled_by_person_offset'] = $offsets[$id];
            }
        }
        unset($invoice);

        return $invoices;
    }

    /**
     * Ensures return invoice tables exist with proper constraints
     * Also fixes payments table schema to support 'return' invoiceType
     */
    private function ensureReturnTablesExist() {
        // Run once per request AND only when the schema is actually outdated:
        // executing ALTERs on every read path costs time and implicitly
        // commits any open transaction.
        static $ensured = false;
        if ($ensured) return;
        $colCheck = @$this->conn->query("SHOW COLUMNS FROM `return_invoice_items` LIKE 'inventory_item_ids'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $ensured = true;
            return;
        }
        $queries = [
            "CREATE TABLE IF NOT EXISTS `return_invoices` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `entity_id` INT(11) NOT NULL,
                `type` ENUM('sales_return','purchase_return') NOT NULL,
                `original_invoice_id` INT(11) NOT NULL,
                `original_invoice_type` ENUM('sales','purchase') NOT NULL,
                `person_id` INT(11) NOT NULL,
                `person_type` ENUM('customer','supplier','partner') NOT NULL,
                `date` DATE NOT NULL,
                `totalAmount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `paidAmount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `discount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `description` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_entity_type` (`entity_id`, `type`),
                KEY `idx_person` (`person_id`, `person_type`),
                KEY `idx_date` (`date`),
                KEY `idx_original_invoice` (`original_invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS `return_invoice_items` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `return_invoice_id` INT(11) NOT NULL,
                `productId` INT(11) NOT NULL,
                `quantity` INT(11) NOT NULL,
                `unitPrice` DECIMAL(15,2) NOT NULL,
                `dimensions` VARCHAR(255) NOT NULL,
                `original_invoice_item_id` INT(11) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_return_invoice` (`return_invoice_id`),
                KEY `idx_product` (`productId`),
                KEY `idx_original_item` (`original_invoice_item_id`),
                CONSTRAINT `fk_return_item_invoice` FOREIGN KEY (`return_invoice_id`) REFERENCES `return_invoices`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            // Fix payments table to include 'return' in invoiceType enum
            "ALTER TABLE `payments` MODIFY COLUMN `invoiceType` ENUM('sales','purchase','return') DEFAULT NULL",
            
            // Fix inventory_items table to include 'returned_to_supplier' status for purchase returns
            "ALTER TABLE `inventory_items` MODIFY COLUMN `status` ENUM('in_stock','sold','consignment_out','returned','returned_to_supplier') NOT NULL DEFAULT 'in_stock'",
            "ALTER TABLE `return_invoice_items` ADD COLUMN `inventory_item_ids` TEXT NULL",
            "ALTER TABLE `inventory_items` ADD COLUMN `returned_from_sales_item_id` INT(11) DEFAULT NULL AFTER `sales_invoice_item_id`",
            "ALTER TABLE `inventory_items` ADD INDEX `idx_returned_from_sales` (`returned_from_sales_item_id`)"
        ];

        foreach ($queries as $sql) {
            try {
                $this->conn->query($sql);
            } catch (Exception $e) {
                // Table might already exist or ALTER might fail if already correct, continue
            }
        }
        $ensured = true;
    }

    /**
     * Save sales return invoice
     */
    public function saveSalesReturn($data) {
        return $this->saveReturnInvoice($data, 'sales_return');
    }

    /**
     * Save purchase return invoice
     */
    public function savePurchaseReturn($data) {
        return $this->saveReturnInvoice($data, 'purchase_return');
    }

    /**
     * Main save logic for return invoices
     * Handles both create and update operations with complete transaction support
     */
    private function saveReturnInvoice($data, $type) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'شناسه مجموعه تجاری نامعتبر است.', 'statusCode' => 401];
        }

        $date = $data['date'] ?? null;
        assert_transaction_date_editable($this->conn, $entity_id, $date);
        if (!empty($data['id'])) {
            $returnId = (int)$data['id'];
            $oldDateStmt = $this->conn->prepare("SELECT date FROM return_invoices WHERE id = ? AND entity_id = ?");
            $oldDateStmt->bind_param('ii', $returnId, $entity_id);
            $oldDateStmt->execute();
            $oldDateRow = db_stmt_to_assoc_array($oldDateStmt);
            if (!empty($oldDateRow[0]['date'])) {
                assert_transaction_date_editable($this->conn, $entity_id, $oldDateRow[0]['date']);
            }
        }

        $this->conn->begin_transaction();
        try {
            $isSalesReturn = ($type === 'sales_return');
            $is_new = empty($data['id']);
            
            // Extract and validate data
            $personType = $isSalesReturn ? 'customer' : 'supplier';
            $person_id = intval($data['person_id'] ?? 0);
            $description = trim($data['description'] ?? '');
            $items = $data['items'] ?? [];
            $payments = $data['payments'] ?? [];
            $discount = floatval(str_replace(',', '', $data['discount'] ?? 0));

            // Validation
            $this->validateReturnInvoiceData($person_id, $date, $items, $personType, $entity_id, $isSalesReturn);
            if (!validate_date_format((string)$date)) {
                throw new Exception('تاریخ فاکتور مرجوعی نامعتبر است.', 400);
            }
            $itemsTotal = $this->calculateTotals($items, [])['total'];
            if ($discount < 0 || $discount > $itemsTotal + 0.01) {
                throw new Exception('تخفیف مرجوعی باید بین صفر و جمع اقلام باشد.', 400);
            }

            // Create or update return invoice header
            $original_invoice_id = isset($data['original_invoice_id']) ? intval($data['original_invoice_id']) : null;
            $original_invoice_type = $isSalesReturn ? 'sales' : 'purchase';
            
            if ($is_new) {
                $returnInvoiceId = $this->createReturnInvoiceHeader($entity_id, $type, $person_id, $personType, $date, $description, $discount, $original_invoice_id, $original_invoice_type);
            } else {
                $returnInvoiceId = intval($data['id']);
                $this->updateReturnInvoiceHeader($returnInvoiceId, $entity_id, $type, $person_id, $date, $description, $discount);
                if (!$original_invoice_id) {
                    $stmtOrig = $this->conn->prepare("SELECT original_invoice_id FROM return_invoices WHERE id = ? AND entity_id = ?");
                    $stmtOrig->bind_param('ii', $returnInvoiceId, $entity_id);
                    $stmtOrig->execute();
                    $origRows = db_stmt_to_assoc_array($stmtOrig);
                    $original_invoice_id = intval($origRows[0]['original_invoice_id'] ?? 0);
                }
            }

            // Process items and inventory
            $this->processReturnInvoiceItemsAndStock($returnInvoiceId, $type, $items, $is_new, $entity_id, intval($original_invoice_id ?? 0));
            
            // Process payments
            $oldPayments = $this->getPaymentsForReturnInvoice($returnInvoiceId, $entity_id);
            $this->processReturnPayments($returnInvoiceId, $type, $payments, $oldPayments, $person_id, $personType, $entity_id);

            // Calculate and update totals
            $totals = $this->calculateTotals($items, $payments);
            $this->updateReturnInvoiceTotals($returnInvoiceId, $totals['total'], $totals['paid']);

            $this->conn->commit();

            // Log activity
            $logAction = $isSalesReturn ? 'SAVE_SALES_RETURN' : 'SAVE_PURCHASE_RETURN';
            $actionText = $is_new ? 'ایجاد شد' : 'ویرایش شد';
            log_activity($this->conn, $logAction, "فاکتور مرجوعی " . ($isSalesReturn ? 'فروش' : 'خرید') . " به شناسه {$returnInvoiceId} {$actionText}.");

            try {
                $pids = [];
                foreach ($items as $it) {
                    if (!empty($it['productId'])) $pids[] = intval($it['productId']);
                }
                if (!empty($pids) && file_exists(__DIR__ . '/../core/IntegrationService.php')) {
                    require_once __DIR__ . '/../core/IntegrationService.php';
                    (new IntegrationService($this->conn))->enqueueStockSync($entity_id, $pids);
                }
            } catch (Throwable $e) {
                // ignore
            }

            return ['success' => true, 'id' => $returnInvoiceId];
            
        } catch (Exception $ex) {
            $this->conn->rollback();
            $code = ($ex->getCode() && $ex->getCode() < 600) ? intval($ex->getCode()) : 500;
            return ['error' => 'خطا در ذخیره فاکتور مرجوعی: ' . $ex->getMessage(), 'statusCode' => $code];
        }
    }

    /**
     * Validate return invoice data before save
     */
    private function validateReturnInvoiceData($person_id, $date, $items, $personType, $entity_id, $isSalesReturn) {
        if (!$person_id || $person_id <= 0) {
            throw new Exception('انتخاب شخص الزامی است.', 400);
        }
        if (!$date) {
            throw new Exception('تاریخ فاکتور مرجوعی مشخص نشده است.', 400);
        }
        if (empty($items)) {
            throw new Exception('حداقل یک قلم کالا لازم است.', 400);
        }

        // Verify person exists and belongs to entity
        $personTable = $isSalesReturn ? 'customers' : 'suppliers';
        $stmt = $this->conn->prepare("SELECT id FROM {$personTable} WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $person_id, $entity_id);
        $stmt->execute();
        if (empty(db_stmt_to_assoc_array($stmt))) {
            throw new Exception('شخص انتخاب شده یافت نشد یا متعلق به این مجموعه نیست.', 404);
        }

        // Validate each item
        foreach ($items as $idx => $item) {
            $productId = intval($item['productId'] ?? 0);
            $dimensions = trim($item['dimensions'] ?? '');
            $quantity = intval($item['quantity'] ?? 0);
            $unitPrice = floatval(str_replace(',', '', $item['unitPrice'] ?? 0));

            if ($productId <= 0) {
                throw new Exception('محصول برای ردیف ' . ($idx + 1) . ' انتخاب نشده است.', 400);
            }
            if ($dimensions === '') {
                throw new Exception('ابعاد برای ردیف ' . ($idx + 1) . ' مشخص نشده است.', 400);
            }
            if ($quantity <= 0) {
                throw new Exception('تعداد برای ردیف ' . ($idx + 1) . ' نامعتبر است.', 400);
            }
            if ($unitPrice < 0) {
                throw new Exception('قیمت واحد برای ردیف ' . ($idx + 1) . ' نامعتبر است.', 400);
            }
        }
    }

    /**
     * Create new return invoice header
     */
    private function createReturnInvoiceHeader($entity_id, $type, $person_id, $personType, $date, $description, $discount, $original_invoice_id = null, $original_invoice_type = null) {
        // Determine original invoice type from return type if not provided
        if (!$original_invoice_type) {
            $original_invoice_type = ($type === 'sales_return') ? 'sales' : 'purchase';
        }
        
        $stmt = $this->conn->prepare(
            "INSERT INTO return_invoices (entity_id, type, original_invoice_id, original_invoice_type, person_id, person_type, date, description, discount, totalAmount, paidAmount) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)"
        );
        $original_invoice_id = intval($original_invoice_id ?? 0);
        $stmt->bind_param('isisssssd', $entity_id, $type, $original_invoice_id, $original_invoice_type, $person_id, $personType, $date, $description, $discount);
        $stmt->execute();
        $id = $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Update existing return invoice header
     */
    private function updateReturnInvoiceHeader($returnInvoiceId, $entity_id, $type, $person_id, $date, $description, $discount) {
        // Verify ownership
        $stmt_check = $this->conn->prepare("SELECT id FROM return_invoices WHERE id = ? AND entity_id = ? AND type = ?");
        $stmt_check->bind_param('iis', $returnInvoiceId, $entity_id, $type);
        $stmt_check->execute();
        if (empty(db_stmt_to_assoc_array($stmt_check))) {
            throw new Exception('فاکتور مرجوعی یافت نشد یا متعلق به شما نیست.', 404);
        }

        $stmt = $this->conn->prepare(
            "UPDATE return_invoices 
             SET person_id = ?, date = ?, description = ?, discount = ? 
             WHERE id = ? AND entity_id = ?"
        );
        $stmt->bind_param('issdii', $person_id, $date, $description, $discount, $returnInvoiceId, $entity_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update return invoice totals
     */
    private function updateReturnInvoiceTotals($returnInvoiceId, $totalAmount, $paidAmount) {
        $stmt = $this->conn->prepare("UPDATE return_invoices SET totalAmount = ?, paidAmount = ? WHERE id = ?");
        $stmt->bind_param('ddi', $totalAmount, $paidAmount, $returnInvoiceId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Calculate totals from items and payments
     * Note: Total does NOT include discount reduction (discount is stored separately)
     */
    private function calculateTotals(array $items, array $payments) {
        $total = 0;
        foreach ($items as $item) {
            $qty = intval($item['quantity'] ?? 0);
            $price = parse_money($item['unitPrice'] ?? 0);
            $total += round($qty * $price, 2);
        }

        $paid = 0;
        foreach ($payments as $payment) {
            $paid += round(parse_money($payment['amount'] ?? 0), 2);
        }

        return ['total' => $total, 'paid' => $paid];
    }

    /**
     * Process return invoice items and update inventory status
     */
    private function processReturnInvoiceItemsAndStock($returnInvoiceId, $type, $newItems, $is_new, $entity_id, $originalInvoiceId = 0) {
        $isSalesReturn = ($type === 'sales_return');

        // For edits, revert previous items first
        if (!$is_new) {
            $this->revertPreviousReturnItems($returnInvoiceId, $isSalesReturn, $entity_id);
        }

        // Process each new item
        foreach ($newItems as $item) {
            $productId = intval($item['productId']);
            $dimensions = trim($item['dimensions']);
            $quantity = intval($item['quantity']);
            $unitPrice = floatval(str_replace(',', '', $item['unitPrice'] ?? 0));
            $selectedIdentifiers = $item['selected_identifiers'] ?? [];

            // Resolve which inventory items to return
            $itemIdsToReturn = $this->resolveReturnIdentifiers(
                $isSalesReturn, 
                $productId, 
                $dimensions, 
                $quantity, 
                $selectedIdentifiers, 
                $entity_id,
                $originalInvoiceId
            );

            // Find the original invoice item ID
            $originalItemId = $this->resolveOriginalInvoiceItemId(
                $isSalesReturn, 
                $itemIdsToReturn, 
                $entity_id
            );

            $inventoryIdsStr = implode(',', array_map('intval', $itemIdsToReturn));

            // Insert return item record (persist inventory IDs so resale does not break the link)
            $stmt = $this->conn->prepare(
                "INSERT INTO return_invoice_items 
                 (return_invoice_id, productId, quantity, unitPrice, dimensions, original_invoice_item_id, inventory_item_ids) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iiidsis', $returnInvoiceId, $productId, $quantity, $unitPrice, $dimensions, $originalItemId, $inventoryIdsStr);
            $stmt->execute();
            $stmt->close();

            // Mark inventory items as returned
            $this->markInventoryItemsReturned($itemIdsToReturn, $entity_id, $isSalesReturn);
        }
    }

    /**
     * Revert previous return items when editing
     */
    private function revertPreviousReturnItems($returnInvoiceId, $isSalesReturn, $entity_id) {
        // Get previous items
        $stmt = $this->conn->prepare(
            "SELECT original_invoice_item_id, quantity 
             FROM return_invoice_items 
             WHERE return_invoice_id = ?"
        );
        $stmt->bind_param('i', $returnInvoiceId);
        $stmt->execute();
        $oldItems = db_stmt_to_assoc_array($stmt);

        // Revert each item's inventory status
        foreach ($oldItems as $oldItem) {
            $origItemId = intval($oldItem['original_invoice_item_id']);
            $qty = intval($oldItem['quantity']);
            
            if ($origItemId <= 0 || $qty <= 0) {
                continue;
            }

            if ($isSalesReturn) {
                // Revert from 'returned' back to 'sold'
                $stmt_revert = $this->conn->prepare(
                    "UPDATE inventory_items 
                     SET status = 'sold', returned_from_sales_item_id = NULL 
                     WHERE sales_invoice_item_id = ? AND entity_id = ? AND status = 'returned' 
                     ORDER BY entry_date ASC, id ASC 
                     LIMIT ?"
                );
                $stmt_revert->bind_param('iii', $origItemId, $entity_id, $qty);
            } else {
                // Revert from 'returned_to_supplier' back to 'in_stock'
                $stmt_revert = $this->conn->prepare(
                    "UPDATE inventory_items 
                     SET status = 'in_stock' 
                     WHERE purchase_invoice_item_id = ? AND entity_id = ? AND status = 'returned_to_supplier' 
                     ORDER BY entry_date ASC, id ASC 
                     LIMIT ?"
                );
                $stmt_revert->bind_param('iii', $origItemId, $entity_id, $qty);
            }
            $stmt_revert->execute();
            $stmt_revert->close();
        }

        // Delete old item records
        $stmt_del = $this->conn->prepare("DELETE FROM return_invoice_items WHERE return_invoice_id = ?");
        $stmt_del->bind_param('i', $returnInvoiceId);
        $stmt_del->execute();
        $stmt_del->close();
    }

    /**
     * Resolve which inventory item IDs to return
     * Either from explicit selection or FIFO
     */
    private function resolveReturnIdentifiers($isSalesReturn, $productId, $dimensions, $quantity, $selectedIdentifiers, $entity_id, $originalInvoiceId = 0) {
        $status = $isSalesReturn ? 'sold' : 'in_stock';
        $itemIdsToReturn = [];
        $originalInvoiceId = intval($originalInvoiceId);

        if (!empty($selectedIdentifiers)) {
            // User-selected identifiers
            $itemIdsToReturn = array_map('intval', $selectedIdentifiers);
            $idsList = implode(',', $itemIdsToReturn);
            
            $stmt = $this->conn->prepare(
                "SELECT id FROM inventory_items 
                 WHERE entity_id = ? AND id IN ({$idsList}) AND product_id = ? AND dimensions = ? AND status = ?"
            );
            $stmt->bind_param('iiss', $entity_id, $productId, $dimensions, $status);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            
            if (count($rows) !== count($itemIdsToReturn)) {
                throw new Exception('برخی شناسه‌های انتخابی معتبر نیستند یا وضعیت مورد انتظار را ندارند.', 400);
            }
        } elseif ($originalInvoiceId > 0) {
            // FIFO scoped to the original invoice so we only return items that
            // actually came from the invoice being returned.
            if ($isSalesReturn) {
                $stmt = $this->conn->prepare(
                    "SELECT ii.id FROM inventory_items ii
                     INNER JOIN sales_invoice_items sii ON ii.sales_invoice_item_id = sii.id
                     WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ?
                       AND sii.invoiceId = ?
                     ORDER BY ii.entry_date ASC, ii.id ASC
                     LIMIT ?"
                );
            } else {
                $stmt = $this->conn->prepare(
                    "SELECT ii.id FROM inventory_items ii
                     INNER JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
                     WHERE ii.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ? AND ii.status = ?
                       AND pii.invoiceId = ?
                     ORDER BY ii.entry_date ASC, ii.id ASC
                     LIMIT ?"
                );
            }
            $stmt->bind_param('iissii', $entity_id, $productId, $dimensions, $status, $originalInvoiceId, $quantity);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);

            if (count($rows) < $quantity) {
                throw new Exception('موجودی کافی برای مرجوعی از فاکتور اصلی یافت نشد.', 400);
            }
            $itemIdsToReturn = array_column($rows, 'id');
        } else {
            // FIFO selection (no original invoice reference available)
            $stmt = $this->conn->prepare(
                "SELECT id FROM inventory_items 
                 WHERE entity_id = ? AND product_id = ? AND dimensions = ? AND status = ? 
                 ORDER BY entry_date ASC, id ASC 
                 LIMIT ?"
            );
            $stmt->bind_param('iissi', $entity_id, $productId, $dimensions, $status, $quantity);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            
            if (count($rows) < $quantity) {
                throw new Exception('موجودی کافی برای مرجوعی یافت نشد.', 400);
            }
            $itemIdsToReturn = array_column($rows, 'id');
        }

        return $itemIdsToReturn;
    }

    /**
     * Find the original invoice item ID from inventory items
     * All items must belong to the same original invoice item
     */
    private function resolveOriginalInvoiceItemId($isSalesReturn, $itemIdsToReturn, $entity_id) {
        $column = $isSalesReturn ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
        $idsList = implode(',', $itemIdsToReturn);
        
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT {$column} AS link 
             FROM inventory_items 
             WHERE entity_id = ? AND id IN ({$idsList})"
        );
        $stmt->bind_param('i', $entity_id);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        
        if (empty($rows)) {
            throw new Exception('شناسه آیتم فاکتور اصلی یافت نشد.', 400);
        }
        if (count($rows) > 1) {
            throw new Exception('شناسه‌های انتخابی از چند فاکتور مختلف هستند. لطفاً اقلام را تفکیک کنید.', 400);
        }
        
        $linkId = intval($rows[0]['link']);
        if ($linkId <= 0) {
            throw new Exception('شناسه آیتم فاکتور اصلی نامعتبر است.', 400);
        }
        
        return $linkId;
    }

    /**
     * Mark inventory items as 'returned' or 'returned_to_supplier'
     */
    private function markInventoryItemsReturned($itemIdsToReturn, $entity_id, $isSalesReturn) {
        if (empty($itemIdsToReturn)) {
            return;
        }
        
        $status = $isSalesReturn ? 'returned' : 'returned_to_supplier';
        $idsList = implode(',', array_map('intval', $itemIdsToReturn));
        if ($isSalesReturn) {
            $stmt = $this->conn->prepare(
                "UPDATE inventory_items 
                 SET status = ?,
                     returned_from_sales_item_id = COALESCE(returned_from_sales_item_id, sales_invoice_item_id)
                 WHERE entity_id = ? AND id IN ({$idsList})"
            );
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE inventory_items 
                 SET status = ? 
                 WHERE entity_id = ? AND id IN ({$idsList})"
            );
        }
        $stmt->bind_param('si', $status, $entity_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get existing payments for a return invoice
     * Includes fallback for legacy payments without entity_id
     */
    private function getPaymentsForReturnInvoice($returnInvoiceId, $entity_id) {
        $stmt = $this->conn->prepare(
            "SELECT * FROM payments 
             WHERE invoiceType = 'return' AND invoiceId = ? AND entity_id = ?"
        );
        $stmt->bind_param('ii', $returnInvoiceId, $entity_id);
        $stmt->execute();
        $payments = db_stmt_to_assoc_array($stmt);

        // Fallback for legacy payments
        if (empty($payments)) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM payments 
                 WHERE invoiceType = 'return' AND invoiceId = ?"
            );
            $stmt->bind_param('i', $returnInvoiceId);
            $stmt->execute();
            $payments = db_stmt_to_assoc_array($stmt);
            
            // Fix entity_id for legacy payments
            if (!empty($payments)) {
                $stmtFix = $this->conn->prepare("UPDATE payments SET entity_id = ? WHERE id = ?");
                foreach ($payments as $payment) {
                    if (empty($payment['entity_id'])) {
                        $stmtFix->bind_param('ii', $entity_id, $payment['id']);
                        $stmtFix->execute();
                    }
                }
                $stmtFix->close();
            }
        }

        return $payments;
    }

    /**
     * Process return payments
     * Handles cash, checks, and endorsed checks
     */
    private function processReturnPayments($returnInvoiceId, $type, $newPayments, $oldPayments, $personId, $personType, $entity_id) {
        $accountModel = new Account($this->conn);
        $isSalesReturn = ($type === 'sales_return');
        $transactionType = $isSalesReturn ? 'payment' : 'receipt';

        // Revert old payments
        foreach ($oldPayments as $oldPayment) {
            // Revert cash account balance
            if ($oldPayment['type'] === 'cash' && !empty($oldPayment['account_id'])) {
                $adjustedAmount = $isSalesReturn ? $oldPayment['amount'] : -$oldPayment['amount'];
                $accountModel->updateBalance($oldPayment['account_id'], $adjustedAmount, $entity_id);
            }

            // Revert or delete checks
            if (!empty($oldPayment['checkId'])) {
                if ($oldPayment['type'] === 'endorse_check') {
                    // Revert endorsed check back to in_hand
                    $stmt = $this->conn->prepare(
                        "UPDATE checks 
                         SET status = 'in_hand', endorsedToInvoiceId = NULL 
                         WHERE id = ? AND entity_id = ?"
                    );
                    $stmt->bind_param('ii', $oldPayment['checkId'], $entity_id);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Delete newly created check — only if it never left its initial
                    // state; a cashed/endorsed check is history and must be kept.
                    $stmt_st = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ?");
                    $stmt_st->bind_param('ii', $oldPayment['checkId'], $entity_id);
                    $stmt_st->execute();
                    $chkStatus = db_stmt_to_assoc_array($stmt_st)[0]['status'] ?? null;
                    $stmt_st->close();
                    if ($chkStatus && in_array($chkStatus, ['in_hand', 'payable'], true)) {
                        $stmt = $this->conn->prepare("DELETE FROM checks WHERE id = ? AND entity_id = ?");
                        $stmt->bind_param('ii', $oldPayment['checkId'], $entity_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        // Delete old payment records
        $stmt = $this->conn->prepare(
            "DELETE FROM payments 
             WHERE invoiceType = 'return' AND invoiceId = ? AND entity_id = ?"
        );
        $stmt->bind_param('ii', $returnInvoiceId, $entity_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        // Fallback delete for legacy payments without entity_id
        if ($affected === 0 && !empty($oldPayments)) {
            $stmt = $this->conn->prepare("DELETE FROM payments WHERE invoiceType = 'return' AND invoiceId = ?");
            $stmt->bind_param('i', $returnInvoiceId);
            $stmt->execute();
            $stmt->close();
        }

        // Create new payments
        foreach ($newPayments as $payment) {
            $this->createReturnPayment(
                $returnInvoiceId, 
                $payment, 
                $personId, 
                $personType, 
                $transactionType, 
                $isSalesReturn, 
                $entity_id,
                $accountModel
            );
        }
    }

    /**
     * Create a single return payment
     */
    private function createReturnPayment($returnInvoiceId, $payment, $personId, $personType, $transactionType, $isSalesReturn, $entity_id, $accountModel) {
        $checkId = null;
        $accountId = !empty($payment['account_id']) ? intval($payment['account_id']) : null;
        $amount = floatval(str_replace(',', '', $payment['amount'] ?? 0));
        $paymentDate = $payment['date'] ?? date('Y-m-d');
        $description = trim($payment['description'] ?? '');
        $typeName = $payment['type'] ?? 'cash';

        // Process based on payment type
        if ($typeName === 'cash') {
            if (!$accountId) {
                throw new Exception('انتخاب حساب برای پرداخت نقدی الزامی است.', 400);
            }
            $adjustedAmount = $isSalesReturn ? -$amount : $amount;
            $accountModel->updateBalance($accountId, $adjustedAmount, $entity_id);
            
        } elseif ($typeName === 'check') {
            $checkDetails = $payment['checkDetails'] ?? [];
            $checkType = $isSalesReturn ? 'payable' : 'received';
            $checkStatus = $isSalesReturn ? 'payable' : 'in_hand';

            $stmt = $this->conn->prepare(
                "INSERT INTO checks 
                 (entity_id, type, invoiceId, invoiceType, checkNumber, dueDate, bankName, issuerName, amount, status) 
                 VALUES (?, ?, ?, 'return', ?, ?, ?, ?, ?, ?)"
            );
            $issuerName = trim($checkDetails['issuerName'] ?? '') ?: null;
            $stmt->bind_param(
                'isissssds', 
                $entity_id, 
                $checkType, 
                $returnInvoiceId, 
                $checkDetails['checkNumber'] ?? '', 
                $checkDetails['dueDate'] ?? null, 
                $checkDetails['bankName'] ?? '', 
                $issuerName,
                $amount, 
                $checkStatus
            );
            $stmt->execute();
            $checkId = $this->conn->insert_id;
            $stmt->close();
            
        } elseif ($typeName === 'endorse_check') {
            $checkId = intval($payment['checkDetails']['endorsedCheckId'] ?? $payment['checkId'] ?? 0);
            if (!$checkId) {
                throw new Exception('شناسه چک برای انتقال نامعتبر است.', 400);
            }
            
            $stmt = $this->conn->prepare(
                "UPDATE checks 
                 SET status = 'endorsed', endorsedToInvoiceId = ? 
                 WHERE id = ? AND entity_id = ?"
            );
            $stmt->bind_param('iii', $returnInvoiceId, $checkId, $entity_id);
            $stmt->execute();
            $stmt->close();
        }

        // Insert payment record
        $stmt = $this->conn->prepare(
            "INSERT INTO payments 
             (entity_id, invoiceId, invoiceType, person_id, person_type, transaction_type, type, amount, date, description, checkId, account_id) 
             VALUES (?, ?, 'return', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiisssdssii', 
            $entity_id, 
            $returnInvoiceId, 
            $personId, 
            $personType, 
            $transactionType, 
            $typeName, 
            $amount, 
            $paymentDate, 
            $description, 
            $checkId, 
            $accountId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete sales return
     */
    public function deleteSalesReturn($id) {
        return $this->deleteReturnInvoiceLogic($id, 'sales_return');
    }

    /**
     * Delete purchase return
     */
    public function deletePurchaseReturn($id) {
        return $this->deleteReturnInvoiceLogic($id, 'purchase_return');
    }

    /**
     * Delete return invoice with complete cleanup
     */
    private function deleteReturnInvoiceLogic($id, $type) {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            return ['error' => 'شناسه مجموعه تجاری نامعتبر است.', 'statusCode' => 401];
        }

        $this->conn->begin_transaction();
        try {
            // Verify ownership
            $stmt = $this->conn->prepare(
                "SELECT id FROM return_invoices 
                 WHERE id = ? AND entity_id = ? AND type = ?"
            );
            $stmt->bind_param('iis', $id, $entity_id, $type);
            $stmt->execute();
            if (empty(db_stmt_to_assoc_array($stmt))) {
                throw new Exception('فاکتور مرجوعی یافت نشد یا متعلق به شما نیست.', 404);
            }

            $isSalesReturn = ($type === 'sales_return');

            // Revert inventory items
            $this->revertReturnInvoiceInventory($id, $isSalesReturn, $entity_id);

            // Revert payments and checks
            $this->revertReturnInvoicePayments($id, $isSalesReturn, $entity_id);

            // Delete the invoice (cascade will delete items)
            $stmt = $this->conn->prepare("DELETE FROM return_invoices WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ii', $id, $entity_id);
            $stmt->execute();
            $stmt->close();

            $this->conn->commit();

            log_activity($this->conn, 'DELETE_RETURN_INVOICE', "فاکتور مرجوعی {$id} حذف شد.");
            
            return ['success' => true];
            
        } catch (Exception $ex) {
            $this->conn->rollback();
            $code = ($ex->getCode() && $ex->getCode() < 600) ? intval($ex->getCode()) : 500;
            return ['error' => $ex->getMessage(), 'statusCode' => $code];
        }
    }

    /**
     * Revert inventory when deleting return invoice
     */
    private function revertReturnInvoiceInventory($returnInvoiceId, $isSalesReturn, $entity_id) {
        $stmt = $this->conn->prepare(
            "SELECT original_invoice_item_id, quantity 
             FROM return_invoice_items 
             WHERE return_invoice_id = ?"
        );
        $stmt->bind_param('i', $returnInvoiceId);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);

        foreach ($items as $item) {
            $origItemId = intval($item['original_invoice_item_id']);
            $qty = intval($item['quantity']);
            
            if ($origItemId <= 0 || $qty <= 0) {
                continue;
            }

            if ($isSalesReturn) {
                $stmt_revert = $this->conn->prepare(
                    "UPDATE inventory_items 
                     SET status = 'sold', returned_from_sales_item_id = NULL 
                     WHERE sales_invoice_item_id = ? AND entity_id = ? AND status = 'returned' 
                     ORDER BY entry_date ASC, id ASC 
                     LIMIT ?"
                );
                $stmt_revert->bind_param('iii', $origItemId, $entity_id, $qty);
            } else {
                $stmt_revert = $this->conn->prepare(
                    "UPDATE inventory_items 
                     SET status = 'in_stock' 
                     WHERE purchase_invoice_item_id = ? AND entity_id = ? AND status = 'returned_to_supplier' 
                     ORDER BY entry_date ASC, id ASC 
                     LIMIT ?"
                );
                $stmt_revert->bind_param('iii', $origItemId, $entity_id, $qty);
            }
            $stmt_revert->execute();
            $stmt_revert->close();
        }
    }

    /**
     * Revert payments when deleting return invoice
     */
    private function revertReturnInvoicePayments($returnInvoiceId, $isSalesReturn, $entity_id) {
        $payments = $this->getPaymentsForReturnInvoice($returnInvoiceId, $entity_id);
        $accountModel = new Account($this->conn);

        foreach ($payments as $payment) {
            // Revert cash balance
            if ($payment['type'] === 'cash' && !empty($payment['account_id'])) {
                $adjustedAmount = $isSalesReturn ? $payment['amount'] : -$payment['amount'];
                $accountModel->updateBalance($payment['account_id'], $adjustedAmount, $entity_id);
            }

            // Revert or delete checks
            if (!empty($payment['checkId'])) {
                if ($payment['type'] === 'endorse_check') {
                    $stmt = $this->conn->prepare(
                        "UPDATE checks 
                         SET status = 'in_hand', endorsedToInvoiceId = NULL 
                         WHERE id = ? AND entity_id = ?"
                    );
                    $stmt->bind_param('ii', $payment['checkId'], $entity_id);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Only delete if the check never left its initial state.
                    $stmt_st = $this->conn->prepare("SELECT status FROM checks WHERE id = ? AND entity_id = ?");
                    $stmt_st->bind_param('ii', $payment['checkId'], $entity_id);
                    $stmt_st->execute();
                    $chkStatus = db_stmt_to_assoc_array($stmt_st)[0]['status'] ?? null;
                    $stmt_st->close();
                    if ($chkStatus && in_array($chkStatus, ['in_hand', 'payable'], true)) {
                        $stmt = $this->conn->prepare("DELETE FROM checks WHERE id = ? AND entity_id = ?");
                        $stmt->bind_param('ii', $payment['checkId'], $entity_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        // Delete payment records
        $stmt = $this->conn->prepare(
            "DELETE FROM payments
             WHERE invoiceType = 'return' AND invoiceId = ? AND entity_id = ?"
        );
        $stmt->bind_param('ii', $returnInvoiceId, $entity_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Fetch complete details for return invoices
     * Includes items, payments, checks, and editability status
     */
    public function fetchReturnInvoiceDetails($invoices, $type) {
        if (empty($invoices)) {
            return [];
        }

        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) {
            throw new Exception('شناسه مجموعه تجاری نامعتبر است.', 401);
        }

        $invoiceIds = array_values(array_unique(array_map('intval', array_column($invoices, 'id'))));
        if (empty($invoiceIds)) {
            return $invoices;
        }

        // Fetch items
        $itemsByInvoice = $this->fetchReturnInvoiceItems($invoiceIds, $type);
        
        // Fetch payments
        list($paymentsByInvoice, $checksById) = $this->fetchReturnInvoicePayments($invoiceIds, $entity_id);

        // Assemble complete invoice data
        $result = [];
        foreach ($invoices as $invoice) {
            $invId = intval($invoice['id']);
            $invoice['items'] = $itemsByInvoice[$invId] ?? [];
            $invoice['payments'] = $paymentsByInvoice[$invId] ?? [];
            
            // Attach check details to payments
            foreach ($invoice['payments'] as &$payment) {
                if (!empty($payment['checkId']) && isset($checksById[$payment['checkId']])) {
                    $payment['checkDetails'] = $checksById[$payment['checkId']];
                }
            }
            unset($payment);

            // Determine if editable
            $invoice['is_editable'] = $this->isReturnInvoiceEditable($invoice['items'], $type);
            
            // Calculate remaining amount
            if (!isset($invoice['remainingAmount'])) {
                $invoice['remainingAmount'] = ($invoice['totalAmount'] ?? 0) - ($invoice['discount'] ?? 0) - ($invoice['paidAmount'] ?? 0);
            }
            
            $result[] = $invoice;
        }

        return $result;
    }

    /**
     * Fetch items for return invoices
     */
    private function fetchReturnInvoiceItems($invoiceIds, $type) {
        $idsList = implode(',', $invoiceIds);
        $itemsByInvoice = [];
        
        $sql = "SELECT rii.*, p.name AS productName 
                FROM return_invoice_items rii 
                LEFT JOIN products p ON rii.productId = p.id 
                WHERE rii.return_invoice_id IN ({$idsList})";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);

        // Fetch selected identifiers for each item
        if (!empty($items)) {
            $itemIds = array_values(array_unique(array_map('intval', array_column($items, 'id'))));
            list($identifiersByItemId, $labelsByItemId) = $this->fetchSelectedIdentifiers($itemIds, $type);
            
            foreach ($items as $item) {
                $returnId = intval($item['return_invoice_id']);
                $itemId = intval($item['id']);
                $item['selected_identifiers'] = $identifiersByItemId[$itemId] ?? [];
                $item['identifier_labels'] = $labelsByItemId[$itemId] ?? [];
                $itemsByInvoice[$returnId][] = $item;
            }
        }

        return $itemsByInvoice;
    }

    /**
     * Fetch selected identifiers for return items.
     * Uses persisted inventory_item_ids first so a later resale does not break the link.
     */
    private function fetchSelectedIdentifiers($itemIds, $type) {
        $itemIdsList = implode(',', array_map('intval', $itemIds));
        $columnName = ($type === 'sales_return') ? 'sales_invoice_item_id' : 'purchase_invoice_item_id';
        $originJoin = ($type === 'sales_return')
            ? " OR ii.returned_from_sales_item_id = rii.original_invoice_item_id"
            : '';

        $sql = "SELECT rii.id AS return_item_id, rii.inventory_item_ids, rii.original_invoice_item_id
                FROM return_invoice_items rii
                WHERE rii.id IN ({$itemIdsList})";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $returnItems = db_stmt_to_assoc_array($stmt);

        $identifiersByItemId = [];
        $missingReturnItemIds = [];
        foreach ($returnItems as $row) {
            $returnItemId = intval($row['return_item_id']);
            $stored = trim((string)($row['inventory_item_ids'] ?? ''));
            if ($stored !== '') {
                $ids = array_values(array_filter(array_map('intval', explode(',', $stored))));
                if (!empty($ids)) {
                    $identifiersByItemId[$returnItemId] = $ids;
                    continue;
                }
            }
            $missingReturnItemIds[] = $returnItemId;
        }

        if (!empty($missingReturnItemIds)) {
            $missingList = implode(',', $missingReturnItemIds);
            $sql = "SELECT DISTINCT rii.id AS return_item_id, ii.id AS inventory_id
                    FROM return_invoice_items rii
                    INNER JOIN inventory_items ii ON (
                        ii.{$columnName} = rii.original_invoice_item_id
                        {$originJoin}
                    )
                    WHERE rii.id IN ({$missingList})";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $identifierRows = db_stmt_to_assoc_array($stmt);
            foreach ($identifierRows as $row) {
                $returnItemId = intval($row['return_item_id']);
                $identifiersByItemId[$returnItemId][] = intval($row['inventory_id']);
            }
        }

        if ($type === 'sales_return' && !empty($missingReturnItemIds)) {
            foreach ($missingReturnItemIds as $returnItemId) {
                if (!empty($identifiersByItemId[$returnItemId])) {
                    continue;
                }
                $stmtMeta = $this->conn->prepare(
                    "SELECT quantity, productId, dimensions, original_invoice_item_id
                     FROM return_invoice_items WHERE id = ?"
                );
                $stmtMeta->bind_param('i', $returnItemId);
                $stmtMeta->execute();
                $meta = db_stmt_to_assoc_array($stmtMeta)[0] ?? null;
                if (!$meta) {
                    continue;
                }
                $qty = intval($meta['quantity']);
                $origItemId = intval($meta['original_invoice_item_id']);
                $productId = intval($meta['productId']);
                $dimensions = $meta['dimensions'] ?? '';
                if ($qty <= 0 || $origItemId <= 0 || $productId <= 0 || $dimensions === '') {
                    continue;
                }
                $stmtCand = $this->conn->prepare(
                    "SELECT ii.id
                     FROM inventory_items ii
                     WHERE ii.product_id = ? AND ii.dimensions = ?
                       AND ii.sales_invoice_item_id IS NOT NULL
                       AND ii.sales_invoice_item_id <> ?
                       AND (ii.returned_from_sales_item_id IS NULL OR ii.returned_from_sales_item_id = ?)
                     ORDER BY ii.id ASC"
                );
                $stmtCand->bind_param('isii', $productId, $dimensions, $origItemId, $origItemId);
                $stmtCand->execute();
                $candidates = array_map('intval', array_column(db_stmt_to_assoc_array($stmtCand), 'id'));
                if (count($candidates) !== $qty) {
                    continue;
                }
                $identifiersByItemId[$returnItemId] = $candidates;
                $ph = implode(',', $candidates);
                $stmtFix = $this->conn->prepare(
                    "UPDATE inventory_items
                     SET returned_from_sales_item_id = ?
                     WHERE id IN ({$ph}) AND returned_from_sales_item_id IS NULL"
                );
                $stmtFix->bind_param('i', $origItemId);
                $stmtFix->execute();
                $stmtFix->close();
            }
        }

        $stmtPersist = $this->conn->prepare(
            "UPDATE return_invoice_items SET inventory_item_ids = ? WHERE id = ? AND (inventory_item_ids IS NULL OR inventory_item_ids = '')"
        );
        foreach ($identifiersByItemId as $returnItemId => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $identifiersByItemId[$returnItemId] = $ids;
            if (empty($ids)) {
                continue;
            }
            $idsStr = implode(',', $ids);
            $stmtPersist->bind_param('si', $idsStr, $returnItemId);
            $stmtPersist->execute();
        }
        $stmtPersist->close();

        $allInventoryIds = [];
        foreach ($identifiersByItemId as $ids) {
            foreach ($ids as $id) {
                $allInventoryIds[$id] = $id;
            }
        }
        $labelsByInventoryId = [];
        if (!empty($allInventoryIds)) {
            $invList = implode(',', $allInventoryIds);
            $stmt = $this->conn->prepare(
                "SELECT id, serial_id FROM inventory_items WHERE id IN ({$invList})"
            );
            $stmt->execute();
            foreach (db_stmt_to_assoc_array($stmt) as $inv) {
                $labelsByInventoryId[intval($inv['id'])] = $inv['serial_id'] ?: ('INV-' . str_pad($inv['id'], 6, '0', STR_PAD_LEFT));
            }
        }

        $labelsByReturnItemId = [];
        foreach ($identifiersByItemId as $returnItemId => $ids) {
            $identifiersByItemId[$returnItemId] = array_values(array_unique($ids));
            $labelsByReturnItemId[$returnItemId] = array_values(array_filter(array_map(function ($id) use ($labelsByInventoryId) {
                return $labelsByInventoryId[$id] ?? null;
            }, $identifiersByItemId[$returnItemId])));
        }

        return [$identifiersByItemId, $labelsByReturnItemId];
    }

    /**
     * Fetch payments and checks for return invoices
     */
    private function fetchReturnInvoicePayments($invoiceIds, $entity_id) {
        $idsList = implode(',', $invoiceIds);
        $paymentsByInvoice = [];
        $checkIds = [];

        // Fetch payments
        $sql = "SELECT * FROM payments WHERE invoiceType = 'return' AND invoiceId IN ({$idsList}) AND entity_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $entity_id);
        $stmt->execute();
        $payments = db_stmt_to_assoc_array($stmt);

        // Fallback for legacy payments
        if (empty($payments)) {
            $sql = "SELECT * FROM payments WHERE invoiceType = 'return' AND invoiceId IN ({$idsList})";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $payments = db_stmt_to_assoc_array($stmt);
            
            // Fix entity_id
            if (!empty($payments)) {
                $stmtFix = $this->conn->prepare("UPDATE payments SET entity_id = ? WHERE id = ?");
                foreach ($payments as $payment) {
                    if (empty($payment['entity_id'])) {
                        $stmtFix->bind_param('ii', $entity_id, $payment['id']);
                        $stmtFix->execute();
                    }
                }
                $stmtFix->close();
            }
        }

        // Group payments and collect check IDs
        foreach ($payments as $payment) {
            $invId = intval($payment['invoiceId']);
            $paymentsByInvoice[$invId][] = $payment;
            if (!empty($payment['checkId'])) {
                $checkIds[] = intval($payment['checkId']);
            }
        }

        // Fetch check details
        $checksById = [];
        if (!empty($checkIds)) {
            $uniqueCheckIds = array_unique($checkIds);
            $checksList = implode(',', $uniqueCheckIds);
            $sql = "SELECT * FROM checks WHERE id IN ({$checksList}) AND entity_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('i', $entity_id);
            $stmt->execute();
            $checks = db_stmt_to_assoc_array($stmt);
            foreach ($checks as $check) {
                $checksById[$check['id']] = $check;
            }
        }

        return [$paymentsByInvoice, $checksById];
    }

    /**
     * Check if return invoice is editable
     * Not editable if any returned items have been re-sold/re-issued
     */
    private function isReturnInvoiceEditable($items, $type) {
        if (empty($items)) {
            return true;
        }

        foreach ($items as $item) {
            $qty = intval($item['quantity'] ?? 0);
            $origId = intval($item['original_invoice_item_id'] ?? 0);
            
            if ($qty <= 0 || $origId <= 0) {
                continue;
            }

            // Determine the correct status based on return type
            // Sales return: items should be in 'returned' status (back to stock)
            // Purchase return: items should be in 'returned_to_supplier' status
            if ($type === 'sales_return') {
                $stmt = $this->conn->prepare(
                    "SELECT COUNT(*) AS c 
                     FROM inventory_items 
                     WHERE sales_invoice_item_id = ? AND status = 'returned'"
                );
            } else {
                $stmt = $this->conn->prepare(
                    "SELECT COUNT(*) AS c 
                     FROM inventory_items 
                     WHERE purchase_invoice_item_id = ? AND status = 'returned_to_supplier'"
                );
            }
            $stmt->bind_param('i', $origId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $returnedCount = intval($rows[0]['c'] ?? 0);
            
            // If count is less than quantity, some items were resold
            if ($returnedCount < $qty) {
                return false;
            }
        }

        return true;
    }
}
