<?php
// /api/controllers/ShareController.php
class ShareController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function generateProforma($data) {
        $invoiceType = ($data['invoice_type'] ?? 'sales');
        // Check permission based on invoice type
        if ($invoiceType === 'sales') {
            check_permission('manage_sales_invoices');
        } else {
            check_permission('manage_purchase_invoices');
        }

        $invoiceId = intval($data['invoice_id'] ?? 0);
        $defaultTtl = intval(get_app_setting($this->conn, 'share_link_ttl_hours', defined('DEFAULT_SHARE_LINK_TTL_HOURS') ? DEFAULT_SHARE_LINK_TTL_HOURS : 168));
        // Links are public; keep their lifetime bounded (max 90 days).
        $ttlHours = max(1, min(2160, intval($data['ttl_hours'] ?? $defaultTtl)));

        if (!in_array($invoiceType, ['sales','purchase']) || !$invoiceId) {
            send_json(['error'=>'اطلاعات نامعتبر'], 400);
        }
        if (!entity_owns($this->conn, $invoiceType === 'sales' ? 'sales_invoices' : 'purchase_invoices', $invoiceId)) {
            send_json(['error' => 'فاکتور یافت نشد.'], 404);
        }
        $expires = (new DateTime())->add(new DateInterval('PT'.max(1,$ttlHours).'H'))->format('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("INSERT INTO proforma_links (invoice_type, invoice_id, token, expires_at) VALUES (?, ?, ?, ?)");

        $token = '';
        $ok = false;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = generate_short_invoice_code(8);
            $stmt->bind_param("siss", $invoiceType, $invoiceId, $token, $expires);
            if ($stmt->execute()) {
                $ok = true;
                break;
            }
        }
        if ($ok) {
            $stmt->close();
            $url = invoice_public_short_url($token);
            send_json(['success'=>true, 'token'=>$token, 'url'=>$url, 'expires_at'=>$expires]);
        } else {
            $err = $stmt->error; $stmt->close();
            send_json(['error'=>"DB error: $err"], 500);
        }
    }

    /** باطل کردن همه لینک‌های عمومی یک فاکتور (e.g. sent to the wrong number). */
    public function revokeLinks($data) {
        $invoiceType = ($data['invoice_type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
        check_permission($invoiceType === 'sales' ? 'manage_sales_invoices' : 'manage_purchase_invoices');
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $entityId = (int)$_SESSION['current_entity_id'];
        $table = $invoiceType === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        $own = $this->conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND entity_id = ?");
        $own->bind_param('ii', $invoiceId, $entityId);
        $own->execute();
        if (empty(db_stmt_to_assoc_array($own))) {
            send_json(['error' => 'فاکتور یافت نشد.'], 404);
            return;
        }
        $stmt = $this->conn->prepare("UPDATE proforma_links SET revoked_at = NOW() WHERE invoice_type = ? AND invoice_id = ? AND revoked_at IS NULL");
        $stmt->bind_param('si', $invoiceType, $invoiceId);
        $stmt->execute();
        $n = $stmt->affected_rows;
        log_activity($this->conn, 'REVOKE_INVOICE_LINKS', "{$n} لینک عمومی فاکتور {$invoiceType} #{$invoiceId} باطل شد.");
        send_json(['success' => true, 'revoked' => $n, 'message' => $n > 0 ? "{$n} لینک باطل شد." : 'لینک فعالی وجود نداشت.']);
    }
}
