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

        // Fetch all transactions for the account
        $query = "
            SELECT 'payment_in' as source, p.date, p.amount, p.invoiceType, p.invoiceId, 'پرداخت نقدی فاکتور فروش' as type, p.description
            FROM payments p WHERE p.account_id = ? AND p.type = 'cash' AND p.invoiceType = 'sales'
            UNION ALL
            SELECT 'payment_out' as source, p.date, p.amount, p.invoiceType, p.invoiceId, 'پرداخت نقدی فاکتور خرید' as type, p.description
            FROM payments p WHERE p.account_id = ? AND p.type = 'cash' AND p.invoiceType = 'purchase'
            UNION ALL
            SELECT 'expense' as source, e.date, e.amount, NULL, NULL, CONCAT('هزینه: ', e.category) as type, e.description
            FROM expenses e WHERE e.account_id = ?
            UNION ALL
            SELECT 'partner_in' as source, pt.date, pt.amount, NULL, NULL, CONCAT('واریز از طرف: ', pr.name) as type, pt.description
            FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'DEPOSIT'
            UNION ALL
            SELECT 'partner_out' as source, pt.date, pt.amount, NULL, NULL, CONCAT('برداشت توسط: ', pr.name) as type, pt.description
            FROM partner_transactions pt JOIN partners pr ON pt.partnerId = pr.id WHERE pt.account_id = ? AND pt.type = 'WITHDRAWAL'
            UNION ALL
            SELECT 'partner_personal_in' as source, pt.date, pt.amount, NULL, NULL, CONCAT('برداشت از حساب شرکت (', comp_acc.name, ')') as type, pt.description
            FROM partner_transactions pt JOIN partners p ON pt.partnerId = p.id JOIN accounts partner_acc ON p.id = partner_acc.partner_id JOIN accounts comp_acc ON pt.account_id = comp_acc.id WHERE partner_acc.id = ?
            UNION ALL
            SELECT 'partner_personal_out' as source, pt.date, pt.amount, NULL, NULL, CONCAT('واریز به حساب شرکت (', comp_acc.name, ')') as type, pt.description
            FROM partner_transactions pt JOIN partners p ON pt.partnerId = p.id JOIN accounts partner_acc ON p.id = partner_acc.partner_id JOIN accounts comp_acc ON pt.account_id = comp_acc.id WHERE partner_acc.id = ?
            UNION ALL
            SELECT 'check' as source, c.dueDate as date, c.amount, NULL, NULL, 'وصول چک' as type, CONCAT('چک شماره: ', c.checkNumber)
            FROM checks c WHERE c.cashed_in_account_id = ? AND c.status = 'cashed'
            ORDER BY date DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiiiiiii", $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId);
        $stmt->execute();
        
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        $fields = []; $row = [];
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
        $stmt->close();
        
        // In this simplified fix, we send all transactions and let the client calculate the running balance.
        // A full implementation would add date filtering and opening balance calculation here.
        send_json($result);
    }
}