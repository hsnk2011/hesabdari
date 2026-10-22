<?php
// /api/controllers/AuditController.php
require_once __DIR__ . '/../models/Account.php'; // Add Account model dependency

class AuditController {
    private $conn;
    private $accountModel; // Add property for account model

    public function __construct($db) {
        $this->conn = $db;
        $this->accountModel = new Account($db); // Instantiate the model
    }

    public function getUnlinkedItems($data) {
        check_permission('manage_products');
        $entity_id = $_SESSION['current_entity_id'];
        $status = isset($data['status']) ? strtolower(trim($data['status'])) : 'in_stock';
        $allowedStatuses = ['all', 'in_stock', 'sold', 'reserved', 'consignment_out', 'returned', 'returned_to_supplier', 'damaged'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'in_stock';
        }

        $select = "
            SELECT
                ii.id as inventory_item_id,
                ii.serial_id,
                ii.product_id,
                ii.dimensions,
                ii.entry_date,
                ii.status,
                ii.sales_invoice_item_id,
                p.name as product_name
            FROM inventory_items ii
            JOIN products p ON ii.product_id = p.id
            WHERE p.entity_id = ?
              AND ii.purchase_invoice_item_id IS NULL
        ";

        if ($status === 'all') {
            $stmt = $this->conn->prepare($select . " ORDER BY p.name, ii.dimensions, ii.id");
            $stmt->bind_param("i", $entity_id);
        } else {
            $stmt = $this->conn->prepare($select . " AND ii.status = ? ORDER BY p.name, ii.dimensions, ii.id");
            $stmt->bind_param("is", $entity_id, $status);
        }
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        send_json($result);
    }

    public function getPossibleMatches($data) {
        check_permission('manage_products');
        $product_id = intval($data['product_id'] ?? 0);
        $dimensions = $data['dimensions'] ?? null;
        $entity_id = $_SESSION['current_entity_id'];

        if (!$product_id) {
            send_json(['error' => 'اطلاعات محصول ناقص است.'], 400);
            return;
        }

        $dim_norm = normalize_dimensions($dimensions ?? '');
        $dim_expr = sql_normalize_dimensions_expr('pii.dimensions');

        $stmt = $this->conn->prepare(
            "
            SELECT
                pii.id as purchase_invoice_item_id,
                pi.id as purchase_invoice_id,
                pi.date,
                pii.unitPrice,
                pii.quantity,
                pii.dimensions as invoice_dimensions,
                s.name as supplier_name,
                (SELECT COUNT(*) FROM inventory_items WHERE purchase_invoice_item_id = pii.id) as linked_count,
                (SELECT GROUP_CONCAT(ii.id ORDER BY ii.id SEPARATOR ', ')
                 FROM inventory_items ii WHERE ii.purchase_invoice_item_id = pii.id) as linked_inventory_ids
            FROM purchase_invoice_items pii
            JOIN purchase_invoices pi ON pii.invoiceId = pi.id
            LEFT JOIN suppliers s ON pi.supplierId = s.id
            WHERE pi.entity_id = ?
              AND pii.productId = ?
              AND ({$dim_expr} = ? OR (COALESCE(pii.dimensions, '') = '' AND ? = ''))
            ORDER BY (pii.quantity - linked_count) DESC, pi.date DESC
        "
        );
        $stmt->bind_param("iiss", $entity_id, $product_id, $dim_norm, $dim_norm);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        foreach ($rows as &$row) {
            $linked = intval($row['linked_count'] ?? 0);
            $qty = intval($row['quantity'] ?? 0);
            $row['available_slots'] = max(0, $qty - $linked);
        }
        unset($row);

        send_json($rows);
    }

