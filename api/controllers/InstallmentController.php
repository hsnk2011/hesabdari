<?php
// /api/controllers/InstallmentController.php
require_once __DIR__ . '/../models/Payment.php';

class InstallmentController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getInstallments($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $invoiceType = $data['invoice_type'] ?? '';
        $status = $data['status'] ?? null;

        // «معوق» is derived from the due date so the filter and the badge agree
        // even before the scheduler has persisted the status.
        $statusExpr = "CASE WHEN i.status = 'pending' AND i.due_date < CURDATE() THEN 'overdue' ELSE i.status END";
        $where = "i.entity_id = ?";
        $params = [$entity_id];
        $types = "i";

        if ($invoiceId && in_array($invoiceType, ['sales', 'purchase'])) {
            $where .= " AND i.invoice_id = ? AND i.invoice_type = ?";
            $params[] = $invoiceId;
            $params[] = $invoiceType;
            $types .= "is";
        }
        if ($status && in_array($status, ['pending', 'paid', 'overdue', 'partially_paid'])) {
            $where .= " AND ({$statusExpr}) = ?";
            $params[] = $status;
            $types .= "s";
        }

        $stmt = $this->conn->prepare(
            "SELECT i.*, {$statusExpr} AS status,
                    COALESCE(si.invoice_number, pi.invoice_number) AS invoice_number,
                    COALESCE(c.name, s.name) AS person_name,
                    COALESCE(c.phone, s.phone) AS person_phone
             FROM installments i
             LEFT JOIN sales_invoices si ON i.invoice_type = 'sales' AND si.id = i.invoice_id
             LEFT JOIN customers c ON c.id = si.customerId
             LEFT JOIN purchase_invoices pi ON i.invoice_type = 'purchase' AND pi.id = i.invoice_id
             LEFT JOIN suppliers s ON s.id = pi.supplierId
             WHERE {$where}
             ORDER BY i.due_date ASC"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $items = db_stmt_to_assoc_array($stmt);

        send_json(['items' => $items]);
    }

    public function savePlan($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $invoiceType = $data['invoice_type'] ?? '';
        $installments = $data['installments'] ?? [];

        if (!$invoiceId || !in_array($invoiceType, ['sales', 'purchase'])) {
            send_json(['error' => 'اطلاعات فاکتور نامعتبر است.'], 400); return;
        }
        $invTable = ($invoiceType === 'sales') ? 'sales_invoices' : 'purchase_invoices';
        $stmtInv = $this->conn->prepare("SELECT id FROM {$invTable} WHERE id = ? AND entity_id = ?");
        $stmtInv->bind_param("ii", $invoiceId, $entity_id);
        $stmtInv->execute();
        if (empty(db_stmt_to_assoc_array($stmtInv))) {
            send_json(['error' => 'فاکتور یافت نشد یا متعلق به این مجموعه نیست.'], 404); return;
        }
        if (empty($installments)) {
            send_json(['error' => 'حداقل یک قسط الزامی است.'], 400); return;
        }
        $planTotal = 0.0;
        foreach ($installments as $idx => $inst) {
            $amt = parse_money($inst['amount'] ?? 0);
            $due = $inst['due_date'] ?? '';
            if ($amt > 0 && !validate_date_format($due)) {
                send_json(['error' => 'تاریخ سررسید قسط ' . ($idx + 1) . ' نامعتبر است.'], 400); return;
            }
            if ($amt > 0) {
                $planTotal += $amt;
            }
        }
        // Pending installments may not exceed what is still owed on the invoice
        // (paid installments are already part of the invoice's paidAmount).
        $grandStmt = $this->conn->prepare("SELECT totalAmount - discount + COALESCE(tax_amount, 0) - paidAmount AS remaining FROM {$invTable} WHERE id = ? AND entity_id = ?");
        $grandStmt->bind_param('ii', $invoiceId, $entity_id);
        $grandStmt->execute();
        $remaining = (float)(db_stmt_to_assoc_array($grandStmt)[0]['remaining'] ?? 0);
        if ($planTotal > $remaining + 1) {
            send_json(['error' => 'جمع اقساط (' . number_format($planTotal) . ') از مانده فاکتور (' . number_format(max(0, $remaining)) . ') بیشتر است.'], 400); return;
        }

        $this->conn->begin_transaction();
        try {
            // Only remove not-yet-paid installments; keep paid/partially_paid history intact.
            $stmt = $this->conn->prepare("DELETE FROM installments WHERE invoice_id = ? AND invoice_type = ? AND entity_id = ? AND status IN ('pending','overdue')");
            $stmt->bind_param("isi", $invoiceId, $invoiceType, $entity_id);
            $stmt->execute();

            // Continue numbering from the highest surviving installment number so we
            // don't collide with kept paid/partially_paid installments.
            $stmtMax = $this->conn->prepare("SELECT COALESCE(MAX(installment_number), 0) AS max_num FROM installments WHERE invoice_id = ? AND invoice_type = ? AND entity_id = ?");
            $stmtMax->bind_param("isi", $invoiceId, $invoiceType, $entity_id);
            $stmtMax->execute();
            $maxRow = db_stmt_to_assoc_array($stmtMax);
            $num = intval($maxRow[0]['max_num'] ?? 0) + 1;

            $stmtIns = $this->conn->prepare("INSERT INTO installments (invoice_id, invoice_type, installment_number, amount, due_date, description, entity_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($installments as $inst) {
                $amount = parse_money($inst['amount'] ?? 0);
                $dueDate = $inst['due_date'] ?? '';
                $desc = $inst['description'] ?? '';
                if ($amount <= 0 || empty($dueDate)) continue;
                $stmtIns->bind_param("isidssi", $invoiceId, $invoiceType, $num, $amount, $dueDate, $desc, $entity_id);
                $stmtIns->execute();
                $num++;
            }

            $this->conn->commit();
            log_activity($this->conn, 'SAVE_INSTALLMENT', "طرح تقسیط برای فاکتور {$invoiceType} #{$invoiceId} ذخیره شد.");
            send_json(['success' => true, 'message' => 'طرح تقسیط ذخیره شد.']);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => $e->getMessage()], 500);
        }
    }

    public function payInstallment($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        $paidAmount = parse_money($data['paid_amount'] ?? 0);
        $paidDate = $data['paid_date'] ?? date('Y-m-d');
        $accountId = intval($data['account_id'] ?? 0);

        if (!$id || $paidAmount <= 0) {
            send_json(['error' => 'اطلاعات نامعتبر.'], 400); return;
        }
        if (!validate_date_format($paidDate)) {
            send_json(['error' => 'تاریخ پرداخت نامعتبر است.'], 400); return;
        }
        assert_transaction_date_editable($this->conn, $entity_id, $paidDate);

        // Resolve the cash account that receives/pays this installment money.
        if ($accountId > 0) {
            $accStmt = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
            $accStmt->bind_param("ii", $accountId, $entity_id);
            $accStmt->execute();
            if (empty(db_stmt_to_assoc_array($accStmt))) {
                send_json(['error' => 'حساب انتخاب شده نامعتبر است.'], 400); return;
            }
        } else {
            $accStmt = $this->conn->prepare("SELECT id FROM accounts WHERE entity_id = ? AND is_cash = 1 ORDER BY id ASC LIMIT 1");
            $accStmt->bind_param("i", $entity_id);
            $accStmt->execute();
            $acc = db_stmt_to_assoc_array($accStmt);
            if (empty($acc)) {
                send_json(['error' => 'هیچ حساب نقدی برای ثبت پرداخت قسط یافت نشد.'], 400); return;
            }
            $accountId = intval($acc[0]['id']);
        }

        $this->conn->begin_transaction();
        try {
            // Lock the installment row to prevent concurrent double-payment.
            $stmt = $this->conn->prepare("SELECT * FROM installments WHERE id = ? AND entity_id = ? FOR UPDATE");
            $stmt->bind_param("ii", $id, $entity_id);
            $stmt->execute();
            $inst = db_stmt_to_assoc_array($stmt);
            if (empty($inst)) { throw new Exception('قسط یافت نشد.', 404); }
            $inst = $inst[0];

            // Cap the payment so we never record more than the installment amount.
            $remaining = floatval($inst['amount']) - floatval($inst['paid_amount']);
            if ($remaining <= 0) {
                throw new Exception('این قسط قبلاً به طور کامل پرداخت شده است.', 400);
            }
            if ($paidAmount > $remaining) {
                $paidAmount = $remaining;
            }

            $newPaidAmount = floatval($inst['paid_amount']) + $paidAmount;
            $status = $newPaidAmount >= floatval($inst['amount']) - 0.005 ? 'paid' : 'partially_paid';
            // paid_amount/status are maintained by Payment::save via installment_id
            // (and reverted if that payment is later deleted).
            $stmtUpd = $this->conn->prepare("UPDATE installments SET paid_date = ? WHERE id = ? AND entity_id = ?");
            $stmtUpd->bind_param("sii", $paidDate, $id, $entity_id);
            $stmtUpd->execute();

            // Book the actual money movement into the financial system (payments + invoice paidAmount + account balance).
            $personId = null;
            $personType = null;
            if ($inst['invoice_type'] === 'sales') {
                $personType = 'customer';
                $pStmt = $this->conn->prepare("SELECT customerId FROM sales_invoices WHERE id = ? AND entity_id = ?");
                $pStmt->bind_param("ii", $inst['invoice_id'], $entity_id);
                $pStmt->execute();
                $pRow = db_stmt_to_assoc_array($pStmt);
                if (empty($pRow)) { throw new Exception('فاکتور مربوط به قسط یافت نشد.', 404); }
                $personId = intval($pRow[0]['customerId']);
            } else {
                $personType = 'supplier';
                $pStmt = $this->conn->prepare("SELECT supplierId FROM purchase_invoices WHERE id = ? AND entity_id = ?");
                $pStmt->bind_param("ii", $inst['invoice_id'], $entity_id);
                $pStmt->execute();
                $pRow = db_stmt_to_assoc_array($pStmt);
                if (empty($pRow)) { throw new Exception('فاکتور مربوط به قسط یافت نشد.', 404); }
                $personId = intval($pRow[0]['supplierId']);
            }

            $paymentModel = new Payment($this->conn);
            $paymentModel->save([
                'type' => 'cash',
                'transaction_type' => ($inst['invoice_type'] === 'sales') ? 'receipt' : 'payment',
                'invoiceId' => intval($inst['invoice_id']),
                'invoiceType' => $inst['invoice_type'],
                'person_id' => $personId,
                'person_type' => $personType,
                'account_id' => $accountId,
                'amount' => $paidAmount,
                'date' => $paidDate,
                'description' => 'پرداخت قسط #' . intval($inst['installment_number']) . ' فاکتور ' . ($inst['invoice_type'] === 'sales' ? 'فروش' : 'خرید') . ' #' . intval($inst['invoice_id']),
                'installment_id' => $id,
            ], false);

            $this->conn->commit();
            log_activity($this->conn, 'PAY_INSTALLMENT', "قسط #{$id} پرداخت شد ({$paidAmount}).");
            send_json(['success' => true, 'message' => 'پرداخت قسط ثبت شد.', 'newStatus' => $status]);
        } catch (Exception $e) {
            $this->conn->rollback();
            $statusCode = is_numeric($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            send_json(['error' => $e->getMessage()], $statusCode);
        }
    }

    public function deletePlan($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $invoiceType = $data['invoice_type'] ?? '';

        // Keep paid/partially_paid rows: they document money that actually moved
        // and deleting them would break the payments/installments audit trail.
        $stmt = $this->conn->prepare("DELETE FROM installments WHERE invoice_id = ? AND invoice_type = ? AND entity_id = ? AND status IN ('pending','overdue')");
        $stmt->bind_param("isi", $invoiceId, $invoiceType, $entity_id);
        $stmt->execute();

        send_json(['success' => true, 'message' => 'طرح تقسیط حذف شد.', 'deleted' => $stmt->affected_rows]);
    }
}
