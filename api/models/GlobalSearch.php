<?php
// /api/models/GlobalSearch.php

class GlobalSearch {
    private $conn;
    private const LIMIT = 6;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function search($rawQuery) {
        $q = $this->normalize($rawQuery);
        if ($q === '') {
            return ['success' => true, 'query' => '', 'groups' => []];
        }
        if (mb_strlen($q) > 80) {
            $q = mb_substr($q, 0, 80);
        }

        $entityId = intval($_SESSION['current_entity_id'] ?? 0);
        if ($entityId <= 0) {
            return ['error' => 'شناسه مجموعه نامعتبر است.', 'statusCode' => 401];
        }

        $like = '%' . $q . '%';
        $isNumeric = ctype_digit($q);
        $idExact = $isNumeric ? intval($q) : 0;
        $isSerialish = (bool)preg_match('/^INV-?\d*$/i', $q);
        if (mb_strlen($q) < 2 && !$isNumeric && !$isSerialish) {
            return ['success' => true, 'query' => $q, 'groups' => []];
        }
        if (preg_match('/^INV-?$/i', $q)) {
            return ['success' => true, 'query' => $q, 'groups' => []];
        }
        if ($isNumeric && strlen($q) <= 2) {
            return $this->searchByExactId($entityId, $idExact, $q);
        }
        $groups = [];

        if ($this->can('manage_customers')) {
            $rows = $this->query(
                "SELECT id, name, phone, nationalId, job
                 FROM customers
                 WHERE entity_id = ?
                   AND (name LIKE ? OR IFNULL(phone,'') LIKE ? OR IFNULL(nationalId,'') LIKE ? OR IFNULL(address,'') LIKE ? OR IFNULL(job,'') LIKE ?
                        OR CAST(id AS CHAR) LIKE ?" . ($isNumeric ? " OR id = ?" : "") . ")
                 ORDER BY name ASC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssssi' : 'issssss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'customers', 'مشتریان', 'bi-people', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['job'] ?? '', $r['nationalId'] ?? '']);
                return [
                    'type' => 'customer',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('مشتری #' . $r['id']),
                    'tab' => 'customers-tab',
                ];
            });
        }

        if ($this->can('manage_suppliers')) {
            $rows = $this->query(
                "SELECT id, name, phone, economicCode, job
                 FROM suppliers
                 WHERE entity_id = ?
                   AND (name LIKE ? OR IFNULL(phone,'') LIKE ? OR IFNULL(economicCode,'') LIKE ? OR IFNULL(address,'') LIKE ? OR IFNULL(job,'') LIKE ?
                        OR CAST(id AS CHAR) LIKE ?" . ($isNumeric ? " OR id = ?" : "") . ")
                 ORDER BY name ASC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssssi' : 'issssss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'suppliers', 'تأمین‌کنندگان', 'bi-truck', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['job'] ?? '', $r['economicCode'] ?? '']);
                return [
                    'type' => 'supplier',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('تأمین‌کننده #' . $r['id']),
                    'tab' => 'suppliers-tab',
                ];
            });
        }

        if ($this->can('manage_contacts')) {
            $rows = $this->query(
                "SELECT id, name, phone, nationalId, job
                 FROM contacts
                 WHERE entity_id = ?
                   AND (name LIKE ? OR IFNULL(phone,'') LIKE ? OR IFNULL(nationalId,'') LIKE ? OR IFNULL(notes,'') LIKE ? OR IFNULL(job,'') LIKE ?
                        OR CAST(id AS CHAR) LIKE ?" . ($isNumeric ? " OR id = ?" : "") . ")
                 ORDER BY name ASC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssssi' : 'issssss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'contacts', 'سایر اشخاص', 'bi-person-badge', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['job'] ?? '', $r['nationalId'] ?? '']);
                return [
                    'type' => 'contact',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('سایر شخص #' . $r['id']),
                    'tab' => 'contacts-tab',
                ];
            });
        }

        if ($this->can('manage_products')) {
            $rows = $this->query(
                "SELECT p.id, p.name, p.design_code, p.sku, p.colorway, p.description, c.name AS collectionName
                 FROM products p
                 LEFT JOIN product_collections c ON c.id = p.collection_id
                 WHERE p.entity_id = ?
                   AND (p.name LIKE ? OR IFNULL(p.design_code,'') LIKE ? OR IFNULL(p.sku,'') LIKE ?
                        OR IFNULL(p.colorway,'') LIKE ? OR IFNULL(p.description,'') LIKE ?
                        OR IFNULL(c.name,'') LIKE ? OR CAST(p.id AS CHAR) LIKE ?)
                 ORDER BY p.name ASC
                 LIMIT " . self::LIMIT,
                'isssssss',
                [$entityId, $like, $like, $like, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'products', 'محصولات', 'bi-box-seam', $rows, function ($r) {
                $bits = array_filter([$r['design_code'] ?? '', $r['sku'] ?? '', $r['collectionName'] ?? '']);
                return [
                    'type' => 'product',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('محصول #' . $r['id']),
                    'tab' => 'products-tab',
                ];
            });

            $serialWhere = 'IFNULL(ii.serial_id,\'\') LIKE ?';
            $serialParams = [$entityId, $like];
            $serialTypes = 'is';
            if (preg_match('/^INV-?0*(\d+)$/i', $q, $m)) {
                $serialWhere .= ' OR ii.id = ? OR ii.serial_id = ?';
                $serialParams[] = intval($m[1]);
                $serialParams[] = 'INV-' . str_pad($m[1], 6, '0', STR_PAD_LEFT);
                $serialTypes .= 'is';
            } elseif ($isNumeric && strlen($q) >= 3) {
                $serialWhere .= ' OR ii.id = ?';
                $serialParams[] = $idExact;
                $serialTypes .= 'i';
            }
            $serialRows = $this->query(
                "SELECT ii.id, ii.serial_id, ii.status, ii.dimensions, ii.product_id, p.name AS product_name,
                        COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
                 FROM inventory_items ii
                 JOIN products p ON p.id = ii.product_id
                 WHERE ii.entity_id = ?
                   AND ({$serialWhere})
                 ORDER BY ii.id DESC
                 LIMIT " . self::LIMIT,
                $serialTypes,
                $serialParams
            );
            $statusMap = [
                'in_stock' => 'موجود',
                'sold' => 'فروخته‌شده',
                'returned' => 'مرجوعی',
                'consignment_out' => 'امانی خروجی',
                'returned_to_supplier' => 'برگشت به تأمین‌کننده',
                'reserved' => 'رزرو',
                'damaged' => 'آسیب‌دیده',
            ];
            $this->pushGroup($groups, 'serials', 'شناسه انبار', 'bi-upc-scan', $serialRows, function ($r) use ($statusMap) {
                $st = $statusMap[$r['status']] ?? $r['status'];
                $dim = $r['dimensions'] ? ' · ' . $r['dimensions'] : '';
                return [
                    'type' => 'serial',
                    'id' => intval($r['id']),
                    'title' => $r['serial_label'],
                    'subtitle' => ($r['product_name'] ?: 'محصول') . $dim . ' · ' . $st,
                    'tab' => 'products-tab',
                    'product_id' => intval($r['product_id']),
                    'serial_id' => $r['serial_label'],
                ];
            });
        }

        if ($this->can('manage_sales_invoices')) {
            $this->addInvoiceGroup($groups, 'sales', $entityId, $q, $like, $isNumeric, $idExact, 0);
        }
        if ($this->can('manage_purchase_invoices')) {
            $this->addInvoiceGroup($groups, 'purchase', $entityId, $q, $like, $isNumeric, $idExact, 0);
        }
        if ($this->can('manage_consignment')) {
            if ($this->can('manage_sales_invoices')) {
                $this->addInvoiceGroup($groups, 'sales', $entityId, $q, $like, $isNumeric, $idExact, 1);
            }
            if ($this->can('manage_purchase_invoices')) {
                $this->addInvoiceGroup($groups, 'purchase', $entityId, $q, $like, $isNumeric, $idExact, 1);
            }
        }

        if ($this->can('manage_quotations')) {
            $rows = $this->query(
                "SELECT q.id, q.date, q.totalAmount, q.status, c.name AS personName
                 FROM quotations q
                 LEFT JOIN customers c ON c.id = q.customerId
                 WHERE q.entity_id = ?
                   AND (CAST(q.id AS CHAR) LIKE ? OR IFNULL(c.name,'') LIKE ? OR IFNULL(q.description,'') LIKE ?
                        OR CAST(q.totalAmount AS CHAR) LIKE ?" . ($isNumeric ? " OR q.id = ?" : "") . ")
                 ORDER BY q.id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssi' : 'issss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like]
            );
            $statusMap = ['pending' => 'در انتظار', 'converted' => 'تبدیل‌شده', 'cancelled' => 'لغو', 'expired' => 'منقضی'];
            $this->pushGroup($groups, 'quotations', 'پیش‌فاکتور', 'bi-file-earmark-text', $rows, function ($r) use ($statusMap) {
                $st = $statusMap[$r['status']] ?? $r['status'];
                return [
                    'type' => 'quotation',
                    'id' => intval($r['id']),
                    'title' => 'پیش‌فاکتور #' . $r['id'] . ($r['personName'] ? ' — ' . $r['personName'] : ''),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['totalAmount']) . ' · ' . $st),
                    'tab' => 'quotations-tab',
                ];
            });
        }

        if ($this->can('manage_checks') || $this->can('manage_transactions')) {
            $rows = $this->query(
                "SELECT id, checkNumber, bankName, issuerName, amount, status, dueDate
                 FROM checks
                 WHERE entity_id = ?
                   AND (IFNULL(checkNumber,'') LIKE ? OR IFNULL(bankName,'') LIKE ? OR IFNULL(issuerName,'') LIKE ?
                        OR CAST(amount AS CHAR) LIKE ?" . ($isNumeric ? " OR id = ?" : "") . ")
                 ORDER BY id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssi' : 'issss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'checks', 'چک‌ها', 'bi-bank', $rows, function ($r) {
                return [
                    'type' => 'check',
                    'id' => intval($r['id']),
                    'title' => 'چک ' . ($r['checkNumber'] ?: '#' . $r['id']),
                    'subtitle' => trim(($r['bankName'] ?? '') . ' · ' . ($r['issuerName'] ?? '') . ' · ' . number_format((float)$r['amount'])),
                    'tab' => 'checks-tab',
                ];
            });
        }

        if ($this->can('manage_accounts')) {
            $rows = $this->query(
                "SELECT id, name, bank_name, account_number, is_cash
                 FROM accounts
                 WHERE entity_id = ?
                   AND (name LIKE ? OR IFNULL(bank_name,'') LIKE ? OR IFNULL(account_number,'') LIKE ?)
                 ORDER BY name ASC
                 LIMIT " . self::LIMIT,
                'isss',
                [$entityId, $like, $like, $like]
            );
            $this->pushGroup($groups, 'accounts', 'حساب‌ها', 'bi-wallet2', $rows, function ($r) {
                $kind = intval($r['is_cash']) ? 'صندوق' : ($r['bank_name'] ?: 'بانک');
                return [
                    'type' => 'account',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => trim($kind . ($r['account_number'] ? ' · ' . $r['account_number'] : '')),
                    'tab' => 'accounts-tab',
                ];
            });
        }

        if ($this->can('manage_return_invoices')) {
            $rows = $this->query(
                "SELECT ri.id, ri.date, ri.totalAmount, ri.type, ri.description,
                        CASE
                            WHEN ri.person_type = 'customer' THEN c.name
                            WHEN ri.person_type = 'supplier' THEN s.name
                            ELSE p.name
                        END AS personName
                 FROM return_invoices ri
                 LEFT JOIN customers c ON c.id = ri.person_id AND ri.person_type = 'customer'
                 LEFT JOIN suppliers s ON s.id = ri.person_id AND ri.person_type = 'supplier'
                 LEFT JOIN partners p ON p.id = ri.person_id AND ri.person_type = 'partner'
                 WHERE ri.entity_id = ?
                   AND (CAST(ri.id AS CHAR) LIKE ? OR IFNULL(ri.description,'') LIKE ?
                        OR IFNULL(c.name,'') LIKE ? OR IFNULL(s.name,'') LIKE ? OR IFNULL(p.name,'') LIKE ?
                        OR CAST(ri.totalAmount AS CHAR) LIKE ?" . ($isNumeric ? " OR ri.id = ?" : "") . ")
                 ORDER BY ri.id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssssi' : 'issssss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'returns', 'فاکتور مرجوعی', 'bi-arrow-return-left', $rows, function ($r) {
                $kind = ($r['type'] === 'purchase_return') ? 'برگشت خرید' : 'برگشت فروش';
                return [
                    'type' => $r['type'] === 'purchase_return' ? 'purchase_return' : 'sales_return',
                    'id' => intval($r['id']),
                    'title' => $kind . ' #' . $r['id'] . ($r['personName'] ? ' — ' . $r['personName'] : ''),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['totalAmount'])),
                    'tab' => 'return-invoices-tab',
                ];
            });
        }

        if ($this->can('manage_transactions')) {
            $rows = $this->query(
                "SELECT e.id, e.date, e.amount, e.category, e.description
                 FROM expenses e
                 WHERE e.entity_id = ?
                   AND (IFNULL(e.category,'') LIKE ? OR IFNULL(e.description,'') LIKE ? OR CAST(e.amount AS CHAR) LIKE ?
                        OR CAST(e.id AS CHAR) LIKE ?" . ($isNumeric ? " OR e.id = ?" : "") . ")
                 ORDER BY e.id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssi' : 'issss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'expenses', 'هزینه‌ها', 'bi-receipt', $rows, function ($r) {
                return [
                    'type' => 'expense',
                    'id' => intval($r['id']),
                    'title' => $r['category'] ?: ('هزینه #' . $r['id']),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount']) . ' · ' . mb_substr((string)($r['description'] ?? ''), 0, 40)),
                    'tab' => 'transactions-tab',
                ];
            });

            $incRows = $this->query(
                "SELECT i.id, i.date, i.amount, i.category, i.description
                 FROM incomes i
                 WHERE i.entity_id = ?
                   AND (IFNULL(i.category,'') LIKE ? OR IFNULL(i.description,'') LIKE ? OR CAST(i.amount AS CHAR) LIKE ?
                        OR CAST(i.id AS CHAR) LIKE ?" . ($isNumeric ? " OR i.id = ?" : "") . ")
                 ORDER BY i.id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'issssi' : 'issss',
                $isNumeric ? [$entityId, $like, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like, $like]
            );
            $this->pushGroup($groups, 'incomes', 'درآمدها', 'bi-cash-coin', $incRows, function ($r) {
                return [
                    'type' => 'income',
                    'id' => intval($r['id']),
                    'title' => $r['category'] ?: ('درآمد #' . $r['id']),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount']) . ' · ' . mb_substr((string)($r['description'] ?? ''), 0, 40)),
                    'tab' => 'transactions-tab',
                ];
            });

            $payRows = $this->query(
                "SELECT p.id, p.date, p.amount, p.description, p.transaction_type, p.type, p.checkId
                 FROM payments p
                 WHERE p.entity_id = ?
                   AND IFNULL(p.checkId, 0) = 0
                   AND (IFNULL(p.description,'') LIKE ? OR CAST(p.amount AS CHAR) LIKE ?
                        OR CAST(p.id AS CHAR) LIKE ?" . ($isNumeric ? " OR p.id = ?" : "") . ")
                 ORDER BY p.id DESC
                 LIMIT " . self::LIMIT,
                $isNumeric ? 'isssi' : 'isss',
                $isNumeric ? [$entityId, $like, $like, $like, $idExact] : [$entityId, $like, $like, $like]
            );
            $this->pushGroup($groups, 'payments', 'دریافت و پرداخت', 'bi-cash-stack', $payRows, function ($r) {
                $kind = ($r['transaction_type'] === 'receipt') ? 'دریافت' : 'پرداخت';
                return [
                    'type' => 'payment',
                    'id' => intval($r['id']),
                    'title' => $kind . ' #' . $r['id'] . ($r['description'] ? ' — ' . mb_substr($r['description'], 0, 40) : ''),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount']) . ' · ' . ($r['type'] ?? '')),
                    'tab' => 'transactions-tab',
                ];
            });
        }

        return ['success' => true, 'query' => $q, 'groups' => $groups];
    }

    private function searchByExactId($entityId, $idExact, $q) {
        $groups = [];
        $paddedSerial = 'INV-' . str_pad((string)$idExact, 6, '0', STR_PAD_LEFT);

        if ($this->can('manage_customers')) {
            $rows = $this->query("SELECT id, name, phone, nationalId FROM customers WHERE entity_id = ? AND id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'customers', 'مشتریان', 'bi-people', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['nationalId'] ?? '']);
                return [
                    'type' => 'customer',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('مشتری #' . $r['id']),
                    'tab' => 'customers-tab',
                ];
            });
        }
        if ($this->can('manage_suppliers')) {
            $rows = $this->query("SELECT id, name, phone, economicCode FROM suppliers WHERE entity_id = ? AND id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'suppliers', 'تأمین‌کنندگان', 'bi-truck', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['economicCode'] ?? '']);
                return [
                    'type' => 'supplier',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('تأمین‌کننده #' . $r['id']),
                    'tab' => 'suppliers-tab',
                ];
            });
        }
        if ($this->can('manage_contacts')) {
            $rows = $this->query("SELECT id, name, phone, nationalId FROM contacts WHERE entity_id = ? AND id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'contacts', 'سایر اشخاص', 'bi-person-badge', $rows, function ($r) {
                $bits = array_filter([$r['phone'] ?? '', $r['nationalId'] ?? '']);
                return [
                    'type' => 'contact',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('سایر شخص #' . $r['id']),
                    'tab' => 'contacts-tab',
                ];
            });
        }
        if ($this->can('manage_products')) {
            $rows = $this->query("SELECT p.id, p.name, p.design_code, p.sku, p.colorway, c.name AS collectionName FROM products p LEFT JOIN product_collections c ON c.id = p.collection_id WHERE p.entity_id = ? AND p.id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'products', 'محصولات', 'bi-box-seam', $rows, function ($r) {
                $bits = array_filter([$r['design_code'] ?? '', $r['sku'] ?? '', $r['collectionName'] ?? '']);
                return [
                    'type' => 'product',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => $bits ? implode(' · ', $bits) : ('محصول #' . $r['id']),
                    'tab' => 'products-tab',
                ];
            });
            $serialRows = $this->query(
                "SELECT ii.id, ii.serial_id, ii.status, ii.dimensions, ii.product_id, p.name AS product_name,
                        COALESCE(NULLIF(ii.serial_id,''), CONCAT('INV-', LPAD(ii.id,6,'0'))) AS serial_label
                 FROM inventory_items ii
                 JOIN products p ON p.id = ii.product_id
                 WHERE ii.entity_id = ? AND (ii.id = ? OR ii.serial_id = ? OR ii.serial_id = ?)
                 LIMIT " . self::LIMIT,
                'iiss',
                [$entityId, $idExact, (string)$idExact, $paddedSerial]
            );
            $statusMap = [
                'in_stock' => 'موجود', 'sold' => 'فروخته‌شده', 'returned' => 'مرجوعی',
                'consignment_out' => 'امانی خروجی', 'returned_to_supplier' => 'برگشت به تأمین‌کننده',
                'reserved' => 'رزرو', 'damaged' => 'آسیب‌دیده',
            ];
            $this->pushGroup($groups, 'serials', 'شناسه انبار', 'bi-upc-scan', $serialRows, function ($r) use ($statusMap) {
                $st = $statusMap[$r['status']] ?? $r['status'];
                $dim = $r['dimensions'] ? ' · ' . $r['dimensions'] : '';
                return [
                    'type' => 'serial',
                    'id' => intval($r['id']),
                    'title' => $r['serial_label'],
                    'subtitle' => ($r['product_name'] ?: 'محصول') . $dim . ' · ' . $st,
                    'tab' => 'products-tab',
                    'product_id' => intval($r['product_id']),
                    'serial_id' => $r['serial_label'],
                ];
            });
        }
        if ($this->can('manage_sales_invoices')) {
            $this->addInvoiceGroup($groups, 'sales', $entityId, $q, '%' . $q . '%', true, $idExact, 0);
        }
        if ($this->can('manage_purchase_invoices')) {
            $this->addInvoiceGroup($groups, 'purchase', $entityId, $q, '%' . $q . '%', true, $idExact, 0);
        }
        if ($this->can('manage_consignment')) {
            if ($this->can('manage_sales_invoices')) {
                $this->addInvoiceGroup($groups, 'sales', $entityId, $q, '%' . $q . '%', true, $idExact, 1);
            }
            if ($this->can('manage_purchase_invoices')) {
                $this->addInvoiceGroup($groups, 'purchase', $entityId, $q, '%' . $q . '%', true, $idExact, 1);
            }
        }
        if ($this->can('manage_quotations')) {
            $rows = $this->query("SELECT q.id, q.date, q.totalAmount, q.status, c.name AS personName FROM quotations q LEFT JOIN customers c ON c.id = q.customerId WHERE q.entity_id = ? AND q.id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'quotations', 'پیش‌فاکتور', 'bi-file-earmark-text', $rows, function ($r) {
                return [
                    'type' => 'quotation',
                    'id' => intval($r['id']),
                    'title' => 'پیش‌فاکتور #' . $r['id'] . ($r['personName'] ? ' — ' . $r['personName'] : ''),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['totalAmount'])),
                    'tab' => 'quotations-tab',
                ];
            });
        }
        if ($this->can('manage_checks') || $this->can('manage_transactions')) {
            $rows = $this->query("SELECT id, checkNumber, bankName, issuerName, amount, status FROM checks WHERE entity_id = ? AND id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'checks', 'چک‌ها', 'bi-bank', $rows, function ($r) {
                return [
                    'type' => 'check',
                    'id' => intval($r['id']),
                    'title' => 'چک ' . ($r['checkNumber'] ?: '#' . $r['id']),
                    'subtitle' => trim(($r['bankName'] ?? '') . ' · ' . ($r['issuerName'] ?? '') . ' · ' . number_format((float)$r['amount'])),
                    'tab' => 'checks-tab',
                ];
            });
        }
        if ($this->can('manage_accounts')) {
            $rows = $this->query("SELECT id, name, bank_name, account_number, is_cash FROM accounts WHERE entity_id = ? AND id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'accounts', 'حساب‌ها', 'bi-wallet2', $rows, function ($r) {
                $kind = intval($r['is_cash']) ? 'صندوق' : ($r['bank_name'] ?: 'بانک');
                return [
                    'type' => 'account',
                    'id' => intval($r['id']),
                    'title' => $r['name'],
                    'subtitle' => trim($kind . ($r['account_number'] ? ' · ' . $r['account_number'] : '')),
                    'tab' => 'accounts-tab',
                ];
            });
        }
        if ($this->can('manage_return_invoices')) {
            $rows = $this->query(
                "SELECT ri.id, ri.date, ri.totalAmount, ri.type,
                        CASE WHEN ri.person_type = 'customer' THEN c.name WHEN ri.person_type = 'supplier' THEN s.name ELSE p.name END AS personName
                 FROM return_invoices ri
                 LEFT JOIN customers c ON c.id = ri.person_id AND ri.person_type = 'customer'
                 LEFT JOIN suppliers s ON s.id = ri.person_id AND ri.person_type = 'supplier'
                 LEFT JOIN partners p ON p.id = ri.person_id AND ri.person_type = 'partner'
                 WHERE ri.entity_id = ? AND ri.id = ? LIMIT 1",
                'ii',
                [$entityId, $idExact]
            );
            $this->pushGroup($groups, 'returns', 'فاکتور مرجوعی', 'bi-arrow-return-left', $rows, function ($r) {
                $kind = ($r['type'] === 'purchase_return') ? 'برگشت خرید' : 'برگشت فروش';
                return [
                    'type' => $r['type'] === 'purchase_return' ? 'purchase_return' : 'sales_return',
                    'id' => intval($r['id']),
                    'title' => $kind . ' #' . $r['id'] . ($r['personName'] ? ' — ' . $r['personName'] : ''),
                    'tab' => 'return-invoices-tab',
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['totalAmount'])),
                ];
            });
        }
        if ($this->can('manage_transactions')) {
            $rows = $this->query("SELECT e.id, e.date, e.amount, e.category, e.description FROM expenses e WHERE e.entity_id = ? AND e.id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'expenses', 'هزینه‌ها', 'bi-receipt', $rows, function ($r) {
                return [
                    'type' => 'expense',
                    'id' => intval($r['id']),
                    'title' => $r['category'] ?: ('هزینه #' . $r['id']),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount'])),
                    'tab' => 'transactions-tab',
                ];
            });
            $incRows = $this->query("SELECT i.id, i.date, i.amount, i.category, i.description FROM incomes i WHERE i.entity_id = ? AND i.id = ? LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'incomes', 'درآمدها', 'bi-cash-coin', $incRows, function ($r) {
                return [
                    'type' => 'income',
                    'id' => intval($r['id']),
                    'title' => $r['category'] ?: ('درآمد #' . $r['id']),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount'])),
                    'tab' => 'transactions-tab',
                ];
            });
            $payRows = $this->query("SELECT p.id, p.date, p.amount, p.description, p.transaction_type, p.type FROM payments p WHERE p.entity_id = ? AND p.id = ? AND IFNULL(p.checkId,0)=0 LIMIT 1", 'ii', [$entityId, $idExact]);
            $this->pushGroup($groups, 'payments', 'دریافت و پرداخت', 'bi-cash-stack', $payRows, function ($r) {
                $kind = ($r['transaction_type'] === 'receipt') ? 'دریافت' : 'پرداخت';
                return [
                    'type' => 'payment',
                    'id' => intval($r['id']),
                    'title' => $kind . ' #' . $r['id'] . ($r['description'] ? ' — ' . mb_substr($r['description'], 0, 40) : ''),
                    'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['amount'])),
                    'tab' => 'transactions-tab',
                ];
            });
        }

        return ['success' => true, 'query' => $q, 'groups' => $groups];
    }

    private function addInvoiceGroup(array &$groups, $kind, $entityId, $q, $like, $isNumeric, $idExact, $consignment) {
        $isSales = $kind === 'sales';
        $table = $isSales ? 'sales_invoices' : 'purchase_invoices';
        $alias = $isSales ? 'si' : 'pi';
        $personJoin = $isSales
            ? 'LEFT JOIN customers p ON p.id = si.customerId'
            : 'LEFT JOIN suppliers p ON p.id = pi.supplierId';
        $itemTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
        $itemAlias = $isSales ? 'sii' : 'pii';
        $consignmentFlag = intval($consignment);

        if ($isNumeric && strlen((string)$q) <= 2) {
            $rows = $this->query(
                "SELECT {$alias}.id, {$alias}.date, {$alias}.totalAmount, {$alias}.invoice_number, p.name AS personName
                 FROM `{$table}` {$alias}
                 {$personJoin}
                 WHERE {$alias}.entity_id = ? AND COALESCE({$alias}.is_consignment,0) = ? AND {$alias}.id = ?
                 LIMIT 1",
                'iii',
                [$entityId, $consignmentFlag, $idExact]
            );
        } else {
        $headerSql = "SELECT {$alias}.id, {$alias}.date, {$alias}.totalAmount, {$alias}.invoice_number,
                             p.name AS personName
                      FROM `{$table}` {$alias}
                      {$personJoin}
                      WHERE {$alias}.entity_id = ?
                        AND COALESCE({$alias}.is_consignment,0) = ?
                        AND (
                              CAST({$alias}.id AS CHAR) LIKE ?
                              OR IFNULL({$alias}.invoice_number,'') LIKE ?
                              OR IFNULL({$alias}.description,'') LIKE ?
                              OR IFNULL(p.name,'') LIKE ?
                              OR CAST({$alias}.totalAmount AS CHAR) LIKE ?
                              " . ($isNumeric ? " OR {$alias}.id = ?" : "") . "
                        )
                      ORDER BY {$alias}.id DESC
                      LIMIT " . self::LIMIT;
        $params = [$entityId, $consignmentFlag, $like, $like, $like, $like, $like];
        $types = 'iisssss';
        if ($isNumeric) {
            $params[] = $idExact;
            $types .= 'i';
        }
        $rows = $this->query($headerSql, $types, $params);
        $found = [];
        foreach ($rows as $row) {
            $found[intval($row['id'])] = $row;
        }

        $searchLines = !$isNumeric;
        if ($searchLines && count($found) < self::LIMIT) {
            $serialJoin = $isSales
                ? "LEFT JOIN inventory_items ii ON (ii.sales_invoice_item_id = {$itemAlias}.id OR ii.returned_from_sales_item_id = {$itemAlias}.id)"
                : "LEFT JOIN inventory_items ii ON ii.purchase_invoice_item_id = {$itemAlias}.id";
            $lineConds = ["IFNULL(pr.name,'') LIKE ?", "IFNULL({$itemAlias}.dimensions,'') LIKE ?"];
            $lineParams = [$entityId, $consignmentFlag, $like, $like];
            $lineTypes = 'iiss';
            if (preg_match('/INV/i', $q) || ($isNumeric && strlen((string)$q) >= 4)) {
                $lineConds[] = "IFNULL(ii.serial_id,'') LIKE ?";
                $lineParams[] = $like;
                $lineTypes .= 's';
                if (preg_match('/^INV-?0*(\d+)$/i', $q, $m)) {
                    $lineConds[] = 'ii.id = ?';
                    $lineParams[] = intval($m[1]);
                    $lineTypes .= 'i';
                }
            }
            $lineSql = "SELECT DISTINCT {$itemAlias}.invoiceId AS id
                        FROM `{$itemTable}` {$itemAlias}
                        INNER JOIN `{$table}` {$alias} ON {$alias}.id = {$itemAlias}.invoiceId
                        LEFT JOIN products pr ON pr.id = {$itemAlias}.productId
                        {$serialJoin}
                        WHERE {$alias}.entity_id = ?
                          AND COALESCE({$alias}.is_consignment,0) = ?
                          AND (" . implode(' OR ', $lineConds) . ")
                        LIMIT " . self::LIMIT;
            $lineRows = $this->query($lineSql, $lineTypes, $lineParams);
            $missing = [];
            foreach ($lineRows as $lr) {
                $id = intval($lr['id']);
                if ($id > 0 && !isset($found[$id])) {
                    $missing[] = $id;
                }
            }
            if ($missing) {
                $placeholders = implode(',', array_fill(0, count($missing), '?'));
                $extraSql = "SELECT {$alias}.id, {$alias}.date, {$alias}.totalAmount, {$alias}.invoice_number,
                                    p.name AS personName
                             FROM `{$table}` {$alias}
                             {$personJoin}
                             WHERE {$alias}.id IN ({$placeholders})";
                $extraTypes = str_repeat('i', count($missing));
                foreach ($this->query($extraSql, $extraTypes, $missing) as $row) {
                    $found[intval($row['id'])] = $row;
                }
            }
        }

        if ($found) {
            usort($found, function ($a, $b) {
                return intval($b['id']) <=> intval($a['id']);
            });
            $rows = array_slice(array_values($found), 0, self::LIMIT);
        }
        }

        if ($consignment) {
            $key = $isSales ? 'consignment_sales' : 'consignment_purchases';
            $label = $isSales ? 'امانی فروش' : 'امانی خرید';
            $icon = 'bi-truck';
            $tab = 'consignment-tab';
            $type = $isSales ? 'consignment_sales' : 'consignment_purchase';
        } else {
            $key = $isSales ? 'sales' : 'purchases';
            $label = $isSales ? 'فاکتور فروش' : 'فاکتور خرید';
            $icon = $isSales ? 'bi-receipt-cutoff' : 'bi-bag';
            $tab = $isSales ? 'sales-invoices-tab' : 'purchase-invoices-tab';
            $type = $isSales ? 'sales_invoice' : 'purchase_invoice';
        }

        $this->pushGroup($groups, $key, $label, $icon, $rows, function ($r) use ($type, $tab, $label) {
            $num = $r['invoice_number'] ? 'شماره ' . $r['invoice_number'] : ('#' . $r['id']);
            return [
                'type' => $type,
                'id' => intval($r['id']),
                'title' => $label . ' ' . $num . ($r['personName'] ? ' — ' . $r['personName'] : ''),
                'subtitle' => trim(($r['date'] ?? '') . ' · ' . number_format((float)$r['totalAmount'])),
                'tab' => $tab,
            ];
        });
    }

    private function pushGroup(array &$groups, $key, $label, $icon, array $rows, callable $map) {
        if (empty($rows)) {
            return;
        }
        $items = [];
        foreach ($rows as $row) {
            $items[] = $map($row);
        }
        $groups[] = [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'items' => $items,
        ];
    }

    private function can($perm) {
        $perms = $_SESSION['permissions'] ?? [];
        return in_array($perm, $perms, true);
    }

    private function normalize($raw) {
        $q = trim((string)$raw);
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $q = str_replace($fa, $en, $q);
        $q = str_replace(['ي', 'ى', 'ك'], ['ی', 'ی', 'ک'], $q);
        $q = str_replace(['%', '_', '\\'], '', $q);
        $q = preg_replace('/\s+/u', ' ', $q);
        return $q;
    }

    private function query($sql, $types, array $params) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('GlobalSearch SQL error: ' . $this->conn->error);
            return [];
        }
        if ($types !== '' && $params) {
            $refs = [];
            foreach ($params as $k => $_) {
                $refs[$k] = &$params[$k];
            }
            array_unshift($refs, $types);
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        if ($stmt->errno) {
            error_log('GlobalSearch execute error: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        return db_stmt_to_assoc_array($stmt);
    }
}
