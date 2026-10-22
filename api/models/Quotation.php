<?php
// /api/models/Quotation.php
require_once __DIR__ . '/BaseModel.php';

class Quotation extends BaseModel {
    protected $tableName = 'quotations';
    protected $alias = 'q';
    protected $select = "SELECT q.*, c.name as customerName, (q.totalAmount - q.discount) as netAmount";
    protected $from = "FROM `quotations` as q";
    protected $join = "LEFT JOIN customers c ON q.customerId = c.id";
    protected $where = "WHERE 1";
    protected $allowedFilters = ['q.id', 'c.name', 'q.totalAmount', 'q.description'];
    protected $allowedSorts = ['id', 'date', 'customerName', 'totalAmount', 'discount', 'netAmount', 'status', 'valid_until'];
    protected $hasEntityId = true;

    public function __construct($db) {
        parent::__construct($db);
        $this->from = "FROM `quotations` as q";
    }

    protected function getOrderByClause($sortBy) {
        if ($sortBy === 'customerName') return 'c.name';
        if ($sortBy === 'netAmount') return '(`totalAmount` - `discount`)';
        return "`q`.`$sortBy`";
    }

    public function getPaginated($input) {
        // Auto-update expired quotations before fetching
        $this->markExpiredQuotations();
        
        // Apply status filter if provided
        $statusFilter = $input['statusFilter'] ?? '';
        if (!empty($statusFilter) && in_array($statusFilter, ['pending', 'converted', 'cancelled', 'expired'])) {
            $this->where .= " AND q.status = '" . $this->conn->real_escape_string($statusFilter) . "'";
        }

        $paginatedResult = parent::getPaginated($input);
        
        // Fetch items for each quotation
        if (!empty($paginatedResult['data'])) {
            $paginatedResult['data'] = $this->fetchQuotationItems($paginatedResult['data']);
        }
        
        return $paginatedResult;
    }

