<?php
// api/core/audit/rules/inventory/InventoryRules.php

require_once __DIR__ . '/../../AbstractRule.php';

class InventoryAuditRules {
    public static function create($db, int $entityId): array {
        return [
            new InvUnlinkedRule($db, $entityId),
            new InvSoldWithoutSalesRule($db, $entityId),
            new InvStatusMismatchRule($db, $entityId),
            new InvBrokenPurchaseLinkRule($db, $entityId),
            new InvDuplicateSoldRule($db, $entityId),
            new InvVirtualSoldRule($db, $entityId),
            new InvSerialIntegrityRule($db, $entityId),
            new InvProductIntegrityRule($db, $entityId),
            new InvNoPurchaseHistoryRule($db, $entityId),
        ];
    }
}

abstract class InventoryBaseRule extends AbstractAuditRule {
    protected function noPurchaseSql(string $alias = 'ii'): string {
        return "({$alias}.purchase_invoice_item_id IS NULL OR {$alias}.purchase_invoice_item_id = 0)";
    }
    protected function noSalesSql(string $alias = 'ii'): string {
        return "({$alias}.sales_invoice_item_id IS NULL OR {$alias}.sales_invoice_item_id = 0)";
    }
}

class InvUnlinkedRule extends InventoryBaseRule {
    public function getId(): string { return 'unlinked_inventory'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'انبار بدون فاکتور خرید'; }

    public function run(): array {
        $issues = [];
        $noPurchase = $this->noPurchaseSql();
        $q = "SELECT ii.id, p.name, ii.dimensions,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON ii.product_id = p.id
              WHERE p.entity_id = ? AND {$noPurchase} AND ii.status = 'in_stock'";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "آیتم انبار بدون فاکتور خرید: {$row['name']} ({$row['dimensions']})",
                'details' => "شناسه: {$row['serial_label']} | id: {$row['id']}",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'guided',
                'remediation_action' => 'link_purchase_invoice',
                'nav' => ['center_panel' => 'inventory_link', 'reference_id' => $row['id']],
            ]);
        }
        return $issues;
    }
}

class InvSoldWithoutSalesRule extends InventoryBaseRule {
    public function getId(): string { return 'sold_without_sales_link'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'فروش یتیم در انبار'; }

    public function run(): array {
        $issues = [];
        $noSales = $this->noSalesSql();
        $q = "SELECT ii.id, p.name, ii.dimensions, ii.status,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON ii.product_id = p.id
              WHERE p.entity_id = ? AND ii.status IN ('sold','consignment_out') AND {$noSales}";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'critical',
                'message' => "فروش جعلی/یتیم در انبار: {$row['name']}",
                'details' => "وضعیت «{$row['status']}» بدون لینک فروش — {$row['serial_label']}",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'delete_orphan_sold',
            ]);
        }
        return $issues;
    }
}

class InvStatusMismatchRule extends InventoryBaseRule {
    public function getId(): string { return 'inventory_status_mismatch'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'وضعیت انبار ناسازگار'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT ii.id, p.name, ii.dimensions,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON ii.product_id = p.id
              WHERE p.entity_id = ? AND ii.status = 'in_stock'
                AND ii.sales_invoice_item_id IS NOT NULL AND ii.sales_invoice_item_id != 0";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'critical',
                'message' => "آیتم «موجود» دارای فاکتور فروش: {$row['name']}",
                'details' => "شناسه: {$row['serial_label']}",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'fix_item_status_sold',
            ]);
        }
        return $issues;
    }
}

class InvBrokenPurchaseLinkRule extends InventoryBaseRule {
    public function getId(): string { return 'broken_purchase_link'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'اتصال خرید شکسته'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT ii.id, p.name, ii.dimensions, ii.purchase_invoice_item_id,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON ii.product_id = p.id
              LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
              WHERE p.entity_id = ?
                AND ii.purchase_invoice_item_id IS NOT NULL AND ii.purchase_invoice_item_id != 0
                AND pii.id IS NULL";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "اتصال خرید شکسته برای: {$row['name']}",
                'details' => "{$row['serial_label']} → purchase item #{$row['purchase_invoice_item_id']} وجود ندارد",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'clear_broken_purchase_link',
            ]);
        }
        return $issues;
    }
}

