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
            return;
        }

        // Logic is now in the model
        $result = $this->accountModel->getLedger($accountId);

        if (isset($result['error'])) {
            send_json($result, $result['statusCode'] ?? 404);
        } else {
            send_json($result);
        }
    }
}