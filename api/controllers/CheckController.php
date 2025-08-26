<?php
// /api/controllers/CheckController.php

require_once __DIR__ . '/../models/Check.php';
class CheckController {
    private $conn;
    private $checkModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->checkModel = new Check($db);
    }

    public function cash($data) {
        $checkId = $data['checkId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        $result = $this->checkModel->cashCheck($checkId, $accountId);
        
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }

    /**
     * Handles clearing a payable check.
     * @param array $data Contains 'checkId' and 'accountId'.
     */
    public function clearPayable($data) {
        $checkId = $data['checkId'] ?? null;
        $accountId = $data['accountId'] ?? null;
        $result = $this->checkModel->clearPayableCheck($checkId, $accountId);
        
        if (isset($result['success'])) {
            send_json($result);
        } else {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
        }
    }
}