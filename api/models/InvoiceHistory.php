<?php
// /api/models/InvoiceHistory.php

/**
 * تاریخچه تغییرات فاکتورهای فروش/خرید و اقلام مرتبط با فاکتورهای دیگر.
 *
 * برخلاف activity_log (که هرس می‌شود و به رکورد خاصی اشاره ندارد)، این جدول
 * برای هر فاکتور یک خط زمانی کامل همراه با تفاوت قبل/بعد نگه می‌دارد.
 */
class InvoiceHistory {
    private $conn;
    private static $tableReady = false;

    const HEADER_LABELS = [
        'date' => 'تاریخ',
        'personName' => 'طرف حساب',
        'invoice_number' => 'شماره فاکتور',
        'totalAmount' => 'جمع کل',
        'discount' => 'تخفیف',
        'tax_rate' => 'نرخ مالیات',
        'tax_amount' => 'مالیات',
        'paidAmount' => 'پرداخت‌شده',
        'due_date' => 'سررسید',
        'payment_terms' => 'شرایط پرداخت',
        'description' => 'توضیحات',
        'is_consignment' => 'امانی',
        'delivery_status' => 'وضعیت تحویل',
        'warehouse_id' => 'انبار',
    ];

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
    }

    private function ensureTable() {
        if (self::$tableReady) return;
        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS `invoice_history` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `entity_id` int(11) NOT NULL DEFAULT 1,
                `invoice_type` enum('sales','purchase') COLLATE utf8mb4_unicode_ci NOT NULL,
                `invoice_id` int(11) NOT NULL,
                `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
                `user_id` int(11) DEFAULT NULL,
                `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `changes` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `snapshot` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`entity_id`, `invoice_type`, `invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$tableReady = true;
    }

    private static function tables($type) {
        return $type === 'purchase'
            ? ['purchase_invoices', 'purchase_invoice_items', 'suppliers', 'supplierId']
            : ['sales_invoices', 'sales_invoice_items', 'customers', 'customerId'];
    }

    /**
     * وضعیت فعلی فاکتور (سربرگ + اقلام) برای مقایسه. اگر فاکتور نباشد null.
     */
    public function snapshot($type, $id) {
        $id = intval($id);
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);
        if ($id <= 0 || $entityId <= 0) return null;
        [$table, $itemsTable, $personTable, $personCol] = self::tables($type);

        $stmt = $this->conn->prepare(
            "SELECT inv.*, per.name AS personName FROM `{$table}` inv
             LEFT JOIN `{$personTable}` per ON per.id = inv.`{$personCol}`
             WHERE inv.id = ? AND inv.entity_id = ?"
        );
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        if (empty($rows)) return null;
        $inv = $rows[0];

        $header = [];
        foreach (array_keys(self::HEADER_LABELS) as $field) {
            if (array_key_exists($field, $inv)) {
                $header[$field] = $inv[$field];
            }
        }

        $stmtItems = $this->conn->prepare(
            "SELECT ii.productId, ii.quantity, ii.unitPrice, ii.dimensions, ii.is_service, ii.item_description,
                    p.name AS productName
             FROM `{$itemsTable}` ii LEFT JOIN products p ON p.id = ii.productId
             WHERE ii.invoiceId = ? ORDER BY ii.id"
        );
        $stmtItems->bind_param('i', $id);
        $stmtItems->execute();
        $items = [];
        foreach (db_stmt_to_assoc_array($stmtItems) as $it) {
            $items[] = [
                'productId' => $it['productId'] !== null ? intval($it['productId']) : null,
                'productName' => $it['productName'] ?? ($it['item_description'] ?: 'خدمت'),
                'dimensions' => $it['dimensions'] ?? '',
                'quantity' => intval($it['quantity']),
                'unitPrice' => floatval($it['unitPrice']),
                'is_service' => intval($it['is_service'] ?? 0),
                'item_description' => $it['item_description'] ?? '',
            ];
        }
        return ['header' => $header, 'items' => $items];
    }

    private static function itemKey($it) {
        return implode('|', [
            $it['is_service'] ? 's' : 'p',
            $it['productId'] ?? '',
            $it['dimensions'] ?? '',
            $it['is_service'] ? ($it['item_description'] ?? '') : '',
        ]);
    }

    private static function sameValue($a, $b) {
        if (is_numeric($a) && is_numeric($b)) {
            return abs(floatval($a) - floatval($b)) < 0.005;
        }
        return trim((string)($a ?? '')) === trim((string)($b ?? ''));
    }

    public static function diff($before, $after) {
        $changes = ['header' => [], 'items_added' => [], 'items_removed' => [], 'items_changed' => []];
        $bh = $before['header'] ?? [];
        $ah = $after['header'] ?? [];
        foreach (self::HEADER_LABELS as $field => $label) {
            if (!array_key_exists($field, $bh) && !array_key_exists($field, $ah)) continue;
            $old = $bh[$field] ?? null;
            $new = $ah[$field] ?? null;
            if (!self::sameValue($old, $new)) {
                $changes['header'][] = ['field' => $field, 'label' => $label, 'old' => $old, 'new' => $new];
            }
        }

        $index = function ($items) {
            $out = [];
            foreach ($items as $it) {
                $k = self::itemKey($it);
                if (isset($out[$k])) {
                    $out[$k]['quantity'] += $it['quantity'];
                } else {
                    $out[$k] = $it;
                }
            }
            return $out;
        };
        $bi = $index($before['items'] ?? []);
        $ai = $index($after['items'] ?? []);
        foreach ($ai as $k => $it) {
            if (!isset($bi[$k])) {
                $changes['items_added'][] = $it;
            } elseif (!self::sameValue($bi[$k]['quantity'], $it['quantity']) || !self::sameValue($bi[$k]['unitPrice'], $it['unitPrice'])) {
                $changes['items_changed'][] = [
                    'productName' => $it['productName'],
                    'dimensions' => $it['dimensions'],
                    'old_quantity' => $bi[$k]['quantity'],
                    'new_quantity' => $it['quantity'],
                    'old_unitPrice' => $bi[$k]['unitPrice'],
                    'new_unitPrice' => $it['unitPrice'],
                ];
            }
        }
        foreach ($bi as $k => $it) {
            if (!isset($ai[$k])) $changes['items_removed'][] = $it;
        }
        return $changes;
    }

    private static function isEmptyDiff($changes) {
        foreach ($changes as $part) {
            if (!empty($part)) return false;
        }
        return true;
    }

    /**
     * ثبت یک رویداد. هرگز استثنا پرتاب نمی‌کند تا عملیات اصلی فاکتور مختل نشود.
     *
     * @param array|null $before وضعیت قبل (null برای ایجاد)
     * @param array|null $after  وضعیت بعد (null برای حذف)
     */
    public function record($type, $id, $action, $before, $after, $note = null) {
        try {
            $id = intval($id);
            $entityId = intval($_SESSION['current_entity_id'] ?? 0);
            if ($id <= 0 || $entityId <= 0 || !in_array($type, ['sales', 'purchase'], true)) return;

            $changes = null;
            if ($before !== null && $after !== null) {
                $diff = self::diff($before, $after);
                // ویرایش بدون تغییر واقعی ثبت نمی‌شود
                if ($action === 'updated' && self::isEmptyDiff($diff)) return;
                $changes = json_encode($diff, JSON_UNESCAPED_UNICODE);
            }
            $snapshot = json_encode($after ?? $before, JSON_UNESCAPED_UNICODE);

            $userId = intval($_SESSION['user_id'] ?? 0) ?: null;
            $username = $_SESSION['username'] ?? (!empty($_SESSION['integration_auth']) ? 'یکپارچه‌سازی' : null);
            $note = $note !== null ? mb_substr($note, 0, 255) : null;

            $stmt = $this->conn->prepare(
                "INSERT INTO invoice_history (entity_id, invoice_type, invoice_id, action, user_id, username, note, changes, snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isisissss', $entityId, $type, $id, $action, $userId, $username, $note, $changes, $snapshot);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('InvoiceHistory::record failed: ' . $e->getMessage());
        }
    }

    public function getHistory($type, $id) {
        $id = intval($id);
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);
        $stmt = $this->conn->prepare(
            "SELECT id, action, user_id, username, note, changes, snapshot, created_at
             FROM invoice_history
             WHERE entity_id = ? AND invoice_type = ? AND invoice_id = ?
             ORDER BY id DESC"
        );
        $stmt->bind_param('isi', $entityId, $type, $id);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        foreach ($rows as &$r) {
            $r['changes'] = $r['changes'] ? json_decode($r['changes'], true) : null;
            $r['snapshot'] = $r['snapshot'] ? json_decode($r['snapshot'], true) : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * اقلامی از این فاکتور که به فاکتورهای طرف مقابل وصل‌اند (از طریق inventory_items):
     * فاکتور فروش ← فاکتورهای خریدی که کالا از آن‌ها آمده؛
     * فاکتور خرید ← فاکتورهای فروشی که کالا با آن‌ها فروخته شده (و باقیمانده موجودی).
     * به‌علاوه فاکتورهای مرجوعی ثبت‌شده روی این فاکتور.
     */
    public function getLinkedItems($type, $id) {
        $id = intval($id);
        $entityId = intval($_SESSION['current_entity_id'] ?? 0);

        if ($type === 'sales') {
            $sql = "SELECT sii.id AS item_id, MAX(p.name) AS productName, MAX(sii.dimensions) AS dimensions,
                        MAX(sii.quantity) AS item_quantity,
                        pi.id AS linked_invoice_id, MAX(pi.date) AS linked_date, MAX(pi.is_consignment) AS linked_is_consignment,
                        MAX(s.name) AS linked_person, MAX(pii.unitPrice) AS linked_unit_price,
                        COUNT(ii.id) AS qty,
                        SUM(ii.status = 'returned') AS returned_qty,
                        GROUP_CONCAT(DISTINCT ii.serial_id ORDER BY ii.serial_id SEPARATOR '، ') AS serials,
                        '' AS stock_status
                    FROM sales_invoice_items sii
                    JOIN inventory_items ii
                        ON (ii.sales_invoice_item_id = sii.id OR ii.returned_from_sales_item_id = sii.id) AND ii.entity_id = ?
                    LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                    LEFT JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                    LEFT JOIN suppliers s ON s.id = pi.supplierId
                    LEFT JOIN products p ON p.id = sii.productId
                    WHERE sii.invoiceId = ?
                    GROUP BY sii.id, pi.id
                    ORDER BY sii.id, pi.id";
        } else {
            $sql = "SELECT pii.id AS item_id, MAX(p.name) AS productName, MAX(pii.dimensions) AS dimensions,
                        MAX(pii.quantity) AS item_quantity,
                        si.id AS linked_invoice_id, MAX(si.date) AS linked_date, MAX(si.is_consignment) AS linked_is_consignment,
                        MAX(c.name) AS linked_person, MAX(sii.unitPrice) AS linked_unit_price,
                        COUNT(ii.id) AS qty,
                        SUM(ii.status = 'returned') AS returned_qty,
                        GROUP_CONCAT(DISTINCT ii.serial_id ORDER BY ii.serial_id SEPARATOR '، ') AS serials,
                        IF(si.id IS NULL, ii.status, '') AS stock_status
                    FROM purchase_invoice_items pii
                    JOIN inventory_items ii ON ii.purchase_invoice_item_id = pii.id AND ii.entity_id = ?
                    LEFT JOIN sales_invoice_items sii ON sii.id = COALESCE(ii.sales_invoice_item_id, ii.returned_from_sales_item_id)
                    LEFT JOIN sales_invoices si ON si.id = sii.invoiceId
                    LEFT JOIN customers c ON c.id = si.customerId
                    LEFT JOIN products p ON p.id = pii.productId
                    WHERE pii.invoiceId = ?
                    GROUP BY pii.id, si.id, stock_status
                    ORDER BY pii.id, si.id";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $entityId, $id);
        $stmt->execute();
        $links = db_stmt_to_assoc_array($stmt);
        foreach ($links as &$l) {
            $l['qty'] = intval($l['qty']);
            $l['returned_qty'] = intval($l['returned_qty']);
            $l['linked_invoice_id'] = $l['linked_invoice_id'] !== null ? intval($l['linked_invoice_id']) : null;
        }
        unset($l);

        $stmtRet = $this->conn->prepare(
            "SELECT ri.id AS return_id, ri.type, ri.date, MAX(p.name) AS productName, MAX(rii.dimensions) AS dimensions,
                    SUM(rii.quantity) AS quantity, SUM(rii.quantity * rii.unitPrice) AS amount
             FROM return_invoices ri
             JOIN return_invoice_items rii ON rii.return_invoice_id = ri.id
             LEFT JOIN products p ON p.id = rii.productId
             WHERE ri.entity_id = ? AND ri.original_invoice_type = ? AND ri.original_invoice_id = ?
             GROUP BY ri.id, rii.productId, rii.dimensions
             ORDER BY ri.id"
        );
        $stmtRet->bind_param('isi', $entityId, $type, $id);
        $stmtRet->execute();
        $returns = db_stmt_to_assoc_array($stmtRet);

        return ['links' => $links, 'returns' => $returns];
    }
}
