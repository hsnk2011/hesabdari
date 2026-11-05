<?php
// /api/controllers/PartnerController.php

require_once __DIR__ . '/../models/Partner.php';

/**
 * The PartnerController handles all requests related to partners.
 */
class PartnerController {
    private $conn;
    private $partnerModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->partnerModel = new Partner($db);
    }

    /**
     * Handles saving (create/update) a partner.
     * @param array $data The partner's data.
     */
    public function savePartner($data) {
        // Permission check is handled by the router
        try {
            $result = $this->partnerModel->save($data);
            if (isset($result['success'])) {
                $action = empty($data['id']) ? 'ایجاد شد.' : 'ویرایش شد.';
                log_activity($this->conn, 'SAVE_PARTNER', "شریک «{$data['name']}» با شناسه {$result['id']} {$action}");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره شریک: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handles deleting a partner.
     * @param array $data Contains the partner's 'id'.
     */
    public function deletePartner($data) {
        // Permission check is handled by the router
        try {
            $id = $data['id'] ?? null;
            
            $partnerName = "با شناسه {$id}";
            $stmt = $this->conn->prepare("SELECT name FROM partners WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);
                if (!empty($result)) {
                    $partnerName = $result[0]['name'];
                }
            }

            $result = $this->partnerModel->delete($id);
            
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_PARTNER', "شریک «{$partnerName}» حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف شریک: ' . $e->getMessage()], 500);
        }
    }
}