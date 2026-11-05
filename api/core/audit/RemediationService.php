<?php
// api/core/audit/RemediationService.php

require_once __DIR__ . '/../../models/Account.php';

class AuditRemediationService {
    private $conn;
    private $accountModel;

    /** rule_id / type values that are safe_auto */
    public const SAFE_AUTO_TYPES = [
        'account_balance_mismatch',
        'financial_discrepancy',
        'orphaned_record',
        'payment_mismatch',
        'inventory_status_mismatch',
        'sold_without_sales_link',
        'broken_purchase_link',
        'duplicate_sold_inventory',
    ];

    public function __construct($db) {
        $this->conn = $db;
        $this->accountModel = new Account($db);
    }

    public function isSafeAuto(string $type): bool {
        return in_array($type, self::SAFE_AUTO_TYPES, true);
    }

    /**
     * @return array{success:bool,message?:string,error?:string,preview?:array}
     */
    public function preview(array $data): array {
        $type = $data['type'] ?? $data['rule_id'] ?? '';
        $refId = intval($data['reference_id'] ?? 0);
        if (!$type || !$refId) {
            return ['success' => false, 'error' => 'اطلاعات ناقص است.'];
        }
        if ($type === 'unlock_user') {
            $stmt = $this->conn->prepare("SELECT id, username, lockout_until FROM users WHERE id = ?");
            $stmt->bind_param('i', $refId);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            if (!$rows) return ['success' => false, 'error' => 'کاربر یافت نشد.'];
            return [
                'success' => true,
                'preview' => [
                    'action' => 'unlock_user',
                    'before' => $rows[0],
                    'after' => ['lockout_until' => null, 'failed_login_attempts' => 0],
                ],
            ];
        }
        if ($this->isSafeAuto($type)) {
            return ['success' => true, 'preview' => ['action' => $type, 'reference_id' => $refId, 'note' => 'اصلاح امن با یک کلیک']];
        }
        return ['success' => false, 'error' => 'این مورد پیش‌نمایش guided جداگانه ندارد.'];
    }

