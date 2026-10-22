<?php
// /api/core/DocumentTrash.php

/**
 * سطل بازیافت: every successful delete of a document/master record leaves a
 * JSON snapshot behind (who, when, full content). Simple records can be
 * restored; invoices/quotations can be re-opened as a pre-filled new document.
 *
 * Hooked centrally in api/index.php: the snapshot is taken before the delete
 * action runs and stored only if, after the request, the row is really gone.
 */
class DocumentTrash {
    /** action => [doc_type, table, id field in request, label] */
    const ACTIONS = [
        'delete_sales_invoice' => ['sales_invoice', 'sales_invoices', 'id', 'فاکتور فروش'],
        'delete_purchase_invoice' => ['purchase_invoice', 'purchase_invoices', 'id', 'فاکتور خرید'],
        'delete_sales_return' => ['sales_return', 'return_invoices', 'id', 'مرجوعی فروش'],
        'delete_purchase_return' => ['purchase_return', 'return_invoices', 'id', 'مرجوعی خرید'],
        'delete_quotation' => ['quotation', 'quotations', 'id', 'پیش فاکتور'],
        'delete_payment' => ['payment', 'payments', 'id', 'تراکنش'],
        'delete_expense' => ['expense', 'expenses', 'id', 'هزینه'],
        'delete_income' => ['income', 'incomes', 'id', 'درآمد'],
        'delete_check' => ['check', 'checks', 'id', 'چک'],
        'delete_customer' => ['customer', 'customers', 'id', 'مشتری'],
        'delete_supplier' => ['supplier', 'suppliers', 'id', 'تأمین‌کننده'],
        'delete_contact' => ['contact', 'contacts', 'id', 'سایر اشخاص'],
        'delete_product' => ['product', 'products', 'id', 'کالا'],
    ];

    /** Types that can be put back with the same content (new id). */
    const RESTORABLE = ['customer', 'supplier', 'contact', 'expense', 'income'];
    /** Types the UI can re-open as a pre-filled new document. */
    const REOPENABLE = ['sales_invoice', 'purchase_invoice', 'quotation'];

    private static $pending = null;

    public static function captureBeforeAction(mysqli $db, $action, array $data) {
        if (!isset(self::ACTIONS[$action])) {
            return;
        }
        list($docType, $table, $idKey, $label) = self::ACTIONS[$action];
        $id = (int)($data[$idKey] ?? 0);
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        if ($id <= 0 || $entityId <= 0) {
            return;
        }
        try {
            $snapshot = self::buildSnapshot($db, $docType, $table, $id, $entityId);
        } catch (Throwable $e) {
            error_log('Trash snapshot failed: ' . $e->getMessage());
            return;
        }
        if (!$snapshot) {
            return;
        }
        self::$pending = compact('docType', 'table', 'id', 'entityId', 'snapshot', 'label');
        register_shutdown_function([self::class, 'finalize'], $db);
    }

