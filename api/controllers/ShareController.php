<?php
// /api/controllers/ShareController.php
class ShareController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function generateProforma($data) {
        $invoiceType = ($data['invoice_type'] ?? 'sales');
        $invoiceId = intval($data['invoice_id'] ?? 0);
        $ttlHours = intval($data['ttl_hours'] ?? 168); // default 7 days

        if (!in_array($invoiceType, ['sales','purchase']) || !$invoiceId) {
            send_json(['error'=>'اطلاعات نامعتبر'], 400);
        }
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTime())->add(new DateInterval('PT'.max(1,$ttlHours).'H'))->format('Y-m-d H:i:s');

        $stmt = $this->conn->prepare("INSERT INTO proforma_links (invoice_type, invoice_id, token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("siss", $invoiceType, $invoiceId, $token, $expires);
        if ($stmt->execute()) {
            $stmt->close();
            send_json(['success'=>true, 'token'=>$token]);
        } else {
            $err = $stmt->error; $stmt->close();
            send_json(['error'=>"DB error: $err"], 500);
        }
    }
}