    /**
     * Apply safe_auto or supported guided fixes.
     * @return array{success:bool,message?:string,error?:string}
     */
    public function apply(array $data, int $entityId): array {
        $type = $data['type'] ?? $data['rule_id'] ?? '';
        $refId = intval($data['reference_id'] ?? 0);
        if (!$type || (!$refId && $type !== 'missing_setting')) {
            return ['success' => false, 'error' => 'اطلاعات ناقص است.'];
        }

        if ($type === 'unlock_user') {
            return $this->unlockUser($refId);
        }

        if (!$this->isSafeAuto($type)) {
            return ['success' => false, 'error' => 'نوع خطا برای اصلاح خودکار پشتیبانی نمی‌شود.'];
        }

        $this->conn->begin_transaction();
        try {
            $result = $this->applySafeAuto($type, $refId, $entityId, $data);
            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'عملیات اصلاح انجام نشد.');
            }
            $this->conn->commit();
            log_activity($this->conn, 'SMART_AUDIT_FIX', ($result['message'] ?? '') . " (شناسه: $refId)");
            return $result;
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => 'خطا در اصلاح مورد: ' . $e->getMessage()];
        }
    }

    private function unlockUser(int $userId): array {
        $stmt = $this->conn->prepare("UPDATE users SET lockout_until = NULL, failed_login_attempts = 0 WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        if ($stmt->affected_rows < 0) {
            return ['success' => false, 'error' => 'خطا در باز کردن قفل کاربر.'];
        }
        log_activity($this->conn, 'SMART_AUDIT_FIX', "قفل کاربر #{$userId} باز شد.");
        return ['success' => true, 'message' => 'قفل کاربر باز شد.'];
    }

    private function applySafeAuto(string $type, int $refId, int $entityId, array $data): array {
        switch ($type) {
            case 'account_balance_mismatch':
                $ledgerData = $this->accountModel->getLedger($refId);
                if (isset($ledgerData['error'])) throw new Exception('حساب یافت نشد.');
                $running = (float)$ledgerData['account']['initial_balance'];
                foreach ($ledgerData['transactions'] ?? [] as $tx) {
                    if (isset($tx['is_consignment']) && $tx['is_consignment'] == 1) continue;
                    $amount = (float)$tx['amount'];
                    $source = $tx['source'] ?? '';
                    if (str_ends_with($source, '_out') || $source === 'expense') $running -= $amount;
                    else $running += $amount;
                }
                $correct = round($running, 2);
                $stmt = $this->conn->prepare("UPDATE accounts SET current_balance = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param('dii', $correct, $refId, $entityId);
                $stmt->execute();
                return ['success' => true, 'message' => 'موجودی حساب اصلاح شد.'];

            case 'financial_discrepancy':
                $table = ($data['reference_table'] === 'sales_invoices') ? 'sales_invoices' : 'purchase_invoices';
                $itemTable = ($table === 'sales_invoices') ? 'sales_invoice_items' : 'purchase_invoice_items';
                $stmt = $this->conn->prepare("SELECT SUM(quantity * unitPrice) as new_total FROM {$itemTable} WHERE invoiceId = ?");
                $stmt->bind_param('i', $refId);
                $stmt->execute();
                $newTotal = floatval(db_stmt_to_assoc_array($stmt)[0]['new_total'] ?? 0);
                $stmt = $this->conn->prepare("UPDATE {$table} SET totalAmount = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param('dii', $newTotal, $refId, $entityId);
                $stmt->execute();
                return ['success' => true, 'message' => 'جمع کل فاکتور اصلاح شد.'];

            case 'orphaned_record':
                $table = $data['reference_table'] ?? '';
                if (!in_array($table, ['sales_invoice_items', 'purchase_invoice_items', 'payments', 'inventory_items'], true)) {
                    throw new Exception('جدول برای حذف یتیم مجاز نیست.');
                }
                if ($table === 'payments') {
                    $stmt = $this->conn->prepare("DELETE FROM payments WHERE id = ? AND entity_id = ?");
                    $stmt->bind_param('ii', $refId, $entityId);
                } elseif ($table === 'inventory_items') {
                    // Scope via owning product's entity so a cross-entity id can't be deleted.
                    $stmt = $this->conn->prepare(
                        "DELETE ii FROM inventory_items ii
                         JOIN products p ON p.id = ii.product_id
                         WHERE ii.id = ? AND p.entity_id = ?"
                    );
                    $stmt->bind_param('ii', $refId, $entityId);
                } elseif ($table === 'sales_invoice_items') {
                    $stmt = $this->conn->prepare(
                        "DELETE sii FROM sales_invoice_items sii
                         JOIN sales_invoices si ON si.id = sii.invoiceId
                         WHERE sii.id = ? AND si.entity_id = ?"
                    );
                    $stmt->bind_param('ii', $refId, $entityId);
                } else {
                    $stmt = $this->conn->prepare(
                        "DELETE pii FROM purchase_invoice_items pii
                         JOIN purchase_invoices pi ON pi.id = pii.invoiceId
                         WHERE pii.id = ? AND pi.entity_id = ?"
                    );
                    $stmt->bind_param('ii', $refId, $entityId);
                }
                $stmt->execute();
                return ['success' => true, 'message' => 'رکورد یتیم حذف شد.'];

            case 'payment_mismatch':
                $table = $data['reference_table'] ?? '';
                if (!in_array($table, ['sales_invoices', 'purchase_invoices'], true)) {
                    throw new Exception('جدول فاکتور نامعتبر است.');
                }
                $invoiceType = ($table === 'sales_invoices') ? 'sales' : 'purchase';
                $stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount),0) as total_paid FROM payments WHERE invoiceId = ? AND invoiceType = ? AND entity_id = ?");
                $stmt->bind_param('isi', $refId, $invoiceType, $entityId);
                $stmt->execute();
                $totalPaid = floatval(db_stmt_to_assoc_array($stmt)[0]['total_paid'] ?? 0);
                $stmt = $this->conn->prepare("UPDATE {$table} SET paidAmount = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param('dii', $totalPaid, $refId, $entityId);
                $stmt->execute();
                return ['success' => true, 'message' => 'مبلغ پرداختی فاکتور اصلاح شد.'];

            case 'inventory_status_mismatch':
                $stmt = $this->conn->prepare(
                    "SELECT ii.id FROM inventory_items ii
                     JOIN products p ON p.id = ii.product_id
                     WHERE ii.id = ? AND p.entity_id = ?
                       AND ii.sales_invoice_item_id IS NOT NULL AND ii.sales_invoice_item_id != 0"
                );
                $stmt->bind_param('ii', $refId, $entityId);
                $stmt->execute();
                if (empty(db_stmt_to_assoc_array($stmt))) {
                    throw new Exception('امکان اصلاح خودکار برای این مورد وجود ندارد.');
                }
                $stmt = $this->conn->prepare("UPDATE inventory_items SET status = 'sold' WHERE id = ?");
                $stmt->bind_param('i', $refId);
                $stmt->execute();
                return ['success' => true, 'message' => "وضعیت آیتم به «فروخته شده» تغییر یافت."];

            case 'sold_without_sales_link':
                $stmt = $this->conn->prepare(
                    "SELECT ii.id FROM inventory_items ii
                     JOIN products p ON p.id = ii.product_id
                     WHERE ii.id = ? AND p.entity_id = ?
                       AND ii.status IN ('sold','consignment_out')
                       AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id = 0)"
                );
                $stmt->bind_param('ii', $refId, $entityId);
                $stmt->execute();
                if (empty(db_stmt_to_assoc_array($stmt))) {
                    throw new Exception('آیتم یافت نشد یا دیگر یتیم نیست.');
                }
                $stmt = $this->conn->prepare("DELETE FROM inventory_items WHERE id = ?");
                $stmt->bind_param('i', $refId);
                $stmt->execute();
                return ['success' => true, 'message' => 'ردیف فروش یتیم از انبار حذف شد.'];

            case 'broken_purchase_link':
                $stmt = $this->conn->prepare(
                    "SELECT ii.id, ii.status, ii.sales_invoice_item_id
                     FROM inventory_items ii
                     JOIN products p ON p.id = ii.product_id
                     LEFT JOIN purchase_invoice_items pii ON pii.id = ii.purchase_invoice_item_id
                     WHERE ii.id = ? AND p.entity_id = ?
                       AND ii.purchase_invoice_item_id IS NOT NULL AND ii.purchase_invoice_item_id != 0
                       AND pii.id IS NULL"
                );
                $stmt->bind_param('ii', $refId, $entityId);
                $stmt->execute();
                $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
                if (!$row) throw new Exception('اتصال شکسته یافت نشد.');
                $noSale = empty($row['sales_invoice_item_id']) || intval($row['sales_invoice_item_id']) === 0;
                if ($row['status'] === 'sold' && $noSale) {
                    $stmt = $this->conn->prepare("DELETE FROM inventory_items WHERE id = ?");
                    $stmt->bind_param('i', $refId);
                    $stmt->execute();
                    return ['success' => true, 'message' => 'ردیف با اتصال خرید نامعتبر حذف شد.'];
                }
                $stmt = $this->conn->prepare("UPDATE inventory_items SET purchase_invoice_item_id = NULL WHERE id = ?");
                $stmt->bind_param('i', $refId);
                $stmt->execute();
                return ['success' => true, 'message' => 'ارجاع خرید نامعتبر پاک شد.'];

            case 'duplicate_sold_inventory':
                $dimensions = isset($data['dimensions']) ? trim((string)$data['dimensions']) : '';
                if ($dimensions === '' && isset($data['meta']['dimensions'])) {
                    $dimensions = trim((string)$data['meta']['dimensions']);
                }
                if ($dimensions !== '') {
                    $stmt = $this->conn->prepare(
                        "DELETE ii FROM inventory_items ii
                         JOIN products p ON p.id = ii.product_id
                         WHERE p.entity_id = ? AND ii.product_id = ? AND ii.dimensions = ?
                           AND ii.status = 'sold'
                           AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id = 0)"
                    );
                    $stmt->bind_param('iis', $entityId, $refId, $dimensions);
                } else {
                    $stmt = $this->conn->prepare(
                        "DELETE ii FROM inventory_items ii
                         JOIN products p ON p.id = ii.product_id
                         WHERE p.entity_id = ? AND ii.product_id = ?
                           AND ii.status = 'sold'
                           AND (ii.sales_invoice_item_id IS NULL OR ii.sales_invoice_item_id = 0)"
                    );
                    $stmt->bind_param('ii', $entityId, $refId);
                }
                $stmt->execute();
                $deleted = $stmt->affected_rows;
                if ($deleted < 1) {
                    throw new Exception('ردیف یتیمی برای حذف یافت نشد.');
                }
                return ['success' => true, 'message' => "{$deleted} ردیف فروش تکراری/یتیم حذف شد."];
        }
        throw new Exception('نوع خطا برای اصلاح خودکار پشتیبانی نمی‌شود.');
    }
}