    public static function finalize(mysqli $db) {
        $p = self::$pending;
        self::$pending = null;
        if (!$p) {
            return;
        }
        try {
            $st = $db->prepare("SELECT id FROM `{$p['table']}` WHERE id = ?");
            $st->bind_param('i', $p['id']);
            $st->execute();
            if (!empty(db_stmt_to_assoc_array($st))) {
                return; // delete failed or was refused
            }
            $row = $p['snapshot']['row'];
            $title = self::titleFor($p['docType'], $row, $p['label']);
            $amount = null;
            foreach (['totalAmount', 'amount'] as $k) {
                if (isset($row[$k])) { $amount = (float)$row[$k]; break; }
            }
            $date = $row['date'] ?? ($row['dueDate'] ?? null);
            $json = json_encode($p['snapshot'], JSON_UNESCAPED_UNICODE);
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $userName = $_SESSION['username'] ?? null;
            $ins = $db->prepare(
                "INSERT INTO document_trash (entity_id, doc_type, doc_id, title, amount, doc_date, snapshot_json, deleted_by, deleted_by_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('isisdssis', $p['entityId'], $p['docType'], $p['id'], $title, $amount, $date, $json, $userId, $userName);
            $ins->execute();
            // Keep the bin bounded: 2000 newest rows per entity.
            $db->query("DELETE FROM document_trash WHERE entity_id = " . (int)$p['entityId'] . " AND id < (SELECT id FROM (SELECT id FROM document_trash WHERE entity_id = " . (int)$p['entityId'] . " ORDER BY id DESC LIMIT 1 OFFSET 1999) t)");
        } catch (Throwable $e) {
            error_log('Trash finalize failed: ' . $e->getMessage());
        }
    }

    private static function fetchAll(mysqli $db, $sql, $types, array $params) {
        $st = $db->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        return db_stmt_to_assoc_array($st);
    }

    private static function buildSnapshot(mysqli $db, $docType, $table, $id, $entityId) {
        $row = self::fetchAll($db, "SELECT * FROM `{$table}` WHERE id = ? AND entity_id = ?", 'ii', [$id, $entityId])[0] ?? null;
        if (!$row) {
            return null;
        }
        $snap = ['row' => $row];
        switch ($docType) {
            case 'sales_invoice':
            case 'purchase_invoice':
                $itemsTable = $docType === 'sales_invoice' ? 'sales_invoice_items' : 'purchase_invoice_items';
                $snap['items'] = self::fetchAll($db, "SELECT i.*, p.name AS productName FROM `{$itemsTable}` i LEFT JOIN products p ON p.id = i.productId WHERE i.invoiceId = ?", 'i', [$id]);
                $invType = $docType === 'sales_invoice' ? 'sales' : 'purchase';
                $snap['payments'] = self::fetchAll($db, "SELECT p.*, c.checkNumber, c.dueDate AS checkDueDate, c.bankName FROM payments p LEFT JOIN checks c ON c.id = p.checkId WHERE p.invoiceType = ? AND p.invoiceId = ? AND p.entity_id = ?", 'sii', [$invType, $id, $entityId]);
                $personTable = $docType === 'sales_invoice' ? 'customers' : 'suppliers';
                $personId = (int)($row[$docType === 'sales_invoice' ? 'customerId' : 'supplierId'] ?? 0);
                $snap['person'] = self::fetchAll($db, "SELECT id, name, phone FROM `{$personTable}` WHERE id = ?", 'i', [$personId])[0] ?? null;
                break;
            case 'sales_return':
            case 'purchase_return':
                $snap['items'] = self::fetchAll($db, "SELECT i.*, p.name AS productName FROM return_invoice_items i LEFT JOIN products p ON p.id = i.productId WHERE i.return_invoice_id = ?", 'i', [$id]);
                $snap['payments'] = self::fetchAll($db, "SELECT * FROM payments WHERE invoiceType = 'return' AND invoiceId = ? AND entity_id = ?", 'ii', [$id, $entityId]);
                break;
            case 'quotation':
                $snap['items'] = self::fetchAll($db, "SELECT i.*, p.name AS productName FROM quotation_items i LEFT JOIN products p ON p.id = i.productId WHERE i.quotationId = ?", 'i', [$id]);
                break;
            case 'check':
                $snap['payments'] = self::fetchAll($db, "SELECT * FROM payments WHERE checkId = ? AND entity_id = ?", 'ii', [$id, $entityId]);
                break;
        }
        return $snap;
    }

    private static function titleFor($docType, array $row, $label) {
        $num = $row['invoice_number'] ?? null;
        switch ($docType) {
            case 'customer': case 'supplier': case 'contact': case 'product':
                return $label . ': ' . ($row['name'] ?? ('#' . $row['id']));
            case 'expense': case 'income':
                return $label . ': ' . ($row['category'] ?? '') . (!empty($row['description']) ? ' - ' . mb_substr($row['description'], 0, 60) : '');
            case 'check':
                return $label . ' شماره ' . ($row['checkNumber'] ?? '');
            case 'payment':
                return $label . ' ' . (($row['transaction_type'] ?? '') === 'receipt' ? 'دریافت' : 'پرداخت') . (!empty($row['description']) ? ' - ' . mb_substr($row['description'], 0, 60) : '');
            default:
                return $label . ' #' . ($num ?: $row['id']);
        }
    }

    public static function listForEntity(mysqli $db, $entityId, $docType = '', $limit = 200) {
        $sql = "SELECT id, doc_type, doc_id, title, amount, doc_date, deleted_by_name, deleted_at, restored_at, restored_doc_id
                FROM document_trash WHERE entity_id = ?";
        $types = 'i';
        $params = [(int)$entityId];
        if ($docType !== '') {
            $sql .= " AND doc_type = ?";
            $types .= 's';
            $params[] = $docType;
        }
        $sql .= " ORDER BY id DESC LIMIT " . max(1, min(1000, (int)$limit));
        $rows = self::fetchAll($db, $sql, $types, $params);
        foreach ($rows as &$r) {
            $r['restorable'] = in_array($r['doc_type'], self::RESTORABLE, true) && empty($r['restored_at']);
            $r['reopenable'] = in_array($r['doc_type'], self::REOPENABLE, true);
        }
        unset($r);
        return $rows;
    }

    public static function get(mysqli $db, $entityId, $trashId) {
        $row = self::fetchAll($db, "SELECT * FROM document_trash WHERE id = ? AND entity_id = ?", 'ii', [(int)$trashId, (int)$entityId])[0] ?? null;
        if ($row) {
            $row['snapshot'] = json_decode($row['snapshot_json'], true);
            unset($row['snapshot_json']);
        }
        return $row;
    }

    /**
     * Restore a simple record through its model (so balances and validation
     * apply). Returns ['success'=>true,'id'=>newId] or error.
     */
    public static function restore(mysqli $db, $entityId, $trashId) {
        $t = self::get($db, $entityId, $trashId);
        if (!$t) {
            return ['error' => 'مورد یافت نشد.', 'statusCode' => 404];
        }
        if (!empty($t['restored_at'])) {
            return ['error' => 'این مورد قبلاً بازیابی شده است.', 'statusCode' => 400];
        }
        $row = $t['snapshot']['row'] ?? [];
        unset($row['id']);
        switch ($t['doc_type']) {
            case 'customer':
                require_once __DIR__ . '/../models/Customer.php';
                $res = (new Customer($db))->save($row);
                break;
            case 'supplier':
                require_once __DIR__ . '/../models/Supplier.php';
                $res = (new Supplier($db))->save($row);
                break;
            case 'contact':
                require_once __DIR__ . '/../models/Contact.php';
                $res = (new Contact($db))->save($row);
                break;
            case 'expense':
                require_once __DIR__ . '/../models/Expense.php';
                $res = (new Expense($db))->save($row);
                break;
            case 'income':
                require_once __DIR__ . '/../models/Income.php';
                $res = (new Income($db))->save($row);
                break;
            default:
                return ['error' => 'این نوع سند به‌صورت خودکار قابل بازیابی نیست.', 'statusCode' => 400];
        }
        if (empty($res['success'])) {
            return ['error' => $res['error'] ?? 'بازیابی ناموفق بود.', 'statusCode' => $res['statusCode'] ?? 400];
        }
        $u = $db->prepare("UPDATE document_trash SET restored_at = NOW(), restored_doc_id = ? WHERE id = ?");
        $newId = (int)$res['id'];
        $tid = (int)$trashId;
        $u->bind_param('ii', $newId, $tid);
        $u->execute();
        return ['success' => true, 'id' => $newId];
    }
}
