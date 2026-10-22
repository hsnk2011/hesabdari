<?php
// /api/controllers/PeriodClosingController.php

require_once __DIR__ . '/../models/PeriodClosing.php';

class PeriodClosingController {
    private $conn;
    private $model;

    public function __construct($db) {
        $this->conn = $db;
        $this->model = new PeriodClosing($db);
    }

    public function getStatus($data) {
        send_json($this->model->getStatus());
    }

    public function getList($data) {
        send_json(['closings' => $this->model->getList()]);
    }

    public function getDetail($data) {
        $id = (int)($data['id'] ?? 0);
        if (!$id) {
            send_json(['error' => 'شناسه الزامی است.'], 400);
            return;
        }
        $result = $this->model->getDetail($id);
        if (isset($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            return;
        }
        send_json($result);
    }

    public function getShareHistory($data) {
        send_json(['history' => $this->model->getShareHistory()]);
    }

    public function previewClose($data) {
        $result = $this->model->preview($data);
        if (isset($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            return;
        }
        send_json($result);
    }

    public function executeClose($data) {
        $result = $this->model->execute($data);
        if (isset($result['success'])) {
            $title = trim($data['title'] ?? '') ?: 'بدون عنوان';
            log_activity(
                $this->conn,
                'ACCOUNT_CLOSING',
                "بستن حساب «{$title}» تا {$result['periodEnd']} با شناسه {$result['id']} انجام شد."
            );
            send_json($result);
            return;
        }
        send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
    }

    public function reopenClosing($data) {
        $result = $this->model->reopen($data);
        if (isset($result['success'])) {
            log_activity(
                $this->conn,
                'ACCOUNT_CLOSING_REOPEN',
                "بستن حساب با شناسه {$data['id']} بازگشایی شد."
            );
            send_json($result);
            return;
        }
        send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
    }
}