    private function fetchQuotationItems($quotations) {
        if (empty($quotations)) return [];
        
        $ids = array_column($quotations, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $this->conn->prepare("
            SELECT qi.*, p.name as productName, pc.name as collectionName
            FROM quotation_items qi
            LEFT JOIN products p ON qi.productId = p.id
            LEFT JOIN product_collections pc ON p.collection_id = pc.id
            WHERE qi.quotationId IN ($placeholders)
        ");
        
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);
        
        $itemsByQuotation = [];
        foreach ($items as $item) {
            $itemsByQuotation[$item['quotationId']][] = $item;
        }
        
        foreach ($quotations as &$q) {
            $q['items'] = $itemsByQuotation[$q['id']] ?? [];
        }
        
        return $quotations;
    }

    public function getById($id) {
        $id = intval($id);
        $entity_id = $_SESSION['current_entity_id'];
        
        $stmt = $this->conn->prepare("
            SELECT q.*, c.name as customerName
            FROM quotations q
            LEFT JOIN customers c ON q.customerId = c.id
            WHERE q.id = ? AND q.entity_id = ?
        ");
        $stmt->bind_param('ii', $id, $entity_id);
        $stmt->execute();
        $quotations = db_stmt_to_assoc_array($stmt);
        
        if (empty($quotations)) {
            return ['error' => 'پیش فاکتور یافت نشد.', 'statusCode' => 404];
        }
        
        $quotation = $quotations[0];
        
        // Get items
        $stmt = $this->conn->prepare("
            SELECT qi.*, p.name as productName, pc.name as collectionName
            FROM quotation_items qi
            LEFT JOIN products p ON qi.productId = p.id
            LEFT JOIN product_collections pc ON p.collection_id = pc.id
            WHERE qi.quotationId = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $quotation['items'] = db_stmt_to_assoc_array($stmt);
        
        return $quotation;
    }

    public function save($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = isset($data['id']) ? intval($data['id']) : null;
        
        // Validation
        if (empty($data['customerId'])) {
            return ['error' => 'انتخاب مشتری الزامی است.', 'statusCode' => 400];
        }
        
        // Validate customer belongs to current entity
        $stmt = $this->conn->prepare("SELECT id FROM customers WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $data['customerId'], $entity_id);
        $stmt->execute();
        $customerResult = db_stmt_to_assoc_array($stmt);
        if (empty($customerResult)) {
            return ['error' => 'مشتری انتخاب شده نامعتبر است.', 'statusCode' => 400];
        }
        
        if (empty($data['date'])) {
            return ['error' => 'تاریخ الزامی است.', 'statusCode' => 400];
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return ['error' => 'حداقل یک آیتم باید اضافه شود.', 'statusCode' => 400];
        }
        
        // Validate all products belong to current entity and calculate total
        $totalAmount = 0;
        $productIds = [];
        foreach ($data['items'] as $item) {
            if (empty($item['productId']) || empty($item['quantity']) || !isset($item['unitPrice'])) {
                return ['error' => 'اطلاعات آیتم‌ها ناقص است.', 'statusCode' => 400];
            }
            $productIds[] = intval($item['productId']);
            // Use floatval consistently and strip commas for formatted prices
            $quantity = floatval($item['quantity']);
            $unitPrice = floatval(str_replace(',', '', $item['unitPrice']));
            $totalAmount += $quantity * $unitPrice;
        }
        
        // Validate all products belong to entity
        if (!empty($productIds)) {
            // The same product may legitimately appear twice; IN() matches each
            // distinct id once, so compare against the deduped list.
            $uniqueProductIds = array_values(array_unique($productIds));
            $placeholders = implode(',', array_fill(0, count($uniqueProductIds), '?'));
            $stmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM products WHERE id IN ($placeholders) AND entity_id = ?");
            $types = str_repeat('i', count($uniqueProductIds)) . 'i';
            $params = array_merge($uniqueProductIds, [$entity_id]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $productResult = db_stmt_to_assoc_array($stmt);
            if ($productResult[0]['cnt'] != count($productIds)) {
                return ['error' => 'یک یا چند محصول انتخاب شده نامعتبر است.', 'statusCode' => 400];
            }
        }
        
        $discount = isset($data['discount']) ? floatval($data['discount']) : 0;
        
        // Validate discount
        if ($discount < 0) {
            return ['error' => 'تخفیف نمی‌تواند منفی باشد.', 'statusCode' => 400];
        }
        if ($discount > $totalAmount) {
            return ['error' => 'تخفیف نمی‌تواند بیشتر از جمع کل باشد.', 'statusCode' => 400];
        }
        
        $this->conn->begin_transaction();
        
        try {
            if ($id) {
                // Update existing quotation
                // First check if it exists and is not converted
                $stmt = $this->conn->prepare("SELECT status FROM quotations WHERE id = ? AND entity_id = ?");
                $stmt->bind_param('ii', $id, $entity_id);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);
                
                if (empty($result)) {
                    throw new Exception('پیش فاکتور یافت نشد.');
                }
                if ($result[0]['status'] === 'converted') {
                    throw new Exception('پیش فاکتور تبدیل شده قابل ویرایش نیست.');
                }
                
                $stmt = $this->conn->prepare("
                    UPDATE quotations SET 
                        customerId = ?, date = ?, valid_until = ?, totalAmount = ?, 
                        discount = ?, description = ?, status = ?
                    WHERE id = ? AND entity_id = ?
                ");
                $validUntil = !empty($data['valid_until']) ? $data['valid_until'] : null;
                $description = $data['description'] ?? '';
                $status = $data['status'] ?? 'pending';
                $stmt->bind_param('issddssis', 
                    $data['customerId'], $data['date'], $validUntil, $totalAmount, 
                    $discount, $description, $status, $id, $entity_id
                );
                $stmt->execute();
                
                // Delete old items
                $stmt = $this->conn->prepare("DELETE FROM quotation_items WHERE quotationId = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                
            } else {
                // Create new quotation
                $stmt = $this->conn->prepare("
                    INSERT INTO quotations (customerId, date, valid_until, totalAmount, discount, description, status, entity_id)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $validUntil = !empty($data['valid_until']) ? $data['valid_until'] : null;
                $description = $data['description'] ?? '';
                $stmt->bind_param('issddsi', 
                    $data['customerId'], $data['date'], $validUntil, $totalAmount, 
                    $discount, $description, $entity_id
                );
                $stmt->execute();
                $id = $this->conn->insert_id;
            }
            
            // Insert items
            $stmt = $this->conn->prepare("
                INSERT INTO quotation_items (quotationId, productId, quantity, unitPrice, dimensions)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($data['items'] as $item) {
                $productId = intval($item['productId']);
                $quantity = floatval($item['quantity']);
                $unitPrice = floatval(str_replace(',', '', $item['unitPrice']));
                $dimensions = $item['dimensions'] ?? '';
                
                $stmt->bind_param('iidds', $id, $productId, $quantity, $unitPrice, $dimensions);
                $stmt->execute();
            }
            
            $this->conn->commit();
            return ['success' => true, 'id' => $id];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        $entity_id = $_SESSION['current_entity_id'];
        
        // Check if quotation exists
        $stmt = $this->conn->prepare("SELECT status, converted_invoice_id FROM quotations WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entity_id);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        if (empty($result)) {
            return ['error' => 'پیش فاکتور یافت نشد.', 'statusCode' => 404];
        }
        
        $wasConverted = $result[0]['status'] === 'converted';
        
        // Wrap in transaction for data integrity
        $this->conn->begin_transaction();
        
        try {
            // First delete items
            $stmt = $this->conn->prepare("DELETE FROM quotation_items WHERE quotationId = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            // Then delete quotation
            $stmt = $this->conn->prepare("DELETE FROM quotations WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ii', $id, $entity_id);
            $stmt->execute();
            
            $this->conn->commit();
            return ['success' => true, 'wasConverted' => $wasConverted];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['error' => 'خطا در حذف پیش فاکتور: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function updateStatus($id, $status) {
        $id = intval($id);
        $entity_id = $_SESSION['current_entity_id'];
        
        $validStatuses = ['pending', 'cancelled', 'expired'];
        if (!in_array($status, $validStatuses)) {
            return ['error' => 'وضعیت نامعتبر است.', 'statusCode' => 400];
        }
        
        // Check current status
        $stmt = $this->conn->prepare("SELECT status FROM quotations WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entity_id);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        if (empty($result)) {
            return ['error' => 'پیش فاکتور یافت نشد.', 'statusCode' => 404];
        }
        if ($result[0]['status'] === 'converted') {
            return ['error' => 'وضعیت پیش فاکتور تبدیل شده قابل تغییر نیست.', 'statusCode' => 400];
        }
        
        $stmt = $this->conn->prepare("UPDATE quotations SET status = ? WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('sii', $status, $id, $entity_id);
        $stmt->execute();
        
        return ['success' => true];
    }

    /**
     * Convert quotation to sales invoice
     * This is a safe operation - does NOT modify inventory or financial records
     * Only creates the invoice record and marks quotation as converted
     */
    /**
     * Convert a quotation into a real sales invoice.
     *
     * Goes through Invoice::saveSalesInvoice so stock is allocated (FIFO /
     * negative-stock policy), totals and tax are recomputed server-side and
     * numbering/credit rules apply — previously raw rows were inserted without
     * touching inventory, leaving sold carpets «in stock» with zero COGS.
     * A named lock makes concurrent double-clicks convert only once.
     */
    public function convertToInvoice($id, array $options = []) {
        $id = intval($id);
        $entity_id = (int)$_SESSION['current_entity_id'];
        $lockName = "hesabdari_quote_conv_{$entity_id}_{$id}";
        $lockRes = $this->conn->query("SELECT GET_LOCK('" . $this->conn->real_escape_string($lockName) . "', 5) AS l");
        $locked = $lockRes && ($r = $lockRes->fetch_assoc()) && (int)$r['l'] === 1;
        if (!$locked) {
            return ['error' => 'این پیش فاکتور در حال تبدیل است. چند لحظه بعد دوباره تلاش کنید.', 'statusCode' => 409];
        }
        try {
            $stmt = $this->conn->prepare("SELECT * FROM quotations WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ii', $id, $entity_id);
            $stmt->execute();
            $quotation = db_stmt_to_assoc_array($stmt)[0] ?? null;
            if (!$quotation) {
                return ['error' => 'پیش فاکتور یافت نشد.', 'statusCode' => 404];
            }
            if ($quotation['status'] === 'converted') {
                return ['error' => 'این پیش فاکتور قبلاً به فاکتور تبدیل شده است.', 'statusCode' => 400];
            }
            if ($quotation['status'] === 'cancelled') {
                return ['error' => 'پیش فاکتور لغو شده قابل تبدیل نیست.', 'statusCode' => 400];
            }
            if ($quotation['status'] === 'expired' && empty($options['allow_expired'])) {
                return ['error' => 'اعتبار این پیش فاکتور به پایان رسیده است. ابتدا وضعیت آن را به «در انتظار» برگردانید (و قیمت‌ها را بررسی کنید)، سپس تبدیل کنید.', 'statusCode' => 400];
            }

            $stmt = $this->conn->prepare("SELECT * FROM quotation_items WHERE quotationId = ? ORDER BY id ASC");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $qItems = db_stmt_to_assoc_array($stmt);
            if (empty($qItems)) {
                return ['error' => 'پیش فاکتور هیچ قلمی ندارد.', 'statusCode' => 400];
            }

            $items = [];
            foreach ($qItems as $qi) {
                $items[] = [
                    'productId' => (int)$qi['productId'],
                    'dimensions' => $qi['dimensions'],
                    'quantity' => (int)$qi['quantity'],
                    'unitPrice' => (float)$qi['unitPrice'],
                ];
            }

            $invoiceDate = !empty($options['date']) ? $options['date'] : (!empty($quotation['date']) ? $quotation['date'] : date('Y-m-d'));
            $description = 'تبدیل از پیش فاکتور شماره ' . $id . ($quotation['description'] ? ' - ' . $quotation['description'] : '');

            require_once __DIR__ . '/Invoice.php';
            $invoiceModel = new Invoice($this->conn, 'sales');
            $res = $invoiceModel->saveSalesInvoice([
                'customerId' => (int)$quotation['customerId'],
                'date' => $invoiceDate,
                'discount' => (float)$quotation['discount'],
                'description' => $description,
                'items' => $items,
                'payments' => [],
            ]);
            if (empty($res['success'])) {
                return ['error' => $res['error'] ?? 'تبدیل به فاکتور ناموفق بود.', 'statusCode' => $res['statusCode'] ?? 500];
            }
            $invoiceId = (int)$res['id'];

            $upd = $this->conn->prepare("UPDATE quotations SET status = 'converted', converted_invoice_id = ? WHERE id = ? AND entity_id = ?");
            $upd->bind_param('iii', $invoiceId, $id, $entity_id);
            $upd->execute();
            $upd->close();

            $out = [
                'success' => true,
                'invoiceId' => $invoiceId,
                'message' => 'پیش فاکتور به فاکتور فروش تبدیل شد و کالاها از موجودی کسر شدند.',
            ];
            if (!empty($res['warnings'])) {
                $out['warnings'] = $res['warnings'];
            }
            return $out;
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('" . $this->conn->real_escape_string($lockName) . "')");
        }
    }

    /**
     * Check and update expired quotations
     * Call this periodically (e.g., via cron)
     */
    public static function updateExpiredQuotations($conn) {
        try {
            $conn->query("
                UPDATE quotations 
                SET status = 'expired' 
                WHERE status = 'pending' 
                  AND valid_until IS NOT NULL 
                  AND valid_until < CURDATE()
            ");
            return true;
        } catch (Exception $e) {
            error_log("Error updating expired quotations: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark expired quotations for current entity (instance method)
     */
    private function markExpiredQuotations() {
        $entity_id = $_SESSION['current_entity_id'] ?? null;
        if (!$entity_id) return;
        
        try {
            $stmt = $this->conn->prepare("
                UPDATE quotations 
                SET status = 'expired' 
                WHERE status = 'pending' 
                  AND entity_id = ?
                  AND valid_until IS NOT NULL 
                  AND valid_until < CURDATE()
            ");
            $stmt->bind_param('i', $entity_id);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Error marking expired quotations: " . $e->getMessage());
        }
    }
}
