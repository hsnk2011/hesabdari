<?php
// /api/models/Asset.php
// دارایی‌ها: holdings that are not carpets (currency, coins, gold, furniture,
// equipment, ...). Kept outside products/inventory. Bought and sold through
// asset documents whose payments use the regular payment engine
// (invoiceType asset_purchase / asset_sale → asset_documents).
require_once __DIR__ . '/BaseModel.php';

class Asset extends BaseModel {
    protected $tableName = 'assets';

    const PERSON_TABLES = [
        'supplier' => 'suppliers',
        'customer' => 'customers',
        'contact' => 'contacts',
    ];

    const DEFAULT_CATEGORIES = ['ارز', 'طلا و سکه', 'اثاثیه', 'تجهیزات', 'خودرو', 'سایر'];

    private function entityId() {
        return (int)($_SESSION['current_entity_id'] ?? 0);
    }

    private static function invoiceTypeFor($docType) {
        return $docType === 'sale' ? 'asset_sale' : 'asset_purchase';
    }

    private static function qty($value) {
        return round((float)str_replace([',', '٬'], '', (string)$value), 4);
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    /**
     * Assets with quantity held, remaining cost and current value.
     */
    public function listAssets($includeArchived = false) {
        $entityId = $this->entityId();
        $sql = "SELECT a.*,
                       COALESCE(SUM(CASE WHEN d.doc_type = 'purchase' THEN i.quantity ELSE -i.quantity END), 0) AS quantity,
                       COALESCE(SUM(CASE WHEN d.doc_type = 'purchase' THEN i.amount ELSE -COALESCE(i.cost_amount, 0) END), 0) AS cost_basis,
                       COALESCE(SUM(CASE WHEN d.doc_type = 'sale' THEN COALESCE(i.gain_amount, 0) ELSE 0 END), 0) AS realized_gain,
                       COUNT(i.id) AS movements
                FROM assets a
                LEFT JOIN asset_document_items i ON i.asset_id = a.id
                LEFT JOIN asset_documents d ON d.id = i.document_id
                WHERE a.entity_id = ?" . ($includeArchived ? '' : ' AND a.is_archived = 0') . "
                GROUP BY a.id
                ORDER BY a.category, a.name";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        $prices = $this->priceMap();
        foreach ($rows as &$r) {
            $r['quantity'] = round((float)$r['quantity'], 4);
            $r['cost_basis'] = round((float)$r['cost_basis'], 2);
            $r['realized_gain'] = round((float)$r['realized_gain'], 2);
            $r['avg_cost'] = $r['quantity'] > 0 ? round($r['cost_basis'] / $r['quantity'], 2) : 0;
            $r['current_value'] = null;
            $r['value_source'] = null;
            $r['value_date'] = null;
            if ($r['quantity'] <= 0) {
                // nothing held: no current value
            } elseif (!empty($r['price_key']) && isset($prices[$r['price_key']])) {
                $p = $prices[$r['price_key']];
                $r['unit_price_now'] = (float)$p['price'];
                $r['current_value'] = round($r['quantity'] * (float)$p['price'], 2);
                $r['value_source'] = 'tgju';
                $r['value_date'] = $p['fetched_at'];
            } elseif ($r['manual_value'] !== null) {
                $r['current_value'] = round((float)$r['manual_value'], 2);
                $r['value_source'] = 'manual';
                $r['value_date'] = $r['manual_value_date'];
            }
            $r['unrealized_gain'] = $r['current_value'] !== null ? round($r['current_value'] - $r['cost_basis'], 2) : null;
        }
        unset($r);
        return $rows;
    }

    private function priceMap() {
        $map = [];
        $res = $this->conn->query("SELECT price_key, title, price, fetched_at FROM asset_prices");
        while ($res && ($row = $res->fetch_assoc())) {
            $map[$row['price_key']] = $row;
        }
        return $map;
    }

    public function saveAsset($data) {
        $entityId = $this->entityId();
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'نام دارایی الزامی است.', 'statusCode' => 400];
        }
        $category = trim((string)($data['category'] ?? '')) ?: 'سایر';
        $unit = trim((string)($data['unit'] ?? '')) ?: 'عدد';
        $priceKey = trim((string)($data['price_key'] ?? ''));
        require_once __DIR__ . '/../core/AssetPriceService.php';
        if ($priceKey !== '' && !isset(AssetPriceService::KEYS[$priceKey])) {
            return ['error' => 'منبع قیمت انتخاب‌شده نامعتبر است.', 'statusCode' => 400];
        }
        $priceKey = $priceKey !== '' ? $priceKey : null;
        $manual = (isset($data['manual_value']) && $data['manual_value'] !== '' && $data['manual_value'] !== null)
            ? parse_money($data['manual_value']) : null;
        $manualDate = $manual !== null ? (normalize_input_date($data['manual_value_date'] ?? '') ?: date('Y-m-d')) : null;
        $notes = trim((string)($data['notes'] ?? ''));
        $archived = !empty($data['is_archived']) ? 1 : 0;

        $dup = $this->conn->prepare("SELECT id FROM assets WHERE entity_id = ? AND name = ? AND id <> ? LIMIT 1");
        $dup->bind_param('isi', $entityId, $name, $id);
        $dup->execute();
        if (!empty(db_stmt_to_assoc_array($dup))) {
            return ['error' => "دارایی «{$name}» قبلاً تعریف شده است.", 'statusCode' => 409];
        }