    public function linkItem($data) {
        check_permission('manage_products');
        $inventory_item_id = intval($data['inventory_item_id'] ?? 0);
        $purchase_invoice_item_id = intval($data['purchase_invoice_item_id'] ?? 0);
        $entity_id = $_SESSION['current_entity_id'];

        if (!$inventory_item_id || !$purchase_invoice_item_id) {
            send_json(['error' => 'شناسه‌های مورد نیاز برای اتصال ارسال نشده است.'], 400);
            return;
        }

        $this->conn->begin_transaction();
        try {
            // Verify ownership and check capacity limit
            $check_stmt = $this->conn->prepare(
                "SELECT pii.quantity, 
                (SELECT COUNT(*) FROM inventory_items WHERE purchase_invoice_item_id = pii.id) as linked_count
                FROM purchase_invoice_items pii
                JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                WHERE pii.id = ? AND pi.entity_id = ?"
            );
            $check_stmt->bind_param("ii", $purchase_invoice_item_id, $entity_id);
            $check_stmt->execute();
            $res = $check_stmt->get_result();
            if ($res->num_rows === 0) {
                throw new Exception('ردیف فاکتور خرید معتبر نیست یا به شما تعلق ندارد.');
            }
            $row = $res->fetch_assoc();
            if ($row['quantity'] - $row['linked_count'] <= 0) {
                throw new Exception('ظرفیت این ردیف فاکتور تکمیل است.');
            }

            // Verify inventory item ownership
            $check_inv = $this->conn->prepare("SELECT ii.id FROM inventory_items ii JOIN products p ON ii.product_id = p.id WHERE ii.id = ? AND p.entity_id = ?");
            $check_inv->bind_param("ii", $inventory_item_id, $entity_id);
            $check_inv->execute();
            if ($check_inv->get_result()->num_rows === 0) {
                throw new Exception('آیتم انبار نامعتبر است.');
            }

            $stmt = $this->conn->prepare("UPDATE inventory_items SET purchase_invoice_item_id = ?, is_virtual = 0 WHERE id = ? AND purchase_invoice_item_id IS NULL");
            $stmt->bind_param("ii", $purchase_invoice_item_id, $inventory_item_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $this->conn->commit();
                log_activity($this->conn, 'LINK_INVENTORY', "آیتم انبار #{$inventory_item_id} به آیتم فاکتور خرید #{$purchase_invoice_item_id} متصل شد.");
                send_json(['success' => true]);
            } else {
                $this->conn->rollback();
                send_json(['error' => 'اتصال انجام نشد. ممکن است این آیتم قبلا متصل شده باشد.'], 409);
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در اتصال آیتم: ' . $e->getMessage()], 500);
        }
    }

    public function autoLinkItems() {
        check_permission('manage_products');
        $entity_id = $_SESSION['current_entity_id'];
        $linked_count = 0;

        $this->conn->begin_transaction();
        try {
            // Find all unlinked items grouped by product and dimension
            $stmt_unlinked = $this->conn->prepare(
                "SELECT ii.id as inventory_item_id, ii.product_id, ii.dimensions
                FROM inventory_items ii
                JOIN products p ON ii.product_id = p.id
                WHERE p.entity_id = ? AND ii.status = 'in_stock' AND ii.purchase_invoice_item_id IS NULL"
            );
            $stmt_unlinked->bind_param("i", $entity_id);
            $stmt_unlinked->execute();
            $items_to_process = db_stmt_to_assoc_array($stmt_unlinked);

            if (empty($items_to_process)) {
                $this->conn->commit();
                send_json(['success' => true, 'message' => 'موردی برای اتصال خودکار یافت نشد.']);
                return;
            }

            $dim_expr = sql_normalize_dimensions_expr('pii.dimensions');
            $stmt_find_match = $this->conn->prepare(
                "SELECT pii.id, pii.quantity,
                 (SELECT COUNT(*) FROM inventory_items WHERE purchase_invoice_item_id = pii.id) as linked_count
                 FROM purchase_invoice_items pii
                 JOIN purchase_invoices pi ON pii.invoiceId = pi.id
                 WHERE pi.entity_id = ? AND pii.productId = ?
                   AND ({$dim_expr} = ? OR (COALESCE(pii.dimensions, '') = '' AND ? = ''))
                 HAVING (quantity - linked_count) > 0
                 ORDER BY pi.date ASC LIMIT 1"
            );

            $stmt_link = $this->conn->prepare(
                "UPDATE inventory_items SET purchase_invoice_item_id = ?, is_virtual = 0 WHERE id = ? AND purchase_invoice_item_id IS NULL"
            );

            foreach ($items_to_process as $item) {
                $dim_bind = normalize_dimensions($item['dimensions'] ?? '');
                $stmt_find_match->bind_param("iiss", $entity_id, $item['product_id'], $dim_bind, $dim_bind);
                $stmt_find_match->execute();
                $possible_matches = db_stmt_to_assoc_array($stmt_find_match, false);

                if (count($possible_matches) > 0) {
                    $purchase_invoice_item_id = $possible_matches[0]['id'];
                    $inventory_item_id = $item['inventory_item_id'];
                    
                    $stmt_link->bind_param("ii", $purchase_invoice_item_id, $inventory_item_id);
                    $stmt_link->execute();
                    if ($stmt_link->affected_rows > 0) {
                        $linked_count++;
                    }
                }
            }
            
            $stmt_find_match->close();
            $stmt_link->close();

            $this->conn->commit();
            
            if ($linked_count > 0) {
                log_activity($this->conn, 'AUTO_LINK_INVENTORY', "تعداد {$linked_count} آیتم انبار به صورت خودکار متصل شدند.");
                send_json(['success' => true, 'message' => "عملیات با موفقیت انجام شد. تعداد {$linked_count} آیتم به صورت خودکار متصل شدند."]);
            } else {
                send_json(['success' => true, 'message' => 'هیچ آیتم ساده‌ای برای اتصال خودکار یافت نشد.']);
            }

        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در حین عملیات اتصال خودکار: ' . $e->getMessage()], 500);
        }
    }
    
    
    // *** شروع متدهای جدید ***
    public function getAccountBalanceAnalysis($data) {
        check_permission('manage_settings');
        $accountId = intval($data['accountId'] ?? 0);
        if (!$accountId) {
            send_json(['error' => 'شناسه حساب نامعتبر است.'], 400);
            return;
        }

        try {
            // Use the more reliable getLedger which now correctly handles initial balance
            $ledgerData = $this->accountModel->getLedger($accountId);
            if (isset($ledgerData['error'])) {
                send_json(['error' => $ledgerData['error']], 404);
                return;
            }
            
            $stored_balance = (float)$ledgerData['account']['current_balance'];
            $opening_balance = (float)$ledgerData['account']['initial_balance']; // Use the actual initial balance
            $transactions = $ledgerData['transactions'] ?? [];
            
            $running_balance = $opening_balance;
            foreach ($transactions as $tx) {
                $amount = (float)$tx['amount'];
                $source = $tx['source'];
                
                // Consignment invoices don't affect balance
                // Consignment-invoice payments moved real money too; the wallet and the
                // ledger both include them, so the expected balance must as well.

                if (str_ends_with($source, '_out') || $source === 'expense') {
                    $running_balance -= $amount;
                } else {
                    $running_balance += $amount;
                }
            }
            
            $calculated_balance = round($running_balance, 2);

            send_json([
                'stored_balance' => $stored_balance,
                'calculated_balance' => $calculated_balance,
                'difference' => $calculated_balance - $stored_balance,
                'transactions' => $transactions,
                'opening_balance' => $opening_balance,
                'is_partner_account' => !empty($ledgerData['account']['partner_id'])
            ]);

        } catch (Exception $e) {
            send_json(['error' => 'خطا در تحلیل حساب: ' . $e->getMessage()], 500);
        }
    }

    public function fixAccountBalance($data) {
        check_permission('manage_settings');
        $accountId = intval($data['accountId'] ?? 0);
        if (!$accountId) {
            send_json(['error' => 'شناسه حساب نامعتبر است.'], 400);
            return;
        }

        $this->conn->begin_transaction();
        try {
            // Recalculate on the server to ensure correctness, using the new reliable logic
            $ledgerData = $this->accountModel->getLedger($accountId);
            if (isset($ledgerData['error'])) throw new Exception("حساب یافت نشد.");

            $opening_balance = (float)$ledgerData['account']['initial_balance'];
            $transactions = $ledgerData['transactions'] ?? [];
            
            $running_balance = $opening_balance;
            foreach ($transactions as $tx) {
                $amount = (float)$tx['amount'];
                $source = $tx['source'];
                // Consignment-invoice payments moved real money too; the wallet and the
                // ledger both include them, so the expected balance must as well.
                if (str_ends_with($source, '_out') || $source === 'expense') {
                    $running_balance -= $amount;
                } else {
                    $running_balance += $amount;
                }
            }
            
            $correct_balance = round($running_balance, 2);

            $entityForFix = (int)$_SESSION['current_entity_id'];
            $stmt = $this->conn->prepare("UPDATE accounts SET current_balance = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("dii", $correct_balance, $accountId, $entityForFix);
            $stmt->execute();
            $stmt->close();
            
            $this->conn->commit();
            log_activity($this->conn, 'FIX_ACCOUNT_BALANCE', "موجودی حساب با شناسه {$accountId} به مبلغ {$correct_balance} اصلاح شد.");
            send_json(['success' => true, 'message' => 'موجودی حساب با موفقیت اصلاح شد.']);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در اصلاح موجودی حساب: ' . $e->getMessage()], 500);
        }
    }
    // *** پایان متدهای جدید ***

    // *** Smart Auditor Methods (v2 Rule Engine) ***
    public function runSmartAudit() {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/SmartAuditor.php';

        $entity_id = $_SESSION['current_entity_id'];
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $auditor = new SmartAuditor($this->conn, $entity_id);

        try {
            $result = $auditor->runFullAuditDetailed($user_id);
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطا در اجرای حسابرسی هوشمند: ' . $e->getMessage()], 500);
        }
    }

    public function getAuditRuns($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../models/AuditRun.php';
        $entity_id = (int)$_SESSION['current_entity_id'];
        $limit = intval($data['limit'] ?? 20);
        $model = new AuditRun($this->conn);
        send_json(['success' => true, 'runs' => $model->listRuns($entity_id, $limit)]);
    }

    public function getAuditRun($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../models/AuditRun.php';
        $entity_id = (int)$_SESSION['current_entity_id'];
        $run_id = intval($data['run_id'] ?? 0);
        if (!$run_id) {
            send_json(['error' => 'شناسه اجرا الزامی است.'], 400);
            return;
        }
        $model = new AuditRun($this->conn);
        $run = $model->getRun($entity_id, $run_id);
        if (!$run) {
            send_json(['error' => 'اجرا یافت نشد.'], 404);
            return;
        }
        send_json(['success' => true, 'run' => $run]);
    }

    public function compareAuditRuns($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../models/AuditRun.php';
        $entity_id = (int)$_SESSION['current_entity_id'];
        $run_id = intval($data['run_id'] ?? 0);
        $against = isset($data['against_run_id']) ? intval($data['against_run_id']) : null;
        if (!$run_id) {
            send_json(['error' => 'شناسه اجرا الزامی است.'], 400);
            return;
        }
        $model = new AuditRun($this->conn);
        $cmp = $model->compareRuns($entity_id, $run_id, $against);
        if (isset($cmp['error'])) {
            send_json(['error' => $cmp['error']], 404);
            return;
        }
        send_json(['success' => true, 'compare' => $cmp]);
    }

    public function ignoreIssues($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/audit/AbstractRule.php';
        $issues = $data['issues'] ?? [];
        $entity_id = $_SESSION['current_entity_id'];
        $user_id = $_SESSION['user_id'] ?? 0;

        if (empty($issues)) {
            send_json(['error' => 'هیچ موردی برای نادیده گرفتن انتخاب نشده است.'], 400);
            return;
        }

        // ensure fingerprint column
        require_once __DIR__ . '/../models/AuditRun.php';
        (new AuditRun($this->conn))->ensureSchema();

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT IGNORE INTO audit_ignored_items (entity_id, reference_table, reference_id, issue_type, fingerprint, ignored_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($issues as $issue) {
                $refTable = (string)($issue['reference_table'] ?? '');
                $refId = (string)($issue['reference_id'] ?? '');
                $type = (string)($issue['type'] ?? $issue['rule_id'] ?? '');
                $fp = (string)($issue['fingerprint'] ?? '');
                if ($fp === '' && $type !== '') {
                    $fp = AbstractAuditRule::makeFingerprint($type, $refTable, $refId, $issue['meta'] ?? []);
                }
                $stmt->bind_param('issssi', $entity_id, $refTable, $refId, $type, $fp, $user_id);
                $stmt->execute();
            }

            $this->conn->commit();
            send_json(['success' => true, 'message' => 'موارد انتخاب شده به لیست نادیده گرفته شده‌ها اضافه شدند.']);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در نادیده گرفتن موارد: ' . $e->getMessage()], 500);
        }
    }

    public function restoreIssues($data) {
        check_permission('manage_settings');
        $ids = $data['ids'] ?? [];
        $entity_id = $_SESSION['current_entity_id'];

        if (empty($ids)) {
            send_json(['error' => 'هیچ موردی برای بازگردانی انتخاب نشده است.'], 400);
            return;
        }

        $this->conn->begin_transaction();
        try {
            $safeIds = implode(',', array_map('intval', $ids));
            $this->conn->query("DELETE FROM audit_ignored_items WHERE id IN ($safeIds) AND entity_id = $entity_id");

            $this->conn->commit();
            send_json(['success' => true, 'message' => 'موارد انتخاب شده بازگردانی شدند.']);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => 'خطا در بازگردانی موارد: ' . $e->getMessage()], 500);
        }
    }

