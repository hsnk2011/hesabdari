<?php
// analytics/lib/SalesAnalytics.php - Sales analytics engine.
//
// Every section of the analytics page is computed here from a handful of base
// datasets (invoices, invoice lines with cost, returns, receipts, expenses,
// inventory) that are loaded once per request and memoized.
//
// Conventions (kept identical to the formal reports in ReportModel):
//   • consignment sales invoices (is_consignment = 1) are excluded from sales;
//   • net sales  = totalAmount − discount − sales returns (VAT excluded);
//   • COGS       = purchase price of the exact inventory items sold
//                  (fallback: last purchase price of product+size, then cost_price),
//                  minus the cost of items that came back with sales returns;
//   • invoice discount is spread over its lines pro-rata to line value.

require_once __DIR__ . '/Jalali.php';

class SalesAnalytics {
    private mysqli $db;
    private int $entityId;
    private string $today;
    private array $memo = [];
    private array $areaCache = [];
    private array $labelCache = [];
    private array $dimensionMap = [];
    private float $dimensionThreshold = 15.0;

    public function __construct(mysqli $db, int $entityId, ?string $today = null) {
        $this->db = $db;
        $this->entityId = $entityId;
        $this->today = $today ?: date('Y-m-d');
        $map = json_decode((string)get_app_setting($db, 'dimension_area_map', '{"پادری":0.4}'), true);
        $this->dimensionMap = is_array($map) ? $map : ['پادری' => 0.4];
        $t = (float)get_app_setting($db, 'dimension_cm_threshold', 15);
        $this->dimensionThreshold = $t > 0 ? $t : 15.0;
    }

    /* =================================================================
     *  Public entry points
     * ================================================================= */

    public function meta(): array {
        $range = $this->rows("SELECT MIN(date) AS first_date, MAX(date) AS last_date, COUNT(*) AS n
                              FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0", 'i', [$this->entityId])[0] ?? [];
        $entity = $this->rows("SELECT name FROM business_entities WHERE id = ?", 'i', [$this->entityId])[0]['name'] ?? '';
        $collections = $this->rows("SELECT id, name, type FROM product_collections WHERE entity_id = ? ORDER BY name", 'i', [$this->entityId]);
        return [
            'entity_name' => $entity,
            'first_date' => $range['first_date'] ?? null,
            'last_date' => $range['last_date'] ?? null,
            'invoice_count' => (int)($range['n'] ?? 0),
            'today' => $this->today,
            'collections' => $collections,
        ];
    }

    /**
     * @param array $f ['start','end','compare' => prev|yoy|none,'granularity' => auto|day|week|month|quarter|year,
     *                  'collection' => int,'ptype' => machine|handmade]
     */
    public function section(string $name, array $f): array {
        $p = $this->periods($f);
        switch ($name) {
            case 'overview':      return $this->overview($p);
            case 'time':          return $this->timePatterns($p);
            case 'products':      return $this->products($p, $f);
            case 'customers':     return $this->customers($p);
            case 'collections':   return $this->collectionsSection($p);
            case 'profit':        return $this->profitability($p);
            case 'inventory':     return $this->inventory($p);
            case 'operations':    return $this->operations($p);
        }
        throw new InvalidArgumentException('بخش ناشناخته است.');
    }

    /** Inventory explorer (filter by collection / keyword / consignment origin). */
    public function inventoryExplorer(?int $collectionId, string $keyword, bool $includeConsignment): array {
        $items = [];
        $total = 0.0;
        $kw = mb_strtolower(trim($keyword));
        foreach ($this->stockItems() as $it) {
            if ($it['status'] === 'consignment_out') continue;
            if ($collectionId && (int)$it['collection_id'] !== $collectionId) continue;
            if (!$includeConsignment && $it['consignment_in']) continue;
            if ($kw !== '') {
                $hay = mb_strtolower($it['product_name'] . ' ' . $it['design_code'] . ' ' . $it['description'] . ' ' . $it['dimensions']);
                if (mb_strpos($hay, $kw) === false) continue;
            }
            $total += $it['cost'];
            $items[] = [
                'id' => $it['id'],
                'product_name' => $it['product_name'],
                'design_code' => $it['design_code'],
                'collection' => $it['collection_name'],
                'dimensions' => $it['dimensions'],
                'warehouse' => $it['warehouse_name'],
                'age_days' => $it['age_days'],
                'cost' => round($it['cost']),
                'cost_source' => $it['cost_source'],
                'is_consignment' => $it['consignment_in'] ? 1 : 0,
                'status' => $it['status'],
            ];
        }
        return ['items' => $items, 'item_count' => count($items), 'total_value' => round($total)];
    }

    /* =================================================================
     *  Periods & buckets
     * ================================================================= */

    private function periods(array $f): array {
        $start = $f['start']; $end = $f['end'];
        if ($start > $end) [$start, $end] = [$end, $start];
        $days = $this->daysBetween($start, $end) + 1;
        $compare = $f['compare'] ?? 'prev';
        $cs = null; $ce = null; $clabel = '';
        if ($compare === 'yoy') {
            $cs = Jalali::shiftYears($start, -1);
            $ce = Jalali::shiftYears($end, -1);
            $clabel = 'همین بازه در سال قبل';
        } elseif ($compare === 'prev') {
            $ce = $this->addDays($start, -1);
            $cs = $this->addDays($ce, -($days - 1));
            $clabel = 'دوره قبل (' . $this->faNum($days) . ' روز)';
        }
        $gran = $f['granularity'] ?? 'auto';
        if (!in_array($gran, ['day', 'week', 'month', 'quarter', 'year'], true)) {
            $gran = $days <= 45 ? 'day' : ($days <= 200 ? 'week' : ($days <= 1100 ? 'month' : 'quarter'));
        }
        // "As of" date for stock-like metrics (recency, balances): never later than today.
        $asOf = min($end, $this->today);
        return [
            'start' => $start, 'end' => $end, 'days' => $days,
            'cstart' => $cs, 'cend' => $ce, 'compare' => $compare, 'compare_label' => $clabel,
            'gran' => $gran, 'asof' => $asOf,
            'label' => Jalali::format($start) . ' تا ' . Jalali::format($end),
            'compare_range' => $cs ? Jalali::format($cs) . ' تا ' . Jalali::format($ce) : '',
        ];
    }

    private function bucketKey(string $iso, string $gran): string {
        [$jy, $jm, $jd] = Jalali::parts($iso);
        switch ($gran) {
            case 'day': return $iso;
            case 'week':
                // Persian week: Saturday-first; key by the week's Saturday.
                return $this->addDays($iso, -Jalali::weekdayIndex($iso));
            case 'month': return sprintf('%04d-%02d', $jy, $jm);
            case 'quarter': return $jy . '-Q' . (intdiv($jm - 1, 3) + 1);
            default: return (string)$jy;
        }
    }

    private function bucketLabel(string $key, string $gran): array {
        switch ($gran) {
            case 'day':
                [$jy, $jm, $jd] = Jalali::parts($key);
                return [sprintf('%02d/%02d', $jm, $jd), Jalali::longLabel($key) . ' (' . Jalali::WEEKDAYS[Jalali::weekdayIndex($key)] . ')'];
            case 'week':
                [$jy, $jm, $jd] = Jalali::parts($key);
                return [sprintf('%02d/%02d', $jm, $jd), 'هفته شروع‌شده از ' . Jalali::longLabel($key)];
            case 'month':
                [$jy, $jm] = array_map('intval', explode('-', $key));
                return [Jalali::MONTHS[$jm] . ' ' . substr((string)$jy, 2), Jalali::monthLabel($jy, $jm)];
            case 'quarter':
                [$jy, $q] = explode('-Q', $key);
                return [Jalali::SEASONS[(int)$q] . ' ' . substr($jy, 2), Jalali::SEASONS[(int)$q] . ' ' . $jy];
            default:
                return [$key, 'سال ' . $key];
        }
    }

    /** Ordered bucket keys covering [start, end]. */
    private function bucketKeys(string $start, string $end, string $gran): array {
        $keys = [];
        $d = $start;
        while ($d <= $end) {
            $k = $this->bucketKey($d, $gran);
            $keys[$k] = true;
            $d = $this->addDays($d, 1);
        }
        return array_keys($keys);
    }

    /* =================================================================
     *  Base datasets (memoized)
     * ================================================================= */

    /** All non-consignment sales invoices of the entity (all time). */
    private function allInvoices(): array {
        if (isset($this->memo['allInv'])) return $this->memo['allInv'];
        $rows = $this->rows(
            "SELECT si.id, si.invoice_number, si.customerId, si.date,
                    COALESCE(si.totalAmount,0) AS gross, COALESCE(si.discount,0) AS discount,
                    COALESCE(si.tax_amount,0) AS tax, COALESCE(si.paidAmount,0) AS paid,
                    si.delivery_status, si.source, si.due_date,
                    COALESCE(c.name, CONCAT('مشتری #', si.customerId)) AS customer_name, c.phone
             FROM sales_invoices si
             LEFT JOIN customers c ON c.id = si.customerId
             WHERE si.entity_id = ? AND si.is_consignment = 0
             ORDER BY si.date, si.id", 'i', [$this->entityId]);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['customerId'] = (int)$r['customerId'];
            $r['gross'] = (float)$r['gross'];
            $r['discount'] = (float)$r['discount'];
            $r['tax'] = (float)$r['tax'];
            $r['paid'] = (float)$r['paid'];
            $r['net'] = $r['gross'] - $r['discount'];
        }
        unset($r);
        return $this->memo['allInv'] = $rows;
    }

    private function invoices(string $s, string $e): array {
        $k = "inv|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        return $this->memo[$k] = array_values(array_filter($this->allInvoices(), fn($r) => $r['date'] >= $s && $r['date'] <= $e));
    }

    private function costExpr(): string {
        // Same fallback chain as ReportModel::inventoryItemCostExpr (aliases: ii, pii, si).
        return "COALESCE(pii.unitPrice,
                    (SELECT pii3.unitPrice FROM purchase_invoice_items pii3
                     JOIN purchase_invoices pi3 ON pii3.invoiceId = pi3.id
                     WHERE pii3.productId = ii.product_id AND pii3.dimensions = ii.dimensions
                       AND pi3.entity_id = si.entity_id AND pi3.date <= si.date
                     ORDER BY pi3.date DESC, pii3.id DESC LIMIT 1),
                    ii.cost_price, 0)";
    }

    /** Sales invoice lines in [s, e] with net value (after pro-rata discount), cost and area. */
    private function lines(string $s, string $e): array {
        $k = "lines|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $cost = $this->costExpr();
        $rows = $this->rows(
            "SELECT sii.id, sii.invoiceId, si.date, si.customerId, sii.productId,
                    COALESCE(p.name, CONCAT('محصول #', sii.productId)) AS product_name,
                    COALESCE(p.design_code,'') AS design_code, COALESCE(p.colorway,'') AS colorway,
                    COALESCE(p.material,'') AS material, p.shaneh, p.density, COALESCE(p.type,'') AS product_type,
                    COALESCE(p.collection_id,0) AS collection_id, COALESCE(pc.name,'') AS collection_name,
                    COALESCE(pc.price_per_meter,0) AS price_per_meter, COALESCE(p.price,0) AS list_price,
                    sii.quantity, sii.unitPrice, COALESCE(sii.dimensions,'') AS dimensions,
                    COALESCE(sii.is_service,0) AS is_service,
                    si.totalAmount AS inv_gross, COALESCE(si.discount,0) AS inv_discount,
                    cst.cost, COALESCE(cst.linked,0) AS linked,
                    (SELECT pii4.unitPrice FROM purchase_invoice_items pii4
                       JOIN purchase_invoices pi4 ON pi4.id = pii4.invoiceId
                      WHERE pii4.productId = sii.productId AND pi4.entity_id = si.entity_id AND pi4.date <= si.date
                      ORDER BY (pii4.dimensions = sii.dimensions) DESC, pi4.date DESC, pii4.id DESC LIMIT 1) AS est_unit_cost
             FROM sales_invoice_items sii
             JOIN sales_invoices si ON si.id = sii.invoiceId
             LEFT JOIN products p ON p.id = sii.productId
             LEFT JOIN product_collections pc ON pc.id = p.collection_id
             LEFT JOIN (
                SELECT ii.sales_invoice_item_id AS sid, COUNT(*) AS linked, SUM($cost) AS cost
                FROM inventory_items ii
                JOIN sales_invoice_items sx ON sx.id = ii.sales_invoice_item_id
                JOIN sales_invoices si ON si.id = sx.invoiceId
                LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
                GROUP BY ii.sales_invoice_item_id
             ) cst ON cst.sid = sii.id
             WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
             ORDER BY si.date, sii.id",
            'ississ', [$this->entityId, $s, $e, $this->entityId, $s, $e]);

        foreach ($rows as &$r) {
            $qty = (int)$r['quantity'];
            $gross = round($qty * (float)$r['unitPrice'], 2);
            $invGross = (float)$r['inv_gross'];
            $disc = ($invGross > 0) ? $gross / $invGross * (float)$r['inv_discount'] : 0.0;
            $r['qty'] = $qty;
            $r['unitPrice'] = (float)$r['unitPrice'];
            $r['gross'] = $gross;
            $r['disc'] = $disc;
            $r['net'] = $gross - $disc;
            $r['is_service'] = (int)$r['is_service'];
            $r['linked'] = (int)$r['linked'];
            $r['cost_estimated'] = false;
            if ($r['is_service']) {
                $r['cost'] = 0.0;
            } elseif ($r['linked'] > 0) {
                $r['cost'] = (float)$r['cost'];
            } else {
                // Not linked to physical stock: estimate from the latest purchase price.
                $r['cost'] = $qty * (float)($r['est_unit_cost'] ?? 0);
                $r['cost_estimated'] = true;
            }
            $r['profit'] = $r['net'] - $r['cost'];
            $area = $this->area($r['dimensions']);
            $r['area_unit'] = $area;
            $r['dim_norm'] = $this->normDim($r['dimensions']);
            $r['size_label'] = $this->sizeLabel($r['dimensions']);
            $r['area'] = $r['is_service'] ? 0.0 : $area * $qty;
            $r['collection_id'] = (int)$r['collection_id'];
            $r['productId'] = (int)$r['productId'];
            $r['invoiceId'] = (int)$r['invoiceId'];
            $r['customerId'] = (int)$r['customerId'];
        }
        unset($r);
        return $this->memo[$k] = $rows;
    }