class InvDuplicateSoldRule extends InventoryBaseRule {
    public function getId(): string { return 'duplicate_sold_inventory'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'فروش تکراری در انبار'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT ii.product_id, p.name, ii.dimensions, COUNT(ii.id) AS sold_inv_count,
                     COALESCE((
                       SELECT SUM(sii.quantity) FROM sales_invoice_items sii
                       JOIN sales_invoices si ON si.id = sii.invoiceId
                       WHERE si.entity_id = ? AND sii.productId = ii.product_id
                         AND sii.dimensions = ii.dimensions AND COALESCE(sii.is_service,0)=0
                     ),0) AS sales_qty
              FROM inventory_items ii
              JOIN products p ON p.id = ii.product_id
              WHERE p.entity_id = ? AND ii.status = 'sold'
              GROUP BY ii.product_id, ii.dimensions
              HAVING sold_inv_count > sales_qty";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('ii', $this->entityId, $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $extra = (int)$row['sold_inv_count'] - (int)$row['sales_qty'];
            $issues[] = $this->issue([
                'severity' => 'critical',
                'message' => "فروش تکراری در انبار: {$row['name']} ({$row['dimensions']})",
                'details' => "{$row['sold_inv_count']} sold در برابر {$row['sales_qty']} فروش (اضافه: {$extra})",
                'reference_table' => 'products',
                'reference_id' => $row['product_id'],
                'remediation_class' => 'safe_auto',
                'remediation_action' => 'cleanup_duplicate_sold',
                'meta' => [
                    'dimensions' => $row['dimensions'],
                    'sold_inv_count' => (int)$row['sold_inv_count'],
                    'sales_qty' => (int)$row['sales_qty'],
                ],
            ]);
        }
        return $issues;
    }
}

class InvVirtualSoldRule extends InventoryBaseRule {
    public function getId(): string { return 'virtual_sold_inventory'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'فروش بدون سابقه خرید'; }

    public function run(): array {
        $issues = [];
        $noPurchase = $this->noPurchaseSql();
        $q = "SELECT ii.id, p.name, ii.dimensions, ii.sales_invoice_item_id,
                     COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
              FROM inventory_items ii
              JOIN products p ON ii.product_id = p.id
              WHERE p.entity_id = ? AND ii.status = 'sold' AND {$noPurchase}
                AND ii.sales_invoice_item_id IS NOT NULL AND ii.sales_invoice_item_id != 0";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'warning',
                'message' => "فروش بدون سابقه خرید: {$row['name']}",
                'details' => "{$row['serial_label']} → sales item #{$row['sales_invoice_item_id']}",
                'reference_table' => 'inventory_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'guided',
                'remediation_action' => 'link_purchase_invoice',
                'nav' => ['center_panel' => 'inventory_link', 'reference_id' => $row['id']],
            ]);
        }
        return $issues;
    }
}

class InvSerialIntegrityRule extends InventoryBaseRule {
    public function getId(): string { return 'serial_integrity'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'یکپارچگی سریال'; }

    public function run(): array {
        $issues = [];
        // purchase lines with qty > linked inventory count
        $q = "SELECT pii.id, pii.invoiceId, pii.quantity, p.name, pii.dimensions,
                     (SELECT COUNT(*) FROM inventory_items ii WHERE ii.purchase_invoice_item_id = pii.id) as linked_count
              FROM purchase_invoice_items pii
              JOIN purchase_invoices pi ON pi.id = pii.invoiceId
              JOIN products p ON p.id = pii.productId
              WHERE pi.entity_id = ? AND COALESCE(pii.is_service,0)=0
              HAVING linked_count < quantity";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $missing = (int)$row['quantity'] - (int)$row['linked_count'];
            $issues[] = $this->issue([
                'rule_id' => 'missing_serials',
                'severity' => 'warning',
                'title' => 'سریال ناقص خرید',
                'message' => "سریال ناقص خرید: {$row['name']} ({$row['dimensions']})",
                'details' => "فاکتور خرید #{$row['invoiceId']} — کمبود {$missing} از {$row['quantity']}",
                'reference_table' => 'purchase_invoice_items',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['tab' => 'purchase-invoices-tab', 'table' => 'purchase_invoices', 'reference_id' => $row['invoiceId']],
                'meta' => ['missing' => $missing],
            ]);
        }

