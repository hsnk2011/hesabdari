<?php
/**
 * AnalyticsCalculations Trait
 * 
 * Shared calculation logic for both Analytics Dashboard and Reporting System.
 * This trait eliminates duplication and ensures consistent calculations across the system.
 * 
 * Used by:
 * - api/models/ReportModel.php (Formal Reports)
 * (The sales analytics page has its own engine in analytics/lib/SalesAnalytics.php.)
 */

trait AnalyticsCalculations {
    
    /**
     * Calculate total sales for a given period
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @param bool $includeConsignment Include consignment invoices (default: false)
     * @return array ['total_sales' => float, 'invoice_count' => int, 'active_customers' => int]
     */
    protected function calculateTotalSales($conn, $startDate, $endDate, $entityId, $includeConsignment = false) {
        $consignmentClause = $includeConsignment ? "" : " AND is_consignment = 0";
        
        $sql = "SELECT 
                    COALESCE(SUM(totalAmount - COALESCE(discount,0)),0) AS total_sales,
                    COUNT(*) AS invoice_count,
                    COUNT(DISTINCT customerId) AS active_customers
                FROM sales_invoices
                WHERE entity_id = ? AND date BETWEEN ? AND ?" . $consignmentClause;
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        return $result[0] ?? ['total_sales' => 0, 'invoice_count' => 0, 'active_customers' => 0];
    }
    
