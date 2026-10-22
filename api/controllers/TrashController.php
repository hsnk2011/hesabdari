<?php
// /api/controllers/TrashController.php
require_once __DIR__ . '/../core/DocumentTrash.php';

/** سطل بازیافت (deleted documents). */
class TrashController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function listItems($data) {
        $type = preg_replace('/[^a-z_]/', '', (string)($data['doc_type'] ?? ''));
        send_json(['success' => true, 'items' => DocumentTrash::listForEntity($this->conn, (int)$_SESSION['current_entity_id'], $type, 300)]);
    }

    public function getItem($data) {
        $item = DocumentTrash::get($this->conn, (int)$_SESSION['current_entity_id'], (int)($data['id'] ?? 0));
        if (!$item) {
            send_json(['error' => 'مورد یافت نشد.'], 404);
            return;
        }
        send_json(['success' => true, 'item' => $item]);
    }

    public function restoreItem($data) {
        $res = DocumentTrash::restore($this->conn, (int)$_SESSION['current_entity_id'], (int)($data['id'] ?? 0));
        if (!empty($res['success'])) {
            log_activity($this->conn, 'TRASH_RESTORE', "بازیابی مورد #" . (int)($data['id'] ?? 0) . " از سطل بازیافت (شناسه جدید {$res['id']}).");
            send_json(['success' => true, 'id' => $res['id'], 'message' => 'مورد بازیابی شد.']);
        } else {
            send_json(['error' => $res['error']], $res['statusCode'] ?? 400);
        }
    }
}
