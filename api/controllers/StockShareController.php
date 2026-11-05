<?php
// /api/controllers/StockShareController.php
// لینک موجودی برای همکاران (management side; the public page is stock.php).

require_once __DIR__ . '/../models/StockShareLink.php';

class StockShareController {
    private $conn;
    private $model;

    public function __construct($db) {
        $this->conn = $db;
        $this->model = new StockShareLink($db);
    }

    public function getLinks($data) {
        $entityId = (int)$_SESSION['current_entity_id'];
        send_json([
            'links' => $this->model->listForEntity($entityId),
            'options' => $this->model->scopeOptions($entityId),
            'default_partner_pct' => floatval(get_app_setting($this->conn, 'stock_share_partner_pct', 0)),
        ]);
    }

    public function saveLink($data) {
        $entityId = (int)$_SESSION['current_entity_id'];
        $isNew = intval($data['id'] ?? 0) <= 0;
        $result = $this->model->save($data, $entityId, $_SESSION['user_id'] ?? null);
        if (!$result['success']) {
            send_json(['error' => $result['error']], 400);
        }
        if (($data['price_mode'] ?? '') === 'partner') {
            // Pre-fill the next link with the last percentage used.
            $pct = (string)floatval($data['partner_discount_pct'] ?? 0);
            $stmt = $this->conn->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('stock_share_partner_pct', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->bind_param('s', $pct);
            $stmt->execute();
        }
        $link = $this->model->find($result['id'], $entityId);
        log_activity($this->conn, $isNew ? 'CREATE_STOCK_SHARE_LINK' : 'UPDATE_STOCK_SHARE_LINK',
            ($isNew ? 'ساخت' : 'ویرایش') . " لینک موجودی «{$link['title']}» #{$link['id']}");
        send_json([
            'success' => true,
            'message' => $isNew ? 'لینک ساخته شد.' : 'لینک به‌روز شد.',
            'id' => (int)$link['id'],
            'url' => StockShareLink::publicUrl($link['token']),
        ]);
    }

    public function revokeLink($data) {
        $entityId = (int)$_SESSION['current_entity_id'];
        $id = intval($data['id'] ?? 0);
        $link = $this->model->find($id, $entityId);
        if (!$link) {
            send_json(['error' => 'لینک یافت نشد.'], 404);
        }
        if (!$this->model->revoke($id, $entityId)) {
            send_json(['error' => 'این لینک قبلاً باطل شده است.'], 400);
        }
        log_activity($this->conn, 'REVOKE_STOCK_SHARE_LINK', "لینک موجودی «{$link['title']}» #{$id} باطل شد.");
        send_json(['success' => true, 'message' => 'لینک باطل شد.']);
    }
}