    /**
     * Calculate gross profit estimation based on last purchase prices
     * This is an approximation suitable for quick analytics, not formal accounting.
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @return float Estimated gross profit
     */
    protected function calculateEstimatedGrossProfit($conn, $startDate, $endDate, $entityId) {
        $sql = "SELECT
                    COALESCE(SUM(sii.quantity * sii.unitPrice), 0) AS sales_amount,
                    COALESCE(SUM(sii.quantity * COALESCE(lp.unitPrice, 0)), 0) AS cost_amount
                FROM sales_invoice_items sii
                JOIN sales_invoices si ON si.id = sii.invoiceId
                LEFT JOIN (
                    SELECT pii.productId, MAX(pii.unitPrice) AS unitPrice
                    FROM purchase_invoice_items pii
                    JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                    WHERE pi.date = (
                        SELECT MAX(pi2.date)
                        FROM purchase_invoices pi2
                        JOIN purchase_invoice_items pii2 ON pii2.invoiceId = pi2.id
                        WHERE pii2.productId = pii.productId AND pi2.entity_id = pi.entity_id
                    )
                    GROUP BY pii.productId
                ) lp ON lp.productId = sii.productId
                WHERE si.entity_id = ? AND si.date BETWEEN ? AND ? AND si.is_consignment = 0";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (profit): " . $conn->error);
        }
        
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $data = $result[0] ?? ['sales_amount' => 0, 'cost_amount' => 0];
        return floatval($data['sales_amount']) - floatval($data['cost_amount']);
    }
    
    /**
     * Calculate return rate based on sales returns
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @param float $totalSales Total sales amount (pass from calculateTotalSales)
     * @return array ['total_returns' => float, 'return_rate' => float (percentage)]
     */
    protected function calculateReturnRate($conn, $startDate, $endDate, $entityId, $totalSales) {
        $sql = "SELECT COALESCE(SUM(totalAmount - COALESCE(discount,0)),0) AS total_returns
                FROM return_invoices
                WHERE entity_id = ? AND date BETWEEN ? AND ? AND type = 'sales_return'";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (returns): " . $conn->error);
        }
        
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $totalReturns = floatval(($result[0] ?? ['total_returns' => 0])['total_returns']);
        $returnRate = ($totalSales > 0) ? (($totalReturns / $totalSales) * 100.0) : 0.0;
        
        return [
            'total_returns' => $totalReturns,
            'return_rate' => $returnRate
        ];
    }
    
    /**
     * Calculate growth rate compared to previous period
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date of current period (Y-m-d)
     * @param string $endDate End date of current period (Y-m-d)
     * @param int $entityId Entity ID
     * @param float $currentSales Total sales of current period
     * @return float Growth rate percentage (can be negative)
     */
    protected function calculateGrowthRate($conn, $startDate, $endDate, $entityId, $currentSales) {
        $d1 = new DateTime($startDate);
        $d2 = new DateTime($endDate);
        $intervalDays = $d1->diff($d2)->days + 1; // inclusive
        
        $prevEnd = (clone $d1)->modify('-1 day');
        $prevStart = (clone $prevEnd)->modify("-{$intervalDays} days");
        $prevStartStr = $prevStart->format('Y-m-d');
        $prevEndStr = $prevEnd->format('Y-m-d');
        
        $sql = "SELECT COALESCE(SUM(totalAmount - COALESCE(discount,0)),0) AS total_sales
                FROM sales_invoices
                WHERE entity_id = ? AND date BETWEEN ? AND ? AND is_consignment = 0";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (prev period): " . $conn->error);
        }
        
        $stmt->bind_param('iss', $entityId, $prevStartStr, $prevEndStr);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $prevSales = floatval(($result[0] ?? ['total_sales' => 0])['total_sales']);
        $growthRate = ($prevSales > 0) ? (($currentSales - $prevSales) / $prevSales * 100.0) : 0.0;
        
        return $growthRate;
    }
    
    /**
     * Get total inventory value based on purchase prices
     * 
     * @param mysqli $conn Database connection
     * @param int $entityId Entity ID
     * @param int|null $collectionId Optional collection filter
     * @param string|null $keyword Optional search keyword
     * @param bool $includeConsignment Include consignment items (default: true)
     * @return array ['total_value' => float, 'item_count' => int]
     */
    protected function calculateInventoryValue($conn, $entityId, $collectionId = null, $keyword = null, $includeConsignment = true) {
        $sql = "SELECT COALESCE(SUM(COALESCE(pii.unitPrice, 0)),0) AS total_value,
                       COUNT(ii.id) AS item_count
                FROM inventory_items ii
                LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                LEFT JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                JOIN products p ON p.id = ii.product_id
                WHERE ii.status IN ('in_stock', 'returned') AND p.entity_id = ?";
        
        $types = 'i';
        $params = [$entityId];
        
        if ($collectionId && intval($collectionId) > 0) {
            $sql .= " AND p.collection_id = ?";
            $types .= 'i';
            $params[] = intval($collectionId);
        }
        
        if ($keyword && strlen($keyword) > 0) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.design_code LIKE ?)";
            $kw = '%' . $keyword . '%';
            $types .= 'sss';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        
        if (!$includeConsignment) {
            $sql .= " AND (pi.is_consignment = 0 OR pi.id IS NULL)";
        }
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (inventory value): " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $row = $result[0] ?? ['total_value' => 0, 'item_count' => 0];
        return [
            'total_value' => floatval($row['total_value']),
            'item_count' => intval($row['item_count'])
        ];
    }
    
    /**
     * Find best-selling month within a date range
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @return array ['year_month' => 'YYYY-MM', 'amount' => float, 'label' => string]
     */
    protected function findBestSellingMonth($conn, $startDate, $endDate, $entityId) {
        $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS ym, 
                       COALESCE(SUM(totalAmount - COALESCE(discount,0)),0) AS amt
                FROM sales_invoices
                WHERE entity_id = ? AND date BETWEEN ? AND ? AND is_consignment = 0
                GROUP BY ym ORDER BY amt DESC LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (best month): " . $conn->error);
        }
        
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $best = $result[0] ?? null;
        if (!$best) {
            return ['year_month' => null, 'amount' => 0, 'label' => null];
        }
        
        // Convert to Persian month label
        $label = $this->convertGregorianMonthToPersianLabel($best['ym']);
        
        return [
            'year_month' => $best['ym'],
            'amount' => floatval($best['amt']),
            'label' => $label
        ];
    }
    
    /**
     * Convert Gregorian YYYY-MM to Persian month label
     * 
     * @param string $yearMonth Format: YYYY-MM
     * @return string Persian month label (e.g., "فروردین 1403")
     */
    private function convertGregorianMonthToPersianLabel($yearMonth) {
        // Use centralized helper function for consistency
        return gregorian_month_to_persian_label($yearMonth);
    }
    
    /**
     * Get top N products by sales quantity
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @param int $limit Number of top products (default: 10)
     * @return array ['labels' => [], 'values' => []]
     */
    protected function getTopProductsBySales($conn, $startDate, $endDate, $entityId, $limit = 10) {
        $sql = "SELECT p.name AS label, sii.productId AS productId, 
                       COALESCE(SUM(sii.quantity),0) AS qty
                FROM sales_invoice_items sii
                JOIN sales_invoices si ON si.id = sii.invoiceId
                LEFT JOIN products p ON p.id = sii.productId
                WHERE si.entity_id = ? AND si.date BETWEEN ? AND ? AND si.is_consignment = 0
                GROUP BY sii.productId, p.name
                ORDER BY qty DESC
                LIMIT ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (top products): " . $conn->error);
        }
        
        $stmt->bind_param('issi', $entityId, $startDate, $endDate, $limit);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $labels = [];
        $values = [];
        foreach ($result as $row) {
            $labels[] = $row['label'] ?: 'محصول #' . $row['productId'];
            $values[] = intval($row['qty']);
        }
        
        return ['labels' => $labels, 'values' => $values];
    }
    
    /**
     * Get top N customers by sales amount
     * 
     * @param mysqli $conn Database connection
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param int $entityId Entity ID
     * @param int $limit Number of top customers (default: 5)
     * @return array ['labels' => [], 'values' => []]
     */
    protected function getTopCustomersBySales($conn, $startDate, $endDate, $entityId, $limit = 5) {
        $sql = "SELECT COALESCE(c.name, CONCAT('مشتری #', si.customerId)) AS label,
                       COALESCE(SUM(si.totalAmount - COALESCE(si.discount,0)),0) AS amt
                FROM sales_invoices si
                LEFT JOIN customers c ON c.id = si.customerId
                WHERE si.entity_id = ? AND si.date BETWEEN ? AND ? AND si.is_consignment = 0
                GROUP BY si.customerId, c.name
                ORDER BY amt DESC
                LIMIT ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed (top customers): " . $conn->error);
        }
        
        $stmt->bind_param('issi', $entityId, $startDate, $endDate, $limit);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        
        $labels = [];
        $values = [];
        foreach ($result as $row) {
            $labels[] = $row['label'];
            $values[] = floatval($row['amt']);
        }
        
        return ['labels' => $labels, 'values' => $values];
    }
}