    public function previewAuditFix($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/audit/RemediationService.php';
        $svc = new AuditRemediationService($this->conn);
        $result = $svc->preview($data);
        if (!$result['success']) {
            send_json(['error' => $result['error'] ?? 'خطا'], 400);
            return;
        }
        send_json($result);
    }

    public function applyGuidedFix($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/audit/RemediationService.php';
        $entity_id = (int)$_SESSION['current_entity_id'];
        $svc = new AuditRemediationService($this->conn);
        $result = $svc->apply($data, $entity_id);
        if (!$result['success']) {
            send_json(['error' => $result['error'] ?? 'خطا'], 400);
            return;
        }
        send_json($result);
    }

    public function resolveIssue($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../core/audit/RemediationService.php';
        $entity_id = (int)$_SESSION['current_entity_id'];
        $type = $data['type'] ?? $data['rule_id'] ?? '';
        $svc = new AuditRemediationService($this->conn);

        if (!$svc->isSafeAuto($type) && $type !== 'unlock_user') {
            send_json(['error' => 'این مورد اصلاح خودکار امن ندارد. از ویزارد راهنما یا «برو به مورد» استفاده کنید.'], 400);
            return;
        }

        // Pass dimensions from meta when present
        if (empty($data['dimensions']) && !empty($data['meta']['dimensions'])) {
            $data['dimensions'] = $data['meta']['dimensions'];
        }

        $result = $svc->apply($data, $entity_id);
        if (!$result['success']) {
            send_json(['error' => $result['error'] ?? 'خطا در اصلاح'], 500);
            return;
        }
        send_json(['success' => true, 'message' => $result['message'] ?? 'اصلاح شد.']);
    }