    /** Sales returns in [s, e] with their amount and cost of goods that came back. */
    private function returns(string $s, string $e): array {
        $k = "ret|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $rows = $this->rows(
            "SELECT ri.id, ri.date, ri.person_id, ri.original_invoice_id,
                    (COALESCE(ri.totalAmount,0) - COALESCE(ri.discount,0)) AS amount,
                    COALESCE(ri.description,'') AS description, si.date AS sale_date,
                    COALESCE(c.name, CONCAT('مشتری #', ri.person_id)) AS customer_name
             FROM return_invoices ri
             LEFT JOIN sales_invoices si ON si.id = ri.original_invoice_id
             LEFT JOIN customers c ON c.id = ri.person_id
             WHERE ri.entity_id = ? AND ri.type = 'sales_return' AND ri.date BETWEEN ? AND ?
             ORDER BY ri.date", 'iss', [$this->entityId, $s, $e]);
        $cost = $this->costExpr();
        $cogsRows = $this->rows(
            "SELECT ri.id, COALESCE(SUM($cost), 0) AS cogs
             FROM return_invoices ri
             JOIN return_invoice_items rii ON rii.return_invoice_id = ri.id
             JOIN inventory_items ii ON (
                (rii.inventory_item_ids IS NOT NULL AND rii.inventory_item_ids != ''
                 AND FIND_IN_SET(ii.id, REPLACE(REPLACE(REPLACE(rii.inventory_item_ids, '[', ''), ']', ''), ' ', '')))
                OR ((rii.inventory_item_ids IS NULL OR rii.inventory_item_ids = '')
                    AND rii.original_invoice_item_id IS NOT NULL
                    AND (ii.sales_invoice_item_id = rii.original_invoice_item_id
                         OR ii.returned_from_sales_item_id = rii.original_invoice_item_id))
             )
             LEFT JOIN purchase_invoice_items pii ON ii.purchase_invoice_item_id = pii.id
             LEFT JOIN sales_invoice_items sii ON sii.id = COALESCE(NULLIF(ii.sales_invoice_item_id, 0),
                    NULLIF(ii.returned_from_sales_item_id, 0), rii.original_invoice_item_id)
             LEFT JOIN sales_invoices si ON sii.invoiceId = si.id
             WHERE ri.entity_id = ? AND ri.type = 'sales_return' AND ri.date BETWEEN ? AND ?
             GROUP BY ri.id", 'iss', [$this->entityId, $s, $e]);
        $cogsById = [];
        foreach ($cogsRows as $c) $cogsById[(int)$c['id']] = (float)$c['cogs'];
        $items = $this->rows(
            "SELECT rii.return_invoice_id, rii.productId, COALESCE(p.name, CONCAT('محصول #', rii.productId)) AS product_name,
                    rii.quantity, rii.unitPrice, COALESCE(rii.dimensions,'') AS dimensions
             FROM return_invoice_items rii
             JOIN return_invoices ri ON ri.id = rii.return_invoice_id
             LEFT JOIN products p ON p.id = rii.productId
             WHERE ri.entity_id = ? AND ri.type = 'sales_return' AND ri.date BETWEEN ? AND ?", 'iss', [$this->entityId, $s, $e]);
        $itemsBy = [];
        foreach ($items as $it) $itemsBy[(int)$it['return_invoice_id']][] = $it;
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
            $r['cogs'] = $cogsById[$r['id']] ?? 0.0;
            $r['items'] = $itemsBy[$r['id']] ?? [];
        }
        unset($r);
        return $this->memo[$k] = $rows;
    }

    /** Customer receipts (and refunds) in [s, e], with the linked invoice date and check due date. */
    private function receipts(string $s, string $e): array {
        $k = "rcp|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $rows = $this->rows(
            "SELECT p.id, p.date, p.amount, p.type, p.transaction_type, p.invoiceId, p.invoiceType, p.person_id,
                    si.date AS inv_date, ch.dueDate AS check_due, ch.status AS check_status, ch.bankName
             FROM payments p
             LEFT JOIN sales_invoices si ON si.id = p.invoiceId AND p.invoiceType = 'sales'
             LEFT JOIN checks ch ON ch.id = p.checkId
             WHERE p.entity_id = ? AND p.person_type = 'customer' AND p.date BETWEEN ? AND ?",
            'iss', [$this->entityId, $s, $e]);
        foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; }
        unset($r);
        return $this->memo[$k] = $rows;
    }

    private function expenses(string $s, string $e): array {
        $k = "exp|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $rows = $this->rows("SELECT date, COALESCE(NULLIF(category,''),'بدون دسته') AS category, amount
                             FROM expenses WHERE entity_id = ? AND date BETWEEN ? AND ?", 'iss', [$this->entityId, $s, $e]);
        foreach ($rows as &$r) $r['amount'] = (float)$r['amount'];
        unset($r);
        return $this->memo[$k] = $rows;
    }

    private function incomesTotal(string $s, string $e): float {
        try {
            $r = $this->rows("SELECT COALESCE(SUM(amount),0) AS t FROM incomes WHERE entity_id = ? AND date BETWEEN ? AND ?",
                'iss', [$this->entityId, $s, $e]);
            return (float)($r[0]['t'] ?? 0);
        } catch (Throwable $e) { return 0.0; }
    }

    /** Physical stock (in stock, returned-to-stock and consignment-out) with cost and age. */
    private function stockItems(): array {
        if (isset($this->memo['stock'])) return $this->memo['stock'];
        $rows = $this->rows(
            "SELECT ii.id, ii.product_id, ii.status, COALESCE(ii.dimensions,'') AS dimensions, ii.entry_date,
                    COALESCE(p.name, CONCAT('محصول #', ii.product_id)) AS product_name, COALESCE(p.design_code,'') AS design_code,
                    COALESCE(p.description,'') AS description, COALESCE(p.type,'') AS product_type,
                    COALESCE(p.collection_id,0) AS collection_id, COALESCE(pc.name,'') AS collection_name,
                    COALESCE(w.name,'') AS warehouse_name,
                    pii.unitPrice AS purchase_price, ii.cost_price, COALESCE(pi.is_consignment,0) AS consignment_in,
                    pi.date AS purchase_date,
                    (SELECT pii2.unitPrice FROM purchase_invoice_items pii2
                       JOIN purchase_invoices pi2 ON pi2.id = pii2.invoiceId
                      WHERE pii2.productId = ii.product_id AND pi2.entity_id = p.entity_id
                      ORDER BY (pii2.dimensions = ii.dimensions) DESC, pi2.date DESC, pii2.id DESC LIMIT 1) AS last_price
             FROM inventory_items ii
             JOIN products p ON p.id = ii.product_id
             LEFT JOIN product_collections pc ON pc.id = p.collection_id
             LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
             LEFT JOIN purchase_invoices pi ON pi.id = pii.invoiceId
             LEFT JOIN warehouses w ON w.id = ii.warehouse_id
             WHERE p.entity_id = ? AND ii.status IN ('in_stock','returned','consignment_out')
             ORDER BY p.name, ii.id", 'i', [$this->entityId]);
        foreach ($rows as &$r) {
            if ($r['purchase_price'] !== null) { $r['cost'] = (float)$r['purchase_price']; $r['cost_source'] = 'purchase'; }
            elseif ($r['cost_price'] !== null && (float)$r['cost_price'] > 0) { $r['cost'] = (float)$r['cost_price']; $r['cost_source'] = 'cost_price'; }
            elseif ($r['last_price'] !== null) { $r['cost'] = (float)$r['last_price']; $r['cost_source'] = 'last_purchase'; }
            else { $r['cost'] = 0.0; $r['cost_source'] = 'unknown'; }
            $since = $r['purchase_date'] ?: substr((string)$r['entry_date'], 0, 10);
            $r['since'] = $since ?: $this->today;
            $r['age_days'] = max(0, $this->daysBetween($r['since'], $this->today));
            $r['collection_id'] = (int)$r['collection_id'];
            $r['consignment_in'] = (int)$r['consignment_in'] === 1;
            $r['area'] = $this->area($r['dimensions']);
            $r['dim_norm'] = $this->normDim($r['dimensions']);
            $r['size_label'] = $this->sizeLabel($r['dimensions']);
        }
        unset($r);
        return $this->memo['stock'] = $rows;
    }

    /* =================================================================
     *  Period summary (KPIs)
     * ================================================================= */

    private function firstPurchaseMap(): array {
        if (isset($this->memo['firstBuy'])) return $this->memo['firstBuy'];
        $m = [];
        foreach ($this->allInvoices() as $r) {
            if (!isset($m[$r['customerId']])) $m[$r['customerId']] = $r['date'];
        }
        return $this->memo['firstBuy'] = $m;
    }

    private function summary(?string $s, ?string $e): ?array {
        if (!$s || !$e) return null;
        $k = "sum|$s|$e";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $inv = $this->invoices($s, $e);
        $lines = $this->lines($s, $e);
        $ret = $this->returns($s, $e);
        $first = $this->firstPurchaseMap();

        $gross = $disc = $tax = 0.0; $cust = []; $newCust = []; $dates = [];
        foreach ($inv as $r) {
            $gross += $r['gross']; $disc += $r['discount']; $tax += $r['tax'];
            $cust[$r['customerId']] = true;
            $dates[$r['date']] = true;
            if (($first[$r['customerId']] ?? '') >= $s && ($first[$r['customerId']] ?? '') <= $e) $newCust[$r['customerId']] = true;
        }
        $cogsGross = $units = $area = $netWithArea = $netGoods = 0.0; $estimated = 0;
        foreach ($lines as $l) {
            $cogsGross += $l['cost'];
            if (!$l['is_service']) { $units += $l['qty']; $netGoods += $l['net']; }
            if ($l['area'] > 0) { $area += $l['area']; $netWithArea += $l['net']; }
            if ($l['cost_estimated']) $estimated++;
        }
        $returns = array_sum(array_column($ret, 'amount'));
        $returnCogs = array_sum(array_column($ret, 'cogs'));
        $netBeforeReturns = $gross - $disc;
        $net = $netBeforeReturns - $returns;
        $cogs = $cogsGross - $returnCogs;
        $gp = $net - $cogs;
        $rc = $this->receipts($s, $e);
        $receipts = 0.0; $refunds = 0.0;
        foreach ($rc as $p) {
            if ($p['transaction_type'] === 'receipt') $receipts += $p['amount']; else $refunds += $p['amount'];
        }
        $exp = array_sum(array_column($this->expenses($s, $e), 'amount'));
        $income = $this->incomesTotal($s, $e);
        $n = count($inv);
        $days = $this->daysBetween($s, $e) + 1;
        return $this->memo[$k] = [
            'gross_sales' => $gross,
            'discounts' => $disc,
            'tax' => $tax,
            'net_before_returns' => $netBeforeReturns,
            'returns' => $returns,
            'net_sales' => $net,
            'cogs' => $cogs,
            'return_cogs' => $returnCogs,
            'gross_profit' => $gp,
            'gross_margin' => $net > 0 ? $gp / $net * 100 : null,
            'invoices' => $n,
            'units' => $units,
            'area' => $area,
            'price_per_m2' => $area > 0 ? $netWithArea / $area : null,
            'avg_unit_price' => $units > 0 ? $netGoods / $units : null,
            'aov' => $n > 0 ? $netBeforeReturns / $n : null,
            'items_per_invoice' => $n > 0 ? $units / $n : null,
            'customers' => count($cust),
            'new_customers' => count($newCust),
            'returning_customers' => count($cust) - count($newCust),
            'sales_per_customer' => count($cust) > 0 ? $net / count($cust) : null,
            'receipts' => $receipts,
            'refunds' => $refunds,
            'collection_ratio' => $net > 0 ? ($receipts - $refunds) / $net * 100 : null,
            'expenses' => $exp,
            'other_income' => $income,
            'operating_profit' => $gp - $exp + $income,
            'discount_rate' => $gross > 0 ? $disc / $gross * 100 : null,
            'return_rate' => $netBeforeReturns > 0 ? $returns / $netBeforeReturns * 100 : null,
            'active_days' => count($dates),
            'days' => $days,
            'daily_avg' => $days > 0 ? $net / $days : 0,
            'estimated_cost_lines' => $estimated,
        ];
    }

    /* =================================================================
     *  Section: overview
     * ================================================================= */

    private function overview(array $p): array {
        $cur = $this->summary($p['start'], $p['end']);
        $cmp = $this->summary($p['cstart'], $p['cend']);
        $trend = $this->trend($p);

        $kpiDefs = [
            'net_sales' => ['فروش خالص', 'money', true],
            'gross_profit' => ['سود ناخالص', 'money', true],
            'gross_margin' => ['حاشیه سود ناخالص', 'pct', true],
            'invoices' => ['تعداد فاکتور', 'int', true],
            'aov' => ['میانگین هر فاکتور', 'money', true],
            'customers' => ['مشتریان فعال', 'int', true],
            'new_customers' => ['مشتری جدید', 'int', true],
            'units' => ['تعداد قلم فروخته‌شده', 'int', true],
            'area' => ['متراژ فروش (m²)', 'dec', true],
            'price_per_m2' => ['میانگین قیمت هر متر', 'money', true],
            'receipts' => ['وصولی از مشتریان', 'money', true],
            'collection_ratio' => ['نسبت وصول به فروش', 'pct', true],
            'discount_rate' => ['نرخ تخفیف', 'pct', false],
            'return_rate' => ['نرخ مرجوعی', 'pct', false],
            'operating_profit' => ['سود عملیاتی تقریبی', 'money', true],
            'daily_avg' => ['میانگین فروش روزانه', 'money', true],
        ];
        $spark = [
            'net_sales' => $trend['net'], 'gross_profit' => $trend['profit'], 'invoices' => $trend['invoices'],
            'customers' => $trend['customers'], 'receipts' => $trend['receipts'], 'gross_margin' => $trend['margin'],
            'aov' => $trend['aov'],
        ];
        $kpis = [];
        foreach ($kpiDefs as $key => [$label, $fmt, $upGood]) {
            $v = $cur[$key];
            $c = $cmp[$key] ?? null;
            $kpis[] = [
                'key' => $key, 'label' => $label, 'format' => $fmt, 'up_is_good' => $upGood,
                'value' => $v, 'compare' => $c, 'delta' => $this->delta($v, $c, $fmt === 'pct'),
                'spark' => $spark[$key] ?? null,
            ];
        }

        $waterfall = [
            ['label' => 'فروش ناخالص', 'value' => $cur['gross_sales'], 'type' => 'total'],
            ['label' => 'تخفیف', 'value' => -$cur['discounts'], 'type' => 'minus'],
            ['label' => 'مرجوعی', 'value' => -$cur['returns'], 'type' => 'minus'],
            ['label' => 'فروش خالص', 'value' => $cur['net_sales'], 'type' => 'total'],
            ['label' => 'بهای تمام‌شده', 'value' => -$cur['cogs'], 'type' => 'minus'],
            ['label' => 'سود ناخالص', 'value' => $cur['gross_profit'], 'type' => 'total'],
            ['label' => 'هزینه‌ها', 'value' => -$cur['expenses'], 'type' => 'minus'],
        ];
        if ($cur['other_income'] > 0) $waterfall[] = ['label' => 'سایر درآمدها', 'value' => $cur['other_income'], 'type' => 'plus'];
        $waterfall[] = ['label' => 'سود عملیاتی', 'value' => $cur['operating_profit'], 'type' => 'total'];

        $score = $this->healthScore($cur, $cmp, $p);
        return [
            'period' => $this->periodInfo($p),
            'summary' => $cur,
            'compare_summary' => $cmp,
            'kpis' => $kpis,
            'trend' => $trend,
            'waterfall' => $waterfall,
            'forecast' => $this->forecast(),
            'health' => $score,
            'insights' => $this->insights($p, $cur, $cmp),
            'top_products' => array_slice($this->productRows($this->lines($p['start'], $p['end']), $p), 0, 5),
            'top_customers' => array_slice($this->customerRows($p), 0, 5),
        ];
    }

    private function periodInfo(array $p): array {
        $info = $p;
        $info['gran_label'] = ['day' => 'روزانه', 'week' => 'هفتگی', 'month' => 'ماهانه', 'quarter' => 'فصلی', 'year' => 'سالانه'][$p['gran']];
        return $info;
    }

    /** Sales trend in buckets for the period, with comparison-period values aligned by index. */
    private function trend(array $p): array {
        $g = $p['gran'];
        $memoKey = "trend|{$p['start']}|{$p['end']}|{$p['cstart']}|$g";
        if (isset($this->memo[$memoKey])) return $this->memo[$memoKey];
        $agg = function (string $s, string $e) use ($g) {
            $keys = $this->bucketKeys($s, $e, $g);
            $b = [];
            foreach ($keys as $k) $b[$k] = ['net' => 0.0, 'profit' => 0.0, 'invoices' => 0, 'cust' => [], 'receipts' => 0.0, 'gross' => 0.0, 'returns' => 0.0, 'units' => 0];
            foreach ($this->invoices($s, $e) as $r) {
                $k = $this->bucketKey($r['date'], $g);
                $b[$k]['net'] += $r['net']; $b[$k]['gross'] += $r['net'];
                $b[$k]['invoices']++; $b[$k]['cust'][$r['customerId']] = true;
            }
            foreach ($this->lines($s, $e) as $l) {
                $k = $this->bucketKey($l['date'], $g);
                $b[$k]['profit'] += $l['profit'];
                if (!$l['is_service']) $b[$k]['units'] += $l['qty'];
            }
            foreach ($this->returns($s, $e) as $r) {
                $k = $this->bucketKey($r['date'], $g);
                $b[$k]['net'] -= $r['amount'];
                $b[$k]['returns'] += $r['amount'];
                $b[$k]['profit'] -= ($r['amount'] - $r['cogs']);
            }
            foreach ($this->receipts($s, $e) as $rc) {
                $k = $this->bucketKey($rc['date'], $g);
                if (!isset($b[$k])) continue;
                $b[$k]['receipts'] += $rc['transaction_type'] === 'receipt' ? $rc['amount'] : -$rc['amount'];
            }
            return $b;
        };
        $cur = $agg($p['start'], $p['end']);
        $cmp = $p['cstart'] ? array_values($agg($p['cstart'], $p['cend'])) : [];
        $out = ['labels' => [], 'titles' => [], 'net' => [], 'profit' => [], 'invoices' => [], 'customers' => [],
                'receipts' => [], 'margin' => [], 'aov' => [], 'units' => [], 'returns' => [], 'cmp_net' => [], 'cmp_profit' => [], 'cmp_invoices' => [],
                'moving_avg' => [], 'gran' => $g];
        $i = 0;
        foreach ($cur as $k => $v) {
            [$label, $title] = $this->bucketLabel($k, $g);
            $out['labels'][] = $label;
            $out['titles'][] = $title;
            $out['net'][] = round($v['net']);
            $out['profit'][] = round($v['profit']);
            $out['invoices'][] = $v['invoices'];
            $out['customers'][] = count($v['cust']);
            $out['receipts'][] = round($v['receipts']);
            $out['returns'][] = round($v['returns']);
            $out['units'][] = $v['units'];
            $out['margin'][] = $v['net'] > 0 ? round($v['profit'] / $v['net'] * 100, 1) : null;
            $out['aov'][] = $v['invoices'] > 0 ? round($v['gross'] / $v['invoices']) : null;
            $out['cmp_net'][] = isset($cmp[$i]) ? round($cmp[$i]['net']) : null;
            $out['cmp_profit'][] = isset($cmp[$i]) ? round($cmp[$i]['profit']) : null;
            $out['cmp_invoices'][] = isset($cmp[$i]) ? $cmp[$i]['invoices'] : null;
            $i++;
        }
        // Moving average (window adapts to granularity) smooths noisy daily/weekly series.
        $w = ['day' => 7, 'week' => 4, 'month' => 3, 'quarter' => 2, 'year' => 2][$g];
        $n = count($out['net']);
        for ($j = 0; $j < $n; $j++) {
            if ($j + 1 < $w) { $out['moving_avg'][] = null; continue; }
            $out['moving_avg'][] = round(array_sum(array_slice($out['net'], $j - $w + 1, $w)) / $w);
        }
        $out['moving_window'] = $w;
        return $this->memo[$memoKey] = $out;
    }

    /** Monthly (Jalali) history + linear trend forecast for the next 3 months + current-month pace. */
    private function forecast(): array {
        $monthly = [];
        foreach ($this->allInvoices() as $r) {
            if ($r['date'] > $this->today) continue;
            $k = $this->bucketKey($r['date'], 'month');
            $monthly[$k] = ($monthly[$k] ?? 0) + $r['net'];
        }
        // Returns reduce the month they happen in.
        if (!empty($monthly)) {
            $first = array_key_first($monthly);
            [$fy, $fm] = array_map('intval', explode('-', $first));
            foreach ($this->returns(Jalali::iso($fy, $fm, 1), $this->today) as $r) {
                $k = $this->bucketKey($r['date'], 'month');
                $monthly[$k] = ($monthly[$k] ?? 0) - $r['amount'];
            }
        }
        [$ty, $tm, $td] = Jalali::parts($this->today);
        $curKey = sprintf('%04d-%02d', $ty, $tm);
        $mtd = $monthly[$curKey] ?? 0.0;
        $len = Jalali::monthLength($ty, $tm);
        $pace = $td > 0 ? $mtd / $td * $len : 0;

        // Fill missing months between first and last-complete month with zeros.
        $series = [];
        if (!empty($monthly)) {
            $first = min(array_keys($monthly));
            [$y, $m] = array_map('intval', explode('-', $first));
            while (sprintf('%04d-%02d', $y, $m) < $curKey) {
                $key = sprintf('%04d-%02d', $y, $m);
                $series[$key] = $monthly[$key] ?? 0.0;
                if (++$m > 12) { $m = 1; $y++; }
            }
        }
        $hist = array_slice($series, -12, null, true);
        $vals = array_values($hist);
        $n = count($vals);
        $pred = [];
        $slope = 0.0;
        if ($n >= 3) {
            $sx = $sy = $sxy = $sxx = 0.0;
            foreach ($vals as $i => $v) { $sx += $i; $sy += $v; $sxy += $i * $v; $sxx += $i * $i; }
            $den = ($n * $sxx - $sx * $sx);
            $slope = $den != 0 ? ($n * $sxy - $sx * $sy) / $den : 0;
            $icpt = ($sy - $slope * $sx) / $n;
            // Residual spread → a simple ± band so the forecast is not read as exact.
            $res = 0.0;
            foreach ($vals as $i => $v) { $res += ($v - ($icpt + $slope * $i)) ** 2; }
            $sd = sqrt($res / max(1, $n - 2));
            [$y, $m] = [$ty, $tm];
            for ($h = 0; $h < 3; $h++) {
                $x = $n + $h;
                $v = max(0, $icpt + $slope * $x);
                $pred[] = ['label' => Jalali::monthLabel($y, $m), 'value' => round($v), 'low' => round(max(0, $v - $sd)), 'high' => round($v + $sd)];
                if (++$m > 12) { $m = 1; $y++; }
            }
        }
        $labels = []; $values = [];
        foreach ($hist as $k => $v) {
            [$y, $m] = array_map('intval', explode('-', $k));
            $labels[] = Jalali::monthLabel($y, $m);
            $values[] = round($v);
        }
        $avg = $n ? array_sum($vals) / $n : 0;
        return [
            'history_labels' => $labels,
            'history' => $values,
            'prediction' => $pred,
            'slope' => round($slope),
            'avg_month' => round($avg),
            'current_month' => [
                'label' => Jalali::monthLabel($ty, $tm), 'mtd' => round($mtd), 'day' => $td, 'length' => $len,
                'projected' => round($pace),
                'vs_avg' => $avg > 0 ? round(($pace - $avg) / $avg * 100, 1) : null,
            ],
        ];
    }

    private function healthScore(array $cur, ?array $cmp, array $p): array {
        $parts = [];
        $clamp = fn($v) => max(0, min(100, $v));
        if ($cmp && $cmp['net_sales'] > 0 && $cmp['invoices'] >= 5) {
            $g = ($cur['net_sales'] - $cmp['net_sales']) / $cmp['net_sales'] * 100;
            $parts[] = ['key' => 'growth', 'label' => 'رشد فروش', 'score' => $clamp(50 + $g * 1.5), 'value' => round($g, 1),
                        'hint' => 'تغییر فروش خالص نسبت به ' . $p['compare_label']];
        }
        if ($cur['gross_margin'] !== null) {
            $parts[] = ['key' => 'margin', 'label' => 'حاشیه سود', 'score' => $clamp($cur['gross_margin'] * 2.5), 'value' => round($cur['gross_margin'], 1),
                        'hint' => 'حاشیه ۴۰٪ و بالاتر امتیاز کامل می‌گیرد'];
        }
        if ($cur['collection_ratio'] !== null) {
            $parts[] = ['key' => 'collection', 'label' => 'وصول مطالبات', 'score' => $clamp($cur['collection_ratio']), 'value' => round($cur['collection_ratio'], 1),
                        'hint' => 'نسبت پول دریافتی به فروش همین دوره'];
        }
        if ($cur['return_rate'] !== null) {
            $parts[] = ['key' => 'returns', 'label' => 'کیفیت (مرجوعی کم)', 'score' => $clamp(100 - $cur['return_rate'] * 10), 'value' => round($cur['return_rate'], 1),
                        'hint' => 'هر ۱٪ مرجوعی ۱۰ امتیاز کم می‌کند'];
        }
        $rows = $this->customerRows($p);
        $tot = array_sum(array_column($rows, 'net'));
        if ($tot > 0 && count($rows) >= 3) {
            $top = array_sum(array_slice(array_column($rows, 'net'), 0, max(1, (int)ceil(count($rows) * 0.1))));
            $share = $top / $tot * 100;
            $parts[] = ['key' => 'concentration', 'label' => 'پراکندگی مشتریان', 'score' => $clamp(130 - $share * 1.3), 'value' => round($share, 1),
                        'hint' => 'سهم ۱۰٪ برترین مشتریان از فروش؛ هرچه کمتر، ریسک وابستگی کمتر'];
        }
        if ($cur['discount_rate'] !== null) {
            $parts[] = ['key' => 'discount', 'label' => 'کنترل تخفیف', 'score' => $clamp(100 - $cur['discount_rate'] * 6), 'value' => round($cur['discount_rate'], 1),
                        'hint' => 'هر ۱٪ تخفیف ۶ امتیاز کم می‌کند'];
        }
        $score = count($parts) ? round(array_sum(array_column($parts, 'score')) / count($parts)) : null;
        foreach ($parts as &$pp) $pp['score'] = round($pp['score']);
        unset($pp);
        $grade = $score === null ? '' : ($score >= 80 ? 'عالی' : ($score >= 65 ? 'خوب' : ($score >= 50 ? 'متوسط' : 'نیازمند توجه')));
        return ['score' => $score, 'grade' => $grade, 'parts' => $parts];
    }

    /** Plain-language findings that combine several sections. */
    private function insights(array $p, array $cur, ?array $cmp): array {
        $out = [];
        $add = function ($level, $icon, $text) use (&$out) { $out[] = ['level' => $level, 'icon' => $icon, 'text' => $text]; };
        $m = fn($v) => $this->faMoney($v);

        if ($cur['invoices'] === 0) {
            $add('info', 'info-circle', 'در این بازه هیچ فاکتور فروشی ثبت نشده است.');
            return $out;
        }
        $cmpWeak = $cmp && ($cmp['invoices'] < 5 || $cmp['net_sales'] <= 0);
        if ($cmpWeak) {
            $add('info', 'info-circle', $p['compare_label'] . ' (' . $p['compare_range'] . ') فقط ' . $this->faNum($cmp['invoices']) . ' فاکتور دارد؛ درصدهای مقایسه‌ای برای این بازه معنادار نیستند.');
        }
        if ($cmp && !$cmpWeak) {
            $g = ($cur['net_sales'] - $cmp['net_sales']) / $cmp['net_sales'] * 100;
            if ($g >= 0) $add('good', 'graph-up-arrow', 'فروش خالص نسبت به ' . $p['compare_label'] . ' ' . $this->faPct($g) . ' رشد کرده است (' . $m($cur['net_sales'] - $cmp['net_sales']) . ' بیشتر).');
            else $add('bad', 'graph-down-arrow', 'فروش خالص نسبت به ' . $p['compare_label'] . ' ' . $this->faPct(-$g) . ' کاهش یافته است (' . $m($cmp['net_sales'] - $cur['net_sales']) . ' کمتر).');
            // Decompose: was it fewer invoices or smaller invoices?
            if ($cmp['invoices'] > 0 && $cmp['aov'] > 0 && $cur['aov']) {
                $dn = ($cur['invoices'] - $cmp['invoices']) / $cmp['invoices'] * 100;
                $da = ($cur['aov'] - $cmp['aov']) / $cmp['aov'] * 100;
                $add('info', 'diagram-2', 'تفکیک تغییر فروش: تعداد فاکتورها ' . $this->signedPct($dn) . ' و میانگین مبلغ هر فاکتور ' . $this->signedPct($da) . ' تغییر کرده است.');
            }
            if ($cur['gross_margin'] !== null && $cmp['gross_margin'] !== null) {
                $dm = $cur['gross_margin'] - $cmp['gross_margin'];
                if (abs($dm) >= 2) {
                    $add($dm > 0 ? 'good' : 'warn', 'percent', 'حاشیه سود ناخالص ' . $this->faNum(round(abs($dm), 1)) . ' واحد درصد ' . ($dm > 0 ? 'بهتر' : 'بدتر') . ' شده است (از ' . $this->faPct($cmp['gross_margin']) . ' به ' . $this->faPct($cur['gross_margin']) . ').');
                }
            }
        }
        // Customer concentration
        $rows = $this->customerRows($p);
        $tot = array_sum(array_column($rows, 'net'));
        if ($tot > 0 && count($rows) >= 5) {
            $top5 = array_sum(array_slice(array_column($rows, 'net'), 0, 5));
            $share = $top5 / $tot * 100;
            if ($share >= 50) $add('warn', 'people', $this->faPct($share) . ' فروش این دوره فقط از ۵ مشتری است؛ وابستگی به چند مشتری ریسک بالایی دارد.');
            else $add('info', 'people', '۵ مشتری برتر ' . $this->faPct($share) . ' فروش را ساخته‌اند.');
        }
        // Weekday pattern (normalized per occurrence)
        $wd = $this->weekdayStats($p['start'], $p['end']);
        $best = null; $worst = null;
        foreach ($wd as $w) {
            if ($w['occurrences'] === 0) continue;
            if ($best === null || $w['avg'] > $best['avg']) $best = $w;
            if ($worst === null || $w['avg'] < $worst['avg']) $worst = $w;
        }
        if ($best && $worst && $best['avg'] > 0 && $best['label'] !== $worst['label']) {
            $add('info', 'calendar-week', 'پرفروش‌ترین روز هفته «' . $best['label'] . '» است (میانگین ' . $m($best['avg']) . ' در هر ' . $best['label'] . ')؛ کم‌فروش‌ترین «' . $worst['label'] . '».');
        }
        // Returns & discounts
        if ($cur['return_rate'] !== null && $cur['return_rate'] >= 5) {
            $add('bad', 'arrow-return-left', 'نرخ مرجوعی ' . $this->faPct($cur['return_rate']) . ' است که بالاتر از حد معمول (۵٪) است.');
        }
        if ($cur['discount_rate'] !== null && $cmp && !$cmpWeak && $cmp['discount_rate'] !== null && $cur['discount_rate'] - $cmp['discount_rate'] >= 1) {
            $add('warn', 'tag', 'نرخ تخفیف از ' . $this->faPct($cmp['discount_rate']) . ' به ' . $this->faPct($cur['discount_rate']) . ' رسیده است؛ تخفیف‌ها مستقیماً از سود کم می‌شوند.');
        }
        // Collections
        if ($cur['collection_ratio'] !== null && $cur['collection_ratio'] < 70) {
            $add('warn', 'cash-coin', 'فقط ' . $this->faPct($cur['collection_ratio']) . ' از فروش این دوره وصول شده است؛ بخش بزرگی از فروش نسیه مانده است.');
        }
        // RFM risk
        $rfm = $this->rfm($p);
        $risk = 0; $riskValue = 0.0;
        foreach ($rfm['customers'] as $c) {
            if (in_array($c['segment'], ['at_risk', 'cant_lose'], true)) { $risk++; $riskValue += $c['monetary']; }
        }
        if ($risk > 0) $add('warn', 'person-exclamation', $this->faNum($risk) . ' مشتری ارزشمند که قبلاً مرتب خرید می‌کردند مدتی است خرید نکرده‌اند (ارزش خرید تاریخی ' . $m($riskValue) . '). بخش مشتریان ← در معرض ریزش.');
        // Checks overdue in hand
        $over = $this->rows("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM checks WHERE entity_id = ? AND type='received' AND status='in_hand' AND dueDate < ?",
            'is', [$this->entityId, $this->today])[0];
        if ((int)$over['n'] > 0) $add('bad', 'exclamation-octagon', $this->faNum((int)$over['n']) . ' چک دریافتی به مبلغ ' . $m((float)$over['t']) . ' سررسیدش گذشته ولی هنوز «در دست» است (نه وصول شده، نه خرج شده).');
        // Dead stock
        $dead = $this->deadStock();
        if ($dead['count'] > 0) $add('warn', 'box-seam', $this->faNum($dead['count']) . ' قلم کالا بیش از ۶ ماه در انبار مانده و در ۶ ماه اخیر هیچ فروشی نداشته است (سرمایه خوابیده: ' . $m($dead['value']) . ').');
        // Loss-making lines
        $loss = array_filter($this->lines($p['start'], $p['end']), fn($l) => !$l['is_service'] && $l['cost'] > 0 && $l['profit'] < 0);
        if (count($loss)) $add('bad', 'emoji-frown', $this->faNum(count($loss)) . ' ردیف فروش زیر قیمت خرید فروخته شده‌اند (زیان ' . $m(-array_sum(array_column($loss, 'profit'))) . '). بخش سودآوری ← فروش‌های زیان‌ده.');
        // Pace of current month
        $f = $this->forecast();
        if ($f['current_month']['vs_avg'] !== null && $f['current_month']['day'] >= 5) {
            $v = $f['current_month']['vs_avg'];
            $add($v >= 0 ? 'good' : 'warn', 'speedometer2', 'با سرعت فعلی، فروش ' . $f['current_month']['label'] . ' حدود ' . $m($f['current_month']['projected']) . ' می‌شود که ' . $this->faPct(abs($v)) . ($v >= 0 ? ' بالاتر' : ' پایین‌تر') . ' از میانگین ماهانه ۱۲ ماه اخیر است.');
        }
        if ($cur['estimated_cost_lines'] > 0) {
            $add('info', 'info-circle', $this->faNum($cur['estimated_cost_lines']) . ' ردیف فروش به کالای انبار متصل نبوده و بهای تمام‌شده آن‌ها از آخرین قیمت خرید تخمین زده شده است.');
        }
        return $out;
    }

    /* =================================================================
     *  Section: time patterns
     * ================================================================= */

    private function weekdayStats(string $s, string $e): array {
        $res = [];
        foreach (Jalali::WEEKDAYS as $i => $name) $res[$i] = ['label' => $name, 'net' => 0.0, 'invoices' => 0, 'occurrences' => 0, 'avg' => 0.0];
        for ($d = $s; $d <= $e; $d = $this->addDays($d, 1)) $res[Jalali::weekdayIndex($d)]['occurrences']++;
        foreach ($this->invoices($s, $e) as $r) {
            $w = Jalali::weekdayIndex($r['date']);
            $res[$w]['net'] += $r['net']; $res[$w]['invoices']++;
        }
        foreach ($res as &$r) { $r['avg'] = $r['occurrences'] ? $r['net'] / $r['occurrences'] : 0; $r['net'] = round($r['net']); $r['avg'] = round($r['avg']); }
        unset($r);
        return array_values($res);
    }

    private function timePatterns(array $p): array {
        $s = $p['start']; $e = $p['end'];
        $inv = $this->invoices($s, $e);
        $daily = [];
        foreach ($inv as $r) {
            $daily[$r['date']]['net'] = ($daily[$r['date']]['net'] ?? 0) + $r['net'];
            $daily[$r['date']]['n'] = ($daily[$r['date']]['n'] ?? 0) + 1;
        }

        // Day of the Jalali month and month thirds (start/middle/end of month).
        $dom = array_fill(1, 31, 0.0); $domN = array_fill(1, 31, 0);
        $thirds = [['label' => 'دهه اول (۱ تا ۱۰)', 'net' => 0.0, 'invoices' => 0], ['label' => 'دهه دوم (۱۱ تا ۲۰)', 'net' => 0.0, 'invoices' => 0], ['label' => 'دهه سوم (۲۱ تا آخر)', 'net' => 0.0, 'invoices' => 0]];
        foreach ($inv as $r) {
            [, , $jd] = Jalali::parts($r['date']);
            $dom[$jd] += $r['net']; $domN[$jd]++;
            $t = $jd <= 10 ? 0 : ($jd <= 20 ? 1 : 2);
            $thirds[$t]['net'] += $r['net']; $thirds[$t]['invoices']++;
        }

        // Calendar heatmap (max ~53 weeks ending at the period end).
        $hs = max($s, $this->addDays($e, -370));
        $hs = $this->addDays($hs, -Jalali::weekdayIndex($hs));
        $cells = [];
        $maxDay = 0.0;
        for ($d = $hs; $d <= $e; $d = $this->addDays($d, 1)) {
            $v = $d >= $s ? ($daily[$d]['net'] ?? 0.0) : null;
            if ($v !== null) $maxDay = max($maxDay, $v);
            [$jy, $jm, $jd] = Jalali::parts($d);
            $cells[] = ['date' => $d, 'jd' => $jd, 'jm' => $jm, 'label' => Jalali::longLabel($d), 'wd' => Jalali::weekdayIndex($d), 'value' => $v === null ? null : round($v), 'n' => $daily[$d]['n'] ?? 0];
        }

        // Seasonality: every Jalali year × month (all history, net of returns).
        $season = [];
        foreach ($this->allInvoices() as $r) {
            [$jy, $jm] = Jalali::parts($r['date']);
            $season[$jy][$jm] = ($season[$jy][$jm] ?? 0) + $r['net'];
        }
        if ($this->allInvoices()) {
            $first = $this->allInvoices()[0]['date'];
            foreach ($this->returns($first, $this->today < $e ? $e : $this->today) as $r) {
                [$jy, $jm] = Jalali::parts($r['date']);
                $season[$jy][$jm] = ($season[$jy][$jm] ?? 0) - $r['amount'];
            }
        }
        ksort($season);
        $years = [];
        foreach ($season as $jy => $months) {
            $vals = [];
            for ($m = 1; $m <= 12; $m++) $vals[] = isset($months[$m]) ? round($months[$m]) : null;
            $years[] = ['year' => $jy, 'values' => $vals, 'total' => round(array_sum($months))];
        }
        // Average share of each month in the year (only complete years count).
        [$ty] = Jalali::parts($this->today);
        $monthShare = array_fill(0, 12, 0.0); $cnt = 0;
        foreach ($years as $y) {
            if ($y['year'] >= $ty || $y['total'] <= 0) continue;
            foreach ($y['values'] as $i => $v) $monthShare[$i] += ($v ?? 0) / $y['total'] * 100;
            $cnt++;
        }
        $monthShare = $cnt ? array_map(fn($v) => round($v / $cnt, 1), $monthShare) : null;

        // Cumulative year-to-date: Jalali year of the period end vs the year before.
        [$ey] = Jalali::parts($e);
        $ytd = $this->cumulativeYear($ey, $e);
        $ytdPrev = $this->cumulativeYear($ey - 1, Jalali::shiftYears($e, -1));

        // Streaks and records
        $activeDays = count($daily);
        $bestDay = null; $worstDay = null;
        foreach ($daily as $d => $v) {
            if ($bestDay === null || $v['net'] > $bestDay['net']) $bestDay = ['date' => $d, 'net' => $v['net'], 'n' => $v['n']];
            if ($worstDay === null || $v['net'] < $worstDay['net']) $worstDay = ['date' => $d, 'net' => $v['net'], 'n' => $v['n']];
        }
        $gap = 0; $gapMax = 0; $gapEnd = null; $streak = 0; $streakMax = 0;
        for ($d = $s; $d <= min($e, $this->today); $d = $this->addDays($d, 1)) {
            if (isset($daily[$d])) { $gap = 0; $streak++; $streakMax = max($streakMax, $streak); }
            else { $streak = 0; $gap++; if ($gap > $gapMax) { $gapMax = $gap; $gapEnd = $d; } }
        }
        $amounts = array_column($inv, 'net');
        sort($amounts);

        return [
            'period' => $this->periodInfo($p),
            'trend' => $this->trend($p),
            'weekday' => $this->weekdayStats($s, $e),
            'day_of_month' => ['values' => array_map('round', array_values($dom)), 'counts' => array_values($domN)],
            'thirds' => array_map(fn($t) => ['label' => $t['label'], 'net' => round($t['net']), 'invoices' => $t['invoices']], $thirds),
            'heatmap' => ['cells' => $cells, 'max' => round($maxDay)],
            'seasonality' => ['years' => $years, 'months' => array_values(Jalali::MONTHS), 'avg_share' => $monthShare],
            'ytd' => ['year' => $ey, 'current' => $ytd, 'previous' => $ytdPrev],
            'records' => [
                'active_days' => $activeDays,
                'total_days' => $p['days'],
                'best_day' => $bestDay ? ['label' => Jalali::longLabel($bestDay['date']), 'net' => round($bestDay['net']), 'invoices' => $bestDay['n']] : null,
                'worst_day' => $worstDay ? ['label' => Jalali::longLabel($worstDay['date']), 'net' => round($worstDay['net']), 'invoices' => $worstDay['n']] : null,
                'longest_gap' => $gapMax,
                'longest_gap_end' => $gapEnd ? Jalali::longLabel($gapEnd) : null,
                'longest_streak' => $streakMax,
                'avg_per_active_day' => $activeDays ? round(array_sum(array_column($daily, 'net')) / $activeDays) : 0,
                'median_invoice' => $this->median($amounts),
            ],
        ];
    }

    /** Cumulative net sales by day-of-year for Jalali year $jy up to $until. */
    private function cumulativeYear(int $jy, string $until): array {
        $start = Jalali::iso($jy, 1, 1);
        $yearEnd = $this->addDays(Jalali::iso($jy + 1, 1, 1), -1);
        $end = min($until, $yearEnd);
        if ($end < $start) return ['labels' => [], 'values' => [], 'total' => 0];
        $byMonth = array_fill(1, 12, 0.0);
        foreach ($this->invoices($start, $end) as $r) { [, $m] = Jalali::parts($r['date']); $byMonth[$m] += $r['net']; }
        foreach ($this->returns($start, $end) as $r) { [, $m] = Jalali::parts($r['date']); $byMonth[$m] -= $r['amount']; }
        [, $lastM] = Jalali::parts($end);
        $cum = 0.0; $vals = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m > $lastM) { $vals[] = null; continue; }
            $cum += $byMonth[$m];
            $vals[] = round($cum);
        }
        return ['values' => $vals, 'total' => round($cum), 'until' => Jalali::longLabel($end)];
    }

    /* =================================================================
     *  Section: products
     * ================================================================= */

    private function filterLines(array $lines, array $f): array {
        $col = (int)($f['collection'] ?? 0);
        $type = $f['ptype'] ?? '';
        if (!$col && !$type) return $lines;
        return array_values(array_filter($lines, function ($l) use ($col, $type) {
            if ($col && $l['collection_id'] !== $col) return false;
            if ($type && $l['product_type'] !== $type) return false;
            return true;
        }));
    }

    private function stockMatchesFilter(array $it, array $f): bool {
        $col = (int)($f['collection'] ?? 0);
        $type = $f['ptype'] ?? '';
        if ($col && (int)$it['collection_id'] !== $col) return false;
        if ($type && $it['product_type'] !== $type) return false;
        return true;
    }

    /** Per-product rows sorted by net revenue, with ABC class. */
    private function productRows(array $lines, array $p): array {
        $by = [];
        foreach ($lines as $l) {
            $id = $l['productId'];
            if (!isset($by[$id])) {
                $by[$id] = ['id' => $id, 'name' => $l['product_name'], 'design_code' => $l['design_code'], 'collection' => $l['collection_name'],
                            'type' => $l['product_type'], 'qty' => 0, 'net' => 0.0, 'gross' => 0.0, 'disc' => 0.0, 'cost' => 0.0, 'profit' => 0.0,
                            'area' => 0.0, 'invoices' => [], 'customers' => [], 'last' => '', 'estimated' => false, 'service' => (bool)$l['is_service']];
            }
            $r = &$by[$id];
            $r['qty'] += $l['qty']; $r['net'] += $l['net']; $r['gross'] += $l['gross']; $r['disc'] += $l['disc'];
            $r['cost'] += $l['cost']; $r['profit'] += $l['profit']; $r['area'] += $l['area'];
            $r['invoices'][$l['invoiceId']] = true; $r['customers'][$l['customerId']] = true;
            if ($l['date'] > $r['last']) $r['last'] = $l['date'];
            if ($l['cost_estimated']) $r['estimated'] = true;
            unset($r);
        }
        // Returned quantities in the same period
        $retQty = [];
        foreach ($this->returns($p['start'], $p['end']) as $ret) {
            foreach ($ret['items'] as $it) $retQty[(int)$it['productId']] = ($retQty[(int)$it['productId']] ?? 0) + (int)$it['quantity'];
        }
        $stock = [];
        foreach ($this->stockItems() as $it) if ($it['status'] !== 'consignment_out') $stock[(int)$it['product_id']] = ($stock[(int)$it['product_id']] ?? 0) + 1;

        usort($by, fn($a, $b) => $b['net'] <=> $a['net']);
        $total = array_sum(array_column($by, 'net'));
        $cum = 0.0;
        $rows = [];
        foreach ($by as $r) {
            $cum += $r['net'];
            $cumPct = $total > 0 ? $cum / $total * 100 : 0;
            $prevCum = $total > 0 ? ($cum - $r['net']) / $total * 100 : 0;
            $rows[] = [
                'id' => $r['id'], 'name' => $r['name'], 'design_code' => $r['design_code'], 'collection' => $r['collection'] ?: '—',
                'type' => $r['type'], 'qty' => $r['qty'], 'net' => round($r['net']), 'cost' => round($r['cost']), 'profit' => round($r['profit']),
                // Goods with no known cost would show a fake 100% margin — report it as unknown instead.
                'margin' => ($r['net'] > 0 && ($r['cost'] > 0 || $r['service'])) ? round($r['profit'] / $r['net'] * 100, 1) : null,
                'cost_unknown' => !$r['service'] && $r['cost'] <= 0,
                'avg_price' => $r['qty'] > 0 ? round($r['net'] / $r['qty']) : null,
                'discount_pct' => $r['gross'] > 0 ? round($r['disc'] / $r['gross'] * 100, 1) : 0,
                'area' => round($r['area'], 2), 'price_m2' => $r['area'] > 0 ? round($r['net'] / $r['area']) : null,
                'invoices' => count($r['invoices']), 'customers' => count($r['customers']),
                'returned' => $retQty[$r['id']] ?? 0,
                'return_rate' => $r['qty'] > 0 ? round(($retQty[$r['id']] ?? 0) / $r['qty'] * 100, 1) : 0,
                'stock' => $stock[$r['id']] ?? 0,
                'last_sale' => Jalali::format($r['last']), 'days_since' => $this->daysBetween($r['last'], $p['asof']),
                'share' => $total > 0 ? round($r['net'] / $total * 100, 2) : 0, 'cum_share' => round($cumPct, 1),
                'abc' => $prevCum < 80 ? 'A' : ($prevCum < 95 ? 'B' : 'C'),
                'estimated' => $r['estimated'], 'service' => $r['service'],
            ];
        }
        return $rows;
    }

    private function groupLines(array $lines, callable $keyFn, callable $labelFn): array {
        $g = [];
        foreach ($lines as $l) {
            $k = $keyFn($l);
            if ($k === null) continue;
            if (!isset($g[$k])) $g[$k] = ['key' => $k, 'label' => $labelFn($l), 'qty' => 0, 'net' => 0.0, 'gross' => 0.0, 'disc' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'area' => 0.0, 'lines' => 0, 'inv' => []];
            $g[$k]['qty'] += $l['qty']; $g[$k]['net'] += $l['net']; $g[$k]['gross'] += $l['gross']; $g[$k]['disc'] += $l['disc'];
            $g[$k]['cost'] += $l['cost']; $g[$k]['profit'] += $l['profit']; $g[$k]['area'] += $l['area']; $g[$k]['lines']++;
            $g[$k]['inv'][$l['invoiceId']] = true;
        }
        $tot = array_sum(array_column($g, 'net'));
        $out = [];
        foreach ($g as $r) {
            $out[] = ['key' => $r['key'], 'label' => $r['label'], 'qty' => $r['qty'], 'net' => round($r['net']), 'cost' => round($r['cost']),
                      'profit' => round($r['profit']), 'margin' => ($r['net'] > 0 && $r['cost'] > 0) ? round($r['profit'] / $r['net'] * 100, 1) : null,
                      'area' => round($r['area'], 2), 'price_m2' => $r['area'] > 0 ? round($r['net'] / $r['area']) : null,
                      'avg_price' => $r['qty'] > 0 ? round($r['net'] / $r['qty']) : null, 'invoices' => count($r['inv']),
                      'discount_pct' => $r['gross'] > 0 ? round($r['disc'] / $r['gross'] * 100, 1) : 0,
                      'share' => $tot > 0 ? round($r['net'] / $tot * 100, 1) : 0];
        }
        usort($out, fn($a, $b) => $b['net'] <=> $a['net']);
        return $out;
    }

    private function products(array $p, array $f): array {
        $lines = $this->filterLines($this->lines($p['start'], $p['end']), $f);
        $goods = array_values(array_filter($lines, fn($l) => !$l['is_service']));
        $rows = $this->productRows($lines, $p);
        $cmpRows = $p['cstart'] ? $this->productRows($this->filterLines($this->lines($p['cstart'], $p['cend']), $f), $p) : [];
        $cmpById = [];
        foreach ($cmpRows as $r) $cmpById[$r['id']] = $r;
        foreach ($rows as &$r) {
            $c = $cmpById[$r['id']] ?? null;
            $r['cmp_net'] = $c['net'] ?? 0;
            $r['growth'] = ($c && $c['net'] > 0) ? round(($r['net'] - $c['net']) / $c['net'] * 100, 1) : null;
        }
        unset($r);

        // ABC summary
        $abc = ['A' => ['count' => 0, 'net' => 0], 'B' => ['count' => 0, 'net' => 0], 'C' => ['count' => 0, 'net' => 0]];
        foreach ($rows as $r) { $abc[$r['abc']]['count']++; $abc[$r['abc']]['net'] += $r['net']; }

        // Collections with stock & sell-through
        $collections = $this->groupLines($goods, fn($l) => $l['collection_id'], fn($l) => $l['collection_name'] ?: 'بدون کلکسیون');
        $stockByCol = [];
        foreach ($this->stockItems() as $it) {
            if ($it['status'] === 'consignment_out') continue;
            $k = $it['collection_id'];
            $stockByCol[$k]['count'] = ($stockByCol[$k]['count'] ?? 0) + 1;
            $stockByCol[$k]['value'] = ($stockByCol[$k]['value'] ?? 0) + $it['cost'];
        }
        $listPpm = [];
        foreach ($goods as $l) if ($l['price_per_meter'] > 0) $listPpm[$l['collection_id']] = (float)$l['price_per_meter'];
        foreach ($collections as &$c) {
            $st = $stockByCol[$c['key']] ?? ['count' => 0, 'value' => 0];
            $c['stock'] = $st['count'];
            $c['stock_value'] = round($st['value']);
            $c['sell_through'] = ($c['qty'] + $st['count']) > 0 ? round($c['qty'] / ($c['qty'] + $st['count']) * 100, 1) : null;
            $c['list_price_m2'] = isset($listPpm[$c['key']]) ? round($listPpm[$c['key']]) : null;
            $c['realization'] = ($c['list_price_m2'] && $c['price_m2']) ? round($c['price_m2'] / $c['list_price_m2'] * 100, 1) : null;
        }
        unset($c);

        $types = $this->groupLines($goods, fn($l) => $l['product_type'] ?: 'unknown',
            fn($l) => ['machine' => 'ماشینی', 'handmade' => 'دستباف'][$l['product_type']] ?? 'نامشخص');
        $sizes = $this->groupLines($goods, fn($l) => $this->normDim($l['dimensions']), fn($l) => $this->normDim($l['dimensions']));
        $sizeStock = [];
        foreach ($this->stockItems() as $it) if ($it['status'] !== 'consignment_out' && $this->stockMatchesFilter($it, $f)) { $k = $it['dim_norm']; $sizeStock[$k] = ($sizeStock[$k] ?? 0) + 1; }
        foreach ($sizes as &$sz) { $sz['stock'] = $sizeStock[$sz['key']] ?? 0; $sz['size_label'] = $this->sizeLabel((string)$sz['key']); }
        unset($sz);

        // Trade size names ("۶ متری", "کناره ۳ متری"…) with current stock of each
        $sizeNames = $this->groupLines($goods, fn($l) => $l['size_label'], fn($l) => $l['size_label']);
        $stockByName = []; $areaByName = [];
        foreach ($this->stockItems() as $it) {
            if ($it['status'] === 'consignment_out' || !$this->stockMatchesFilter($it, $f)) continue;
            $stockByName[$it['size_label']] = ($stockByName[$it['size_label']] ?? 0) + 1;
        }
        foreach ($goods as $l) $areaByName[$l['size_label']] = $l['area_unit'];
        foreach ($sizeNames as &$sn) { $sn['stock'] = $stockByName[$sn['key']] ?? 0; $sn['sort_area'] = round($areaByName[$sn['key']] ?? 0, 2); }
        unset($sn);

        // Product × size: answers "how many 6-meter rugs of design X did we sell?"
        $ps = [];
        foreach ($goods as $l) {
            $key = $l['productId'] . '|' . $l['dim_norm'];
            if (!isset($ps[$key])) $ps[$key] = ['product' => $l['product_name'], 'design_code' => $l['design_code'], 'collection' => $l['collection_name'] ?: '—',
                                                'dims' => $l['dim_norm'], 'size_label' => $l['size_label'], 'area_unit' => round($l['area_unit'], 2),
                                                'qty' => 0, 'area' => 0.0, 'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'last' => ''];
            $ps[$key]['qty'] += $l['qty']; $ps[$key]['area'] += $l['area']; $ps[$key]['net'] += $l['net'];
            $ps[$key]['cost'] += $l['cost']; $ps[$key]['profit'] += $l['profit'];
            if ($l['date'] > $ps[$key]['last']) $ps[$key]['last'] = $l['date'];
        }
        $stockPD = [];
        foreach ($this->stockItems() as $it) {
            if ($it['status'] === 'consignment_out') continue;
            $k2 = (int)$it['product_id'] . '|' . $it['dim_norm'];
            $stockPD[$k2] = ($stockPD[$k2] ?? 0) + 1;
        }
        $productSizes = [];
        foreach ($ps as $key => $x) {
            $productSizes[] = ['product' => $x['product'], 'design_code' => $x['design_code'], 'collection' => $x['collection'], 'dims' => $x['dims'],
                               'size_label' => $x['size_label'], 'area_unit' => $x['area_unit'], 'qty' => $x['qty'], 'area' => round($x['area'], 2),
                               'net' => round($x['net']), 'profit' => round($x['profit']),
                               'margin' => ($x['net'] > 0 && $x['cost'] > 0) ? round($x['profit'] / $x['net'] * 100, 1) : null,
                               'avg_price' => $x['qty'] ? round($x['net'] / $x['qty']) : null,
                               'price_m2' => $x['area'] > 0 ? round($x['net'] / $x['area']) : null,
                               'stock' => $stockPD[$key] ?? 0, 'last_sale' => Jalali::format($x['last'])];
        }
        usort($productSizes, fn($a, $b) => ($b['qty'] <=> $a['qty']) ?: ($b['net'] <=> $a['net']));

        // Size classes by area (small / medium / large rugs)
        $areaClass = $this->groupLines($goods, function ($l) {
            $a = $l['area_unit'];
            if ($a <= 0) return 'z';
            return $a < 2 ? 'a' : ($a < 4 ? 'b' : ($a < 7 ? 'c' : 'd'));
        }, function ($l) {
            $a = $l['area_unit'];
            if ($a <= 0) return 'بدون متراژ';
            return $a < 2 ? 'کوچک (زیر ۲ متر)' : ($a < 4 ? 'متوسط (۲ تا ۴ متر)' : ($a < 7 ? 'بزرگ (۴ تا ۷ متر)' : 'خیلی بزرگ (۷ متر به بالا)'));
        });
        usort($areaClass, fn($a, $b) => strcmp($a['key'], $b['key']));

        // Attribute breakdowns (only where data exists)
        $attrs = [];
        foreach (['colorway' => 'رنگ‌بندی', 'material' => 'جنس', 'shaneh' => 'شانه', 'density' => 'تراکم', 'design_code' => 'کد طرح'] as $col => $label) {
            $withVal = array_filter($goods, fn($l) => trim((string)$l[$col]) !== '' && (string)$l[$col] !== '0');
            if (count($withVal) < 3) continue;
            $attrs[] = ['key' => $col, 'label' => $label, 'coverage' => round(count($withVal) / max(1, count($goods)) * 100),
                        'rows' => array_slice($this->groupLines($withVal, fn($l) => (string)$l[$col], fn($l) => (string)$l[$col]), 0, 15)];
        }

        // Price bands by unit price (quantile-based so bands always hold data)
        $prices = array_map(fn($l) => $l['qty'] > 0 ? $l['net'] / $l['qty'] : 0, $goods);
        sort($prices);
        $bands = [];
        if (count($prices) >= 4) {
            $edges = [];
            foreach ([0.25, 0.5, 0.75] as $q) $edges[] = $this->roundNice($prices[(int)floor($q * (count($prices) - 1))]);
            $edges = array_values(array_unique($edges));
            $bands = $this->groupLines($goods, function ($l) use ($edges) {
                $u = $l['qty'] > 0 ? $l['net'] / $l['qty'] : 0;
                foreach ($edges as $i => $e) if ($u < $e) return $i;
                return count($edges);
            }, function ($l) use ($edges) {
                $u = $l['qty'] > 0 ? $l['net'] / $l['qty'] : 0;
                foreach ($edges as $i => $e) if ($u < $e) return ($i === 0 ? 'زیر ' : $this->faMoney($edges[$i - 1]) . ' تا ') . $this->faMoney($e);
                return 'بالای ' . $this->faMoney(end($edges));
            });
            usort($bands, fn($a, $b) => $a['key'] <=> $b['key']);
        }

        // Market basket: what sells together on the same invoice
        $pairs = $this->basket($goods, 'productId', 'product_name');
        $colPairs = $this->basket($goods, 'collection_id', 'collection_name');

        // Rising and falling products vs comparison period
        $movers = array_values(array_filter($rows, fn($r) => $r['cmp_net'] > 0 || $r['net'] > 0));
        $rising = $movers; usort($rising, fn($a, $b) => ($b['net'] - $b['cmp_net']) <=> ($a['net'] - $a['cmp_net']));
        $falling = $movers; usort($falling, fn($a, $b) => ($a['net'] - $a['cmp_net']) <=> ($b['net'] - $b['cmp_net']));
        $lost = [];
        foreach ($cmpRows as $c) {
            $found = false;
            foreach ($rows as $r) if ($r['id'] === $c['id']) { $found = true; break; }
            if (!$found) $lost[] = ['name' => $c['name'], 'net' => $c['net'], 'qty' => $c['qty']];
        }

        return [
            'period' => $this->periodInfo($p),
            'rows' => $rows,
            'abc' => $abc,
            'pareto' => array_map(fn($r) => ['name' => $r['name'], 'net' => $r['net'], 'cum' => $r['cum_share']], array_slice($rows, 0, 40)),
            'collections' => $collections,
            'types' => $types,
            'sizes' => $sizes,
            'size_names' => $sizeNames,
            'product_sizes' => $productSizes,
            'area_classes' => $areaClass,
            'attributes' => $attrs,
            'price_bands' => $bands,
            'pairs' => $pairs,
            'collection_pairs' => $colPairs,
            'rising' => array_slice(array_filter($rising, fn($r) => $r['net'] > $r['cmp_net']), 0, 8),
            'falling' => array_slice(array_filter($falling, fn($r) => $r['net'] < $r['cmp_net']), 0, 8),
            'not_sold_again' => array_slice($lost, 0, 15),
            'totals' => ['products' => count($rows), 'net' => array_sum(array_column($rows, 'net')), 'qty' => array_sum(array_column($rows, 'qty')), 'profit' => array_sum(array_column($rows, 'profit'))],
        ];
    }

    private function basket(array $lines, string $idField, string $nameField): array {
        $byInv = [];
        $names = [];
        foreach ($lines as $l) {
            $id = $l[$idField];
            if (!$id) continue;
            $byInv[$l['invoiceId']][$id] = true;
            $names[$id] = $l[$nameField] ?: '—';
        }
        $nInv = count($byInv);
        $single = []; $pair = [];
        foreach ($byInv as $items) {
            $ids = array_keys($items);
            sort($ids);
            foreach ($ids as $a) $single[$a] = ($single[$a] ?? 0) + 1;
            $c = count($ids);
            for ($i = 0; $i < $c; $i++) for ($j = $i + 1; $j < $c; $j++) {
                $k = $ids[$i] . '|' . $ids[$j];
                $pair[$k] = ($pair[$k] ?? 0) + 1;
            }
        }
        arsort($pair);
        $out = [];
        foreach ($pair as $k => $n) {
            if ($n < 2) break;
            [$a, $b] = explode('|', $k);
            $lift = ($nInv > 0 && $single[$a] && $single[$b]) ? ($n / $nInv) / (($single[$a] / $nInv) * ($single[$b] / $nInv)) : null;
            $out[] = ['a' => $names[$a], 'b' => $names[$b], 'count' => $n,
                      'confidence' => round($n / min($single[$a], $single[$b]) * 100), 'lift' => $lift !== null ? round($lift, 1) : null];
            if (count($out) >= 12) break;
        }
        $multi = count(array_filter($byInv, fn($x) => count($x) > 1));
        return ['pairs' => $out, 'multi_share' => $nInv ? round($multi / $nInv * 100, 1) : 0];
    }

    /* =================================================================
     *  Section: customers
     * ================================================================= */

    /** Per-customer rows for the period, enriched with lifetime stats & RFM segment. */
    private function customerRows(array $p): array {
        $k = "custRows|{$p['start']}|{$p['end']}";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $life = $this->lifetime($p['end']);
        $by = [];
        foreach ($this->invoices($p['start'], $p['end']) as $r) {
            $id = $r['customerId'];
            if (!isset($by[$id])) $by[$id] = ['id' => $id, 'name' => $r['customer_name'], 'phone' => $r['phone'], 'invoices' => 0, 'net' => 0.0, 'gross' => 0.0, 'disc' => 0.0, 'profit' => 0.0, 'returns' => 0.0];
            $by[$id]['invoices']++; $by[$id]['net'] += $r['net']; $by[$id]['gross'] += $r['gross']; $by[$id]['disc'] += $r['discount'];
        }
        foreach ($this->lines($p['start'], $p['end']) as $l) if (isset($by[$l['customerId']])) $by[$l['customerId']]['profit'] += $l['profit'];
        foreach ($this->returns($p['start'], $p['end']) as $r) {
            $id = (int)$r['person_id'];
            if (isset($by[$id])) { $by[$id]['returns'] += $r['amount']; $by[$id]['net'] -= $r['amount']; $by[$id]['profit'] -= ($r['amount'] - $r['cogs']); }
        }
        $rfm = $this->rfm($p);
        $seg = [];
        foreach ($rfm['customers'] as $c) $seg[$c['id']] = $c['segment'];
        $bal = $this->balances($p['asof']);
        $rows = [];
        foreach ($by as $id => $r) {
            $lf = $life[$id] ?? null;
            $rows[] = [
                'id' => $id, 'name' => $r['name'], 'phone' => $r['phone'] ?? '', 'invoices' => $r['invoices'], 'net' => round($r['net']),
                'profit' => round($r['profit']), 'margin' => $r['net'] > 0 ? round($r['profit'] / $r['net'] * 100, 1) : null,
                'aov' => $r['invoices'] ? round(($r['gross'] - $r['disc']) / $r['invoices']) : 0,
                'discount_pct' => $r['gross'] > 0 ? round($r['disc'] / $r['gross'] * 100, 1) : 0,
                'returns' => round($r['returns']),
                'first' => $lf ? Jalali::format($lf['first']) : '', 'last' => $lf ? Jalali::format($lf['last']) : '',
                'days_since' => $lf ? $this->daysBetween($lf['last'], $p['asof']) : null,
                'lifetime_invoices' => $lf['count'] ?? 0, 'lifetime_net' => round($lf['net'] ?? 0),
                'avg_interval' => $lf['avg_interval'] ?? null,
                'balance' => round($bal[$id] ?? 0),
                'segment' => $seg[$id] ?? '',
                'is_new' => $lf && $lf['first'] >= $p['start'],
            ];
        }
        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);
        $tot = array_sum(array_column($rows, 'net'));
        foreach ($rows as &$r) $r['share'] = $tot > 0 ? round($r['net'] / $tot * 100, 2) : 0;
        unset($r);
        return $this->memo[$k] = $rows;
    }

    /** Lifetime stats per customer up to $until. */
    private function lifetime(string $until): array {
        $k = "life|$until";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $c = [];
        foreach ($this->allInvoices() as $r) {
            if ($r['date'] > $until) break;
            $id = $r['customerId'];
            if (!isset($c[$id])) $c[$id] = ['first' => $r['date'], 'last' => $r['date'], 'count' => 0, 'net' => 0.0, 'dates' => [], 'name' => $r['customer_name'], 'phone' => $r['phone']];
            $c[$id]['last'] = $r['date'];
            $c[$id]['count']++;
            $c[$id]['net'] += $r['net'];
            $c[$id]['dates'][$r['date']] = true;
        }
        foreach ($c as &$x) {
            $d = array_keys($x['dates']);
            $x['visits'] = count($d);
            $x['avg_interval'] = count($d) > 1 ? (int)round($this->daysBetween($d[0], end($d)) / (count($d) - 1)) : null;
            unset($x['dates']);
        }
        unset($x);
        return $this->memo[$k] = $c;
    }

    /** Customer receivable balances as of a date (same formula as ReportModel::getCustomerReceivables). */
    private function balances(string $asOf): array {
        $k = "bal|$asOf";
        if (isset($this->memo[$k])) return $this->memo[$k];
        require_once __DIR__ . '/../../api/models/ReportModel.php';
        $rm = new ReportModel($this->db);
        $res = $rm->getCustomerReceivables($asOf, $this->entityId);
        $out = [];
        foreach ($res['customers'] as $c) $out[(int)$c['id']] = (float)$c['receivable'];
        $this->memo["balRaw|$asOf"] = $res;
        return $this->memo[$k] = $out;
    }

    private const SEGMENTS = [
        'champions' => ['قهرمانان', 'اخیراً و مرتب خرید می‌کنند؛ ارزشمندترین مشتریان', 'good'],
        'loyal' => ['وفادار', 'چند بار خرید کرده‌اند و هنوز فعال‌اند', 'good'],
        'potential' => ['وفادار آینده', 'اخیراً دو سه بار خرید کرده‌اند؛ با پیگیری وفادار می‌شوند', 'good'],
        'new' => ['تازه‌وارد', 'اولین خرید را به‌تازگی انجام داده‌اند', 'info'],
        'need_attention' => ['نیازمند توجه', 'نه خیلی فعال، نه از دست رفته؛ یک تماس کمک می‌کند', 'warn'],
        'about_to_sleep' => ['در آستانه خواب', 'مدتی است خرید نکرده‌اند و خریدشان کم بوده', 'warn'],
        'at_risk' => ['در معرض ریزش', 'قبلاً مرتب خرید می‌کردند اما مدتی است نیامده‌اند', 'bad'],
        'cant_lose' => ['نباید از دست برود', 'بیشترین خرید را داشته‌اند ولی مدت زیادی است غیبت دارند', 'bad'],
        'lost' => ['از دست رفته', 'خیلی وقت است خرید نکرده‌اند و خریدشان کم بوده', 'muted'],
    ];

    /** RFM segmentation of all customers as of the period end. */
    private function rfm(array $p): array {
        $asOf = $p['asof'];
        $k = "rfm|$asOf";
        if (isset($this->memo[$k])) return $this->memo[$k];
        $life = $this->lifetime($asOf);
        $monies = array_map(fn($c) => $c['net'], $life);
        sort($monies);
        $n = count($monies);
        $mScore = function ($v) use ($monies, $n) {
            if ($n === 0) return 1;
            // Binary search: index of the last value <= $v (percentile rank → quintile).
            $lo = 0; $hi = $n - 1; $rank = 0;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($monies[$mid] <= $v) { $rank = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
            }
            return 1 + (int)floor($rank / $n * 5);
        };
        $customers = [];
        foreach ($life as $id => $c) {
            $rec = $this->daysBetween($c['last'], $asOf);
            $r = $rec <= 30 ? 5 : ($rec <= 90 ? 4 : ($rec <= 180 ? 3 : ($rec <= 365 ? 2 : 1)));
            $f = $c['count'] >= 6 ? 5 : ($c['count'] >= 4 ? 4 : ($c['count'] >= 3 ? 3 : ($c['count'] >= 2 ? 2 : 1)));
            $m = min(5, $mScore($c['net']));
            if ($r >= 4 && $f >= 4) $seg = 'champions';
            elseif ($r >= 3 && $f >= 3) $seg = 'loyal';
            elseif ($r >= 4 && $f === 1) $seg = 'new';
            elseif ($r >= 4) $seg = 'potential';
            elseif ($r === 3) $seg = $m >= 4 ? 'loyal' : 'need_attention';
            elseif ($f >= 4 || ($m >= 5 && $f >= 2)) $seg = 'cant_lose';
            elseif ($f >= 2) $seg = 'at_risk';
            elseif ($r === 2) $seg = 'about_to_sleep';
            else $seg = 'lost';
            $customers[] = ['id' => $id, 'name' => $c['name'], 'phone' => $c['phone'] ?? '', 'recency' => $rec, 'frequency' => $c['count'],
                            'monetary' => round($c['net']), 'r' => $r, 'f' => $f, 'm' => $m, 'segment' => $seg,
                            'last' => Jalali::format($c['last']), 'first' => Jalali::format($c['first']), 'avg_interval' => $c['avg_interval']];
        }
        $segs = [];
        foreach (self::SEGMENTS as $key => [$label, $desc, $tone]) $segs[$key] = ['key' => $key, 'label' => $label, 'desc' => $desc, 'tone' => $tone, 'count' => 0, 'monetary' => 0, 'recency_sum' => 0];
        foreach ($customers as $c) { $segs[$c['segment']]['count']++; $segs[$c['segment']]['monetary'] += $c['monetary']; $segs[$c['segment']]['recency_sum'] += $c['recency']; }
        foreach ($segs as &$s) { $s['avg_recency'] = $s['count'] ? round($s['recency_sum'] / $s['count']) : null; unset($s['recency_sum']); }
        unset($s);
        usort($customers, fn($a, $b) => $b['monetary'] <=> $a['monetary']);
        return $this->memo[$k] = ['segments' => array_values($segs), 'customers' => $customers];
    }

    private function customers(array $p): array {
        $rows = $this->customerRows($p);
        $rfm = $this->rfm($p);
        $life = $this->lifetime($p['asof']);
        $cur = $this->summary($p['start'], $p['end']);
        $tot = array_sum(array_column($rows, 'net'));

        // Concentration: Lorenz-style curve and HHI
        $curve = [[0, 0]];
        $cum = 0.0; $n = count($rows); $hhi = 0.0;
        foreach ($rows as $i => $r) {
            $cum += $r['net'];
            if ($tot > 0) {
                $curve[] = [round(($i + 1) / $n * 100, 1), round($cum / $tot * 100, 1)];
                $hhi += ($r['net'] / $tot * 100) ** 2;
            }
        }
        if (count($curve) > 60) {  // thin out the curve for the chart
            $step = count($curve) / 60; $thin = [];
            for ($i = 0; $i < 60; $i++) $thin[] = $curve[(int)floor($i * $step)];
            $thin[] = end($curve); $curve = $thin;
        }
        $topShare = function ($pct) use ($rows, $tot) {
            if ($tot <= 0 || !$rows) return null;
            $k = max(1, (int)ceil(count($rows) * $pct / 100));
            return round(array_sum(array_slice(array_column($rows, 'net'), 0, $k)) / $tot * 100, 1);
        };

        // New vs returning per bucket
        $first = $this->firstPurchaseMap();
        $g = $p['gran'];
        $nv = [];
        foreach ($this->bucketKeys($p['start'], $p['end'], $g) as $k) $nv[$k] = ['new' => [], 'ret' => [], 'new_net' => 0.0, 'ret_net' => 0.0];
        foreach ($this->invoices($p['start'], $p['end']) as $r) {
            $k = $this->bucketKey($r['date'], $g);
            $isNew = $this->bucketKey($first[$r['customerId']], $g) === $k;
            if ($isNew) { $nv[$k]['new'][$r['customerId']] = true; $nv[$k]['new_net'] += $r['net']; }
            else { $nv[$k]['ret'][$r['customerId']] = true; $nv[$k]['ret_net'] += $r['net']; }
        }
        $newRet = ['labels' => [], 'new' => [], 'returning' => [], 'new_net' => [], 'returning_net' => []];
        foreach ($nv as $k => $v) {
            $newRet['labels'][] = $this->bucketLabel($k, $g)[0];
            $newRet['new'][] = count($v['new']); $newRet['returning'][] = count($v['ret']);
            $newRet['new_net'][] = round($v['new_net']); $newRet['returning_net'][] = round($v['ret_net']);
        }

        // Frequency distribution (lifetime)
        $freq = ['۱ بار' => 0, '۲ بار' => 0, '۳ بار' => 0, '۴ تا ۵ بار' => 0, '۶ تا ۱۰ بار' => 0, 'بیش از ۱۰ بار' => 0];
        $freqNet = array_fill_keys(array_keys($freq), 0.0);
        foreach ($life as $c) {
            $n2 = $c['count'];
            $k = $n2 == 1 ? '۱ بار' : ($n2 == 2 ? '۲ بار' : ($n2 == 3 ? '۳ بار' : ($n2 <= 5 ? '۴ تا ۵ بار' : ($n2 <= 10 ? '۶ تا ۱۰ بار' : 'بیش از ۱۰ بار'))));
            $freq[$k]++; $freqNet[$k] += $c['net'];
        }
        $lifeCount = count($life);
        $repeat = count(array_filter($life, fn($c) => $c['count'] > 1));
        $intervals = array_values(array_filter(array_column($life, 'avg_interval'), fn($v) => $v !== null));
        sort($intervals);

        // Cohort retention by Jalali month of first purchase (last 12 cohorts)
        $cohorts = $this->cohorts($p['asof']);

        // Churn risk list: repeat buyers overdue vs their own rhythm
        $risk = [];
        foreach ($rfm['customers'] as $c) {
            if ($c['frequency'] < 2 || !$c['avg_interval']) continue;
            $expected = max(30, $c['avg_interval']);
            if ($c['recency'] > $expected * 1.5) {
                $c['overdue_ratio'] = round($c['recency'] / $expected, 1);
                $c['expected'] = $expected;
                $risk[] = $c;
            }
        }
        usort($risk, fn($a, $b) => $b['monetary'] <=> $a['monetary']);

        // Dormant: bought before but not in this period
        $activeIds = array_flip(array_column($rows, 'id'));
        $dormant = array_values(array_filter($rfm['customers'], fn($c) => !isset($activeIds[$c['id']])));
        $bal = $this->memo["balRaw|{$p['asof']}"] ?? null;

        return [
            'period' => $this->periodInfo($p),
            'summary' => [
                'active' => $cur['customers'], 'new' => $cur['new_customers'], 'returning' => $cur['returning_customers'],
                'sales_per_customer' => $cur['sales_per_customer'],
                'repeat_in_period' => count(array_filter($rows, fn($r) => $r['invoices'] > 1)),
                'lifetime_customers' => $lifeCount,
                'repeat_rate' => $lifeCount ? round($repeat / $lifeCount * 100, 1) : null,
                'avg_ltv' => $lifeCount ? round(array_sum(array_column($life, 'net')) / $lifeCount) : null,
                'median_interval' => $this->median($intervals),
                'top10_share' => $topShare(10), 'top20_share' => $topShare(20),
                'hhi' => round($hhi), 'one_timers' => $freq['۱ بار'],
                'total_receivable' => $bal ? round($bal['totalReceivable']) : null,
                'customers_with_debt' => $bal ? count(array_filter($bal['customers'], fn($c) => $c['receivable'] > 1)) : null,
            ],
            'rows' => $rows,
            'segments' => $rfm['segments'],
            'rfm_customers' => array_slice($rfm['customers'], 0, 400),
            'concentration' => $curve,
            'new_returning' => $newRet,
            'frequency' => ['labels' => array_keys($freq), 'counts' => array_values($freq), 'net' => array_map('round', array_values($freqNet))],
            'cohorts' => $cohorts,
            'churn_risk' => array_slice($risk, 0, 30),
            'dormant' => array_slice($dormant, 0, 30),
            'debtors' => $bal ? array_slice(array_map(fn($c) => ['name' => $c['name'], 'receivable' => round($c['receivable']), 'pending_checks' => round($c['pendingChecks'])],
                                                     array_filter($bal['customers'], fn($c) => $c['receivable'] > 1)), 0, 15) : [],
        ];
    }

    private function cohorts(string $asOf): array {
        $first = []; $active = [];
        foreach ($this->allInvoices() as $r) {
            if ($r['date'] > $asOf) break;
            $mk = $this->bucketKey($r['date'], 'month');
            if (!isset($first[$r['customerId']])) $first[$r['customerId']] = $mk;
            $active[$r['customerId']][$mk] = true;
        }
        $mIndex = function ($k) { [$y, $m] = array_map('intval', explode('-', $k)); return $y * 12 + $m - 1; };
        $groups = [];
        foreach ($first as $cid => $mk) $groups[$mk][] = $cid;
        ksort($groups);
        $groups = array_slice($groups, -12, null, true);
        $nowIdx = $mIndex($this->bucketKey($asOf, 'month'));
        $out = [];
        foreach ($groups as $mk => $ids) {
            $base = $mIndex($mk);
            $cells = [];
            for ($o = 0; $o < 12; $o++) {
                if ($base + $o > $nowIdx) { $cells[] = null; continue; }
                $c = 0;
                foreach ($ids as $cid) {
                    foreach ($active[$cid] as $am => $_) if ($mIndex($am) === $base + $o) { $c++; break; }
                }
                $cells[] = round($c / count($ids) * 100);
            }
            // Ever came back after the first month?
            $back = 0;
            foreach ($ids as $cid) foreach ($active[$cid] as $am => $_) if ($mIndex($am) > $base) { $back++; break; }
            [$y, $m] = array_map('intval', explode('-', $mk));
            $out[] = ['label' => Jalali::monthLabel($y, $m), 'size' => count($ids), 'cells' => $cells, 'came_back' => round($back / count($ids) * 100)];
        }
        return $out;
    }

    /* =================================================================
     *  Section: collections & cash
     * ================================================================= */

    private function collectionsSection(array $p): array {
        $s = $p['start']; $e = $p['end'];
        $cur = $this->summary($s, $e);
        $rc = $this->receipts($s, $e);
        $g = $p['gran'];

        // Payment method mix and days-to-collect
        $mix = ['cash' => 0.0, 'check' => 0.0, 'endorse_check' => 0.0];
        $wDays = 0.0; $wAmt = 0.0; $wCashDays = 0.0; $wCashAmt = 0.0; $unlinked = 0.0;
        foreach ($rc as $r) {
            if ($r['transaction_type'] !== 'receipt') continue;
            $mix[$r['type']] = ($mix[$r['type']] ?? 0) + $r['amount'];
            if ($r['inv_date']) {
                $d = max(0, $this->daysBetween($r['inv_date'], $r['date']));
                $wDays += $d * $r['amount']; $wAmt += $r['amount'];
                // Money is really in hand at the check's due date
                $cashDate = ($r['type'] === 'check' && $r['check_due']) ? max($r['date'], $r['check_due']) : $r['date'];
                $wCashDays += max(0, $this->daysBetween($r['inv_date'], $cashDate)) * $r['amount']; $wCashAmt += $r['amount'];
            } else {
                $unlinked += $r['amount'];
            }
        }
        // Invoices of the period: paid status and same-day collection
        $inv = $this->invoices($s, $e);
        $status = ['paid' => ['n' => 0, 'amt' => 0.0], 'partial' => ['n' => 0, 'amt' => 0.0], 'unpaid' => ['n' => 0, 'amt' => 0.0]];
        $remaining = 0.0;
        foreach ($inv as $r) {
            $due = $r['net'] + $r['tax'];
            $rem = $due - $r['paid'];
            $st = $rem <= 1 ? 'paid' : ($r['paid'] > 1 ? 'partial' : 'unpaid');
            $status[$st]['n']++; $status[$st]['amt'] += $due;
            if ($rem > 1) $remaining += $rem;
        }
        $ids = array_column($inv, 'id');
        $sameDay = 0.0;
        if ($ids) {
            $invDate = array_column($inv, 'date', 'id');
            $all = $this->receipts($s, max($e, $this->today));
            foreach ($all as $r) {
                if ($r['transaction_type'] === 'receipt' && $r['invoiceId'] && isset($invDate[$r['invoiceId']]) && $r['date'] === $invDate[$r['invoiceId']] && $r['type'] === 'cash') $sameDay += $r['amount'];
            }
        }
        $netInv = array_sum(array_column($inv, 'net'));

        // Sales vs collections trend
        $tr = $this->trend($p);

        // Aging (as of today, uses the app's configured buckets)
        require_once __DIR__ . '/../../api/models/ReportModel.php';
        $rm = new ReportModel($this->db);
        $aging = $rm->getAgedReceivablesReport(['personType' => 'customer']);
        $agingLabels = [];
        foreach (array_keys($aging['summary']) as $key) {
            if ($key === 'current') $agingLabels[] = 'سررسید نشده';
            elseif (preg_match('/^(\d+)_(\d+)$/', $key, $mm)) $agingLabels[] = $this->faNum((int)$mm[1]) . ' تا ' . $this->faNum((int)$mm[2]) . ' روز';
            elseif (preg_match('/^over_(\d+)$/', $key, $mm)) $agingLabels[] = 'بیش از ' . $this->faNum((int)$mm[1]) . ' روز';
            else $agingLabels[] = $key;
        }
        $persons = $aging['persons'];
        usort($persons, fn($a, $b) => $b['total'] <=> $a['total']);
        $oldest = [];
        foreach ($aging['persons'] as $per) foreach ($per['invoices'] as $iv) $oldest[] = ['customer' => $per['person_name'], 'invoice' => $iv['id'], 'date' => Jalali::format($iv['date']), 'days' => (int)$iv['daysOld'], 'remaining' => round($iv['remaining'])];
        usort($oldest, fn($a, $b) => $b['days'] <=> $a['days']);

        // DSO: receivable ÷ average daily net sales over the last 90 days
        $bal = $this->balances($this->today);
        $totalAR = array_sum(array_filter($bal, fn($v) => $v > 0));
        $s90 = $this->summary($this->addDays($this->today, -89), $this->today);
        $dso = $s90['net_sales'] > 0 ? round($totalAR / ($s90['net_sales'] / 90)) : null;

        // Checks received from customers
        $checks = $this->rows(
            "SELECT ch.id, ch.status, ch.amount, ch.dueDate, COALESCE(NULLIF(ch.bankName,''),'نامشخص') AS bank,
                    COALESCE(NULLIF(ch.issuerName,''),'') AS issuer, ch.cashed_date, MIN(p.date) AS received_date
             FROM checks ch LEFT JOIN payments p ON p.checkId = ch.id
             WHERE ch.entity_id = ? AND ch.type = 'received'
             GROUP BY ch.id", 'i', [$this->entityId]);
        $statusLabels = ['in_hand' => 'در دست', 'endorsed' => 'خرج‌شده (واگذار)', 'cashed' => 'وصول‌شده', 'bounced' => 'برگشتی', 'payable' => 'پرداختنی'];
        $chStatus = []; $inHand = 0.0; $inHandN = 0; $overdue = ['n' => 0, 'amt' => 0.0]; $byBank = []; $byIssuer = []; $maturity = [];
        $tenorW = 0.0; $tenorA = 0.0; $periodChecks = ['n' => 0, 'amt' => 0.0, 'bounced_n' => 0, 'bounced_amt' => 0.0];
        [$ty, $tm] = Jalali::parts($this->today);
        for ($i = 0; $i < 6; $i++) {
            $mm = $tm + $i; $yy = $ty + intdiv($mm - 1, 12); $mm = ($mm - 1) % 12 + 1;
            $maturity[sprintf('%04d-%02d', $yy, $mm)] = ['label' => Jalali::monthLabel($yy, $mm), 'amount' => 0.0, 'count' => 0];
        }
        $later = ['amount' => 0.0, 'count' => 0];
        foreach ($checks as $c) {
            $amt = (float)$c['amount'];
            $st = $c['status'];
            $chStatus[$st]['label'] = $statusLabels[$st] ?? $st;
            $chStatus[$st]['n'] = ($chStatus[$st]['n'] ?? 0) + 1;
            $chStatus[$st]['amt'] = ($chStatus[$st]['amt'] ?? 0) + $amt;
            if ($c['received_date'] && $c['received_date'] >= $s && $c['received_date'] <= $e) {
                $periodChecks['n']++; $periodChecks['amt'] += $amt;
                if ($st === 'bounced') { $periodChecks['bounced_n']++; $periodChecks['bounced_amt'] += $amt; }
                if ($c['dueDate']) { $tenorW += max(0, $this->daysBetween($c['received_date'], $c['dueDate'])) * $amt; $tenorA += $amt; }
            }
            if ($st === 'in_hand') {
                $inHand += $amt; $inHandN++;
                $byBank[$c['bank']] = ($byBank[$c['bank']] ?? 0) + $amt;
                if ($c['issuer'] !== '') $byIssuer[$c['issuer']] = ($byIssuer[$c['issuer']] ?? 0) + $amt;
                if ($c['dueDate'] && $c['dueDate'] < $this->today) { $overdue['n']++; $overdue['amt'] += $amt; }
                elseif ($c['dueDate']) {
                    $mk = $this->bucketKey($c['dueDate'], 'month');
                    if (isset($maturity[$mk])) { $maturity[$mk]['amount'] += $amt; $maturity[$mk]['count']++; }
                    else { $later['amount'] += $amt; $later['count']++; }
                }
            }
        }
        arsort($byBank); arsort($byIssuer);
        $bankRows = []; foreach ($byBank as $b => $v) $bankRows[] = ['label' => $b, 'amount' => round($v), 'share' => $inHand > 0 ? round($v / $inHand * 100, 1) : 0];
        $issuerRows = []; foreach (array_slice($byIssuer, 0, 10, true) as $b => $v) $issuerRows[] = ['label' => $b, 'amount' => round($v), 'share' => $inHand > 0 ? round($v / $inHand * 100, 1) : 0];
        $allChecks = array_sum(array_column($chStatus, 'n'));
        $bounced = $chStatus['bounced']['n'] ?? 0;

        // Online payment gateway
        $online = [];
        try {
            $online = $this->rows("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM online_payments
                                   WHERE entity_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY status", 'iss', [$this->entityId, $s, $e]);
        } catch (Throwable $ex) { $online = []; }

        // Installments
        $inst = ['overdue_n' => 0, 'overdue_amt' => 0.0, 'upcoming_n' => 0, 'upcoming_amt' => 0.0];
        try {
            $ir = $this->rows("SELECT due_date, amount - COALESCE(paid_amount,0) AS rem FROM installments
                               WHERE entity_id = ? AND invoice_type = 'sales' AND status <> 'paid'", 'i', [$this->entityId]);
            foreach ($ir as $x) {
                if ((float)$x['rem'] <= 0) continue;
                if ($x['due_date'] < $this->today) { $inst['overdue_n']++; $inst['overdue_amt'] += (float)$x['rem']; }
                else { $inst['upcoming_n']++; $inst['upcoming_amt'] += (float)$x['rem']; }
            }
        } catch (Throwable $ex) {}

        $receiptsTotal = array_sum($mix);
        return [
            'period' => $this->periodInfo($p),
            'summary' => [
                'receipts' => round($cur['receipts']), 'refunds' => round($cur['refunds']),
                'collection_ratio' => $cur['collection_ratio'],
                'avg_days_to_pay' => $wAmt > 0 ? round($wDays / $wAmt) : null,
                'avg_days_to_cash' => $wCashAmt > 0 ? round($wCashDays / $wCashAmt) : null,
                'same_day_cash_share' => $netInv > 0 ? round($sameDay / $netInv * 100, 1) : null,
                'period_remaining' => round($remaining),
                'unlinked_receipts' => round($unlinked),
                'total_receivable' => round($totalAR),
                'dso' => $dso,
                'checks_in_hand' => round($inHand), 'checks_in_hand_n' => $inHandN,
                'checks_overdue' => round($overdue['amt']), 'checks_overdue_n' => $overdue['n'],
                'bounce_rate' => $allChecks ? round($bounced / $allChecks * 100, 1) : null,
                'avg_check_tenor' => $tenorA > 0 ? round($tenorW / $tenorA) : null,
                'period_checks' => $periodChecks,
            ],
            'mix' => [
                ['label' => 'نقد / واریز', 'amount' => round($mix['cash']), 'share' => $receiptsTotal > 0 ? round($mix['cash'] / $receiptsTotal * 100, 1) : 0],
                ['label' => 'چک', 'amount' => round($mix['check']), 'share' => $receiptsTotal > 0 ? round($mix['check'] / $receiptsTotal * 100, 1) : 0],
                ['label' => 'چک واگذاری', 'amount' => round($mix['endorse_check'] ?? 0), 'share' => $receiptsTotal > 0 ? round(($mix['endorse_check'] ?? 0) / $receiptsTotal * 100, 1) : 0],
                ['label' => 'تهاتر', 'amount' => round($mix['setoff'] ?? 0), 'share' => $receiptsTotal > 0 ? round(($mix['setoff'] ?? 0) / $receiptsTotal * 100, 1) : 0],
            ],
            'invoice_status' => [
                ['key' => 'paid', 'label' => 'تسویه کامل', 'n' => $status['paid']['n'], 'amount' => round($status['paid']['amt'])],
                ['key' => 'partial', 'label' => 'بخشی پرداخت‌شده', 'n' => $status['partial']['n'], 'amount' => round($status['partial']['amt'])],
                ['key' => 'unpaid', 'label' => 'پرداخت‌نشده', 'n' => $status['unpaid']['n'], 'amount' => round($status['unpaid']['amt'])],
            ],
            'trend' => ['labels' => $tr['labels'], 'titles' => $tr['titles'], 'sales' => $tr['net'], 'receipts' => $tr['receipts']],
            'aging' => ['labels' => $agingLabels, 'values' => array_map('round', array_values($aging['summary'])), 'total' => round($aging['totalOutstanding'])],
            'aging_persons' => array_slice(array_map(function ($x) use ($aging) {
                $vals = [];
                foreach (array_keys($aging['summary']) as $key) $vals[] = round($x[$key]);
                return ['name' => $x['person_name'], 'values' => $vals, 'total' => round($x['total']), 'invoices' => count($x['invoices'])];
            }, $persons), 0, 20),
            'oldest_invoices' => array_slice($oldest, 0, 15),
            'checks' => [
                'status' => array_values(array_map(fn($x) => ['label' => $x['label'], 'n' => $x['n'], 'amount' => round($x['amt'])], $chStatus)),
                'maturity' => array_values(array_map(fn($x) => ['label' => $x['label'], 'amount' => round($x['amount']), 'count' => $x['count']], $maturity)),
                'later' => ['amount' => round($later['amount']), 'count' => $later['count']],
                'banks' => $bankRows, 'issuers' => $issuerRows,
            ],
            'online' => array_map(fn($o) => ['status' => $o['status'], 'n' => (int)$o['n'], 'amount' => round((float)$o['amt'])], $online),
            'installments' => array_map(fn($v) => is_float($v) ? round($v) : $v, $inst),
        ];
    }

    /* =================================================================
     *  Section: profitability
     * ================================================================= */

    private function profitability(array $p): array {
        $s = $p['start']; $e = $p['end'];
        $cur = $this->summary($s, $e);
        $cmp = $this->summary($p['cstart'], $p['cend']);
        $tr = $this->trend($p);
        $g = $p['gran'];

        // Expenses per bucket and by category
        $expB = array_fill_keys($this->bucketKeys($s, $e, $g), 0.0);
        $cats = [];
        foreach ($this->expenses($s, $e) as $x) {
            $expB[$this->bucketKey($x['date'], $g)] += $x['amount'];
            $cats[$x['category']] = ($cats[$x['category']] ?? 0) + $x['amount'];
        }
        arsort($cats);
        $cmpCats = [];
        if ($p['cstart']) foreach ($this->expenses($p['cstart'], $p['cend']) as $x) $cmpCats[$x['category']] = ($cmpCats[$x['category']] ?? 0) + $x['amount'];
        $catRows = [];
        foreach ($cats as $c => $v) {
            $catRows[] = ['label' => $c, 'amount' => round($v), 'of_sales' => $cur['net_sales'] > 0 ? round($v / $cur['net_sales'] * 100, 2) : null,
                          'per_invoice' => $cur['invoices'] ? round($v / $cur['invoices']) : null,
                          'cmp' => round($cmpCats[$c] ?? 0), 'delta' => $this->delta($v, $cmpCats[$c] ?? null)];
        }
        $opProfit = [];
        $i = 0;
        foreach ($expB as $k => $v) {
            $opProfit[] = round(($tr['profit'][$i] ?? 0) - $v);
            $i++;
        }

        // Break-even: monthly fixed cost ÷ gross margin
        $months = max(1, $p['days'] / 30.44);
        $monthlyExp = $cur['expenses'] / $months;
        $monthlySales = $cur['net_sales'] / $months;
        $gm = $cur['gross_margin'];
        $breakEven = ($gm && $gm > 0) ? $monthlyExp / ($gm / 100) : null;

        // Discount analysis per invoice
        $inv = $this->invoices($s, $e);
        $profitByInv = [];
        foreach ($this->lines($s, $e) as $l) { $profitByInv[$l['invoiceId']]['p'] = ($profitByInv[$l['invoiceId']]['p'] ?? 0) + $l['profit']; $profitByInv[$l['invoiceId']]['n'] = ($profitByInv[$l['invoiceId']]['n'] ?? 0) + $l['net']; }
        $dBands = [['label' => 'بدون تخفیف', 'max' => 0.0001], ['label' => 'تا ۲٪', 'max' => 2], ['label' => '۲ تا ۵٪', 'max' => 5], ['label' => '۵ تا ۱۰٪', 'max' => 10], ['label' => 'بیش از ۱۰٪', 'max' => INF]];
        $db = [];
        foreach ($dBands as $b) $db[] = ['label' => $b['label'], 'n' => 0, 'net' => 0.0, 'profit' => 0.0, 'disc' => 0.0];
        $withDisc = 0; $discPctSum = 0.0;
        foreach ($inv as $r) {
            $pct = $r['gross'] > 0 ? $r['discount'] / $r['gross'] * 100 : 0;
            foreach ($dBands as $bi => $b) if ($pct < $b['max'] || $bi === count($dBands) - 1) { break; }
            $db[$bi]['n']++; $db[$bi]['net'] += $r['net']; $db[$bi]['disc'] += $r['discount'];
            $db[$bi]['profit'] += $profitByInv[$r['id']]['p'] ?? 0;
            if ($r['discount'] > 0) { $withDisc++; $discPctSum += $pct; }
        }
        foreach ($db as &$b) { $b['margin'] = $b['net'] > 0 ? round($b['profit'] / $b['net'] * 100, 1) : null; $b['net'] = round($b['net']); $b['profit'] = round($b['profit']); $b['disc'] = round($b['disc']); }
        unset($b);

        // Loss-making & thin-margin lines
        $lossLines = [];
        foreach ($this->lines($s, $e) as $l) {
            if ($l['is_service'] || $l['cost'] <= 0) continue;
            $m = $l['net'] > 0 ? $l['profit'] / $l['net'] * 100 : -100;
            if ($m < 5) $lossLines[] = ['date' => Jalali::format($l['date']), 'invoice' => $l['invoiceId'], 'product' => $l['product_name'], 'dimensions' => $l['dimensions'],
                                        'net' => round($l['net']), 'cost' => round($l['cost']), 'profit' => round($l['profit']), 'margin' => round($m, 1), 'estimated' => $l['cost_estimated']];
        }
        usort($lossLines, fn($a, $b) => $a['profit'] <=> $b['profit']);

        // Customer profitability extremes
        $custRows = array_values(array_filter($this->customerRows($p), fn($r) => $r['net'] > 0));
        $byMargin = $custRows; usort($byMargin, fn($a, $b) => ($a['margin'] ?? 0) <=> ($b['margin'] ?? 0));

        // Data quality signals that affect profit numbers
        $lines = $this->lines($s, $e);
        $unlinked = array_filter($lines, fn($l) => $l['cost_estimated']);
        $zeroCost = array_filter($lines, fn($l) => !$l['is_service'] && $l['cost'] <= 0);

        return [
            'period' => $this->periodInfo($p),
            'summary' => $cur, 'compare_summary' => $cmp,
            'trend' => ['labels' => $tr['labels'], 'titles' => $tr['titles'], 'net' => $tr['net'], 'profit' => $tr['profit'], 'margin' => $tr['margin'],
                        'expenses' => array_map('round', array_values($expB)), 'operating' => $opProfit, 'cmp_profit' => $tr['cmp_profit']],
            'expense_categories' => $catRows,
            'break_even' => [
                'monthly_expenses' => round($monthlyExp), 'monthly_sales' => round($monthlySales), 'break_even_sales' => $breakEven !== null ? round($breakEven) : null,
                'safety_margin' => ($breakEven && $monthlySales > 0) ? round(($monthlySales - $breakEven) / $monthlySales * 100, 1) : null,
            ],
            'discount' => [
                'bands' => $db, 'invoices_with_discount' => $withDisc, 'share_with_discount' => count($inv) ? round($withDisc / count($inv) * 100, 1) : 0,
                'avg_when_given' => $withDisc ? round($discPctSum / $withDisc, 1) : 0, 'total' => round($cur['discounts']),
                'profit_if_no_discount' => round($cur['gross_profit'] + $cur['discounts']),
            ],
            'loss_lines' => array_slice($lossLines, 0, 40),
            'loss_count' => count(array_filter($lossLines, fn($x) => $x['profit'] < 0)),
            'low_margin_customers' => array_slice($byMargin, 0, 10),
            'high_margin_customers' => array_slice(array_reverse($byMargin), 0, 10),
            'quality' => [
                'estimated_lines' => count($unlinked), 'estimated_value' => round(array_sum(array_column($unlinked, 'net'))),
                'zero_cost_lines' => count($zeroCost), 'zero_cost_value' => round(array_sum(array_column($zeroCost, 'net'))),
                'return_cogs' => round($cur['return_cogs']),
            ],
        ];
    }

    /* =================================================================
     *  Section: inventory
     * ================================================================= */

    private function deadStock(): array {
        if (isset($this->memo['dead'])) return $this->memo['dead'];
        $since = $this->addDays($this->today, -180);
        $sold = [];
        foreach ($this->lines($since, $this->today) as $l) $sold[$l['productId']] = true;
        $by = [];
        $count = 0; $value = 0.0;
        foreach ($this->stockItems() as $it) {
            if ($it['status'] === 'consignment_out' || $it['age_days'] < 180 || isset($sold[(int)$it['product_id']])) continue;
            $count++; $value += $it['cost'];
            $id = (int)$it['product_id'];
            if (!isset($by[$id])) $by[$id] = ['name' => $it['product_name'], 'collection' => $it['collection_name'] ?: '—', 'count' => 0, 'value' => 0.0, 'oldest' => 0, 'sizes' => []];
            $by[$id]['count']++; $by[$id]['value'] += $it['cost']; $by[$id]['oldest'] = max($by[$id]['oldest'], $it['age_days']);
            $by[$id]['sizes'][$it['dimensions']] = true;
        }
        $rows = array_values($by);
        foreach ($rows as &$r) { $r['value'] = round($r['value']); $r['sizes'] = implode('، ', array_keys($r['sizes'])); }
        unset($r);
        usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
        return $this->memo['dead'] = ['count' => $count, 'value' => round($value), 'rows' => $rows];
    }

    private function inventory(array $p): array {
        $items = $this->stockItems();
        $inStock = array_values(array_filter($items, fn($i) => $i['status'] !== 'consignment_out'));
        $consOut = array_values(array_filter($items, fn($i) => $i['status'] === 'consignment_out'));
        $value = array_sum(array_column($inStock, 'cost'));
        $consIn = array_filter($inStock, fn($i) => $i['consignment_in']);
        $ownValue = $value - array_sum(array_column($consIn, 'cost'));

        // Velocity: COGS over the last 90 days
        $l90 = $this->lines($this->addDays($this->today, -89), $this->today);
        $cogs90 = array_sum(array_column($l90, 'cost'));
        $dailyCogs = $cogs90 / 90;
        $coverage = $dailyCogs > 0 ? round($ownValue / $dailyCogs) : null;
        $turnover = $ownValue > 0 ? round($dailyCogs * 365 / $ownValue, 2) : null;

        // Aging buckets
        $ab = [['label' => 'تا ۳۰ روز', 'max' => 30], ['label' => '۳۱ تا ۹۰ روز', 'max' => 90], ['label' => '۹۱ تا ۱۸۰ روز', 'max' => 180], ['label' => '۱۸۱ تا ۳۶۵ روز', 'max' => 365], ['label' => 'بیش از یک سال', 'max' => PHP_INT_MAX]];
        $aging = array_map(fn($b) => ['label' => $b['label'], 'count' => 0, 'value' => 0.0], $ab);
        $ageSum = 0;
        foreach ($inStock as $it) {
            foreach ($ab as $i => $b) if ($it['age_days'] <= $b['max']) break;
            $aging[$i]['count']++; $aging[$i]['value'] += $it['cost'];
            $ageSum += $it['age_days'];
        }
        foreach ($aging as &$a) $a['value'] = round($a['value']);
        unset($a);

        // By collection: stock vs 90-day sales velocity
        $sold90 = [];
        foreach ($l90 as $l) if (!$l['is_service']) $sold90[$l['collection_id']] = ($sold90[$l['collection_id']] ?? 0) + $l['qty'];
        $col = [];
        foreach ($inStock as $it) {
            $k = $it['collection_id'];
            if (!isset($col[$k])) $col[$k] = ['label' => $it['collection_name'] ?: 'بدون کلکسیون', 'count' => 0, 'value' => 0.0, 'age' => 0, 'area' => 0.0];
            $col[$k]['count']++; $col[$k]['value'] += $it['cost']; $col[$k]['age'] += $it['age_days']; $col[$k]['area'] += $it['area'];
        }
        $colRows = [];
        foreach ($col as $k => $c) {
            $s90 = $sold90[$k] ?? 0;
            $colRows[] = ['label' => $c['label'], 'count' => $c['count'], 'value' => round($c['value']), 'avg_age' => round($c['age'] / $c['count']),
                          'area' => round($c['area'], 1), 'sold_90' => $s90,
                          'months_of_stock' => $s90 > 0 ? round($c['count'] / ($s90 / 3), 1) : null,
                          'share' => $value > 0 ? round($c['value'] / $value * 100, 1) : 0];
        }
        usort($colRows, fn($a, $b) => $b['value'] <=> $a['value']);

        // Sizes: what is in stock vs what sells (selected period)
        $soldSize = [];
        foreach ($this->lines($p['start'], $p['end']) as $l) if (!$l['is_service']) { $k = $this->normDim($l['dimensions']); $soldSize[$k] = ($soldSize[$k] ?? 0) + $l['qty']; }
        $stockSize = [];
        foreach ($inStock as $it) { $k = $this->normDim($it['dimensions']); $stockSize[$k] = ($stockSize[$k] ?? 0) + 1; }
        $sizeRows = [];
        foreach (array_unique(array_merge(array_keys($soldSize), array_keys($stockSize))) as $k) {
            $sold = $soldSize[$k] ?? 0; $st = $stockSize[$k] ?? 0;
            $sizeRows[] = ['label' => $k, 'sold' => $sold, 'stock' => $st, 'ratio' => $st > 0 ? round($sold / $st, 2) : null,
                           'signal' => $st === 0 && $sold > 0 ? 'shortage' : (($sold === 0 && $st >= 3) ? 'excess' : ($st > 0 && $sold / $st >= 1.5 ? 'low' : 'ok'))];
        }
        usort($sizeRows, fn($a, $b) => ($b['sold'] + $b['stock']) <=> ($a['sold'] + $a['stock']));

        // Warehouses
        $wh = [];
        foreach ($inStock as $it) { $k = $it['warehouse_name'] ?: 'نامشخص'; $wh[$k]['count'] = ($wh[$k]['count'] ?? 0) + 1; $wh[$k]['value'] = ($wh[$k]['value'] ?? 0) + $it['cost']; }
        $whRows = [];
        foreach ($wh as $k => $v) $whRows[] = ['label' => $k, 'count' => $v['count'], 'value' => round($v['value'])];
        usort($whRows, fn($a, $b) => $b['value'] <=> $a['value']);

        // Reorder alerts
        $reorder = $this->rows(
            "SELECT p.id, p.name, COALESCE(p.reorder_point,0) rp, COALESCE(p.min_stock,0) ms,
                    (SELECT COUNT(*) FROM inventory_items ii WHERE ii.product_id = p.id AND ii.status IN ('in_stock','returned')) AS stock
             FROM products p WHERE p.entity_id = ? AND COALESCE(p.is_active,1) = 1 AND (COALESCE(p.reorder_point,0) > 0 OR COALESCE(p.min_stock,0) > 0)",
            'i', [$this->entityId]);
        $reorder = array_values(array_filter(array_map(fn($r) => ['name' => $r['name'], 'stock' => (int)$r['stock'], 'threshold' => max((int)$r['rp'], (int)$r['ms'])], $reorder), fn($r) => $r['stock'] <= $r['threshold']));

        $costSources = ['purchase' => 0, 'cost_price' => 0, 'last_purchase' => 0, 'unknown' => 0];
        foreach ($inStock as $it) $costSources[$it['cost_source']]++;

        return [
            'period' => $this->periodInfo($p),
            'summary' => [
                'count' => count($inStock), 'value' => round($value), 'own_value' => round($ownValue),
                'consignment_in_count' => count($consIn), 'consignment_in_value' => round(array_sum(array_column($consIn, 'cost'))),
                'consignment_out_count' => count($consOut), 'consignment_out_value' => round(array_sum(array_column($consOut, 'cost'))),
                'avg_age' => count($inStock) ? round($ageSum / count($inStock)) : null,
                'coverage_days' => $coverage, 'turnover' => $turnover, 'cogs_90' => round($cogs90),
                'area' => round(array_sum(array_column($inStock, 'area')), 1),
                'products' => count(array_unique(array_column($inStock, 'product_id'))),
            ],
            'aging' => $aging,
            'collections' => $colRows,
            'sizes' => array_slice($sizeRows, 0, 25),
            'warehouses' => $whRows,
            'dead' => $this->deadStock(),
            'reorder' => $reorder,
            'cost_sources' => $costSources,
        ];
    }

    /* =================================================================
     *  Section: operations & data health
     * ================================================================= */

    private function operations(array $p): array {
        $s = $p['start']; $e = $p['end'];
        $inv = $this->invoices($s, $e);
        $lines = $this->lines($s, $e);

        // Delivery status — pending ones are listed regardless of period
        $dLabels = ['preparing' => 'در حال آماده‌سازی', 'ready' => 'آماده تحویل', 'delivered' => 'تحویل‌شده'];
        $delivery = [];
        foreach ($inv as $r) {
            $k = $r['delivery_status'] ?: 'delivered';
            $delivery[$k]['label'] = $dLabels[$k] ?? $k;
            $delivery[$k]['n'] = ($delivery[$k]['n'] ?? 0) + 1;
            $delivery[$k]['amount'] = ($delivery[$k]['amount'] ?? 0) + $r['net'];
        }
        $pending = [];
        foreach ($this->allInvoices() as $r) {
            if (!in_array($r['delivery_status'], ['preparing', 'ready'], true)) continue;
            $pending[] = ['id' => $r['id'], 'customer' => $r['customer_name'], 'date' => Jalali::format($r['date']), 'status' => $dLabels[$r['delivery_status']],
                          'days' => $this->daysBetween($r['date'], $this->today), 'net' => round($r['net'])];
        }
        usort($pending, fn($a, $b) => $b['days'] <=> $a['days']);

        // Invoice size distribution (log-ish nice edges)
        $amounts = array_column($inv, 'net');
        sort($amounts);
        $hist = [];
        if ($amounts) {
            $edges = [];
            foreach ([0.2, 0.4, 0.6, 0.8] as $q) $edges[] = $this->roundNice($amounts[(int)floor($q * (count($amounts) - 1))]);
            $edges = array_values(array_unique(array_filter($edges, fn($x) => $x > 0)));
            $bins = [];
            for ($i = 0; $i <= count($edges); $i++) $bins[$i] = ['n' => 0, 'net' => 0.0];
            foreach ($amounts as $a) {
                $i = 0;
                while ($i < count($edges) && $a >= $edges[$i]) $i++;
                $bins[$i]['n']++; $bins[$i]['net'] += $a;
            }
            foreach ($bins as $i => $b) {
                $label = $i === 0 ? 'زیر ' . $this->faMoney($edges[0] ?? 0) : ($i === count($edges) ? 'بالای ' . $this->faMoney($edges[$i - 1]) : $this->faMoney($edges[$i - 1]) . ' تا ' . $this->faMoney($edges[$i]));
                $hist[] = ['label' => $label, 'n' => $b['n'], 'net' => round($b['net'])];
            }
        }
        // Items per invoice
        $perInv = [];
        foreach ($lines as $l) if (!$l['is_service']) $perInv[$l['invoiceId']] = ($perInv[$l['invoiceId']] ?? 0) + $l['qty'];
        $ipi = ['۱ قلم' => 0, '۲ قلم' => 0, '۳ قلم' => 0, '۴ تا ۵ قلم' => 0, 'بیش از ۵ قلم' => 0];
        foreach ($inv as $r) {
            $n = $perInv[$r['id']] ?? 0;
            $k = $n <= 1 ? '۱ قلم' : ($n == 2 ? '۲ قلم' : ($n == 3 ? '۳ قلم' : ($n <= 5 ? '۴ تا ۵ قلم' : 'بیش از ۵ قلم')));
            $ipi[$k]++;
        }

        // Quotations funnel
        $quotes = ['total' => 0, 'converted' => 0, 'pending' => 0, 'cancelled' => 0, 'expired' => 0, 'value' => 0.0, 'converted_value' => 0.0, 'days_to_convert' => null];
        try {
            $q = $this->rows("SELECT q.status, (q.totalAmount - COALESCE(q.discount,0)) AS amt, q.date, si.date AS inv_date
                              FROM quotations q LEFT JOIN sales_invoices si ON si.id = q.converted_invoice_id
                              WHERE q.entity_id = ? AND q.date BETWEEN ? AND ?", 'iss', [$this->entityId, $s, $e]);
            $dsum = 0; $dn = 0;
            foreach ($q as $x) {
                $quotes['total']++; $quotes[$x['status']] = ($quotes[$x['status']] ?? 0) + 1; $quotes['value'] += (float)$x['amt'];
                if ($x['status'] === 'converted') { $quotes['converted_value'] += (float)$x['amt']; if ($x['inv_date']) { $dsum += $this->daysBetween($x['date'], $x['inv_date']); $dn++; } }
            }
            $quotes['days_to_convert'] = $dn ? round($dsum / $dn) : null;
            $quotes['value'] = round($quotes['value']); $quotes['converted_value'] = round($quotes['converted_value']);
        } catch (Throwable $ex) {}
        $quotes['conversion'] = $quotes['total'] ? round($quotes['converted'] / $quotes['total'] * 100, 1) : null;

        // Returns detail
        $ret = $this->returns($s, $e);
        $retProducts = [];
        $daysToReturn = [];
        foreach ($ret as $r) {
            if ($r['sale_date']) $daysToReturn[] = $this->daysBetween($r['sale_date'], $r['date']);
            foreach ($r['items'] as $it) {
                $k = $it['product_name'];
                $retProducts[$k]['qty'] = ($retProducts[$k]['qty'] ?? 0) + (int)$it['quantity'];
                $retProducts[$k]['amount'] = ($retProducts[$k]['amount'] ?? 0) + (int)$it['quantity'] * (float)$it['unitPrice'];
            }
        }
        arsort($retProducts);
        $retRows = [];
        foreach ($retProducts as $k => $v) $retRows[] = ['name' => $k, 'qty' => $v['qty'], 'amount' => round($v['amount'])];
        usort($retRows, fn($a, $b) => $b['amount'] <=> $a['amount']);

        // Consignment (open)
        $cons = $this->rows("SELECT COUNT(*) n, COALESCE(SUM(totalAmount - COALESCE(discount,0)),0) amt FROM sales_invoices WHERE entity_id = ? AND is_consignment = 1", 'i', [$this->entityId])[0];

        // Channels
        $ch = [];
        foreach ($inv as $r) {
            $k = $r['source'] ?: 'manual';
            $ch[$k]['n'] = ($ch[$k]['n'] ?? 0) + 1; $ch[$k]['net'] = ($ch[$k]['net'] ?? 0) + $r['net'];
        }
        $chRows = [];
        foreach ($ch as $k => $v) $chRows[] = ['label' => $k === 'manual' ? 'ثبت دستی در حسابداری' : $k, 'n' => $v['n'], 'net' => round($v['net'])];

        // Data health checks
        $noItems = $this->rows("SELECT COUNT(*) n FROM sales_invoices si WHERE si.entity_id = ? AND si.is_consignment = 0 AND si.date BETWEEN ? AND ?
                                AND NOT EXISTS (SELECT 1 FROM sales_invoice_items x WHERE x.invoiceId = si.id)", 'iss', [$this->entityId, $s, $e])[0]['n'];
        $noPhone = $this->rows("SELECT COUNT(DISTINCT c.id) n FROM customers c JOIN sales_invoices si ON si.customerId = c.id
                                WHERE si.entity_id = ? AND si.date BETWEEN ? AND ? AND (c.phone IS NULL OR TRIM(c.phone) = '')", 'iss', [$this->entityId, $s, $e])[0]['n'];
        $future = count(array_filter($this->allInvoices(), fn($r) => $r['date'] > $this->today));
        $zeroPrice = count(array_filter($lines, fn($l) => $l['unitPrice'] <= 0));
        $noCollection = count(array_filter($lines, fn($l) => !$l['is_service'] && !$l['collection_id']));
        $noDim = count(array_filter($lines, fn($l) => !$l['is_service'] && $l['area'] <= 0));
        $mismatch = 0;
        $sumByInv = [];
        foreach ($lines as $l) $sumByInv[$l['invoiceId']] = ($sumByInv[$l['invoiceId']] ?? 0) + $l['gross'];
        foreach ($inv as $r) if (abs(($sumByInv[$r['id']] ?? 0) - $r['gross']) > 1) $mismatch++;
        $health = [
            ['label' => 'ردیف فروش بدون اتصال به کالای انبار (بهای تمام‌شده تخمینی)', 'n' => count(array_filter($lines, fn($l) => $l['cost_estimated'])), 'level' => 'warn'],
            ['label' => 'ردیف کالا با بهای تمام‌شده صفر', 'n' => count(array_filter($lines, fn($l) => !$l['is_service'] && $l['cost'] <= 0)), 'level' => 'bad'],
            ['label' => 'ردیف فروش با قیمت صفر', 'n' => $zeroPrice, 'level' => 'warn'],
            ['label' => 'فاکتور بدون هیچ ردیف کالا', 'n' => (int)$noItems, 'level' => 'bad'],
            ['label' => 'فاکتوری که جمع ردیف‌هایش با مبلغ فاکتور نمی‌خواند', 'n' => $mismatch, 'level' => 'bad'],
            ['label' => 'ردیف کالا بدون کلکسیون', 'n' => $noCollection, 'level' => 'info'],
            ['label' => 'ردیف کالا بدون ابعاد قابل محاسبه (متراژ صفر)', 'n' => $noDim, 'level' => 'info'],
            ['label' => 'مشتری فعال بدون شماره تماس', 'n' => (int)$noPhone, 'level' => 'info'],
            ['label' => 'فاکتور با تاریخ آینده (کل سیستم)', 'n' => $future, 'level' => 'warn'],
        ];

        $cur = $this->summary($s, $e);
        return [
            'period' => $this->periodInfo($p),
            'delivery' => array_values(array_map(fn($x) => ['label' => $x['label'], 'n' => $x['n'], 'amount' => round($x['amount'])], $delivery)),
            'pending' => array_slice($pending, 0, 25),
            'pending_count' => count($pending),
            'pending_value' => round(array_sum(array_column($pending, 'net'))),
            'invoice_sizes' => $hist,
            'items_per_invoice' => ['labels' => array_keys($ipi), 'counts' => array_values($ipi)],
            'quotes' => $quotes,
            'returns' => [
                'count' => count($ret), 'amount' => round(array_sum(array_column($ret, 'amount'))), 'rate' => $cur['return_rate'],
                'avg_days' => $daysToReturn ? round(array_sum($daysToReturn) / count($daysToReturn)) : null,
                'products' => array_slice($retRows, 0, 10),
                'list' => array_map(fn($r) => ['date' => Jalali::format($r['date']), 'customer' => $r['customer_name'], 'amount' => round($r['amount']),
                                               'days' => $r['sale_date'] ? $this->daysBetween($r['sale_date'], $r['date']) : null, 'description' => $r['description']], array_slice(array_reverse($ret), 0, 15)),
            ],
            'consignment' => ['open_invoices' => (int)$cons['n'], 'open_value' => round((float)$cons['amt'])],
            'channels' => $chRows,
            'health' => $health,
            'tax' => ['total' => round($cur['tax']), 'invoices_with_tax' => count(array_filter($inv, fn($r) => $r['tax'] > 0))],
        ];
    }

    /* =================================================================
     *  Utilities
     * ================================================================= */

    private function rows(string $sql, string $types = '', array $params = []): array {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('SQL prepare failed: ' . $this->db->error);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function daysBetween(string $a, string $b): int {
        return (int)round((strtotime($b) - strtotime($a)) / 86400);
    }

    private function addDays(string $iso, int $n): string {
        return date('Y-m-d', strtotime($iso . ' ' . ($n >= 0 ? '+' : '') . $n . ' day'));
    }

    private function area(string $dim): float {
        $dim = trim($dim);
        if (isset($this->areaCache[$dim])) return $this->areaCache[$dim];
        $a = 0.0;
        if ($dim !== '') {
            if (array_key_exists($dim, $this->dimensionMap)) {
                $a = (float)$this->dimensionMap[$dim];
            } else {
                $parts = explode('x', str_replace(['*', '×', 'X'], 'x', mb_strtolower($dim)));
                if (count($parts) === 2) {
                    $w = (float)$parts[0]; $h = (float)$parts[1];
                    if ($w > 0 && $h > 0) {
                        if ($w > $this->dimensionThreshold) $w /= 100;
                        if ($h > $this->dimensionThreshold) $h /= 100;
                        $a = $w * $h;
                    }
                }
            }
        }
        return $this->areaCache[$dim] = $a;
    }

    /** Sides in meters [short, long] for "WxH" (cm values above the threshold are converted), or null for named sizes. */
    private function dimSides(string $dim): ?array {
        $dim = trim($dim);
        if ($dim === '' || array_key_exists($dim, $this->dimensionMap)) return null;
        $parts = explode('x', str_replace(['*', '×', 'X'], 'x', mb_strtolower($dim)));
        if (count($parts) !== 2) return null;
        $w = (float)$parts[0]; $h = (float)$parts[1];
        if (!($w > 0 && $h > 0)) return null;
        if ($w > $this->dimensionThreshold) $w /= 100;
        if ($h > $this->dimensionThreshold) $h /= 100;
        return [min($w, $h), max($w, $h)];
    }

    /**
     * Trade name of a rug size, e.g. 2x3 / 309x201 → "۶ متری", 2.5x3.5 → "۹ متری", 80x300 → "کناره ۳ متری".
     * Runners use the same aspect-ratio rule as the products page (kenareh_min_aspect_ratio).
     */
    private function sizeLabel(string $dim): string {
        $k = 'lbl|' . $dim;
        if (isset($this->labelCache[$k])) return $this->labelCache[$k];
        $sides = $this->dimSides($dim);
        if ($sides === null) {
            $t = trim($dim);
            return $this->labelCache[$k] = ($t === '' ? 'بدون ابعاد' : $t);
        }
        [$short, $long] = $sides;
        $ratio = function_exists('kenareh_min_aspect_ratio') ? kenareh_min_aspect_ratio() : 1.75;
        if ($long / $short >= $ratio) {
            return $this->labelCache[$k] = 'کناره ' . $this->faNum((float)(round($long * 2) / 2)) . ' متری';
        }
        $area = $short * $long;
        $v = $area < 2.5 ? max(0.5, round($area * 2) / 2) : round($area);
        return $this->labelCache[$k] = $this->faNum((float)$v) . ' متری';
    }

    /** Normalised size label: "205x150" → "1.5x2.05" so the same size groups together. */
    private function normDim(string $dim): string {
        $dim = trim($dim);
        if ($dim === '') return 'بدون ابعاد';
        if (array_key_exists($dim, $this->dimensionMap)) return $dim;
        $parts = explode('x', str_replace(['*', '×', 'X'], 'x', mb_strtolower($dim)));
        if (count($parts) !== 2) return $dim;
        $w = (float)$parts[0]; $h = (float)$parts[1];
        if (!($w > 0 && $h > 0)) return $dim;
        if ($w > $this->dimensionThreshold) $w /= 100;
        if ($h > $this->dimensionThreshold) $h /= 100;
        $a = min($w, $h); $b = max($w, $h);
        $fmt = fn($x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
        return $fmt($a) . 'x' . $fmt($b);
    }

    private function delta($v, $c, bool $points = false): ?float {
        if ($v === null || $c === null) return null;
        if ($points) return round($v - $c, 1);
        if (abs((float)$c) < 0.0001) return null;
        return round(($v - $c) / abs($c) * 100, 1);
    }

    private function median(array $sorted): ?float {
        $n = count($sorted);
        if (!$n) return null;
        $mid = intdiv($n, 2);
        return round($n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }

    /** Round to a "nice" boundary (1, 2, 2.5, 5 × 10^n) for readable bands. */
    private function roundNice(float $v): float {
        if ($v <= 0) return 0;
        $exp = floor(log10($v));
        $base = 10 ** $exp;
        foreach ([1, 2, 2.5, 5, 10] as $m) if ($v <= $m * $base) return $m * $base;
        return 10 * $base;
    }

    private function faNum($n): string {
        $s = is_float($n) ? rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.') : number_format((float)$n, 0, '.', ',');
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫']);
    }

    private function faPct($v): string { return $this->faNum(round((float)$v, 1)) . '٪'; }

    private function signedPct($v): string { return ($v >= 0 ? '+' : '−') . $this->faPct(abs($v)); }

    /** Compact money: 1,250,000,000 → "۱٫۲۵ میلیارد". */
    private function faMoney($v): string {
        $v = (float)$v; $abs = abs($v); $sign = $v < 0 ? '−' : '';
        if ($abs >= 1e9) return $sign . $this->faNum(round($abs / 1e9, 2)) . ' میلیارد';
        if ($abs >= 1e6) return $sign . $this->faNum(round($abs / 1e6, 1)) . ' میلیون';
        if ($abs >= 1e3) return $sign . $this->faNum(round($abs / 1e3)) . ' هزار';
        return $sign . $this->faNum(round($abs));
    }
}
