<?php
// /api/controllers/AccountController.php

require_once __DIR__ . '/../models/Account.php';

class AccountController {
    private $conn;
    private $accountModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->accountModel = new Account($db);
    }

    public function getPaginated($data) {
        $result = $this->accountModel->getPaginated($data);
        send_json($result);
    }

    public function getFullList() {
        $result = $this->accountModel->getAll();
        send_json($result);
    }

    public function save($data) {
        $result = $this->accountModel->save($data);
        if (isset($result['success'])) {
            log_activity($this->conn, 'SAVE_ACCOUNT', "حساب «{$data['name']}» ذخیره شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function delete($data) {
        $id = $data['id'] ?? null;
        $result = $this->accountModel->delete($id);
        if (isset($result['success'])) {
            log_activity($this->conn, 'DELETE_ACCOUNT', "حساب با شناسه {$id} حذف شد.");
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    public function getAccountTransactions($data) {
        $accountId = $data['accountId'] ?? null;
        if (!$accountId) {
            send_json(['error' => 'شناسه حساب الزامی است.'], 400);
        }
        
        $query = "
            SELECT 'payment' as source, p.date, p.amount, p.invoiceType, p.invoiceId, 'پرداخت نقدی فاکتور' as type, p.description
            FROM payments p
            WHERE p.account_id = ? AND p.type = 'cash'
            
            UNION ALL
            
            SELECT 'expense' as source, e.date, e.amount, NULL, NULL, CONCAT('هزینه: ', e.category) as type, e.description
            FROM expenses e
            WHERE e.account_id = ?
            
            UNION ALL
            
            SELECT 'partner' as source, pt.date, pt.amount, NULL, NULL, IF(pt.type = 'DEPOSIT', 'واریز شریک', 'برداشت شریک') as type, pt.description
            FROM partner_transactions pt
            WHERE pt.account_id = ?
            
            UNION ALL
            
            SELECT 'check' as source, c.dueDate as date, c.amount, NULL, NULL, 'وصول چک' as type, CONCAT('چک شماره: ', c.checkNumber)
            FROM checks c
            WHERE c.cashed_in_account_id = ? AND c.status = 'cashed'
            
            ORDER BY date DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $accountId, $accountId, $accountId, $accountId);
        $stmt->execute();
        
        // --- COMPATIBILITY FIX: Replaced get_result() with bind_result() loop ---
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        $fields = [];
        $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        
        $result = [];
        while ($stmt->fetch()) {
            $c = [];
            foreach($row as $key => $val) {
                $c[$key] = $val;
            }
            $result[] = $c;
        }
        // --- END FIX ---
        
        $stmt->close();
        
        send_json($result);
    }
}