    /**
     * Resolve audit reference (e.g. invoice line item → parent invoice) for UI navigation.
     */
    public function resolveAuditNav($data) {
        check_permission('manage_settings');
        $table = $data['reference_table'] ?? '';
        $id = intval($data['reference_id'] ?? 0);
        if ($id <= 0 || $table === '') {
            send_json(['error' => 'اطلاعات نامعتبر است.'], 400);
            return;
        }

        $open = null;
        if ($table === 'sales_invoice_items') {
            $stmt = $this->conn->prepare('SELECT invoiceId FROM sales_invoice_items WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt);
            if (!empty($row[0]['invoiceId'])) {
                $open = ['entityType' => 'salesInvoice', 'id' => intval($row[0]['invoiceId'])];
            }
        } elseif ($table === 'purchase_invoice_items') {
            $stmt = $this->conn->prepare('SELECT invoiceId FROM purchase_invoice_items WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt);
            if (!empty($row[0]['invoiceId'])) {
                $open = ['entityType' => 'purchaseInvoice', 'id' => intval($row[0]['invoiceId'])];
            }
        } elseif ($table === 'return_invoices') {
            $stmt = $this->conn->prepare('SELECT type FROM return_invoices WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = db_stmt_to_assoc_array($stmt);
            if (!empty($row[0]['type'])) {
                $t = $row[0]['type'];
                $open = [
                    'entityType' => ($t === 'purchase_return' || $t === 'purchaseReturn') ? 'purchaseReturn' : 'salesReturn',
                    'id' => $id
                ];
            }
        } elseif ($table === 'sales_invoices') {
            $open = ['entityType' => 'salesInvoice', 'id' => $id];
        } elseif ($table === 'purchase_invoices') {
            $open = ['entityType' => 'purchaseInvoice', 'id' => $id];
        } elseif ($table === 'payments') {
            $open = ['entityType' => 'payment', 'id' => $id];
        } elseif ($table === 'expenses') {
            $open = ['entityType' => 'expense', 'id' => $id];
        } elseif ($table === 'incomes') {
            $open = ['entityType' => 'income', 'id' => $id];
        } elseif ($table === 'checks') {
            $open = ['entityType' => 'check', 'id' => $id];
        }

        if (!$open) {
            send_json(['success' => false, 'error' => 'هدف ناوبری یافت نشد.']);
            return;
        }
        send_json(['success' => true, 'open' => $open]);
    }
}