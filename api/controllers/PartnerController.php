<?php
// /api/controllers/PartnerController.php

require_once __DIR__ . '/../models/Partner.php';

/**
 * The PartnerController handles all requests related to partners and their transactions.
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
        $result = $this->partnerModel->save($data);
        if (isset($result['success'])) {
            $action = empty($data['id']) ? 'ایجاد شد.' : 'ویرایش شد.';
            log_activity($this->conn, 'SAVE_PARTNER', "شریک «{$data['name']}» با شناسه {$result['id']} {$action}");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles deleting a partner.
     * @param array $data Contains the partner's 'id'.
     */
    public function deletePartner($data) {
        $id = $data['id'] ?? null;
        // A custom message is needed for partner deletion failure due to foreign keys.
        $res = $this->conn->query("SELECT name FROM partners WHERE id = ".intval($id))->fetch_assoc();
        $partnerName = $res['name'] ?? "با شناسه {$id}";

        $result = $this->partnerModel->delete($id);
        
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_ENTITY', "شریک «{$partnerName}» حذف شد.");
            send_json($result);
        } else {
            if ($result['statusCode'] === 409) {
                 $result['error'] = 'این شریک قابل حذف نیست. ابتدا باید تمام تراکنش‌های مالی ثبت‌شده برای او را حذف کنید.';
            }
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles saving a partner's financial transaction.
     * @param array $data The transaction data.
     */
    public function savePartnerTransaction($data) {
        $result = $this->partnerModel->saveTransaction($data);
        if (isset($result['success'])) {
            $type_text = ($data['type'] === 'DEPOSIT' ? 'واریز' : 'برداشت');
            $partnerName = $result['partnerName'] ?? 'نامشخص';
            log_activity($this->conn, 'PARTNER_TRANSACTION', "{$type_text} برای شریک «{$partnerName}» به مبلغ {$data['amount']} ثبت شد.");
            send_json(['success' => true, 'id' => $result['id']]);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles deleting a partner's financial transaction.
     * @param array $data Contains the transaction 'id'.
     */
    public function deletePartnerTransaction($data) {
        $id = $data['id'] ?? null;
        $result = $this->partnerModel->deleteTransaction($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_PARTNER_TRANSACTION', "تراکنش شریک با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}