        if ($id > 0) {
            $stmt = $this->conn->prepare("UPDATE assets SET name = ?, category = ?, unit = ?, price_key = ?, manual_value = ?, manual_value_date = ?, notes = ?, is_archived = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('ssssdssiii', $name, $category, $unit, $priceKey, $manual, $manualDate, $notes, $archived, $id, $entityId);
            $stmt->execute();
            if ($stmt->affected_rows === 0 && !$this->ownsAsset($id)) {
                return ['error' => 'دارایی یافت نشد.', 'statusCode' => 404];
            }
        } else {
            $stmt = $this->conn->prepare("INSERT INTO assets (entity_id, name, category, unit, price_key, manual_value, manual_value_date, notes, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssdssi', $entityId, $name, $category, $unit, $priceKey, $manual, $manualDate, $notes, $archived);
            $stmt->execute();
            $id = $stmt->insert_id;
        }
        return ['success' => true, 'id' => $id];
    }

    private function ownsAsset($id) {
        $entityId = $this->entityId();
        $st = $this->conn->prepare("SELECT id FROM assets WHERE id = ? AND entity_id = ?");
        $st->bind_param('ii', $id, $entityId);
        $st->execute();
        return !empty(db_stmt_to_assoc_array($st));
    }

    public function deleteAsset($id) {
        $id = (int)$id;
        if (!$this->ownsAsset($id)) {
            return ['error' => 'دارایی یافت نشد.', 'statusCode' => 404];
        }
        $st = $this->conn->prepare("SELECT COUNT(*) AS n FROM asset_document_items WHERE asset_id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        if ((int)(db_stmt_to_assoc_array($st)[0]['n'] ?? 0) > 0) {
            return ['error' => 'این دارایی سند خرید یا فروش دارد و قابل حذف نیست؛ می‌توانید آن را بایگانی کنید.', 'statusCode' => 409];
        }
        $entityId = $this->entityId();
        $del = $this->conn->prepare("DELETE FROM assets WHERE id = ? AND entity_id = ?");
        $del->bind_param('ii', $id, $entityId);
        $del->execute();
        return ['success' => true];
    }

    /** Every purchase/sale row of one asset, oldest first, with running quantity. */
    public function getHistory($assetId) {
        $assetId = (int)$assetId;
        if (!$this->ownsAsset($assetId)) {
            return ['error' => 'دارایی یافت نشد.', 'statusCode' => 404];
        }
        $stmt = $this->conn->prepare(
            "SELECT d.id AS document_id, d.doc_type, d.doc_number, d.date, d.description, d.person_type, d.person_id,
                    i.quantity, i.unit_price, i.amount, i.cost_amount, i.gain_amount
             FROM asset_document_items i
             JOIN asset_documents d ON d.id = i.document_id
             WHERE i.asset_id = ?
             ORDER BY d.date, d.id, i.id"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $rows = $this->attachPersonNames(db_stmt_to_assoc_array($stmt));
        $running = 0.0;
        foreach ($rows as &$r) {
            $running += ($r['doc_type'] === 'purchase' ? 1 : -1) * (float)$r['quantity'];
            $r['running_quantity'] = round($running, 4);
        }
        unset($r);
        return ['success' => true, 'rows' => $rows];
    }

    private function attachPersonNames(array $rows) {
        $byType = [];
        foreach ($rows as $r) {
            if (!empty($r['person_type']) && !empty($r['person_id']) && isset(self::PERSON_TABLES[$r['person_type']])) {
                $byType[$r['person_type']][(int)$r['person_id']] = true;
            }
        }
        $names = [];
        foreach ($byType as $type => $ids) {
            $table = self::PERSON_TABLES[$type];
            $list = implode(',', array_map('intval', array_keys($ids)));
            $res = $this->conn->query("SELECT id, name FROM `{$table}` WHERE id IN ({$list})");
            while ($res && ($row = $res->fetch_assoc())) {
                $names[$type][(int)$row['id']] = $row['name'];
            }
        }
        foreach ($rows as &$r) {
            $r['person_name'] = $names[$r['person_type'] ?? ''][(int)($r['person_id'] ?? 0)] ?? null;
        }
        unset($r);
        return $rows;
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function listDocuments($data) {
        $entityId = $this->entityId();
        $where = 'd.entity_id = ?';
        $types = 'i';
        $params = [$entityId];
        if (!empty($data['doc_type']) && in_array($data['doc_type'], ['purchase', 'sale'], true)) {
            $where .= ' AND d.doc_type = ?';
            $types .= 's';
            $params[] = $data['doc_type'];
        }
        if (!empty($data['asset_id'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM asset_document_items x WHERE x.document_id = d.id AND x.asset_id = ?)';
            $types .= 'i';
            $params[] = (int)$data['asset_id'];
        }
        $limit = max(1, min(500, (int)($data['limit'] ?? 200)));
        $stmt = $this->conn->prepare(
            "SELECT d.*, (d.total_amount - d.paidAmount) AS remaining,
                    (SELECT GROUP_CONCAT(CONCAT(a.name, ' × ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity))) SEPARATOR '، ')
                       FROM asset_document_items i JOIN assets a ON a.id = i.asset_id WHERE i.document_id = d.id) AS items_summary,
                    (SELECT COALESCE(SUM(i.gain_amount), 0) FROM asset_document_items i WHERE i.document_id = d.id) AS gain
             FROM asset_documents d
             WHERE {$where}
             ORDER BY d.date DESC, d.id DESC
             LIMIT {$limit}"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return ['success' => true, 'rows' => $this->attachPersonNames(db_stmt_to_assoc_array($stmt))];
    }

    public function getDocument($id) {
        $id = (int)$id;
        $entityId = $this->entityId();
        $stmt = $this->conn->prepare("SELECT * FROM asset_documents WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        $doc = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$doc) {
            return ['error' => 'سند دارایی یافت نشد.', 'statusCode' => 404];
        }
        $doc = $this->attachPersonNames([$doc])[0];
        $it = $this->conn->prepare("SELECT i.*, a.name AS asset_name, a.unit FROM asset_document_items i JOIN assets a ON a.id = i.asset_id WHERE i.document_id = ? ORDER BY i.id");
        $it->bind_param('i', $id);
        $it->execute();
        $doc['items'] = db_stmt_to_assoc_array($it);

        $invoiceType = self::invoiceTypeFor($doc['doc_type']);
        $pay = $this->conn->prepare("SELECT * FROM payments WHERE entity_id = ? AND invoiceType = ? AND invoiceId = ? ORDER BY date, id");
        $pay->bind_param('isi', $entityId, $invoiceType, $id);
        $pay->execute();
        $payments = db_stmt_to_assoc_array($pay);
        $checkIds = array_values(array_filter(array_map(function ($p) { return (int)($p['checkId'] ?? 0); }, $payments)));
        $checks = [];
        if ($checkIds) {
            $res = $this->conn->query("SELECT * FROM checks WHERE entity_id = " . (int)$entityId . " AND id IN (" . implode(',', $checkIds) . ")");
            while ($res && ($c = $res->fetch_assoc())) {
                $checks[(int)$c['id']] = $c;
            }
        }
        foreach ($payments as &$p) {
            if (!empty($p['checkId']) && isset($checks[(int)$p['checkId']])) {
                $p['checkDetails'] = $checks[(int)$p['checkId']];
            }
        }
        unset($p);
        $doc['payments'] = $payments;
        $doc['remaining'] = round((float)$doc['total_amount'] - (float)$doc['paidAmount'], 2);
        return ['success' => true, 'document' => $doc];
    }

    /**
     * Create or update an asset purchase/sale document with its rows and payments.
     * Rows: [{asset_id | new_asset: {name, category, unit, price_key}, quantity, unit_price}]
     */
    public function saveDocument($data, $manageTransaction = true) {
        $entityId = $this->entityId();
        $id = (int)($data['id'] ?? 0);
        $docType = ($data['doc_type'] ?? '') === 'sale' ? 'sale' : 'purchase';
        $date = normalize_input_date($data['date'] ?? '') ?: '';
        if (!validate_date_format($date)) {
            return ['error' => 'تاریخ سند نامعتبر است.', 'statusCode' => 400];
        }
        assert_transaction_date_editable($this->conn, $entityId, $date);

        $old = null;
        if ($id > 0) {
            $st = $this->conn->prepare("SELECT * FROM asset_documents WHERE id = ? AND entity_id = ?");
            $st->bind_param('ii', $id, $entityId);
            $st->execute();
            $old = db_stmt_to_assoc_array($st)[0] ?? null;
            if (!$old) {
                return ['error' => 'سند دارایی یافت نشد.', 'statusCode' => 404];
            }
            assert_transaction_date_editable($this->conn, $entityId, $old['date']);
            if ($old['doc_type'] !== $docType) {
                return ['error' => 'نوع سند (خرید/فروش) پس از ثبت قابل تغییر نیست.', 'statusCode' => 400];
            }
        }

        $personType = $data['person_type'] ?? null;
        $personId = (int)($data['person_id'] ?? 0);
        if ($personId > 0) {
            if (!isset(self::PERSON_TABLES[$personType])) {
                return ['error' => 'نوع طرف حساب نامعتبر است.', 'statusCode' => 400];
            }
            $table = self::PERSON_TABLES[$personType];
            $chk = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ?");
            $chk->bind_param('ii', $personId, $entityId);
            $chk->execute();
            if (empty(db_stmt_to_assoc_array($chk))) {
                return ['error' => 'طرف حساب انتخاب‌شده متعلق به این مجموعه نیست.', 'statusCode' => 400];
            }
        } else {
            $personType = null;
            $personId = null;
        }

        $rows = [];
        foreach ((array)($data['items'] ?? []) as $it) {
            $q = self::qty($it['quantity'] ?? 0);
            if ($q <= 0) {
                continue;
            }
            $unitPrice = parse_money($it['unit_price'] ?? 0);
            if ($unitPrice < 0) {
                return ['error' => 'قیمت واحد نمی‌تواند منفی باشد.', 'statusCode' => 400];
            }
            $rows[] = [
                'asset_id' => (int)($it['asset_id'] ?? 0),
                'new_asset' => is_array($it['new_asset'] ?? null) ? $it['new_asset'] : null,
                'quantity' => $q,
                'unit_price' => $unitPrice,
                'amount' => round($q * $unitPrice, 2),
            ];
        }
        if (empty($rows)) {
            return ['error' => 'حداقل یک ردیف با مقدار بیشتر از صفر لازم است.', 'statusCode' => 400];
        }
        $total = round(array_sum(array_column($rows, 'amount')), 2);
        $payments = (array)($data['payments'] ?? []);
        // موجودی اول دوره: assets owned before using the app — no money moves now.
        $isOpening = ($docType === 'purchase' && !empty($data['is_opening'])) ? 1 : 0;
        if ($isOpening && (!empty($payments) || $personId !== null)) {
            return ['error' => 'سند موجودی اول دوره طرف حساب و پرداخت ندارد.', 'statusCode' => 400];
        }
        if ($personId === null && !$isOpening) {
            // Without a counterparty nothing can stay open: payments must cover the total.
            $paid = 0.0;
            foreach ($payments as $p) {
                $paid += parse_money($p['amount'] ?? 0);
            }
            if (abs($paid - $total) > 0.5) {
                return ['error' => 'برای خرید یا فروش نسیه، طرف حساب را انتخاب کنید؛ در غیر این صورت جمع پرداخت‌ها باید با مبلغ سند برابر باشد.', 'statusCode' => 400];
            }
        }
        foreach ($payments as $p) {
            if (!empty($p['date'])) {
                assert_transaction_date_editable($this->conn, $entityId, $p['date']);
            }
        }

        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            $affected = [];
            foreach ($rows as &$r) {
                if ($r['asset_id'] <= 0 && $r['new_asset']) {
                    if ($docType === 'sale') {
                        throw new Exception('دارایی جدید فقط در سند خرید تعریف می‌شود.', 400);
                    }
                    $saved = $this->saveAsset($r['new_asset']);
                    if (!empty($saved['error'])) {
                        throw new Exception($saved['error'], $saved['statusCode'] ?? 400);
                    }
                    $r['asset_id'] = (int)$saved['id'];
                }
                if ($r['asset_id'] <= 0 || !$this->ownsAsset($r['asset_id'])) {
                    throw new Exception('دارایی ردیف سند نامعتبر است.', 400);
                }
                $affected[$r['asset_id']] = true;
            }
            unset($r);

            $desc = trim((string)($data['description'] ?? ''));
            $docNumber = trim((string)($data['doc_number'] ?? ''));
            if ($id > 0) {
                $up = $this->conn->prepare("UPDATE asset_documents SET date = ?, person_id = ?, person_type = ?, description = ?, total_amount = ?, doc_number = ?, is_opening = ? WHERE id = ? AND entity_id = ?");
                $up->bind_param('sissdsiii', $date, $personId, $personType, $desc, $total, $docNumber, $isOpening, $id, $entityId);
                $up->execute();
                $oldItems = $this->conn->prepare("SELECT asset_id FROM asset_document_items WHERE document_id = ?");
                $oldItems->bind_param('i', $id);
                $oldItems->execute();
                foreach (db_stmt_to_assoc_array($oldItems) as $oi) {
                    $affected[(int)$oi['asset_id']] = true;
                }
                $delItems = $this->conn->prepare("DELETE FROM asset_document_items WHERE document_id = ?");
                $delItems->bind_param('i', $id);
                $delItems->execute();
            } else {
                $sourceInvoice = !empty($data['source_purchase_invoice_id']) ? (int)$data['source_purchase_invoice_id'] : null;
                $ins = $this->conn->prepare("INSERT INTO asset_documents (entity_id, doc_type, doc_number, date, person_id, person_type, description, total_amount, paidAmount, source_purchase_invoice_id, is_opening) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                $ins->bind_param('isssissdii', $entityId, $docType, $docNumber, $date, $personId, $personType, $desc, $total, $sourceInvoice, $isOpening);
                $ins->execute();
                $id = $ins->insert_id;
                if ($docNumber === '') {
                    $docNumber = (string)$id;
                    $num = $this->conn->prepare("UPDATE asset_documents SET doc_number = ? WHERE id = ?");
                    $num->bind_param('si', $docNumber, $id);
                    $num->execute();
                }
            }

            $insItem = $this->conn->prepare("INSERT INTO asset_document_items (document_id, asset_id, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?)");
            foreach ($rows as $r) {
                $insItem->bind_param('iiddd', $id, $r['asset_id'], $r['quantity'], $r['unit_price'], $r['amount']);
                $insItem->execute();
            }

            foreach (array_keys($affected) as $assetId) {
                $this->recomputeAsset($assetId);
            }

            if (empty($data['skip_payments'])) {
                require_once __DIR__ . '/Invoice.php';
                $invoiceType = self::invoiceTypeFor($docType);
                $defaultTxn = $docType === 'sale' ? 'receipt' : 'payment';
                $paymentsForSync = [];
                foreach ($payments as $p) {
                    $p['transaction_type'] = $p['transaction_type'] ?? $defaultTxn;
                    $p['date'] = normalize_input_date($p['date'] ?? '') ?: $date;
                    if (($p['type'] ?? 'cash') === 'cash' && empty($p['account_id'])) {
                        throw new Exception('برای پرداخت نقدی، حساب یا صندوق را انتخاب کنید.', 400);
                    }
                    $paymentsForSync[] = $p;
                }
                (new Invoice($this->conn))->syncInvoicePayments($id, $invoiceType, $personId, $personType, $paymentsForSync, $defaultTxn);
            }

            if ($manageTransaction) {
                $this->conn->commit();
            }
            log_activity($this->conn, $old ? 'UPDATE_ASSET_DOCUMENT' : 'CREATE_ASSET_DOCUMENT',
                ($docType === 'sale' ? 'سند فروش دارایی' : 'سند خرید دارایی') . " #{$id} ثبت شد.");
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? (int)$e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $code];
        }
    }

    public function deleteDocument($id) {
        $id = (int)$id;
        $entityId = $this->entityId();
        $st = $this->conn->prepare("SELECT * FROM asset_documents WHERE id = ? AND entity_id = ?");
        $st->bind_param('ii', $id, $entityId);
        $st->execute();
        $doc = db_stmt_to_assoc_array($st)[0] ?? null;
        if (!$doc) {
            return ['error' => 'سند دارایی یافت نشد.', 'statusCode' => 404];
        }
        assert_transaction_date_editable($this->conn, $entityId, $doc['date']);
        $this->conn->begin_transaction();
        try {
            $invoiceType = self::invoiceTypeFor($doc['doc_type']);
            $pay = $this->conn->prepare("SELECT id FROM payments WHERE invoiceType = ? AND invoiceId = ? AND entity_id = ?");
            $pay->bind_param('sii', $invoiceType, $id, $entityId);
            $pay->execute();
            $paymentIds = array_column(db_stmt_to_assoc_array($pay), 'id');
            if ($paymentIds) {
                require_once __DIR__ . '/Payment.php';
                $paymentModel = new Payment($this->conn);
                foreach ($paymentIds as $pid) {
                    $res = $paymentModel->delete((int)$pid, false, false);
                    if (!empty($res['error'])) {
                        throw new Exception($res['error'], $res['statusCode'] ?? 500);
                    }
                }
            }
            $it = $this->conn->prepare("SELECT DISTINCT asset_id FROM asset_document_items WHERE document_id = ?");
            $it->bind_param('i', $id);
            $it->execute();
            $assetIds = array_map('intval', array_column(db_stmt_to_assoc_array($it), 'asset_id'));
            $del = $this->conn->prepare("DELETE FROM asset_documents WHERE id = ? AND entity_id = ?");
            $del->bind_param('ii', $id, $entityId);
            $del->execute();
            foreach ($assetIds as $aid) {
                $this->recomputeAsset($aid);
            }
            $this->conn->commit();
            log_activity($this->conn, 'DELETE_ASSET_DOCUMENT', "سند دارایی #{$id} حذف شد.");
            return ['success' => true];
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? (int)$e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $code];
        }
    }

    /**
     * Moving-average cost, replayed in date order. Sale rows get cost_amount
     * and gain_amount; selling more than is held at that date is refused.
     */
    public function recomputeAsset($assetId) {
        $assetId = (int)$assetId;
        $stmt = $this->conn->prepare(
            "SELECT i.id, i.quantity, i.amount, d.doc_type, d.date, d.doc_number
             FROM asset_document_items i JOIN asset_documents d ON d.id = i.document_id
             WHERE i.asset_id = ? ORDER BY d.date, d.doc_type = 'sale', d.id, i.id"
        );
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $movements = db_stmt_to_assoc_array($stmt);
        $qty = 0.0;
        $cost = 0.0;
        $upd = $this->conn->prepare("UPDATE asset_document_items SET cost_amount = ?, gain_amount = ? WHERE id = ?");
        foreach ($movements as $row) {
            $q = (float)$row['quantity'];
            if ($row['doc_type'] === 'purchase') {
                $qty += $q;
                $cost += (float)$row['amount'];
                $nullCost = null;
                $upd->bind_param('ddi', $nullCost, $nullCost, $row['id']);
                $upd->execute();
                continue;
            }
            if ($q > $qty + 0.00005) {
                $nameSt = $this->conn->prepare("SELECT name, unit FROM assets WHERE id = ?");
                $nameSt->bind_param('i', $assetId);
                $nameSt->execute();
                $a = db_stmt_to_assoc_array($nameSt)[0] ?? ['name' => '', 'unit' => ''];
                throw new Exception("موجودی «{$a['name']}» در تاریخ " . gregorian_to_jalali($row['date']) . " فقط " . rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') . " {$a['unit']} است و فروش " . rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.') . " {$a['unit']} ممکن نیست.", 400);
            }
            $saleCost = $qty > 0 ? round($cost * $q / $qty, 2) : 0.0;
            if (abs($q - $qty) < 0.00005) {
                $saleCost = round($cost, 2); // last unit takes the remaining cost exactly
            }
            $qty -= $q;
            $cost -= $saleCost;
            $gain = round((float)$row['amount'] - $saleCost, 2);
            $upd->bind_param('ddi', $saleCost, $gain, $row['id']);
            $upd->execute();
        }
    }

    // ------------------------------------------------------------------
    // Purchase invoice → asset document
    // ------------------------------------------------------------------

    /** Guess category and tgju price key from a product name. */
    public static function guessFromName($name) {
        $map = [
            ['دلار', 'ارز', 'price_dollar_rl', 'دلار'], ['یورو', 'ارز', 'price_eur', 'یورو'], ['درهم', 'ارز', 'price_aed', 'درهم'],
            ['پوند', 'ارز', 'price_gbp', 'پوند'], ['لیر', 'ارز', 'price_try', 'لیر'],
            ['نیم سکه', 'طلا و سکه', 'nim', 'عدد'], ['ربع سکه', 'طلا و سکه', 'rob', 'عدد'], ['سکه گرمی', 'طلا و سکه', 'gerami', 'عدد'],
            ['بهار آزادی', 'طلا و سکه', 'sekeb', 'عدد'], ['سکه', 'طلا و سکه', 'sekee', 'عدد'],
            ['مثقال', 'طلا و سکه', 'mesghal', 'مثقال'],
            ['طلای 24', 'طلا و سکه', 'geram24', 'گرم'], ['طلای ۲۴', 'طلا و سکه', 'geram24', 'گرم'], ['طلا', 'طلا و سکه', 'geram18', 'گرم'],
        ];
        foreach ($map as [$needle, $cat, $key, $unit]) {
            if (mb_strpos($name, $needle) !== false) {
                return ['category' => $cat, 'price_key' => $key, 'unit' => $unit];
            }
        }
        return ['category' => 'سایر', 'price_key' => null, 'unit' => 'عدد'];
    }

    /**
     * Purchase invoices that can be moved to assets (not consignment, nothing
     * sold from them, no installment plan). Invoices whose items look like
     * currency/gold come first.
     */
    public function listConvertibleInvoices($search = '') {
        $entityId = $this->entityId();
        $where = "pi.entity_id = ? AND pi.is_consignment = 0
                  AND NOT EXISTS (SELECT 1 FROM purchase_invoice_items x JOIN inventory_items ii ON ii.purchase_invoice_item_id = x.id
                                  WHERE x.invoiceId = pi.id AND ii.status <> 'in_stock')
                  AND NOT EXISTS (SELECT 1 FROM installments ins WHERE ins.invoice_type = 'purchase' AND ins.invoice_id = pi.id)";
        $types = 'i';
        $params = [$entityId];
        $search = trim((string)$search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= " AND (pi.invoice_number LIKE ? OR s.name LIKE ? OR pi.description LIKE ?
                        OR EXISTS (SELECT 1 FROM purchase_invoice_items y LEFT JOIN products py ON py.id = y.productId
                                   WHERE y.invoiceId = pi.id AND (py.name LIKE ? OR y.item_description LIKE ?)))";
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $stmt = $this->conn->prepare(
            "SELECT pi.id, pi.invoice_number, pi.date, pi.totalAmount, pi.discount, pi.tax_amount, pi.paidAmount, s.name AS supplier_name,
                    (SELECT GROUP_CONCAT(COALESCE(p.name, it.item_description) SEPARATOR '، ')
                       FROM purchase_invoice_items it LEFT JOIN products p ON p.id = it.productId WHERE it.invoiceId = pi.id) AS items
             FROM purchase_invoices pi LEFT JOIN suppliers s ON s.id = pi.supplierId
             WHERE {$where}
             ORDER BY pi.date DESC, pi.id DESC
             LIMIT 300"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        foreach ($rows as &$r) {
            $r['total'] = invoice_grand_total($r);
            $guess = self::guessFromName((string)$r['items'] . ' ' . (string)$r['supplier_name']);
            $r['suggested'] = $guess['price_key'] !== null ? 1 : 0;
        }
        unset($r);
        usort($rows, function ($a, $b) {
            return [$b['suggested'], $b['date'], (int)$b['id']] <=> [$a['suggested'], $a['date'], (int)$a['id']];
        });
        return ['success' => true, 'rows' => array_slice($rows, 0, 60)];
    }

    /** Purchase invoice id from what the user typed: its number first, else its id. */
    public function resolvePurchaseInvoiceRef($ref) {
        $ref = trim(strtr((string)$ref, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '#' => '']));
        if ($ref === '') {
            return 0;
        }
        $entityId = $this->entityId();
        $st = $this->conn->prepare("SELECT id FROM purchase_invoices WHERE entity_id = ? AND invoice_number = ? LIMIT 1");
        $st->bind_param('is', $entityId, $ref);
        $st->execute();
        $row = db_stmt_to_assoc_array($st)[0] ?? null;
        if ($row) {
            return (int)$row['id'];
        }
        return ctype_digit($ref) ? (int)$ref : 0;
    }

    /** What a purchase invoice would become (for the confirmation dialog). */
    public function previewPurchaseInvoiceConversion($invoiceId) {
        $inv = $this->loadConvertibleInvoice((int)$invoiceId);
        if (!empty($inv['error'])) {
            return $inv;
        }
        return ['success' => true, 'invoice' => $inv['invoice'], 'items' => $inv['items']];
    }

    private function loadConvertibleInvoice($invoiceId) {
        $entityId = $this->entityId();
        $st = $this->conn->prepare("SELECT pi.*, s.name AS supplier_name FROM purchase_invoices pi LEFT JOIN suppliers s ON s.id = pi.supplierId WHERE pi.id = ? AND pi.entity_id = ?");
        $st->bind_param('ii', $invoiceId, $entityId);
        $st->execute();
        $invoice = db_stmt_to_assoc_array($st)[0] ?? null;
        if (!$invoice) {
            return ['error' => 'فاکتور خرید یافت نشد.', 'statusCode' => 404];
        }
        if ((int)($invoice['is_consignment'] ?? 0) === 1) {
            return ['error' => 'فاکتور امانی قابل تبدیل به سند دارایی نیست.', 'statusCode' => 400];
        }
        $it = $this->conn->prepare(
            "SELECT pii.id, pii.productId, pii.quantity, pii.unitPrice, COALESCE(pii.item_description, '') AS item_description,
                    COALESCE(p.name, pii.item_description) AS name,
                    (SELECT COUNT(*) FROM inventory_items ii WHERE ii.purchase_invoice_item_id = pii.id AND ii.status <> 'in_stock') AS used
             FROM purchase_invoice_items pii LEFT JOIN products p ON p.id = pii.productId
             WHERE pii.invoiceId = ? ORDER BY pii.id"
        );
        $it->bind_param('i', $invoiceId);
        $it->execute();
        $items = db_stmt_to_assoc_array($it);
        if (empty($items)) {
            return ['error' => 'این فاکتور ردیفی ندارد.', 'statusCode' => 400];
        }
        foreach ($items as &$row) {
            if ((int)$row['used'] > 0) {
                return ['error' => "ردیف «{$row['name']}» فروخته یا جابه‌جا شده است؛ این فاکتور قابل تبدیل نیست.", 'statusCode' => 409];
            }
            $row['quantity'] = (float)$row['quantity'];
            $row['unitPrice'] = (float)$row['unitPrice'];
            $row['amount'] = round($row['quantity'] * $row['unitPrice'], 2);
            $row += self::guessFromName((string)$row['name']);
        }
        unset($row);
        $inst = $this->conn->prepare("SELECT COUNT(*) AS n FROM installments WHERE invoice_type = 'purchase' AND invoice_id = ? AND entity_id = ?");
        $inst->bind_param('ii', $invoiceId, $entityId);
        $inst->execute();
        if ((int)(db_stmt_to_assoc_array($inst)[0]['n'] ?? 0) > 0) {
            return ['error' => 'این فاکتور طرح اقساط دارد؛ ابتدا اقساط را حذف کنید.', 'statusCode' => 409];
        }
        return ['invoice' => $invoice, 'items' => $items];
    }

    /**
     * Move a purchase invoice (bought as stock by mistake) into an asset purchase
     * document: same date, supplier and money movements. $rows lets the user state
     * the real quantity per row (e.g. 400 dollars instead of 1); the row amount is kept.
     */
    public function convertPurchaseInvoice($invoiceId, array $rows = []) {
        $invoiceId = (int)$invoiceId;
        $entityId = $this->entityId();
        $loaded = $this->loadConvertibleInvoice($invoiceId);
        if (!empty($loaded['error'])) {
            return $loaded;
        }
        $invoice = $loaded['invoice'];
        assert_transaction_date_editable($this->conn, $entityId, $invoice['date']);
        $overrides = [];
        foreach ($rows as $r) {
            $overrides[(int)($r['item_id'] ?? 0)] = $r;
        }
        // Discount and tax are spread over the rows so the document total matches the invoice.
        $itemsTotal = array_sum(array_column($loaded['items'], 'amount'));
        $grand = invoice_grand_total($invoice);
        $factor = $itemsTotal > 0 ? $grand / $itemsTotal : 1;

        $docItems = [];
        foreach ($loaded['items'] as $row) {
            $ov = $overrides[(int)$row['id']] ?? [];
            $qty = isset($ov['quantity']) && self::qty($ov['quantity']) > 0 ? self::qty($ov['quantity']) : $row['quantity'];
            $amount = round($row['amount'] * $factor, 2);
            $name = trim((string)($ov['asset_name'] ?? '')) ?: trim((string)$row['name']) ?: 'دارایی';
            $existing = $this->conn->prepare("SELECT id FROM assets WHERE entity_id = ? AND name = ? LIMIT 1");
            $existing->bind_param('is', $entityId, $name);
            $existing->execute();
            $assetId = (int)(db_stmt_to_assoc_array($existing)[0]['id'] ?? 0);
            $guess = self::guessFromName($name);
            $docItems[] = [
                'asset_id' => $assetId,
                'new_asset' => $assetId ? null : [
                    'name' => $name,
                    'category' => $ov['category'] ?? $guess['category'],
                    'unit' => trim((string)($ov['unit'] ?? '')) ?: $guess['unit'],
                    'price_key' => array_key_exists('price_key', $ov) ? $ov['price_key'] : $guess['price_key'],
                ],
                'quantity' => $qty,
                'unit_price' => $qty > 0 ? round($amount / $qty, 6) : 0,
            ];
        }

        $this->conn->begin_transaction();
        try {
            $saved = $this->saveDocument([
                'doc_type' => 'purchase',
                'date' => $invoice['date'],
                'person_type' => !empty($invoice['supplierId']) ? 'supplier' : null,
                'person_id' => $invoice['supplierId'] ?: null,
                'description' => trim('تبدیل‌شده از فاکتور خرید ' . ($invoice['invoice_number'] ?: '#' . $invoiceId) . '. ' . (string)$invoice['description']),
                'items' => $docItems,
                'skip_payments' => true,
                'source_purchase_invoice_id' => $invoiceId,
            ], false);
            if (!empty($saved['error'])) {
                throw new Exception($saved['error'], $saved['statusCode'] ?? 400);
            }
            $docId = (int)$saved['id'];
            // Rounding of unit prices must not change the document total: the last row absorbs it.
            $sumRes = $this->conn->query("SELECT COALESCE(SUM(amount), 0) AS s, MAX(id) AS last_id FROM asset_document_items WHERE document_id = {$docId}");
            $sumRow = $sumRes->fetch_assoc();
            $diff = round($grand - (float)$sumRow['s'], 2);
            if (abs($diff) >= 0.01) {
                $this->conn->query("UPDATE asset_document_items SET amount = amount + ({$diff}) WHERE id = " . (int)$sumRow['last_id']);
                $aRes = $this->conn->query("SELECT asset_id FROM asset_document_items WHERE id = " . (int)$sumRow['last_id']);
                $this->recomputeAsset((int)$aRes->fetch_assoc()['asset_id']);
            }
            $fix = $this->conn->prepare("UPDATE asset_documents SET total_amount = ?, paidAmount = ? WHERE id = ?");
            $paid = (float)$invoice['paidAmount'];
            $fix->bind_param('ddi', $grand, $paid, $docId);
            $fix->execute();

            // Payments and checks follow the money to the new document.
            $mv = $this->conn->prepare("UPDATE payments SET invoiceType = 'asset_purchase', invoiceId = ?, is_allocated = 0 WHERE invoiceType = 'purchase' AND invoiceId = ? AND entity_id = ?");
            $mv->bind_param('iii', $docId, $invoiceId, $entityId);
            $mv->execute();
            $mc = $this->conn->prepare("UPDATE checks SET invoiceType = 'asset_purchase', invoiceId = ? WHERE invoiceType = 'purchase' AND invoiceId = ? AND entity_id = ?");
            $mc->bind_param('iii', $docId, $invoiceId, $entityId);
            $mc->execute();

            $itemIds = array_map('intval', array_column($loaded['items'], 'id'));
            $productIds = array_values(array_unique(array_filter(array_map('intval', array_column($loaded['items'], 'productId')))));
            $ids = implode(',', $itemIds);
            $this->conn->query("DELETE FROM inventory_items WHERE purchase_invoice_item_id IN ({$ids}) AND status = 'in_stock'");
            $this->conn->query("DELETE FROM purchase_invoice_items WHERE invoiceId = {$invoiceId}");
            $del = $this->conn->prepare("DELETE FROM purchase_invoices WHERE id = ? AND entity_id = ?");
            $del->bind_param('ii', $invoiceId, $entityId);
            $del->execute();

            // Products that existed only for this purchase are removed.
            $removedProducts = 0;
            foreach ($productIds as $pid) {
                $refs = 0;
                foreach (['sales_invoice_items' => 'productId', 'purchase_invoice_items' => 'productId', 'return_invoice_items' => 'productId',
                          'quotation_items' => 'productId', 'stocktaking_items' => 'product_id', 'inventory_items' => 'product_id'] as $tbl => $col) {
                    $r = $this->conn->query("SELECT COUNT(*) AS n FROM `{$tbl}` WHERE `{$col}` = {$pid}");
                    $refs += $r ? (int)$r->fetch_assoc()['n'] : 1;
                }
                if ($refs === 0) {
                    $this->conn->query("DELETE FROM products WHERE id = {$pid} AND entity_id = {$entityId}");
                    $removedProducts += $this->conn->affected_rows > 0 ? 1 : 0;
                }
            }

            $this->conn->commit();
            log_activity($this->conn, 'CONVERT_PURCHASE_TO_ASSET', "فاکتور خرید #{$invoiceId} به سند خرید دارایی #{$docId} تبدیل شد.");
            return ['success' => true, 'id' => $docId, 'removed_products' => $removedProducts];
        } catch (Exception $e) {
            $this->conn->rollback();
            $code = is_numeric($e->getCode()) && $e->getCode() > 0 ? (int)$e->getCode() : 500;
            return ['error' => $e->getMessage(), 'statusCode' => $code];
        }
    }

    // ------------------------------------------------------------------
    // Effects on other parts of the app
    // ------------------------------------------------------------------

    /**
     * Net position from asset documents per person (positive = the person owes us),
     * up to $endDate. Used by every person-balance computation.
     */
    public static function personNet($conn, $entityId, $personType, array $personIds, $endDate = null) {
        $out = [];
        $personIds = array_values(array_filter(array_map('intval', $personIds)));
        if (empty($personIds) || !isset(self::PERSON_TABLES[$personType]) || !self::tablesReady($conn)) {
            return $out;
        }
        $endDate = $endDate ?: '9999-12-31';
        $ids = implode(',', $personIds);
        $stmt = $conn->prepare(
            "SELECT d.person_id,
                    SUM(CASE WHEN d.doc_type = 'sale' THEN d.total_amount ELSE -d.total_amount END) AS docs
             FROM asset_documents d
             WHERE d.entity_id = ? AND d.person_type = ? AND d.person_id IN ({$ids}) AND d.date <= ?
             GROUP BY d.person_id"
        );
        $stmt->bind_param('iss', $entityId, $personType, $endDate);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $r) {
            $out[(int)$r['person_id']] = (float)$r['docs'];
        }
        $stmt = $conn->prepare(
            "SELECT d.person_id,
                    SUM(IF(p.transaction_type = 'receipt', -p.amount, p.amount)) AS paid
             FROM payments p
             JOIN asset_documents d ON d.id = p.invoiceId AND p.invoiceType IN ('asset_purchase','asset_sale')
             WHERE p.entity_id = ? AND d.person_type = ? AND d.person_id IN ({$ids}) AND p.date <= ?
             GROUP BY d.person_id"
        );
        $stmt->bind_param('iss', $entityId, $personType, $endDate);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $r) {
            $pid = (int)$r['person_id'];
            $out[$pid] = ($out[$pid] ?? 0) + (float)$r['paid'];
        }
        return $out;
    }

    /**
     * SQL expression: what the person ($idExpr of $personType) owes us through
     * asset documents (positive = owes us). Entity id is an int baked in.
     */
    public static function sqlPersonNet($idExpr, $personType, $entityId) {
        $e = (int)$entityId;
        $t = $personType === 'customer' ? 'customer' : ($personType === 'contact' ? 'contact' : 'supplier');
        return "((SELECT COALESCE(SUM(IF(ad.doc_type = 'sale', ad.total_amount, -ad.total_amount)), 0)
                  FROM asset_documents ad WHERE ad.entity_id = {$e} AND ad.person_type = '{$t}' AND ad.person_id = {$idExpr})
               + (SELECT COALESCE(SUM(IF(ap.transaction_type = 'receipt', -ap.amount, ap.amount)), 0)
                  FROM payments ap JOIN asset_documents ad2 ON ad2.id = ap.invoiceId AND ap.invoiceType IN ('asset_purchase','asset_sale')
                  WHERE ap.entity_id = {$e} AND ad2.person_type = '{$t}' AND ad2.person_id = {$idExpr}))";
    }

    /** Realized gain/loss of asset sales dated in [start, end]. */
    public static function realizedGain($conn, $entityId, $startDate, $endDate) {
        if (!self::tablesReady($conn)) {
            return 0.0;
        }
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(i.gain_amount), 0) AS g
             FROM asset_document_items i JOIN asset_documents d ON d.id = i.document_id
             WHERE d.entity_id = ? AND d.doc_type = 'sale' AND d.date BETWEEN ? AND ?"
        );
        $stmt->bind_param('iss', $entityId, $startDate, $endDate);
        $stmt->execute();
        return round((float)(db_stmt_to_assoc_array($stmt)[0]['g'] ?? 0), 2);
    }

    /** Book value (remaining moving-average cost) of all assets at a date. */
    public static function bookValue($conn, $entityId, $asOfDate = null) {
        if (!self::tablesReady($conn)) {
            return 0.0;
        }
        $asOfDate = $asOfDate ?: '9999-12-31';
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(CASE WHEN d.doc_type = 'purchase' THEN i.amount ELSE -COALESCE(i.cost_amount, 0) END), 0) AS v
             FROM asset_document_items i JOIN asset_documents d ON d.id = i.document_id
             WHERE d.entity_id = ? AND d.date <= ?"
        );
        $stmt->bind_param('is', $entityId, $asOfDate);
        $stmt->execute();
        return round((float)(db_stmt_to_assoc_array($stmt)[0]['v'] ?? 0), 2);
    }

    /** Statement rows (documents + their payments) for one person. */
    public static function statementRows($conn, $entityId, $personType, $personId, $startDate, $endDate) {
        if (!self::tablesReady($conn) || !isset(self::PERSON_TABLES[$personType])) {
            return [];
        }
        $rows = [];
        $stmt = $conn->prepare(
            "SELECT id, doc_type, doc_number, date, description, total_amount FROM asset_documents
             WHERE entity_id = ? AND person_type = ? AND person_id = ? AND date BETWEEN ? AND ?"
        );
        $stmt->bind_param('isiss', $entityId, $personType, $personId, $startDate, $endDate);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $d) {
            $rows[] = [
                'date' => $d['date'],
                'kind' => 'asset_document',
                'id' => (int)$d['id'],
                'description' => ($d['doc_type'] === 'sale' ? 'سند فروش دارایی #' : 'سند خرید دارایی #') . $d['doc_number']
                    . (trim((string)$d['description']) !== '' ? ' - ' . $d['description'] : ''),
                // + they owe us more, − we owe them more
                'signed' => $d['doc_type'] === 'sale' ? (float)$d['total_amount'] : -(float)$d['total_amount'],
            ];
        }
        $stmt = $conn->prepare(
            "SELECT p.id, p.date, p.amount, p.transaction_type, p.type, d.doc_number, d.doc_type
             FROM payments p JOIN asset_documents d ON d.id = p.invoiceId AND p.invoiceType IN ('asset_purchase','asset_sale')
             WHERE p.entity_id = ? AND d.person_type = ? AND d.person_id = ? AND p.date BETWEEN ? AND ?"
        );
        $stmt->bind_param('isiss', $entityId, $personType, $personId, $startDate, $endDate);
        $stmt->execute();
        foreach (db_stmt_to_assoc_array($stmt) as $p) {
            $kind = ['cash' => 'نقدی', 'check' => 'چک', 'endorse_check' => 'خرج چک', 'check_bounce' => 'برگشت چک'][$p['type']] ?? $p['type'];
            $rows[] = [
                'date' => $p['date'],
                'kind' => 'asset_payment',
                'id' => (int)$p['id'],
                'description' => ($p['transaction_type'] === 'receipt' ? 'دریافت ' : 'پرداخت ') . $kind
                    . ' بابت سند ' . ($p['doc_type'] === 'sale' ? 'فروش' : 'خرید') . ' دارایی #' . $p['doc_number'],
                'signed' => $p['transaction_type'] === 'receipt' ? -(float)$p['amount'] : (float)$p['amount'],
            ];
        }
        return $rows;
    }

    private static function tablesReady($conn) {
        static $ready = null;
        if ($ready === null) {
            $res = $conn->query("SHOW TABLES LIKE 'asset_documents'");
            $ready = $res && $res->num_rows > 0;
        }
        return $ready;
    }
}
