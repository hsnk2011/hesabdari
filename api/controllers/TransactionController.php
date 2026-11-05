<?php
// /api/controllers/TransactionController.php

require_once __DIR__ . '/../models/Transaction.php';

class TransactionController {
    private $conn;
    private $transactionModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->transactionModel = new Transaction($db);
    }

    public function getPaginatedData($data) {
        $result = $this->transactionModel->getPaginated($data);
        send_json($result);
    }
}