        $q2 = "SELECT si.id, COUNT(sii.id) as item_lines,
                      SUM(CASE WHEN EXISTS(
                        SELECT 1 FROM inventory_items ii WHERE ii.sales_invoice_item_id = sii.id
                      ) THEN 0 ELSE 1 END) as unlinked_lines
               FROM sales_invoices si
               JOIN sales_invoice_items sii ON sii.invoiceId = si.id
               WHERE si.entity_id = ? AND COALESCE(sii.is_service,0)=0
                 AND (si.is_consignment = 0 OR si.is_consignment IS NULL)
               GROUP BY si.id
               HAVING unlinked_lines > 0";
        // Simpler approach without correlated HAVING complexity:
        $q2 = "SELECT DISTINCT si.id
               FROM sales_invoices si
               JOIN sales_invoice_items sii ON sii.invoiceId = si.id
               LEFT JOIN inventory_items ii ON (
                    ii.sales_invoice_item_id = sii.id
                    OR ii.returned_from_sales_item_id = sii.id
               )
               LEFT JOIN return_invoice_items rii ON rii.original_invoice_item_id = sii.id
               WHERE si.entity_id = ?
                 AND COALESCE(sii.is_service,0)=0
                 AND (si.is_consignment = 0 OR si.is_consignment IS NULL)
                 AND ii.id IS NULL
                 AND rii.id IS NULL";
        $stmt2 = $this->db->prepare($q2);
        $stmt2->bind_param('i', $this->entityId);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'sales_without_serials',
                'severity' => 'warning',
                'title' => 'فروش بدون شناسه انبار',
                'message' => "فاکتور فروش #{$row['id']} بدون اتصال کامل به انبار",
                'details' => 'حداقل یک ردیف کالا به inventory وصل نیست.',
                'reference_table' => 'sales_invoices',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
                'nav' => ['tab' => 'sales-invoices-tab', 'table' => 'sales_invoices', 'reference_id' => $row['id']],
            ]);
        }
        return $issues;
    }
}

class InvProductIntegrityRule extends InventoryBaseRule {
    public function getId(): string { return 'product_integrity'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'یکپارچگی محصول'; }

    public function run(): array {
        $issues = [];
        $stmt = $this->db->prepare(
            "SELECT id, name FROM products WHERE entity_id = ? AND (price IS NULL OR price = 0) AND collection_id IS NULL"
        );
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'info',
                'message' => "محصول بدون قیمت پایه: {$row['name']}",
                'details' => "شناسه محصول: {$row['id']}",
                'reference_table' => 'products',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'update_product_price',
            ]);
        }

        $stmt2 = $this->db->prepare(
            "SELECT p.id, p.name, p.collection_id FROM products p
             LEFT JOIN product_collections pc ON p.collection_id = pc.id
             WHERE p.entity_id = ? AND p.collection_id IS NOT NULL AND pc.id IS NULL"
        );
        $stmt2->bind_param('i', $this->entityId);
        $stmt2->execute();
        foreach (db_stmt_to_assoc_array($stmt2) as $row) {
            $issues[] = $this->issue([
                'rule_id' => 'orphan_product_collection',
                'severity' => 'warning',
                'title' => 'کلکسیون یتیم محصول',
                'message' => "محصول متصل به کلکسیون حذف‌شده: {$row['name']}",
                'details' => "collection_id={$row['collection_id']}",
                'reference_table' => 'products',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'fix_product_collection',
            ]);
        }
        return $issues;
    }
}

class InvNoPurchaseHistoryRule extends InventoryBaseRule {
    public function getId(): string { return 'inventory_without_purchase_history'; }
    public function getCategory(): string { return 'inventory'; }
    public function getTitle(): string { return 'موجودی بدون سابقه خرید'; }

    public function run(): array {
        $issues = [];
        $q = "SELECT DISTINCT p.id, p.name
              FROM products p
              JOIN inventory_items ii ON ii.product_id = p.id AND ii.status = 'in_stock'
              WHERE p.entity_id = ?
                AND NOT EXISTS (
                  SELECT 1 FROM purchase_invoice_items pii
                  JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                  WHERE pi.entity_id = p.entity_id AND pii.productId = p.id
                )";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param('i', $this->entityId);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $issues[] = $this->issue([
                'severity' => 'info',
                'message' => "محصول در انبار بدون هیچ سابقه خرید: {$row['name']}",
                'details' => "شناسه محصول: {$row['id']}",
                'reference_table' => 'products',
                'reference_id' => $row['id'],
                'remediation_class' => 'navigate_only',
                'remediation_action' => 'manual_review',
            ]);
        }
        return $issues;
    }
}
